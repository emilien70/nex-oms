<?php

namespace Tests\Feature;

use App\Exceptions\OrderCurrencyException;
use App\Models\Currency;
use App\Models\Order;
use App\Services\OrderTotalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Tests\TestCase;

class OrderCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_uses_order_currency_and_decimal_totals_without_float(): void
    {
        $order = $this->order(['currency' => 'PLN', 'delivery_cost_gross' => '0.10']);

        $this->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 3,
            'unit_price_gross' => '0,10',
            'currency' => 'pln',
            'vat_rate' => '23',
            'weight' => '1',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'currency' => 'PLN',
            'unit_price_gross' => '0.10',
            'total_price_gross' => '0.30',
        ]);
        $this->assertSame('0.40', $order->refresh()->total_gross);
    }

    public function test_order_product_accepts_integer_vat_rates_including_future_rates(): void
    {
        Http::preventStrayRequests();

        foreach (['0', '8', '23', '24', '100'] as $vatRate) {
            $order = $this->order();

            $this->post(route('orders.products.store', $order), [
                'product_name' => 'Produkt VAT '.$vatRate,
                'quantity' => 1,
                'unit_price_gross' => '10.00',
                'currency' => 'PLN',
                'vat_rate' => $vatRate,
            ])->assertSessionDoesntHaveErrors();

            $this->assertSame($vatRate.'.00', $order->items()->sole()->vat_rate);
        }
    }

    public function test_order_product_rejects_non_integer_and_out_of_range_vat_input(): void
    {
        $order = $this->order();

        foreach (['23.0', '23.00', '23,00', '23.5', '100.01', '101', '1000', '-1'] as $vatRate) {
            $this->from(route('orders.show', $order))->post(route('orders.products.store', $order), [
                'product_name' => 'Nieprawidłowy VAT',
                'quantity' => 1,
                'unit_price_gross' => '10.00',
                'currency' => 'PLN',
                'vat_rate' => $vatRate,
            ])->assertSessionHasErrors('vat_rate');
        }

        $this->assertDatabaseMissing('order_items', ['order_id' => $order->getKey()]);
        $this->assertSame('0.00', $order->fresh()->total_gross);
    }

    public function test_order_product_price_boundary_is_validated_before_persistence(): void
    {
        $invalidOrder = $this->order();

        $this->from(route('orders.show', $invalidOrder))->post(route('orders.products.store', $invalidOrder), [
            'product_name' => 'Za wysoka cena',
            'quantity' => 1,
            'unit_price_gross' => '10000000000.00',
            'currency' => 'PLN',
            'vat_rate' => '23',
        ])->assertSessionHasErrors('unit_price_gross');

        $this->assertDatabaseMissing('order_items', ['order_id' => $invalidOrder->getKey()]);

        $validOrder = $this->order();
        $this->post(route('orders.products.store', $validOrder), [
            'product_name' => 'Maksymalna bezpieczna cena',
            'quantity' => 1,
            'unit_price_gross' => '9999999999.99',
            'currency' => 'PLN',
            'vat_rate' => '24',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('9999999999.99', $validOrder->fresh()->total_gross);
        $this->assertSame('9999999999.99', $validOrder->items()->sole()->total_price_gross);
    }

    public function test_order_line_overflow_rolls_back_item_total_and_event(): void
    {
        $order = $this->order();

        $this->from(route('orders.show', $order))->post(route('orders.products.store', $order), [
            'product_name' => 'Przepełniona wartość pozycji',
            'quantity' => 2,
            'unit_price_gross' => '9999999999.99',
            'currency' => 'PLN',
            'vat_rate' => '24',
        ])->assertSessionHasErrors('unit_price_gross');

        $this->assertDatabaseMissing('order_items', ['order_id' => $order->getKey()]);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'product_added',
        ]);
        $this->assertSame('0.00', $order->fresh()->total_gross);
    }

    public function test_product_currency_must_exist_and_match_order_currency(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'PLN']);
        $order->items()->create([
            'product_name' => 'Istniejący produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'total_price_gross' => '10.00',
            'currency' => 'PLN',
        ]);

        $this->from(route('orders.show', $order))->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'currency' => 'EUR',
        ])->assertSessionHasErrors('currency');

        $this->from(route('orders.show', $order))->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'currency' => 'XXX',
        ])->assertSessionHasErrors('currency');

        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame('PLN', $order->refresh()->currency);
    }

    public function test_first_item_sets_currency_of_economically_empty_pln_order(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'PLN']);
        Http::preventStrayRequests();

        $this->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '25.00',
            'currency' => 'EUR',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $item = $order->items()->firstOrFail();
        $event = $order->events()->where('event_type', 'product_added')->firstOrFail();

        $this->assertSame('EUR', $order->currency);
        $this->assertSame('25.00', $order->total_gross);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('0.00', $order->delivery_cost_gross);
        $this->assertSame('EUR', $item->currency);
        $this->assertSame('25.00', $item->unit_price_gross);
        $this->assertSame('25.00', $item->total_price_gross);
        $this->assertSame('EUR', $event->payload['currency']);
        $this->assertTrue($event->payload['order_currency_adopted']);
        $this->assertSame('PLN', $event->payload['previous_order_currency']);
        $this->assertCount(1, $order->items);
        Http::assertNothingSent();
    }

    public function test_second_item_must_use_adopted_order_currency(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'PLN']);

        $this->postProduct($order, 'EUR')->assertSessionDoesntHaveErrors();
        $this->postProduct($order, 'EUR')->assertSessionDoesntHaveErrors();
        $this->postProduct($order, 'PLN')->assertSessionHasErrors('currency');

        $this->assertSame('EUR', $order->refresh()->currency);
        $this->assertCount(2, $order->items);
    }

    public function test_non_zero_delivery_cost_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder(['delivery_cost_gross' => '10.00']);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_non_zero_paid_amount_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder(['paid_amount' => '5.00']);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_non_zero_total_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder(['total_gross' => '10.00']);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_existing_invoice_or_proforma_blocks_first_item_currency_adoption(): void
    {
        $this->ensureCurrency('EUR');

        foreach ([InvoiceDocumentType::Invoice, InvoiceDocumentType::Proforma] as $documentType) {
            $order = $this->order();
            $series = InvoiceSeries::query()->where('document_type', $documentType->value)->firstOrFail();
            Invoice::query()->create([
                'order_id' => $order->id,
                'invoice_series_id' => $series->id,
                'document_type' => $documentType,
                'currency' => 'PLN',
            ]);

            $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');
            $this->assertCurrencyAdoptionWasBlocked($order);
        }
    }

    public function test_existing_document_slot_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder();
        OrderDocumentSlot::query()->create([
            'order_id' => $order->id,
            'document_type' => InvoiceDocumentType::Invoice,
        ]);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_existing_shipment_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder();
        $order->shipments()->create([
            'provider' => 'test',
            'service' => 'test',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_existing_shipment_creation_attempt_blocks_first_item_currency_adoption(): void
    {
        $order = $this->foreignCurrencyOrder();
        $order->shipmentCreationAttempts()->create([
            'provider' => 'test',
            'request_uuid' => (string) Str::uuid(),
            'status' => ShipmentCreationAttempt::STATUS_QUEUED,
        ]);

        $this->postProduct($order, 'EUR')->assertSessionHasErrors('currency');

        $this->assertCurrencyAdoptionWasBlocked($order);
    }

    public function test_first_pln_item_does_not_change_order_currency(): void
    {
        $order = $this->order(['currency' => 'PLN']);

        $this->postProduct($order, 'PLN')->assertSessionDoesntHaveErrors();

        $event = $order->events()->where('event_type', 'product_added')->firstOrFail();
        $this->assertSame('PLN', $order->refresh()->currency);
        $this->assertSame('PLN', $order->items()->firstOrFail()->currency);
        $this->assertFalse($event->payload['order_currency_adopted']);
    }

    public function test_failed_product_creation_rolls_back_adopted_currency_item_total_and_event(): void
    {
        $this->ensureCurrency('EUR');
        $order = $this->order(['currency' => 'PLN']);
        $totals = Mockery::mock(OrderTotalService::class);
        $totals->shouldReceive('lineTotal')->once()->andReturn('25.00');
        $totals->shouldReceive('recalculate')->once()->andThrow(new \RuntimeException('Kontrolowany błąd testowy.'));
        $this->instance(OrderTotalService::class, $totals);
        $this->withoutExceptionHandling();

        try {
            $this->postProduct($order, 'EUR');
            $this->fail('Oczekiwany błąd nie został zgłoszony.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Kontrolowany błąd testowy.', $exception->getMessage());
        }

        $order->refresh();
        $this->assertSame('PLN', $order->currency);
        $this->assertSame('0.00', $order->total_gross);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->id,
            'event_type' => 'product_added',
        ]);
    }

    public function test_historical_unknown_currency_is_shown_but_cannot_be_selected_as_new_value(): void
    {
        $order = $this->order(['currency' => 'XYZ']);
        $item = $order->items()->create([
            'product_name' => 'Historyczny',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'total_price_gross' => '10.00',
            'currency' => 'XYZ',
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('XYZ')
            ->assertDontSee('waluta historyczna')
            ->assertSee('value="XYZ" selected disabled', false);

        $this->patch(route('order-items.update', $item), [
            'product_name' => 'Historyczny poprawiony',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('XYZ', $item->refresh()->currency);
    }

    public function test_total_service_rejects_mixed_currency_order(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'PLN']);
        $order->items()->create([
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'total_price_gross' => '10.00',
            'currency' => 'EUR',
        ]);

        $this->expectException(OrderCurrencyException::class);
        app(OrderTotalService::class)->recalculate($order);
    }

    public function test_product_form_uses_only_local_codes_without_http_and_selects_order_currency(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'EUR']);
        Http::preventStrayRequests();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('value="PLN"', false)
            ->assertSee('value="EUR" selected', false)
            ->assertDontSee('euro')
            ->assertDontSee('nbp_table');

        Http::assertNothingSent();
    }

    public function test_empty_order_product_form_defaults_to_pln(): void
    {
        $order = $this->order(['currency' => '']);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('value="PLN" selected', false);
    }

    public function test_decimal_total_delivery_and_remaining_due_are_exact(): void
    {
        $order = $this->order([
            'currency' => 'PLN',
            'delivery_cost_gross' => '0.20',
            'paid_amount' => '20.00',
        ]);
        $totals = app(OrderTotalService::class);
        $order->items()->create([
            'product_name' => 'Produkt',
            'quantity' => 3,
            'unit_price_gross' => '19.99',
            'total_price_gross' => $totals->lineTotal('19.99', 3),
            'currency' => 'PLN',
        ]);

        $this->assertSame('60.17', $totals->recalculate($order));
        $this->assertSame('40.17', $totals->remainingDue($order->refresh()));
    }

    public function test_payment_update_without_currency_preserves_eur(): void
    {
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'EUR', 'payment_method' => 'Stara metoda']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'paid',
            'payment_method' => 'Przelew',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('Przelew', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_payment_update_without_currency_preserves_pln(): void
    {
        $order = $this->order(['currency' => 'PLN']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'paid',
            'payment_method' => 'Gotówka',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('PLN', $order->refresh()->currency);
    }

    public function test_payment_update_without_currency_preserves_unknown_historical_code(): void
    {
        $order = $this->order(['currency' => 'XYZ', 'payment_method' => 'Stara metoda']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'paid',
            'payment_method' => 'Historyczna płatność',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('XYZ', $order->currency);
        $this->assertSame('Historyczna płatność', $order->payment_method);
    }

    public function test_empty_currency_cannot_clear_existing_order_currency(): void
    {
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'EUR', 'payment_method' => 'Stara metoda']);

        $this->patch(route('orders.sections.payment', $order), [
            'currency' => '',
            'payment_status' => 'paid',
            'payment_method' => 'Nowa metoda',
        ])->assertSessionHasErrors('currency');

        $order->refresh();
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('Stara metoda', $order->payment_method);
    }

    public function test_payment_update_cannot_change_order_currency_through_manipulated_request(): void
    {
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'euro', 'nbp_table' => 'A']);
        Currency::query()->updateOrCreate(['code' => 'USD'], ['name' => 'dolar amerykański', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'EUR', 'payment_method' => 'Stara metoda']);

        $this->patch(route('orders.sections.payment', $order), [
            'currency' => 'USD',
            'payment_status' => 'paid',
            'payment_method' => 'Nowa metoda',
        ])->assertSessionHasErrors('currency');

        $order->refresh();
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('Stara metoda', $order->payment_method);
    }

    public function test_payment_update_does_not_fallback_to_pln_when_order_has_no_currency(): void
    {
        $order = $this->order(['currency' => '', 'payment_method' => 'Stara metoda']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'paid',
            'payment_method' => 'Nowa metoda',
        ])->assertSessionHasErrors('currency');

        $order->refresh();
        $this->assertSame('', $order->currency);
        $this->assertSame('Stara metoda', $order->payment_method);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
            'cash_on_delivery' => false,
            'payment_status' => 'unpaid',
        ], $attributes));
    }

    private function ensureCurrency(string $code): void
    {
        Currency::query()->updateOrCreate(
            ['code' => $code],
            ['name' => strtolower($code), 'nbp_table' => 'A'],
        );
    }

    private function foreignCurrencyOrder(array $attributes = []): Order
    {
        $this->ensureCurrency('EUR');

        return $this->order(array_replace(['currency' => 'PLN'], $attributes));
    }

    private function postProduct(Order $order, string $currency)
    {
        return $this->from(route('orders.show', $order))->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '25.00',
            'currency' => $currency,
        ]);
    }

    private function assertCurrencyAdoptionWasBlocked(Order $order): void
    {
        $this->assertSame('PLN', $order->refresh()->currency);
        $this->assertCount(0, $order->items);
    }
}
