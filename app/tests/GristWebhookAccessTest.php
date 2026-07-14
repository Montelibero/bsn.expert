<?php

declare(strict_types=1);

use Montelibero\BSN\GristSyncService;
use Montelibero\BSN\GristWebhookAccess;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertGristWebhookAccess(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$env_names = [
    GristSyncService::KNOWN_TOKENS => 'GRIST_WEBHOOK_SECRET_KNOWN_TOKENS',
    GristSyncService::MTLA_MEMBERS => 'GRIST_WEBHOOK_SECRET_MTLA_MEMBERS',
    GristSyncService::DOCUMENTS => 'GRIST_WEBHOOK_SECRET_DOCUMENTS',
];
$previous_values = [];
foreach ($env_names as $env_name) {
    $previous_values[$env_name] = $_ENV[$env_name] ?? null;
    unset($_ENV[$env_name]);
}

try {
    $Access = new GristWebhookAccess();

    foreach ($env_names as $scope => $env_name) {
        assertGristWebhookAccess(
            true,
            $Access->isAllowed($scope, null),
            sprintf('%s must allow migration-mode webhooks while its secret is unset.', $scope)
        );

        $_ENV[$env_name] = 'secret-for-' . $scope;
        assertGristWebhookAccess(
            false,
            $Access->isAllowed($scope, null),
            sprintf('%s must reject a missing Authorization header after configuration.', $scope)
        );
        assertGristWebhookAccess(
            false,
            $Access->isAllowed($scope, 'Bearer wrong-secret'),
            sprintf('%s must reject a wrong bearer secret.', $scope)
        );
        assertGristWebhookAccess(
            true,
            $Access->isAllowed($scope, 'Bearer secret-for-' . $scope),
            sprintf('%s must accept its own bearer secret.', $scope)
        );

        $_ENV[$env_name] = '';
        assertGristWebhookAccess(
            true,
            $Access->isAllowed($scope, 'anything'),
            sprintf('%s must treat an empty secret as migration mode.', $scope)
        );
        unset($_ENV[$env_name]);
    }

    $_ENV['GRIST_WEBHOOK_SECRET_KNOWN_TOKENS'] = 'tokens-secret';
    $_ENV['GRIST_WEBHOOK_SECRET_MTLA_MEMBERS'] = 'members-secret';
    assertGristWebhookAccess(
        false,
        $Access->isAllowed(GristSyncService::MTLA_MEMBERS, 'Bearer tokens-secret'),
        'Secrets from different Grist documents must not be interchangeable.'
    );
} finally {
    foreach ($previous_values as $env_name => $value) {
        if ($value === null) {
            unset($_ENV[$env_name]);
        } else {
            $_ENV[$env_name] = $value;
        }
    }
}

fwrite(STDOUT, "Grist webhook access regression tests passed.\n");
