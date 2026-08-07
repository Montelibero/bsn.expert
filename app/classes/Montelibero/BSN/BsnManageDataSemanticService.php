<?php

namespace Montelibero\BSN;

use Soneso\StellarSDK\Xdr\XdrDataEntry;
use Soneso\StellarSDK\Xdr\XdrLedgerEntry;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryChangeType;
use Soneso\StellarSDK\Xdr\XdrLedgerEntryType;
use Soneso\StellarSDK\Xdr\XdrOperationMeta;
use Soneso\StellarSDK\Xdr\XdrTransactionMeta;

final class BsnManageDataSemanticService
{
    /**
     * Horizon account data is base64 encoded. The returned state can be used as
     * one account entry in the overlay accepted by analyzeAndApply().
     *
     * @param array<string, mixed> $encoded_data
     * @return array{complete: bool, entries: array<string, string>, known_keys: array<string, bool>}
     */
    public function snapshotFromHorizonData(array $encoded_data): array
    {
        $entries = [];
        foreach ($encoded_data as $name => $encoded_value) {
            if (!is_string($name) || !is_string($encoded_value)) {
                continue;
            }

            $value = base64_decode($encoded_value, true);
            if ($value !== false) {
                $entries[$name] = $value;
            }
        }

        return [
            'complete' => true,
            'entries' => $entries,
            'known_keys' => [],
        ];
    }

    /**
     * Seeds the value observed immediately before an operation. This is used
     * with transaction result metadata and can also be used by an XDR preview.
     *
     * @param array<string, array{complete: bool, entries: array<string, string>, known_keys: array<string, bool>}> $overlay
     */
    public function seedPriorState(
        array &$overlay,
        string $source_account_id,
        string $data_name,
        bool $exists,
        ?string $value = null,
    ): void {
        $overlay[$source_account_id] ??= $this->emptyAccountState();
        $overlay[$source_account_id]['known_keys'][$data_name] = true;

        if ($exists && $value !== null) {
            $overlay[$source_account_id]['entries'][$data_name] = $value;
        } else {
            unset($overlay[$source_account_id]['entries'][$data_name]);
        }
    }

    /**
     * Interprets one ManageData operation and applies it to the supplied state.
     * The caller must pass the effective operation source and the raw XDR value
     * (null means deletion). Keeping the overlay between calls makes operation
     * order explicit for transaction composition previews.
     *
     * @param array<string, array{complete: bool, entries: array<string, string>, known_keys: array<string, bool>}> $overlay
     * @return list<array{
     *     kind: 'set'|'remove'|'remove_unknown',
     *     source_account_id: string,
     *     tag_name: string,
     *     data_name: string,
     *     target_account_id?: string
     * }>
     */
    public function analyzeAndApply(
        string $source_account_id,
        string $data_name,
        ?string $value,
        array &$overlay,
    ): array {
        $tag_name = self::normalizeDataEntryTagName($data_name);
        $state = $overlay[$source_account_id] ?? $this->emptyAccountState();
        $entries = $state['entries'];
        $prior_known = $state['complete'] || isset($state['known_keys'][$data_name]);
        $prior_value = array_key_exists($data_name, $entries) ? $entries[$data_name] : null;
        $prior_target = self::isAccountId($prior_value) ? $prior_value : null;
        $new_target = self::isAccountId($value) ? $value : null;
        $semantics = [];

        if ($tag_name !== null) {
            if ($prior_target !== null && $prior_target !== $new_target) {
                $semantics[] = $this->semantic('remove', $source_account_id, $tag_name, $data_name, $prior_target);
            } elseif ($value === null && !$prior_known) {
                $semantics[] = $this->semantic('remove_unknown', $source_account_id, $tag_name, $data_name);
            }

            if ($new_target !== null) {
                $semantics[] = $this->semantic('set', $source_account_id, $tag_name, $data_name, $new_target);
            }
        }

        $overlay[$source_account_id] = $state;
        $overlay[$source_account_id]['known_keys'][$data_name] = true;
        if ($value === null) {
            unset($overlay[$source_account_id]['entries'][$data_name]);
        } else {
            $overlay[$source_account_id]['entries'][$data_name] = $value;
        }

        return $semantics;
    }

