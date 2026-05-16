<?php

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\TokensController;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;
use Throwable;
use Yosymfony\Toml\Exception\ParseException;
use Yosymfony\Toml\Toml;

class StellarTomlCrawler
{
    private const MAX_TOML_BYTES = 102400;
    private const HTTP_TIMEOUT_SECONDS = 10;

    public function __construct(
        private StellarSDK $Stellar,
        private TokensController $TokensController,
        private StellarTomlManager $TomlManager,
    ) {
    }

    public function runAll(?callable $log = null): array
    {
        $started_at = microtime(true);
        $summary = [
            'status' => 'ok',
            'started_at_ts' => time(),
            'mtlap_horizon_pages' => 0,
            'mtlap_holders' => 0,
            'mtlac_horizon_pages' => 0,
            'mtlac_holders' => 0,
            'known_tokens' => 0,
            'known_token_issuer_horizon_requests' => 0,
            'known_token_issuer_horizon_skipped' => 0,
            'accounts_seen' => 0,
            'accounts_with_home_domain' => 0,
            'home_domains_seen' => 0,
            'home_domains_requested' => 0,
            'home_domains_ok' => 0,
            'home_domains_error' => 0,
            'home_domains_ignored' => 0,
            'home_domains_unchanged' => 0,
            'errors' => [],
        ];

        $observed_accounts = [];
        $member_token_accounts = [];
        $known_issuer_home_domains = [];

        try {
            $this->log($log, 'Collecting MTLAP holders');
            foreach ($this->collectMemberTokenHolders('MTLAP', $summary) as $account_id => $home_domain) {
                $member_token_accounts[$account_id] = true;
                $this->addObservedAccount($observed_accounts, $account_id, $home_domain, 'mtlap_holder');
            }
        } catch (Throwable $e) {
            $summary['status'] = 'partial';
            $summary['errors'][] = [
                'stage' => 'mtlap_holders',
                'message' => $e->getMessage(),
            ];
        }

        try {
            $this->log($log, 'Collecting MTLAC holders');
            foreach ($this->collectMemberTokenHolders('MTLAC', $summary) as $account_id => $home_domain) {
                $member_token_accounts[$account_id] = true;
                $this->addObservedAccount($observed_accounts, $account_id, $home_domain, 'mtlac_holder');
            }
        } catch (Throwable $e) {
            $summary['status'] = 'partial';
            $summary['errors'][] = [
                'stage' => 'mtlac_holders',
                'message' => $e->getMessage(),
            ];
        }

        try {
            $this->log($log, 'Collecting known token issuers');
            $known_tokens = $this->TokensController->getKnownTokens();
            $summary['known_tokens'] = count($known_tokens);
            foreach ($known_tokens as $token) {
                $issuer = strtoupper(trim((string) ($token['issuer'] ?? '')));
                $code = trim((string) ($token['code'] ?? ''));
                if (!BSN::validateStellarAccountIdFormat($issuer) || $code === '') {
                    continue;
                }
                if (isset($member_token_accounts[$issuer])) {
                    $summary['known_token_issuer_horizon_skipped']++;
                    $this->addObservedAccount($observed_accounts, $issuer, $observed_accounts[$issuer]['home_domain'] ?? null, 'known_token_issuer', $code);
                    continue;
                }

                if (array_key_exists($issuer, $known_issuer_home_domains)) {
                    $summary['known_token_issuer_horizon_skipped']++;
                    $this->addObservedAccount($observed_accounts, $issuer, $known_issuer_home_domains[$issuer], 'known_token_issuer', $code);
                    continue;
                }

                $summary['known_token_issuer_horizon_requests']++;
                try {
                    $home_domain = StellarTomlManager::normalizeHomeDomain($this->Stellar->requestAccount($issuer)->getHomeDomain());
                } catch (Throwable) {
                    $home_domain = null;
                }
                $known_issuer_home_domains[$issuer] = $home_domain;
                $this->addObservedAccount($observed_accounts, $issuer, $home_domain, 'known_token_issuer', $code);
            }
        } catch (Throwable $e) {
            $summary['status'] = 'partial';
            $summary['errors'][] = [
                'stage' => 'known_tokens',
                'message' => $e->getMessage(),
            ];
        }

        $summary['accounts_seen'] = count($observed_accounts);
        $domains = $this->groupAccountsByDomain($observed_accounts);
        $summary['accounts_with_home_domain'] = array_sum(array_map('count', $domains));
        $summary['home_domains_seen'] = count($domains);

        foreach ($domains as $home_domain => $accounts) {
            if ($this->TomlManager->isDomainIgnored($home_domain)) {
                $summary['home_domains_ignored']++;
                $this->log($log, sprintf('Skipping ignored %s (%d accounts)', $home_domain, count($accounts)));
                $this->recordIgnoredDomain($home_domain, $accounts);
                continue;
            }

            $summary['home_domains_requested']++;
            $this->log($log, sprintf('Refreshing %s (%d accounts)', $home_domain, count($accounts)));
            $result = $this->refreshDomain($home_domain, $accounts);

            if (($result['status'] ?? null) === 'ok') {
                $summary['home_domains_ok']++;
                if (($result['unchanged'] ?? false) === true) {
                    $summary['home_domains_unchanged']++;
                }
            } else {
                $summary['home_domains_error']++;
            }
        }

        $summary['duration_seconds'] = round(microtime(true) - $started_at, 3);
        $this->TomlManager->recordRun($summary);

        return $summary;
    }

