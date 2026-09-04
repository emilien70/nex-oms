<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceEditabilityPolicy;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceProvenanceType;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceProvenance;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Services\KsefInvoiceProvenanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefInvoiceProvenanceServiceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_schema_and_model_store_explicit_environment_scoped_outside_provenance(): void
    {
        $invoice = $this->invoice();
        $provenance = $this->mark($invoice, KsefEnvironment::Production);

        $this->assertTrue(Schema::hasColumns('ksef_invoice_provenances', [
            'invoice_id',
            'environment',
            'provenance',
            'recorded_at',
            'created_at',
            'updated_at',
        ]));
        $indexes = collect(Schema::getIndexes('ksef_invoice_provenances'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['invoice_id', 'environment']
                && $index['unique'],
        ));
        $this->assertSame(KsefEnvironment::Production, $provenance->environment);
        $this->assertSame(KsefInvoiceProvenanceType::OutsideKsef, $provenance->provenance);
        $this->assertNotNull($provenance->recorded_at);
        $this->assertSame($invoice->getKey(), $provenance->invoice->getKey());
        $this->assertSame($provenance->getKey(), $invoice->fresh()->ksefProvenances()->sole()->getKey());
        $this->assertSame('production', DB::table('ksef_invoice_provenances')->value('environment'));
        $this->assertSame('outside_ksef', DB::table('ksef_invoice_provenances')->value('provenance'));
        Http::assertNothingSent();
    }

    public function test_marking_the_same_environment_twice_is_idempotent(): void
    {
        $invoice = $this->invoice();
        $first = $this->mark($invoice, KsefEnvironment::Production);
        $recordedAt = $first->recorded_at;

        $second = $this->mark($invoice, KsefEnvironment::Production);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertTrue($recordedAt->equalTo($second->recorded_at));
        $this->assertSame(1, KsefInvoiceProvenance::query()->count());
    }

    public function test_unfinalized_invoice_cannot_be_marked_as_outside_ksef(): void
    {
        $invoice = $this->invoice(finalize: false);

        $this->expectDomainError(
            'ksef_invoice_provenance_document_not_finalized',
            fn () => $this->mark($invoice, KsefEnvironment::Production),
        );

        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_unfinalized_invoice_with_provenance_is_not_editable(): void
    {
        $invoice = $this->invoice(finalize: false);
        KsefInvoiceProvenance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Demo,
            'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
            'recorded_at' => now(),
        ]);

        $this->expectDomainError(
            'invoice_edit_blocked_by_ksef_provenance',
            fn () => app(InvoiceEditabilityPolicy::class)->assertEditable($invoice),
        );
    }

    #[DataProvider('invalidDocumentProvider')]
    public function test_only_a_numbered_issued_invoice_can_be_marked(array $attributes): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill($attributes)->saveQuietly();

        $this->expectDomainError(
            'ksef_invoice_provenance_document_invalid',
            fn () => $this->mark($invoice->refresh(), KsefEnvironment::Production),
        );

        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
    }

    #[DataProvider('submissionStatusProvider')]
    public function test_any_same_environment_submission_history_blocks_mark(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->invoice();
        $this->submission($invoice, KsefEnvironment::Production, $status);

        $exception = $this->expectDomainError(
            'ksef_invoice_provenance_submission_history_exists',
            fn () => $this->mark($invoice, KsefEnvironment::Production),
        );

        $this->assertSame('production', $exception->metadata()['environment']);
        $this->assertSame([$status->value], $exception->metadata()['submission_statuses']);
        $this->assertDatabaseCount('ksef_invoice_provenances', 0);
    }

    #[DataProvider('nonProductionEnvironmentProvider')]
    public function test_test_environment_acceptance_does_not_block_production_mark(
        KsefEnvironment $environment,
    ): void {
        $invoice = $this->invoice();
        $this->submission($invoice, $environment, KsefInvoiceSubmissionStatus::Accepted);

        $provenance = $this->mark($invoice, KsefEnvironment::Production);

        $this->assertSame(KsefEnvironment::Production, $provenance->environment);
        $this->assertDatabaseCount('ksef_invoice_provenances', 1);
    }

    public function test_unique_constraint_prevents_duplicate_environment_provenance(): void
    {
        $invoice = $this->invoice();
        $this->mark($invoice, KsefEnvironment::Production);

        $this->expectException(QueryException::class);
        KsefInvoiceProvenance::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Production,
            'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
            'recorded_at' => now(),
        ]);
    }

    public function test_foreign_key_rejects_unknown_invoice(): void
    {
        $this->expectException(QueryException::class);
        KsefInvoiceProvenance::query()->create([
            'invoice_id' => 999999,
            'environment' => KsefEnvironment::Production,
            'provenance' => KsefInvoiceProvenanceType::OutsideKsef,
            'recorded_at' => now(),
        ]);
    }

    public static function invalidDocumentProvider(): array
    {
        return [
            'correction' => [['document_type' => InvoiceDocumentType::Correction]],
            'proforma' => [['document_type' => InvoiceDocumentType::Proforma]],
            'draft invoice' => [['status' => InvoiceDocumentStatus::Draft]],
            'missing number' => [['number' => null]],
            'blank number' => [['number' => '   ']],
            'missing issue date' => [['issue_date' => null]],
        ];
    }

    public static function submissionStatusProvider(): array
    {
        return array_combine(
            array_map(static fn (KsefInvoiceSubmissionStatus $status): string => $status->value, KsefInvoiceSubmissionStatus::cases()),
            array_map(static fn (KsefInvoiceSubmissionStatus $status): array => [$status], KsefInvoiceSubmissionStatus::cases()),
        );
    }

    public static function nonProductionEnvironmentProvider(): array
    {
        return [
            'demo' => [KsefEnvironment::Demo],
            'test' => [KsefEnvironment::Test],
        ];
    }

    private function invoice(bool $finalize = true): Invoice
    {
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-PROVENANCE-'.uniqid(),
            'total_gross' => '123.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
        ]);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext('2026-08-30 10:00:00'),
        );

        return $finalize
            ? app(InvoiceFinalizationService::class)->finalize($invoice)
            : $invoice;
    }

    private function submission(
        Invoice $invoice,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status,
    ): KsefInvoiceSubmission {
        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA3',
            'generated_at' => CarbonImmutable::parse('2026-08-30T10:00:00Z'),
            'payload_xml' => '<Faktura/>',
            'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => strlen('<Faktura/>'),
        ]);
    }

    private function mark(
        Invoice $invoice,
        KsefEnvironment $environment,
    ): KsefInvoiceProvenance {
        return app(KsefInvoiceProvenanceService::class)->markOutsideKsef($invoice, $environment);
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Expected domain error '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
