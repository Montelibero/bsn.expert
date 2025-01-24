<?php

namespace Montelibero\BSN\Controllers;

use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class MultisigController
{
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(Environment $Twig, StellarSDK $Stellar)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
        
        $this->Stellar = $Stellar;
    }

    public function Multisig(): string
    {
        return $this->Twig->render('tools_multisig.twig', [
        ]);
    }
}
