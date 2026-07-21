<?php

namespace Tests\Feature;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Tests\TestCase;

class AutomationCallUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_url_action_is_available_and_requires_valid_http_url(): void
    {
        $this->assertSame(
            html_entity_decode('Wywo&#322;aj URL', ENT_QUOTES, 'UTF-8'),
            app(AutomationCatalog::class)->actions()[AutomationCatalog::ACTION_CALL_URL],
        );

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee(AutomationCatalog::ACTION_CALL_URL);

        $this->post(route('orders.automatic-actions.store'), [
            'name' => '',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CALL_URL,
                'configuration' => ['url' => 'ftp://example.com/webhook'],
                'stop_on_error' => '1',
            ]],
        ])->assertSessionHasErrors('actions.0.configuration.url');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_matching_rule_calls_url_with_get_and_logs_response(): void
    {
        Http::fake([
            'https://example.com/oms-hook*' => Http::response(['accepted' => true], 200),
        ]);

        $rule = $this->rule('https://example.com/oms-hook?source=nex');
        $order = $this->order();

        $this->evaluate($order);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://example.com/oms-hook?source=nex'
            && $request->hasHeader('X-NEX-OMS-Order-ID', (string) $order->id));

        $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertDatabaseHas('automation_run_steps', [
            'automation_run_id' => $run->id,
            'action_type' => AutomationCatalog::ACTION_CALL_URL,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('integration_api_logs', [
            'integration' => 'automation_url',
            'operation' => 'call_url',
            'order_id' => $order->id,
            'method' => 'GET',
            'response_status' => 200,
            'successful' => true,
        ]);
    }

    public function test_failed_url_response_fails_action_and_is_logged(): void
    {
        Http::fake([
            'https://example.com/failing-hook' => Http::response(['message' => 'failure'], 500),
        ]);

        $rule = $this->rule('https://example.com/failing-hook');
        $order = $this->order();

        $this->evaluate($order);

        $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertDatabaseHas('automation_run_steps', [
            'automation_run_id' => $run->id,
            'action_type' => AutomationCatalog::ACTION_CALL_URL,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('integration_api_logs', [
            'integration' => 'automation_url',
            'operation' => 'call_url',
            'response_status' => 500,
            'successful' => false,
        ]);
    }

    public function test_order_variables_are_url_encoded_and_replaced_in_get_url(): void
    {
        Carbon::setTestNow('2026-07-16 15:30:00');
        Http::fake(['https://multi-click.pl/*' => Http::response('OK', 200)]);

        $url = 'https://multi-click.pl/sndb/add.php?serial=[uwagi_sprzedawcy]'
            .'&sale_date=[data_zamowienia]&key=SNDB700';
        $rule = $this->rule($url);
        $order = $this->order();
        $order->update(['notes' => "SN001\nSN 002"]);

        $this->evaluate($order);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://multi-click.pl/sndb/add.php?serial=SN001%0ASN%20002'
                .'&sale_date=16.07.2026%2015%3A30&key=SNDB700');
        $this->assertDatabaseHas('automation_runs', [
            'automation_rule_id' => $rule->id,
            'status' => AutomationRun::STATUS_COMPLETED,
        ]);

        Carbon::setTestNow();
    }

    public function test_unknown_order_variable_prevents_rule_from_being_saved(): void
    {
        $this->post(route('orders.automatic-actions.store'), [
            'name' => '',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CALL_URL,
                'configuration' => ['url' => 'https://example.com/hook?date=[data_zamowenia]'],
                'stop_on_error' => '1',
            ]],
        ])->assertSessionHasErrors('actions.0.configuration.url');
    }

    private function rule(string $url): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Wywolaj zewnetrzny URL',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);

        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CALL_URL,
            'configuration' => ['url' => $url],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
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

    private function evaluate(Order $order): void
    {
        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'source' => 'manual',
            'old_status' => Order::STATUS_PENDING,
            'new_status' => Order::STATUS_NEW,
        ]);
    }
}
