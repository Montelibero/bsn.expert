<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AccountMergeOperation;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypePoolShare;
use Soneso\StellarSDK\BeginSponsoringFutureReservesOperation;
use Soneso\StellarSDK\ChangeTrustOperation;
use Soneso\StellarSDK\Claimant;
use Soneso\StellarSDK\ClaimClaimableBalanceOperation;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\CreateClaimableBalanceOperation;
use Soneso\StellarSDK\CreatePassiveSellOfferOperation;
use Soneso\StellarSDK\Crypto\CryptoKeyType;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\EndSponsoringFutureReservesOperation;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageBuyOfferOperation;
use Soneso\StellarSDK\ManageSellOfferOperation;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperation;
use Soneso\StellarSDK\PathPaymentStrictSendOperation;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\RevokeSponsorshipOperation;
use Soneso\StellarSDK\SetOptionsOperation;
use Soneso\StellarSDK\Xdr\XdrBuffer;
use Soneso\StellarSDK\Xdr\XdrDecoratedSignature;
use Soneso\StellarSDK\Xdr\XdrEncoder;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrMemo;
use Soneso\StellarSDK\Xdr\XdrMuxedAccount;
use Soneso\StellarSDK\Xdr\XdrMuxedAccountMed25519;
use Soneso\StellarSDK\Xdr\XdrOperation;
use Soneso\StellarSDK\Xdr\XdrOperationType;
use Soneso\StellarSDK\Xdr\XdrPreconditions;
use Soneso\StellarSDK\Xdr\XdrPreconditionType;
use Soneso\StellarSDK\Xdr\XdrSequenceNumber;
use Soneso\StellarSDK\Xdr\XdrSignerKey;
use Soneso\StellarSDK\Xdr\XdrSignerKeyType;
use Soneso\StellarSDK\Xdr\XdrTimeBounds;
use Soneso\StellarSDK\Xdr\XdrTransaction;
use Soneso\StellarSDK\Xdr\XdrTransactionV0;

final class TransactionConsolidator
{
    public const MAX_OPERATIONS = 100;
    public const MAX_SIGNATURES = 20;
    public const MAX_IMPORT_ITEMS = 50;
    public const MAX_IMPORT_BYTES = 1_048_576;
    public const MAX_ENVELOPE_BYTES = 262_144;
    public const DEFAULT_MAX_OPERATION_FEE = 10_000;

    private const MAX_SEQUENCE = '9223372036854775807';

    public function parseEnvelope(string $xdr): TransactionConsolidationItem
    {
        $decoded = $this->decodeEnvelope($xdr);
        $source = $this->formatSource($decoded['source']);
        $operations = [];
        $warnings = $decoded['warnings'];

        foreach ($decoded['operations'] as $index => $Operation) {
            $operationSource = $Operation->getSourceAccount();
            $effectiveSource = $operationSource ?? $decoded['source'];
            $operations[] = $this->describeOperation(
                $Operation,
                $index,
                $operationSource === null ? null : $this->formatSource($operationSource),
                $this->formatSource($effectiveSource),
            );
            if (
                $Operation->getBody()->getType()->getValue() === XdrOperationType::CREATE_CLAIMABLE_BALANCE
                && !in_array('claimable_balance_id_changes', $warnings, true)
            ) {
                $warnings[] = 'claimable_balance_id_changes';
            }
        }

        return new TransactionConsolidationItem(
            id: hash('sha256', $decoded['transaction_xdr']),
            fingerprint: hash('sha256', $decoded['envelope_xdr']),
            xdr: $xdr,
            source: $source,
            memo: TransactionConsolidationMemo::fromXdr($decoded['memo']),
            warnings: $warnings,
            signature_count: $decoded['signature_count'],
            operation_count: count($operations),
            operations: $operations,
            envelope_type: $decoded['envelope_type'],
        );
    }

    /**
     * Parses one non-empty XDR candidate per line. Invalid lines do not discard
     * valid ones; duplicates are identified by their inner transaction body.
     *
     * @return array{
     *     items: list<TransactionConsolidationItem>,
     *     errors: list<array{line: int, message: string}>,
     *     duplicates: list<array{line: int, id: string}>
     * }
     */
    public function importLines(string $input): array
    {
        if (strlen($input) > self::MAX_IMPORT_BYTES) {
            throw new \InvalidArgumentException('XDR import exceeds the 1 MiB limit.');
        }

        $items = [];
        $errors = [];
        $duplicates = [];
        $seen = [];
        $candidateCount = 0;

        foreach (preg_split('/\r\n|\n|\r/', $input) ?: [] as $offset => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $candidateCount++;
            if ($candidateCount > self::MAX_IMPORT_ITEMS) {
                $errors[] = [
                    'line' => $offset + 1,
                    'message' => 'Import is limited to 50 non-empty XDR lines.',
                ];
                continue;
            }

            try {
                $Item = $this->parseEnvelope($line);
                if (isset($seen[$Item->id])) {
                    $duplicates[] = ['line' => $offset + 1, 'id' => $Item->id];
                    continue;
                }

                $seen[$Item->id] = true;
                $items[] = $Item;
            } catch (\Throwable $Error) {
                $errors[] = [
                    'line' => $offset + 1,
                    'message' => $Error instanceof \InvalidArgumentException
                        ? $Error->getMessage()
                        : 'Invalid transaction envelope XDR.',
                ];
            }
        }

        return compact('items', 'errors', 'duplicates');
    }

