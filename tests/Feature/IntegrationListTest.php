<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntegrationListTest extends TestCase
{
    public function test_integration_list_contains_allegro_tile(): void
    {
        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('data-integration="allegro"', false)
            ->assertSeeText('Allegro')
            ->assertSee('href="'.route('integrations.allegro.edit').'"', false);
    }

    public function test_integration_list_contains_active_ksef_tile(): void
    {
        $response = $this->get(route('integrations.index'));

        $response->assertOk()
            ->assertSeeText('Lista integracji')
            ->assertSee('data-integration="ksef"', false)
            ->assertSeeText('KSeF')
            ->assertSee('href="'.route('integrations.ksef.edit').'"', false)
            ->assertSee('href="'.route('integrations.index').'"', false);
    }

    public function test_integration_list_contains_paynow_tile(): void
    {
        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('data-integration="paynow"', false)
            ->assertSeeText('PayNow')
            ->assertSee('href="'.route('integrations.paynow.edit').'"', false);
    }

    public function test_integration_list_contains_prestashop_tile(): void
    {
        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('data-integration="prestashop"', false)
            ->assertSeeText('PrestaShop')
            ->assertSee('href="'.route('integrations.prestashop.edit').'"', false);
    }
}
