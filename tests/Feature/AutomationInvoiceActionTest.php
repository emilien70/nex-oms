<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Automation\Services\AutomationInvoiceActionService;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceOperationSource;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class AutomationInvoiceActionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_editor_lists_issue_invoice_action_and_active_invoice_series(): void
    {
        $active = $this->createDocumentSeries(attributes: ['name' => 'Automatyczne FV']);
        $inactive = $this->createDocumentSeries(attributes: [
            'name' => 'Ukryte FV',
            'is_active' => false,
        ]);
        $wrongType = $this->createDocumentSeries(InvoiceDocumentType::Proforma, [
            'name' => 'Automatyczne PF',
        ]);

        $catalog = app(AutomationCatalog::class);
        $this->assertSame('Wystaw Fakturę', $catalog->actionLabel(AutomationCatalog::ACTION_ISSUE_INVOICE));
        $this->assertArrayHasKey($active->id, $catalog->invoiceSeries());
        $this->assertArrayNotHasKey($inactive->id, $catalog->invoiceSeries());
        $this->assertArrayNotHasKey($wrongType->id, $catalog->invoiceSeries());

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee(AutomationCatalog::ACTION_ISSUE_INVOICE)
            ->assertSee('const invoiceSeries', false)
            ->assertSee('SERIA NUMERACJI')
            ->assertSee($active->name)
            ->assertDontSee($inactive->name)
            ->assertDontSee($wrongType->name);
    }

    public function test_rule_stores_explicit_invoice_series_id(): void
    {
        $series = $this->createDocumentSeries();

        $this->post(route('orders.automatic-actions.store'), $this->rulePayload($series->id))
            ->assertSessionDoesntHaveErrors();

        $action = AutomationRule::query()->with('actions')->firstOrFail()->actions->firstOrFail();

        $this->assertSame(AutomationCatalog::ACTION_ISSUE_INVOICE, $action->action_type);
        $this->assertSame($series->id, $action->configuration['invoice_series_id']);
    }

    public function test_rule_rejects_inactive_or_non_invoice_series(): void
    {
        $inactive = $this->createDocumentSeries(attributes: ['is_active' => false]);
        $proforma = $this->createDocumentSeries(InvoiceDocumentType::Proforma);

        foreach ([$inactive->id, $proforma->id, 999999, null] as $seriesId) {
            $this->postJson(route('orders.automatic-actions.store'), $this->rulePayload($seriesId))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('actions.0.configuration.invoice_series_id');
        }

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_matching_rule_issues_invoice_with_selected_series(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $rule = $this->createRule($series->id);

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->id,
            'old_status' => 'pending',
            'new_status' => $order->status,
            'source' => 'manual',
        ]);

        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
        $run = AutomationRun::query()->with('steps')->firstOrFail();
        $step = $run->steps->firstOrFail();
        $event = $order->events()->where('event_type', 'invoice_issued')->firstOrFail();

        $this->assertSame($series->id, $invoice->invoice_series_id);
        $this->assertTrue($invoice->isIssued());
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('completed', $step->status);
        $this->assertSame($invoice->id, $step->output['invoice_id']);
        $this->assertSame($series->id, $step->output['invoice_series_id']);
        $this->assertSame(InvoiceOperationSource::Automation->value, $step->output['source']);
        $this->assertSame(InvoiceOperationSource::Automation->value, $event->payload['source']);
        $this->assertSame($rule->id, $run->automation_rule_id);
    }

    public function test_execution_rechecks_series_activity(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $this->createRule($series->id);
        $series->update(['is_active' => false]);

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->id,
            'source' => 'manual',
        ]);

        $run = AutomationRun::query()->with('steps')->firstOrFail();

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->steps->firstOrFail()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_action_preserves_invoice_already_exists_domain_error(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        app(InvoiceIssuingService::class)->issue($order, $series, $this->documentContext());
        $rule = $this->createRule($series->id);
        $run = AutomationRun::query()->create([
            'automation_rule_id' => $rule->id,
            'order_id' => $order->id,
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => AutomationRun::STATUS_RUNNING,
            'event_payload' => [],
            'rule_snapshot' => ['name' => $rule->name],
        ]);

        try {
            app(AutomationInvoiceActionService::class)->execute($run, [
                'invoice_series_id' => $series->id,
            ]);
            $this->fail('Oczekiwano błędu invoice_already_exists.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_already_exists', $exception->errorCode());
        }

        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
    }

    private function createRule(int $seriesId): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Automatyczna Faktura VAT',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_ISSUE_INVOICE,
            'configuration' => ['invoice_series_id' => $seriesId],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
    }

    private function rulePayload(mixed $seriesId): array
    {
        return [
            'name' => 'Automatyczna Faktura VAT',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_ISSUE_INVOICE,
                'configuration' => ['invoice_series_id' => $seriesId],
                'stop_on_error' => '1',
            ]],
        ];
    }
}
