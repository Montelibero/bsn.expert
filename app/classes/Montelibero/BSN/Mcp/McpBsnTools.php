<?php

declare(strict_types=1);

namespace Montelibero\BSN\Mcp;

use InvalidArgumentException;
use Montelibero\BSN\Account;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\Knowledge\AccountReportBuilder;
use Montelibero\BSN\Relations\Corporate;
use Montelibero\BSN\Relations\Known;
use Montelibero\BSN\Relations\Member;
use Montelibero\BSN\Relations\Person;
use Montelibero\BSN\Tag;
use Montelibero\BSN\TagCategory;

final class McpBsnTools
{
    public const ACCOUNT_GET = 'bsn.account.get';
    public const ACCOUNT_TAGS = 'bsn.account.tags';
    public const TAGS_LIST = 'bsn.tags.list';

    public function __construct(
        private readonly BSN $BSN,
        private readonly AccountsManager $AccountsManager,
        private readonly KnownTagsCatalog $KnownTagsCatalog,
        private readonly AccountReportBuilder $AccountReports,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $account_input = [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'A Stellar G... account ID, @BSN username, or username*bsn.expert address.',
                ],
                'locale' => [
                    'type' => 'string',
                    'enum' => ['ru', 'en'],
                    'default' => 'ru',
                    'description' => 'Language for tag and category descriptions.',
                ],
            ],
            'required' => ['account'],
            'additionalProperties' => false,
        ];
        $annotations = [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];

        return [
            [
                'name' => self::ACCOUNT_GET,
                'title' => 'Look up a BSN account',
                'description' => 'Use for a general account lookup. Return the public BSN Expert account card: profile, membership type, balances, ownership, signatures, multisig data, and a compact summary of known incoming and outgoing tags. The summary names bsn.account.tags as the tool for linked accounts, unknown tags, and reciprocal-pair details.',
                'inputSchema' => $account_input,
                'annotations' => $annotations,
            ],
            [
                'name' => self::ACCOUNT_TAGS,
                'title' => 'Get an account\'s BSN tags',
                'description' => 'Use when the question is specifically about an account\'s tags or relationships. Return all incoming and outgoing BSN tag links with linked accounts, including unknown tags and whether the matching reciprocal pair exists. Do not call after bsn.account.get when its compact tag summary already answers the question.',
                'inputSchema' => $account_input,
                'annotations' => $annotations,
            ],
            [
                'name' => self::TAGS_LIST,
                'title' => 'List BSN relationship tags',
                'description' => 'Return the BSN relationship-tag catalog with localized descriptions, categories, single-value rules, pair type, reciprocal tag, and strong-pair requirement.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'locale' => [
                            'type' => 'string',
                            'enum' => ['ru', 'en'],
                            'default' => 'ru',
                            'description' => 'Language for tag and category descriptions.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $annotations,
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            self::ACCOUNT_GET => $this->account($arguments),
            self::ACCOUNT_TAGS => $this->accountTags($arguments),
            self::TAGS_LIST => $this->tags($arguments),
            default => throw new InvalidArgumentException(sprintf('Unknown tool: %s', $name)),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function account(array $arguments): array
    {
        $this->validateArgumentNames($arguments, ['account', 'locale']);
        $resolved = $this->resolveAccount($arguments['account'] ?? null);
        $locale = $this->locale($arguments['locale'] ?? null);
        $report = $this->AccountReports->build($resolved['id'], $locale);
        $Account = $this->BSN->getAccountById($resolved['id']);

        $balances = $Account?->getBalances() ?? [];
        ksort($balances, SORT_NATURAL | SORT_FLAG_CASE);

        $report['lookup'] = [
            'query' => $resolved['query'],
            'resolved_by' => $resolved['by'],
        ];
        $report['account']['url'] = 'https://bsn.expert/accounts/' . $resolved['id'];
        $report['account']['bsn_score'] = $Account?->calcBsnScore() ?? 0;
        $report['account']['relation'] = $this->relation($Account ?? Account::fromId($resolved['id']));
        $report['balances'] = $balances;
        $relations = is_array($report['relations'] ?? null) ? $report['relations'] : [];
        $report['tags_summary'] = [
            'known_tags_only' => true,
            'incoming' => $this->tagSummaryDirection($relations['income'] ?? null),
            'outgoing' => $this->tagSummaryDirection($relations['outcome'] ?? null),
            'details' => [
                'tool' => self::ACCOUNT_TAGS,
                'arguments' => [
                    'account' => $resolved['id'],
                    'locale' => $locale,
                ],
            ],
        ];
        unset($report['relations']);

        return $report;
    }

    /** @return array{links_count: int, tags_count: int, tag_names: list<string>} */
    private function tagSummaryDirection(mixed $direction): array
    {
        $direction = is_array($direction) ? $direction : [];
        $groups = is_array($direction['groups'] ?? null) ? $direction['groups'] : [];
        $tag_names = [];
        foreach ($groups as $group) {
            $name = is_array($group) ? ($group['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $tag_names[] = $name;
            }
        }

        return [
            'links_count' => is_int($direction['links_count'] ?? null)
                ? $direction['links_count']
                : 0,
            'tags_count' => count($tag_names),
            'tag_names' => $tag_names,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function accountTags(array $arguments): array
    {
        $this->validateArgumentNames($arguments, ['account', 'locale']);
        $resolved = $this->resolveAccount($arguments['account'] ?? null);
        $locale = $this->locale($arguments['locale'] ?? null);
        $Account = $this->BSN->getAccountById($resolved['id']) ?? Account::fromId($resolved['id']);

        return [
            'locale' => $locale,
            'lookup' => [
                'query' => $resolved['query'],
                'resolved_by' => $resolved['by'],
            ],
            'account' => $Account->jsonSerialize() + [
                'is_known_in_bsn' => $this->BSN->getAccountById($resolved['id']) !== null,
                'url' => 'https://bsn.expert/accounts/' . $resolved['id'],
            ],
            'semantics' => [
                'outgoing' => 'The requested account assigned the tag to the linked account.',
                'incoming' => 'The linked account assigned the tag to the requested account.',
                'reciprocal_status' => [
                    'not_applicable' => 'The tag has no reciprocal pair.',
                    'confirmed' => 'The linked account has assigned the matching reciprocal tag.',
                    'missing' => 'The optional reciprocal tag is absent.',
                    'required_missing' => 'The strong reciprocal tag is absent.',
                ],
            ],
            'outgoing' => $this->tagDirection($Account, true, $locale),
            'incoming' => $this->tagDirection($Account, false, $locale),
            'source' => [
                'snapshot_at' => $this->BSN->getDataTimestamp(),
                'loaded_at' => $this->BSN->getDataLoadedAt(),
                'generated_at' => time(),
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function tags(array $arguments): array
    {
        $this->validateArgumentNames($arguments, ['locale']);
        $locale = $this->locale($arguments['locale'] ?? null);
        $known_links = $this->KnownTagsCatalog->list()['links'] ?? [];
        $descriptions = $this->KnownTagsCatalog->tagDescriptions($locale);
        $link_counts = [];
        foreach ($this->BSN->getLinks() as $Link) {
            $tag_name = $Link->getTag()->getName();
            $link_counts[$tag_name] = ($link_counts[$tag_name] ?? 0) + 1;
        }

        $tags = [];
        foreach ($this->BSN->getTags() as $Tag) {
            $Pair = $Tag->getPair();
            $Category = $Tag->getCategory();
            $tags[] = [
                'name' => $Tag->getName(),
                'description' => is_string($descriptions[$Tag->getName()] ?? null)
                    ? $descriptions[$Tag->getName()]
                    : null,
                'known' => array_key_exists($Tag->getName(), $known_links),
                'category' => $this->category($Category, $locale),
                'single' => $Tag->isSingle(),
                'standard' => $Tag->isStandard(),
                'editable' => $Tag->isEditable(),
                'pair' => $Pair === null ? null : [
                    'name' => $Pair->getName(),
                    'kind' => $Pair === $Tag ? 'same_tag' : 'complementary',
                    'strong' => $Tag->isPairStrong(),
                ],
                'links_count' => $link_counts[$Tag->getName()] ?? 0,
            ];
        }

        $category_order = array_flip(TagCategory::SORT_EXAMPLE);
        usort($tags, static function (array $left, array $right) use ($category_order): int {
            $left_category = (string) ($left['category']['id'] ?? TagCategory::UNKNOWN_ID);
            $right_category = (string) ($right['category']['id'] ?? TagCategory::UNKNOWN_ID);

            return (($category_order[$left_category] ?? PHP_INT_MAX) <=> ($category_order[$right_category] ?? PHP_INT_MAX))
                ?: strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return [
            'locale' => $locale,
            'semantics' => [
                'single' => 'At most one outgoing value is supported for this tag on one source account.',
                'pair.kind.same_tag' => 'Both sides use the same tag name, for example Spouse to Spouse.',
                'pair.kind.complementary' => 'The reverse direction uses another tag name, for example Employer to Employee.',
                'pair.strong' => 'The reciprocal side is required for the relationship to be fully confirmed.',
            ],
            'tags_count' => count($tags),
            'tags' => $tags,
            'source' => [
                'snapshot_at' => $this->BSN->getDataTimestamp(),
                'loaded_at' => $this->BSN->getDataLoadedAt(),
                'generated_at' => time(),
            ],
        ];
    }

    /**
     * @return array{groups: list<array<string, mixed>>, groups_count: int, links_count: int}
     */
    private function tagDirection(Account $Account, bool $outgoing, string $locale): array
    {
        $Tags = $outgoing ? $Account->getOutcomeTags() : $Account->getIncomeTags();
        $known_links = $this->KnownTagsCatalog->list()['links'] ?? [];
        $descriptions = $this->KnownTagsCatalog->tagDescriptions($locale);
        $groups = [];
        $total_links = 0;

        foreach ($Tags as $Tag) {
            $LinkedAccounts = $outgoing
                ? $Account->getOutcomeLinks($Tag)
                : $Account->getIncomeLinks($Tag);
            $Pair = $Tag->getPair();
            $PairedAccounts = $Pair === null
                ? []
                : ($outgoing ? $Account->getIncomeLinks($Pair) : $Account->getOutcomeLinks($Pair));
            $links = [];
            foreach ($LinkedAccounts as $LinkedAccount) {
                $has_pair = $Pair !== null && in_array($LinkedAccount, $PairedAccounts, true);
                $links[] = $LinkedAccount->jsonSerialize() + [
                    'reciprocal_status' => $this->pairStatus($Tag, $has_pair),
                ];
            }

            usort($links, static fn(array $left, array $right): int =>
                strcasecmp((string) $left['display_name'], (string) $right['display_name'])
                ?: strcmp((string) $left['id'], (string) $right['id'])
            );

            $groups[] = [
                'name' => $Tag->getName(),
                'description' => is_string($descriptions[$Tag->getName()] ?? null)
                    ? $descriptions[$Tag->getName()]
                    : null,
                'known' => array_key_exists($Tag->getName(), $known_links),
                'category' => $this->category($Tag->getCategory(), $locale),
                'single' => $Tag->isSingle(),
                'standard' => $Tag->isStandard(),
                'pair' => $Pair === null ? null : [
                    'name' => $Pair->getName(),
                    'kind' => $Pair === $Tag ? 'same_tag' : 'complementary',
                    'strong' => $Tag->isPairStrong(),
                ],
                'count' => count($links),
                'links' => $links,
            ];
            $total_links += count($links);
        }

        $semantic_order = array_flip(KnownTagsCatalog::ACCOUNT_PAGE_TAG_ORDER);
        usort($groups, static function (array $left, array $right) use ($semantic_order): int {
            return (($semantic_order[(string) $left['name']] ?? PHP_INT_MAX)
                    <=> ($semantic_order[(string) $right['name']] ?? PHP_INT_MAX))
                ?: strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return [
            'groups' => $groups,
            'groups_count' => count($groups),
            'links_count' => $total_links,
        ];
    }

    private function pairStatus(Tag $Tag, bool $has_pair): string
    {
        if ($Tag->getPair() === null) {
            return 'not_applicable';
        }
        if ($has_pair) {
            return 'confirmed';
        }

        return $Tag->isPairStrong() ? 'required_missing' : 'missing';
    }

    /** @return array{id: string, name: string, unknown: bool}|null */
    private function category(?TagCategory $Category, string $locale): ?array
    {
        if ($Category === null) {
            return null;
        }

        return [
            'id' => $Category->getId(),
            'name' => $this->KnownTagsCatalog->categoryName($Category->getId(), $locale),
            'unknown' => $Category->isUnknown(),
        ];
    }

    /** @return array{type: string, level?: int, inherited?: bool} */
    private function relation(Account $Account): array
    {
        $Relation = $Account->getRelation();
        $type = match (true) {
            $Relation instanceof Person => 'person',
            $Relation instanceof Corporate => 'corporate',
            $Relation instanceof Known => 'known',
            default => 'unknown',
        };
        $result = ['type' => $type];
        if ($Relation instanceof Member) {
            $result['level'] = $Relation->getLevel();
            $result['inherited'] = (bool) $Relation->isInherited();
        }

        return $result;
    }

    /** @return array{id: string, query: string, by: string} */
    private function resolveAccount(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('The account argument must be a non-empty string.');
        }

        $query = trim($value);
        $account_id = strtoupper($query);
        if (BSN::validateStellarAccountIdFormat($account_id)) {
            return ['id' => $account_id, 'query' => $query, 'by' => 'account_id'];
        }

        $username = ltrim($query, '@');
        if (str_contains($username, '*')) {
            [$username, $domain] = array_pad(explode('*', $username, 2), 2, '');
            if (strcasecmp($domain, 'bsn.expert') !== 0) {
                throw new InvalidArgumentException('Only username*bsn.expert federation addresses are supported.');
            }
        }
        if (!AccountsManager::validateUsername($username)) {
            throw new InvalidArgumentException('Use a Stellar G... account ID or a valid BSN username.');
        }

        $account_id = $this->AccountsManager->fetchAccountIdByUsername($username);
        if (!is_string($account_id) || !BSN::validateStellarAccountIdFormat($account_id)) {
            throw new InvalidArgumentException(sprintf('BSN username not found: %s', $username));
        }

        return ['id' => $account_id, 'query' => $query, 'by' => 'username'];
    }

    private function locale(mixed $value): string
    {
        if ($value === null) {
            return 'ru';
        }
        if (!is_string($value) || !in_array($value, ['ru', 'en'], true)) {
            throw new InvalidArgumentException('The locale argument must be either "ru" or "en".');
        }

        return $value;
    }

    /** @param array<string, mixed> $arguments @param list<string> $allowed */
    private function validateArgumentNames(array $arguments, array $allowed): void
    {
        $unknown = array_diff(array_keys($arguments), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown argument: %s', (string) reset($unknown)));
        }
    }
}
