<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Automation\Jobs\EvaluateAutomationEventJob;
use Modules\Automation\Models\AutomationRule;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroOrderPreviewService;
use Modules\Integrations\Allegro\Services\AllegroOrderSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AllegroOrderFixture as Fixture;
use Tests\TestCase;

class AllegroPriceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    #[DataProvider('validReconciliations')]
    public function test_valid_reconciliation_does_not_change_buyer_amounts(string $type, int $quantity, string $amount, bool $payment, bool $surcharge): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['lineItems'][0]['reconciliation'] = $this->lineReconciliation($type, $quantity, $amount);
        if ($payment) {
            $raw['payment']['reconciliation'] = ['amount' => $amount, 'currency' => 'PLN'];
        }
        if ($surcharge) {
            $raw['surcharges'][0]['reconciliation'] = ['amount' => '0.01', 'currency' => 'PLN'];
        }
        $preview = app(AllegroOrderPreviewService::class)->normalize($account, Fixture::ORDER_ID, $raw);
        $this->assertTrue($preview['importable'], implode(' ', $preview['blockers']));
        $this->assertSame('100.03', $preview['total_gross']);
        $this->assertSame('100.03', $preview['paid_amount']);
        $this->assertSame('90.03', $preview['items'][0]['total_price_gross']);
        $this->assertSame('paid', $preview['payment_status']);
        $this->sync($account, $raw);
        $order = Order::query()->sole();
        $this->assertTrue($order->purchaseIsComplete());
        $this->assertSame('100.03', $order->paid_amount);
        $this->assertNull($order->allegroOrderLink->processing_error);
        $this->assertEqualsCanonicalizing(['100.02', '0.01'], $order->allegroOrderLink->payments()->pluck('amount')->all());
        $this->assertDatabaseCount('allegro_order_payments', 2);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('shipments', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('User-Agent', config('services.allegro.user_agent')));
    }

    public static function validReconciliations(): array
    {
        return [
            'billing all units' => ['BILLING', 3, '10.47', false, false],
            'billing some units' => ['BILLING', 1, '10.47', false, false],
            'wallet' => ['WALLET', 3, '10.47', true, false],
            'wallet surcharge' => ['WALLET', 1, '10.47', true, true],
            'zero reconciliation' => ['BILLING', 1, '0.00', false, false],
        ];
    }

    public function test_reviewed_purchase_is_completed_in_place_and_keeps_manual_data(): void
    {
        $account = $this->account();
        $raw = $this->order382Payload();
        $raw['lineItems'][0]['reconciliation']['type'] = 'UNKNOWN';
        $this->sync($account, $raw);
        $order = Order::query()->sole();
        $id = $order->id;
        $this->assertSame('review', $order->allegroOrderLink->import_state);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertNull($order->pickup_point_id);
        $order->update(['notes' => 'Reczna notatka', 'star_color' => 'green', 'status' => 'pending']);
        AutomationRule::query()->create(['name' => 'Creation', 'trigger' => 'order.created', 'is_active' => true]);
        AutomationRule::query()->create(['name' => 'Completion', 'trigger' => 'order.data_completed', 'is_active' => true]);

        $this->sync($account, $this->order382Payload());
        $order->refresh();
        $this->assertSame($id, $order->id);
        $this->assertTrue($order->purchaseIsComplete());
        $this->assertSame('complete', $order->allegroOrderLink->import_state);
        $this->assertNotNull($order->allegroOrderLink->completed_at);
        $this->assertNull($order->allegroOrderLink->processing_error);
        $this->assertSame('513.13', $order->total_gross);
        $this->assertSame('513.13', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pending', $order->status);
        $this->assertSame('Reczna notatka', $order->notes);
        $this->assertSame('green', $order->star_color);
        $this->assertSame('JDC02M', $order->pickup_point_id);
        $this->assertSame($order->shipping_name, $order->billing_name);
        $this->assertSame($order->shipping_street, $order->billing_street);
        $this->assertSame('513.13', $order->allegroOrderLink->payments()->sole()->amount);
        $this->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('Zamówienie wymaga sprawdzenia:')
            ->assertDontSee('Zamówienie zawiera dodatkowe rozliczenie Allegro Ceny');
        $itemId = $order->items()->sole()->id;
        $order->update(['shipping_street' => 'Reczna poprawka 1']);
        $this->sync($account, $this->order382Payload());
        $this->assertSame($itemId, $order->items()->sole()->id);
        $this->assertSame('Reczna poprawka 1', $order->fresh()->shipping_street);
        $this->assertSame(1, $order->events()->where('event_type', 'allegro_order_completed')->count());
        Queue::assertPushed(EvaluateAutomationEventJob::class, 1);
        Queue::assertPushed(EvaluateAutomationEventJob::class, fn ($job) => $job->eventName === 'order.data_completed');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('allegro_order_payments', 1);
    }

    public function test_multiple_billing_and_wallet_lines_use_discounted_prices_without_reconciliation_equations(): void
    {
        $raw = Fixture::payload();
        $raw['lineItems'][0]['reconciliation'] = $this->lineReconciliation('BILLING', 1, '10.47');
        $raw['lineItems'][] = array_replace($raw['lineItems'][0], [
            'id' => '6f34b791-445e-40a6-83a9-18830d73bf7b',
            'quantity' => 1, 'reconciliation' => $this->lineReconciliation('WALLET', 1, '2.00'),
        ]);
        $raw['summary']['totalToPay']['amount'] = '130.04';
        $raw['payment']['paidAmount']['amount'] = '130.03';
        $raw['payment']['reconciliation'] = ['amount' => '2.00', 'currency' => 'PLN'];
        $preview = app(AllegroOrderPreviewService::class)->normalize($this->account(), Fixture::ORDER_ID, $raw);
        $this->assertTrue($preview['importable']);
        $this->assertSame('130.04', $preview['paid_amount']);
        $this->assertCount(2, $preview['items']);
        $this->assertSame('30.01', $preview['items'][1]['unit_price_gross']);
    }

    public function test_valid_reconciliation_does_not_hide_an_order_total_mismatch(): void
    {
        $raw = $this->order382Payload();
        $raw['summary']['totalToPay']['amount'] = '523.60';
        $raw['payment']['paidAmount']['amount'] = '523.60';
        $preview = app(AllegroOrderPreviewService::class)->normalize($this->account(), Fixture::ORDER_ID, $raw);
        $this->assertFalse($preview['importable']);
        $this->assertStringContainsString('Suma produktów', implode(' ', $preview['blockers']));
    }

    #[DataProvider('invalidReconciliations')]
    public function test_invalid_reconciliation_still_requires_review(string $path, mixed $value): void
    {
        $account = $this->account();
        $raw = Fixture::payload();
        $raw['lineItems'][0]['reconciliation'] = $this->lineReconciliation('BILLING', 1, '10.47');
        data_set($raw, $path, $value);
        $preview = app(AllegroOrderPreviewService::class)->normalize($account, Fixture::ORDER_ID, $raw);
        $this->assertFalse($preview['importable']);
        $this->assertStringContainsString('Nieprawidłowe dane rozliczenia Allegro Ceny', implode(' ', $preview['blockers']));
        $this->sync($account, $raw);
        $order = Order::query()->sole();
        $this->assertFalse($order->purchaseIsComplete());
        $this->assertSame('review', $order->allegroOrderLink->import_state);
        $this->assertSame('0.00', $order->paid_amount);
        $this->assertDatabaseCount('allegro_order_payments', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidReconciliations(): array
    {
        $line = 'lineItems.0.reconciliation';
        $cases = [
            'unknown type' => [$line.'.type', 'UNKNOWN'],
            'wrong type case' => [$line.'.type', 'billing'],
            'missing type' => [$line.'.type', null],
            'missing quantity' => [$line.'.quantity', null],
            'string quantity' => [$line.'.quantity', '1'],
            'float quantity' => [$line.'.quantity', 1.0],
            'zero quantity' => [$line.'.quantity', 0],
            'negative quantity' => [$line.'.quantity', -1],
            'too many units' => [$line.'.quantity', 4],
            'missing value' => [$line.'.value', null],
            'negative amount' => [$line.'.value.amount', '-0.01'],
            'excess precision' => [$line.'.value.amount', '0.001'],
            'out of range' => [$line.'.value.amount', '10000000000.00'],
            'currency mismatch' => [$line.'.value.currency', 'EUR'],
            'missing amount' => [$line.'.value.amount', null],
        ];
        foreach (['empty array' => [], 'empty string' => '', 'false' => false, 'zero' => 0, 'list' => ['BILLING']] as $name => $value) {
            $cases['line '.$name] = [$line, $value];
        }
        foreach (['payment.reconciliation', 'surcharges.0.reconciliation'] as $path) {
            $cases[$path.' wrong currency'] = [$path, ['amount' => '1.00', 'currency' => 'EUR']];
            $cases[$path.' negative'] = [$path, ['amount' => '-0.01', 'currency' => 'PLN']];
            $cases[$path.' missing amount'] = [$path, ['currency' => 'PLN']];
            $cases[$path.' empty'] = [$path, []];
            $cases[$path.' false'] = [$path, false];
        }

        return $cases;
    }

    #[DataProvider('paymentStates')]
    public function test_reconciliation_does_not_override_payment_or_completeness_rules(string $status, string $type, ?string $amount, bool $complete, string $paid, string $paymentStatus): void
    {
        $raw = $this->order382Payload();
        $raw['status'] = $status;
        $raw['payment']['type'] = $type;
        $raw['payment']['paidAmount'] = $amount === null ? null : ['amount' => $amount, 'currency' => 'PLN'];
        if ($amount === null) {
            $raw['payment']['finishedAt'] = null;
        }
        $this->sync($this->account(), $raw);
        $order = Order::query()->sole();
        $this->assertSame($complete, $order->purchaseIsComplete());
        $this->assertSame($paid, $order->paid_amount);
        $this->assertSame($paymentStatus, $order->payment_status);
    }

    public static function paymentStates(): array
    {
        return [
            'partial payment' => ['READY_FOR_PROCESSING', 'ONLINE', '500.00', true, '500.00', 'unpaid'],
            'overpayment still blocked' => ['READY_FOR_PROCESSING', 'ONLINE', '523.60', false, '0.00', 'unpaid'],
            'cod is not payment' => ['READY_FOR_PROCESSING', 'CASH_ON_DELIVERY', null, true, '0.00', 'unpaid'],
            'unsupported payment' => ['READY_FOR_PROCESSING', 'EXTENDED_TERM', '513.13', false, '0.00', 'unpaid'],
            'bought is incomplete' => ['BOUGHT', 'ONLINE', '513.13', false, '0.00', 'unpaid'],
            'filled in is incomplete' => ['FILLED_IN', 'ONLINE', '513.13', false, '0.00', 'unpaid'],
        ];
    }

    private function lineReconciliation(string $type, int $quantity, string $amount): array
    {
        return ['type' => $type, 'quantity' => $quantity, 'value' => ['amount' => $amount, 'currency' => 'PLN']];
    }

    private function order382Payload(): array
    {
        $raw = Fixture::payload();
        $raw['lineItems'][0]['quantity'] = 1;
        $raw['lineItems'][0]['price']['amount'] = '513.13';
        $raw['lineItems'][0]['originalPrice']['amount'] = '513.13';
        $raw['lineItems'][0]['tax'] = ['rate' => '23.00'];
        $raw['lineItems'][0]['reconciliation'] = $this->lineReconciliation('BILLING', 1, '10.47');
        $raw['lineItems'][0]['discounts'] = [['type' => 'CAMPAIGN']];
        $raw['delivery']['cost']['amount'] = '0.00';
        $raw['summary']['totalToPay']['amount'] = '513.13';
        $raw['payment']['provider'] = 'AF';
        $raw['payment']['paidAmount']['amount'] = '513.13';
        $raw['surcharges'] = [];
        $raw['invoice'] = ['required' => false, 'address' => null];

        return $raw;
    }

    private function sync(AllegroAccount $account, array $raw): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['*/order/checkout-forms/'.Fixture::ORDER_ID => Http::response(
            json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 200, ['Content-Type' => 'application/json'],
        )]);
        app(AllegroOrderSyncService::class)->sync($account, Fixture::ORDER_ID);
    }

    private function account(): AllegroAccount
    {
        return AllegroAccount::query()->create([
            'account_key' => 'default', 'name' => 'Allegro test', 'environment' => 'production',
            'client_id' => 'client', 'client_secret' => 'secret', 'access_token' => 'token', 'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHours(5), 'granted_scopes' => AllegroAccount::REQUIRED_SCOPES,
            'allegro_user_id' => '12345678', 'allegro_login' => 'test', 'marketplace_id' => 'allegro-pl',
            'is_active' => true, 'last_test_status' => 'success', 'last_tested_at' => now(),
        ]);
    }
}
