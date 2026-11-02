<?php

namespace Tests\Unit\VonHalsky;

use JsonException;
use Modules\Integrations\VonHalsky\Support\VonHalskyOrderJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VonHalskyOrderJsonTest extends TestCase
{
    public function test_amount_lexemes_are_preserved_without_converting_identifiers_flags_or_quantities(): void
    {
        $json = <<<'JSON'
{"id":"PG76P11","quantity":2,"invalidId":123,"enabled":true,"empty":null,"money":{"amount":999999999.99,"currency":"PLN"},"other":{"amount":0.010000000000000001},"name":"test 123 \" 456","escaped":{"am\u006funt":1.25}}
JSON;
        $data = VonHalskyOrderJson::decode($json);
        $this->assertSame('999999999.99', $data['money']['amount']);
        $this->assertSame('0.010000000000000001', $data['other']['amount']);
        $this->assertSame('1.25', $data['escaped']['amount']);
        $this->assertSame(2, $data['quantity']);
        $this->assertSame(123, $data['invalidId']);
        $this->assertTrue($data['enabled']);
        $this->assertNull($data['empty']);
        $this->assertSame(json_decode($json, true)['name'], $data['name']);
    }

    #[DataProvider('invalidJson')]
    public function test_invalid_json_or_root_is_rejected(string $json): void
    {
        $this->expectException(JsonException::class);
        VonHalskyOrderJson::decode($json);
    }

    public static function invalidJson(): array
    {
        return [['{"amount":01}'], ['{"amount":1.}'], ['[]'], ['null'], ['"text"'], ['{"amount":1,}']];
    }
}
