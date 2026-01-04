<?php

namespace Montelibero\BSN;

use InvalidArgumentException;

class SignatureCollection
{
    private array $contracts = [];
    private array $signatures = [];
    private array $account_contract_to_signatures = [];
    private array $contract_to_signatures = [];
    private DocumentsManager $DocumentsManager;

    public function __construct(DocumentsManager $DocumentsManager)
    {
        $this->DocumentsManager = $DocumentsManager;
        $this->loadContractsData();
    }

    public function addSignature(Account $Account, string $hash, string $name): void
    {
        $Hash = $this->makeContract($hash);
        $account_id = $Account->getId();

        if (!array_key_exists($account_id, $this->account_contract_to_signatures)) {
            $this->account_contract_to_signatures[$account_id] = [];
        }
        if (array_key_exists($hash, $this->account_contract_to_signatures[$account_id])) {
            return;
        }

        $Signature = new Signature($Account, $Hash, $name);
        $this->account_contract_to_signatures[$account_id][] = $Signature;
        $this->contract_to_signatures[$hash][] = $Signature;
        $this->signatures[$hash][] = $Signature;

        $Account->addSignature($Signature);
    }

    public function makeContract(string $contract): Contract
    {
        if (!Contract::validate($contract)) {
            throw new InvalidArgumentException("Invalid hash: $contract");
        }

        if (array_key_exists($contract, $this->contracts)) {
            return $this->contracts[$contract];
        }

        $this->contract_to_signatures[$contract] = [];

        return $this->contracts[$contract] = new Contract($contract);
    }

    /**
     * @return Signature[]
     */
    public function getSignatures(): array
    {
        return $this->signatures;
    }

    /**
     * @param Account $Account
     * @return Signature[]
     */
    public function getSignaturesByAccount(Account $Account): array
    {
        return $this->account_contract_to_signatures[$Account->getId()] ?? [];
    }

    /**
     * @param Contract|string $contract
     * @return Signature[]
     */
    public function getAccountsByContract(Contract|string $contract): array
    {
        return $this->contract_to_signatures[(string) $contract] ?? [];
    }

    public function getContractsByUsing(): array
    {
        return array_map(
            function ($signatures) {
                return count($signatures);
            },
            $this->contract_to_signatures
        );
    }

    public function loadContractsData(): void
    {
        $data = $this->DocumentsManager->getDocuments();

        foreach ($data as $hash => $item) {
            $Contract = $this->makeContract($hash);
            $Contract->setName($item['name']);
            $Contract->setType($item['type']);
            $Contract->setUrl($item['url']);
            $Contract->setText($item['text']);
            $Contract->setSource($item['source'] ?? null);
            if ($item['new_hash']) {
                $Contract->setNewContract($this->makeContract($item['new_hash']));
            }
        }
    }
}
