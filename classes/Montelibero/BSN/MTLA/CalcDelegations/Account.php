<?php

namespace Montelibero\BSN\MTLA\CalcDelegations;

use DateTime;

class Account
{
    private string $id;
    private int $token_amount;
    private bool $is_ready_to_council;
    private ?self $DelegateAssemblyTo = null;
    private ?self $DelegateCouncilTo = null;
    private bool $is_broken_a_delegate = false;
    private bool $is_broken_c_delegate = false;
    private ?AccountCollection $DelegatedAssemblyBy;
    private ?AccountCollection $DelegatedCouncilBy;
    private ?DateTime $AssetAddedAt;

    public function __construct(string $id, int $token_amount = 0)
    {

        $this->id = $id;
        $this->token_amount = $token_amount;
        $this->DelegatedAssemblyBy = new AccountCollection();
        $this->DelegatedCouncilBy = new AccountCollection();
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->getId();
    }

    /**
     * @return int
     */
    public function getTokenAmount(): int
    {
        return $this->token_amount;
    }

    public function isReadyToCouncil(bool $new_value = null): bool
    {
        if ($new_value !== null) {
            $this->is_ready_to_council = $new_value;
        }

        return $this->is_ready_to_council;
    }

    public function isVerified(): bool
    {
        return $this->getTokenAmount() >= 2;
    }

    /**
     * @return ?Account
     */
    public function getDelegateAssemblyTo(): ?Account
    {
        return $this->DelegateAssemblyTo;
    }

    /**
     * @param ?Account $DelegateAssemblyTo
     */
    public function setDelegateAssemblyTo(?Account $DelegateAssemblyTo): void
    {
        $this->DelegateAssemblyTo = $DelegateAssemblyTo;
        $DelegateAssemblyTo?->addDelegatedAssemblyBy($this);
    }

    public function isBrokenAssemblyDelegate(bool $new_value = null): bool
    {
        if ($new_value !== null) {
            $this->is_broken_a_delegate = $new_value;
        }

        return $this->is_broken_a_delegate;
    }

    private function addDelegatedAssemblyBy(?Account $Account): void
    {
        $this->DelegatedAssemblyBy->addItem($Account);
    }

    /**
     * @return ?Account
     */
    public function getDelegateCouncilTo(): ?Account
    {
        return $this->DelegateCouncilTo;
    }

    /**
     * @param ?Account $DelegateCouncilTo
     */
    public function setDelegateCouncilTo(?Account $DelegateCouncilTo): void
    {
        $this->DelegateCouncilTo = $DelegateCouncilTo;
        $DelegateCouncilTo?->addDelegatedCouncilBy($this);
    }

    public function isBrokenCouncilDelegate(bool $new_value = null): bool
    {
        if ($new_value !== null) {
            $this->is_broken_a_delegate = $new_value;
        }

        return $this->is_broken_a_delegate;
    }

    private function addDelegatedCouncilBy(?Account $Account): void
    {
        $this->DelegatedCouncilBy->addItem($Account);
    }

    public function getAssemblyTree(): array
    {
        $data = [
            'id' => $this->getId(),
            'own_token_amount' => $this->getTokenAmount(),
            'delegated_voices' => 0,
            'delegated' => [],
        ];

        foreach ($this->DelegatedAssemblyBy->asArray() as $Account) {
            if ($Account->isBrokenAssemblyDelegate()) {
                continue;
            }
            $account_data = $Account->getAssemblyTree();
            $data['delegated_voices'] += ($account_data['own_token_amount'] ? 1 : 0) + $account_data['delegated_voices'];
            $data['delegated'][] = $account_data;
        }

        return $data;
    }

    public function getCouncilTree(): array
    {
        $data = [
            'id' => $this->getId(),
            'own_token_amount' => $this->isVerified() ? $this->getTokenAmount() : 0,
            'delegated_token_amount' => 0,
            'delegated' => [],
        ];
        if ($this->isReadyToCouncil()) {
            $data['is_ready_to_council'] = true;
        }

        foreach ($this->DelegatedCouncilBy->asArray() as $Account) {
            if ($Account->isBrokenCouncilDelegate()) {
                continue;
            }
            $account_data = $Account->getCouncilTree();
            $data['delegated_token_amount'] += $account_data['own_token_amount'] + $account_data['delegated_token_amount'];
            $data['delegated'][] = $account_data;
        }

        return $data;
    }

    public function getTokenPower(): int
    {
        return ($this->isVerified() ? $this->getTokenAmount() : 0) + $this->getDelegatedTokenAmount();
    }

    public function getDelegatedTokenAmount(): int
    {
        $count = 0;

        foreach ($this->DelegatedCouncilBy->asArray() as $Account) {
            if ($Account->isBrokenCouncilDelegate()) {
                continue;
            }
            $count += $Account->getTokenPower();
        }

        return $count;
    }

    public function getVoicePower(): int
    {
        return ($this->getTokenAmount() ? 1 : 0) + $this->getDelegatedVoices();
    }

    public function getDelegatedVoices(): int
    {
        $count = 0;

        foreach ($this->DelegatedAssemblyBy->asArray() as $Account) {
            if ($Account->isBrokenAssemblyDelegate()) {
                continue;
            }
            $count += $Account->getVoicePower();
        }

        return $count;
    }

    /**
     * @return DateTime|null
     */
    public function getAssetAddedAt(): ?DateTime
    {
        return $this->AssetAddedAt;
    }

    /**
     * @param DateTime|null $AssetAddedAt
     */
    public function setAssetAddedAt(?DateTime $AssetAddedAt): void
    {
        $this->AssetAddedAt = $AssetAddedAt;
    }
}