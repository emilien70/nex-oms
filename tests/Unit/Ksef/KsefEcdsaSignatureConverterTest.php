<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefEcdsaSignatureConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefEcdsaSignatureConverterTest extends TestCase
{
    public function test_der_and_fixed_width_raw_signatures_round_trip(): void
    {
        $raw = str_repeat("\x80", 32).str_repeat("\x01", 32);
        $converter = app(KsefEcdsaSignatureConverter::class);

        $this->assertSame($raw, $converter->derToRaw($converter->rawToDer($raw)));
    }

    #[DataProvider('malformedDerSignatures')]
    public function test_malformed_der_is_rejected(string $signature): void
    {
        $this->expectException(KsefApiException::class);
        $this->expectExceptionMessage('ECDSA');

        app(KsefEcdsaSignatureConverter::class)->derToRaw($signature);
    }

    public static function malformedDerSignatures(): array
    {
        return [
            'empty' => [''],
            'wrong sequence tag' => ["\x31\x06\x02\x01\x01\x02\x01\x01"],
            'missing s' => ["\x30\x03\x02\x01\x01"],
            'negative r' => ["\x30\x06\x02\x01\x80\x02\x01\x01"],
            'unnecessary leading zero' => ["\x30\x07\x02\x02\x00\x01\x02\x01\x01"],
            'oversized r' => ["\x30\x26\x02\x21".str_repeat("\x01", 33)."\x02\x01\x01"],
            'trailing bytes' => ["\x30\x06\x02\x01\x01\x02\x01\x01\x00"],
        ];
    }
}
