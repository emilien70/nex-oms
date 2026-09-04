<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefLatarniaMessageCategory;
use Modules\Ksef\Enums\KsefLatarniaMessageType;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefExactInstantPersistenceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('app.timezone', 'Europe/Warsaw');
    }

    #[DataProvider('exactInstantProvider')]
    public function test_issuance_and_submission_store_exact_instants_as_utc(
        string $input,
        string $expectedRaw,
    ): void {
        $instant = CarbonImmutable::parse($input);
        $invoice = $this->invoice();
        $issuance = $this->issuance($invoice, $instant);
        $submission = $this->submission($invoice, $instant);

        $this->assertSame($expectedRaw, $this->rawIssuanceTime($issuance));
        $this->assertSame($expectedRaw, $this->rawSubmissionTime($submission));

        $freshIssuance = $issuance->fresh();
        $freshSubmission = $submission->fresh();

        $this->assertSame($instant->getTimestamp(), $freshIssuance->issued_at->getTimestamp());
        $this->assertSame($instant->getTimestamp(), $freshSubmission->generated_at->getTimestamp());
        $this->assertSame('UTC', $freshIssuance->issued_at->getTimezone()->getName());
        $this->assertSame('UTC', $freshSubmission->generated_at->getTimezone()->getName());
        Http::assertNothingSent();
    }

    public static function exactInstantProvider(): array
    {
        return [
            'normal UTC' => ['2026-09-03T15:26:27Z', '2026-09-03 15:26:27'],
            'explicit positive offset' => ['2026-09-03T17:26:27+02:00', '2026-09-03 15:26:27'],
            'winter' => ['2026-01-15T10:00:00Z', '2026-01-15 10:00:00'],
            'summer' => ['2026-07-15T10:00:00Z', '2026-07-15 10:00:00'],
            'spring before transition' => ['2026-03-29T00:30:00Z', '2026-03-29 00:30:00'],
            'spring after transition' => ['2026-03-29T01:30:00Z', '2026-03-29 01:30:00'],
            'fall first occurrence' => ['2026-10-25T00:30:00Z', '2026-10-25 00:30:00'],
            'fall second occurrence' => ['2026-10-25T01:30:00Z', '2026-10-25 01:30:00'],
        ];
    }

    #[DataProvider('applicationTimezoneProvider')]
    public function test_exact_instant_storage_is_independent_of_application_timezone(string $timezone): void
    {
        config()->set('app.timezone', $timezone);
        $instant = CarbonImmutable::parse('2026-10-25T00:30:00Z');
        $invoice = $this->invoice();
        $issuance = $this->issuance($invoice, $instant);
        $submission = $this->submission($invoice, $instant);

        $this->assertSame('2026-10-25 00:30:00', $this->rawIssuanceTime($issuance));
        $this->assertSame('2026-10-25 00:30:00', $this->rawSubmissionTime($submission));
        $this->assertSame($instant->getTimestamp(), $issuance->fresh()->issued_at->getTimestamp());
        $this->assertSame($instant->getTimestamp(), $submission->fresh()->generated_at->getTimestamp());
    }

    public static function applicationTimezoneProvider(): array
    {
        return [
            'Warsaw' => ['Europe/Warsaw'],
            'Tokyo' => ['Asia/Tokyo'],
        ];
    }

    public function test_fall_repeated_hour_instants_remain_distinct_for_both_models(): void
    {
        $first = CarbonImmutable::parse('2026-10-25T00:30:00Z');
        $second = CarbonImmutable::parse('2026-10-25T01:30:00Z');
        $firstInvoice = $this->invoice();
        $secondInvoice = $this->invoice();

        $firstIssuance = $this->issuance($firstInvoice, $first)->fresh();
        $secondIssuance = $this->issuance($secondInvoice, $second)->fresh();
        $firstSubmission = $this->submission($firstInvoice, $first)->fresh();
        $secondSubmission = $this->submission($secondInvoice, $second)->fresh();

        $this->assertSame(3600, $secondIssuance->issued_at->getTimestamp() - $firstIssuance->issued_at->getTimestamp());
        $this->assertSame(3600, $secondSubmission->generated_at->getTimestamp() - $firstSubmission->generated_at->getTimestamp());
        $this->assertNotSame($this->rawIssuanceTime($firstIssuance), $this->rawIssuanceTime($secondIssuance));
        $this->assertNotSame($this->rawSubmissionTime($firstSubmission), $this->rawSubmissionTime($secondSubmission));
    }

    #[DataProvider('legacyTimestampProvider')]
    public function test_migration_converts_legacy_warsaw_wall_clocks_to_utc(
        string $legacyRaw,
        string $expectedUtcRaw,
    ): void {
        $invoice = $this->invoice();
        $issuance = $this->issuance($invoice, CarbonImmutable::parse('2026-09-03T15:26:27Z'));
        $submission = $this->submission($invoice, CarbonImmutable::parse('2026-09-03T15:26:27Z'));
        DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->update(['issued_at' => $legacyRaw]);
        DB::table('ksef_invoice_submissions')->where('id', $submission->getKey())->update(['generated_at' => $legacyRaw]);

        $this->runMigration();

        $this->assertSame($expectedUtcRaw, $this->rawIssuanceTime($issuance));
        $this->assertSame($expectedUtcRaw, $this->rawSubmissionTime($submission));
        $expected = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $expectedUtcRaw, 'UTC');
        $this->assertSame($expected->getTimestamp(), $issuance->fresh()->issued_at->getTimestamp());
        $this->assertSame($expected->getTimestamp(), $submission->fresh()->generated_at->getTimestamp());
    }

    public static function legacyTimestampProvider(): array
    {
        return [
            'summer' => ['2026-09-03 17:26:27', '2026-09-03 15:26:27'],
            'winter' => ['2026-01-15 11:00:00', '2026-01-15 10:00:00'],
            'spring before gap' => ['2026-03-29 01:30:00', '2026-03-29 00:30:00'],
            'spring after gap' => ['2026-03-29 03:30:00', '2026-03-29 01:30:00'],
        ];
    }

    #[DataProvider('invalidLegacyTimestampProvider')]
    public function test_migration_fails_atomically_for_non_unique_legacy_wall_clocks(
        string $table,
        string $column,
        string $invalidRaw,
    ): void {
        $invoice = $this->invoice();
        $issuance = $this->issuance($invoice, CarbonImmutable::parse('2026-09-03T15:26:27Z'));
        $submission = $this->submission($invoice, CarbonImmutable::parse('2026-09-03T15:26:27Z'));
        $safeLegacy = '2026-09-03 17:26:27';
        DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->update(['issued_at' => $safeLegacy]);
        DB::table('ksef_invoice_submissions')->where('id', $submission->getKey())->update(['generated_at' => $safeLegacy]);
        DB::table($table)->where('id', 1)->update([$column => $invalidRaw]);

        try {
            $this->runMigration();
            $this->fail('Expected the legacy timestamp migration to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nie wskazuje dokładnie jednego instantu', $exception->getMessage());
        }

        $expectedIssuance = $table === 'ksef_offline_issuances' ? $invalidRaw : $safeLegacy;
        $expectedSubmission = $table === 'ksef_invoice_submissions' ? $invalidRaw : $safeLegacy;
        $this->assertSame($expectedIssuance, $this->rawIssuanceTime($issuance));
        $this->assertSame($expectedSubmission, $this->rawSubmissionTime($submission));
    }

    public static function invalidLegacyTimestampProvider(): array
    {
        return [
            'ambiguous issuance' => ['ksef_offline_issuances', 'issued_at', '2026-10-25 02:30:00'],
            'ambiguous submission' => ['ksef_invoice_submissions', 'generated_at', '2026-10-25 02:30:00'],
            'nonexistent issuance' => ['ksef_offline_issuances', 'issued_at', '2026-03-29 02:30:00'],
            'nonexistent submission' => ['ksef_invoice_submissions', 'generated_at', '2026-03-29 02:30:00'],
        ];
    }

    #[DataProvider('engineBoundaryProvider')]
    public function test_engine_uses_the_persisted_exact_issuance_instant(
        string $issuedAt,
        string $publishedAt,
        KsefOfflineSubmissionObligationStatus $expectedStatus,
    ): void {
        $invoice = $this->invoice();
        $issuance = $this->issuance(
            $invoice,
            CarbonImmutable::parse($issuedAt),
            '2026-10-25',
        )->fresh();
        $message = $this->failureMessage(CarbonImmutable::parse($publishedAt));

        $result = app(KsefOfflineSubmissionObligationEngine::class)->evaluate(
            $issuance,
            [$message],
            [],
            CarbonImmutable::parse('2026-10-25T03:00:00Z'),
            KsefLatarniaEvidenceCoverage::Complete,
        );

        $this->assertSame($expectedStatus, $result->status);
        $this->assertSame('2026-10-26', $result->baseDeadline->toDateString());
        $this->assertSame(
            $expectedStatus === KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd ? [901] : [],
            $result->appliedEventIds,
        );
    }

    public static function engineBoundaryProvider(): array
    {
        return [
            'publication after first occurrence' => [
                '2026-10-25T00:30:00Z',
                '2026-10-25T00:45:00Z',
                KsefOfflineSubmissionObligationStatus::WaitingForFailureEnd,
            ],
            'publication before second occurrence' => [
                '2026-10-25T01:30:00Z',
                '2026-10-25T01:15:00Z',
                KsefOfflineSubmissionObligationStatus::Pending,
            ],
        ];
    }

    private function invoice(): Invoice
    {
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-UTC-'.uniqid(),
            'delivery_cost_gross' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false,
        ]);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext('2026-09-03 10:00:00'),
        )->refresh();
    }

    private function issuance(
        Invoice $invoice,
        CarbonImmutable $issuedAt,
        string $issueDate = '2026-09-03',
    ): KsefOfflineIssuance {
        $payload = '<Faktura>UTC persistence fixture</Faktura>';

        return KsefOfflineIssuance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'procedure' => KsefOfflineIssuanceProcedure::Offline24,
            'issue_date' => $issueDate,
            'issued_at' => $issuedAt,
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
            'certificate_remote_verified_at' => CarbonImmutable::parse('2026-09-03T14:00:00Z'),
            'invoice_verification_url' => 'https://example.test/invoice',
            'certificate_verification_url' => 'https://example.test/certificate',
        ]);
    }

    private function submission(
        Invoice $invoice,
        CarbonImmutable $generatedAt,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>UTC submission fixture</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Preparing,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => $generatedAt,
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
        ]);
    }

    private function failureMessage(CarbonImmutable $publishedAt): KsefLatarniaMessage
    {
        return (new KsefLatarniaMessage)->forceFill([
            'id' => 901,
            'source_environment' => KsefLatarniaEnvironment::Test,
            'external_message_id' => 'UTC-BOUNDARY-901',
            'event_id' => 901,
            'version' => 1,
            'category' => KsefLatarniaMessageCategory::Failure,
            'type' => KsefLatarniaMessageType::FailureStart,
            'title' => 'Synthetic UTC boundary failure',
            'text' => 'Synthetic fixture.',
            'start_at' => $publishedAt,
            'end_at' => null,
            'published_at' => $publishedAt,
            'payload_json' => '{}',
            'payload_hash' => str_repeat('B', 44),
            'first_fetched_at' => $publishedAt,
            'last_seen_at' => $publishedAt,
        ]);
    }

    private function rawIssuanceTime(KsefOfflineIssuance $issuance): ?string
    {
        return DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->value('issued_at');
    }

    private function rawSubmissionTime(KsefInvoiceSubmission $submission): ?string
    {
        return DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->value('generated_at');
    }

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_13_083000_normalize_ksef_exact_instants_to_utc.php',
        );
        $migration->up();
    }
}
