<?php
declare(strict_types=1);

use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\DocumentsManager;
use Soneso\StellarSDK\Crypto\KeyPair;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

final class SnapshotAccountsManager extends AccountsManager
{
    public function __construct()
    {
    }

    public function fetchUsernames(): array
    {
        return [];
    }
}

final class SnapshotDocumentsManager extends DocumentsManager
{
    public function __construct()
    {
    }

    public function getDocuments(?string $source = null): array
    {
        return [];
    }
}

function assertSnapshotState(mixed $expected, mixed $actual, string $message): void
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

function writeSnapshot(string $path, array $snapshot, int $mtime): void
{
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $encoded) === false || !touch($path, $mtime)) {
        throw new RuntimeException('Unable to write the snapshot fixture.');
    }
    clearstatcache(false, $path);
}

$Source = KeyPair::random()->getAccountId();
$Target = KeyPair::random()->getAccountId();
$Replacement = KeyPair::random()->getAccountId();
$BadAccount = KeyPair::random()->getAccountId();
$signature_hash = str_repeat('a', 64);
$snapshot_path = tempnam(sys_get_temp_dir(), 'bsn-snapshot-');
if ($snapshot_path === false) {
    throw new RuntimeException('Unable to create the snapshot fixture.');
}

try {
    $BSN = new BSN(new SnapshotAccountsManager(), new SnapshotDocumentsManager());
    $FriendTag = $BSN->makeTagByName('Friend');
    $FriendTag->isPromote(true);
    $FriendTag->isStandard(true);
    $FriendTag->isEditable(false);
    $OwnerTag = $BSN->makeTagByName('Owner');
    $OwnerTag->isSingle(true);
    $FriendTag->setPair($OwnerTag, true);
    $BSN->assignTagCategory($FriendTag, $BSN->makeTagCategoryById('social', 'Social'));

    writeSnapshot($snapshot_path, [
        'data_timestamp' => 100,
        'accounts' => [
            $Source => [
                'profile' => ['Name' => ['Original']],
                'tags' => ['Friend' => [$Target]],
                'signatures' => [$signature_hash => 'Original signature'],
            ],
            $Target => ['profile' => ['Name' => ['Target']]],
        ],
    ], time() - 10);
    $BSN->loadFromJsonFile($snapshot_path);

    $OriginalSource = $BSN->getAccountById($Source);
    $OriginalFriendTag = $BSN->getTag('Friend');
    $original_loaded_at = $BSN->getDataLoadedAt();

    assertSnapshotState(2, $BSN->getAccountsCount(), 'The initial snapshot must load both accounts.');
    assertSnapshotState(1, count($BSN->getLinks()), 'The initial snapshot must load its link.');
    assertSnapshotState(1, count($BSN->getSignatures()->getSignatures()), 'The initial snapshot must load its signature.');
    assertSnapshotState(100, $BSN->getDataTimestamp(), 'The initial snapshot timestamp must be recorded.');

    writeSnapshot($snapshot_path, [
        'data_timestamp' => 200,
        'accounts' => [
            $Replacement => ['profile' => ['Name' => ['Partial replacement']]],
            $BadAccount => ['profile' => 'not-an-array'],
        ],
    ], time());
    $BSN->refreshFromJsonFileIfChanged($snapshot_path);

    assertSnapshotState($OriginalSource, $BSN->getAccountById($Source), 'A failed refresh must retain the original account object.');
    assertSnapshotState(null, $BSN->getAccountById($Replacement), 'A failed refresh must not leak partially loaded accounts.');
    assertSnapshotState($OriginalFriendTag, $BSN->getTag('Friend'), 'A failed refresh must retain the original tag graph.');
    assertSnapshotState(2, $BSN->getAccountsCount(), 'A failed refresh must retain all original accounts.');
    assertSnapshotState(1, count($BSN->getLinks()), 'A failed refresh must retain all original links.');
    assertSnapshotState(1, count($BSN->getSignatures()->getSignatures()), 'A failed refresh must retain all original signatures.');
    assertSnapshotState(100, $BSN->getDataTimestamp(), 'A failed refresh must retain the original timestamp.');
    assertSnapshotState($original_loaded_at, $BSN->getDataLoadedAt(), 'A failed refresh must retain the original load time.');

    writeSnapshot($snapshot_path, [
        'data_timestamp' => 300,
        'accounts' => [
            $Replacement => ['profile' => ['Name' => ['Replacement']]],
        ],
    ], time() + 10);
    $BSN->refreshFromJsonFileIfChanged($snapshot_path);

    assertSnapshotState(1, $BSN->getAccountsCount(), 'A valid refresh must replace the account graph.');
    assertSnapshotState(null, $BSN->getAccountById($Source), 'A valid refresh must remove obsolete accounts.');
    assertSnapshotState('Replacement', $BSN->getAccountById($Replacement)?->getName()[0] ?? null, 'A valid refresh must expose new account data.');
    assertSnapshotState([], $BSN->getLinks(), 'A valid refresh must replace old links.');
    assertSnapshotState([], $BSN->getSignatures()->getSignatures(), 'A valid refresh must replace old signatures.');
    assertSnapshotState(300, $BSN->getDataTimestamp(), 'A valid refresh must commit the new timestamp.');
    assertSnapshotState(true, $BSN->getTag('Friend')?->isPromote(), 'Tag configuration must survive a valid refresh.');
    assertSnapshotState(true, $BSN->getTag('Friend')?->isStandard(), 'Standard-tag configuration must survive a valid refresh.');
    assertSnapshotState(false, $BSN->getTag('Friend')?->isEditable(), 'Editable-tag configuration must survive a valid refresh.');
    assertSnapshotState(true, $BSN->getTag('Owner')?->isSingle(), 'Single-tag configuration must survive a valid refresh.');
    assertSnapshotState('social', $BSN->getTag('Friend')?->getCategory()?->getId(), 'Tag categories must survive a valid refresh.');
    assertSnapshotState('Owner', $BSN->getTag('Friend')?->getPair()?->getName(), 'Tag pairs must survive a valid refresh.');
    assertSnapshotState(true, $BSN->getTag('Friend')?->isPairStrong(), 'Strong-pair configuration must survive a valid refresh.');

    $BSN->loadMtlaMembersFromJson([[
        'stellar' => $Replacement,
        'tg_id' => '12345',
        'tg_username' => 'replacement',
    ]]);
    assertSnapshotState('12345', $BSN->getAccountById($Replacement)?->getTelegramId(), 'MTLA member refresh must apply Telegram metadata.');
    assertSnapshotState($Replacement, $BSN->getAccountByTelegramId('12345')?->getId(), 'MTLA member refresh must rebuild the Telegram lookup.');

    $BSN->loadMtlaMembersFromJson([]);
    assertSnapshotState(null, $BSN->getAccountById($Replacement)?->getTelegramId(), 'Removed MTLA members must lose stale Telegram ids.');
    assertSnapshotState(null, $BSN->getAccountById($Replacement)?->getTelegramUsername(), 'Removed MTLA members must lose stale Telegram usernames.');
    assertSnapshotState(null, $BSN->getAccountByTelegramId('12345'), 'Removed MTLA members must disappear from the Telegram lookup.');
} finally {
    @unlink($snapshot_path);
}

fwrite(STDOUT, "BSN atomic snapshot reload regression test passed.\n");
