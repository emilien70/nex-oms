<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCountryTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_view_contains_country_selects_and_polish_country_names(): void
    {
        $order = $this->createOrder([
            'shipping_country_code' => 'DE',
            'billing_country_code' => 'PL',
        ]);

        $response = $this->get(route('orders.show', $order));

        $response->assertOk()
            ->assertSee('name="shipping_country_code"', false)
            ->assertSee('name="billing_country_code"', false)
            ->assertSee('<option value="DE" selected>Niemcy</option>', false)
            ->assertSee('<option value="PL" selected>Polska</option>', false)
            ->assertSee('Niemcy')
            ->assertSee('Polska')
            ->assertSee("setFormValue(billingForm, 'billing_country_code'", false)
            ->assertSee("setFormValue(shippingForm, 'shipping_country_code'", false)
            ->assertSee("setBillingValue('billing_country_code', 'PL')", false)
            ->assertDontSee('Kraj: DE')
            ->assertDontSee('Kraj: PL');
    }

    public function test_empty_historical_countries_do_not_select_poland_automatically(): void
    {
        $response = $this->get(route('orders.show', $this->createOrder()));
        $html = $response->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'value="PL" selected',
            $this->selectMarkup($html, 'shipping_country_code'),
        );
        $this->assertStringNotContainsString(
            'value="PL" selected',
            $this->selectMarkup($html, 'billing_country_code'),
        );
    }

    public function test_shipping_country_is_normalized_and_does_not_change_billing_country(): void
    {
        $order = $this->createOrder([
            'shipping_country_code' => 'DE',
            'billing_country_code' => 'FR',
        ]);

        $this->patch(route('orders.sections.shipping-address', $order), [
            'shipping_name' => 'Anna Nowak',
            'shipping_company_name' => '',
            'shipping_address_line' => 'Testowa 1',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'shipping_country_code' => ' pl ',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('PL', $order->shipping_country_code);
        $this->assertSame('FR', $order->billing_country_code);
    }

    public function test_billing_country_is_normalized_and_does_not_change_shipping_country(): void
    {
        $order = $this->createOrder([
            'shipping_country_code' => 'DE',
            'billing_country_code' => 'FR',
        ]);

        $this->patch(route('orders.sections.billing-address', $order), [
            'billing_name' => 'Jan Kowalski',
            'billing_company_name' => '',
            'billing_address_line' => 'Fakturowa 2',
            'billing_postal_code' => '00-002',
            'billing_city' => 'Warszawa',
            'billing_country_code' => ' pl ',
            'billing_tax_id' => '',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('DE', $order->shipping_country_code);
        $this->assertSame('PL', $order->billing_country_code);
    }

    public function test_invalid_and_empty_shipping_country_are_rejected_with_polish_message(): void
    {
        $order = $this->createOrder(['shipping_country_code' => 'DE']);
        $payload = [
            'shipping_name' => 'Anna Nowak',
            'shipping_address_line' => 'Testowa 1',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
        ];

        $this->from(route('orders.show', $order))
            ->patch(route('orders.sections.shipping-address', $order), $payload + [
                'shipping_country_code' => 'XX',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHasErrors([
                'shipping_country_code' => 'Wybierz prawidłowy kraj.',
            ]);

        $this->from(route('orders.show', $order))
            ->patch(route('orders.sections.shipping-address', $order), $payload + [
                'shipping_country_code' => '',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHasErrors([
                'shipping_country_code' => 'Wybierz prawidłowy kraj.',
            ]);

        $this->assertSame('DE', $order->fresh()->shipping_country_code);
    }

    public function test_ajax_fragments_show_resolved_country_names(): void
    {
        $order = $this->createOrder([
            'shipping_country_code' => 'DE',
            'billing_country_code' => 'PL',
        ]);

        $response = $this->getJson(route('orders.state', [
            'order' => $order,
            'fragments' => 'shipping,billing',
        ]))->assertOk();

        $this->assertStringContainsString('Niemcy', $response->json('fragments.shipping'));
        $this->assertStringContainsString('Polska', $response->json('fragments.billing'));
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'payment_status' => 'unpaid',
            'shipping_name' => 'Anna Nowak',
            'shipping_street' => 'Testowa',
            'shipping_building_number' => '1',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'billing_name' => 'Jan Kowalski',
            'billing_street' => 'Fakturowa',
            'billing_building_number' => '2',
            'billing_postal_code' => '00-002',
            'billing_city' => 'Warszawa',
        ], $attributes));
    }

    private function selectMarkup(string $html, string $name): string
    {
        preg_match('/<select[^>]+name="'.preg_quote($name, '/').'"[^>]*>.*?<\/select>/s', $html, $matches);

        return $matches[0] ?? '';
    }
}
