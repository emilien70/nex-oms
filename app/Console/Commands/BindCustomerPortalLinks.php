<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Services\PortalAccessService;

class BindCustomerPortalLinks extends Command
{
    protected $signature = 'customer-portal:bind-links {--dry-run}';

    protected $description = 'Bind existing customer portal codes permanently to their orders without changing URLs or access.';

    public function handle(PortalAccessService $access): int
    {
        if (! $access->bindingsReady()) {
            $this->error('Najpierw wykonaj migracje stalych przypisan portalu.');

            return self::FAILURE;
        }
        $count = 0;
        $legacy = 0;
        foreach (PortalLink::query()->whereNotNull('order_id')->lazyById(100) as $link) {
            try {
                if ($link->binding_hash === null && preg_match('/^[A-Za-z0-9_-]{32}$/D', $link->token) === 1
                    && hash_equals($link->token_hash, hash('sha256', $link->token))) {
                    $legacy++;

                    continue;
                }
                $count += (int) $access->bindExistingLink($link->id, (bool) $this->option('dry-run'));
            } catch (\RuntimeException $exception) {
                $this->error('Przerwano przypisywanie: sprawdz integralnosc linku ID '.$link->id.'. Nie wymieniono kodu.');

                return self::FAILURE;
            }
        }
        $this->info(($this->option('dry-run') ? 'Przypisania do uzupelnienia (bez zmian): ' : 'Uzupelnione stale przypisania: ').$count);
        if ($legacy > 0) {
            $this->info('Pominiete historyczne kody (bez zmian): '.$legacy);
        }

        return self::SUCCESS;
    }
}
