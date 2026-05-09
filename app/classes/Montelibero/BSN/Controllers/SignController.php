<?php
namespace Montelibero\BSN\Controllers;

use chillerlan\QRCode\Data\QRCodeDataException;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DI\Container;
use GuzzleHttp\Client;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Transaction;
use Twig\Environment;

class SignController
{
    private const ORIGIN_DOMAIN = 'bsn.expert';

    private BSN $BSN;
    private Environment $Twig;

    private StellarSDK $Stellar;

    private Container $Container;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;

        $this->Stellar = $Stellar;

        $this->Container = $Container;
    }

    public function Sign(): ?string
    {
        $xdr = $_POST['xdr'] ?? '';
        $uri = $_POST['uri'] ?? '';
        $description = $_POST['description'] ?? '';
        if (!$xdr && !$uri) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['mmwb', 'eurmtl', 'qr', 'xdr'], true)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if ($action === 'xdr' && !$xdr) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if ($action === 'eurmtl' && !$this->canCollectMultisig($xdr)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        $right_sign = md5($xdr . $uri . $description . $_ENV['SERVER_STELLAR_SECRET_KEY']);

        if (!isset($_POST['sign']) || $_POST['sign'] !== $right_sign) {
            SimpleRouter::response()->httpCode(403);
            return null;
        }

        if ($action === 'qr' || $action === 'xdr') {
            $Template = $this->Twig->load('signing_page.twig');
            return $Template->render($this->buildSigningTemplateData($xdr, $uri, $description) + [
                'signing_view' => $action,
            ]);
        }

        $url = null;

        switch ($action) {
            case 'mmwb':
                $HttpClient = new Client();
                $response = $HttpClient->post('https://eurmtl.me/remote/sep07/add', [
                    'json' => ['uri' => $uri],
                    'http_errors' => false
                ]);
                $response_body = (string) $response->getBody();
                $parsed_response = json_decode($response_body, true);
                $url = $parsed_response['url'] ?? null;
                break;
            case 'eurmtl':
                if (!$description) {
                    $description = $this->getTransactionMemoText($xdr) ?? '';
                }
                $url = CommonController::pushTransactionToEurmtl($xdr, $description);
                break;
        }

        if (!$url) {
            SimpleRouter::response()->httpCode(502);
            return null;
        }
        http_response_code(302);
        header('Location: ' . $url);

        return null;
    }

    public function SignTransaction(?string $xdr = null, ?string $uri = null, ?string $description = null, ): ?string
    {
        /*
         * Есть либо транзакция, которую нужно подписать и отправить в блокчейн
         *  Тогда мы её отображаем, но также даём опции подписать по SEP-07 или через MMWB.
         * Или есть сразу SEP-07 ссылка, содержащая в себе callback.
         *  Тогда не отображаем TX, но показываем кнопы подписать и QR
         */

        return $this->renderSigningTemplateData($this->buildSigningTemplateData($xdr, $uri, $description));
    }

    public function renderSigningTemplateData(array $data): string
    {
        $Template = $this->Twig->load('signing.twig');
        return $Template->render($data);
    }

    public function buildSigningTemplateData(?string $xdr, ?string $uri, ?string $description): array
    {
        if (!$xdr && !$uri) {
            throw new \Exception('No transaction or uri provided');
        }
        if (!$uri) {
            $UriScheme = new URIScheme();
            $uri = $UriScheme->generateSignTransactionURI($xdr, originDomain: self::ORIGIN_DOMAIN);
        }
        $uri = self::ensureSignedUri($uri);

        $QROptions = new QROptions();
        $QROptions->outputBase64 = false;
        $QROptions->addQuietzone = false;
        $qr_svg = null;
        try {

            $qr_svg = (new QRCode($QROptions))->render($uri);
            $qr_svg = str_replace('<svg ', '<svg width="600" height="600" ', $qr_svg);
            $qr_svg = str_replace('fill="#fff"', 'fill="none"', $qr_svg);
            $qr_svg = str_replace('fill="#000"', 'fill="currentColor"', $qr_svg);
            //        $qr_data = 'data:image/svg+xml;base64,' . base64_encode($qr_svg);
        } catch (QRCodeDataException $E) {
            // Do nothing
        }

        $memo_text = $this->getTransactionMemoText($xdr);
        $can_collect_multisig = $this->canCollectMultisig($xdr);
        if (!$description && $can_collect_multisig) {
            $description = $memo_text;
        }

        $sign = md5($xdr . $uri . $description . $_ENV['SERVER_STELLAR_SECRET_KEY']);

        return [
            'xdr' => $xdr,
            'uri' => $uri,
            'description' => $description,
            'qr_svg' => $qr_svg,
            'sign' => $sign,
            'can_collect_multisig' => $can_collect_multisig,
        ];
    }

    public static function signSep07Uri(string $uri, KeyPair $KeyPair): string
    {
        $payloadStart = array();
        for ($i = 0; $i < 36; $i++) {
            $payloadStart[$i] = pack('C', 0);
        }
        $payloadStart[35] = pack('C', 4);
        $urlBytes = URIScheme::uriSchemePrefix . $uri;
        $payload = implode('', $payloadStart) . $urlBytes;
        $signatureBytes = $KeyPair->sign($payload);
        $base64Signature = base64_encode($signatureBytes);
        return $uri . '&signature=' . urlencode($base64Signature);
    }

    private static function ensureSignedUri(string $uri): string
    {
        if (preg_match('/[?&]signature=/', $uri)) {
            return $uri;
        }

        if (!preg_match('/[?&]origin_domain=/', $uri)) {
            $uri .= (str_contains($uri, '?') ? '&' : '?')
                . URIScheme::originDomainParameterName . '=' . urlencode(self::ORIGIN_DOMAIN);
        }

        return self::signSep07Uri($uri, KeyPair::fromSeed($_ENV['SERVER_STELLAR_SECRET_KEY']));
    }

    private function canCollectMultisig(?string $xdr): bool
    {
        $memo_text = $this->getTransactionMemoText($xdr);
        if ($memo_text === null) {
            return false;
        }

        $memo_length = function_exists('mb_strlen') ? mb_strlen($memo_text) : strlen($memo_text);
        return $memo_length > 3;
    }

    private function getTransactionMemoText(?string $xdr): ?string
    {
        if (!$xdr) {
            return null;
        }

        try {
            $Transaction = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
        } catch (\Throwable) {
            return null;
        }

        if (!$Transaction instanceof Transaction) {
            return null;
        }

        $Memo = $Transaction->getMemo();
        if ($Memo->typeAsString() !== 'text') {
            return null;
        }

        return $Memo->valueAsString();
    }
}
