<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Shipments\Models\CourierAccount;
use Tests\TestCase;

class InPostCourierSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_form_explains_and_marks_missing_required_fields(): void
    {
        $this->get(route('integrations.couriers.inpost-lockers.edit'))
            ->assertOk()
            ->assertSee('id="inpostAccountForm"', false)
            ->assertSee('novalidate', false)
            ->assertSee('<option value="order_id" selected>Numer zam&oacute;wienia</option>', false)
            ->assertSee('Uzupe&#322;nij wymagane pola oznaczone na czerwono.', false)
            ->assertSee("accountForm.classList.add('was-validated')", false)
            ->assertSee("showFieldTab(accountForm.querySelector(':invalid'))", false);
    }

    public function test_inpost_settings_are_saved_with_an_encrypted_token(): void
    {
        $response = $this->put(route('integrations.couriers.inpost-lockers.update'), [
            'name' => 'InPost Paczkomaty',
            'environment' => 'sandbox',
            'api_token' => 'test-secret-token-123',
            'organization_id' => '12345',
            'default_parcel_template' => 'medium',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'content_description_source' => 'customer_email',
            'sending_method' => 'parcel_locker',
            'sender_point_id' => 'PXS01M',
            'sender_company_name' => 'NEX Test',
            'sender_contact_name' => 'Jan Kowalski',
            'sender_street' => 'Testowa',
            'sender_building_number' => '12',
            'sender_apartment_number' => '4',
            'sender_postal_code' => '00-001',
            'sender_city' => 'Warszawa',
            'sender_country_code' => 'PL',
            'sender_phone' => '+48 501 294 368',
            'sender_email' => 'nadawca@example.test',
            'is_active' => '1',
        ]);

        $response->assertRedirect();

        $account = CourierAccount::query()->firstOrFail();

        $this->assertSame('test-secret-token-123', $account->api_token);
        $this->assertNotSame('test-secret-token-123', $account->getRawOriginal('api_token'));
        $this->assertTrue($account->is_active);
        $this->assertSame('medium', $account->setting('default_parcel_template'));
        $this->assertSame('customer_email', $account->setting('content_description_source'));
        $this->assertSame('parcel_locker', $account->setting('sending_method'));
        $this->assertSame('PXS01M', $account->setting('sender_point_id'));
        $this->assertSame('NEX Test', $account->setting('sender_company_name'));
    }

    public function test_connection_uses_the_configured_organization(): void
    {
        $account = CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'name' => 'InPost Paczkomaty',
            'environment' => 'sandbox',
            'api_token' => 'test-secret-token-123',
            'organization_id' => '9876',
            'settings' => [],
            'is_active' => true,
        ]);

        Http::fake([
            '*/v1/organizations/9876' => Http::response([
                'id' => 9876,
                'name' => 'NEX Test',
            ]),
        ]);

        $response = $this->post(route('integrations.couriers.inpost-lockers.test'));

        $response->assertRedirect();
        $response->assertSessionHas('inpost_connection_success', 'Polaczenie z InPost dziala. Organizacja: NEX Test.');
        $this->assertNull($account->fresh()->last_error);
        $this->assertNotNull($account->fresh()->last_tested_at);

        $this->get(route('integrations.couriers.inpost-lockers.edit'))
            ->assertOk()
            ->assertSee('Polaczenie z InPost dziala. Organizacja: NEX Test.')
            ->assertSee('bootstrap.Modal.getOrCreateInstance', false);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox-api-shipx-pl.easypack24.net/v1/organizations/9876'
            && $request->hasHeader('Authorization', 'Bearer test-secret-token-123'));
    }
}
