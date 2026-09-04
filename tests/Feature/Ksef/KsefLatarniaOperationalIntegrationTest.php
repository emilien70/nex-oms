<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefLatarniaOperationalSyncService;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefLatarniaOperationalIntegrationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-04T10:00:00Z'));
    }

    public function test_migration_adds_nullable_exact_coverage_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ksef_latarnia_sync_states', [
            'messages_coverage_from_at',
            'messages_coverage_through_at',
        ]));
        $state = KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
        ]);

        $this->assertNull($state->messages_coverage_from_at);
        $this->assertNull($state->messages_coverage_through_at);
    }

    public function test_disabled_scheduled_gate_makes_no_http_request_or_latarnia_write(): void
    {
        config()->set('ksef.latarnia.sync_enabled', false);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['is_active' => true, 'environment' => KsefEnvironment::Test])->save();

        $result = app(KsefLatarniaOperationalSyncService::class)->runScheduled();

        $this->assertSame([], $result);
        $this->assertDatabaseCount('ksef_latarnia_sync_states', 0);
        $this->assertDatabaseCount('ksef_latarnia_messages', 0);
        Http::assertNothingSent();
    }

    public function test_scheduled_sync_deduplicates_active_test_and_historical_production(): void
    {
        config()->set('ksef.latarnia.sync_enabled', true);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['is_active' => true, 'environment' => KsefEnvironment::Test])->save();
        $this->offlineIssuance(KsefEnvironment::Production);
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/status')
            ? Http::response(['status' => 'AVAILABLE'])
            : Http::response([]));

        $result = app(KsefLatarniaOperationalSyncService::class)->runScheduled();

        $this->assertSame(['test', 'production'], array_keys($result));
        Http::assertSentCount(4);
        foreach ([
            'https://api-latarnia-test.ksef.mf.gov.pl/status',
            'https://api-latarnia-test.ksef.mf.gov.pl/messages',
            'https://api-latarnia.ksef.mf.gov.pl/status',
            'https://api-latarnia.ksef.mf.gov.pl/messages',
        ] as $url) {
            Http::assertSent(fn (Request $request): bool => $request->url() === $url);
        }
    }

    public function test_demo_has_no_fallback_but_historical_test_remains_relevant(): void
    {
        config()->set('ksef.latarnia.sync_enabled', true);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['is_active' => true, 'environment' => KsefEnvironment::Demo])->save();

        $this->assertSame([], app(KsefLatarniaOperationalSyncService::class)->runScheduled());
        Http::assertNothingSent();

        $this->offlineIssuance(KsefEnvironment::Test);
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/status')
            ? Http::response(['status' => 'AVAILABLE'])
            : Http::response([]));

        $result = app(KsefLatarniaOperationalSyncService::class)->runScheduled();

        $this->assertSame(['test'], array_keys($result));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api-latarnia.ksef.mf.gov.pl'));
    }

    public function test_manual_refresh_works_with_gate_disabled_and_reports_partial_success(): void
    {
        config()->set('ksef.latarnia.sync_enabled', false);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Test])->save();
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/status')
            ? Http::response(['status' => 'AVAILABLE'])
            : Http::response(['error' => 'unavailable'], 500));

        $response = $this->post(route('integrations.ksef.latarnia.refresh'));

        $response->assertRedirect(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSessionHas('warning', 'Odświeżono status Latarni, ale nie udało się odświeżyć historii komunikatów.');
        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame(KsefLatarniaStatus::Available, $state->current_status);
        $this->assertNull($state->messages_coverage_through_at);
        Http::assertSentCount(2);
    }

    public function test_manual_production_refresh_reports_full_success_without_environment_fallback(): void
    {
        config()->set('ksef.latarnia.sync_enabled', false);
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Production])->save();
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/status')
            ? Http::response(['status' => 'AVAILABLE'])
            : Http::response([]));

        $this->post(route('integrations.ksef.latarnia.refresh'))
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSessionHas('status', 'Dane Latarni KSeF zostały odświeżone.');

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame(KsefLatarniaEnvironment::Production, $state->source_environment);
        $this->assertSame(KsefLatarniaStatus::Available, $state->current_status);
        $this->assertNotNull($state->messages_coverage_from_at);
        $this->assertNotNull($state->messages_coverage_through_at);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-latarnia.ksef.mf.gov.pl/status');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-latarnia.ksef.mf.gov.pl/messages');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api-latarnia-test'));
    }

    public function test_manual_refresh_reports_messages_only_partial_success(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Test])->save();
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/status')
            ? Http::response(['error' => 'unavailable'], 500)
            : Http::response([]));

        $this->post(route('integrations.ksef.latarnia.refresh'))
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSessionHas('warning', 'Odświeżono historię komunikatów, ale nie udało się odświeżyć statusu Latarni.');

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertNull($state->current_status);
        $this->assertSame('ksef_latarnia_http_error', $state->status_last_error_code);
        $this->assertNotNull($state->messages_coverage_through_at);
        Http::assertSentCount(2);
    }

    public function test_manual_refresh_reports_complete_failure_without_advancing_coverage(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Test])->save();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

        $this->post(route('integrations.ksef.latarnia.refresh'))
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSessionHas('error', 'Nie udało się odświeżyć danych Latarni KSeF.');

        $state = KsefLatarniaSyncState::query()->firstOrFail();
        $this->assertSame('ksef_latarnia_http_error', $state->status_last_error_code);
        $this->assertSame('ksef_latarnia_http_error', $state->messages_last_error_code);
        $this->assertNull($state->messages_coverage_from_at);
        $this->assertNull($state->messages_coverage_through_at);
        Http::assertSentCount(2);
    }

    public function test_manual_demo_refresh_is_safe_and_never_falls_back_to_production(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Demo])->save();

        $this->post(route('integrations.ksef.latarnia.refresh'))
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSessionHas('warning', 'Latarnia niedostępna dla środowiska DEMO.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ksef_latarnia_sync_states', 0);
    }

    public function test_latarnia_tab_reads_fresh_local_state_and_only_twenty_recent_messages_without_http(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Test])->save();
        KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'current_status' => KsefLatarniaStatus::Available,
            'status_last_success_at' => CarbonImmutable::parse('2026-09-04T09:58:00Z'),
            'messages_last_success_at' => CarbonImmutable::parse('2026-09-04T09:59:00Z'),
            'messages_coverage_from_at' => CarbonImmutable::parse('2026-08-05T09:59:00Z'),
            'messages_coverage_through_at' => CarbonImmutable::parse('2026-09-04T09:59:00Z'),
            'status_last_error_message' => 'Bezpieczny błąd statusu',
        ]);

        foreach (range(1, 22) as $index) {
            $this->latarniaMessage($index);
        }

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'latarnia']));

        $response->assertOk()
            ->assertSee('Latarnia KSeF')
            ->assertSee('KSeF dostępny')
            ->assertSee('Aktualna')
            ->assertSee('Komunikat 22')
            ->assertSee('Komunikat 3')
            ->assertDontSee('data-latarnia-message="MSG-2"', false)
            ->assertDontSee('PAYLOAD_SHOULD_NOT_BE_RENDERED')
            ->assertSeeInOrder(['Komunikat 22', 'Komunikat 21']);
        Http::assertNothingSent();
    }

    public function test_stale_available_and_demo_never_present_available_as_current(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'current_status' => KsefLatarniaStatus::Available,
            'status_last_success_at' => CarbonImmutable::parse('2026-09-04T09:44:59Z'),
        ]);

        $this->get(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSee('Brak aktualnych danych Latarni')
            ->assertDontSee('KSeF dostępny');

        $settings->forceFill(['environment' => KsefEnvironment::Demo])->save();
        $this->get(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSee('Latarnia niedostępna dla środowiska DEMO.')
            ->assertDontSee('KSeF dostępny');
        Http::assertNothingSent();
    }

    public function test_fresh_failure_statuses_are_presented_with_explicit_polish_labels(): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill(['environment' => KsefEnvironment::Test])->save();
        $state = KsefLatarniaSyncState::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'current_status' => KsefLatarniaStatus::Failure,
            'status_last_success_at' => CarbonImmutable::parse('2026-09-04T09:59:00Z'),
        ]);

        $this->get(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSee('Awaria KSeF');

        $state->forceFill(['current_status' => KsefLatarniaStatus::TotalFailure])->save();

        $this->get(route('integrations.ksef.edit', ['tab' => 'latarnia']))
            ->assertSee('Awaria całkowita KSeF');
        Http::assertNothingSent();
    }

    private function offlineIssuance(KsefEnvironment $environment): KsefOfflineIssuance
    {
        $order = $this->createDocumentOrder(['external_id' => 'LATARNIA-'.uniqid()]);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, ['include_shipping' => false]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext('2026-09-04 10:00:00'),
        );

        return $this->createOfflineIssuance($invoice, $environment);
    }

    private function createOfflineIssuance(Invoice $invoice, KsefEnvironment $environment): KsefOfflineIssuance
    {
        $payload = '<Faktura>Latarnia fixture</Faktura>';

        return KsefOfflineIssuance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'procedure' => KsefOfflineIssuanceProcedure::Offline24,
            'issue_date' => '2026-09-04',
            'issued_at' => CarbonImmutable::parse('2026-09-04T08:00:00Z'),
            'seller_nip' => '9876543210',
            'context_identifier_type' => 'Nip',
            'context_identifier_value' => '9876543210',
            'schema_id' => 'FA (3) 1-0E',
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'offline_certificate_id' => null,
            'certificate_serial_number' => 'ABC123',
            'certificate_fingerprint_sha256' => str_repeat('A', 64),
            'certificate_valid_from' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            'certificate_valid_until' => CarbonImmutable::parse('2027-01-01T00:00:00Z'),
            'certificate_remote_status' => 'Active',
            'certificate_remote_valid_from' => CarbonImmutable::parse('2026-01-01T00:00:00Z'),
            'certificate_remote_valid_until' => CarbonImmutable::parse('2027-01-01T00:00:00Z'),
            'certificate_remote_verified_at' => CarbonImmutable::parse('2026-09-04T08:00:00Z'),
            'invoice_verification_url' => 'https://example.test/invoice',
            'certificate_verification_url' => 'https://example.test/certificate',
        ]);
    }

    private function latarniaMessage(int $index): KsefLatarniaMessage
    {
        $instant = CarbonImmutable::parse('2026-09-04T08:00:00Z')->addMinutes($index);

        return KsefLatarniaMessage::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'MSG-'.$index,
            'event_id' => $index,
            'version' => 1,
            'category' => 'MAINTENANCE',
            'type' => 'MAINTENANCE_ANNOUNCEMENT',
            'title' => 'Komunikat '.$index,
            'text' => 'Bezpieczna treść.',
            'start_at' => $instant,
            'end_at' => $instant->addMinute(),
            'published_at' => $instant,
            'payload_json' => '{"secret":"PAYLOAD_SHOULD_NOT_BE_RENDERED"}',
            'payload_hash' => base64_encode(hash('sha256', (string) $index, true)),
            'first_fetched_at' => $instant,
            'last_seen_at' => $instant,
        ]);
    }
}
