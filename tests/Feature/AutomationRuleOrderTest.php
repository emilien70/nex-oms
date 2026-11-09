<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Tests\TestCase;

class AutomationRuleOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_order_is_used_by_the_list_and_event_queue(): void
    {
        Queue::fake();
        $first = $this->rule(1);
        $inactive = $this->rule(2, ['is_active' => false]);
        $second = $this->rule(3);
        $otherTrigger = $this->rule(4, ['trigger' => 'order.created']);
        $notMatching = $this->rule(5, ['conditions' => [
            ['field' => 'order_status', 'operator' => 'equals', 'value' => Order::STATUS_SHIPPED],
        ]]);
        $order = Order::create(['status' => Order::STATUS_NEW, 'source' => 'manual', 'currency' => 'PLN']);
        $original = [$first->id, $inactive->id, $second->id, $otherTrigger->id, $notMatching->id];
        $reordered = [$inactive->id, $second->id, $notMatching->id, $otherTrigger->id, $first->id];

        $this->patchJson(route('orders.automatic-actions.order'), [
            'rule_ids' => $reordered,
            'previous_rule_ids' => $original,
        ])->assertOk()->assertJsonPath('saved', true);

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertViewHas('canReorder', true)
            ->assertSee('data-automation-sort-handle', false)
            ->assertSeeInOrder(array_map(fn ($id) => 'id="automation-rule-'.$id.'"', $reordered), false);
        $this->assertSame($reordered, $this->storedIds());
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame('order.created', $otherTrigger->fresh()->trigger);
        $this->assertSame([AutomationCatalog::ACTION_CHANGE_STATUS, AutomationCatalog::ACTION_DELAY], $second->actions()->pluck('action_type')->all());
        Queue::assertNothingPushed();

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'order_id' => $order->id,
        ]);

        $this->assertSame(
            [$second->id, $first->id],
            Queue::pushed(ExecuteAutomationRunJob::class)->map(fn ($job) => $job->run->automation_rule_id)->all(),
        );
    }

    public function test_filtered_lists_do_not_expose_reordering(): void
    {
        $this->rule(1, ['is_active' => false]);
        $this->rule(2, ['is_active' => false]);

        foreach ([['active' => '0'], ['trigger' => 'order.status_changed'], ['search' => 'Rule']] as $filters) {
            $this->get(route('orders.automatic-actions.index', $filters))
                ->assertOk()
                ->assertViewHas('canReorder', false)
                ->assertDontSee('data-automation-sort-list=', false);
        }
    }

    public function test_incomplete_duplicate_and_unknown_rule_lists_are_rejected(): void
    {
        $first = $this->rule(1);
        $second = $this->rule(2);
        $third = $this->rule(3);
        $original = [$first->id, $second->id, $third->id];

        foreach ([[$second->id, $first->id], [$second->id, $second->id, $first->id], [$second->id, $first->id, 999999]] as $invalid) {
            $this->patchJson(route('orders.automatic-actions.order'), [
                'rule_ids' => $invalid,
                'previous_rule_ids' => $original,
            ])->assertUnprocessable();
        }

        $this->patchJson(route('orders.automatic-actions.order'), [
            'rule_ids' => [$second->id, $first->id],
            'previous_rule_ids' => [$first->id, $second->id],
        ])->assertConflict();
        $this->assertSame($original, $this->storedIds());
    }

    public function test_stale_reorder_cannot_overwrite_a_more_recent_save(): void
    {
        $first = $this->rule(1);
        $second = $this->rule(1);
        $third = $this->rule(3);
        $original = [$first->id, $second->id, $third->id];
        $reordered = [$third->id, $first->id, $second->id];

        $this->patchJson(route('orders.automatic-actions.order'), [
            'rule_ids' => $reordered,
            'previous_rule_ids' => $original,
        ])->assertOk();
        $this->patchJson(route('orders.automatic-actions.order'), [
            'rule_ids' => [$second->id, $third->id, $first->id],
            'previous_rule_ids' => $original,
        ])->assertConflict();
        $this->assertSame($reordered, $this->storedIds());
    }

    private function rule(int $position, array $attributes = []): AutomationRule
    {
        $rule = AutomationRule::create($attributes + [
            'name' => 'Rule '.$position,
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'sort_order' => $position,
            'is_active' => true,
        ]);
        $rule->actions()->create(['action_type' => AutomationCatalog::ACTION_DELAY, 'sort_order' => 2]);
        $rule->actions()->create(['action_type' => AutomationCatalog::ACTION_CHANGE_STATUS, 'sort_order' => 1]);

        return $rule;
    }

    private function storedIds(): array
    {
        return AutomationRule::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
    }
}
