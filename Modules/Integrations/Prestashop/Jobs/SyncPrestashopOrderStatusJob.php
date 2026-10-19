<?php

namespace Modules\Integrations\Prestashop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Services\PrestashopOutboundStatusSyncService;
use Throwable;

class SyncPrestashopOrderStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $uniqueFor = 600;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public int $orderId,
        public string $expectedOmsStatus,
        public string $eventId,
    ) {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'prestashop-status:'.$this->eventId;
    }

    public function handle(PrestashopOutboundStatusSyncService $syncService): void
    {
        try {
            $syncService->sync($this->orderId, $this->expectedOmsStatus, $this->eventId);
        } catch (PrestashopApiException $exception) {
            if ($this->shouldRetry($exception)) {
                throw $exception;
            }

            $syncService->recordFailure(
                $this->orderId,
                $this->expectedOmsStatus,
                $this->eventId,
                $exception,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        app(PrestashopOutboundStatusSyncService::class)->recordFailure(
            $this->orderId,
            $this->expectedOmsStatus,
            $this->eventId,
            $exception,
        );
    }

    private function shouldRetry(PrestashopApiException $exception): bool
    {
        return $exception->statusCode === null
            || $exception->statusCode === 429
            || $exception->statusCode >= 500;
    }
}
