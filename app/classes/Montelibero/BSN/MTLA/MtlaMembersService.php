<?php

declare(strict_types=1);

namespace Montelibero\BSN\MTLA;

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\GristSnapshotStore;
use Montelibero\BSN\GristSyncService;

class MtlaMembersService
{
    public function __construct(
        private readonly BSN $BSN,
        private readonly GristSnapshotStore $Snapshots,
    ) {
    }

    public function isCoordinator(string $account_id): bool
    {
        $Account = $this->BSN->getAccountById($account_id);
        if ($Account === null || $Account->getBalance('MTLAP') < 1) {
            return false;
        }

        $Mtla = $this->BSN->getAccountById(MtlaController::MTLA_ACCOUNT);
        $ProgramTag = $this->BSN->makeTagByName('Program');
        $CoordinatorTag = $this->BSN->makeTagByName('ProgramCoordinator');
        $MyPartTag = $this->BSN->makeTagByName('MyPart');
        $PartOfTag = $this->BSN->makeTagByName('PartOf');
        $accountIds = static fn(array $accounts): array => array_map(
            static fn(Account $Item): string => $Item->getId(),
            $accounts
        );
        $participating_programs = $accountIds($Account->getOutcomeLinks($PartOfTag));

        foreach ($Mtla?->getOutcomeLinks($ProgramTag) ?? [] as $Program) {
            // Count every distinct nominee before checking membership or reciprocal participation.
            $coordinators = array_values(array_unique($accountIds($Program->getOutcomeLinks($CoordinatorTag))));
            if (
                $coordinators === [$account_id]
                && in_array($Program->getId(), $participating_programs, true)
                && in_array($account_id, $accountIds($Program->getOutcomeLinks($MyPartTag)), true)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{telegram_id: string, telegram_username: ?string}>|null */
    public function members(?string $stellar = null, ?string $telegram_id = null): ?array
    {
        $snapshot = $this->Snapshots->fetch(GristSyncService::MTLA_MEMBERS);
        if ($snapshot === null) {
            return null;
        }

        $members = [];
        $conflicts = [];
        foreach ($snapshot['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $address = $row['stellar'] ?? null;
            $tg_id = GristSyncService::normalizeTelegramId($row['tg_id'] ?? null);
            if (
                !is_string($address)
                || !BSN::validateStellarAccountIdFormat($address)
                || $tg_id === null
                || ($this->BSN->getAccountById($address)?->getBalance('MTLAP') ?? 0) <= 0
            ) {
                continue;
            }

            $username = is_string($row['tg_username'] ?? null) ? trim($row['tg_username'], "@ \t\n\r") : '';
            $member = ['telegram_id' => $tg_id, 'telegram_username' => $username !== '' ? $username : null];
            if (isset($members[$address]) && $members[$address] !== $member) {
                $conflicts[$address] = true;
            }
            $members[$address] = $member;
        }

        // An ambiguous source row must not silently assign somebody else's Telegram identity.
        $members = array_diff_key($members, $conflicts);
        $members = array_filter($members, static fn(array $member, string $address): bool =>
            ($stellar === null || $stellar === $address)
            && ($telegram_id === null || $telegram_id === $member['telegram_id']), ARRAY_FILTER_USE_BOTH);
        ksort($members);

        return $members;
    }
}
