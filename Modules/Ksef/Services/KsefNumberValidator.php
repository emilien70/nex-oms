<?php

namespace Modules\Ksef\Services;

final class KsefNumberValidator
{
    public function isValid(string $number): bool
    {
        if (preg_match('/^\d{10}-\d{8}-[0-9A-F]{12}-[0-9A-F]{2}$/', $number) !== 1) {
            return false;
        }

        $checksum = 0;
        foreach (str_split(substr($number, 0, 32)) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return strtoupper(substr($number, -2)) === strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }
}
