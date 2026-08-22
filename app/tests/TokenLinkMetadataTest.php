<?php

declare(strict_types=1);

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Contract;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\Signature;
use Soneso\StellarSDK\Crypto\KeyPair;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTokenLinkMetadata(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$known_issuer = KeyPair::random()->getAccountId();
$unknown_issuer = KeyPair::random()->getAccountId();
$offer_text = 'Public terms for the KNOWN token.';
$OfferContract = new Contract(hash('sha256', $offer_text));
$OfferContract->setText($offer_text);

$KnownIssuer = Account::fromId($known_issuer);
$KnownIssuer->addSignature(new Signature($KnownIssuer, $OfferContract, 'KNOWNTokenOffer'));
$UnknownIssuer = Account::fromId($unknown_issuer);

$BSN = new class([$known_issuer => $KnownIssuer, $unknown_issuer => $UnknownIssuer]) extends BSN {
    public function __construct(private readonly array $accounts)
    {
    }

    public function makeAccountById(string $id): Account
    {
        return $this->accounts[$id] ?? Account::fromId($id);
    }

    public function getDataLoadedAt(): ?int
    {
        return 1;
    }
};

$TokensController = new class(['KNOWN-' . $known_issuer]) extends TokensController {
    public function __construct(private readonly array $known_keys)
    {
    }

    public function getKnownToken(string $key): ?array
    {
        return in_array($key, $this->known_keys, true) ? ['key' => $key] : null;
    }
};

$BSNProperty = new ReflectionProperty(TokensController::class, 'BSN');
$BSNProperty->setValue($TokensController, $BSN);

$known_token = [
    'code' => 'KNOWN',
    'issuer' => $known_issuer,
    'amount' => 10.0,
    'is_known' => false,
];
$TokensController->applyTokenLinkMetadata($known_token);

assertTokenLinkMetadata($known_token['is_known'] === true, 'Exact known token metadata must be refreshed.');
assertTokenLinkMetadata($known_token['has_offer_text'] === true, 'A displayable token offer must be marked.');

$same_code_unknown_token = [
    'code' => 'KNOWN',
    'issuer' => $unknown_issuer,
    'amount' => 5.0,
    'is_known' => true,
];
$TokensController->applyTokenLinkMetadata($same_code_unknown_token);

assertTokenLinkMetadata(
    $same_code_unknown_token['is_known'] === false,
    'A matching code with a different issuer must remain unknown.',
);
assertTokenLinkMetadata(
    $same_code_unknown_token['has_offer_text'] === false,
    'A token without a displayable offer must not be marked.',
);

$Twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/twig'));
$known_html = $Twig->render('token_link.twig', ['token' => $known_token]);
$unknown_html = $Twig->render('token_link.twig', ['token' => $same_code_unknown_token]);

assertTokenLinkMetadata(
    str_contains($known_html, 'href="/tokens/KNOWN"'),
    'A known token must use the short link without its issuer.',
);
assertTokenLinkMetadata(str_contains($known_html, 'is-info'), 'A known token must be highlighted.');
assertTokenLinkMetadata(
    str_contains($known_html, 'token_has_offer_text'),
    'A token with a displayable offer must use the underline class.',
);
assertTokenLinkMetadata(
    str_contains($unknown_html, 'href="/tokens/KNOWN-' . $unknown_issuer . '"'),
    'An unknown token link must include its issuer.',
);
assertTokenLinkMetadata(str_contains($unknown_html, 'is-dark'), 'An unknown token must not use known highlighting.');

fwrite(STDOUT, "Token link metadata tests passed.\n");
