<?php

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class StellarTomlManager
{
    public const FRESH_SECONDS = 604800;

    private string $tomls_collection = 'stellar_tomls';
    private string $runs_collection = 'stellar_toml_runs';

    public function __construct(
        private Manager $Mongo,
        private string $database,
    ) {
    }

    public function fetchDomain(string $home_domain): ?array
    {
        $doc = $this->fetchDomainRaw($home_domain);

        return $doc ? $this->normalizeMongoValue($doc) : null;
    }

    public function fetchDomainRaw(string $home_domain): ?array
    {
        $query = new Query(['home_domain' => $home_domain], ['limit' => 1]);
        $doc = current($this->Mongo->executeQuery($this->tomlsNamespace(), $query)->toArray()) ?: null;

        return $doc ? get_object_vars($doc) : null;
    }

    public function saveDomain(array $doc): void
    {
        $now = self::now();
        $doc['updated_at'] = $now;

        $bulk = new BulkWrite();
        $bulk->update(
            ['home_domain' => $doc['home_domain']],
            [
                '$set' => $doc,
                '$setOnInsert' => ['created_at' => $now],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->tomlsNamespace(), $bulk);
    }

    public function setDomainIgnored(string $home_domain, bool $ignored, ?string $admin_account_id = null, ?string $reason = null): ?array
    {
        $home_domain = self::normalizeHomeDomain($home_domain);
        if ($home_domain === null) {
            return null;
        }

        $now = self::now();
        $existing = $this->fetchDomainRaw($home_domain) ?? [];
        $set = [
            'home_domain' => $home_domain,
            'ignored' => $ignored,
            'updated_at' => $now,
        ];
        if ($ignored) {
            $set['status'] = 'ignored';
            $set['status_before_ignore'] = ($existing['ignored'] ?? false)
                ? ($existing['status_before_ignore'] ?? null)
                : ($existing['status'] ?? null);
            $set['error_before_ignore'] = ($existing['ignored'] ?? false)
                ? ($existing['error_before_ignore'] ?? null)
                : ($existing['error'] ?? null);
            $set['ignored_at'] = $now;
            $set['ignored_by'] = $admin_account_id;
            $set['ignore_reason'] = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        } else {
            $set['ignored'] = false;
            $set['status'] = is_string($existing['status_before_ignore'] ?? null)
                ? $existing['status_before_ignore']
                : 'pending';
            $set['error'] = $existing['error_before_ignore'] ?? null;
            $set['ignore_removed_at'] = $now;
            $set['ignore_removed_by'] = $admin_account_id;
        }

        $bulk = new BulkWrite();
        $bulk->update(
            ['home_domain' => $home_domain],
            [
                '$set' => $set,
                '$setOnInsert' => [
                    'created_at' => $now,
                    'observed_accounts' => [],
                    'declared_accounts' => [],
                    'currencies' => [],
                ],
            ],
            ['upsert' => true]
        );
        $this->Mongo->executeBulkWrite($this->tomlsNamespace(), $bulk);

        return $this->fetchDomain($home_domain);
    }

    public function isDomainIgnored(string $home_domain): bool
    {
        $doc = $this->fetchDomainRaw($home_domain);

        return (bool) ($doc['ignored'] ?? false);
    }

    public function recordRun(array $summary): void
    {
        $summary['created_at'] = self::now();

        $bulk = new BulkWrite();
        $bulk->insert($summary);
        $this->Mongo->executeBulkWrite($this->runsNamespace(), $bulk);
    }

    public function fetchLastRun(): ?array
    {
        $query = new Query([], ['limit' => 1, 'sort' => ['created_at' => -1]]);
        $doc = current($this->Mongo->executeQuery($this->runsNamespace(), $query)->toArray()) ?: null;

        return $doc ? $this->normalizeMongoValue($doc) : null;
    }

    public function fetchDashboardData(int $problem_limit = 200): array
    {
        $query = new Query([], ['sort' => ['home_domain' => 1]]);
        $docs = $this->Mongo->executeQuery($this->tomlsNamespace(), $query);

        $domains = [];
        $problem_domains = [];
        $accounts = [];
        $accounts_with_problem = [];
        $ok_domains_count = 0;
        $error_domains_count = 0;
        $ignored_domains_count = 0;
        $ignored_domains = [];

        foreach ($docs as $doc) {
            $doc = $this->normalizeMongoValue($doc);
            $domains[] = $doc;
            $has_account_problem = false;

            foreach (($doc['observed_accounts'] ?? []) as $account) {
                $account_id = (string) ($account['account_id'] ?? '');
                if ($account_id === '') {
                    continue;
                }
                $accounts[$account_id] = true;
                if (($account['problem'] ?? null) !== null) {
                    $accounts_with_problem[$account_id] = true;
                    $has_account_problem = true;
                }
            }

            if (($doc['ignored'] ?? false) === true) {
                $ignored_domains_count++;
                $ignored_domains[] = $doc;
                continue;
            }

            if (($doc['status'] ?? null) === 'ok' && !$has_account_problem) {
                $ok_domains_count++;
            } else {
                $error_domains_count++;
                if (count($problem_domains) < $problem_limit) {
                    $problem_domains[] = $doc;
                }
            }
        }

        return [
            'last_run' => $this->fetchLastRun(),
            'domains_count' => count($domains),
            'ok_domains_count' => $ok_domains_count,
            'error_domains_count' => $error_domains_count,
            'ignored_domains_count' => $ignored_domains_count,
            'accounts_count' => count($accounts),
            'accounts_with_problem_count' => count($accounts_with_problem),
            'problem_domains' => $problem_domains,
            'ignored_domains' => $ignored_domains,
        ];
    }

    public function findFreshByAccount(string $account_id): ?array
    {
        $min_success = new UTCDateTime((time() - self::FRESH_SECONDS) * 1000);
        $query = new Query(
            [
                'status' => 'ok',
                'ignored' => ['$ne' => true],
                'last_success_at' => ['$gte' => $min_success],
                '$or' => [
                    ['declared_accounts' => $account_id],
                    ['currencies.issuer' => $account_id],
                ],
            ],
            ['limit' => 1, 'sort' => ['last_success_at' => -1]]
        );
        $doc = current($this->Mongo->executeQuery($this->tomlsNamespace(), $query)->toArray()) ?: null;

        return $doc ? $this->normalizeMongoValue($doc) : null;
    }

    public function findFreshByToken(string $code, string $issuer): ?array
    {
        $min_success = new UTCDateTime((time() - self::FRESH_SECONDS) * 1000);
        $query = new Query(
            [
                'status' => 'ok',
                'ignored' => ['$ne' => true],
                'last_success_at' => ['$gte' => $min_success],
                'currencies.key' => self::tokenKey($code, $issuer),
            ],
            ['limit' => 1, 'sort' => ['last_success_at' => -1]]
        );
        $doc = current($this->Mongo->executeQuery($this->tomlsNamespace(), $query)->toArray()) ?: null;

        return $doc ? $this->normalizeMongoValue($doc) : null;
    }

    public static function tokenKey(string $code, string $issuer): string
    {
        return strtoupper($code) . '-' . strtoupper($issuer);
    }

    public static function now(): UTCDateTime
    {
        return new UTCDateTime((int) (microtime(true) * 1000));
    }

    public static function normalizeHomeDomain(?string $home_domain): ?string
    {
        $home_domain = strtolower(trim((string) $home_domain));
        if ($home_domain === '') {
            return null;
        }
        if (str_contains($home_domain, '://') || preg_match('~[/?#@:\s]~', $home_domain)) {
            return null;
        }

        $ascii = function_exists('idn_to_ascii')
            ? idn_to_ascii($home_domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : $home_domain;
        if (!is_string($ascii) || $ascii === '') {
            return null;
        }

        if (strlen($ascii) > 253 || !preg_match('~\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z~', $ascii)) {
            return null;
        }

        return $ascii;
    }

    private function tomlsNamespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->tomls_collection);
    }

    private function runsNamespace(): string
    {
        return sprintf('%s.%s', $this->database, $this->runs_collection);
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return [
                'iso' => $value->toDateTime()->format('Y-m-d H:i:s'),
                'ts' => $value->toDateTime()->getTimestamp(),
            ];
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeMongoValue($item);
        }

        return $value;
    }
}
