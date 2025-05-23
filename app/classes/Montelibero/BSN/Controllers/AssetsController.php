<?php
namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class AssetsController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function AssetsReload(): ?string
    {
        self::reloadAssets();
        return "OK";
    }

    public static function reloadAssets(): void
    {
        $grist_response = \gristRequest(
            'https://montelibero.getgrist.com/api/docs/gxZer88w3TotbWzkQCzvyw/tables/Assets/records',
            'GET'
        );
        $members = [];
        foreach ($grist_response['records'] as $item) {
            $fields = $item['fields'];
            if (
                empty($fields['code'])
                || empty($fields['issuer'])
            ) {
                continue;
            }
            $members = [
                'issuer' => $fields['issuer'],
                'code' => $fields['code'],
                'category' => $fields['category'],
            ];
        }
        apcu_store('assets', $members, 3600);
    }
}
