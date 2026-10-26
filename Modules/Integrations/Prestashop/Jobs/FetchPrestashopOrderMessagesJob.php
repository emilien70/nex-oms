<?php

namespace Modules\Integrations\Prestashop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Services\PrestashopOrderMessageService;
use Throwable;

class FetchPrestashopOrderMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $uniqueFor = 900;

    public int $timeout = 80;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $linkId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'prestashop-order-messages:'.$this->linkId;
    }

    public function handle(PrestashopOrderMessageService $messages): void
    {
        try {
            $messages->sync($this->linkId);
        } catch (PrestashopApiException $exception) {
            if ($exception->statusCode === null || $exception->statusCode === 429 || $exception->statusCode >= 500) {
                throw $exception;
            }
            $messages->recordFailure($this->linkId, 'read-http-'.$exception->statusCode);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PrestashopOrderMessageService::class)->recordFailure($this->linkId, 'failed');
    }
}
