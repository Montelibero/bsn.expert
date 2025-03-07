<?php

namespace Montelibero\BSN;

class Signature
{
    private Account $Account;
    private Contract $Contract;
    private string $name;

    public function __construct(Account $Account, Contract $Contract, string $name)
    {
        $this->Account = $Account;
        $this->Contract = $Contract;
        $this->name = $name;
    }

    public function getAccount(): Account
    {
        return $this->Account;
    }

    public function getContract(): Contract
    {
        return $this->Contract;
    }

    public function getName(): string
    {
        return $this->name;
    }
}