<?php

namespace Tests\Feature\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Automation\Services\AutomationEngine;
use Modules\Invoices\Events\InvoiceIssued;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductStockMovement;
use Modules\Products\Services\OrderItemBundleSnapshotService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceStockAutomationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_editor_lists_invoice_trigger_and_stock_action_and_stores_empty_configuration(): void
    {
        $catalog = app(AutomationCatalog::class);

        $this->assertSame('Wystawiono Fakturę VAT', $catalog->triggerLabel('invoice.issued'));
        $this->assertSame(
            'Zmniejsz stan magazynowy',
            $catalog->actionLabel(AutomationCatalog::ACTION_DECREASE_STOCK),
        );

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('value="invoice.issued"', false)
            ->assertSee(AutomationCatalog::ACTION_DECREASE_STOCK)
            ->assertSee('Ka&#380;da pozycja zam&oacute;wienia mo&#380;e zosta&#263; odj&#281;ta tylko raz.', false);

        $this->post(route('orders.automatic-actions.store'), [
            'name' => 'Faktura zmniejsza magazyn',
            'description' => '',
            'trigger' => 'invoice.issued',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_DECREASE_STOCK,
                'configuration' => ['unexpected' => 'discarded'],
                'stop_on_error' => '1',
            ]],
        ])->assertSessionDoesntHaveErrors();

        $action = AutomationRule::query()->with('actions')->sole()->actions->sole();
        $this->assertSame(AutomationCatalog::ACTION_DECREASE_STOCK, $action->action_type);
        $this->assertSame([], $action->configuration);
    }

    public function test_stock_action_is_rejected_for_a_trigger_other_than_invoice_issued(): void
    {
        $this->postJson(route('orders.automatic-actions.store'), [
            'name' => 'Nieprawidłowa reguła magazynowa',
            'description' => '',
            'trigger' => 'order.status_changed',
            'is_active' => '1',
            'conditions' => [],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_DECREASE_STOCK,
                'configuration' => [],
                'stop_on_error' => '1',
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actions.0.type');

        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_invoice_event_decreases_only_linked_catalog_stock_and_never_twice(): void
    {
        Event::fake([InvoiceIssued::class]);
        $product = $this->product(['stock_quantity' => 10]);
        $order = $this->createDocumentOrder(['total_gross' => '93.00']);
        $linkedItem = $this->createDocumentItem($order, [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'quantity' => 3,
            'unit_price_gross' => '20.00',
            'total_price_gross' => '60.00',
        ]);
        $this->createDocumentItem($order, [
            'product_name' => 'Pozycja ręczna',
            'quantity' => 2,
            'unit_price_gross' => '5.00',
            'total_price_gross' => '10.00',
        ]);
        $series = $this->createDocumentSeries();
        $this->stockRule('Pomniejszenie magazynu A');
        $this->stockRule('Pomniejszenie magazynu B');

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );

        Event::assertDispatched(
            InvoiceIssued::class,
            fn (InvoiceIssued $event): bool => (
                $event->invoice->is($invoice)
                && $event->source === 'manual'
                && $event->payload()['order_id'] === $order->getKey()
            ),
        );

        $event = new InvoiceIssued($invoice, 'manual');
        app(AutomationEngine::class)->evaluate($event->name(), $event->payload());

        $movement = ProductStockMovement::query()->sole();
        $runs = AutomationRun::query()->with('steps')->orderBy('id')->get();

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertSame($linkedItem->getKey(), $movement->order_item_id);
        $this->assertSame($invoice->getKey(), $movement->invoice_id);
        $this->assertSame(-3, $movement->quantity_delta);
        $this->assertSame(10, $movement->quantity_before);
        $this->assertSame(7, $movement->quantity_after);
        $this->assertSame(
            'invoice_issued:order:'.$order->getKey().':order_item:'.$linkedItem->getKey(),
            $movement->idempotency_key,
        );
        $this->assertCount(2, $runs);
        $this->assertTrue($runs->every(
            fn (AutomationRun $run): bool => $run->status === AutomationRun::STATUS_COMPLETED,
        ));
        $this->assertSame([1, 0], $runs->map(
            fn (AutomationRun $run): int => $run->steps->sole()->output['movement_count'],
        )->all());
        $this->assertSame([0, 1], $runs->map(
            fn (AutomationRun $run): int => $run->steps->sole()->output['already_applied_count'],
        )->all());
        $this->assertDatabaseCount('product_stock_movements', 1);
        $this->assertDatabaseCount('order_events', 4);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'stock_decreased',
        ]);

        $productId = $product->getKey();
        $product->delete();

        $movement = $movement->fresh();
        $this->assertNull($movement->product_id);
        $this->assertSame($productId, $movement->product_snapshot['id']);
        $this->assertDatabaseCount('product_stock_movements', 1);
    }

    public function test_fractional_invoice_quantity_fails_without_partial_stock_change(): void
    {
        Event::fake([InvoiceIssued::class]);
        $product = $this->product(['stock_quantity' => 8]);
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order, [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $invoice->items()
            ->whereNotNull('product_id')
            ->firstOrFail()
            ->update(['quantity' => '1.5000']);
        $this->stockRule('Ułamkowa ilość');
        $event = new InvoiceIssued($invoice->fresh(), 'manual');

        app(AutomationEngine::class)->evaluate($event->name(), $event->payload());

        $run = AutomationRun::query()->with('steps')->sole();
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->steps->sole()->status);
        $this->assertStringContainsString('całkowitego stanu magazynowego', $run->steps->sole()->error_message);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'stock_decreased',
        ]);
    }

    public function test_bundle_deducts_snapshot_components_once_instead_of_bundle_stock(): void
    {
        Event::fake([InvoiceIssued::class]);
        $decoder = $this->product([
            'name' => 'Dekoder',
            'stock_quantity' => 10,
        ]);
        $adapter = $this->product([
            'name' => 'Adapter',
            'stock_quantity' => 20,
        ]);
        $replacement = $this->product([
            'name' => 'Pilot',
            'stock_quantity' => 30,
        ]);
        $bundle = $this->product([
            'item_type' => Product::TYPE_BUNDLE,
            'name' => 'Dekoder + WiFi',
            'stock_quantity' => 0,
        ]);
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $decoder->getKey(), 'quantity' => 1],
            ['component_product_id' => $adapter->getKey(), 'quantity' => 2],
        ]);
        $order = $this->createDocumentOrder(['total_gross' => '400.00']);
        $orderItem = $this->createDocumentItem($order, [
            'product_id' => $bundle->getKey(),
            'product_name' => $bundle->name,
            'quantity' => 2,
            'unit_price_gross' => '200.00',
            'total_price_gross' => '400.00',
        ]);
        app(OrderItemBundleSnapshotService::class)->capture($orderItem, $bundle);

        $bundle->bundleComponents()->delete();
        $bundle->bundleComponents()->createMany([
            ['component_product_id' => $decoder->getKey(), 'quantity' => 5],
            ['component_product_id' => $replacement->getKey(), 'quantity' => 1],
        ]);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
        $this->stockRule('Pomniejszenie zestawu A');
        $this->stockRule('Pomniejszenie zestawu B');
        $event = new InvoiceIssued($invoice, 'manual');

        app(AutomationEngine::class)->evaluate($event->name(), $event->payload());

        $this->assertSame(8, $decoder->fresh()->stock_quantity);
        $this->assertSame(16, $adapter->fresh()->stock_quantity);
        $this->assertSame(30, $replacement->fresh()->stock_quantity);
        $this->assertSame(0, $bundle->fresh()->stock_quantity);
        $this->assertDatabaseCount('product_stock_movements', 2);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $decoder->getKey(),
            'order_item_id' => $orderItem->getKey(),
            'quantity_delta' => -2,
            'idempotency_key' => 'invoice_issued:order:'.$order->getKey()
                .':order_item:'.$orderItem->getKey()
                .':component:'.$decoder->getKey(),
        ]);
        $this->assertDatabaseHas('product_stock_movements', [
            'product_id' => $adapter->getKey(),
            'order_item_id' => $orderItem->getKey(),
            'quantity_delta' => -4,
        ]);

        $runs = AutomationRun::query()->with('steps')->orderBy('id')->get();
        $this->assertSame([2, 0], $runs->map(
            fn (AutomationRun $run): int => $run->steps->sole()->output['movement_count'],
        )->all());
        $this->assertSame([0, 2], $runs->map(
            fn (AutomationRun $run): int => $run->steps->sole()->output['already_applied_count'],
        )->all());
    }

    public function test_invoice_issued_by_automation_continues_the_same_chain_for_stock_action(): void
    {
        Event::fake([InvoiceIssued::class]);
        $product = $this->product(['stock_quantity' => 5]);
        $order = $this->createDocumentOrder(['total_gross' => '223.00']);
        $this->createDocumentItem($order, [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price_gross' => '100.00',
            'total_price_gross' => '200.00',
        ]);
        $series = $this->createDocumentSeries();
        $issueRule = AutomationRule::query()->create([
            'name' => 'Automatyczne wystawienie',
            'trigger' => 'order.status_changed',
            'conditions' => [],
            'is_active' => true,
        ]);
        $issueRule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_ISSUE_INVOICE,
            'configuration' => ['invoice_series_id' => $series->getKey()],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $stockRule = $this->stockRule('Magazyn po automatycznej fakturze');

        app(AutomationEngine::class)->evaluate('order.status_changed', [
            'event_id' => (string) str()->uuid(),
            'event_name' => 'order.status_changed',
            'order_id' => $order->getKey(),
            'source' => 'manual',
        ]);

        $issueRun = AutomationRun::query()
            ->where('automation_rule_id', $issueRule->getKey())
            ->sole();
        $issuedEvent = null;
        Event::assertDispatched(
            InvoiceIssued::class,
            function (InvoiceIssued $event) use (&$issuedEvent, $issueRun): bool {
                $issuedEvent = $event;

                return $event->source === 'automation_run:'.$issueRun->getKey();
            },
        );
        $this->assertInstanceOf(InvoiceIssued::class, $issuedEvent);

        app(AutomationEngine::class)->evaluate($issuedEvent->name(), $issuedEvent->payload());

        $stockRun = AutomationRun::query()
            ->where('automation_rule_id', $stockRule->getKey())
            ->sole();
        $this->assertSame($issueRun->chain_id, $stockRun->chain_id);
        $this->assertSame(1, $stockRun->depth);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $stockRun->status);
        $this->assertDatabaseCount('product_stock_movements', 1);
    }

    private function product(array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Produkt magazynowy',
            'ean' => null,
            'price_gross' => '20.00',
            'currency' => 'PLN',
            'vat_rate' => '23.00',
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function stockRule(string $name): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'name' => $name,
            'trigger' => 'invoice.issued',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_DECREASE_STOCK,
            'configuration' => [],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
    }
}
