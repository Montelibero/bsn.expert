<?php

namespace Montelibero\BSN\Controllers;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use GuzzleHttp\Client;
use Soneso\StellarSDK\SEP\URIScheme\URIScheme;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class CommonController
{
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(Environment $Twig, StellarSDK $Stellar)
    {
        $this->Twig = $Twig;
        $this->Stellar = $Stellar;
    }

    public function SignTransaction(?string $xdr = null, ?string $uri = null, ?string $description = null, ): ?string
    {
        /*
         * Есть либо транзакция, которую нужно подписать и отправить в блокчейн
         *  Тогда мы её отображаем, но также даём опции подписать по SEP-07 или через MMWB.
         * Или есть сразу SEP-07 ссылка, содержащая в себе callback.
         *  Тогда не отображаем TX, но показываем кнопы подписать и QR
         */

        if (!$xdr && !$uri) {
            throw new \Exception('No transaction or uri provided');
        }
        if (!$uri) {
            $UriScheme = new URIScheme();
            $uri = $UriScheme->generateSignTransactionURI($xdr);
        }

        $QROptions = new QROptions();
        $QROptions->outputBase64 = false;
        $QROptions->addQuietzone = false;
        $qr_svg = (new QRCode($QROptions))->render($uri);
        $qr_svg = str_replace('<svg ', '<svg width="600" height="600" ', $qr_svg);
        $qr_svg = str_replace('fill="#fff"', 'fill="none"', $qr_svg);
        $qr_svg = str_replace('fill="#000"', 'fill="currentColor"', $qr_svg);
//        $qr_data = 'data:image/svg+xml;base64,' . base64_encode($qr_svg);

        // MMWB integration
        try {
            $HttpClient = new Client();
            $response = $HttpClient->post('https://eurmtl.me/remote/sep07/add', [
                'json' => ['uri' => $uri],
                'http_errors' => false
            ]);
            $response_body = (string) $response->getBody();
            $parsed_response = json_decode($response_body, true);
            $mmwb_url = $parsed_response['url'] ?? null;
        } catch (\Exception $e) {
            $mmwb_url = null;
        }

        $sign = md5($xdr . $uri . $description . $_ENV['SERVER_STELLAR_SECRET_KEY']);

        $Template = $this->Twig->load('signing.twig');
        return $Template->render([
            'xdr' => $xdr,
            'uri' => $uri,
            'description' => $description,
            'mmwb_url' => $mmwb_url,
            'qr_svg' => $qr_svg,
            'sign' => $sign,
        ]);
    }

    public static function pushTransactionToEurmtl(string $xdr, string $description): string
    {
        $curl = curl_init('https://eurmtl.me/remote/add_transaction');

        $payload = json_encode([
            'tx_body' => $xdr,
            'tx_description' => $description
        ]);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $_ENV['EURMTL_KEY']
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 201) {
            throw new \RuntimeException('Failed to push transaction to eurmtl.me. HTTP code: ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (!isset($data['hash'])) {
            throw new \RuntimeException('Invalid response from eurmtl.me: hash not found');
        }

        return "https://eurmtl.me/sign_tools/" . $data['hash'];
    }

}