<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class AutomationActivityFeedTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
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
            ->assertJsonPath('activities.0.progress.total', 2)
            ->assertJsonPath('activities.0.steps.0.status', 'running')
            ->assertJsonPath('activities.0.steps.0.status_label', '⟳ Wykonywanie')
            ->assertJsonPath('activities.0.steps.1.status', 'queued')
            ->assertJsonPath('activities.0.steps.1.status_label', '… Oczekuje');
    }

    public function test_feed_builds_all_queued_steps_from_the_rule_snapshot(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_QUEUED, $this->threeActions());

        $response = $this->getJson(route('automation.activity.index'))->assertOk();
        $steps = $response->json('activities.0.steps');

        $this->assertSame($run->id, $response->json('activities.0.id'));
        $this->assertSame([1, 2, 3], array_column($steps, 'position'));
        $this->assertSame(['queued', 'queued', 'queued'], array_column($steps, 'status'));
        $this->assertSame(['… Oczekuje', '… Oczekuje', '… Oczekuje'], array_column($steps, 'status_label'));
        $this->assertSame(['queued', 'queued', 'queued'], array_column($steps, 'tone'));
    }

    public function test_feed_presents_completed_running_and_future_steps(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_RUNNING, $this->threeActions());
        $this->addStep($run, 0, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_PENDING,
        ]);
        $this->addStep($run, 1, AutomationCatalog::ACTION_DELAY, 'running', ['minutes' => 5]);

        $response = $this->getJson(route('automation.activity.index'))->assertOk();
        $steps = $response->json('activities.0.steps');

        $this->assertSame(['completed', 'running', 'queued'], array_column($steps, 'status'));
        $this->assertSame(
            ['✓ Wykonano', '⟳ Wykonywanie', '… Oczekuje'],
            array_column($steps, 'status_label'),
        );
        $this->assertSame(['success', 'progress', 'queued'], array_column($steps, 'tone'));
        $this->assertSame(2, $response->json('activities.0.progress.current'));
        $this->assertSame(3, $response->json('activities.0.progress.total'));
    }

    public function test_feed_presents_all_completed_steps(): void
    {
        $run = $this->createRun(
            $this->order(),
            AutomationRun::STATUS_COMPLETED,
            $this->threeActions(),
            now(),
        );
        $this->addThreeCompletedSteps($run);

        $response = $this->getJson(route('automation.activity.index'))->assertOk();
        $steps = $response->json('activities.0.steps');

        $this->assertSame(['completed', 'completed', 'completed'], array_column($steps, 'status'));
        $this->assertSame(['✓ Wykonano', '✓ Wykonano', '✓ Wykonano'], array_column($steps, 'status_label'));
        $this->assertSame('Gotowe', $response->json('activities.0.status_label'));
    }

    public function test_feed_marks_unstarted_steps_after_a_stopping_failure_as_skipped(): void
    {
        $error = 'Brak prawidłowego punktu odbioru.';
        $run = $this->createRun(
            $this->order(),
            AutomationRun::STATUS_FAILED,
            $this->threeActions(),
            now(),
        );
        $run->update(['error_message' => $error]);
        $this->addStep($run, 0, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_PENDING,
        ]);
        $this->addStep(
            $run,
            1,
            AutomationCatalog::ACTION_DELAY,
            'failed',
            ['minutes' => 5],
            errorMessage: $error,
        );

        $response = $this->getJson(route('automation.activity.index'))->assertOk();
        $steps = $response->json('activities.0.steps');

        $this->assertSame(['completed', 'failed', 'skipped'], array_column($steps, 'status'));
        $this->assertSame(['✓ Wykonano', '✕ Błąd', '— Pominięto'], array_column($steps, 'status_label'));
        $this->assertSame(['success', 'error', 'skipped'], array_column($steps, 'tone'));
        $this->assertSame($error, $steps[1]['details']);
        $this->assertNull($response->json('activities.0.details'));
    }

    public function test_feed_does_not_skip_steps_completed_after_a_non_stopping_failure(): void
    {
        $run = $this->createRun(
            $this->order(),
            AutomationRun::STATUS_COMPLETED_WITH_ERRORS,
            $this->threeActions(),
            now(),
        );
        $this->addStep($run, 0, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_PENDING,
        ]);
        $this->addStep(
            $run,
            1,
            AutomationCatalog::ACTION_DELAY,
            'failed',
            ['minutes' => 5],
            errorMessage: 'Krok został wykonany z błędem.',
        );
        $this->addStep($run, 2, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_SHIPPED,
        ]);

        $steps = $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->json('activities.0.steps');

        $this->assertSame(['completed', 'failed', 'completed'], array_column($steps, 'status'));
        $this->assertNotContains('skipped', array_column($steps, 'status'));
    }

    public function test_feed_presents_active_delay_as_waiting_and_completed_after_resume(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_WAITING, $this->threeActions());
        $this->addStep($run, 0, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_PENDING,
        ]);
        $this->addStep(
            $run,
            1,
            AutomationCatalog::ACTION_DELAY,
            'completed',
            ['minutes' => 5],
            output: ['resume_at' => now()->addMinutes(5)->toIso8601String()],
        );

        $waitingSteps = $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->json('activities.0.steps');

        $this->assertSame(['completed', 'waiting', 'queued'], array_column($waitingSteps, 'status'));
        $this->assertSame('◷ Oczekiwanie 5 min', $waitingSteps[1]['status_label']);
        $this->assertSame('waiting', $waitingSteps[1]['tone']);

        $run->update(['status' => AutomationRun::STATUS_RUNNING]);

        $resumedSteps = $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->json('activities.0.steps');

        $this->assertSame('completed', $resumedSteps[1]['status']);
        $this->assertSame('✓ Wykonano', $resumedSteps[1]['status_label']);

        $this->addStep($run, 2, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_SHIPPED,
        ]);
        $run->update([
            'status' => AutomationRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);

        $completedSteps = $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->json('activities.0.steps');

        $this->assertSame(['completed', 'completed', 'completed'], array_column($completedSteps, 'status'));
    }

    public function test_feed_reuses_catalog_summaries_for_every_supported_action_label(): void
    {
        $series = $this->createDocumentSeries(attributes: ['name' => 'Faktury Activity Feed']);
        $actions = [
            [
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_SHIPPED],
            ],
            [
                'type' => AutomationCatalog::ACTION_CREATE_SHIPMENT,
                'configuration' => ['provider' => 'inpost_lockers', 'parcel_template' => 'medium'],
            ],
            [
                'type' => AutomationCatalog::ACTION_DELAY,
                'configuration' => ['minutes' => 10],
            ],
            [
                'type' => AutomationCatalog::ACTION_ISSUE_INVOICE,
                'configuration' => ['invoice_series_id' => $series->id],
            ],
            [
                'type' => AutomationCatalog::ACTION_CALL_URL,
                'configuration' => ['url' => 'https://example.com/oms-hook'],
            ],
        ];
        $this->createRun($this->order(), AutomationRun::STATUS_QUEUED, $actions);

        $steps = $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->json('activities.0.steps');

        $this->assertSame([
            'Zmień status: Wysłane',
            'Utwórz przesyłkę (InPost Paczkomaty, Gabaryt B)',
            'Poczekaj przez 10 min',
            'Wystaw Fakturę: Faktury Activity Feed',
            'Wywołaj URL: https://example.com/oms-hook',
        ], array_column($steps, 'label'));
    }

    public function test_single_step_keeps_compact_message_and_layout_supports_safe_multi_step_rendering(): void
    {
        $this->createRun($this->order(), AutomationRun::STATUS_QUEUED, [[
            'type' => AutomationCatalog::ACTION_DELAY,
            'configuration' => ['minutes' => 5],
        ]]);

        $this->getJson(route('automation.activity.index'))
            ->assertOk()
            ->assertJsonCount(1, 'activities.0.steps')
            ->assertJsonPath('activities.0.message', 'Oczekuje na wykonanie');

        $page = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('if (steps.length >= 2) {', $page);
        $this->assertStringContainsString('message.textContent = activity.message;', $page);
        $this->assertStringContainsString('stepLabel.textContent =', $page);
        $this->assertStringContainsString('stepStatus.textContent = step.status_label;', $page);
        $this->assertStringContainsString('stepDetails.textContent = step.details;', $page);

        foreach (['completed', 'failed', 'skipped', 'running', 'queued', 'waiting'] as $status) {
            $this->assertStringContainsString(".automation-activity-step-status.is-{$status}", $page);
        }

        preg_match_all(
            '/\.automation-activity-step-status(?:\.[^{]+)?\s*\{([^}]*)\}/',
            $page,
            $statusRules,
        );
        $this->assertNotEmpty($statusRules[1]);
        foreach ($statusRules[1] as $declarations) {
            $this->assertStringNotContainsString('background', $declarations);
        }
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

    private function threeActions(): array
    {
        return [
            [
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_PENDING],
                'stop_on_error' => true,
            ],
            [
                'type' => AutomationCatalog::ACTION_DELAY,
                'configuration' => ['minutes' => 5],
                'stop_on_error' => true,
            ],
            [
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_SHIPPED],
                'stop_on_error' => true,
            ],
        ];
    }

    private function addThreeCompletedSteps(AutomationRun $run): void
    {
        $this->addStep($run, 0, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_PENDING,
        ]);
        $this->addStep($run, 1, AutomationCatalog::ACTION_DELAY, 'completed', ['minutes' => 5]);
        $this->addStep($run, 2, AutomationCatalog::ACTION_CHANGE_STATUS, 'completed', [
            'status' => Order::STATUS_SHIPPED,
        ]);
    }

    private function addStep(
        AutomationRun $run,
        int $position,
        string $actionType,
        string $status,
        array $configuration,
        ?string $errorMessage = null,
        array $output = [],
    ): void {
        $run->steps()->create([
            'position' => $position,
            'action_type' => $actionType,
            'status' => $status,
            'configuration' => $configuration,
            'output' => $output,
            'error_message' => $errorMessage,
            'started_at' => now(),
            'finished_at' => in_array($status, ['completed', 'failed'], true) ? now() : null,
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
