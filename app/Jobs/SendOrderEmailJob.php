<?php

namespace App\Jobs;

use App\Services\OrderEmailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendOrderEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public int $messageId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'order-email-'.$this->messageId;
    }

    public function handle(OrderEmailSender $sender): void
    {
        $sender->send($this->messageId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OrderEmailSender::class)->markInterrupted($this->messageId);
    }
}
