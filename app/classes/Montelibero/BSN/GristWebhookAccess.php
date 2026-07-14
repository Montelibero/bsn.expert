<?php

declare(strict_types=1);

namespace Montelibero\BSN;

class GristWebhookAccess
{
    private const SECRET_ENV_BY_SCOPE = [
        GristSyncService::KNOWN_TOKENS => 'GRIST_WEBHOOK_SECRET_KNOWN_TOKENS',
        GristSyncService::MTLA_MEMBERS => 'GRIST_WEBHOOK_SECRET_MTLA_MEMBERS',
        GristSyncService::DOCUMENTS => 'GRIST_WEBHOOK_SECRET_DOCUMENTS',
    ];

    public function isAllowed(string $scope, ?string $authorization_header): bool
    {
        GristSyncService::assertScope($scope);
        $env_name = self::SECRET_ENV_BY_SCOPE[$scope];
        $secret = trim((string) ($_ENV[$env_name] ?? ''));

        // Transitional mode: webhooks stay callable until a secret is configured.
        if ($secret === '') {
            return true;
        }

        return is_string($authorization_header)
            && hash_equals('Bearer ' . $secret, trim($authorization_header));
    }
}
