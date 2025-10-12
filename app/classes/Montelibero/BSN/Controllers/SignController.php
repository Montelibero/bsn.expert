<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use GuzzleHttp\Client;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class SignController
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
        if (!in_array($action, ['mmwb', 'eurmtl'])) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if ($action == 'eurmtl' && !$description) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        $right_sign = md5($xdr . $uri . $description . $_ENV['SERVER_STELLAR_SECRET_KEY']);

        if (!isset($_POST['sign']) || $_POST['sign'] !== $right_sign) {
            SimpleRouter::response()->httpCode(403);
            return null;
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
}