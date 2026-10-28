<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Services\AutomationSmsActionService;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\Integrations\MacroDroid\Jobs\NotifySmsDeviceJob;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;
use Modules\Integrations\MacroDroid\Services\SmsGatewayService;
use Modules\Integrations\MacroDroid\Services\SmsPhoneNumber;
use Modules\Integrations\MacroDroid\Services\SmsPreparationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MacroDroidSmsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
        $this->token = str_repeat('x', 64);
    }

    public function test_read_only_pages_do_not_enable_integration_or_send_requests(): void
    {
        $this->get(route('integrations.macrodroid.edit'))->assertOk()->assertSee('MacroDroid')->assertSee('6.0.11');
        $this->get(route('orders.email-templates.index'))->assertOk()->assertSee('Szablony SMS');
        $this->get(route('integrations.index'))->assertOk()->assertSee('MacroDroid SMS');
        $this->get(route('orders.show', $this->order()))->assertOk()->assertSee('data-sms-form', false);
        $this->assertDatabaseCount('macrodroid_devices', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('numbers')]
    public function test_phone_normalization(string $raw, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(ValidationException::class);
        }
        $this->assertSame($expected, SmsPhoneNumber::normalize($raw));
    }

    public static function numbers(): array
    {
        return [['500 000 000', '+48500000000'], ['0048 (500) 000-000', '+48500000000'],
            ['48500000000', '+48500000000'], ['+49 123 4567890', '+491234567890'],
            ['', null], ['500000000 ext 1', null], ["+48500000000\n+48500000001", null],
            ['+48', null], ['+4850', null], ['++48500000000', null], ['0000000000000000000', null]];
    }

    public function test_manual_send_is_disabled_until_configuration_is_explicitly_enabled(): void
    {
        $this->postJson(route('orders.sms.store', $this->order()), $this->manualData())->assertUnprocessable();
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public function test_manual_messages_are_rendered_frozen_encrypted_and_idempotent(): void
    {
        $this->device();
        $order = $this->order();
        $template = $this->template();
        $data = $this->manualData() + ['sms_template_id' => $template->id];
        $this->postJson(route('orders.sms.store', $order), $data)->assertCreated();
        $this->postJson(route('orders.sms.store', $order), $data)->assertCreated();
        $message = SmsMessage::query()->sole();
        $this->assertSame('Zamowienie '.$order->id, $message->body);
        $this->assertTrue($message->customer_recipient);
        $raw = DB::table('order_sms_messages')->first();
        $this->assertStringNotContainsString('Zamowienie', $raw->body);
        $this->assertStringNotContainsString('500000000', $raw->recipient);
        $template->update(['body_template' => 'Zmieniony szablon']);
        $this->assertSame('Zamowienie '.$order->id, $message->fresh()->body);
        $this->postJson(route('orders.sms.store', $order), array_replace($data, ['body' => 'Inna tresc']))->assertUnprocessable();
        $this->assertDatabaseCount('order_sms_messages', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('badBodies')]
    public function test_invalid_body_is_rejected(string $body): void
    {
        $this->device();
        $this->postJson(route('orders.sms.store', $this->order()), array_replace($this->manualData(), ['body' => $body]))->assertUnprocessable();
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public static function badBodies(): array
    {
        return [['   '], ['[nieistniejaca_zmienna]'], ["tekst\0tekst"], [str_repeat('a', 800)]];
    }

    public function test_claim_begin_and_ack_are_single_use_and_do_not_claim_delivery(): void
    {
        $device = $this->device();
        $message = $this->message();
        $claim = $this->api('claim', ['job_id' => $message->public_id])->assertOk()->assertJsonPath('available', true)->json();
        $this->api('claim', ['job_id' => $message->public_id])->assertStatus(409)->assertJsonPath('available', false)->assertJsonMissingPath('text');
        $payload = ['job_id' => $message->public_id, 'claim_token' => $claim['claim_token']];
        $this->api('begin', $payload)->assertOk()->assertJsonPath('may_send', true);
        $this->api('begin', $payload)->assertStatus(409)->assertJsonPath('may_send', false);
        $this->api('acknowledge', $payload + ['outcome' => 'submitted'])->assertOk()->assertJsonPath('status', 'submitted');
        $completed = $message->fresh()->completed_at;
        $this->travel(1)->minute();
        $this->api('acknowledge', $payload + ['outcome' => 'submitted'])->assertOk();
        $this->assertEquals($completed, $message->fresh()->completed_at);
        $this->get(route('orders.sms.history', $message->order_id))->assertOk()->assertJsonFragment(['pending' => false]);
        $this->assertStringContainsString('nie jest potwierdzenie doreczenia', view('components.order-sms-history', ['messages' => collect([$message->fresh()]), 'order' => $message->order])->render());
    }

    public function test_wrong_device_token_and_wrong_claim_are_rejected(): void
    {
        $this->device();
        $message = $this->message();
        $this->postJson(route('macrodroid.api.claim'), ['job_id' => $message->public_id])->assertUnauthorized();
        $this->withToken(str_repeat('z', 64))->postJson(route('macrodroid.api.claim'), ['job_id' => $message->public_id])->assertUnauthorized();
        $this->api('claim', ['job_id' => $message->public_id])->assertOk();
        $this->api('begin', ['job_id' => $message->public_id, 'claim_token' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_stale_customer_phone_cancels_before_claim(): void
    {
        $device = $this->device();
        $message = $this->message();
        $message->order->update(['customer_phone' => '500000001']);
        $this->api('claim', ['job_id' => $message->public_id])->assertStatus(409)->assertJsonPath('available', false);
        $this->assertSame('cancelled', $message->fresh()->status);
    }

    public function test_reusing_a_batch_key_with_another_recipient_does_not_create_another_message(): void
    {
        $this->device();
        $order = $this->order();
        $data = $this->manualData();
        $this->postJson(route('orders.sms.store', $order), $data)->assertCreated();
        $data['recipient'] = '500000001';
        $this->postJson(route('orders.sms.store', $order), $data)->assertUnprocessable();
        $this->assertDatabaseCount('order_sms_messages', 1);
    }

    public function test_customer_change_between_claim_and_begin_prevents_send(): void
    {
        $this->device();
        $message = $this->message();
        $claim = $this->api('claim', ['job_id' => $message->public_id])->json();
        $message->order->update(['customer_phone' => '500000001']);
        $this->api('begin', ['job_id' => $message->public_id, 'claim_token' => $claim['claim_token']])->assertStatus(409)->assertJsonPath('may_send', false);
    }

    public function test_only_one_message_is_claimed_at_a_time_and_rate_limits_are_enforced(): void
    {
        $device = $this->device();
        $device->update(['per_minute' => 1]);
        $first = $this->message();
        $second = $this->message();
        $claim = $this->api('claim', ['job_id' => $first->public_id])->json();
        $this->api('claim', ['job_id' => $second->public_id])->assertStatus(409)->assertJsonPath('available', false);
        $this->api('acknowledge', ['job_id' => $first->public_id, 'claim_token' => $claim['claim_token'], 'outcome' => 'failed'])->assertOk();
        $this->api('claim', ['job_id' => $second->public_id])->assertStatus(409)->assertJsonPath('available', false);
        $this->travel(61)->seconds();
        $this->api('claim', ['job_id' => $second->public_id])->assertOk()->assertJsonPath('available', true);
    }

    public function test_expired_claim_is_not_reissued_and_interrupted_send_becomes_uncertain(): void
    {
        $device = $this->device();
        $first = $this->message();
        $claim = $this->api('claim', ['job_id' => $first->public_id])->json();
        $this->travel(121)->seconds();
        $this->api('begin', ['job_id' => $first->public_id, 'claim_token' => $claim['claim_token']])->assertJsonPath('may_send', false);
        $this->assertSame('expired', $first->fresh()->status);
        $second = $this->message();
        $claim = $this->api('claim', ['job_id' => $second->public_id])->json();
        $payload = ['job_id' => $second->public_id, 'claim_token' => $claim['claim_token']];
        $this->api('begin', $payload)->assertJsonPath('may_send', true);
        $this->travel(6)->minutes();
        app(SmsGatewayService::class)->expire();
        $this->assertSame('uncertain', $second->fresh()->status);
        $this->api('acknowledge', $payload + ['outcome' => 'submitted'])->assertJsonPath('status', 'uncertain');
        $this->api('claim', ['job_id' => $second->public_id])->assertJsonPath('available', false);
    }

    public function test_rotation_disables_device_cancels_pending_and_never_exposes_saved_token(): void
    {
        $device = $this->device();
        $message = $this->message();
        $response = $this->postJson(route('integrations.macrodroid.key'), ['expected_lock_version' => 0])->assertOk();
        $this->assertSame(64, strlen($response->json('access_token')));
        $this->assertNotSame($response->json('access_token'), $device->fresh()->token_hash);
        $this->assertFalse($device->fresh()->enabled);
        $this->assertSame('cancelled', $message->fresh()->status);
        $this->get(route('integrations.macrodroid.edit'))->assertDontSee($response->json('access_token'))->assertDontSee($device->webhook_url);
        $this->postJson(route('integrations.macrodroid.key'), ['expected_lock_version' => 0])->assertConflict();
    }

    public function test_probe_requires_a_matching_device_callback_and_never_creates_an_sms(): void
    {
        $device = $this->device();
        $this->post(route('integrations.macrodroid.probe'), ['expected_lock_version' => 0])->assertRedirect();
        $device->refresh();
        $this->assertNull($device->probe_confirmed_at);
        $this->api('heartbeat', ['probe_id' => (string) Str::uuid()])->assertJsonPath('probe_confirmed', false);
        $this->api('heartbeat', ['probe_id' => $device->probe_id])->assertJsonPath('probe_confirmed', true);
        $this->assertNotNull($device->fresh()->probe_confirmed_at);
        $this->assertDatabaseCount('order_sms_messages', 0);
    }

    public function test_webhook_is_only_a_wakeup_without_message_data(): void
    {
        $device = $this->device();
        $message = $this->message();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('', 200)]);
        (new NotifySmsDeviceJob($message->public_id, $device->generation))->handle();
        Http::assertSent(fn ($request) => $request->data() === []
            && ! str_contains($request->url(), '500000000') && ! str_contains($request->url(), $this->token));
        $this->assertSame('queued', $message->fresh()->status);
        $this->assertFalse(NotifySmsDeviceJob::validWebhook('https://trigger.macrodroid.com.evil.test/deviceabcdefgh/sms'));
        $this->assertFalse(NotifySmsDeviceJob::validWebhook('http://127.0.0.1/sms'));
    }

    public function test_templates_validate_variables_lock_version_and_preserve_history_on_delete(): void
    {
        $this->post(route('orders.sms-templates.store'), ['name' => 'Test', 'body_template' => '[nieznana]'])->assertSessionHasErrors('body', null, 'smsTemplate');
        $this->post(route('orders.sms-templates.store'), ['name' => 'Test', 'body_template' => 'Nr [id_zamowienia]'])->assertRedirect();
        $template = SmsTemplate::query()->sole();
        $this->patchJson(route('orders.sms-templates.update', $template), ['name' => 'Test', 'body_template' => 'Inna', 'expected_lock_version' => 99])->assertConflict();
        $this->device();
        $message = app(SmsPreparationService::class)->prepare($this->order(), $template, ['idempotency_key' => (string) Str::uuid()])[0];
        $this->delete(route('orders.sms-templates.destroy', $template), ['expected_lock_version' => 0])->assertRedirect();
        $this->assertNull($message->fresh()->sms_template_id);
        $this->assertSame('Test', $message->fresh()->template_name_snapshot);
    }

    public function test_portal_link_cannot_be_sent_to_an_additional_recipient(): void
    {
        $this->device();
        $order = $this->order();
        $this->postJson(route('orders.sms.store', $order), array_replace($this->manualData(), ['body' => '[link_zamowienia]', 'recipient' => '500000001']))->assertUnprocessable();
        $this->assertDatabaseCount('order_sms_messages', 0);
        $this->postJson(route('orders.sms.store', $order), array_replace($this->manualData(), ['body' => '[link_zamowienia]']))->assertCreated();
        $this->assertStringContainsString('/'.$order->id.'/', SmsMessage::query()->sole()->body);
    }

    public function test_automation_deduplicates_recipients_and_replay_and_records_queued_not_sent(): void
    {
        $this->device();
        $order = $this->order();
        $run = AutomationRun::query()->create(['order_id' => $order->id, 'event_id' => (string) Str::uuid(),
            'event_name' => 'order.status_changed', 'chain_id' => (string) Str::uuid(), 'depth' => 0,
            'status' => 'running', 'event_payload' => [], 'rule_snapshot' => []]);
        $step = $run->steps()->create(['position' => 1, 'action_type' => 'send_sms', 'status' => 'running', 'configuration' => []]);
        $configuration = ['sms_template_id' => $this->template()->id, 'recipient_customer' => true,
            'recipient_phones' => ['+48 500 000 000', '500000001', '+48500000001']];
        $first = app(AutomationSmsActionService::class)->execute($run, $step, $configuration);
        $this->assertSame($first, app(AutomationSmsActionService::class)->execute($run, $step, $configuration));
        $this->assertSame('queued', $first['status']);
        $this->assertSame(2, $first['recipient_count']);
        $this->assertDatabaseCount('order_sms_messages', 2);
    }

    public function test_automation_editor_saves_sms_configuration_and_rejects_invalid_phone(): void
    {
        $template = $this->template();
        $data = ['name' => 'SMS', 'trigger' => 'order.status_changed', 'is_active' => true, 'conditions' => [],
            'actions' => [['type' => 'send_sms', 'stop_on_error' => true,
                'configuration' => ['sms_template_id' => $template->id, 'recipient_customer' => false, 'recipient_phones' => ['500000001']]]]];
        $this->get(route('orders.automatic-actions.index'))->assertOk()->assertSee('send_sms', false);
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertCreated();
        $data['actions'][0]['configuration']['recipient_phones'] = ['blad'];
        $this->postJson(route('orders.automatic-actions.store'), $data)->assertUnprocessable();
    }

    public function test_trashed_order_is_not_issued_to_phone(): void
    {
        $this->device();
        $message = $this->message();
        $message->order->delete();
        $this->api('claim', ['job_id' => $message->public_id])->assertJsonPath('available', false);
        $this->assertSame('cancelled', $message->fresh()->status);
    }

    public function test_cancel_is_order_scoped_and_does_not_cancel_an_in_progress_send(): void
    {
        $this->device();
        $message = $this->message();
        $this->postJson(route('orders.sms.cancel', [$this->order(), $message]))->assertNotFound();
        $claim = $this->api('claim', ['job_id' => $message->public_id])->json();
        $this->api('begin', ['job_id' => $message->public_id, 'claim_token' => $claim['claim_token']]);
        $this->postJson(route('orders.sms.cancel', [$message->order_id, $message]))->assertConflict();
    }

    public function test_settings_reject_ssrf_and_do_not_enable_on_get_or_key_generation(): void
    {
        $data = ['name' => 'Telefon', 'enabled' => false, 'per_minute' => 6, 'per_day' => 200,
            'lifetime_minutes' => 60, 'max_parts' => 5, 'expected_lock_version' => 0];
        $this->putJson(route('integrations.macrodroid.update'), $data + ['webhook_url' => 'https://127.0.0.1/private'])->assertUnprocessable();
        $this->postJson(route('integrations.macrodroid.key'), ['expected_lock_version' => 0])->assertOk();
        $this->assertFalse(SmsDevice::query()->sole()->enabled);
        $data['expected_lock_version'] = 1;
        $data['webhook_url'] = 'https://trigger.macrodroid.com/device-fake-12345678/sms';
        $this->put(route('integrations.macrodroid.update'), $data)->assertRedirect();
        $this->assertFalse(SmsDevice::query()->sole()->enabled);
        $this->assertDatabaseCount('order_sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_test_sms_requires_confirmation_and_uses_one_time_intent(): void
    {
        $this->device();
        $data = ['recipient' => '500000001', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson(route('integrations.macrodroid.test-sms'), $data)->assertUnprocessable();
        $this->post(route('integrations.macrodroid.test-sms'), $data + ['confirm' => 1])->assertRedirect();
        $this->post(route('integrations.macrodroid.test-sms'), $data + ['confirm' => 1])->assertRedirect();
        $this->assertDatabaseCount('order_sms_messages', 1);
        $message = SmsMessage::query()->sole();
        $this->assertNull($message->order_id);
        $this->assertSame('test', $message->source);
        Http::assertNothingSent();
    }

    public function test_daily_limit_and_queued_expiry_never_reissue_phone_data(): void
    {
        $device = $this->device();
        $device->update(['per_day' => 1]);
        $first = $this->message();
        $claim = $this->api('claim', ['job_id' => $first->public_id])->json();
        $this->api('acknowledge', ['job_id' => $first->public_id, 'claim_token' => $claim['claim_token'], 'outcome' => 'failed']);
        $second = $this->message();
        $this->travel(2)->minutes();
        $this->api('claim', ['job_id' => $second->public_id])->assertJsonPath('available', false);
        $this->travel(61)->minutes();
        $this->api('claim', ['job_id' => $second->public_id])->assertJsonPath('available', false)->assertJsonMissingPath('phone');
        $this->assertSame('expired', $second->fresh()->status);
    }

    public function test_attempt_to_ack_before_begin_cannot_claim_success(): void
    {
        $this->device();
        $message = $this->message();
        $claim = $this->api('claim', ['job_id' => $message->public_id])->json();
        $this->api('acknowledge', ['job_id' => $message->public_id, 'claim_token' => $claim['claim_token'], 'outcome' => 'submitted'])->assertConflict();
        $this->assertSame('claimed', $message->fresh()->status);
    }

    public function test_administrative_posts_keep_csrf_and_device_api_has_its_own_authentication(): void
    {
        $this->app->instance(ValidateCsrfToken::class,
            new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->postJson(route('orders.sms-templates.store'), ['name' => 'Test', 'body_template' => 'Test'])->assertStatus(419);
        $this->postJson(route('macrodroid.api.claim'), ['job_id' => (string) Str::uuid()])->assertUnauthorized();
    }

    public function test_public_customer_host_does_not_expose_sms_admin_or_device_api(): void
    {
        $order = $this->order();
        PortalSetting::query()->first()->update(['customer_domain' => 'client.example.test']);
        $this->get('https://client.example.test/integrations/macrodroid')->assertNotFound();
        $this->postJson('https://client.example.test/api/macrodroid/v1/claim', ['job_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_phone_can_claim_the_next_job_without_cloud_variables(): void
    {
        $this->device();
        $message = $this->message();
        $this->api('claim', [])->assertOk()->assertJsonPath('job_id', $message->public_id);
        $this->api('claim', [])->assertStatus(409)->assertJsonPath('available', false)->assertJsonMissingPath('text');
    }

    public function test_probe_wakeup_uses_a_separate_macro_and_challenge_never_claims_an_sms(): void
    {
        $device = $this->device();
        $message = $this->message();
        $probe = (string) Str::uuid();
        $device->update(['probe_id' => $probe, 'probe_expires_at' => now()->addMinutes(5)]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('', 200)]);
        (new NotifySmsDeviceJob($probe, $device->generation, true))->handle();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/nex-oms-sms-probe') && $request->data() === []);
        $this->api('heartbeat', [])->assertOk()->assertJsonPath('probe_id', $probe);
        $this->api('heartbeat', ['probe_id' => $probe])->assertOk()->assertJsonPath('probe_confirmed', true);
        $this->assertSame('queued', $message->fresh()->status);
    }

    public function test_webhook_redirect_is_not_followed_and_does_not_change_message_status(): void
    {
        $device = $this->device();
        $message = $this->message();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('', 302, ['Location' => 'https://example.test/private'])]);
        try {
            (new NotifySmsDeviceJob($message->public_id, $device->generation))->handle();
            $this->fail('Redirect must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('sms_device_wakeup_failed', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame('queued', $message->fresh()->status);
    }

    public function test_cloud_wakeup_is_coalesced_for_the_first_queued_message_and_rate_limited(): void
    {
        $device = $this->device();
        $first = $this->message();
        $second = $this->message();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('', 200)]);
        (new NotifySmsDeviceJob($second->public_id, $device->generation))->handle();
        Http::assertNothingSent();
        for ($i = 0; $i < 32; $i++) {
            (new NotifySmsDeviceJob($first->public_id, $device->generation))->handle();
        }
        Http::assertSentCount(30);
        $this->assertSame('queued', $first->fresh()->status);
    }

    private function api(string $operation, array $data)
    {
        return $this->withToken($this->token)->postJson(route('macrodroid.api.'.$operation), $data);
    }

    private function device(): SmsDevice
    {
        return SmsDevice::query()->create(['singleton_key' => 'primary', 'name' => 'Test', 'enabled' => true,
            'token_hash' => hash('sha256', $this->token), 'webhook_url' => 'https://trigger.macrodroid.com/fake-device-12345678/nex-oms-sms',
            'generation' => 1, 'per_minute' => 6, 'per_day' => 200, 'max_parts' => 5, 'lifetime_minutes' => 60]);
    }

    private function order(): Order
    {
        return Order::query()->create(['source' => 'manual', 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid',
            'customer_email' => 'test@example.test', 'customer_phone' => '500000000', 'billing_name' => 'Jan Test']);
    }

    private function template(): SmsTemplate
    {
        return SmsTemplate::query()->create(['name' => 'Potwierdzenie', 'body_template' => 'Zamowienie [id_zamowienia]']);
    }

    private function manualData(): array
    {
        return ['body' => 'Zamowienie [id_zamowienia]', 'recipient' => '500000000', 'idempotency_key' => (string) Str::uuid()];
    }

    private function message(): SmsMessage
    {
        return app(SmsPreparationService::class)->prepare($this->order(), null, [
            'body' => 'Zamowienie [id_zamowienia]', 'idempotency_key' => (string) Str::uuid(),
        ])[0];
    }
}
