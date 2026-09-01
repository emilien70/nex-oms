<?php

namespace Modules\Ksef\ValueObjects;

use InvalidArgumentException;
use Modules\Ksef\Enums\KsefContextIdentifierType;

final readonly class KsefContextIdentifier
{
    private const NIP_PATTERN = '/^[1-9](?:(?:\d[1-9])|(?:[1-9]\d))\d{7}$/D';

    private const VAT_UE_PATTERNS = [
        'AT' => '/^U\d{8}$/D',
        'BE' => '/^[01]\d{9}$/D',
        'BG' => '/^\d{9,10}$/D',
        'CY' => '/^\d{8}[A-Z]$/D',
        'CZ' => '/^\d{8,10}$/D',
        'DE' => '/^\d{9}$/D',
        'DK' => '/^\d{8}$/D',
        'EE' => '/^\d{9}$/D',
        'EL' => '/^\d{9}$/D',
        'ES' => '/^(?:[A-Z]\d{8}|\d{8}[A-Z]|[A-Z]\d{7}[A-Z])$/D',
        'FI' => '/^\d{8}$/D',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/D',
        'HR' => '/^\d{11}$/D',
        'HU' => '/^\d{8}$/D',
        'IE' => '/^(?:\d{7}[A-Z]{2}|\d[A-Z0-9+*]\d{5}[A-Z])$/D',
        'IT' => '/^\d{11}$/D',
        'LT' => '/^(?:\d{9}|\d{12})$/D',
        'LU' => '/^\d{8}$/D',
        'LV' => '/^\d{11}$/D',
        'MT' => '/^\d{8}$/D',
        'NL' => '/^[A-Z0-9+*]{12}$/D',
        'PT' => '/^\d{9}$/D',
        'RO' => '/^\d{2,10}$/D',
        'SE' => '/^\d{12}$/D',
        'SI' => '/^\d{8}$/D',
        'SK' => '/^\d{10}$/D',
        'XI' => '/^(?:(?:\d{9}|\d{12})|(?:GD|HA)\d{3})$/D',
    ];

    private function __construct(
        public KsefContextIdentifierType $type,
        public string $value,
    ) {}

    public static function make(KsefContextIdentifierType $type, string $value): self
    {
        if (! self::isValid($type, $value)) {
            throw new InvalidArgumentException('Identyfikator kontekstu KSeF ma nieprawidłowy format.');
        }

        return new self($type, $value);
    }

    private static function isValid(KsefContextIdentifierType $type, string $value): bool
    {
        return match ($type) {
            KsefContextIdentifierType::Nip => preg_match(self::NIP_PATTERN, $value) === 1,
            KsefContextIdentifierType::InternalId => preg_match(
                '/^[1-9](?:(?:\d[1-9])|(?:[1-9]\d))\d{7}-\d{5}$/D',
                $value,
            ) === 1,
            KsefContextIdentifierType::NipVatUe => self::isValidNipVatUe($value),
            KsefContextIdentifierType::PeppolId => preg_match('/^P[A-Z]{2}\d{6}$/D', $value) === 1,
        };
    }

    private static function isValidNipVatUe(string $value): bool
    {
        if (preg_match('/^(.{10})-([A-Z]{2})(.+)$/D', $value, $parts) !== 1
            || preg_match(self::NIP_PATTERN, $parts[1]) !== 1) {
            return false;
        }

        $pattern = self::VAT_UE_PATTERNS[$parts[2]] ?? null;

        return $pattern !== null && preg_match($pattern, $parts[3]) === 1;
    }
}
