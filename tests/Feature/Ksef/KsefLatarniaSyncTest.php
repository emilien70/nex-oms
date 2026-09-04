<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Services\KsefLatarniaSyncService;
use Tests\TestCase;

class KsefLatarniaSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-03T10:00:00Z'));
    }

    public function test_migration_exposes_history_and_sync_state_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('ksef_latarnia_messages', [
            'source_environment',
            'external_message_id',
            'event_id',
            'version',
            'category',
            'type',
            'title',
            'text',
            'start_at',
            'end_at',
            'published_at',
            'payload_json',
            'payload_hash',
            'first_fetched_at',
            'last_seen_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ksef_latarnia_sync_states', [
            'source_environment',
            'current_status',
            'status_payload_json',
            'status_payload_hash',
            'status_last_attempt_at',
            'status_last_success_at',
            'status_last_error_at',
            'status_last_error_code',
            'status_last_error_message',
            'messages_last_attempt_at',
            'messages_last_success_at',
            'messages_last_error_at',
            'messages_last_error_code',
            'messages_last_error_message',
            'messages_coverage_from_at',
            'messages_coverage_through_at',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('ksef_latarnia_messages')"))
            ->pluck('name');

        $this->assertContains('ksef_latarnia_message_version_unique', $indexes);
        $this->assertContains('ksef_latarnia_message_event_index', $indexes);
        $this->assertContains('ksef_latarnia_message_start_index', $indexes);
        $this->assertFalse(Schema::hasTable('ksef_latarnia_status_snapshots'));
    }

    public function test_status_sync_persists_current_status_payload_and_embedded_message_atomically(): void
    {
        $message = $this->message([
            'id' => 'FAIL-1',
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'end' => null,
            'futureNeutral' => ['z' => 2, 'a' => 1],
        ]);
        Http::fake(['*' => Http::response([
            'status' => 'FAILURE',
            'messages' => [$message],
        ])]);

        $status = app(KsefLatarniaSyncService::class)->syncStatus(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $stored = KsefLatarniaMessage::query()->firstOrFail();

        $this->assertSame(KsefLatarniaStatus::Failure, $status->status);
        $this->assertSame(KsefLatarniaStatus::Failure, $state->current_status);
        $this->assertSame(44, strlen($state->status_payload_hash));
        $this->assertNotNull($state->status_last_attempt_at);
        $this->assertNotNull($state->status_last_success_at);
        $this->assertNull($state->status_last_error_code);
        $this->assertSame('FAIL-1', $stored->external_message_id);
        $this->assertSame(101, $stored->event_id);
        $this->assertSame(KsefLatarniaMessageCategory::Failure, $stored->category);
        $this->assertNull($stored->end_at);
        $this->assertStringContainsString('futureNeutral', $stored->payload_json);
        $this->assertSame(44, strlen($stored->payload_hash));
        Http::assertSentCount(1);
    }

    public function test_same_payload_updates_last_seen_new_version_adds_history_and_disappearance_never_deletes(): void
    {
        $versionOne = $this->message(['id' => 'VERSIONED-1']);
        $versionTwo = array_replace($versionOne, [
            'version' => 2,
            'title' => 'Druga wersja',
        ]);
        Http::fakeSequence()
            ->push([$versionOne])
            ->push([$versionOne])
            ->push([$versionTwo])
            ->push([]);
        $sync = app(KsefLatarniaSyncService::class);

        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $first = KsefLatarniaMessage::query()->firstOrFail();
        $firstSeen = $first->first_fetched_at;
        $initialLastSeen = $first->last_seen_at;

        $this->travel(1)->minutes();
        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $same = KsefLatarniaMessage::query()->firstOrFail();
        $this->assertDatabaseCount('ksef_latarnia_messages', 1);
        $this->assertEquals($firstSeen, $same->first_fetched_at);
        $this->assertTrue($same->last_seen_at->greaterThan($initialLastSeen));

        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $this->assertDatabaseCount('ksef_latarnia_messages', 2);
        $this->assertSame([1, 2], KsefLatarniaMessage::query()->orderBy('version')->pluck('version')->all());

        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $this->assertDatabaseCount('ksef_latarnia_messages', 2);
        Http::assertSentCount(4);
    }

    public function test_messages_success_initializes_and_continuously_extends_the_retention_coverage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T10:00:00Z'));
        Http::fake(['*' => Http::response([])]);
        $sync = app(KsefLatarniaSyncService::class);

        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $initial = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertSame('2026-08-05 10:00:00', $initial->messages_coverage_from_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04 10:00:00', $initial->messages_coverage_through_at->format('Y-m-d H:i:s'));

        $this->travel(5)->minutes();
        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $extended = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertSame('2026-08-05 10:00:00', $extended->messages_coverage_from_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04 10:05:00', $extended->messages_coverage_through_at->format('Y-m-d H:i:s'));
    }

    public function test_messages_success_resets_coverage_after_an_unrecoverable_gap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T10:00:00Z'));
        KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'messages_coverage_from_at' => CarbonImmutable::parse('2026-06-01T10:00:00Z'),
            'messages_coverage_through_at' => CarbonImmutable::parse('2026-07-01T09:59:59Z'),
        ]);
        Http::fake(['*' => Http::response([])]);

        app(KsefLatarniaSyncService::class)->syncMessages(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertSame('2026-08-05 10:00:00', $state->messages_coverage_from_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04 10:00:00', $state->messages_coverage_through_at->format('Y-m-d H:i:s'));
    }

    public function test_status_success_and_failed_messages_sync_never_advance_existing_coverage(): void
    {
        $from = CarbonImmutable::parse('2026-08-05T09:00:00Z');
        $through = CarbonImmutable::parse('2026-09-03T09:00:00Z');
        KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'messages_coverage_from_at' => $from,
            'messages_coverage_through_at' => $through,
        ]);
        Http::fakeSequence()
            ->push(['status' => 'AVAILABLE'])
            ->push(['error' => 'unavailable'], 500);
        $sync = app(KsefLatarniaSyncService::class);

        $sync->syncStatus(KsefLatarniaEnvironment::Test);
        $this->assertKsefError(
            'ksef_latarnia_http_error',
            fn () => $sync->syncMessages(KsefLatarniaEnvironment::Test),
        );
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertTrue($state->messages_coverage_from_at->equalTo($from));
        $this->assertTrue($state->messages_coverage_through_at->equalTo($through));
        $this->assertNull($state->messages_last_success_at);
        $this->assertSame('ksef_latarnia_http_error', $state->messages_last_error_code);
    }

    public function test_same_version_payload_conflict_fails_closed_and_preserves_original(): void
    {
        $original = $this->message(['id' => 'CONFLICT-1']);
        $changed = array_replace($original, ['text' => 'Zmieniona treść tej samej wersji.']);
        Http::fakeSequence()->push([$original])->push([$changed]);
        $sync = app(KsefLatarniaSyncService::class);
        $sync->syncMessages(KsefLatarniaEnvironment::Test);
        $before = KsefLatarniaMessage::query()->firstOrFail()->getAttributes();
        $stateBefore = KsefLatarniaSyncState::query()->firstOrFail();
        $coverageFromBefore = $stateBefore->messages_coverage_from_at;
        $coverageThroughBefore = $stateBefore->messages_coverage_through_at;
        $successBefore = $stateBefore->messages_last_success_at;
        $this->travel(1)->minutes();

        $this->assertKsefError(
            'ksef_latarnia_message_version_conflict',
            fn () => $sync->syncMessages(KsefLatarniaEnvironment::Test),
        );

        $after = KsefLatarniaMessage::query()->firstOrFail();
        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertDatabaseCount('ksef_latarnia_messages', 1);
        $this->assertSame($before['payload_hash'], $after->getRawOriginal('payload_hash'));
        $this->assertSame($before['text'], $after->getRawOriginal('text'));
        $this->assertSame('ksef_latarnia_message_version_conflict', $state->messages_last_error_code);
        $this->assertNotNull($state->messages_last_error_at);
        $this->assertTrue($state->messages_last_success_at->equalTo($successBefore));
        $this->assertTrue($state->messages_coverage_from_at->equalTo($coverageFromBefore));
        $this->assertTrue($state->messages_coverage_through_at->equalTo($coverageThroughBefore));
    }

    public function test_messages_endpoint_is_atomic_when_one_message_is_invalid(): void
    {
        Http::fake(['*' => Http::response([
            $this->message(['id' => 'VALID-1']),
            $this->message(['id' => 'INVALID-1', 'type' => 'FAILURE_PAUSE']),
        ])]);

        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => app(KsefLatarniaSyncService::class)->syncMessages(KsefLatarniaEnvironment::Test),
        );

        $this->assertDatabaseCount('ksef_latarnia_messages', 0);
        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertNull($state->messages_last_success_at);
        $this->assertSame('ksef_latarnia_response_invalid', $state->messages_last_error_code);
    }

    public function test_invalid_status_preserves_last_confirmed_status_payload_and_success_time(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'AVAILABLE'])
            ->push(['status' => 'DEGRADED']);
        $sync = app(KsefLatarniaSyncService::class);
        $sync->syncStatus(KsefLatarniaEnvironment::Test);
        $before = KsefLatarniaSyncState::query()->firstOrFail();

        $this->travel(1)->minutes();
        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => $sync->syncStatus(KsefLatarniaEnvironment::Test),
        );

        $after = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame(KsefLatarniaStatus::Available, $after->current_status);
        $this->assertSame($before->status_payload_json, $after->status_payload_json);
        $this->assertSame($before->status_payload_hash, $after->status_payload_hash);
        $this->assertEquals($before->status_last_success_at, $after->status_last_success_at);
        $this->assertTrue($after->status_last_attempt_at->greaterThan($before->status_last_attempt_at));
        $this->assertSame('ksef_latarnia_response_invalid', $after->status_last_error_code);
    }

    public function test_status_endpoint_validates_all_embedded_messages_before_any_state_change(): void
    {
        Http::fakeSequence()
            ->push(['status' => 'AVAILABLE'])
            ->push([
                'status' => 'FAILURE',
                'messages' => [
                    $this->message([
                        'id' => 'VALID-EMBEDDED',
                        'category' => 'FAILURE',
                        'type' => 'FAILURE_START',
                        'end' => null,
                    ]),
                    $this->message(['id' => 'INVALID-EMBEDDED', 'type' => 'FAILURE_PAUSE']),
                ],
            ]);
        $sync = app(KsefLatarniaSyncService::class);
        $sync->syncStatus(KsefLatarniaEnvironment::Test);

        $this->assertKsefError(
            'ksef_latarnia_response_invalid',
            fn () => $sync->syncStatus(KsefLatarniaEnvironment::Test),
        );

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame(KsefLatarniaStatus::Available, $state->current_status);
        $this->assertDatabaseMissing('ksef_latarnia_messages', ['external_message_id' => 'VALID-EMBEDDED']);
        $this->assertDatabaseMissing('ksef_latarnia_messages', ['external_message_id' => 'INVALID-EMBEDDED']);
    }

    public function test_later_confirmed_failure_replaces_current_available_status(): void
    {
        $failure = $this->message([
            'id' => 'STATUS-UPGRADE',
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'end' => null,
        ]);
        Http::fakeSequence()
            ->push(['status' => 'AVAILABLE'])
            ->push(['status' => 'FAILURE', 'messages' => [$failure]]);
        $sync = app(KsefLatarniaSyncService::class);

        $sync->syncStatus(KsefLatarniaEnvironment::Test);
        $sync->syncStatus(KsefLatarniaEnvironment::Test);

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame(KsefLatarniaStatus::Failure, $state->current_status);
        $this->assertDatabaseHas('ksef_latarnia_messages', ['external_message_id' => 'STATUS-UPGRADE']);
    }

    public function test_sync_preserves_messages_success_when_status_fails(): void
    {
        Http::fake(function (Request $request) {
            return str_ends_with($request->url(), '/status')
                ? Http::response(['error' => 'unavailable'], 500)
                : Http::response([$this->message(['id' => 'PARTIAL-MESSAGES'])]);
        });

        $result = app(KsefLatarniaSyncService::class)->sync(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertFalse($result->statusSuccess);
        $this->assertTrue($result->messagesSuccess);
        $this->assertSame('ksef_latarnia_http_error', $result->statusError);
        $this->assertNull($result->messagesError);
        $this->assertNull($state->current_status);
        $this->assertSame('ksef_latarnia_http_error', $state->status_last_error_code);
        $this->assertNotNull($state->messages_last_success_at);
        $this->assertDatabaseHas('ksef_latarnia_messages', ['external_message_id' => 'PARTIAL-MESSAGES']);
        Http::assertSentCount(2);
    }

    public function test_sync_preserves_status_and_embedded_messages_when_messages_endpoint_fails(): void
    {
        $embedded = $this->message([
            'id' => 'EMBEDDED-FAILURE',
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'end' => null,
        ]);
        Http::fake(function (Request $request) use ($embedded) {
            if (str_ends_with($request->url(), '/status')) {
                return Http::response(['status' => 'FAILURE', 'messages' => [$embedded]]);
            }

            return (Http::failedConnection('Synthetic messages failure'))($request);
        });

        $result = app(KsefLatarniaSyncService::class)->sync(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertTrue($result->statusSuccess);
        $this->assertFalse($result->messagesSuccess);
        $this->assertNull($result->statusError);
        $this->assertSame('ksef_latarnia_network_error', $result->messagesError);
        $this->assertSame(KsefLatarniaStatus::Failure, $state->current_status);
        $this->assertNotNull($state->status_last_success_at);
        $this->assertNull($state->messages_last_success_at);
        $this->assertSame('ksef_latarnia_network_error', $state->messages_last_error_code);
        $this->assertDatabaseHas('ksef_latarnia_messages', ['external_message_id' => 'EMBEDDED-FAILURE']);
        Http::assertSentCount(2);
    }

    public function test_success_after_failure_clears_only_current_endpoint_error(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'unavailable'], 500)
            ->push(['status' => 'AVAILABLE']);
        $sync = app(KsefLatarniaSyncService::class);
        $this->assertKsefError(
            'ksef_latarnia_http_error',
            fn () => $sync->syncStatus(KsefLatarniaEnvironment::Test),
        );

        $sync->syncStatus(KsefLatarniaEnvironment::Test);
        $state = KsefLatarniaSyncState::query()->firstOrFail();

        $this->assertSame(KsefLatarniaStatus::Available, $state->current_status);
        $this->assertNull($state->status_last_error_at);
        $this->assertNull($state->status_last_error_code);
        $this->assertNull($state->status_last_error_message);
        $this->assertNotNull($state->status_last_success_at);
    }

    public function test_historical_message_business_fields_and_deletion_are_blocked(): void
    {
        Http::fake(['*' => Http::response([$this->message(['id' => 'IMMUTABLE-1'])])]);
        app(KsefLatarniaSyncService::class)->syncMessages(KsefLatarniaEnvironment::Test);
        $message = KsefLatarniaMessage::query()->firstOrFail();

        try {
            $message->forceFill(['title' => 'Niedozwolona zmiana'])->save();
            $this->fail('Expected immutable Latarnia message update block.');
        } catch (DomainException $exception) {
            $this->assertSame('Historia komunikatów Latarni KSeF jest niezmienna.', $exception->getMessage());
        }

        $message->refresh();
        try {
            $message->delete();
            $this->fail('Expected immutable Latarnia message deletion block.');
        } catch (DomainException $exception) {
            $this->assertSame('Historia komunikatów Latarni KSeF jest niezmienna.', $exception->getMessage());
        }

        $this->assertDatabaseHas('ksef_latarnia_messages', [
            'external_message_id' => 'IMMUTABLE-1',
            'title' => 'Komunikat testowy',
        ]);
    }

    private function assertKsefError(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected controlled Latarnia failure.');
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);
        }
    }

    private function message(array $overrides = []): array
    {
        return array_replace([
            'id' => 'MSG-1',
            'eventId' => 101,
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Komunikat testowy',
            'text' => 'Publiczna treść komunikatu testowego.',
            'start' => '2026-09-03T12:00:00+02:00',
            'end' => '2026-09-03T13:00:00+02:00',
            'version' => 1,
            'published' => '2026-09-03T09:00:00Z',
        ], $overrides);
    }
}