    public function refreshAccount(string $account_id): array
    {
        $account_id = strtoupper(trim($account_id));
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return [
                'status' => 'error',
                'error' => ['code' => 'invalid_account', 'message' => 'Invalid Stellar account id'],
            ];
        }

        try {
            $home_domain = StellarTomlManager::normalizeHomeDomain($this->Stellar->requestAccount($account_id)->getHomeDomain());
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => ['code' => 'horizon_error', 'message' => $e->getMessage()],
            ];
        }

        if ($home_domain === null) {
            return [
                'status' => 'error',
                'error' => ['code' => 'no_home_domain', 'message' => 'Account has no home_domain'],
            ];
        }

        return $this->refreshDomain($home_domain, [
            $account_id => [
                'account_id' => $account_id,
                'home_domain' => $home_domain,
                'sources' => ['manual'],
                'tokens' => [],
            ],
        ]);
    }

    public function refreshDomain(string $home_domain, array $observed_accounts = []): array
    {
        $home_domain = StellarTomlManager::normalizeHomeDomain($home_domain);
        if ($home_domain === null) {
            return [
                'status' => 'error',
                'error' => ['code' => 'invalid_domain', 'message' => 'Invalid home_domain'],
            ];
        }

        if ($this->TomlManager->isDomainIgnored($home_domain)) {
            return [
                'status' => 'ignored',
                'home_domain' => $home_domain,
                'error' => ['code' => 'ignored', 'message' => 'Domain is ignored'],
            ];
        }

        $last_attempt_at = StellarTomlManager::now();
        $existing = $this->TomlManager->fetchDomainRaw($home_domain) ?? [];
        $observed_accounts = $this->mergeObservedAccounts($existing['observed_accounts'] ?? [], $observed_accounts);
        $fetch = $this->fetchToml($home_domain);

        if (($fetch['status'] ?? null) !== 'ok') {
            $doc = $this->preservePreviousData($existing);
            $doc['home_domain'] = $home_domain;
            $doc['status'] = 'error';
            $doc['last_attempt_at'] = $last_attempt_at;
            $doc['observed_accounts'] = $this->buildObservedAccounts(
                $observed_accounts,
                $doc['declared_accounts'] ?? [],
                $doc['currencies'] ?? [],
                'domain_fetch_failed'
            );
            $doc['error'] = $fetch['error'];
            $this->TomlManager->saveDomain($doc);

            return ['status' => 'error', 'home_domain' => $home_domain, 'error' => $fetch['error']];
        }

        $content = $fetch['content'];
        $hash = hash('sha256', $content);
        $unchanged = (($existing['status'] ?? null) === 'ok' && ($existing['last_content_hash'] ?? null) === $hash);

        if ($unchanged) {
            $doc = $this->preservePreviousData($existing);
            $doc['home_domain'] = $home_domain;
            $doc['status'] = 'ok';
            $doc['last_attempt_at'] = $last_attempt_at;
            $doc['last_success_at'] = $last_attempt_at;
            $doc['last_content_hash'] = $hash;
            $doc['content_size'] = $fetch['content_size'];
            $doc['observed_accounts'] = $this->buildObservedAccounts(
                $observed_accounts,
                $doc['declared_accounts'] ?? [],
                $doc['currencies'] ?? []
            );
            $doc['error'] = null;
            $this->TomlManager->saveDomain($doc);

            return ['status' => 'ok', 'home_domain' => $home_domain, 'unchanged' => true];
        }

        try {
            $parsed = Toml::Parse($content);
            if (!is_array($parsed)) {
                throw new ParseException('Parsed TOML root is not an array');
            }
        } catch (Throwable $e) {
            $doc = $this->preservePreviousData($existing);
            $doc['home_domain'] = $home_domain;
            $doc['status'] = 'error';
            $doc['last_attempt_at'] = $last_attempt_at;
            $doc['last_attempt_hash'] = $hash;
            $doc['last_attempt_size'] = $fetch['content_size'];
            $doc['observed_accounts'] = $this->buildObservedAccounts(
                $observed_accounts,
                $doc['declared_accounts'] ?? [],
                $doc['currencies'] ?? [],
                'domain_fetch_failed'
            );
            $doc['error'] = ['code' => 'parse_error', 'message' => $e->getMessage()];
            $this->TomlManager->saveDomain($doc);

            return ['status' => 'error', 'home_domain' => $home_domain, 'error' => $doc['error']];
        }

        $currencies = $this->extractCurrencies($parsed['CURRENCIES'] ?? []);
        $declared_accounts = $this->extractDeclaredAccounts($parsed, $currencies);
        $doc = [
            'home_domain' => $home_domain,
            'status' => 'ok',
            'last_attempt_at' => $last_attempt_at,
            'last_success_at' => $last_attempt_at,
            'last_content_hash' => $hash,
            'content_size' => $fetch['content_size'],
            'version' => $this->stringValue($parsed['VERSION'] ?? null),
            'network_passphrase' => $this->stringValue($parsed['NETWORK_PASSPHRASE'] ?? null),
            'servers' => $this->extractServers($parsed),
            'documentation' => $this->extractDocumentation($parsed['DOCUMENTATION'] ?? []),
            'principals' => $this->extractPrincipals($parsed['PRINCIPALS'] ?? []),
            'declared_accounts' => $declared_accounts,
            'currencies' => $currencies,
            'observed_accounts' => $this->buildObservedAccounts($observed_accounts, $declared_accounts, $currencies),
            'raw_toml' => $content,
            'error' => null,
        ];
        $this->TomlManager->saveDomain($doc);

        return ['status' => 'ok', 'home_domain' => $home_domain, 'unchanged' => false];
    }

    private function collectMemberTokenHolders(string $code, array &$summary): array
    {
        $holders = [];
        $asset = Asset::createNonNativeAsset($code, MtlaController::MTLA_ACCOUNT);
        $accounts_page = $this->Stellar->accounts()->forAsset($asset)->limit(200)->execute();
        $summary_prefix = strtolower($code);

        do {
            $summary[$summary_prefix . '_horizon_pages']++;
            foreach ($accounts_page->getAccounts() as $account_response) {
                if (!$account_response instanceof AccountResponse || !$this->hasAssetBalanceAtLeast($account_response, $code, MtlaController::MTLA_ACCOUNT, 1.0)) {
                    continue;
                }
                $holders[$account_response->getAccountId()] = StellarTomlManager::normalizeHomeDomain($account_response->getHomeDomain());
            }

            $accounts_page = $accounts_page->getNextPage();
        } while ($accounts_page->getAccounts()->count() > 0);

        $summary[$summary_prefix . '_holders'] = count($holders);

        return $holders;
    }

    private function hasAssetBalanceAtLeast(AccountResponse $account, string $code, string $issuer, float $min): bool
    {
        foreach ($account->getBalances()->toArray() as $balance) {
            if (
                $balance instanceof AccountBalanceResponse
                && $balance->getAssetCode() === $code
                && $balance->getAssetIssuer() === $issuer
                && (float) $balance->getBalance() >= $min
            ) {
                return true;
            }
        }

        return false;
    }

    private function fetchToml(string $home_domain): array
    {
        $dns_error = $this->validateDomainDns($home_domain);
        if ($dns_error !== null) {
            return ['status' => 'error', 'error' => $dns_error];
        }

        $client = new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['User-Agent' => 'BSN Viewer stellar.toml crawler'],
        ]);

        try {
            $response = $client->request('GET', 'https://' . $home_domain . '/.well-known/stellar.toml', [
                'stream' => true,
            ]);
        } catch (ConnectException $e) {
            return ['status' => 'error', 'error' => $this->classifyGuzzleError($e, 'connect_error')];
        } catch (RequestException $e) {
            return ['status' => 'error', 'error' => $this->classifyGuzzleError($e, 'server_error')];
        } catch (GuzzleException $e) {
            return ['status' => 'error', 'error' => ['code' => 'server_error', 'message' => $e->getMessage()]];
        }

        $status_code = $response->getStatusCode();
        if ($status_code !== 200) {
            return [
                'status' => 'error',
                'error' => [
                    'code' => 'http_error',
                    'message' => 'HTTP ' . $status_code,
                    'http_status' => $status_code,
                ],
            ];
        }

        $content_length = $response->getHeaderLine('Content-Length');
        if ($content_length !== '' && (int) $content_length > self::MAX_TOML_BYTES) {
            return [
                'status' => 'error',
                'error' => ['code' => 'too_large', 'message' => 'Content-Length exceeds 100 KB'],
            ];
        }

        $body = $response->getBody();
        $content = '';
        while (!$body->eof()) {
            $content .= $body->read(8192);
            if (strlen($content) > self::MAX_TOML_BYTES) {
                return [
                    'status' => 'error',
                    'error' => ['code' => 'too_large', 'message' => 'File exceeds 100 KB'],
                ];
            }
        }

        if (trim($content) === '') {
            return ['status' => 'error', 'error' => ['code' => 'empty_file', 'message' => 'File is empty']];
        }

        return [
            'status' => 'ok',
            'content' => $content,
            'content_size' => strlen($content),
        ];
    }

    private function validateDomainDns(string $home_domain): ?array
    {
        $records = @dns_get_record($home_domain, DNS_A + DNS_AAAA);
        if (!$records) {
            return ['code' => 'dns_error', 'message' => 'Domain does not resolve'];
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['code' => 'invalid_domain', 'message' => 'Domain resolves to private or reserved IP'];
            }
        }

        return null;
    }

    private function classifyGuzzleError(Throwable $e, string $fallback_code): array
    {
        $message = $e->getMessage();
        $code = str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout')
            ? 'timeout'
            : $fallback_code;

        return ['code' => $code, 'message' => $message];
    }

    private function recordIgnoredDomain(string $home_domain, array $observed_accounts): void
    {
        $existing = $this->TomlManager->fetchDomainRaw($home_domain) ?? [];
        $observed_accounts = $this->mergeObservedAccounts($existing['observed_accounts'] ?? [], $observed_accounts);

        $doc = $this->preservePreviousData($existing);
        $doc['home_domain'] = $home_domain;
        $doc['status'] = 'ignored';
        $doc['ignored'] = true;
        $doc['ignored_at'] = $existing['ignored_at'] ?? StellarTomlManager::now();
        $doc['ignored_by'] = $existing['ignored_by'] ?? null;
        $doc['ignore_reason'] = $existing['ignore_reason'] ?? null;
        $doc['last_seen_at'] = StellarTomlManager::now();
        $doc['observed_accounts'] = $this->buildObservedAccounts(
            $observed_accounts,
            $doc['declared_accounts'] ?? [],
            $doc['currencies'] ?? [],
            'domain_ignored'
        );
        $doc['error'] = ['code' => 'ignored', 'message' => 'Domain is ignored'];

        $this->TomlManager->saveDomain($doc);
    }

    private function addObservedAccount(array &$accounts, string $account_id, ?string $home_domain, string $source, ?string $token_code = null): void
    {
        if (!isset($accounts[$account_id])) {
            $accounts[$account_id] = [
                'account_id' => $account_id,
                'home_domain' => $home_domain,
                'sources' => [],
                'tokens' => [],
            ];
        }

        if ($accounts[$account_id]['home_domain'] === null && $home_domain !== null) {
            $accounts[$account_id]['home_domain'] = $home_domain;
        }
        $accounts[$account_id]['sources'][$source] = true;
        if ($token_code !== null) {
            $accounts[$account_id]['tokens'][$token_code] = true;
        }
    }

    private function groupAccountsByDomain(array $observed_accounts): array
    {
        $domains = [];
        foreach ($observed_accounts as $account) {
            $home_domain = $account['home_domain'] ?? null;
            if ($home_domain === null) {
                continue;
            }
            $domains[$home_domain][$account['account_id']] = [
                'account_id' => $account['account_id'],
                'home_domain' => $home_domain,
                'sources' => array_keys($account['sources']),
                'tokens' => array_keys($account['tokens']),
            ];
        }

        ksort($domains);

        return $domains;
    }

    private function buildObservedAccounts(array $observed_accounts, array $declared_accounts, array $currencies, ?string $forced_problem = null): array
    {
        $declared = array_fill_keys($declared_accounts, true);
        foreach ($currencies as $currency) {
            $issuer = $currency['issuer'] ?? null;
            if (is_string($issuer) && BSN::validateStellarAccountIdFormat($issuer)) {
                $declared[$issuer] = true;
            }
        }

        $result = [];
        foreach ($observed_accounts as $account) {
            $account_id = $account['account_id'];
            $mentioned = isset($declared[$account_id]);
            $result[] = [
                'account_id' => $account_id,
                'home_domain' => $account['home_domain'] ?? null,
                'sources' => array_values($account['sources'] ?? []),
                'tokens' => array_values($account['tokens'] ?? []),
                'mentioned' => $mentioned,
                'problem' => $forced_problem ?? ($mentioned ? null : 'account_not_declared'),
            ];
        }

        usort($result, static fn(array $a, array $b): int => strcmp($a['account_id'], $b['account_id']));

        return $result;
    }

    private function mergeObservedAccounts(mixed $existing_observed_accounts, array $observed_accounts): array
    {
        $merged = [];
        if (is_array($existing_observed_accounts)) {
            foreach ($existing_observed_accounts as $account) {
                $account = $this->objectsToArrays($account);
                $account_id = strtoupper(trim((string) ($account['account_id'] ?? '')));
                if (!BSN::validateStellarAccountIdFormat($account_id)) {
                    continue;
                }
                $merged[$account_id] = [
                    'account_id' => $account_id,
                    'home_domain' => $account['home_domain'] ?? null,
                    'sources' => array_fill_keys((array) ($account['sources'] ?? []), true),
                    'tokens' => array_fill_keys((array) ($account['tokens'] ?? []), true),
                ];
            }
        }

        foreach ($observed_accounts as $account) {
            $account = $this->objectsToArrays($account);
            $account_id = strtoupper(trim((string) ($account['account_id'] ?? '')));
            if (!BSN::validateStellarAccountIdFormat($account_id)) {
                continue;
            }
            if (!isset($merged[$account_id])) {
                $merged[$account_id] = [
                    'account_id' => $account_id,
                    'home_domain' => $account['home_domain'] ?? null,
                    'sources' => [],
                    'tokens' => [],
                ];
            }
            if (($merged[$account_id]['home_domain'] ?? null) === null && ($account['home_domain'] ?? null) !== null) {
                $merged[$account_id]['home_domain'] = $account['home_domain'];
            }
            foreach ((array) ($account['sources'] ?? []) as $source) {
                $merged[$account_id]['sources'][(string) $source] = true;
            }
            foreach ((array) ($account['tokens'] ?? []) as $token) {
                $merged[$account_id]['tokens'][(string) $token] = true;
            }
        }

        foreach ($merged as &$account) {
            $account['sources'] = array_keys($account['sources']);
            $account['tokens'] = array_keys($account['tokens']);
        }
        unset($account);

        return $merged;
    }

    private function extractDeclaredAccounts(array $parsed, array $currencies): array
    {
        $accounts = [];
        if (is_array($parsed['ACCOUNTS'] ?? null)) {
            foreach ($parsed['ACCOUNTS'] as $account_id) {
                $account_id = strtoupper(trim((string) $account_id));
                if (BSN::validateStellarAccountIdFormat($account_id)) {
                    $accounts[$account_id] = true;
                }
            }
        }
        foreach ($currencies as $currency) {
            $issuer = strtoupper(trim((string) ($currency['issuer'] ?? '')));
            if (BSN::validateStellarAccountIdFormat($issuer)) {
                $accounts[$issuer] = true;
            }
        }

        return array_keys($accounts);
    }

    private function extractServers(array $parsed): array
    {
        return $this->pickStringFields($parsed, [
            'FEDERATION_SERVER' => 'federation_server',
            'AUTH_SERVER' => 'auth_server',
            'TRANSFER_SERVER' => 'transfer_server',
            'TRANSFER_SERVER_SEP0024' => 'transfer_server_sep0024',
            'KYC_SERVER' => 'kyc_server',
            'WEB_AUTH_ENDPOINT' => 'web_auth_endpoint',
            'WEB_AUTH_FOR_CONTRACTS_ENDPOINT' => 'web_auth_for_contracts_endpoint',
            'WEB_AUTH_CONTRACT_ID' => 'web_auth_contract_id',
            'SIGNING_KEY' => 'signing_key',
            'HORIZON_URL' => 'horizon_url',
            'URI_REQUEST_SIGNING_KEY' => 'uri_request_signing_key',
            'DIRECT_PAYMENT_SERVER' => 'direct_payment_server',
            'ANCHOR_QUOTE_SERVER' => 'anchor_quote_server',
        ]);
    }

    private function extractDocumentation(mixed $documentation): array
    {
        if (!is_array($documentation)) {
            return [];
        }

        return $this->pickStringFields($documentation, [
            'ORG_NAME' => 'org_name',
            'ORG_DBA' => 'org_dba',
            'ORG_URL' => 'org_url',
            'ORG_LOGO' => 'org_logo',
            'ORG_DESCRIPTION' => 'org_description',
            'ORG_PHYSICAL_ADDRESS' => 'org_physical_address',
            'ORG_PHYSICAL_ADDRESS_ATTESTATION' => 'org_physical_address_attestation',
            'ORG_PHONE_NUMBER' => 'org_phone_number',
            'ORG_PHONE_NUMBER_ATTESTATION' => 'org_phone_number_attestation',
            'ORG_KEYBASE' => 'org_keybase',
            'ORG_TWITTER' => 'org_twitter',
            'ORG_GITHUB' => 'org_github',
            'ORG_OFFICIAL_EMAIL' => 'org_official_email',
            'ORG_SUPPORT_EMAIL' => 'org_support_email',
            'ORG_LICENSING_AUTHORITY' => 'org_licensing_authority',
            'ORG_LICENSE_TYPE' => 'org_license_type',
            'ORG_LICENSE_NUMBER' => 'org_license_number',
        ]);
    }

    private function extractPrincipals(mixed $principals): array
    {
        if (!is_array($principals)) {
            return [];
        }

        $result = [];
        foreach ($principals as $principal) {
            if (!is_array($principal)) {
                continue;
            }
            $item = $this->pickStringFields($principal, [
                'name' => 'name',
                'email' => 'email',
                'keybase' => 'keybase',
                'telegram' => 'telegram',
                'twitter' => 'twitter',
                'github' => 'github',
                'id_photo_hash' => 'id_photo_hash',
                'verification_photo_hash' => 'verification_photo_hash',
            ]);
            if ($item) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function extractCurrencies(mixed $currencies): array
    {
        if (!is_array($currencies)) {
            return [];
        }

        $result = [];
        foreach ($currencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }
            $item = $this->pickStringFields($currency, [
                'code' => 'code',
                'issuer' => 'issuer',
                'contract' => 'contract',
                'code_template' => 'code_template',
                'status' => 'status',
                'name' => 'name',
                'desc' => 'desc',
                'conditions' => 'conditions',
                'image' => 'image',
                'anchor_asset_type' => 'anchor_asset_type',
                'anchor_asset' => 'anchor_asset',
                'attestation_of_reserve' => 'attestation_of_reserve',
                'redemption_instructions' => 'redemption_instructions',
                'approval_server' => 'approval_server',
                'approval_criteria' => 'approval_criteria',
                'toml' => 'toml',
            ]);
            foreach (['display_decimals', 'fixed_number', 'max_number'] as $field) {
                if (isset($currency[$field]) && is_numeric($currency[$field])) {
                    $item[$field] = (int) $currency[$field];
                }
            }
            foreach (['is_unlimited', 'is_asset_anchored', 'regulated'] as $field) {
                if (isset($currency[$field]) && is_bool($currency[$field])) {
                    $item[$field] = $currency[$field];
                }
            }
            foreach (['collateral_addresses', 'collateral_address_messages', 'collateral_address_signatures'] as $field) {
                if (isset($currency[$field]) && is_array($currency[$field])) {
                    $item[$field] = array_values(array_filter(array_map([$this, 'stringValue'], $currency[$field])));
                }
            }

            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $issuer = strtoupper(trim((string) ($item['issuer'] ?? '')));
            if ($code !== '') {
                $item['code'] = $code;
            }
            if (BSN::validateStellarAccountIdFormat($issuer)) {
                $item['issuer'] = $issuer;
                $item['key'] = StellarTomlManager::tokenKey($code, $issuer);
            }

            if ($item) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function pickStringFields(array $source, array $map): array
    {
        $result = [];
        foreach ($map as $source_key => $target_key) {
            $value = $this->stringValue($source[$source_key] ?? null);
            if ($value !== null) {
                $result[$target_key] = $value;
            }
        }

        return $result;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function preservePreviousData(array $existing): array
    {
        $fields = [
            'last_success_at',
            'last_content_hash',
            'content_size',
            'version',
            'network_passphrase',
            'servers',
            'documentation',
            'principals',
            'declared_accounts',
            'currencies',
            'raw_toml',
        ];
        $doc = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $existing)) {
                $doc[$field] = $this->objectsToArrays($existing[$field]);
            }
        }

        return $doc;
    }

    private function objectsToArrays(mixed $value): mixed
    {
        if (is_object($value) && !($value instanceof \MongoDB\BSON\UTCDateTime)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->objectsToArrays($item);
        }

        return $value;
    }

    private function log(?callable $log, string $message): void
    {
        if ($log !== null) {
            $log($message);
        }
    }
}