    /**
     * Returns the explicit "none" choice first, followed by unique memos from
     * the supplied transactions in their first-seen order.
     *
     * @param list<TransactionConsolidationItem> $items
     * @return list<TransactionConsolidationMemo>
     */
    public function memoCandidates(array $items): array
    {
        $none = TransactionConsolidationMemo::none();
        $result = [$none];
        $seen = [$none->fingerprint() => true];

        foreach ($items as $Item) {
            if (!$Item instanceof TransactionConsolidationItem) {
                throw new \InvalidArgumentException('Memo candidates require consolidation items.');
            }

            $fingerprint = $Item->memo->fingerprint();
            if (!isset($seen[$fingerprint])) {
                $seen[$fingerprint] = true;
                $result[] = $Item->memo;
            }
        }

        return $result;
    }

    /**
     * Builds an unsigned V1 envelope. Selection keys are item IDs and values
     * are zero-based operation indexes. Item and operation order is preserved.
     *
     * @param list<TransactionConsolidationItem> $items
     * @param array<string, list<int>> $selectedIndexes
     */
    public function build(
        array $items,
        array $selectedIndexes,
        string $source,
        TransactionConsolidationMemo $Memo,
        string|int $sequenceNumber,
        int $maxOperationFee = self::DEFAULT_MAX_OPERATION_FEE,
        bool $sponsorReserves = false,
    ): string {
        if ($maxOperationFee < 1) {
            throw new \InvalidArgumentException('Maximum operation fee must be positive.');
        }

        $Source = $this->parseSource($source);
        $selectedOperations = $this->prepareOperations($items, $selectedIndexes, $Source, $sponsorReserves);
        $operationCount = count($selectedOperations);
        if ($operationCount < 1) {
            throw new \InvalidArgumentException('At least one operation must be selected.');
        }
        if ($operationCount > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('A transaction cannot contain more than 100 operations.');
        }

        $fee = $operationCount * $maxOperationFee;
        if ($fee > 0xffff_ffff) {
            throw new \InvalidArgumentException('Transaction fee exceeds the uint32 limit.');
        }

        $Sequence = $this->parseSequence($sequenceNumber);

        $bytes = XdrEncoder::integer32(XdrEnvelopeType::ENVELOPE_TYPE_TX);
        $bytes .= $Source->encode();
        $bytes .= XdrEncoder::unsignedInteger32($fee);
        $bytes .= (new XdrSequenceNumber($Sequence))->encode();
        $bytes .= XdrEncoder::integer32(XdrPreconditionType::NONE);
        $bytes .= $Memo->toXdr()->encode();
        $bytes .= XdrEncoder::integer32($operationCount);
        foreach ($selectedOperations as $Operation) {
            $bytes .= $this->encodeOperation($Operation);
        }
        $bytes .= XdrEncoder::integer32(0); // TransactionExt v0.
        $bytes .= XdrEncoder::integer32(0); // No signatures.

        $result = base64_encode($bytes);
        $this->parseEnvelope($result);

        return $result;
    }

    /**
     * Returns the number of operations in the resulting transaction, including
     * sponsorship wrappers when requested.
     *
     * @param list<TransactionConsolidationItem> $items
     * @param array<string, list<int>> $selectedIndexes
     */
    public function resultOperationCount(
        array $items,
        array $selectedIndexes,
        string $source,
        bool $sponsorReserves = false,
    ): int {
        return count($this->prepareOperations(
            $items,
            $selectedIndexes,
            $this->parseSource($source),
            $sponsorReserves,
        ));
    }

