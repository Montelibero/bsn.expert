<?php

declare(strict_types=1);

namespace Montelibero\BSN\Knowledge;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\Tag;

final class AccountReportBuilder
{
    private const EXTRA_PROFILE_FIELDS = [
        'TimeTokenCode',
    ];
    public function __construct(
        private readonly BSN $BSN,
        private readonly KnownTagsCatalog $KnownTagsCatalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $account_id, string $locale = 'ru'): array
    {
        $account_id = strtoupper(trim($account_id));
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            throw new \InvalidArgumentException('Укажите корректный Stellar-адрес аккаунта.');
        }

        $Account = $this->BSN->getAccountById($account_id);
        $is_known_in_bsn = $Account !== null;
        $Account ??= Account::fromId($account_id);

        $outcome = $this->buildTagDirection($Account, true, $locale);
        $income = $this->buildTagDirection($Account, false, $locale);

        return [
            'locale' => $locale,
            'account' => $this->publicAccount($Account) + [
                'is_known_in_bsn' => $is_known_in_bsn,
            ],
            'profile' => [
                'about' => array_values($Account->getAbout()),
                'websites' => $this->websites($Account),
                'extra' => $this->extraProfile($Account),
            ],
            'ownership' => [
                'owner' => $this->confirmedOwnershipLinks($Account, 'Owner')[0] ?? null,
                'owned' => $this->confirmedOwnershipLinks($Account, 'OwnershipFull'),
            ],
            'relations' => [
                'outcome' => $outcome,
                'income' => $income,
            ],
            'signatures' => $this->signatures($Account),
            'multisig' => $this->multisig($Account),
            'multisig_participations' => $this->multisigParticipations($Account),
            'source' => [
                'snapshot_at' => $this->BSN->getDataTimestamp(),
                'loaded_at' => $this->BSN->getDataLoadedAt(),
                'generated_at' => time(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTagDirection(Account $Account, bool $outgoing, string $locale): array
    {
        $Tags = $outgoing ? $Account->getOutcomeTags() : $Account->getIncomeTags();
        $descriptions = $this->KnownTagsCatalog->tagDescriptions($locale);
        $groups = [];
        $total_links = 0;

        foreach ($Tags as $Tag) {
            $LinkedAccounts = $outgoing
                ? $Account->getOutcomeLinks($Tag)
                : $Account->getIncomeLinks($Tag);
            $links_count = count($LinkedAccounts);

            if ($Tag->getCategory()?->isUnknown() ?? true) {
                continue;
            }
            $total_links += $links_count;

            $Pair = $Tag->getPair();
            $PairedAccounts = $Pair === null
                ? []
                : ($outgoing
                    ? $Account->getIncomeLinks($Pair)
                    : $Account->getOutcomeLinks($Pair));
            $items = [];

            foreach ($LinkedAccounts as $LinkedAccount) {
                $items[] = $this->publicAccount($LinkedAccount) + [
                    'pair_status' => $this->pairStatus(
                        $Pair,
                        $Tag,
                        in_array($LinkedAccount, $PairedAccounts, true)
                    ),
                ];
            }

            usort($items, static function (array $left, array $right): int {
                return strcasecmp((string) $left['label'], (string) $right['label'])
                    ?: strcmp((string) $left['id'], (string) $right['id']);
            });

            $Category = $Tag->getCategory();
            $groups[] = [
                'name' => $Tag->getName(),
                'description' => is_string($descriptions[$Tag->getName()] ?? null)
                    ? $descriptions[$Tag->getName()]
                    : null,
                'category' => $Category === null ? null : [
                    'id' => $Category->getId(),
                    'name' => $this->KnownTagsCatalog->categoryName($Category->getId(), $locale),
                ],
                'pair' => $Pair?->getName(),
                'pair_strong' => $Pair !== null && $Tag->isPairStrong(),
                'count' => $links_count,
                'items' => $items,
            ];
        }

        $semantic_order = array_flip(KnownTagsCatalog::ACCOUNT_PAGE_TAG_ORDER);
        usort($groups, static function (array $left, array $right) use ($semantic_order): int {
            $left_position = $semantic_order[(string) $left['name']] ?? null;
            $right_position = $semantic_order[(string) $right['name']] ?? null;

            if ($left_position === null && $right_position === null) {
                return 0;
            }
            if ($left_position === null) {
                return 1;
            }
            if ($right_position === null) {
                return -1;
            }

            return $left_position <=> $right_position;
        });

        return [
            'groups' => $groups,
            'groups_count' => count($groups),
            'links_count' => $total_links,
        ];
    }

    private function pairStatus(?Tag $Pair, Tag $Tag, bool $has_pair): string
    {
        if ($Pair === null) {
            return 'not_applicable';
        }
        if ($has_pair) {
            return 'confirmed';
        }

        return $Tag->isPairStrong() ? 'required_missing' : 'missing';
    }

    /**
     * @return array<string, mixed>
     */
    private function publicAccount(Account $Account): array
    {
        $name = $Account->getName()[0] ?? null;
        $username = $Account->getUsername();
        $label = $name
            ?: ($username === null ? $Account->getShortId() : $username . '*bsn.expert');

        return [
            'id' => $Account->getId(),
            'short_id' => $Account->getShortId(),
            'name' => $name,
            'username' => $username,
            'label' => $label,
        ];
    }

    /**
     * @return list<string>
     */
    private function websites(Account $Account): array
    {
        $websites = [];
        foreach ($Account->getWebsite() as $website) {
            $normalized = $this->normalizeWebsite($website);
            if ($normalized !== null) {
                $websites[] = $normalized;
            }
        }

        return array_values(array_unique($websites));
    }

    private function normalizeWebsite(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }
        if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $website)) {
            $website = 'https://' . $website;
        }

        $parts = parse_url($website);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return filter_var($website, FILTER_VALIDATE_URL) === false ? null : $website;
    }

