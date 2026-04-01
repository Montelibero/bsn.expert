<?php

namespace Montelibero\BSN\Controllers;

trait RefreshDataCodeTrait
{
    public function buildRefreshDataContext(string $scope, bool $can_refresh): array
    {
        return [
            'can_refresh' => $can_refresh,
            'action' => $this->getRefreshDataRedirectUri(),
            'code_param_name' => $this->getRefreshDataCodeParamName(),
            'code' => $can_refresh ? $this->buildRefreshDataCode($scope) : null,
        ];
    }

    public function isRefreshDataRequested(string $scope): bool
    {
        $value = (string) ($_GET[$this->getRefreshDataCodeParamName()] ?? '');
        return $value !== '' && hash_equals($this->buildRefreshDataCode($scope), $value);
    }

    public function getRefreshDataRedirectUri(array $query_updates = []): string
    {
        $parts = parse_url($this->getCurrentRequestUri());
        $path = $parts['path'] ?? '/';

        parse_str($parts['query'] ?? '', $query);
        unset($query[$this->getRefreshDataCodeParamName()]);

        foreach ($query_updates as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
                continue;
            }

            $query[$key] = $value;
        }

        $query_string = http_build_query($query);
        return $path . ($query_string !== '' ? '?' . $query_string : '');
    }

    protected function getRefreshDataCodeParamName(): string
    {
        return 'refresh_data_code';
    }

    protected function buildRefreshDataCode(string $scope): string
    {
        return hash('sha256', session_id() . ':' . $scope);
    }

    protected function getCurrentRequestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return $uri !== '' ? $uri : '/';
    }
}
