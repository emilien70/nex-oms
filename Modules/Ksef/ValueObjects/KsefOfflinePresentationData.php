<?php

namespace Modules\Ksef\ValueObjects;

use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineBuyerClassification;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;

final readonly class KsefOfflinePresentationData
{
    /**
     * @param  array{name: string, nip: string, address: list<string>}  $seller
     * @param  array{name: string, identity_label: string, identity_value: ?string, address: list<string>}  $buyer
     * @param  list<array{name: string, unit_name: string, quantity: string, unit_price_net: string, total_net: string, vat: string, gtu: ?string}>  $lines
     * @param  list<array{vat: string, net: string, vat_amount: string, gross: string}>  $taxRows
     * @param  list<array{key: string, value: string}>  $additionalDescriptions
     * @param  array<string, string|bool|null>|null  $payment
     */
    public function __construct(
        public KsefEnvironment $environment,
        public KsefOfflineIssuanceProcedure $procedure,
        public KsefOfflineBuyerClassification $buyerClassification,
        public array $seller,
        public array $buyer,
        public string $invoiceNumber,
        public string $issueDate,
        public ?string $saleDate,
        public ?string $placeOfIssue,
        public string $currency,
        public string $totalNet,
        public string $totalVat,
        public string $totalGross,
        public array $lines,
        public array $taxRows,
        public array $additionalDescriptions,
        public ?array $payment,
        public ?string $orderNumber,
        public string $invoiceVerificationUrl,
        public string $certificateVerificationUrl,
        public ?array $correction = null,
    ) {}

    public function testMark(): string
    {
        return match ($this->environment) {
            KsefEnvironment::Test => 'KSeF TEST — DOKUMENT TESTOWY',
            KsefEnvironment::Demo => 'KSeF DEMO — DOKUMENT TESTOWY',
            KsefEnvironment::Production => '',
        };
    }
}
