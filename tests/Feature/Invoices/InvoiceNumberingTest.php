<?php

namespace Tests\Feature\Invoices;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceNumberCounterAdjustment;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceNumberFormatter;
use Modules\Invoices\Services\InvoiceNumberingConfigurationValidator;
use Modules\Invoices\Services\InvoiceNumberingPeriodResolver;
use Modules\Invoices\Services\InvoiceNumberingService;
use Modules\Invoices\Services\InvoiceSeriesManagementService;
use Tests\TestCase;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbering_tables_columns_and_relations_exist_without_released_number_pool(): void
    {
        $this->assertTrue(Schema::hasColumns('invoice_number_counters', [
            'id', 'invoice_series_id', 'numbering_period_key', 'last_sequence_number',
            'protected_floor_sequence_number', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('invoice_number_counter_adjustments', [
            'id', 'invoice_number_counter_id', 'numbering_period_key_snapshot',
            'series_name_snapshot', 'number_format_snapshot', 'previous_last_sequence_number',
            'new_last_sequence_number', 'previous_protected_floor_sequence_number',
            'new_protected_floor_sequence_number', 'previous_next_sequence_number',
            'new_next_sequence_number', 'reason', 'actor_snapshot', 'metadata', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('invoice_number_counter_adjustments', 'updated_at'));
        $this->assertFalse(Schema::hasTable('invoice_released_numbers'));
        $this->assertFalse(Schema::hasTable('invoice_number_gaps'));
        $this->assertFalse(Schema::hasTable('oss_settings'));
        $this->assertFalse(Schema::hasColumn('invoices', 'ksef_number'));

        $series = $this->createSeries('Relacje licznikow');
        $counter = $series->numberCounters()->create(['numbering_period_key' => '2026']);
        $adjustment = $counter->adjustments()->create($this->adjustmentPayload());

        $this->assertTrue($counter->series->is($series));
        $this->assertTrue($adjustment->counter->is($counter));
        $this->assertSame(1, $counter->nextSequenceNumber());

        try {
            $adjustment->update(['reason' => 'Niedozwolona zmiana']);
            $this->fail('Historia licznika zostala zmieniona.');
        } catch (DomainException) {
            $this->assertSame('Test relacji', $adjustment->refresh()->reason);
        }
    }

    public function test_counter_and_invoice_sequence_uniqueness_are_enforced_while_null_drafts_are_allowed(): void
    {
        $series = $this->createSeries('Unikalnosc');
        InvoiceNumberCounter::query()->create([
            'invoice_series_id' => $series->id,
            'numbering_period_key' => '2026',
        ]);

        try {
            InvoiceNumberCounter::query()->create([
                'invoice_series_id' => $series->id,
                'numbering_period_key' => '2026',
            ]);
            $this->fail('Powielony licznik zostal zapisany.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->createDraft($series, ['number' => 'A/1', 'sequence_number' => 1, 'numbering_period_key' => '2026']);
        $this->createDraft($series);
        $this->createDraft($series);

        $this->expectException(QueryException::class);
        $this->createDraft($series, ['number' => 'A/2', 'sequence_number' => 1, 'numbering_period_key' => '2026']);
    }

    public function test_period_resolver_supports_monthly_fiscal_yearly_and_none_periods(): void
    {
        $resolver = app(InvoiceNumberingPeriodResolver::class);
        $monthly = $this->createSeries('Miesieczna', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'TEST %N/%M/%Y',
        ]);
        $yearly = $this->createSeries('Roczna lipiec', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 7,
            'number_format' => 'TEST %N/%M/%Y',
        ]);
        $none = $this->createSeries('Bez resetu', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::None,
        ]);

        $this->assertSame('2026-07', $resolver->resolve($monthly, CarbonImmutable::parse('2026-07-31')));
        $this->assertSame('2026-08', $resolver->resolve($monthly, CarbonImmutable::parse('2026-08-01')));
        $this->assertSame('2026', $resolver->resolve($yearly, CarbonImmutable::parse('2026-07-01')));
        $this->assertSame('2026', $resolver->resolve($yearly, CarbonImmutable::parse('2027-02-01')));
        $this->assertSame('2027', $resolver->resolve($yearly, CarbonImmutable::parse('2027-07-01')));
        $this->assertSame('none', $resolver->resolve($none, CarbonImmutable::parse('2030-01-01')));
    }

    public function test_numbering_configuration_validator_enforces_period_identity_tokens(): void
    {
        $validator = app(InvoiceNumberingConfigurationValidator::class);

        $validator->validate('FA %N/%M/%Y', InvoiceSeriesResetPeriod::Monthly, 1);
        $validator->validate('FA %N/%Y', InvoiceSeriesResetPeriod::Yearly, 1);
        $validator->validate('FA %N/%M/%Y', InvoiceSeriesResetPeriod::Yearly, 7);
        $validator->validate('FA %N', InvoiceSeriesResetPeriod::None, 1);
        $this->addToAssertionCount(4);

        $this->assertNumberingConfigurationRejected(
            'FA %N/%Y',
            InvoiceSeriesResetPeriod::Monthly,
            1,
            'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.',
        );
        $this->assertNumberingConfigurationRejected(
            'FA %N/%M',
            InvoiceSeriesResetPeriod::Monthly,
            1,
            'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.',
        );
        $this->assertNumberingConfigurationRejected(
            'FA %N',
            InvoiceSeriesResetPeriod::Yearly,
            1,
            'Przy rocznym resetowaniu numeracji format musi zawierać token roku %Y lub %y.',
        );
        $this->assertNumberingConfigurationRejected(
            'FA %N/%Y',
            InvoiceSeriesResetPeriod::Yearly,
            7,
            'Przy rocznym resetowaniu z początkiem roku innym niż styczeń format musi zawierać token miesiąca %M oraz token roku %Y lub %y.',
        );
        $this->assertNumberingConfigurationRejected(
            'FA %N/%M',
            InvoiceSeriesResetPeriod::Yearly,
            7,
            'Przy rocznym resetowaniu z początkiem roku innym niż styczeń format musi zawierać token miesiąca %M oraz token roku %Y lub %y.',
        );
    }

    public function test_management_service_rejects_unsafe_configuration_without_form_request(): void
    {
        $management = app(InvoiceSeriesManagementService::class);
        $message = 'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.';

        try {
            $management->create([
                'document_type' => InvoiceDocumentType::Invoice->value,
                'name' => 'Niebezpieczna nowa seria',
                'number_format' => 'FA %N/%Y',
                'reset_period' => InvoiceSeriesResetPeriod::Monthly->value,
                'fiscal_year_start_month' => 1,
                'default_currency' => 'PLN',
                'is_active' => true,
            ]);
            $this->fail('Serwis utworzył serię z niebezpiecznym formatem.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoice_series', ['name' => 'Niebezpieczna nowa seria']);

        $series = $this->createSeries('Bezpieczna seria');

        try {
            $management->update($series, [
                'number_format' => 'FA %N/%Y',
                'reset_period' => InvoiceSeriesResetPeriod::Monthly->value,
                'fiscal_year_start_month' => 1,
            ]);
            $this->fail('Serwis zaktualizował serię do niebezpiecznej konfiguracji.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame(InvoiceSeriesResetPeriod::Yearly, $series->refresh()->reset_period);
        $this->assertSame('TEST %N/%Y', $series->number_format);

        $unsafeHiddenSeries = $this->createSeries('Niebezpieczna ukryta seria', InvoiceDocumentType::Invoice, [
            'number_format' => 'FA %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'is_active' => false,
        ]);

        try {
            $management->setActive($unsafeHiddenSeries, true);
            $this->fail('Serwis aktywował serię z niebezpieczną konfiguracją.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertFalse($unsafeHiddenSeries->refresh()->is_active);
    }

    public function test_numbering_service_rejects_unsafe_configuration_before_preview_adjustment_and_assignment(): void
    {
        $series = $this->createSeries('Niebezpieczna numeracja', InvoiceDocumentType::Invoice, [
            'number_format' => 'FA %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
        ]);
        $invoice = $this->createDraft($series);
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');
        $message = 'Przy miesięcznym resetowaniu numeracji format musi zawierać token miesiąca %M oraz token roku %Y lub %y.';

        foreach ([
            fn () => $service->previewNextNumber($series, $date),
            fn () => $service->setNextNumber($series, $date, 25, 'Test niebezpiecznej konfiguracji'),
            fn () => $service->assignNextNumber($invoice, $date),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Niebezpieczna konfiguracja numeracji została użyta.');
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertNull($invoice->refresh()->number);
        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_unknown_reset_period_raises_controlled_domain_error(): void
    {
        $series = $this->createSeries('Uszkodzona konfiguracja');
        DB::table('invoice_series')->where('id', $series->id)->update(['reset_period' => 'unknown']);
        $series->refresh();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nieznany sposób resetowania numeracji.');

        app(InvoiceNumberingPeriodResolver::class)->resolve($series, CarbonImmutable::parse('2026-07-01'));
    }

    public function test_formatter_preserves_supported_tokens_padding_dates_text_and_overflow(): void
    {
        $formatter = app(InvoiceNumberFormatter::class);
        $date = CarbonImmutable::parse('2026-07-15');

        $this->assertSame('FV 7/07/2026/26', $formatter->format('FV %N/%M/%Y/%y', 7, $date));
        $this->assertSame('FV 07/007/0007', $formatter->format('FV %NN/%NNN/%NNNN', 7, $date));
        $this->assertSame('FV 1234', $formatter->format('FV %NNN', 1234, $date));
        $this->assertSame('07M/1', $formatter->format('%MM/%N', 1, $date));
        $this->assertDoesNotMatchRegularExpression('/%N+|%M|%Y|%y/', $formatter->format('%N-%M-%Y-%y', 1, $date));
    }

    public function test_preview_is_read_only_works_for_hidden_series_and_accepts_candidate(): void
    {
        $series = $this->createSeries('Ukryty podglad', InvoiceDocumentType::Invoice, ['is_active' => false]);
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');

        $initial = $service->previewNextNumber($series, $date);
        $candidate = $service->previewNextNumber($series, $date, 4251);

        $this->assertSame(1, $initial->currentNextSequenceNumber);
        $this->assertSame(4251, $candidate->previewSequenceNumber);
        $this->assertSame('TEST 4251/2026', $candidate->formattedNumber);
        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_assigning_numbers_updates_only_numbering_fields_and_counter(): void
    {
        $series = $this->createSeries('Nadawanie');
        $first = $this->createDraft($series);
        $second = $this->createDraft($series);
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');

        $first = $service->assignNextNumber($first, $date);
        $second = $service->assignNextNumber($second, $date);

        $this->assertSame('TEST 1/2026', $first->number);
        $this->assertSame(1, $first->sequence_number);
        $this->assertSame('2026', $first->numbering_period_key);
        $this->assertSame('TEST %N/%Y', $first->number_format_snapshot);
        $this->assertSame('Nadawanie', $first->series_name_snapshot);
        $this->assertSame('2026-07-15', $first->issue_date->toDateString());
        $this->assertSame(2, $second->sequence_number);
        $this->assertSame(InvoiceDocumentStatus::Draft, $second->status);
        $this->assertNull($second->issued_at);
        $this->assertSame(0, $second->items()->count());
        $this->assertNull($second->seller_snapshot);
        $this->assertDatabaseHas('invoice_number_counters', [
            'invoice_series_id' => $series->id,
            'numbering_period_key' => '2026',
            'last_sequence_number' => 2,
            'protected_floor_sequence_number' => 0,
        ]);
    }

    public function test_assigning_number_is_not_repeatable_and_does_not_advance_counter_on_error(): void
    {
        $series = $this->createSeries('Idempotencja');
        $invoice = $this->createDraft($series);
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');
        $service->assignNextNumber($invoice, $date);

        try {
            $service->assignNextNumber($invoice, $date);
            $this->fail('Dokument otrzymal drugi numer.');
        } catch (DomainException $exception) {
            $this->assertSame('Dokument posiada juz nadany numer.', $this->withoutPolishCharacters($exception->getMessage()));
        }

        $this->assertSame(1, $series->numberCounters()->firstOrFail()->last_sequence_number);
    }

    public function test_assignment_rejects_inactive_mismatched_non_draft_and_date_mismatch_documents(): void
    {
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');

        $inactive = $this->createSeries('Nieaktywna', InvoiceDocumentType::Invoice, ['is_active' => false]);
        $mismatched = $this->createSeries('Inny typ', InvoiceDocumentType::Invoice);
        $issuedSeries = $this->createSeries('Wystawiony');
        $datedSeries = $this->createSeries('Inna data');

        foreach ([
            [$this->createDraft($inactive), 'Nieaktywna seria'],
            [$this->createDraft($mismatched, ['document_type' => InvoiceDocumentType::Proforma]), 'Typ dokumentu'],
            [$this->createDraft($issuedSeries, ['status' => InvoiceDocumentStatus::Issued]), 'statusie szkicu'],
            [$this->createDraft($datedSeries, ['issue_date' => '2026-07-14']), 'Data wystawienia'],
        ] as [$invoice, $message]) {
            try {
                $service->assignNextNumber($invoice, $date);
                $this->fail('Nieprawidlowy dokument otrzymal numer.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('invoice_number_counters', 0);
    }

    public function test_series_periods_and_none_reset_have_independent_counters(): void
    {
        $service = app(InvoiceNumberingService::class);
        $first = $this->createSeries('Seria A', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'A %N/%M/%Y',
        ]);
        $second = $this->createSeries('Seria B', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::Monthly,
            'number_format' => 'B %N/%M/%Y',
        ]);
        $none = $this->createSeries('Seria bez resetu', InvoiceDocumentType::Invoice, [
            'reset_period' => InvoiceSeriesResetPeriod::None,
        ]);

        $this->assertSame(1, $service->assignNextNumber($this->createDraft($first), CarbonImmutable::parse('2026-07-01'))->sequence_number);
        $this->assertSame(1, $service->assignNextNumber($this->createDraft($first), CarbonImmutable::parse('2026-08-01'))->sequence_number);
        $this->assertSame(1, $service->assignNextNumber($this->createDraft($second), CarbonImmutable::parse('2026-07-01'))->sequence_number);
        $this->assertSame(1, $service->assignNextNumber($this->createDraft($none), CarbonImmutable::parse('2026-01-01'))->sequence_number);
        $this->assertSame(2, $service->assignNextNumber($this->createDraft($none), CarbonImmutable::parse('2027-01-01'))->sequence_number);
        $this->assertSame(4, InvoiceNumberCounter::query()->count());
    }

    public function test_manual_next_number_sets_floor_history_and_drives_next_assignment(): void
    {
        $series = $this->createSeries('Reczna zmiana');
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');
        $adjustment = $service->setNextNumber(
            $series,
            $date,
            4251,
            'Kontynuacja dotychczasowej numeracji',
            ['type' => 'user', 'id' => 10],
        );

        $counter = $series->numberCounters()->firstOrFail();
        $this->assertSame(4250, $counter->last_sequence_number);
        $this->assertSame(4250, $counter->protected_floor_sequence_number);
        $this->assertSame(1, $counter->adjustments()->count());
        $this->assertSame(1, $adjustment->previous_next_sequence_number);
        $this->assertSame(4251, $adjustment->new_next_sequence_number);
        $this->assertSame('Reczna zmiana', $adjustment->series_name_snapshot);
        $this->assertSame(['type' => 'user', 'id' => 10], $adjustment->actor_snapshot);
        $this->assertSame(4251, $service->assignNextNumber($this->createDraft($series), $date)->sequence_number);
    }

    public function test_empty_period_manual_setting_can_be_lowered_and_updates_protected_floor(): void
    {
        $series = $this->createSeries('Korekta pustego okresu');
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');

        $service->setNextNumber($series, $date, 4251, 'Pierwsze ustawienie');
        $service->setNextNumber($series, $date, 4201, 'Korekta przed wystawieniem');

        $counter = $series->numberCounters()->firstOrFail();
        $this->assertSame(4200, $counter->last_sequence_number);
        $this->assertSame(4200, $counter->protected_floor_sequence_number);
        $this->assertSame(2, $counter->adjustments()->count());
    }

    public function test_period_with_documents_can_only_move_forward_and_noop_creates_no_history(): void
    {
        $series = $this->createSeries('Okres z dokumentami');
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');
        $service->assignNextNumber($this->createDraft($series), $date);
        $service->setNextNumber($series, $date, 20, 'Przesuniecie do przodu');

        $this->assertSame(20, $service->assignNextNumber($this->createDraft($series), $date)->sequence_number);

        foreach ([21, 13, 11] as $requested) {
            $historyBefore = InvoiceNumberCounterAdjustment::query()->count();
            try {
                $service->setNextNumber($series, $date, $requested, 'Niedozwolona zmiana');
                $this->fail('Niedozwolona zmiana licznika zostala zapisana.');
            } catch (DomainException) {
                $this->assertSame($historyBefore, InvoiceNumberCounterAdjustment::query()->count());
            }
        }
    }

    public function test_counter_inconsistency_is_detected_without_writes(): void
    {
        $series = $this->createSeries('Niespojnosc');
        $this->createDraft($series, [
            'number' => 'TEST 5/2026',
            'sequence_number' => 5,
            'numbering_period_key' => '2026',
        ]);

        try {
            app(InvoiceNumberingService::class)->setNextNumber(
                $series,
                CarbonImmutable::parse('2026-07-15'),
                10,
                'Proba zmiany',
            );
            $this->fail('Niespojnosc nie zostala wykryta.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('niespójność', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoice_number_counters', 0);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_manual_setting_supports_each_document_type_system_custom_and_hidden_series(): void
    {
        $service = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');

        foreach (InvoiceDocumentType::cases() as $index => $type) {
            $series = $this->createSeries('Typ '.$type->value, $type, ['is_active' => $index !== 1]);
            $service->setNextNumber($series, $date, 100 + $index, 'Ustawienie dla typu');
        }

        $system = InvoiceSeries::query()->where('is_system', true)->firstOrFail();
        $service->setNextNumber($system, $date, 500, 'Ustawienie serii systemowej');

        $this->assertDatabaseCount('invoice_number_counter_adjustments', 4);
    }

    public function test_numbering_identity_is_locked_after_assignment_or_manual_adjustment_but_name_remains_editable(): void
    {
        $management = app(InvoiceSeriesManagementService::class);
        $numbering = app(InvoiceNumberingService::class);
        $date = CarbonImmutable::parse('2026-07-15');
        $assigned = $this->createSeries('Z numerem');
        $adjusted = $this->createSeries('Z progiem');
        $draftOnly = $this->createSeries('Tylko szkic');
        $this->createDraft($draftOnly);
        $numbering->assignNextNumber($this->createDraft($assigned), $date);
        $numbering->setNextNumber($adjusted, $date, 10, 'Poczatek numeracji');

        foreach ([$assigned, $adjusted] as $series) {
            foreach ([
                ['number_format' => 'NOWY %N/%Y'],
                ['reset_period' => InvoiceSeriesResetPeriod::Monthly->value],
                ['fiscal_year_start_month' => 7],
                ['document_type' => InvoiceDocumentType::Proforma->value],
            ] as $change) {
                try {
                    $management->update($series, $change);
                    $this->fail('Tozsamosc numeracji zostala zmieniona.');
                } catch (DomainException $exception) {
                    $this->assertStringContainsString('Nie można zmienić parametrów numeracji', $exception->getMessage());
                }
            }

            $management->update($series, ['name' => $series->name.' zmieniona']);
            $this->assertStringEndsWith('zmieniona', $series->refresh()->name);
        }

        $management->update($draftOnly, ['number_format' => 'DRAFT %N/%Y']);
        $this->assertSame('DRAFT %N/%Y', $draftOnly->refresh()->number_format);
    }

    private function createSeries(
        string $name,
        InvoiceDocumentType $type = InvoiceDocumentType::Invoice,
        array $attributes = [],
    ): InvoiceSeries {
        return InvoiceSeries::query()->create(array_replace([
            'document_type' => $type,
            'name' => $name,
            'number_format' => 'TEST %N/%Y',
            'reset_period' => InvoiceSeriesResetPeriod::Yearly,
            'fiscal_year_start_month' => 1,
            'default_currency' => 'PLN',
            'is_active' => true,
        ], $attributes))->refresh();
    }

    private function createDraft(InvoiceSeries $series, array $attributes = []): Invoice
    {
        return Invoice::query()->create(array_replace([
            'invoice_series_id' => $series->id,
            'document_type' => $series->document_type,
            'status' => InvoiceDocumentStatus::Draft,
        ], $attributes))->refresh();
    }

    private function adjustmentPayload(): array
    {
        return [
            'numbering_period_key_snapshot' => '2026',
            'previous_last_sequence_number' => 0,
            'new_last_sequence_number' => 1,
            'previous_protected_floor_sequence_number' => 0,
            'new_protected_floor_sequence_number' => 1,
            'previous_next_sequence_number' => 1,
            'new_next_sequence_number' => 2,
            'reason' => 'Test relacji',
        ];
    }

    private function assertNumberingConfigurationRejected(
        string $numberFormat,
        InvoiceSeriesResetPeriod $resetPeriod,
        int $fiscalYearStartMonth,
        string $expectedMessage,
    ): void {
        try {
            app(InvoiceNumberingConfigurationValidator::class)->validate(
                $numberFormat,
                $resetPeriod,
                $fiscalYearStartMonth,
            );
            $this->fail('Niebezpieczna konfiguracja numeracji została zaakceptowana.');
        } catch (DomainException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    private function withoutPolishCharacters(string $value): string
    {
        return strtr($value, ['ż' => 'z', 'Ż' => 'Z', 'ó' => 'o', 'Ó' => 'O', 'ł' => 'l', 'Ł' => 'L', 'ć' => 'c', 'Ć' => 'C', 'ą' => 'a', 'Ą' => 'A', 'ę' => 'e', 'Ę' => 'E', 'ś' => 's', 'Ś' => 'S', 'ź' => 'z', 'Ź' => 'Z', 'ń' => 'n', 'Ń' => 'N']);
    }
}
