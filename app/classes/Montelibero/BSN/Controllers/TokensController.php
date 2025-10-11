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

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;

        $this->Container = $Container;
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

        $base_assets = [
            "EURMTL-GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V",
            "USDM-GDHDC4GBNPMENZAOBB4NCQ25TGZPDRK6ZGWUGSI22TVFATOLRPSUUSDM",
            "MTL-GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V",
            "MTLRECT-GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V",
            "BTCMTL-GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V",
            "SATSMTL-GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V",
            "GPA-GBGGX7QD3JCPFKOJTLBRAFU3SIME3WSNDXETWI63EDCORLBB6HIP2CRR",
            "Agora-GBGGX7QD3JCPFKOJTLBRAFU3SIME3WSNDXETWI63EDCORLBB6HIP2CRR",
            "TIC-GBJ3HT6EDPWOUS3CUSIJW5A4M7ASIKNW4WFTLG76AAT5IE6VGVN47TIC",
            "TOC-GBJ3HT6EDPWOUS3CUSIJW5A4M7ASIKNW4WFTLG76AAT5IE6VGVN47TIC",
            "TPS-GAODFS2M4NSBFGKVNG6SEECI3DWU2GXQKG6MUBYJEIIINVIPZULCJTPS",
            "EURTPS-GDEF73CXYOZXQ6XLUN55UBCW5YTIU4KVZEPOI6WJSREN3DMOBLVLZTOP",
            "USDC-GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN",
        ];

        $known_tokens = [];
        foreach ($base_assets as $asset) {
            [$asset_code, $asset_issuer] = explode('-', $asset);
            $known_tokens[$asset_code] = $asset_issuer;
        }

        if (!$issuer) {
            if (!array_key_exists($code, $known_tokens)) {
                SimpleRouter::response()->httpCode(403);
                return null;
            }
            $issuer = $known_tokens[$code];
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

        $signing_form = $this->Container->get(CommonController::class)->SignTransaction(null, $uri_signed);


        $Template = $this->Twig->load('token.twig');
        return $Template->render([
            'code' => $code,
            'issuer' => $Issuer->jsonSerialize(),
            'holders_count' => $holders_count,
            'issued' => $issued,
            'add_trustline_form' => $signing_form,
        ]);
    }
}