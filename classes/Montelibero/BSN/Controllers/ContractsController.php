<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Contract;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class ContractsController
{
    private BSN $BSN;
    private Environment $Twig;

    public function __construct(BSN $BSN, Environment $Twig)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

    }

    public function Contracts(): ?string
    {
        $Contracts = $this->BSN->getSignatures();
        $contracts = [];
        foreach ($Contracts->getContractsByUsing() as $hash => $using_count) {
            if ($using_count < 2) {
                continue;
            }
            $Hash = $Contracts->makeContract($hash);
            $contracts[] = [
                'hash' => $Hash->hash,
                'name' => $Hash->getName(),
                'display_name' => $Hash->getDisplayName(),
                'using_count' => $using_count,
            ];
        }
        $Template = $this->Twig->load('contracts.twig');
        return $Template->render([
            'contracts' => $contracts,
        ]);
    }

    public function Contract(string $hash): ?string
    {
        $Hash = null;
        $Contracts = $this->BSN->getSignatures();

        if (Contract::validate($hash)) {
            $Hash = $Contracts->makeContract($hash);
        }

        if (!$Hash) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $signatures = [];
        foreach ($Contracts->getAccountsByContract($Hash) as $Signature) {
            $signatures[] = [
                'account' => $Signature->getAccount()->jsonSerialize(),
                'name' => $Signature->getName(),
            ];
        }

        $Template = $this->Twig->load('contract.twig');
        $data = [];
        $data['contract'] = $Hash->jsonSerialize();
        if ($NewHash = $Hash->getNewContract()) {
            $data['new_hash'] = $NewHash->jsonSerialize();
        }
        $data['signatures'] = $signatures;
        return $Template->render($data);
    }

    public function ContractText(string $hash): ?string
    {
        $Hash = null;

        if (Contract::validate($hash)) {
            $Hash = $this->BSN->getSignatures()->makeContract($hash);
        }

        if (!$Hash || !$Hash->getText()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Template = $this->Twig->load('contract_text.twig');
        $data = [];
        $data['contract'] = $Hash->jsonSerialize();
        $calculated_hash = hash("sha256", $Hash->getText());
        if ($calculated_hash !== $Hash->hash) {
            $data['invalid_hash'] = true;
            $data['calculated_hash'] = $calculated_hash;
        }
        return $Template->render($data);
    }
}