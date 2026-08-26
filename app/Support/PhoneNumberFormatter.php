<?php

namespace App\Support;

class PhoneNumberFormatter
{
    public static function normalize(?string $phone): ?string
    {
        $originalPhone = trim((string) $phone);
        $phone = $originalPhone;

        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[^\d+]+/', '', $phone) ?: '';

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            return self::formatWithCountryCode($digits, self::countryCodeLength($digits, $originalPhone));
        } elseif (strlen($digits) === 9) {
            return self::formatWithCountryCode('48'.$digits, 2);
        } elseif (str_starts_with($digits, '48')) {
            return self::formatWithCountryCode($digits, 2);
        } elseif (str_starts_with($digits, '49')) {
            return self::formatWithCountryCode($digits, 2);
        } elseif (str_starts_with($digits, '1') && strlen($digits) === 11) {
            return self::formatWithCountryCode($digits, 1);
        } else {
            return self::formatWithCountryCode($digits, 2);
        }
    }

    private static function countryCodeLength(string $digits, string $originalPhone): int
    {
        if (preg_match('/^\+(\d{1,3})(?:\D|$)/', $originalPhone, $matches)) {
            return strlen($matches[1]);
        }

        if (str_starts_with($digits, '48') || str_starts_with($digits, '49')) {
            return 2;
        }

        if (str_starts_with($digits, '1') && strlen($digits) === 11) {
            return 1;
        }

        return 2;
    }

    private static function formatWithCountryCode(string $digits, int $countryCodeLength): string
    {
        $countryCode = substr($digits, 0, $countryCodeLength);
        $subscriberNumber = substr($digits, $countryCodeLength);

        if ($subscriberNumber === '') {
            return '+'.$countryCode;
        }

        return '+'.$countryCode.' '.trim(chunk_split($subscriberNumber, 3, ' '));
    }
}
