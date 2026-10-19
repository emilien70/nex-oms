<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAjaxUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_state_can_render_order_fragments(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt AJAX',
            'quantity' => 2,
            'unit_price_gross' => 25,
            'total_price_gross' => 50,
            'currency' => 'PLN',
        ]);
        $order->events()->create([
            'event_type' => 'test_event',
            'title' => 'Zdarzenie AJAX',
        ]);

        $response = $this->getJson(route('orders.state', $order).'?fragments=order-info,shipping,billing,pickup,products,history,shipments');

        $response->assertOk()->assertJsonStructure([
            'fields',
            'latest_event_id',
            'shipments_signature',
            'fragments' => [
                'order-info',
                'shipping',
                'billing',
                'pickup',
                'products',
                'shipments',
                'history',
            ],
        ]);

        $this->assertStringContainsString('Produkt AJAX', $response->json('fragments.products'));
        $this->assertStringContainsString('Zdarzenie AJAX', $response->json('fragments.history'));
        $this->assertStringContainsString('data-edit-field="shipping_company_name"', $response->json('fragments.shipping'));
        $this->assertStringContainsString('data-edit-field="billing_tax_id"', $response->json('fragments.billing'));
        $this->assertStringContainsString('data-edit-field="pickup_point_id"', $response->json('fragments.pickup'));
        $this->assertStringContainsString('nex-empty">...', $response->json('fragments.shipping'));
        $this->assertStringContainsString('nex-empty">...', $response->json('fragments.billing'));
        $this->assertStringNotContainsString('nex-empty">-', $response->json('fragments.shipping'));
        $this->assertStringNotContainsString('nex-empty">-', $response->json('fragments.billing'));
    }

    public function test_inline_order_section_returns_ajax_refresh_instructions(): void
    {
        $order = $this->order();
        $order->items()->create([
            'product_name' => 'Produkt z dostawa',
            'quantity' => 2,
            'unit_price_gross' => 50,
            'total_price_gross' => 100,
            'currency' => 'PLN',
        ]);

        $this->patchJson(route('orders.sections.order-info', $order), [
            'source' => 'manual',
            'customer_login' => 'ajax-user',
            'customer_email' => 'ajax@example.test',
            'customer_phone' => '501294368',
            'shipping_method' => 'Paczkomat',
            'cash_on_delivery' => false,
            'delivery_cost_gross' => '12.50',
            'payment_method' => 'Przelew',
            'notes' => 'Zapis bez przeładowania',
        ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('refresh.0', 'order-info')
            ->assertJsonPath('refresh.1', 'history');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'customer_login' => 'ajax-user',
            'shipping_method' => 'Paczkomat',
            'total_gross' => '112.50',
        ]);
    }

    public function test_order_page_maps_inline_rows_to_their_editor_fields(): void
    {
        $response = $this->get(route('orders.show', $this->order()))->assertOk();

        foreach ([
            'customer_login',
            'customer_email',
            'customer_phone',
            'source',
            'shipping_method',
            'cash_on_delivery',
            'delivery_cost_gross',
            'payment_method',
            'notes',
            'shipping_name',
            'shipping_company_name',
            'shipping_address_line',
            'shipping_postal_code',
            'shipping_country_code',
            'billing_name',
            'billing_company_name',
            'billing_address_line',
            'billing_postal_code',
            'billing_country_code',
            'billing_tax_id',
            'pickup_point_name',
            'pickup_point_id',
            'pickup_point_address',
            'pickup_point_postal_code',
        ] as $fieldName) {
            $response->assertSee('data-edit-field="'.$fieldName.'"', false);
        }

        $response
            ->assertSee('form.elements.namedItem(fieldName)', false)
            ->assertSee('input:not([type="hidden"]), select, textarea', false)
            ->assertSee('focusTarget.focus()', false)
            ->assertSee('focusTarget.select()', false);
    }

    public function test_payment_and_product_mutations_return_ajax_refresh_instructions(): void
    {
        $order = $this->order(['total_gross' => 100]);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'order-info');

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Nowy produkt',
            'quantity' => 1,
            'unit_price_gross' => '49.99',
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'products')
            ->assertJsonPath('refresh.1', 'order-info')
            ->assertJsonPath('refresh.2', 'history');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_name' => 'Nowy produkt',
        ]);
        $this->assertSame('49.99', $order->fresh()->total_gross);

        $orderItem = $order->items()->firstOrFail();

        $this->patchJson(route('order-items.update', $orderItem), [
            'product_name' => 'Nowy produkt',
            'quantity' => 2,
            'unit_price_gross' => 30,
            'currency' => 'PLN',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $this->assertSame('60.00', $order->fresh()->total_gross);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => 0,
        ])->assertOk();

        $this->deleteJson(route('order-items.destroy', $orderItem))
            ->assertOk()
            ->assertJsonPath('refresh.1', 'order-info');

        $order->refresh();
        $this->assertSame('0.00', $order->total_gross);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);

        $orderInfo = $this->getJson(route('orders.state', $order).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bg-secondary', $orderInfo);
        $this->assertStringContainsString('0,00 PLN', $orderInfo);
    }

    public function test_payment_actions_use_icons_and_show_payment_link_only_for_unpaid_order(): void
    {
        $unpaidOrder = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
        ]);

        $unpaidOrderInfo = $this->getJson(route('orders.state', $unpaidOrder).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bi bi-arrow-clockwise', $unpaidOrderInfo);
        $this->assertStringContainsString('bi bi-pencil', $unpaidOrderInfo);
        $this->assertStringContainsString('data-payment-link-action', $unpaidOrderInfo);
        $this->assertStringContainsString('data-payment-link-url="'.route('orders.paynow.payment-link', $unpaidOrder).'"', $unpaidOrderInfo);
        $this->assertStringContainsString('Pojedyncze klikni&#281;cie: kopiuj do schowka', $unpaidOrderInfo);
        $this->assertStringContainsString('Podw&oacute;jne klikni&#281;cie: otw&oacute;rz link.', $unpaidOrderInfo);
        $this->assertStringNotContainsString('data-payment-link-placeholder', $unpaidOrderInfo);
        $this->assertStringContainsString('bi bi-link-45deg', $unpaidOrderInfo);

        $paidOrder = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '100.00',
            'payment_status' => 'paid',
        ]);

        $paidOrderInfo = $this->getJson(route('orders.state', $paidOrder).'?fragments=order-info')
            ->assertOk()
            ->json('fragments.order-info');

        $this->assertStringContainsString('bi bi-arrow-clockwise', $paidOrderInfo);
        $this->assertStringContainsString('bi bi-pencil', $paidOrderInfo);
        $this->assertStringNotContainsString('data-payment-link-action', $paidOrderInfo);
        $this->assertStringNotContainsString('bi bi-link-45deg', $paidOrderInfo);
    }

    public function test_first_eur_product_refreshes_currency_fields_fragments_and_next_product_form(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'euro', 'nbp_table' => 'A']);
        $order = $this->order([
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);

        $this->postJson(route('orders.products.store', $order), [
            'product_name' => 'Produkt EUR',
            'quantity' => 1,
            'unit_price_gross' => '25.00',
            'currency' => 'EUR',
        ])
            ->assertOk()
            ->assertJsonPath('refresh.0', 'products')
            ->assertJsonPath('refresh.1', 'order-info')
            ->assertJsonPath('refresh.2', 'history');

        $state = $this->getJson(route('orders.state', $order).'?fragments=products,order-info')
            ->assertOk()
            ->assertJsonPath('fields.currency', 'EUR');

        $orderInfo = $state->json('fragments.order-info');
        $products = $state->json('fragments.products');

        $this->assertStringContainsString('0,00 EUR', $orderInfo);
        $this->assertGreaterThanOrEqual(2, substr_count($orderInfo, '0,00 EUR'));
        $this->assertStringContainsString('25,00 EUR', $orderInfo);
        $this->assertStringContainsString('Produkt EUR', $products);
        $this->assertStringContainsString('25,00 EUR', $products);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('value="EUR" selected', false)
            ->assertDontSee('value="PLN" selected', false);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'shipping_name' => 'Jan Kowalski',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
        ], $overrides));
    }
}
