<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Operations\AccountMergeOperationResponse;
use Soneso\StellarSDK\Responses\Operations\BeginSponsoringFutureReservesOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ChangeTrustOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ClawbackOperationResponse;
use Soneso\StellarSDK\Responses\Operations\EndSponsoringFutureReservesOperationResponse;
use Soneso\StellarSDK\Responses\Operations\CreateClaimableBalanceOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageBuyOfferOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageDataOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageSellOfferOperationResponse;
use Soneso\StellarSDK\Responses\Operations\CreatePassiveSellOfferResponse;
use Soneso\StellarSDK\Responses\Operations\OperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentStrictReceiveOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentStrictSendOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\SetTrustlineFlagsOperationResponse;
use Soneso\StellarSDK\Responses\Operations\SetOptionsOperationResponse;
use Soneso\StellarSDK\Responses\Operations\CreateAccountOperationResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class TransactionsController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private ?array $known_tokens = null;
    private Container $Container;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
        $this->Container = $Container;
    }

    public function Index(): ?string
    {
        $tx_hash = isset($_REQUEST['tx_hash']) ? trim((string)$_REQUEST['tx_hash']) : '';
        $error = null;

        if ($tx_hash && BSN::validateTransactionHashFormat($tx_hash)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => $tx_hash]));
            return null;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tx_hash) {
            $error = 'transactions.index.invalid_hash';
        }

        $Template = $this->Twig->load('transactions_index.twig');
        return $Template->render([
            'tx_hash' => $tx_hash,
            'error' => $error,
        ]);
    }

    public function Transaction(string $tx_hash): ?string
    {
        if (!BSN::validateTransactionHashFormat($tx_hash)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if ($tx_hash !== strtolower($tx_hash)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => strtolower($tx_hash)]));
            return null;
        }

        $transaction = $this->fetchTransaction($tx_hash);
        if (!$transaction) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Template = $this->Twig->load('transaction.twig');
        return $Template->render([
            'transaction' => $transaction,
            'tx_hash' => $transaction['hash'],
        ]);
    }

    public function AccountOperations(string $account_id): ?string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($account_id)) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Account = $this->BSN->makeAccountById($account_id);

        $cursor = isset($_GET['cursor']) ? trim((string) $_GET['cursor']) : null;
        if ($cursor === '') {
            $cursor = null;
        }
        if ($cursor !== null && !ctype_digit($cursor)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        $show_spam = filter_var($_GET['show_spam'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $operations_data = $this->collectAccountOperations($Account, $cursor, $show_spam);
        if ($operations_data === null) {
            SimpleRouter::response()->httpCode(502);
            return null;
        }

        $operations_path = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: SimpleRouter::getUrl('account_operations', ['id' => $Account->getId()]);
        $base_query = [];
        if ($cursor !== null) {
            $base_query['cursor'] = $cursor;
        }
        $spam_query = $base_query + ['show_spam' => 'true'];
        $show_spam_url = $operations_path . ($spam_query ? '?' . http_build_query($spam_query) : '');
        $hide_spam_url = $operations_path . ($base_query ? '?' . http_build_query($base_query) : '');

        $next_url = null;
        if ($operations_data['next_cursor']) {
            $next_params = ['cursor' => $operations_data['next_cursor']];
            if ($show_spam) {
                $next_params['show_spam'] = 'true';
            }
            $next_url = $operations_path . '?' . http_build_query($next_params);
        }

        $Template = $this->Twig->load('account_operations.twig');
        return $Template->render([
            'account' => $Account->jsonSerialize(),
            'display_name' => $Account->getDisplayName(),
            'account_short_id' => $Account->getShortId(),
            'operations' => $operations_data['operations'],
            'next_cursor' => $operations_data['next_cursor'],
            'operations_path' => $operations_path,
            'next_url' => $next_url,
            'show_spam' => $show_spam,
            'show_spam_url' => $show_spam_url,
            'hide_spam_url' => $hide_spam_url,
        ]);
    }

    private function fetchTransaction(string $tx_hash): ?array
    {
        $cache_key = 'transaction:' . strtolower($tx_hash) . ':2';
        $cached = apcu_fetch($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $Transaction = $this->Stellar->transactions()->transaction($tx_hash);
        } catch (HorizonRequestException) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        } catch (\Exception) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        }

        $Memo = $Transaction->getMemo();
        $memo_value = $Memo->valueAsString();
        $memo_type = $Memo->typeAsString();
        if (in_array($memo_type, ['hash', 'return'], true) && $memo_value !== null) {
            $decoded = base64_decode($memo_value, true);
            if ($decoded !== false) {
                $memo_value = bin2hex($decoded);
            }
        }
        $data = [
            'hash' => $Transaction->getHash(),
            'ledger' => $Transaction->getLedger(),
            'created_at' => $Transaction->getCreatedAt(),
            'successful' => $Transaction->isSuccessful(),
            'operation_count' => $Transaction->getOperationCount(),
            'fee_charged' => $Transaction->getFeeCharged(),
            'max_fee' => $Transaction->getMaxFee(),
            'fee_charged_xlm' => $this->stroopToXlmString($Transaction->getFeeCharged()),
            'max_fee_xlm' => $this->stroopToXlmString($Transaction->getMaxFee()),
            'source_account' => $Transaction->getSourceAccount(),
            'fee_account' => $Transaction->getFeeAccount(),
            'memo' => [
                'type' => $memo_type,
                'value' => $memo_value,
            ],
            'envelope_xdr' => $Transaction->getEnvelopeXdrBase64(),
            'operations' => $this->fetchOperations($tx_hash),
        ];

        $data['source_account_data'] = $this->BSN->makeAccountById($data['source_account'])->jsonSerialize();
        if ($data['fee_account']) {
            $data['fee_account_data'] = $this->BSN->makeAccountById($data['fee_account'])->jsonSerialize();
        }

        apcu_store($cache_key, $data, 60 * 5);

        return $data;
    }

    private function fetchOperations(string $tx_hash): ?array
    {
        $cache_key = 'transaction_ops:' . strtolower($tx_hash) . ':2';
        $cached = apcu_fetch($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $OperationsPage = $this->Stellar->operations()
                ->forTransaction($tx_hash)
                ->order('asc')
                ->limit(200)
                ->execute();
        } catch (HorizonRequestException) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        } catch (\Exception) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        }

        $operations = [];
        foreach ($OperationsPage->getOperations()->toArray() as $Operation) {
            $operations[] = $this->normalizeOperation($Operation);
        }

        apcu_store($cache_key, $operations, 60 * 5);

        return $operations;
    }

    private function fetchAccountOperations(Account $Account, ?string $cursor): ?array
    {
        $cursor_key = $cursor ?? 'latest';
        $cache_key = 'account_ops:' . strtolower($Account->getId()) . ':' . $cursor_key . ':1';
        $cached = apcu_fetch($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $Builder = $this->Stellar->operations()
                ->forAccount($Account->getId())
                ->order('desc')
                ->limit(200);

            if ($cursor !== null) {
                $Builder = $Builder->cursor($cursor);
            }

            $OperationsPage = $Builder->execute();
        } catch (HorizonRequestException) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        } catch (\Exception) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        }

        $operations = [];
        foreach ($OperationsPage->getOperations()->toArray() as $Operation) {
            $operations[] = $this->normalizeOperation($Operation);
        }

        $next_cursor = null;
        if (!empty($operations)) {
            $last_operation = end($operations);
            $next_cursor = $last_operation['paging_token'] ?? null;
        }

        $result = [
            'operations' => $operations,
            'next_cursor' => $next_cursor,
        ];

        apcu_store($cache_key, $result, 60 * 5);

        return $result;
    }

    private function collectAccountOperations(Account $Account, ?string $cursor, bool $show_spam): ?array
    {
        $operations = [];
        $next_cursor = null;
        $last_batch_count = 0;
        $current_cursor = $cursor;

        while (true) {
            $batch = $this->fetchAccountOperations($Account, $current_cursor);
            if ($batch === null) {
                return null;
            }

            $page_operations = $batch['operations'] ?? [];
            $last_batch_count = count($page_operations);

            if ($show_spam) {
                $operations = $page_operations;
                if ($last_batch_count === 200 && ($batch['next_cursor'] ?? null)) {
                    $next_cursor = $batch['next_cursor'];
                }
                break;
            }

            $filtered = $this->filterAccountOperations($page_operations, $Account);
            $operations = array_merge($operations, $filtered);

            $should_continue = $last_batch_count === 200 && ($batch['next_cursor'] ?? null) && count($operations) < 50;
            if (!$should_continue) {
                if ($last_batch_count === 200 && ($batch['next_cursor'] ?? null)) {
                    $next_cursor = $batch['next_cursor'];
                }
                break;
            }

            $current_cursor = $batch['next_cursor'];
        }

        return [
            'operations' => $operations,
            'next_cursor' => $next_cursor,
        ];
    }

    private function filterAccountOperations(array $operations, Account $Account): array
    {
        $result = [];
        foreach ($operations as $operation) {
            if ($this->isSpamOperation($operation, $Account)) {
                continue;
            }
            $result[] = $operation;
        }

        return $result;
    }

    private function isSpamOperation(array $operation, Account $Account): bool
    {
        if (($operation['type'] ?? null) === 'create_claimable_balance'
            && ($operation['source_account'] ?? null) !== $Account->getId()
        ) {
            return true;
        }

        if ($this->isSmallIncomingSpam($operation, $Account)) {
            return true;
        }

        return false;
    }

    private function isSmallIncomingSpam(array $operation, Account $Account): bool
    {
        $thresholds = [
            ['code' => 'XLM', 'issuer' => null, 'threshold' => 0.1],
            ['code' => 'USDM', 'issuer' => 'GDHDC4GBNPMENZAOBB4NCQ25TGZPDRK6ZGWUGSI22TVFATOLRPSUUSDM', 'threshold' => 0.01],
        ];
        $account_id = $Account->getId();
        $type = $operation['type'] ?? '';
        $data = $operation['data'] ?? [];

        $is_payment = $type === 'payment';
        $is_path = str_contains($type, 'path_payment');

        if ($is_payment && ($data['to']['id'] ?? null) === $account_id) {
            foreach ($thresholds as $rule) {
                if (($data['asset_code'] ?? null) === $rule['code']
                    && ($rule['issuer'] === null || ($data['asset_issuer'] ?? null) === $rule['issuer'])
                    && (float) ($data['amount'] ?? 0) < $rule['threshold']
                ) {
                    return true;
                }
            }
        }

        if ($is_path && ($data['to']['id'] ?? null) === $account_id) {
            foreach ($thresholds as $rule) {
                if (($data['dest_asset_code'] ?? null) === $rule['code']
                    && ($rule['issuer'] === null || ($data['dest_asset_issuer'] ?? null) === $rule['issuer'])
                    && (float) ($data['dest_amount'] ?? 0) < $rule['threshold']
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeOperation(OperationResponse $Operation): array
    {
        $type = $Operation->getHumanReadableOperationType();
        $base = [
            'id' => $Operation->getOperationId(),
            'type' => $type,
            'created_at' => $Operation->getCreatedAt(),
            'source_account' => $Operation->getSourceAccount(),
            'source_account_data' => $this->accountData($Operation->getSourceAccount()),
            'transaction_hash' => $Operation->getTransactionHash(),
            'paging_token' => $Operation->getPagingToken(),
        ];

        return match ($type) {
            'create_account' => $base + $this->normalizeCreateAccount($Operation),
            'payment' => $base + $this->normalizePayment($Operation),
            'path_payment_strict_receive', 'path_payment_strict_send' => $base + $this->normalizePathPayment($Operation),
            'change_trust' => $base + $this->normalizeChangeTrust($Operation),
            'set_trustline_flags', 'set_trust_line_flags' => $base + $this->normalizeSetTrustlineFlags($Operation),
            'manage_sell_offer', 'manage_buy_offer', 'create_passive_sell_offer' => $base + $this->normalizeOffer($Operation),
            'account_merge' => $base + $this->normalizeAccountMerge($Operation),
            'begin_sponsoring_future_reserves' => $base + $this->normalizeBeginSponsoring($Operation),
            'end_sponsoring_future_reserves' => $base + $this->normalizeEndSponsoring($Operation),
            'create_claimable_balance' => $base + $this->normalizeCreateClaimableBalance($Operation),
            'manage_data' => $base + $this->normalizeManageData($Operation),
            'set_options' => $base + $this->normalizeSetOptions($Operation),
            'clawback' => $base + $this->normalizeClawback($Operation),
            default => $base + $this->normalizeUnsupported(),
        };
    }

    private function formatAsset(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'label' => 'XLM',
                'url' => '/tokens/XLM',
            ];
        }

        return $this->formatAssetByParts($Asset->getCode(), $Asset->getIssuer());
    }

    private function formatAssetByParts(string $code, string $issuer): array
    {
        $known = $this->getKnownToken($code, $issuer);
        if ($known) {
            return [
                'label' => $code,
                'url' => '/tokens/' . $code,
            ];
        }

        return [
            'label' => $code . '-' . $issuer,
            'url' => '/tokens/' . $code . '-' . $issuer,
        ];
    }

    private function assetParts(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'code' => 'XLM',
                'issuer' => null,
            ];
        }

        return [
            'code' => $Asset->getCode(),
            'issuer' => $Asset->getIssuer(),
        ];
    }

    private function formatPredicate(\Soneso\StellarSDK\Responses\ClaimableBalances\ClaimantPredicateResponse $Predicate): string
    {
        $t = fn(string $key, array $params = []) => $this->Container->get(Translator::class)->trans($key, $params);

        if ($Predicate->getUnconditional()) {
            return $t('transactions.operations.claimable.predicates.unconditional');
        }

        if ($Predicate->getBeforeAbsoluteTime()) {
            return $t('transactions.operations.claimable.predicates.before_abs', [
                '%time%' => $Predicate->getBeforeAbsoluteTime(),
            ]);
        }

        if ($Predicate->getBeforeRelativeTime()) {
            return $t('transactions.operations.claimable.predicates.before_rel', [
                '%seconds%' => $Predicate->getBeforeRelativeTime(),
            ]);
        }

        if ($Predicate->getAnd()) {
            $parts = array_map([$this, 'formatPredicate'], $Predicate->getAnd()->toArray());
            return $t('transactions.operations.claimable.predicates.and', [
                '%items%' => implode('; ', $parts),
            ]);
        }

        if ($Predicate->getOr()) {
            $parts = array_map([$this, 'formatPredicate'], $Predicate->getOr()->toArray());
            return $t('transactions.operations.claimable.predicates.or', [
                '%items%' => implode('; ', $parts),
            ]);
        }

        if ($Predicate->getNot()) {
            return $t('transactions.operations.claimable.predicates.not', [
                '%inner%' => $this->formatPredicate($Predicate->getNot()),
            ]);
        }

        return $t('transactions.operations.claimable.predicates.unknown');
    }

    private function accountData(?string $account_id): ?array
    {
        if (!$account_id) {
            return null;
        }

        return $this->BSN->makeAccountById($account_id)->jsonSerialize();
    }

    private function getKnownToken(string $code, string $issuer): ?array
    {
        if ($this->known_tokens === null) {
            $this->known_tokens = [];
            $cached = apcu_fetch('known_tokens');
            if (is_array($cached)) {
                foreach ($cached as $item) {
                    $key = ($item['code'] ?? '') . '-' . ($item['issuer'] ?? '');
                    $this->known_tokens[$key] = $item;
                }
            }
        }

        $key = $code . '-' . $issuer;
        return $this->known_tokens[$key] ?? null;
    }

    private function normalizeUnsupported(): array
    {
        return [
            'template' => 'operations/unsupported.twig',
            'unsupported' => true,
        ];
    }

    private function normalizeCreateAccount(OperationResponse $Operation): array
    {
        if (!$Operation instanceof CreateAccountOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/create_account.twig',
            'data' => [
                'funder' => $this->accountData($Operation->getFunder()),
                'account' => $this->accountData($Operation->getAccount()),
                'starting_balance' => $Operation->getStartingBalance(),
            ],
        ];
    }

    private function normalizePayment(OperationResponse $Operation): array
    {
        if (!$Operation instanceof PaymentOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset_parts = $this->assetParts($Operation->getAsset());

        return [
            'template' => 'operations/payment.twig',
            'data' => [
                'from' => $this->accountData($Operation->getFrom()),
                'to' => $this->accountData($Operation->getTo()),
                'amount' => $Operation->getAmount(),
                'asset' => $this->formatAsset($Operation->getAsset()),
                'asset_code' => $asset_parts['code'],
                'asset_issuer' => $asset_parts['issuer'],
            ],
        ];
    }

    private function normalizePathPayment(OperationResponse $Operation): array
    {
        if (!$Operation instanceof PathPaymentOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $dest_asset_parts = $this->assetParts($Operation->getAsset());

        $path = [];
        foreach ($Operation->getPath()->toArray() as $AssetInPath) {
            $path[] = $this->formatAsset($AssetInPath);
        }

        $data = [
            'from' => $this->accountData($Operation->getFrom()),
            'to' => $this->accountData($Operation->getTo()),
            'dest_amount' => $Operation->getAmount(),
            'dest_asset' => $this->formatAsset($Operation->getAsset()),
            'dest_asset_code' => $dest_asset_parts['code'],
            'dest_asset_issuer' => $dest_asset_parts['issuer'],
            'source_amount' => $Operation->getSourceAmount(),
            'source_asset' => $this->formatAsset($Operation->getSourceAsset()),
            'path' => $path,
        ];

        if ($Operation instanceof PathPaymentStrictReceiveOperationResponse) {
            $data['source_max'] = $Operation->getSourceMax();
        }
        if ($Operation instanceof PathPaymentStrictSendOperationResponse) {
            $data['destination_min'] = $Operation->getDestinationMin();
        }

        return [
            'template' => 'operations/path_payment.twig',
            'data' => $data,
        ];
    }

    private function normalizeChangeTrust(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ChangeTrustOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset = $Operation->getAssetType() === Asset::TYPE_POOL_SHARE
            ? [
                'label' => $Operation->getLiquidityPoolId(),
                'url' => null,
            ]
            : $this->formatAssetByParts($Operation->getAssetCode(), $Operation->getAssetIssuer());

        $limit = $Operation->getLimit();
        $is_close = $limit !== null && (float) $limit == 0.0;
        $is_max = $limit !== null && in_array($limit, ['922337203685.4775807', '9223372036854775807'], true);

        return [
            'template' => 'operations/change_trust.twig',
            'data' => [
                'trustor' => $this->accountData($Operation->getTrustor()),
                'trustee' => $this->accountData($Operation->getTrustee()),
                'asset' => $asset,
                'limit' => ($is_close || $is_max) ? null : $limit,
                'action' => $is_close ? 'close' : 'open',
                'action_label' => $is_close
                    ? $this->Container->get(Translator::class)->trans('transactions.operations.change_trust.actions.close')
                    : $this->Container->get(Translator::class)->trans('transactions.operations.change_trust.actions.open'),
            ],
        ];
    }

    private function normalizeSetTrustlineFlags(OperationResponse $Operation): array
    {
        if (!$Operation instanceof SetTrustlineFlagsOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset = $this->formatAsset(Asset::create($Operation->getAssetType(), $Operation->getAssetCode(), $Operation->getAssetIssuer()));

        return [
            'template' => 'operations/set_trustline_flags.twig',
            'data' => [
                'trustor' => $this->accountData($Operation->getTrustor()),
                'asset' => $asset,
                'set_flags' => $Operation->getSetFlagsS() ?? $Operation->getSetFlags(),
                'clear_flags' => $Operation->getClearFlagsS() ?? $Operation->getClearFlags(),
            ],
        ];
    }

    private function normalizeOffer(OperationResponse $Operation): array
    {
        $template = 'operations/manage_offer.twig';

        if ($Operation instanceof ManageSellOfferOperationResponse) {
            $mode = 'manage_sell_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = $Operation->getOfferId();
        } elseif ($Operation instanceof ManageBuyOfferOperationResponse) {
            $mode = 'manage_buy_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = $Operation->getOfferId();
        } elseif ($Operation instanceof CreatePassiveSellOfferResponse) {
            $mode = 'create_passive_sell_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = null;
        } else {
            return $this->normalizeUnsupported();
        }

        $action_key = 'manage_offer.create';
        $is_delete = false;
        if ($offer_id) {
            $is_delete = ((float)$amount === 0.0);
            $action_key = $is_delete ? 'manage_offer.delete' : 'manage_offer.update';
        }

        return [
            'template' => $template,
            'data' => [
                'action_label' => $this->Container->get(Translator::class)->trans('transactions.operations.offer_actions.' . $action_key),
                'selling' => $this->formatAsset($selling),
                'buying' => $this->formatAsset($buying),
                'amount' => $is_delete ? null : $amount,
                'price' => $is_delete ? null : $price,
                'offer_id' => $offer_id,
            ],
        ];
    }

    private function normalizeAccountMerge(OperationResponse $Operation): array
    {
        if (!$Operation instanceof AccountMergeOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/account_merge.twig',
            'data' => [
                'account' => $this->accountData($Operation->getAccount()),
                'into' => $this->accountData($Operation->getInto()),
            ],
        ];
    }

    private function normalizeBeginSponsoring(OperationResponse $Operation): array
    {
        if (!$Operation instanceof BeginSponsoringFutureReservesOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/begin_sponsoring.twig',
            'data' => [
                'sponsored' => $this->accountData($Operation->getSponsoredId()),
            ],
        ];
    }

    private function normalizeEndSponsoring(OperationResponse $Operation): array
    {
        if (!$Operation instanceof EndSponsoringFutureReservesOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/end_sponsoring.twig',
            'data' => [
                'begin_sponsor' => $this->accountData($Operation->getBeginSponsor()),
            ],
        ];
    }

    private function normalizeCreateClaimableBalance(OperationResponse $Operation): array
    {
        if (!$Operation instanceof CreateClaimableBalanceOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset_parts = $this->assetParts($Operation->getAsset());
        $claimants = [];
        foreach ($Operation->getClaimants()->toArray() as $Claimant) {
            $claimants[] = [
                'destination' => $this->accountData($Claimant->getDestination()),
                'predicate' => $this->formatPredicate($Claimant->getPredicate()),
            ];
        }

        return [
            'template' => 'operations/create_claimable_balance.twig',
            'data' => [
                'sponsor' => $this->accountData($Operation->getSponsor()),
                'amount' => $Operation->getAmount(),
                'asset' => $this->formatAsset($Operation->getAsset()),
                'asset_code' => $asset_parts['code'],
                'asset_issuer' => $asset_parts['issuer'],
                'claimants' => $claimants,
            ],
        ];
    }

    private function normalizeManageData(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ManageDataOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $value = $Operation->getValue();
        $decoded_value = null;
        if ($value !== null) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false && $this->isLikelyText($decoded)) {
                $decoded_value = $decoded;
            }
        }

        $is_cleared = $value === null || $value === '';

        $action_label = $is_cleared
            ? $this->Container->get(Translator::class)->trans('transactions.operations.manage_data.delete_title')
            : null;

        return [
            'template' => 'operations/manage_data.twig',
            'data' => [
                'name' => $Operation->getName(),
                'value_raw' => $value,
                'decoded_value' => $decoded_value,
                'cleared' => $is_cleared,
                'action_label' => $action_label,
            ],
        ];
    }

    private function isLikelyText(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        // Allow printable plus whitespace \r\n\t
        return !preg_match('/[^\P{C}\r\n\t]/u', $value);
    }

    private function normalizeClawback(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ClawbackOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/clawback.twig',
            'data' => [
                'from' => $this->accountData($Operation->getFrom()),
                'asset' => $this->formatAsset($Operation->getAsset()),
                'amount' => $Operation->getAmount(),
            ],
        ];
    }

    private function stroopToXlmString(?int $stroops): ?string
    {
        if ($stroops === null) {
            return null;
        }

        return number_format($stroops * 0.0000001, 7, '.', '');
    }

    private function normalizeSetOptions(OperationResponse $Operation): array
    {
        if (!$Operation instanceof SetOptionsOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $signer_key = $Operation->getSignerKey();
        $signer_weight = $Operation->getSignerWeight();
        $signer = null;
        $signer_action = null;
        if ($signer_key !== null) {
            $signer = [
                'key' => $signer_key,
                'account' => $this->BSN::validateStellarAccountIdFormat($signer_key)
                    ? $this->accountData($signer_key)
                    : null,
                'weight' => $signer_weight,
            ];
            if ($signer_weight === 0) {
                $signer_action = 'remove';
            } elseif ($signer_weight !== null) {
                $signer_action = 'upsert';
            }
        }

        $thresholds = [];
        if ($Operation->getLowThreshold() !== null) {
            $thresholds['low'] = $Operation->getLowThreshold();
        }
        if ($Operation->getMedThreshold() !== null) {
            $thresholds['med'] = $Operation->getMedThreshold();
        }
        if ($Operation->getHighThreshold() !== null) {
            $thresholds['high'] = $Operation->getHighThreshold();
        }

        $flags = [
            'set' => $Operation->getSetFlagsS() ?? $Operation->getSetFlags(),
            'clear' => $Operation->getClearFlagsS() ?? $Operation->getClearFlags(),
        ];

        return [
            'template' => 'operations/set_options.twig',
            'data' => [
                'signer' => $signer,
                'signer_action' => $signer_action,
                'master_weight' => $Operation->getMasterKeyWeight(),
                'thresholds' => $thresholds,
                'home_domain' => $Operation->getHomeDomain(),
                'inflation_destination' => $this->accountData($Operation->getInflationDestination()),
                'flags' => $flags,
            ],
        ];
    }
}
