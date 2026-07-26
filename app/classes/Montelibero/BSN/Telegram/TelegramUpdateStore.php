<?php

declare(strict_types=1);

namespace Montelibero\BSN\Telegram;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;

class TelegramUpdateStore
{
    public const COLLECTION = 'telegram_updates';

    public const ENQUEUE_ACCEPTED = 'accepted';
    public const ENQUEUE_DUPLICATE = 'duplicate';

    public const STATE_PENDING = 'pending';
    public const STATE_PROCESSING = 'processing';
    public const STATE_RETRY = 'retry';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_DELIVERY_UNCERTAIN = 'delivery_uncertain';

    public const PHASE_RESPOND = 'respond';
    public const PHASE_RECORD_USAGE = 'record_usage';

    private const LEASE_SECONDS = 300;
    private const RETENTION_SECONDS = 30 * 86400;
    private const WRITE_TIMEOUT_MILLISECONDS = 5000;

    private readonly WriteConcern $WriteConcern;

    public function __construct(
        private readonly Manager $Mongo,
        private readonly string $database,
    ) {
        // Telegram must only receive 2xx after the inbox write is journaled.
        $this->WriteConcern = new WriteConcern(1, self::WRITE_TIMEOUT_MILLISECONDS, true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return self::ENQUEUE_ACCEPTED|self::ENQUEUE_DUPLICATE
     */
    public function enqueue(array $payload): string
    {
        $update_id = $this->requireUpdateId($payload['update_id'] ?? null);
        $Now = $this->now();
        $Bulk = new BulkWrite();
        $Bulk->insert([
            '_id' => $update_id,
            'update_id' => $update_id,
            'payload' => $payload,
            'state' => self::STATE_PENDING,
            'phase' => self::PHASE_RESPOND,
            'attempt_count' => 0,
            'received_at' => $Now,
            'available_at' => $Now,
            'purge_at' => $this->after(self::RETENTION_SECONDS),
        ]);

        try {
            $this->write($Bulk);
        } catch (BulkWriteException $Exception) {
            if ($this->isDuplicateKey($Exception)) {
                return self::ENQUEUE_DUPLICATE;
            }

            throw $Exception;
        }

        return self::ENQUEUE_ACCEPTED;
    }

    /**
     * @return array{
     *     update_id: string,
     *     lease_token: string,
     *     attempt_count: int,
     *     payload: array<string, mixed>,
     *     phase: self::PHASE_*,
     *     pending_usage: ?array<string, mixed>,
     *     pending_effect: array<string, mixed>
     * }|null
     */
    public function claimNextDue(): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $Now = $this->now();
            $filter = $this->claimableFilter($Now);
            $Query = new Query($filter, [
                'sort' => ['available_at' => 1, 'received_at' => 1],
                'limit' => 1,
            ]);
            $Cursor = $this->Mongo->executeQuery($this->namespace(), $Query);
            $Cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
            $document = current($Cursor->toArray()) ?: null;
            if (!is_array($document)) {
                return null;
            }

            $update_id = $this->requireUpdateId($document['_id'] ?? null);
            $lease_token = bin2hex(random_bytes(16));
            $Bulk = new BulkWrite();
            $claim_filter = $filter;
            $claim_filter['_id'] = $update_id;
            $Bulk->update(
                $claim_filter,
                [
                    '$set' => [
                        'state' => self::STATE_PROCESSING,
                        'lease_token' => $lease_token,
                        'lease_until' => $this->after(self::LEASE_SECONDS),
                        'started_at' => $Now,
                    ],
                    '$inc' => ['attempt_count' => 1],
                    '$unset' => ['retry_after' => ''],
                ],
                ['limit' => 1]
            );
            $Result = $this->write($Bulk);
            if ($Result->getModifiedCount() !== 1) {
                continue;
            }

            $payload = $document['payload'] ?? null;
            if (!is_array($payload)) {
                throw new \RuntimeException('Telegram update document has no valid payload.');
            }

            return [
                'update_id' => $update_id,
                'lease_token' => $lease_token,
                'attempt_count' => ((int) ($document['attempt_count'] ?? 0)) + 1,
                'payload' => $payload,
                'phase' => ($document['phase'] ?? null) === self::PHASE_RECORD_USAGE
                    ? self::PHASE_RECORD_USAGE
                    : self::PHASE_RESPOND,
                'pending_usage' => is_array($document['pending_usage'] ?? null)
                    ? $document['pending_usage']
                    : null,
                'pending_effect' => is_array($document['pending_effect'] ?? null)
                    ? $document['pending_effect']
                    : [],
            ];
        }

        return null;
    }

