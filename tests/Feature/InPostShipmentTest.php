<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\InPost\Exceptions\InPostApiException;
use Modules\Integrations\InPost\Jobs\CancelInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\CreateInPostShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;
use Modules\Shipments\Services\CourierDriverRegistry;
use Modules\Shipments\Services\ShipmentCreationAttemptService;
use Modules\Shipments\Support\OrderReferenceFormatter;
use Tests\TestCase;

class InPostShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_is_added_to_the_integration_queue(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();

        $response = $this->post(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'cod_amount' => '120.00',
            'insurance_amount' => '500.00',
            'additional_services' => [
                Shipment::ADDITIONAL_SERVICE_WEEKEND,
                Shipment::ADDITIONAL_SERVICE_RETURN_LABEL,
            ],
        ]);

        $response->assertRedirect();

        $shipment = Shipment::query()->firstOrFail();
        $reference = OrderReferenceFormatter::format($order->id);

        $this->assertSame($account->id, $shipment->courier_account_id);
        $this->assertSame('WAW01M', $shipment->target_point_id);
        $this->assertSame('120.00', $shipment->cod_amount);
        $this->assertSame($reference, $shipment->content_description);
        $this->assertSame('dispatch_order', $shipment->sending_method);
        $this->assertSame(Shipment::SERVICE_INPOST_LOCKER_STANDARD, $shipment->service);
        $this->assertSame([
            Shipment::ADDITIONAL_SERVICE_WEEKEND,
            Shipment::ADDITIONAL_SERVICE_RETURN_LABEL,
        ], $shipment->additional_services);
        $this->assertNotNull($shipment->status_changed_at);
        Queue::assertPushed(CreateInPostShipmentJob::class, fn ($job): bool => $job->shipment->is($shipment));
    }

    public function test_allegro_order_uses_allegro_service_when_service_is_not_selected(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order(['source' => 'allegro']);

        $this->post(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            Shipment::SERVICE_INPOST_LOCKER_ALLEGRO,
            Shipment::query()->firstOrFail()->service,
        );
    }

    public function test_explicit_service_overrides_the_order_source_default(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order(['source' => 'allegro']);

        $this->post(route('orders.shipments.inpost.store', $order), [
            'service' => Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            Shipment::query()->firstOrFail()->service,
        );
    }

    public function test_allegro_locker_service_is_saved_and_sent_to_shipx(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order();

        $this->post(route('orders.shipments.inpost.store', $order), [
            'service' => Shipment::SERVICE_INPOST_LOCKER_ALLEGRO,
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->firstOrFail();

        $this->assertSame(Shipment::SERVICE_INPOST_LOCKER_ALLEGRO, $shipment->service);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 559,
                'status' => 'confirmed',
                'tracking_number' => '123456789012345678901239',
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(fn ($request): bool => $request['service'] === Shipment::SERVICE_INPOST_LOCKER_ALLEGRO
            && ! isset($request['end_of_week_collection']));
    }

    public function test_order_view_shows_only_enabled_courier_accounts(): void
    {
        $this->account();
        $order = $this->order();

        CourierAccount::query()->create([
            'provider' => 'dpd',
            'name' => 'DPD PL',
            'environment' => 'production',
            'settings' => [],
            'is_active' => false,
        ]);

        $response = $this->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('data-inpost-courier-button', false);
        $response->assertSee('InPost Paczkomaty');
        $response->assertSee('data-courier-form-host', false);
        $response->assertSee('data-courier-form-url=', false);
        $response->assertDontSee('action="'.route('orders.shipments.inpost.store', $order).'"', false);
        $response->assertDontSee('name="sending_method"', false);
        $response->assertDontSee('DPD PL');
        $response->assertDontSee('Allegro.pl');
        $response->assertDontSee('Apaczka');

        $formResponse = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]))->assertOk();

        $formHtml = (string) $formResponse->json('html');
        $this->assertStringContainsString('data-ajax-shipment-form', $formHtml);
        $this->assertStringContainsString('name="sending_method"', $formHtml);
        $this->assertStringContainsString('value="parcel_locker"', $formHtml);
        $this->assertStringContainsString('value="dispatch_order"', $formHtml);
        $this->assertStringContainsString('Paczka w Weekend', $formHtml);
        $this->assertStringContainsString('Etykieta zwrotna', $formHtml);
    }

    public function test_allegro_order_selects_allegro_locker_service_by_default(): void
    {
        $this->account();
        $allegroOrder = $this->order(['source' => 'allegro']);
        $manualOrder = $this->order();

        $this->getJson(route('orders.shipments.form', [
            'order' => $allegroOrder,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]))
            ->assertOk()
            ->assertJsonPath('fields.service', Shipment::SERVICE_INPOST_LOCKER_ALLEGRO);

        $this->getJson(route('orders.shipments.form', [
            'order' => $manualOrder,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]))
            ->assertOk()
            ->assertJsonPath('fields.service', Shipment::SERVICE_INPOST_LOCKER_STANDARD);
    }

    public function test_internal_order_id_is_used_as_the_content_description_and_courier_reference(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $reference = OrderReferenceFormatter::format($order->id);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('name="content_description"', false)
            ->assertDontSee('Odbiorca:');

        $formResponse = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]))->assertOk();

        $this->assertStringContainsString('name="content_description"', (string) $formResponse->json('html'));
        $this->assertStringContainsString('value="'.$reference.'"', (string) $formResponse->json('html'));

        $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]))
            ->assertOk()
            ->assertJsonPath('fields.content_description', $reference)
            ->assertJsonMissingPath('fields.receiver');

        $this->post(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
        ])->assertRedirect();

        $shipment = Shipment::query()->firstOrFail();

        $this->assertSame($reference, $shipment->content_description);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 557,
                'status' => 'confirmed',
                'tracking_number' => '123456789012345678901236',
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(fn ($request): bool => $request['comments'] === $reference
            && $request['reference'] === $reference);
    }

    public function test_content_description_can_be_edited_before_creating_a_shipment(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order();
        $reference = OrderReferenceFormatter::format($order->id);

        $this->post(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'content_description' => 'Dekoder i pilot',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->firstOrFail();

        $this->assertSame('Dekoder i pilot', $shipment->content_description);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 558,
                'status' => 'confirmed',
                'tracking_number' => '123456789012345678901237',
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(fn ($request): bool => $request['comments'] === 'Dekoder i pilot'
            && $request['reference'] === $reference);
    }

    public function test_ajax_creation_exposes_shipment_status_until_label_is_available(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order();

        $response = $this->postJson(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'content_description' => OrderReferenceFormatter::format($order->id),
        ]);

        $shipment = Shipment::query()->firstOrFail();
        $attempt = ShipmentCreationAttempt::query()->firstOrFail();

        $response
            ->assertAccepted()
            ->assertJsonPath('id', $attempt->id)
            ->assertJsonPath('status', ShipmentCreationAttempt::STATUS_PROCESSING)
            ->assertJsonPath('provider_status', Shipment::STATUS_QUEUED)
            ->assertJsonPath('label_available', false)
            ->assertJsonPath('polling_finished', false)
            ->assertJsonPath('status_url', route('shipment-creation-attempts.status', $attempt))
            ->assertJsonPath('row_html', null);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'tracking_number' => null,
        ]);
        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('data-shipment-id="'.$shipment->id.'"', false);

        $shipment->update([
            'external_id' => '558',
            'tracking_number' => '123456789012345678901238',
            'status' => Shipment::STATUS_CONFIRMED,
        ]);
        app(ShipmentCreationAttemptService::class)->succeed($shipment->fresh());

        $statusResponse = $this->getJson(route('shipment-creation-attempts.status', $attempt));

        $statusResponse
            ->assertOk()
            ->assertJsonPath('tracking_number', '123456789012345678901238')
            ->assertJsonPath('label_available', true)
            ->assertJsonPath('polling_finished', true);

        $this->assertStringContainsString('123456789012345678901238', $statusResponse->json('row_html'));
        $this->assertStringContainsString('Etykieta', $statusResponse->json('row_html'));
    }

    public function test_sending_method_can_be_selected_when_creating_an_inpost_shipment(): void
    {
        Queue::fake();

        $account = $this->account();
        $account->update(['settings' => array_merge($account->settings, [
            'sending_method' => 'dispatch_order',
            'sender_point_id' => 'PXS01M',
        ])]);
        $order = $this->order();

        $response = $this->post(route('orders.shipments.inpost.store', $order), [
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'parcel_locker',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $shipment = Shipment::query()->firstOrFail();

        $this->assertSame('parcel_locker', $shipment->sending_method);
        $this->assertSame('PXS01M', $shipment->dropoff_point_id);
    }

    public function test_order_shipment_form_uses_the_courier_account_sending_method_as_default(): void
    {
        $account = $this->account();
        $account->update(['settings' => array_merge($account->settings, [
            'sending_method' => 'parcel_locker',
            'sender_point_id' => 'PXS01M',
        ])]);

        $order = $this->order();
        $response = $this->getJson(route('orders.shipments.form', [
            'order' => $order,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
        ]));

        $response->assertOk();
        $formHtml = (string) $response->json('html');
        $this->assertStringContainsString(
            '<option value="parcel_locker" selected>Nadanie w paczkomacie</option>',
            $formHtml,
        );
        $this->assertStringContainsString(
            '<option value="dispatch_order" >Odbi&oacute;r przez kuriera</option>',
            $formHtml,
        );
    }

    public function test_enabled_courier_with_connection_error_is_still_visible_in_the_order_view(): void
    {
        $account = $this->account();
        $account->update(['last_error' => 'Blad konfiguracji']);
        $order = $this->order();

        $response = $this->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('data-inpost-courier-button', false);
        $response->assertSee('InPost Paczkomaty');
    }

    public function test_disabled_courier_account_is_hidden_from_the_order_view(): void
    {
        $account = $this->account();
        $account->update(['is_active' => false]);
        $order = $this->order();

        $response = $this->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertDontSee('aria-label="Aktywne integracje kurierskie"', false);
        $response->assertSee('Skonfiguruj i aktywuj Integracj', false);
    }

    public function test_inpost_tracking_number_links_to_the_courier_tracking_page(): void
    {
        $account = $this->account();
        $order = $this->order();

        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'tracking_number' => '620999680606230435548821',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CONFIRMED,
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $shipment->events()->create([
            'event_type' => 'shipment_status_changed',
            'status' => Shipment::STATUS_CONFIRMED,
            'payload' => [
                'old_status' => Shipment::STATUS_QUEUED,
                'new_status' => Shipment::STATUS_CONFIRMED,
            ],
            'occurred_at' => now(),
        ]);

        $response = $this->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee(
            'href="https://inpost.pl/sledzenie-przesylek?number=620999680606230435548821"',
            false,
        );
        $response->assertSee(
            'href="'.route('integrations.couriers.inpost-lockers.edit').'"',
            false,
        );
        $response->assertSee('target="_blank"', false);
        $response->assertDontSee('Historia (1)');
        $response->assertDontSee('Status: Oczekuje na wyslanie -&gt; Utworzona', false);
    }

    public function test_inpost_panel_filters_shipments_and_contains_single_account_editor(): void
    {
        $account = $this->account();
        $order = $this->order();

        foreach ([
            ['status' => Shipment::STATUS_CONFIRMED, 'tracking_number' => '620000000000000000000001'],
            ['status' => Shipment::STATUS_ERROR, 'tracking_number' => '620000000000000000000002'],
        ] as $data) {
            $order->shipments()->create([
                'courier_account_id' => $account->id,
                'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
                'service' => 'inpost_locker_standard',
                'parcel_template' => 'medium',
                'status' => $data['status'],
                'status_changed_at' => now(),
                'tracking_number' => $data['tracking_number'],
                'sending_method' => 'dispatch_order',
                'currency' => 'PLN',
                'request_uuid' => (string) Str::uuid(),
            ]);
        }

        $response = $this->get(route('integrations.couriers.inpost-lockers.edit', [
            'status' => Shipment::OMS_STATUS_CREATED,
        ]));

        $response->assertOk();
        $response->assertSee('620000000000000000000001');
        $response->assertDontSee('620000000000000000000002');
        $response->assertSee('Opis zawarto&#347;ci: Numer zamówienia', false);
        $response->assertDontSee('Numer zam&amp;oacute;wienia', false);
        $response->assertSee('id="inpostAccountModal"', false);
        $response->assertSee('class="nex-pagination-toolbar"', false);
        $response->assertSee('class="nex-page-range dropdown-toggle"', false);
        $response->assertSee('class="nex-pagination-total"', false);
        $response->assertSee('class="btn-group btn-group-sm nex-page-navigation"', false);
        $response->assertDontSee('Przesy&#322;ek na stron&#281;', false);
        $response->assertDontSee('Dodaj nowe konto');
    }

    public function test_inpost_panel_shows_shipment_details_instead_of_linking_details_to_the_order(): void
    {
        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'small',
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => '503',
            'tracking_number' => '620000000000000000000003',
            'sending_method' => 'dispatch_order',
            'content_description' => 'Wartosc zapasowa',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        IntegrationApiLog::query()->create([
            'integration' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'operation' => 'create_shipment',
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'request_id' => (string) Str::uuid(),
            'method' => 'POST',
            'url' => 'https://example.test/v1/shipments',
            'request_payload' => [
                'receiver' => ['email' => 'nieaktualny@example.test'],
            ],
            'successful' => true,
        ]);

        IntegrationApiLog::query()->create([
            'integration' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'operation' => 'create_shipment',
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'request_id' => (string) Str::uuid(),
            'method' => 'POST',
            'url' => 'https://example.test/v1/shipments',
            'request_payload' => [
                'receiver' => [
                    'phone' => '501294368',
                    'email' => 'odbiorca@example.test',
                ],
                'parcels' => ['template' => 'medium'],
                'comments' => 'Dekoder i pilot',
                'custom_attributes' => ['sending_method' => 'parcel_locker'],
            ],
            'successful' => true,
        ]);

        $response = $this->get(route('integrations.couriers.inpost-lockers.edit'));

        $response->assertOk();
        $response->assertSee('id="shipmentDetailsModal"', false);
        $response->assertSee('data-bs-target="#shipmentDetailsModal"', false);
        $response->assertSee('data-shipment-parcel="Gabaryt B (19 x 38 x 64 cm)"', false);
        $response->assertSee('data-shipment-phone="+48 501 294 368"', false);
        $response->assertSee('data-shipment-email="odbiorca@example.test"', false);
        $response->assertDontSee('nieaktualny@example.test');
        $response->assertSee('data-shipment-content="Dekoder i pilot"', false);
        $response->assertSee('data-shipment-sending-method="Nadanie w paczkomacie"', false);
        $response->assertSee('Etykieta');
        $response->assertDontSee('aria-label="Operacje na przesy&#322;ce"', false);
        $response->assertDontSee('Pon&oacute;w nadanie', false);
        $response->assertDontSee('Od&#347;wie&#380; status', false);
        $response->assertDontSee('Usu&#324; przesy&#322;k&#281;', false);
        $response->assertDontSee('Przejd&#378; do zam&oacute;wienia', false);
    }

    public function test_selected_shipment_without_courier_cancellation_support_is_deleted_locally(): void
    {
        $account = $this->account();
        $order = $this->order();
        $selectedShipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'small',
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => 'bulk-delete-1',
            'tracking_number' => '620000000000000000000011',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);
        $remainingShipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CONFIRMED,
            'external_id' => 'bulk-delete-2',
            'tracking_number' => '620000000000000000000012',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $response = $this->post(route('integrations.couriers.inpost-lockers.shipments.delete'), [
            'shipment_ids' => [$selectedShipment->id],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('shipments', ['id' => $selectedShipment->id]);
        $this->assertDatabaseHas('shipments', ['id' => $remainingShipment->id]);
    }

    public function test_selected_shipment_is_cancelled_at_courier_before_deletion(): void
    {
        Queue::fake();

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'small',
            'status' => Shipment::STATUS_CREATED,
            'external_id' => 'bulk-cancel-1',
            'tracking_number' => '620000000000000000000013',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $response = $this->post(route('integrations.couriers.inpost-lockers.shipments.delete'), [
            'shipment_ids' => [$shipment->id],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id]);
        Queue::assertPushed(CancelInPostShipmentJob::class, fn ($job): bool => $job->shipment->is($shipment));
    }

    public function test_shipx_payload_is_mapped_and_tracking_number_is_saved(): void
    {
        Queue::fake();
        Event::fake([ShipmentCreated::class, ShipmentStatusChanged::class]);

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'cod_amount' => 120,
            'insurance_amount' => 500,
            'additional_services' => [Shipment::ADDITIONAL_SERVICE_WEEKEND],
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 555,
                'status' => 'confirmed',
                'tracking_number' => '123456789012345678901234',
                'updated_at' => '2026-07-15T10:15:30Z',
                'parcels' => [[
                    'template' => 'medium',
                    'tracking_number' => '123456789012345678901234',
                ]],
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        $shipment->refresh();

        $this->assertSame('555', $shipment->external_id);
        $this->assertSame('confirmed', $shipment->status);
        $this->assertSame(Shipment::OMS_STATUS_CREATED, $shipment->oms_status);
        $this->assertSame('123456789012345678901234', $shipment->tracking_number);
        $this->assertNotNull($shipment->confirmed_at);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'shipment_created',
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_status_changed',
            'status' => Shipment::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->id,
            'event_type' => 'shipment_status_changed',
        ]);
        $this->assertSame(1, IntegrationApiLog::query()->where('operation', 'create_shipment')->count());
        Event::assertDispatched(ShipmentCreated::class, fn (ShipmentCreated $event): bool => $event->shipment->is($shipment)
            && $event->name() === 'shipment.created'
            && $event->payload()['order_id'] === $order->id
        );
        Event::assertNotDispatched(ShipmentStatusChanged::class);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox-api-shipx-pl.easypack24.net/v1/organizations/12345/shipments'
                && $request->hasHeader('X-Request-ID')
                && $request['service'] === 'inpost_locker_standard'
                && $request['parcels']['template'] === 'medium'
                && $request['custom_attributes']['target_point'] === 'WAW01M'
                && $request['receiver']['email'] === 'anna@example.test'
                && $request['receiver']['phone'] === '501294368'
                && $request['cod']['amount'] === 120.0
                && $request['end_of_week_collection'] === true;
        });
    }

    public function test_weekend_service_is_rejected_for_allegro_locker_service(): void
    {
        Queue::fake();

        $this->account();
        $order = $this->order(['source' => 'allegro']);

        $this->post(route('orders.shipments.inpost.store', $order), [
            'service' => Shipment::SERVICE_INPOST_LOCKER_ALLEGRO,
            'parcel_template' => 'medium',
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'additional_services' => [Shipment::ADDITIONAL_SERVICE_WEEKEND],
        ])->assertRedirect()->assertSessionHasErrors('shipment');

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_refresh_maps_inpost_status_to_oms_workflow_status(): void
    {
        Event::fake([ShipmentStatusChanged::class]);

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => '556',
            'tracking_number' => '123456789012345678901235',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CONFIRMED,
            'status_changed_at' => now()->subHour(),
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        Http::fake([
            '*/v1/shipments/556' => Http::response([
                'id' => 556,
                'status' => 'ready_to_pickup',
                'tracking_number' => '123456789012345678901235',
                'updated_at' => '2026-07-16T10:15:30Z',
                'parcels' => [[
                    'template' => 'medium',
                    'tracking_number' => '123456789012345678901235',
                ]],
            ]),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->refresh($shipment);

        $shipment->refresh();

        $this->assertSame('ready_to_pickup', $shipment->status);
        $this->assertSame(Shipment::OMS_STATUS_READY_FOR_PICKUP, $shipment->oms_status);
        $this->assertSame('Oczekuje w punkcie', $shipment->statusLabel());
        $this->assertSame(90, $shipment->omsStatusProgress());
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'shipment_status_changed',
        ]);
        Event::assertDispatched(ShipmentStatusChanged::class, function (ShipmentStatusChanged $event) use ($shipment): bool {
            return $event->shipment->is($shipment)
                && $event->oldStatus === Shipment::OMS_STATUS_CREATED
                && $event->newStatus === Shipment::OMS_STATUS_READY_FOR_PICKUP
                && $event->oldProviderStatus === Shipment::STATUS_CONFIRMED
                && $event->newProviderStatus === 'ready_to_pickup';
        });
    }

    public function test_delivered_shipment_is_not_refreshed_again(): void
    {
        Queue::fake();
        Http::fake();

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => 'delivered-123',
            'tracking_number' => '620000000000000000000123',
            'service' => Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            'parcel_template' => 'medium',
            'status' => 'delivered',
            'oms_status' => Shipment::OMS_STATUS_DELIVERED,
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $driver = app(CourierDriverRegistry::class)->forShipment($shipment);
        $driver->dispatchRefresh($shipment);
        $driver->refresh($shipment);

        Queue::assertNotPushed(RefreshInPostShipmentJob::class);
        Http::assertNothingSent();
    }

    public function test_sender_description_and_dropoff_point_are_sent_to_shipx(): void
    {
        $account = $this->account();
        $account->update(['settings' => array_merge($account->settings, [
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
        ])]);
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'dropoff_point_id' => 'PXS01M',
            'sending_method' => 'parcel_locker',
            'content_description' => 'NEX-100',
            'currency' => 'PLN',
            'request_uuid' => (string) Str::uuid(),
        ]);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'id' => 556,
                'status' => 'confirmed',
                'tracking_number' => '123456789012345678901235',
            ], 201),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);

        Http::assertSent(fn ($request): bool => $request['comments'] === 'NEX-100'
            && $request['custom_attributes']['sending_method'] === 'parcel_locker'
            && $request['custom_attributes']['dropoff_point'] === 'PXS01M'
            && $request['sender']['company_name'] === 'NEX Test'
            && $request['sender']['first_name'] === 'Jan'
            && $request['sender']['last_name'] === 'Kowalski'
            && $request['sender']['phone'] === '501294368'
            && $request['sender']['address']['building_number'] === '12/4'
        );
    }

    public function test_shipx_validation_details_are_included_in_the_error_message(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'small',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        Http::fake([
            '*/v1/organizations/12345/shipments' => Http::response([
                'status' => 400,
                'error' => 'validation_failed',
                'message' => 'Wystapily bledy podczas walidacji.',
                'details' => [
                    'receiver' => [[
                        'phone' => ['Nieprawidlowy'],
                    ]],
                ],
            ], 400),
        ]);

        $this->expectExceptionMessage('Telefon odbiorcy: Nieprawidlowy.');

        app(CourierDriverRegistry::class)->forShipment($shipment)->create($shipment);
    }

    public function test_known_creation_failure_requires_verification_before_retry(): void
    {
        Queue::fake();

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CREATION_FAILED,
            'status_changed_at' => now(),
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
            'error_message' => 'Nieprawidlowe dane odbiorcy.',
        ]);

        $this->post(route('shipments.retry', $shipment))
            ->assertRedirect()
            ->assertSessionHasErrors('shipment');

        $shipment->refresh();

        $this->assertSame(Shipment::STATUS_CREATION_FAILED, $shipment->status);
        $this->assertSame('Nieprawidlowe dane odbiorcy.', $shipment->error_message);
        $this->assertDatabaseMissing('shipment_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_retry_queued',
        ]);
        Queue::assertNotPushed(CreateInPostShipmentJob::class);
    }

    public function test_unknown_creation_outcome_cannot_be_retried(): void
    {
        Queue::fake();

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CREATION_UNKNOWN,
            'status_changed_at' => now(),
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
            'error_message' => 'Timeout API.',
        ]);

        $this->post(route('shipments.retry', $shipment))
            ->assertRedirect()
            ->assertSessionHasErrors('shipment');

        $this->assertSame(Shipment::STATUS_CREATION_UNKNOWN, $shipment->fresh()->status);
        Queue::assertNotPushed(CreateInPostShipmentJob::class);
    }

    public function test_job_marks_api_validation_error_as_known_creation_failure(): void
    {
        Event::fake([ShipmentCreationFailed::class]);

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        (new CreateInPostShipmentJob($shipment))->failed(new InPostApiException('Blad walidacji.', 422));

        $attempt = ShipmentCreationAttempt::query()->firstOrFail();
        $this->assertSame(ShipmentCreationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        Event::assertDispatched(ShipmentCreationFailed::class, fn (ShipmentCreationFailed $event): bool => ! $event->outcomeUnknown);
    }

    public function test_job_marks_network_error_as_unknown_outcome(): void
    {
        Event::fake([ShipmentCreationFailed::class]);

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        (new CreateInPostShipmentJob($shipment))->failed(new InPostApiException('Timeout API.'));

        $this->assertSame(Shipment::STATUS_CREATION_UNKNOWN, $shipment->fresh()->status);
        Event::assertDispatched(ShipmentCreationFailed::class, fn (ShipmentCreationFailed $event): bool => $event->outcomeUnknown);
    }

    public function test_job_marks_local_php_error_as_known_creation_failure(): void
    {
        Event::fake([ShipmentCreationFailed::class]);

        $account = $this->account();
        $shipment = $this->order()->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        (new CreateInPostShipmentJob($shipment))->failed(new \Error('Lokalny blad kodu.'));

        $attempt = ShipmentCreationAttempt::query()->firstOrFail();
        $this->assertSame(ShipmentCreationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('Lokalny blad kodu.', $attempt->error_message);
        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        Event::assertDispatched(ShipmentCreationFailed::class, fn (ShipmentCreationFailed $event): bool => ! $event->outcomeUnknown);
    }

    public function test_label_is_streamed_from_inpost_without_saving_binary_in_database(): void
    {
        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => '555',
            'tracking_number' => '123456789012345678901234',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CONFIRMED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        Http::fake([
            '*/v1/shipments/555/label*' => Http::response('%PDF-test-label', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $response = $this->get(route('shipments.label', $shipment));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-test-label', $response->getContent());
    }

    public function test_cancellation_is_queued_for_the_courier_integration(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => '555',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CREATED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $response = $this->post(route('shipments.cancel', $shipment));

        $response->assertRedirect();
        Queue::assertPushed(CancelInPostShipmentJob::class, fn ($job): bool => $job->shipment->is($shipment));
    }

    public function test_queued_shipment_can_be_cancelled_before_it_reaches_the_courier(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_QUEUED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $response = $this->post(route('shipments.cancel', $shipment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        Queue::assertNotPushed(CancelInPostShipmentJob::class);
    }

    public function test_confirmed_shipment_is_removed_from_oms_when_courier_api_does_not_allow_cancellation(): void
    {
        Queue::fake();

        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => '555',
            'tracking_number' => '123456789012345678901234',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CONFIRMED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $shipmentEvent = $shipment->events()->create([
            'event_type' => 'shipment_created',
            'status' => Shipment::STATUS_CONFIRMED,
            'occurred_at' => now(),
        ]);
        $orderEvent = $order->events()->create([
            'event_type' => 'shipment_created',
            'title' => 'Przesylka utworzona',
            'payload' => ['shipment_id' => $shipment->id],
        ]);
        $apiLog = IntegrationApiLog::query()->create([
            'integration' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'operation' => 'get_shipment',
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'request_id' => (string) Str::uuid(),
            'method' => 'GET',
            'url' => 'https://example.test/v1/shipments/555',
            'successful' => true,
        ]);

        $response = $this->post(route('shipments.cancel', $shipment), [
            'local_only' => '1',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        $this->assertDatabaseMissing('shipment_events', ['id' => $shipmentEvent->id]);
        $this->assertDatabaseMissing('integration_api_logs', ['id' => $apiLog->id]);
        $this->assertDatabaseMissing('order_events', ['id' => $orderEvent->id]);
        Queue::assertNotPushed(CancelInPostShipmentJob::class);
    }

    public function test_successful_courier_cancellation_removes_shipment_and_its_traces_from_oms(): void
    {
        $account = $this->account();
        $order = $this->order();
        $shipment = $order->shipments()->create([
            'courier_account_id' => $account->id,
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'external_id' => '555',
            'service' => 'inpost_locker_standard',
            'parcel_template' => 'medium',
            'status' => Shipment::STATUS_CREATED,
            'target_point_id' => 'WAW01M',
            'sending_method' => 'dispatch_order',
            'currency' => 'PLN',
            'label_format' => 'Pdf',
            'label_type' => 'A6',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $orderEvent = $order->events()->create([
            'event_type' => 'shipment_created',
            'title' => 'Przesylka utworzona',
            'payload' => ['shipment_id' => $shipment->id],
        ]);

        Http::fake([
            '*/v1/shipments/555' => Http::response(null, 204),
        ]);

        app(CourierDriverRegistry::class)->forShipment($shipment)->cancel($shipment);

        $this->assertDatabaseMissing('shipments', ['id' => $shipment->id]);
        $this->assertDatabaseMissing('integration_api_logs', ['shipment_id' => $shipment->id]);
        $this->assertDatabaseMissing('order_events', ['id' => $orderEvent->id]);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://sandbox-api-shipx-pl.easypack24.net/v1/shipments/555');
    }

    private function account(): CourierAccount
    {
        return CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'name' => 'InPost Paczkomaty',
            'environment' => 'sandbox',
            'api_token' => 'test-secret-token-123',
            'organization_id' => '12345',
            'settings' => [
                'default_parcel_template' => 'medium',
                'label_format' => 'Pdf',
                'label_type' => 'A6',
            ],
            'is_active' => true,
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'customer_email' => 'anna@example.test',
            'customer_phone' => '+48 501 294 368',
            'shipping_name' => 'Anna Kowalska',
            'currency' => 'PLN',
            'total_gross' => 120,
            'paid_amount' => 0,
            'cash_on_delivery' => true,
            'payment_status' => 'unpaid',
        ], $overrides));
    }
}
