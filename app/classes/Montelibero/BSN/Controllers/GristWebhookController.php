<?php

declare(strict_types=1);

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\GristSyncJobManager;
use Montelibero\BSN\GristWebhookAccess;
use Pecee\SimpleRouter\SimpleRouter;

class GristWebhookController
{
    private const DEBOUNCE_SECONDS = 60;

    public function __construct(
        private readonly GristSyncJobManager $Jobs,
        private readonly GristWebhookAccess $Access,
    ) {
    }

    public function receive(string $scope): string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$this->Access->isAllowed($scope, is_string($authorization) ? $authorization : null)) {
            SimpleRouter::response()->httpCode(401);
            SimpleRouter::response()->header('WWW-Authenticate: Bearer');

            return $this->json(['status' => 'error', 'error' => 'unauthorized']);
        }

        $this->Jobs->schedule($scope, self::DEBOUNCE_SECONDS);
        SimpleRouter::response()->httpCode(202);

        return $this->json([
            'status' => 'scheduled',
            'scope' => $scope,
            'debounce_seconds' => self::DEBOUNCE_SECONDS,
        ]);
    }

    private function json(array $data): string
    {
        SimpleRouter::response()->header('Content-Type: application/json; charset=utf-8');

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
