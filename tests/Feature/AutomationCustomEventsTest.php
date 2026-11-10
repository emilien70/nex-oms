<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\EvaluateCustomAutomationEventJob;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationCustomEvent;
use Modules\Automation\Models\AutomationCustomEventInvocation;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationCustomEventAppearance;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\OrderAutomationHistory;
use Tests\TestCase;

class AutomationCustomEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_settings_are_rendered_and_validation_only_accepts_catalog_choices(): void
    {
        $this->postJson(route('orders.custom-events.store'), $this->eventData('Nadanie', [
            'icon' => 'arbitrary-icon', 'color' => '#123456', 'order_statuses' => ['unknown'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['icon', 'color', 'order_statuses.0']);

        $event = $this->createEvent('Nadanie', [
            'description' => 'Opis zdarzenia',
            'button_label' => 'InPost A',
            'order_statuses' => [Order::STATUS_NEW],
        ]);
        $this->assertSame(1, $event->button_position);
        $this->assertCount(42, AutomationCustomEventAppearance::ICONS);
        $this->assertCount(28, AutomationCustomEventAppearance::COLORS);

        $this->get(route('orders.custom-events.index'))
            ->assertOk()->assertSee('Zdarzenia własne')->assertSee('Dodaj zdarzenie własne')
            ->assertSee('InPost A')->assertSee('Opis zdarzenia');
        $this->get(route('orders.show', $this->order()))
            ->assertOk()->assertSee('Edytuj układ szybkich akcji')->assertSee('InPost A')
            ->assertSee('data-quick-action-run=', false);
        $this->get(route('orders.show', $this->order(Order::STATUS_SHIPPED)))
            ->assertOk()->assertSee('InPost A')->assertSee('data-unavailable', false)
            ->assertSee('Brak dostępnych szybkich akcji.');

        $this->putJson(route('orders.custom-events.update', $event), $this->eventData('Nadanie', ['button_enabled' => false]))->assertOk();
        $this->assertNull($event->fresh()->button_position);
    }

    public function test_duplicate_uses_new_event_creation_without_inheriting_the_rule_or_grid_position(): void
    {
        $settings = [
            'description' => 'Opis oryginału',
            'button_label' => 'InPost A',
            'confirmation_required' => true,
            'order_statuses' => [Order::STATUS_NEW],
        ];
        $event = $this->createEvent('Nadanie', $settings);
        $this->rule($event);

        $this->get(route('orders.custom-events.index'))
            ->assertOk()->assertSee('data-custom-event-duplicate', false)->assertSee('Duplikuj');
        $this->assertDatabaseCount('automation_custom_events', 1);

        $copy = $this->createEvent('Kopia Nadanie', $settings);
        $this->assertNotSame($event->triggerKey(), $copy->triggerKey());
        $this->assertSame($event->button_position + 1, $copy->button_position);
        $this->assertDatabaseHas('automation_rules', ['trigger' => $event->triggerKey()]);
        $this->assertDatabaseMissing('automation_rules', ['trigger' => $copy->triggerKey()]);
        $this->assertSame('Nadanie', $event->fresh()->name);
    }

    public function test_reorder_and_grid_positions_are_independent_and_conflicts_are_rejected(): void
    {
        $one = $this->createEvent('Pierwsze');
        $two = $this->createEvent('Drugie');
        $rule = $this->rule($one);
        $original = [$one->id, $two->id];
        $reordered = array_reverse($original);

        $this->patchJson(route('orders.custom-events.order'), [
            'event_ids' => $reordered, 'previous_event_ids' => $original,
        ])->assertOk();
        $this->get(route('orders.custom-events.index'))
            ->assertOk()->assertSee('data-automation-sort-handle', false)
            ->assertSeeInOrder(array_map(fn (int $id): string => 'data-automation-sort-row="'.$id.'"', $reordered), false);
        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()->assertSeeInOrder([$two->triggerKey(), $one->triggerKey()]);
        $this->patchJson(route('orders.custom-events.order'), [
            'event_ids' => $reordered, 'previous_event_ids' => $original,
        ])->assertConflict();
        $this->patchJson(route('orders.custom-events.order'), [
            'event_ids' => $original, 'previous_event_ids' => $reordered,
        ])->assertOk();

        $previous = [['id' => $one->id, 'position' => 1], ['id' => $two->id, 'position' => 2]];
        $layout = [['id' => $two->id, 'position' => 2], ['id' => $one->id, 'position' => 8]];
        $this->patchJson(route('orders.custom-events.layout'), [
            'layout' => [['id' => $one->id, 'position' => 2], $previous[1]], 'previous_layout' => $previous,
        ])->assertUnprocessable();
        $this->patchJson(route('orders.custom-events.layout'), ['layout' => [$layout[0]], 'previous_layout' => $previous])
            ->assertUnprocessable();
        $this->patchJson(route('orders.custom-events.layout'), ['layout' => $layout, 'previous_layout' => $previous])
            ->assertOk();
        $this->patchJson(route('orders.custom-events.layout'), ['layout' => $layout, 'previous_layout' => $previous])
            ->assertConflict();

        $this->assertSame(8, $one->fresh()->button_position);
        $this->assertSame(1, $one->fresh()->sort_order);
        $this->assertSame(9, $this->createEvent('Nowe')->button_position);
        $this->assertSame(1, $rule->fresh()->sort_order);
    }

    public function test_a_rule_keeps_its_event_after_rename_and_prevents_deletion_while_linked(): void
    {
        $event = $this->createEvent('Stara nazwa');
        $ruleData = [
            'name' => 'Reguła',
            'trigger' => $event->triggerKey(),
            'is_active' => false,
            'conditions' => [],
            'actions' => [['type' => AutomationCatalog::ACTION_CHANGE_STATUS, 'configuration' => ['status' => Order::STATUS_SHIPPED], 'stop_on_error' => true]],
        ];
        $this->postJson(route('orders.automatic-actions.store'), $ruleData)->assertCreated();
        $this->postJson(route('orders.automatic-actions.store'), array_replace($ruleData, ['trigger' => 'custom_event.999999']))
            ->assertUnprocessable()->assertJsonValidationErrors('trigger');
        $this->putJson(route('orders.custom-events.update', $event), $this->eventData('Nowa nazwa'))->assertOk();
        $this->assertDatabaseHas('automation_rules', ['name' => 'Reguła', 'trigger' => $event->triggerKey()]);
        $this->deleteJson(route('orders.custom-events.destroy', $event))->assertUnprocessable();

        AutomationRule::query()->where('trigger', $event->triggerKey())->firstOrFail()->delete();
        $this->deleteJson(route('orders.custom-events.destroy', $event))->assertOk();
        $this->assertModelMissing($event);
    }

    public function test_manual_invocation_runs_only_matching_active_rules_once_and_records_the_manual_origin(): void
    {
        Queue::fake();
        $order = $this->order();
        $event = $this->createEvent('Nadanie');
        $second = $this->rule($event, ['sort_order' => 2]);
        $first = $this->rule($event);
        $this->rule($event, ['is_active' => false]);
        $this->rule($event, ['conditions' => [['field' => 'order_status', 'operator' => 'equals', 'value' => Order::STATUS_SHIPPED]]]);
        $this->rule($event, ['trigger' => 'order.created']);
        $this->rule($this->createEvent('Inne'));
        $id = (string) Str::uuid();
        $url = route('orders.quick-actions.store', [$order, $event]);
        $response = $this->postJson($url, ['request_id' => $id])->assertAccepted()->assertJsonPath('status', 'pending');
        $this->postJson($url, ['request_id' => $id])->assertAccepted();
        $this->postJson($url, ['request_id' => (string) Str::uuid()])->assertConflict();
        Queue::assertPushed(EvaluateCustomAutomationEventJob::class, 1);
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);

        $job = new EvaluateCustomAutomationEventJob($id);
        $job->handle(app(AutomationEngine::class));
        $job->handle(app(AutomationEngine::class));
        $this->postJson($url, ['request_id' => $id])->assertOk()->assertJsonPath('status', 'checked');
        $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('status', 'checked');
        $this->getJson(route('orders.quick-actions.show', [$this->order(), $id]))->assertNotFound();

        $this->assertSame([$first->id, $second->id], Queue::pushed(ExecuteAutomationRunJob::class)
            ->map(fn ($job): int => $job->run->automation_rule_id)->all());
        $this->assertSame(2, AutomationRun::query()->where('event_id', $id)->count());
        $this->assertSame(2, AutomationCustomEventInvocation::query()->findOrFail($id)->run_count);
        $this->putJson(route('orders.custom-events.update', $event), $this->eventData('Przemianowano'))->assertOk();
        $history = app(OrderAutomationHistory::class)->forOrder($order)->first();
        $this->assertSame('Ręcznie', $history['actor']);
        $this->assertSame('Nadanie', $history['trigger']);
    }

    public function test_status_confirmation_and_no_matching_rule_are_handled_without_running_actions(): void
    {
        Queue::fake();
        $order = $this->order();
        $event = $this->createEvent('Bez reguły', [
            'order_statuses' => [Order::STATUS_NEW], 'confirmation_required' => true,
        ]);
        $url = route('orders.quick-actions.store', [$order, $event]);
        $id = (string) Str::uuid();
        $this->postJson($url, ['request_id' => $id])->assertUnprocessable();
        $this->postJson(route('orders.quick-actions.store', [$this->order(Order::STATUS_SHIPPED), $event]), [
            'request_id' => $id, 'confirmed' => true,
        ])->assertConflict();
        Queue::assertNothingPushed();

        $response = $this->postJson($url, ['request_id' => $id, 'confirmed' => true])->assertAccepted();
        (new EvaluateCustomAutomationEventJob($id))->handle(app(AutomationEngine::class));
        $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('message', 'Żadna aktywna reguła nie pasuje do tego zamówienia.');
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);

        $this->travel(4)->seconds();
        $nextId = (string) Str::uuid();
        $this->postJson($url, ['request_id' => $nextId, 'confirmed' => true])->assertAccepted();
        $order->update(['status' => Order::STATUS_SHIPPED]);
        (new EvaluateCustomAutomationEventJob($nextId))->handle(app(AutomationEngine::class));
        $this->getJson(route('orders.quick-actions.show', [$order, $nextId]))->assertOk()->assertJsonPath('status', 'unavailable');
        $event->update(['button_enabled' => false]);
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'confirmed' => true])->assertConflict();
        $order->delete();
        $this->postJson($url, ['request_id' => (string) Str::uuid(), 'confirmed' => true])->assertNotFound();
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
    }

    private function order(string $status = Order::STATUS_NEW): Order
    {
        return Order::create(['status' => $status, 'source' => 'manual', 'currency' => 'PLN']);
    }

    private function eventData(string $name, array $attributes = []): array
    {
        return $attributes + [
            'name' => $name,
            'button_enabled' => true,
            'icon' => 'truck',
            'color' => '#FF9900',
            'confirmation_required' => false,
        ];
    }

    private function createEvent(string $name, array $attributes = []): AutomationCustomEvent
    {
        $this->postJson(route('orders.custom-events.store'), $this->eventData($name, $attributes))->assertCreated();

        return AutomationCustomEvent::query()->latest('id')->firstOrFail();
    }

    private function rule(AutomationCustomEvent $event, array $attributes = []): AutomationRule
    {
        return AutomationRule::create($attributes + ['name' => 'Reguła', 'trigger' => $event->triggerKey(), 'is_active' => true, 'sort_order' => 1]);
    }
}
