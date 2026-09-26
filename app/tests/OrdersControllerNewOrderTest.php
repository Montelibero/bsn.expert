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

$apply_get = Closure::bind(
    fn (array $values, array $tokens, array $params): array => $this->applyNewOrderGetParams(
        $values,
        $tokens,
        $params,
    ),
    $Controller,
    OrdersController::class,
);
$complete_values = Closure::bind(
    fn (array $values): array => $this->completeNewOrderValues($values),
    $Controller,
    OrdersController::class,
);
$prepare_order = Closure::bind(
    function (AccountResponse $Account, array $tokens, array $values, array &$errors): ?array {
        return $this->prepareNewOrder($Account, $tokens, $values, $errors);
    },
    $Controller,
    OrdersController::class,
);
$build_operation = Closure::bind(
    fn (array $prepared) => $this->buildNewOrderOperation($prepared),
    $Controller,
    OrdersController::class,
);
$swap_values = Closure::bind(
    fn (array $values): array => $this->swapNewOrderValues($values),
    $Controller,
    OrdersController::class,
);

assertOrdersNewTrue($apply_get instanceof Closure, 'GET initializer must be callable.');
assertOrdersNewTrue($complete_values instanceof Closure, 'Value resolver must be callable.');
assertOrdersNewTrue($prepare_order instanceof Closure, 'Order preparer must be callable.');
assertOrdersNewTrue($build_operation instanceof Closure, 'Operation builder must be callable.');

$source = KeyPair::random()->getAccountId();
$issuer = KeyPair::random()->getAccountId();
$PaymentAsset = Asset::createNonNativeAsset('MTLPAC', $issuer);
$BuyingAsset = Asset::createNonNativeAsset('SOZ', $source);
$Account = AccountResponse::fromJson([
    'account_id' => $source,
    'sequence' => '1',
]);
$selling_key = 'MTLPAC-' . $issuer;
$buying_key = 'SOZ-' . $source;
$tokens = [
    $selling_key => [
        'asset' => $PaymentAsset,
        'code' => 'MTLPAC',
        'available' => '600.0000000',
        'available_label' => '600',
        'selling_disabled' => false,
    ],
    $buying_key => [
        'asset' => $BuyingAsset,
        'code' => 'SOZ',
        'available' => null,
        'available_label' => '∞',
        'selling_disabled' => false,
    ],
];
$blank_values = [
    'selling' => $selling_key,
    'buying' => $buying_key,
    'selling_amount' => '',
    'buying_amount' => '',
    'selling_rate' => '',
    'buying_rate' => '',
    'amount_source' => '',
    'rate_source' => '',
    'source_order' => '',
];

$legacy_values = $apply_get($blank_values, $tokens, [
    'sell' => 'MTLPAC',
    'buy' => 'SOZ',
    'amount' => '10',
    'price' => '20',
]);
$legacy_values = $complete_values($legacy_values);
assertOrdersNewSame('selling', $legacy_values['amount_source'], 'Legacy amount must describe the offered amount.');
assertOrdersNewSame('10', $legacy_values['selling_amount'], 'Legacy amount must initialize the selling amount.');
assertOrdersNewSame('200', $legacy_values['buying_amount'], 'Legacy price must calculate the buying amount.');
assertOrdersNewSame('1', $legacy_values['selling_rate'], 'Legacy price must use one selling token as the rate base.');
assertOrdersNewSame('20', $legacy_values['buying_rate'], 'Legacy price must initialize the buying side of the rate.');

$buy_get_values = $apply_get($blank_values, $tokens, [
    'buy_amount' => '20',
    'sell_rate' => '30',
    'buy_rate' => '1',
]);
$buy_get_values = $complete_values($buy_get_values);
assertOrdersNewSame('buying', $buy_get_values['amount_source'], 'A requested buying amount must remain the source.');
assertOrdersNewSame('600', $buy_get_values['selling_amount'], 'Selling amount must be calculated from the requested buying amount.');
assertOrdersNewSame('20', $buy_get_values['buying_amount'], 'Requested buying amount must be retained.');

