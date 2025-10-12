<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class CommonController
{
    private Environment $Twig;
    private StellarSDK $Stellar;
    private Container $Container;

    public function __construct(Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->Twig = $Twig;
        $this->Stellar = $Stellar;
        $this->Container = $Container;
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