    /**
     * Returns per-operation classic data-entry states recorded before the
     * operation. Result metadata is immutable evidence for published txs.
     *
     * @return array<int, array<string, array<string, array{exists: bool, value: ?string}>>>
     */
    public function priorStatesFromTransactionMeta(?XdrTransactionMeta $Meta): array
    {
        if ($Meta === null) {
            return [];
        }

        $result = [];
        foreach ($this->operationMetas($Meta) as $operation_index => $OperationMeta) {
            $created = [];
            foreach ($OperationMeta->getLedgerEntryChanges() as $Change) {
                $type = $Change->getType()->getValue();
                $Entry = match ($type) {
                    XdrLedgerEntryChangeType::LEDGER_ENTRY_STATE => $Change->getState(),
                    XdrLedgerEntryChangeType::LEDGER_ENTRY_CREATED => $Change->getCreated(),
                    default => null,
                };
                $DataEntry = $this->classicDataEntry($Entry);
                if ($DataEntry === null) {
                    continue;
                }

                $source_account_id = $DataEntry->getAccountID()->getAccountId();
                $data_name = $DataEntry->getDataName();
                if ($type === XdrLedgerEntryChangeType::LEDGER_ENTRY_STATE) {
                    $result[$operation_index][$source_account_id][$data_name] = [
                        'exists' => true,
                        'value' => $DataEntry->getDataValue()->getValue(),
                    ];
                } else {
                    $created[$source_account_id][$data_name] = true;
                }
            }

            foreach ($created as $source_account_id => $data_names) {
                foreach (array_keys($data_names) as $data_name) {
                    if (!isset($result[$operation_index][$source_account_id][$data_name])) {
                        $result[$operation_index][$source_account_id][$data_name] = [
                            'exists' => false,
                            'value' => null,
                        ];
                    }
                }
            }
        }

        return $result;
    }

    public static function normalizeDataEntryTagName(string $entry_name): ?string
    {
        $tag_name = trim(explode(':', $entry_name, 2)[0]);
        $tag_name = preg_replace('/\s*\d+\s*$/', '', $tag_name);
        if ($tag_name === null || $tag_name === '' || preg_match('/^[a-z0-9_]+$/i', $tag_name) !== 1) {
            return null;
        }

        return $tag_name;
    }

    /**
     * @return array{complete: bool, entries: array<string, string>, known_keys: array<string, bool>}
     */
    private function emptyAccountState(): array
    {
        return [
            'complete' => false,
            'entries' => [],
            'known_keys' => [],
        ];
    }

    /**
     * @param 'set'|'remove'|'remove_unknown' $kind
     * @return array{
     *     kind: 'set'|'remove'|'remove_unknown',
     *     source_account_id: string,
     *     tag_name: string,
     *     data_name: string,
     *     target_account_id?: string
     * }
     */
    private function semantic(
        string $kind,
        string $source_account_id,
        string $tag_name,
        string $data_name,
        ?string $target_account_id = null,
    ): array {
        $semantic = [
            'kind' => $kind,
            'source_account_id' => $source_account_id,
            'tag_name' => $tag_name,
            'data_name' => $data_name,
        ];
        if ($target_account_id !== null) {
            $semantic['target_account_id'] = $target_account_id;
        }

        return $semantic;
    }

    private static function isAccountId(?string $value): bool
    {
        return $value !== null && BSN::validateStellarAccountIdFormat($value);
    }

    /**
     * @return list<XdrOperationMeta>
     */
    private function operationMetas(XdrTransactionMeta $Meta): array
    {
        return match ($Meta->getV()) {
            0 => $Meta->getOperations() ?? [],
            1 => $Meta->getV1()?->getOperations() ?? [],
            2 => $Meta->getV2()?->getOperations() ?? [],
            3 => $Meta->getV3()?->getOperations() ?? [],
            default => [],
        };
    }

    private function classicDataEntry(?XdrLedgerEntry $Entry): ?XdrDataEntry
    {
        if ($Entry === null || $Entry->getData()->getType()->getValue() !== XdrLedgerEntryType::DATA) {
            return null;
        }

        return $Entry->getData()->getData();
    }
}
