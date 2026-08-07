<?php
declare(strict_types=1);

use Montelibero\BSN\BsnManageDataSemanticService;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Xdr\XdrAccountID;
use Soneso\StellarSDK\Xdr\XdrDataEntry;
use Soneso\StellarSDK\Xdr\XdrDataEntryExt;
use Soneso\StellarSDK\Xdr\XdrDataValueMandatory;
use Soneso\StellarSDK\Xdr\XdrLedgerEntry;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryChange;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryChangeType;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryData;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryExt;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryType;
use Soneso\StellarSDK\Xdr\XdrOperationMeta;
use Soneso\StellarSDK\Xdr\XdrTransactionMeta;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertBsnManageDataSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function dataEntryChange(string $source, string $name, string $value, int $change_type): XdrLedgerEntryChange
{
    $DataEntry = new XdrDataEntry(
        new XdrAccountID($source),
        $name,
        new XdrDataValueMandatory($value),
        new XdrDataEntryExt(0),
    );
    $LedgerEntryData = new XdrLedgerEntryData(XdrLedgerEntryType::DATA());
    $LedgerEntryData->setData($DataEntry);
    $LedgerEntry = new XdrLedgerEntry(1, $LedgerEntryData, new XdrLedgerEntryExt(0, null));
    $Change = new XdrLedgerEntryChange(new XdrLedgerEntryChangeType($change_type));
    if ($change_type === XdrLedgerEntryChangeType::LEDGER_ENTRY_STATE) {
        $Change->setState($LedgerEntry);
    } elseif ($change_type === XdrLedgerEntryChangeType::LEDGER_ENTRY_CREATED) {
        $Change->setCreated($LedgerEntry);
    } else {
        throw new InvalidArgumentException('Unsupported fixture change type.');
    }

    return $Change;
}

$Source = KeyPair::random()->getAccountId();
$Alice = KeyPair::random()->getAccountId();
$Bob = KeyPair::random()->getAccountId();
$Service = new BsnManageDataSemanticService();

assertBsnManageDataSame('Love', BsnManageDataSemanticService::normalizeDataEntryTagName('Love12'), 'A numeric BSN slot suffix must be removed.');
assertBsnManageDataSame('PartOf', BsnManageDataSemanticService::normalizeDataEntryTagName('PartOf7:Supporter'), 'A pair suffix must not become part of the tag name.');
assertBsnManageDataSame(null, BsnManageDataSemanticService::normalizeDataEntryTagName('Bad tag'), 'Names outside the BSN tag grammar must stay technical data.');

$Overlay = [];
$set = $Service->analyzeAndApply($Source, 'Love1', $Alice, $Overlay);
assertBsnManageDataSame('set', $set[0]['kind'] ?? null, 'A valid account value must be recognized as a tag assignment.');
assertBsnManageDataSame($Source, $set[0]['source_account_id'] ?? null, 'Semantics must use the effective operation source supplied by the caller.');
assertBsnManageDataSame($Alice, $set[0]['target_account_id'] ?? null, 'The raw ManageData value must be the assignment target.');

$remove = $Service->analyzeAndApply($Source, 'Love1', null, $Overlay);
assertBsnManageDataSame('remove', $remove[0]['kind'] ?? null, 'A sequential deletion must resolve the target assigned earlier in the same overlay.');
assertBsnManageDataSame($Alice, $remove[0]['target_account_id'] ?? null, 'A sequential deletion must retain the exact prior target.');

$SnapshotOverlay = [
    $Source => $Service->snapshotFromHorizonData([
        'Love1' => base64_encode($Alice),
        'Ignored' => false,
    ]),
];
$replacement = $Service->analyzeAndApply($Source, 'Love1', $Bob, $SnapshotOverlay);
assertBsnManageDataSame(['remove', 'set'], array_column($replacement, 'kind'), 'Replacing a known tag target must describe both semantic changes.');
assertBsnManageDataSame($Alice, $replacement[0]['target_account_id'] ?? null, 'Replacement removal must reference the snapshot target.');
assertBsnManageDataSame($Bob, $replacement[1]['target_account_id'] ?? null, 'Replacement assignment must reference the operation value.');

$UnknownOverlay = [];
$unknown_remove = $Service->analyzeAndApply($Source, 'Love1', null, $UnknownOverlay);
assertBsnManageDataSame('remove_unknown', $unknown_remove[0]['kind'] ?? null, 'A deletion without historical state must remain explicitly unresolved.');
assertBsnManageDataSame(false, isset($unknown_remove[0]['target_account_id']), 'An unresolved deletion must not invent a target.');

$KnownNonTagOverlay = [
    $Source => $Service->snapshotFromHorizonData(['Love1' => base64_encode('not an account')]),
];
assertBsnManageDataSame([], $Service->analyzeAndApply($Source, 'Love1', null, $KnownNonTagOverlay), 'Known non-account data must not be described as a BSN tag.');

$TransactionMeta = new XdrTransactionMeta(0);
$TransactionMeta->setOperations([
    new XdrOperationMeta([
        dataEntryChange($Source, 'Love1', $Alice, XdrLedgerEntryChangeType::LEDGER_ENTRY_STATE),
    ]),
    new XdrOperationMeta([
        dataEntryChange($Source, 'Friend1', $Bob, XdrLedgerEntryChangeType::LEDGER_ENTRY_CREATED),
    ]),
]);
$prior_states = $Service->priorStatesFromTransactionMeta($TransactionMeta);
assertBsnManageDataSame(
    ['exists' => true, 'value' => $Alice],
    $prior_states[0][$Source]['Love1'] ?? null,
    'Published transaction metadata must expose the immutable prior value.',
);
assertBsnManageDataSame(
    ['exists' => false, 'value' => null],
    $prior_states[1][$Source]['Friend1'] ?? null,
    'A created ledger entry proves that the prior value was absent.',
);

$PublishedOverlay = [];
$published_prior = $prior_states[0][$Source]['Love1'];
$Service->seedPriorState(
    $PublishedOverlay,
    $Source,
    'Love1',
    $published_prior['exists'],
    $published_prior['value'],
);
$published_remove = $Service->analyzeAndApply($Source, 'Love1', null, $PublishedOverlay);
assertBsnManageDataSame(
    $Alice,
    $published_remove[0]['target_account_id'] ?? null,
    'A published deletion must resolve its target from result metadata.',
);

fwrite(STDOUT, "BSN ManageData semantic service regression test passed.\n");
