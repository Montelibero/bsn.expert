<?php
namespace Montelibero\BSN\Controllers;

use DI\Container;
use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Operations\AccountMergeOperationResponse;
use Soneso\StellarSDK\Responses\Operations\BeginSponsoringFutureReservesOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ChangeTrustOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ClawbackOperationResponse;
use Soneso\StellarSDK\Responses\Operations\EndSponsoringFutureReservesOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageBuyOfferOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageDataOperationResponse;
use Soneso\StellarSDK\Responses\Operations\ManageSellOfferOperationResponse;
use Soneso\StellarSDK\Responses\Operations\CreatePassiveSellOfferResponse;
use Soneso\StellarSDK\Responses\Operations\OperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentStrictReceiveOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PathPaymentStrictSendOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\Responses\Operations\SetTrustlineFlagsOperationResponse;
use Soneso\StellarSDK\Responses\Operations\SetOptionsOperationResponse;
use Soneso\StellarSDK\Responses\Operations\CreateAccountOperationResponse;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class TransactionsController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;
    private ?array $known_tokens = null;
    private Container $Container;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Container $Container)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
        $this->Container = $Container;
    }

    public function Index(): ?string
    {
        $tx_hash = isset($_REQUEST['tx_hash']) ? trim((string)$_REQUEST['tx_hash']) : '';
        $error = null;

        if ($tx_hash && BSN::validateTransactionHashFormat($tx_hash)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => $tx_hash]));
            return null;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tx_hash) {
            $error = 'transactions.index.invalid_hash';
        }

        $Template = $this->Twig->load('transactions_index.twig');
        return $Template->render([
            'tx_hash' => $tx_hash,
            'error' => $error,
        ]);
    }

    public function Transaction(string $tx_hash): ?string
    {
        if (!BSN::validateTransactionHashFormat($tx_hash)) {
            SimpleRouter::response()->httpCode(400);
            return null;
        }

        if ($tx_hash !== strtolower($tx_hash)) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('transaction_page', ['tx_hash' => strtolower($tx_hash)]));
            return null;
        }

        $transaction = $this->fetchTransaction($tx_hash);
        if (!$transaction) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $Template = $this->Twig->load('transaction.twig');
        return $Template->render([
            'transaction' => $transaction,
            'tx_hash' => $transaction['hash'],
        ]);
    }

    private function fetchTransaction(string $tx_hash): ?array
    {
        $cache_key = 'transaction:' . strtolower($tx_hash) . ':2';
        $cached = apcu_fetch($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $Transaction = $this->Stellar->transactions()->transaction($tx_hash);
        } catch (HorizonRequestException) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        } catch (\Exception) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        }

        $Memo = $Transaction->getMemo();
        $memo_value = $Memo->valueAsString();
        $memo_type = $Memo->typeAsString();
        if (in_array($memo_type, ['hash', 'return'], true) && $memo_value !== null) {
            $decoded = base64_decode($memo_value, true);
            if ($decoded !== false) {
                $memo_value = bin2hex($decoded);
            }
        }
        $data = [
            'hash' => $Transaction->getHash(),
            'ledger' => $Transaction->getLedger(),
            'created_at' => $Transaction->getCreatedAt(),
            'successful' => $Transaction->isSuccessful(),
            'operation_count' => $Transaction->getOperationCount(),
            'fee_charged' => $Transaction->getFeeCharged(),
            'max_fee' => $Transaction->getMaxFee(),
            'fee_charged_xlm' => $this->stroopToXlmString($Transaction->getFeeCharged()),
            'max_fee_xlm' => $this->stroopToXlmString($Transaction->getMaxFee()),
            'source_account' => $Transaction->getSourceAccount(),
            'fee_account' => $Transaction->getFeeAccount(),
            'memo' => [
                'type' => $memo_type,
                'value' => $memo_value,
            ],
            'envelope_xdr' => $Transaction->getEnvelopeXdrBase64(),
            'operations' => $this->fetchOperations($tx_hash),
        ];

        $data['source_account_data'] = $this->BSN->makeAccountById($data['source_account'])->jsonSerialize();
        if ($data['fee_account']) {
            $data['fee_account_data'] = $this->BSN->makeAccountById($data['fee_account'])->jsonSerialize();
        }

        apcu_store($cache_key, $data, 60 * 5);

        return $data;
    }

    private function fetchOperations(string $tx_hash): ?array
    {
        $cache_key = 'transaction_ops:' . strtolower($tx_hash) . ':2';
        $cached = apcu_fetch($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $OperationsPage = $this->Stellar->operations()
                ->forTransaction($tx_hash)
                ->order('asc')
                ->limit(200)
                ->execute();
        } catch (HorizonRequestException) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        } catch (\Exception) {
            apcu_store($cache_key, null, 60 * 5);
            return null;
        }

        $operations = [];
        foreach ($OperationsPage->getOperations()->toArray() as $Operation) {
            $operations[] = $this->normalizeOperation($Operation);
        }

        apcu_store($cache_key, $operations, 60 * 5);

        return $operations;
    }

    private function normalizeOperation(OperationResponse $Operation): array
    {
        $type = $Operation->getHumanReadableOperationType();
        $base = [
            'id' => $Operation->getOperationId(),
            'type' => $type,
            'created_at' => $Operation->getCreatedAt(),
            'source_account' => $Operation->getSourceAccount(),
            'source_account_data' => $this->accountData($Operation->getSourceAccount()),
        ];

        return match ($type) {
            'create_account' => $base + $this->normalizeCreateAccount($Operation),
            'payment' => $base + $this->normalizePayment($Operation),
            'path_payment_strict_receive', 'path_payment_strict_send' => $base + $this->normalizePathPayment($Operation),
            'change_trust' => $base + $this->normalizeChangeTrust($Operation),
            'set_trustline_flags', 'set_trust_line_flags' => $base + $this->normalizeSetTrustlineFlags($Operation),
            'manage_sell_offer', 'manage_buy_offer', 'create_passive_sell_offer' => $base + $this->normalizeOffer($Operation),
            'account_merge' => $base + $this->normalizeAccountMerge($Operation),
            'begin_sponsoring_future_reserves' => $base + $this->normalizeBeginSponsoring($Operation),
            'end_sponsoring_future_reserves' => $base + $this->normalizeEndSponsoring($Operation),
            'manage_data' => $base + $this->normalizeManageData($Operation),
            'set_options' => $base + $this->normalizeSetOptions($Operation),
            'clawback' => $base + $this->normalizeClawback($Operation),
            default => $base + $this->normalizeUnsupported(),
        };
    }

    private function formatAsset(Asset $Asset): array
    {
        if ($Asset->getType() === Asset::TYPE_NATIVE) {
            return [
                'label' => 'XLM',
                'url' => '/tokens/XLM',
            ];
        }

        return $this->formatAssetByParts($Asset->getCode(), $Asset->getIssuer());
    }

    private function formatAssetByParts(string $code, string $issuer): array
    {
        $known = $this->getKnownToken($code, $issuer);
        if ($known) {
            return [
                'label' => $code,
                'url' => '/tokens/' . $code,
            ];
        }

        return [
            'label' => $code . '-' . $issuer,
            'url' => '/tokens/' . $code . '-' . $issuer,
        ];
    }

    private function accountData(?string $account_id): ?array
    {
        if (!$account_id) {
            return null;
        }

        return $this->BSN->makeAccountById($account_id)->jsonSerialize();
    }

    private function getKnownToken(string $code, string $issuer): ?array
    {
        if ($this->known_tokens === null) {
            $this->known_tokens = [];
            $cached = apcu_fetch('known_tokens');
            if (is_array($cached)) {
                foreach ($cached as $item) {
                    $key = ($item['code'] ?? '') . '-' . ($item['issuer'] ?? '');
                    $this->known_tokens[$key] = $item;
                }
            }
        }

        $key = $code . '-' . $issuer;
        return $this->known_tokens[$key] ?? null;
    }

    private function normalizeUnsupported(): array
    {
        return [
            'template' => 'operations/unsupported.twig',
            'unsupported' => true,
        ];
    }

    private function normalizeCreateAccount(OperationResponse $Operation): array
    {
        if (!$Operation instanceof CreateAccountOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/create_account.twig',
            'data' => [
                'funder' => $this->accountData($Operation->getFunder()),
                'account' => $this->accountData($Operation->getAccount()),
                'starting_balance' => $Operation->getStartingBalance(),
            ],
        ];
    }

    private function normalizePayment(OperationResponse $Operation): array
    {
        if (!$Operation instanceof PaymentOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/payment.twig',
            'data' => [
                'from' => $this->accountData($Operation->getFrom()),
                'to' => $this->accountData($Operation->getTo()),
                'amount' => $Operation->getAmount(),
                'asset' => $this->formatAsset($Operation->getAsset()),
            ],
        ];
    }

    private function normalizePathPayment(OperationResponse $Operation): array
    {
        if (!$Operation instanceof PathPaymentOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $path = [];
        foreach ($Operation->getPath()->toArray() as $AssetInPath) {
            $path[] = $this->formatAsset($AssetInPath);
        }

        $data = [
            'from' => $this->accountData($Operation->getFrom()),
            'to' => $this->accountData($Operation->getTo()),
            'dest_amount' => $Operation->getAmount(),
            'dest_asset' => $this->formatAsset($Operation->getAsset()),
            'source_amount' => $Operation->getSourceAmount(),
            'source_asset' => $this->formatAsset($Operation->getSourceAsset()),
            'path' => $path,
        ];

        if ($Operation instanceof PathPaymentStrictReceiveOperationResponse) {
            $data['source_max'] = $Operation->getSourceMax();
        }
        if ($Operation instanceof PathPaymentStrictSendOperationResponse) {
            $data['destination_min'] = $Operation->getDestinationMin();
        }

        return [
            'template' => 'operations/path_payment.twig',
            'data' => $data,
        ];
    }

    private function normalizeChangeTrust(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ChangeTrustOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset = $Operation->getAssetType() === Asset::TYPE_POOL_SHARE
            ? [
                'label' => $Operation->getLiquidityPoolId(),
                'url' => null,
            ]
            : $this->formatAssetByParts($Operation->getAssetCode(), $Operation->getAssetIssuer());

        $limit = $Operation->getLimit();
        $is_close = $limit !== null && (float) $limit == 0.0;
        $is_max = $limit !== null && in_array($limit, ['922337203685.4775807', '9223372036854775807'], true);

        return [
            'template' => 'operations/change_trust.twig',
            'data' => [
                'trustor' => $this->accountData($Operation->getTrustor()),
                'trustee' => $this->accountData($Operation->getTrustee()),
                'asset' => $asset,
                'limit' => ($is_close || $is_max) ? null : $limit,
                'action' => $is_close ? 'close' : 'open',
                'action_label' => $is_close
                    ? $this->Container->get(Translator::class)->trans('transactions.operations.change_trust.actions.close')
                    : $this->Container->get(Translator::class)->trans('transactions.operations.change_trust.actions.open'),
            ],
        ];
    }

    private function normalizeSetTrustlineFlags(OperationResponse $Operation): array
    {
        if (!$Operation instanceof SetTrustlineFlagsOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $asset = $this->formatAsset(Asset::create($Operation->getAssetType(), $Operation->getAssetCode(), $Operation->getAssetIssuer()));

        return [
            'template' => 'operations/set_trustline_flags.twig',
            'data' => [
                'trustor' => $this->accountData($Operation->getTrustor()),
                'asset' => $asset,
                'set_flags' => $Operation->getSetFlagsS() ?? $Operation->getSetFlags(),
                'clear_flags' => $Operation->getClearFlagsS() ?? $Operation->getClearFlags(),
            ],
        ];
    }

    private function normalizeOffer(OperationResponse $Operation): array
    {
        $template = 'operations/manage_offer.twig';

        if ($Operation instanceof ManageSellOfferOperationResponse) {
            $mode = 'manage_sell_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = $Operation->getOfferId();
        } elseif ($Operation instanceof ManageBuyOfferOperationResponse) {
            $mode = 'manage_buy_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = $Operation->getOfferId();
        } elseif ($Operation instanceof CreatePassiveSellOfferResponse) {
            $mode = 'create_passive_sell_offer';
            $amount = $Operation->getAmount();
            $price = $Operation->getPrice();
            $selling = $Operation->getSellingAsset();
            $buying = $Operation->getBuyingAsset();
            $offer_id = null;
        } else {
            return $this->normalizeUnsupported();
        }

        $action_key = 'manage_offer.create';
        $is_delete = false;
        if ($offer_id) {
            $is_delete = ((float)$amount === 0.0);
            $action_key = $is_delete ? 'manage_offer.delete' : 'manage_offer.update';
        }

        return [
            'template' => $template,
            'data' => [
                'action_label' => $this->Container->get(Translator::class)->trans('transactions.operations.offer_actions.' . $action_key),
                'selling' => $this->formatAsset($selling),
                'buying' => $this->formatAsset($buying),
                'amount' => $is_delete ? null : $amount,
                'price' => $is_delete ? null : $price,
                'offer_id' => $offer_id,
            ],
        ];
    }

    private function normalizeAccountMerge(OperationResponse $Operation): array
    {
        if (!$Operation instanceof AccountMergeOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/account_merge.twig',
            'data' => [
                'account' => $this->accountData($Operation->getAccount()),
                'into' => $this->accountData($Operation->getInto()),
            ],
        ];
    }

    private function normalizeBeginSponsoring(OperationResponse $Operation): array
    {
        if (!$Operation instanceof BeginSponsoringFutureReservesOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/begin_sponsoring.twig',
            'data' => [
                'sponsored' => $this->accountData($Operation->getSponsoredId()),
            ],
        ];
    }

    private function normalizeEndSponsoring(OperationResponse $Operation): array
    {
        if (!$Operation instanceof EndSponsoringFutureReservesOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/end_sponsoring.twig',
            'data' => [
                'begin_sponsor' => $this->accountData($Operation->getBeginSponsor()),
            ],
        ];
    }

    private function normalizeManageData(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ManageDataOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $value = $Operation->getValue();
        $decoded_value = null;
        if ($value !== null) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false && $this->isLikelyText($decoded)) {
                $decoded_value = $decoded;
            }
        }

        $is_cleared = $value === null || $value === '';

        $action_label = $is_cleared
            ? $this->Container->get(Translator::class)->trans('transactions.operations.manage_data.delete_title')
            : null;

        return [
            'template' => 'operations/manage_data.twig',
            'data' => [
                'name' => $Operation->getName(),
                'value_raw' => $value,
                'decoded_value' => $decoded_value,
                'cleared' => $is_cleared,
                'action_label' => $action_label,
            ],
        ];
    }

    private function isLikelyText(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        // Allow printable plus whitespace \r\n\t
        return !preg_match('/[^\P{C}\r\n\t]/u', $value);
    }

    private function normalizeClawback(OperationResponse $Operation): array
    {
        if (!$Operation instanceof ClawbackOperationResponse) {
            return $this->normalizeUnsupported();
        }

        return [
            'template' => 'operations/clawback.twig',
            'data' => [
                'from' => $this->accountData($Operation->getFrom()),
                'asset' => $this->formatAsset($Operation->getAsset()),
                'amount' => $Operation->getAmount(),
            ],
        ];
    }

    private function stroopToXlmString(?int $stroops): ?string
    {
        if ($stroops === null) {
            return null;
        }

        return number_format($stroops * 0.0000001, 7, '.', '');
    }

    private function normalizeSetOptions(OperationResponse $Operation): array
    {
        if (!$Operation instanceof SetOptionsOperationResponse) {
            return $this->normalizeUnsupported();
        }

        $signer_key = $Operation->getSignerKey();
        $signer_weight = $Operation->getSignerWeight();
        $signer = null;
        $signer_action = null;
        if ($signer_key !== null) {
            $signer = [
                'key' => $signer_key,
                'account' => $this->BSN::validateStellarAccountIdFormat($signer_key)
                    ? $this->accountData($signer_key)
                    : null,
                'weight' => $signer_weight,
            ];
            if ($signer_weight === 0) {
                $signer_action = 'remove';
            } elseif ($signer_weight !== null) {
                $signer_action = 'upsert';
            }
        }

        $thresholds = [];
        if ($Operation->getLowThreshold() !== null) {
            $thresholds['low'] = $Operation->getLowThreshold();
        }
        if ($Operation->getMedThreshold() !== null) {
            $thresholds['med'] = $Operation->getMedThreshold();
        }
        if ($Operation->getHighThreshold() !== null) {
            $thresholds['high'] = $Operation->getHighThreshold();
        }

        $flags = [
            'set' => $Operation->getSetFlagsS() ?? $Operation->getSetFlags(),
            'clear' => $Operation->getClearFlagsS() ?? $Operation->getClearFlags(),
        ];

        return [
            'template' => 'operations/set_options.twig',
            'data' => [
                'signer' => $signer,
                'signer_action' => $signer_action,
                'master_weight' => $Operation->getMasterKeyWeight(),
                'thresholds' => $thresholds,
                'home_domain' => $Operation->getHomeDomain(),
                'inflation_destination' => $this->accountData($Operation->getInflationDestination()),
                'flags' => $flags,
            ],
        ];
    }
}
