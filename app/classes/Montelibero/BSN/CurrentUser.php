<?php

namespace Montelibero\BSN;

class CurrentUser
{
    private array $session;

    private const SESSION_ACCOUNT_KEY = 'account';
    private const SESSION_CURRENT_ACCOUNT_KEY = 'current_account_id';
    private const SESSION_HISTORY_KEY = 'current_account_history';
    private const HISTORY_LIMIT = 20;
    private BSN $BSN;

    public function __construct(array &$session, BSN $BSN)
    {
        $this->session = & $session;
        $this->BSN = $BSN;

        if (!isset($this->session[self::SESSION_HISTORY_KEY]) || !is_array($this->session[self::SESSION_HISTORY_KEY])) {
            $this->session[self::SESSION_HISTORY_KEY] = [];
        }
    }

    public function getAccountId(): ?string
    {
        return $this->session[self::SESSION_ACCOUNT_KEY]['id'] ?? null;
    }

    public function isAuthorized(): bool
    {
        return (bool) $this->getAccountId();
    }

    public function getAccount(): ?Account
    {
        if ($account_id = $this->getAccountId()) {
            return $this->BSN->makeAccountById($account_id);
        }

        return null;
    }

    public function getCurrentAccountId(): ?string
    {
        $explicit = $this->session[self::SESSION_CURRENT_ACCOUNT_KEY] ?? null;
        if ($explicit) {
            return $explicit;
        }

        if ($this->isAuthorized()) {
            return $this->getAccountId();
        }

        return null;
    }

    public function setCurrentAccountId(?string $account_id): bool
    {
        $account_id = $account_id ? trim($account_id) : null;

        if ($account_id !== null && !BSN::validateStellarAccountIdFormat($account_id)) {
            return false;
        }

        $this->session[self::SESSION_CURRENT_ACCOUNT_KEY] = $account_id ?: null;

        if ($account_id) {
            $this->rememberCurrentAccount($account_id);
        }

        return true;
    }

    public function getCurrentAccount(): ?Account
    {
        if ($account_id = $this->getCurrentAccountId()) {
            return $this->BSN->makeAccountById($account_id);
        }

        return null;
    }


    public function getCurrentAccountHistory(): array
    {
        $history = $this->session[self::SESSION_HISTORY_KEY] ?? [];
        return array_values(array_filter(array_unique($history)));
    }

    private function rememberCurrentAccount(string $account_id): void
    {
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return;
        }

        $history = $this->session[self::SESSION_HISTORY_KEY] ?? [];
        array_unshift($history, $account_id);
        $history = array_slice(array_values(array_unique($history)), 0, self::HISTORY_LIMIT);
        $this->session[self::SESSION_HISTORY_KEY] = $history;
    }
}
