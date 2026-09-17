<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;

final class InvoiceGtuCodes
{
    public const ALLOWED = ['GTU_01', 'GTU_02', 'GTU_03', 'GTU_04', 'GTU_05', 'GTU_06', 'GTU_07',
        'GTU_08', 'GTU_09', 'GTU_10', 'GTU_11', 'GTU_12', 'GTU_13'];

    public static function normalize(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvoiceDomainException('invoice_gtu_invalid', 'GTU musi być listą kodów GTU_01–GTU_13.');
        }
        $result = [];
        foreach ($value as $code) {
            if (! is_string($code) || ! in_array($normalized = strtoupper(trim($code)), self::ALLOWED, true)) {
                throw new InvoiceDomainException('invoice_gtu_invalid', 'Wybierz prawidłowe kody GTU_01–GTU_13.');
            }
            $result[] = $normalized;
        }
        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }
}
