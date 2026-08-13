<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntegrationListTest extends TestCase
{
    public function test_integration_list_contains_non_interactive_ksef_tile(): void
    {
        $response = $this->get(route('integrations.index'));

        $response->assertOk()
            ->assertSeeText('Lista integracji')
            ->assertSee('data-integration="ksef"', false)
            ->assertSeeText('KSeF')
            ->assertDontSee('href="/integrations/ksef"', false)
            ->assertSee('href="'.route('integrations.index').'"', false);
    }
}
