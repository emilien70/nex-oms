<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceEditingTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nbp.retries' => 0, 'nbp.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_issued_invoice_has_edit_page_and_order_link(): void
    {
        $invoice = $this->issueInvoice();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Faktura VAT '.$invoice->number)
            ->assertSee('Dane nabywcy')
            ->assertSee('Aktualne dane w zamówieniu')
            ->assertSee('name="expected_lock_version"', false)
            ->assertDontSee('revision_number')
            ->assertDontSee('Rewizja')
            ->assertSee(route('invoices.pdf', $invoice), false);

        $this->get(route('orders.show', $invoice->order))
            ->assertOk()
            ->assertSee(route('invoices.edit', $invoice), false);
    }

    public function test_only_consistent_issued_invoice_vat_without_corrections_is_editable(): void
    {
        $invoice = $this->issueInvoice();

        $invoice->update(['status' => InvoiceDocumentStatus::Draft]);
        $this->get(route('invoices.edit', $invoice))->assertStatus(422);

        $invoice->update(['status' => InvoiceDocumentStatus::Issued, 'document_type' => InvoiceDocumentType::Proforma]);
        $this->get(route('invoices.edit', $invoice))->assertStatus(422);

        $invoice->update(['document_type' => InvoiceDocumentType::Invoice]);
        $invoice->documentSlots()->delete();
        $this->get(route('invoices.edit', $invoice))->assertStatus(422);
    }

    public function test_correction_blocks_invoice_editing(): void
    {
        $invoice = $this->issueInvoice();
        Invoice::query()->create([
            'order_id' => $invoice->order_id,
            'invoice_series_id' => $this->createDocumentSeries(InvoiceDocumentType::Correction)->id,
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'corrected_invoice_id' => $invoice->id,
        ]);

        $this->get(route('invoices.edit', $invoice))->assertStatus(422);
    }

    public function test_buyer_is_edited_only_in_snapshot_and_does_not_create_edit_event(): void
    {
        $invoice = $this->issueInvoice();
        $orderCity = $invoice->order->billing_city;
        $payload = $this->buyerPayload($invoice, ['city' => 'Gdańsk']);

        $this->patchJson(route('invoices.buyer.update', $invoice), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('lock_version', 2)
            ->assertJsonStructure(['fragments' => ['buyer']]);

        $invoice->refresh();
        $this->assertSame('Gdańsk', $invoice->buyer_snapshot['city']);
        $this->assertSame($orderCity, $invoice->order->fresh()->billing_city);
        $this->assertSame(2, $invoice->lock_version);
        $this->assertDatabaseMissing('order_events', ['order_id' => $invoice->order_id, 'event_type' => 'invoice_edited']);
    }

    public function test_recipient_and_seller_edits_change_only_invoice_snapshots(): void
    {
        $invoice = $this->issueInvoice();
        $order = $invoice->order;
        $series = $invoice->series;

        $this->patchJson(route('invoices.recipient.update', $invoice), $this->recipientPayload($invoice, [
            'city' => 'Wrocław',
        ]))->assertOk()->assertJsonPath('lock_version', 2);

        $invoice->refresh();
        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice, [
            'seller_name' => 'Sprzedawca ze snapshotu',
            'additional_information_text' => 'Informacja po edycji',
        ]))->assertOk()->assertJsonPath('lock_version', 3);

        $invoice->refresh();
        $this->assertSame('Wrocław', $invoice->recipient_snapshot['city']);
        $this->assertSame('Sprzedawca ze snapshotu', $invoice->seller_snapshot['name']);
        $this->assertSame('Informacja po edycji', $invoice->additional_information_text);
        $this->assertSame('Kraków', $order->fresh()->shipping_city);
        $this->assertSame('NEX Seller sp. z o.o.', $series->fresh()->seller_name);
    }

    public function test_identical_update_is_no_op_and_does_not_change_lock_or_invalidate_pdf(): void
    {
        Storage::fake('local');
        $invoice = $this->issueInvoice();
        $eventCount = $invoice->order->events()->count();
        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);

        $this->patchJson(route('invoices.buyer.update', $invoice), $this->buyerPayload($invoice))
            ->assertOk()
            ->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('lock_version', 1);

        $this->assertSame(1, $invoice->fresh()->lock_version);
        $this->assertSame($eventCount, $invoice->order->events()->count());
        Storage::disk('local')->assertExists($path);
    }

    public function test_stale_lock_version_returns_conflict_without_mutation(): void
    {
        $invoice = $this->issueInvoice();
        $payload = $this->buyerPayload($invoice, ['city' => 'Łódź']);
        $payload['expected_lock_version'] = 99;

        $this->patchJson(route('invoices.buyer.update', $invoice), $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'invoice_edit_conflict');

        $this->assertNotSame('Łódź', $invoice->fresh()->buyer_snapshot['city']);
    }

    public function test_immutable_fields_are_rejected_by_backend(): void
    {
        $invoice = $this->issueInvoice();

        foreach ([
            'number' => 'FA 999/2026',
            'invoice_series_id' => 999,
            'currency' => 'EUR',
            'document_type' => 'proforma',
            'status' => 'draft',
        ] as $field => $value) {
            $this->patchJson(route('invoices.buyer.update', $invoice), $this->buyerPayload($invoice, [$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertNotSame('FA 999/2026', $invoice->fresh()->number);
        $this->assertSame('PLN', $invoice->fresh()->currency);
        $this->assertSame(1, $invoice->fresh()->lock_version);
    }

    public function test_issue_date_may_change_only_when_number_and_period_stay_the_same(): void
    {
        $invoice = $this->issueInvoice();
        $samePeriod = $this->detailsPayload($invoice, ['issue_date' => '2026-08-01']);

        $this->patchJson(route('invoices.details.update', $invoice), $samePeriod)->assertOk();
        $invoice->refresh();
        $this->assertSame('2026-08-01', $invoice->issue_date->toDateString());
        $this->assertSame('FV 1/2026', $invoice->number);

        $differentPeriod = $this->detailsPayload($invoice, ['issue_date' => '2027-01-02']);
        $this->patchJson(route('invoices.details.update', $invoice), $differentPeriod)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_edit_numbering_mismatch');
        $this->assertSame('2026-08-01', $invoice->fresh()->issue_date->toDateString());
    }

    public function test_item_crud_recalculates_totals_and_ajax_fragments(): void
    {
        $invoice = $this->issueInvoice();
        $initialCount = $invoice->items()->count();
        $payload = $this->itemPayload($invoice, ['name' => 'Pozycja ręczna', 'quantity' => '2', 'unit_price_gross' => '10.00', 'position' => 20]);

        $response = $this->postJson(route('invoices.items.store', $invoice), $payload)
            ->assertOk()
            ->assertJsonPath('lock_version', 2)
            ->assertJsonStructure(['fragments' => ['items', 'totals', 'nbp-summary']]);

        $invoice->refresh();
        $this->assertSame($initialCount + 1, $invoice->items()->count());
        $item = $invoice->items()->where('name', 'Pozycja ręczna')->firstOrFail();
        $this->assertSame('20.00', $item->total_gross);
        $this->assertStringContainsString('Pozycja ręczna', $response->json('fragments.items'));

        $this->patchJson(route('invoices.items.update', [$invoice, $item]), $this->itemPayload($invoice->fresh(), [
            'name' => 'Pozycja zmieniona', 'quantity' => '3', 'unit_price_gross' => '10.00', 'position' => 20,
        ]))->assertOk()->assertJsonPath('lock_version', 3);
        $this->assertSame('30.00', $item->fresh()->total_gross);

        $this->deleteJson(route('invoices.items.destroy', [$invoice, $item]), ['expected_lock_version' => 3])
            ->assertOk()
            ->assertJsonPath('lock_version', 4);
        $this->assertDatabaseMissing('invoice_items', ['id' => $item->id]);
    }

    public function test_item_totals_are_calculated_server_side_and_missing_tax_is_rejected(): void
    {
        $invoice = $this->issueInvoice();
        $payload = $this->itemPayload($invoice, [
            'quantity' => '2',
            'unit_price_gross' => '12.34',
            'total_net' => '0.01',
            'total_vat' => '0.01',
            'total_gross' => '9999.99',
        ]);

        $this->postJson(route('invoices.items.store', $invoice), $payload)->assertOk();
        $item = $invoice->items()->where('name', 'Pozycja dodatkowa')->firstOrFail();
        $this->assertSame('24.68', $item->total_gross);
        $this->assertNotSame('9999.99', $item->total_gross);

        $invalid = $this->itemPayload($invoice->fresh(), ['vat_rate' => null, 'vat_code' => null]);
        $this->postJson(route('invoices.items.store', $invoice), $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vat_rate', 'vat_code']);
    }

    public function test_last_item_cannot_be_deleted_and_paid_amount_cannot_exceed_total(): void
    {
        $invoice = $this->issueInvoice();
        $invoice->items()->where('id', '!=', $invoice->items()->firstOrFail()->id)->delete();
        $item = $invoice->items()->firstOrFail();

        $this->deleteJson(route('invoices.items.destroy', [$invoice, $item]), ['expected_lock_version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_last_item');

        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice, ['paid_amount' => '9999.00']))
            ->assertUnprocessable();
        $this->assertSame('50.00', $invoice->fresh()->paid_amount);
    }

    public function test_copy_from_order_uses_series_snapshot_and_checks_currency(): void
    {
        $invoice = $this->issueInvoice();
        $eventCount = $invoice->order->events()->count();
        $invoice->series->update(['include_shipping' => false]);
        $invoice->order->items()->firstOrFail()->update(['product_name' => 'Aktualny produkt', 'unit_price_gross' => '150.00', 'total_price_gross' => '150.00']);

        $this->postJson(route('invoices.items.copy-from-order', $invoice), ['expected_lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('lock_version', 2);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'name' => 'Aktualny produkt', 'total_gross' => 150]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'name' => 'Kurier testowy']);
        $this->assertSame($eventCount, $invoice->order->events()->count());

        $invoice->order->update(['currency' => 'EUR']);
        $this->postJson(route('invoices.items.copy-from-order', $invoice), ['expected_lock_version' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_order_currency_mismatch');
    }

    public function test_copy_from_order_rolls_back_when_new_total_is_lower_than_paid_amount(): void
    {
        Storage::fake('local');
        $invoice = $this->issueInvoice();
        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        $beforeNames = $invoice->items()->orderBy('id')->pluck('name')->all();
        $beforeTotal = $invoice->total_gross;
        $invoice->order->items()->firstOrFail()->update(['unit_price_gross' => '1.00', 'total_price_gross' => '1.00']);
        $invoice->order->update(['shipping_method' => null, 'delivery_cost_gross' => '0.00']);

        $this->postJson(route('invoices.items.copy-from-order', $invoice), ['expected_lock_version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_paid_amount_exceeds_total');

        $invoice->refresh();
        $this->assertSame($beforeNames, $invoice->items()->orderBy('id')->pluck('name')->all());
        $this->assertSame($beforeTotal, $invoice->total_gross);
        $this->assertSame(1, $invoice->lock_version);
        Storage::disk('local')->assertExists($path);
    }

    public function test_pdf_cache_is_invalidated_after_edit_and_regenerated_at_the_same_path(): void
    {
        Storage::fake('local');
        $invoice = $this->issueInvoice();

        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $currentPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice->fresh());
        $stalePath = 'invoices/'.$invoice->getKey().'/invoice-v39.pdf';
        Storage::disk('local')->put($stalePath, '%PDF-1.7 stale invoice');
        Storage::disk('local')->assertExists($currentPath);

        $this->patchJson(route('invoices.buyer.update', $invoice), $this->buyerPayload($invoice, [
            'city' => 'Sopot',
        ]))->assertOk()->assertJsonPath('lock_version', 2);

        Storage::disk('local')->assertMissing($currentPath);
        Storage::disk('local')->assertMissing($stalePath);

        $invoice->refresh();
        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $regeneratedPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);

        $this->assertSame($currentPath, $regeneratedPath);
        $this->assertStringEndsWith('/invoice-v40.pdf', $regeneratedPath);
        Storage::disk('local')->assertExists($regeneratedPath);
        $this->assertCount(1, Storage::disk('local')->allFiles('invoices/'.$invoice->getKey()));
    }

    public function test_foreign_money_edit_uses_saved_rate_without_http_and_date_change_fetches_new_rate(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'nbp_table' => 'A']);
        Http::fakeSequence()
            ->push($this->xml('4.2000'))
            ->push($this->xml('4.5000', '2026-07-21'));
        $invoice = $this->issueInvoice(['currency' => 'EUR'], ['currency' => 'EUR'], ['default_currency' => 'EUR']);
        $this->assertSame(1, Http::recorded()->count());

        $this->postJson(route('invoices.items.store', $invoice), $this->itemPayload($invoice, [
            'name' => 'Usługa EUR', 'unit_price_gross' => '10.00', 'position' => 20,
        ]))->assertOk();
        $this->assertSame(1, Http::recorded()->count());
        $this->assertSame('4.2000', $invoice->fresh()->tax_metadata_snapshot['currency_conversion']['rate']);

        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice->fresh(), [
            'issue_date' => '2026-08-01',
        ]))->assertOk();
        $this->assertSame(1, Http::recorded()->count());

        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice->fresh(), [
            'sale_date' => '2026-07-22',
        ]))->assertOk();
        $this->assertSame(2, Http::recorded()->count());
        $this->assertSame('4.5000', $invoice->fresh()->tax_metadata_snapshot['currency_conversion']['rate']);
    }

    public function test_legacy_foreign_invoice_without_rate_allows_text_but_blocks_money_and_invalid_snapshot_blocks_every_edit(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'nbp_table' => 'A']);
        Http::fake(['*' => Http::response($this->xml('4.2000'))]);
        $invoice = $this->issueInvoice(['currency' => 'EUR'], ['currency' => 'EUR'], ['default_currency' => 'EUR']);
        $invoice->update(['tax_metadata_snapshot' => []]);

        $this->patchJson(route('invoices.buyer.update', $invoice), $this->buyerPayload($invoice, ['city' => 'Berlin']))
            ->assertOk();
        $invoice->refresh();
        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice, [
            'issue_date' => '2026-08-01',
        ]))->assertOk();
        $this->assertCount(1, Http::recorded());

        $invoice->refresh();
        $this->patchJson(route('invoices.details.update', $invoice), $this->detailsPayload($invoice, [
            'sale_date' => '2026-07-22',
        ]))->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_edit_missing_currency_snapshot');

        $invoice->refresh();
        $this->postJson(route('invoices.items.store', $invoice), $this->itemPayload($invoice, ['name' => 'Kwota bez kursu']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_edit_missing_currency_snapshot');

        $invoice->update(['tax_metadata_snapshot' => ['currency_conversion' => ['rate' => 'broken']]]);
        $this->patchJson(route('invoices.buyer.update', $invoice), $this->buyerPayload($invoice, ['city' => 'Monachium']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_edit_invalid_currency_snapshot');
    }

    private function issueInvoice(array $orderAttributes = [], array $itemAttributes = [], array $seriesAttributes = []): Invoice
    {
        $order = $this->createDocumentOrder($orderAttributes);
        $this->createDocumentItem($order, $itemAttributes);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Invoice, $seriesAttributes),
            $this->documentContext(),
        )->refresh();
    }

    /** @return array<string, mixed> */
    private function buyerPayload(Invoice $invoice, array $changes = []): array
    {
        $buyer = $invoice->fresh()->buyer_snapshot;

        return array_merge([
            'expected_lock_version' => $invoice->fresh()->lock_version,
            'name' => $buyer['name'] ?? null,
            'company_name' => $buyer['company_name'] ?? null,
            'tax_id' => $buyer['tax_id'] ?? null,
            'street' => $buyer['street'] ?? null,
            'building_number' => $buyer['building_number'] ?? null,
            'apartment_number' => $buyer['apartment_number'] ?? null,
            'postal_code' => $buyer['postal_code'] ?? null,
            'city' => $buyer['city'] ?? null,
            'province' => $buyer['province'] ?? null,
            'country_code' => $buyer['country_code'] ?? null,
            'email' => $buyer['email'] ?? null,
            'phone' => $buyer['phone'] ?? null,
        ], $changes);
    }

    /** @return array<string, mixed> */
    private function recipientPayload(Invoice $invoice, array $changes = []): array
    {
        $recipient = $invoice->fresh()->recipient_snapshot;

        return array_merge([
            'expected_lock_version' => $invoice->fresh()->lock_version,
            'name' => $recipient['name'] ?? null,
            'company_name' => $recipient['company_name'] ?? null,
            'street' => $recipient['street'] ?? null,
            'building_number' => $recipient['building_number'] ?? null,
            'apartment_number' => $recipient['apartment_number'] ?? null,
            'postal_code' => $recipient['postal_code'] ?? null,
            'city' => $recipient['city'] ?? null,
            'province' => $recipient['province'] ?? null,
            'country_code' => $recipient['country_code'] ?? null,
            'email' => $recipient['email'] ?? null,
            'phone' => $recipient['phone'] ?? null,
        ], $changes);
    }

    /** @return array<string, mixed> */
    private function detailsPayload(Invoice $invoice, array $changes = []): array
    {
        $invoice = $invoice->fresh();
        $seller = $invoice->seller_snapshot ?? [];
        $issuer = $invoice->issuer_snapshot ?? [];
        $payment = $invoice->payment_snapshot ?? [];

        return array_merge([
            'expected_lock_version' => $invoice->lock_version,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'sale_date' => $invoice->sale_date?->toDateString(),
            'payment_due_date' => $invoice->payment_due_date?->toDateString(),
            'payment_method' => $payment['effective_payment_method'] ?? null,
            'payment_identifier' => $payment['payment_identifier'] ?? null,
            'paid_amount' => $invoice->paid_amount,
            'place_of_issue' => $issuer['place_of_issue'] ?? null,
            'issuer_name' => $issuer['issuer_name'] ?? null,
            'additional_information_text' => $invoice->additional_information_text,
            'seller_name' => $seller['name'] ?? null,
            'seller_tax_id' => $seller['tax_id'] ?? null,
            'seller_regon' => $seller['regon'] ?? null,
            'seller_bdo' => $seller['bdo'] ?? null,
            'seller_street' => $seller['street'] ?? null,
            'seller_building_number' => $seller['building_number'] ?? null,
            'seller_apartment_number' => $seller['apartment_number'] ?? null,
            'seller_postal_code' => $seller['postal_code'] ?? null,
            'seller_city' => $seller['city'] ?? null,
            'seller_province' => $seller['province'] ?? null,
            'seller_country_code' => $seller['country_code'] ?? null,
            'seller_email' => $seller['email'] ?? null,
            'seller_phone' => $seller['phone'] ?? null,
            'seller_bank_name' => $seller['bank_name'] ?? null,
            'seller_bank_account' => $seller['bank_account'] ?? null,
            'seller_bank_swift' => $seller['bank_swift'] ?? null,
        ], $changes);
    }

    /** @return array<string, mixed> */
    private function itemPayload(Invoice $invoice, array $changes = []): array
    {
        return array_merge([
            'expected_lock_version' => $invoice->fresh()->lock_version,
            'name' => 'Pozycja dodatkowa',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => '1',
            'unit_price_gross' => '10.00',
            'vat_rate' => '23',
            'vat_code' => null,
            'position' => 10,
        ], $changes);
    }

    private function xml(string $mid, string $effectiveDate = '2026-07-17'): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><ExchangeRatesSeries><Table>A</Table><Code>EUR</Code><Rates><Rate><No>137/A/NBP/2026</No><EffectiveDate>{$effectiveDate}</EffectiveDate><Mid>{$mid}</Mid></Rate></Rates></ExchangeRatesSeries>";
    }
}
