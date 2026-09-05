<?php

namespace Modules\Ksef\Services\Fa3;

use InvalidArgumentException;

final class KsefFa3CorrectionTaxBuckets
{
    public const FIELDS = [
        'standard_1' => ['net' => 'P_13_1', 'vat' => 'P_14_1', 'pln_vat' => 'P_14_1W'],
        'standard_2' => ['net' => 'P_13_2', 'vat' => 'P_14_2', 'pln_vat' => 'P_14_2W'],
        'standard_3' => ['net' => 'P_13_3', 'vat' => 'P_14_3', 'pln_vat' => 'P_14_3W'],
        'domestic_zero' => ['net' => 'P_13_6_1'],
        'wdt' => ['net' => 'P_13_6_2'],
        'export' => ['net' => 'P_13_6_3'],
    ];

    public static function forRate(string $rate): string
    {
        return match ($rate) {
            '23', '22' => 'standard_1',
            '8', '7' => 'standard_2',
            '5' => 'standard_3',
            '0 KR' => 'domestic_zero',
            '0 WDT' => 'wdt',
            '0 EX' => 'export',
            default => throw new InvalidArgumentException('Unsupported correction tax treatment.'),
        };
    }
}
