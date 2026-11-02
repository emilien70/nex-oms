<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\OrderDataCompleted;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationConditionEvaluator;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationExecutionState;
use Modules\Automation\Services\AutomationOrderModificationService;
use Modules\Automation\Services\AutomationOrderSources;
use Modules\Automation\Services\AutomationRuleService;
use Modules\Automation\Services\AutomationRunner;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class AutomationOrderModificationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_editor_and_request_support_new_events_sources_and_modification(): void
    {
        $allegro = AllegroAccount::query()->create(['account_key' => 'test', 'name' => 'multi-click']);
        $presta = PrestashopAccount::query()->create(['account_key' => 'test', 'name' => 'multi-click.pl', 'base_url' => 'https://shop.example.test']);
        $data = $this->ruleData();
        $data['conditions'] = [$this->sources(['allegro:'.$allegro->id, 'prestashop:'.$presta->id])];
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $rule = AutomationRule::query()->sole();
        $this->assertSame($data['conditions'], $rule->conditions);
        $this->assertSame('notes', $rule->actions()->sole()->configuration['field']);
        $this->get(route('orders.automatic-actions.index'))->assertOk()
            ->assertSee('Pobrano kompletne dane zamówienia')->assertSee('Utworzono zamówienie')
            ->assertSee('Zmodyfikuj zamówienie')->assertSee('data-order-sources-root', false)
            ->assertSee('multi-click (Allegro)')->assertSee('multi-click.pl (PrestaShop)');
    }

    public function test_sources_are_or_within_selection_and_and_with_other_conditions(): void
    {
        $a = AllegroAccount::query()->create(['account_key' => 'a', 'name' => 'Pierwsze']);
        $b = AllegroAccount::query()->create(['account_key' => 'b', 'name' => 'Drugie']);
        $presta = PrestashopAccount::query()->create(['account_key' => 'p', 'name' => 'Sklep', 'base_url' => 'https://shop.example.test']);
        $order = $this->createDocumentOrder(['source' => 'prestashop']);
        PrestashopOrderLink::query()->create([
            'prestashop_account_id' => $presta->id, 'order_id' => $order->id,
            'external_order_id' => 123, 'external_reference' => 'REF-123',
            'source_hash' => str_repeat('a', 64), 'imported_at' => now(),
        ]);
        $evaluator = app(AutomationConditionEvaluator::class);
        $conditions = [$this->sources(['allegro:'.$a->id, 'prestashop:'.$presta->id])];
        $this->assertTrue($evaluator->matches($order, [], $conditions));
        $this->assertFalse($evaluator->matches($order, [], [$this->sources(['allegro:'.$b->id])]));
        $this->assertFalse($evaluator->matches($order, [], [...$conditions, [
            'field' => 'order_status', 'operator' => 'equals', 'value' => 'shipped',
        ]]));
        $this->assertTrue($evaluator->matches($order, [], [['field' => 'source', 'operator' => 'equals', 'value' => 'prestashop']]));
        $presta->update(['name' => 'Nowa nazwa']);
        $this->assertTrue($evaluator->matches($order->fresh(), [], $conditions));
        $this->assertSame('Nowa nazwa (PrestaShop)', app(AutomationOrderSources::class)->options()['prestashop:'.$presta->id]);
        $payload = ['order_source' => ['channel' => 'allegro', 'account_id' => $a->id]];
        $this->assertTrue($evaluator->matches($order, $payload, [$this->sources(['allegro:'.$a->id])]));
        $this->assertFalse($evaluator->matches($order, $payload, [$this->sources(['prestashop:'.$presta->id])]));
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_selection_is_rejected(mixed $value): void
    {
        $data = $this->ruleData();
        $data['conditions'] = [$this->sources($value)];
        $this->postJson(route('orders.automatic-actions.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('conditions.0.value');
        $this->assertDatabaseCount('automation_rules', 0);
    }

    public static function invalidSources(): array
    {
        return [
            'empty' => [[]], 'scalar' => ['prestashop'], 'unknown account' => [['prestashop:99999']],
            'all and account' => [['*', 'allegro']], 'duplicate' => [['allegro', 'allegro']],
            'nested' => [[['allegro']]], 'not a list' => [['channel' => 'allegro']],
        ];
    }

    #[DataProvider('invalidModifications')]
    public function test_invalid_or_protected_field_configuration_is_rejected(array $configuration): void
    {
        $data = $this->ruleData();
        $data['actions'][0]['configuration'] = $configuration;
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('automation_rules', 0);
    }

    public static function invalidModifications(): array
    {
        return [
            'financial' => [['field' => 'paid_amount', 'value' => '100']],
            'source' => [['field' => 'source', 'value' => 'manual']],
            'id' => [['field' => 'id', 'value' => '15']],
            'status' => [['field' => 'status', 'value' => 'shipped']],
            'email' => [['field' => 'customer_email', 'value' => 'invalid']],
            'country' => [['field' => 'shipping_country_code', 'value' => 'XX']],
            'empty country' => [['field' => 'shipping_country_code', 'value' => '']],
            'missing value' => [['field' => 'notes']],
            'unknown variable' => [['field' => 'notes', 'value' => '[nie_ma_takiej_zmiennej]']],
            'wrong type' => [['field' => 'notes', 'value' => ['text']]],
            'too long nip' => [['field' => 'billing_tax_id', 'value' => str_repeat('1', 33)]],
        ];
    }

    public function test_modifications_run_in_order_normalize_phone_and_preserve_zero_and_empty(): void
    {
        $order = $this->createDocumentOrder();
        $run = $this->execute($order, [
            $this->modify('customer_phone', '500 600 700'),
            $this->modify('notes', '[telefon_kupujacego] / [id_zamowienia]'),
            $this->modify('billing_tax_id', ''),
            $this->modify('customer_login', '0'),
            $this->modify('shipping_country_code', 'de'),
        ]);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $order->refresh();
        $this->assertSame('+48 500 600 700', $order->customer_phone);
        $this->assertSame('+48 500 600 700 / '.$order->id, $order->notes);
        $this->assertNull($order->billing_tax_id);
        $this->assertSame('0', $order->customer_login);
        $this->assertSame('DE', $order->shipping_country_code);
        $this->assertSame(5, $order->events()->where('event_type', 'automation_order_modified')->count());
        Http::assertNothingSent();
    }

    public function test_new_invoice_sees_modified_fields_and_existing_invoice_remains_unchanged(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $run = $this->execute($order, [
            $this->modify('billing_tax_id', '5250001009'),
            $this->modify('notes', 'Nowy numer seryjny'),
            ['type' => AutomationCatalog::ACTION_ISSUE_INVOICE, 'configuration' => ['invoice_series_id' => $series->id], 'stop_on_error' => true],
        ]);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
        $invoice = $order->invoices()->sole();
        $this->assertSame('5250001009', $invoice->buyer_snapshot['tax_id']);
        $before = $invoice->getAttributes();
        $this->execute($order, [$this->modify('billing_tax_id', '1234567890'), $this->modify('notes', 'Jeszcze inne uwagi')]);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_rendered_value_is_validated_and_failure_rolls_back_without_order_event(): void
    {
        $order = $this->createDocumentOrder(['notes' => 'nie jest adresem e-mail']);
        $run = $this->execute($order, [$this->modify('customer_email', '[uwagi_sprzedawcy]')]);
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('klient@example.test', $order->fresh()->customer_email);
        $this->assertSame(0, $order->events()->where('event_type', 'automation_order_modified')->count());
    }

    public function test_modifying_buyer_details_keeps_portal_url_but_revokes_previous_verification(): void
    {
        $order = $this->createDocumentOrder();
        $portal = app(PortalAccessService::class);
        $portal->enable();
        $portal->provision($order->id);
        $before = PortalLink::query()->where('order_id', $order->id)->sole();
        $url = $portal->orderLink($order)['url'];
        $this->execute($order, [$this->modify('customer_email', 'new@example.test')]);
        $after = $before->fresh();
        $this->assertSame($url, $portal->orderLink($order->fresh())['url']);
        $this->assertSame($before->token_hash, $after->token_hash);
        $this->assertSame($before->generation + 1, $after->generation);
        Http::assertNothingSent();
    }

    public function test_failed_receipt_write_rolls_back_field_and_history_in_the_same_transaction(): void
    {
        $order = $this->createDocumentOrder();
        $rule = app(AutomationRuleService::class)->create($this->ruleData());
        app(AutomationEngine::class)->evaluate($rule->trigger, ['order_id' => $order->id, 'event_id' => (string) Str::uuid()]);
        $state = app(AutomationExecutionState::class);
        $run = $state->claim($order->automationRuns()->sole(), 0, (string) Str::uuid());
        $step = $state->startStep($run, $run->execution_token, 0, $run->rule_snapshot['actions'][0]);
        $listener = function ($step): void {
            if (data_get($step->output, 'order_modification') === true) {
                throw new RuntimeException('Receipt unavailable');
            }
        };
        AutomationRunStep::updating($listener);
        try {
            app(AutomationOrderModificationService::class)->execute($run, $step, ['field' => 'notes', 'value' => 'New']);
            $this->fail('Receipt error must roll back the mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Receipt unavailable', $exception->getMessage());
        } finally {
            AutomationRunStep::flushEventListeners();
        }
        $this->assertSame("SN-001\nSN-002", $order->fresh()->notes);
        $this->assertNull($step->fresh()->output);
        $this->assertSame(0, $order->events()->where('event_type', 'automation_order_modified')->count());
    }

    public function test_duplicate_step_uses_atomic_receipt_and_does_not_overwrite_later_manual_change(): void
    {
        $order = $this->createDocumentOrder();
        $rule = app(AutomationRuleService::class)->create($this->ruleData());
        app(AutomationEngine::class)->evaluate($rule->trigger, ['order_id' => $order->id, 'event_id' => (string) Str::uuid()]);
        $run = $order->automationRuns()->sole();
        $state = app(AutomationExecutionState::class);
        $token = (string) Str::uuid();
        $run = $state->claim($run, 0, $token);
        $action = $run->rule_snapshot['actions'][0];
        $step = $state->startStep($run, $token, 0, $action);
        $service = app(AutomationOrderModificationService::class);
        $first = $service->execute($run, $step, $action['configuration']);
        $this->assertSame($first, $step->fresh()->output);
        $order->update(['notes' => 'Późniejsza poprawka ręczna']);
        $this->assertSame($first, $service->execute($run, $step, $action['configuration']));
        $this->assertSame('Późniejsza poprawka ręczna', $order->fresh()->notes);
        $this->assertSame(1, $order->events()->where('event_type', 'automation_order_modified')->count());
    }

    public function test_no_change_is_a_noop_and_incomplete_allegro_remains_blocked(): void
    {
        $order = $this->createDocumentOrder(['notes' => 'Bez zmian']);
        $run = $this->execute($order, [$this->modify('notes', 'Bez zmian')]);
        $this->assertFalse($run->steps()->sole()->output['changed']);
        $this->assertSame(0, $order->events()->where('event_type', 'automation_order_modified')->count());
        $order->update(['source' => 'allegro']);
        AllegroOrderLink::query()->create([
            'order_id' => $order->id, 'environment' => 'sandbox', 'seller_user_id' => '123',
            'account_name' => 'Test', 'external_status' => 'BOUGHT',
            'external_order_id' => (string) Str::uuid(), 'source_hash' => str_repeat('a', 64),
            'import_state' => 'incomplete', 'imported_at' => now(),
        ]);
        $run = $this->execute($order, [$this->modify('customer_phone', '500 600 700')]);
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('+48 500 000 000', $order->fresh()->customer_phone);
    }

    public function test_empty_value_can_be_saved_to_explicitly_clear_a_field(): void
    {
        $data = $this->ruleData();
        $data['actions'][0] = $this->modify('billing_tax_id', '');
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $this->assertArrayHasKey('value', AutomationRule::query()->sole()->actions()->sole()->configuration);
    }

    public function test_manual_creation_event_is_after_commit_and_not_emitted_by_edits(): void
    {
        $data = $this->ruleData();
        $data['trigger'] = 'order.created';
        app(AutomationRuleService::class)->create($data);
        $this->post(route('orders.empty-store'))->assertRedirect();
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        $order = Order::query()->sole();
        Queue::assertPushed(EvaluateAutomationEventJob::class, fn ($job) => $job->eventName === 'order.created'
            && $job->eventPayload['order_source'] === ['channel' => 'manual', 'account_id' => null]);
        $order->update(['notes' => 'Ręczna zmiana']);
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        $this->assertSame((new OrderCreated($order))->payload()['event_id'], (new OrderCreated($order->fresh()))->payload()['event_id']);
    }

    public function test_rollback_does_not_dispatch_new_order_events_and_duplicate_event_does_not_duplicate_run(): void
    {
        $rule = app(AutomationRuleService::class)->create($this->ruleData());
        DB::beginTransaction();
        $order = $this->createDocumentOrder();
        OrderDataCompleted::dispatch($order);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $order = $this->createDocumentOrder();
        $event = new OrderDataCompleted($order);
        app(AutomationEngine::class)->evaluate($rule->trigger, $event->payload());
        app(AutomationEngine::class)->evaluate($rule->trigger, $event->payload());
        $this->assertSame(1, $order->automationRuns()->count());
    }

    private function execute(Order $order, array $actions): AutomationRun
    {
        $data = $this->ruleData();
        $data['actions'] = $actions;
        $rule = app(AutomationRuleService::class)->create($data);
        app(AutomationEngine::class)->evaluate($rule->trigger, ['order_id' => $order->id, 'event_id' => (string) Str::uuid()]);
        $run = $order->automationRuns()->where('automation_rule_id', $rule->id)->sole();
        app(AutomationRunner::class)->execute($run, 0);

        return $run;
    }

    private function ruleData(): array
    {
        return ['name' => 'Modyfikacja', 'trigger' => 'order.data_completed', 'is_active' => true,
            'conditions' => [], 'actions' => [$this->modify('notes', 'Automatyczna notatka')]];
    }

    private function modify(string $field, string $value): array
    {
        return ['type' => AutomationCatalog::ACTION_MODIFY_ORDER,
            'configuration' => compact('field', 'value'), 'stop_on_error' => true];
    }

    private function sources(mixed $value): array
    {
        return ['field' => 'integration_source', 'operator' => 'in', 'value' => $value];
    }
}
