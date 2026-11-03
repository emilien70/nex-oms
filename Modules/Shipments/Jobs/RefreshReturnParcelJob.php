<?php

namespace Modules\Shipments\Jobs;

use App\Services\ReturnParcelTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Shipments\Support\ShipmentQueue;

class RefreshReturnParcelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $parcelId)
    {
        $this->onQueue(ShipmentQueue::SYNC);
    }

    public function uniqueId(): string
    {
        return 'return-parcel:'.$this->parcelId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(15)->expireAfter(120)];
    }

    public function handle(ReturnParcelTrackingService $tracking): void
    {
        $tracking->sync($this->parcelId);
    }
}
