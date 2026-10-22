<?php

namespace Modules\Integrations\Allegro\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Services\AllegroOrderDiscoveryService;
use Throwable;

class DiscoverAllegroOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $accountId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return 'allegro-order-discovery:'.$this->accountId;
    }

    public function handle(AllegroOrderDiscoveryService $discovery): void
    {
        try {
            $discovery->discover($this->accountId);
        } catch (Throwable $exception) {
            AllegroAccount::query()->whereKey($this->accountId)->update(['last_order_import_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $account = AllegroAccount::query()->find($this->accountId);
            if ($account?->isOperational() && $account->order_import_enabled && $account->order_import_identity === $account->orderImportIdentity()) {
                $discovery->dispatchPending($account);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        AllegroAccount::query()->whereKey($this->accountId)->update([
            'last_order_import_error' => mb_substr($exception?->getMessage() ?: 'Przekroczono czas odczytu dziennika Allegro. Odczyt zostanie ponowiony.', 0, 1000),
        ]);
    }
}
