<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionPaymentMethodSource;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class CorrectionSeriesSourceResolutionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_source_invoice_modes_ignore_tampered_values_in_direct_service_call(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries();

        $correction = $this->issueCorrection($source, $series, [
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Podmieniony wystawiajacy',
            'payment_method' => 'Podmieniona platnosc',
        ]);

        $this->assertSame($source->sale_date?->toDateString(), $correction->sale_date?->toDateString());
        $this->assertSame($source->issuer_snapshot, $correction->issuer_snapshot);
        $this->assertSame($source->payment_snapshot, $correction->payment_snapshot);
    }

    public function test_issue_date_series_and_fixed_modes_resolve_values_from_series(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries([
            'correction_sale_date_source' => CorrectionSaleDateSource::IssueDate,
            'correction_issuer_source' => CorrectionIssuerSource::Series,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::Fixed,
            'issuer_name' => 'Wystawca serii',
            'place_of_issue' => 'Poznan',
            'fixed_payment_method' => 'Gotowka',
        ]);

        $correction = $this->issueCorrection($source, $series, [
            'issue_date' => '2026-08-06',
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Podmieniony wystawiajacy',
            'payment_method' => 'Podmieniona platnosc',
        ]);

        $this->assertSame('2026-08-06', $correction->sale_date?->toDateString());
        $this->assertSame('Wystawca serii', data_get($correction->issuer_snapshot, 'issuer_name'));
        $this->assertSame('Poznan', data_get($correction->issuer_snapshot, 'place_of_issue'));
        $this->assertSame('Gotowka', data_get($correction->payment_snapshot, 'effective_payment_method'));
    }

    public function test_none_payment_mode_clears_effective_payment_method(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries([
            'correction_payment_method_source' => CorrectionPaymentMethodSource::None,
        ]);

        $correction = $this->issueCorrection($source, $series, [
            'payment_method' => 'Podmieniona platnosc',
        ]);

        $this->assertNull(data_get($correction->payment_snapshot, 'effective_payment_method'));
    }

    public function test_http_store_cannot_override_values_resolved_from_series(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries([
            'correction_sale_date_source' => CorrectionSaleDateSource::IssueDate,
            'correction_issuer_source' => CorrectionIssuerSource::Series,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::Fixed,
            'issuer_name' => 'Wystawca HTTP',
            'fixed_payment_method' => 'Karta',
        ]);

        $this->post(route('invoices.corrections.store', $source), $this->payload($source, $series, [
            'issue_date' => '2026-08-06',
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Atakujacy',
            'payment_method' => 'Atakujaca platnosc',
        ]))->assertRedirect();

        $correction = Invoice::query()
            ->where('order_id', $source->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->firstOrFail();

        $this->assertSame('2026-08-06', $correction->sale_date?->toDateString());
        $this->assertSame('Wystawca HTTP', data_get($correction->issuer_snapshot, 'issuer_name'));
        $this->assertSame('Karta', data_get($correction->payment_snapshot, 'effective_payment_method'));
    }

    public function test_update_uses_saved_series_modes_and_preserves_resolved_fixed_values(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries([
            'correction_sale_date_source' => CorrectionSaleDateSource::IssueDate,
            'correction_issuer_source' => CorrectionIssuerSource::Series,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::Fixed,
            'issuer_name' => 'Pierwotny wystawca serii',
            'place_of_issue' => 'Poznan',
            'fixed_payment_method' => 'Gotowka',
        ]);
        $correction = $this->issueCorrection($source, $series);

        $series->update([
            'correction_sale_date_source' => CorrectionSaleDateSource::SourceInvoice,
            'correction_issuer_source' => CorrectionIssuerSource::SourceInvoice,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::None,
            'issuer_name' => 'Nowy wystawca serii',
            'place_of_issue' => 'Warszawa',
            'fixed_payment_method' => null,
        ]);

        $updated = app(CorrectionService::class)->update($correction, $this->updatePayload($correction, [
            'issue_date' => '2026-08-06',
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Podmieniony wystawiajacy',
            'payment_method' => 'Podmieniona platnosc',
        ]));

        $this->assertSame('2026-08-06', $updated->sale_date?->toDateString());
        $this->assertSame('Pierwotny wystawca serii', data_get($updated->issuer_snapshot, 'issuer_name'));
        $this->assertSame('Poznan', data_get($updated->issuer_snapshot, 'place_of_issue'));
        $this->assertSame('Gotowka', data_get($updated->payment_snapshot, 'effective_payment_method'));

        $this->get(route('invoices.corrections.edit', $updated))
            ->assertOk()
            ->assertSee('name="sale_date" value="2026-08-06" required readonly', false)
            ->assertSee('name="payment_method" value="Gotowka" maxlength="255" readonly', false)
            ->assertSee('name="issuer_name" value="Pierwotny wystawca serii" maxlength="255" readonly', false)
            ->assertSee('data-source-mode="issue_date"', false)
            ->assertSee('data-source-mode="series"', false)
            ->assertSee('data-source-mode="fixed"', false)
            ->assertDontSee('Nowy wystawca serii');
    }

    public function test_update_source_invoice_modes_rebuild_values_from_source_snapshot(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $correction = $this->issueCorrection($source, $series);

        $source->update([
            'issuer_snapshot' => ['issuer_name' => 'Wystawca z faktury', 'place_of_issue' => 'Gdansk'],
            'payment_snapshot' => ['effective_payment_method' => 'Przelew z faktury'],
        ]);

        $updated = app(CorrectionService::class)->update($correction, $this->updatePayload($correction, [
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Podmieniony wystawiajacy',
            'payment_method' => 'Podmieniona platnosc',
        ]));

        $this->assertSame($source->sale_date?->toDateString(), $updated->sale_date?->toDateString());
        $this->assertSame('Wystawca z faktury', data_get($updated->issuer_snapshot, 'issuer_name'));
        $this->assertSame('Gdansk', data_get($updated->issuer_snapshot, 'place_of_issue'));
        $this->assertSame('Przelew z faktury', data_get($updated->payment_snapshot, 'effective_payment_method'));
    }

    public function test_tampered_post_sources_do_not_turn_an_identical_update_into_a_change(): void
    {
        Storage::fake('local');
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $correction = $this->issueCorrection($source, $series)->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');
        $lockVersion = $correction->lock_version;
        $itemIds = $correction->items->modelKeys();

        $updated = app(CorrectionService::class)->update($correction, $this->updatePayload($correction, [
            'buyer' => $correction->buyer_snapshot,
            'sale_date' => '2099-01-01',
            'issuer_name' => 'HACKED',
            'payment_method' => 'HACKED',
        ]));

        $this->assertSame($lockVersion, $updated->lock_version);
        $this->assertSame($itemIds, $updated->items->modelKeys());
        $this->assertSame($source->sale_date?->toDateString(), $updated->sale_date?->toDateString());
        $this->assertSame($source->issuer_snapshot, $updated->issuer_snapshot);
        $this->assertSame($source->payment_snapshot, $updated->payment_snapshot);
        Storage::disk('local')->assertExists($cachePath);
    }

    public function test_incomplete_saved_source_modes_reject_update_and_editor_with_controlled_error(): void
    {
        $source = $this->issuedInvoice();
        $correction = $this->issueCorrection($source, $this->correctionSeries());
        $settings = $correction->series_settings_snapshot;
        unset($settings['correction_payment_method_source']);
        $correction->update(['series_settings_snapshot' => $settings]);

        try {
            app(CorrectionService::class)->update($correction, $this->updatePayload($correction));
            $this->fail('Oczekiwano kontrolowanego bledu niekompletnego snapshotu ustawien.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_edit_series_settings_incomplete', $exception->errorCode());
            $this->assertSame(
                'Nie można edytować Korekty, ponieważ zapisane ustawienia źródeł danych dokumentu są niekompletne.',
                $exception->getMessage(),
            );
        }

        $this->get(route('invoices.corrections.edit', $correction))
            ->assertStatus(422)
            ->assertSeeText('Nie można edytować Korekty');
    }

    public function test_create_form_renders_canonical_readonly_values_without_old_input_override(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries([
            'correction_issuer_source' => CorrectionIssuerSource::Series,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::Fixed,
            'issuer_name' => 'Wystawca formularza',
            'fixed_payment_method' => 'Gotowka',
        ]);

        $response = $this->withSession(['_old_input' => [
            'sale_date' => '2099-01-01',
            'issuer_name' => 'Stary wystawiajacy',
            'payment_method' => 'Stara platnosc',
        ]])->get(route('invoices.corrections.create', [
            'invoice' => $source,
            'series_id' => $series->getKey(),
        ]));

        $response->assertOk()
            ->assertSee('name="sale_date" value="2026-07-20" required readonly', false)
            ->assertSee('name="payment_method" value="Gotowka" maxlength="255" readonly', false)
            ->assertSee('name="issuer_name" value="Wystawca formularza" maxlength="255" readonly', false)
            ->assertDontSee('2099-01-01')
            ->assertDontSee('Stary wystawiajacy')
            ->assertDontSee('Stara platnosc')
            ->assertSeeText(CorrectionSaleDateSource::SourceInvoice->description())
            ->assertSeeText(CorrectionIssuerSource::Series->description())
            ->assertSeeText(CorrectionPaymentMethodSource::Fixed->description());
    }

    private function issuedInvoice(): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
    }

    private function correctionSeries(array $attributes = []): InvoiceSeries
    {
        return $this->createDocumentSeries(InvoiceDocumentType::Correction, array_merge([
            'correction_sale_date_source' => CorrectionSaleDateSource::SourceInvoice,
            'correction_issuer_source' => CorrectionIssuerSource::SourceInvoice,
            'correction_payment_method_source' => CorrectionPaymentMethodSource::SourceInvoice,
        ], $attributes));
    }

    private function issueCorrection(Invoice $source, InvoiceSeries $series, array $overrides = []): Invoice
    {
        return app(CorrectionService::class)->issue(
            $source,
            $series,
            $source->getKey(),
            $source->lock_version,
            $this->payload($source, $series, $overrides),
            $this->documentContext('2026-08-05 10:00:00'),
        );
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $source, InvoiceSeries $series, array $overrides = []): array
    {
        return array_replace_recursive([
            'expected_source_document_id' => $source->getKey(),
            'expected_source_lock_version' => $source->lock_version,
            'correction_series_id' => $series->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester korekty',
            'additional_information' => null,
            'change_items' => false,
            'change_buyer' => true,
            'items' => [],
            'buyer' => array_merge($source->buyer_snapshot, [
                'name' => 'Pierwszy nabywca korekty',
                'company_name' => null,
            ]),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function updatePayload(Invoice $correction, array $overrides = []): array
    {
        $source = $correction->correctedInvoice()->firstOrFail();

        return array_replace_recursive($this->payload($source, $correction->series, [
            'expected_lock_version' => $correction->lock_version,
            'buyer' => array_merge($source->buyer_snapshot, [
                'name' => 'Drugi nabywca korekty',
                'company_name' => null,
            ]),
        ]), $overrides);
    }
}
