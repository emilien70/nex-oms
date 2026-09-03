<?php

namespace Tests\Unit\Ksef;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefLatarniaClient;
use Modules\Ksef\Services\KsefLatarniaEndpointResolver;
use Modules\Ksef\Services\KsefLatarniaMessageParser;
use Modules\Ksef\Services\KsefLatarniaPayloadCanonicalizer;
use Modules\Ksef\Services\KsefLatarniaStatusParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefLatarniaClientAndParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_client_uses_exact_official_hosts_get_json_and_no_authorization(): void
    {
        Http::fake([
            'https://api-latarnia-test.ksef.mf.gov.pl/status' => Http::response(['status' => 'AVAILABLE']),
            'https://api-latarnia-test.ksef.mf.gov.pl/messages' => Http::response([]),
            'https://api-latarnia.ksef.mf.gov.pl/status' => Http::response(['status' => 'AVAILABLE']),
            'https://api-latarnia.ksef.mf.gov.pl/messages' => Http::response([]),
        ]);
        $client = app(KsefLatarniaClient::class);

        $this->assertSame('AVAILABLE', $client->status(KsefLatarniaEnvironment::Test)->status);
        $this->assertSame([], $client->messages(KsefLatarniaEnvironment::Test));
        $this->assertSame('AVAILABLE', $client->status(KsefLatarniaEnvironment::Production)->status);
        $this->assertSame([], $client->messages(KsefLatarniaEnvironment::Production));

        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertFalse($request->hasHeader('Authorization'));
        }
        Http::assertNotSent(fn (Request $request): bool => ! in_array($request->url(), [
            'https://api-latarnia-test.ksef.mf.gov.pl/status',
            'https://api-latarnia-test.ksef.mf.gov.pl/messages',
            'https://api-latarnia.ksef.mf.gov.pl/status',
            'https://api-latarnia.ksef.mf.gov.pl/messages',
        ], true));
    }

    public function test_demo_has_no_latarnia_mapping_and_sends_no_http(): void
    {
        try {
            app(KsefLatarniaEndpointResolver::class)->fromKsefEnvironment(KsefEnvironment::Demo);
            $this->fail('Expected controlled missing Latarnia DEMO mapping.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_latarnia_environment_unavailable', $exception->safeCode);
        }

        Http::assertNothingSent();
    }

    public function test_ksef_environment_mapping_is_exact_for_test_and_production(): void
    {
        $resolver = app(KsefLatarniaEndpointResolver::class);

        $this->assertSame(
            KsefLatarniaEnvironment::Test,
            $resolver->fromKsefEnvironment(KsefEnvironment::Test),
        );
        $this->assertSame(
            KsefLatarniaEnvironment::Production,
            $resolver->fromKsefEnvironment(KsefEnvironment::Production),
        );
    }

    public function test_client_maps_connection_failure_without_retry(): void
    {
        Http::fake(['*' => Http::failedConnection('Synthetic Latarnia connection failure')]);
        $this->assertKsefError(
            'ksef_latarnia_network_error',
            fn () => app(KsefLatarniaClient::class)->status(KsefLatarniaEnvironment::Test),
        );
        Http::assertSentCount(1);
    }

    public function test_client_maps_http_failure_without_exposing_response_body(): void
    {
        Http::fake(['*' => Http::response(['error' => 'public diagnostic'], 429)]);
        $this->assertKsefError(
            'ksef_latarnia_http_error',
            fn () => app(KsefLatarniaClient::class)->status(KsefLatarniaEnvironment::Test),
            429,
        );
        Http::assertSentCount(1);
    }

    public function test_client_rejects_malformed_json_and_unexpected_roots(): void
    {
        Http::fake(['*' => Http::response('{not-json', 200)]);
        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaClient::class)->status(KsefLatarniaEnvironment::Test),
        );
    }

    public function test_client_rejects_status_list_root(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaClient::class)->status(KsefLatarniaEnvironment::Test),
        );
    }

    public function test_client_rejects_messages_object_root(): void
    {
        Http::fake(['*' => Http::response(['status' => 'AVAILABLE'], 200)]);
        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaClient::class)->messages(KsefLatarniaEnvironment::Test),
        );
    }

    public function test_canonical_payload_sorts_object_keys_preserves_array_order_and_hashes_to_base64_sha256(): void
    {
        $canonicalizer = app(KsefLatarniaPayloadCanonicalizer::class);
        $left = [
            'z' => 1.0,
            'nested' => ['b' => true, 'a' => 'zażółć'],
            'items' => [['b' => 2, 'a' => 1], ['value' => 3]],
        ];
        $right = [
            'items' => [['a' => 1, 'b' => 2], ['value' => 3]],
            'nested' => ['a' => 'zażółć', 'b' => true],
            'z' => 1.0,
        ];

        $canonical = $canonicalizer->canonicalize($left);

        $this->assertSame($canonical, $canonicalizer->canonicalize($right));
        $this->assertSame(
            '{"items":[{"a":1,"b":2},{"value":3}],"nested":{"a":"zażółć","b":true},"z":1.0}',
            $canonical,
        );
        $this->assertSame(44, strlen($canonicalizer->hash($canonical)));
        $this->assertSame('{}', $canonicalizer->canonicalize((object) []));
    }

    #[DataProvider('validMessageCases')]
    public function test_message_parser_accepts_official_semantic_variants(
        string $category,
        string $type,
        bool $includeEnd,
        ?string $end,
    ): void {
        $payload = $this->message([
            'category' => $category,
            'type' => $type,
        ]);

        if ($includeEnd) {
            $payload['end'] = $end;
        } else {
            unset($payload['end']);
        }

        $message = app(KsefLatarniaMessageParser::class)->parse($this->decoded($payload));

        $this->assertSame($category, $message->category->value);
        $this->assertSame($type, $message->type->value);
        $this->assertSame($end === null ? null : '2026-09-03 11:00:00', $message->endAt?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $message->startAt->timezoneName);
        $this->assertSame(44, strlen($message->payloadHash));
    }

    public static function validMessageCases(): array
    {
        return [
            'maintenance' => ['MAINTENANCE', 'MAINTENANCE_ANNOUNCEMENT', true, '2026-09-03T13:00:00+02:00'],
            'failure start without end' => ['FAILURE', 'FAILURE_START', false, null],
            'failure start with null end' => ['FAILURE', 'FAILURE_START', true, null],
            'failure end' => ['FAILURE', 'FAILURE_END', true, '2026-09-03T13:00:00+02:00'],
            'total failure start' => ['TOTAL_FAILURE', 'FAILURE_START', false, null],
            'total failure end' => ['TOTAL_FAILURE', 'FAILURE_END', true, '2026-09-03T13:00:00+02:00'],
        ];
    }

    #[DataProvider('invalidMessageCases')]
    public function test_message_parser_fails_closed_for_invalid_contract(array $changes, array $remove = []): void
    {
        $payload = array_replace($this->message(), $changes);

        foreach ($remove as $key) {
            unset($payload[$key]);
        }

        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaMessageParser::class)->parse($this->decoded($payload)),
        );
    }

    public static function invalidMessageCases(): array
    {
        return [
            'unknown category' => [['category' => 'SOMETHING_NEW']],
            'unknown type' => [['type' => 'FAILURE_PAUSE']],
            'category type mismatch' => [['category' => 'MAINTENANCE', 'type' => 'FAILURE_START']],
            'timestamp without offset' => [['start' => '2026-09-03T10:00:00']],
            'maintenance end absent' => [[], ['end']],
            'maintenance end equals start' => [['end' => '2026-09-03T12:00:00+02:00']],
            'failure end absent' => [['category' => 'FAILURE', 'type' => 'FAILURE_END'], ['end']],
            'failure end before start' => [[
                'category' => 'FAILURE',
                'type' => 'FAILURE_END',
                'end' => '2026-09-03T11:59:59+02:00',
            ]],
            'id too long' => [['id' => str_repeat('X', 25)]],
            'event id not positive' => [['eventId' => 0]],
            'version not integer' => [['version' => '1']],
            'title empty' => [['title' => '   ']],
            'text too long' => [['text' => str_repeat('X', 3001)]],
            'published without offset' => [['published' => '2026-09-03T09:00:00']],
        ];
    }

    #[DataProvider('validStatusCases')]
    public function test_status_parser_accepts_each_status_and_priority_messages(
        string $status,
        array $messages,
        int $expectedMessages,
    ): void {
        $parsed = app(KsefLatarniaStatusParser::class)->parse($this->decoded([
            'messages' => $messages,
            'status' => $status,
            'neutralFutureField' => ['b' => 2, 'a' => 1],
        ]));

        $this->assertSame($status, $parsed->status->value);
        $this->assertCount($expectedMessages, $parsed->messages);
        $this->assertStringContainsString('neutralFutureField', $parsed->payloadJson);
        $this->assertSame(44, strlen($parsed->payloadHash));
    }

    public static function validStatusCases(): array
    {
        $maintenance = self::staticMessage('M-1', 'MAINTENANCE', 'MAINTENANCE_ANNOUNCEMENT');
        $failure = self::staticMessage('F-1', 'FAILURE', 'FAILURE_START', null);
        $total = self::staticMessage('T-1', 'TOTAL_FAILURE', 'FAILURE_START', null);

        return [
            'available' => ['AVAILABLE', [], 0],
            'maintenance' => ['MAINTENANCE', [$maintenance], 1],
            'failure' => ['FAILURE', [$maintenance, $failure], 2],
            'total failure priority' => ['TOTAL_FAILURE', [$maintenance, $failure, $total], 3],
        ];
    }

    #[DataProvider('invalidStatusCases')]
    public function test_status_parser_fails_closed_for_unknown_or_inconsistent_status(array $payload): void
    {
        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaStatusParser::class)->parse($this->decoded($payload)),
        );
    }

    public static function invalidStatusCases(): array
    {
        return [
            'unknown status' => [['status' => 'DEGRADED']],
            'available with message' => [[
                'status' => 'AVAILABLE',
                'messages' => [self::staticMessage('M-1', 'MAINTENANCE', 'MAINTENANCE_ANNOUNCEMENT')],
            ]],
            'maintenance without maintenance message' => [[
                'status' => 'MAINTENANCE',
                'messages' => [self::staticMessage('F-1', 'FAILURE', 'FAILURE_START', null)],
            ]],
            'failure without messages' => [['status' => 'FAILURE', 'messages' => []]],
            'total failure without matching category' => [[
                'status' => 'TOTAL_FAILURE',
                'messages' => [self::staticMessage('F-1', 'FAILURE', 'FAILURE_START', null)],
            ]],
            'messages not a list' => [['status' => 'FAILURE', 'messages' => ['message' => 'invalid']]],
        ];
    }

    private function assertKsefError(string $safeCode, callable $callback, ?int $httpStatus = null): void
    {
        try {
            $callback();
            $this->fail('Expected controlled Latarnia failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);

            if ($httpStatus !== null) {
                $this->assertSame($httpStatus, $exception->httpStatus);
            }

            $this->assertStringNotContainsString('public diagnostic', $exception->getMessage());
        }
    }

    private function message(array $overrides = []): array
    {
        return array_replace(self::staticMessage(), $overrides);
    }

    private static function staticMessage(
        string $id = 'MSG-1',
        string $category = 'MAINTENANCE',
        string $type = 'MAINTENANCE_ANNOUNCEMENT',
        ?string $end = '2026-09-03T13:00:00+02:00',
    ): array {
        return [
            'id' => $id,
            'eventId' => 101,
            'category' => $category,
            'type' => $type,
            'title' => 'Komunikat testowy',
            'text' => 'Publiczna treść komunikatu testowego.',
            'start' => '2026-09-03T12:00:00+02:00',
            'end' => $end,
            'version' => 1,
            'published' => '2026-09-03T09:00:00Z',
        ];
    }

    private function decoded(array $payload): object
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
