<?php

namespace Montelibero\BSN\Controllers;

use Twig\Environment;

class GraphController
{
    private Environment $Twig;

    public function __construct(Environment $Twig)
    {
        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function Graph(): string
    {
        $Template = $this->Twig->load('graph.twig');
        return $Template->render([]);
    }
}
