<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Tests\TestCase;

class AutomationActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_returns_the_current_automation_step_for_the_global_panel(): void
    {
        $this->travelTo(Carbon::parse('2026-07-16 15:30:00'));

        $order = $this->order();
        $run = $this->createRun($order, AutomationRun::STATUS_RUNNING, [
            [
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_PENDING],
            ],
            [
                'type' => AutomationCatalog::ACTION_CREATE_INPOST_SHIPMENT,
                'configuration' => ['parcel_template' => 'medium'],
            ],
        ]);
        $run->steps()->create([
            'position' => 0,
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'status' => 'running',
            'configuration' => ['status' => Order::STATUS_PENDING],
            'started_at' => now(),
        ]);

        $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJsonPath('activities.0.id', $run->id)
            ->assertJsonPath('activities.0.order_id', $order->id)
            ->assertJsonPath('activities.0.status', AutomationRun::STATUS_RUNNING)
            ->assertJsonPath('activities.0.status_label', 'Wykonywanie')
            ->assertJsonPath('activities.0.tone', 'progress')
            ->assertJsonPath('activities.0.message', 'Zmieniam status zamówienia na Oczekujące')
            ->assertJsonPath('activities.0.progress.current', 1)
            ->assertJsonPath('activities.0.progress.total', 2);
    }

    public function test_feed_contains_only_recent_finished_runs_and_layout_contains_the_panel(): void
    {
        $this->travelTo(Carbon::parse('2026-07-16 15:35:00'));

        $order = $this->order();
        $recent = $this->createRun($order, AutomationRun::STATUS_COMPLETED, [], now());
        $old = $this->createRun($order, AutomationRun::STATUS_COMPLETED, [], now()->subMinutes(3));

        DB::table('automation_runs')
            ->where('id', $old->id)
            ->update(['updated_at' => now()->subMinutes(3)]);

        $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->assertJsonCount(1, 'activities')
            ->assertJsonPath('activities.0.id', $recent->id)
            ->assertJsonPath('activities.0.tone', 'success')
            ->assertJsonPath('activities.0.is_terminal', true);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-automation-activity-center', false)
            ->assertSee('nexoms:automation-finished', false)
            ->assertSee(route('automation.activity.index'), false);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 100,
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
        ]);
    }

    private function createRun(
        Order $order,
        string $status,
        array $actions,
        ?Carbon $finishedAt = null,
    ): AutomationRun {
        return AutomationRun::query()->create([
            'automation_rule_id' => null,
            'order_id' => $order->id,
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => $status,
            'event_payload' => ['order_id' => $order->id],
            'rule_snapshot' => [
                'name' => 'Testowa automatyczna akcja',
                'actions' => $actions,
            ],
            'started_at' => now(),
            'finished_at' => $finishedAt,
        ]);
    }
}
