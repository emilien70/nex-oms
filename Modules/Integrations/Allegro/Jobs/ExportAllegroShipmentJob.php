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
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;
use Modules\Integrations\Allegro\Services\AllegroShipmentExportService;
use Throwable;

class ExportAllegroShipmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 80;

    public int $uniqueFor = 600;

    public array $backoff = [15, 60, 180];

    public function __construct(public int $exportId, public string $token)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-shipment:'.$this->token;
    }

    public function middleware(): array
    {
        $export = AllegroShipmentExport::query()->find($this->exportId);

        return $export ? [(new WithoutOverlapping('allegro-order:'.$export->environment.':'.$export->seller_user_id.':'.$export->external_order_id))
            ->shared()->releaseAfter(15)->expireAfter(180)] : [];
    }

    public function handle(AllegroShipmentExportService $exports): void
    {
        try {
            $exports->sync($this);
        } catch (Throwable $exception) {
            if ($exception instanceof AllegroApiException && ($exception->statusCode === null
                || in_array($exception->statusCode, [409, 429], true) || $exception->statusCode >= 500)) {
                throw $exception;
            }
            $exports->failure($this, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AllegroShipmentExportService::class)->failure($this, $exception ?? new AllegroApiException('Przekroczono czas przekazywania numeru przesyłki.'));
    }
}
