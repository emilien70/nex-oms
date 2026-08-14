<?php

namespace Modules\Ksef\ValueObjects\Fa3;

final readonly class KsefFa3DocumentData
{
    /**
     * @param  array<string, mixed>  $seller
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $invoice
     * @param  array<string, array<string, string>|null>  $taxBuckets
     * @param  array<string, bool>  $annotations
     * @param  array<int, array<string, int|string>>  $lines
     * @param  array<string, string>  $registrations
     * @param  array<string, mixed>|null  $recipient
     * @param  array<int, array{key: string, value: string}>  $additionalDescriptions
     * @param  array<string, mixed>|null  $payment
     * @param  array<string, string>|null  $transactionTerms
     */
    public function __construct(
        public string $generatedAt,
        public array $seller,
        public array $buyer,
        public array $invoice,
        public array $taxBuckets,
        public array $annotations,
        public array $lines,
        public array $registrations,
        public ?array $recipient,
        public array $additionalDescriptions,
        public ?array $payment,
        public ?array $transactionTerms,
    ) {}
}
