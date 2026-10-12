<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Enums\PayNowPaymentStatus;
use Modules\Integrations\PayNow\Models\PayNowAccount;
use Modules\Integrations\PayNow\Models\PayNowPayment;
use Tests\TestCase;

class PayNowFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_models_store_encrypted_financial_integration_data(): void
    {
        $this->assertTrue(Schema::hasColumns('paynow_accounts', [
            'account_key',
            'environment',
            'api_key',
            'signature_key',
            'last_test_status',
        ]));
        $this->assertTrue(Schema::hasColumns('paynow_payments', [
            'paynow_account_id',
            'order_id',
            'amount',
            'currency',
            'external_id',
            'provider_payment_id',
            'idempotency_key',
            'payment_url',
            'status',
            'needs_review',
            'accounted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('paynow_payment_events', [
            'paynow_payment_id',
            'event_key',
            'source',
            'status',
            'payload',
            'payload_sha256',
        ]));

        $account = $this->account();
        $order = $this->order();
        $payment = $this->payment($account, $order);
        $event = $payment->events()->create([
            'event_key' => hash('sha256', 'event-1'),
            'source' => 'webhook',
            'provider_payment_id' => 'PBLA-123-ABC-456',
            'external_id' => 'order-1-payment-1',
            'status' => PayNowPaymentStatus::Pending,
            'provider_modified_at' => now(),
            'received_at' => now(),
            'payload' => ['status' => 'PENDING', 'private' => 'provider-data'],
            'payload_sha256' => hash('sha256', '{"status":"PENDING"}'),
        ]);

        $this->assertSame(PayNowEnvironment::Sandbox, $account->environment);
        $this->assertSame(PayNowPaymentStatus::Created, $payment->status);
        $this->assertSame('125.50', $payment->amount);
        $this->assertSame(PayNowPaymentStatus::Pending, $event->status);
        $this->assertSame('provider-data', $event->payload['private']);
        $this->assertNotSame('secret-api-key', DB::table('paynow_accounts')->value('api_key'));
        $this->assertNotSame('secret-signature-key', DB::table('paynow_accounts')->value('signature_key'));
        $this->assertNotSame('https://pay.example/payment/1', DB::table('paynow_payments')->value('payment_url'));
        $this->assertNotSame(json_encode($event->payload), DB::table('paynow_payment_events')->value('payload'));
        $this->assertArrayNotHasKey('api_key', $account->toArray());
        $this->assertArrayNotHasKey('signature_key', $account->toArray());
        $this->assertArrayNotHasKey('payment_url', $payment->toArray());
        $this->assertArrayNotHasKey('payload', $event->toArray());
    }

    public function test_financial_history_survives_removal_of_related_order_account_and_payment(): void
    {
        $account = $this->account();
        $order = $this->order();
        $payment = $this->payment($account, $order);
        $event = $payment->events()->create([
            'event_key' => hash('sha256', 'event-delete-test'),
            'source' => 'status_check',
            'provider_payment_id' => 'PBLA-123-ABC-456',
            'external_id' => 'order-1-payment-1',
            'status' => PayNowPaymentStatus::Confirmed,
            'received_at' => now(),
            'payload' => ['status' => 'CONFIRMED'],
            'payload_sha256' => hash('sha256', '{"status":"CONFIRMED"}'),
        ]);

        $order->forceDelete();
        $account->delete();
        $payment->refresh();

        $this->assertNull($payment->order_id);
        $this->assertNull($payment->paynow_account_id);

        $payment->delete();
        $event->refresh();

        $this->assertNull($event->paynow_payment_id);
        $this->assertSame('PBLA-123-ABC-456', $event->provider_payment_id);
        $this->assertSame(PayNowPaymentStatus::Confirmed, $event->status);
    }

    public function test_payment_statuses_have_stable_terminal_semantics(): void
    {
        $this->assertFalse(PayNowPaymentStatus::Created->isTerminal());
        $this->assertFalse(PayNowPaymentStatus::Pending->isTerminal());
        $this->assertTrue(PayNowPaymentStatus::Confirmed->isTerminal());
        $this->assertTrue(PayNowPaymentStatus::Rejected->isTerminal());
        $this->assertSame('Potwierdzona', PayNowPaymentStatus::Confirmed->label());
    }

    public function test_payment_events_are_append_only(): void
    {
        $payment = $this->payment($this->account(), $this->order());
        $event = $payment->events()->create([
            'event_key' => hash('sha256', 'immutable-event'),
            'source' => 'webhook',
            'provider_payment_id' => 'PBLA-123-ABC-456',
            'external_id' => 'order-1-payment-1',
            'status' => PayNowPaymentStatus::Pending,
            'received_at' => now(),
            'payload' => ['status' => 'PENDING'],
            'payload_sha256' => hash('sha256', '{"status":"PENDING"}'),
        ]);

        try {
            $event->update(['status' => PayNowPaymentStatus::Confirmed]);
            $this->fail('PayNow event update should have been rejected.');
        } catch (LogicException) {
            $this->assertSame(PayNowPaymentStatus::Pending, $event->fresh()->status);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function account(): PayNowAccount
    {
        return PayNowAccount::query()->create([
            'account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY,
            'name' => 'PayNow test',
            'environment' => PayNowEnvironment::Sandbox,
            'api_key' => 'secret-api-key',
            'signature_key' => 'secret-signature-key',
            'is_active' => false,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'external_id' => 'PAYNOW-ORDER-1',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '125.50',
            'paid_amount' => '0.00',
            'customer_email' => 'customer@example.test',
        ]);
    }

    private function payment(PayNowAccount $account, Order $order): PayNowPayment
    {
        return PayNowPayment::query()->create([
            'paynow_account_id' => $account->getKey(),
            'order_id' => $order->getKey(),
            'amount' => '125.50',
            'currency' => 'PLN',
            'external_id' => 'order-1-payment-1',
            'provider_payment_id' => 'PBLA-123-ABC-456',
            'idempotency_key' => 'nex-order-1-payment-1',
            'payment_url' => 'https://pay.example/payment/1',
            'status' => PayNowPaymentStatus::Created,
            'buyer_email_snapshot' => 'customer@example.test',
            'description' => 'Płatność za zamówienie nr 1',
        ]);
    }
}
