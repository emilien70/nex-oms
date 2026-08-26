<?php

namespace App\Support;

class AddressLineFormatter
{
    public static function formatAddressLine(?string $street, ?string $buildingNumber, ?string $apartmentNumber): ?string
    {
        $line = trim((string) $street.' '.(string) $buildingNumber);

        if ($apartmentNumber !== null && trim($apartmentNumber) !== '') {
            $line .= '/'.trim($apartmentNumber);
        }

        return $line !== '' ? $line : null;
    }

    /**
     * @return array{street: ?string, building_number: ?string, apartment_number: ?string}
     */
    public static function parseAddressLine(?string $addressLine): array
    {
        $addressLine = trim((string) $addressLine);

        if ($addressLine === '') {
            return [
                'street' => null,
                'building_number' => null,
                'apartment_number' => null,
            ];
        }

        if (! preg_match('/^(.+)\s+([^\s\/]+)(?:\/([^\s]+))?$/', $addressLine, $matches)) {
            return [
                'street' => $addressLine,
                'building_number' => null,
                'apartment_number' => null,
            ];
        }

        return [
            'street' => trim($matches[1]),
            'building_number' => trim($matches[2]),
            'apartment_number' => isset($matches[3]) ? trim($matches[3]) : null,
        ];
    }

    public static function formatPostalCity(?string $postalCode, ?string $city): ?string
    {
        $line = trim((string) $postalCode.' '.(string) $city);

        return $line !== '' ? $line : null;
    }

    /**
     * @return array{postal_code: ?string, city: ?string}
     */
    public static function parsePostalCity(?string $postalCity): array
    {
        $postalCity = trim((string) $postalCity);

        if ($postalCity === '') {
            return [
                'postal_code' => null,
                'city' => null,
            ];
        }

        if (! preg_match('/^(\d{2}-?\d{3})\s+(.+)$/', $postalCity, $matches)) {
            return [
                'postal_code' => null,
                'city' => $postalCity,
            ];
        }

        return [
            'postal_code' => $matches[1],
            'city' => trim($matches[2]),
        ];
    }
}