$sell_values = $blank_values + [];
$sell_values['selling_amount'] = '600';
$sell_values['selling_rate'] = '30';
$sell_values['buying_rate'] = '1';
$sell_values['amount_source'] = 'selling';
$sell_values['rate_source'] = 'both';
$errors = [];
$sell = $prepare_order($Account, $tokens, $sell_values, $errors);
assertOrdersNewSame([], $errors, 'An order based on the offered amount must pass validation.');
assertOrdersNewTrue(is_array($sell), 'An order based on the offered amount must be prepared.');
assertOrdersNewSame('600', $sell['form_values']['selling_amount'], 'Offered amount must be retained.');
assertOrdersNewSame('20', $sell['form_values']['buying_amount'], 'Received amount must be calculated.');
assertOrdersNewSame('600', $sell['preview']['selling']['amount'], 'Preview must show the offered amount.');
assertOrdersNewSame('20', $sell['preview']['buying']['amount'], 'Preview must show the calculated received amount.');

$SellOperation = $build_operation($sell);
assertOrdersNewTrue($SellOperation instanceof ManageSellOfferOperation, 'An offered amount must build ManageSellOffer.');
assertOrdersNewSame('600.0000000', $SellOperation->getAmount(), 'ManageSellOffer must carry the selling amount.');
assertOrdersNewSame(1, $SellOperation->getPrice()->getN(), 'ManageSellOffer price numerator must be exact.');
assertOrdersNewSame(30, $SellOperation->getPrice()->getD(), 'ManageSellOffer price denominator must be exact.');

$buy_values = $blank_values + [];
$buy_values['buying_amount'] = '20';
$buy_values['selling_rate'] = '30';
$buy_values['buying_rate'] = '1';
$buy_values['amount_source'] = 'buying';
$buy_values['rate_source'] = 'both';
$errors = [];
$buy = $prepare_order($Account, $tokens, $buy_values, $errors);
assertOrdersNewSame([], $errors, 'An order based on the requested amount must pass validation.');
assertOrdersNewTrue(is_array($buy), 'An order based on the requested amount must be prepared.');
assertOrdersNewSame('600', $buy['form_values']['selling_amount'], 'Payment amount must be calculated.');
assertOrdersNewSame('20', $buy['form_values']['buying_amount'], 'Requested amount must be retained.');

$BuyOperation = $build_operation($buy);
assertOrdersNewTrue($BuyOperation instanceof ManageBuyOfferOperation, 'A requested amount must build ManageBuyOffer.');
assertOrdersNewSame('20.0000000', $BuyOperation->getAmount(), 'ManageBuyOffer must carry the buying amount.');
assertOrdersNewSame(30, $BuyOperation->getPrice()->getN(), 'ManageBuyOffer price numerator must be exact.');
assertOrdersNewSame(1, $BuyOperation->getPrice()->getD(), 'ManageBuyOffer price denominator must be exact.');

$amount_pair_values = $blank_values + [];
$amount_pair_values['selling_amount'] = '600';
$amount_pair_values['buying_amount'] = '20';
$amount_pair_values['amount_source'] = 'both';
$errors = [];
$amount_pair = $prepare_order($Account, $tokens, $amount_pair_values, $errors);
assertOrdersNewSame([], $errors, 'Two amounts without a rate must pass validation.');
assertOrdersNewSame('30', $amount_pair['form_values']['selling_rate'], 'The left rate must be derived from both amounts.');
assertOrdersNewSame('1', $amount_pair['form_values']['buying_rate'], 'The right rate must be derived from both amounts.');
assertOrdersNewSame('calculated', $amount_pair['form_values']['rate_source'], 'Derived rate must remain secondary.');
$AmountPairOperation = $build_operation($amount_pair);
assertOrdersNewTrue($AmountPairOperation instanceof ManageSellOfferOperation, 'Two explicit amounts must build ManageSellOffer.');
assertOrdersNewSame(1, $AmountPairOperation->getPrice()->getN(), 'Derived sell price numerator must be exact.');
assertOrdersNewSame(30, $AmountPairOperation->getPrice()->getD(), 'Derived sell price denominator must be exact.');

