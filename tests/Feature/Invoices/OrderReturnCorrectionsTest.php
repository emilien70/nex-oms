<?php

namespace Tests\Feature\Invoices;

use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfViewModelFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderReturnCorrectionsTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_opening_form_has_return_quantities_no_number_or_document_and_safe_back_link(): void
    {
        [$invoice, $return] = $this->fixture();
        $before = InvoiceNumberCounter::query()->get()->toArray();
        $response = $this->get($this->createUrl($invoice, $return))->assertOk()
            ->assertSee('Pozycje zwrotu')->assertSee('Uwzględnij korektę kosztu dostawy')
            ->assertSee('href="'.route('returns.show', $return).'"', false);
        $prepared = $response->viewData('returnCorrection');
        $this->assertSame(3, $prepared['rows'][0]['before']);
        $this->assertSame(1, $prepared['rows'][0]['selected']);
        $this->assertSame('23.00', $prepared['shipping']['maximum']);
        $this->assertSame('goods_return', $response->viewData('defaults')['reason']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame($before, InvoiceNumberCounter::query()->get()->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('sources')]
    public function test_partial_return_uses_invoice_prices_and_preserves_order_for_any_source(string $source): void
    {
        [$invoice, $return] = $this->fixture($source);
        $before = $invoice->order->getAttributes();
        $return->items()->update(['unit_price' => '0.01', 'name' => 'Different return snapshot']);
        $payload = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->assertNotNull($correction);
        $this->assertSame('-100.00', $correction->total_gross);
        $product = $correction->items()->where('line_type', 'product')->sole();
        $this->assertSame('3.0000', $product->correction_before_snapshot['quantity']);
        $this->assertSame('2.0000', $product->correction_after_snapshot['quantity']);
        $this->assertSame('100.0000', $product->unit_price_gross);
        $this->assertSame('0.00', $correction->items()->where('line_type', 'shipping')->sole()->correction_difference_snapshot['total_gross']);
        $this->assertSame($before, $invoice->order->fresh()->getAttributes());
        $this->assertSame(1, $return->events()->where('type', 'correction_issued')->count());
        $this->get(route('returns.show', $return))->assertOk()->assertSee('Edytuj korektę')->assertSee($correction->number);
        Http::assertNothingSent();
    }

    public static function sources(): array
    {
        return [['manual'], ['prestashop'], ['allegro']];
    }

    #[DataProvider('shippingAmounts')]
    public function test_shipping_can_be_corrected_fully_or_partly(string $amount, string $total, string $remaining): void
    {
        [$invoice, $return] = $this->fixture();
        $data = [...$this->payload($invoice, $return), 'return_shipping' => true, 'return_shipping_amount' => $amount];
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->assertSame($total, $correction->total_gross);
        $this->assertSame($remaining, $correction->items()->where('line_type', 'shipping')->sole()->correction_after_snapshot['total_gross']);
        $this->assertDatabaseCount('allegro_refund_commands', 0);
        $this->assertDatabaseCount('allegro_commission_refund_claims', 0);
    }

    public static function shippingAmounts(): array
    {
        return [['23.00', '-123.00', '0.00'], ['5.01', '-105.01', '17.99']];
    }

    #[DataProvider('badInput')]
    public function test_invalid_selection_does_not_consume_number_or_leave_link(string $kind): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $key = array_key_first($data['return_quantities']);
        match ($kind) {
            'too_many' => $data['return_quantities'][$key] = 2,
            'negative' => $data['return_quantities'][$key] = -1,
            'fraction' => $data['return_quantities'][$key] = '0.5',
            'zero' => $data['return_quantities'][$key] = 0,
            'foreign_line' => $data['return_quantities'][999999] = 1,
            'shipping' => $data = [...$data, 'return_shipping' => 1, 'return_shipping_amount' => '23.01'],
            'hash' => $data['return_context_hash'] = str_repeat('0', 64),
            'changed_return' => $return->items()->update(['quantity' => 2]),
            'changed_version' => $return->increment('lock_version'),
            'changed_source' => $invoice->items()->where('line_type', 'product')->update(['quantity' => '4.0000', 'total_gross' => '400.00']),
        };
        $before = InvoiceNumberCounter::query()->get()->toArray();
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertNull($return->fresh()->correction);
        $this->assertSame($before, InvoiceNumberCounter::query()->get()->toArray());
    }

    public static function badInput(): array
    {
        return array_map(fn ($value) => [$value], ['too_many', 'negative', 'fraction', 'zero', 'foreign_line', 'shipping', 'hash', 'changed_return', 'changed_version', 'changed_source']);
    }

    public function test_repeat_store_and_reopening_return_go_to_existing_document(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertRedirect();
        $this->get($this->createUrl($invoice, $return))->assertRedirect();
        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame($correction->id, $return->fresh()->correction->id);
    }

    public function test_unfinished_correction_for_another_return_is_not_overwritten_or_attached(): void
    {
        [$invoice, $return] = $this->fixture();
        $this->post(route('invoices.corrections.store', $invoice), $this->payload($invoice, $return))->assertSessionHasNoErrors();
        $first = $return->fresh()->correction;
        $other = $this->makeReturn($invoice);
        $this->get($this->createUrl($invoice, $other))->assertRedirect()->assertSessionHas('warning');
        $this->assertNull($other->correction);
        $this->assertSame('-100.00', $first->fresh()->total_gross);
    }

    public function test_next_return_uses_last_closed_after_and_only_remaining_shipping(): void
    {
        [$invoice, $return] = $this->fixture();
        $this->post(route('invoices.corrections.store', $invoice), [...$this->payload($invoice, $return), 'return_shipping' => 1, 'return_shipping_amount' => '5.00'])->assertSessionHasNoErrors();
        $first = $return->fresh()->correction;
        app(InvoiceFinalizationService::class)->finalize($first);
        $other = $this->makeReturn($invoice);
        $data = $this->payload($invoice, $other);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $second = $other->fresh()->correction;
        $this->assertSame($first->id, $second->previous_correction_id);
        $product = $second->items()->where('line_type', 'product')->sole();
        $this->assertSame('2.0000', $product->correction_before_snapshot['quantity']);
        $this->assertSame('1.0000', $product->correction_after_snapshot['quantity']);
        $this->assertSame('18.00', $second->items()->where('line_type', 'shipping')->sole()->correction_before_snapshot['total_gross']);
        $this->assertSame(1, $return->events()->where('type', 'correction_finalized')->count());
    }

    public function test_linked_edit_uses_frozen_return_limits_and_updates_same_number(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $return->items()->update(['quantity' => 3, 'name' => 'Changed after issue']);
        $update = [...$data, 'expected_lock_version' => $correction->lock_version, 'additional_information' => 'Updated'];
        $this->patch(route('invoices.corrections.update', $correction), $update)->assertSessionHasNoErrors();
        $this->assertSame($correction->number, $correction->fresh()->number);
        $this->assertSame('-100.00', $correction->fresh()->total_gross);
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()->assertSee('data-return-correction-items', false);
        $update['expected_lock_version'] = $correction->fresh()->lock_version;
        $update['return_quantities'][array_key_first($update['return_quantities'])] = 2;
        $this->patch(route('invoices.corrections.update', $correction), $update)->assertSessionHasErrors('correction');
    }

    public function test_parent_return_deletion_preserves_snapshot_and_pdf_header_without_live_dependency(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $series = InvoiceSeries::findOrFail($data['correction_series_id']);
        $series->update(['show_return_id_in_header' => true]);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $id = $return->id;
        $snapshot = $correction->order_snapshot;
        $return->delete();
        $correction->refresh();
        $this->assertNull($correction->order_return_id);
        $this->assertSame($snapshot, $correction->order_snapshot);
        $view = app(InvoicePdfViewModelFactory::class)->make($correction);
        $this->assertSame($id, $view['return_number']);
        $series->update(['show_return_id_in_header' => false]);
        $this->assertSame($id, app(InvoicePdfViewModelFactory::class)->make($correction->fresh())['return_number']);
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()->assertSee('Zwrot '.$id);
    }

    public function test_allowed_document_deletion_releases_link_and_keeps_return_history_and_back_target(): void
    {
        [$invoice, $return] = $this->fixture();
        $this->post(route('invoices.corrections.store', $invoice), $this->payload($invoice, $return))->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->delete(route('invoices.destroy', $correction), ['expected_lock_version' => $correction->lock_version,
            'return_to' => 'order_return', 'return_query' => http_build_query(['order_return_id' => $return->id])])
            ->assertRedirect(route('returns.show', $return));
        $this->assertNull($return->fresh()->correction);
        $this->assertSame(1, $return->events()->where('type', 'correction_deleted')->count());
        $this->get($this->createUrl($invoice, $return))->assertOk();
    }

    public function test_unmatched_or_foreign_return_and_duplicate_invoice_lines_are_blocked(): void
    {
        [$invoice, $return] = $this->fixture();
        [$other] = $this->fixture();
        $this->get($this->createUrl($other, $return))->assertStatus(422);
        $return->items()->update(['order_item_id' => null]);
        $this->get($this->createUrl($invoice, $return))->assertStatus(422);
        $return->items()->update(['order_item_id' => $invoice->items()->where('line_type', 'product')->sole()->order_item_id]);
        $invoice->items()->where('line_type', 'product')->sole()->replicate()->save();
        $this->get($this->createUrl($invoice, $return))->assertStatus(422);
    }

    public function test_closed_link_is_read_only_and_reopening_does_not_create_another_correction(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = app(InvoiceFinalizationService::class)->finalize($return->fresh()->correction);
        $this->get($this->createUrl($invoice, $return))->assertRedirect();
        $this->get(route('invoices.corrections.edit', $correction))->assertOk()->assertSee('Korekta została zamknięta');
        $this->patch(route('invoices.corrections.update', $correction), [...$data, 'expected_lock_version' => $correction->lock_version])->assertSessionHasErrors();
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_submitted_prices_and_unselected_shipping_amount_cannot_change_document(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $data['return_shipping_amount'] = '23.00';
        $data['items'] = [['line_type' => 'product', 'position' => 1, 'name' => 'Tampered', 'unit_name' => 'szt.',
            'quantity' => 0, 'unit_price_gross' => '0.01', 'vat_rate' => '0']];
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->assertSame('-100.00', $correction->total_gross);
        $product = $correction->items()->where('line_type', 'product')->sole();
        $this->assertSame('Produkt testowy', $product->name);
        $this->assertSame('23.00', $product->vat_rate);
        $this->assertSame('100.0000', $product->unit_price_gross);
    }

    public function test_zero_gross_return_still_corrects_quantity_without_forcing_shipping(): void
    {
        $order = $this->createDocumentOrder(['total_gross' => '23.00', 'paid_amount' => '0.00']);
        $this->createDocumentItem($order, ['quantity' => 3, 'unit_price_gross' => '0.00', 'total_price_gross' => '0.00']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());
        $return = $this->makeReturn($invoice);
        $this->post(route('invoices.corrections.store', $invoice), $this->payload($invoice, $return))->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame('2.0000', $correction->items()->where('line_type', 'product')->sole()->quantity);
    }

    public function test_no_invoice_or_rejected_return_has_no_active_create_action(): void
    {
        [$invoice, $return] = $this->fixture();
        $return->update(['local_status_id' => ReturnStatusSetting::where('code', 'rejected')->value('id')]);
        $this->get($this->createUrl($invoice, $return))->assertStatus(422);
        $this->get(route('returns.show', $return))->assertOk()->assertSee('Wymagany nieodrzucony zwrot');
        $standalone = OrderReturn::create(['source' => 'manual', 'creation_method' => 'manual', 'currency' => 'PLN',
            'total_value' => '0.00', 'submitted_at' => now()]);
        $this->get(route('returns.show', $standalone))->assertOk()->assertSee('Wymagany nieodrzucony zwrot');
    }

    public function test_print_header_is_optional_and_return_link_cannot_be_changed_on_update(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $this->assertNull(app(InvoicePdfViewModelFactory::class)->make($correction)['return_number']);
        $other = $this->makeReturn($invoice);
        $this->patch(route('invoices.corrections.update', $correction), [...$data,
            'order_return_id' => $other->id, 'expected_lock_version' => $correction->lock_version])->assertSessionHasErrors('correction');
        $this->assertSame($return->id, $correction->fresh()->order_return_id);
        $this->assertNull($other->fresh()->correction);
    }

    public function test_stale_edit_and_repeat_confirmation_of_closed_document_do_not_mutate_history(): void
    {
        [$invoice, $return] = $this->fixture();
        $data = $this->payload($invoice, $return);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertSessionHasNoErrors();
        $correction = $return->fresh()->correction;
        $version = $correction->lock_version;
        $update = [...$data, 'expected_lock_version' => $version, 'additional_information' => 'Saved text'];
        $this->patch(route('invoices.corrections.update', $correction), $update)->assertSessionHasNoErrors();
        $this->patch(route('invoices.corrections.update', $correction), [...$update, 'additional_information' => 'Stale text'])->assertSessionHasErrors('correction');
        $this->assertSame('Saved text', $correction->fresh()->additional_information_text);
        app(InvoiceFinalizationService::class)->finalize($correction);
        $this->post(route('invoices.corrections.store', $invoice), $data)->assertRedirect();
        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame(1, $return->events()->where('type', 'correction_finalized')->count());
    }

    private function fixture(string $source = 'manual'): array
    {
        $order = $this->createDocumentOrder(['source' => $source, 'total_gross' => '323.00']);
        $this->createDocumentItem($order, ['quantity' => 3, 'total_price_gross' => '300.00']);
        $invoice = app(InvoiceIssuingService::class)->issue($order, $this->createDocumentSeries(), $this->documentContext());

        return [$invoice, $this->makeReturn($invoice)];
    }

    private function makeReturn(Invoice $invoice): OrderReturn
    {
        $return = OrderReturn::create(['source' => $invoice->order->source, 'creation_method' => 'manual', 'order_id' => $invoice->order_id,
            'currency' => $invoice->currency, 'total_value' => '100.00', 'submitted_at' => now(), 'local_status_changed_at' => now(),
            'local_status_id' => ReturnStatusSetting::where('code', 'new')->value('id'), 'reference_number' => 'RET-'.uniqid()]);
        $return->items()->create(['order_item_id' => $invoice->items()->where('line_type', 'product')->sole()->order_item_id,
            'position' => 1, 'name' => 'Produkt testowy', 'reason' => 'MISTAKE', 'quantity' => 1, 'unit_price' => '100.00', 'total_value' => '100.00', 'currency' => $invoice->currency]);

        return $return;
    }

    private function createUrl(Invoice $invoice, OrderReturn $return): string
    {
        return route('invoices.corrections.create', ['invoice' => $invoice, 'order_return_id' => $return->id,
            'return_to' => 'order_return', 'return_query' => http_build_query(['order_return_id' => $return->id])]);
    }

    private function payload(Invoice $invoice, OrderReturn $return): array
    {
        $view = $this->get($this->createUrl($invoice, $return))->assertOk();
        $prepared = $view->viewData('returnCorrection');
        $effective = app(CorrectionSourceStateService::class)->effectiveDocument($invoice);

        return ['order_return_id' => $return->id, 'return_context_hash' => $prepared['hash'],
            'return_quantities' => array_column($prepared['rows'], 'selected', 'source_item_id'),
            'expected_source_document_id' => $effective->id, 'expected_source_lock_version' => $effective->lock_version,
            'correction_series_id' => $view->viewData('selectedSeries')->id, 'reason' => 'goods_return',
            'issue_date' => '2026-10-22', 'sale_date' => '2026-07-20', 'change_items' => true, 'change_buyer' => false,
            'additional_information' => '', 'return_shipping' => false];
    }
}
