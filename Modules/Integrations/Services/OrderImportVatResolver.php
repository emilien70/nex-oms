<?php

namespace Modules\Integrations\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class OrderImportVatResolver
{
    public function __construct(private readonly InvoiceFinancialValueValidator $financial) {}

    /** @return array{status: string, rate: ?string} */
    public function source(mixed $rate, bool $exempt = false): array
    {
        if ($exempt) {
            return ['status' => 'special', 'rate' => null];
        }
        if ($rate === null || $rate === '') {
            return ['status' => 'missing', 'rate' => null];
        }
        if (! is_string($rate) && ! is_int($rate)) {
            return ['status' => 'invalid', 'rate' => null];
        }

        try {
            $value = BigDecimal::of($rate)->toScale(2, RoundingMode::Unnecessary);
            if ($value->isNegative() || $value->isGreaterThan(100)) {
                return ['status' => 'invalid', 'rate' => null];
            }

            return ['status' => 'valid', 'rate' => (string) $value];
        } catch (MathException|\InvalidArgumentException) {
            return ['status' => 'invalid', 'rate' => null];
        }
    }

    /**
     * @param  array{status: string, rate: ?string}  $source
     * @return array{rate: ?string, source: ?string, source_status: string, catalog_invalid: bool}
     */
    public function resolve(array $source, mixed $catalogRate, bool $useCatalog, bool $catalogFirst, bool $domestic, mixed $defaultRate): array
    {
        $result = ['rate' => null, 'source' => null, 'source_status' => $source['status'], 'catalog_invalid' => false];
        if (! in_array($source['status'], ['missing', 'valid'], true)) {
            return $result;
        }

        $catalog = null;
        if ($useCatalog && $domestic && $catalogRate !== null) {
            try {
                $catalog = $this->financial->assertVatPercentage($catalogRate);
            } catch (InvoiceDomainException) {
                $result['catalog_invalid'] = true;
            }
        }

        $candidates = $catalogFirst
            ? ['catalog' => $catalog, 'channel' => $source['rate']]
            : ['channel' => $source['rate'], 'catalog' => $catalog];
        foreach ($candidates as $origin => $rate) {
            if ($rate !== null) {
                return array_replace($result, ['rate' => $rate, 'source' => $origin]);
            }
        }

        if ($domestic && $defaultRate !== null) {
            try {
                return array_replace($result, [
                    'rate' => $this->financial->assertVatPercentage($defaultRate), 'source' => 'default',
                ]);
            } catch (InvoiceDomainException) {
                // An invalid historical setting is not permission to guess a tax rate.
            }
        }

        return $result;
    }
}
