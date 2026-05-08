<?php

namespace Montelibero\BSN;

use Montelibero\BSN\Relations\Member;

class CurrentUser
{
    private array $session;

    private const SESSION_ACCOUNT_KEY = 'account';
    private const SESSION_CURRENT_ACCOUNT_KEY = 'current_account_id';
    private const SESSION_HISTORY_KEY = 'current_account_history';
    private const SESSION_AUTO_CURRENT_ACCOUNT_NOTICE_KEY = 'auto_current_account_notice';
    private const HISTORY_LIMIT = 20;
    private BSN $BSN;
    private ?string $request_current_account_id = null;

    public function __construct(array &$session, BSN $BSN)
    {
        $this->session = & $session;
        $this->BSN = $BSN;

        if (!isset($this->session[self::SESSION_HISTORY_KEY]) || !is_array($this->session[self::SESSION_HISTORY_KEY])) {
            $this->session[self::SESSION_HISTORY_KEY] = [];
        }

        $previous_current_account_id = $this->getCurrentAccountIdWithoutRequestParam();
        $this->request_current_account_id = $this->resolveRequestCurrentAccountId();
        if ($this->request_current_account_id !== null) {
            if (
                $previous_current_account_id !== null
                && $previous_current_account_id !== $this->request_current_account_id
            ) {
                $this->rememberAutoCurrentAccountChange($this->request_current_account_id);
            }
            $this->setCurrentAccountId($this->request_current_account_id);
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

    public function isImpactActivist(): bool
    {
        $Account = $this->getAccount();
        return $Account !== null && $Account->getBalance('MTLAP') >= 4;
    }

    public function getMemberLevel(): int
    {
        $Account = $this->getAccount();
        if ($Account === null) {
            return 0;
        }

        $Relation = $Account->getRelation();
        if ($Relation instanceof Member) {
            return $Relation->getLevel();
        }

        return 0;
    }

    public function getShowTelegramUsernames(): bool
    {
        if ($this->getMemberLevel() >= 2) {
            return true;
        }

        return (bool) ($this->session['show_telegram_usernames'] ?? false);
    }

    public function getShowUnknownTags(): bool
    {
        return ($_COOKIE['show_unknown_tags'] ?? null) === 'yes';
    }

    public function getCurrentAccountId(): ?string
    {
        if ($this->request_current_account_id !== null) {
            return $this->request_current_account_id;
        }

        return $this->getCurrentAccountIdWithoutRequestParam();
    }

    private function getCurrentAccountIdWithoutRequestParam(): ?string
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

    public function getCurrentAccountRequestParam(): ?string
    {
        return $this->request_current_account_id;
    }

    public function getCurrentAccountCleanupUrl(): ?string
    {
        if ($this->request_current_account_id === null) {
            return null;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }
        if (!isset($_COOKIE[session_name()])) {
            return null;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($request_uri) || $request_uri === '') {
            return null;
        }

        $parts = parse_url($request_uri);
        if ($parts === false) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        unset($query['current_account']);

        $url = $parts['path'] ?? '/';
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
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

    public function rememberAutoCurrentAccountChange(string $account_id): void
    {
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return;
        }

        $this->session[self::SESSION_AUTO_CURRENT_ACCOUNT_NOTICE_KEY] = $account_id;
    }

    public function consumeAutoCurrentAccountNotice(): ?array
    {
        $account_id = $this->session[self::SESSION_AUTO_CURRENT_ACCOUNT_NOTICE_KEY] ?? null;
        unset($this->session[self::SESSION_AUTO_CURRENT_ACCOUNT_NOTICE_KEY]);

        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return null;
        }

        return $this->BSN->makeAccountById($account_id)->jsonSerialize();
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

    private function resolveRequestCurrentAccountId(): ?string
    {
        foreach ([$_POST['current_account'] ?? null, $_GET['current_account'] ?? null] as $value) {
            $account_id = strtoupper(trim((string) $value));
            if (BSN::validateStellarAccountIdFormat($account_id)) {
                return $account_id;
            }
        }

        return null;
    }
}
