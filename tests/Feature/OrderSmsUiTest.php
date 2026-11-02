<?php

namespace Tests\Feature;

use App\Models\Order;
use App\View\Components\OrderEmailExchange;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSmsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::swap((new Factory)->preventStrayRequests());
    }

    public function test_sms_form_uses_email_styles_and_configured_readonly_sim_number(): void
    {
        SmsDevice::query()->create(['singleton_key' => 'primary', 'sender_phone' => '+48500111222', 'enabled' => true]);
        $order = $this->order();
        $xpath = $this->exchange($order);
        $form = $xpath->query('//form[@data-sms-form]')->item(0);
        $this->assertStringContainsString('order-email-compose', $form->getAttribute('class'));
        $this->assertSame('+48500111222', $xpath->query('//input[@id="orderSmsSender" and @readonly]')->item(0)->getAttribute('value'));
        $this->assertSame('', $xpath->query('//input[@id="orderSmsSender"]')->item(0)->getAttribute('name'));
        $this->assertSame(0, $xpath->query('//input[@data-sms-recipient]')->length);
        $this->assertSame('Nie wybrano', trim($xpath->query('//select[@id="orderSmsTemplate"]/option[1]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//textarea[@id="orderSmsBody" and contains(@class,"order-email-editor")]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-sms-form]//span[@data-sms-character-count and text()="0"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-sms-form]//button[@type="submit" and not(@disabled)]')->length);
        $this->assertSame(2, $xpath->query('//input[@name="order_contact_channel"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-order-email-form]//input[@data-email-subject]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-order-email-form]//select[@name="email_account_id" and @required]')->length);
        $this->assertDatabaseCount('order_sms_messages', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_missing_sim_number_is_not_replaced_with_customer_number(): void
    {
        $xpath = $this->exchange($this->order());
        $sender = $xpath->query('//input[@id="orderSmsSender"]')->item(0);
        $this->assertSame('', $sender->getAttribute('value'));
        $this->assertSame('Nie uzupełniono numeru karty SIM', $sender->getAttribute('placeholder'));
        $this->assertSame(1, $xpath->query('//form[@data-sms-form]//button[@type="submit" and @disabled]')->length);
        $this->assertDatabaseCount('macrodroid_devices', 0);
    }

    public function test_disabled_device_still_shows_its_sender_but_cannot_send(): void
    {
        SmsDevice::query()->create(['singleton_key' => 'primary', 'sender_phone' => '+48500111222', 'enabled' => false]);
        $xpath = $this->exchange($this->order());
        $this->assertSame('+48500111222', $xpath->query('//input[@id="orderSmsSender"]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//form[@data-sms-form]//button[@type="submit" and @disabled]')->length);
    }

    public function test_empty_message_is_visible_only_without_email_or_sms_history(): void
    {
        $xpath = $this->exchange($this->order());
        $this->assertStringNotContainsString('d-none', $xpath->query('//*[@data-email-empty]')->item(0)->getAttribute('class'));
    }

    #[DataProvider('statuses')]
    public function test_sms_history_hides_empty_email_notice_and_only_submitted_is_green(string $status): void
    {
        $order = $this->order();
        $message = $this->sms($order, $status);
        $before = $message->fresh()->getRawOriginal();
        $xpath = $this->exchange($order);
        $this->assertStringContainsString('d-none', $xpath->query('//*[@data-email-empty]')->item(0)->getAttribute('class'));
        $badge = $xpath->query('//*[@data-sms-id="'.$message->id.'"]//span[contains(@class,"order-sms-status")]')->item(0);
        $this->assertSame($status === 'submitted', str_contains($badge->getAttribute('class'), 'is-sent'));
        if ($status === 'submitted') {
            $this->assertSame('Wysłane', trim($badge->textContent));
            $this->assertStringContainsString('nie jest potwierdzenie doręczenia', $badge->getAttribute('title'));
        }
        $article = $xpath->query('//*[@data-sms-id="'.$message->id.'"]')->item(0);
        $this->assertStringNotContainsString('Telefon wykonal akcje SMS', $article->textContent);
        $this->assertStringNotContainsString('Telefon zgłosił', $article->textContent);
        $this->assertStringNotContainsString('Recznie', $article->textContent);
        $this->assertStringNotContainsString('1 cz.', $article->textContent);
        $this->assertSame($before, $message->fresh()->getRawOriginal());
        $response = $this->getJson(route('orders.sms.history', $order))->assertOk();
        $this->assertSame(! $message->terminal(), $response->json('pending'));
        $this->assertStringContainsString('data-sms-id="'.$message->id.'"', $response->json('html'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], ['queued', 'claimed', 'submitting', 'submitted', 'failed', 'uncertain', 'expired', 'cancelled']);
    }

    #[DataProvider('emailContexts')]
    public function test_email_or_mixed_history_hides_empty_notice(bool $includeSms): void
    {
        $order = $this->order();
        $order->emailMessages()->create([
            'source' => 'manual', 'status' => 'sent', 'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'shop@example.test', 'to_email' => 'customer@example.test',
            'subject' => 'Wiadomosc e-mail', 'format' => 'plain_text', 'body_text' => 'Tresc e-maila',
        ]);
        if ($includeSms) {
            $this->sms($order, 'submitted');
        }
        $xpath = $this->exchange($order);
        $this->assertStringContainsString('d-none', $xpath->query('//*[@data-email-empty]')->item(0)->getAttribute('class'));
        $this->assertSame(1, $xpath->query('//*[@data-email-message-id]')->length);
        $this->assertSame($includeSms ? 1 : 0, $xpath->query('//*[@data-sms-id]')->length);
    }

    public static function emailContexts(): array
    {
        return [[false], [true]];
    }

    public function test_sms_history_escapes_body_recipient_and_actor(): void
    {
        $order = $this->order();
        $message = $this->sms($order, 'submitted');
        $message->update(['body' => '<script>alert(1)</script>', 'actor_name' => '<img src=x onerror=alert(1)>']);
        $html = view('components.order-sms-history', ['messages' => collect([$message]), 'order' => $order])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img ', $html);
    }

    public function test_order_page_resolves_recipient_on_send_instead_of_caching_phone(): void
    {
        $this->get(route('orders.show', $this->order()))->assertOk()
            ->assertSee('nexoms:sms-history-updated', false)
            ->assertSee('syncMessageEmptyState', false)
            ->assertSee("root.resolveMessageRecipient('sms')", false)
            ->assertSee("emailExchange.resolveMessageRecipient('email')", false)
            ->assertSee('recipient_token', false)
            ->assertSee('pendingContactSaves', false)
            ->assertDontSee('data-sms-recipient', false)
            ->assertSee('announceHistory(true)', false)
            ->assertSee('Array.from(data.body).length', false);
    }

    private function exchange(Order $order): DOMXPath
    {
        $html = (new OrderEmailExchange($order))->render()->with('order', $order)->render();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function order(): Order
    {
        return Order::query()->create(['source' => 'manual', 'status' => Order::STATUS_NEW, 'currency' => 'PLN',
            'total_gross' => '100.00', 'paid_amount' => '0.00', 'payment_status' => 'unpaid',
            'customer_email' => null, 'customer_phone' => '500000000', 'billing_name' => 'Jan Test']);
    }

    private function sms(Order $order, string $status): SmsMessage
    {
        return SmsMessage::query()->create(['public_id' => (string) Str::uuid(), 'order_id' => $order->id,
            'source' => 'manual', 'status' => $status, 'idempotency_key' => (string) Str::uuid(),
            'batch_key' => (string) Str::uuid(), 'intent_hash' => hash('sha256', 'FAKE_INTENT'),
            'device_generation' => 1, 'recipient' => '+48500000000', 'body' => 'Wiadomosc SMS',
            'actor_name' => 'Operator NEX-OMS', 'encoding' => 'GSM-7', 'units' => 14, 'parts' => 1,
            'expires_at' => now()->addHour(), 'completed_at' => $status === 'submitted' ? now() : null]);
    }
}