    /**
     * Persists the post-delivery phase before writing analytics. If analytics
     * storage is temporarily unavailable, a reclaimed job resumes from this
     * phase and never sends the Telegram response a second time.
     *
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $effect
     */
    public function markUsagePending(
        string $update_id,
        string $lease_token,
        array $usage,
        array $effect,
    ): bool {
        $update_id = $this->requireUpdateId($update_id);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $lease_token)) {
            throw new \InvalidArgumentException('Invalid Telegram update lease token.');
        }
        if ($usage === []) {
            throw new \InvalidArgumentException('Telegram pending usage payload must not be empty.');
        }

        $Bulk = new BulkWrite();
        $Bulk->update(
            [
                '_id' => $update_id,
                'state' => self::STATE_PROCESSING,
                'lease_token' => $lease_token,
                'phase' => self::PHASE_RESPOND,
            ],
            [
                '$set' => [
                    'phase' => self::PHASE_RECORD_USAGE,
                    'pending_usage' => $usage,
                    'pending_effect' => $effect,
                    'response_delivered_at' => $this->now(),
                ],
            ],
            ['limit' => 1]
        );

        return $this->write($Bulk)->getModifiedCount() === 1;
    }

    /**
     * @param array<string, mixed> $effect
     */
    public function complete(string $update_id, string $lease_token, array $effect = []): bool
    {
        return $this->finish(
            $update_id,
            $lease_token,
            self::STATE_COMPLETED,
            [
                'completed_at' => $this->now(),
                'effect' => $effect,
                'last_error' => null,
            ]
        );
    }

    public function retry(string $update_id, string $lease_token, string $error, int $delay_seconds): bool
    {
        $retry_after = $this->after(max(1, $delay_seconds));

        return $this->finish(
            $update_id,
            $lease_token,
            self::STATE_RETRY,
            [
                'available_at' => $retry_after,
                'retry_after' => $retry_after,
                'last_failure_at' => $this->now(),
                'last_error' => $this->sanitizeError($error),
            ]
        );
    }

    public function fail(string $update_id, string $lease_token, string $error): bool
    {
        return $this->finish(
            $update_id,
            $lease_token,
            self::STATE_FAILED,
            [
                'failed_at' => $this->now(),
                'last_error' => $this->sanitizeError($error),
            ]
        );
    }

    public function deliveryUncertain(string $update_id, string $lease_token, string $error): bool
    {
        return $this->finish(
            $update_id,
            $lease_token,
            self::STATE_DELIVERY_UNCERTAIN,
            [
                'delivery_uncertain_at' => $this->now(),
                'last_error' => $this->sanitizeError($error),
            ]
        );
    }

    /**
     * @param array<string, mixed> $set
     */
    private function finish(
        string $update_id,
        string $lease_token,
        string $state,
        array $set,
    ): bool {
        $update_id = $this->requireUpdateId($update_id);
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $lease_token)) {
            throw new \InvalidArgumentException('Invalid Telegram update lease token.');
        }

        $set['state'] = $state;
        $Bulk = new BulkWrite();
        $unset = [
            'lease_token' => '',
            'lease_until' => '',
        ];
        if ($state === self::STATE_COMPLETED) {
            $unset += [
                'phase' => '',
                'pending_usage' => '',
                'pending_effect' => '',
                // Clean up reaction metadata written by pre-release workers.
                'pending_success_reaction' => '',
                'pending_clear_reaction' => '',
            ];
        }

        $Bulk->update(
            [
                '_id' => $update_id,
                'state' => self::STATE_PROCESSING,
                'lease_token' => $lease_token,
            ],
            [
                '$set' => $set,
                '$unset' => $unset,
            ],
            ['limit' => 1]
        );

        return $this->write($Bulk)->getModifiedCount() === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function claimableFilter(UTCDateTime $Now): array
    {
        return [
            '$or' => [
                [
                    'state' => ['$in' => [self::STATE_PENDING, self::STATE_RETRY]],
                    'available_at' => ['$lte' => $Now],
                ],
                [
                    'state' => self::STATE_PROCESSING,
                    'lease_until' => ['$lte' => $Now],
                ],
            ],
        ];
    }

    private function requireUpdateId(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Telegram update_id.');
        }

        return $value;
    }

    private function sanitizeError(string $error): string
    {
        $error = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error) ?? '';
        $error = trim($error);

        return mb_substr($error === '' ? 'unknown error' : $error, 0, 500, 'UTF-8');
    }

    private function isDuplicateKey(BulkWriteException $Exception): bool
    {
        if ($Exception->getCode() === 11000) {
            return true;
        }

        foreach ($Exception->getWriteResult()->getWriteErrors() as $WriteError) {
            if ($WriteError->getCode() === 11000) {
                return true;
            }
        }

        return false;
    }

    private function now(): UTCDateTime
    {
        return new UTCDateTime((int) (microtime(true) * 1000));
    }

    private function after(int $seconds): UTCDateTime
    {
        return new UTCDateTime((int) ((microtime(true) + $seconds) * 1000));
    }

    private function write(BulkWrite $Bulk): \MongoDB\Driver\WriteResult
    {
        return $this->Mongo->executeBulkWrite(
            $this->namespace(),
            $Bulk,
            ['writeConcern' => $this->WriteConcern]
        );
    }

    private function namespace(): string
    {
        return sprintf('%s.%s', $this->database, self::COLLECTION);
    }
}
