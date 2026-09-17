<?php

namespace Tests\Feature\Invoices;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Invoices\IsolatesSalesRegisterFiles;
use Tests\TestCase;

class InvoiceGtuEditingTest extends TestCase
{
    use Concerns\CreatesInvoiceStage2CDocuments;
    use IsolatesSalesRegisterFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    public function test_gtu_only_save_preserves_all_money_snapshots_and_updates_version(): void
    {
        $invoice = $this->issue();
        $item = $invoice->items()->first();
        $before = $invoice->getAttributes();
        $beforeItem = $item->getAttributes();
        $this->get(route('invoices.edit', $invoice))->assertOk()->assertSee('Zapisz GTU')->assertSee('gtu_codes_present', false);
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->lock_version, 'gtu_only' => true,
            'gtu_codes' => [' gtu_06 ', 'GTU_01', 'GTU_06'], 'quantity' => '999', 'unit_price_gross' => '999',
        ])->assertOk();
        $this->assertSame(['GTU_01', 'GTU_06'], $item->fresh()->gtu_codes);
        $this->assertSame($before['lock_version'] + 1, $invoice->fresh()->lock_version);
        $this->assertSame(array_diff_key($before, array_flip(['lock_version', 'updated_at'])),
            array_diff_key($invoice->fresh()->getAttributes(), array_flip(['lock_version', 'updated_at'])));
        $this->assertSame(array_diff_key($beforeItem, array_flip(['gtu_codes', 'updated_at'])),
            array_diff_key($item->fresh()->getAttributes(), array_flip(['gtu_codes', 'updated_at'])));
        Http::assertNothingSent();
    }

    public function test_older_item_edit_preserves_gtu_and_explicit_empty_list_clears_it(): void
    {
        $invoice = $this->issue();
        $item = $invoice->items()->first();
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->lock_version, 'gtu_only' => true, 'gtu_codes' => ['GTU_06'],
        ])->assertOk();
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->fresh()->lock_version, 'name' => 'Changed description',
            'description' => null, 'unit_name' => $item->unit_name, 'quantity' => '1', 'unit_price_gross' => '100.00',
            'vat_rate' => '23', 'position' => 1,
        ])->assertOk();
        $this->assertSame(['GTU_06'], $item->fresh()->gtu_codes);
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->fresh()->lock_version, 'gtu_only' => true, 'gtu_codes' => [],
        ])->assertOk();
        $this->assertSame([], $item->fresh()->gtu_codes);
    }

    public function test_unchecked_html_controls_clear_only_with_explicit_presence_marker(): void
    {
        $invoice = $this->issue();
        $item = $invoice->items()->first();
        $item->update(['gtu_codes' => ['GTU_06']]);
        $payload = ['expected_lock_version' => $invoice->lock_version, 'gtu_only' => '1'];
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), $payload)->assertUnprocessable();
        $this->assertSame(['GTU_06'], $item->fresh()->gtu_codes);
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), $payload + ['gtu_codes_present' => '1'])->assertOk();
        $this->assertSame([], $item->fresh()->gtu_codes);
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_codes_are_rejected_without_changes(mixed $codes): void
    {
        $invoice = $this->issue();
        $item = $invoice->items()->first();
        $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->lock_version, 'gtu_only' => true, 'gtu_codes' => $codes,
        ])->assertUnprocessable()->assertJsonValidationErrors('gtu_codes');
        $this->assertSame([], $item->fresh()->gtu_codes);
        $this->assertSame($invoice->lock_version, $invoice->fresh()->lock_version);
    }

    public static function invalidCodes(): array
    {
        return [[null], [false], ['GTU_06'], [['GTU_99']], [['']], [[['GTU_01']]], [['key' => 'GTU_06']]];
    }

    public function test_foreign_item_and_stale_version_are_rejected(): void
    {
        $invoice = $this->issue();
        $other = $this->issue();
        $payload = ['expected_lock_version' => $invoice->lock_version, 'gtu_only' => true, 'gtu_codes' => ['GTU_06']];
        $this->patchJson(route('invoices.items.update', [$invoice, $other->items()->first()]), $payload)->assertNotFound();
        $this->patchJson(route('invoices.items.update', [$invoice, $invoice->items()->first()]), $payload)->assertOk();
        $this->patchJson(route('invoices.items.update', [$invoice, $invoice->items()->first()]), array_replace($payload, ['gtu_codes' => []]))->assertStatus(409);
        $this->assertSame(['GTU_06'], $invoice->items()->first()->gtu_codes);
    }

    public function test_gtu_edit_preserves_foreign_currency_rate_and_converted_amounts(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'nbp_table' => 'A']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('<?xml version="1.0"?><ExchangeRatesSeries><Table>A</Table><Code>EUR</Code><Rates><Rate><No>137/A/NBP/2026</No><EffectiveDate>2026-07-17</EffectiveDate><Mid>4.2000</Mid></Rate></Rates></ExchangeRatesSeries>')]);
        $order = $this->createDocumentOrder(['currency' => 'EUR']);
        $this->createDocumentItem($order, ['currency' => 'EUR']);
        $invoice = app(InvoiceIssuingService::class)->issue($order,
            $this->createDocumentSeries(attributes: ['default_currency' => 'EUR']), $this->documentContext())->fresh();
        $before = $invoice->getAttributes();
        $this->assertSame('4.2000', $invoice->tax_metadata_snapshot['currency_conversion']['rate']);
        $requests = Http::recorded()->count();
        $this->patchJson(route('invoices.items.update', [$invoice, $invoice->items()->first()]), [
            'expected_lock_version' => $invoice->lock_version, 'gtu_only' => true, 'gtu_codes' => ['GTU_06'],
        ])->assertOk();
        $this->assertSame(array_diff_key($before, array_flip(['lock_version', 'updated_at'])),
            array_diff_key($invoice->fresh()->getAttributes(), array_flip(['lock_version', 'updated_at'])));
        $this->assertSame($requests, Http::recorded()->count());
    }

    #[DataProvider('blockedDocuments')]
    public function test_document_guards_also_block_crafted_gtu_request(string $blocker): void
    {
        $invoice = $this->issue();
        $item = $invoice->items()->first();
        if ($blocker === 'provenance') {
            DB::table('ksef_invoice_provenances')->insert(['invoice_id' => $invoice->id, 'environment' => 'production',
                'provenance' => 'outside_ksef', 'recorded_at' => now()]);
        } elseif ($blocker === 'correction') {
            Invoice::query()->create(['order_id' => $invoice->order_id, 'document_type' => 'correction', 'status' => 'issued',
                'invoice_series_id' => $invoice->invoice_series_id, 'corrected_invoice_id' => $invoice->id, 'currency' => 'PLN']);
        } else {
            $invoice->forceFill(['finalized_at' => now()])->save();
        }
        $response = $this->patchJson(route('invoices.items.update', [$invoice, $item]), [
            'expected_lock_version' => $invoice->lock_version, 'gtu_only' => true, 'gtu_codes' => ['GTU_06'],
        ]);
        $this->assertContains($response->status(), [409, 422]);
        $this->assertSame([], $item->fresh()->gtu_codes);
        $this->assertSame($invoice->lock_version, $invoice->fresh()->lock_version);
        if ($blocker !== 'correction') {
            $this->get(route('invoices.edit', $invoice))->assertDontSee('Zapisz GTU');
        }
    }

    public static function blockedDocuments(): array
    {
        return [['finalized'], ['correction'], ['provenance']];
    }

    private function issue(): Invoice
    {
        $order = $this->createDocumentOrder(['billing_tax_id' => null]);
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue($order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false, 'seller_tax_id' => '1234563218']),
            $this->documentContext());
    }
}
