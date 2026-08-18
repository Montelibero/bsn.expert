<?php

declare(strict_types=1);

use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\Account;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\TokenLabelFormatter;
use Soneso\StellarSDK\Crypto\KeyPair;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTokenLabelSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, $expected, $actual));
    }
}

$known_issuer = KeyPair::random()->getAccountId();
$unknown_issuer = KeyPair::random()->getAccountId();
$contact_issuer = KeyPair::random()->getAccountId();

$TokensController = new class(['EURMTL-' . $known_issuer]) extends TokensController {
    public function __construct(private readonly array $known_keys)
    {
    }

    public function getKnownToken(string $key): ?array
    {
        return in_array($key, $this->known_keys, true) ? ['key' => $key] : null;
    }
};
$CurrentContacts = new class([$contact_issuer => 'Вася Пупкин']) extends CurrentContacts {
    public function __construct(private readonly array $contact_names)
    {
    }

    public function getContactName(mixed $account): ?string
    {
        return is_string($account) ? ($this->contact_names[$account] ?? null) : null;
    }
};
$Formatter = new TokenLabelFormatter($TokensController, $CurrentContacts);

assertTokenLabelSame('XLM', $Formatter->format('XLM'), 'A native token must retain its code.');
assertTokenLabelSame(
    'EURMTL',
    $Formatter->format('EURMTL', $known_issuer),
    'An exact known token must retain its code.',
);
assertTokenLabelSame(
    'EURMTL-' . Account::fromId($unknown_issuer)->getShortId(),
    $Formatter->format('EURMTL', $unknown_issuer),
    'An unknown token colliding with a known code must include its shortened issuer.',
);
assertTokenLabelSame(
    'ABC-' . Account::fromId($contact_issuer)->getShortId() . ' [📒 Вася Пупкин]',
    $Formatter->formatToken([
        'code' => 'ABC',
        'issuer' => $contact_issuer,
        'is_known' => true,
    ]),
    'An unknown token must include both its issuer and personal contact label.',
);

fwrite(STDOUT, "Token label formatter tests passed.\n");
