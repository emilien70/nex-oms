<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Integrations\AllegroShipping\Drivers\AllegroShippingDriver;
use Modules\Integrations\AllegroShipping\Jobs\CreateAllegroShipmentJob;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Tests\TestCase;

class AllegroShippingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_contains_allegro_shipping_driver(): void
    {
        $this->assertInstanceOf(
            AllegroShippingDriver::class,
            app(CourierDriverRegistry::class)->driver(CourierAccount::PROVIDER_ALLEGRO_SHIPPING),
        );
    }

    public function test_account_client_secret_is_encrypted_and_settings_are_saved(): void
    {
        $payload = $this->accountPayload();
        $payload['default_weight'] = '0,2';

        $this->put(route('integrations.couriers.allegro-shipping.update'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account = CourierAccount::query()->firstOrFail();
        $this->assertSame('client-id', $account->organization_id);
        $this->assertSame('client-secret', $account->api_secret);
        $this->assertSame('A6', $account->setting('label_type'));
        $this->assertSame('customer_email', $account->setting('content_description_source'));
        $this->assertSame('external_id', $account->setting('reference_number_source'));
        $this->assertSame('0.2', $account->setting('default_weight'));
        $this->assertFalse($account->is_active);
        $this->assertNotSame('client-secret', $account->getRawOriginal('api_secret'));
    }

    public function test_device_flow_connects_account_and_stores_encrypted_tokens(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/oauth/device')) {
                return Http::response([
                    'user_code' => 'abc123xyz',
                    'device_code' => 'private-device-code',
                    'expires_in' => 3600,
                    'interval' => 5,
                    'verification_uri' => 'https://allegro.pl/skojarz-aplikacje',
                    'verification_uri_complete' => 'https://allegro.pl/skojarz-aplikacje?code=abc123xyz',
                ]);
            }

            return Http::response([
                'access_token' => 'device-access-token-long-enough',
                'refresh_token' => 'device-refresh-token-long-enough',
                'expires_in' => 43199,
                'scope' => 'allegro:api:shipments:read allegro:api:shipments:write',
            ]);
        });

        $this->post(route('integrations.couriers.allegro-shipping.device.start'), $this->accountPayload())
            ->assertRedirect()
            ->assertSessionHas('allegro_device_authorization.user_code', 'abc123xyz');

        $this->postJson(route('integrations.couriers.allegro-shipping.device.poll'))
            ->assertOk()
            ->assertJsonPath('status', 'connected');

        $account = CourierAccount::query()->firstOrFail();
        $this->assertSame('device-access-token-long-enough', $account->api_token);
        $this->assertSame('device-refresh-token-long-enough', $account->api_refresh_token);
        $this->assertTrue($account->is_active);
        $this->assertNotSame('device-access-token-long-enough', $account->getRawOriginal('api_token'));
        $this->assertDatabaseHas('integration_api_logs', [
            'integration' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'operation' => 'device_authorization_poll',
        ]);
        $this->assertStringNotContainsString(
            'device-access-token-long-enough',
            (string) \DB::table('integration_api_logs')->where('operation', 'device_authorization_poll')->value('response_payload'),
        );
    }

    public function test_form_uses_delivery_proposal_for_allegro_order(): void
    {
        $account = $this->account();
        $settings = $account->settings;
        $settings['parcel_templates'] = [[
            'id' => 'template-1', 'name' => 'Karton Allegro',
            'weight' => 2.5, 'length' => 30, 'width' => 20, 'height' => 10,
        ]];
        $settings['default_weight'] = 3.5;
        $settings['default_length'] = 41;
        $settings['default_width'] = 31;
        $settings['default_height'] = 21;
        $account->update(['settings' => $settings]);
        $order = $this->order();
        Http::fake(['*/shipment-management/delivery-proposals/*' => Http::response($this->proposal())]);

        $response = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
        ]));

        $response->assertOk()->assertJsonPath('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING);
        $this->assertStringContainsString('ADDITIONAL_HANDLING', $response->json('html'));
        $this->assertStringContainsString('parcels[0][weight]', $response->json('html'));
        $this->assertStringContainsString('name="package_type"', $response->json('html'));
        $this->assertStringContainsString('name="reference_number"', $response->json('html'));
        $this->assertStringContainsString('name="swap_sender_receiver"', $response->json('html'));
        $this->assertStringContainsString('Dokumenty', $response->json('html'));
        $this->assertStringContainsString('name="insurance_amount" value=""', $response->json('html'));
        $this->assertStringContainsString('name="parcels[0][weight]" value="3.5"', $response->json('html'));
        $this->assertStringContainsString('name="parcels[0][length]" value="41"', $response->json('html'));
        $this->assertStringContainsString('name="parcels[0][width]" value="31"', $response->json('html'));
        $this->assertStringContainsString('name="parcels[0][height]" value="21"', $response->json('html'));
        $this->assertStringContainsString('Karton Allegro', $response->json('html'));
        $this->assertStringContainsString('data-courier-parcel-template-select', $response->json('html'));
    }

    public function test_form_uses_configured_description_and_reference_sources(): void
    {
        $account = $this->account();
        $settings = $account->settings;
        $settings['content_description_source'] = 'customer_login';
        $settings['reference_number_source'] = 'external_id';
        $account->update(['settings' => $settings]);
        $order = $this->order(['customer_login' => 'kupujacy_allegro']);
        Http::fake(['*/shipment-management/delivery-proposals/*' => Http::response($this->proposal())]);

        $response = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('name="content_description" maxlength="100" value="kupujacy_allegro"', $response->json('html'));
        $this->assertStringContainsString('name="reference_number" maxlength="100" value="checkout-form-id"', $response->json('html'));
    }

    public function test_allegro_parcel_templates_can_be_created_updated_and_deleted(): void
    {
        $account = $this->account();

        $this->post(route('integrations.couriers.allegro-shipping.templates.store'), [
            'template_name' => 'Karton M',
            'template_weight' => 3,
            'template_length' => 40,
            'template_width' => 30,
            'template_height' => 20,
        ])->assertRedirect(route('integrations.couriers.allegro-shipping.edit'));

        $template = collect($account->fresh()->setting('parcel_templates'))->first();
        $this->assertSame('Karton M', $template['name']);

        $this->put(route('integrations.couriers.allegro-shipping.templates.update', $template['id']), [
            'template_name' => 'Karton L',
            'template_weight' => 5,
            'template_length' => 50,
            'template_width' => 40,
            'template_height' => 30,
        ])->assertRedirect(route('integrations.couriers.allegro-shipping.edit'));

        $this->assertSame('Karton L', collect($account->fresh()->setting('parcel_templates'))->first()['name']);

        $this->delete(route('integrations.couriers.allegro-shipping.templates.destroy', $template['id']))
            ->assertRedirect(route('integrations.couriers.allegro-shipping.edit'));

        $this->assertSame([], $account->fresh()->setting('parcel_templates'));
    }

    public function test_shipment_is_queued_created_and_label_can_be_downloaded(): void
    {
        Queue::fake();
        $this->account();
        $order = $this->order();
        Http::fake(['*/shipment-management/delivery-proposals/*' => Http::response($this->proposal())]);

        $this->post(route('orders.shipments.allegro-shipping.store', $order), [
            'label_format' => 'PDF',
            'content_description' => (string) $order->id,
            'reference_number' => 'REF-'.$order->id,
            'package_type' => 'DOX',
            'swap_sender_receiver' => '1',
            'cod_amount' => '110.00',
            'insurance_amount' => '100.00',
            'additional_services' => ['ADDITIONAL_HANDLING'],
            'parcels' => [['weight' => '0,2', 'length' => 30, 'width' => 20, 'height' => 10]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->with('parcels')->firstOrFail();
        $this->assertSame(CourierAccount::PROVIDER_ALLEGRO_SHIPPING, $shipment->provider);
        $this->assertSame('REF-'.$order->id, $shipment->reference_number);
        $this->assertTrue($shipment->swap_sender_receiver);
        $this->assertSame('DOX', $shipment->parcels->first()->package_type);
        $this->assertSame(0.2, (float) $shipment->parcels->first()->weight);
        Queue::assertPushed(CreateAllegroShipmentJob::class);

        Queue::fake();
        Http::fake(function ($request) use ($shipment) {
            if (str_contains($request->url(), 'delivery-proposals')) {
                return Http::response($this->proposal());
            }
            if (str_ends_with($request->url(), '/create-commands')) {
                return Http::response(['commandId' => $shipment->request_uuid], 202);
            }
            if (str_contains($request->url(), '/create-commands/')) {
                return Http::response(['commandId' => $shipment->request_uuid, 'status' => 'SUCCESS', 'shipmentId' => 'shipment-uuid']);
            }
            if (str_ends_with($request->url(), '/shipments/shipment-uuid')) {
                return Http::response([
                    'id' => 'shipment-uuid', 'carrier' => 'INPOST', 'labelFormat' => 'PDF',
                    'packages' => [['waybill' => '620000000000000001']],
                ]);
            }
            if (str_ends_with($request->url(), '/shipment-management/label')) {
                return Http::response('%PDF-ALLEGRO', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response([], 404);
        });

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(function ($request) use ($order): bool {
            if (! str_ends_with($request->url(), '/shipment-management/shipments/create-commands')) {
                return false;
            }

            $package = (array) data_get($request->data(), 'input.packages.0', []);

            return data_get($request->data(), 'input.referenceNumber') === 'REF-'.$order->id
                && data_get($package, 'type') === 'DOX'
                && data_get($request->data(), 'input.sender.company') === null
                && data_get($request->data(), 'input.receiver.company') === 'NEX'
                && ! array_key_exists('textOnLabel', $package);
        });

        $shipment->refresh();
        $this->assertSame('shipment-uuid', $shipment->external_id);
        $this->assertSame('620000000000000001', $shipment->tracking_number);
        $this->assertSame('INPOST', $shipment->carrier_code);
        $this->assertSame(Shipment::STATUS_CONFIRMED, $shipment->status);
        $this->assertSame(Shipment::OMS_STATUS_CREATED, $shipment->oms_status);
        $this->assertDatabaseHas('integration_api_logs', ['integration' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING, 'operation' => 'create_shipment']);

        $label = app(CourierDriverRegistry::class)->forShipment($shipment)->label($shipment);
        $this->assertSame('%PDF-ALLEGRO', $label->body());
    }

    public function test_manual_order_cannot_use_allegro_shipping(): void
    {
        $this->account();
        $order = $this->order(['source' => 'manual', 'external_id' => null]);

        $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
        ]))->assertUnprocessable();
    }

    public function test_allegro_carrier_shipment_uses_local_cancellation_fallback(): void
    {
        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'carrier_code' => 'ALLEGRO',
            'external_id' => 'shipment-uuid',
            'tracking_number' => 'A0054TEST1',
            'service' => Shipment::SERVICE_ALLEGRO_DELIVERY,
            'status' => 'pending',
            'oms_status' => Shipment::OMS_STATUS_CREATED,
            'sending_method' => 'allegro_order',
            'currency' => 'PLN',
            'label_format' => 'PDF',
            'label_type' => 'A6',
            'request_uuid' => '31d169f4-c3be-4ac4-a598-162bcb3ab2ad',
        ]);

        $this->assertFalse($shipment->canCancelViaCourier());
        $this->assertTrue($shipment->canCancelLocally());

        $this->post(route('shipments.cancel', $shipment), ['local_only' => '1'])
            ->assertRedirect();

        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
    }

    public function test_refresh_maps_allegro_tracking_status_and_exposes_tracking_link(): void
    {
        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'carrier_code' => 'ALLEGRO',
            'external_id' => 'shipment-uuid',
            'tracking_number' => 'A0054MRMR0',
            'service' => Shipment::SERVICE_ALLEGRO_DELIVERY,
            'status' => Shipment::STATUS_CONFIRMED,
            'oms_status' => Shipment::OMS_STATUS_PROBLEM,
            'sending_method' => 'allegro_order',
            'currency' => 'PLN',
            'label_format' => 'PDF',
            'label_type' => 'A6',
            'request_uuid' => 'ca3a7ec6-a4d4-4fb8-b1b3-ef6b00a8c758',
            'confirmed_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/shipment-management/shipments/shipment-uuid')) {
                return Http::response([
                    'id' => 'shipment-uuid',
                    'carrier' => 'ALLEGRO',
                    'packages' => [['waybill' => 'A0054MRMR0']],
                ]);
            }

            if (str_contains($request->url(), '/order/carriers/ALLEGRO/tracking')) {
                return Http::response([
                    'carrierId' => 'ALLEGRO',
                    'waybills' => [[
                        'waybill' => 'A0054MRMR0',
                        'trackingDetails' => [
                            'statuses' => [[
                                'occurredAt' => '2026-07-21T10:00:00Z',
                                'code' => 'PENDING',
                            ]],
                        ],
                    ]],
                ]);
            }

            return Http::response([], 404);
        });

        $refreshed = app(CourierDriverRegistry::class)->forShipment($shipment)->refresh($shipment);

        $this->assertSame('pending', $refreshed->status);
        $this->assertSame(Shipment::OMS_STATUS_CREATED, $refreshed->oms_status);
        $this->assertSame(
            'https://allegro.pl/allegrodelivery/sledzenie-paczki?numer=A0054MRMR0',
            $refreshed->trackingUrl(),
        );
        $this->assertDatabaseHas('integration_api_logs', [
            'shipment_id' => $shipment->id,
            'operation' => 'get_tracking',
        ]);
    }

    private function account(): CourierAccount
    {
        return CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            'name' => 'Wysylam z Allegro',
            'environment' => 'sandbox',
            'api_token' => 'access-token-long-enough',
            'api_secret' => 'client-secret',
            'api_refresh_token' => 'refresh-token-long-enough',
            'organization_id' => 'client-id',
            'settings' => ['label_format' => 'PDF', 'label_type' => 'A6', 'default_weight' => 1, 'default_length' => 25, 'default_width' => 20, 'default_height' => 10],
            'is_active' => true,
        ]);
    }

    private function accountPayload(): array
    {
        return [
            'name' => 'Wysylam z Allegro', 'environment' => 'sandbox', 'organization_id' => 'client-id',
            'api_secret' => 'client-secret', 'label_format' => 'PDF', 'label_type' => 'A6',
            'content_description_source' => 'customer_email', 'reference_number_source' => 'external_id',
            'default_weight' => 1, 'default_length' => 25, 'default_width' => 20, 'default_height' => 10, 'is_active' => 1,
        ];
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'allegro', 'external_id' => 'checkout-form-id', 'status' => Order::STATUS_NEW,
            'customer_email' => 'buyer+hash@allegromail.pl', 'customer_phone' => '+48 501 294 368',
            'shipping_name' => 'Anna Kowalska', 'shipping_street' => 'Testowa', 'shipping_building_number' => '12',
            'shipping_postal_code' => '00-001', 'shipping_city' => 'Warszawa', 'shipping_country_code' => 'PL',
            'currency' => 'PLN', 'total_gross' => 110, 'paid_amount' => 0, 'cash_on_delivery' => true,
        ], $overrides));
    }

    private function proposal(): array
    {
        $party = ['name' => 'Anna Kowalska', 'street' => 'Testowa 12', 'postalCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL', 'email' => 'buyer+hash@allegromail.pl', 'phone' => '501294368'];

        return [
            'orderId' => 'checkout-form-id',
            'suggestedInput' => [
                'sender' => $party + ['company' => 'NEX'], 'receiver' => $party,
                'packages' => [['type' => 'PACKAGE', 'weight' => ['value' => 2], 'length' => ['value' => 30], 'width' => ['value' => 20], 'height' => ['value' => 10]]],
                'insurance' => ['amount' => '100.00', 'currency' => 'PLN'],
                'cashOnDelivery' => ['amount' => '110.00', 'currency' => 'PLN'],
                'labelFormat' => 'PDF', 'additionalServices' => ['ADDITIONAL_HANDLING'], 'additionalProperties' => [],
            ],
            'deliveryOptions' => [
                ['deliveryType' => 'TO-DOOR', 'packageType' => 'PACKAGE', 'additionalServices' => [['id' => 'ADDITIONAL_HANDLING', 'name' => 'Przesylka niestandardowa']]],
                ['deliveryType' => 'TO-DOOR', 'packageType' => 'DOX', 'additionalServices' => []],
            ],
        ];
    }
}
