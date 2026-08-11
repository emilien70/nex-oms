<?php

namespace Modules\Invoices\Services;

class InvoiceTaxIdentityNormalizer
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /** @return array{vat_rate: ?string, vat_code: ?string} */
    public function normalize(mixed $vatRate, mixed $vatCode): array
    {
        $code = trim((string) $vatCode);

        if ($code !== '') {
            return [
                'vat_rate' => null,
                'vat_code' => strtoupper($code),
            ];
        }

        $rate = trim((string) $vatRate);

        return [
            'vat_rate' => $rate === '' ? null : $this->decimal->normalize($rate, 2),
            'vat_code' => null,
        ];
    }

    /** @param array{vat_rate: ?string, vat_code: ?string} $identity */
    public function key(array $identity): ?string
    {
        if ($identity['vat_code'] !== null) {
            return 'code:'.$identity['vat_code'];
        }

        return $identity['vat_rate'] !== null ? 'rate:'.$identity['vat_rate'] : null;
    }
}
