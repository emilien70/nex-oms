<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Invoices\Services\InvoiceIssuingService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderStatusPollingTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_order_state_endpoint_returns_current_status_for_live_view_updates(): void
    {
        Carbon::setTestNow('2026-07-16 13:22:00');

        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'status_changed_at' => now(),
            'currency' => 'PLN',
            'total_gross' => 0,
            'payment_status' => 'unpaid',
        ]);

        $this->getJson(route('orders.state', $order))
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('status', Order::STATUS_NEW)
            ->assertJsonPath('status_label', 'Nowe')
            ->assertJsonPath('status_color', '#f4ad42')
            ->assertJsonPath('status_changed_at.date', '2026-07-16')
            ->assertJsonPath('status_changed_at.time', '13:22');
    }

    public function test_status_can_be_changed_with_ajax_and_returns_fresh_state(): void
    {
        Carbon::setTestNow('2026-07-16 14:05:00');

        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'payment_status' => 'unpaid',
        ]);

        $this->patchJson(route('orders.status.update', $order), [
            'status' => Order::STATUS_PENDING,
        ])
            ->assertOk()
            ->assertJsonPath('status', Order::STATUS_PENDING)
            ->assertJsonPath('status_label', 'Oczekujące')
            ->assertJsonPath('status_changed_at.time', '14:05');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_order_view_refreshes_after_automation_without_periodic_order_polling(): void
    {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => 0,
            'payment_status' => 'unpaid',
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-order-id="'.$order->id.'"', false)
            ->assertSee('nexoms:automation-finished', false)
            ->assertSee("refreshOrderState(['sales-documents', 'history'])", false)
            ->assertSee("refreshOrderState(['sales-documents'])", false)
            ->assertSee('scheduleKsefAutomaticRefresh', false)
            ->assertDontSee('setInterval(refreshOrderState', false);
    }

    public function test_order_state_endpoint_returns_sales_document_actions_after_automation_issues_invoice(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );

        $this->getJson(route('orders.state', [
            'order' => $order,
            'fragments' => 'sales-documents',
        ]))
            ->assertOk()
            ->assertJsonPath('fragments.sales-documents', function (string $html) use ($invoice): bool {
                return str_contains($html, 'id="order-sales-document-actions"')
                    && str_contains($html, 'management-issued-invoice-actions')
                    && str_contains($html, e((string) $invoice->number));
            });
    }
}
