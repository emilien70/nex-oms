<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Services\AllegroOutboundStatusSyncService;
use Throwable;

class SyncAllegroOrderStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 80;

    public int $uniqueFor = 600;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public int $orderId,
        public string $expectedOmsStatus,
        public string $eventId,
        public int $accountId,
        public int $configurationRevision,
    ) {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-status:'.$this->eventId;
    }

    public function middleware(): array
    {
        $link = AllegroOrderLink::query()->where('order_id', $this->orderId)->first();

        return $link ? [(new WithoutOverlapping('allegro-order:'.$link->environment.':'.$link->seller_user_id.':'.$link->external_order_id))
            ->shared()->releaseAfter(15)->expireAfter(180)] : [];
    }

    public function handle(AllegroOutboundStatusSyncService $sync): void
    {
        try {
            $sync->sync($this);
        } catch (Throwable $exception) {
            if ($exception instanceof AllegroApiException && ($exception->statusCode === null
                || in_array($exception->statusCode, [409, 429], true) || $exception->statusCode >= 500)) {
                throw $exception;
            }
            $sync->recordFailure($this, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AllegroOutboundStatusSyncService::class)->recordFailure($this, $exception ?? new AllegroApiException('Przekroczono czas przekazywania statusu do Allegro.'));
    }
}
