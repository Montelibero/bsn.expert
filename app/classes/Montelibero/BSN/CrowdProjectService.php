<?php

namespace Montelibero\BSN;

use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Claimant;
use Soneso\StellarSDK\CreateClaimableBalanceOperationBuilder;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\ClaimableBalances\ClaimableBalanceResponse;
use Soneso\StellarSDK\Responses\ClaimableBalances\ClaimantResponse;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Throwable;

class CrowdProjectService
{
    private const CACHE_PREFIX = 'crowd_snapshot:v3:';
    private const CACHE_TTL = 1800;
    private const FRESH_SNAPSHOT_SECONDS = 60;
    private const STALE_CACHE_SECONDS = 21600;
    private const SCALE = 7;

    public function __construct(
        private readonly CrowdConfig $Config,
        private readonly CrowdIpfsClient $IpfsClient,
        private readonly MongoCacheManager $CacheManager,
        private readonly StellarSDK $Stellar,
    ) {
    }

    public function fetchSnapshot(bool $force_refresh = false): array
    {
        $issuer = $this->Config->issuer();
        if (!$issuer) {
            return $this->emptySnapshot('CROWD_STELLAR_ACCOUNT_ID is not configured');
        }

        $cache_key = self::CACHE_PREFIX . $issuer;
        $cached = $this->CacheManager->fetch($cache_key);
        if (!$force_refresh && is_array($cached) && is_array($cached['data'] ?? null)) {
            return $this->finalizeSnapshot($cached['data'], true);
        }

        try {
            $snapshot = $this->buildSnapshot($issuer);
            $this->CacheManager->store($cache_key, $snapshot, self::CACHE_TTL, [
                'issuer' => $issuer,
            ]);
            return $this->finalizeSnapshot($snapshot, false);
        } catch (Throwable $Exception) {
            if (is_array($cached) && is_array($cached['data'] ?? null)) {
                $snapshot = $cached['data'];
                $snapshot['warning'] = $Exception->getMessage();
                return $this->finalizeSnapshot($snapshot, true);
            }

            return $this->emptySnapshot($Exception->getMessage(), $issuer);
        }
    }

    public function findProject(string $code, bool $force_refresh = false): ?array
    {
        $code = strtoupper(trim($code));
        foreach ($this->fetchSnapshot($force_refresh)['projects'] as $project) {
            if (($project['code'] ?? '') === $code) {
                return $project;
            }
        }

        return null;
    }

    public function canCreateProjects(?string $current_account_id): bool
    {
        $issuer = $this->Config->issuer();
        return $issuer !== null && $current_account_id === $issuer;
    }

    public function defaultCreateValues(): array
    {
        return [
            'code' => '',
            'name' => '',
            'description' => '',
            'full_description' => '',
            'target_amount' => '',
            'deadline' => '',
            'project_account_id' => '',
            'contact_account_id' => '',
        ];
    }

    public function prepareCreateProject(array $input): array
    {
        $values = $this->normalizeCreateValues($input);
        $errors = $this->validateCreateValues($values);
        if ($errors) {
            return $this->createPreparationResult($values, $errors);
        }

        try {
            $issuer = $this->Config->issuer();
            if (!$issuer) {
                throw new \RuntimeException('CROWD_STELLAR_ACCOUNT_ID is not configured');
            }

            $IssuerAccount = $this->Stellar->requestAccount($issuer);
            if ($IssuerAccount->getData()->get('ipfshash-P' . $values['code']) !== null) {
                $errors[] = ['key' => 'crowd_create.errors.code_taken', 'params' => []];
            }

            $this->Stellar->requestAccount($values['project_account_id']);
            if ($values['contact_account_id'] !== '') {
                $this->Stellar->requestAccount($values['contact_account_id']);
            }

            if ($errors) {
                return $this->createPreparationResult($values, $errors);
            }

            $metadata = $this->buildCreateMetadata($values);
            $upload = $this->IpfsClient->uploadProjectJson($metadata, $values['code']);
            $Transaction = $this->buildCreateTransaction($IssuerAccount, $values, $upload['cid']);

            return [
                'values' => $values,
                'errors' => [],
                'signing_xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
                'signing_description' => 'Crowd project ' . $values['code'],
                'upload' => $upload,
            ];
        } catch (Throwable $Exception) {
            return $this->createPreparationResult($values, [[
                'key' => 'crowd_create.errors.prepare_failed',
                'params' => ['%message%' => $Exception->getMessage()],
            ]]);
        }
    }

