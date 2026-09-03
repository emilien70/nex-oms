<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Services\KsefLatarniaSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class KsefLatarniaUtcInstantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('app.timezone', 'Europe/Warsaw');
    }

    #[DataProvider('roundTripCases')]
    public function test_message_utc_instant_roundtrip_is_independent_of_warsaw_timezone(
        string $input,
        string $expectedRaw,
        string $expectedUtc,
    ): void {
        $instant = CarbonImmutable::parse($input);
        $message = $this->createMessage($instant);
        $raw = DB::table('ksef_latarnia_messages')->find($message->getKey());
        $reloaded = $message->fresh();

        $this->assertNotNull($raw);
        $this->assertInstanceOf(KsefLatarniaMessage::class, $reloaded);
        $this->assertSame($expectedRaw, $raw->start_at);
        $this->assertSame($expectedUtc, $reloaded->start_at->format('Y-m-d H:i:s P'));
        $this->assertSame('UTC', $reloaded->start_at->timezoneName);
        $this->assertSame($instant->getTimestamp(), $reloaded->start_at->getTimestamp());
        $this->assertNull($reloaded->end_at);
    }

    public function test_fall_dst_repeated_hour_instants_remain_distinct(): void
    {
        $first = $this->createMessage(
            CarbonImmutable::parse('2026-10-25T00:30:00Z'),
            'DST-FALL-A',
        )->fresh();
        $second = $this->createMessage(
            CarbonImmutable::parse('2026-10-25T01:30:00Z'),
            'DST-FALL-B',
        )->fresh();

        $this->assertInstanceOf(KsefLatarniaMessage::class, $first);
        $this->assertInstanceOf(KsefLatarniaMessage::class, $second);
        $this->assertSame('2026-10-25 00:30:00', $first->getRawOriginal('start_at'));
        $this->assertSame('2026-10-25 01:30:00', $second->getRawOriginal('start_at'));
        $this->assertSame(3600, $second->start_at->getTimestamp() - $first->start_at->getTimestamp());
    }

    public function test_raw_database_utc_wall_clock_is_read_as_utc_and_null_stays_null(): void
    {
        $id = DB::table('ksef_latarnia_messages')->insertGetId($this->messageAttributes([
            'external_message_id' => 'RAW-LIVE-UTC',
            'start_at' => '2026-01-31 11:27:00',
            'end_at' => null,
            'published_at' => '2026-01-31 11:35:00',
            'first_fetched_at' => '2026-09-03 17:00:00',
            'last_seen_at' => '2026-09-03 17:00:00',
        ]));

        $message = KsefLatarniaMessage::query()->findOrFail($id);

        $this->assertSame('2026-01-31 11:27:00 +00:00', $message->start_at->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-01-31 11:35:00 +00:00', $message->published_at->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-09-03 17:00:00 +00:00', $message->first_fetched_at->format('Y-m-d H:i:s P'));
        $this->assertSame('2026-09-03 17:00:00 +00:00', $message->last_seen_at->format('Y-m-d H:i:s P'));
        $this->assertNull($message->end_at);
    }

    public function test_raw_utc_read_is_independent_of_a_different_application_timezone(): void
    {
        config()->set('app.timezone', 'Asia/Tokyo');
        $id = DB::table('ksef_latarnia_messages')->insertGetId($this->messageAttributes([
            'external_message_id' => 'RAW-TOKYO-UTC',
            'start_at' => '2026-07-31 10:00:00',
        ]));

        $message = KsefLatarniaMessage::query()->findOrFail($id);

        $this->assertSame('UTC', $message->start_at->timezoneName);
        $this->assertSame('2026-07-31 10:00:00 +00:00', $message->start_at->format('Y-m-d H:i:s P'));
        $this->assertSame(
            CarbonImmutable::parse('2026-07-31T10:00:00Z')->getTimestamp(),
            $message->start_at->getTimestamp(),
        );
    }

    public function test_corrupt_raw_utc_value_fails_closed(): void
    {
        $id = DB::table('ksef_latarnia_messages')->insertGetId($this->messageAttributes([
            'external_message_id' => 'CORRUPT-UTC',
            'start_at' => 'NOT-A-DATE',
        ]));
        $message = KsefLatarniaMessage::query()->findOrFail($id);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Nieprawidłowy zapis czasu UTC Latarni KSeF w polu start_at.');

        $message->start_at;
    }

    public function test_sync_state_fields_roundtrip_as_utc_instants(): void
    {
        $instant = CarbonImmutable::parse('2026-07-31T12:00:00+02:00');
        $state = KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'status_last_attempt_at' => $instant,
            'status_last_success_at' => $instant,
            'status_last_error_at' => null,
            'messages_last_attempt_at' => $instant,
            'messages_last_success_at' => $instant,
            'messages_last_error_at' => null,
        ]);
        $raw = DB::table('ksef_latarnia_sync_states')->find($state->getKey());
        $reloaded = $state->fresh();

        $this->assertNotNull($raw);
        $this->assertInstanceOf(KsefLatarniaSyncState::class, $reloaded);
        $this->assertSame('2026-07-31 10:00:00', $raw->status_last_attempt_at);
        $this->assertSame($instant->getTimestamp(), $reloaded->status_last_attempt_at->getTimestamp());
        $this->assertSame($instant->getTimestamp(), $reloaded->status_last_success_at->getTimestamp());
        $this->assertSame($instant->getTimestamp(), $reloaded->messages_last_attempt_at->getTimestamp());
        $this->assertSame($instant->getTimestamp(), $reloaded->messages_last_success_at->getTimestamp());
        $this->assertNull($reloaded->status_last_error_at);
        $this->assertNull($reloaded->messages_last_error_at);
    }

    public function test_message_sync_preserves_parser_instants_after_reload(): void
    {
        Http::fake(['*' => Http::response([$this->remoteMessage()])]);

        $parsed = app(KsefLatarniaSyncService::class)
            ->syncMessages(KsefLatarniaEnvironment::Test)[0];
        $stored = KsefLatarniaMessage::query()->firstOrFail();

        $this->assertSame($parsed->startAt->getTimestamp(), $stored->start_at->getTimestamp());
        $this->assertSame($parsed->endAt?->getTimestamp(), $stored->end_at?->getTimestamp());
        $this->assertSame($parsed->publishedAt->getTimestamp(), $stored->published_at->getTimestamp());
        $this->assertSame('2026-07-31 10:00:00', $stored->getRawOriginal('start_at'));
        $this->assertSame('2026-07-31 11:00:00', $stored->getRawOriginal('end_at'));
        Http::assertSentCount(1);
    }

    public function test_status_sync_timestamps_preserve_exact_utc_now(): void
    {
        $now = CarbonImmutable::parse('2026-03-29T00:30:00Z');
        $this->travelTo($now);
        Http::fake(['*' => Http::response(['status' => 'AVAILABLE'])]);

        app(KsefLatarniaSyncService::class)->syncStatus(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertSame($now->getTimestamp(), $state->status_last_attempt_at->getTimestamp());
        $this->assertSame($now->getTimestamp(), $state->status_last_success_at->getTimestamp());
        $this->assertSame('2026-03-29 00:30:00', $state->getRawOriginal('status_last_attempt_at'));
        $this->assertSame('2026-03-29 00:30:00', $state->getRawOriginal('status_last_success_at'));
        Http::assertSentCount(1);
    }

    public function test_error_timestamp_preserves_exact_utc_now(): void
    {
        $now = CarbonImmutable::parse('2026-10-25T01:30:00Z');
        $this->travelTo($now);
        Http::fake(['*' => Http::response(['error' => 'synthetic'], 500)]);

        try {
            app(KsefLatarniaSyncService::class)->syncMessages(KsefLatarniaEnvironment::Test);
            $this->fail('Expected controlled Latarnia HTTP error.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_latarnia_http_error', $exception->safeCode);
        }

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame($now->getTimestamp(), $state->messages_last_attempt_at->getTimestamp());
        $this->assertSame($now->getTimestamp(), $state->messages_last_error_at->getTimestamp());
        $this->assertSame('2026-10-25 01:30:00', $state->getRawOriginal('messages_last_error_at'));
        Http::assertSentCount(1);
    }

    public function test_same_payload_updates_last_seen_with_exact_utc_instant_only(): void
    {
        Http::fakeSequence()
            ->push([$this->remoteMessage()])
            ->push([$this->remoteMessage()]);
        $sync = app(KsefLatarniaSyncService::class);
        $firstSeenAt = CarbonImmutable::parse('2026-10-25T00:30:00Z');
        $secondSeenAt = CarbonImmutable::parse('2026-10-25T01:30:00Z');

        $this->travelTo($firstSeenAt);
        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $this->travelTo($secondSeenAt);
        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $stored = KsefLatarniaMessage::query()->firstOrFail();

        $this->assertDatabaseCount('ksef_latarnia_messages', 1);
        $this->assertSame($firstSeenAt->getTimestamp(), $stored->first_fetched_at->getTimestamp());
        $this->assertSame($secondSeenAt->getTimestamp(), $stored->last_seen_at->getTimestamp());
        $this->assertSame('2026-10-25 00:30:00', $stored->getRawOriginal('first_fetched_at'));
        $this->assertSame('2026-10-25 01:30:00', $stored->getRawOriginal('last_seen_at'));
        Http::assertSentCount(2);
    }

    public static function roundTripCases(): array
    {
        return [
            'winter UTC' => [
                '2026-01-31T11:27:00Z',
                '2026-01-31 11:27:00',
                '2026-01-31 11:27:00 +00:00',
            ],
            'summer UTC' => [
                '2026-07-31T10:00:00Z',
                '2026-07-31 10:00:00',
                '2026-07-31 10:00:00 +00:00',
            ],
            'explicit UTC+2 offset' => [
                '2026-07-31T12:00:00+02:00',
                '2026-07-31 10:00:00',
                '2026-07-31 10:00:00 +00:00',
            ],
            'before spring DST transition' => [
                '2026-03-29T00:30:00Z',
                '2026-03-29 00:30:00',
                '2026-03-29 00:30:00 +00:00',
            ],
            'after spring DST transition' => [
                '2026-03-29T01:30:00Z',
                '2026-03-29 01:30:00',
                '2026-03-29 01:30:00 +00:00',
            ],
            'fall DST first occurrence' => [
                '2026-10-25T00:30:00Z',
                '2026-10-25 00:30:00',
                '2026-10-25 00:30:00 +00:00',
            ],
            'fall DST second occurrence' => [
                '2026-10-25T01:30:00Z',
                '2026-10-25 01:30:00',
                '2026-10-25 01:30:00 +00:00',
            ],
        ];
    }

    private function createMessage(
        CarbonImmutable $instant,
        string $externalMessageId = 'UTC-ROUNDTRIP',
    ): KsefLatarniaMessage {
        return KsefLatarniaMessage::query()->create($this->messageAttributes([
            'external_message_id' => $externalMessageId,
            'start_at' => $instant,
            'end_at' => null,
            'published_at' => $instant,
            'first_fetched_at' => $instant,
            'last_seen_at' => $instant,
        ]));
    }

    private function messageAttributes(array $overrides = []): array
    {
        $payload = '{}';

        return array_replace([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'UTC-ROUNDTRIP',
            'event_id' => 2001,
            'version' => 1,
            'category' => KsefLatarniaMessageCategory::Failure,
            'type' => KsefLatarniaMessageType::FailureStart,
            'title' => 'UTC round-trip test',
            'text' => 'Publiczna treść testowa.',
            'start_at' => '2026-01-31 11:27:00',
            'end_at' => null,
            'published_at' => '2026-01-31 11:35:00',
            'payload_json' => $payload,
            'payload_hash' => base64_encode(hash('sha256', $payload, true)),
            'first_fetched_at' => '2026-09-03 17:00:00',
            'last_seen_at' => '2026-09-03 17:00:00',
            'created_at' => '2026-09-03 17:00:00',
            'updated_at' => '2026-09-03 17:00:00',
        ], $overrides);
    }

    private function remoteMessage(): array
    {
        return [
            'id' => 'SYNC-UTC',
            'eventId' => 3001,
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Synchronizacja UTC',
            'text' => 'Publiczna treść testowa.',
            'start' => '2026-07-31T12:00:00+02:00',
            'end' => '2026-07-31T13:00:00+02:00',
            'version' => 1,
            'published' => '2026-07-31T09:30:00Z',
        ];
    }
}