    /**
     * @param list<TransactionConsolidationItem> $items
     * @param array<string, list<int>> $selectedIndexes
     * @return list<XdrOperation>
     */
    private function prepareOperations(
        array $items,
        array $selectedIndexes,
        XdrMuxedAccount $Source,
        bool $sponsorReserves,
    ): array {
        $knownItems = [];
        foreach ($items as $Item) {
            if (!$Item instanceof TransactionConsolidationItem) {
                throw new \InvalidArgumentException('Build requires consolidation items.');
            }
            if (isset($knownItems[$Item->id])) {
                throw new \InvalidArgumentException('Duplicate consolidation item ID.');
            }
            $knownItems[$Item->id] = true;
        }
        foreach (array_keys($selectedIndexes) as $itemId) {
            if (!is_string($itemId) || !isset($knownItems[$itemId])) {
                throw new \InvalidArgumentException('Selection contains an unknown item ID.');
            }
        }

        $selectedOperations = [];
        $primaryKey = $this->baseAccountKey($Source);
        $primarySource = $this->formatSource($Source);
        $activeSponsoredKey = null;
        foreach ($items as $Item) {
            $indexes = $selectedIndexes[$Item->id] ?? [];
            if (!is_array($indexes)) {
                throw new \InvalidArgumentException('Selected operation indexes must be an array.');
            }

            $selection = [];
            foreach ($indexes as $index) {
                if (!is_int($index) || $index < 0 || $index >= $Item->operation_count) {
                    throw new \InvalidArgumentException('Selected operation index is out of range.');
                }
                if (isset($selection[$index])) {
                    throw new \InvalidArgumentException('Selected operation index is duplicated.');
                }
                $selection[$index] = true;
            }

            if ($selection === []) {
                continue;
            }

            $decoded = $this->decodeEnvelope($Item->xdr);
            $id = hash('sha256', $decoded['transaction_xdr']);
            $fingerprint = hash('sha256', $decoded['envelope_xdr']);
            if (
                !hash_equals($Item->id, $id)
                || !hash_equals($Item->fingerprint, $fingerprint)
                || $Item->operation_count !== count($decoded['operations'])
            ) {
                throw new \InvalidArgumentException('Consolidation item does not match its authoritative XDR.');
            }

            foreach ($decoded['operations'] as $index => $Operation) {
                if (!isset($selection[$index])) {
                    continue;
                }

                $effectiveSource = $Operation->getSourceAccount() ?? $decoded['source'];
                $resultOperation = new XdrOperation(
                    $Operation->getBody(),
                    hash_equals($primarySource, $this->formatSource($effectiveSource))
                        ? null
                        : $effectiveSource,
                );
                $effectiveKey = $this->baseAccountKey($effectiveSource);
                $sponsoredKey = (
                    $sponsorReserves
                    && !hash_equals($primaryKey, $effectiveKey)
                    && $this->canRequireReserveSponsorship($Operation)
                ) ? $effectiveKey : null;

                if (
                    $activeSponsoredKey !== null
                    && ($sponsoredKey === null || !hash_equals($activeSponsoredKey, $sponsoredKey))
                ) {
                    $selectedOperations[] = $this->endSponsorshipOperation($activeSponsoredKey);
                    $activeSponsoredKey = null;
                }
                if ($sponsoredKey !== null && $activeSponsoredKey === null) {
                    $selectedOperations[] = new XdrOperation(
                        (new BeginSponsoringFutureReservesOperation(
                            StrKey::encodeAccountId($sponsoredKey),
                        ))->toOperationBody(),
                    );
                    $activeSponsoredKey = $sponsoredKey;
                }

                $selectedOperations[] = $resultOperation;
            }
        }
        if ($activeSponsoredKey !== null) {
            $selectedOperations[] = $this->endSponsorshipOperation($activeSponsoredKey);
        }

        return $selectedOperations;
    }

    private function endSponsorshipOperation(string $sponsoredKey): XdrOperation
    {
        return new XdrOperation(
            (new EndSponsoringFutureReservesOperation())->toOperationBody(),
            new XdrMuxedAccount($sponsoredKey),
        );
    }

    private function canRequireReserveSponsorship(XdrOperation $Operation): bool
    {
        try {
            $HighLevel = AbstractOperation::fromXdr($Operation);
        } catch (\Throwable) {
            return false;
        }

        return match (true) {
            $HighLevel instanceof ChangeTrustOperation => !$this->isZeroDecimal($HighLevel->getLimit()),
            $HighLevel instanceof SetOptionsOperation => $HighLevel->getSignerKey() !== null
                && ($HighLevel->getSignerWeight() ?? 0) > 0,
            $HighLevel instanceof ManageSellOfferOperation,
            $HighLevel instanceof ManageBuyOfferOperation => $HighLevel->getOfferId() === 0
                && !$this->isZeroDecimal($HighLevel->getAmount()),
            $HighLevel instanceof CreatePassiveSellOfferOperation => !$this->isZeroDecimal($HighLevel->getAmount()),
            $HighLevel instanceof ManageDataOperation => ($HighLevel->getValue() ?? '') !== '',
            default => false,
        };
    }

    private function baseAccountKey(XdrMuxedAccount $Source): string
    {
        if ($Source->getDiscriminant() === CryptoKeyType::KEY_TYPE_ED25519) {
            return $Source->getEd25519() ?? throw new \InvalidArgumentException('Missing Ed25519 account key.');
        }
        if ($Source->getDiscriminant() === CryptoKeyType::KEY_TYPE_MUXED_ED25519) {
            return $Source->getMed25519()?->getEd25519()
                ?? throw new \InvalidArgumentException('Missing muxed account key.');
        }

        throw new \InvalidArgumentException('Unsupported transaction source type.');
    }

