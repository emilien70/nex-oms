<?php

namespace Tests\Feature\Invoices;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesResetPeriod;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\InvoiceSeries;
use Tests\TestCase;

class InvoiceSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_series_table_contains_system_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('invoice_series'));
        $this->assertTrue(Schema::hasColumns('invoice_series', [
            'id',
            'document_type',
            'name',
            'number_format',
            'reset_period',
            'fiscal_year_start_month',
            'is_active',
            'is_system',
            'system_key',
            'default_correction_series_id',
            'default_currency',
            'seller_name',
            'seller_tax_id',
            'seller_regon',
            'seller_bdo',
            'seller_street',
            'seller_building_number',
            'seller_apartment_number',
            'seller_postal_code',
            'seller_city',
            'seller_province',
            'seller_country_code',
            'seller_email',
            'seller_phone',
            'seller_bank_name',
            'seller_bank_account',
            'seller_bank_swift',
            'place_of_issue',
            'issuer_name',
            'logo_path',
            'additional_information_template',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('invoice_series', 'is_default'));

        $indexes = collect(Schema::getIndexes('invoice_series'));

        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['document_type', 'is_active']
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['document_type', 'is_system']
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['columns'] === ['system_key'] && $index['unique']
        ));
    }

    public function test_migration_creates_exactly_three_expected_system_series(): void
    {
        $expected = [
            InvoiceSeriesSystemKey::Invoice->value => [
                'type' => InvoiceDocumentType::Invoice,
                'name' => 'Faktury',
                'format' => 'BL %N/%Y',
            ],
            InvoiceSeriesSystemKey::Correction->value => [
                'type' => InvoiceDocumentType::Correction,
                'name' => 'Korekty',
                'format' => 'BLK %N/%Y',
            ],
            InvoiceSeriesSystemKey::Proforma->value => [
                'type' => InvoiceDocumentType::Proforma,
                'name' => 'Faktury Pro-Forma',
                'format' => 'BLPF %N/%Y',
            ],
        ];

        $this->assertSame(3, InvoiceSeries::query()->where('is_system', true)->count());

        foreach ($expected as $key => $definition) {
            $series = InvoiceSeries::query()->where('system_key', $key)->firstOrFail();

            $this->assertTrue($series->is_system);
            $this->assertTrue($series->is_active);
            $this->assertSame($definition['type'], $series->document_type);
            $this->assertSame($definition['name'], $series->name);
            $this->assertSame($definition['format'], $series->number_format);
            $this->assertSame(InvoiceSeriesResetPeriod::Yearly, $series->reset_period);
            $this->assertSame(1, $series->fiscal_year_start_month);
            $this->assertSame('PLN', $series->default_currency);
        }

        $invoice = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $correction = $this->systemSeries(InvoiceSeriesSystemKey::Correction);
        $proforma = $this->systemSeries(InvoiceSeriesSystemKey::Proforma);

        $this->assertTrue($invoice->defaultCorrectionSeries->is($correction));
        $this->assertNull($proforma->default_correction_series_id);
        $this->assertNull($proforma->defaultCorrectionSeries);
    }

    public function test_custom_series_uses_defaults_casts_and_ignores_system_fields_in_mass_assignment(): void
    {
        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Faktury dodatkowe',
            'number_format' => 'FV/%N/%Y',
            'is_system' => true,
            'system_key' => InvoiceSeriesSystemKey::Invoice,
        ])->refresh();

        $this->assertSame(InvoiceDocumentType::Invoice, $series->document_type);
        $this->assertSame(InvoiceSeriesResetPeriod::Yearly, $series->reset_period);
        $this->assertSame(1, $series->fiscal_year_start_month);
        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
        $this->assertFalse($series->is_active);
        $this->assertSame('PLN', $series->default_currency);
        $this->assertSame('PL', $series->seller_country_code);
    }

    public function test_enums_have_exact_values(): void
    {
        $this->assertSame(
            ['invoice', 'proforma', 'correction'],
            array_column(InvoiceDocumentType::cases(), 'value')
        );
        $this->assertSame(
            ['monthly', 'yearly', 'none'],
            array_column(InvoiceSeriesResetPeriod::cases(), 'value')
        );
        $this->assertSame(
            ['invoice', 'correction', 'proforma'],
            array_column(InvoiceSeriesSystemKey::cases(), 'value')
        );
    }

    public function test_name_must_be_unique_within_document_type(): void
    {
        InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria duplikowana',
            'number_format' => 'FV/%N/%Y',
        ]);

        $this->expectException(QueryException::class);

        InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria duplikowana',
            'number_format' => 'FV2/%N/%Y',
        ]);
    }

    public function test_same_name_is_allowed_for_different_document_types(): void
    {
        InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria wspólna',
            'number_format' => 'FV/%N/%Y',
        ]);

        InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Proforma,
            'name' => 'Seria wspólna',
            'number_format' => 'PRO/%N/%Y',
        ]);

        $this->assertSame(2, InvoiceSeries::query()->where('name', 'Seria wspólna')->count());
    }

    public function test_system_key_must_be_unique(): void
    {
        $this->expectException(QueryException::class);

        DB::table('invoice_series')->insert([
            'document_type' => 'invoice',
            'name' => 'Druga seria systemowa faktur',
            'number_format' => 'SYS/%N/%Y',
            'is_system' => true,
            'system_key' => 'invoice',
            'is_active' => true,
        ]);
    }

    public function test_system_series_cannot_be_deleted(): void
    {
        $this->expectException(DomainException::class);

        $this->systemSeries(InvoiceSeriesSystemKey::Invoice)->delete();
    }

    public function test_system_series_cannot_be_deactivated(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $series->is_active = false;
        $series->save();

        $this->assertTrue($series->refresh()->is_active);
    }

    public function test_system_series_cannot_be_changed_to_custom_series(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $series->is_system = false;

        $this->expectException(DomainException::class);

        $series->save();
    }

    public function test_system_series_key_cannot_be_changed(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $series->system_key = InvoiceSeriesSystemKey::Correction;

        $this->expectException(DomainException::class);

        $series->save();
    }

    public function test_system_series_document_type_cannot_be_changed(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $series->document_type = InvoiceDocumentType::Proforma;

        $this->expectException(DomainException::class);

        $series->save();
    }

    public function test_system_series_name_and_number_format_can_be_changed(): void
    {
        $series = $this->systemSeries(InvoiceSeriesSystemKey::Invoice);
        $series->name = 'Faktury główne';
        $series->number_format = 'FV %N/%Y';
        $series->save();

        $series->refresh();

        $this->assertSame('Faktury główne', $series->name);
        $this->assertSame('FV %N/%Y', $series->number_format);
        $this->assertTrue($series->is_system);
        $this->assertSame(InvoiceSeriesSystemKey::Invoice, $series->system_key);
    }

    public function test_custom_series_can_be_inactive_and_deleted(): void
    {
        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria robocza',
            'number_format' => 'ROB/%N/%Y',
            'is_active' => false,
        ])->refresh();

        $this->assertFalse($series->is_system);
        $this->assertNull($series->system_key);
        $this->assertFalse($series->is_active);

        $series->delete();

        $this->assertDatabaseMissing('invoice_series', ['id' => $series->id]);
    }

    public function test_default_correction_series_relations_return_expected_models(): void
    {
        $correctionSeries = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Correction,
            'name' => 'Korekty dodatkowe',
            'number_format' => 'KOR/%N/%Y',
        ]);
        $invoiceSeries = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Faktury dodatkowe',
            'number_format' => 'FV/%N/%Y',
            'default_correction_series_id' => $correctionSeries->id,
        ]);

        $this->assertTrue($invoiceSeries->defaultCorrectionSeries->is($correctionSeries));
        $this->assertTrue($correctionSeries->seriesUsingAsDefaultCorrection->contains($invoiceSeries));
    }

    public function test_deleting_custom_correction_series_sets_reference_to_null(): void
    {
        $correctionSeries = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Correction,
            'name' => 'Korekty robocze',
            'number_format' => 'KOR/%N/%Y',
        ]);
        $invoiceSeries = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Faktury robocze',
            'number_format' => 'FV/%N/%Y',
            'default_correction_series_id' => $correctionSeries->id,
        ]);

        $correctionSeries->delete();

        $this->assertNull($invoiceSeries->refresh()->default_correction_series_id);
        $this->assertNull($invoiceSeries->defaultCorrectionSeries);
    }

    public function test_seller_fields_are_nullable_and_can_store_complete_seller_data(): void
    {
        $emptySeller = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria bez sprzedawcy',
            'number_format' => 'FV-R/%N/%Y',
        ]);

        $this->assertNull($emptySeller->seller_name);
        $this->assertNull($emptySeller->seller_tax_id);

        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Proforma,
            'name' => 'Pro formy dodatkowe',
            'number_format' => 'PRO/%N/%Y',
            'seller_name' => 'NEX Sp. z o.o.',
            'seller_tax_id' => '1234567890',
            'seller_regon' => '123456789',
            'seller_bdo' => '000123456',
            'seller_street' => 'Testowa',
            'seller_building_number' => '10',
            'seller_apartment_number' => '2',
            'seller_postal_code' => '00-001',
            'seller_city' => 'Warszawa',
            'seller_province' => 'mazowieckie',
            'seller_country_code' => 'PL',
            'seller_email' => 'faktury@example.com',
            'seller_phone' => '+48 501 234 567',
            'seller_bank_name' => 'Bank Testowy',
            'seller_bank_account' => '00112233445566778899001122',
            'seller_bank_swift' => 'TESTPLPW',
            'place_of_issue' => 'Warszawa',
            'issuer_name' => 'Jan Kowalski',
            'logo_path' => 'invoice-series/logos/nex-oms.png',
        ]);

        $this->assertDatabaseHas('invoice_series', [
            'id' => $series->id,
            'seller_name' => 'NEX Sp. z o.o.',
            'seller_tax_id' => '1234567890',
            'seller_bank_account' => '00112233445566778899001122',
            'place_of_issue' => 'Warszawa',
            'issuer_name' => 'Jan Kowalski',
        ]);
    }

    public function test_additional_information_template_is_stored_without_resolving_variables(): void
    {
        $template = "Uwagi sprzedawcy:\n[uwagi_sprzedawcy]";

        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Faktury z uwagami',
            'number_format' => 'FV-U/%N/%Y',
            'additional_information_template' => $template,
        ])->refresh();

        $this->assertSame($template, $series->additional_information_template);
    }

    private function systemSeries(InvoiceSeriesSystemKey $key): InvoiceSeries
    {
        return InvoiceSeries::query()->where('system_key', $key->value)->firstOrFail();
    }
}
