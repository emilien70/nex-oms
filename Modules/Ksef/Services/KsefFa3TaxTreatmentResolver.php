<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Enums\KsefZeroVatClassification;

class KsefFa3TaxTreatmentResolver
{
    private const STANDARD_RATES = [
        '23.00' => '23',
        '22.00' => '22',
        '8.00' => '8',
        '7.00' => '7',
        '5.00' => '5',
    ];

    private const ZERO_RATES = [
        'domestic_zero' => '0 KR',
        'wdt' => '0 WDT',
        'export' => '0 EX',
    ];

    public function __construct(
        private readonly InvoiceTaxIdentityNormalizer $taxIdentity,
    ) {}

    /** @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    public function resolve(
        InvoiceItem $item,
        KsefZeroVatClassification $zeroClassification,
        ?array $existing = null,
    ): array {
        $identity = $this->taxIdentity->normalize($item->vat_rate, $item->vat_code);
        $identityKey = $this->taxIdentity->key($identity);

        if ($this->hasSameTaxIdentity($existing, $identityKey)) {
            return array_replace($existing, [
                'invoice_item_id' => $item->getKey(),
                'position' => $item->position,
            ]);
        }

        $base = [
            'invoice_item_id' => $item->getKey(),
            'position' => $item->position,
            'tax_identity' => $identityKey,
        ];

        $zeroTreatment = match ($zeroClassification) {
            KsefZeroVatClassification::Wdt => 'wdt',
            KsefZeroVatClassification::Export => 'export',
            KsefZeroVatClassification::Domestic => 'domestic_zero',
        };

        return $base + $this->canonicalSemantics($identity, $zeroTreatment);
    }

    /** @param array<string, mixed> $treatment */
    public function isCanonical(InvoiceItem $item, array $treatment): bool
    {
        $identity = $this->taxIdentity->normalize($item->vat_rate, $item->vat_code);
        $identityKey = $this->taxIdentity->key($identity);
        $zeroTreatment = is_string($treatment['treatment'] ?? null)
            ? $treatment['treatment']
            : null;
        $semantics = $this->canonicalSemantics($identity, $zeroTreatment);
        $expected = [
            'invoice_item_id' => $item->getKey(),
            'position' => $item->position,
            'tax_identity' => $identityKey,
        ] + $semantics;

        ksort($expected);
        ksort($treatment);

        return $treatment === $expected;
    }

    /** @param array<string, mixed>|null $existing */
    private function hasSameTaxIdentity(?array $existing, ?string $identityKey): bool
    {
        return $existing !== null
            && is_string($identityKey)
            && ($existing['tax_identity'] ?? null) === $identityKey;
    }

    /**
     * @param  array{vat_rate: ?string, vat_code: ?string}  $identity
     * @return array<string, mixed>
     */
    private function canonicalSemantics(array $identity, ?string $zeroTreatment): array
    {
        if ($identity['vat_code'] !== null) {
            return [
                'status' => 'unsupported',
                'reason' => 'unsupported_vat_code',
                'vat_code' => $identity['vat_code'],
            ];
        }

        $rate = $identity['vat_rate'];
        if (isset(self::STANDARD_RATES[$rate])) {
            return [
                'status' => 'resolved',
                'treatment' => 'standard',
                'fa3_rate' => self::STANDARD_RATES[$rate],
            ];
        }

        if ($rate === '0.00' && isset(self::ZERO_RATES[$zeroTreatment])) {
            return [
                'status' => 'resolved',
                'treatment' => $zeroTreatment,
                'fa3_rate' => self::ZERO_RATES[$zeroTreatment],
            ];
        }

        return [
            'status' => 'unsupported',
            'reason' => 'unsupported_percentage',
            'vat_rate' => $rate,
        ];
    }
}