    /**
     * @return array{
     *     envelope_xdr: string,
     *     transaction_xdr: string,
     *     envelope_type: string,
     *     source: XdrMuxedAccount,
     *     memo: XdrMemo,
     *     operations: list<XdrOperation>,
     *     warnings: list<string>,
     *     signature_count: int
     * }
     */
    private function decodeEnvelope(string $xdr): array
    {
        if ($xdr === '' || strlen($xdr) > (int) ceil(self::MAX_ENVELOPE_BYTES / 3) * 4) {
            throw new \InvalidArgumentException('Transaction envelope XDR is empty or too large.');
        }
        if (preg_match('/\s/', $xdr) === 1) {
            throw new \InvalidArgumentException('Transaction envelope XDR must be on one line.');
        }

        $raw = base64_decode($xdr, true);
        if ($raw === false || strlen($raw) > self::MAX_ENVELOPE_BYTES || base64_encode($raw) !== $xdr) {
            throw new \InvalidArgumentException('Transaction envelope must use canonical Base64.');
        }

        $Buffer = new class($raw) extends XdrBuffer {
            public function atEnd(): bool
            {
                return $this->position === $this->size;
            }
        };

        try {
            $type = $Buffer->readInteger32();
            $warnings = [];
            $signatureCount = 0;

            if ($type === XdrEnvelopeType::ENVELOPE_TYPE_TX) {
                $Transaction = $this->decodeV1Transaction($Buffer);
                $signatures = $this->decodeSignatures($Buffer);
                $signatureCount = count($signatures);
                if ($signatureCount > 0) {
                    $warnings[] = 'signatures_discarded';
                }
                $envelopeType = 'v1';
                $source = $Transaction->getSourceAccount();
                $memo = $Transaction->getMemo();
                $operations = $Transaction->getOperations();
                $transactionXdr = $this->encodeV1Transaction($Transaction);
                if ($Transaction->getPreconditions()?->getType()->getValue() !== XdrPreconditionType::NONE) {
                    $warnings[] = 'preconditions_discarded';
                }
            } elseif ($type === XdrEnvelopeType::ENVELOPE_TYPE_TX_V0) {
                $Transaction = $this->decodeV0Transaction($Buffer);
                $signatures = $this->decodeSignatures($Buffer);
                $signatureCount = count($signatures);
                $warnings[] = 'legacy_v0_normalized';
                if ($signatureCount > 0) {
                    $warnings[] = 'signatures_discarded';
                }
                if ($Transaction->getTimeBounds() !== null) {
                    $warnings[] = 'preconditions_discarded';
                }
                $envelopeType = 'v0';
                $source = new XdrMuxedAccount($Transaction->getSourceAccountEd25519());
                $memo = $Transaction->getMemo();
                $operations = $Transaction->getOperations();
                $transactionXdr = $this->encodeV0Transaction($Transaction);
            } elseif ($type === XdrEnvelopeType::ENVELOPE_TYPE_TX_FEE_BUMP) {
                XdrMuxedAccount::decode($Buffer); // Outer fee source is intentionally discarded.
                $Buffer->readInteger64(); // Outer fee is intentionally discarded.
                if ($Buffer->readInteger32() !== XdrEnvelopeType::ENVELOPE_TYPE_TX) {
                    throw new \InvalidArgumentException('Fee-bump inner envelope must be a V1 transaction.');
                }

                $Transaction = $this->decodeV1Transaction($Buffer);
                $innerSignatures = $this->decodeSignatures($Buffer);
                if ($Buffer->readInteger32() !== 0) {
                    throw new \InvalidArgumentException('Unsupported fee-bump transaction extension.');
                }
                $outerSignatures = $this->decodeSignatures($Buffer);
                $signatureCount = count($innerSignatures) + count($outerSignatures);
                $warnings[] = 'fee_bump_outer_discarded';
                if ($signatureCount > 0) {
                    $warnings[] = 'signatures_discarded';
                }
                if ($Transaction->getPreconditions()?->getType()->getValue() !== XdrPreconditionType::NONE) {
                    $warnings[] = 'preconditions_discarded';
                }
                $envelopeType = 'fee_bump';
                $source = $Transaction->getSourceAccount();
                $memo = $Transaction->getMemo();
                $operations = $Transaction->getOperations();
                $transactionXdr = $this->encodeV1Transaction($Transaction);
            } else {
                throw new \InvalidArgumentException('Only V0, V1, and fee-bump transaction envelopes are supported.');
            }

            if (!$Buffer->atEnd()) {
                throw new \InvalidArgumentException('Transaction envelope XDR contains trailing data.');
            }

            return [
                'envelope_xdr' => $raw,
                'transaction_xdr' => $transactionXdr,
                'envelope_type' => $envelopeType,
                'source' => $source,
                'memo' => $memo,
                'operations' => $operations,
                'warnings' => $warnings,
                'signature_count' => $signatureCount,
            ];
        } catch (\InvalidArgumentException $Error) {
            throw $Error;
        } catch (\Throwable $Error) {
            throw new \InvalidArgumentException('Invalid transaction envelope XDR.', 0, $Error);
        }
    }

