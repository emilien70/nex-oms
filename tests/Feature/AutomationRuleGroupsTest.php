<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRuleGroup;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Tests\TestCase;

class AutomationRuleGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_can_be_created_renamed_and_deleted_without_deleting_or_disabling_rules(): void
    {
        $group = $this->createGroup('Przesyłka');
        $this->postJson(route('orders.automatic-action-groups.store'), ['name' => 'Przesyłka'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        foreach (['', 'Brak grupy', str_repeat('x', 51)] as $name) {
            $this->postJson(route('orders.automatic-action-groups.store'), ['name' => $name])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        $rule = $this->createRule('Nadaj przesyłkę', 7, $group->id);
        $action = $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => Order::STATUS_SHIPPED],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        $this->get(route('orders.automatic-action-groups.index'))->assertOk()
            ->assertSee('Nowa grupa akcji')->assertSee('data-group-rule-count="1"', false);
        $this->putJson(route('orders.automatic-action-groups.update', $group), ['name' => 'Kurierzy'])->assertOk();
        $this->get(route('orders.automatic-actions.index'))->assertOk()->assertSee('Kurierzy');

        $this->deleteJson(route('orders.automatic-action-groups.destroy', $group))->assertOk();
        $this->assertModelMissing($group);
        $rule->refresh();
        $this->assertNull($rule->group_id);
        $this->assertTrue($rule->is_active);
        $this->assertSame(7, $rule->sort_order);
        $this->assertSame($action->id, $rule->actions()->sole()->id);
    }

    public function test_group_display_order_and_filter_do_not_change_the_order_of_automation_runs(): void
    {
        Queue::fake();
        $shipping = $this->createGroup('Przesyłka');
        $billing = $this->createGroup('Dokumenty');
        $first = $this->createRule('Faktura', 1, $billing->id);
        $second = $this->createRule('Paczka', 2, $shipping->id);
        $third = $this->createRule('Inna reguła', 3);

        $this->get(route('orders.automatic-actions.index'))->assertOk()
            ->assertViewHas('canReorder', false)
            ->assertSeeInOrder(['id="automation-rule-'.$third->id.'"', 'id="automation-rule-'.$second->id.'"', 'id="automation-rule-'.$first->id.'"'], false)
            ->assertSee('Rozwiń wszystkie grupy')->assertSee('Zwiń wszystkie grupy');

        $reorder = ['group_ids' => [$billing->id, $shipping->id], 'previous_group_ids' => [$shipping->id, $billing->id]];
        $this->patchJson(route('orders.automatic-action-groups.order'), $reorder)->assertOk();
        $this->patchJson(route('orders.automatic-action-groups.order'), $reorder)->assertConflict();
        $this->patchJson(route('orders.automatic-action-groups.order'), [
            'group_ids' => [$shipping->id, 99999],
            'previous_group_ids' => $reorder['group_ids'],
        ])->assertUnprocessable();

        $this->get(route('orders.automatic-actions.index'))->assertOk()
            ->assertSeeInOrder(['id="automation-rule-'.$third->id.'"', 'id="automation-rule-'.$first->id.'"', 'id="automation-rule-'.$second->id.'"'], false);
        $this->get(route('orders.automatic-actions.index', ['view' => 'order']))->assertOk()
            ->assertViewHas('canReorder', true)
            ->assertSeeInOrder(['id="automation-rule-'.$first->id.'"', 'id="automation-rule-'.$second->id.'"', 'id="automation-rule-'.$third->id.'"'], false);
        $this->get(route('orders.automatic-actions.index', ['view' => 'order', 'group' => $shipping->id]))->assertOk()
            ->assertViewHas('canReorder', false)->assertSee('id="automation-rule-'.$second->id.'"', false)
            ->assertDontSee('id="automation-rule-'.$first->id.'"', false)->assertDontSee('id="automation-rule-'.$third->id.'"', false);
        $this->get(route('orders.automatic-actions.index', ['group' => 'none']))->assertOk()
            ->assertSee('id="automation-rule-'.$third->id.'"', false)->assertDontSee('id="automation-rule-'.$second->id.'"', false);

        $order = Order::create(['status' => Order::STATUS_NEW, 'source' => 'manual', 'currency' => 'PLN']);
        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'order_id' => $order->id,
        ]);
        $this->assertSame([$first->id, $second->id, $third->id], Queue::pushed(ExecuteAutomationRunJob::class)
            ->map(fn ($job): int => $job->run->automation_rule_id)->all());
    }

    public function test_rule_group_can_be_assigned_changed_cleared_and_preserved_for_old_clients(): void
    {
        $one = $this->createGroup('Pierwsza');
        $two = $this->createGroup('Druga');
        $payload = [
            'name' => 'Reguła',
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'conditions' => [],
            'actions' => [['type' => AutomationCatalog::ACTION_CHANGE_STATUS, 'configuration' => ['status' => Order::STATUS_SHIPPED], 'stop_on_error' => true]],
        ];
        $this->postJson(route('orders.automatic-actions.store'), $payload + ['group_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('group_id');
        $this->postJson(route('orders.automatic-actions.store'), $payload + ['group_id' => $one->id])->assertCreated();
        $rule = AutomationRule::query()->sole();
        $this->assertSame($one->id, $rule->group_id);
        $url = route('orders.automatic-actions.update', $rule);
        $this->putJson($url, $payload + ['group_id' => $two->id])->assertOk();
        $this->putJson($url, $payload)->assertOk();
        $this->assertSame($two->id, $rule->fresh()->group_id);
        $this->putJson($url, $payload + ['group_id' => null])->assertOk();
        $this->assertNull($rule->fresh()->group_id);
        $this->assertSame($rule->sort_order, $rule->fresh()->sort_order);
    }

    private function createGroup(string $name): AutomationRuleGroup
    {
        $this->postJson(route('orders.automatic-action-groups.store'), ['name' => $name])->assertCreated();

        return AutomationRuleGroup::query()->where('name', $name)->sole();
    }

    private function createRule(string $name, int $order, ?int $groupId = null): AutomationRule
    {
        return AutomationRule::create([
            'name' => $name,
            'group_id' => $groupId,
            'trigger' => 'order.status_changed',
            'is_active' => true,
            'sort_order' => $order,
        ]);
    }
}
