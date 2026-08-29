<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3CorrectionData
{
    /**
     * @param  array<string, mixed>|null  $buyerBefore
     * @param  array<string, mixed>  $buyerAfter
     * @param  array<int, KsefFa3CorrectionLine>  $changedLines
     * @param  array{net: string, vat: string, gross: string, taxSummary: array<int, mixed>}  $differenceTotals
     */
    public function __construct(
        public string $kind,
        public string $reason,
        public ?int $type,
        public KsefFa3CorrectionRootInvoice $rootInvoice,
        public ?array $buyerBefore,
        public array $buyerAfter,
        public ?string $buyerLinkId,
        public array $changedLines,
        public array $differenceTotals,
    ) {}
}
