<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\Tag;
use Montelibero\BSN\TagCategory;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class Editor2Controller
{
    private const KEEP_SINGLE_VALUE = '__keep';
    private const MANY_COUNTERPARTIES_LEGEND_THRESHOLD = 5;

    public function __construct(
        private readonly BSN $BSN,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly CurrentUser $CurrentUser,
        private readonly ContactsManager $ContactsManager,
        private readonly Container $Container,
        private readonly Translator $Translator,
    ) {
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function LegacyPathRedirect(string $legacy_source_id): ?string
    {
        if (!$this->isValidAccountId($legacy_source_id)) {
            SimpleRouter::response()->redirect('/editor/', 302);
            return null;
        }

        // Legacy /editor compatibility: translate /editor/{source}/ links to the new query-mode entrypoint.
        $this->applyLegacySourceAccount(strtoupper($legacy_source_id));
        if ($single_account_edit_tags_url = $this->buildLegacySingleAccountEditTagsRedirectUrl(strtoupper($legacy_source_id), true)) {
            SimpleRouter::response()->redirect($single_account_edit_tags_url, 302);
            return null;
        }

        SimpleRouter::response()->redirect($this->buildLegacyPathRedirectUrl(strtoupper($legacy_source_id)), 302);
        return null;
    }

    public function Editor(): ?string
    {
        $notice = null;
        // Legacy /editor compatibility: old form links used /editor/?id={source_account_id}.
        if (($query_source_id = $this->getRequestAccountId('id')) !== null) {
            $this->applyLegacySourceAccount($query_source_id);
            if ($single_account_edit_tags_url = $this->buildLegacySingleAccountEditTagsRedirectUrl($query_source_id, false)) {
                SimpleRouter::response()->redirect($single_account_edit_tags_url, 302);
                return null;
            }

            SimpleRouter::response()->redirect($this->buildLegacyQueryRedirectUrl($query_source_id), 302);
            return null;
        }

        $source_account_id = $this->CurrentUser->getCurrentAccountId();

        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/editor/'),
                302
            );
            return null;
        }
        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $action = (string) ($_POST['action'] ?? '');
        $selected_tag_names = $this->getSelectedTagNames();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start') {
            if (!$selected_tag_names) {
                return $this->renderStartScreen(
                    $source_account_id,
                    $notice,
                    $this->Translator->trans('editor2.errors.no_tags'),
                );
            }

            SimpleRouter::response()->redirect(
                $this->buildEditUrl(
                    $selected_tag_names,
                    $this->parseAccountList((string) ($_POST['counterparties'] ?? ''))
                ),
                303
            );
            return null;
        }

        if (!$selected_tag_names && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->renderStartScreen($source_account_id, $notice);
        }

        if (!$selected_tag_names) {
            return $this->renderStartScreen(
                $source_account_id,
                $notice,
                $this->Translator->trans('editor2.errors.no_tags'),
            );
        }

        $data_entries = $this->parseDataEntries($this->fetchAccountData($source_account_id));
        $counterparty_ids = $this->getCounterpartyIds($source_account_id, $selected_tag_names, $data_entries);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_accounts') {
            $counterparty_ids = array_values(array_unique(array_merge(
                $counterparty_ids,
                $this->parseAccountList((string) ($_POST['add_accounts'] ?? ''))
            )));
        }

        $signing_form = null;
        $transaction_summary = null;
        $no_changes = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
            $save_result = $this->buildSaveTransaction(
                $source_account_id,
                $selected_tag_names,
                $counterparty_ids,
                $data_entries,
                $_POST['tag'] ?? [],
                $_POST['single_tag'] ?? [],
            );

            if ($save_result['xdr']) {
                $signing_form = $this->Container
                    ->get(SignController::class)
                    ->SignTransaction($save_result['xdr'], null, 'BSN tags update');
                $transaction_summary = $save_result['summary'];
            } else {
                $no_changes = true;
            }
        }

        return $this->renderEditScreen(
            $source_account_id,
            $selected_tag_names,
            $counterparty_ids,
            $data_entries,
            $this->hasExplicitCounterparties(),
            $notice,
            $signing_form,
            $transaction_summary,
            $no_changes
        );
    }

    private function renderStartScreen(
        string $source_account_id,
        ?array $notice,
        ?string $error = null,
    ): string
    {
        $Template = $this->Twig->load('editor2_start.twig');

        return $Template->render([
            'source_account' => $this->BSN->makeAccountById($source_account_id)->jsonSerialize(),
            'tag_categories' => $this->buildSelectionTagCategories($this->getSelectedTagNames()),
            'counterparties_value' => (string) (
                $_POST['counterparties']
                ?? $_GET['contacts']
                ?? $_GET['contact']
                ?? ''
            ),
            'custom_tags_value' => (string) ($_POST['custom_tags'] ?? ''),
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'notice' => $notice,
            'error' => $error,
        ]);
    }

    private function renderEditScreen(
        string $source_account_id,
        array $selected_tag_names,
        array $counterparty_ids,
        array $data_entries,
        bool $has_explicit_counterparties,
        ?array $notice,
        ?string $signing_form,
        ?array $transaction_summary,
        bool $no_changes,
    ): string {
        $counterparties = [];
        $show_tag_legend = count($counterparty_ids) > self::MANY_COUNTERPARTIES_LEGEND_THRESHOLD;
        $single_values = $this->getCurrentSingleValues($selected_tag_names, $data_entries);
        $desired_single_values = $this->getDesiredSingleValues($single_values);
        $counterparty_set = array_fill_keys($counterparty_ids, true);
        $posted_counterparty_set = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? array_fill_keys($this->parseAccountList((string) ($_POST['counterparties'] ?? '')), true)
            : [];
        $desired_multi_tags = (array) ($_POST['tag'] ?? []);

        foreach ($single_values as $tag_name => $entry) {
            $value = $entry['value'];
            if (
                ($desired_single_values[$tag_name] ?? null) === self::KEEP_SINGLE_VALUE
                && BSN::validateStellarAccountIdFormat($value)
                && isset($counterparty_set[$value])
            ) {
                $desired_single_values[$tag_name] = $value;
            }
        }

        foreach ($counterparty_ids as $account_id) {
            $Account = $this->BSN->makeAccountById($account_id);
            $current_tag_names = $this->getCurrentTagNamesForCounterparty($selected_tag_names, $data_entries, $account_id);
            $reciprocal_tag_names = $this->getReciprocalTagNames($this->BSN->makeAccountById($source_account_id), $Account, $selected_tag_names);
            $counterparties[] = [
                'account' => $Account->jsonSerialize(),
                'current_tag_names' => $current_tag_names,
                'reciprocal_tag_names' => array_values(array_unique(array_filter($reciprocal_tag_names))),
                'tag_categories' => $this->buildEditTagCategories(
                    $selected_tag_names,
                    $current_tag_names,
                    $reciprocal_tag_names,
                    $account_id,
                    $desired_single_values,
                    $desired_multi_tags,
                    isset($posted_counterparty_set[$account_id]),
                    !$show_tag_legend
                ),
            ];
        }

        $single_keep_values = [];
        $single_empty_values = [];
        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            if (!$Tag->isSingle()) {
                continue;
            }

            $value = $single_values[$tag_name]['value'] ?? null;
            $desired_value = $desired_single_values[$tag_name] ?? '';
            if ($value !== null && (!BSN::validateStellarAccountIdFormat($value) || !isset($counterparty_set[$value]))) {
                $single_keep_values[] = [
                    'tag_name' => $tag_name,
                    'value' => $value,
                    'value_account' => BSN::validateStellarAccountIdFormat($value)
                        ? $this->BSN->makeAccountById($value)->jsonSerialize()
                        : null,
                    'checked' => $desired_value === self::KEEP_SINGLE_VALUE,
                ];
            }

            $single_empty_values[] = [
                'tag_name' => $tag_name,
                'checked' => $desired_value === '',
            ];
        }

        $Template = $this->Twig->load('editor2_edit.twig');

        return $Template->render([
            'source_account' => $this->BSN->makeAccountById($source_account_id)->jsonSerialize(),
            'selected_tags' => $selected_tag_names,
            'counterparty_ids_value' => implode(', ', $counterparty_ids),
            'counterparties' => $counterparties,
            'show_tag_legend' => $show_tag_legend,
            'tag_legend_categories' => $show_tag_legend ? $this->buildTagLegendCategories($selected_tag_names) : [],
            'single_keep_values' => $single_keep_values,
            'single_empty_values' => $single_empty_values,
            'keep_single_value' => self::KEEP_SINGLE_VALUE,
            'has_explicit_counterparties' => $has_explicit_counterparties,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
            'notice' => $notice,
            'signing_form' => $signing_form,
            'transaction_summary' => $transaction_summary,
            'no_changes' => $no_changes,
        ]);
    }

    private function buildSelectionTagCategories(array $selected_tag_names): array
    {
        $descriptions = $this->loadKnownTagDescriptions();
        $selected_tag_set = array_fill_keys($selected_tag_names, true);
        $categories = [];

        foreach ($this->BSN->getTags() as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }

            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();
            if ($Category->isUnknown() && !isset($selected_tag_set[$Tag->getName()])) {
                continue;
            }

            $this->addTagToCategory($categories, $Category, $Tag, $descriptions, $selected_tag_set);
        }

        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();
            if ($Category->isUnknown()) {
                $this->addTagToCategory($categories, $Category, $Tag, $descriptions, $selected_tag_set);
            }
        }

        $this->sortCategories($categories);

        return array_values($categories);
    }

    private function buildEditTagCategories(
        array $selected_tag_names,
        array $current_tag_names,
        array $reciprocal_tag_names,
        string $counterparty_id,
        array $desired_single_values,
        array $desired_multi_tags,
        bool $use_posted_multi_values,
        bool $include_descriptions = true,
    ): array {
        $descriptions = $include_descriptions ? $this->loadKnownTagDescriptions() : [];
        $current_tag_set = array_fill_keys($current_tag_names, true);
        $reciprocal_tag_set = array_fill_keys($reciprocal_tag_names, true);
        $categories = [];

        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();
            $desired_single_value = $desired_single_values[$tag_name] ?? null;
            $checked = $Tag->isSingle()
                ? $desired_single_value === $counterparty_id
                : (
                    $use_posted_multi_values
                        ? isset($desired_multi_tags[$tag_name][$counterparty_id])
                        : isset($current_tag_set[$tag_name])
                );

            $this->addTagToCategory(
                $categories,
                $Category,
                $Tag,
                $descriptions,
                [$tag_name => $checked],
                $this->resolveReciprocalTagName($Tag, $reciprocal_tag_set)
            );
        }

        $this->sortCategories($categories);

        return array_values($categories);
    }

    private function buildTagLegendCategories(array $selected_tag_names): array
    {
        $descriptions = $this->loadKnownTagDescriptions();
        $categories = [];

        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();

            $this->addTagToCategory(
                $categories,
                $Category,
                $Tag,
                $descriptions,
                []
            );
        }

        $this->sortCategories($categories);

        return array_values($categories);
    }

    private function addTagToCategory(
        array &$categories,
        TagCategory $Category,
        Tag $Tag,
        array $descriptions,
        array $checked_tag_names,
        ?string $reciprocal_tag_name = null,
    ): void {
        $category_id = $Category->getId();
        if (!isset($categories[$category_id])) {
            $categories[$category_id] = [
                'id' => $category_id,
                'name' => $Category->getName(),
                'is_unknown' => $Category->isUnknown(),
                'tags' => [],
            ];
        }

        $categories[$category_id]['tags'][$Tag->getName()] = [
            'name' => $Tag->getName(),
            'is_single' => $Tag->isSingle(),
            'checked' => (bool) ($checked_tag_names[$Tag->getName()] ?? false),
            'description' => $descriptions[$Tag->getName()] ?? '',
            'reciprocal_tag_name' => $reciprocal_tag_name,
        ];
    }

    private function sortCategories(array &$categories): void
    {
        foreach ($categories as &$category) {
            $this->sortTags($category['tags']);
        }
        unset($category);

        WebApp::semantic_sort_keys($categories, TagCategory::SORT_EXAMPLE);
    }

    private function sortTags(array &$tags): void
    {
        uasort($tags, function (array $a, array $b): int {
            $index_a = array_search($a['name'], WebApp::$sort_tags_example, true);
            $index_b = array_search($b['name'], WebApp::$sort_tags_example, true);
            if ($index_a !== false && $index_b !== false) {
                return $index_a <=> $index_b;
            }
            if ($index_a !== false) {
                return -1;
            }
            if ($index_b !== false) {
                return 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });
    }

    private function getSelectedTagNames(): array
    {
        $tag_names = [];

        foreach ((array) ($_POST['tags'] ?? []) as $tag_name) {
            if (!is_string($tag_name)) {
                continue;
            }

            $tag_name = $this->normalizeEditableTagName($tag_name);
            if ($tag_name !== null) {
                $tag_names[$tag_name] = true;
            }
        }

        foreach ($this->parseTagList((string) ($_POST['selected_tags'] ?? '')) as $tag_name) {
            $tag_names[$tag_name] = true;
        }

        foreach ($this->parseTagList((string) ($_POST['custom_tags'] ?? '')) as $tag_name) {
            $tag_names[$tag_name] = true;
        }

        $query_tag_value = isset($_GET['tags']) ? (string) $_GET['tags'] : (string) ($_GET['tag'] ?? '');
        foreach ($this->parseTagList($query_tag_value) as $tag_name) {
            $tag_names[$tag_name] = true;
        }

        return $this->sortTagNames(array_keys($tag_names));
    }

    private function sortTagNames(array $tag_names): array
    {
        usort($tag_names, function (string $a, string $b): int {
            $index_a = array_search($a, WebApp::$sort_tags_example, true);
            $index_b = array_search($b, WebApp::$sort_tags_example, true);
            if ($index_a !== false && $index_b !== false) {
                return $index_a <=> $index_b;
            }
            if ($index_a !== false) {
                return -1;
            }
            if ($index_b !== false) {
                return 1;
            }

            return strcasecmp($a, $b);
        });

        return $tag_names;
    }

    private function getCounterpartyIds(
        string $source_account_id,
        array $selected_tag_names,
        array $data_entries,
    ): array
    {
        $posted_counterparties = $this->parseAccountList((string) ($_POST['counterparties'] ?? ''));
        if ($posted_counterparties) {
            return $posted_counterparties;
        }

        $query_counterparties = $this->parseAccountList(
            isset($_GET['contacts'])
                ? (string) $_GET['contacts']
                : (string) ($_GET['contact'] ?? '')
        );
        if ($query_counterparties) {
            return $query_counterparties;
        }

        $SourceAccount = $this->BSN->makeAccountById($source_account_id);
        $candidates = [];
        $priorities = [];

        foreach ($data_entries as $tag_name => $entries) {
            foreach ($entries as $entry) {
                $value = $entry['value'];
                if (!BSN::validateStellarAccountIdFormat($value)) {
                    continue;
                }
                $candidates[$value] = true;
                $priorities[$value] ??= 3;
                if (in_array($tag_name, $selected_tag_names, true)) {
                    $priorities[$value] = min($priorities[$value], 1);
                }
            }
        }

        foreach ($this->getReciprocalCandidateIds($SourceAccount, $selected_tag_names) as $account_id) {
            $candidates[$account_id] = true;
            $priorities[$account_id] = min($priorities[$account_id] ?? 3, 2);
        }

        if ($this->CurrentUser->isAuthorized()) {
            foreach ($this->ContactsManager->getContacts($this->CurrentUser->getAccountId()) as $account_id => $_) {
                if (!BSN::validateStellarAccountIdFormat($account_id)) {
                    continue;
                }
                $candidates[$account_id] = true;
                $priorities[$account_id] ??= 3;
            }
        }

        $ids = array_keys($candidates);
        usort($ids, function (string $a, string $b) use ($priorities): int {
            $priority_compare = ($priorities[$a] ?? 3) <=> ($priorities[$b] ?? 3);
            if ($priority_compare !== 0) {
                return $priority_compare;
            }

            return strcasecmp(
                $this->BSN->makeAccountById($a)->getDisplayName(),
                $this->BSN->makeAccountById($b)->getDisplayName()
            );
        });

        return $ids;
    }

    /**
     * @return string[]
     */
    private function getReciprocalCandidateIds(Account $SourceAccount, array $selected_tag_names): array
    {
        $ids = [];
        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            foreach ($SourceAccount->getIncomeLinks($Tag) as $Account) {
                $ids[$Account->getId()] = true;
            }

            if ($PairTag = $Tag->getPair()) {
                foreach ($SourceAccount->getIncomeLinks($PairTag) as $Account) {
                    $ids[$Account->getId()] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return string[]
     */
    private function getCurrentTagNamesForCounterparty(array $selected_tag_names, array $data_entries, string $counterparty_id): array
    {
        $tag_names = [];
        foreach ($selected_tag_names as $tag_name) {
            foreach ($data_entries[$tag_name] ?? [] as $entry) {
                if ($entry['value'] === $counterparty_id) {
                    $tag_names[$tag_name] = true;
                    break;
                }
            }
        }

        return array_keys($tag_names);
    }

    /**
     * @return string[]
     */
    private function getReciprocalTagNames(Account $SourceAccount, Account $Counterparty, array $selected_tag_names): array
    {
        $tag_names = [];
        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            if (in_array($SourceAccount, $Counterparty->getOutcomeLinks($Tag), true)) {
                $tag_names[] = $Tag->getName();
            }

            if (($PairTag = $Tag->getPair()) && in_array($SourceAccount, $Counterparty->getOutcomeLinks($PairTag), true)) {
                $tag_names[] = $PairTag->getName();
            }
        }

        return $tag_names;
    }

    private function resolveReciprocalTagName(Tag $Tag, array $reciprocal_tag_set): ?string
    {
        if (isset($reciprocal_tag_set[$Tag->getName()])) {
            return $Tag->getName();
        }

        $PairTag = $Tag->getPair();
        if ($PairTag && isset($reciprocal_tag_set[$PairTag->getName()])) {
            return $PairTag->getName();
        }

        return null;
    }

    private function getCurrentSingleValues(array $selected_tag_names, array $data_entries): array
    {
        $values = [];
        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            if (!$Tag->isSingle()) {
                continue;
            }

            foreach ($data_entries[$tag_name] ?? [] as $entry) {
                if ($entry['key'] === $tag_name) {
                    $values[$tag_name] = $entry;
                    break;
                }
            }
        }

        return $values;
    }

    private function getDesiredSingleValues(array $current_single_values): array
    {
        $posted = (array) ($_POST['single_tag'] ?? []);
        $desired = [];
        foreach ($posted as $tag_name => $value) {
            if (!$this->validateEditableTagName((string) $tag_name)) {
                continue;
            }
            $value = (string) $value;
            if ($value === self::KEEP_SINGLE_VALUE || $value === '' || BSN::validateStellarAccountIdFormat($value)) {
                $desired[$tag_name] = $value;
            }
        }

        foreach ($current_single_values as $tag_name => $entry) {
            if (isset($desired[$tag_name])) {
                continue;
            }
            $value = $entry['value'];
            $desired[$tag_name] = BSN::validateStellarAccountIdFormat($value) ? $value : self::KEEP_SINGLE_VALUE;
        }

        return $desired;
    }

    private function buildSaveTransaction(
        string $source_account_id,
        array $selected_tag_names,
        array $counterparty_ids,
        array $data_entries,
        array $desired_multi_tags,
        array $desired_single_tags,
    ): array {
        $counterparty_set = array_fill_keys($counterparty_ids, true);
        $keys_to_remove = [];
        $tags_to_remove = [];
        $tags_to_add = [];
        $operations = [];
        $summary_add_by_account = [];
        $summary_remove_by_account = [];
        $summary_add_by_tag = [];
        $summary_remove_by_tag = [];
        $summary_clear_tags = [];

        foreach ($selected_tag_names as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $entries = $data_entries[$tag_name] ?? [];

            if ($Tag->isSingle()) {
                $desired_value = (string) ($desired_single_tags[$tag_name] ?? self::KEEP_SINGLE_VALUE);
                if ($desired_value === self::KEEP_SINGLE_VALUE) {
                    continue;
                }

                if ($desired_value === '') {
                    foreach ($entries as $entry) {
                        $keys_to_remove[$entry['key']] = true;
                        $tags_to_remove[$tag_name] = true;
                    }
                    if ($entries) {
                        $summary_clear_tags[$tag_name] = true;
                    }
                    continue;
                }

                if (!BSN::validateStellarAccountIdFormat($desired_value)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if ($entry['key'] !== $tag_name) {
                        $keys_to_remove[$entry['key']] = true;
                    }
                }

                $current_value = $this->getExactDataEntryValue($entries, $tag_name);
                if (($current_value ?? null) !== $desired_value) {
                    if (BSN::validateStellarAccountIdFormat($current_value)) {
                        $this->addSummaryAccountTag($summary_remove_by_account, $summary_remove_by_tag, $current_value, $tag_name);
                    }
                    $this->addSummaryAccountTag($summary_add_by_account, $summary_add_by_tag, $desired_value, $tag_name);
                    $tags_to_add[$tag_name] = [$desired_value];
                    $operations[] = (new ManageDataOperationBuilder($tag_name, $desired_value))->build();
                }
                continue;
            }

            $desired_values = [];
            foreach ((array) ($desired_multi_tags[$tag_name] ?? []) as $account_id => $_) {
                if (is_string($account_id) && isset($counterparty_set[$account_id])) {
                    $desired_values[$account_id] = true;
                }
            }

            $current_values = [];
            foreach ($entries as $entry) {
                $value = $entry['value'];
                if (!isset($counterparty_set[$value])) {
                    continue;
                }
                $current_values[$value] = true;
                if (!isset($desired_values[$value])) {
                    $keys_to_remove[$entry['key']] = true;
                    $tags_to_remove[$tag_name][] = $value;
                    $this->addSummaryAccountTag($summary_remove_by_account, $summary_remove_by_tag, $value, $tag_name);
                }
            }

            foreach (array_keys($desired_values) as $account_id) {
                if (!isset($current_values[$account_id])) {
                    $tags_to_add[$tag_name][] = $account_id;
                    $this->addSummaryAccountTag($summary_add_by_account, $summary_add_by_tag, $account_id, $tag_name);
                }
            }
        }

        foreach (array_keys($keys_to_remove) as $key) {
            $operations[] = (new ManageDataOperationBuilder($key, null))->build();
        }

        foreach ($tags_to_add as $tag_name => $account_ids) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            if ($Tag->isSingle()) {
                continue;
            }

            $entries = $data_entries[$tag_name] ?? [];
            foreach ($account_ids as $account_id) {
                $key_name = $this->findFirstFreeTagDataKey($tag_name, $entries, $keys_to_remove);
                $keys_to_remove[$key_name] = true;
                $operations[] = (new ManageDataOperationBuilder($key_name, $account_id))->build();
            }
        }

        if (!$operations) {
            return [
                'xdr' => null,
                'summary' => null,
            ];
        }

        $StellarAccount = $this->Stellar->requestAccount($source_account_id);
        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('BSN tags update'));
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addOperations($operations);

        return [
            'xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
            'summary' => [
                'remove' => array_keys($tags_to_remove),
                'add' => array_keys($tags_to_add),
                'operations_count' => count($operations),
                'report' => $this->buildTransactionReport(
                    $summary_add_by_account,
                    $summary_remove_by_account,
                    $summary_add_by_tag,
                    $summary_remove_by_tag,
                    $summary_clear_tags
                ),
            ],
        ];
    }

    private function addSummaryAccountTag(array &$by_account, array &$by_tag, string $account_id, string $tag_name): void
    {
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return;
        }

        $by_account[$account_id][$tag_name] = true;
        $by_tag[$tag_name][$account_id] = true;
    }

    private function buildTransactionReport(
        array $add_by_account,
        array $remove_by_account,
        array $add_by_tag,
        array $remove_by_tag,
        array $clear_tags,
    ): array {
        $affected_account_ids = array_values(array_unique(array_merge(
            array_keys($add_by_account),
            array_keys($remove_by_account)
        )));

        return [
            'mode' => count($affected_account_ids) <= self::MANY_COUNTERPARTIES_LEGEND_THRESHOLD ? 'accounts' : 'tags',
            'accounts' => $this->buildTransactionReportAccountGroups($affected_account_ids, $add_by_account, $remove_by_account),
            'add_tags' => $this->buildTransactionReportTagGroups($add_by_tag),
            'remove_tags' => $this->buildTransactionReportTagGroups($remove_by_tag),
            'clear_tags' => $this->sortTagNames(array_keys($clear_tags)),
        ];
    }

    private function buildTransactionReportAccountGroups(array $account_ids, array $add_by_account, array $remove_by_account): array
    {
        usort($account_ids, function (string $a, string $b): int {
            return strcasecmp(
                $this->BSN->makeAccountById($a)->getDisplayName(),
                $this->BSN->makeAccountById($b)->getDisplayName()
            );
        });

        $groups = [];
        foreach ($account_ids as $account_id) {
            $groups[] = [
                'account' => $this->BSN->makeAccountById($account_id)->jsonSerialize(),
                'add' => $this->sortTagNames(array_keys($add_by_account[$account_id] ?? [])),
                'remove' => $this->sortTagNames(array_keys($remove_by_account[$account_id] ?? [])),
            ];
        }

        return $groups;
    }

    private function buildTransactionReportTagGroups(array $by_tag): array
    {
        $groups = [];
        foreach ($this->sortTagNames(array_keys($by_tag)) as $tag_name) {
            $account_ids = array_keys($by_tag[$tag_name]);
            usort($account_ids, function (string $a, string $b): int {
                return strcasecmp(
                    $this->BSN->makeAccountById($a)->getDisplayName(),
                    $this->BSN->makeAccountById($b)->getDisplayName()
                );
            });

            $groups[] = [
                'tag_name' => $tag_name,
                'accounts' => array_map(
                    fn(string $account_id): array => $this->BSN->makeAccountById($account_id)->jsonSerialize(),
                    $account_ids
                ),
            ];
        }

        return $groups;
    }

    private function getExactDataEntryValue(array $entries, string $key_name): ?string
    {
        foreach ($entries as $entry) {
            if ($entry['key'] === $key_name) {
                return $entry['value'];
            }
        }

        return null;
    }

    private function findFirstFreeTagDataKey(string $tag_name, array $entries, array $keys_to_skip): string
    {
        $occupied = [];
        foreach ($entries as $entry) {
            if (isset($keys_to_skip[$entry['key']])) {
                continue;
            }
            if ($entry['suffix'] !== null && $entry['suffix'] > 0) {
                $occupied[$entry['suffix']] = true;
            }
        }

        for ($suffix = 1; ; $suffix++) {
            if (!isset($occupied[$suffix])) {
                return $tag_name . $suffix;
            }
        }
    }

    private function parseDataEntries(array $raw_data): array
    {
        $result = [];

        foreach ($raw_data as $key_name => $encoded_value) {
            $value = base64_decode((string) $encoded_value, true);
            if ($value === false || $value === '') {
                continue;
            }
            if (!preg_match('/^\s*(?<tag>[a-z0-9_]+?)\s*(?<suffix>\d*)\s*$/i', (string) $key_name, $match)) {
                continue;
            }

            $tag_name = $match['tag'];
            $suffix = $match['suffix'] === '' ? null : (int) $match['suffix'];
            $result[$tag_name] ??= [];
            $result[$tag_name][] = [
                'key' => (string) $key_name,
                'value' => $value,
                'suffix' => $suffix,
            ];
        }

        return $result;
    }

    private function parseTagList(string $value): array
    {
        $tag_names = [];
        foreach (preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag_name) {
            $tag_name = $this->normalizeEditableTagName($tag_name);
            if ($tag_name !== null) {
                $tag_names[$tag_name] = true;
            }
        }

        return array_keys($tag_names);
    }

    private function parseAccountList(string $value): array
    {
        $ids = [];
        foreach (preg_split('/\s*,\s*/', strtoupper(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $account_id) {
            if (BSN::validateStellarAccountIdFormat($account_id)) {
                $ids[$account_id] = true;
            }
        }

        return array_keys($ids);
    }

    private function getRequestAccountId(string $key): ?string
    {
        $account_id = strtoupper(trim((string) ($_GET[$key] ?? '')));
        return BSN::validateStellarAccountIdFormat($account_id) ? $account_id : null;
    }

    private function isValidAccountId(?string $account_id): bool
    {
        return BSN::validateStellarAccountIdFormat(strtoupper(trim((string) $account_id)));
    }

    private function hasExplicitCounterparties(): bool
    {
        return isset($_GET['contacts']) || isset($_GET['contact']);
    }

    private function applyLegacySourceAccount(string $legacy_source_id): void
    {
        $current_source_id = $this->CurrentUser->getCurrentAccountId();
        if ($legacy_source_id === $current_source_id) {
            return;
        }

        $this->CurrentUser->setCurrentAccountId($legacy_source_id);
        $this->CurrentUser->rememberAutoCurrentAccountChange($legacy_source_id);
    }

    private function buildLegacyQueryRedirectUrl(string $source_account_id): string
    {
        $query_parts = ['current_account=' . rawurlencode($source_account_id)];

        $tag_names = $this->parseTagList(isset($_GET['tags']) ? (string) $_GET['tags'] : (string) ($_GET['tag'] ?? ''));
        if ($tag_names) {
            $query_parts[] = (count($tag_names) === 1 ? 'tag' : 'tags')
                . '=' . $this->encodeCommaSeparatedList($tag_names);
        }

        $counterparty_ids = $this->parseAccountList(
            isset($_GET['contacts']) ? (string) $_GET['contacts'] : (string) ($_GET['contact'] ?? '')
        );
        if ($counterparty_ids) {
            $query_parts[] = (count($counterparty_ids) === 1 ? 'contact' : 'contacts')
                . '=' . $this->encodeCommaSeparatedList($counterparty_ids);
        }

        return '/editor/' . ($query_parts ? '?' . implode('&', $query_parts) : '');
    }

    private function buildLegacySingleAccountEditTagsRedirectUrl(
        string $source_account_id,
        bool $include_legacy_id_as_contact,
    ): ?string
    {
        $tag_names = $this->parseTagList(isset($_GET['tags']) ? (string) $_GET['tags'] : (string) ($_GET['tag'] ?? ''));
        if ($tag_names) {
            return null;
        }

        // Legacy /editor compatibility: contact-only editor links map to the single-account tag editor.
        $counterparty_ids = $this->parseAccountList(
            isset($_GET['contacts'])
                ? (string) $_GET['contacts']
                : (
                    isset($_GET['contact'])
                        ? (string) $_GET['contact']
                        : ($include_legacy_id_as_contact ? (string) ($_GET['id'] ?? '') : '')
                )
        );

        if (count($counterparty_ids) !== 1) {
            return null;
        }

        return '/accounts/' . rawurlencode($counterparty_ids[0])
            . '/edit_tags?current_account=' . rawurlencode($source_account_id);
    }

    private function buildLegacyPathRedirectUrl(string $legacy_source_id): string
    {
        $query_parts = ['current_account=' . rawurlencode($legacy_source_id)];

        $tag_names = $this->parseTagList(isset($_GET['tags']) ? (string) $_GET['tags'] : (string) ($_GET['tag'] ?? ''));
        if ($tag_names) {
            $query_parts[] = (count($tag_names) === 1 ? 'tag' : 'tags')
                . '=' . $this->encodeCommaSeparatedList($tag_names);
        }

        // Legacy /editor compatibility: in /editor/{source}/ links, query id meant contact.
        $counterparty_ids = $this->parseAccountList(
            isset($_GET['contacts'])
                ? (string) $_GET['contacts']
                : (
                    isset($_GET['contact'])
                        ? (string) $_GET['contact']
                        : (string) ($_GET['id'] ?? '')
                )
        );
        if ($counterparty_ids) {
            $query_parts[] = (count($counterparty_ids) === 1 ? 'contact' : 'contacts')
                . '=' . $this->encodeCommaSeparatedList($counterparty_ids);
        }

        return '/editor/' . ($query_parts ? '?' . implode('&', $query_parts) : '');
    }

    private function buildEditUrl(array $tag_names, array $counterparty_ids): string
    {
        $query_parts = [
            (count($tag_names) === 1 ? 'tag' : 'tags') . '=' . $this->encodeCommaSeparatedList($tag_names),
        ];

        if ($counterparty_ids) {
            $query_parts[] = (count($counterparty_ids) === 1 ? 'contact' : 'contacts')
                . '=' . $this->encodeCommaSeparatedList($counterparty_ids);
        }

        if ($current_account_id = $this->CurrentUser->getCurrentAccountRequestParam()) {
            $query_parts[] = 'current_account=' . rawurlencode($current_account_id);
        }

        return '/editor/?' . implode('&', $query_parts);
    }

    private function encodeCommaSeparatedList(array $items): string
    {
        return implode(',', array_map('rawurlencode', array_values($items)));
    }

    private function normalizeEditableTagName(string $tag_name): ?string
    {
        $tag_name = trim($tag_name);
        if (!$this->validateEditableTagName($tag_name)) {
            return null;
        }

        return $this->BSN->findTagByName($tag_name)?->getName() ?? $tag_name;
    }

    private function validateEditableTagName(?string $tag_name): bool
    {
        return BSN::validateTagNameFormat($tag_name)
            && strlen((string) $tag_name) <= 63
            && preg_match('/\d\z/', (string) $tag_name) !== 1;
    }

    private function fetchAccountData(string $id): array
    {
        return $this->Stellar->requestAccount($id)->getData()->getData();
    }

    private function loadKnownTagDescriptions(): array
    {
        static $descriptions_by_locale = [];

        $locale = $this->Translator->getLocale();
        if (!array_key_exists($locale, $descriptions_by_locale)) {
            $path = dirname(__DIR__, 4) . '/known_tags/lang-' . $locale . '.json';
            if (!is_file($path)) {
                $path = dirname(__DIR__, 4) . '/known_tags/lang-en.json';
            }

            $parsed = json_decode(file_get_contents($path), true) ?? [];
            $descriptions_by_locale[$locale] = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : $parsed;
        }

        return $descriptions_by_locale[$locale];
    }
}
