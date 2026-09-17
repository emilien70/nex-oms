<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesRegisterKsefNumberTest extends TestCase
{
    use RefreshDatabase;

    private int $attempt = 0;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
    }

    public function test_only_production_acceptance_is_used_regardless_of_current_settings_or_newer_test_attempts(): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice);
        $this->submission($invoice, ['environment' => 'test', 'ksef_number' => $this->number('2')]);
        $this->submission($invoice, ['environment' => 'demo', 'ksef_number' => $this->number('3')]);
        foreach (KsefEnvironment::cases() as $environment) {
            KsefSetting::updateOrCreate(['singleton_key' => KsefSetting::SINGLETON_KEY], ['environment' => $environment]);
            $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
        }
        Http::assertNothingSent();
    }

    public function test_missing_production_number_keeps_invoice_and_does_not_borrow_source_number_for_correction(): void
    {
        $source = $this->invoice();
        $this->submission($source);
        $correction = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $source->id]);
        $this->submission($correction, ['environment' => 'demo']);
        $this->submission($correction, ['status' => 'rejected', 'ksef_number' => null]);
        $this->assertSame($correction->id, $this->row($correction)['id']);
        $this->assertNull($this->row($correction)['ksef_number']);
        $this->submission($correction, ['ksef_number' => $this->number('2')]);
        $this->assertSame($this->number('2'), $this->row($correction)['ksef_number']);
        $this->assertSame($this->number(), $this->row($source)['ksef_number']);
    }

    public function test_repeated_number_is_unambiguous_but_different_accepted_numbers_are_not(): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice);
        $this->submission($invoice);
        $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
        $this->submission($invoice, ['ksef_number' => $this->number('2')]);
        $row = $this->row($invoice);
        $this->assertNull($row['ksef_number']);
        $this->assertContains('sales_register_ksef_number_ambiguous', array_column($row['warnings'], 'code'));
    }

    public function test_authorization_date_uses_same_production_link_utc_cast_and_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        $invoice = $this->invoice();
        $this->submission($invoice, ['acquisition_date' => '2026-08-21 23:30:00']);
        $this->submission($invoice, ['environment' => 'demo', 'acquisition_date' => '2026-09-01 12:00:00']);
        $this->submission($invoice, ['acquisition_date' => '2026-08-21 23:30:00']);
        $this->assertSame('2026-08-22', $this->row($invoice)['ksef_authorization_date']);
        $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
        $correction = $this->invoice(['document_type' => 'correction', 'corrected_invoice_id' => $invoice->id]);
        $this->assertNull($this->row($correction)['ksef_authorization_date']);
        $this->submission($correction, ['ksef_number' => $this->number('2'), 'acquisition_date' => '2026-08-25 23:30:00']);
        $this->assertSame('2026-08-26', $this->row($correction)['ksef_authorization_date']);
        $this->assertSame('2026-08-21', $this->row($correction)['issue_date']);
    }

    #[DataProvider('invalidAuthorizationDates')]
    public function test_unknown_or_conflicting_authorization_dates_keep_valid_number(?string $date, string $code): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice, ['acquisition_date' => '2026-08-21 12:00:00']);
        $this->submission($invoice, ['acquisition_date' => $date]);
        $row = $this->row($invoice);
        $this->assertSame($this->number(), $row['ksef_number']);
        $this->assertNull($row['ksef_authorization_date']);
        $this->assertContains('sales_register_ksef_authorization_date_'.$code, array_column($row['warnings'], 'code'));
    }

    public static function invalidAuthorizationDates(): array
    {
        return [
            'absent' => [null, 'unavailable'], 'invalid' => ['NOT A DATE', 'unavailable'],
            'normalized invalid date' => ['2026-02-30 12:00:00', 'unavailable'],
            'different instant same day' => ['2026-08-21 12:00:01', 'conflict'],
            'different day' => ['2026-08-22 12:00:00', 'conflict'],
        ];
    }

    #[DataProvider('invalidAcceptedMetadata')]
    public function test_invalid_accepted_metadata_is_reported_without_a_number(array $attributes): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice, $attributes);
        $row = $this->row($invoice);
        $this->assertNull($row['ksef_number']);
        $this->assertNull($row['ksef_authorization_date']);
        $this->assertContains('sales_register_ksef_link_invalid', array_column($row['warnings'], 'code'));
        $this->assertTrue($row['completeness']['original']);
    }

    public static function invalidAcceptedMetadata(): array
    {
        return [
            'malformed number' => [['ksef_number' => 'not-a-ksef-number']],
            'missing number' => [['ksef_number' => null]],
            'seller mismatch' => [['seller_nip' => '1111111111']],
            'context mismatch' => [['context_nip' => '1111111111']],
            'hash' => [['invoice_hash' => 'bad-hash']],
            'size' => [['invoice_size' => 0]],
            'malformed size' => [['invoice_size' => 'corrupt']],
            'schema' => [['schema_id' => '']],
            'unlinked offline' => [['invoicing_mode' => 'Offline']],
        ];
    }

    #[DataProvider('invalidSellerTaxIds')]
    public function test_invalid_seller_tax_id_keeps_both_documents_and_totals(array $attributes): void
    {
        $invalid = $this->invoice($attributes);
        $valid = $this->invoice();
        $this->submission($invalid);
        $this->submission($valid, ['ksef_number' => $this->number('2')]);
        $before = DB::table('invoices')->orderBy('id')->get()->toArray();
        $submissionsBefore = DB::table('ksef_invoice_submissions')->orderBy('id')->get()->toArray();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $report = app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments([$invalid->id, $valid->id]));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $rows = array_column($report['records'], null, 'id');
        $this->assertCount(2, $rows);
        $this->assertNull($rows[$invalid->id]['ksef_number']);
        $this->assertContains('sales_register_ksef_link_invalid', array_column($rows[$invalid->id]['warnings'], 'code'));
        $this->assertSame($this->number('2'), $rows[$valid->id]['ksef_number']);
        foreach ($rows as $row) {
            $this->assertSame(['net' => '1.00', 'vat' => '0.23', 'gross' => '1.23'], $row['totals']);
        }
        $this->assertSame(['net' => '2.00', 'vat' => '0.46', 'gross' => '2.46'], $report['summaries']['currencies']['PLN']['totals']);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
        }
        $this->assertEquals($before, DB::table('invoices')->orderBy('id')->get()->toArray());
        $this->assertEquals($submissionsBefore, DB::table('ksef_invoice_submissions')->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public static function invalidSellerTaxIds(): array
    {
        return [
            'array' => [['seller_snapshot' => ['tax_id' => ['1234563218']]]],
            'boolean true' => [['seller_snapshot' => ['tax_id' => true]]],
            'boolean false' => [['seller_snapshot' => ['tax_id' => false]]],
            'JSON object decoded as structure' => [['seller_snapshot' => ['tax_id' => (object) ['value' => '1234563218']]]],
            'explicit null' => [['seller_snapshot' => ['tax_id' => null]]],
            'explicit empty' => [['seller_snapshot' => ['tax_id' => '']]],
            'invalid scalar text' => [['seller_tax_id_snapshot' => 'not-a-nip']],
            'scalar persisted in TEXT column' => [['seller_tax_id_snapshot' => true]],
            'conflicting valid scalar' => [['seller_tax_id_snapshot' => '5260250995']],
        ];
    }

    #[DataProvider('validSellerTaxIds')]
    public function test_valid_seller_tax_id_normalization_and_missing_key_fallback_are_preserved(array $attributes): void
    {
        $invoice = $this->invoice($attributes);
        $this->submission($invoice);
        $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
    }

    public static function validSellerTaxIds(): array
    {
        return [
            'missing snapshot key' => [['seller_snapshot' => []]],
            'formatted snapshot and scalar' => [['seller_snapshot' => ['tax_id' => 'PL 123-456-32-18'], 'seller_tax_id_snapshot' => 'PL 123-456-32-18']],
            'snapshot without scalar' => [['seller_tax_id_snapshot' => null]],
        ];
    }

    public function test_conflicting_provenance_or_seller_snapshot_never_selects_arbitrary_number(): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice);
        DB::table('ksef_invoice_provenances')->insert([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'provenance' => 'outside_ksef', 'recorded_at' => '2026-08-21 12:00:00',
        ]);
        $this->assertNull($this->row($invoice)['ksef_number']);
        $other = $this->invoice(['seller_snapshot' => ['tax_id' => '']]);
        $this->submission($other);
        $this->assertNull($this->row($other)['ksef_number']);
    }

    public function test_offline_number_requires_own_production_issuance_with_matching_hash_date_and_size(): void
    {
        $invoice = $this->invoice();
        $issuance = $this->issuance($invoice);
        $this->submission($invoice, ['offline_issuance_id' => $issuance, 'invoicing_mode' => 'Offline']);
        $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
        foreach (['environment' => 'demo', 'invoice_hash' => $this->hash('different'), 'invoice_size' => 999, 'issue_date' => '2026-08-20',
            'invoice_id' => $this->invoice()->id] as $field => $badValue) {
            $original = DB::table('ksef_offline_issuances')->where('id', $issuance)->value($field);
            DB::table('ksef_offline_issuances')->where('id', $issuance)->update([$field => $badValue]);
            $this->assertNull($this->row($invoice)['ksef_number'], $field);
            DB::table('ksef_offline_issuances')->where('id', $issuance)->update([$field => $original]);
        }
    }

    public function test_technical_offline_number_requires_full_local_chain_of_matching_metadata(): void
    {
        $invoice = $this->invoice(['document_type' => 'correction']);
        $issuance = $this->issuance($invoice);
        $rejected = $this->submission($invoice, ['offline_issuance_id' => $issuance, 'invoicing_mode' => 'Offline', 'status' => 'rejected', 'ksef_number' => null]);
        $technical = DB::table('ksef_offline_technical_corrections')->insertGetId([
            'invoice_id' => $invoice->id, 'offline_issuance_id' => $issuance, 'rejected_submission_id' => $rejected,
            'environment' => 'production', 'context_nip' => '1234563218', 'seller_nip' => '1234563218',
            'schema_id' => 'FA(3)', 'generated_at' => '2026-08-21 12:00:00', 'payload_xml' => 'UNREADABLE_FAKE_ENCRYPTED_XML',
            'invoice_hash' => $this->hash('technical'), 'invoice_size' => 42, 'hash_of_corrected_invoice' => $this->hash(),
            'source_status_code' => 450, 'eligibility_policy_version' => 1, 'business_fingerprint' => $this->hash('business'), 'business_fingerprint_version' => 2,
        ]);
        $this->submission($invoice, ['offline_issuance_id' => $issuance, 'offline_technical_correction_id' => $technical,
            'invoicing_mode' => 'Offline', 'invoice_hash' => $this->hash('technical'), 'invoice_size' => 42]);
        $this->assertSame($this->number(), $this->row($invoice)['ksef_number']);
        foreach (['environment' => 'test', 'invoice_hash' => $this->hash(), 'hash_of_corrected_invoice' => $this->hash('wrong'),
            'invoice_id' => $this->invoice()->id] as $field => $badValue) {
            $original = DB::table('ksef_offline_technical_corrections')->where('id', $technical)->value($field);
            DB::table('ksef_offline_technical_corrections')->where('id', $technical)->update([$field => $badValue]);
            $this->assertNull($this->row($invoice)['ksef_number'], $field);
            DB::table('ksef_offline_technical_corrections')->where('id', $technical)->update([$field => $original]);
        }
        DB::table('ksef_invoice_submissions')->where('id', $rejected)->update(['status' => 'uncertain']);
        $this->assertNull($this->row($invoice)['ksef_number']);
    }

    public function test_reads_metadata_only_and_option_off_avoids_all_ksef_queries(): void
    {
        $invoice = $this->invoice();
        $this->submission($invoice);
        foreach ([true, false] as $include) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $report = app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments([$invoice->id], $include));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame($include ? $this->number() : null, $report['records'][0]['ksef_number']);
            foreach ($queries as $query) {
                $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
                $this->assertDoesNotMatchRegularExpression('/payload_xml|credentials|access_token|private_key|ksef_settings/i', $query['query']);
                if (! $include) {
                    $this->assertStringNotContainsString('ksef_', $query['query']);
                }
            }
            $this->assertStringNotContainsString('UNREADABLE_FAKE_ENCRYPTED_XML', json_encode($report));
        }
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function invoice(array $attributes = []): Invoice
    {
        $type = $attributes['document_type'] ?? 'invoice';

        return Invoice::create(array_replace([
            'invoice_series_id' => InvoiceSeries::where('system_key', $type)->value('id'), 'document_type' => $type,
            'status' => 'issued', 'issue_date' => '2026-08-21', 'number' => 'FAKE REGISTER '.++$this->sequence, 'currency' => 'PLN',
            'seller_tax_id_snapshot' => '1234563218', 'seller_snapshot' => ['tax_id' => '1234563218'],
            'total_net' => '1.00', 'total_vat' => '0.23', 'total_gross' => '1.23',
            'tax_summary_snapshot' => [['vat_rate' => '23.00', 'vat_code' => null, 'net' => '1.00', 'vat' => '0.23', 'gross' => '1.23']],
        ], $attributes));
    }

    private function submission(Invoice $invoice, array $attributes = []): int
    {
        return DB::table('ksef_invoice_submissions')->insertGetId(array_replace([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'attempt_number' => ++$this->attempt,
            'status' => 'accepted', 'schema_id' => 'FA(3)', 'generated_at' => '2026-08-21 12:00:00',
            'seller_nip' => '1234563218', 'context_nip' => '1234563218', 'payload_xml' => 'UNREADABLE_FAKE_ENCRYPTED_XML',
            'invoice_hash' => $this->hash(), 'invoice_size' => 10, 'ksef_number' => $this->number(), 'invoicing_mode' => 'Online',
        ], $attributes));
    }

    private function issuance(Invoice $invoice): int
    {
        return DB::table('ksef_offline_issuances')->insertGetId([
            'invoice_id' => $invoice->id, 'environment' => 'production', 'procedure' => 'offline24', 'issue_date' => '2026-08-21',
            'issued_at' => '2026-08-21 12:00:00', 'seller_nip' => '1234563218', 'context_identifier_type' => 'Nip',
            'context_identifier_value' => '1234563218', 'schema_id' => 'FA(3)', 'payload_xml' => 'UNREADABLE_FAKE_ENCRYPTED_XML',
            'invoice_hash' => $this->hash(), 'invoice_size' => 10, 'certificate_serial_number' => 'FAKE',
            'certificate_fingerprint_sha256' => str_repeat('a', 64), 'certificate_valid_from' => '2026-01-01 00:00:00',
            'certificate_valid_until' => '2026-12-31 00:00:00', 'certificate_remote_status' => 'Active',
            'certificate_remote_valid_from' => '2026-01-01 00:00:00', 'certificate_remote_valid_until' => '2026-12-31 00:00:00',
            'certificate_remote_verified_at' => '2026-08-21 12:00:00', 'invoice_verification_url' => 'FAKE', 'certificate_verification_url' => 'FAKE',
        ]);
    }

    private function row(Invoice $invoice): array
    {
        return app(SalesRegisterDataService::class)->build(SalesRegisterFilters::forDocuments([$invoice->id]))['records'][0];
    }

    private function hash(string $value = 'original'): string
    {
        return base64_encode(hash('sha256', $value, true));
    }

    private function number(string $suffix = '1'): string
    {
        $base = '1234563218-20260821-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
        $crc = 0;
        foreach (str_split($base) as $character) {
            $crc ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80) !== 0 ? ($crc << 1) ^ 0x07 : $crc << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($crc), 2, '0', STR_PAD_LEFT));
    }
}
