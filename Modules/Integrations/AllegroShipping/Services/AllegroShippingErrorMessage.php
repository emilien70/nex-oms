<?php

namespace Modules\Integrations\AllegroShipping\Services;

final class AllegroShippingErrorMessage
{
    public static function fromPayload(array $payload): ?string
    {
        $messages = [];

        foreach ((array) ($payload['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = self::text($error['userMessage'] ?? null)
                ?? self::text($error['message'] ?? null)
                ?? self::text($error['code'] ?? null);
            if ($message === null) {
                continue;
            }

            $field = match ($path = self::text($error['path'] ?? null)) {
                'receiver.name' => 'Odbiorca',
                'receiver.company' => 'Nazwa firmy odbiorcy',
                'receiver.street' => 'Ulica i numer odbiorcy',
                'receiver.postalCode' => 'Kod pocztowy odbiorcy',
                'receiver.city' => 'Miejscowość odbiorcy',
                'receiver.countryCode' => 'Kraj odbiorcy',
                'receiver.email' => 'E-mail odbiorcy',
                'receiver.phone' => 'Telefon odbiorcy',
                'receiver.point' => 'Punkt odbioru',
                default => $path,
            };

            $messages[] = $field === null ? $message : $field.': '.$message;
        }

        return $messages === [] ? self::text($payload['message'] ?? null) : implode("\n", array_unique($messages));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
