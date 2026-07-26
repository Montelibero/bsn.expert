<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\Knowledge\AccountReportBuilder;
use Montelibero\BSN\RequestLocale;
use Montelibero\BSN\Telegram\AccountRichMessageRenderer;
use Montelibero\BSN\Telegram\TelegramBotApiClient;
use Montelibero\BSN\Telegram\TelegramBotConfig;
use Soneso\StellarSDK\Crypto\KeyPair;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class TelegramArticleAccountsManager extends AccountsManager
{
    /**
     * @param array<string, string> $usernames
     */
    public function __construct(private readonly array $usernames)
    {
    }

    public function fetchUsernames(): array
    {
        return $this->usernames;
    }
}

final class TelegramArticleDocumentsManager extends DocumentsManager
{
    public function __construct()
    {
    }

    public function getDocuments(?string $source = null): array
    {
        return [];
    }
}

function assertTelegramArticle(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function telegramArticlePlainText(mixed $text): string
{
    if (is_string($text)) {
        return $text;
    }
    if (!is_array($text)) {
        return '';
    }
    if (array_is_list($text)) {
        return implode('', array_map(telegramArticlePlainText(...), $text));
    }

    return telegramArticlePlainText($text['text'] ?? ($text['alternative_text'] ?? ''));
}

function telegramArticleUrlText(mixed $value, string $url): ?string
{
    if (!is_array($value)) {
        return null;
    }
    if (($value['type'] ?? null) === 'url' && ($value['url'] ?? null) === $url) {
        return telegramArticlePlainText($value['text'] ?? '');
    }

    foreach ($value as $child) {
        $text = telegramArticleUrlText($child, $url);
        if ($text !== null) {
            return $text;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $blocks
 */
function telegramArticleHeadingIndex(array $blocks, string $title): ?int
{
    foreach ($blocks as $index => $block) {
        if (($block['type'] ?? null) === 'heading'
            && telegramArticlePlainText($block['text'] ?? '') === $title
        ) {
            return $index;
        }
    }

    return null;
}

$account_id = 'GDUMK6YJZ6ZC72CAMVHLUHLIFTNSLD7WFWO75Q3T2EOEW75XWH4PNSOZ';
$target_ids = [];
for ($index = 0; $index < 12; $index++) {
    $target_ids[] = KeyPair::random()->getAccountId();
}

$Catalog = new KnownTagsCatalog(new RequestLocale(), dirname(__DIR__) . '/known_tags');
$BSN = new BSN(
    new TelegramArticleAccountsManager([
        $account_id => 'Soz',
        $target_ids[0] => 'paired_friend',
        $target_ids[11] => 'unnamed_account',
    ]),
    new TelegramArticleDocumentsManager()
);
$BSN->loadKnownTags($Catalog->list());

$accounts = [
    $account_id => [
        'profile' => [
            'Name' => ['Soz Nov'],
            'About' => ['Ancap, activist of Montelibero <b>as plain text</b>'],
            'Website' => [
                'https://sozidatel.com',
                'https://t.me/soznov',
                'javascript:alert(1)',
            ],
            'TimeTokenCode' => ['SOZ'],
            'BirthDate' => ['1982-11-14'],
            'Telegram' => ['must_not_be_amplified'],
            'TelegramUserID' => ['private_3718221'],
        ],
        'tags' => [
            'Friend' => $target_ids,
            'Like' => array_slice($target_ids, 0, 10),
            'MyGuide' => [$target_ids[4]],
            'MyJudge' => [$target_ids[5]],
            'Owner' => [$target_ids[2]],
            'OwnerMajority' => [$target_ids[2]],
            'OwnershipFull' => [$target_ids[3], $target_ids[4]],
            'MysteryTag' => [$target_ids[0]],
        ],
        'signatures' => [
            str_repeat('a', 64) => 'Montelibero Declaration',
        ],
        'multisig' => [
            'thresholds' => [1, 2, 3],
            'master_key' => 2,
            'signers' => [
                [$target_ids[0], 2],
                [$target_ids[1], 1],
            ],
        ],
    ],
];

foreach ($target_ids as $index => $target_id) {
    $accounts[$target_id] = [
        'profile' => [
            'Name' => ['Linked account ' . ($index + 1)],
        ],
    ];
}
$accounts[$target_ids[11]] = [];
$accounts[$target_ids[0]]['tags'] = [
    'Friend' => [$account_id],
];
$accounts[$target_ids[1]]['multisig'] = [
    'thresholds' => [1, 2, 3],
    'master_key' => 1,
    'signers' => [
        [$account_id, 2],
    ],
];
$accounts[$target_ids[2]]['tags'] = [
    'OwnershipFull' => [$account_id],
    'OwnershipMajority' => [$account_id],
];
$accounts[$target_ids[3]]['tags'] = [
    'Owner' => [$account_id],
];

$BSN->loadFromJson([
    'createDate' => '2026-07-25T15:48:41+00:00',
    'accounts' => $accounts,
]);

$ReportBuilder = new AccountReportBuilder($BSN, $Catalog);
$report = $ReportBuilder->build($account_id, 'ru');
$report_json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

assertTelegramArticle(
    $BSN->getAccountById($account_id)?->getDisplayName(),
    $report['account']['label'],
    'The public label must reuse Account::getDisplayName().'
);
assertTelegramArticle('Soz', $report['account']['username'], 'The public federation username must be present.');
assertTelegramArticle(true, $report['account']['is_known_in_bsn'], 'A snapshot account must be marked as known to BSN.');
assertTelegramArticle(
    strtotime('2026-07-25T15:48:41+00:00'),
    $report['source']['snapshot_at'],
    'The live createDate field must become the snapshot timestamp.'
);
assertTelegramArticle(28, $report['relations']['outcome']['links_count'], 'Only known links must be counted.');
assertTelegramArticle(
    false,
    str_contains($report_json, 'MysteryTag'),
    'Unknown tags must be absent from the public report.'
);
assertTelegramArticle(
    $target_ids[2],
    $report['ownership']['owner']['id'] ?? null,
    'Only a confirmed Owner to OwnershipFull pair may define the owner.'
);
assertTelegramArticle(1, count($report['ownership']['owned']), 'Only confirmed OwnershipFull links may define owned accounts.');
assertTelegramArticle(
    $target_ids[3],
    $report['ownership']['owned'][0]['id'] ?? null,
    'An unconfirmed OwnershipFull link must be excluded from ownership.'
);
assertTelegramArticle(
    false,
    str_contains($report_json, 'must_not_be_amplified'),
    'Telegram profile metadata must not be amplified in the public report.'
);
assertTelegramArticle(
    false,
    str_contains($report_json, 'private_3718221'),
    'Telegram user IDs must not be present in the public report.'
);
assertTelegramArticle(
    false,
    str_contains($report_json, '1982-11-14'),
    'Non-allowlisted profile fields must not be present in the public report.'
);
assertTelegramArticle(
    false,
    str_contains($report_json, 'javascript:alert'),
    'Unsafe website schemes must be excluded.'
);

$Translator = new Translator('ru');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.ru.yaml', 'ru');
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.en.yaml', 'en');
$BotConfig = new TelegramBotConfig([
    'TG_BOT_USERNAME' => 'BSN_test_robot',
]);
$Renderer = new AccountRichMessageRenderer($Translator, $BotConfig);
$message = $Renderer->render($report);
$message_json = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

$unknown_account_id = KeyPair::random()->getAccountId();
$known_accounts_count = $BSN->getAccountsCount();
$unknown_report = $ReportBuilder->build($unknown_account_id, 'ru');
$unknown_message = $Renderer->render($unknown_report);
$unknown_message_json = json_encode(
    $unknown_message,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);
assertTelegramArticle(false, $unknown_report['account']['is_known_in_bsn'], 'An absent account must be marked as unknown to BSN.');
assertTelegramArticle($known_accounts_count, $BSN->getAccountsCount(), 'Rendering an unknown account must not mutate the BSN snapshot.');
assertTelegramArticle(0, $unknown_report['relations']['income']['links_count'], 'An unknown account must have zero incoming tags.');
assertTelegramArticle(0, $unknown_report['relations']['outcome']['links_count'], 'An unknown account must have zero outgoing tags.');
assertTelegramArticle(
    1,
    count($unknown_message['rich_message']['blocks']),
    'An unknown account article must contain only one block.'
);
assertTelegramArticle(
    'footer',
    $unknown_message['rich_message']['blocks'][0]['type'] ?? null,
    'The only unknown-account block must be the article footer.'
);
$unknown_footer_text = telegramArticlePlainText(
    $unknown_message['rich_message']['blocks'][0]['text'] ?? ''
);
assertTelegramArticle(
    true,
    str_contains(
        $unknown_footer_text,
        'BSN snapshot: ' . gmdate('d.m.Y H:i', (int) $unknown_report['source']['snapshot_at']) . ' UTC'
    ),
    'The unknown-account footer must show when the BSN snapshot was created.'
);
assertTelegramArticle(
    true,
    str_contains(
        $unknown_footer_text,
        'статья: ' . gmdate('d.m.Y H:i', (int) $unknown_report['source']['generated_at']) . ' UTC'
    ),
    'The unknown-account footer must show when the report was generated.'
);
assertTelegramArticle(
    1,
    $unknown_message['stats']['blocks'],
    'The unknown-account article statistics must reflect the footer-only response.'
);
assertTelegramArticle(
    false,
    str_contains($unknown_message_json, 'Входящие теги'),
    'An unknown account article must not contain empty tag sections.'
);
assertTelegramArticle(
    false,
    str_contains($unknown_message_json, 'В BSN.expert пока нет сведений'),
    'An unknown account article must not contain a separate unknown-account notice.'
);

$invalid_account_error = null;
try {
    $ReportBuilder->build('not-a-stellar-account', 'ru');
} catch (InvalidArgumentException $Exception) {
    $invalid_account_error = $Exception->getMessage();
}
assertTelegramArticle(
    'Укажите корректный Stellar-адрес аккаунта.',
    $invalid_account_error,
    'Only malformed Stellar addresses must fail article generation.'
);

assertTelegramArticle(true, $message['rich_message']['skip_entity_detection'], 'Entity detection must be disabled.');
assertTelegramArticle(true, $message['stats']['blocks'] <= 400, 'The article must stay below its block budget.');
assertTelegramArticle(true, $message['stats']['characters'] <= 28000, 'The article must stay below its text budget.');
assertTelegramArticle('heading', $message['rich_message']['blocks'][0]['type'], 'The article must start with a heading.');
assertTelegramArticle(1, $message['rich_message']['blocks'][0]['size'], 'The account heading must be level one.');
assertTelegramArticle('Soz Nov', $message['rich_message']['blocks'][0]['text'], 'The account name must be the H1.');
assertTelegramArticle('paragraph', $message['rich_message']['blocks'][1]['type'] ?? null, 'The federation username must have its own block.');
assertTelegramArticle('code', $message['rich_message']['blocks'][1]['text']['type'] ?? null, 'The federation username must remain copyable code.');
assertTelegramArticle('Soz*bsn.expert', $message['rich_message']['blocks'][1]['text']['text'] ?? null, 'The federation username must be independently copyable.');
assertTelegramArticle('paragraph', $message['rich_message']['blocks'][2]['type'] ?? null, 'The Stellar address must have its own block.');
assertTelegramArticle('code', $message['rich_message']['blocks'][2]['text']['type'] ?? null, 'The Stellar address must remain copyable code.');
assertTelegramArticle($account_id, $message['rich_message']['blocks'][2]['text']['text'] ?? null, 'The Stellar address must be independently copyable.');
assertTelegramArticle(false, array_key_exists('reply_markup', $message), 'The article must not include inline buttons.');
assertTelegramArticle(true, str_contains($message_json, '"type":"details"'), 'Tag data must use collapsible blocks.');
assertTelegramArticle(false, str_contains($message_json, 'MysteryTag'), 'Unknown tags must not be shown.');
assertTelegramArticle(false, str_contains($message_json, 'односторонняя связь'), 'Circle markers replace pair prose.');
assertTelegramArticle(false, str_contains($message_json, 'Заявлено этим аккаунтом'), 'Legacy direction labels must be absent.');
assertTelegramArticle(false, str_contains($message_json, 'Заявлено об этом аккаунте'), 'Legacy direction labels must be absent.');
assertTelegramArticle(true, str_contains($message_json, 'Теги: '), 'The summary must call relations tags.');
assertTelegramArticle(false, str_contains($message_json, 'Связи: '), 'The legacy relation summary label must be absent.');
assertTelegramArticle(false, str_contains($message_json, 'Владельцы: '), 'The summary must not repeat owner counts.');
assertTelegramArticle(false, str_contains($message_json, 'Под контролем аккаунта: '), 'The summary must not repeat controlled account counts.');
assertTelegramArticle(false, str_contains($message_json, 'может подписать самостоятельно'), 'Participation prose must stay compact.');
assertTelegramArticle(false, str_contains($message_json, 'может пройти средний порог самостоятельно'), 'Signer prose must stay compact.');
foreach (['🟢', '🔴', '🟡', '⚪️'] as $marker) {
    assertTelegramArticle(
        true,
        str_contains($message_json, $marker),
        sprintf('The article must use the %s pairing marker.', $marker)
    );
}
assertTelegramArticle(
    true,
    str_contains(
        $message_json,
        (string) $BSN->getAccountById($target_ids[11])?->getDisplayName()
    ),
    'Known tag links must not be truncated.'
);
$first_linked_account = null;
$friend_group_report = null;
foreach ($report['relations']['outcome']['groups'] as $group) {
    if (($group['name'] ?? null) === 'Friend') {
        $friend_group_report = $group;
    }
    foreach ($group['items'] as $item) {
        if (($item['id'] ?? null) === $target_ids[0]) {
            $first_linked_account = $item;
            break;
        }
    }
}
assertTelegramArticle(
    $target_ids[0],
    $friend_group_report['items'][0]['id'] ?? null,
    'Changing the visible account format must not change the existing name-based order.'
);
$first_linked_display_name = $BSN->getAccountById($target_ids[0])?->getDisplayName();
$account_start_parameter = 'a_' . $target_ids[0];
assertTelegramArticle(
    true,
    strlen($account_start_parameter) <= 64,
    'The complete account start parameter must fit Telegram\'s 64-character limit.'
);
assertTelegramArticle(
    $first_linked_display_name,
    $first_linked_account['label'] ?? null,
    'Linked-account report data must reuse Account::getDisplayName().'
);
assertTelegramArticle(
    $first_linked_display_name,
    telegramArticleUrlText(
        $message['rich_message'],
        'https://t.me/BSN_test_robot?start=' . $account_start_parameter
    ),
    'A named Telegram account deep link must show short_id followed by the profile name.'
);
assertTelegramArticle(
    false,
    str_contains(
        $message_json,
        'Linked account 1 · ' . ($first_linked_account['short_id'] ?? '')
    ),
    'Telegram account links must not use the old name-first format.'
);
$unnamed_linked_display_name = $BSN->getAccountById($target_ids[11])?->getDisplayName();
assertTelegramArticle(
    $unnamed_linked_display_name,
    telegramArticleUrlText(
        $message['rich_message'],
        'https://t.me/BSN_test_robot?start=a_' . $target_ids[11]
    ),
    'An unnamed federation account deep link must show only its short_id.'
);
assertTelegramArticle(
    false,
    str_contains($message_json, 'unnamed_account*bsn.expert'),
    'A federation username must not replace the account display label.'
);
assertTelegramArticle(
    'полная страница',
    telegramArticleUrlText($message['rich_message'], 'https://bsn.expert/@Soz'),
    'The article footer must keep the full BSN.expert account link.'
);

$heading_sizes = [];
$incoming_heading_index = null;
$outgoing_heading_index = null;
foreach ($message['rich_message']['blocks'] as $index => $block) {
    if (($block['type'] ?? null) !== 'heading') {
        continue;
    }
    $heading_text = telegramArticlePlainText($block['text'] ?? '');
    $heading_sizes[$heading_text] = (int) ($block['size'] ?? 0);
    if ($heading_text === 'Входящие теги') {
        $incoming_heading_index = $index;
    }
    if ($heading_text === 'Исходящие теги') {
        $outgoing_heading_index = $index;
    }
}
assertTelegramArticle(false, isset($heading_sizes['Дополнительные сведения']), 'Extra profile data does not need a parent heading.');
assertTelegramArticle(3, $heading_sizes['Таймтокен'] ?? null, 'A profile field must reuse the account page translation.');
assertTelegramArticle(false, isset($heading_sizes['Описание']), 'A pullquote does not need an About heading.');
assertTelegramArticle(false, isset($heading_sizes['Ссылки']), 'Website links do not need a heading.');
assertTelegramArticle(true, is_int($incoming_heading_index), 'Incoming tags must have an H2.');
assertTelegramArticle(true, is_int($outgoing_heading_index), 'Outgoing tags must have an H2.');
assertTelegramArticle(
    true,
    is_int($incoming_heading_index)
        && is_int($outgoing_heading_index)
        && $incoming_heading_index < $outgoing_heading_index,
    'Incoming tags must be shown before outgoing tags.'
);

$incoming_details = is_int($incoming_heading_index)
    ? ($message['rich_message']['blocks'][$incoming_heading_index + 1] ?? null)
    : null;
$outgoing_details = is_int($outgoing_heading_index)
    ? ($message['rich_message']['blocks'][$outgoing_heading_index + 1] ?? null)
    : null;
assertTelegramArticle('details', $incoming_details['type'] ?? null, 'All incoming tags must share one outer details block.');
assertTelegramArticle('details', $outgoing_details['type'] ?? null, 'All outgoing tags must share one outer details block.');
assertTelegramArticle(false, (bool) ($incoming_details['is_open'] ?? false), 'Incoming tags must be collapsed by default.');
assertTelegramArticle(false, (bool) ($outgoing_details['is_open'] ?? false), 'Outgoing tags must be collapsed by default.');
assertTelegramArticle(
    sprintf('%d тега', count($report['relations']['income']['groups'])),
    $incoming_details['summary'] ?? null,
    'The incoming details summary must show only the localized tag count.'
);
assertTelegramArticle(
    sprintf('%d тегов', count($report['relations']['outcome']['groups'])),
    $outgoing_details['summary'] ?? null,
    'The outgoing details summary must show only the localized tag count.'
);

$outgoing_tag_headings = [];
foreach (($outgoing_details['blocks'] ?? []) as $block) {
    if (($block['type'] ?? null) === 'heading') {
        $outgoing_tag_headings[] = telegramArticlePlainText($block['text'] ?? '');
    }
}
assertTelegramArticle(
    ['Friend', 'Like', 'OwnershipFull', 'OwnerMajority', 'Owner', 'MyJudge', 'MyGuide'],
    $outgoing_tag_headings,
    'Telegram tags must follow the semantic order used on the account page.'
);

$friend_heading_index = null;
foreach (($outgoing_details['blocks'] ?? []) as $index => $block) {
    if (($block['type'] ?? null) !== 'heading') {
        continue;
    }
    if (telegramArticlePlainText($block['text'] ?? '') === 'Friend') {
        $friend_heading_index = $index;
        break;
    }
}
assertTelegramArticle(true, is_int($friend_heading_index), 'A tag group must have its own heading inside the outer details block.');
assertTelegramArticle(
    3,
    is_int($friend_heading_index)
        ? ($outgoing_details['blocks'][$friend_heading_index]['size'] ?? null)
        : null,
    'A tag group must use H3.'
);
assertTelegramArticle(
    true,
    is_int($friend_heading_index)
        && is_string($outgoing_details['blocks'][$friend_heading_index]['text'] ?? null),
    'A tag heading must be plain text rather than a link.'
);

$friend_links_details = null;
if (is_int($friend_heading_index)) {
    for ($index = $friend_heading_index + 1; $index < count($outgoing_details['blocks']); $index++) {
        $block = $outgoing_details['blocks'][$index];
        if (($block['type'] ?? null) === 'heading') {
            break;
        }
        if (($block['type'] ?? null) === 'details') {
            $friend_links_details = $block;
            break;
        }
    }
}
assertTelegramArticle(true, is_array($friend_links_details), 'All Friend links must be nested below the tag heading.');
assertTelegramArticle('12 аккаунтов', $friend_links_details['summary'] ?? null, 'A tag details summary must show its localized link count.');
assertTelegramArticle(false, (bool) ($friend_links_details['is_open'] ?? false), 'Tag accounts must be collapsed by default.');
$friend_links_block = $friend_links_details['blocks'][0] ?? null;
assertTelegramArticle('paragraph', $friend_links_block['type'] ?? null, 'Tag links must share one text block.');
assertTelegramArticle(
    true,
    is_string($unnamed_linked_display_name)
        && str_contains(
            telegramArticlePlainText($friend_links_block['text'] ?? ''),
            $unnamed_linked_display_name
        ),
    'The inner details block must contain every Friend link.'
);

$owner_paragraph = null;
$owned_paragraph = null;
foreach ($message['rich_message']['blocks'] as $block) {
    if (($block['type'] ?? null) !== 'paragraph') {
        continue;
    }
    $plain_text = telegramArticlePlainText($block['text'] ?? '');
    if (str_starts_with($plain_text, 'Аккаунт принадлежит')) {
        $owner_paragraph = $plain_text;
    }
    if (str_starts_with($plain_text, 'Аккаунту принадлежат')) {
        $owned_paragraph = $plain_text;
    }
}
assertTelegramArticle(true, is_string($owner_paragraph), 'Confirmed Owner data must have a compact ownership paragraph.');
assertTelegramArticle(true, str_contains((string) $owner_paragraph, 'Linked account 3'), 'The confirmed owner must be shown.');
assertTelegramArticle(true, is_string($owned_paragraph), 'Confirmed OwnershipFull data must have a compact ownership paragraph.');
assertTelegramArticle(true, str_contains((string) $owned_paragraph, 'Linked account 4'), 'A confirmed owned account must be shown.');
assertTelegramArticle(false, str_contains((string) $owned_paragraph, 'Linked account 5'), 'An unconfirmed owned account must not be shown as ownership.');

$document_heading_index = telegramArticleHeadingIndex(
    $message['rich_message']['blocks'],
    'Подписанные документы'
);
$document_details = is_int($document_heading_index)
    ? ($message['rich_message']['blocks'][$document_heading_index + 1] ?? null)
    : null;
assertTelegramArticle(true, is_int($document_heading_index), 'Signed documents must have an H2 without a count.');
assertTelegramArticle('details', $document_details['type'] ?? null, 'Signed documents must be collapsible.');
assertTelegramArticle('1 документ', $document_details['summary'] ?? null, 'The document details summary must contain the count.');
assertTelegramArticle(false, (bool) ($document_details['is_open'] ?? false), 'Signed documents must be collapsed by default.');

$multisig_heading_index = telegramArticleHeadingIndex($message['rich_message']['blocks'], 'Мультиподпись');
$multisig_summary = is_int($multisig_heading_index)
    ? ($message['rich_message']['blocks'][$multisig_heading_index + 1] ?? null)
    : null;
$signers_details = is_int($multisig_heading_index)
    ? ($message['rich_message']['blocks'][$multisig_heading_index + 2] ?? null)
    : null;
assertTelegramArticle('paragraph', $multisig_summary['type'] ?? null, 'Multisig thresholds and master key must share one paragraph.');
$multisig_summary_text = telegramArticlePlainText($multisig_summary['text'] ?? '');
assertTelegramArticle(true, str_contains($multisig_summary_text, 'Лимиты: low 1 / med 2 / high 3'), 'Multisig thresholds must reuse the account page labels.');
assertTelegramArticle(true, str_contains($multisig_summary_text, 'Мастер ключ: 2'), 'The master key weight must be visible.');
assertTelegramArticle('details', $signers_details['type'] ?? null, 'Signers must be collapsible.');
assertTelegramArticle('Подписанты', $signers_details['summary'] ?? null, 'The signer details title must stay compact.');
assertTelegramArticle('paragraph', $signers_details['blocks'][0]['type'] ?? null, 'All signers must share one text block.');
assertTelegramArticle(
    true,
    str_contains(telegramArticlePlainText($signers_details['blocks'][0]['text'] ?? ''), '(2)'),
    'Signer weights must be shown in parentheses.'
);

$participation_heading_index = telegramArticleHeadingIndex(
    $message['rich_message']['blocks'],
    'Участвует в мультиподписи'
);
$participation_details = is_int($participation_heading_index)
    ? ($message['rich_message']['blocks'][$participation_heading_index + 1] ?? null)
    : null;
assertTelegramArticle('details', $participation_details['type'] ?? null, 'Multisig participations must be collapsible.');
assertTelegramArticle('1 аккаунт', $participation_details['summary'] ?? null, 'Participation details must contain the account count.');
assertTelegramArticle('paragraph', $participation_details['blocks'][0]['type'] ?? null, 'All participations must share one text block.');
assertTelegramArticle(
    true,
    str_contains(telegramArticlePlainText($participation_details['blocks'][0]['text'] ?? ''), '(2/2)'),
    'Participation weights must use the weight/threshold format.'
);

$many_participations_report = $report;
$many_participations_report['multisig_participations'] = [];
foreach ($report['relations']['outcome']['groups'][0]['items'] as $linked_account) {
    $many_participations_report['multisig_participations'][] = [
        'account' => $linked_account,
        'weight' => 1,
        'med_threshold' => 10,
    ];
}
$many_participations_message = $Renderer->render($many_participations_report);
$many_participations_heading_index = telegramArticleHeadingIndex(
    $many_participations_message['rich_message']['blocks'],
    'Участвует в мультиподписи'
);
$many_participations_details = is_int($many_participations_heading_index)
    ? ($many_participations_message['rich_message']['blocks'][$many_participations_heading_index + 1] ?? null)
    : null;
$many_participations_text = telegramArticlePlainText(
    $many_participations_details['blocks'][0]['text'] ?? ''
);
assertTelegramArticle('12 аккаунтов', $many_participations_details['summary'] ?? null, 'Participation details must not retain the old ten-account limit.');
assertTelegramArticle(
    true,
    is_string($unnamed_linked_display_name)
        && str_contains($many_participations_text, $unnamed_linked_display_name),
    'Every multisig participation must be shown.'
);
assertTelegramArticle(true, str_contains($many_participations_text, '(1/10)'), 'Every participation must include its weight and threshold.');
assertTelegramArticle(false, str_contains($many_participations_text, 'Показано'), 'Participation output must not contain a truncation notice.');

$without_name_report = $report;
$without_name_report['account']['name'] = null;
$without_name_report['account']['label'] = 'Soz*bsn.expert';
$without_name_message = $Renderer->render($without_name_report);
assertTelegramArticle(
    $report['account']['short_id'],
    $without_name_message['rich_message']['blocks'][0]['text'],
    'The H1 must fall back to short_id instead of a federation username.'
);

$many_groups_report = $report;
$sample_link = $report['relations']['outcome']['groups'][0]['items'][0];
$many_groups_report['relations']['outcome']['groups'] = [];
for ($index = 1; $index <= 9; $index++) {
    $many_groups_report['relations']['outcome']['groups'][] = [
        'name' => 'KnownTag' . $index,
        'description' => null,
        'count' => 1,
        'items' => [$sample_link],
    ];
}
$many_groups_report['relations']['outcome']['groups_count'] = 9;
$many_groups_report['relations']['outcome']['links_count'] = 9;
$many_groups_message = $Renderer->render($many_groups_report);
$many_groups_json = json_encode($many_groups_message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
assertTelegramArticle(
    true,
    str_contains($many_groups_json, 'KnownTag9'),
    'Known tag groups must not be truncated after the eighth group.'
);
$pullquotes = array_values(array_filter(
    $message['rich_message']['blocks'],
    static fn(array $block): bool => ($block['type'] ?? null) === 'pullquote'
));
assertTelegramArticle($report['profile']['about'][0], $pullquotes[0]['text'] ?? null, 'Profile text must stay plain.');

$history = [];
$Mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'result' => [
            'message_id' => 321,
        ],
    ], JSON_THROW_ON_ERROR)),
    new Response(401, ['Content-Type' => 'application/json'], json_encode([
        'ok' => false,
        'error_code' => 401,
        'description' => 'Unauthorized token 123456:TEST_token',
    ], JSON_THROW_ON_ERROR)),
]);
$Handler = HandlerStack::create($Mock);
$Handler->push(Middleware::history($history));
$HttpClient = new Client([
    'handler' => $Handler,
    'base_uri' => 'https://api.telegram.org/',
]);
$ApiClient = new TelegramBotApiClient('123456:TEST_token', $HttpClient);
$result = $ApiClient->sendRichMessage(
    '3718221',
    $message['rich_message']
);

