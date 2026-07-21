<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderVariableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_variables_page_documents_order_variables_and_is_linked_in_settings(): void
    {
        $this->get(route('settings.variables.index'))
            ->assertOk()
            ->assertSee('Zmienne')
            ->assertSee('[uwagi_sprzedawcy]')
            ->assertSee('[data_zamowienia]')
            ->assertSee(route('settings.variables.index'), false);
    }

    public function test_variables_can_be_rendered_as_plain_text_for_future_templates(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 158.5,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'customer_email' => 'klient@example.com',
            'notes' => 'SN001',
        ]);

        $rendered = app(OrderVariableService::class)->render(
            'Zamowienie [id_zamowienia], [email_kupujacego], [uwagi_sprzedawcy]',
            $order,
        );

        $this->assertSame(
            'Zamowienie '.$order->id.', klient@example.com, SN001',
            $rendered,
        );
    }
}
