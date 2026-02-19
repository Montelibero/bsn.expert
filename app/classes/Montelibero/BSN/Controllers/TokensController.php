<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Relations\Person;
use Montelibero\BSN\Relations\Member;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Symfony\Component\Translation\Translator;
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
        $categories = [
            'membership',
            'mtl_shares',
            'mtl_stables',
            'mtl_wrapped',
            'euro_notes',
            'bonds',
            'shares_div',
            'shares_nodiv',
            'time_tokens',
            'donation',
            'nft',
            'mtl_debt',
        ];
        $promoted_assets = [
            "EURMTL",
            "USDM",
            "MTL",
            "MTLRECT",
            "BTCMTL",
            "SATSMTL",
            "MTLCrowd",
        ];
        $tokens = [];
        foreach ($this->known_tokens as $asset) {
            // Ignore not categorized tokens
            if (empty($asset['category'])) {
                continue;
            }
            if (!array_key_exists($asset['category'], $tokens)) {
                $tokens[$asset['category']] = [
                    'tokens' => [],
                ];
            }
            $tokens[$asset['category']]['tokens'][$asset['code']] = $asset;
        }
        WebApp::semantic_sort_keys($tokens, $categories);
        $Translator = $this->Container->get(Translator::class);
        foreach ($tokens as $category_name => & $category) {
            $category['name_code'] = $category_name;
            $category['name'] = $Translator->trans("tokens.categories." . $category_name . '.name');
            $category['description'] = $Translator->trans("tokens.categories." . $category_name . '.description');
            uasort($category['tokens'], function ($a, $b) {
                return strcmp($a['code'], $b['code']);
            });
            WebApp::semantic_sort_keys($category['tokens'], $promoted_assets);
        }
        $Template = $this->Twig->load('tokens.twig');
        return $Template->render([
            'tokens' => $tokens,
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

        $category = null;
        $category_name = null;
        $offer_link = null;

        if (!$issuer) {
            $known_tag = $this->getKnownTokenByCode($code);
            if (!$known_tag) {
                SimpleRouter::response()->httpCode(403);
                return null;
            }
            $issuer = $known_tag['issuer'];
            if ($category = $known_tag['category']) {
                $Translator = $this->Container->get(Translator::class);
                $category_name = $Translator->trans("tokens.categories." . $known_tag['category'] . '.name');
            }
            $offer_link = $known_tag['offer_link'] ?? null;
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

        $issued = null;
        $holders_count = null;
        if ($asset_data = $this->fetchAssetData($code, $issuer)) {
            $issued = $asset_data['issued'];
            $holders_count = $asset_data['holders_count'];
        }

        $holders = [];
        if ($holders_count <= 200
            && (
                ($Issuer->getRelation() instanceof Member)
                || $Issuer->getIncomeTags()
                || $Issuer->getBalance('EURMTL')
                || $known_tag
            )
        ) {
            $holders = $this->fetchTokenHolders($code, $issuer);
        }
        foreach ($holders as & $balance) {
            $balance['account'] = $this->BSN->makeAccountById($balance['id'])->jsonSerialize();
        }

        // SEP-07 URL to open trustline
        $ServerKeypair = Keypair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']);
        $StellarAccount = new \Soneso\StellarSDK\Account($ServerKeypair->getAccountId(), new BigInteger(0));
        $Transaction = new TransactionBuilder($StellarAccount);
        $Operation = new ChangeTrustOperationBuilder(Asset::createNonNativeAsset($code, $issuer));
        $Transaction->addOperation($Operation->build());
        $Transaction = $Transaction->build();
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
            'category' => $category,
            'category_name' => $category_name,
            'offer_link' => $offer_link,
            'add_trustline_form' => $signing_form,
            'holders' => $holders,
        ]);
    }

    public function reloadKnownTokens(): void
    {
        $grist_response = gristRequest(
            'https://montelibero.getgrist.com/api/docs/gxZer88w3TotbWzkQCzvyw/tables/Assets/records',
            'GET'
        );
        $known_tokens = [];
        $known_codes = [];
        foreach ($grist_response['records'] as $item) {
            $fields = $item['fields'];
            if (
                empty($fields['code'])
                || empty($fields['issuer'])
            ) {
                continue;
            }
            $code = trim((string) $fields['code']);
            $issuer = trim((string) $fields['issuer']);
            if (
                !BSN::validateTokenNameFormat($code)
                || !BSN::validateStellarAccountIdFormat($issuer)
            ) {
                continue;
            }
            $known_tokens[] = [
                'code' => $code,
                'issuer' => $issuer,
                'offer_link' => $fields['offerta_link'],
                'category' => $fields['category'],
            ];
            $known_codes[strtolower($code)] = true;
        }

        $TagTimeTokenIssuer = $this->BSN->makeTagByName('TimeTokenIssuer');
        foreach ($this->BSN->getAccounts() as $Account) {
            if (
                !($Account->getRelation() instanceof Person)
                || $Account->getBalance('MTLAP') < 1
            ) {
                continue;
            }

            $code = trim((string) $Account->getProfileSingleItem('TimeTokenCode'));
            if (!BSN::validateTokenNameFormat($code)) {
                continue;
            }

            $issuer = $Account->getId();
            if ($tt_issuers = $Account->getOutcomeLinks($TagTimeTokenIssuer)) {
                $issuer = $tt_issuers[0]->getId();
            } elseif ($tt_issuer_profile = $Account->getProfileSingleItem('TimeTokenIssuer')) {
                $issuer = trim((string) $tt_issuer_profile);
            }

            if (!BSN::validateStellarAccountIdFormat($issuer)) {
                continue;
            }

            $code_key = strtolower($code);
            if (array_key_exists($code_key, $known_codes)) {
                continue;
            }

            $known_tokens[] = [
                'code' => $code,
                'issuer' => $issuer,
                'offer_link' => null,
                'category' => 'time_tokens',
            ];
            $known_codes[$code_key] = true;
        }

        apcu_store('known_tokens', $known_tokens, 3600);
    }

    private function loadKnownTokens(): void
    {
        $known_tokens = apcu_fetch('known_tokens');
        if (!$known_tokens) {
            $this->reloadKnownTokens();
            $known_tokens = apcu_fetch('known_tokens');
            if (!$known_tokens) {
                return;
            }
        }

        foreach ($known_tokens as $item) {
            $key = $item['code'] . '-' . $item['issuer'];
            $this->known_tokens[$key] = $item;
            $this->known_tokens_by_code[$item['code']] = &$this->known_tokens[$key];
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

    public function searchKnownTokenByCode(string $search): ?array
    {
        $search = strtolower($search);
        foreach ($this->known_tokens_by_code as $code => $known_token) {
            if (strtolower($code) === $search) {
                return $known_token;
            }
        }

        return null;
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

    private function fetchTokenHolders(string $code, string $issuer): array
    {
        $apcu_cache_key = 'token_horders:' . $issuer . ':' . $code . ":2";
        $holders = apcu_fetch($apcu_cache_key);
        if ($holders) {
            return $holders;
        }

        $holders = [];
        $Asset = Asset::createNonNativeAsset($code, $issuer);
        try {
            $Accounts = $this->Stellar
                ->accounts()
                ->forAsset($Asset)
                ->limit(200)
                ->execute();
            $accounts = [];
    //        do {
    //            $this->log('Got new ' . $Accounts->getAccounts()->count() . ' accounts');
    //            foreach ($Accounts->getAccounts() as $Account) {
    //                $accounts[] = $Account;
    //            }
    //            $Accounts = $Accounts->getNextPage();
    //            $this->log('Fetch next accounts ' . $code);
    //        } while ($Accounts->getAccounts()->count());
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
        } catch (\Exception $E) {
        }

        foreach ($accounts as $Account) {
            foreach ($Account->getBalances() as $Balance) {
                if ($Balance->getAssetType() === Asset::TYPE_NATIVE) {
                    continue;
                }
                if ($Balance->getAssetIssuer() === $issuer && $Balance->getAssetCode() === $code) {
                    $holders[] = [
                        'id' => $Account->getAccountId(),
                        'amount' => $Balance->getBalance(),
                        'amount_value' => (float) $Balance->getBalance(),
                    ];
                    break;
                }
            }
        }

        usort($holders, function ($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });

        apcu_store($apcu_cache_key, $holders, 60 * 60);

        return $holders;
    }

    private function fetchAssetData(string $code, mixed $issuer)
    {
        $apcu_key = 'token_data:' . $issuer . ':' . $code;
        $data = apcu_fetch($apcu_key);
        if ($data !== false) {
            return $data;
        }

        $AssetRequest = $this->Stellar->assets()->forAssetCode($code)->forAssetIssuer($issuer)->execute();
        $AssetsResponse = $AssetRequest->getAssets();
        if (!$AssetsResponse->count()) {
            apcu_store($apcu_key, null, 60 * 5);
            return null;
        }
        $Asset = $AssetsResponse->toArray()[0];
        $data = [
            'holders_count' => $Asset->getAccounts()->getAuthorized() + $Asset->getAccounts()->getUnauthorized(),
            'issued' => (float) $Asset->getBalances()->getAuthorized() + (float) $Asset->getBalances()->getUnauthorized(
                ),
        ];

        apcu_store($apcu_key, $data, 60 * 60);

        return $data;
    }
}
