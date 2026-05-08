<?php

namespace Montelibero\BSN;

class CurrentContacts
{
    private ?string $loaded_for_account_id = null;
    private ?array $contacts = null;

    public function __construct(
        private readonly BSN $BSN,
        private readonly ContactsManager $ContactsManager,
        private readonly CurrentUser $CurrentUser,
    ) {
    }

    public function beginRequest(): void
    {
        $this->loaded_for_account_id = null;
        $this->contacts = null;
    }

    public function refresh(): void
    {
        $this->beginRequest();
    }

    public function all(): array
    {
        $account_id = $this->CurrentUser->getAccountId();
        if ($account_id === null) {
            return [];
        }

        if ($this->contacts === null || $this->loaded_for_account_id !== $account_id) {
            $this->contacts = $this->ContactsManager->getContacts($account_id);
            $this->loaded_for_account_id = $account_id;
        }

        return $this->contacts;
    }

    public function getContact(Account|array|string|null $account): ?array
    {
        $account_id = $this->resolveAccountId($account);
        if ($account_id === null) {
            return null;
        }

        return $this->all()[$account_id] ?? null;
    }

    public function isContact(Account|array|string|null $account): bool
    {
        return $this->getContact($account) !== null;
    }

    public function getContactName(Account|array|string|null $account): ?string
    {
        $name = $this->getContact($account)['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getDisplayName(Account|array|string|null $account, bool $ignore_contact = false): string
    {
        $Account = $this->resolveAccount($account);
        if ($Account === null) {
            return $this->fallbackDisplayName($account);
        }

        $result = $Account->getShortId();
        $public_name = $Account->getName()[0] ?? '';
        $contact_name = null;
        $contact_mode = !$ignore_contact && $this->isContact($Account);
        if ($contact_mode && ($contact_name = $this->getContactName($Account))) {
            $result .= ' [📒 ' . $contact_name . ']';
        } elseif ($contact_mode && $public_name) {
            $result .= ' [📒 ' . $public_name . ']';
        } elseif ($contact_mode) {
            $result .= ' [📒]';
        } elseif ($public_name) {
            $result .= ' [' . $public_name . ']';
        }

        if (
            $contact_name === null
            && $this->CurrentUser->getShowTelegramUsernames()
            && $telegram_username = $Account->getTelegramUsername()
        ) {
            $result .= ' @' . $telegram_username;
        }

        if ($emoji = $Account->getEmoji()) {
            $result .= ' ' . $emoji;
        }

        return $result;
    }

    public function serialize(Account $Account, bool $ignore_contact = false): array
    {
        $data = [
            'id' => $Account->getId(),
            'short_id' => $Account->getShortId(),
            'display_name' => $this->getDisplayName($Account, $ignore_contact),
        ];
        if ($username = $Account->getUsername()) {
            $data['username'] = $username;
        }
        if ($name = $Account->getName()[0] ?? null) {
            $data['name'] = $name;
        }

        return $data;
    }

    public function getAccountIds(): array
    {
        return array_keys($this->all());
    }

    private function resolveAccount(Account|array|string|null $account): ?Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        $account_id = $this->resolveAccountId($account);
        if ($account_id === null) {
            return null;
        }

        return $this->BSN->makeAccountById($account_id);
    }

    private function resolveAccountId(Account|array|string|null $account): ?string
    {
        if ($account instanceof Account) {
            return $account->getId();
        }
        if ($account === null) {
            return null;
        }
        if (is_string($account)) {
            return BSN::validateStellarAccountIdFormat($account) ? $account : null;
        }

        $account_id = $account['id'] ?? $account['account_id'] ?? null;
        if (!is_string($account_id)) {
            return null;
        }

        return BSN::validateStellarAccountIdFormat($account_id) ? $account_id : null;
    }

    private function fallbackDisplayName(Account|array|string|null $account): string
    {
        if (is_array($account)) {
            return (string) (
                $account['display_name']
                ?? $account['name']
                ?? $account['short_id']
                ?? $account['id']
                ?? $account['account_id']
                ?? ''
            );
        }
        if ($account === null) {
            return '';
        }

        return (string) $account;
    }
}
