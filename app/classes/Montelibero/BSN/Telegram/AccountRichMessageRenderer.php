<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use Symfony\Component\Translation\Translator;

final class AccountRichMessageRenderer
{
    private const MAX_BLOCKS = 400;
    private const MAX_TEXT_CHARACTERS = 28000;
    private const BSN_BASE_URL = 'https://bsn.expert';
    private string $locale = 'ru';

    public function __construct(
        private readonly Translator $Translator,
        private readonly TelegramBotConfig $Config,
    ) {
    }

    /**
     * @param array<string, mixed> $report
     * @return array{
     *     rich_message: array<string, mixed>,
     *     stats: array{blocks: int, characters: int}
     * }
     */
    public function render(array $report): array
    {
        $this->locale = is_string($report['locale'] ?? null) && $report['locale'] !== ''
            ? $report['locale']
            : 'ru';
        $account = $report['account'];
        $account_url = $this->accountUrl($account);

        if (($account['is_known_in_bsn'] ?? true) === false) {
            return $this->finalize([
                $this->footer($report['source'], $account_url),
            ]);
        }

        $title = is_string($account['name'] ?? null) && $account['name'] !== ''
            ? $account['name']
            : (string) $account['short_id'];
        $blocks = [
            $this->heading($title, 1),
            ...$this->accountIdentityBlocks($account),
        ];

        $about = array_values($report['profile']['about'] ?? []);
        if ($about !== []) {
            $blocks[] = [
                'type' => 'pullquote',
                'text' => implode("\n\n", $about),
            ];
        }

        $websites = array_values($report['profile']['websites'] ?? []);
        if ($websites !== []) {
            $website_text = [];
            foreach ($websites as $index => $url) {
                if ($index > 0) {
                    $website_text[] = "\n";
                }
                $website_text[] = [
                    'type' => 'url',
                    'text' => $url,
                    'url' => $url,
                ];
            }
            $blocks[] = $this->paragraph($website_text);
        }

        $extra_profile = array_values($report['profile']['extra'] ?? []);
        if ($extra_profile !== []) {
            foreach ($extra_profile as $item) {
                $blocks[] = $this->heading(
                    $this->profileFieldLabel((string) $item['key']),
                    3
                );
                $blocks[] = $this->paragraph(implode(', ', $item['values']));
            }
        }

        $blocks[] = $this->heading($this->trans('telegram_account_article.summary.header'), 2);
        $blocks[] = $this->summaryList($report);

        array_push(
            $blocks,
            ...$this->ownershipBlocks($report['ownership']),
            ...$this->directionBlocks(
                $report['relations']['income'],
                $this->trans('account_page.income_tags.header')
            ),
            ...$this->directionBlocks(
                $report['relations']['outcome'],
                $this->trans('account_page.outcome_tags.header')
            ),
            ...$this->signatureBlocks($report['signatures']),
            ...$this->multisigBlocks($report['multisig']),
            ...$this->multisigParticipationBlocks($report['multisig_participations'])
        );

        $blocks[] = ['type' => 'divider'];
        $blocks[] = $this->footer($report['source'], $account_url);
        $blocks = array_values($blocks);

        return $this->finalize($blocks);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{
     *     rich_message: array<string, mixed>,
     *     stats: array{blocks: int, characters: int}
     * }
     */
    private function finalize(array $blocks): array
    {
        $block_count = $this->countBlocks($blocks);
        $character_count = $this->countBlockCharacters($blocks);
        if ($block_count > self::MAX_BLOCKS || $character_count > self::MAX_TEXT_CHARACTERS) {
            throw new \LengthException(sprintf(
                'Статья превышает внутренний лимит: %d блоков, %d символов.',
                $block_count,
                $character_count
            ));
        }

        return [
            'rich_message' => [
                'blocks' => $blocks,
                'skip_entity_detection' => true,
            ],
            'stats' => [
                'blocks' => $block_count,
                'characters' => $character_count,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountIdentityBlocks(array $account): array
    {
        $blocks = [];
        if (is_string($account['username'] ?? null) && $account['username'] !== '') {
            $blocks[] = $this->paragraph($this->code($account['username'] . '*bsn.expert'));
        }
        $blocks[] = $this->paragraph($this->code((string) $account['id']));

        return $blocks;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summaryList(array $report): array
    {
        $outcome = $report['relations']['outcome'];
        $income = $report['relations']['income'];
        $summary = [];

        $summary[] = [
            $this->bold($this->trans('telegram_account_article.summary.tags') . ': '),
            $this->trans('telegram_account_article.summary.directions', [
                '%incoming%' => $this->tagLinksCount($income),
                '%outgoing%' => $this->tagLinksCount($outcome),
            ]),
        ];
        $signed_documents_count = count($report['signatures']);
        if ($signed_documents_count > 0) {
            $summary[] = [
                $this->bold($this->trans('telegram_account_article.signed_documents') . ': '),
                (string) $signed_documents_count,
            ];
        }

        $multisig_participations_count = count($report['multisig_participations']);
        if ($multisig_participations_count > 0) {
            $summary[] = [
                $this->bold($this->trans('account_page.multisig.participates_in') . ': '),
                (string) $multisig_participations_count,
            ];
        }

        return $this->listBlock($summary);
    }

    /**
     * @param array<string, mixed> $ownership
     * @return list<array<string, mixed>>
     */
    private function ownershipBlocks(array $ownership): array
    {
        $owner = is_array($ownership['owner'] ?? null) ? $ownership['owner'] : null;
        $owned = array_values($ownership['owned'] ?? []);
        if ($owner === null && $owned === []) {
            return [];
        }

        $blocks = [];
        if ($owner !== null) {
            $blocks[] = $this->paragraph([
                $this->bold($this->trans('telegram_account_article.ownership.belongs_to')),
                "\n",
                $this->accountLink($owner),
            ]);
        }
        if ($owned !== []) {
            $blocks[] = $this->paragraph([
                $this->bold($this->trans('telegram_account_article.ownership.owns')),
                "\n",
                ...$this->accountLinksText($owned),
            ]);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $direction
     * @return list<array<string, mixed>>
     */
    private function directionBlocks(array $direction, string $title): array
    {
        $groups = $this->nonEmptyTagGroups($direction);
        if ($groups === []) {
            return [];
        }

        $blocks = [$this->heading($title, 2)];

        $tag_blocks = [];
        foreach ($groups as $group) {
            $tag_blocks[] = $this->heading((string) $group['name'], 3);

            if (is_string($group['description'] ?? null) && $group['description'] !== '') {
                $tag_blocks[] = $this->paragraph($this->italic($group['description']));
            }

            $items = array_values($group['items'] ?? []);
            $tag_blocks[] = [
                'type' => 'details',
                'summary' => $this->countLabel(count($items), 'accounts'),
                'blocks' => [
                    $this->paragraph($this->tagLinksText($items)),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'details',
            'summary' => $this->countLabel(count($groups), 'types'),
            'blocks' => $tag_blocks,
        ];

        return $blocks;
    }

    /**
     * @param array<string, mixed> $direction
     * @return list<array<string, mixed>>
     */
    private function nonEmptyTagGroups(array $direction): array
    {
        return array_values(array_filter(
            array_values($direction['groups'] ?? []),
            static fn(array $group): bool => array_values($group['items'] ?? []) !== []
        ));
    }

    /** @param array<string, mixed> $direction */
    private function tagLinksCount(array $direction): int
    {
        return array_sum(array_map(
            static fn(array $group): int => count(array_values($group['items'] ?? [])),
            $this->nonEmptyTagGroups($direction)
        ));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<mixed>
     */
    private function tagLinksText(array $items): array
    {
        $text = [];
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $text[] = "\n";
            }
            $text[] = $this->pairMarker((string) $item['pair_status']) . ' ';
            $text[] = $this->accountLink($item);
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @return list<mixed>
     */
    private function accountLinksText(array $accounts): array
    {
        $text = [];
        foreach ($accounts as $index => $account) {
            if ($index > 0) {
                $text[] = "\n";
            }
            $text[] = $this->accountLink($account);
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<mixed>
     */
    private function weightedAccountLinksText(array $items, bool $participations): array
    {
        $text = [];
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $text[] = "\n";
            }
            $account = $participations ? $item['account'] : $item;
            $text[] = $this->accountLink($account);
            $text[] = $participations
                ? sprintf(' (%d/%d)', (int) $item['weight'], (int) $item['med_threshold'])
                : sprintf(' (%d)', (int) $item['weight']);
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $signatures
     * @return list<array<string, mixed>>
     */
    private function signatureBlocks(array $signatures): array
    {
        if ($signatures === []) {
            return [];
        }

        $items = array_map(function (array $signature): array {
            $name = (string) $signature['name'];
            if ($signature['is_obsolete']) {
                $name .= ' · ' . $this->trans('telegram_account_article.obsolete');
            }

            return [
                [
                    'type' => 'url',
                    'text' => $name,
                    'url' => self::BSN_BASE_URL . '/documents/' . rawurlencode((string) $signature['hash']),
                ],
                ' · ',
                $this->code((string) $signature['hash_short']),
            ];
        }, $signatures);

        return [
            $this->heading($this->trans('telegram_account_article.signed_documents'), 2),
            [
                'type' => 'details',
                'summary' => $this->countLabel(count($signatures), 'documents'),
                'blocks' => [
                    $this->listBlock($items),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $multisig
     * @return list<array<string, mixed>>
     */
    private function multisigBlocks(?array $multisig): array
    {
        if ($multisig === null) {
            return [];
        }

        $thresholds = $multisig['thresholds'];
        $signers = array_values($multisig['signers']);

        $blocks = [
            $this->heading($this->trans('account_page.multisig.header'), 2),
            $this->paragraph([
                $this->bold($this->trans('account_page.multisig.limit') . ': '),
                sprintf(
                    '%s %d / %s %d / %s %d',
                    $this->trans('account_page.multisig.thresholds.low'),
                    (int) $thresholds['low'],
                    $this->trans('account_page.multisig.thresholds.med'),
                    (int) $thresholds['med'],
                    $this->trans('account_page.multisig.thresholds.high'),
                    (int) $thresholds['high']
                ),
                "\n",
                $this->bold($this->trans('account_page.multisig.master_key') . ': '),
                (string) $multisig['master_key'],
            ]),
        ];
        if ($signers !== []) {
            $blocks[] = [
                'type' => 'details',
                'summary' => $this->trans('telegram_account_article.signers'),
                'blocks' => [
                    $this->paragraph($this->weightedAccountLinksText($signers, false)),
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $participations
     * @return list<array<string, mixed>>
     */
    private function multisigParticipationBlocks(array $participations): array
    {
        if ($participations === []) {
            return [];
        }

        return [
            $this->heading($this->trans('account_page.multisig.participates_in'), 2),
            [
                'type' => 'details',
                'summary' => $this->countLabel(count($participations), 'accounts'),
                'blocks' => [
                    $this->paragraph($this->weightedAccountLinksText($participations, true)),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function footer(array $source, string $account_url): array
    {
        $snapshot_timestamp = is_int($source['snapshot_at'] ?? null)
            ? $source['snapshot_at']
            : null;
        $generated_timestamp = is_int($source['generated_at'] ?? null)
            ? $source['generated_at']
            : null;

        $snapshot_at = $snapshot_timestamp === null
            ? $this->trans('telegram_account_article.footer.unknown')
            : gmdate('Y-m-d H:i', $snapshot_timestamp) . ' UTC';
        if ($generated_timestamp === null) {
            $generated_at = $this->trans('telegram_account_article.footer.unknown');
        } elseif ($snapshot_timestamp !== null
            && gmdate('Y-m-d', $snapshot_timestamp) === gmdate('Y-m-d', $generated_timestamp)
        ) {
            $generated_at = gmdate('H:i', $generated_timestamp) . ' UTC';
        } else {
            $generated_at = gmdate('Y-m-d H:i', $generated_timestamp) . ' UTC';
        }

        return [
            'type' => 'footer',
            'text' => [
                sprintf(
                    'BSN: %s · %s: %s · ',
                    $snapshot_at,
                    $this->trans('telegram_account_article.footer.article'),
                    $generated_at
                ),
                [
                    'type' => 'url',
                    'text' => $this->trans('telegram_account_article.footer.full_page'),
                    'url' => $account_url,
                ],
            ],
        ];
    }

    private function pairMarker(string $status): string
    {
        return match ($status) {
            'confirmed' => '🟢',
            'required_missing' => '🔴',
            'missing' => '🟡',
            default => '⚪️',
        };
    }

    private function profileFieldLabel(string $key): string
    {
        return match ($key) {
            'TimeTokenCode' => $this->trans('account_page.timetoken'),
            default => $key,
        };
    }

    /** @param array<string, int|string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->Translator->trans($key, $parameters, null, $this->locale);
    }

    private function countLabel(int $count, string $noun): string
    {
        $form = 'many';
        if ($this->locale === 'ru') {
            $last_two_digits = $count % 100;
            $last_digit = $count % 10;
            if ($last_two_digits < 11 || $last_two_digits > 19) {
                if ($last_digit === 1) {
                    $form = 'one';
                } elseif ($last_digit >= 2 && $last_digit <= 4) {
                    $form = 'few';
                }
            }
        } elseif ($count === 1) {
            $form = 'one';
        }

        return sprintf(
            '%d %s',
            $count,
            $this->trans(sprintf('telegram_account_article.counts.%s.%s', $noun, $form))
        );
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountLink(array $account): array
    {
        return [
            'type' => 'url',
            'text' => (string) $account['label'],
            'url' => $this->accountTelegramUrl($account),
        ];
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountTelegramUrl(array $account): string
    {
        return sprintf(
            'https://t.me/%s?start=a_%s',
            rawurlencode($this->Config->botUsername()),
            rawurlencode((string) $account['id'])
        );
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountUrl(array $account): string
    {
        if (is_string($account['username'] ?? null) && $account['username'] !== '') {
            return self::BSN_BASE_URL . '/@' . rawurlencode($account['username']);
        }

        return self::BSN_BASE_URL . '/accounts/' . rawurlencode((string) $account['id']);
    }

    private function heading(mixed $text, int $size): array
    {
        return [
            'type' => 'heading',
            'text' => $text,
            'size' => $size,
        ];
    }

    private function paragraph(mixed $text): array
    {
        return [
            'type' => 'paragraph',
            'text' => $text,
        ];
    }

    /**
     * @param list<array<int, mixed>> $items
     */
    private function listBlock(array $items): array
    {
        return [
            'type' => 'list',
            'items' => array_map(function (array $item): array {
                $item = array_values(array_filter(
                    $item,
                    static fn(mixed $part): bool => $part !== ''
                ));

                return [
                    'blocks' => [
                        $this->paragraph($item),
                    ],
                ];
            }, array_values($items)),
        ];
    }

    private function bold(mixed $text): array
    {
        return [
            'type' => 'bold',
            'text' => $text,
        ];
    }

    private function italic(mixed $text): array
    {
        return [
            'type' => 'italic',
            'text' => $text,
        ];
    }

    private function code(mixed $text): array
    {
        return [
            'type' => 'code',
            'text' => $text,
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            $count++;
            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $count += $this->countBlocks(array_values($block['blocks']));
            }
            if (($block['type'] ?? null) === 'list' && is_array($block['items'] ?? null)) {
                foreach ($block['items'] as $item) {
                    $count++;
                    if (is_array($item['blocks'] ?? null)) {
                        $count += $this->countBlocks(array_values($item['blocks']));
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function countBlockCharacters(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            foreach (['text', 'summary', 'credit'] as $key) {
                if (array_key_exists($key, $block)) {
                    $count += $this->countRichTextCharacters($block[$key]);
                }
            }
            if (isset($block['blocks']) && is_array($block['blocks'])) {
                $count += $this->countBlockCharacters(array_values($block['blocks']));
            }
            if (($block['type'] ?? null) === 'list' && is_array($block['items'] ?? null)) {
                foreach ($block['items'] as $item) {
                    if (is_array($item['blocks'] ?? null)) {
                        $count += $this->countBlockCharacters(array_values($item['blocks']));
                    }
                }
            }
        }

        return $count;
    }

    private function countRichTextCharacters(mixed $text): int
    {
        if (is_string($text)) {
            return mb_strlen($text, 'UTF-8');
        }
        if (!is_array($text)) {
            return 0;
        }
        if (array_is_list($text)) {
            return array_sum(array_map($this->countRichTextCharacters(...), $text));
        }

        return array_key_exists('text', $text)
            ? $this->countRichTextCharacters($text['text'])
            : mb_strlen((string) ($text['alternative_text'] ?? ''), 'UTF-8');
    }
}
