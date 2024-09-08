<?php

namespace Montelibero\BSN;

class Link
{
    private Tag $Tag;
    private Account $SourceAccount;
    private Account $TargetAccount;

    /**
     * @param Tag $Tag
     * @param Account $SourceAccount
     * @param Account $TargetAccount
     */
    public function __construct(Tag $Tag, Account $SourceAccount, Account $TargetAccount)
    {
        $this->Tag = $Tag;
        $this->SourceAccount = $SourceAccount;
        $this->TargetAccount = $TargetAccount;
    }

    public function getTag(): Tag
    {
        return $this->Tag;
    }

    public function getSourceAccount(): Account
    {
        return $this->SourceAccount;
    }

    public function getTargetAccount(): Account
    {
        return $this->TargetAccount;
    }

}