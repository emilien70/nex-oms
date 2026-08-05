<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceCorrectionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_correction_form_uses_effective_invoice_state_and_polish_reasons(): void
    {
        $invoice = $this->issuedInvoice();

        $this->get(route('invoices.corrections.create', $invoice))
            ->assertOk()
            ->assertSeeText('Tworzenie korekty')
            ->assertSeeText('Faktura korygująca')
            ->assertSeeText('Korekcie uległy pozycje faktury')
            ->assertSeeText('Korekcie uległy inne dane na fakturze')
            ->assertSeeText(CorrectionReason::GoodsReturn->label())
            ->assertSeeText('Produkt testowy')
            ->assertSeeText('Jan Kowalski');
    }

    public function test_free_text_default_reason_is_presented_as_other_reason(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction, [
            'default_correction_reason' => 'Uzgodniony zwrot handlowy',
        ]);

        $this->get(route('invoices.corrections.create', [
            'invoice' => $invoice,
            'series_id' => $series->getKey(),
        ]))
            ->assertOk()
            ->assertSee('value="other" selected', false)
            ->assertSee('value="Uzgodniony zwrot handlowy"', false);
    }

    public function test_invoice_edit_uses_direct_link_for_one_series_and_modal_for_many_series(): void
    {
        $invoice = $this->issuedInvoice();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.corrections.create', [
                'invoice' => $invoice,
                'series_id' => $this->systemCorrectionSeries()->getKey(),
            ]), false)
            ->assertDontSee('id="invoiceEditCorrectionSeriesModal"', false);

        $additional = $this->createDocumentSeries(InvoiceDocumentType::Correction);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('id="invoiceEditCorrectionSeriesModal"', false)
            ->assertSeeText($additional->name);
    }

    public function test_item_correction_is_issued_numbered_snapshotted_and_rendered_as_pdf(): void
    {
        Storage::fake('local');

        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $items = $this->submittedItems($invoice);
        $items[0]['quantity'] = 0;

        $response = $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $items,
        ]));

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $response->assertRedirect(route('invoices.pdf', $correction));
        $this->assertSame(InvoiceDocumentStatus::Issued, $correction->status);
        $this->assertSame($invoice->getKey(), $correction->corrected_invoice_id);
        $this->assertNull($correction->previous_correction_id);
        $this->assertNotNull($correction->number);
        $this->assertSame('-100.00', $correction->total_gross);
        $this->assertSame('123.00', $correction->correction_totals_snapshot['before']['gross']);
        $this->assertSame('23.00', $correction->correction_totals_snapshot['after']['gross']);
        $this->assertSame('-100.00', $correction->correction_totals_snapshot['difference']['gross']);
        $this->assertSame('123.00', $invoice->fresh()->total_gross);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $invoice->order_id,
            'event_type' => 'correction_issued',
        ]);

        $this->get(route('invoices.pdf', $correction))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_buyer_only_correction_keeps_amounts_and_snapshots_changed_buyer(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $payload = $this->payload($series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, [
                'name' => 'Anna Zmieniona',
                'company_name' => null,
                'country_code' => 'DE',
            ]),
        ]);

        $this->post(route('invoices.corrections.store', $invoice), $payload)->assertRedirect();

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame('Anna Zmieniona', $correction->buyer_snapshot['name']);
        $this->assertSame('DE', $correction->buyer_snapshot['country_code']);
        $this->assertSame('Niemcy', $correction->buyer_snapshot['country_name']);
        $this->assertSame('Jan Kowalski', $invoice->fresh()->buyer_snapshot['name']);
    }

    public function test_next_correction_uses_state_after_previous_correction(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $firstItems = $this->submittedItems($invoice);
        $firstItems[0]['unit_price_gross'] = '80.00';

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $firstItems,
        ]))->assertRedirect();

        $first = Invoice::query()->where('document_type', InvoiceDocumentType::Correction)->sole();
        $secondItems = $this->submittedItems($invoice);
        $secondItems[0]['unit_price_gross'] = '60.00';

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $secondItems,
        ]))->assertRedirect();

        $second = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->whereKeyNot($first->getKey())
            ->sole();
        $product = $second->items->firstWhere('line_type', 'product');

        $this->assertSame($first->getKey(), $second->previous_correction_id);
        $this->assertSame('80.0000', $product->correction_before_snapshot['unit_price_gross']);
        $this->assertSame('60.0000', $product->correction_after_snapshot['unit_price_gross']);
        $this->assertSame('-20.00', $product->correction_difference_snapshot['total_gross']);
    }

    public function test_unchanged_correction_is_rejected_without_consuming_a_number(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $this->submittedItems($invoice),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('correction');

        $this->assertDatabaseMissing('invoices', [
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_non_correction_series_is_rejected_by_backend(): void
    {
        $invoice = $this->issuedInvoice();

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($invoice->series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, ['name' => 'Zmieniony nabywca']),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('correction');

        $this->assertDatabaseMissing('invoices', [
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_service_builds_a_linear_chain_under_domain_rules(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $service = app(CorrectionService::class);
        $data = $this->payload($series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, ['name' => 'Pierwsza zmiana']),
        ]);

        $first = $service->issue($invoice, $series, $data, $this->documentContext('2026-08-05 10:00:00'));
        $data['buyer']['name'] = 'Druga zmiana';
        $second = $service->issue($invoice, $series, $data, $this->documentContext('2026-08-05 10:01:00'));

        $this->assertSame($first->getKey(), $second->previous_correction_id);
        $this->assertSame('Pierwsza zmiana', $second->order_snapshot['correction']['buyer_before']['name']);
        $this->assertSame('Druga zmiana', $second->buyer_snapshot['name']);
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

    private function systemCorrectionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedItems(Invoice $source): array
    {
        return app(CorrectionSourceStateService::class)
            ->effectiveItems($source)
            ->map(function (array $item): array {
                $snapshot = $item['snapshot'];

                return [
                    'source_item_id' => $item['source_item_id'],
                    'order_item_id' => $item['source_item']->order_item_id,
                    'line_type' => $snapshot['line_type'],
                    'position' => $snapshot['position'],
                    'name' => $snapshot['name'],
                    'description' => $snapshot['description'],
                    'unit_name' => $snapshot['unit_name'],
                    'quantity' => (int) $snapshot['quantity'],
                    'unit_price_gross' => $this->twoDecimals($snapshot['unit_price_gross']),
                    'vat_rate' => $snapshot['vat_rate'] !== null
                        ? $this->twoDecimals($snapshot['vat_rate'])
                        : null,
                    'vat_code' => $snapshot['vat_code'],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(InvoiceSeries $series, array $overrides = []): array
    {
        return array_replace_recursive([
            'correction_series_id' => $series->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester korekty',
            'additional_information' => 'Informacja korekty',
            'change_items' => false,
            'change_buyer' => false,
            'items' => [],
            'buyer' => [],
        ], $overrides);
    }

    private function twoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
