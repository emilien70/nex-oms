<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Models\AllegroOrderImportEvent;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroOrderSyncService;
use Throwable;

class SyncAllegroOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public int $timeout = 80;

    public int $tries = 10;

    public function __construct(public int $eventId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-order-event:'.$this->eventId;
    }

    public function middleware(): array
    {
        $event = AllegroOrderImportEvent::query()->find($this->eventId);

        return [(new WithoutOverlapping('allegro-order:'.$event?->environment.':'.$event?->seller_user_id.':'.$event?->external_order_id))->shared()->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(AllegroOrderSyncService $sync): void
    {
        $event = AllegroOrderImportEvent::query()->with('account')->find($this->eventId);
        if (! $event || $event->status === 'processed' || ! $event->account?->order_import_enabled || ! $event->account->isOperational()
            || $event->environment !== $event->account->environment || $event->seller_user_id !== $event->account->allegro_user_id) {
            return;
        }
        $event->increment('attempts');
        try {
            $sync->sync($event->account, $event->external_order_id);
            $link = AllegroOrderLink::query()->where('environment', $event->environment)
                ->where('seller_user_id', $event->seller_user_id)->where('external_order_id', $event->external_order_id)->first();
            if ($link?->import_state === 'review') {
                throw new \RuntimeException($link->processing_error ?: 'Zakup wymaga sprawdzenia.');
            }
            $event->update(['status' => 'processed', 'last_error' => null, 'processed_at' => now(), 'next_attempt_at' => null]);
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000), 'next_attempt_at' => now()->addMinutes(min(30, 2 ** $event->attempts))]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AllegroOrderImportEvent::query()->whereKey($this->eventId)->where('status', '!=', 'processed')->update([
            'status' => 'failed', 'last_error' => mb_substr($exception?->getMessage() ?: 'Przekroczono czas synchronizacji zakupu Allegro.', 0, 1000),
            'next_attempt_at' => now()->addMinutes(5),
        ]);
    }
}
