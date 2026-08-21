<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

final class TransactionConsolidationStore
{
    public const COLLECTION = 'transaction_consolidation_drafts';
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
    ) {
    }

    /**
     * @return array{
     *     schema_version: int,
     *     revision: int,
     *     items: list<array{
     *         id: string,
     *         xdr: string,
     *         fingerprint: string,
     *         enabled: bool,
     *         enabled_operations: list<int>
     *     }>,
     *     order: list<string>,
     *     settings: array{
     *         source_mode: string,
     *         source_account: string,
     *         memo_choice: string,
     *         custom_memo: string,
     *         seq_num: string,
     *         sponsor_reserves: bool,
     *         preconditions_ack: bool
     *     },
     *     created_at: ?int,
     *     updated_at: ?int
     * }
     */
    public function get(string $owner_account_id): array
    {
        $owner_account_id = $this->normalizeOwnerAccountId($owner_account_id);
        $Query = new Query($this->ownerFilter($owner_account_id), ['limit' => 1]);
        $document = current($this->Mongo->executeQuery($this->namespace(), $Query)->toArray()) ?: null;

        if ($document === null) {
            return $this->emptySnapshot();
        }

        $document = $this->normalizeMongoValue($document);
        $state = $this->normalizeState([
            'items' => $document['items'] ?? [],
            'order' => $document['order'] ?? [],
            'settings' => $document['settings'] ?? [],
        ]);

        return [
            'schema_version' => (int) ($document['schema_version'] ?? self::SCHEMA_VERSION),
            'revision' => max(0, (int) ($document['revision'] ?? 0)),
            'items' => $state['items'],
            'order' => $state['order'],
            'settings' => $state['settings'],
            'created_at' => $this->timestamp($document['created_at'] ?? null),
            'updated_at' => $this->timestamp($document['updated_at'] ?? null),
        ];
    }

    /**
     * Replaces the complete draft when its revision still matches.
     *
     * @return int|null The new revision, or null when the compare-and-swap lost.
     */
    public function save(string $owner_account_id, array $state, int $expected_revision): ?int
    {
        $owner_account_id = $this->normalizeOwnerAccountId($owner_account_id);
        $expected_revision = $this->normalizeExpectedRevision($expected_revision);
        $state = $this->normalizeState($state);
        $Now = $this->now();

        $Bulk = new BulkWrite();
        $Bulk->update(
            $this->casFilter($owner_account_id, $expected_revision),
            [
                '$set' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'items' => $state['items'],
                    'order' => $state['order'],
                    'settings' => $state['settings'],
                    'updated_at' => $Now,
                ],
                '$setOnInsert' => [
                    '_id' => $owner_account_id,
                    'owner_account_id' => $owner_account_id,
                    'created_at' => $Now,
                ],
                '$inc' => ['revision' => 1],
            ],
            ['upsert' => $expected_revision === 0]
        );

        return $this->executeCas($Bulk, $expected_revision);
    }

    /**
     * Atomically appends one XDR item and its order entry, unless its XDR is
     * already present. Passing null uses the latest observed revision for one
     * CAS attempt; callers may retry after a conflict.
     *
     * @return array{revision: int, changed: bool}|null Null means CAS conflict.
     */
    public function addItem(
        string $owner_account_id,
        array $item,
        ?int $expected_revision = null,
    ): ?array {
        $owner_account_id = $this->normalizeOwnerAccountId($owner_account_id);
        $item = $this->normalizeItem($item);
        if ($item === null) {
            throw new \InvalidArgumentException('A consolidation item requires a non-empty id and XDR.');
        }

        if ($expected_revision === null) {
            $expected_revision = $this->get($owner_account_id)['revision'];
        }
        $expected_revision = $this->normalizeExpectedRevision($expected_revision);
        $Now = $this->now();

        $filter = $this->casFilter($owner_account_id, $expected_revision);
        $filter['items.fingerprint'] = ['$ne' => $item['fingerprint']];
        $filter['items.id'] = ['$ne' => $item['id']];

        $Bulk = new BulkWrite();
        $Bulk->update(
            $filter,
            [
                '$set' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'updated_at' => $Now,
                ],
                '$setOnInsert' => [
                    '_id' => $owner_account_id,
                    'owner_account_id' => $owner_account_id,
                    'settings' => $this->normalizeSettings([]),
                    'created_at' => $Now,
                ],
                '$push' => [
                    'items' => $item,
                    'order' => $item['id'],
                ],
                '$inc' => ['revision' => 1],
            ],
            ['upsert' => $expected_revision === 0]
        );

        $new_revision = $this->executeCas($Bulk, $expected_revision);
        if ($new_revision !== null) {
            return ['revision' => $new_revision, 'changed' => true];
        }

        $snapshot = $this->get($owner_account_id);
        foreach ($snapshot['items'] as $stored_item) {
            if (
                hash_equals($stored_item['fingerprint'], $item['fingerprint'])
                || hash_equals($stored_item['id'], $item['id'])
            ) {
                return ['revision' => $snapshot['revision'], 'changed' => false];
            }
        }

        return null;
    }

    /**
     * Clears the draft while retaining its document and monotonic revision.
     *
     * @return int|null The new revision, or null when the compare-and-swap lost.
     */
    public function clear(string $owner_account_id, int $expected_revision): ?int
    {
        return $this->save($owner_account_id, $this->emptyState(), $expected_revision);
    }

    /**
     * @return array{
     *     items: list<array{
     *         id: string,
     *         xdr: string,
     *         fingerprint: string,
     *         enabled: bool,
     *         enabled_operations: list<int>
     *     }>,
     *     order: list<string>,
     *     settings: array{
     *         source_mode: string,
     *         source_account: string,
     *         memo_choice: string,
     *         custom_memo: string,
     *         seq_num: string,
     *         sponsor_reserves: bool,
     *         preconditions_ack: bool
     *     }
     * }
     */
    private function normalizeState(array $state): array
    {
        $items = [];
        $item_ids = [];
        $fingerprints = [];
        $raw_items = $state['items'] ?? [];
        if (is_array($raw_items)) {
            foreach ($raw_items as $raw_item) {
                $item = $this->normalizeItem($raw_item);
                if (
                    $item === null
                    || isset($item_ids[$item['id']])
                    || isset($fingerprints[$item['fingerprint']])
                ) {
                    continue;
                }

                $items[] = $item;
                $item_ids[$item['id']] = true;
                $fingerprints[$item['fingerprint']] = true;
            }
        }

        $order = [];
        $ordered_ids = [];
        $raw_order = $state['order'] ?? [];
        if (is_array($raw_order)) {
            foreach ($raw_order as $raw_item_id) {
                if (!is_scalar($raw_item_id)) {
                    continue;
                }
                $item_id = trim((string) $raw_item_id);
                if (!isset($item_ids[$item_id]) || isset($ordered_ids[$item_id])) {
                    continue;
                }
                $order[] = $item_id;
                $ordered_ids[$item_id] = true;
            }
        }
        foreach ($items as $item) {
            if (!isset($ordered_ids[$item['id']])) {
                $order[] = $item['id'];
            }
        }

        return [
            'items' => $items,
            'order' => $order,
            'settings' => $this->normalizeSettings($state['settings'] ?? []),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     xdr: string,
     *     fingerprint: string,
     *     enabled: bool,
     *     enabled_operations: list<int>
     * }|null
     */
    private function normalizeItem(mixed $raw_item): ?array
    {
        if (is_object($raw_item)) {
            $raw_item = get_object_vars($raw_item);
        }
        if (!is_array($raw_item)) {
            return null;
        }

        $id = is_scalar($raw_item['id'] ?? null) ? trim((string) $raw_item['id']) : '';
        $xdr = is_scalar($raw_item['xdr'] ?? null) ? trim((string) $raw_item['xdr']) : '';
        if ($id === '' || $xdr === '' || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) !== 1) {
            return null;
        }

        $enabled_operations = [];
        $seen_operations = [];
        $raw_operations = $raw_item['enabled_operations'] ?? [];
        if (is_array($raw_operations)) {
            foreach ($raw_operations as $raw_operation) {
                if (
                    (!is_int($raw_operation) && !(is_string($raw_operation) && ctype_digit($raw_operation)))
                    || (int) $raw_operation < 0
                ) {
                    continue;
                }
                $operation = (int) $raw_operation;
                if (!isset($seen_operations[$operation])) {
                    $enabled_operations[] = $operation;
                    $seen_operations[$operation] = true;
                }
            }
        }
        sort($enabled_operations, SORT_NUMERIC);

        return [
            'id' => $id,
            'xdr' => $xdr,
            'fingerprint' => hash('sha256', $xdr),
            'enabled' => $this->normalizeBool($raw_item['enabled'] ?? true),
            'enabled_operations' => $enabled_operations,
        ];
    }

    /**
     * @return array{
     *     source_mode: string,
     *     source_account: string,
     *     memo_choice: string,
     *     custom_memo: string,
     *     seq_num: string,
     *     sponsor_reserves: bool,
     *     preconditions_ack: bool
     * }
     */
    private function normalizeSettings(mixed $raw_settings): array
    {
        if (is_object($raw_settings)) {
            $raw_settings = get_object_vars($raw_settings);
        }
        if (!is_array($raw_settings)) {
            $raw_settings = [];
        }

        return [
            'source_mode' => $this->scalarString($raw_settings['source_mode'] ?? 'current_account', true),
            'source_account' => strtoupper($this->scalarString($raw_settings['source_account'] ?? '', true)),
            'memo_choice' => $this->scalarString($raw_settings['memo_choice'] ?? 'custom', true),
            'custom_memo' => $this->scalarString($raw_settings['custom_memo'] ?? ''),
            'seq_num' => $this->scalarString($raw_settings['seq_num'] ?? '', true),
            'sponsor_reserves' => $this->normalizeBool($raw_settings['sponsor_reserves'] ?? false),
            'preconditions_ack' => $this->normalizeBool($raw_settings['preconditions_ack'] ?? false),
        ];
    }

    private function scalarString(mixed $value, bool $trim = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string) $value;
        return $trim ? trim($value) : $value;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function ownerFilter(string $owner_account_id): array
    {
        return [
            '_id' => $owner_account_id,
            'owner_account_id' => $owner_account_id,
        ];
    }

    /** @return array<string, mixed> */
    private function casFilter(string $owner_account_id, int $expected_revision): array
    {
        $filter = $this->ownerFilter($owner_account_id);
        if ($expected_revision === 0) {
            $filter['$or'] = [
                ['revision' => 0],
                ['revision' => ['$exists' => false]],
            ];
        } else {
            $filter['revision'] = $expected_revision;
        }

        return $filter;
    }

    private function executeCas(BulkWrite $Bulk, int $expected_revision): ?int
    {
        try {
            $Result = $this->Mongo->executeBulkWrite($this->namespace(), $Bulk);
        } catch (BulkWriteException $Exception) {
            if ($Exception->getCode() === 11000) {
                return null;
            }
            throw $Exception;
        }

        if ($Result->getModifiedCount() !== 1 && $Result->getUpsertedCount() !== 1) {
            return null;
        }

        return $expected_revision + 1;
    }

    private function normalizeOwnerAccountId(string $owner_account_id): string
    {
        $owner_account_id = strtoupper(trim($owner_account_id));
        if (!BSN::validateStellarAccountIdFormat($owner_account_id)) {
            throw new \InvalidArgumentException('Invalid consolidation draft owner account id.');
        }

        return $owner_account_id;
    }

    private function normalizeExpectedRevision(int $expected_revision): int
    {
        if ($expected_revision < 0) {
            throw new \InvalidArgumentException('Expected consolidation draft revision cannot be negative.');
        }

        return $expected_revision;
    }

    private function now(): UTCDateTime
    {
        return new UTCDateTime((int) (microtime(true) * 1000));
    }

    private function timestamp(mixed $value): ?int
    {
        return $value instanceof UTCDateTime ? $value->toDateTime()->getTimestamp() : null;
    }

    /**
     * @return array{
     *     items: list<array>,
     *     order: list<string>,
     *     settings: array{
     *         source_mode: string,
     *         source_account: string,
     *         memo_choice: string,
     *         custom_memo: string,
     *         seq_num: string,
     *         sponsor_reserves: bool,
     *         preconditions_ack: bool
     *     }
     * }
     */
    private function emptyState(): array
    {
        return [
            'items' => [],
            'order' => [],
            'settings' => $this->normalizeSettings([]),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => 0,
            ...$this->emptyState(),
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            return $value;
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

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, self::COLLECTION);
    }
}
