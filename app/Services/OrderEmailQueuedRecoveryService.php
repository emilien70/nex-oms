<?php

namespace App\Services;

use App\Enums\OrderEmailMessageStatus;
use App\Jobs\SendOrderEmailJob;
use App\Models\OrderEmailMessage;

final class OrderEmailQueuedRecoveryService
{
    public const STALE_AFTER_MINUTES = 10;

    public function recover(): int
    {
        $dispatched = 0;

        OrderEmailMessage::query()
            ->where('status', OrderEmailMessageStatus::Queued->value)
            ->where('queued_at', '<=', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->whereNull('sending_started_at')
            ->where('attempt_count', 0)
            ->eachById(function (OrderEmailMessage $message) use (&$dispatched): void {
                SendOrderEmailJob::dispatch($message->getKey());
                $dispatched++;
            });

        return $dispatched;
    }
}
