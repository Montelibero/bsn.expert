<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BsnManageDataSemanticService;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\TransactionConsolidationItem;
use Montelibero\BSN\TransactionConsolidationMemo;
use Montelibero\BSN\TransactionConsolidationStore;
use Montelibero\BSN\TransactionConsolidator;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

final class TransactionConsolidationController
{
    private const CSRF_PURPOSE = 'csrf:transaction_consolidation';
    private const MAX_REQUEST_BYTES = 2_500_000;
    private const MAX_STORED_ITEMS = 50;
    private const MAX_STORED_OPERATIONS = 500;
    private const MAX_BSN_SNAPSHOT_SOURCES = 5;

    /** @var array<string, TransactionConsolidationItem> */
    private array $parsedItems = [];

    /** @var array<string, AccountResponse|null> */
    private array $accounts = [];

    public function __construct(
        private readonly Environment $Twig,
        private readonly Translator $Translator,
        private readonly CurrentUser $CurrentUser,
        private readonly RequestSession $RequestSession,
        private readonly TransactionConsolidationStore $Store,
        private readonly TransactionConsolidator $Consolidator,
        private readonly StellarSDK $Stellar,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
        private readonly BsnManageDataSemanticService $BsnSemantics,
        private readonly SignController $SignController,
        private readonly TokensController $TokensController,
    ) {
    }

    public function Index(): ?string
    {
        $this->noStoreHeaders();

        $authorized = $this->CurrentUser->isAuthorized();
        $owner = $this->CurrentUser->getAccountId();
        $errors = [];
        $warnings = [];
        $notices = [];
        $confirmClear = false;
        $draftConflict = false;
        $signingForm = null;
        $xdrBatch = '';
        $state = $this->emptyState();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            if ($owner !== null) {
                try {
                    $state = $this->Store->get($owner);
                } catch (\Throwable) {
                    $warnings[] = $this->trans('tools_consolidate.errors.storage_unavailable');
                }
            }
        } else {
            $xdrBatch = $this->postedString('xdr_batch');
            $expectedRevision = $this->postedRevision();

            if ($this->requestTooLarge() || (string) ($_POST['form_complete'] ?? '') !== '1') {
                SimpleRouter::response()->httpCode(413);
                $errors[] = $this->trans('tools_consolidate.errors.form_truncated');
                $state = $owner !== null ? $this->safeStoredState($owner, $warnings) : $this->emptyState();
            } elseif ($authorized && !$this->validCsrf($_POST['csrf_token'] ?? null)) {
                SimpleRouter::response()->httpCode(403);
                $errors[] = $this->trans('tools_consolidate.errors.csrf');
                $state = $owner !== null ? $this->safeStoredState($owner, $warnings) : $this->emptyState();
            } else {
                try {
                    $state = $this->stateFromPost();
                    $action = $this->postedString('action', true);
                    $this->applyOrderingAction($state);

                    if ($this->postedString('remove', true) !== '') {
                        $this->removeItem($state, $this->postedString('remove', true));
                    }

                    if ($action === 'import') {
                        try {
                            $xdrBatch = $this->importBatch($state, $xdrBatch, $notices);
                        } catch (\InvalidArgumentException) {
                            $errors[] = $this->trans('tools_consolidate.errors.import_too_large');
                        }
                    } elseif ($action === 'clear') {
                        $state = $this->emptyState();
                        $notices[] = $this->trans('tools_consolidate.notices.cleared');
                    } elseif ($action === 'confirm_clear') {
                        $confirmClear = true;
                    }

                    $this->ensureDefaults($state);
                    $saveConflict = false;

                    if ($owner !== null) {
                        try {
                            $newRevision = $this->Store->save($owner, $state, $expectedRevision);
                            if ($newRevision === null) {
                                SimpleRouter::response()->httpCode(409);
                                $errors[] = $this->trans('tools_consolidate.errors.conflict');
                                $overflowXdr = [];
                                $state = $this->mergeConflictState(
                                    $state,
                                    $this->safeStoredState($owner, $warnings),
                                    $overflowXdr,
                                );
                                if ($overflowXdr !== []) {
                                    $xdrBatch = $this->appendXdrBatch($xdrBatch, $overflowXdr);
                                    $notices[] = $this->trans('tools_consolidate.notices.conflict_overflow');
                                }
                                $saveConflict = true;
                                $draftConflict = true;
                            } else {
                                $state['revision'] = $newRevision;
                                if ($action === 'save') {
                                    SimpleRouter::response()->redirect('/tools/consolidate', 303);
                                    return null;
                                }
                            }
                        } catch (\Throwable) {
                            $warnings[] = $this->trans('tools_consolidate.errors.storage_unavailable');
                        }
                    }

                    if ($action === 'build' && !$saveConflict) {
                        $signingForm = $this->buildSigningForm($state, $errors, $warnings);
                    }
                } catch (\Throwable $Error) {
                    SimpleRouter::response()->httpCode(400);
                    $errors[] = $this->trans('tools_consolidate.errors.invalid_state');
                    $warnings[] = $Error->getMessage();
                    $state = $owner !== null ? $this->safeStoredState($owner, $warnings) : $this->emptyState();
                }
            }
        }

        $this->ensureDefaults($state);
        $view = $this->viewState($state);

