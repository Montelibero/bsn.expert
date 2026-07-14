<?php

declare(strict_types=1);

namespace Montelibero\BSN;

class GristRuntimeData
{
    private const CHECK_INTERVAL_SECONDS = 60;

    private int $members_checked_at = 0;
    private int $members_version = -1;

    public function __construct(
        private readonly BSN $BSN,
        private readonly GristSnapshotStore $SnapshotStore,
    ) {
    }

    public function refreshMtlaMembersIfNeeded(bool $force = false): void
    {
        if (!$force && time() - $this->members_checked_at < self::CHECK_INTERVAL_SECONDS) {
            return;
        }
        $this->members_checked_at = time();

        $snapshot = $this->SnapshotStore->fetch(GristSyncService::MTLA_MEMBERS);
        if ($snapshot === null || $snapshot['version'] === $this->members_version) {
            return;
        }

        $this->BSN->loadMtlaMembersFromJson($snapshot['data']);
        $this->members_version = $snapshot['version'];
    }
}
