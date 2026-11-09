<?php

namespace App\Support;

final class PolishNip
{
    public static function withoutSeparators(string $value): string
    {
        return preg_replace('/[\s-]+/u', '', $value) ?? '';
    }

    public static function isValid(string $nip): bool
    {
        if (preg_match('/^\d{10}$/D', $nip) !== 1 || $nip === '0000000000') {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $nip[$index]) * $weight;
        }
        $checksum = $sum % 11;

        return $checksum !== 10 && $checksum === (int) $nip[9];
    }
}