assertTelegramArticle(321, $result['message_id'], 'The API client must return the sent message.');
assertTelegramArticle(1, count($history), 'One API call must send one message.');
assertTelegramArticle(
    'https://api.telegram.org/bot123456:TEST_token/sendRichMessage',
    (string) $history[0]['request']->getUri(),
    'The bot token colon must remain in the Telegram API path instead of becoming a URI scheme.'
);
$sent_payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
assertTelegramArticle('3718221', $sent_payload['chat_id'], 'The configured chat ID must be sent.');
assertTelegramArticle(
    $message['rich_message'],
    $sent_payload['rich_message'],
    'The renderer output must be passed to sendRichMessage unchanged.'
);
assertTelegramArticle(false, array_key_exists('reply_markup', $sent_payload), 'The Telegram payload must not include inline buttons.');

$safe_error = null;
try {
    $ApiClient->sendRichMessage('3718221', $message['rich_message']);
} catch (RuntimeException $Exception) {
    $safe_error = $Exception->getMessage();
}
assertTelegramArticle(true, is_string($safe_error), 'Telegram API errors must become safe exceptions.');
assertTelegramArticle(
    false,
    str_contains((string) $safe_error, '123456:TEST_token'),
    'Telegram API errors must not expose the bot token.'
);
assertTelegramArticle(
    true,
    str_contains((string) $safe_error, '[redacted]'),
    'Telegram API descriptions must explicitly redact the token.'
);

