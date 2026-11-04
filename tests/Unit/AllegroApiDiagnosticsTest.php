<?php

namespace Tests\Unit;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Modules\Integrations\Allegro\Support\AllegroApiDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllegroApiDiagnosticsTest extends TestCase
{
    #[DataProvider('traces')]
    public function test_only_a_bounded_trace_identifier_is_accepted(?array $headers, ?string $expected): void
    {
        $response = $headers === null ? null : new Response(new PsrResponse(200, $headers));

        $this->assertSame($expected, AllegroApiDiagnostics::traceId($response));
    }

    public static function traces(): array
    {
        return [
            'no response' => [null, null],
            'missing header' => [[], null],
            'empty header' => [['Trace-Id' => ''], null],
            'case insensitive header' => [['tRaCe-Id' => 'abc-123_DEF:456.7'], 'abc-123_DEF:456.7'],
            'maximum length' => [['Trace-Id' => str_repeat('a', 255)], str_repeat('a', 255)],
            'too long' => [['Trace-Id' => str_repeat('a', 256)], null],
            'multiple values' => [['Trace-Id' => ['first', 'second']], null],
            'whitespace' => [['Trace-Id' => 'Bearer PRIVATE-TOKEN'], null],
            'html' => [['Trace-Id' => '<script>alert(1)</script>'], null],
            'non-ascii' => [['Trace-Id' => "trace-\xc3\xa9"], null],
        ];
    }
}
