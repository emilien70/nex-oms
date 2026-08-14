<?php

namespace Modules\Ksef\Services;

use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceTaxIdentityNormalizer;
use Modules\Ksef\Enums\KsefZeroVatClassification;

class KsefFa3TaxTreatmentResolver
{
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

        if ($this->matchesExistingIdentity($existing, $identityKey)) {
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

        if ($identity['vat_code'] !== null) {
            return $base + [
                'status' => 'unsupported',
                'reason' => 'unsupported_vat_code',
                'vat_code' => $identity['vat_code'],
            ];
        }

        $rate = $identity['vat_rate'];
        $standard = [
            '23.00' => '23',
            '22.00' => '22',
            '8.00' => '8',
            '7.00' => '7',
            '5.00' => '5',
        ];

        if (isset($standard[$rate])) {
            return $base + [
                'status' => 'resolved',
                'treatment' => 'standard',
                'fa3_rate' => $standard[$rate],
            ];
        }

        if ($rate === '0.00') {
            [$treatment, $fa3Rate] = match ($zeroClassification) {
                KsefZeroVatClassification::Wdt => ['wdt', '0 WDT'],
                KsefZeroVatClassification::Export => ['export', '0 EX'],
                KsefZeroVatClassification::Domestic => ['domestic_zero', '0 KR'],
            };

            return $base + [
                'status' => 'resolved',
                'treatment' => $treatment,
                'fa3_rate' => $fa3Rate,
            ];
        }

        return $base + [
            'status' => 'unsupported',
            'reason' => 'unsupported_percentage',
            'vat_rate' => $rate,
        ];
    }

    /** @param array<string, mixed>|null $existing */
    private function matchesExistingIdentity(?array $existing, ?string $identityKey): bool
    {
        return $existing !== null
            && is_string($identityKey)
            && ($existing['tax_identity'] ?? null) === $identityKey
            && in_array($existing['status'] ?? null, ['resolved', 'unsupported'], true);
    }
}