$admin_template = file_get_contents(dirname(__DIR__) . '/twig/admin_telegram.twig');
assertTelegramArticle(true, is_string($admin_template), 'The Telegram admin template must be readable.');
assertTelegramArticle(
    true,
    str_contains((string) $admin_template, 'name="csrf_token"'),
    'The webhook registration form must carry its CSRF token.'
);
assertTelegramArticle(
    true,
    str_contains((string) $admin_template, 'value="register_webhook"'),
    'The Telegram admin form must use the fixed webhook action.'
);
assertTelegramArticle(
    false,
    str_contains((string) $admin_template, 'name="account_id"'),
    'The removed one-off account test form must not remain.'
);
assertTelegramArticle(
    false,
    str_contains((string) $admin_template, 'name="chat_id"'),
    'The Telegram admin page must not accept an arbitrary recipient.'
);
assertTelegramArticle(
    true,
    str_contains((string) $admin_template, 'Использование за 14 дней'),
    'The Telegram admin page must expose the fourteen-day usage dashboard.'
);
assertTelegramArticle(
    true,
    str_contains((string) $admin_template, "(chat.title ?: chat.id)|e"),
    'Stored Telegram chat titles must be escaped in the admin dashboard.'
);
assertTelegramArticle(
    true,
    str_contains((string) $admin_template, "(user.name ?: 'без имени')|e"),
    'Stored Telegram user names must be escaped in the admin dashboard.'
);

fwrite(STDOUT, "Telegram account article regression test passed.\n");
