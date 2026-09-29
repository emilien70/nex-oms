<?php

namespace Tests\Feature;

use App\Contracts\OrderEmailTransport;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Services\OrderEmailQueuedRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OrderEmailRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_stale_untouched_queued_message_is_dispatched_without_smtp(): void
    {
        $message = $this->message(OrderEmailMessageStatus::Queued, now()->subMinutes(11));
        $transport = new RecoveryOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        $count = app(OrderEmailQueuedRecoveryService::class)->recover();

        $this->assertSame(1, $count);
        Queue::assertPushed(
            SendOrderEmailJob::class,
            fn (SendOrderEmailJob $job): bool => $job->messageId === $message->getKey(),
        );
        $this->assertSame(0, $transport->sendCount);
        $this->assertSame(OrderEmailMessageStatus::Queued, $message->fresh()->status);
    }

    public function test_fresh_queued_message_is_not_dispatched(): void
    {
        $this->message(OrderEmailMessageStatus::Queued, now()->subMinutes(9));

        $this->assertSame(0, app(OrderEmailQueuedRecoveryService::class)->recover());
        Queue::assertNothingPushed();
    }

    public function test_non_recoverable_statuses_and_attempted_messages_are_not_dispatched(): void
    {
        foreach ([
            OrderEmailMessageStatus::Sending,
            OrderEmailMessageStatus::Sent,
            OrderEmailMessageStatus::Failed,
            OrderEmailMessageStatus::Unknown,
        ] as $status) {
            $this->message($status, now()->subHour(), [
                'sending_started_at' => $status === OrderEmailMessageStatus::Sending ? now()->subHour() : null,
            ]);
        }

        $this->message(OrderEmailMessageStatus::Queued, now()->subHour(), ['attempt_count' => 1]);
        $this->message(OrderEmailMessageStatus::Queued, now()->subHour(), ['sending_started_at' => now()->subHour()]);

        $this->assertSame(0, app(OrderEmailQueuedRecoveryService::class)->recover());
        Queue::assertNothingPushed();
    }

    private function message(
        OrderEmailMessageStatus $status,
        \DateTimeInterface $queuedAt,
        array $overrides = [],
    ): OrderEmailMessage {
        $order = Order::query()->create([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
        ]);

        return $order->emailMessages()->create(array_merge([
            'source' => OrderEmailMessageSource::Manual,
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'from_email' => 'sender@example.test',
            'to_email' => 'buyer@example.test',
            'subject' => 'Test',
            'format' => EmailTemplateFormat::PlainText,
            'body_text' => 'Test',
            'attempt_count' => 0,
            'queued_at' => $queuedAt,
        ], $overrides));
    }
}

final class RecoveryOrderEmailTransport implements OrderEmailTransport
{
    public int $sendCount = 0;

    public function send(EmailAccount $account, Email $email): void
    {
        $this->sendCount++;
    }
}
