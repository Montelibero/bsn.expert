<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Contract;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;

class ContractsController
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
        $data['default_id'] = isset($_SESSION['account']) ? $_SESSION['account']['id'] : null;
        return $Template->render($data);
    }

    public function ContractSign(string $hash): ?string
    {
        $Hash = null;

        if (Contract::validate($hash)) {
            $Hash = $this->BSN->getSignatures()->makeContract($hash);
        }

        if (!$Hash || !$Hash->getText()) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $account_id = $_GET['id'] ?? null;
        if (!$account_id || !BSN::validateStellarAccountIdFormat($account_id)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }
        
        $data_set = $this->Stellar->requestAccount($account_id)->getData()->getData();
        $entry_names = [];
        foreach ($data_set as $key => $value) {
            $value = base64_decode($value);
            if ($value === $Hash->hash) {
                $entry_names[] = $key;
            }
        }

        $data = [];
        $data['account'] = $this->BSN->makeAccountById($account_id)->jsonSerialize();
        $data['is_signed'] = !!$entry_names;
        $data['entry_names'] = $entry_names;

        /*
         * Если ключа не найдено, предлагаем добавить (с каким именем?)
         * Если ключ есть, предлагаем удалить, или переопределить его имя
         * Старое имя показываем, если оно одно, если больше одного, то тоже об этом говорим.
         */

        // TODO: находить устаревшие версии документа, предлагать обновиться

        $Template = $this->Twig->load('contract_sign.twig');
        $data['contract'] = $Hash->jsonSerialize();
        return $Template->render($data);
    }
}