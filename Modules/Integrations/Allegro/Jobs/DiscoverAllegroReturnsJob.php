<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroReturnSyncService;
use Throwable;

class DiscoverAllegroReturnsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $accountId, public int $revision)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-returns:'.$this->accountId.':'.$this->revision;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('allegro-returns-account:'.$this->accountId))->shared()->dontRelease()->expireAfter(120)];
    }

    public function handle(AllegroReturnSyncService $sync): void
    {
        try {
            $sync->discover($this->accountId, $this->revision);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AllegroAccount::query()->whereKey($this->accountId)->where('returns_revision', $this->revision)
            ->update(['returns_error' => 'Nie udało się pobrać zwrotów. Zachowano poprzednie dane; odczyt zostanie ponowiony.']);
    }
}
