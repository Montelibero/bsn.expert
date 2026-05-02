<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
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

class SingleAccountEditTagsController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private CurrentUser $CurrentUser;
    private Container $Container;
    private Translator $Translator;

    public function __construct(
        BSN $BSN,
        Environment $Twig,
        StellarSDK $Stellar,
        CurrentUser $CurrentUser,
        Container $Container,
        Translator $Translator,
    ) {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        $this->Stellar = $Stellar;
        $this->CurrentUser = $CurrentUser;
        $this->Container = $Container;
        $this->Translator = $Translator;
    }

    public function EditTags(string $target_account_id): ?string
    {
        if (!BSN::validateStellarAccountIdFormat($target_account_id)) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $source_account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$source_account_id) {
            SimpleRouter::response()->redirect(
                '/who_you_are?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'),
                302
            );
            return null;
        }

        $TargetAccount = $this->BSN->makeAccountById($target_account_id);
        $SourceAccount = $this->BSN->makeAccountById($source_account_id);
        $posted_tag_names = $this->getPostedTagNames();
        $custom_tag_input = $this->getCustomTagInput();
        $custom_tag_name = $this->normalizeCustomTagName($custom_tag_input);
        $custom_tag_error = null;
        $signing_form = null;
        $transaction_summary = null;
        $no_changes = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_custom_tag') {
            if ($custom_tag_name === null) {
                $custom_tag_error = $this->Translator->trans($this->getCustomTagErrorTranslationKey($custom_tag_input));
            } else {
                $posted_tag_names[$custom_tag_name] = true;
            }
        }

        $data_entries = $this->parseLinkData($this->fetchAccountData($source_account_id));
        $reciprocal_data_entries = $this->parseLinkData($this->fetchAccountData($target_account_id));
        $current_tag_names = $this->getTagNamesPointingToTarget($data_entries, $target_account_id);
        $reciprocal_tag_names = $this->getTagNamesPointingToTarget($reciprocal_data_entries, $source_account_id);
        $checked_tag_names = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? $posted_tag_names
            : array_fill_keys($current_tag_names, true);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
            $save_result = $this->buildSaveTransaction(
                $source_account_id,
                $target_account_id,
                $data_entries,
                $checked_tag_names
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

        $Template = $this->Twig->load('single_account_edit_tags.twig');
        return $Template->render([
            'account' => $TargetAccount->jsonSerialize(),
            'source_account' => $SourceAccount->jsonSerialize(),
            'current_tag_names' => $current_tag_names,
            'reciprocal_tag_names' => $reciprocal_tag_names,
            'tag_categories' => $this->buildTagCategories($checked_tag_names, $current_tag_names, $reciprocal_tag_names),
            'custom_tag_value' => $custom_tag_error ? $custom_tag_input : '',
            'custom_tag_error' => $custom_tag_error,
            'signing_form' => $signing_form,
            'transaction_summary' => $transaction_summary,
            'no_changes' => $no_changes,
        ]);
    }

    private function buildSaveTransaction(
        string $source_account_id,
        string $target_account_id,
        array $data_entries,
        array $desired_tag_names,
    ): array {
        $current_tag_names = array_fill_keys($this->getTagNamesPointingToTarget($data_entries, $target_account_id), true);
        $desired_tag_names = array_fill_keys(
            array_filter(
                array_keys($desired_tag_names),
                fn(string $tag_name): bool => $this->validateEditableTagName($tag_name)
            ),
            true
        );

        $keys_to_remove = [];
        $tags_to_remove = [];
        $tags_to_add = [];

        foreach ($data_entries as $tag_name => $entries) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $is_desired = isset($desired_tag_names[$tag_name]);
            $has_target = isset($current_tag_names[$tag_name]);

            if (!$is_desired) {
                foreach ($entries as $entry) {
                    if ($entry['value'] === $target_account_id) {
                        $keys_to_remove[$entry['key']] = true;
                        $tags_to_remove[$tag_name] = true;
                    }
                }
                continue;
            }

            if ($Tag->isSingle()) {
                foreach ($entries as $entry) {
                    if ($entry['suffix'] !== null) {
                        $keys_to_remove[$entry['key']] = true;
                    }
                }

                if (!$this->hasExactSingleTagValue($entries, $tag_name, $target_account_id)) {
                    $tags_to_add[$tag_name] = true;
                }
            } elseif (!$has_target) {
                $tags_to_add[$tag_name] = true;
            }
        }

        foreach ($desired_tag_names as $tag_name => $_) {
            if (!isset($data_entries[$tag_name])) {
                $tags_to_add[$tag_name] = true;
            }
        }

        $StellarAccount = $this->Stellar->requestAccount($source_account_id);
        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('BSN tags update'));
        $Transaction->setMaxOperationFee(10000);
        $operations = [];

        foreach (array_keys($keys_to_remove) as $key) {
            $operations[] = (new ManageDataOperationBuilder($key, null))->build();
        }

        foreach (array_keys($tags_to_add) as $tag_name) {
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $key_name = $Tag->isSingle()
                ? $tag_name
                : $this->findFirstFreeTagDataKey($tag_name, $data_entries[$tag_name] ?? [], $keys_to_remove);

            $operations[] = (new ManageDataOperationBuilder($key_name, $target_account_id))->build();
        }

        if (!$operations) {
            return [
                'xdr' => null,
                'summary' => null,
            ];
        }

        $Transaction->addOperations($operations);

        return [
            'xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
            'summary' => [
                'remove' => array_keys($tags_to_remove),
                'add' => array_keys($tags_to_add),
                'operations_count' => count($operations),
            ],
        ];
    }

    private function hasExactSingleTagValue(array $entries, string $tag_name, string $target_account_id): bool
    {
        foreach ($entries as $entry) {
            if ($entry['key'] === $tag_name && $entry['value'] === $target_account_id) {
                return true;
            }
        }

        return false;
    }

    private function findFirstFreeTagDataKey(string $tag_name, array $entries, array $keys_to_remove): string
    {
        $occupied = [];
        foreach ($entries as $entry) {
            if (isset($keys_to_remove[$entry['key']])) {
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

    private function buildTagCategories(array $checked_tag_names, array $current_tag_names, array $reciprocal_tag_names): array
    {
        $descriptions = $this->loadKnownTagDescriptions();
        $categories = [];
        $unknown_tags = [];
        $reciprocal_tag_set = array_fill_keys($reciprocal_tag_names, true);

        foreach ($this->BSN->getTags() as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }

            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();
            if ($Category->isUnknown()) {
                if (isset($checked_tag_names[$Tag->getName()]) || in_array($Tag->getName(), $current_tag_names, true)) {
                    $unknown_tags[$Tag->getName()] = $Tag;
                }
                continue;
            }

            $this->addTagToCategory($categories, $Category, $Tag, $descriptions, $checked_tag_names, $reciprocal_tag_set);
        }

        foreach (array_unique(array_merge($current_tag_names, array_keys($checked_tag_names))) as $tag_name) {
            if (!$this->validateEditableTagName($tag_name)) {
                continue;
            }
            $Tag = $this->BSN->getTag($tag_name) ?? $this->BSN->makeTagByName($tag_name);
            $Category = $Tag->getCategory() ?? $this->BSN->getUnknownTagCategory();
            if ($Category->isUnknown()) {
                $unknown_tags[$tag_name] = $Tag;
            }
        }

        if ($unknown_tags) {
            $UnknownCategory = $this->BSN->getUnknownTagCategory();
            foreach ($unknown_tags as $Tag) {
                $this->addTagToCategory($categories, $UnknownCategory, $Tag, $descriptions, $checked_tag_names, $reciprocal_tag_set);
            }
        }

        foreach ($categories as &$category) {
            $this->sortTags($category['tags']);
        }
        unset($category);

        WebApp::semantic_sort_keys($categories, TagCategory::SORT_EXAMPLE);

        return array_values($categories);
    }

    private function addTagToCategory(
        array &$categories,
        TagCategory $Category,
        Tag $Tag,
        array $descriptions,
        array $checked_tag_names,
        array $reciprocal_tag_set,
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
            'checked' => isset($checked_tag_names[$Tag->getName()]),
            'description' => $descriptions[$Tag->getName()] ?? '',
            'reciprocal_tag_name' => $this->resolveReciprocalTagName($Tag, $reciprocal_tag_set),
        ];
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

    private function getPostedTagNames(): array
    {
        $result = [];
        foreach ($_POST['tag'] ?? [] as $tag_name => $_) {
            if (is_string($tag_name) && $this->validateEditableTagName($tag_name)) {
                $result[$tag_name] = true;
            }
        }

        return $result;
    }

    private function getCustomTagInput(): string
    {
        return trim((string) ($_POST['custom_tag'] ?? ''));
    }

    private function normalizeCustomTagName(string $tag_name): ?string
    {
        if ($tag_name === '') {
            return null;
        }

        return $this->validateEditableTagName($tag_name) ? $tag_name : null;
    }

    private function getCustomTagErrorTranslationKey(string $tag_name): string
    {
        if (preg_match('/[^\x00-\x7F]/', $tag_name) === 1) {
            return 'edit_tags.custom_tag.non_latin';
        }

        if (BSN::validateTagNameFormat($tag_name) && $this->hasNumericSuffix($tag_name)) {
            return 'edit_tags.custom_tag.trailing_digits';
        }

        return 'edit_tags.custom_tag.invalid';
    }

    private function validateEditableTagName(?string $tag_name): bool
    {
        return BSN::validateTagNameFormat($tag_name)
            && strlen((string) $tag_name) <= 63
            && !$this->hasNumericSuffix((string) $tag_name);
    }

    private function hasNumericSuffix(string $tag_name): bool
    {
        return preg_match('/\d\z/', $tag_name) === 1;
    }

    private function getTagNamesPointingToTarget(array $data_entries, string $target_account_id): array
    {
        $tag_names = [];
        foreach ($data_entries as $tag_name => $entries) {
            foreach ($entries as $entry) {
                if ($entry['value'] === $target_account_id) {
                    $tag_names[$tag_name] = true;
                    break;
                }
            }
        }

        return array_keys($tag_names);
    }

    private function parseLinkData(array $raw_data): array
    {
        $result = [];

        foreach ($raw_data as $key_name => $encoded_value) {
            $value = base64_decode((string) $encoded_value, true);
            if (!BSN::validateStellarAccountIdFormat($value)) {
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
