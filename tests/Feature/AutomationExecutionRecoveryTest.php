<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\ExecuteAutomationRunJob;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationActionExecutor;
use Modules\Automation\Services\AutomationActivityFeed;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationExecutionState;
use Modules\Automation\Services\AutomationRunner;
use RuntimeException;
use Tests\TestCase;

class AutomationExecutionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_completed_run_and_its_external_actions_are_not_repeated(): void
    {
        $run = $this->makeRun();
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturn(['accepted' => true]);
        $job = new ExecuteAutomationRunJob($run, 0);
        $job->handle(app(AutomationRunner::class));
        $job->handle(app(AutomationRunner::class));
        $job->failed(new RuntimeException('Late duplicate failure'));

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNull($run->fresh()->execution_expires_at);
        $this->assertSame('completed', $run->steps()->sole()->status);
        $this->assertSame(1, $run->order->events()->where('event_type', 'automation_completed')->count());
        Http::assertNothingSent();
    }

    public function test_competing_execution_cannot_claim_a_running_run(): void
    {
        $run = $this->makeRun();
        $job = new ExecuteAutomationRunJob($run, 0);
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function () use ($run): array {
            $this->assertNull(app(AutomationExecutionState::class)->claim($run, 0, (string) Str::uuid()));
            (new ExecuteAutomationRunJob($run, 0))->handle(app(AutomationRunner::class));

            return ['accepted' => true];
        });

        $job->handle(app(AutomationRunner::class));
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertDatabaseCount('automation_run_steps', 1);
    }

    public function test_serialized_job_failure_marks_only_its_own_execution_uncertain_once(): void
    {
        $run = $this->makeRun();
        $job = new ExecuteAutomationRunJob($run, 0);
        $this->claimAndStart($run, $job->executionToken);
        $failed = unserialize(serialize($job));
        $failed->failed(new RuntimeException('Worker timeout'));
        $failed->failed(new RuntimeException('Repeated failure notification'));

        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        $this->assertTrue($run->fresh()->isFinished());
        $this->assertSame('uncertain', $run->steps()->sole()->status);
        $this->assertSame(1, $run->order->events()->where('event_type', 'automation_failed')->count());
        $this->mock(AutomationActionExecutor::class)->shouldNotReceive('execute');
        (new ExecuteAutomationRunJob($run, 0))->handle(app(AutomationRunner::class));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(1, $job->tries);
    }

    public function test_stale_failure_hook_cannot_interrupt_a_different_owner(): void
    {
        $run = $this->makeRun();
        $old = new ExecuteAutomationRunJob($run, 0);
        $current = new ExecuteAutomationRunJob($run, 0);
        $this->claimAndStart($run, $current->executionToken);
        $old->failed(new RuntimeException('Old attempt failed'));

        $this->assertSame(AutomationRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame('running', $run->steps()->sole()->status);
        $this->assertSame(0, $run->order->events()->count());
    }

    public function test_expired_execution_is_interrupted_without_replaying_or_overwriting_completed_steps(): void
    {
        $run = $this->makeRun(3);
        $token = (string) Str::uuid();
        $state = app(AutomationExecutionState::class);
        $state->claim($run, 0, $token);
        $first = $state->startStep($run, $token, 0, $run->rule_snapshot['actions'][0]);
        $state->recordStep($run, $token, $first, ['accepted' => 'first'], null);
        $second = $state->startStep($run, $token, 1, $run->rule_snapshot['actions'][1]);
        $this->travel(AutomationExecutionState::LEASE_SECONDS)->seconds();

        $this->assertSame(1, $state->recoverExpired());
        $this->assertSame(0, $state->recoverExpired());
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame(['accepted' => 'first'], $first->fresh()->output);
        $this->assertSame('uncertain', $second->fresh()->status);
        $this->assertFalse($state->recordStep($run, $token, $second, ['late' => true], null));
        $this->assertFalse($state->finish($run, $token, false));
        $this->assertSame(1, $run->order->events()->count());
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unexpired_run_and_delayed_or_queued_runs_are_not_recovered(): void
    {
        $running = $this->makeRun();
        $this->claimAndStart($running, (string) Str::uuid());
        $queued = $this->makeRun();
        $waiting = $this->makeRun();
        $waiting->update(['status' => AutomationRun::STATUS_WAITING]);
        $this->travel(AutomationExecutionState::LEASE_SECONDS - 1)->seconds();

        $this->assertSame(0, app(AutomationExecutionState::class)->recoverExpired());
        $this->assertSame(AutomationRun::STATUS_RUNNING, $running->fresh()->status);
        $this->assertSame(AutomationRun::STATUS_QUEUED, $queued->fresh()->status);
        $this->assertSame(AutomationRun::STATUS_WAITING, $waiting->fresh()->status);
    }

    public function test_legacy_running_without_a_lease_is_only_stopped_after_five_minutes(): void
    {
        $run = $this->makeRun();
        $run->update(['status' => AutomationRun::STATUS_RUNNING]);
        $run->steps()->create(['position' => 0, 'action_type' => AutomationCatalog::ACTION_CALL_URL, 'status' => 'running']);
        $this->travel(299)->seconds();
        $this->assertSame(0, app(AutomationExecutionState::class)->recoverExpired());
        $this->travel(1)->seconds();
        $this->assertSame(1, app(AutomationExecutionState::class)->recoverExpired());
        $this->assertSame('uncertain', $run->steps()->sole()->status);
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
    }

    public function test_late_worker_does_not_continue_after_recovery_even_if_external_action_returned_success(): void
    {
        $run = $this->makeRun(2);
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (): array {
            $this->travel(AutomationExecutionState::LEASE_SECONDS + 1)->seconds();
            $this->assertSame(1, app(AutomationExecutionState::class)->recoverExpired());

            return ['remote_result' => 'possibly completed'];
        });

        (new ExecuteAutomationRunJob($run, 0))->handle(app(AutomationRunner::class));
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        $this->assertSame('uncertain', $run->steps()->sole()->status);
        $this->assertNull($run->steps()->sole()->output);
    }

    public function test_result_persistence_failure_is_not_treated_as_a_retryable_action_failure(): void
    {
        $run = $this->makeRun(2, stopOnError: false);
        $job = new ExecuteAutomationRunJob($run, 0);
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturn(['sent' => true]);
        $state = $this->partialMock(AutomationExecutionState::class);
        $state->shouldReceive('recordStep')->once()->andThrow(new RuntimeException('Database unavailable after external success'));

        try {
            $job->handle(app(AutomationRunner::class));
            $this->fail('The persistence error must reach the queue failure handler.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame('uncertain', $run->steps()->sole()->status);
        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        $job->handle(app(AutomationRunner::class));
        $this->assertDatabaseCount('automation_run_steps', 1);
    }

    public function test_regular_action_failure_can_still_stop_or_continue_according_to_rule(): void
    {
        $stop = $this->makeRun(2);
        $continue = $this->makeRun(2, stopOnError: false);
        $executor = $this->mock(AutomationActionExecutor::class);
        $executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('Known failure'));
        $executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('Known failure'));
        $executor->shouldReceive('execute')->once()->andReturn(['accepted' => true]);

        (new ExecuteAutomationRunJob($stop, 0))->handle(app(AutomationRunner::class));
        (new ExecuteAutomationRunJob($continue, 0))->handle(app(AutomationRunner::class));

        $this->assertSame(AutomationRun::STATUS_FAILED, $stop->fresh()->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED_WITH_ERRORS, $continue->fresh()->status);
        $this->assertSame(['failed', 'completed'], $continue->steps()->pluck('status')->all());
    }

    public function test_duplicate_and_early_delay_jobs_do_not_resume_and_old_failure_cannot_stop_later_segment(): void
    {
        $run = $this->makeRun();
        $snapshot = $run->rule_snapshot;
        array_unshift($snapshot['actions'], ['type' => AutomationCatalog::ACTION_DELAY, 'configuration' => ['minutes' => 2]]);
        $run->update(['rule_snapshot' => $snapshot]);
        $old = new ExecuteAutomationRunJob($run, 0);
        $old->handle(app(AutomationRunner::class));
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->fresh()->status);
        $this->assertNull($run->fresh()->execution_token);
        Queue::assertPushed(ExecuteAutomationRunJob::class, fn ($job) => $job->position === 1 && $job->delay->equalTo(now()->addMinutes(2)));
        $this->assertNull(app(AutomationExecutionState::class)->claim($run, 1, (string) Str::uuid()));
        $this->travel(2)->minutes();
        $this->assertNull(app(AutomationExecutionState::class)->claim($run, 0, (string) Str::uuid()));
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function () use ($old, $run): array {
            $old->failed(new RuntimeException('Old segment failed after dispatch'));
            $this->assertSame(AutomationRun::STATUS_RUNNING, $run->fresh()->status);

            return ['accepted' => true];
        });
        (new ExecuteAutomationRunJob($run, 1))->handle(app(AutomationRunner::class));
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(['completed', 'completed'], $run->steps()->pluck('status')->all());
    }

    public function test_ui_and_order_history_show_uncertain_and_skipped_steps(): void
    {
        $run = $this->makeRun(3);
        $token = (string) Str::uuid();
        $this->claimAndStart($run, $token);
        app(AutomationExecutionState::class)->interrupt($run->id, $token);
        $this->getJson(route('automation.activity.index'))->assertOk()
            ->assertJsonPath('activities.0.status', AutomationRun::STATUS_INTERRUPTED)
            ->assertJsonPath('activities.0.status_label', 'Przerwano - sprawdź wynik')
            ->assertJsonPath('activities.0.is_terminal', true)
            ->assertJsonPath('activities.0.can_dismiss', true)
            ->assertJsonPath('activities.0.steps.0.status', 'uncertain')
            ->assertJsonPath('activities.0.steps.1.status', 'skipped');
        $html = $this->get(route('orders.show', $run->order_id))->assertOk()->getContent();
        $this->assertStringContainsString('is-uncertain', $html);
        $this->assertStringContainsString('bi-exclamation-triangle', $html);
        $activity = app(AutomationActivityFeed::class)->present($run->fresh('steps'));
        $this->assertSame(['uncertain', 'skipped', 'skipped'], array_column($activity['steps'], 'status'));
    }

    public function test_external_action_runs_outside_lifecycle_database_transaction(): void
    {
        $run = $this->makeRun();
        $baseLevel = DB::transactionLevel();
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function () use ($baseLevel): array {
            $this->assertSame($baseLevel, DB::transactionLevel());

            return [];
        });
        (new ExecuteAutomationRunJob($run, 0))->handle(app(AutomationRunner::class));
    }

    public function test_recovery_rechecks_the_lease_under_the_lock_instead_of_using_a_stale_selection(): void
    {
        $run = $this->makeRun();
        $token = (string) Str::uuid();
        $this->claimAndStart($run, $token);
        $this->travel(299)->seconds();
        $step = $run->steps()->sole();
        $this->assertTrue(app(AutomationExecutionState::class)->recordStep($run, $token, $step, [], null));
        $this->travel(2)->seconds();

        $this->assertFalse(app(AutomationExecutionState::class)->interrupt($run->id, onlyExpired: true));
        $this->assertSame(AutomationRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, $run->order->events()->count());
    }

    public function test_interruption_between_steps_preserves_success_and_skips_unstarted_steps(): void
    {
        $run = $this->makeRun(2);
        $token = (string) Str::uuid();
        $this->claimAndStart($run, $token);
        $step = $run->steps()->sole();
        app(AutomationExecutionState::class)->recordStep($run, $token, $step, ['sent' => true], null);
        app(AutomationExecutionState::class)->interrupt($run->id, $token);

        $activity = app(AutomationActivityFeed::class)->present($run->fresh('steps'));
        $this->assertSame(['completed', 'skipped'], array_column($activity['steps'], 'status'));
        $this->assertSame(['sent' => true], $step->fresh()->output);
    }

    public function test_scheduled_recovery_only_updates_local_state_and_never_dispatches_a_retry(): void
    {
        $run = $this->makeRun();
        $this->claimAndStart($run, (string) Str::uuid());
        $this->travel(301)->seconds();
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'automation-recover-interrupted');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $event->run(app());

        $this->assertSame(AutomationRun::STATUS_INTERRUPTED, $run->fresh()->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_old_serialized_job_without_a_token_can_execute_but_cannot_interrupt_an_unrelated_owner(): void
    {
        $run = $this->makeRun();
        $job = new ExecuteAutomationRunJob($run, 0);
        unset($job->executionToken);
        $this->mock(AutomationActionExecutor::class)->shouldReceive('execute')->once()->andReturn([]);
        $job->handle(app(AutomationRunner::class));
        $job->failed(new RuntimeException('Legacy failure callback without ownership'));
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_additive_migration_preserves_existing_runs_and_steps(): void
    {
        $run = $this->makeRun();
        $step = $run->steps()->create(['position' => 0, 'action_type' => AutomationCatalog::ACTION_CALL_URL, 'status' => 'completed', 'output' => ['historical' => true]]);
        $snapshot = $run->rule_snapshot;
        $migration = require database_path('migrations/2026_10_24_010000_add_execution_lease_to_automation_runs.php');
        $migration->down();
        $migration->up();

        $this->assertSame($snapshot, $run->fresh()->rule_snapshot);
        $this->assertSame(AutomationRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertNull($run->fresh()->execution_token);
        $this->assertNull($run->fresh()->execution_expires_at);
        $this->assertSame(['historical' => true], $step->fresh()->output);
        $this->assertSame($run->order_id, $run->fresh()->order_id);
    }

    private function claimAndStart(AutomationRun $run, string $token): void
    {
        $state = app(AutomationExecutionState::class);
        $this->assertNotNull($state->claim($run, 0, $token));
        $this->assertNotNull($state->startStep($run, $token, 0, $run->rule_snapshot['actions'][0]));
    }

    private function makeRun(int $actions = 1, bool $stopOnError = true): AutomationRun
    {
        $order = Order::query()->create([
            'source' => 'manual', 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid',
        ]);

        return AutomationRun::query()->create([
            'order_id' => $order->id, 'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed', 'chain_id' => (string) Str::uuid(),
            'depth' => 0, 'status' => AutomationRun::STATUS_QUEUED,
            'event_payload' => ['order_id' => $order->id],
            'rule_snapshot' => ['name' => 'Recovery test', 'actions' => array_fill(0, $actions, [
                'type' => AutomationCatalog::ACTION_CALL_URL,
                'configuration' => ['url' => 'https://example.test/never-called'],
                'stop_on_error' => $stopOnError,
            ])],
        ]);
    }
}
