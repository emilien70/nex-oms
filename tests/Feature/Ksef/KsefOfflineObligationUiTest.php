<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationQueryService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefOfflineObligationUiTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->asOf = CarbonImmutable::parse('2026-09-04T10:04:00Z');
        $this->travelTo($this->asOf);
        config()->set('ksef.latarnia.freshness_minutes', 15);
    }

    public function test_invoice_list_shows_local_complete_offline24_deadline_without_http(): void
    {
        $issuance = $this->offlineIssuance(KsefEnvironment::Test);
        $this->completeCoverage(KsefLatarniaEnvironment::Test);

        $response = $this->get(route('invoices.index'));

        $response->assertOk()
            ->assertSee($issuance->invoice->number)
            ->assertSee('Offline24 · do 07.09.2026')
            ->assertSee('Stan wg danych Latarni na: 04.09.2026 12:00')
            ->assertDontSee('Co najmniej jedna Faktura Offline24 wymaga uwagi.');
        Http::assertNothingSent();
    }

    public function test_stale_evidence_shows_only_a_labeled_base_deadline_and_global_warning(): void
    {
        $this->offlineIssuance(KsefEnvironment::Test);
        $this->completeCoverage(
            KsefLatarniaEnvironment::Test,
            through: CarbonImmutable::parse('2026-09-04T09:48:59Z'),
        );

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Offline24 · brak pełnych danych Latarni')
            ->assertSee('Bazowy termin Offline24: 07.09.2026')
            ->assertSee('Co najmniej jedna Faktura Offline24 wymaga uwagi.');
        Http::assertNothingSent();
    }

    public function test_demo_unsupported_evidence_is_informational_without_global_alarm_or_fallback(): void
    {
        $this->offlineIssuance(KsefEnvironment::Demo);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Offline24 · brak pełnych danych Latarni')
            ->assertDontSee('Co najmniej jedna Faktura Offline24 wymaga uwagi.');

        $this->assertDatabaseCount('ksef_latarnia_sync_states', 0);
        Http::assertNothingSent();
    }

    public function test_message_first_seen_after_evidence_as_of_cannot_close_an_earlier_projection(): void
    {
        $this->offlineIssuance(KsefEnvironment::Test);
        $this->completeCoverage(KsefLatarniaEnvironment::Test);
        $this->failureMessage(
            id: 'FAILURE-START',
            type: KsefLatarniaMessageType::FailureStart,
            publishedAt: CarbonImmutable::parse('2026-09-04T09:00:00Z'),
            firstFetchedAt: CarbonImmutable::parse('2026-09-04T09:01:00Z'),
        );
        $this->failureMessage(
            id: 'FAILURE-END',
            type: KsefLatarniaMessageType::FailureEnd,
            publishedAt: CarbonImmutable::parse('2026-09-04T09:30:00Z'),
            firstFetchedAt: CarbonImmutable::parse('2026-09-04T10:02:00Z'),
            endAt: CarbonImmutable::parse('2026-09-04T09:20:00Z'),
        );

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Offline24 · trwa awaria KSeF');
        Http::assertNothingSent();
    }

    public function test_total_failure_is_a_read_only_projection_without_submission_or_document_mutation(): void
    {
        $issuance = $this->offlineIssuance(KsefEnvironment::Test);
        $this->completeCoverage(KsefLatarniaEnvironment::Test);
        $this->failureMessage(
            id: 'TOTAL-START',
            type: KsefLatarniaMessageType::FailureStart,
            publishedAt: CarbonImmutable::parse('2026-09-04T09:00:00Z'),
            firstFetchedAt: CarbonImmutable::parse('2026-09-04T09:01:00Z'),
            category: KsefLatarniaMessageCategory::TotalFailure,
        );
        $invoiceBefore = $issuance->invoice->getAttributes();
        $issuanceBefore = $issuance->getAttributes();

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Offline24 · brak obowiązku wysyłki wg projekcji Latarni');

        $this->assertEquals($invoiceBefore, $issuance->invoice->fresh()->getAttributes());
        $this->assertEquals($issuanceBefore, $issuance->fresh()->getAttributes());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_submission_state_keeps_precedence_over_latarnia_evidence(): void
    {
        $issuance = $this->offlineIssuance(KsefEnvironment::Test);
        $this->submission($issuance, KsefInvoiceSubmissionStatus::Accepted, KsefInvoicingMode::Offline, 'REF-1');

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Offline24 · obowiązek wykonany');
        Http::assertNothingSent();
    }

    public function test_bulk_query_has_fixed_latarnia_and_submission_query_count(): void
    {
        $first = $this->offlineIssuance(KsefEnvironment::Test);
        $second = $this->offlineIssuance(KsefEnvironment::Test);
        $this->completeCoverage(KsefLatarniaEnvironment::Test);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $results = app(KsefOfflineSubmissionObligationQueryService::class)->forInvoices(
            collect([$first->invoice, $second->invoice]),
            $this->asOf,
        );

        $this->assertCount(2, $results);
        foreach ([
            'ksef_offline_issuances',
            'ksef_latarnia_sync_states',
            'ksef_latarnia_messages',
            'ksef_invoice_submissions',
        ] as $table) {
            $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, $table)));
        }
        Http::assertNothingSent();
    }

    private function offlineIssuance(KsefEnvironment $environment): KsefOfflineIssuance
    {
        $order = $this->createDocumentOrder(['external_id' => 'OBLIGATION-'.uniqid()]);
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, ['include_shipping' => false]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext('2026-09-04 10:00:00'),
        );
        $payload = '<Faktura>Offline obligation fixture</Faktura>';

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
        ])->load('invoice');
    }

    private function completeCoverage(
        KsefLatarniaEnvironment $environment,
        ?CarbonImmutable $through = null,
    ): KsefLatarniaSyncState {
        return KsefLatarniaSyncState::query()->create([
            'source_environment' => $environment,
            'messages_coverage_from_at' => CarbonImmutable::parse('2026-08-05T08:00:00Z'),
            'messages_coverage_through_at' => $through ?? CarbonImmutable::parse('2026-09-04T10:00:00Z'),
        ]);
    }

    private function failureMessage(
        string $id,
        KsefLatarniaMessageType $type,
        CarbonImmutable $publishedAt,
        CarbonImmutable $firstFetchedAt,
        KsefLatarniaMessageCategory $category = KsefLatarniaMessageCategory::Failure,
        ?CarbonImmutable $endAt = null,
    ): KsefLatarniaMessage {
        return KsefLatarniaMessage::query()->create([
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => $id,
            'event_id' => 501,
            'version' => 1,
            'category' => $category,
            'type' => $type,
            'title' => 'Synthetic failure',
            'text' => 'Synthetic failure fixture.',
            'start_at' => CarbonImmutable::parse('2026-09-04T08:30:00Z'),
            'end_at' => $endAt,
            'published_at' => $publishedAt,
            'payload_json' => '{}',
            'payload_hash' => base64_encode(hash('sha256', $id, true)),
            'first_fetched_at' => $firstFetchedAt,
            'last_seen_at' => $firstFetchedAt,
        ]);
    }

    private function submission(
        KsefOfflineIssuance $issuance,
        KsefInvoiceSubmissionStatus $status,
        KsefInvoicingMode $mode,
        ?string $reference,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>Submission fixture</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $issuance->invoice_id,
            'offline_issuance_id' => $issuance->getKey(),
            'environment' => $issuance->environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => $this->asOf,
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'invoicing_mode' => $mode,
            'invoice_reference_number' => $reference,
        ]);
    }
}