    /**
     * @return list<array{key: string, values: list<string>}>
     */
    private function extraProfile(Account $Account): array
    {
        $extra = [];
        foreach (self::EXTRA_PROFILE_FIELDS as $key) {
            $values = array_values(array_filter(
                $Account->getProfileItem($key),
                static fn(mixed $value): bool => is_string($value) && $value !== ''
            ));
            if ($values !== []) {
                $extra[] = [
                    'key' => $key,
                    'values' => $values,
                ];
            }
        }

        return $extra;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function confirmedOwnershipLinks(Account $Account, string $tag_name): array
    {
        $Tag = $this->BSN->getTag($tag_name);
        $Pair = $Tag?->getPair();
        if ($Tag === null || $Pair === null) {
            return [];
        }

        $PairedAccounts = $Account->getIncomeLinks($Pair);
        $links = [];
        foreach ($Account->getOutcomeLinks($Tag) as $LinkedAccount) {
            if (!in_array($LinkedAccount, $PairedAccounts, true)) {
                continue;
            }

            $links[] = $this->publicAccount($LinkedAccount);
        }

        usort($links, static function (array $left, array $right): int {
            return strcasecmp((string) $left['label'], (string) $right['label'])
                ?: strcmp((string) $left['id'], (string) $right['id']);
        });

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signatures(Account $Account): array
    {
        $signatures = [];
        foreach ($Account->getSignatures() as $Signature) {
            $Contract = $Signature->getContract();
            $signatures[] = [
                'hash' => $Contract->getHash(),
                'hash_short' => $Contract->hash_short,
                'name' => $Signature->getName(),
                'is_obsolete' => $Contract->isObsolete(),
            ];
        }

        usort($signatures, static function (array $left, array $right): int {
            return strcasecmp((string) $left['name'], (string) $right['name'])
                ?: strcmp((string) $left['hash'], (string) $right['hash']);
        });

        return $signatures;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function multisig(Account $Account): ?array
    {
        $multisig = $Account->getMultisig();
        if ($multisig === null) {
            return null;
        }

        $med_threshold = (int) ($multisig['thresholds'][1] ?? 0);
        $signers = [];
        foreach ($multisig['signers'] ?? [] as $signer) {
            $SignerAccount = $signer['account'] ?? null;
            if (!$SignerAccount instanceof Account) {
                continue;
            }
            $weight = (int) ($signer['weight'] ?? 0);
            $signers[] = $this->publicAccount($SignerAccount) + [
                'weight' => $weight,
            ];
        }

        usort($signers, static function (array $left, array $right): int {
            return ((int) $right['weight'] <=> (int) $left['weight'])
                ?: strcmp((string) $left['id'], (string) $right['id']);
        });

        $master_key = (int) ($multisig['master_key'] ?? 0);

        return [
            'thresholds' => [
                'low' => (int) ($multisig['thresholds'][0] ?? 0),
                'med' => $med_threshold,
                'high' => (int) ($multisig['thresholds'][2] ?? 0),
            ],
            'master_key' => $master_key,
            'signers' => $signers,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function multisigParticipations(Account $Account): array
    {
        $participations = [];
        foreach ($Account->getMultisigParticipations() as $participation) {
            $MultisigAccount = $participation['account'] ?? null;
            if (!$MultisigAccount instanceof Account) {
                continue;
            }
            $weight = (int) ($participation['weight'] ?? 0);
            $med_threshold = (int) ($participation['med_threshold'] ?? 0);
            $participations[] = [
                'account' => $this->publicAccount($MultisigAccount),
                'weight' => $weight,
                'med_threshold' => $med_threshold,
            ];
        }

        return $participations;
    }
}
