<?php

namespace Tests\Unit\Ksef;

use InvalidArgumentException;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\ValueObjects\KsefContextIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KsefContextIdentifierTest extends TestCase
{
    #[DataProvider('validIdentifiers')]
    public function test_official_context_identifier_values_are_accepted(
        KsefContextIdentifierType $type,
        string $value,
    ): void {
        $identifier = KsefContextIdentifier::make($type, $value);

        $this->assertSame($type, $identifier->type);
        $this->assertSame($value, $identifier->value);
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_context_identifier_values_are_rejected(
        KsefContextIdentifierType $type,
        string $value,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        KsefContextIdentifier::make($type, $value);
    }

    public static function validIdentifiers(): array
    {
        return [
            'NIP' => [KsefContextIdentifierType::Nip, '1111111111'],
            'InternalId' => [KsefContextIdentifierType::InternalId, '1111111111-12345'],
            'NipVatUe with path-safe plus' => [KsefContextIdentifierType::NipVatUe, '1111111111-IE1+12345A'],
            'PeppolId' => [KsefContextIdentifierType::PeppolId, 'PPL123456'],
        ];
    }

    public static function invalidIdentifiers(): array
    {
        return [
            'NIP too short' => [KsefContextIdentifierType::Nip, '111111111'],
            'InternalId malformed' => [KsefContextIdentifierType::InternalId, '1111111111/12345'],
            'NipVatUe unsupported country' => [KsefContextIdentifierType::NipVatUe, '1111111111-US123456789'],
            'PeppolId lowercase' => [KsefContextIdentifierType::PeppolId, 'Ppl123456'],
        ];
    }
}
