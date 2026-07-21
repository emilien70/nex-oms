<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\DPD\Drivers\DpdDriver;
use Modules\Integrations\DPD\Jobs\CreateDpdShipmentJob;
use Modules\Integrations\DPD\Jobs\RefreshDpdShipmentJob;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\CourierDriverRegistry;
use Tests\TestCase;

class DpdShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_contains_dpd_driver(): void
    {
        $this->assertInstanceOf(
            DpdDriver::class,
            app(CourierDriverRegistry::class)->driver(CourierAccount::PROVIDER_DPD),
        );
    }

    public function test_dpd_account_settings_are_saved(): void
    {
        $response = $this->put(route('integrations.couriers.dpd.update'), $this->accountPayload());

        $response->assertRedirect()->assertSessionHasNoErrors();

        $account = CourierAccount::query()->firstOrFail();
        $this->assertSame(CourierAccount::PROVIDER_DPD, $account->provider);
        $this->assertSame('dpd-login', $account->resolvedApiLogin());
        $this->assertSame('100001', $account->resolvedOrganizationId());
        $this->assertSame('NEXOMS', $account->resolvedInfoChannel());
        $this->assertSame(Shipment::SERVICE_DPD_DOMESTIC, $account->setting('default_service'));
        $this->assertTrue($account->is_active);
    }

    public function test_dpd_parcel_templates_can_be_created_updated_and_deleted(): void
    {
        $account = $this->account();

        $this->post(route('integrations.couriers.dpd.templates.store'), [
            'template_name' => 'Karton DPD',
            'template_weight' => '2.5',
            'template_length' => '40',
            'template_width' => '30',
            'template_height' => '20',
        ])->assertRedirect(route('integrations.couriers.dpd.edit'))->assertSessionHasNoErrors();

        $template = collect($account->fresh()->setting('parcel_templates'))->first();
        $this->assertSame('Karton DPD', $template['name']);
        $this->assertSame(2.5, $template['weight']);

        $this->put(route('integrations.couriers.dpd.templates.update', $template['id']), [
            'template_name' => 'Karton DPD XL',
            'template_weight' => '4',
            'template_length' => '60',
            'template_width' => '40',
            'template_height' => '30',
        ])->assertRedirect(route('integrations.couriers.dpd.edit'))->assertSessionHasNoErrors();

        $updatedTemplate = collect($account->fresh()->setting('parcel_templates'))->first();
        $this->assertSame('Karton DPD XL', $updatedTemplate['name']);
        $this->assertEquals(4.0, $updatedTemplate['weight']);

        $this->delete(route('integrations.couriers.dpd.templates.destroy', $template['id']))
            ->assertRedirect(route('integrations.couriers.dpd.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $account->fresh()->setting('parcel_templates'));
    }

    public function test_active_dpd_account_exposes_lazy_order_form(): void
    {
        $account = $this->account();
        $settings = $account->settings;
        $settings['parcel_templates'] = [[
            'id' => 'dpd-box',
            'name' => 'Karton DPD',
            'weight' => 2.5,
            'length' => 40,
            'width' => 30,
            'height' => 20,
        ]];
        $account->update(['settings' => $settings]);
        $order = $this->order();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('DPD')
            ->assertSee(route('orders.shipments.form', ['order' => $order, 'provider' => CourierAccount::PROVIDER_DPD]), false)
            ->assertDontSee('action="'.route('orders.shipments.dpd.store', $order).'"', false);

        $formResponse = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_DPD,
        ]));

        $formResponse
            ->assertOk()
            ->assertJsonPath('provider', CourierAccount::PROVIDER_DPD);
        $this->assertStringContainsString(route('orders.shipments.dpd.store', $order), $formResponse->json('html'));
        $this->assertStringContainsString('data-courier-shipment-form', $formResponse->json('html'));
        $this->assertStringContainsString('data-courier-parcel-template-select', $formResponse->json('html'));
        $this->assertStringContainsString('Karton DPD', $formResponse->json('html'));
    }

    public function test_dpd_shipment_is_queued_and_created_using_rest_api(): void
    {
        Queue::fake();
        $this->account();
        $order = $this->order();

        $this->post(route('orders.shipments.dpd.store', $order), [
            'shipment_provider' => CourierAccount::PROVIDER_DPD,
            'service' => Shipment::SERVICE_DPD_TIME_1200,
            'content_description' => (string) $order->id,
            'cod_amount' => '120.00',
            'insurance_amount' => '150.00',
            'additional_services' => [
                Shipment::ADDITIONAL_SERVICE_SATURDAY,
                Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS,
            ],
            'parcels' => [
                $this->parcel(),
                $this->parcel(['weight' => '2.25', 'length' => '30']),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->with('parcels')->firstOrFail();
        $this->assertSame(CourierAccount::PROVIDER_DPD, $shipment->provider);
        $this->assertCount(2, $shipment->parcels);
        Queue::assertPushed(CreateDpdShipmentJob::class);

        Http::fake([
            'https://dpdservicesdemo.dpd.com.pl/public/shipment/v1/generatePackagesNumbers' => Http::response([
                'status' => 'OK',
                'sessionId' => 771122,
                'packages' => [[
                    'parcels' => [
                        ['waybill' => '00000000000001'],
                        ['waybill' => '00000000000002'],
                    ],
                ]],
            ], 200),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(function ($request) use ($order): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->url() === 'https://dpdservicesdemo.dpd.com.pl/public/shipment/v1/generatePackagesNumbers'
                && $authorization === 'Basic '.base64_encode('dpd-login:dpd-password')
                && ($request->header('x-dpd-fid')[0] ?? null) === '100001'
                && data_get($request->data(), 'packages.0.ref1') === str_pad((string) $order->id, 3, '0', STR_PAD_LEFT)
                && data_get($request->data(), 'packages.0.receiver.address') === 'Klientowska 8/2'
                && data_get($request->data(), 'packages.0.parcels.1.weight') === 2.25
                && collect(data_get($request->data(), 'packages.0.services'))->contains(fn ($service) => data_get($service, 'code') === 'COD')
                && collect(data_get($request->data(), 'packages.0.services'))->contains(fn ($service) => data_get($service, 'code') === 'TIME1200');
        });

        $shipment->refresh()->load('parcels');
        $this->assertSame('771122', $shipment->external_id);
        $this->assertSame('00000000000001', $shipment->tracking_number);
        $this->assertSame('00000000000002', $shipment->parcels[1]->tracking_number);
        $this->assertDatabaseHas('integration_api_logs', [
            'integration' => CourierAccount::PROVIDER_DPD,
            'shipment_id' => $shipment->id,
            'operation' => 'create_shipment',
            'successful' => true,
        ]);
    }

    public function test_dpd_label_is_decoded_and_returned(): void
    {
        $shipment = $this->createdShipment();
        Http::fake([
            'https://dpdservicesdemo.dpd.com.pl/public/shipment/v1/generateSpedLabels' => Http::response([
                'status' => 'OK',
                'documentData' => base64_encode('%PDF-DPD-LABEL'),
            ]),
        ]);

        $response = app(CourierDriverRegistry::class)->forShipment($shipment)->label($shipment);

        $this->assertSame('%PDF-DPD-LABEL', $response->body());
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'labelSearchParams.session.packages.0.parcels.0.waybill') === $shipment->tracking_number
            && data_get($request->data(), 'format') === 'LBL_PRINTER');
    }

    public function test_dpd_status_is_refreshed_through_info_services(): void
    {
        $shipment = $this->createdShipment();
        Http::fake([
            'https://dpdinfoservices.dpd.com.pl/*' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                    <soap:Body><getEventsForWaybillV1Response><eventsList>
                        <businessCode>190101</businessCode>
                        <description>Doreczono</description>
                        <eventTime>2026-07-20T12:30:00+02:00</eventTime>
                        <waybill>00000000000001</waybill>
                    </eventsList></getEventsForWaybillV1Response></soap:Body>
                </soap:Envelope>
                XML, 200, ['Content-Type' => 'text/xml']),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->refresh($shipment);

        $shipment->refresh();
        $this->assertSame('190101', $shipment->status);
        $this->assertSame(Shipment::OMS_STATUS_DELIVERED, $shipment->oms_status);
        $this->assertNotNull($shipment->last_synced_at);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), '<waybill>00000000000001</waybill>')
            && str_contains($request->body(), '<channel>NEXOMS</channel>'));
    }

    public function test_dpd_registered_shipment_status_is_mapped_as_created(): void
    {
        $shipment = $this->createdShipment();
        Http::fake([
            'https://dpdinfoservices.dpd.com.pl/*' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                    <soap:Body><getEventsForWaybillV1Response><eventsList>
                        <businessCode>030103</businessCode>
                        <description>Zarejestrowano dane przesylki, przesylka jeszcze nie nadana</description>
                        <eventTime>2026-07-21T00:22:18.095</eventTime>
                        <waybill>00000000000001</waybill>
                    </eventsList></getEventsForWaybillV1Response></soap:Body>
                </soap:Envelope>
                XML, 200, ['Content-Type' => 'text/xml']),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->refresh($shipment);

        $shipment->refresh();
        $this->assertSame('030103', $shipment->status);
        $this->assertSame(Shipment::OMS_STATUS_CREATED, $shipment->oms_status);
        $this->assertNull($shipment->error_message);
    }

    public function test_dpd_panel_and_bulk_refresh_are_available(): void
    {
        Queue::fake();
        $shipment = $this->createdShipment();

        $this->get(route('integrations.couriers.dpd.edit'))
            ->assertOk()
            ->assertSee('Utworzone przesylki')
            ->assertSee($shipment->tracking_number)
            ->assertSee('class="inpost-panel nex-pagination-dropdown-host"', false)
            ->assertSee('class="nex-page-range dropdown-toggle"', false)
            ->assertSee('class="btn-group btn-group-sm nex-page-navigation"', false)
            ->assertSee('id="dpdAccountModal"', false)
            ->assertSee('Szablony wymiar&oacute;w i wag przesy&#322;ek', false)
            ->assertSee('id="dpdTemplateModal"', false);

        $this->post(route('integrations.couriers.dpd.shipments.refresh'), [
            'shipment_ids' => [$shipment->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        Queue::assertPushed(RefreshDpdShipmentJob::class);
    }

    private function account(): CourierAccount
    {
        return CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_DPD,
            'name' => 'DPD',
            'environment' => 'sandbox',
            'api_token' => 'dpd-password',
            'organization_id' => '100001',
            'settings' => [
                'api_login' => 'dpd-login',
                'info_channel' => 'NEXOMS',
                'default_service' => Shipment::SERVICE_DPD_DOMESTIC,
                'default_weight' => 1,
                'default_length' => 25,
                'default_width' => 20,
                'default_height' => 10,
                'label_format' => 'PDF',
                'label_type' => 'LABEL',
                'content_description_source' => 'order_id',
                'sender_company_name' => 'NEX Test',
                'sender_contact_name' => 'Jan Kowalski',
                'sender_street' => 'Nadawcza',
                'sender_building_number' => '12',
                'sender_postal_code' => '00-001',
                'sender_city' => 'Warszawa',
                'sender_country_code' => 'PL',
                'sender_phone' => '+48 501 294 368',
                'sender_email' => 'nadawca@example.test',
            ],
            'is_active' => true,
        ]);
    }

    private function accountPayload(): array
    {
        return [
            'name' => 'DPD', 'environment' => 'sandbox', 'api_login' => 'dpd-login',
            'api_token' => 'dpd-password', 'organization_id' => '100001', 'info_channel' => 'NEXOMS',
            'default_service' => Shipment::SERVICE_DPD_DOMESTIC, 'default_weight' => '1',
            'default_length' => '25', 'default_width' => '20', 'default_height' => '10',
            'default_insurance_amount' => '0', 'label_format' => 'PDF', 'label_type' => 'LABEL',
            'content_description_source' => 'order_id', 'default_saturday' => '1',
            'sender_company_name' => 'NEX Test', 'sender_contact_name' => 'Jan Kowalski',
            'sender_street' => 'Nadawcza', 'sender_building_number' => '12',
            'sender_postal_code' => '00-001', 'sender_city' => 'Warszawa',
            'sender_country_code' => 'PL', 'sender_phone' => '+48 501 294 368',
            'sender_email' => 'nadawca@example.test', 'is_active' => '1',
        ];
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual', 'status' => Order::STATUS_NEW,
            'customer_email' => 'anna@example.test', 'customer_phone' => '+48 501 294 368',
            'shipping_name' => 'Anna Kowalska', 'shipping_street' => 'Klientowska',
            'shipping_building_number' => '8', 'shipping_apartment_number' => '2',
            'shipping_postal_code' => '00-001', 'shipping_city' => 'Warszawa',
            'shipping_country_code' => 'PL', 'currency' => 'PLN', 'total_gross' => 120,
            'paid_amount' => 0, 'cash_on_delivery' => true, 'payment_status' => 'unpaid',
        ]);
    }

    private function parcel(array $overrides = []): array
    {
        return array_merge(['weight' => '1', 'length' => '25', 'width' => '20', 'height' => '10'], $overrides);
    }

    private function createdShipment(): Shipment
    {
        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_DPD,
            'service' => Shipment::SERVICE_DPD_DOMESTIC,
            'status' => '30103',
            'oms_status' => Shipment::OMS_STATUS_CREATED,
            'external_id' => '771122',
            'tracking_number' => '00000000000001',
            'currency' => 'PLN',
            'label_format' => 'PDF',
            'label_type' => 'LABEL',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->parcels()->create([
            'position' => 1, 'weight' => 1, 'length' => 25, 'width' => 20, 'height' => 10,
            'tracking_number' => '00000000000001',
        ]);

        return $shipment->fresh(['order', 'courierAccount', 'parcels']);
    }
}
