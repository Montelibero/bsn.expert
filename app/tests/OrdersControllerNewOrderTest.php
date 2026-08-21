<?php

declare(strict_types=1);

use Montelibero\BSN\Controllers\OrdersController;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\ManageBuyOfferOperation;
use Soneso\StellarSDK\ManageSellOfferOperation;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertOrdersNewSame(mixed $expected, mixed $actual, string $message): void
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

function assertOrdersNewTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$Reflection = new ReflectionClass(OrdersController::class);
/** @var OrdersController $Controller */
$Controller = $Reflection->newInstanceWithoutConstructor();
$Reflection->getProperty('Translator')->setValue($Controller, new Translator('en'));

$resolve_direction = Closure::bind(
    fn (array $params): ?string => $this->resolveNewOrderDirection($params),
    $Controller,
    OrdersController::class,
);
$prepare_order = Closure::bind(
    function (AccountResponse $Account, array $tokens, array $values, string $direction, array &$errors): ?array {
        return $this->prepareNewOrder($Account, $tokens, $values, $direction, $errors);
    },
    $Controller,
    OrdersController::class,
);
$build_operation = Closure::bind(
    fn (array $prepared) => $this->buildNewOrderOperation($prepared),
    $Controller,
    OrdersController::class,
);

assertOrdersNewTrue($resolve_direction instanceof Closure, 'Direction resolver must be callable.');
assertOrdersNewTrue($prepare_order instanceof Closure, 'Order preparer must be callable.');
assertOrdersNewTrue($build_operation instanceof Closure, 'Operation builder must be callable.');

assertOrdersNewSame(null, $resolve_direction([]), 'An empty request must show the direction chooser.');
assertOrdersNewSame(
    null,
    $resolve_direction(['current_account' => KeyPair::random()->getAccountId()]),
    'Current account alone must not select a direction.',
);
assertOrdersNewSame('sell', $resolve_direction(['sell' => 'MTLPAC']), 'Legacy initialization must default to selling.');
assertOrdersNewSame('buy', $resolve_direction(['direction' => 'buy']), 'Explicit buy direction must be retained.');
assertOrdersNewSame(null, $resolve_direction(['direction' => 'invalid', 'sell' => 'MTLPAC']), 'Invalid explicit direction must be rejected.');

$source = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$PaymentAsset = Asset::createNonNativeAsset('MTLPAC', $issuer);
$BuyingAsset = Asset::createNonNativeAsset('SOZ', $source);
$Account = AccountResponse::fromJson([
    'account_id' => $source,
    'sequence' => '1',
]);
$tokens = [
    'MTLPAC-' . $issuer => [
        'asset' => $PaymentAsset,
        'code' => 'MTLPAC',
        'available' => '600.0000000',
        'available_label' => '600',
    ],
    'SOZ-' . $source => [
        'asset' => $BuyingAsset,
        'code' => 'SOZ',
        'available' => null,
        'available_label' => '∞',
    ],
];
$values = [
    'selling' => 'MTLPAC-' . $issuer,
    'buying' => 'SOZ-' . $source,
    'amount' => '20',
    'price' => '30',
];

$errors = [];
$buy = $prepare_order($Account, $tokens, $values, 'buy', $errors);
assertOrdersNewSame([], $errors, 'An affordable buy order must pass validation.');
assertOrdersNewTrue(is_array($buy), 'An affordable buy order must be prepared.');
assertOrdersNewSame('20.0000000', $buy['amount'], 'Buy amount must be the exact amount of SOZ being bought.');
assertOrdersNewSame('30.0000000', $buy['price'], 'Buy price must be MTLPAC per SOZ without inversion.');
assertOrdersNewSame('600', $buy['preview']['selling']['amount'], 'Buy preview must show the maximum MTLPAC payment.');
assertOrdersNewSame('20', $buy['preview']['buying']['amount'], 'Buy preview must show the exact SOZ amount.');

$BuyOperation = $build_operation($buy);
assertOrdersNewTrue($BuyOperation instanceof ManageBuyOfferOperation, 'Buy direction must build ManageBuyOffer.');
assertOrdersNewSame('20.0000000', $BuyOperation->getAmount(), 'ManageBuyOffer must carry the buying amount.');
assertOrdersNewSame(30, $BuyOperation->getPrice()->getN(), 'ManageBuyOffer price numerator must remain exact.');
assertOrdersNewSame(1, $BuyOperation->getPrice()->getD(), 'ManageBuyOffer price denominator must remain exact.');

$too_expensive = $values;
$too_expensive['amount'] = '20.0000001';
$errors = [];
assertOrdersNewSame(
    null,
    $prepare_order($Account, $tokens, $too_expensive, 'buy', $errors),
    'A buy order exceeding the payment balance must be rejected.',
);
assertOrdersNewTrue($errors !== [], 'An unaffordable buy order must report a validation error.');

$errors = [];
$sell = $prepare_order($Account, $tokens, $values, 'sell', $errors);
assertOrdersNewSame([], $errors, 'A sell order within the balance must pass validation.');
$SellOperation = $build_operation($sell);
assertOrdersNewTrue($SellOperation instanceof ManageSellOfferOperation, 'Sell direction must build ManageSellOffer.');
assertOrdersNewSame('20.0000000', $SellOperation->getAmount(), 'ManageSellOffer must carry the selling amount.');

fwrite(STDOUT, "New order controller tests passed.\n");
