<?php

namespace Tests\Feature;

use App\Exceptions\OrderCurrencyException;
use App\Models\Currency;
use App\Models\Order;
use App\Services\OrderTotalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_product_currency_must_exist_and_match_order_currency(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => 'PLN']);

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

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_first_item_can_set_currency_only_for_money_empty_order(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order(['currency' => '']);

        $this->post(route('orders.products.store', $order), [
            'product_name' => 'Produkt',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'currency' => 'EUR',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('EUR', $order->refresh()->currency);
        $this->assertSame('EUR', $order->items()->firstOrFail()->currency);
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
}
