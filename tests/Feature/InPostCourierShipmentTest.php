<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\InPost\Drivers\InPostCourierDriver;
use Modules\Integrations\InPost\Drivers\InPostLockerDriver;
use Modules\Integrations\InPost\Jobs\CreateInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Tests\TestCase;

class InPostCourierShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_uses_separate_inpost_drivers(): void
    {
        $registry = app(CourierDriverRegistry::class);

        $this->assertInstanceOf(
            InPostLockerDriver::class,
            $registry->driver(CourierAccount::PROVIDER_INPOST_LOCKERS),
        );
        $this->assertInstanceOf(
            InPostCourierDriver::class,
            $registry->driver(CourierAccount::PROVIDER_INPOST_COURIER),
        );
    }

    public function test_courier_account_settings_are_saved(): void
    {
        $this->account();

        $response = $this->put(route('integrations.couriers.inpost-courier.update'), [
            'name' => 'InPost Kurier',
            'environment' => 'sandbox',
            'api_token' => 'test-secret-token-123',
            'organization_id' => '12345',
            'default_service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'default_weight' => '1.5',
            'default_length' => '25',
            'default_width' => '20',
            'default_height' => '10',
            'default_insurance_amount' => '100',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'content_description_source' => 'order_id',
            'default_sms' => '1',
            'default_email' => '0',
            'default_saturday' => '1',
            'default_return_documents' => '0',
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

        $response->assertRedirect()->assertSessionHasNoErrors();

        $account = CourierAccount::query()->firstOrFail();

        $this->assertSame(CourierAccount::PROVIDER_INPOST_COURIER, $account->provider);
        $this->assertSame(Shipment::SERVICE_INPOST_COURIER_STANDARD, $account->setting('default_service'));
        $this->assertSame('1.5', (string) $account->setting('default_weight'));
        $this->assertTrue($account->setting('default_sms'));
        $this->assertTrue($account->setting('default_saturday'));
        $this->assertSame('NEX Test', $account->setting('sender_company_name'));
        $this->assertSame('Karton S', data_get($account->setting('parcel_templates'), '0.name'));
    }

    public function test_courier_parcel_templates_can_be_created_updated_and_deleted(): void
    {
        $account = $this->account();

        $this->post(route('integrations.couriers.inpost-courier.templates.store'), [
            'template_name' => 'Koperta',
            'template_weight' => '0.30',
            'template_length' => '35',
            'template_width' => '25',
            'template_height' => '3',
        ])->assertRedirect(route('integrations.couriers.inpost-courier.edit'));

        $createdTemplate = collect($account->fresh()->setting('parcel_templates'))
            ->firstWhere('name', 'Koperta');

        $this->assertNotNull($createdTemplate);
        $this->assertSame(0.3, $createdTemplate['weight']);

        $this->put(route('integrations.couriers.inpost-courier.templates.update', [
            'templateId' => $createdTemplate['id'],
        ]), [
            'template_name' => 'Koperta duza',
            'template_weight' => '0.50',
            'template_length' => '40',
            'template_width' => '30',
            'template_height' => '5',
        ])->assertRedirect(route('integrations.couriers.inpost-courier.edit'));

        $updatedTemplate = collect($account->fresh()->setting('parcel_templates'))
            ->firstWhere('id', $createdTemplate['id']);

        $this->assertSame('Koperta duza', $updatedTemplate['name']);
        $this->assertEquals(40.0, $updatedTemplate['length']);

        $this->delete(route('integrations.couriers.inpost-courier.templates.destroy', [
            'templateId' => $createdTemplate['id'],
        ]))->assertRedirect(route('integrations.couriers.inpost-courier.edit'));

        $this->assertNull(collect($account->fresh()->setting('parcel_templates'))
            ->firstWhere('id', $createdTemplate['id']));
    }

    public function test_insurance_is_required_at_least_up_to_the_cod_amount(): void
    {
        Queue::fake();
        $this->account();
        $order = $this->order();

        $response = $this->post(route('orders.shipments.inpost-courier.store', $order), [
            'shipment_provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'cod_amount' => '120.00',
            'insurance_amount' => '100.00',
            'parcels' => [$this->parcel()],
        ]);

        $response->assertRedirect()->assertSessionHasErrors('insurance_amount');
        $this->assertDatabaseCount('shipments', 0);
        Queue::assertNothingPushed();
    }

    public function test_courier_shipment_is_queued_with_multiple_parcels_and_sent_to_shipx(): void
    {
        Queue::fake();
        $this->account();
        $order = $this->order();

        $response = $this->post(route('orders.shipments.inpost-courier.store', $order), [
            'shipment_provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'service' => Shipment::SERVICE_INPOST_COURIER_EXPRESS_1200,
            'content_description' => (string) $order->id,
            'cod_amount' => '120.00',
            'insurance_amount' => '120.00',
            'additional_services' => [
                Shipment::ADDITIONAL_SERVICE_SMS,
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
            ],
            'parcels' => [
                $this->parcel(),
                $this->parcel(['weight' => '2.25', 'length' => '30', 'is_non_standard' => '1']),
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->with('parcels')->firstOrFail();

        $this->assertSame(CourierAccount::PROVIDER_INPOST_COURIER, $shipment->provider);
        $this->assertSame(Shipment::SERVICE_INPOST_COURIER_EXPRESS_1200, $shipment->service);
        $this->assertSame('120.00', $shipment->cod_amount);
        $this->assertSame('120.00', $shipment->insurance_amount);
        $this->assertCount(2, $shipment->parcels);
        $this->assertTrue($shipment->parcels[1]->is_non_standard);
        Queue::assertPushed(CreateInPostShipmentJob::class);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 7001,
                'status' => 'confirmed',
                'tracking_number' => '620000000000000000000001',
                'parcels' => [
                    ['id' => 'parcel-api-1', 'tracking_number' => '620000000000000000000001'],
                    ['id' => 'parcel-api-2', 'tracking_number' => '620000000000000000000002'],
                ],
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(function ($request) use ($order): bool {
            return $request->method() === 'POST'
                && $request['service'] === Shipment::SERVICE_INPOST_COURIER_EXPRESS_1200
                && $request['reference'] === str_pad((string) $order->id, 3, '0', STR_PAD_LEFT)
                && data_get($request->data(), 'parcels.0.dimensions.length') === 250.0
                && data_get($request->data(), 'parcels.1.dimensions.length') === 300.0
                && data_get($request->data(), 'parcels.1.weight.amount') === 2.25
                && data_get($request->data(), 'parcels.1.is_non_standard') === true
                && data_get($request->data(), 'cod.amount') === 120.0
                && data_get($request->data(), 'insurance.amount') === 120.0
                && in_array(Shipment::ADDITIONAL_SERVICE_SMS, $request['additional_services'], true)
                && in_array(Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS, $request['additional_services'], true)
                && data_get($request->data(), 'receiver.address.post_code') === '00-001';
        });

        $shipment->refresh()->load('parcels');
        $this->assertSame('parcel-api-1', $shipment->parcels[0]->external_id);
        $this->assertSame('620000000000000000000002', $shipment->parcels[1]->tracking_number);
        $this->assertDatabaseHas('integration_api_logs', [
            'integration' => CourierAccount::PROVIDER_INPOST_COURIER,
            'shipment_id' => $shipment->id,
            'successful' => true,
        ]);
    }

    public function test_order_view_shows_active_courier_account_and_parcel_form(): void
    {
        $this->account();
        $order = $this->order();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('InPost Kurier')
            ->assertSee('data-courier-form-url=', false)
            ->assertSee('data-courier-form-host', false)
            ->assertDontSee('action="'.route('orders.shipments.inpost-courier.store', $order).'"', false)
            ->assertDontSee('name="parcels[0][weight]"', false)
            ->assertDontSee('Odbiorca:');

        $formResponse = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
        ]))->assertOk();

        $formHtml = (string) $formResponse->json('html');
        $this->assertStringContainsString('data-inpost-courier-shipment-form', $formHtml);
        $this->assertStringContainsString('name="parcels[0][weight]"', $formHtml);
        $this->assertStringContainsString('data-courier-parcel-template-select', $formHtml);
        $this->assertStringContainsString('Karton S', $formHtml);
        $this->assertStringContainsString('Zwrot dokument&oacute;w', $formHtml);
        $this->assertStringNotContainsString('Spos&oacute;b nadania', $formHtml);

        $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
        ]))
            ->assertOk()
            ->assertJsonPath('provider', CourierAccount::PROVIDER_INPOST_COURIER)
            ->assertJsonPath('fields.service', Shipment::SERVICE_INPOST_COURIER_STANDARD)
            ->assertJsonPath('fields.cod_amount', '120.00')
            ->assertJsonPath('fields.insurance_amount', '120.00')
            ->assertJsonPath('fields.parcel.weight', '1')
            ->assertJsonPath('fields.parcel.length', '25')
            ->assertJsonPath('fields.parcel.width', '20')
            ->assertJsonPath('fields.parcel.height', '10')
            ->assertJsonMissingPath('fields.receiver');
    }

    public function test_courier_settings_use_the_compact_inpost_panel_layout(): void
    {
        $account = $this->account();
        $order = $this->order();
        $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => 'courier-panel-shipment',
            'tracking_number' => '620000000000000000000099',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->get(route('integrations.couriers.inpost-courier.edit'))
            ->assertOk()
            ->assertSee('class="inpost-page"', false)
            ->assertSee('Wyszukiwanie zaawansowane')
            ->assertSee('Utworzone przesy&#322;ki', false)
            ->assertSee('Zam&oacute;wienie kuriera', false)
            ->assertSee('Szablony wymiar&oacute;w i wag przesy&#322;ek', false)
            ->assertSee('Konto w systemie kuriera (po&#322;&#261;czenie API)', false)
            ->assertSee('id="inpostCourierAccountModal"', false)
            ->assertSee('id="inpostCourierTemplateModal"', false)
            ->assertSee('data-select-all-courier-shipments', false)
            ->assertSee('data-courier-shipment-checkbox', false)
            ->assertSee('class="inpost-panel nex-pagination-dropdown-host"', false)
            ->assertSee(route('integrations.couriers.inpost-courier.shipments.refresh'), false)
            ->assertSee(route('integrations.couriers.inpost-courier.shipments.delete'), false)
            ->assertSee('Od&#347;wie&#380; zaznaczone', false)
            ->assertSee('Usu&#324; zaznaczone', false)
            ->assertDontSee('<th>Podpaczki</th>', false)
            ->assertSee('class="nex-page-range dropdown-toggle"', false)
            ->assertSee('class="nex-pagination-total"', false)
            ->assertSee('class="btn-group btn-group-sm nex-page-navigation"', false)
            ->assertDontSee('Przesy&#322;ek na stron&#281;', false)
            ->assertSee('Konfiguracja InPost Kurier')
            ->assertSee('Ustawienia przesy&#322;ek', false)
            ->assertSeeInOrder([
                'Konto w systemie kuriera (po&#322;&#261;czenie API)',
                'Szablony wymiar&oacute;w i wag przesy&#322;ek',
            ], false)
            ->assertDontSee('courier-config-page', false);
    }

    public function test_courier_panel_bulk_actions_refresh_and_delete_selected_shipments(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $refreshShipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => 'courier-bulk-refresh',
            'tracking_number' => '620000000000000000000101',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $deleteShipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => 'courier-bulk-delete',
            'tracking_number' => '620000000000000000000102',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->post(route('integrations.couriers.inpost-courier.shipments.refresh'), [
            'shipment_ids' => [$refreshShipment->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(
            RefreshInPostShipmentJob::class,
            fn (RefreshInPostShipmentJob $job): bool => $job->shipment->is($refreshShipment),
        );

        $this->post(route('integrations.couriers.inpost-courier.shipments.delete'), [
            'shipment_ids' => [$deleteShipment->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shipments', ['id' => $refreshShipment->id]);
        $this->assertDatabaseMissing('shipments', ['id' => $deleteShipment->id]);
    }

    private function account(): CourierAccount
    {
        return CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_INPOST_COURIER,
            'name' => 'InPost Kurier',
            'environment' => 'sandbox',
            'api_token' => 'test-secret-token-123',
            'organization_id' => '12345',
            'settings' => [
                'default_service' => Shipment::SERVICE_INPOST_COURIER_STANDARD,
                'default_weight' => 1,
                'default_length' => 25,
                'default_width' => 20,
                'default_height' => 10,
                'default_insurance_amount' => 0,
                'label_format' => 'Pdf',
                'label_type' => 'A6',
                'content_description_source' => 'order_id',
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
                'parcel_templates' => [[
                    'id' => 'karton-s',
                    'name' => 'Karton S',
                    'weight' => 1,
                    'length' => 25,
                    'width' => 20,
                    'height' => 10,
                ]],
            ],
            'is_active' => true,
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'customer_email' => 'anna@example.test',
            'customer_phone' => '+48 501 294 368',
            'shipping_name' => 'Anna Kowalska',
            'shipping_street' => 'Klientowska',
            'shipping_building_number' => '8',
            'shipping_apartment_number' => '2',
            'shipping_postal_code' => '00-001',
            'shipping_city' => 'Warszawa',
            'shipping_country_code' => 'PL',
            'currency' => 'PLN',
            'total_gross' => 120,
            'paid_amount' => 0,
            'cash_on_delivery' => true,
            'payment_status' => 'unpaid',
        ]);
    }

    private function parcel(array $overrides = []): array
    {
        return array_merge([
            'weight' => '1.00',
            'length' => '25',
            'width' => '20',
            'height' => '10',
            'is_non_standard' => '0',
        ], $overrides);
    }
}
