<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Integrations\DPD\Jobs\CreateDpdShipmentJob;
use Modules\Integrations\InPost\Jobs\CreateInPostShipmentJob;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Tests\TestCase;

class AutomaticActionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_actions_page_is_available_from_orders_menu(): void
    {
        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('Automatyczne akcje')
            ->assertSee(route('orders.automatic-actions.index'), false);

        $this->get(route('orders.automatic-actions.create'))
            ->assertRedirect(route('orders.automatic-actions.index'));
    }

    public function test_refunded_payment_state_is_not_available_for_automation_rules(): void
    {
        $options = app(AutomationCatalog::class)->conditionDefinitions()['payment_state']['options'];

        $this->assertSame(['unpaid', 'partial', 'paid'], array_keys($options));
        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertDontSee('value="refunded"', false);
    }

    public function test_rule_can_be_created_with_conditions_and_ordered_actions(): void
    {
        $response = $this->post(route('orders.automatic-actions.store'), [
            'name' => '',
            'description' => 'Testowa regula',
            'trigger' => 'shipment.created',
            'is_active' => '1',
            'conditions' => [
                ['field' => 'source', 'operator' => 'equals', 'value' => 'manual'],
            ],
            'actions' => [
                [
                    'type' => AutomationCatalog::ACTION_DELAY,
                    'configuration' => ['minutes' => '5'],
                    'stop_on_error' => '1',
                ],
                [
                    'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                    'configuration' => ['status' => Order::STATUS_SHIPPED],
                    'stop_on_error' => '1',
                ],
            ],
        ]);

        $rule = AutomationRule::query()->with('actions')->firstOrFail();

        $response->assertRedirect(route('orders.automatic-actions.index', ['edit' => $rule->id]));
        $this->assertNotSame('', $rule->name);
        $this->assertSame('shipment.created', $rule->trigger);
        $this->assertSame('source', $rule->conditions[0]['field']);
        $this->assertSame([
            AutomationCatalog::ACTION_DELAY,
            AutomationCatalog::ACTION_CHANGE_STATUS,
        ], $rule->actions->pluck('action_type')->all());

        $this->get(route('orders.automatic-actions.index', ['edit' => $rule->id]))
            ->assertOk()
            ->assertSee('data-automation-editor-row="'.$rule->id.'"', false)
            ->assertSee('data-automation-editor', false)
            ->assertSee('ID: '.$rule->id)
            ->assertSee(route('orders.automatic-actions.update', $rule), false)
            ->assertDontSee('name="group_name"', false);
    }

    public function test_new_action_draft_exists_only_in_the_view_until_it_is_saved(): void
    {
        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('id="automation-rule-draft"', false)
            ->assertSee('data-is-draft="1"', false)
            ->assertSee('ID: zostanie nadane po zapisie')
            ->assertSee(route('orders.automatic-actions.store'), false);

        $this->assertDatabaseCount('automation_rules', 0);

        $response = $this->postJson(route('orders.automatic-actions.store'), [
            'name' => '',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => '0',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_PENDING],
                'stop_on_error' => '1',
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('redirect_url', route('orders.automatic-actions.index'));

        $rule = AutomationRule::query()->with('actions')->firstOrFail();
        $this->assertFalse($rule->is_active);
        $this->assertCount(1, $rule->actions);
        $this->assertSame(AutomationCatalog::ACTION_CHANGE_STATUS, $rule->actions->first()->action_type);
    }

    public function test_ksef_invoice_accepted_event_can_be_selected_and_saved(): void
    {
        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('value="ksef.invoice_accepted"', false)
            ->assertSee('Faktura zaakceptowana w KSeF');

        $this->post(route('orders.automatic-actions.store'), [
            'name' => 'Po akceptacji Faktury w KSeF',
            'description' => '',
            'trigger' => 'ksef.invoice_accepted',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_SHIPPED],
                'stop_on_error' => '1',
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('automation_rules', [
            'name' => 'Po akceptacji Faktury w KSeF',
            'trigger' => 'ksef.invoice_accepted',
            'is_active' => true,
        ]);

        $order = $this->order();
        app(AutomationEngine::class)->evaluate('ksef.invoice_accepted', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'ksef.invoice_accepted',
            'order_id' => $order->getKey(),
            'invoice_id' => 123,
            'submission_id' => 456,
        ]);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_matching_rule_changes_order_status_and_records_execution(): void
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Po oczekujacym oznacz jako wyslane',
            'trigger' => 'order.status_changed',
            'conditions' => [
                ['field' => 'order_status', 'operator' => 'equals', 'value' => Order::STATUS_PENDING],
            ],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => Order::STATUS_SHIPPED],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $order = $this->order(['status' => Order::STATUS_PENDING]);

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->id,
            'old_status' => Order::STATUS_NEW,
            'new_status' => Order::STATUS_PENDING,
            'source' => 'manual',
        ]);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertDatabaseHas('automation_runs', [
            'automation_rule_id' => $rule->id,
            'order_id' => $order->id,
            'status' => AutomationRun::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('automation_run_steps', [
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'automation_completed',
        ]);
    }

    public function test_inactive_or_non_matching_rule_is_not_executed(): void
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Nieaktywna regula',
            'trigger' => 'order.status_changed',
            'conditions' => [['field' => 'source', 'operator' => 'equals', 'value' => 'allegro']],
            'is_active' => false,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => Order::STATUS_SHIPPED],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $order = $this->order();

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'source' => 'manual',
        ]);

        $this->assertSame(Order::STATUS_NEW, $order->fresh()->status);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_shipment_event_cannot_create_another_shipment(): void
    {
        $this->from(route('orders.automatic-actions.create'))
            ->post(route('orders.automatic-actions.store'), [
                'name' => 'Niebezpieczna petla',
                'trigger' => 'shipment.created',
                'is_active' => '1',
                'conditions' => [],
                'actions' => [[
                    'type' => AutomationCatalog::ACTION_CREATE_INPOST_SHIPMENT,
                    'configuration' => ['parcel_template' => 'medium'],
                    'stop_on_error' => '1',
                ]],
            ])
            ->assertRedirect(route('orders.automatic-actions.create'))
            ->assertSessionHasErrors('actions.0.type');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_automatic_shipment_action_can_override_the_source_service(): void
    {
        Queue::fake([CreateInPostShipmentJob::class]);

        CourierAccount::query()->create([
            'provider' => CourierAccount::PROVIDER_INPOST_LOCKERS,
            'name' => 'InPost Paczkomaty',
            'environment' => 'sandbox',
            'api_token' => 'test-token',
            'organization_id' => '12345',
            'settings' => ['sending_method' => 'dispatch_order'],
            'is_active' => true,
        ]);

        $rule = AutomationRule::query()->create([
            'name' => 'Nadaj standardowo',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CREATE_INPOST_SHIPMENT,
            'configuration' => [
                'service' => Shipment::SERVICE_INPOST_LOCKER_STANDARD,
                'parcel_template' => 'medium',
            ],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $order = $this->order([
            'source' => 'allegro',
            'pickup_point_id' => 'WAW01M',
        ]);

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->id,
            'old_status' => Order::STATUS_NEW,
            'new_status' => Order::STATUS_PENDING,
            'source' => 'manual',
        ]);

        $this->assertSame(
            Shipment::SERVICE_INPOST_LOCKER_STANDARD,
            Shipment::query()->firstOrFail()->service,
        );
    }

    public function test_generic_shipment_action_uses_selected_dpd_account_and_parameters(): void
    {
        Queue::fake([CreateDpdShipmentJob::class]);

        CourierAccount::query()->create([
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
            ],
            'is_active' => true,
        ]);

        $rule = AutomationRule::query()->create([
            'name' => 'Nadaj DPD',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CREATE_SHIPMENT,
            'configuration' => [
                'provider' => CourierAccount::PROVIDER_DPD,
                'service' => Shipment::SERVICE_DPD_TIME_1200,
                'cod_auto' => false,
                'insurance_amount' => '250',
                'content_description' => 'Automatyczna przesylka',
                'additional_services' => [Shipment::ADDITIONAL_SERVICE_SATURDAY],
                'parcels' => [[
                    'weight' => '3.5',
                    'length' => '45',
                    'width' => '35',
                    'height' => '25',
                ]],
            ],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $order = $this->order();

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->id,
            'old_status' => Order::STATUS_NEW,
            'new_status' => Order::STATUS_PENDING,
            'source' => 'manual',
        ]);

        $shipment = Shipment::query()->with('parcels')->firstOrFail();
        $this->assertSame(CourierAccount::PROVIDER_DPD, $shipment->provider);
        $this->assertSame(Shipment::SERVICE_DPD_TIME_1200, $shipment->service);
        $this->assertSame('Automatyczna przesylka', $shipment->content_description);
        $this->assertSame('3.500', $shipment->parcels->first()->weight);
        $this->assertSame('45.00', $shipment->parcels->first()->length);
        Queue::assertPushed(CreateDpdShipmentJob::class);
    }

    public function test_shipment_action_editor_lists_active_carriers_and_generic_label(): void
    {
        foreach ([
            CourierAccount::PROVIDER_INPOST_LOCKERS => 'InPost Paczkomaty',
            CourierAccount::PROVIDER_INPOST_COURIER => 'InPost Kurier',
            CourierAccount::PROVIDER_DPD => 'DPD',
        ] as $provider => $name) {
            CourierAccount::query()->create([
                'provider' => $provider,
                'name' => $name,
                'environment' => 'sandbox',
                'api_token' => 'token-'.$provider,
                'organization_id' => '12345',
                'settings' => $provider === CourierAccount::PROVIDER_DPD
                    ? ['api_login' => 'dpd-login', 'info_channel' => 'NEXOMS']
                    : [],
                'is_active' => true,
            ]);
        }

        $this->assertSame(
            html_entity_decode('Utw&oacute;rz przesy&#322;k&#281;', ENT_QUOTES, 'UTF-8'),
            app(AutomationCatalog::class)->actionLabel(AutomationCatalog::ACTION_CREATE_SHIPMENT),
        );

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('InPost Paczkomaty')
            ->assertSee('InPost Kurier')
            ->assertSee('DPD')
            ->assertSee('shipmentDefinitions', false)
            ->assertSee('shipment-provider', false);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 100,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
        ], $attributes));
    }
}
