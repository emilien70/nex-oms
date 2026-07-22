<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicesPageTest extends TestCase
{
    public function test_invoices_page_is_available_from_orders_menu(): void
    {
        $response = $this->get(route('invoices.index'));

        $response
            ->assertOk()
            ->assertSee('Faktury')
            ->assertSee('Obs&#322;uga faktur zostanie dodana p&oacute;&#378;niej.', false);
    }
}