    private function decodeV1Transaction(XdrBuffer $Buffer): XdrTransaction
    {
        $Source = XdrMuxedAccount::decode($Buffer);
        $fee = $Buffer->readUnsignedInteger32();
        $Sequence = XdrSequenceNumber::decode($Buffer);
        $this->validateInputSequence($Sequence);
        $Preconditions = XdrPreconditions::decode($Buffer);
        if (!in_array($Preconditions->getType()->getValue(), [
            XdrPreconditionType::NONE,
            XdrPreconditionType::TIME,
            XdrPreconditionType::V2,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported transaction precondition type.');
        }
        $Memo = XdrMemo::decode($Buffer);
        TransactionConsolidationMemo::fromXdr($Memo);
        $operations = $this->decodeOperations($Buffer);

        $extension = $Buffer->readInteger32();
        if ($extension !== 0) {
            throw new \InvalidArgumentException('Soroban transaction envelopes cannot be consolidated.');
        }

        return new XdrTransaction(
            $Source,
            $Sequence,
            $operations,
            $fee,
            $Memo,
            $Preconditions,
        );
    }

    private function decodeV0Transaction(XdrBuffer $Buffer): XdrTransactionV0
    {
        $source = $Buffer->readUnsignedInteger256();
        $fee = $Buffer->readUnsignedInteger32();
        $Sequence = XdrSequenceNumber::decode($Buffer);
        $this->validateInputSequence($Sequence);

        $timeBoundsPresent = $Buffer->readInteger32();
        if ($timeBoundsPresent !== 0 && $timeBoundsPresent !== 1) {
            throw new \InvalidArgumentException('Invalid V0 time-bounds pointer.');
        }
        $TimeBounds = $timeBoundsPresent === 1 ? XdrTimeBounds::decode($Buffer) : null;

        $Memo = XdrMemo::decode($Buffer);
        TransactionConsolidationMemo::fromXdr($Memo);
        $operations = $this->decodeOperations($Buffer);
        if ($Buffer->readInteger32() !== 0) {
            throw new \InvalidArgumentException('Unsupported V0 transaction extension.');
        }

        return new XdrTransactionV0($source, $Sequence, $operations, $fee, $Memo, $TimeBounds);
    }

    /** @return list<XdrOperation> */
    private function decodeOperations(XdrBuffer $Buffer): array
    {
        $count = $Buffer->readInteger32();
        if ($count < 1 || $count > self::MAX_OPERATIONS) {
            throw new \InvalidArgumentException('A transaction must contain between 1 and 100 operations.');
        }

        $operations = [];
        for ($index = 0; $index < $count; $index++) {
            $Operation = XdrOperation::decode($Buffer);
            $type = $Operation->getBody()->getType()->getValue();
            if ($type < XdrOperationType::CREATE_ACCOUNT || $type > XdrOperationType::RESTORE_FOOTPRINT) {
                throw new \InvalidArgumentException('Unsupported operation type.');
            }
            if (in_array($type, [
                XdrOperationType::INVOKE_HOST_FUNCTION,
                XdrOperationType::EXTEND_FOOTPRINT_TTL,
                XdrOperationType::RESTORE_FOOTPRINT,
            ], true)) {
                throw new \InvalidArgumentException('Soroban operations cannot be consolidated.');
            }
            $operations[] = $Operation;
        }

        return $operations;
    }

    /** @return list<XdrDecoratedSignature> */
    private function decodeSignatures(XdrBuffer $Buffer): array
    {
        $count = $Buffer->readInteger32();
        if ($count < 0 || $count > self::MAX_SIGNATURES) {
            throw new \InvalidArgumentException('A transaction envelope cannot contain more than 20 signatures.');
        }

        $signatures = [];
        for ($index = 0; $index < $count; $index++) {
            $Signature = XdrDecoratedSignature::decode($Buffer);
            if (strlen($Signature->getSignature()) > 64) {
                throw new \InvalidArgumentException('A decorated signature cannot exceed 64 bytes.');
            }
            $signatures[] = $Signature;
        }

        return $signatures;
    }

    /**
     * @return array{
     *     index: int,
     *     type: int,
     *     class: class-string|string,
     *     source: ?string,
     *     effective_source: string,
     *     sponsorship_eligible: bool,
     *     summary: string,
     *     details: array<string, int|float|string|bool|null|list<string>>
     * }
     */
    private function describeOperation(
        XdrOperation $Operation,
        int $index,
        ?string $source,
        string $effectiveSource,
    ): array {
        $type = $Operation->getBody()->getType()->getValue();
        $class = XdrOperation::class;
        $summary = $type === XdrOperationType::INFLATION ? 'Inflation' : 'Operation #' . ($index + 1);
        $details = ['xdr' => base64_encode($this->encodeOperation($Operation))];

        try {
            $HighLevel = AbstractOperation::fromXdr($Operation);
            $class = $HighLevel::class;
            $shortClass = substr($class, (int) strrpos($class, '\\') + 1);
            $summary = preg_replace('/Operation\z/', '', $shortClass) ?: $shortClass;
            [$summary, $specificDetails] = $this->describeClassicOperation($HighLevel, $Operation, $summary);
            $details = array_replace($details, $specificDetails);

            if ($HighLevel instanceof ManageDataOperation) {
                $value = $HighLevel->getValue();
                $details['key'] = $HighLevel->getKey();
                $details['value'] = $value;
                $details['delete'] = $value === null;
                $summary = $value === null ? 'ManageData: delete ' : 'ManageData: set ';
                $summary .= $this->displayBytes($HighLevel->getKey());
            }
        } catch (\Throwable) {
            // The low-level XDR remains authoritative for supported classic ops.
        }

        return [
            'index' => $index,
            'type' => $type,
            'class' => $class,
            'source' => $source,
            'effective_source' => $effectiveSource,
            'sponsorship_eligible' => $this->canRequireReserveSponsorship($Operation),
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * @return array{string, array<string, int|float|string|bool|null|list<string>>}
     */
    private function describeClassicOperation(
        AbstractOperation $Operation,
        XdrOperation $XdrOperation,
        string $fallback,
    ): array {
        if ($Operation instanceof PaymentOperation) {
            $destination = $Operation->getDestination()->getAccountId();
            $XdrPayment = $XdrOperation->getBody()->getPaymentOp();
            if ($XdrPayment !== null) {
                $destination = $this->formatSource($XdrPayment->getDestination());
            }
            $asset = $this->assetName($Operation->getAsset());

            return [
                sprintf('Payment: %s %s to %s', $Operation->getAmount(), $this->assetName($Operation->getAsset(), true), $destination),
                ['destination' => $destination, 'asset' => $asset, 'amount' => $Operation->getAmount()],
            ];
        }

        if ($Operation instanceof PathPaymentStrictReceiveOperation) {
            $destination = $Operation->getDestination()->getAccountId();
            $XdrPathPayment = $XdrOperation->getBody()->getPathPaymentStrictReceiveOp();
            if ($XdrPathPayment !== null) {
                $destination = $this->formatSource($XdrPathPayment->getDestination());
            }
            $path = array_map(fn(Asset $Asset): string => $this->assetName($Asset), $Operation->getPath() ?? []);

            return [
                sprintf(
                    'Path payment strict receive: receive %s %s at %s, send up to %s %s',
                    $Operation->getDestAmount(),
                    $this->assetName($Operation->getDestAsset(), true),
                    $destination,
                    $Operation->getSendMax(),
                    $this->assetName($Operation->getSendAsset(), true),
                ),
                [
                    'destination' => $destination,
                    'source_asset' => $this->assetName($Operation->getSendAsset()),
                    'source_max' => $Operation->getSendMax(),
                    'destination_asset' => $this->assetName($Operation->getDestAsset()),
                    'destination_amount' => $Operation->getDestAmount(),
                    'path' => $path,
                ],
            ];
        }

        if ($Operation instanceof PathPaymentStrictSendOperation) {
            $destination = $Operation->getDestination()->getAccountId();
            $XdrPathPayment = $XdrOperation->getBody()->getPathPaymentStrictSendOp();
            if ($XdrPathPayment !== null) {
                $destination = $this->formatSource($XdrPathPayment->getDestination());
            }
            $path = array_map(fn(Asset $Asset): string => $this->assetName($Asset), $Operation->getPath() ?? []);

            return [
                sprintf(
                    'Path payment strict send: send %s %s to %s, receive at least %s %s',
                    $Operation->getSendAmount(),
                    $this->assetName($Operation->getSendAsset(), true),
                    $destination,
                    $Operation->getDestMin(),
                    $this->assetName($Operation->getDestAsset(), true),
                ),
                [
                    'destination' => $destination,
                    'source_asset' => $this->assetName($Operation->getSendAsset()),
                    'source_amount' => $Operation->getSendAmount(),
                    'destination_asset' => $this->assetName($Operation->getDestAsset()),
                    'destination_min' => $Operation->getDestMin(),
                    'path' => $path,
                ],
            ];
        }

        if ($Operation instanceof CreateAccountOperation) {
            return [
                sprintf('Create account: %s with %s XLM', $Operation->getDestination(), $Operation->getStartingBalance()),
                ['destination' => $Operation->getDestination(), 'starting_balance' => $Operation->getStartingBalance()],
            ];
        }

        if ($Operation instanceof ChangeTrustOperation) {
            $asset = $this->assetName($Operation->getAsset());
            $summary = $this->isZeroDecimal($Operation->getLimit())
                ? sprintf('Remove trustline: %s', $this->assetName($Operation->getAsset(), true))
                : sprintf('Change trustline: %s, limit %s', $this->assetName($Operation->getAsset(), true), $Operation->getLimit());

            return [$summary, ['asset' => $asset, 'limit' => $Operation->getLimit()]];
        }

        if ($Operation instanceof ManageSellOfferOperation || $Operation instanceof ManageBuyOfferOperation) {
            $isSell = $Operation instanceof ManageSellOfferOperation;
            $action = $this->isZeroDecimal($Operation->getAmount())
                ? 'delete'
                : ($Operation->getOfferId() === 0 ? 'create' : 'update');
            $selling = $this->assetName($Operation->getSelling());
            $buying = $this->assetName($Operation->getBuying());
            $sellingDisplay = $this->assetName($Operation->getSelling(), true);
            $buyingDisplay = $this->assetName($Operation->getBuying(), true);
            $price = $Operation->getPrice()->getN() . '/' . $Operation->getPrice()->getD();
            $kind = $isSell ? 'sell' : 'buy';
            $summary = $action === 'delete'
                ? sprintf('Delete %s offer #%d: %s → %s', $kind, $Operation->getOfferId(), $sellingDisplay, $buyingDisplay)
                : sprintf(
                    '%s %s offer: %s %s (%s → %s) at %s',
                    ucfirst($action),
                    $kind,
                    $Operation->getAmount(),
                    $this->assetName($isSell ? $Operation->getSelling() : $Operation->getBuying(), true),
                    $sellingDisplay,
                    $buyingDisplay,
                    $price,
                );

            return [$summary, [
                'action' => $action,
                'selling' => $selling,
                'buying' => $buying,
                'amount' => $Operation->getAmount(),
                'price_n' => $Operation->getPrice()->getN(),
                'price_d' => $Operation->getPrice()->getD(),
                'offer_id' => $Operation->getOfferId(),
            ]];
        }

        if ($Operation instanceof SetOptionsOperation) {
            return $this->describeSetOptions($Operation);
        }

        if ($Operation instanceof AccountMergeOperation) {
            $destination = $Operation->getDestination()->getAccountId();
            $XdrMerge = $XdrOperation->getBody()->getAccountMergeOp();
            if ($XdrMerge !== null) {
                $destination = $this->formatSource($XdrMerge->getDestination());
            }

            return ['Merge account into ' . $destination, ['destination' => $destination]];
        }

        if ($Operation instanceof BeginSponsoringFutureReservesOperation) {
            return [
                'Begin sponsoring reserves for ' . $Operation->getSponsoredId(),
                ['sponsored_account' => $Operation->getSponsoredId()],
            ];
        }

        if ($Operation instanceof EndSponsoringFutureReservesOperation) {
            return ['End sponsoring future reserves', []];
        }

        if ($Operation instanceof RevokeSponsorshipOperation) {
            $LedgerKey = $Operation->getLedgerKey();
            if ($LedgerKey !== null) {
                $type = $LedgerKey->getType()->getValue();

                return [
                    'Revoke sponsorship for ledger entry type ' . $type,
                    ['mode' => 'ledger_entry', 'ledger_type' => $type, 'ledger_key_xdr' => $LedgerKey->toBase64Xdr()],
                ];
            }

            $account = $Operation->getSignerAccount();
            $SignerKey = $Operation->getSignerKey();
            $details = ['mode' => 'signer', 'signer_account' => $account];
            if ($SignerKey !== null) {
                $details['signer_type'] = $SignerKey->getType()->getValue();
                $details['signer_key'] = $this->signerKeyName($SignerKey);
            }

            return ['Revoke signer sponsorship for ' . ($account ?? 'unknown account'), $details];
        }

        if ($Operation instanceof CreateClaimableBalanceOperation) {
            $claimants = $Operation->getClaimants();
            $asset = $this->assetName($Operation->getAsset());
            $details = [
                'asset' => $asset,
                'amount' => $Operation->getAmount(),
                'claimant_count' => count($claimants),
            ];
            if (($claimants[0] ?? null) instanceof Claimant) {
                $details['first_claimant'] = $claimants[0]->getDestination();
            }

            return [
                sprintf(
                    'Create claimable balance: %s %s for %d claimant(s)',
                    $Operation->getAmount(),
                    $this->assetName($Operation->getAsset(), true),
                    count($claimants),
                ),
                $details,
            ];
        }

        if ($Operation instanceof ClaimClaimableBalanceOperation) {
            return [
                'Claim balance: ' . $Operation->getBalanceId(),
                ['balance_id' => $Operation->getBalanceId()],
            ];
        }

        return [$fallback, []];
    }

    /** @return array{string, array<string, int|float|string|bool|null|list<string>>} */
    private function describeSetOptions(SetOptionsOperation $Operation): array
    {
        $details = [];
        $changes = [];
        $fields = [
            'inflation_destination' => $Operation->getInflationDestination(),
            'clear_flags' => $Operation->getClearFlags(),
            'set_flags' => $Operation->getSetFlags(),
            'master_weight' => $Operation->getMasterKeyWeight(),
            'low_threshold' => $Operation->getLowThreshold(),
            'medium_threshold' => $Operation->getMediumThreshold(),
            'high_threshold' => $Operation->getHighThreshold(),
            'home_domain' => $Operation->getHomeDomain(),
        ];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $details[$name] = $value;
            $changes[] = str_replace('_', ' ', $name) . '=' . (is_string($value) ? $this->displayBytes($value) : $value);
        }

        $SignerKey = $Operation->getSignerKey();
        if ($SignerKey !== null) {
            $signer = $this->signerKeyName($SignerKey);
            $details['signer_type'] = $SignerKey->getType()->getValue();
            $details['signer_key'] = $signer;
            $details['signer_weight'] = $Operation->getSignerWeight();
            $changes[] = sprintf('signer=%s (weight %d)', $signer, $Operation->getSignerWeight() ?? 0);
        }

        return ['Set options' . ($changes === [] ? '' : ': ' . implode(', ', $changes)), $details];
    }

    private function assetName(Asset $Asset, bool $display = false): string
    {
        if ($Asset instanceof AssetTypePoolShare) {
            return sprintf(
                'pool_share(%s,%s)',
                $this->assetName($Asset->getAssetA(), $display),
                $this->assetName($Asset->getAssetB(), $display),
            );
        }

        $name = Asset::canonicalForm($Asset);

        return $display && $name === 'native' ? 'XLM' : $name;
    }

    private function signerKeyName(XdrSignerKey $Key): string
    {
        try {
            return match ($Key->getType()->getValue()) {
                XdrSignerKeyType::ED25519 => StrKey::encodeAccountId($Key->getEd25519() ?? throw new \LogicException()),
                XdrSignerKeyType::PRE_AUTH_TX => StrKey::encodePreAuth($Key->getPreAuthTx() ?? throw new \LogicException()),
                XdrSignerKeyType::HASH_X => StrKey::encodeSha256Hash($Key->getHashX() ?? throw new \LogicException()),
                XdrSignerKeyType::ED25519_SIGNED_PAYLOAD => StrKey::encodeXdrSignedPayload(
                    $Key->getSignedPayload() ?? throw new \LogicException()
                ),
                default => base64_encode($Key->encode()),
            };
        } catch (\Throwable) {
            return base64_encode($Key->encode());
        }
    }

    private function isZeroDecimal(string $value): bool
    {
        return preg_match('/\A[+-]?0+(?:\.0+)?\z/D', $value) === 1;
    }

    private function encodeV1Transaction(XdrTransaction $Transaction): string
    {
        $bytes = $Transaction->getSourceAccount()->encode();
        $bytes .= XdrEncoder::unsignedInteger32($Transaction->getFee());
        $bytes .= $Transaction->getSequenceNumber()->encode();
        $bytes .= $Transaction->getPreconditions()?->encode()
            ?? XdrEncoder::integer32(XdrPreconditionType::NONE);
        $bytes .= $Transaction->getMemo()->encode();
        $bytes .= XdrEncoder::integer32(count($Transaction->getOperations()));
        foreach ($Transaction->getOperations() as $Operation) {
            $bytes .= $this->encodeOperation($Operation);
        }
        $bytes .= XdrEncoder::integer32(0);

        return $bytes;
    }

    private function encodeV0Transaction(XdrTransactionV0 $Transaction): string
    {
        $bytes = XdrEncoder::unsignedInteger256($Transaction->getSourceAccountEd25519());
        $bytes .= XdrEncoder::unsignedInteger32($Transaction->getFee());
        $bytes .= $Transaction->getSequenceNumber()->encode();
        $TimeBounds = $Transaction->getTimeBounds();
        $bytes .= XdrEncoder::integer32($TimeBounds === null ? 0 : 1);
        if ($TimeBounds !== null) {
            $bytes .= $TimeBounds->encode();
        }
        $bytes .= $Transaction->getMemo()->encode();
        $bytes .= XdrEncoder::integer32(count($Transaction->getOperations()));
        foreach ($Transaction->getOperations() as $Operation) {
            $bytes .= $this->encodeOperation($Operation);
        }
        $bytes .= XdrEncoder::integer32(0);

        return $bytes;
    }

    private function encodeOperation(XdrOperation $Operation): string
    {
        $Source = $Operation->getSourceAccount();
        $bytes = XdrEncoder::integer32($Source === null ? 0 : 1);
        if ($Source !== null) {
            $bytes .= $Source->encode();
        }

        $type = $Operation->getBody()->getType()->getValue();
        if ($type === XdrOperationType::INFLATION) {
            return $bytes . XdrEncoder::integer32($type);
        }
        if ($type === XdrOperationType::MANAGE_DATA) {
            $ManageData = $Operation->getBody()->getManageDataOperation();
            if ($ManageData === null) {
                throw new \InvalidArgumentException('ManageData operation body is missing.');
            }
            $value = $ManageData->getValue()->getValue();
            $bytes .= XdrEncoder::integer32($type);
            $bytes .= XdrEncoder::string($ManageData->getKey(), 64);
            $bytes .= XdrEncoder::integer32($value === null ? 0 : 1);
            if ($value !== null) {
                $bytes .= XdrEncoder::opaqueVariable($value);
            }

            return $bytes;
        }

        return $bytes . $Operation->getBody()->encode();
    }

    private function parseSource(string $source): XdrMuxedAccount
    {
        try {
            if (str_starts_with($source, 'G')) {
                $raw = StrKey::decodeAccountId($source);
                if (strlen($raw) !== 32 || !hash_equals($source, StrKey::encodeAccountId($raw))) {
                    throw new \InvalidArgumentException('Invalid account ID.');
                }

                return new XdrMuxedAccount($raw);
            }
            if (str_starts_with($source, 'M')) {
                $raw = StrKey::decodeMuxedAccountId($source);
                if (strlen($raw) !== 40) {
                    throw new \InvalidArgumentException('Invalid muxed account ID.');
                }
                $Buffer = new XdrBuffer($raw);
                $Med25519 = XdrMuxedAccountMed25519::decodeInverted($Buffer);
                if (!hash_equals($source, StrKey::encodeMuxedAccountId($Med25519->encodeInverted()))) {
                    throw new \InvalidArgumentException('Invalid muxed account ID.');
                }

                return new XdrMuxedAccount(null, $Med25519);
            }
        } catch (\Throwable $Error) {
            throw new \InvalidArgumentException('Transaction source must be a valid G or M account ID.', 0, $Error);
        }

        throw new \InvalidArgumentException('Transaction source must be a valid G or M account ID.');
    }

    private function formatSource(XdrMuxedAccount $Source): string
    {
        if ($Source->getDiscriminant() === CryptoKeyType::KEY_TYPE_ED25519) {
            $raw = $Source->getEd25519();
            if ($raw === null) {
                throw new \InvalidArgumentException('Missing Ed25519 account key.');
            }

            return StrKey::encodeAccountId($raw);
        }
        if ($Source->getDiscriminant() === CryptoKeyType::KEY_TYPE_MUXED_ED25519) {
            $Med25519 = $Source->getMed25519();
            if ($Med25519 === null) {
                throw new \InvalidArgumentException('Missing muxed account key.');
            }

            return StrKey::encodeMuxedAccountId($Med25519->encodeInverted());
        }

        throw new \InvalidArgumentException('Unsupported transaction source type.');
    }

    private function parseSequence(string|int $sequenceNumber): BigInteger
    {
        $value = (string) $sequenceNumber;
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Sequence number must be a positive decimal integer.');
        }

        $Sequence = new BigInteger($value, 10);
        if ($Sequence->compare(new BigInteger(self::MAX_SEQUENCE, 10)) > 0) {
            throw new \InvalidArgumentException('Sequence number exceeds the signed int64 limit.');
        }

        return $Sequence;
    }

    private function validateInputSequence(XdrSequenceNumber $Sequence): void
    {
        $value = $Sequence->getValue();
        if (
            $value->compare(new BigInteger(0)) <= 0
            || $value->compare(new BigInteger(self::MAX_SEQUENCE, 10)) > 0
        ) {
            throw new \InvalidArgumentException(
                'Transaction sequence must be a positive signed int64; login challenge envelopes are not supported.'
            );
        }
    }

    private function displayBytes(string $value): string
    {
        if (preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return $value;
        }

        return 'base64:' . base64_encode($value);
    }
}
