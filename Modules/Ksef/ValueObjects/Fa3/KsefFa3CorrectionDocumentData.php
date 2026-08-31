<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3CorrectionDocumentData
{
    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyerAfter
     * @param  array<string, mixed>|null  $buyerBefore
     * @param  array<string, mixed>  $invoice
     * @param  array<string, array<string, string>|null>  $taxBuckets
     * @param  array<string, bool>  $annotations
     * @param  list<array{position: int, before: array<string, string|int>, after: array<string, string|int>}>  $lines
     */
    public function __construct(
        public string $generatedAt,
        public array $seller,
        public array $buyerAfter,
        public ?array $buyerBefore,
        public ?string $buyerLinkId,
        public array $invoice,
        public array $taxBuckets,
        public array $annotations,
        public array $lines,
        public KsefFa3CorrectionSourceReference $sourceReference,
        public string $reason,
    ) {}
}
