<?php

namespace Montelibero\BSN\Controllers;

use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class ErrorController
{
    private Environment $Twig;

    public function __construct(Environment $Twig)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function Error404(): ?string
    {
        SimpleRouter::response()->httpCode(404);
        $Template = $this->Twig->load('404.twig');
        return $Template->render();
    }
}
