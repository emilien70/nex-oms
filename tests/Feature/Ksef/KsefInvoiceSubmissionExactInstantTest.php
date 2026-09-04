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
use Modules\Ksef\Models\KsefInvoiceSubmission;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefInvoiceSubmissionExactInstantTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private const TARGET_FIELDS = [
        'session_valid_until',
        'session_closed_at',
        'acquisition_date',
        'invoicing_date',
        'permanent_storage_date',
        'last_checked_at',
        'next_follow_up_at',
        'last_follow_up_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('app.timezone', 'Europe/Warsaw');
    }

    #[DataProvider('targetFieldProvider')]
    public function test_target_field_round_trip_preserves_exact_utc_instant(string $field): void
    {
        $submission = $this->submission($this->invoice());

        foreach ($this->exactInstants() as [$input, $expectedRaw]) {
            $instant = CarbonImmutable::parse($input);
            $submission->forceFill([$field => $instant])->save();

            $this->assertSame($expectedRaw, $this->rawValue($submission, $field));

            $fresh = $submission->fresh();
            $this->assertSame($instant->getTimestamp(), $fresh->{$field}->getTimestamp());
            $this->assertSame('UTC', $fresh->{$field}->getTimezone()->getName());
        }

        Http::assertNothingSent();
    }

    public static function targetFieldProvider(): array
    {
        return collect(self::TARGET_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => [$field]])
            ->all();
    }

    #[DataProvider('applicationTimezoneProvider')]
    public function test_all_target_fields_are_independent_of_application_timezone(string $timezone): void
    {
        config()->set('app.timezone', $timezone);
        $instant = CarbonImmutable::parse('2026-09-03T17:26:27+02:00');
        $submission = $this->submission($this->invoice());
        $submission->forceFill(array_fill_keys(self::TARGET_FIELDS, $instant))->save();
        $fresh = $submission->fresh();

        foreach (self::TARGET_FIELDS as $field) {
            $this->assertSame('2026-09-03 15:26:27', $this->rawValue($submission, $field));
            $this->assertSame($instant->getTimestamp(), $fresh->{$field}->getTimestamp());
            $this->assertSame('UTC', $fresh->{$field}->getTimezone()->getName());
        }
    }

    public static function applicationTimezoneProvider(): array
    {
        return [
            'Warsaw' => ['Europe/Warsaw'],
            'Tokyo' => ['Asia/Tokyo'],
        ];
    }

    public function test_fall_repeated_hour_instants_remain_distinct_for_every_target_field(): void
    {
        $first = CarbonImmutable::parse('2026-10-25T00:30:00Z');
        $second = CarbonImmutable::parse('2026-10-25T01:30:00Z');
        $firstSubmission = $this->submission($this->invoice());
        $secondSubmission = $this->submission($this->invoice());
        $firstSubmission->forceFill(array_fill_keys(self::TARGET_FIELDS, $first))->save();
        $secondSubmission->forceFill(array_fill_keys(self::TARGET_FIELDS, $second))->save();
        $firstSubmission->refresh();
        $secondSubmission->refresh();

        foreach (self::TARGET_FIELDS as $field) {
            $this->assertSame(
                3600,
                $secondSubmission->{$field}->getTimestamp() - $firstSubmission->{$field}->getTimestamp(),
            );
            $this->assertSame('2026-10-25 00:30:00', $this->rawValue($firstSubmission, $field));
            $this->assertSame('2026-10-25 01:30:00', $this->rawValue($secondSubmission, $field));
        }
    }

    #[DataProvider('legacyTimestampProvider')]
    public function test_migration_converts_all_legacy_warsaw_wall_clocks_to_utc(
        string $legacyRaw,
        string $expectedUtcRaw,
    ): void {
        $submission = $this->submission($this->invoice());
        $generatedAt = $this->rawValue($submission, 'generated_at');
        DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->update(array_fill_keys(self::TARGET_FIELDS, $legacyRaw));

        $this->runMigration();

        $fresh = $submission->fresh();
        $expected = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $expectedUtcRaw, 'UTC');
        $this->assertNotFalse($expected);

        foreach (self::TARGET_FIELDS as $field) {
            $this->assertSame($expectedUtcRaw, $this->rawValue($submission, $field));
            $this->assertSame($expected->getTimestamp(), $fresh->{$field}->getTimestamp());
            $this->assertSame('UTC', $fresh->{$field}->getTimezone()->getName());
        }

        $this->assertSame($generatedAt, $this->rawValue($submission, 'generated_at'));
    }

    public static function legacyTimestampProvider(): array
    {
        return [
            'summer' => ['2026-09-03 17:26:27', '2026-09-03 15:26:27'],
            'winter' => ['2026-01-15 11:00:00', '2026-01-15 10:00:00'],
        ];
    }

    #[DataProvider('invalidLegacyTimestampProvider')]
    public function test_migration_fails_closed_without_partial_multi_column_updates(string $invalidRaw): void
    {
        $first = $this->submission($this->invoice());
        $second = $this->submission($this->invoice());
        $safeLegacy = '2026-09-03 17:26:27';

        foreach ([$first, $second] as $submission) {
            DB::table('ksef_invoice_submissions')
                ->where('id', $submission->getKey())
                ->update(array_fill_keys(self::TARGET_FIELDS, $safeLegacy));
        }
        DB::table('ksef_invoice_submissions')
            ->where('id', $second->getKey())
            ->update(['permanent_storage_date' => $invalidRaw]);

        try {
            $this->runMigration();
            $this->fail('Expected the submission timestamp migration to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nie wskazuje dokładnie jednego instantu', $exception->getMessage());
        }

        foreach (self::TARGET_FIELDS as $field) {
            $this->assertSame($safeLegacy, $this->rawValue($first, $field));
            $this->assertSame(
                $field === 'permanent_storage_date' ? $invalidRaw : $safeLegacy,
                $this->rawValue($second, $field),
            );
        }
    }

    public static function invalidLegacyTimestampProvider(): array
    {
        return [
            'ambiguous fall hour' => ['2026-10-25 02:30:00'],
            'nonexistent spring hour' => ['2026-03-29 02:30:00'],
            'invalid format' => ['NOT-A-DATETIME'],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    private function exactInstants(): array
    {
        return [
            ['2026-09-03T15:26:27Z', '2026-09-03 15:26:27'],
            ['2026-09-03T17:26:27+02:00', '2026-09-03 15:26:27'],
            ['2026-01-15T10:00:00Z', '2026-01-15 10:00:00'],
            ['2026-07-15T10:00:00Z', '2026-07-15 10:00:00'],
            ['2026-03-29T00:30:00Z', '2026-03-29 00:30:00'],
            ['2026-03-29T01:30:00Z', '2026-03-29 01:30:00'],
            ['2026-10-25T00:30:00Z', '2026-10-25 00:30:00'],
            ['2026-10-25T01:30:00Z', '2026-10-25 01:30:00'],
        ];
    }

    private function invoice(): Invoice
    {
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-SUBMISSION-UTC-'.uniqid(),
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

    private function submission(Invoice $invoice): KsefInvoiceSubmission
    {
        $payload = '<Faktura>Submission exact instant fixture</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Preparing,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => CarbonImmutable::parse('2026-09-03T15:26:27Z'),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
        ]);
    }

    private function rawValue(KsefInvoiceSubmission $submission, string $field): mixed
    {
        return DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->value($field);
    }

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_13_084000_normalize_ksef_submission_exact_instants_to_utc.php',
        );
        $migration->up();
    }
}
