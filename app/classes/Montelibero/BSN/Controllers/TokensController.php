<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Twig\Environment;

class TokensController
{
    private BSN $BSN;
    private Environment $Twig;

    private ?string $default_viewer = null;
    private StellarSDK $Stellar;

    private Container $Container;

    private array $known_tokens = [];
    private array $known_tokens_by_code = [];

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        $this->Container = $Container;

        $this->loadKnownTokens();
    }

    public function Tokens(): ?string
    {
        $Template = $this->Twig->load('tokens.twig');
        return $Template->render([
        ]);
    }

    public function TokenXLM(): ?string
    {
        $Template = $this->Twig->load('token_xlm.twig');
        return $Template->render();
    }

    public function Token(string $code): ?string
    {
        if (str_contains($code, "-")) {
            [$code, $issuer] = explode('-', $code);
        } else {
            $issuer = null;
        }
        if (!$this->BSN::validateTokenNameFormat($code)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if (!$issuer) {
            $known_tag = $this->getKnownTokenByCode($code);
            if (!$known_tag) {
                SimpleRouter::response()->httpCode(403);
                return null;
            }
            $issuer = $known_tag['issuer'];
        } else {
            $known_tag = $this->getKnownTokenByCode($code);
            if ($known_tag && $known_tag['issuer'] === $issuer) {
                http_response_code(301);
                header("Location: /tokens/" . $code);
                return null;
            }
        }

        $Issuer = $this->BSN->makeAccountById($issuer);

        if (!$this->BSN::validateStellarAccountIdFormat($issuer)) {
            SimpleRouter::response()->httpCode(401);
            return null;
        }

        $AssetRequest = $this->Stellar->assets()->forAssetCode($code)->forAssetIssuer($issuer)->execute();
        $Asset = $AssetRequest->getAssets()->toArray()[0];

        $holders_count = $Asset->getAccounts()->getAuthorized();
        $issued = $Asset->getBalances()->getAuthorized();

        // SEP-07 URL to open trustline
        $ServerKeypair = Keypair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']);
        $StellarAccount = new \Soneso\StellarSDK\Account($ServerKeypair->getAccountId(), new BigInteger(0));
        $Transaction = new TransactionBuilder($StellarAccount);
        $Operation = new ChangeTrustOperationBuilder(Asset::createNonNativeAsset($code, $issuer));
        $Transaction->addOperation($Operation->build());
        $Transaction = $Transaction->build();
        $Transaction->sign($ServerKeypair, Network::public());
        $tx = $Transaction->toEnvelopeXdrBase64();
        $UriScheme = new URIScheme();
        $uri = $UriScheme->generateSignTransactionURI(
            $tx,
            replace: "sourceAccount:X;X:account to authenticate",
            message: "Open trustline",
            originDomain: "bsn.expert"
        );
        $uri_signed = LoginController::getSignedUrl($uri, $ServerKeypair);

        $signing_form = $this->Container->get(SignController::class)->SignTransaction(null, $uri_signed);


        $Template = $this->Twig->load('token.twig');
        return $Template->render([
            'code' => $code,
            'issuer' => $Issuer->jsonSerialize(),
            'holders_count' => $holders_count,
            'issued' => $issued,
            'add_trustline_form' => $signing_form,
        ]);
    }

    public static function reloadKnownTokens(): void
    {
        $grist_response = gristRequest(
            'https://montelibero.getgrist.com/api/docs/gxZer88w3TotbWzkQCzvyw/tables/Assets/records',
            'GET'
        );
        $known_tokens = [];
        foreach ($grist_response['records'] as $item) {
            $fields = $item['fields'];
            if (
                empty($fields['code'])
                || empty($fields['issuer'])
            ) {
                continue;
            }
            $known_tokens[] = [
                'code' => $fields['code'],
                'issuer' => $fields['issuer'],
                'offer_link' => $fields['offerta_link'],
                'category' => $fields['category'],
            ];
        }
        apcu_store('known_tokens', $known_tokens, 3600);
    }

    private function loadKnownTokens(): void
    {
        $known_tokens = apcu_fetch('known_tokens');
        if (!$known_tokens) {
            self::reloadKnownTokens();
            $known_tokens = apcu_fetch('known_tokens');
            if (!$known_tokens) {
                return;
            }
        }

        foreach ($known_tokens as $item) {
            $key = $item['code'] . '-' . $item['issuer'];
            $this->known_tokens[$key] = $item;
            $this->known_tokens_by_code[$item['code']] = & $this->known_tokens[$key];
        }
    }

    public function getKnownToken(string $key): ?array
    {
        return $this->known_tokens[$key] ?? null;
    }

    public function getKnownTokenByCode(string $code): ?array
    {
        return $this->known_tokens_by_code[$code] ?? null;
    }

    public function shortKnownTokenKey($key): string
    {
        [$code, $issuer] = explode('-', $key);
        $known_tag = $this->getKnownTokenByCode($code);
        if ($known_tag && $known_tag['issuer'] === $issuer) {
            return $code;
        }

        return $key;
    }
}