<?php

declare(strict_types=1);

namespace Montelibero\BSN;

final readonly class TransactionConsolidationItem
{
    /**
     * @param list<string> $warnings
     * @param list<array{
     *     index: int,
     *     type: int,
     *     class: class-string|string,
     *     source: ?string,
     *     effective_source: string,
     *     summary: string,
     *     details: array<string, int|float|string|bool|null>
     * }> $operations
     */
    public function __construct(
        public string $id,
        public string $fingerprint,
        public string $xdr,
        public string $source,
        public TransactionConsolidationMemo $memo,
        public array $warnings,
        public int $signature_count,
        public int $operation_count,
        public array $operations,
        public string $envelope_type,
    ) {
    }
}
