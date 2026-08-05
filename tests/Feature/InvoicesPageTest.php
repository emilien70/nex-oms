<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_page_is_available_from_orders_menu(): void
    {
        $response = $this->get(route('invoices.index'));

        $response
            ->assertOk()
            ->assertSee('Faktury')
            ->assertSee('Seria numeracji')
            ->assertSee('Wyszukiwanie zaawansowane')
            ->assertSee('Drukuj zaznaczone')
            ->assertDontSee('Obsługa faktur zostanie dodana później.');
    }
}
