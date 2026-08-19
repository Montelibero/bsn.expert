<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final readonly class ApiPrincipal
{
    private array $details;

    public function __construct(array $key_details, string $token_mask)
    {
        $key_id = $key_details['id'] ?? null;
        $account_id = $key_details['account_id'] ?? null;
        if (!is_string($key_id) || $key_id === '' || !is_string($account_id) || $account_id === '') {
            throw new \UnexpectedValueException('Authenticated API key is missing its identity metadata.');
        }

        unset($key_details['key']);
        $key_details['key_masked'] = $token_mask;
        $this->details = $key_details;
    }

    public function keyId(): string
    {
        return $this->details['id'];
    }

    public function accountId(): string
    {
        return $this->details['account_id'];
    }

    public function permissions(): array
    {
        $permissions = $this->details['permissions'] ?? [];
        return is_array($permissions) ? $permissions : [];
    }

    public function lastSuccessfulContactsSyncRevision(): ?int
    {
        $revision = $this->details['last_succeed_contacts_sync_revision'] ?? null;
        return is_int($revision) ? $revision : null;
    }

    public function details(): array
    {
        return $this->details;
    }
}