    private function createPreparationResult(array $values, array $errors): array
    {
        return [
            'values' => $values,
            'errors' => $errors,
            'signing_xdr' => null,
            'signing_description' => null,
            'upload' => null,
        ];
    }

    private function normalizeCreateValues(array $input): array
    {
        $values = $this->defaultCreateValues();
        foreach ($values as $key => $value) {
            $values[$key] = trim((string) ($input[$key] ?? ''));
        }

        $values['code'] = strtoupper($values['code']);
        $values['target_amount'] = str_replace(',', '.', $values['target_amount']);
        $values['project_account_id'] = strtoupper($values['project_account_id']);
        $values['contact_account_id'] = strtoupper($values['contact_account_id']);

        return $values;
    }

    private function validateCreateValues(array $values): array
    {
        $errors = [];
        if ($values['code'] === '') {
            $errors[] = ['key' => 'crowd_create.errors.code_required', 'params' => []];
        } elseif (!preg_match('/\A[A-Z0-9]{1,11}\z/', $values['code'])) {
            $errors[] = ['key' => 'crowd_create.errors.code_invalid', 'params' => []];
        }

        if ($values['name'] === '') {
            $errors[] = ['key' => 'crowd_create.errors.name_required', 'params' => []];
        }
        if ($values['description'] === '') {
            $errors[] = ['key' => 'crowd_create.errors.description_required', 'params' => []];
        }
        if (!preg_match('/\A\d+(\.\d{1,7})?\z/', $values['target_amount']) || bccomp($values['target_amount'], '0', self::SCALE) <= 0) {
            $errors[] = ['key' => 'crowd_create.errors.target_invalid', 'params' => []];
        }
        if (!BSN::validateStellarAccountIdFormat($values['project_account_id'])) {
            $errors[] = ['key' => 'crowd_create.errors.project_account_invalid', 'params' => []];
        }
        if ($values['contact_account_id'] !== '' && !BSN::validateStellarAccountIdFormat($values['contact_account_id'])) {
            $errors[] = ['key' => 'crowd_create.errors.contact_account_invalid', 'params' => []];
        }
        if ($values['deadline'] !== '') {
            $Deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['deadline']);
            $date_errors = \DateTimeImmutable::getLastErrors();
            if (!$Deadline || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))) {
                $errors[] = ['key' => 'crowd_create.errors.deadline_invalid', 'params' => []];
            } elseif ($Deadline < new \DateTimeImmutable('today')) {
                $errors[] = ['key' => 'crowd_create.errors.deadline_past', 'params' => []];
            }
        }

        return $errors;
    }

    private function buildCreateMetadata(array $values): array
    {
        return [
            'name' => $values['name'],
            'code' => $values['code'],
            'description' => $values['description'],
            'fulldescription' => base64_encode($values['full_description']),
            'contact_account_id' => $values['contact_account_id'],
            'project_account_id' => $values['project_account_id'],
            'target_amount' => $this->decimal($values['target_amount']),
            'deadline' => $values['deadline'],
        ];
    }

    private function buildCreateTransaction($IssuerAccount, array $values, string $cid): TransactionBuilder
    {
        $issuer = $IssuerAccount->getAccountId();
        $Transaction = new TransactionBuilder($IssuerAccount);
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addMemo(Memo::text('Create funding ' . $values['code']));

        $project_token = Asset::createNonNativeAsset('P' . $values['code'], $issuer);
        $funding_token = Asset::createNonNativeAsset('C' . $values['code'], $issuer);
        $mtlcrowd = Asset::createNonNativeAsset(CrowdConfig::MTLCROWD_CODE, CrowdConfig::MTLCROWD_ISSUER);
        $native = Asset::native();
        $predicate = Claimant::predicateUnconditional();

        $Transaction->addOperations([
            (new BeginSponsoringFutureReservesOperationBuilder($issuer))
                ->setSourceAccount($values['project_account_id'])
                ->build(),
            (new ManageDataOperationBuilder('ipfshash-P' . $values['code'], $cid))
                ->build(),
            (new CreateClaimableBalanceOperationBuilder([
                new Claimant($issuer, $predicate),
                new Claimant($values['project_account_id'], $predicate),
            ], $project_token, '0.0000001'))
                ->build(),
            (new ManageSellOfferOperationBuilder($funding_token, $mtlcrowd, $this->decimal($values['target_amount']), '1'))
                ->build(),
            (new EndSponsoringFutureReservesOperationBuilder())
                ->build(),
            (new ChangeTrustOperationBuilder($mtlcrowd))
                ->setSourceAccount($values['project_account_id'])
                ->build(),
        ]);

        if ($values['contact_account_id'] !== '') {
            $Transaction->addOperation(
                (new PaymentOperationBuilder($issuer, $native, '0.0000001'))
                    ->setSourceAccount($values['contact_account_id'])
                    ->build()
            );
        }

        return $Transaction;
    }

    private function buildSnapshot(string $issuer): array
    {
        $Account = $this->Stellar->requestAccount($issuer);
        $data_keys = $Account->getData()->getData();
        $projects = [];
        $warnings = [];

        foreach ($data_keys as $key => $encoded_value) {
            if (!preg_match('/^ipfshash-P([A-Z0-9]{1,11})$/', (string) $key, $match)) {
                continue;
            }

            $code = $match[1];
            $cid = base64_decode((string) $encoded_value, true);
            if ($cid === false || trim($cid) === '') {
                $warnings[] = sprintf('%s has invalid CID data', $key);
                continue;
            }

            try {
                $metadata = $this->IpfsClient->fetchJson(trim($cid));
                $projects[] = $this->buildProject($issuer, $code, trim($cid), $metadata);
            } catch (Throwable $Exception) {
                $warnings[] = sprintf('%s: %s', $code, $Exception->getMessage());
                $projects[] = $this->buildBrokenProject($code, trim($cid), $Exception->getMessage());
            }
        }

        usort($projects, function (array $a, array $b): int {
            $deadline = strcmp((string) ($b['deadline'] ?? ''), (string) ($a['deadline'] ?? ''));
            if ($deadline !== 0) {
                return $deadline;
            }

            return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });

        return [
            'issuer' => $issuer,
            'fetched_at' => time(),
            'projects' => $projects,
            'groups' => $this->buildGroups($projects),
            'totals' => $this->buildTotals($projects),
            'warning' => $warnings ? implode("\n", $warnings) : null,
        ];
    }

    private function buildProject(string $issuer, string $code, string $cid, array $metadata): array
    {
        $project_code = (string) ($metadata['code'] ?? $code);
        $project_code = strtoupper(trim($project_code)) ?: $code;
        $target = $this->decimal($metadata['target_amount'] ?? '0');
        $is_closed = isset($metadata['funding_status']);

        if ($is_closed) {
            $funded = $this->decimal($metadata['funded_amount'] ?? '0');
            $supporters = $this->normalizeSupporters($metadata['supporters'] ?? []);
            $supporters_count = (int) ($metadata['supporters_count'] ?? count($supporters));
            $remaining = $this->decimal($metadata['remaining_amount'] ?? $this->remaining($target, $funded));
            $metrics_source = 'ipfs';
            $status = in_array($metadata['funding_status'], ['completed', 'canceled'], true)
                ? $metadata['funding_status']
                : 'closed';
        } else {
            $live = $this->collectLiveMetrics($issuer, $project_code);
            $funded = $live['funded_amount'];
            $supporters = $live['supporters'];
            $supporters_count = count($supporters);
            $remaining = $this->remaining($target, $funded);
            $metrics_source = 'horizon';
            $status = $this->activeStatus($target, $funded, (string) ($metadata['deadline'] ?? ''));
        }

        return [
            'code' => $project_code,
            'project_token_code' => 'P' . $project_code,
            'funding_token_code' => 'C' . $project_code,
            'name' => (string) ($metadata['name'] ?? $project_code),
            'description' => (string) ($metadata['description'] ?? ''),
            'full_description' => $this->decodeFullDescription($metadata['fulldescription'] ?? null),
            'contact_account' => $this->accountData($metadata['contact_account_id'] ?? null),
            'project_account' => $this->accountData($metadata['project_account_id'] ?? null),
            'target_amount' => $target,
            'deadline' => (string) ($metadata['deadline'] ?? ''),
            'cid' => $cid,
            'ipfs_url' => 'https://ipfs.io/ipfs/' . rawurlencode($cid),
            'funded_amount' => $funded,
            'remaining_amount' => $remaining,
            'mtlcrowd_token' => $this->Config->mtlCrowdToken(),
            'supporters_count' => $supporters_count,
            'supporters' => $supporters,
            'progress_percent' => $this->progressPercent($target, $funded),
            'status' => $status,
            'is_closed' => $is_closed,
            'metrics_source' => $metrics_source,
            'ipfs_from_cache' => (bool) ($metadata['_ipfs_from_cache'] ?? false),
            'warning' => $metadata['_ipfs_warning'] ?? null,
        ];
    }

    private function buildBrokenProject(string $code, string $cid, string $warning): array
    {
        return [
            'code' => $code,
            'project_token_code' => 'P' . $code,
            'funding_token_code' => 'C' . $code,
            'name' => $code,
            'description' => '',
            'full_description' => '',
            'contact_account' => null,
            'project_account' => null,
            'target_amount' => '0.0000000',
            'deadline' => '',
            'cid' => $cid,
            'ipfs_url' => 'https://ipfs.io/ipfs/' . rawurlencode($cid),
            'funded_amount' => '0.0000000',
            'remaining_amount' => '0.0000000',
            'mtlcrowd_token' => $this->Config->mtlCrowdToken(),
            'supporters_count' => 0,
            'supporters' => [],
            'progress_percent' => 0.0,
            'status' => 'error',
            'is_closed' => false,
            'metrics_source' => 'none',
            'ipfs_from_cache' => false,
            'warning' => $warning,
        ];
    }

    private function collectLiveMetrics(string $issuer, string $code): array
    {
        $asset_code = 'C' . $code;
        if (strlen($asset_code) > 12) {
            return [
                'funded_amount' => '0.0000000',
                'supporters' => [],
            ];
        }

        $funded = '0.0000000';
        $supporters = [];
        $Page = $this->Stellar
            ->claimableBalances()
            ->forAsset(Asset::createNonNativeAsset($asset_code, $issuer))
            ->limit(200)
            ->execute();

        while ($Page && $Page->getClaimableBalances()->count()) {
            foreach ($Page->getClaimableBalances()->toArray() as $Claimable) {
                if (!$Claimable instanceof ClaimableBalanceResponse) {
                    continue;
                }

                $claimants = array_map(
                    static fn(ClaimantResponse $Claimant): string => $Claimant->getDestination(),
                    $Claimable->getClaimants()->toArray()
                );
                if (!in_array($issuer, $claimants, true)) {
                    continue;
                }

                $amount = $this->decimal($Claimable->getAmount());
                $funded = bcadd($funded, $amount, self::SCALE);
                foreach ($claimants as $claimant) {
                    if ($claimant === $issuer) {
                        continue;
                    }

                    $supporters[$claimant] = bcadd($supporters[$claimant] ?? '0.0000000', $amount, self::SCALE);
                }
            }

            $Page = $Page->getNextPage();
        }

        arsort($supporters, SORT_NUMERIC);

        return [
            'funded_amount' => $funded,
            'supporters' => array_map(
                fn(string $account_id, string $amount): array => [
                    'account' => $this->accountData($account_id),
                    'amount' => $amount,
                ],
                array_keys($supporters),
                array_values($supporters)
            ),
        ];
    }

    private function normalizeSupporters(mixed $supporters): array
    {
        if (!is_array($supporters)) {
            return [];
        }

        $items = [];
        foreach ($supporters as $supporter) {
            if (!is_array($supporter)) {
                continue;
            }

            $account_id = $supporter['account_id'] ?? $supporter['account'] ?? null;
            if (!is_string($account_id) || $account_id === '') {
                continue;
            }

            $items[] = [
                'account' => $this->accountData($account_id),
                'amount' => $this->decimal($supporter['amount'] ?? '0'),
            ];
        }

        usort($items, static fn(array $a, array $b): int => bccomp($b['amount'], $a['amount'], self::SCALE));

        return $items;
    }

    private function buildTotals(array $projects): array
    {
        $successful_projects = 0;
        $collected = '0.0000000';
        $supporters = [];

        foreach ($projects as $project) {
            $status = (string) ($project['status'] ?? 'unknown');
            if (in_array($status, ['completed', 'funded'], true)) {
                $successful_projects++;
            }

            if (in_array($status, ['completed', 'funded', 'active'], true)) {
                $collected = bcadd($collected, $project['funded_amount'] ?? '0', self::SCALE);
            }

            foreach ($project['supporters'] ?? [] as $supporter) {
                $account_id = $supporter['account']['id'] ?? null;
                if (is_string($account_id) && $account_id !== '') {
                    $supporters[$account_id] = true;
                }
            }
        }

        return [
            'projects_count' => count($projects),
            'successful_projects' => $successful_projects,
            'supporters_count' => count($supporters),
            'collected_amount' => $collected,
        ];
    }

    private function buildGroups(array $projects): array
    {
        $groups = [
            'current' => [],
            'completed' => [],
            'canceled' => [],
        ];

        foreach ($projects as $project) {
            $status = (string) ($project['status'] ?? 'unknown');
            if ($status === 'canceled') {
                $groups['canceled'][] = $project;
            } elseif ($status === 'completed') {
                $groups['completed'][] = $project;
            } else {
                $groups['current'][] = $project;
            }
        }

        return $groups;
    }

    private function activeStatus(string $target, string $funded, string $deadline): string
    {
        if (bccomp($funded, $target, self::SCALE) >= 0 && bccomp($target, '0', self::SCALE) > 0) {
            return 'funded';
        }

        if ($deadline !== '' && strtotime($deadline . ' 23:59:59 UTC') !== false && time() > strtotime($deadline . ' 23:59:59 UTC')) {
            return 'expired';
        }

        return 'active';
    }

    private function remaining(string $target, string $funded): string
    {
        return bccomp($target, $funded, self::SCALE) > 0
            ? bcsub($target, $funded, self::SCALE)
            : '0.0000000';
    }

    private function progressPercent(string $target, string $funded): float
    {
        if (bccomp($target, '0', self::SCALE) <= 0) {
            return 0.0;
        }

        return round(min(100, ((float) $funded / (float) $target) * 100), 1);
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            $value = '0';
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function decodeFullDescription(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false ? $decoded : $value;
    }

    private function accountData(mixed $account_id): ?array
    {
        if (!is_string($account_id) || trim($account_id) === '') {
            return null;
        }

        $account_id = trim($account_id);
        return [
            'id' => $account_id,
            'short_id' => substr($account_id, 0, 2) . '...' . substr($account_id, -6),
        ];
    }

    private function emptySnapshot(?string $warning = null, ?string $issuer = null): array
    {
        return $this->finalizeSnapshot([
            'issuer' => $issuer,
            'fetched_at' => null,
            'projects' => [],
            'groups' => [
                'current' => [],
                'completed' => [],
                'canceled' => [],
            ],
            'totals' => [
                'projects_count' => 0,
                'successful_projects' => 0,
                'supporters_count' => 0,
                'collected_amount' => '0.0000000',
            ],
            'warning' => $warning,
        ], false);
    }

    private function finalizeSnapshot(array $snapshot, bool $from_cache): array
    {
        $fetched_at = isset($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0;
        $age = $fetched_at > 0 ? max(0, time() - $fetched_at) : null;
        $snapshot['from_cache'] = $from_cache;
        $snapshot['age_seconds'] = $age;
        $snapshot['is_fresh'] = $age !== null && $age < self::FRESH_SNAPSHOT_SECONDS;
        $snapshot['is_stale_cache'] = $age !== null && $age >= self::STALE_CACHE_SECONDS;

        return $snapshot;
    }
}
