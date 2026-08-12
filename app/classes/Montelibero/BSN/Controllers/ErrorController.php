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
    }

    public function Error404(): ?string
    {
        SimpleRouter::response()->httpCode(404);
        $Template = $this->Twig->load('404.twig');
        return $Template->render();
    }

    /** @param list<string> $allowed_methods */
    public function Error405(array $allowed_methods): ?string
    {
        $Response = SimpleRouter::response()->httpCode(405);
        if ($allowed_methods !== []) {
            $Response->header('Allow: ' . implode(', ', $allowed_methods));
        }

        $Template = $this->Twig->load('405.twig');
        return $Template->render();
    }
}
