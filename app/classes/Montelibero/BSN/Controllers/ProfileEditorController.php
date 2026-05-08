<?php

namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class ProfileEditorController
{
    private const PRIMARY_TAGS = ['Name', 'About', 'Website'];
    private const MAX_DATA_VALUE_BYTES = 64;
    private const MAX_DATA_KEY_BYTES = 64;
    private const MAX_OPERATIONS = 100;

    public function __construct(
        private readonly BSN $BSN,
        private readonly Environment $Twig,
        private readonly StellarSDK $Stellar,
        private readonly CurrentUser $CurrentUser,
        private readonly Container $Container,
        private readonly Translator $Translator,
    ) {
    }

    public function Profile(): ?string
    {
        $account_id = $this->CurrentUser->getCurrentAccountId();
        if (!$account_id) {
            SimpleRouter::response()->redirect(
                '/who_are_you?return_to=' . urlencode($_SERVER['REQUEST_URI'] ?? '/editor/profile'),
                302
            );
            return null;
        }
        if ($cleanup_url = $this->CurrentUser->getCurrentAccountCleanupUrl()) {
            SimpleRouter::response()->redirect($cleanup_url, 302);
            return null;
        }

        $raw_data = $this->fetchAccountData($account_id);
        $fresh_profile = $this->parseProfileData($raw_data);
        $form_profile = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? $this->buildProfileFromPost($fresh_profile)
            : $this->buildInitialFormProfile($fresh_profile);

        $custom_tag_value = $this->getCustomTagInput();
        $custom_tag_error = null;
        $errors = [];
        $signing_form = null;
        $no_changes = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string) ($_POST['action'] ?? '');

            if (isset($_POST['add_row_tag'])) {
                $tag_name = $this->normalizeEditableTagName((string) ($_POST['add_row_tag'] ?? ''));
                if ($tag_name !== null && isset($form_profile[$tag_name]) && !$form_profile[$tag_name]['is_single']) {
                    $form_profile[$tag_name]['values'][] = '';
                }
            } elseif ($action === 'add_custom_tag') {
                $custom_tag_name = $this->normalizeEditableTagName($custom_tag_value);
                if ($custom_tag_name === null) {
                    $custom_tag_error = $this->Translator->trans($this->getCustomTagErrorTranslationKey($custom_tag_value));
                } else {
                    $form_profile[$custom_tag_name] ??= $this->makeProfileGroup($custom_tag_name, ['']);
                    $custom_tag_value = '';
                }
            } elseif ($action === 'save') {
                $desired_profile = $this->normalizeDesiredProfile($form_profile);
                $errors = $this->validateDesiredProfile($desired_profile);

                if (!$errors) {
                    $save_result = $this->buildSaveTransaction($account_id, $raw_data, $desired_profile);
                    if ($save_result['errors'] ?? null) {
                        $errors = $save_result['errors'];
                    } elseif ($save_result['xdr']) {
                        $signing_form = $this->Container
                            ->get(SignController::class)
                            ->SignTransaction($save_result['xdr'], null, 'BSN profile update');
                    } else {
                        $no_changes = true;
                    }
                }
            }
        }

        return $this->render(
            $account_id,
            $form_profile,
            $custom_tag_value,
            $custom_tag_error,
            $errors,
            $signing_form,
            $no_changes
        );
    }

    private function render(
        string $account_id,
        array $profile,
        string $custom_tag_value,
        ?string $custom_tag_error,
        array $errors,
        ?string $signing_form,
        bool $no_changes,
    ): string {
        $Template = $this->Twig->load('editor_profile.twig');

        return $Template->render([
            'account' => $this->BSN->makeAccountById($account_id)->jsonSerialize(),
            'primary_tags' => self::PRIMARY_TAGS,
            'profile_groups' => $this->sortProfileGroupsForView($profile),
            'has_extra_groups' => $this->hasExtraGroups($profile),
            'custom_tag_value' => $custom_tag_value,
            'custom_tag_error' => $custom_tag_error,
            'errors' => $errors,
            'signing_form' => $signing_form,
            'no_changes' => $no_changes,
            'max_value_bytes' => self::MAX_DATA_VALUE_BYTES,
            'current_account_param' => $this->CurrentUser->getCurrentAccountRequestParam(),
        ]);
    }

    private function buildInitialFormProfile(array $profile): array
    {
        foreach (self::PRIMARY_TAGS as $tag_name) {
            $profile[$tag_name] ??= $this->makeProfileGroup($tag_name, ['']);
            if (!$profile[$tag_name]['values']) {
                $profile[$tag_name]['values'][] = '';
            }
        }

        return $profile;
    }

    private function buildProfileFromPost(array $fresh_profile): array
    {
        $profile = [];
        foreach ($_POST['profile'] ?? [] as $tag_name => $values) {
            $tag_name = $this->normalizeEditableTagName((string) $tag_name);
            if ($tag_name === null) {
                continue;
            }

            $profile[$tag_name] = $this->makeProfileGroup(
                $tag_name,
                array_values(array_map('strval', (array) $values))
            );
        }

        foreach (self::PRIMARY_TAGS as $tag_name) {
            if (!isset($profile[$tag_name])) {
                $profile[$tag_name] = $this->makeProfileGroup(
                    $tag_name,
                    $fresh_profile[$tag_name]['values'] ?? ['']
                );
            }
        }

        return $profile;
    }

    private function normalizeDesiredProfile(array $profile): array
    {
        $desired = [];
        foreach ($profile as $tag_name => $group) {
            $tag_name = $this->normalizeEditableTagName((string) $tag_name);
            if ($tag_name === null) {
                continue;
            }

            $values = [];
            foreach ((array) ($group['values'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $values[] = $value;
            }

            $desired[$tag_name] = $this->makeProfileGroup($tag_name, $values);
        }

        foreach (self::PRIMARY_TAGS as $tag_name) {
            $desired[$tag_name] ??= $this->makeProfileGroup($tag_name, []);
        }

        return $desired;
    }

    private function validateDesiredProfile(array $profile): array
    {
        $errors = [];
        foreach ($profile as $tag_name => $group) {
            foreach ((array) ($group['values'] ?? []) as $index => $value) {
                $bytes = strlen((string) $value);
                if ($bytes > self::MAX_DATA_VALUE_BYTES) {
                    $errors[] = $this->Translator->trans('editor_profile.errors.value_too_long', [
                        '%tag%' => $tag_name,
                        '%row%' => (string) ($index + 1),
                        '%bytes%' => (string) $bytes,
                        '%limit%' => (string) self::MAX_DATA_VALUE_BYTES,
                    ]);
                }

                $key = $this->makeCanonicalKey($tag_name, $index);
                if (strlen($key) > self::MAX_DATA_KEY_BYTES) {
                    $errors[] = $this->Translator->trans('editor_profile.errors.key_too_long', [
                        '%tag%' => $tag_name,
                        '%row%' => (string) ($index + 1),
                    ]);
                }
            }
        }

        return $errors;
    }

    private function buildSaveTransaction(string $account_id, array $raw_data, array $desired_profile): array
    {
        $fresh_profile = $this->parseProfileData($raw_data);
        $editable_tag_names = array_keys($desired_profile);
        $operations = [];

        foreach ($editable_tag_names as $tag_name) {
            $current_entries = $fresh_profile[$tag_name]['entries'] ?? [];
            $desired_values = $desired_profile[$tag_name]['values'] ?? [];
            $desired_by_key = [];

            foreach ($desired_values as $index => $value) {
                $key = $this->makeCanonicalKey($tag_name, $index);
                if ($this->isProtectedRawDataKey($raw_data, $key)) {
                    return [
                        'xdr' => null,
                        'errors' => [$this->Translator->trans('editor_profile.errors.protected_key_conflict', [
                            '%key%' => $key,
                        ])],
                    ];
                }

                $desired_by_key[$key] = $value;
            }

            $current_by_key = [];
            foreach ($current_entries as $entry) {
                $current_by_key[$entry['key']] = $entry['value'];
            }

            foreach ($current_by_key as $key => $_) {
                if (!array_key_exists($key, $desired_by_key)) {
                    $operations[] = (new ManageDataOperationBuilder($key, null))->build();
                }
            }

            foreach ($desired_by_key as $key => $value) {
                if (!array_key_exists($key, $current_by_key) || $current_by_key[$key] !== $value) {
                    $operations[] = (new ManageDataOperationBuilder($key, $value))->build();
                }
            }
        }

        if (!$operations) {
            return ['xdr' => null];
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            return [
                'xdr' => null,
                'errors' => [$this->Translator->trans('editor_profile.errors.too_many_operations')],
            ];
        }

        $StellarAccount = $this->Stellar->requestAccount($account_id);
        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('BSN profile update'));
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addOperations($operations);

        return ['xdr' => $Transaction->build()->toEnvelopeXdrBase64()];
    }

    private function parseProfileData(array $raw_data): array
    {
        $groups = [];

        foreach ($raw_data as $key => $encoded_value) {
            $parsed_key = $this->parseDataKey((string) $key);
            if ($parsed_key === null) {
                continue;
            }

            $value = base64_decode((string) $encoded_value, true);
            if (!$this->isEditableProfileValue($value)) {
                continue;
            }

            $tag_name = $parsed_key['tag'];
            $groups[$tag_name] ??= $this->makeProfileGroup($tag_name, []);
            $groups[$tag_name]['entries'][] = [
                'key' => (string) $key,
                'value' => $value,
                'suffix' => $parsed_key['suffix'],
            ];
        }

        foreach ($groups as $tag_name => &$group) {
            usort($group['entries'], fn(array $a, array $b): int => $this->compareEntries($a, $b));
            $group['values'] = array_map(fn(array $entry): string => $entry['value'], $group['entries']);
            $group['is_single'] = $this->isKnownSingleTag($tag_name);
        }
        unset($group);

        return $groups;
    }

    private function isProtectedRawDataKey(array $raw_data, string $key): bool
    {
        if (!array_key_exists($key, $raw_data)) {
            return false;
        }

        $value = base64_decode((string) $raw_data[$key], true);

        return !$this->isEditableProfileValue($value);
    }

    private function parseDataKey(string $key): ?array
    {
        if (!preg_match('/\A(?<tag>[A-Za-z0-9_]*?[A-Za-z_])(?<suffix>\d*)\z/', $key, $match)) {
            return null;
        }

        $tag_name = $match['tag'];
        if (!$this->validateEditableTagName($tag_name)) {
            return null;
        }

        return [
            'tag' => $this->BSN->findTagByName($tag_name)?->getName() ?? $tag_name,
            'suffix' => $match['suffix'] === '' ? null : (int) $match['suffix'],
        ];
    }

    private function compareEntries(array $a, array $b): int
    {
        $a_order = $this->entryOrder($a);
        $b_order = $this->entryOrder($b);
        if ($a_order !== $b_order) {
            return $a_order <=> $b_order;
        }

        if (($a['suffix'] ?? null) === null && ($b['suffix'] ?? null) !== null) {
            return -1;
        }
        if (($a['suffix'] ?? null) !== null && ($b['suffix'] ?? null) === null) {
            return 1;
        }

        return strcmp((string) $a['key'], (string) $b['key']);
    }

    private function entryOrder(array $entry): int
    {
        $suffix = $entry['suffix'] ?? null;
        if ($suffix === null || $suffix <= 1) {
            return 1;
        }

        return $suffix;
    }

    private function sortProfileGroupsForView(array $profile): array
    {
        foreach ($profile as $tag_name => &$group) {
            $group['name'] = $tag_name;
            $group['description'] = $this->getKnownTagDescription($tag_name);
            $group['values'] = array_values($group['values'] ?: ['']);
            $group['is_primary'] = in_array($tag_name, self::PRIMARY_TAGS, true);
            $group['is_single'] = $this->isKnownSingleTag($tag_name);
        }
        unset($group);

        uasort($profile, function (array $a, array $b): int {
            $a_primary = array_search($a['name'], self::PRIMARY_TAGS, true);
            $b_primary = array_search($b['name'], self::PRIMARY_TAGS, true);

            if ($a_primary !== false || $b_primary !== false) {
                return ($a_primary === false ? PHP_INT_MAX : $a_primary)
                    <=> ($b_primary === false ? PHP_INT_MAX : $b_primary);
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return array_values($profile);
    }

    private function hasExtraGroups(array $profile): bool
    {
        foreach (array_keys($profile) as $tag_name) {
            if (!in_array($tag_name, self::PRIMARY_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function makeProfileGroup(string $tag_name, array $values): array
    {
        return [
            'name' => $tag_name,
            'values' => $values,
            'entries' => [],
            'is_single' => $this->isKnownSingleTag($tag_name),
            'description' => $this->getKnownTagDescription($tag_name),
            'is_primary' => in_array($tag_name, self::PRIMARY_TAGS, true),
        ];
    }

    private function makeCanonicalKey(string $tag_name, int $index): string
    {
        return $index === 0 ? $tag_name : $tag_name . ($index + 1);
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
            && strlen((string) $tag_name) <= self::MAX_DATA_KEY_BYTES - 1
            && preg_match('/\d\z/', (string) $tag_name) !== 1;
    }

    private function isKnownSingleTag(string $tag_name): bool
    {
        return (bool) ($this->BSN->findTagByName($tag_name)?->isSingle())
            || isset($this->getKnownSingleTagNames()[$tag_name]);
    }

    private function getKnownSingleTagNames(): array
    {
        static $single_tag_names = null;
        if ($single_tag_names !== null) {
            return $single_tag_names;
        }

        $single_tag_names = [];
        $path = dirname(__DIR__, 4) . '/known_tags/list.json';
        $known_tags = json_decode(file_get_contents($path), true) ?: [];

        foreach (['presentation', 'links'] as $section) {
            foreach (($known_tags[$section] ?? []) as $tag_name => $tag_data) {
                if (is_array($tag_data) && ($tag_data['single'] ?? false) && $this->validateEditableTagName($tag_name)) {
                    $single_tag_names[$tag_name] = true;
                }
            }
        }

        return $single_tag_names;
    }

    private function isEditableProfileValue(mixed $value): bool
    {
        if (!is_string($value) || BSN::validateStellarAccountIdFormat($value)) {
            return false;
        }

        return !function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8');
    }

    private function getCustomTagInput(): string
    {
        return trim((string) ($_POST['custom_tag'] ?? ''));
    }

    private function getCustomTagErrorTranslationKey(string $tag_name): string
    {
        if (preg_match('/[^\x00-\x7F]/', $tag_name) === 1) {
            return 'editor_profile.custom_tag.non_latin';
        }

        if (BSN::validateTagNameFormat($tag_name) && preg_match('/\d\z/', $tag_name) === 1) {
            return 'editor_profile.custom_tag.trailing_digits';
        }

        return 'editor_profile.custom_tag.invalid';
    }

    private function getKnownTagDescription(string $tag_name): string
    {
        $descriptions = $this->loadKnownTagDescriptions();

        return (string) ($descriptions[$tag_name] ?? '');
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

    private function fetchAccountData(string $id): array
    {
        return $this->Stellar->requestAccount($id)->getData()->getData();
    }
}
