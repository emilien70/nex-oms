<?php

namespace Tests\Feature;

use App\Contracts\OrderEmailTransport;
use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Exceptions\OrderEmailException;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Services\OrderEmailPreparationService;
use App\Services\OrderEmailSender;
use App\ValueObjects\OrderEmailPreparationData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationEmailActionService;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Services\SmsGatewayService;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OrderMessageRecipientTest extends TestCase
{
    use RefreshDatabase;

    private RecipientEmailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::swap((new Factory)->preventStrayRequests());
        $this->transport = new RecipientEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $this->transport);
    }

    #[DataProvider('channels')]
    public function test_recipient_lookup_is_fresh_private_and_read_only(string $channel, string $field, string $value, string $normalized): void
    {
        $order = $this->order();
        $this->get(route('orders.show', $order))->assertOk();
        $order->update([$field => $value]);
        $before = $order->fresh()->getRawOriginal();
        $response = $this->getJson(route('orders.message-recipient', [$order, 'channel' => $channel]))
            ->assertOk()->assertJsonPath('recipient', $normalized)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $response->json('recipient_token'));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('order_email_messages', 0);
        $this->assertDatabaseCount('order_sms_messages', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function channels(): array
    {
        return [['email', 'customer_email', ' NEW@EXAMPLE.TEST ', 'new@example.test'],
            ['sms', 'customer_phone', '+48 500 000 001', '+48500000001']];
    }

    #[DataProvider('invalidContacts')]
    public function test_missing_or_invalid_current_recipient_is_not_replaced_with_old_data(string $channel, string $field, ?string $value): void
    {
        $order = $this->order();
        $order->update([$field => $value]);
        $this->getJson(route('orders.message-recipient', [$order, 'channel' => $channel]))->assertUnprocessable();
        $this->assertDatabaseCount('order_email_messages', 0);
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public static function invalidContacts(): array
    {
        return [['email', 'customer_email', null], ['email', 'customer_email', "a@example.test\nb@example.test"],
            ['sms', 'customer_phone', null], ['sms', 'customer_phone', 'bledny']];
    }

    public function test_customer_domain_cannot_read_recipient_or_send_and_lookup_requires_valid_channel(): void
    {
        $order = $this->order();
        $this->getJson(route('orders.message-recipient', [$order, 'channel' => 'other']))->assertUnprocessable();
        $this->getJson(route('orders.message-recipient', $order))->assertUnprocessable();
        PortalSetting::query()->first()->update(['customer_domain' => 'client.example.test']);
        $this->getJson('https://client.example.test/orders/'.$order->id.'/message-recipient?channel=email')->assertNotFound();
        $this->postJson('https://client.example.test/orders/'.$order->id.'/email-messages', $this->emailData())->assertNotFound();
        $this->assertDatabaseCount('order_email_messages', 0);
    }

    #[DataProvider('malformedTokens')]
    public function test_supplied_invalid_token_never_falls_back_to_legacy_tokenless_request(string $channel, mixed $token): void
    {
        $this->device();
        $order = $this->order();
        $route = $channel === 'sms' ? 'orders.sms.store' : 'orders.email-messages.store';
        $data = $channel === 'sms' ? $this->smsData() : $this->emailData();
        $this->postJson(route($route, $order), $data + ['recipient_token' => $token])->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_token');
        $this->assertDatabaseCount('order_email_messages', 0);
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public static function malformedTokens(): array
    {
        $cases = [];
        foreach (['email', 'sms'] as $channel) {
            foreach ([null, '', 123, []] as $token) {
                $cases[] = [$channel, $token];
            }
        }

        return $cases;
    }

    #[DataProvider('confirmationCases')]
    public function test_changed_wrong_order_wrong_channel_or_forged_confirmation_never_queues(string $channel, string $case): void
    {
        $this->device();
        $order = $this->order();
        $lookupOrder = $case === 'order' ? $this->order() : $order;
        $lookupChannel = $case === 'channel' ? ($channel === 'sms' ? 'email' : 'sms') : $channel;
        $token = $this->getJson(route('orders.message-recipient', [$lookupOrder, 'channel' => $lookupChannel]))->json('recipient_token');
        if ($case === 'changed') {
            $order->update($channel === 'sms' ? ['customer_phone' => '500000002'] : ['customer_email' => 'changed@example.test']);
        }
        if ($case === 'forged') {
            $token = str_repeat('0', 64);
        }
        $route = $channel === 'sms' ? 'orders.sms.store' : 'orders.email-messages.store';
        $data = $channel === 'sms' ? $this->smsData() : $this->emailData();
        $this->postJson(route($route, $order), $data + ['recipient_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_token');
        $this->assertDatabaseCount('order_email_messages', 0);
        $this->assertDatabaseCount('order_sms_messages', 0);
        Queue::assertNothingPushed();
    }

    public static function confirmationCases(): array
    {
        $cases = [];
        foreach (['sms', 'email'] as $channel) {
            foreach (['changed', 'order', 'channel', 'forged'] as $case) {
                $cases[] = [$channel, $case];
            }
        }

        return $cases;
    }

    public function test_manual_sms_uses_saved_phone_without_browser_recipient_and_freezes_it(): void
    {
        $device = $this->device();
        $order = $this->order();
        $order->update(['customer_phone' => '500000001']);
        $data = $this->smsData();
        $data['recipient_token'] = $this->getJson(route('orders.message-recipient', [$order, 'channel' => 'sms']))->json('recipient_token');
        $this->postJson(route('orders.sms.store', $order), $data)->assertCreated();
        $this->postJson(route('orders.sms.store', $order), $data)->assertCreated();
        $message = SmsMessage::query()->sole();
        $this->assertSame('+48500000001', $message->recipient);
        $this->assertTrue($message->customer_recipient);
        $order->update(['customer_phone' => '500000002']);
        $this->assertFalse(app(SmsGatewayService::class)->claim($device, $message->public_id)['available']);
        $this->assertSame('cancelled', $message->fresh()->status);
        $this->assertSame('+48500000001', $message->fresh()->recipient);
    }

    public function test_old_sms_form_cannot_send_stale_number_as_custom_recipient(): void
    {
        $this->device();
        $order = $this->order();
        $order->update(['customer_phone' => '500000001']);
        $this->postJson(route('orders.sms.store', $order), $this->smsData() + ['recipient' => '500000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient');
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public function test_email_preparation_resolves_from_locked_order_not_stale_model_or_data(): void
    {
        $order = $this->order();
        Order::query()->whereKey($order->id)->update(['customer_email' => 'new@example.test', 'billing_name' => 'Nowy klient']);
        $message = $this->email($order, true);
        $this->assertSame('new@example.test', $message->to_email);
        $this->assertSame('Nowy klient', $message->to_name);
        $this->assertTrue($message->actor_snapshot['recipient_customer']);
    }

    public function test_manual_email_ignores_a_forged_browser_address_and_is_idempotent(): void
    {
        $order = $this->order();
        $order->update(['customer_email' => 'new@example.test']);
        $data = $this->emailData() + ['to_email' => 'other@example.test'];
        $data['recipient_token'] = $this->getJson(route('orders.message-recipient', [$order, 'channel' => 'email']))->json('recipient_token');
        $this->postJson(route('orders.email-messages.store', $order), $data)->assertCreated();
        $this->postJson(route('orders.email-messages.store', $order), $data)->assertCreated();
        $this->assertSame('new@example.test', OrderEmailMessage::query()->sole()->to_email);
    }

    public function test_customer_email_changed_in_queue_is_blocked_without_rewriting_snapshot_or_transport(): void
    {
        $order = $this->order();
        $message = $this->email($order, true);
        $order->update(['customer_email' => 'new@example.test']);
        app(OrderEmailSender::class)->send($message->id);
        app(OrderEmailSender::class)->send($message->id);
        $this->assertSame(0, $this->transport->sendCount);
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->fresh()->status);
        $this->assertSame('recipient_changed', $message->fresh()->safe_error_code);
        $this->assertSame('old@example.test', $message->fresh()->to_email);
        $this->assertSame(0, $message->fresh()->attempt_count);
    }

    public function test_last_check_before_smtp_detects_change_after_claim(): void
    {
        $order = $this->order();
        $message = $this->email($order, true);
        $changed = false;
        Event::listen('eloquent.retrieved: '.EmailAccount::class, function () use ($order, &$changed): void {
            if (! $changed) {
                $changed = true;
                Order::query()->whereKey($order->id)->update(['customer_email' => 'new@example.test']);
            }
        });
        app(OrderEmailSender::class)->send($message->id);
        $this->assertTrue($changed);
        $this->assertSame(0, $this->transport->sendCount);
        $this->assertSame('recipient_changed', $message->fresh()->safe_error_code);
        $this->assertSame(1, $message->fresh()->attempt_count);
    }

    public function test_email_formatting_changes_do_not_block_and_sent_snapshot_stays_immutable(): void
    {
        $order = $this->order();
        $message = $this->email($order, true);
        $order->update(['customer_email' => ' OLD@EXAMPLE.TEST ']);
        app(OrderEmailSender::class)->send($message->id);
        $order->update(['customer_email' => 'new@example.test']);
        app(OrderEmailSender::class)->send($message->id);
        $this->assertSame(1, $this->transport->sendCount);
        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame('old@example.test', $message->fresh()->to_email);
    }

    public function test_explicit_email_recipient_is_not_replaced_or_blocked_by_customer_edit(): void
    {
        $order = $this->order();
        $message = $this->email($order, false);
        $order->update(['customer_email' => null]);
        app(OrderEmailSender::class)->send($message->id);
        $this->assertSame(1, $this->transport->sendCount);
        $this->assertSame('explicit@example.test', $message->fresh()->to_email);
        $this->assertFalse($message->actor_snapshot['recipient_customer']);
    }

    public function test_unknown_legacy_automation_recipient_fails_closed_without_changing_history(): void
    {
        $message = $this->email($this->order(), false);
        DB::table('order_email_messages')->where('id', $message->id)->update(['source' => 'automation', 'actor_snapshot' => '{}']);
        app(OrderEmailSender::class)->send($message->id);
        $this->assertSame('recipient_context_missing', $message->fresh()->safe_error_code);
        $this->assertSame('explicit@example.test', $message->fresh()->to_email);
        $this->assertSame(0, $this->transport->sendCount);
    }

    #[DataProvider('invalidMarkers')]
    public function test_recipient_provenance_is_a_strict_boolean(mixed $marker): void
    {
        $message = $this->email($this->order(), false);
        DB::table('order_email_messages')->where('id', $message->id)->update([
            'actor_snapshot' => json_encode(['recipient_customer' => $marker]),
        ]);
        app(OrderEmailSender::class)->send($message->id);
        $this->assertSame('recipient_context_missing', $message->fresh()->safe_error_code);
        $this->assertSame(0, $this->transport->sendCount);
    }

    public static function invalidMarkers(): array
    {
        return [[0], [1], ['false'], ['true'], [null]];
    }

    public function test_same_address_and_key_cannot_change_recipient_provenance(): void
    {
        $order = $this->order();
        $account = $this->account();
        $key = (string) Str::uuid();
        $data = fn (bool $customer) => new OrderEmailPreparationData(
            toEmail: 'old@example.test', toName: 'Klient', source: OrderEmailMessageSource::Manual,
            idempotencyKey: $key, subjectTemplate: 'Temat', bodyTemplate: 'Tresc', format: EmailTemplateFormat::PlainText,
            emailAccount: $account, actorName: 'Operator', recipientCustomer: $customer,
        );
        app(OrderEmailPreparationService::class)->prepareAndQueue($order, null, $data(false));
        try {
            app(OrderEmailPreparationService::class)->prepareAndQueue($order, null, $data(true));
            $this->fail('Changed recipient provenance must conflict.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('idempotency_conflict', $exception->safeCode());
        }
        $this->assertDatabaseCount('order_email_messages', 1);
        $this->assertFalse(OrderEmailMessage::query()->sole()->actor_snapshot['recipient_customer']);
    }

    #[DataProvider('legacyRecoveryCases')]
    public function test_legacy_manual_replay_preserves_old_fingerprint_and_recovers_only_queue_dispatch_failure(bool $recover): void
    {
        $order = $this->order();
        $data = new OrderEmailPreparationData(
            toEmail: 'old@example.test', toName: 'Klient', source: OrderEmailMessageSource::Manual,
            idempotencyKey: (string) Str::uuid(), subjectTemplate: 'Temat', bodyTemplate: 'Tresc',
            format: EmailTemplateFormat::PlainText, emailAccount: $this->account(), actorName: 'Operator', recipientCustomer: true,
        );
        $service = app(OrderEmailPreparationService::class);
        $message = $service->prepareAndQueue($order, null, $data);
        $actor = $message->actor_snapshot;
        unset($actor['recipient_customer']);
        DB::table('order_email_messages')->where('id', $message->id)->update([
            'actor_snapshot' => json_encode($actor), 'status' => $recover ? 'failed' : 'queued',
            'safe_error_code' => $recover ? 'queue_dispatch_failed' : null,
        ]);
        $before = $message->fresh()->only(['actor_snapshot', 'intent_fingerprint', 'to_email', 'body_text', 'attachment_manifest_sha256']);
        Queue::fake();
        $replayed = $service->prepareAndQueue($order, null, $data);
        $this->assertSame($message->id, $replayed->id);
        $this->assertSame($before, $replayed->only(array_keys($before)));
        $this->assertSame(OrderEmailMessageStatus::Queued, $replayed->status);
        $this->assertDatabaseCount('order_email_messages', 1);
        Queue::assertPushed(SendOrderEmailJob::class, $recover ? 1 : 0);
    }

    public static function legacyRecoveryCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('lockChannels')]
    public function test_portal_settings_are_acquired_before_order_for_both_channels(string $channel): void
    {
        $order = $this->order();
        $device = $this->device();
        PortalSetting::query()->first()->update(['is_enabled' => true]);
        $account = $this->account();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        if ($channel === 'email') {
            app(OrderEmailPreparationService::class)->prepareAndQueue($order, null, new OrderEmailPreparationData(
                toEmail: 'old@example.test', toName: 'Klient', source: OrderEmailMessageSource::Manual,
                idempotencyKey: (string) Str::uuid(), subjectTemplate: 'Temat', bodyTemplate: '[link_zamowienia]',
                format: EmailTemplateFormat::PlainText, emailAccount: $account, actorName: 'Operator', recipientCustomer: true,
            ));
        } else {
            app(SmsPreparationService::class)->prepare($order, null, ['body' => '[link_zamowienia]',
                'idempotency_key' => (string) Str::uuid()]);
        }
        $selects = array_values(array_filter($queries, fn ($query) => str_starts_with($query, 'select')));
        $settings = array_key_first(array_filter($selects, fn ($query) => str_contains($query, 'from "customer_portal_settings"')));
        $orders = array_key_first(array_filter($selects, fn ($query) => str_contains($query, 'from "orders"')));
        $this->assertNotNull($settings);
        $this->assertNotNull($orders);
        $this->assertLessThan($orders, $settings);
    }

    public static function lockChannels(): array
    {
        return [['email'], ['sms']];
    }

    public function test_automation_reloads_customer_deduplicates_and_preserves_explicit_recipient(): void
    {
        $order = $this->order();
        $run = AutomationRun::query()->create(['order_id' => $order->id, 'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed', 'chain_id' => (string) Str::uuid(), 'depth' => 0,
            'status' => 'running', 'event_payload' => [], 'rule_snapshot' => []]);
        $step = $run->steps()->create(['position' => 1, 'action_type' => 'send_email', 'status' => 'pending', 'configuration' => []]);
        $run->setRelation('order', $order);
        Order::query()->whereKey($order->id)->update(['customer_email' => 'new@example.test']);
        $template = EmailTemplate::query()->create(['email_account_id' => $this->account()->id, 'name' => 'Test',
            'subject_template' => 'Temat', 'body_template' => 'Tresc', 'format' => 'plain_text']);
        $result = app(AutomationEmailActionService::class)->execute($run, $step, [
            'email_template_id' => $template->id, 'recipient_customer' => true,
            'recipient_emails' => ['new@example.test', 'explicit@example.test'],
        ]);
        $this->assertSame(2, $result['recipient_count']);
        Order::query()->whereKey($order->id)->update(['customer_email' => 'changed@example.test']);
        foreach (OrderEmailMessage::all() as $message) {
            app(OrderEmailSender::class)->send($message->id);
        }
        $this->assertSame(1, $this->transport->sendCount);
        $customer = OrderEmailMessage::where('to_email', 'new@example.test')->sole();
        $explicit = OrderEmailMessage::where('to_email', 'explicit@example.test')->sole();
        $this->assertTrue($customer->actor_snapshot['recipient_customer']);
        $this->assertSame('recipient_changed', $customer->safe_error_code);
        $this->assertFalse($explicit->actor_snapshot['recipient_customer']);
        $this->assertSame(OrderEmailMessageStatus::Sent, $explicit->status);
    }

    public function test_automation_stale_recipient_context_is_rejected_instead_of_creating_duplicate(): void
    {
        $order = $this->order();
        $run = AutomationRun::query()->create(['order_id' => $order->id, 'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed', 'chain_id' => (string) Str::uuid(), 'depth' => 0,
            'status' => 'running', 'event_payload' => [], 'rule_snapshot' => []]);
        Order::query()->whereKey($order->id)->update(['customer_email' => 'new@example.test']);
        try {
            app(OrderEmailPreparationService::class)->prepareAndQueue($order, null, new OrderEmailPreparationData(
                toEmail: 'old@example.test', toName: 'Klient', source: OrderEmailMessageSource::Automation,
                idempotencyKey: (string) Str::uuid(), automationRun: $run, subjectTemplate: 'Temat', bodyTemplate: 'Tresc',
                format: EmailTemplateFormat::PlainText, emailAccount: $this->account(), recipientCustomer: true,
            ));
            $this->fail('Stale automatic recipients must not be rebound.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('recipient_changed', $exception->safeCode());
        }
        $this->assertDatabaseCount('order_email_messages', 0);
    }

    private function email(Order $order, bool $customer): OrderEmailMessage
    {
        return app(OrderEmailPreparationService::class)->prepareAndQueue($order, null, new OrderEmailPreparationData(
            toEmail: $customer ? 'stale@example.test' : 'explicit@example.test', toName: 'Stara nazwa',
            source: OrderEmailMessageSource::Manual, idempotencyKey: (string) Str::uuid(),
            subjectTemplate: 'Temat', bodyTemplate: 'Tresc', format: EmailTemplateFormat::PlainText,
            emailAccount: $this->account(), actorName: 'Operator', recipientCustomer: $customer,
        ));
    }

    private function order(): Order
    {
        return Order::query()->create(['source' => 'manual', 'status' => Order::STATUS_NEW,
            'currency' => 'PLN', 'total_gross' => '0.00', 'payment_status' => 'unpaid',
            'customer_email' => 'old@example.test', 'customer_phone' => '500000000', 'billing_name' => 'Klient']);
    }

    private function account(): EmailAccount
    {
        return EmailAccount::query()->create(['name' => 'Test', 'email' => 'shop@example.test',
            'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_username' => 'shop@example.test',
            'smtp_password' => 'FAKE', 'encryption' => EmailAccountEncryption::StartTls]);
    }

    private function device(): SmsDevice
    {
        return SmsDevice::query()->create(['singleton_key' => 'primary', 'enabled' => true,
            'token_hash' => hash('sha256', 'FAKE'), 'webhook_url' => 'https://trigger.macrodroid.com/fake-device-12345678/nex-oms-sms',
            'generation' => 1, 'per_minute' => 6, 'per_day' => 200, 'max_parts' => 5, 'lifetime_minutes' => 60]);
    }

    private function smsData(): array
    {
        return ['body' => 'Tresc SMS', 'idempotency_key' => (string) Str::uuid()];
    }

    private function emailData(): array
    {
        return ['message_type' => 'email', 'email_account_id' => $this->account()->id,
            'subject' => 'Temat', 'format' => 'plain_text', 'body' => 'Tresc',
            'sending_option' => 'page_and_email', 'idempotency_key' => (string) Str::uuid()];
    }
}

class RecipientEmailTransport implements OrderEmailTransport
{
    public int $sendCount = 0;

    public function send(EmailAccount $account, Email $email): void
    {
        $this->sendCount++;
    }
}
