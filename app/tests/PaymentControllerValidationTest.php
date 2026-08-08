<?php

declare(strict_types=1);

use Montelibero\BSN\Controllers\PaymentController;
use Montelibero\BSN\PaymentDestination;
use Montelibero\BSN\PaymentTransactionBuilder;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertPaymentValidationTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function paymentValidationAccount(string $account_id, array $balances = []): AccountResponse
{
    return AccountResponse::fromJson([
        'account_id' => $account_id,
        'sequence' => '1',
        'balances' => $balances,
    ]);
}

$source = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$Asset = Asset::createNonNativeAsset('TEST', $issuer);
$asset_key = 'TEST-' . $issuer;
$SourceAccount = paymentValidationAccount($source, [[
    'asset_type' => Asset::TYPE_CREDIT_ALPHANUM_4,
    'asset_code' => 'TEST',
    'asset_issuer' => $issuer,
    'balance' => '5.0000000',
    'limit' => '10.0000000',
    'buying_liabilities' => '1.0000000',
    'selling_liabilities' => '2.0000000',
    'is_authorized' => true,
]]);
$IssuerAccount = paymentValidationAccount($issuer);
$SelfDestination = PaymentDestination::fromAddress($source);
$IssuerDestination = PaymentDestination::fromAddress($issuer);
$token = [
    'asset' => $Asset,
    'code' => 'TEST',
    'available' => '3.0000000',
    'available_unlimited' => false,
];
$tokens = [$asset_key => $token];

$Reflection = new ReflectionClass(PaymentController::class);
/** @var PaymentController $Controller */
$Controller = $Reflection->newInstanceWithoutConstructor();
$Reflection->getProperty('Translator')->setValue($Controller, new Translator('en'));

$validate_source = Closure::bind(
    function (string $source_id, array $source_tokens, array $payments, array &$row_errors, array &$errors): void {
        $this->validateSourceBalances($source_id, $source_tokens, $payments, $row_errors, $errors);
    },
    $Controller,
    PaymentController::class,
);
$validate_destination = Closure::bind(
    function (AccountResponse $Account, array $payments, array &$row_errors): void {
        $this->validateDestinationBalances($Account, $payments, $row_errors);
    },
    $Controller,
    PaymentController::class,
);
assertPaymentValidationTrue($validate_source instanceof Closure, 'Source validator must be callable in the regression test.');
assertPaymentValidationTrue($validate_destination instanceof Closure, 'Destination validator must be callable in the regression test.');

$self_payment = [
    'index' => 0,
    'destination' => $SelfDestination,
    'destination_account' => $SourceAccount,
    'token' => $token,
    'asset' => $Asset,
    'asset_key' => $asset_key,
    'amount' => '4.0000000',
];
$row_errors = [];
$errors = [];
$validate_source($source, $tokens, [$self_payment], $row_errors, $errors);
assertPaymentValidationTrue($row_errors === [] && $errors === [], 'A credit self-payment must not consume the source balance.');
$validate_destination($SourceAccount, [$self_payment], $row_errors);
assertPaymentValidationTrue($row_errors === [], 'A credit self-payment fitting the trustline headroom must be accepted.');

$second_self_payment = $self_payment;
$second_self_payment['index'] = 1;
$row_errors = [];
$validate_destination($SourceAccount, [$self_payment, $second_self_payment], $row_errors);
assertPaymentValidationTrue($row_errors === [], 'Sequential credit self-payments must not consume receive capacity permanently.');

$too_large_self_payment = $self_payment;
$too_large_self_payment['amount'] = '4.0000001';
$row_errors = [];
$validate_destination($SourceAccount, [$too_large_self_payment], $row_errors);
assertPaymentValidationTrue(isset($row_errors[0]), 'A credit self-payment above trustline headroom must be rejected.');

$payment_to_issuer = $self_payment;
$payment_to_issuer['destination'] = $IssuerDestination;
$payment_to_issuer['destination_account'] = $IssuerAccount;
$payment_to_issuer['amount'] = '2.0000000';
$self_after_outgoing = $self_payment;
$self_after_outgoing['index'] = 1;
$self_after_outgoing['amount'] = '6.0000000';
$row_errors = [];
$errors = [];
$validate_source($source, $tokens, [$payment_to_issuer, $self_after_outgoing], $row_errors, $errors);
$validate_destination($SourceAccount, [$payment_to_issuer, $self_after_outgoing], $row_errors);
assertPaymentValidationTrue(
    $row_errors === [] && $errors === [],
    'An earlier outgoing payment must free trustline headroom for a later credit self-payment.',
);

$self_before_outgoing = $self_after_outgoing;
$self_before_outgoing['index'] = 0;
$payment_to_issuer['index'] = 1;
$row_errors = [];
$validate_destination($SourceAccount, [$self_before_outgoing, $payment_to_issuer], $row_errors);
assertPaymentValidationTrue(
    isset($row_errors[0]),
    'A later outgoing payment must not retroactively free headroom for an earlier credit self-payment.',
);

$native_token = [
    'asset' => Asset::native(),
    'code' => 'XLM',
    'available' => '0.5000000',
    'available_unlimited' => false,
];
$native_self_payment = [
    'index' => 0,
    'destination' => $SelfDestination,
    'destination_account' => $SourceAccount,
    'token' => $native_token,
    'asset' => Asset::native(),
    'asset_key' => 'XLM',
    'amount' => PaymentTransactionBuilder::MAX_AMOUNT,
];
$row_errors = [];
$errors = [];
$validate_source($source, ['XLM' => $native_token], [$native_self_payment], $row_errors, $errors);
assertPaymentValidationTrue(
    $row_errors === [] && $errors === [],
    'A native self-payment must only require the transaction fee, not the nominal payment amount.',
);

fwrite(STDOUT, "Payment controller validation tests passed.\n");