$three_source_values = $blank_values;
$three_source_values['selling_amount'] = '5';
$three_source_values['buying_amount'] = '123';
$three_source_values['selling_rate'] = '1';
$three_source_values['buying_rate'] = '1000';
$three_source_values['source_order'] = 'buying_rate,selling_amount,selling_rate';
$three_source_values = $complete_values($three_source_values);
assertOrdersNewSame('5000', $three_source_values['buying_amount'], 'Three current fields must calculate the fourth.');
assertOrdersNewSame('selling', $three_source_values['amount_source'], 'Only the current selling amount is explicit.');
assertOrdersNewSame('both', $three_source_values['rate_source'], 'Both rate fields used by the calculation must remain explicit.');

$recent_source_values = $three_source_values;
$recent_source_values['buying_amount'] = '5000';
$recent_source_values['buying_rate'] = '777';
$recent_source_values['source_order'] = 'buying_rate,selling_amount,selling_rate,buying_amount';
$recent_source_values = $complete_values($recent_source_values);
assertOrdersNewSame(
    'selling_amount,selling_rate,buying_amount',
    $recent_source_values['source_order'],
    'The oldest field must stop being a source when a fourth field is edited.',
);
assertOrdersNewSame('1000', $recent_source_values['buying_rate'], 'The oldest field must be recalculated.');
assertOrdersNewSame('both', $recent_source_values['amount_source'], 'Both recently edited amounts must remain explicit.');
assertOrdersNewSame('selling', $recent_source_values['rate_source'], 'Only the recently edited rate must remain explicit.');

$swap_input = $sell['form_values'];
$swap_input['source_order'] = 'selling_amount,selling_rate,buying_rate';
$swapped = $swap_values($swap_input);
assertOrdersNewSame($buying_key, $swapped['selling'], 'Swapping must offer the previously requested token.');
assertOrdersNewSame($selling_key, $swapped['buying'], 'Swapping must request the previously offered token.');
assertOrdersNewSame('20', $swapped['selling_amount'], 'Amounts must exchange sides.');
assertOrdersNewSame('600', $swapped['buying_amount'], 'The original offered amount must become the requested amount.');
assertOrdersNewSame('1', $swapped['selling_rate'], 'The rate numerator and denominator must exchange sides.');
assertOrdersNewSame('30', $swapped['buying_rate'], 'Swapping must retain the exact rate.');
assertOrdersNewSame('buying_amount,buying_rate,selling_rate', $swapped['source_order'], 'Edit priorities must follow their values.');
assertOrdersNewSame($swap_input, $swap_values($swapped), 'Two swaps must restore the entire form.');
$errors = [];
$swapped_order = $prepare_order($Account, $tokens, $swapped, $errors);
assertOrdersNewSame([], $errors, 'A swapped order must remain valid.');
$SwappedOperation = $build_operation($swapped_order);
assertOrdersNewTrue($SwappedOperation instanceof ManageBuyOfferOperation, 'Swapping must preserve the explicit amount on its new side.');
assertOrdersNewSame('600.0000000', $SwappedOperation->getAmount(), 'The swapped operation must use the new requested amount.');
assertOrdersNewSame(1, $SwappedOperation->getPrice()->getN(), 'Swapped buy price must use the new selling side.');
assertOrdersNewSame(30, $SwappedOperation->getPrice()->getD(), 'Swapped buy price must use the new buying side.');
$partial_swap = $blank_values;
$partial_swap['selling_amount'] = '1,';
assertOrdersNewSame('1,', $swap_values($partial_swap)['buying_amount'], 'Swapping an unfinished form must preserve raw input.');

$too_expensive = $buy_values;
$too_expensive['buying_amount'] = '20.0000001';
$errors = [];
assertOrdersNewSame(
    null,
    $prepare_order($Account, $tokens, $too_expensive, $errors),
    'An order whose calculated payment exceeds the balance must be rejected.',
);
assertOrdersNewTrue($errors !== [], 'An unaffordable order must report a validation error.');

$inconsistent = $amount_pair_values;
$inconsistent['buying_amount'] = '21';
$inconsistent['selling_rate'] = '30';
$inconsistent['buying_rate'] = '1';
$inconsistent['rate_source'] = 'both';
$errors = [];
assertOrdersNewSame(
    null,
    $prepare_order($Account, $tokens, $inconsistent, $errors),
    'Amounts that disagree with an explicit rate must be rejected.',
);
assertOrdersNewTrue($errors !== [], 'An inconsistent order must report a validation error.');

fwrite(STDOUT, "New order controller tests passed.\n");
