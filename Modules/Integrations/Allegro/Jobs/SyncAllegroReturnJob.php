<?php

namespace Modules\Integrations\Allegro\Jobs;

use App\Models\OrderReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Services\AllegroReturnSyncService;
use Throwable;

class SyncAllegroReturnJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $returnId, public int $revision)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-return:'.$this->returnId.':'.$this->revision;
    }

    public function middleware(): array
    {
        $accountId = OrderReturn::query()->whereKey($this->returnId)->value('allegro_account_id');

        return [(new WithoutOverlapping('allegro-returns-account:'.$accountId))->shared()->dontRelease()->expireAfter(120)];
    }

    public function handle(AllegroReturnSyncService $sync): void
    {
        try {
            $sync->refresh($this->returnId, $this->revision);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $record = OrderReturn::query()->with('account')->find($this->returnId);
        if ($record?->account?->returns_revision === $this->revision) {
            $record->update(['sync_error' => 'Nie udało się odświeżyć zwrotu. Wyświetlane są ostatnio pobrane dane.', 'next_sync_at' => now()->addMinutes(5)]);
        }
    }
}
