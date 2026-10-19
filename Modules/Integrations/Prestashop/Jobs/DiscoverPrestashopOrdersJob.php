<?php

namespace Modules\Integrations\Prestashop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Services\PrestashopOrderDiscoveryService;
use Throwable;

class DiscoverPrestashopOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $uniqueFor = 600;

    public int $timeout = 120;

    public array $backoff = [15, 60, 180];

    public function __construct(public int $accountId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'prestashop-order-discovery:'.$this->accountId;
    }

    public function handle(PrestashopOrderDiscoveryService $discovery): void
    {
        try {
            $discovery->discover($this->accountId);
        } catch (PrestashopApiException $exception) {
            if ($this->shouldRetry($exception)) {
                throw $exception;
            }

            $discovery->recordRunFailure($this->accountId, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            app(PrestashopOrderDiscoveryService::class)
                ->recordRunFailure($this->accountId, $exception);
        }
    }

    private function shouldRetry(PrestashopApiException $exception): bool
    {
        return $exception->statusCode === null
            || $exception->statusCode === 429
            || $exception->statusCode >= 500;
    }
}
