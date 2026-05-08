<?php

namespace Montelibero\BSN\Controllers;

use Twig\Environment;

class GraphController
{
    private Environment $Twig;

    public function __construct(Environment $Twig)
    {
        $this->Twig = $Twig;
    }

    public function Graph(): string
    {
        $Template = $this->Twig->load('graph.twig');
        return $Template->render([]);
    }
}
