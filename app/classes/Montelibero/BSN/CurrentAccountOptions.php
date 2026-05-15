<?php

namespace Montelibero\BSN;

class CurrentAccountOptions
{
    public function __construct(
        private readonly BSN $BSN,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentContacts $CurrentContacts,
    ) {
    }

    public function all(): array
    {
        $account_id = $this->CurrentUser->getAccountId();
        $current_account_id = $this->CurrentUser->getCurrentAccountId();
        $history_account_ids = $this->CurrentUser->getCurrentAccountHistory();
        if (
            $current_account_id
            && $current_account_id !== $account_id
            && !in_array($current_account_id, $history_account_ids, true)
        ) {
            array_unshift($history_account_ids, $current_account_id);
        }

        $history_positions = array_flip($history_account_ids);
        $ignored_account_ids = $this->CurrentUser->getIgnoredCurrentAccountOptionIds();

        $self_option = null;
        if ($account_id) {
            $Account = $this->BSN->makeAccountById($account_id);
            $self_option = $this->serializeOption($Account, 'self');
        }

        $options = [];
        foreach ($this->getOwnedAccounts($account_id) as $Account) {
            if ($this->shouldSkipIgnoredAccount($Account->getId(), $ignored_account_ids)) {
                continue;
            }

            $options[$Account->getId()] = $this->buildSortableOption(
                $this->serializeOption($Account, 'owned'),
                $history_positions
            );
        }

        foreach ($history_account_ids as $history_account_id) {
            if (!BSN::validateStellarAccountIdFormat($history_account_id)) {
                continue;
            }
            if ($history_account_id === $account_id) {
                continue;
            }
            if (array_key_exists($history_account_id, $options)) {
                continue;
            }
            if ($this->shouldSkipIgnoredAccount($history_account_id, $ignored_account_ids)) {
                continue;
            }

            $Account = $this->BSN->makeAccountById($history_account_id);
            $options[$history_account_id] = $this->buildSortableOption(
                $this->serializeOption($Account, 'history'),
                $history_positions
            );
        }

        uasort($options, function (array $a, array $b): int {
            $position = $a['position'] <=> $b['position'];
            if ($position !== 0) {
                return $position;
            }

            return strnatcasecmp($a['option']['display_name'] ?? $a['option']['id'], $b['option']['display_name'] ?? $b['option']['id']);
        });

        $result = [];
        if ($self_option !== null) {
            $result[] = $self_option;
        }
        foreach ($options as $option) {
            $result[] = $option['option'];
        }

        return $result;
    }

    private function getOwnedAccounts(?string $owner_id): array
    {
        if (!$owner_id) {
            return [];
        }

        $OwnerTag = Tag::fromName('Owner');
        $OwnershipFullTag = Tag::fromName('OwnershipFull');
        $owned = [];
        foreach ($this->BSN->getAccounts() as $Account) {
            $owners = $Account->getOutcomeLinks($OwnerTag);
            if (count($owners) !== 1) {
                continue;
            }

            $Owner = $owners[0];
            if ($Owner->getId() !== $owner_id || $Account->getId() === $owner_id) {
                continue;
            }

            foreach ($Owner->getOutcomeLinks($OwnershipFullTag) as $OutcomeLink) {
                if ($OutcomeLink->getId() === $Account->getId()) {
                    $owned[$Account->getId()] = $Account;
                    break;
                }
            }
        }

        return array_values($owned);
    }

    private function shouldSkipIgnoredAccount(string $account_id, array $ignored_account_ids): bool
    {
        return in_array($account_id, $ignored_account_ids, true);
    }

    private function serializeOption(Account $Account, string $source): array
    {
        return array_merge(
            $this->CurrentContacts->serialize($Account),
            ['source' => $source]
        );
    }

    private function buildSortableOption(array $option, array $history_positions): array
    {
        return [
            'option' => $option,
            'position' => $history_positions[$option['id']] ?? PHP_INT_MAX,
        ];
    }
}
