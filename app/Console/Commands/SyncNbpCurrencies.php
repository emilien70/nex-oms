<?php

namespace App\Console\Commands;

use App\Services\NbpCurrencySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNbpCurrencies extends Command
{
    protected $signature = 'currencies:sync-nbp';

    protected $description = 'Synchronizuje lokalny katalog walut z tabelami A i B NBP';

    public function handle(NbpCurrencySyncService $syncService): int
    {
        try {
            $count = $syncService->sync();
        } catch (Throwable $exception) {
            Log::error('Synchronizacja katalogu walut NBP nie powiodła się.', [
                'exception' => $exception,
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Zsynchronizowano katalog walut NBP: {$count} walut.");

        return self::SUCCESS;
    }
}