        return $this->Twig->render('tools_consolidate.twig', $view + [
            'authorized' => $authorized,
            'csrf_token' => $authorized ? $this->csrfToken() : null,
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $notices,
            'confirm_clear' => $confirmClear,
            'draft_conflict' => $draftConflict,
            'signing_form' => $signingForm,
            'xdr_batch' => $xdrBatch,
        ]);
    }

    public function Add(): ?string
    {
        $this->noStoreHeaders();
        $owner = $this->CurrentUser->getAccountId();
        if ($owner === null) {
            SimpleRouter::response()->httpCode(401);
            return $this->trans('tools_consolidate.errors.unauthorized');
        }
        if (!$this->validCsrf($_POST['consolidation_csrf_token'] ?? null)) {
            SimpleRouter::response()->httpCode(403);
            return $this->trans('tools_consolidate.errors.csrf');
        }

        try {
            $Item = $this->Consolidator->parseEnvelope($this->postedString('xdr', true));
            $storedItem = $this->storedItem($Item);
            $result = null;
            for ($attempt = 0; $attempt < 3 && $result === null; $attempt++) {
                $snapshot = $this->Store->get($owner);
                $duplicate = false;
                $operationCount = 0;
                foreach ($snapshot['items'] as $existing) {
                    if (hash_equals($existing['id'], $Item->id)) {
                        $duplicate = true;
                    }
                    $operationCount += $this->parseItem($existing['xdr'])->operation_count;
                }
                if (
                    !$duplicate
                    && (
                        count($snapshot['items']) >= self::MAX_STORED_ITEMS
                        || $operationCount + $Item->operation_count > self::MAX_STORED_OPERATIONS
                    )
                ) {
                    SimpleRouter::response()->httpCode(422);
                    return $this->trans('tools_consolidate.notices.limit');
                }

                $result = $this->Store->addItem($owner, $storedItem, $snapshot['revision']);
            }
            if ($result === null) {
                SimpleRouter::response()->httpCode(409);
                return $this->trans('tools_consolidate.errors.conflict');
            }

            SimpleRouter::response()->redirect('/tools/consolidate#item-' . rawurlencode($Item->id), 303);
            return null;
        } catch (\InvalidArgumentException $Error) {
            SimpleRouter::response()->httpCode(400);
            return $Error->getMessage();
        } catch (\Throwable) {
            SimpleRouter::response()->httpCode(503);
            return $this->trans('tools_consolidate.errors.storage_unavailable');
        }
    }

    public function Autosave(): string
    {
        $this->noStoreHeaders();
        SimpleRouter::response()->header('Content-Type: application/json; charset=utf-8');

        $owner = $this->CurrentUser->getAccountId();
        if ($owner === null) {
            return $this->jsonError(401, 'unauthorized');
        }
        if (!$this->validCsrf($_POST['csrf_token'] ?? null)) {
            return $this->jsonError(403, 'csrf');
        }
        if ($this->requestTooLarge() || (string) ($_POST['form_complete'] ?? '') !== '1') {
            return $this->jsonError(413, 'truncated');
        }

        try {
            $state = $this->stateFromPost();
            $this->ensureDefaults($state);
            $revision = $this->Store->save($owner, $state, $this->postedRevision());
            if ($revision === null) {
                return $this->jsonError(409, 'conflict');
            }

            return json_encode(['status' => 'ok', 'revision' => $revision], JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException $Error) {
            return $this->jsonError(422, 'invalid_state', $Error->getMessage());
        } catch (\Throwable) {
            return $this->jsonError(503, 'storage_unavailable');
        }
    }

    /** @return array{revision:int,items:list<array>,order:list<string>,settings:array} */
    private function emptyState(): array
    {
        return [
            'revision' => 0,
            'items' => [],
            'order' => [],
            'settings' => [
                'source_mode' => 'current_account',
                'source_account' => '',
                'memo_choice' => 'custom',
                'custom_memo' => '',
                'seq_num' => '',
                'sponsor_reserves' => false,
                'preconditions_ack' => false,
            ],
        ];
    }

    private function safeStoredState(string $owner, array &$warnings): array
    {
        try {
            return $this->Store->get($owner);
        } catch (\Throwable) {
            $warnings[] = $this->trans('tools_consolidate.errors.storage_unavailable');
            return $this->emptyState();
        }
    }

    private function mergeConflictState(array $submitted, array $latest, array &$overflowXdr): array
    {
        $submittedItems = [];
        foreach ($submitted['items'] as $item) {
            $submittedItems[$item['id']] = $item;
        }

        $items = [];
        $operationCount = 0;
        foreach ($latest['items'] as $item) {
            $item = $submittedItems[$item['id']] ?? $item;
            $operations = $this->parseItem($item['xdr'])->operation_count;
            if (
                count($items) >= self::MAX_STORED_ITEMS
                || $operationCount + $operations > self::MAX_STORED_OPERATIONS
            ) {
                $overflowXdr[] = $item['xdr'];
                continue;
            }
            $items[$item['id']] = $item;
            $operationCount += $operations;
        }
        foreach ($submittedItems as $id => $item) {
            if (isset($items[$id])) {
                continue;
            }
            $operations = $this->parseItem($item['xdr'])->operation_count;
            if (
                count($items) >= self::MAX_STORED_ITEMS
                || $operationCount + $operations > self::MAX_STORED_OPERATIONS
            ) {
                $overflowXdr[] = $item['xdr'];
                continue;
            }
            $items[$id] = $item;
            $operationCount += $operations;
        }

        $order = [];
        foreach ([...$submitted['order'], ...$latest['order']] as $id) {
            if (isset($items[$id]) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $submitted['items'] = array_map(static fn(string $id): array => $items[$id], $order);
        $submitted['order'] = $order;
        $submitted['revision'] = (int) ($latest['revision'] ?? 0);
        return $submitted;
    }

    /** @param list<string> $xdrs */
    private function appendXdrBatch(string $batch, array $xdrs): string
    {
        $lines = [];
        $seen = [];
        foreach ([...(preg_split('/\r\n|\n|\r/', $batch) ?: []), ...$xdrs] as $line) {
            $line = trim($line);
            if ($line !== '' && !isset($seen[$line])) {
                $lines[] = $line;
                $seen[$line] = true;
            }
        }
        return implode("\n", $lines);
    }

    private function stateFromPost(): array
    {
        $rawItems = $_POST['items'] ?? [];
        $rawOrder = $_POST['order'] ?? [];
        if (!is_array($rawItems) || !is_array($rawOrder) || count($rawItems) > self::MAX_STORED_ITEMS) {
            throw new \InvalidArgumentException('Invalid consolidation item list.');
        }

        $itemsById = [];
        $totalOperations = 0;
        foreach ($rawItems as $postedId => $rawItem) {
            if (!is_string($postedId) || !is_array($rawItem)) {
                throw new \InvalidArgumentException('Invalid consolidation item.');
            }
            $Item = $this->parseItem($this->scalarString($rawItem['xdr'] ?? null, true));
            if (!hash_equals($Item->id, $postedId) || isset($itemsById[$postedId])) {
                throw new \InvalidArgumentException('Consolidation item ID does not match its XDR.');
            }

            $totalOperations += $Item->operation_count;
            if ($totalOperations > self::MAX_STORED_OPERATIONS) {
                throw new \InvalidArgumentException('A draft cannot display more than 500 operations.');
            }

            $enabledOperations = [];
            $rawEnabled = $rawItem['enabled_operations'] ?? [];
            if (is_array($rawEnabled)) {
                foreach ($rawEnabled as $index) {
                    if ((is_int($index) || (is_string($index) && ctype_digit($index)))) {
                        $index = (int) $index;
                        if ($index >= 0 && $index < $Item->operation_count) {
                            $enabledOperations[$index] = $index;
                        }
                    }
                }
            }
            ksort($enabledOperations, SORT_NUMERIC);

            $itemsById[$postedId] = [
                'id' => $postedId,
                'xdr' => $Item->xdr,
                'fingerprint' => $Item->fingerprint,
                'enabled' => (string) ($rawItem['enabled'] ?? '') === '1',
                'enabled_operations' => array_values($enabledOperations),
            ];
        }

        $order = [];
        foreach ($rawOrder as $id) {
            $id = is_scalar($id) ? (string) $id : '';
            if (isset($itemsById[$id]) && !in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach (array_keys($itemsById) as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $items = array_map(static fn(string $id): array => $itemsById[$id], $order);
        [$sourceMode, $sourceAccount] = $this->postedSourceSetting();

        return [
            'revision' => $this->postedRevision(),
            'items' => $items,
            'order' => $order,
            'settings' => [
                'source_mode' => $sourceMode,
                'source_account' => $sourceAccount,
                'memo_choice' => $this->postedString('memo_choice', true) ?: 'custom',
                'custom_memo' => $this->postedString('custom_memo'),
                'seq_num' => $this->postedString('seq_num', true),
                'sponsor_reserves' => (string) ($_POST['sponsor_reserves'] ?? '') === '1',
                'preconditions_ack' => (string) ($_POST['preconditions_ack'] ?? '') === '1',
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function postedSourceSetting(): array
    {
        $choice = $this->postedString('source_choice', true);
        if ($choice === '' || $choice === 'current') {
            return ['current_account', ''];
        }
        if (str_starts_with($choice, 'source:')) {
            return ['account', strtoupper(substr($choice, 7))];
        }

        return ['account', strtoupper($this->postedString('custom_source', true))];
    }

    private function applyOrderingAction(array &$state): void
    {
        foreach (['move_before' => false, 'move_after' => true] as $field => $after) {
            $command = $this->postedString($field, true);
            if ($command === '' || !str_contains($command, ':')) {
                continue;
            }
            [$itemId, $anchorId] = explode(':', $command, 2);
            if ($itemId === $anchorId || !in_array($itemId, $state['order'], true) || !in_array($anchorId, $state['order'], true)) {
                continue;
            }

            $order = array_values(array_filter($state['order'], static fn(string $id): bool => $id !== $itemId));
            $anchor = array_search($anchorId, $order, true);
            if ($anchor === false) {
                continue;
            }
            array_splice($order, $anchor + ($after ? 1 : 0), 0, [$itemId]);
            $state['order'] = $order;
            $byId = [];
            foreach ($state['items'] as $item) {
                $byId[$item['id']] = $item;
            }
            $state['items'] = array_map(static fn(string $id): array => $byId[$id], $order);
        }
    }

    private function removeItem(array &$state, string $id): void
    {
        $state['items'] = array_values(array_filter(
            $state['items'],
            static fn(array $item): bool => $item['id'] !== $id,
        ));
        $state['order'] = array_values(array_filter(
            $state['order'],
            static fn(string $itemId): bool => $itemId !== $id,
        ));
    }

    private function importBatch(array &$state, string $input, array &$notices): string
    {
        if (trim($input) === '') {
            return '';
        }

        $result = $this->Consolidator->importLines($input);
        $existing = array_fill_keys($state['order'], true);
        $operationCount = 0;
        foreach ($state['items'] as $stored) {
            $operationCount += $this->parseItem($stored['xdr'])->operation_count;
        }
        $added = 0;
        $limitReported = false;
        $retainedXdr = [];
        foreach ($result['items'] as $Item) {
            if (isset($existing[$Item->id])) {
                $notices[] = $this->trans('tools_consolidate.notices.duplicate_existing');
                continue;
            }
            if (
                count($state['items']) >= self::MAX_STORED_ITEMS
                || $operationCount + $Item->operation_count > self::MAX_STORED_OPERATIONS
            ) {
                if (!$limitReported) {
                    $notices[] = $this->trans('tools_consolidate.notices.limit');
                    $limitReported = true;
                }
                $retainedXdr[$Item->xdr] = true;
                continue;
            }

            $state['items'][] = $this->storedItem($Item);
            $state['order'][] = $Item->id;
            $existing[$Item->id] = true;
            $operationCount += $Item->operation_count;
            $added++;
        }
        if ($added > 0) {
            $notices[] = $this->trans('tools_consolidate.notices.added');
        }
        foreach ($result['duplicates'] as $duplicate) {
            $notices[] = $this->trans('tools_consolidate.notices.duplicate', ['%line%' => $duplicate['line']]);
        }
        foreach ($result['errors'] as $error) {
            $notices[] = $this->trans('tools_consolidate.notices.rejected', [
                '%line%' => $error['line'],
                '%message%' => $error['message'],
            ]);
        }

        if ($result['errors'] === [] && $retainedXdr === []) {
            return '';
        }
        $lines = preg_split('/\r\n|\n|\r/', $input) ?: [];
        $errorLines = [];
        foreach ($result['errors'] as $error) {
            $errorLines[$error['line']] = true;
        }
        $rejected = [];
        foreach ($lines as $offset => $line) {
            $trimmed = trim($line);
            if (
                $trimmed !== ''
                && (isset($errorLines[$offset + 1]) || isset($retainedXdr[$trimmed]))
            ) {
                $rejected[] = $trimmed;
            }
        }
        return implode("\n", $rejected);
    }

    private function ensureDefaults(array &$state): void
    {
        $settings = &$state['settings'];
        $current = $this->CurrentUser->getCurrentAccountId();
        if (($settings['source_mode'] ?? '') === 'current_account' && $current === null) {
            $first = $state['items'][0]['xdr'] ?? null;
            if (is_string($first) && $first !== '') {
                $settings['source_mode'] = 'account';
                $settings['source_account'] = $this->parseItem($first)->source;
            }
        }

        $settings['memo_choice'] = is_string($settings['memo_choice'] ?? null)
            ? $settings['memo_choice']
            : 'custom';
        if ($settings['memo_choice'] === 'none') {
            $settings['memo_choice'] = 'custom';
        }
        $settings['custom_memo'] = is_string($settings['custom_memo'] ?? null)
            ? $settings['custom_memo']
            : '';
        $settings['seq_num'] = is_string($settings['seq_num'] ?? null)
            ? trim($settings['seq_num'])
            : '';
        $settings['sponsor_reserves'] = (bool) ($settings['sponsor_reserves'] ?? false);
        $settings['preconditions_ack'] = (bool) ($settings['preconditions_ack'] ?? false);
    }

    private function buildSigningForm(array $state, array &$errors, array &$warnings): ?string
    {
        [$Items, $selected] = $this->buildSelection($state);
        $selectedCount = array_sum(array_map('count', $selected));
        if ($selectedCount < 1) {
            $errors[] = $this->trans('tools_consolidate.errors.no_operations');
            return null;
        }
        if ($selectedCount > TransactionConsolidator::MAX_OPERATIONS) {
            $errors[] = $this->trans('tools_consolidate.errors.too_many_operations');
            return null;
        }

        $selectedIds = array_fill_keys(array_keys($selected), true);
        $hasPreconditions = false;
        foreach ($Items as $Item) {
            if (isset($selectedIds[$Item->id]) && in_array('preconditions_discarded', $Item->warnings, true)) {
                $hasPreconditions = true;
                break;
            }
        }
        if ($hasPreconditions && !($state['settings']['preconditions_ack'] ?? false)) {
            $errors[] = $this->trans('tools_consolidate.errors.preconditions_ack');
            return null;
        }

        try {
            $source = $this->resolveSource($state);
            $sponsorReserves = (bool) ($state['settings']['sponsor_reserves'] ?? false);
            $resultOperationCount = $this->Consolidator->resultOperationCount(
                $Items,
                $selected,
                $source,
                $sponsorReserves,
            );
            if ($resultOperationCount > TransactionConsolidator::MAX_OPERATIONS) {
                $errors[] = $this->trans('tools_consolidate.errors.too_many_operations');
                return null;
            }
            $Memo = $this->resolveMemo($state, $Items);
            $sequence = $this->resolveSequence($source, $state['settings']['seq_num'] ?? '', $warnings);
            $maxFeeXlm = bcdiv(
                (string) ($resultOperationCount * TransactionConsolidator::DEFAULT_MAX_OPERATION_FEE),
                '10000000',
                7,
            );
            $this->checkFeeBalance($source, $maxFeeXlm, $warnings);

            $xdr = $this->Consolidator->build(
                $Items,
                $selected,
                $source,
                $Memo,
                $sequence,
                sponsorReserves: $sponsorReserves,
            );
            return $this->SignController->SignTransaction(
                $xdr,
                null,
                $this->trans('tools_consolidate.result.description'),
            );
        } catch (\InvalidArgumentException $Error) {
            $errors[] = $this->trans('tools_consolidate.errors.build_failed', ['%message%' => $Error->getMessage()]);
        } catch (\Throwable $Error) {
            $errors[] = $this->trans('tools_consolidate.errors.build_failed', ['%message%' => $Error->getMessage()]);
        }

        return null;
    }

    /** @return array{0:list<TransactionConsolidationItem>,1:array<string,list<int>>} */
    private function buildSelection(array $state): array
    {
        $Items = [];
        $selected = [];
        foreach ($state['items'] as $stored) {
            $Item = $this->parseItem($stored['xdr']);
            $Items[] = $Item;
            if ($stored['enabled'] && $stored['enabled_operations'] !== []) {
                $selected[$Item->id] = $stored['enabled_operations'];
            }
        }
        return [$Items, $selected];
    }

    private function resolveSource(array $state): string
    {
        $settings = $state['settings'];
        if (($settings['source_mode'] ?? '') === 'current_account') {
            $source = $this->CurrentUser->getCurrentAccountId();
            if ($source === null) {
                throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.current_source_missing'));
            }
        } else {
            $source = strtoupper(trim((string) ($settings['source_account'] ?? '')));
        }

        if (!$this->validSource($source)) {
            throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.invalid_source'));
        }
        return $source;
    }

    private function resolveMemo(array $state, array $Items): TransactionConsolidationMemo
    {
        $choice = (string) ($state['settings']['memo_choice'] ?? 'none');
        if ($choice === 'none') {
            return TransactionConsolidationMemo::none();
        }
        if ($choice === 'custom') {
            return TransactionConsolidationMemo::fromCustom((string) ($state['settings']['custom_memo'] ?? ''));
        }
        if (str_starts_with($choice, 'memo:')) {
            $fingerprint = substr($choice, 5);
            foreach ($Items as $Item) {
                if (hash_equals($Item->memo->fingerprint(), $fingerprint)) {
                    return $Item->memo;
                }
            }
        }

        throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.invalid_memo'));
    }

    private function resolveSequence(string $source, string $manual, array &$warnings): string
    {
        $manual = trim($manual);
        $Account = $this->loadAccount($source);
        if ($manual === '') {
            if ($Account === null) {
                throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.sequence_unavailable'));
            }
            return $Account->getSequenceNumber()->add(new BigInteger('1'))->toString();
        }
        if (preg_match('/\A[1-9][0-9]*\z/D', $manual) !== 1) {
            throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.invalid_sequence'));
        }

        $ManualSequence = new BigInteger($manual, 10);
        if ($Account === null) {
            $warnings[] = $this->trans('tools_consolidate.warnings.sequence_not_verified');
            return $manual;
        }

        $Next = $Account->getSequenceNumber()->add(new BigInteger('1'));
        $comparison = $ManualSequence->compare($Next);
        if ($comparison < 0) {
            throw new \InvalidArgumentException($this->trans('tools_consolidate.errors.sequence_too_low', [
                '%next%' => $Next->toString(),
            ]));
        }
        if ($comparison > 0) {
            $warnings[] = $this->trans('tools_consolidate.warnings.sequence_above_current', [
                '%next%' => $Next->toString(),
            ]);
        }
        return $manual;
    }

    private function checkFeeBalance(string $source, string $feeXlm, array &$warnings): void
    {
        $Account = $this->loadAccount($source);
        if ($Account === null) {
            return;
        }
        try {
            $available = $this->ReserveCalculator->calculateAvailableXlm($Account);
            if (bccomp($available, $feeXlm, 7) < 0) {
                $warnings[] = $this->trans('tools_consolidate.warnings.fee_balance', [
                    '%fee%' => $feeXlm,
                    '%available%' => $available,
                ]);
            }
        } catch (\Throwable) {
            $warnings[] = $this->trans('tools_consolidate.warnings.fee_not_verified');
        }
    }

    private function loadAccount(string $source): ?AccountResponse
    {
        try {
            $base = MuxedAccount::fromAccountId($source)->getEd25519AccountId();
        } catch (\Throwable) {
            return null;
        }
        if (!array_key_exists($base, $this->accounts)) {
            try {
                $this->accounts[$base] = $this->Stellar->requestAccount($base);
            } catch (\Throwable) {
                $this->accounts[$base] = null;
            }
        }
        return $this->accounts[$base];
    }

    private function viewState(array $state): array
    {
        $items = [];
        $objects = [];
        $sourceAddresses = [];
        $selectedCount = 0;
        $selected = [];
        $hasPreconditions = false;
        foreach ($state['items'] as $stored) {
            try {
                $Item = $this->parseItem($stored['xdr']);
            } catch (\Throwable) {
                continue;
            }
            $objects[] = $Item;
            $sourceAddresses[$Item->source] = true;
            $warnings = array_map(fn(string $warning): string => $this->warningMessage($warning), $Item->warnings);
            $operations = $Item->operations;
            foreach ($operations as &$operation) {
                $operation = array_replace($operation, $this->operationView($operation));
                $operation['effective_source_account_id'] = $this->baseAccountId($operation['effective_source'])
                    ?? $operation['effective_source'];
                $operation['bsn_semantics'] = [];
            }
            unset($operation);
            $view = [
                'id' => $Item->id,
                'fingerprint' => $Item->fingerprint,
                'xdr' => $Item->xdr,
                'source' => $Item->source,
                'source_account_id' => $this->baseAccountId($Item->source) ?? $Item->source,
                'memo' => [
                    'type' => $Item->memo->type,
                    'label' => $this->memoDisplay($Item->memo),
                ],
                'warnings' => $warnings,
                'signature_count' => $Item->signature_count,
                'operation_count' => $Item->operation_count,
                'operations' => $operations,
                'enabled' => (bool) $stored['enabled'],
                'enabled_operations' => $stored['enabled_operations'],
            ];
            if ($view['enabled']) {
                $selectedCount += count($view['enabled_operations']);
                if ($view['enabled_operations'] !== []) {
                    $selected[$Item->id] = $view['enabled_operations'];
                }
                if (in_array('preconditions_discarded', $Item->warnings, true)) {
                    $hasPreconditions = true;
                }
            }
            $items[] = $view;
        }

        $this->applyBsnSemantics($items);
        $memoCandidates = $this->memoCandidateViews($objects);
        $currentId = $this->CurrentUser->getCurrentAccountId();
        $sourceCandidates = [];
        foreach (array_keys($sourceAddresses) as $address) {
            if ($currentId !== null && hash_equals($currentId, $address)) {
                continue;
            }
            $sourceCandidates[] = [
                'address' => $address,
                'account_id' => $this->baseAccountId($address) ?? $address,
            ];
        }

        $settings = $state['settings'];
        $savedSource = (string) ($settings['source_account'] ?? '');
        $sourceChoice = ($settings['source_mode'] ?? '') === 'current_account'
            || ($currentId !== null && hash_equals($currentId, $savedSource))
            ? 'current'
            : 'source:' . $savedSource;
        $knownSourceChoices = array_map(static fn(array $source): string => 'source:' . $source['address'], $sourceCandidates);
        if ($sourceChoice !== 'current' && !in_array($sourceChoice, $knownSourceChoices, true)) {
            $sourceChoice = 'custom';
        }

        $resultOperationCount = $selectedCount;
        if ($selectedCount > 0) {
            try {
                $resultOperationCount = $this->Consolidator->resultOperationCount(
                    $objects,
                    $selected,
                    $this->resolveSource($state),
                    (bool) ($settings['sponsor_reserves'] ?? false),
                );
            } catch (\Throwable) {
                // Keep the basic count until the source setting becomes valid.
            }
        }

        return [
            'revision' => (int) ($state['revision'] ?? 0),
            'items' => $items,
            'selected_operation_count' => $resultOperationCount,
            'max_operation_fee' => TransactionConsolidator::DEFAULT_MAX_OPERATION_FEE,
            'max_fee_xlm' => bcdiv(
                (string) ($resultOperationCount * TransactionConsolidator::DEFAULT_MAX_OPERATION_FEE),
                '10000000',
                7,
            ),
            'source_candidates' => $sourceCandidates,
            'current_source' => $currentId === null ? null : [
                'account_id' => $this->baseAccountId($currentId) ?? $currentId,
            ],
            'source_choice' => $sourceChoice,
            'custom_source' => $sourceChoice === 'custom'
                ? (string) ($settings['source_account'] ?? '')
                : '',
            'memo_candidates' => $memoCandidates,
            'memo_choice' => (string) ($settings['memo_choice'] ?? 'custom'),
            'custom_memo' => (string) ($settings['custom_memo'] ?? ''),
            'seq_num' => (string) ($settings['seq_num'] ?? ''),
            'sponsor_reserves' => (bool) ($settings['sponsor_reserves'] ?? false),
            'has_preconditions' => $hasPreconditions,
            'preconditions_ack' => (bool) ($settings['preconditions_ack'] ?? false),
        ];
    }

    private function applyBsnSemantics(array &$items): void
    {
        $overlay = [];
        $sourceMap = [];
        foreach ($items as $item) {
            if (!$item['enabled']) {
                continue;
            }
            foreach ($item['operations'] as $operation) {
                if (
                    !in_array($operation['index'], $item['enabled_operations'], true)
                    || !isset($operation['details']['key'])
                    || !($operation['details']['delete'] ?? false)
                ) {
                    continue;
                }
                $base = $this->baseAccountId($operation['effective_source']);
                if (
                    $base !== null
                    && (
                        isset($sourceMap[$base])
                        || count($sourceMap) < self::MAX_BSN_SNAPSHOT_SOURCES
                    )
                ) {
                    $sourceMap[$base] = $base;
                }
            }
        }
        foreach ($sourceMap as $base) {
            $Account = $this->loadAccount($base);
            if ($Account !== null) {
                $overlay[$base] = $this->BsnSemantics->snapshotFromHorizonData(
                    $Account->getData()->getData(),
                );
            }
        }

        foreach ($items as &$item) {
            if (!$item['enabled']) {
                continue;
            }
            foreach ($item['operations'] as &$operation) {
                if (
                    !in_array($operation['index'], $item['enabled_operations'], true)
                    || !isset($operation['details']['key'])
                ) {
                    continue;
                }
                $semanticSource = $this->baseAccountId($operation['effective_source'])
                    ?? $operation['effective_source'];
                $operation['bsn_semantics'] = $this->BsnSemantics->analyzeAndApply(
                    $semanticSource,
                    (string) $operation['details']['key'],
                    ($operation['details']['delete'] ?? false) ? null : ($operation['details']['value'] ?? ''),
                    $overlay,
                );
            }
            unset($operation);
        }
        unset($item);
    }

    private function memoCandidateViews(array $Items): array
    {
        $result = [];
        foreach ($this->Consolidator->memoCandidates($Items) as $Memo) {
            if ($Memo->type === 'none') {
                continue;
            }
            $fingerprint = $Memo->fingerprint();
            $count = 0;
            foreach ($Items as $Item) {
                if (hash_equals($Item->memo->fingerprint(), $fingerprint)) {
                    $count++;
                }
            }
            $result[] = [
                'choice' => 'memo:' . $fingerprint,
                'type' => strtoupper($Memo->type),
                'label' => $this->memoDisplay($Memo),
                'count' => $count,
            ];
        }
        return $result;
    }

    private function storedItem(TransactionConsolidationItem $Item): array
    {
        return [
            'id' => $Item->id,
            'xdr' => $Item->xdr,
            'fingerprint' => $Item->fingerprint,
            'enabled' => true,
            'enabled_operations' => range(0, $Item->operation_count - 1),
        ];
    }

    private function parseItem(string $xdr): TransactionConsolidationItem
    {
        $cacheKey = hash('sha256', $xdr);
        return $this->parsedItems[$cacheKey] ??= $this->Consolidator->parseEnvelope($xdr);
    }

    private function warningMessage(string $warning): string
    {
        $key = 'tools_consolidate.warnings.' . $warning;
        $translated = $this->trans($key);
        return $translated === $key ? $warning : $translated;
    }

    /**
     * Adapts authoritative XDR operation data to the same presentation
     * templates used by published transactions.
     *
     * @param array{class:string,details:array<string,mixed>,effective_source:string} $operation
     * @return array{title:string,template:?string,data:array<string,mixed>}
     */
    private function operationView(array $operation): array
    {
        $details = $operation['details'];
        $class = substr($operation['class'], (int) strrpos($operation['class'], '\\') + 1);
        $type = match ($class) {
            'PaymentOperation' => 'payment',
            'PathPaymentStrictReceiveOperation' => 'path_payment_strict_receive',
            'PathPaymentStrictSendOperation' => 'path_payment_strict_send',
            'CreateAccountOperation' => 'create_account',
            'ChangeTrustOperation' => 'change_trust',
            'ManageSellOfferOperation' => 'manage_sell_offer',
            'ManageBuyOfferOperation' => 'manage_buy_offer',
            'ManageDataOperation' => 'manage_data',
            'SetOptionsOperation' => 'set_options',
            'AccountMergeOperation' => 'account_merge',
            'BeginSponsoringFutureReservesOperation' => 'begin_sponsoring_future_reserves',
            'EndSponsoringFutureReservesOperation' => 'end_sponsoring_future_reserves',
            'CreateClaimableBalanceOperation' => 'create_claimable_balance',
            default => null,
        };
        $title = $type === null
            ? (preg_replace('/Operation\z/', '', $class) ?: $class)
            : $this->trans('transactions.operations.types.' . $type);
        $view = ['title' => $title, 'template' => null, 'data' => []];

        if ($class === 'PaymentOperation') {
            return array_replace($view, [
                'template' => 'operations/payment.twig',
                'data' => [
                    'to' => $this->accountView($details['destination'] ?? null),
                    'amount' => (string) ($details['amount'] ?? ''),
                    'asset' => $this->assetView($details['asset'] ?? null),
                ],
            ]);
        }
        if ($class === 'PathPaymentStrictReceiveOperation' || $class === 'PathPaymentStrictSendOperation') {
            $path = is_array($details['path'] ?? null) ? $details['path'] : [];
            return array_replace($view, [
                'template' => 'operations/path_payment.twig',
                'data' => [
                    'from' => $this->accountView($operation['effective_source']),
                    'to' => $this->accountView($details['destination'] ?? null),
                    'source_asset' => $this->assetView($details['source_asset'] ?? null),
                    'dest_asset' => $this->assetView($details['destination_asset'] ?? null),
                    'source_amount' => isset($details['source_amount']) ? (string) $details['source_amount'] : null,
                    'source_max' => isset($details['source_max']) ? (string) $details['source_max'] : null,
                    'dest_amount' => isset($details['destination_amount']) ? (string) $details['destination_amount'] : null,
                    'destination_min' => isset($details['destination_min']) ? (string) $details['destination_min'] : null,
                    'path' => array_map(fn(mixed $asset): array => $this->assetView($asset), $path),
                ],
            ]);
        }
        if ($class === 'CreateAccountOperation') {
            return array_replace($view, [
                'template' => 'operations/create_account.twig',
                'data' => [
                    'account' => $this->accountView($details['destination'] ?? null),
                    'starting_balance' => (string) ($details['starting_balance'] ?? ''),
                ],
            ]);
        }
        if ($class === 'ChangeTrustOperation') {
            $close = $this->zeroDecimal($details['limit'] ?? null);
            return [
                'title' => $this->trans('transactions.operations.change_trust.actions.' . ($close ? 'close' : 'open')),
                'template' => 'operations/change_trust.twig',
                'data' => [
                    'asset' => $this->assetView($details['asset'] ?? null),
                    'limit' => $close || (string) ($details['limit'] ?? '') === '922337203685.4775807'
                        ? null
                        : (string) ($details['limit'] ?? ''),
                ],
            ];
        }
        if ($class === 'ManageSellOfferOperation' || $class === 'ManageBuyOfferOperation') {
            return $this->offerOperationView($details, $class === 'ManageBuyOfferOperation');
        }
        if ($class === 'ManageDataOperation') {
            $delete = (bool) ($details['delete'] ?? false);
            $value = is_string($details['value'] ?? null) ? $details['value'] : null;
            return [
                'title' => $delete
                    ? $this->trans('transactions.operations.manage_data.delete_title')
                    : $view['title'],
                'template' => 'operations/manage_data.twig',
                'data' => [
                    'name' => (string) ($details['key'] ?? ''),
                    'cleared' => $delete,
                    'decoded_value' => $value !== null && $this->isLikelyText($value) ? $value : null,
                    'value_raw' => $value === null ? null : base64_encode($value),
                    'bsn_semantics' => [],
                ],
            ];
        }
        if ($class === 'SetOptionsOperation') {
            return array_replace($view, [
                'template' => 'operations/set_options.twig',
                'data' => $this->setOptionsView($details),
            ]);
        }
        if ($class === 'AccountMergeOperation') {
            return array_replace($view, [
                'template' => 'operations/account_merge.twig',
                'data' => ['into' => $this->accountView($details['destination'] ?? null)],
            ]);
        }
        if ($class === 'BeginSponsoringFutureReservesOperation') {
            return array_replace($view, [
                'template' => 'operations/begin_sponsoring.twig',
                'data' => ['sponsored' => $this->accountView($details['sponsored_account'] ?? null)],
            ]);
        }
        if ($class === 'CreateClaimableBalanceOperation') {
            return array_replace($view, [
                'template' => 'operations/create_claimable_balance.twig',
                'data' => [
                    'sponsor' => $this->accountView($operation['effective_source']),
                    'amount' => (string) ($details['amount'] ?? ''),
                    'asset' => $this->assetView($details['asset'] ?? null),
                    'claimant_count' => (int) ($details['claimant_count'] ?? 0),
                    'claimants' => [],
                ],
            ]);
        }

        return $view;
    }

    /** @param array<string,mixed> $details */
    private function offerOperationView(array $details, bool $buy): array
    {
        $action = (string) ($details['action'] ?? 'update');
        $selling = $this->assetView($details['selling'] ?? null);
        $buying = $this->assetView($details['buying'] ?? null);
        $data = [
            'action_label' => $this->trans('transactions.operations.offer_actions.manage_offer.' . $action),
            'selling' => $selling,
            'buying' => $buying,
            'selling_code' => (string) ($selling['code'] ?? $selling['label'] ?? ''),
            'buying_code' => (string) ($buying['code'] ?? $buying['label'] ?? ''),
            'offer_id' => (int) ($details['offer_id'] ?? 0) ?: null,
            'exchange_amount' => null,
            'target_amount' => null,
            'direct_rate' => null,
            'reverse_rate' => null,
        ];

        if ($action !== 'delete') {
            $amount = (string) ($details['amount'] ?? '0');
            $n = (string) ($details['price_n'] ?? '0');
            $d = (string) ($details['price_d'] ?? '1');
            $price = $this->decimalRatio($n, $d);
            $reverse = $n === '0' ? null : $this->decimalRatio($d, $n);
            if ($buy) {
                $data['exchange_amount'] = bcmul($amount, $price, 7);
                $data['target_amount'] = $amount;
                $data['direct_rate'] = $this->compactDecimal($price);
                $data['reverse_rate'] = $reverse === null ? null : $this->compactDecimal($reverse);
            } else {
                $data['exchange_amount'] = $amount;
                $data['target_amount'] = bcmul($amount, $price, 7);
                $data['direct_rate'] = $reverse === null ? null : $this->compactDecimal($reverse);
                $data['reverse_rate'] = $this->compactDecimal($price);
            }
        }

        return [
            'title' => $data['action_label'],
            'template' => 'operations/manage_offer.twig',
            'data' => $data,
        ];
    }

    /** @return array{id:string}|null */
    private function accountView(mixed $account): ?array
    {
        if (!is_string($account) || $account === '') {
            return null;
        }

        return ['id' => $this->baseAccountId($account) ?? $account];
    }

    /** @return array{code?:string,issuer?:string,is_known?:bool,label:string,url:?string} */
    private function assetView(mixed $asset): array
    {
        $asset = is_string($asset) ? $asset : '';
        if ($asset === 'native') {
            return ['code' => 'XLM', 'is_known' => true, 'label' => 'XLM', 'url' => '/tokens/XLM'];
        }
        if (str_starts_with($asset, 'pool_share(')) {
            return ['label' => $asset, 'url' => null];
        }
        if (!str_contains($asset, ':')) {
            return ['label' => $asset, 'url' => null];
        }

        [$code, $issuer] = explode(':', $asset, 2);
        $known = $this->TokensController->getKnownToken($code . '-' . $issuer) !== null;
        return [
            'code' => $code,
            'issuer' => $issuer,
            'is_known' => $known,
            'label' => $known ? $code : $asset,
            'url' => '/tokens/' . ($known ? $code : $code . '-' . $issuer),
        ];
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function setOptionsView(array $details): array
    {
        $signer = null;
        if (is_string($details['signer_key'] ?? null)) {
            $signerKey = $details['signer_key'];
            $signer = [
                'account' => str_starts_with($signerKey, 'G') ? $this->accountView($signerKey) : null,
                'key' => $signerKey,
                'weight' => $details['signer_weight'] ?? null,
            ];
        }
        $thresholds = [];
        foreach (['low', 'medium', 'high'] as $name) {
            $key = $name . '_threshold';
            if (array_key_exists($key, $details)) {
                $thresholds[$name === 'medium' ? 'med' : $name] = $details[$key];
            }
        }

        return [
            'signer' => $signer,
            'signer_action' => $signer === null ? null : ((int) ($signer['weight'] ?? 0) === 0 ? 'remove' : 'upsert'),
            'master_weight' => $details['master_weight'] ?? null,
            'thresholds' => $thresholds,
            'home_domain' => $details['home_domain'] ?? null,
            'inflation_destination' => $this->accountView($details['inflation_destination'] ?? null),
            'flags' => [
                'set' => array_key_exists('set_flags', $details) ? [(string) $details['set_flags']] : [],
                'clear' => array_key_exists('clear_flags', $details) ? [(string) $details['clear_flags']] : [],
            ],
        ];
    }

    private function decimalRatio(string $numerator, string $denominator): string
    {
        if (preg_match('/\A-?[0-9]+\z/D', $numerator) !== 1
            || preg_match('/\A[1-9][0-9]*\z/D', $denominator) !== 1
        ) {
            return '0';
        }
        return bcdiv($numerator, $denominator, 7);
    }

    private function compactDecimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' || $value === '-' ? '0' : $value;
    }

    private function isLikelyText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    private function zeroDecimal(mixed $value): bool
    {
        return is_scalar($value) && preg_match('/\A[+-]?0+(?:\.0+)?\z/D', (string) $value) === 1;
    }

    private function memoDisplay(TransactionConsolidationMemo $Memo): string
    {
        if ($Memo->type === 'none') {
            return '—';
        }

        $display = $Memo->value;
        return $display !== null && preg_match('//u', $display) === 1
            ? $display
            : $Memo->label;
    }

    private function validSource(string $source): bool
    {
        try {
            if (str_starts_with($source, 'G')) {
                $raw = StrKey::decodeAccountId($source);
                return strlen($raw) === 32 && hash_equals($source, StrKey::encodeAccountId($raw));
            }
            if (str_starts_with($source, 'M')) {
                $raw = StrKey::decodeMuxedAccountId($source);
                return strlen($raw) === 40 && hash_equals($source, StrKey::encodeMuxedAccountId($raw));
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private function baseAccountId(string $source): ?string
    {
        try {
            return MuxedAccount::fromAccountId($source)->getEd25519AccountId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function csrfToken(): string
    {
        return $this->RequestSession->getOrCreateToken(self::CSRF_PURPOSE);
    }

    private function validCsrf(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    private function requestTooLarge(): bool
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        return is_scalar($length) && ctype_digit((string) $length) && (int) $length > self::MAX_REQUEST_BYTES;
    }

    private function postedRevision(): int
    {
        $value = $_POST['revision'] ?? 0;
        return (is_int($value) || (is_string($value) && ctype_digit($value))) ? max(0, (int) $value) : 0;
    }

    private function postedString(string $key, bool $trim = false): string
    {
        return $this->scalarString($_POST[$key] ?? null, $trim);
    }

    private function scalarString(mixed $value, bool $trim = false): string
    {
        $result = is_scalar($value) ? (string) $value : '';
        return $trim ? trim($result) : $result;
    }

    private function trans(string $key, array $parameters = []): string
    {
        return $this->Translator->trans($key, $parameters);
    }

    private function noStoreHeaders(): void
    {
        SimpleRouter::response()->header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        SimpleRouter::response()->header('Pragma: no-cache');
        SimpleRouter::response()->header('Referrer-Policy: no-referrer');
        SimpleRouter::response()->header('X-Content-Type-Options: nosniff');
    }

    private function jsonError(int $status, string $error, ?string $message = null): string
    {
        SimpleRouter::response()->httpCode($status);
        return json_encode(array_filter([
            'status' => 'error',
            'error' => $error,
            'message' => $message,
        ], static fn(mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }
}
