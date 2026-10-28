<?php

namespace Modules\Integrations\MacroDroid\Services;

use Illuminate\Validation\ValidationException;

class SmsPhoneNumber
{
    public static function normalize(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[+0-9 ().-]+$/D', trim($value))) {
            self::invalid();
        }
        $phone = preg_replace('/[ ().-]/', '', trim($value));
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (preg_match('/^[0-9]{9}$/D', $phone)) {
            $phone = '+48'.$phone;
        } elseif (preg_match('/^48[0-9]{9}$/D', $phone)) {
            $phone = '+'.$phone;
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)
            || (str_starts_with($phone, '+48') && strlen($phone) !== 12)) {
            self::invalid();
        }

        return $phone;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['recipient' => 'Podaj poprawny numer telefonu z prefiksem kraju (polski numer moze miec 9 cyfr).']);
    }
}
