<?php

namespace Modules\Ksef\Services;

class KsefFa3BuyerIdentityResolver
{
    private const EU_VAT_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI',
    ];

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    public function resolve(array $buyer): array
    {
        $countryCode = strtoupper(trim((string) ($buyer['country_code'] ?? '')));
        $taxId = $this->compactTaxId($buyer['tax_id'] ?? null);

        if ($taxId === null) {
            return $this->resolved('none', $countryCode !== '' ? $countryCode : null, null);
        }

        if ($countryCode === 'PL') {
            $nip = $this->normalizePolishNip($taxId);

            return $nip !== null
                ? $this->resolved('pl_nip', 'PL', $nip)
                : $this->unresolved('pl_nip_invalid', 'PL');
        }

        $vatPrefix = $this->vatPrefixForCountry($countryCode);
        if ($vatPrefix === null) {
            return $this->unresolved('foreign_tax_identity_unsupported', $countryCode ?: null);
        }

        if (! str_starts_with($taxId, $vatPrefix)) {
            $suppliedPrefix = substr($taxId, 0, 2);

            return $this->unresolved(
                in_array($suppliedPrefix, self::EU_VAT_PREFIXES, true)
                    ? 'eu_vat_country_mismatch'
                    : 'eu_vat_identity_ambiguous',
                $vatPrefix,
            );
        }

        $identifier = substr($taxId, 2);
        if ($identifier === '' || preg_match('/^[0-9A-Z+*]{1,12}$/', $identifier) !== 1) {
            return $this->unresolved('eu_vat_identity_invalid', $vatPrefix);
        }

        return $this->resolved('eu_vat', $vatPrefix, $identifier);
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    public function withSemantics(array $buyer): array
    {
        $buyer['tax_identity'] = $this->resolve($buyer);
        $buyer['subject_flags'] = [
            'version' => 1,
            'jst' => false,
            'vat_group' => false,
        ];

        return $buyer;
    }

    public function normalizePolishNip(mixed $value): ?string
    {
        $taxId = $this->compactTaxId($value);
        if ($taxId === null) {
            return null;
        }

        if (str_starts_with($taxId, 'PL')) {
            $taxId = substr($taxId, 2);
        }

        if (preg_match('/^\d{10}$/', $taxId) !== 1) {
            return null;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $checksum = 0;
        foreach ($weights as $position => $weight) {
            $checksum += ((int) $taxId[$position]) * $weight;
        }

        $control = $checksum % 11;

        return $control !== 10 && $control === (int) $taxId[9] ? $taxId : null;
    }

    private function compactTaxId(mixed $value): ?string
    {
        $taxId = strtoupper(trim((string) $value));
        $taxId = preg_replace('/[\s.-]+/u', '', $taxId);

        return is_string($taxId) && $taxId !== '' ? $taxId : null;
    }

    private function vatPrefixForCountry(string $countryCode): ?string
    {
        $prefix = match ($countryCode) {
            'GR' => 'EL',
            default => $countryCode,
        };

        return $prefix !== 'PL' && in_array($prefix, self::EU_VAT_PREFIXES, true)
            ? $prefix
            : null;
    }

    /** @return array<string, mixed> */
    private function resolved(string $type, ?string $countryCode, ?string $identifier): array
    {
        return [
            'version' => 1,
            'status' => 'resolved',
            'type' => $type,
            'country_code' => $countryCode,
            'identifier' => $identifier,
        ];
    }

    /** @return array<string, mixed> */
    private function unresolved(string $reason, ?string $countryCode): array
    {
        return [
            'version' => 1,
            'status' => 'unresolved',
            'type' => null,
            'country_code' => $countryCode,
            'identifier' => null,
            'reason' => $reason,
        ];
    }
}
