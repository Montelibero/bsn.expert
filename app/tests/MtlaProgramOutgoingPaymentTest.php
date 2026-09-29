<?php

declare(strict_types=1);

use Montelibero\BSN\BSN;
use Montelibero\BSN\Link;
use Montelibero\BSN\MTLA\MtlaProgramReportService;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentStrictSendOperationResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$BSN = (new ReflectionClass(BSN::class))->newInstanceWithoutConstructor();
$Service = (new ReflectionClass(MtlaProgramReportService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(MtlaProgramReportService::class, 'BSN'))->setValue($Service, $BSN);
$Normalize = new ReflectionMethod(MtlaProgramReportService::class, 'normalizeProgramOutgoingPayment');

$program = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$owner = KeyPair::random()->getAccountId();
$other = KeyPair::random()->getAccountId();
$Owner = $BSN->makeAccountById($owner);
$Owner->setProfile(['TimeTokenCode' => ['HOUR']]);
$Owner->addLink(new Link($BSN->makeTagByName('TimeTokenIssuer'), $Owner, $BSN->makeAccountById($issuer)));
$BSN->makeAccountById($issuer)->setProfile(['TimeTokenCode' => ['HOUR']]);
$BSN->makeAccountById($other)->setProfile(['TimeTokenCode' => ['HOUR']]);

$payment = [
    'from' => $program,
    'to' => $owner,
    'asset_type' => 'credit_alphanum4',
    'asset_code' => 'HOUR',
    'asset_issuer' => $issuer,
    'amount' => '3.5000000',
];
$expected = ['asset_key' => 'HOUR-' . $issuer, 'amount' => 3.5];
$cases = [
    'Separate TT owner' => [[], $expected],
    'Issuer is also the owner: count once' => [['to' => $issuer], $expected],
    'Unknown recipient' => [['to' => KeyPair::random()->getAccountId()], null],
    'Same code with another issuer' => [['to' => $other], null],
    'Another token sent to the owner' => [['asset_code' => 'ELSE'], null],
    'Incoming payment' => [['from' => $owner, 'to' => $program], null],
    'Another sender' => [['from' => $other], null],
    'Native asset' => [['asset_type' => 'native'], null],
];
foreach ($cases as $label => [$overrides, $result]) {
    $Operation = PaymentOperationResponse::fromJson(array_replace($payment, $overrides));
    if ($Normalize->invoke($Service, $program, $Operation) !== $result) {
        throw new RuntimeException($label);
    }
}

// Profile-based issuer declarations use the same resolver as the report rows.
foreach (['TimeTokenIssuer', 'TimeTockenIssuer'] as $field) {
    $Owner->clearLinks();
    $Owner->setProfile(['TimeTokenCode' => ['HOUR'], $field => [$issuer]]);
    if ($Normalize->invoke($Service, $program, PaymentOperationResponse::fromJson($payment)) !== $expected) {
        throw new RuntimeException('Owner with profile issuer: ' . $field);
    }
}

// Preserve source-asset accounting for path payments.
$path_payment = array_replace($payment, [
    'source_asset_type' => 'credit_alphanum4',
    'source_asset_code' => 'HOUR',
    'source_asset_issuer' => $issuer,
    'source_amount' => '3.5000000',
    'amount' => '2.0000000',
]);
foreach ([$owner => $expected, $issuer => $expected, $other => null] as $recipient => $result) {
    $Operation = PathPaymentStrictSendOperationResponse::fromJson(array_replace($path_payment, ['to' => $recipient]));
    if ($Normalize->invoke($Service, $program, $Operation) !== $result) {
        throw new RuntimeException('Path payment recipient: ' . $recipient);
    }
}

fwrite(STDOUT, "MTLA program outgoing payment tests passed.\n");
