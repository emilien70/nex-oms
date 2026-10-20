<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\OrderAutomationHistory;
use Modules\Shipments\Models\IntegrationApiLog;
use Tests\TestCase;

class OrderAutomationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_order_page_displays_its_own_persistent_history_without_executing_actions(): void
    {
        $order = $this->order();
        $run = $this->createRun($order, name: 'Historyczna akcja');
        $this->step($run, 0, 'completed');
        $run->timestamps = false;
        $run->update(['created_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(2)]);
        $run->timestamps = true;
        $run->refresh();
        $run->timestamps = true;
        $run->refresh();
        $this->createRun($this->order(), name: 'Cudze wykonanie');
        $before = [$order->fresh()->getAttributes(), $run->getAttributes(), DB::table('automation_run_steps')->get()->toJson()];

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('Historia automatycznych akcji')
            ->assertSee('Wykonane akcje')
            ->assertSee('Automat, '.$run->created_at->format('d.m.Y H:i:s'))
            ->assertSee('Historyczna akcja')
            ->assertSee('Ustawiono status')
            ->assertSee('Zmień status')
            ->assertSee('is-completed', false)
            ->assertDontSee('Cudze wykonanie');

        $this->assertSame($before, [$order->fresh()->getAttributes(), $run->fresh()->getAttributes(), DB::table('automation_run_steps')->get()->toJson()]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_state_fragment_paginates_only_the_selected_order_with_stable_newest_first_order(): void
    {
        $order = $this->order();
        $runs = collect(range(1, 12))->map(fn (int $index) => $this->createRun($order, name: 'Akcja '.$index));
        $this->createRun($this->order(), name: 'Inne zamowienie');

        $first = $this->getJson(route('orders.state', $order).'?fragments=automation-history')->assertOk();
        $html = $first->json('fragments.automation-history');
        $this->assertSame(10, substr_count($html, 'data-automation-run-id='));
        $this->assertStringContainsString('data-automation-run-id="'.$runs->last()->id.'"', $html);
        $this->assertStringNotContainsString('data-automation-run-id="'.$runs->first()->id.'"', $html);
        $this->assertStringNotContainsString('Inne zamowienie', $html);

        $second = $this->getJson(route('orders.state', $order).'?fragments=automation-history&automation_page=2')->assertOk();
        $html = $second->json('fragments.automation-history');
        $this->assertSame(2, substr_count($html, 'data-automation-run-id='));
        $this->assertStringContainsString('data-automation-history-current-page="2"', $html);
        $this->assertLessThan(strpos($html, 'Akcja 1'), strpos($html, 'Akcja 2'));
        $this->get(route('orders.show', [$order, 'automation_page' => 2]))->assertOk()
            ->assertSee('data-automation-history-current-page="2"', false);
        Queue::assertNothingPushed();
    }

    public function test_failed_and_skipped_steps_are_distinguished_and_error_text_is_visible(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_FAILED, actions: $this->actions(3));
        $this->step($run, 0, 'completed');
        $this->step($run, 1, 'failed', error: 'Brak konfiguracji akcji');

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame(['completed', 'failed', 'skipped'], array_column($history['steps'], 'status'));
        $this->assertSame('Brak konfiguracji akcji', $history['steps'][1]['error']);
        $this->assertNull($history['steps'][2]['error']);
    }

    public function test_delay_remains_waiting_and_future_steps_are_not_marked_executed(): void
    {
        $actions = [
            ['type' => AutomationCatalog::ACTION_DELAY, 'configuration' => ['minutes' => 5]],
            ...$this->actions(),
        ];
        $run = $this->createRun($this->order(), AutomationRun::STATUS_WAITING, actions: $actions);
        $this->step($run, 0, 'completed', output: ['resume_at' => now()->addMinutes(5)->toIso8601String()]);

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame(['waiting', 'queued'], array_column($history['steps'], 'status'));
        $this->assertSame('5 min', $history['steps'][0]['target']);
    }

    public function test_completed_with_errors_does_not_hide_successful_later_steps(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_COMPLETED_WITH_ERRORS, actions: $this->actions(3));
        $this->step($run, 0, 'failed', error: 'Blad pierwszego kroku');
        $this->step($run, 1, 'completed');
        $this->step($run, 2, 'completed');

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame(['failed', 'completed', 'completed'], array_column($history['steps'], 'status'));
    }

    public function test_deleting_a_rule_does_not_remove_run_snapshot_or_historical_output(): void
    {
        $rule = AutomationRule::query()->create(['name' => 'Regula', 'trigger' => 'order.status_changed']);
        $actions = [
            ['type' => AutomationCatalog::ACTION_ISSUE_INVOICE, 'configuration' => ['invoice_series_id' => 999]],
            ['type' => AutomationCatalog::ACTION_PRINT, 'configuration' => ['document_type' => AutomationCatalog::PRINT_DOCUMENT_LATEST_SHIPMENT_LABEL, 'printer_id' => 999]],
        ];
        $run = $this->createRun($this->order(), name: 'Zapisana nazwa', actions: $actions);
        $run->update(['automation_rule_id' => $rule->id]);
        $this->step($run, 0, 'completed', output: ['invoice_number' => 'FV 15/2026']);
        $this->step($run, 1, 'completed', output: ['printer_name' => 'Drukarka historyczna']);
        $rule->update(['name' => 'Zmieniona regula']);
        $rule->delete();

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertNull($run->fresh()->automation_rule_id);
        $this->assertSame('Zapisana nazwa', $history['rule_name']);
        $this->assertSame('FV 15/2026', $history['steps'][0]['target']);
        $this->assertStringContainsString('Drukarka historyczna', $history['steps'][1]['target']);
        $this->assertStringContainsString('kolejki NEX Printer', $history['steps'][1]['status_label']);
    }

    public function test_history_uses_the_email_template_snapshot_and_does_not_claim_delivery(): void
    {
        $run = $this->createRun($this->order(), actions: [[
            'type' => AutomationCatalog::ACTION_SEND_EMAIL, 'configuration' => ['email_template_id' => 999],
        ]]);
        $messageId = DB::table('order_email_messages')->insertGetId([
            'order_id' => $run->order_id, 'automation_run_id' => $run->id,
            'source' => 'automation', 'status' => 'queued', 'idempotency_key' => (string) Str::uuid(),
            'template_name_snapshot' => 'Historyczne potwierdzenie',
            'from_email' => 'seller@example.test', 'to_email' => 'buyer@example.test',
            'subject' => 'Temat', 'format' => 'plain_text', 'body_text' => 'Tresc',
        ]);
        $this->step($run, 0, 'completed', output: ['message_id' => $messageId]);

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame('Historyczne potwierdzenie', $history['steps'][0]['target']);
        $this->assertStringContainsString('kolejki e-mail', $history['steps'][0]['status_label']);
    }

    public function test_url_details_use_the_saved_request_and_duration_without_rendering_current_order_data(): void
    {
        $run = $this->createRun($this->order(), actions: [[
            'type' => AutomationCatalog::ACTION_CALL_URL,
            'configuration' => ['url' => 'https://example.test/?order=[numer_zamowienia]'],
        ]]);
        $requestId = (string) Str::uuid();
        $this->step($run, 0, 'completed', output: ['request_id' => $requestId]);
        $log = IntegrationApiLog::query()->create([
            'integration' => 'automation_url', 'operation' => 'call_url',
            'order_id' => $run->order_id, 'request_id' => $requestId,
            'method' => 'GET', 'url' => 'https://example.test/?order=historyczny',
            'request_payload' => ['automation_run_id' => $run->id],
            'duration_ms' => 1148, 'successful' => true,
        ]);

        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame('https://example.test/?order=historyczny (1.148s)', $history['steps'][0]['target']);
        $log->update(['order_id' => $this->order()->id]);
        $history = app(OrderAutomationHistory::class)->forOrder($run->order)->first();
        $this->assertSame('https://example.test/?order=[numer_zamowienia]', $history['steps'][0]['target']);
        Http::assertNothingSent();
    }

    public function test_history_escapes_names_urls_and_errors_without_creating_an_external_link(): void
    {
        $run = $this->createRun($this->order(), AutomationRun::STATUS_FAILED, name: '<script>bad()</script>', actions: [[
            'type' => AutomationCatalog::ACTION_CALL_URL,
            'configuration' => ['url' => 'https://example.test/?value=<img src=x onerror=bad()>'],
        ]]);
        $this->step($run, 0, 'failed', error: '<img src=x onerror=bad()>');

        $html = $this->getJson(route('orders.state', $run->order).'?fragments=automation-history')
            ->assertOk()->json('fragments.automation-history');
        $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=bad()&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<a ', $html);
        Http::assertNothingSent();
    }

    public function test_empty_history_and_order_event_changes_return_readable_fragments(): void
    {
        $order = $this->order();
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Brak historii automatycznych akcji.');
        $order->events()->create(['event_type' => 'test', 'title' => 'Zdarzenie']);
        $response = $this->getJson(route('orders.state', $order).'?latest_event_id=0')->assertOk();
        $this->assertStringContainsString('Brak historii automatycznych akcji.', $response->json('fragments.automation-history'));
        $this->assertSame(0, DB::table('automation_runs')->count());
        Queue::assertNothingPushed();
    }

    private function order(): Order
    {
        return Order::query()->create(['source' => 'manual', 'status' => 'new', 'currency' => 'PLN']);
    }

    private function actions(int $count = 1): array
    {
        return array_fill(0, $count, [
            'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => 'shipped'],
        ]);
    }

    private function createRun(Order $order, string $status = AutomationRun::STATUS_COMPLETED, string $name = 'Testowa regula', ?array $actions = null): AutomationRun
    {
        return AutomationRun::query()->create([
            'order_id' => $order->id, 'event_id' => (string) Str::uuid(), 'chain_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed', 'depth' => 0, 'status' => $status,
            'event_payload' => ['order_id' => $order->id, 'source' => 'manual'],
            'rule_snapshot' => ['name' => $name, 'actions' => $actions ?? $this->actions()],
            'started_at' => now(), 'finished_at' => $status === AutomationRun::STATUS_WAITING ? null : now(),
        ]);
    }

    private function step(AutomationRun $run, int $position, string $status, array $output = [], ?string $error = null): void
    {
        $action = $run->rule_snapshot['actions'][$position];
        $run->steps()->create([
            'position' => $position, 'action_type' => $action['type'], 'configuration' => $action['configuration'],
            'status' => $status, 'output' => $output, 'error_message' => $error,
            'started_at' => now(), 'finished_at' => now(),
        ]);
    }
}
