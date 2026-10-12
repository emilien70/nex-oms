<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Automation\Models\AutomationRule;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationCatalog;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Tests\TestCase;

class PayNowPaymentAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_registered_payment_triggers_matching_rules_once(): void
    {
        $partialRule = $this->rule(
            'Częściowa płatność PayNow',
            [
                ['field' => 'payment_source', 'operator' => 'equals', 'value' => 'paynow'],
                ['field' => 'payment_state', 'operator' => 'equals', 'value' => 'partial'],
            ],
            Order::STATUS_PENDING,
        );
        $paidRule = $this->rule(
            'Pełna płatność PayNow',
            [
                ['field' => 'payment_source', 'operator' => 'equals', 'value' => 'paynow'],
                ['field' => 'payment_state', 'operator' => 'equals', 'value' => 'paid'],
            ],
            Order::STATUS_SHIPPED,
        );
        $account = $this->account();
        $order = $this->order(['total_gross' => '100.00']);
        $firstPayment = $this->payment($account, $order, 1, '40.00');
        $firstBody = $this->notification($firstPayment, '2026-10-12T10:15:30+02:00');

        $this->postNotification($firstBody, $account)->assertStatus(202);
        $this->postNotification($firstBody, $account)->assertStatus(202);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(1, $partialRule->runs()->count());
        $this->assertSame(0, $paidRule->runs()->count());

        $secondPayment = $this->payment($account, $order, 2, '60.00');
        $this->postNotification(
            $this->notification($secondPayment, '2026-10-12T10:20:30+02:00'),
            $account,
        )->assertStatus(202);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $this->assertSame(1, $partialRule->runs()->count());
        $this->assertSame(1, $paidRule->runs()->count());

        $partialRun = $partialRule->runs()->firstOrFail();
        $paidRun = $paidRule->runs()->firstOrFail();

        $this->assertSame('payment.registered', $partialRun->event_name);
        $this->assertSame('paynow', $partialRun->event_payload['payment_source']);
        $this->assertSame('partial', $partialRun->event_payload['payment_state']);
        $this->assertSame('40.00', $partialRun->event_payload['paid_amount_after']);
        $this->assertSame('paid', $paidRun->event_payload['payment_state']);
        $this->assertSame('100.00', $paidRun->event_payload['paid_amount_after']);
        $this->assertNotSame($partialRun->event_id, $paidRun->event_id);
    }

    public function test_payment_requiring_review_does_not_trigger_automation(): void
    {
        $rule = $this->rule('Każda płatność PayNow', [], Order::STATUS_PENDING);
        $account = $this->account();
        $order = $this->order([
            'total_gross' => '100.00',
            'paid_amount' => '90.00',
        ]);
        $payment = $this->payment($account, $order, 1, '20.00');

        $this->postNotification(
            $this->notification($payment, '2026-10-12T10:25:30+02:00'),
            $account,
        )->assertStatus(202);

        $this->assertSame('90.00', $order->fresh()->paid_amount);
        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertTrue($payment->fresh()->needs_review);
        $this->assertSame(0, $rule->runs()->count());
    }

    public function test_failed_automation_does_not_roll_back_registered_payment(): void
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Błędna akcja po płatności',
            'trigger' => 'payment.registered',
            'conditions' => [],
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => 'unsupported_test_action',
            'configuration' => [],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);
        $account = $this->account();
        $order = $this->order(['total_gross' => '100.00']);
        $payment = $this->payment($account, $order, 1, '100.00');

        $this->postNotification(
            $this->notification($payment, '2026-10-12T10:30:30+02:00'),
            $account,
        )->assertStatus(202);

        $order->refresh();

        $this->assertSame('100.00', $order->paid_amount);
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($payment->fresh()->accounted_at);
        $this->assertDatabaseHas('automation_runs', [
            'automation_rule_id' => $rule->getKey(),
            'order_id' => $order->getKey(),
            'event_name' => 'payment.registered',
            'status' => AutomationRun::STATUS_FAILED,
        ]);
    }

    public function test_automation_editor_exposes_and_saves_payment_trigger_and_source(): void
    {
        $catalog = app(AutomationCatalog::class);

        $this->assertSame(
            'Zarejestrowano płatność',
            $catalog->triggers()['payment.registered'],
        );
        $this->assertSame(
            ['paynow' => 'PayNow'],
            $catalog->conditionDefinitions()['payment_source']['options'],
        );

        $this->get(route('orders.automatic-actions.index'))
            ->assertOk()
            ->assertSee('payment.registered')
            ->assertSee('payment_source')
            ->assertSee('PayNow');

        $this->postJson(route('orders.automatic-actions.store'), [
            'name' => 'Płatność PayNow',
            'trigger' => 'payment.registered',
            'is_active' => true,
            'conditions' => [[
                'field' => 'payment_source',
                'operator' => 'equals',
                'value' => 'paynow',
            ]],
            'actions' => [[
                'type' => AutomationCatalog::ACTION_CHANGE_STATUS,
                'configuration' => ['status' => Order::STATUS_PENDING],
                'stop_on_error' => true,
            ]],
        ])->assertCreated();

        $savedRule = AutomationRule::query()->where('name', 'Płatność PayNow')->firstOrFail();

        $this->assertSame('payment.registered', $savedRule->trigger);
        $this->assertSame('payment_source', $savedRule->conditions[0]['field']);
        $this->assertSame('paynow', $savedRule->conditions[0]['value']);
    }

    /**
     * @param  array<int, array{field: string, operator: string, value: string}>  $conditions
     */
    private function rule(string $name, array $conditions, string $targetStatus): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'name' => $name,
            'trigger' => 'payment.registered',
            'conditions' => $conditions,
            'is_active' => true,
        ]);
        $rule->actions()->create([
            'action_type' => AutomationCatalog::ACTION_CHANGE_STATUS,
            'configuration' => ['status' => $targetStatus],
            'stop_on_error' => true,
            'sort_order' => 1,
        ]);

        return $rule;
    }

    private function account(): PayNowAccount
    {
        return PayNowAccount::query()->create([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'PayNow test',
            'environment' => PayNowEnvironment::Sandbox,
            'api_key' => 'test-api-key',
            'signature_key' => 'test-signature-key',
            'is_active' => false,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'external_id' => 'PAYNOW-AUTOMATION-ORDER',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '100.00',
            'paid_amount' => '0.00',
            'payment_status' => 'unpaid',
            'customer_email' => 'customer@example.test',
        ], $overrides));
    }

    private function payment(
        PayNowAccount $account,
        Order $order,
        int $sequence,
        string $amount,
    ): PayNowPayment {
        return PayNowPayment::query()->create([
            'paynow_account_id' => $account->getKey(),
            'order_id' => $order->getKey(),
            'amount' => $amount,
            'currency' => 'PLN',
            'external_id' => 'nex-order-'.$order->getKey().'-automation-payment-'.$sequence,
            'provider_payment_id' => 'PBLA-AUTOMATION-'.$sequence,
            'idempotency_key' => 'paynow-automation-payment-'.$sequence,
            'status' => PayNowPaymentStatus::Pending,
            'buyer_email_snapshot' => 'customer@example.test',
            'description' => 'Płatność za zamówienie',
        ]);
    }

    private function notification(PayNowPayment $payment, string $modifiedAt): string
    {
        return json_encode([
            'paymentId' => $payment->provider_payment_id,
            'externalId' => $payment->external_id,
            'status' => 'CONFIRMED',
            'modifiedAt' => $modifiedAt,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function postNotification(string $body, PayNowAccount $account)
    {
        $signature = base64_encode(hash_hmac(
            'sha256',
            $body,
            (string) $account->signature_key,
            true,
        ));

        return $this->call(
            'POST',
            route('api.paynow.notifications'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
