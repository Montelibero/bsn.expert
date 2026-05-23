<?php

namespace Montelibero\BSN;

use GuzzleHttp\Client;
use Montelibero\BSN\Controllers\TokensController;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\Claimant;
use Soneso\StellarSDK\ClaimClaimableBalanceOperationBuilder;
use Soneso\StellarSDK\ClawbackOperationBuilder;
use Soneso\StellarSDK\CreateClaimableBalanceOperationBuilder;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\ManageBuyOfferOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperationBuilder;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\ClaimableBalances\ClaimableBalanceResponse;
use Soneso\StellarSDK\Responses\ClaimableBalances\ClaimantResponse;
use Soneso\StellarSDK\Responses\Offers\OfferResponse;
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
    private const DONATION_SLIPPAGE_PERCENT = '5';

    private ?Client $HttpClient = null;

    public function __construct(
        private readonly CrowdConfig $Config,
        private readonly CrowdIpfsClient $IpfsClient,
        private readonly MongoCacheManager $CacheManager,
        private readonly StellarSDK $Stellar,
        private readonly TokensController $TokensController,
        private readonly StellarAccountReserveCalculator $ReserveCalculator,
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

    public function createValuesFromProject(array $project): array
    {
        return [
            'code' => (string) ($project['code'] ?? ''),
            'name' => (string) ($project['name'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
            'full_description' => (string) ($project['full_description'] ?? ''),
            'target_amount' => (string) ($project['target_amount'] ?? ''),
            'deadline' => (string) ($project['deadline'] ?? ''),
            'project_account_id' => (string) ($project['project_account']['id'] ?? ''),
            'contact_account_id' => (string) ($project['contact_account']['id'] ?? ''),
        ];
    }

    public function crowdToken(): array
    {
        return $this->Config->mtlCrowdToken();
    }

    public function projectAdminActions(array $project, ?string $current_account_id = null): array
    {
        $is_active = !($project['is_closed'] ?? false);
        $is_funded = bccomp($project['funded_amount'] ?? '0', $project['target_amount'] ?? '0', self::SCALE) >= 0
            && bccomp($project['target_amount'] ?? '0', '0', self::SCALE) > 0;
        $deadline_reached = $this->isDeadlineReached((string) ($project['deadline'] ?? ''));
        $code = rawurlencode((string) ($project['code'] ?? ''));
        $primary = null;
        $menu = [];

        if ($is_active && $is_funded) {
            $primary = [
                'action' => 'complete',
                'url' => $this->withCurrentAccountParam("/crowd/$code/action/complete", $current_account_id),
                'label' => 'crowd_page.admin.complete',
                'icon' => 'fa-check',
                'class' => 'is-primary',
            ];
        } elseif ($is_active && $deadline_reached) {
            $primary = [
                'action' => 'cancel',
                'url' => $this->withCurrentAccountParam("/crowd/$code/action/cancel", $current_account_id),
                'label' => 'crowd_page.admin.cancel',
                'icon' => 'fa-xmark',
                'class' => 'is-warning',
            ];
        }

        if ($is_active) {
            $menu[] = [
                'url' => $this->withCurrentAccountParam('/crowd/create?code=' . $code, $current_account_id),
                'label' => 'crowd_page.admin.edit',
                'icon' => 'fa-file-pen',
            ];
            if (!$deadline_reached && !$is_funded) {
                $menu[] = [
                    'url' => $this->withCurrentAccountParam("/crowd/$code/action/cancel", $current_account_id),
                    'label' => 'crowd_page.admin.cancel',
                    'icon' => 'fa-xmark',
                ];
            }
        }

        $menu[] = [
            'url' => $this->withCurrentAccountParam("/crowd/$code/action/delete", $current_account_id),
            'label' => 'crowd_page.admin.delete',
            'icon' => 'fa-xmark',
        ];

        return [
            'primary' => $primary,
            'menu' => $menu,
        ];
    }

    private function withCurrentAccountParam(string $url, ?string $current_account_id): string
    {
        if ($current_account_id === null || $current_account_id === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'current_account=' . rawurlencode($current_account_id);
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

    public function prepareEditProject(array $project, array $input): array
    {
        $values = $this->normalizeCreateValues($input + ['code' => $project['code'] ?? '']);
        $values['code'] = (string) ($project['code'] ?? $values['code']);
        $errors = $this->validateCreateValues($values, true);
        $new_target_amount = $this->decimal($values['target_amount']);
        $funded_amount = $this->decimal($project['funded_amount'] ?? '0');
        if (bccomp($new_target_amount, $funded_amount, self::SCALE) < 0) {
            $errors[] = [
                'key' => 'crowd_create.errors.target_below_funded',
                'params' => ['%amount%' => $this->shortAmount($funded_amount)],
            ];
        }
        if ($errors) {
            return $this->createPreparationResult($values, $errors);
        }

        try {
            $issuer = $this->Config->issuer();
            if (!$issuer) {
                throw new \RuntimeException('CROWD_STELLAR_ACCOUNT_ID is not configured');
            }

            $IssuerAccount = $this->Stellar->requestAccount($issuer);
            $offer_operations = [];
            if (!($project['is_closed'] ?? false) && bccomp($new_target_amount, $this->decimal($project['target_amount'] ?? '0'), self::SCALE) !== 0) {
                $offer_operations = $this->buildUpdateFundingOfferOperations($project, $new_target_amount);
            }
            $metadata = $this->buildCreateMetadata($values);
            $upload = $this->IpfsClient->uploadProjectJson($metadata, $values['code']);
            $Transaction = new TransactionBuilder($IssuerAccount);
            $Transaction->setMaxOperationFee(10000);
            $Transaction->addMemo(Memo::text('Edit funding ' . $values['code']));
            $Transaction->addOperation(
                (new ManageDataOperationBuilder('ipfshash-P' . $values['code'], $upload['cid']))->build()
            );
            foreach ($offer_operations as $Operation) {
                $Transaction->addOperation($Operation);
            }

            return [
                'values' => $values,
                'errors' => [],
                'signing_xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
                'signing_description' => 'Edit crowd project ' . $values['code'],
                'upload' => $upload,
            ];
        } catch (Throwable $Exception) {
            return $this->createPreparationResult($values, [[
                'key' => 'crowd_create.errors.prepare_failed',
                'params' => ['%message%' => $Exception->getMessage()],
            ]]);
        }
    }

    public function prepareProjectAction(string $code, string $action): array
    {
        $project = $this->findProject($code);
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['complete', 'cancel', 'delete'], true)) {
            throw new \RuntimeException('Unknown project action');
        }

        $issuer = $this->Config->issuer();
        if (!$issuer) {
            throw new \RuntimeException('CROWD_STELLAR_ACCOUNT_ID is not configured');
        }

        $is_active = !($project['is_closed'] ?? false);
        $is_funded = bccomp($project['funded_amount'] ?? '0', $project['target_amount'] ?? '0', self::SCALE) >= 0
            && bccomp($project['target_amount'] ?? '0', '0', self::SCALE) > 0;
        if ($action === 'complete' && (!$is_active || !$is_funded)) {
            throw new \RuntimeException('Project is not ready to complete');
        }
        if ($action === 'cancel' && !$is_active) {
            throw new \RuntimeException('Only active projects can be canceled');
        }

        $IssuerAccount = $this->Stellar->requestAccount($issuer);
        $Transaction = new TransactionBuilder($IssuerAccount);
        $Transaction->setMaxOperationFee(10000);
        $memo = match ($action) {
            'complete' => 'Complete funding ',
            'cancel' => 'Cancel funding ',
            default => 'Delete funding ',
        };
        $Transaction->addMemo(Memo::text($memo . $project['code']));

        if ($action === 'complete') {
            foreach ($this->buildFinishFundingOperations($project, false) as $Operation) {
                $Transaction->addOperation($Operation);
            }
            $upload = $this->uploadFinalProjectMetadata($project, 'completed');
            $Transaction->addOperation(
                (new ManageDataOperationBuilder('ipfshash-P' . $project['code'], $upload['cid']))->build()
            );
        } elseif ($action === 'cancel') {
            foreach ($this->buildFinishFundingOperations($project, true) as $Operation) {
                $Transaction->addOperation($Operation);
            }
            $upload = $this->uploadFinalProjectMetadata($project, 'canceled');
            $Transaction->addOperation(
                (new ManageDataOperationBuilder('ipfshash-P' . $project['code'], $upload['cid']))->build()
            );
        } else {
            if ($is_active) {
                foreach ($this->buildFinishFundingOperations($project, true) as $Operation) {
                    $Transaction->addOperation($Operation);
                }
            }
            foreach ($this->buildDeleteProjectOperations($project, $is_active) as $Operation) {
                $Transaction->addOperation($Operation);
            }
            $upload = null;
        }

        return [
            'project' => $project,
            'action' => $action,
            'signing_xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
            'signing_description' => ucfirst($action) . ' crowd project ' . $project['code'],
            'upload' => $upload ?? null,
        ];
    }

    public function prepareDonation(string $code, string $donor_account_id, string $amount): array
    {
        $project = $this->findProject($code);
        if (!$project) {
            return $this->donationPreparationResult(null, null, [[
                'key' => 'crowd_page.support.errors.project_not_found',
                'params' => [],
            ]]);
        }

        $amount = str_replace(',', '.', trim($amount));
        $errors = $this->validateDonation($project, $donor_account_id, $amount);
        if ($errors) {
            return $this->donationPreparationResult($project, null, $errors);
        }

        try {
            $DonorAccount = $this->Stellar->requestAccount($donor_account_id);
            $amount = $this->decimal($amount);
            $payment = $this->selectDonationPayment($DonorAccount, $project, $amount);
            $Transaction = $this->buildDonationTransaction($DonorAccount, $project, $amount, $payment);

            return [
                'project' => $project,
                'errors' => [],
                'payment' => $payment,
                'amount' => $amount,
                'signing_xdr' => $Transaction->build()->toEnvelopeXdrBase64(),
                'signing_description' => 'Support funding ' . $project['code'],
            ];
        } catch (Throwable $Exception) {
            $error = match ($Exception->getMessage()) {
                'crowd_no_payment_asset' => ['key' => 'crowd_page.support.errors.no_payment_asset', 'params' => []],
                'crowd_reserve_missing' => ['key' => 'crowd_page.support.errors.reserve_missing', 'params' => []],
                default => [
                    'key' => 'crowd_page.support.errors.prepare_failed',
                    'params' => ['%message%' => $Exception->getMessage()],
                ],
            };

            return $this->donationPreparationResult($project, null, [$error]);
        }
    }

    private function donationPreparationResult(?array $project, ?array $payment, array $errors): array
    {
        return [
            'project' => $project,
            'errors' => $errors,
            'payment' => $payment,
            'amount' => null,
            'signing_xdr' => null,
            'signing_description' => null,
        ];
    }

    private function validateDonation(array $project, string $donor_account_id, string $amount): array
    {
        $errors = [];
        if (($project['is_closed'] ?? false) || bccomp($project['remaining_amount'] ?? '0', '0', self::SCALE) <= 0) {
            $errors[] = ['key' => 'crowd_page.support.errors.closed', 'params' => []];
        }
        if (!BSN::validateStellarAccountIdFormat($donor_account_id)) {
            $errors[] = ['key' => 'crowd_page.support.errors.account_invalid', 'params' => []];
        }
        if (!preg_match('/\A(?:0|[1-9]\d*)(?:\.\d{1,7})?\z/', $amount) || bccomp($amount, '0', self::SCALE) <= 0) {
            $errors[] = ['key' => 'crowd_page.support.errors.amount_invalid', 'params' => []];
        } elseif (bccomp($amount, $project['remaining_amount'] ?? '0', self::SCALE) > 0) {
            $errors[] = [
                'key' => 'crowd_page.support.errors.amount_too_high',
                'params' => ['%amount%' => $this->shortAmount((string) ($project['remaining_amount'] ?? '0'))],
            ];
        }

        return $errors;
    }

    private function selectDonationPayment(AccountResponse $DonorAccount, array $project, string $amount): array
    {
        $crowd_token = $this->Config->mtlCrowdToken();
        $crowd_asset = $this->mtlCrowdAsset();
        $crowd_balance = $this->findBalanceForAsset($DonorAccount, $crowd_asset);
        if ($crowd_balance !== null && bccomp($this->availableCreditBalance($crowd_balance), $amount, self::SCALE) >= 0) {
            $this->validateDonationReserve($DonorAccount, $project, $crowd_asset, '0.0000000');

            return [
                'mode' => 'direct',
                'asset' => $crowd_asset,
                'token' => $crowd_token + ['is_known' => true],
                'send_max' => $amount,
                'source_amount' => $amount,
                'path' => [],
            ];
        }

        $candidates = $this->paymentCandidates($DonorAccount);
        $paths = $this->fetchStrictReceivePaths(
            array_map(static fn(array $candidate): Asset => $candidate['asset'], $candidates),
            $crowd_asset,
            $amount
        );
        foreach ($candidates as $candidate) {
            $asset = $candidate['asset'];
            $path = $paths[$this->horizonAssetCanonical($asset)] ?? null;
            if ($path === null || bccomp($candidate['available'], '0', self::SCALE) <= 0) {
                continue;
            }

            $send_max = $this->addSlippage((string) $path['source_amount']);
            if (bccomp($send_max, $candidate['available'], self::SCALE) > 0) {
                continue;
            }

            $this->validateDonationReserve($DonorAccount, $project, $asset, $send_max);

            return [
                'mode' => 'path',
                'asset' => $asset,
                'token' => $candidate['token'],
                'send_max' => $send_max,
                'source_amount' => $this->decimal($path['source_amount']),
                'path' => $path['path'],
            ];
        }

        throw new \RuntimeException('crowd_no_payment_asset');
    }

    private function buildDonationTransaction(AccountResponse $DonorAccount, array $project, string $amount, array $payment): TransactionBuilder
    {
        $donor_account_id = $DonorAccount->getAccountId();
        $issuer = (string) $this->Config->issuer();
        $funding_asset = Asset::createNonNativeAsset('C' . $project['code'], $issuer);
        $crowd_asset = $this->mtlCrowdAsset();
        $Transaction = new TransactionBuilder($DonorAccount);
        $Transaction->setMaxOperationFee(10000);
        $Transaction->addMemo(Memo::text('Support funding ' . $project['code']));

        if (($payment['mode'] ?? '') === 'path' && $this->findBalanceForAsset($DonorAccount, $crowd_asset) === null) {
            $Transaction->addOperation((new ChangeTrustOperationBuilder($crowd_asset))->build());
        }

        if (($payment['mode'] ?? '') === 'path') {
            $PathPayment = new PathPaymentStrictReceiveOperationBuilder(
                $payment['asset'],
                $payment['send_max'],
                $donor_account_id,
                $crowd_asset,
                $amount
            );
            $PathPayment->setPath($payment['path']);
            $Transaction->addOperation($PathPayment->build());
        }

        if ($this->findBalanceForAsset($DonorAccount, $funding_asset) === null) {
            $Transaction->addOperation((new ChangeTrustOperationBuilder($funding_asset))->build());
        }

        $Transaction->addOperation((new ManageBuyOfferOperationBuilder($crowd_asset, $funding_asset, $amount, '1'))->build());
        $Transaction->addOperation((new CreateClaimableBalanceOperationBuilder([
            new Claimant($issuer, Claimant::predicateUnconditional()),
            new Claimant($donor_account_id, Claimant::predicateUnconditional()),
        ], $funding_asset, $amount))->build());

        return $Transaction;
    }

    private function buildUpdateFundingOfferOperations(array $project, string $new_target_amount): array
    {
        $issuer = (string) $this->Config->issuer();
        $code = (string) ($project['code'] ?? '');
        $funding_asset = Asset::createNonNativeAsset('C' . $code, $issuer);
        $mtlcrowd = $this->mtlCrowdAsset();
        $funded_amount = $this->decimal($project['funded_amount'] ?? '0');
        $remaining_to_sell = bccomp($new_target_amount, $funded_amount, self::SCALE) > 0
            ? bcsub($new_target_amount, $funded_amount, self::SCALE)
            : '0.0000000';
        $offers = array_values(array_filter(
            $this->activeOffersForSellingAsset($funding_asset, $issuer),
            fn(OfferResponse $Offer): bool => $this->assetEquals($Offer->getBuying(), $mtlcrowd)
        ));

        if (!$offers && bccomp($remaining_to_sell, '0', self::SCALE) > 0) {
            return [
                (new ManageSellOfferOperationBuilder($funding_asset, $mtlcrowd, $remaining_to_sell, '1'))->build(),
            ];
        }

        $operations = [];
        foreach ($offers as $index => $Offer) {
            $amount = $index === 0 && bccomp($remaining_to_sell, '0', self::SCALE) > 0 ? $remaining_to_sell : '0';
            $operations[] = (new ManageSellOfferOperationBuilder(
                $Offer->getSelling(),
                $Offer->getBuying(),
                $amount,
                '1'
            ))
                ->setOfferId((int) $Offer->getOfferId())
                ->build();
        }

        return $operations;
    }

    private function validateDonationReserve(AccountResponse $DonorAccount, array $project, Asset $send_asset, string $send_max): void
    {
        $new_entries = 1; // claimable balance
        $issuer = (string) $this->Config->issuer();
        if ($this->findBalanceForAsset($DonorAccount, Asset::createNonNativeAsset('C' . $project['code'], $issuer)) === null) {
            $new_entries++;
        }
        if ($this->findBalanceForAsset($DonorAccount, $this->mtlCrowdAsset()) === null) {
            $new_entries++;
        }

        $base_reserve = $this->ReserveCalculator->fetchBaseReserveXlm();
        $available = $this->ReserveCalculator->calculateAvailableXlm($DonorAccount, $base_reserve);
        if ($send_asset->getType() === Asset::TYPE_NATIVE) {
            $available = bcsub($available, $send_max, self::SCALE);
        }
        $required = $this->ReserveCalculator->requiredReserveForNewEntries($new_entries, $base_reserve);

        if (bccomp($required, $available, self::SCALE) > 0) {
            throw new \RuntimeException('crowd_reserve_missing');
        }
    }

    private function paymentCandidates(AccountResponse $Account): array
    {
        $allowed_codes = [];
        foreach ($this->Config->paymentCurrencies() as $index => $code) {
            $allowed_codes[strtoupper($code)] = $index;
        }

        $candidates = [];
        foreach ($this->Config->paymentCurrencies() as $code) {
            if (strcasecmp($code, 'XLM') === 0 || strcasecmp($code, 'native') === 0) {
                $asset = Asset::native();
                $available = $this->availableBalance($Account, $asset, $this->findBalanceForAsset($Account, $asset));
                if (bccomp($available, '0', self::SCALE) <= 0) {
                    continue;
                }
                $candidates[$this->horizonAssetCanonical($asset)] = [
                    'asset' => $asset,
                    'available' => $available,
                    'token' => [
                        'code' => 'XLM',
                        'issuer' => null,
                        'is_known' => true,
                        'url' => '/tokens/XLM',
                    ],
                ];
                continue;
            }
        }

        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse || $Balance->getAssetType() === Asset::TYPE_NATIVE) {
                continue;
            }
            $code = (string) $Balance->getAssetCode();
            if (!array_key_exists(strtoupper($code), $allowed_codes)) {
                continue;
            }
            if ($Balance->getIsAuthorized() === false) {
                continue;
            }

            $available = $this->availableCreditBalance($Balance);
            if (bccomp($available, '0', self::SCALE) <= 0) {
                continue;
            }

            $issuer = (string) $Balance->getAssetIssuer();
            $asset = Asset::createNonNativeAsset($code, $issuer);
            $known = $this->TokensController->searchKnownTokenByCode($code);
            $is_known = $known !== null && ($known['issuer'] ?? null) === $issuer;
            $candidates[$this->horizonAssetCanonical($asset)] = [
                'asset' => $asset,
                'available' => $available,
                'token' => [
                    'code' => $code,
                    'issuer' => $issuer,
                    'is_known' => $is_known,
                ],
            ];
        }

        uasort($candidates, function (array $a, array $b) use ($allowed_codes): int {
            $a_code = strtoupper((string) ($a['token']['code'] ?? ''));
            $b_code = strtoupper((string) ($b['token']['code'] ?? ''));

            return ($allowed_codes[$a_code] ?? PHP_INT_MAX) <=> ($allowed_codes[$b_code] ?? PHP_INT_MAX);
        });

        return array_values($candidates);
    }

    private function availableBalance(AccountResponse $Account, Asset $asset, ?AccountBalanceResponse $Balance): string
    {
        if ($asset->getType() === Asset::TYPE_NATIVE) {
            return $this->ReserveCalculator->calculateAvailableXlm($Account);
        }
        if ($Balance === null || $Balance->getIsAuthorized() === false) {
            return '0.0000000';
        }

        return $this->availableCreditBalance($Balance);
    }

    private function availableCreditBalance(AccountBalanceResponse $Balance): string
    {
        return bcsub($Balance->getBalance(), $Balance->getSellingLiabilities() ?? '0.0000000', self::SCALE);
    }

    private function findBalanceForAsset(AccountResponse $Account, Asset $asset): ?AccountBalanceResponse
    {
        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (!$Balance instanceof AccountBalanceResponse) {
                continue;
            }
            if ($this->balanceMatchesAsset($Balance, $asset)) {
                return $Balance;
            }
        }

        return null;
    }

    private function balanceMatchesAsset(AccountBalanceResponse $Balance, Asset $asset): bool
    {
        if ($asset->getType() === Asset::TYPE_NATIVE) {
            return $Balance->getAssetType() === Asset::TYPE_NATIVE;
        }
        if (!$asset instanceof AssetTypeCreditAlphanum) {
            return false;
        }

        return $Balance->getAssetCode() === $asset->getCode()
            && $Balance->getAssetIssuer() === $asset->getIssuer();
    }

    private function assetEquals(Asset $a, Asset $b): bool
    {
        if ($a->getType() !== $b->getType()) {
            return false;
        }
        if ($a->getType() === Asset::TYPE_NATIVE) {
            return true;
        }
        if (!$a instanceof AssetTypeCreditAlphanum || !$b instanceof AssetTypeCreditAlphanum) {
            return false;
        }

        return $a->getCode() === $b->getCode() && $a->getIssuer() === $b->getIssuer();
    }

    private function assetFromToken(array $token): Asset
    {
        if (($token['code'] ?? '') === 'XLM' && empty($token['issuer'])) {
            return Asset::native();
        }

        return Asset::createNonNativeAsset((string) $token['code'], (string) $token['issuer']);
    }

    private function fetchStrictReceivePaths(array $send_assets, AssetTypeCreditAlphanum $dest_asset, string $dest_amount): array
    {
        $source_assets = array_values(array_unique(array_map(
            fn(Asset $asset): string => $this->horizonAssetCanonical($asset),
            $send_assets
        )));
        if (!$source_assets) {
            return [];
        }

        try {
            $response = $this->fetchHorizonJson('/paths/strict-receive', [
                'destination_asset_type' => $dest_asset->getType(),
                'destination_asset_code' => $dest_asset->getCode(),
                'destination_asset_issuer' => $dest_asset->getIssuer(),
                'destination_amount' => $dest_amount,
                'source_assets' => implode(',', $source_assets),
            ]);
        } catch (Throwable) {
            return [];
        }

        $best = [];
        foreach (($response['_embedded']['records'] ?? []) as $record) {
            if (!is_array($record) || !isset($record['source_amount'])) {
                continue;
            }
            $key = $this->horizonRecordSourceAssetCanonical($record);
            if ($key === null) {
                continue;
            }
            if (!isset($best[$key]) || bccomp((string) $record['source_amount'], (string) $best[$key]['source_amount'], self::SCALE) < 0) {
                $best[$key] = [
                    'source_amount' => $this->decimal($record['source_amount']),
                    'path' => array_map(fn(array $asset): Asset => $this->assetFromHorizonPathItem($asset), $record['path'] ?? []),
                ];
            }
        }

        return $best;
    }

    private function fetchHorizonJson(string $path, array $query): array
    {
        $base_url = rtrim((string) ($_ENV['STELLAR_HORIZON_ENDPOINT'] ?? 'https://horizon.stellar.org'), '/');
        $Response = $this->httpClient()->get($base_url . $path, [
            'query' => $query,
            'http_errors' => false,
        ]);
        if ($Response->getStatusCode() >= 400) {
            throw new \RuntimeException('Horizon paths request failed: HTTP ' . $Response->getStatusCode());
        }

        $json = json_decode((string) $Response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($json) ? $json : [];
    }

    private function httpClient(): Client
    {
        if ($this->HttpClient === null) {
            $this->HttpClient = new Client([
                'timeout' => 6,
                'connect_timeout' => 3,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'BSN Viewer crowd',
                ],
            ]);
        }

        return $this->HttpClient;
    }

    private function horizonAssetCanonical(Asset $asset): string
    {
        if ($asset->getType() === Asset::TYPE_NATIVE) {
            return 'native';
        }
        if (!$asset instanceof AssetTypeCreditAlphanum) {
            throw new \RuntimeException('Unsupported asset type');
        }

        return $asset->getCode() . ':' . $asset->getIssuer();
    }

    private function assetFromHorizonPathItem(array $asset): Asset
    {
        if (($asset['asset_type'] ?? '') === Asset::TYPE_NATIVE) {
            return Asset::native();
        }

        return Asset::createNonNativeAsset((string) $asset['asset_code'], (string) $asset['asset_issuer']);
    }

    private function horizonRecordSourceAssetCanonical(array $record): ?string
    {
        if (($record['source_asset_type'] ?? '') === Asset::TYPE_NATIVE) {
            return 'native';
        }
        if (!isset($record['source_asset_code'], $record['source_asset_issuer'])) {
            return null;
        }

        return $record['source_asset_code'] . ':' . $record['source_asset_issuer'];
    }

    private function addSlippage(string $amount): string
    {
        $multiplier = bcadd('1', bcdiv(self::DONATION_SLIPPAGE_PERCENT, '100', self::SCALE), self::SCALE);
        $value = bcmul($amount, $multiplier, self::SCALE + 1);
        $factor = 10 ** self::SCALE;

        return number_format(ceil((float) $value * $factor) / $factor, self::SCALE, '.', '');
    }

    private function shortAmount(string $amount): string
    {
        return rtrim(rtrim($this->decimal($amount), '0'), '.');
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

    private function validateCreateValues(array $values, bool $is_edit = false): array
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
        if (!$is_edit && $values['deadline'] !== '') {
            $Deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['deadline']);
            $date_errors = \DateTimeImmutable::getLastErrors();
            if (!$Deadline || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))) {
                $errors[] = ['key' => 'crowd_create.errors.deadline_invalid', 'params' => []];
            } elseif ($Deadline < new \DateTimeImmutable('today')) {
                $errors[] = ['key' => 'crowd_create.errors.deadline_past', 'params' => []];
            }
        } elseif ($is_edit && $values['deadline'] !== '') {
            $Deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['deadline']);
            $date_errors = \DateTimeImmutable::getLastErrors();
            if (!$Deadline || ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))) {
                $errors[] = ['key' => 'crowd_create.errors.deadline_invalid', 'params' => []];
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
        $mtlcrowd = $this->mtlCrowdAsset();
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

    private function uploadFinalProjectMetadata(array $project, string $status): array
    {
        $supporters = array_map(static fn(array $supporter): array => [
            'account_id' => (string) ($supporter['account']['id'] ?? ''),
            'amount' => (string) ($supporter['amount'] ?? '0'),
        ], $project['supporters'] ?? []);

        $metadata = [
            'name' => (string) ($project['name'] ?? ''),
            'code' => (string) ($project['code'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
            'fulldescription' => base64_encode((string) ($project['full_description'] ?? '')),
            'contact_account_id' => (string) ($project['contact_account']['id'] ?? ''),
            'project_account_id' => (string) ($project['project_account']['id'] ?? ''),
            'target_amount' => (string) ($project['target_amount'] ?? '0.0000000'),
            'deadline' => (string) ($project['deadline'] ?? ''),
            'funding_status' => $status,
            'funded_amount' => (string) ($project['funded_amount'] ?? '0.0000000'),
            'remaining_amount' => (string) ($project['remaining_amount'] ?? '0.0000000'),
            'supporters_count' => count($supporters),
            'supporters' => $supporters,
        ];

        return $this->IpfsClient->uploadProjectJson($metadata, (string) ($project['code'] ?? ''));
    }

    private function buildFinishFundingOperations(array $project, bool $refund): array
    {
        $issuer = (string) $this->Config->issuer();
        $code = (string) ($project['code'] ?? '');
        $asset = Asset::createNonNativeAsset('C' . $code, $issuer);
        $claimable_balances = $this->claimableBalancesForAsset($asset, $issuer);
        $holders = $this->tokenHolders($asset, $issuer);
        $operations = [];

        foreach ($claimable_balances as $ClaimableBalance) {
            $operations[] = (new ClaimClaimableBalanceOperationBuilder($ClaimableBalance->getBalanceId()))->build();
        }

        foreach ($holders as $holder) {
            $operations[] = (new ClawbackOperationBuilder($asset, MuxedAccount::fromAccountId($holder['account_id']), $holder['amount']))->build();
        }

        if ($refund) {
            foreach ($this->refundAmounts($claimable_balances, $holders) as $account_id => $amount) {
                if (bccomp($amount, '0', self::SCALE) > 0) {
                    $operations[] = (new PaymentOperationBuilder($account_id, $this->mtlCrowdAsset(), $amount))->build();
                }
            }
        } else {
            $total = '0.0000000';
            foreach ($claimable_balances as $ClaimableBalance) {
                $total = bcadd($total, $this->decimal($ClaimableBalance->getAmount()), self::SCALE);
            }
            foreach ($holders as $holder) {
                $total = bcadd($total, $holder['amount'], self::SCALE);
            }

            $project_account_id = (string) ($project['project_account']['id'] ?? '');
            if ($project_account_id !== '' && bccomp($total, '0', self::SCALE) > 0) {
                if ($this->accountHasTrustline($project_account_id, $this->mtlCrowdAsset())) {
                    $operations[] = (new PaymentOperationBuilder($project_account_id, $this->mtlCrowdAsset(), $total))->build();
                } else {
                    $operations[] = (new CreateClaimableBalanceOperationBuilder([
                        new Claimant($project_account_id, Claimant::predicateUnconditional()),
                    ], $this->mtlCrowdAsset(), $total))->build();
                }
            }
        }

        foreach ($this->activeOffersForSellingAsset($asset, $issuer) as $Offer) {
            $operations[] = (new ManageSellOfferOperationBuilder(
                $Offer->getSelling(),
                $Offer->getBuying(),
                '0',
                $Offer->getPrice()
            ))
                ->setOfferId((int) $Offer->getOfferId())
                ->build();
        }

        return $operations;
    }

    private function buildDeleteProjectOperations(array $project, bool $funding_already_cleaned): array
    {
        $issuer = (string) $this->Config->issuer();
        $code = (string) ($project['code'] ?? '');
        $operations = [
            (new ManageDataOperationBuilder('ipfshash-P' . $code, null))->build(),
        ];

        $project_asset = Asset::createNonNativeAsset('P' . $code, $issuer);
        foreach ($this->claimableBalancesForAsset($project_asset, $issuer) as $ClaimableBalance) {
            $operations[] = (new ClaimClaimableBalanceOperationBuilder($ClaimableBalance->getBalanceId()))->build();
        }
        foreach ($this->tokenHolders($project_asset, $issuer) as $holder) {
            $operations[] = (new ClawbackOperationBuilder($project_asset, MuxedAccount::fromAccountId($holder['account_id']), $holder['amount']))->build();
        }

        if (!$funding_already_cleaned) {
            $funding_asset = Asset::createNonNativeAsset('C' . $code, $issuer);
            foreach ($this->claimableBalancesForAsset($funding_asset, $issuer) as $ClaimableBalance) {
                $operations[] = (new ClaimClaimableBalanceOperationBuilder($ClaimableBalance->getBalanceId()))->build();
            }
            foreach ($this->tokenHolders($funding_asset, $issuer) as $holder) {
                $operations[] = (new ClawbackOperationBuilder($funding_asset, MuxedAccount::fromAccountId($holder['account_id']), $holder['amount']))->build();
            }
            foreach ($this->activeOffersForSellingAsset($funding_asset, $issuer) as $Offer) {
                $operations[] = (new ManageSellOfferOperationBuilder(
                    $Offer->getSelling(),
                    $Offer->getBuying(),
                    '0',
                    $Offer->getPrice()
                ))
                    ->setOfferId((int) $Offer->getOfferId())
                    ->build();
            }
        }

        return $operations;
    }

    private function claimableBalancesForAsset(AssetTypeCreditAlphanum $asset, string $issuer): array
    {
        $balances = [];
        $Page = $this->Stellar->claimableBalances()->forAsset($asset)->limit(200)->execute();
        while ($Page && $Page->getClaimableBalances()->count()) {
            foreach ($Page->getClaimableBalances()->toArray() as $ClaimableBalance) {
                if (!$ClaimableBalance instanceof ClaimableBalanceResponse) {
                    continue;
                }

                $claimants = array_map(
                    static fn(ClaimantResponse $Claimant): string => $Claimant->getDestination(),
                    $ClaimableBalance->getClaimants()->toArray()
                );
                if (in_array($issuer, $claimants, true)) {
                    $balances[] = $ClaimableBalance;
                }
            }

            $Page = $Page->getNextPage();
        }

        return $balances;
    }

    private function tokenHolders(AssetTypeCreditAlphanum $asset, string $issuer): array
    {
        $holders = [];
        $Page = $this->Stellar->accounts()->forAsset($asset)->limit(200)->execute();
        while ($Page && $Page->getAccounts()->count()) {
            foreach ($Page->getAccounts()->toArray() as $Account) {
                if (!$Account instanceof AccountResponse || $Account->getAccountId() === $issuer) {
                    continue;
                }
                foreach ($Account->getBalances()->toArray() as $Balance) {
                    if (!$Balance instanceof AccountBalanceResponse) {
                        continue;
                    }
                    if (
                        $Balance->getAssetCode() === $asset->getCode()
                        && $Balance->getAssetIssuer() === $asset->getIssuer()
                        && bccomp($Balance->getBalance(), '0', self::SCALE) > 0
                    ) {
                        $holders[] = [
                            'account_id' => $Account->getAccountId(),
                            'amount' => $this->decimal($Balance->getBalance()),
                        ];
                    }
                }
            }

            $Page = $Page->getNextPage();
        }

        return $holders;
    }

    private function activeOffersForSellingAsset(AssetTypeCreditAlphanum $asset, string $issuer): array
    {
        $offers = [];
        $Page = $this->Stellar->offers()->forSeller($issuer)->forSellingAsset($asset)->limit(200)->execute();
        while ($Page && $Page->getOffers()->count()) {
            foreach ($Page->getOffers()->toArray() as $Offer) {
                if (
                    $Offer instanceof OfferResponse
                    && $Offer->getSeller() === $issuer
                    && $this->assetEquals($Offer->getSelling(), $asset)
                ) {
                    $offers[] = $Offer;
                }
            }

            $Page = $Page->getNextPage();
        }

        return $offers;
    }

    private function refundAmounts(array $claimable_balances, array $holders): array
    {
        $refunds = [];
        foreach ($claimable_balances as $ClaimableBalance) {
            if (!$ClaimableBalance instanceof ClaimableBalanceResponse) {
                continue;
            }
            $sponsor = $ClaimableBalance->getSponsor();
            if (!$sponsor) {
                continue;
            }

            $refunds[$sponsor] = bcadd(
                $refunds[$sponsor] ?? '0.0000000',
                $this->decimal($ClaimableBalance->getAmount()),
                self::SCALE
            );
        }

        foreach ($holders as $holder) {
            $account_id = (string) ($holder['account_id'] ?? '');
            if ($account_id === '') {
                continue;
            }
            $refunds[$account_id] = bcadd($refunds[$account_id] ?? '0.0000000', $holder['amount'], self::SCALE);
        }

        return $refunds;
    }

    private function accountHasTrustline(string $account_id, AssetTypeCreditAlphanum $asset): bool
    {
        $Account = $this->Stellar->requestAccount($account_id);
        foreach ($Account->getBalances()->toArray() as $Balance) {
            if (
                $Balance instanceof AccountBalanceResponse
                && $Balance->getAssetCode() === $asset->getCode()
                && $Balance->getAssetIssuer() === $asset->getIssuer()
            ) {
                return true;
            }
        }

        return false;
    }

    private function mtlCrowdAsset(): AssetTypeCreditAlphanum
    {
        return Asset::createNonNativeAsset($this->Config->crowdTokenCode(), $this->Config->crowdTokenIssuer());
    }

    private function isDeadlineReached(string $deadline): bool
    {
        return $deadline !== '' && strtotime($deadline . ' 23:59:59') !== false && time() > strtotime($deadline . ' 23:59:59');
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
