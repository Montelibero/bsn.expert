<?php

namespace Montelibero\BSN\Controllers;

interface RefreshDataCodeInterface
{
    public function buildRefreshDataContext(string $scope, bool $can_refresh): array;

    public function isRefreshDataRequested(string $scope): bool;

    public function getRefreshDataRedirectUri(array $query_updates = []): string;
}
