<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use DateTimeImmutable;
use DateTimeZone;

final class TelegramDailyReportRenderer
{
    private const MAX_BLOCKS = 400;
    private const MAX_TEXT_CHARACTERS = 28000;
    private const SECTION_CHARACTER_BUDGET = 8000;

    /**
     * @param array<string, mixed> $aggregate
     * @return array{
     *     rich_message: array<string, mixed>,
     *     stats: array{blocks: int, characters: int}
     * }
     */
    public function render(
        array $aggregate,
        ?DateTimeImmutable $generated_at = null,
    ): array {
        $day_utc = (string) ($aggregate['day_utc'] ?? '');
        TelegramUsageStore::assertDay($day_utc);
        $totals = is_array($aggregate['totals'] ?? null) ? $aggregate['totals'] : [];
        $accounts = array_values(is_array($aggregate['accounts'] ?? null) ? $aggregate['accounts'] : []);
        $chats = array_values(is_array($aggregate['chats'] ?? null) ? $aggregate['chats'] : []);
        $users = array_values(is_array($aggregate['users'] ?? null) ? $aggregate['users'] : []);
        $GeneratedAt = ($generated_at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        $blocks = [
            $this->heading('Суточный отчёт BSN Robot', 1),
            $this->paragraph([
                '#daily_report · ',
                $this->code($day_utc . ' UTC'),
            ]),
            $this->heading('Итого', 2),
            $this->paragraph([
                $this->bold('Обращения: '),
                $this->countLabel((int) ($totals['requests'] ?? 0), 'обращение', 'обращения', 'обращений'),
                "\n",
                $this->bold('Активные чаты: '),
                $this->countLabel((int) ($totals['unique_chats'] ?? 0), 'чат', 'чата', 'чатов'),
                "\n",
                $this->bold('Пользователи: '),
                $this->countLabel((int) ($totals['unique_users'] ?? 0), 'пользователь', 'пользователя', 'пользователей'),
                "\n",
                $this->bold('Аккаунты: '),
                $this->countLabel((int) ($totals['unique_accounts'] ?? 0), 'аккаунт', 'аккаунта', 'аккаунтов'),
            ]),
            ['type' => 'divider'],
            $this->heading('Популярные аккаунты', 2),
            $this->collapsedQuote(
                $this->countLabel(count($accounts), 'аккаунт', 'аккаунта', 'аккаунтов'),
                array_map(fn(array $account): array => $this->accountLine($account), $accounts)
            ),
            ['type' => 'divider'],
            $this->heading('Активные чаты', 2),
            $this->collapsedQuote(
                $this->countLabel(count($chats), 'чат', 'чата', 'чатов'),
                array_map(fn(array $chat): array => $this->chatLine($chat), $chats)
            ),
            ['type' => 'divider'],
            $this->heading('Пользователи', 2),
            $this->collapsedQuote(
                $this->countLabel(count($users), 'пользователь', 'пользователя', 'пользователей'),
                array_map(fn(array $user): array => $this->userLine($user), $users)
            ),
            ['type' => 'divider'],
            [
                'type' => 'footer',
                'text' => sprintf(
                    'Период: %s 00:00–24:00 UTC · сформировано %s UTC',
                    $day_utc,
                    $GeneratedAt->format('Y-m-d H:i')
                ),
            ],
        ];

        $block_count = $this->countBlocks($blocks);
        $character_count = $this->countBlockCharacters($blocks);
        if ($block_count > self::MAX_BLOCKS || $character_count > self::MAX_TEXT_CHARACTERS) {
            throw new \LengthException(sprintf(
                'Telegram daily report exceeds the internal limit: %d blocks, %d characters.',
                $block_count,
                $character_count
            ));
        }

        return [
            'rich_message' => [
                'blocks' => $blocks,
            ],
            'stats' => [
                'blocks' => $block_count,
                'characters' => $character_count,
            ],
        ];
    }

    /** @param array<string, mixed> $account */
    private function accountLine(array $account): array
    {
        $suffix = ($account['known'] ?? false) === true ? '' : ' · нет в BSN';

        return [
            $this->code((string) ($account['account_id'] ?? '')),
            sprintf(
                ' — %s · %s · %s%s',
                $this->countLabel((int) ($account['requests'] ?? 0), 'обращение', 'обращения', 'обращений'),
                $this->countLabel((int) ($account['chat_count'] ?? 0), 'чат', 'чата', 'чатов'),
                $this->countLabel((int) ($account['user_count'] ?? 0), 'пользователь', 'пользователя', 'пользователей'),
                $suffix
            ),
        ];
    }

    /** @param array<string, mixed> $chat */
    private function chatLine(array $chat): array
    {
        $title = trim((string) ($chat['title'] ?? ''));
        $username = trim((string) ($chat['username'] ?? ''));
        $type = trim((string) ($chat['type'] ?? ''));
        $identity = [];
        if ($title !== '') {
            $identity[] = $title;
        }
        if ($username !== '') {
            if ($identity !== []) {
                $identity[] = ' · ';
            }
            $identity[] = '@' . ltrim($username, '@');
        }
        if ($identity === []) {
            $identity[] = $type !== '' ? $type : 'Чат';
        }
        $identity[] = ' · ';
        $identity[] = $this->code((string) ($chat['chat_id'] ?? ''));
        $identity[] = sprintf(
            ' — %s · %s',
            $this->countLabel((int) ($chat['requests'] ?? 0), 'обращение', 'обращения', 'обращений'),
            $this->countLabel((int) ($chat['user_count'] ?? 0), 'пользователь', 'пользователя', 'пользователей')
        );

        return $identity;
    }

    /** @param array<string, mixed> $user */
    private function userLine(array $user): array
    {
        $name = trim((string) ($user['name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $identity = [];
        if ($name !== '') {
            $identity[] = $name;
        }
        if ($username !== '') {
            if ($identity !== []) {
                $identity[] = ' · ';
            }
            $identity[] = '@' . ltrim($username, '@');
        }
        if ($identity !== []) {
            $identity[] = ' · ';
        }
        $identity[] = $this->code((string) ($user['user_id'] ?? ''));
        $identity[] = sprintf(
            ' — %s · %s',
            $this->countLabel((int) ($user['requests'] ?? 0), 'обращение', 'обращения', 'обращений'),
            $this->countLabel((int) ($user['chat_count'] ?? 0), 'чат', 'чата', 'чатов')
        );

        return $identity;
    }

    /**
     * @param list<array<int, mixed>> $rows
     * @return array<string, mixed>
     */
    private function collapsedQuote(string $summary, array $rows): array
    {
        return [
            'type' => 'details',
            'summary' => $summary,
            'blocks' => [[
                'type' => 'blockquote',
                'blocks' => [
                    $this->paragraph($this->boundedRows($rows)),
                ],
            ]],
        ];
    }

    /**
     * @param list<array<int, mixed>> $rows
     * @return list<mixed>|string
     */
    private function boundedRows(array $rows): array|string
    {
        if ($rows === []) {
            return 'Нет данных.';
        }

        $text = [];
        $characters = 0;
        $visible = 0;
        foreach ($rows as $row) {
            $row_characters = $this->countRichTextCharacters($row) + ($visible > 0 ? 1 : 0);
            if ($characters + $row_characters > self::SECTION_CHARACTER_BUDGET) {
                break;
            }
            if ($visible > 0) {
                $text[] = "\n";
            }
            array_push($text, ...$row);
            $characters += $row_characters;
            $visible++;
        }

        $hidden = count($rows) - $visible;
        if ($hidden > 0) {
            if ($text !== []) {
                $text[] = "\n";
            }
            $text[] = $this->italic(sprintf('… ещё %d', $hidden));
        }

        return $text === [] ? 'Нет данных.' : $text;
    }

    private function countLabel(int $count, string $one, string $few, string $many): string
    {
        $count = max(0, $count);
        $last_two = $count % 100;
        $last = $count % 10;
        $noun = $many;
        if ($last_two < 11 || $last_two > 19) {
            if ($last === 1) {
                $noun = $one;
            } elseif ($last >= 2 && $last <= 4) {
                $noun = $few;
            }
        }

        return $count . "\u{00A0}" . $noun;
    }

    private function heading(mixed $text, int $size): array
    {
        return ['type' => 'heading', 'text' => $text, 'size' => $size];
    }

    private function paragraph(mixed $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }

    private function bold(mixed $text): array
    {
        return ['type' => 'bold', 'text' => $text];
    }

    private function italic(mixed $text): array
    {
        return ['type' => 'italic', 'text' => $text];
    }

    private function code(mixed $text): array
    {
        return ['type' => 'code', 'text' => $text];
    }

    /** @param list<array<string, mixed>> $blocks */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            $count++;
            if (is_array($block['blocks'] ?? null)) {
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

    /** @param list<array<string, mixed>> $blocks */
    private function countBlockCharacters(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            foreach (['text', 'summary', 'credit'] as $key) {
                if (array_key_exists($key, $block)) {
                    $count += $this->countRichTextCharacters($block[$key]);
                }
            }
            if (is_array($block['blocks'] ?? null)) {
                $count += $this->countBlockCharacters(array_values($block['blocks']));
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
