<?php

namespace Modules\Integrations\Prestashop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Services\PrestashopAutomaticOrderImportService;
use Throwable;

class ImportPrestashopOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $uniqueFor = 900;

    public int $timeout = 180;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $attemptId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'prestashop-order-import:'.$this->attemptId;
    }

    public function handle(PrestashopAutomaticOrderImportService $importer): void
    {
        try {
            $importer->importAttempt($this->attemptId);
        } catch (PrestashopImportException $exception) {
            $importer->recordFailure($this->attemptId, $exception);
        } catch (PrestashopApiException $exception) {
            if ($this->shouldRetry($exception)) {
                throw $exception;
            }

            $importer->recordFailure($this->attemptId, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            app(PrestashopAutomaticOrderImportService::class)
                ->recordFailure($this->attemptId, $exception);
        }
    }

    private function shouldRetry(PrestashopApiException $exception): bool
    {
        return $exception->statusCode === null
            || $exception->statusCode === 429
            || $exception->statusCode >= 500;
    }
}
