<?php

namespace Tests\Feature;

use App\Exceptions\OrderPaymentStateException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderPaymentStateService;
use App\Services\OrderTotalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Services\KsefFa3EligibilityValidator;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderPaymentStateTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_quick_payment_marks_the_full_current_total_as_paid(): void
    {
        $order = $this->paymentOrder();

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'paid',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('100.00', $order->paid_amount);
    }

    public function test_quick_payment_clears_a_full_payment_when_marked_unpaid(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'unpaid',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('0.00', $order->paid_amount);
    }

    public function test_quick_payment_preserves_a_valid_partial_payment(): void
    {
        $order = $this->paymentOrder(['paid_amount' => '40.00']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'unpaid',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('40.00', $order->paid_amount);
    }

    public function test_quick_payment_rejects_refunded_without_mutation(): void
    {
        $order = $this->paymentOrder(['payment_method' => 'Przelew']);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'refunded',
            'payment_method' => 'Gotówka',
        ])->assertSessionHasErrors('payment_status');

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('Przelew', $order->payment_method);
    }

    public function test_paid_amount_editor_derives_the_matching_status(): void
    {
        $order = $this->paymentOrder();

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => '100.00',
        ])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->patchJson(route('orders.paid-amount.update', $order), [
            'paid_amount' => '40.00',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('40.00', $order->paid_amount);
    }

    #[DataProvider('validPaymentPairs')]
    public function test_full_order_create_accepts_consistent_payment_pairs(string $status, string $paidAmount): void
    {
        $this->post(route('orders.store'), $this->orderPayload($status, $paidAmount))
            ->assertSessionDoesntHaveErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($status, $order->payment_status);
        $this->assertSame($paidAmount, $order->paid_amount);
        $this->assertSame('100.00', $order->total_gross);
    }

    #[DataProvider('invalidPaymentPairs')]
    public function test_full_order_create_rejects_inconsistent_payment_pairs(string $status, string $paidAmount): void
    {
        $expectedMessage = $paidAmount === '101.00'
            ? 'Kwota zapłacona nie może przekraczać wartości zamówienia.'
            : 'Status płatności i kwota zapłacona są niespójne.';

        $this->postJson(route('orders.store'), $this->orderPayload($status, $paidAmount))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount')
            ->assertJsonPath(
                'errors.paid_amount.0',
                $expectedMessage,
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    #[DataProvider('validPaymentPairs')]
    public function test_full_order_edit_accepts_consistent_payment_pairs(string $status, string $paidAmount): void
    {
        $order = $this->paymentOrder();
        $this->paymentItem($order, '100.00');

        $this->put(route('orders.update', $order), $this->orderPayload($status, $paidAmount))
            ->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame($status, $order->payment_status);
        $this->assertSame($paidAmount, $order->paid_amount);
        $this->assertSame('100.00', $order->total_gross);
    }

    #[DataProvider('invalidPaymentPairs')]
    public function test_full_order_edit_rejects_inconsistent_payment_pairs_without_partial_write(
        string $status,
        string $paidAmount,
    ): void {
        $order = $this->paymentOrder(['payment_method' => 'Przelew']);
        $item = $this->paymentItem($order, '100.00');

        $payload = $this->orderPayload($status, $paidAmount);
        $payload['payment_method'] = 'Gotówka';

        $this->putJson(route('orders.update', $order), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount');

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertSame('Przelew', $order->payment_method);
        $this->assertSame('Produkt płatności', $item->fresh()->product_name);
    }

    public function test_full_order_form_rejects_refunded_status(): void
    {
        $this->postJson(route('orders.store'), $this->orderPayload('refunded', '0.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_status');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_recalculation_preserves_actual_full_payment_and_derives_unpaid_after_total_increase(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $this->paymentItem($order, '120.00');

        app(OrderTotalService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame('120.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_recalculation_preserves_a_partial_payment_after_total_increase(): void
    {
        $order = $this->paymentOrder(['paid_amount' => '40.00']);
        $this->paymentItem($order, '120.00');

        app(OrderTotalService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame('120.00', $order->total_gross);
        $this->assertSame('40.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_recalculation_marks_a_partial_payment_as_paid_when_it_covers_the_new_total(): void
    {
        $order = $this->paymentOrder(['paid_amount' => '40.00']);
        $this->paymentItem($order, '40.00');

        app(OrderTotalService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame('40.00', $order->total_gross);
        $this->assertSame('40.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_recalculation_keeps_a_fully_paid_order_paid_when_total_is_unchanged(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $this->paymentItem($order, '100.00');

        app(OrderTotalService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame('100.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_recalculation_marks_an_eighty_unit_partial_payment_as_paid_at_the_new_total(): void
    {
        $order = $this->paymentOrder(['paid_amount' => '80.00']);
        $this->paymentItem($order, '80.00');

        app(OrderTotalService::class)->recalculate($order);

        $order->refresh();
        $this->assertSame('80.00', $order->total_gross);
        $this->assertSame('80.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
    }

    #[DataProvider('recalculationOverpayments')]
    public function test_recalculation_rejects_an_overpayment_without_changing_order_totals(
        string $status,
        string $paidAmount,
        string $newTotal,
    ): void {
        $order = $this->paymentOrder([
            'payment_status' => $status,
            'paid_amount' => $paidAmount,
        ]);
        $this->paymentItem($order, $newTotal);

        try {
            app(OrderTotalService::class)->recalculate($order);
            $this->fail('Expected an inconsistent payment state to be rejected.');
        } catch (OrderPaymentStateException $exception) {
            $this->assertSame(
                'Kwota zapłacona nie może przekraczać wartości zamówienia.',
                $exception->getMessage(),
            );
        }

        $order->refresh();
        $this->assertSame('100.00', $order->total_gross);
        $this->assertSame($paidAmount, $order->paid_amount);
        $this->assertSame($status, $order->payment_status);
    }

    public function test_product_increase_preserves_actual_payment_and_derives_unpaid_status(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $item = $this->paymentItem($order, '100.00');

        $this->patchJson(route('order-items.update', $item), $this->productPayload('120.00'))
            ->assertOk();

        $order->refresh();
        $this->assertSame('120.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('120.00', $item->fresh()->total_price_gross);
    }

    public function test_product_decrease_below_actual_payment_is_rejected_atomically(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $item = $this->paymentItem($order, '100.00');

        $this->patchJson(route('order-items.update', $item), $this->productPayload('80.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount');

        $order->refresh();
        $this->assertSame('100.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('100.00', $item->fresh()->total_price_gross);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'product_updated',
        ]);
    }

    public function test_delivery_increase_preserves_actual_payment_and_derives_unpaid_status(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $this->paymentItem($order, '100.00');

        $this->patchJson(route('orders.sections.order-info', $order), $this->orderInfoPayload('20.00'))
            ->assertOk();

        $order->refresh();
        $this->assertSame('120.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('20.00', $order->delivery_cost_gross);
    }

    public function test_delivery_decrease_below_actual_payment_is_rejected_atomically(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
            'delivery_cost_gross' => '20.00',
        ]);
        $this->paymentItem($order, '80.00');

        $this->patchJson(route('orders.sections.order-info', $order), $this->orderInfoPayload('0.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount');

        $order->refresh();
        $this->assertSame('100.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('20.00', $order->delivery_cost_gross);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'order_info_updated',
        ]);
    }

    public function test_manual_recalculation_preserves_an_unchanged_full_payment(): void
    {
        $order = $this->paymentOrder([
            'payment_status' => 'paid',
            'paid_amount' => '100.00',
        ]);
        $this->paymentItem($order, '100.00');

        $this->patchJson(route('orders.recalculate-total', $order))
            ->assertOk();

        $order->refresh();
        $this->assertSame('100.00', $order->total_gross);
        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_zero_total_preserves_the_existing_supported_status_with_zero_paid_amount(): void
    {
        $service = app(OrderPaymentStateService::class);

        $this->assertSame(
            ['payment_status' => 'unpaid', 'paid_amount' => '0.00'],
            $service->explicit('0.00', '0.00', 'unpaid'),
        );
        $this->assertSame(
            ['payment_status' => 'paid', 'paid_amount' => '0.00'],
            $service->explicit('0.00', '0.00', 'paid'),
        );

        foreach (['unpaid', 'paid'] as $status) {
            $order = $this->paymentOrder([
                'total_gross' => '0.00',
                'paid_amount' => '0.00',
                'payment_status' => $status,
            ]);

            $this->assertSame(
                ['payment_status' => $status, 'paid_amount' => '0.00'],
                $service->afterTotalRecalculation($order, '0.00'),
            );
        }
    }

    public function test_paid_to_unpaid_quick_update_produces_a_consistent_invoice_and_fa3_preflight(): void
    {
        $order = $this->createDocumentOrder([
            'total_gross' => '2176.00',
            'paid_amount' => '2176.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'paid',
            'billing_tax_id' => '5260250995',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '2176.00',
            'total_price_gross' => '2176.00',
        ]);

        $this->patch(route('orders.sections.payment', $order), [
            'payment_status' => 'unpaid',
        ])->assertSessionDoesntHaveErrors();

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('0.00', $order->paid_amount);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame('unpaid', $invoice->payment_snapshot['payment_status']);
        $this->assertSame('0.00', $invoice->payment_snapshot['paid_amount']);
        $this->assertSame('2176.00', $invoice->payment_snapshot['amount_due']);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('2176.00', $invoice->amount_due);

        app(KsefFa3EligibilityValidator::class)->assertEligible(
            $invoice,
            app(KsefSettingsService::class)->get(),
            KsefFa3EligibilityMode::Preflight,
        );
        Http::assertNothingSent();
    }

    public function test_partial_payment_after_product_increase_is_snapshotted_and_passes_fa3_preflight(): void
    {
        $order = $this->createDocumentOrder([
            'total_gross' => '100.00',
            'paid_amount' => '100.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'paid',
            'billing_tax_id' => '5260250995',
        ]);
        $item = $this->createDocumentItem($order);

        $this->patchJson(route('order-items.update', $item), $this->productPayload('120.00'))
            ->assertOk();

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order->fresh(),
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame('120.00', $invoice->total_gross);
        $this->assertSame('100.00', $invoice->paid_amount);
        $this->assertSame('20.00', $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->payment_snapshot['payment_status']);
        $this->assertSame('100.00', $invoice->payment_snapshot['paid_amount']);
        $this->assertSame('20.00', $invoice->payment_snapshot['amount_due']);

        app(KsefFa3EligibilityValidator::class)->assertEligible(
            $invoice,
            app(KsefSettingsService::class)->get(),
            KsefFa3EligibilityMode::Preflight,
        );
        Http::assertNothingSent();
    }

    #[DataProvider('invoicePaymentSnapshots')]
    public function test_invoice_snapshots_only_consistent_order_payment_states(
        string $status,
        string $paidAmount,
        string $amountDue,
    ): void {
        $order = $this->createDocumentOrder([
            'total_gross' => '100.00',
            'paid_amount' => $paidAmount,
            'delivery_cost_gross' => '0.00',
            'payment_status' => $status,
        ]);
        $this->createDocumentItem($order);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );

        $this->assertSame($status, $invoice->payment_snapshot['payment_status']);
        $this->assertSame($paidAmount, $invoice->payment_snapshot['paid_amount']);
        $this->assertSame($amountDue, $invoice->payment_snapshot['amount_due']);
        $this->assertSame($paidAmount, $invoice->paid_amount);
        $this->assertSame($amountDue, $invoice->amount_due);
    }

    public function test_database_seeder_creates_only_consistent_payment_states(): void
    {
        $this->seed(DatabaseSeeder::class);

        $orders = Order::query()->where('total_gross', '>', 0)->get();
        $this->assertCount(4, $orders);
        $this->assertSame(0, Order::query()->where('payment_status', 'refunded')->count());

        foreach ($orders as $order) {
            if ($order->payment_status === 'paid') {
                $this->assertSame($order->total_gross, $order->paid_amount);
            } else {
                $this->assertSame('unpaid', $order->payment_status);
                $this->assertLessThan(0, app(InvoiceDecimalCalculator::class)->compare(
                    (string) $order->paid_amount,
                    (string) $order->total_gross,
                ));
            }
        }
    }

    public static function validPaymentPairs(): array
    {
        return [
            'unpaid zero' => ['unpaid', '0.00'],
            'unpaid partial' => ['unpaid', '40.00'],
            'paid full' => ['paid', '100.00'],
        ];
    }

    public static function invalidPaymentPairs(): array
    {
        return [
            'unpaid full' => ['unpaid', '100.00'],
            'paid zero' => ['paid', '0.00'],
            'paid partial' => ['paid', '40.00'],
            'overpaid' => ['unpaid', '101.00'],
        ];
    }

    public static function invoicePaymentSnapshots(): array
    {
        return [
            'paid full' => ['paid', '100.00', '0.00'],
            'unpaid zero' => ['unpaid', '0.00', '100.00'],
            'unpaid partial' => ['unpaid', '40.00', '60.00'],
        ];
    }

    public static function recalculationOverpayments(): array
    {
        return [
            'fully paid order reduced to eighty' => ['paid', '100.00', '80.00'],
            'partial payment reduced below eighty' => ['unpaid', '80.00', '60.00'],
            'fully paid order reduced to zero' => ['paid', '100.00', '0.00'],
        ];
    }

    private function paymentOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
            'payment_status' => 'unpaid',
            'shipping_country_code' => 'PL',
            'billing_country_code' => 'PL',
        ], $attributes));
    }

    private function paymentItem(Order $order, string $total): OrderItem
    {
        return $order->items()->create([
            'product_name' => 'Produkt płatności',
            'quantity' => 1,
            'unit_price_gross' => $total,
            'total_price_gross' => $total,
            'currency' => 'PLN',
        ]);
    }

    /** @return array<string, mixed> */
    private function productPayload(string $unitPriceGross): array
    {
        return [
            'product_name' => 'Produkt płatności',
            'quantity' => 1,
            'unit_price_gross' => $unitPriceGross,
            'currency' => 'PLN',
            'vat_rate' => '23',
        ];
    }

    /** @return array<string, mixed> */
    private function orderInfoPayload(string $deliveryCostGross): array
    {
        return [
            'source' => 'manual',
            'cash_on_delivery' => false,
            'delivery_cost_gross' => $deliveryCostGross,
        ];
    }

    /** @return array<string, mixed> */
    private function orderPayload(string $status, string $paidAmount): array
    {
        return [
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'shipping_country_code' => 'PL',
            'billing_country_code' => 'PL',
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => $paidAmount,
            'delivery_cost_gross' => '0.00',
            'cash_on_delivery' => '0',
            'payment_status' => $status,
            'payment_method' => 'Przelew',
            'items' => [[
                'product_name' => 'Produkt formularza',
                'quantity' => 1,
                'unit_price_gross' => '100.00',
            ]],
        ];
    }
}
