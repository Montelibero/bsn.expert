<?php

namespace Montelibero\BSN\MTLA\CalcDelegations;

class AccountCollection
{
    /**
     * @var Account[]
     */
    private array $list = [];

    private array $id2item = [];

    public function addItem(Account $Account): void
    {
        if (!array_key_exists($Account->getId(), $this->id2item)) {
            $this->list[] = $Account;
            $this->id2item[$Account->getId()] = $Account;
        }
    }

    public function getById(string $id): ?Account
    {
        return $this->id2item[$id] ?? null;
    }

    public function isExists(string|Account $needle): bool
    {
        return array_key_exists((string) $needle, $this->id2item);
    }

    /**
     * @return Account[]
     */
    public function asArray(): array
    {
        return $this->list;
    }

    public function isEmpty(): bool
    {
        return !$this->list;
    }

    public function isNonEmpty(): bool
    {
        return !!$this->list;
    }
}