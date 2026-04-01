<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\StellarLabUrlGenerator;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class XdrToLabController
{
    public function __construct(
        private readonly Environment $Twig,
        private readonly Translator $Translator,
        private readonly StellarLabUrlGenerator $Generator,
    ) {
    }

    public function XdrToLab(): string
    {
        $xdr = (string) ($_POST['xdr'] ?? '');
        $labUrl = null;
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (trim($xdr) === '') {
                $error = $this->Translator->trans('tools_xdr2lab.errors.empty_xdr');
            } else {
                try {
                    $labUrl = $this->Generator->generateMainnetClassicBuildUrl($xdr);
                } catch (\Throwable $Throwable) {
                    $error = $Throwable->getMessage();
                }
            }
        }

        return $this->Twig->render('tools_xdr2lab.twig', [
            'xdr' => $xdr,
            'lab_url' => $labUrl,
            'error' => $error,
        ]);
    }
}
