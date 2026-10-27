<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Services\PortalAccessService;

class ReplaceLegacyCustomerPortalLinks extends Command
{
    protected $signature = 'customer-portal:replace-legacy-links {--dry-run}';

    protected $description = 'Replace eligible active legacy customer portal codes without extending access.';

    public function handle(PortalAccessService $access): int
    {
        if (! $access->ready()) {
            $this->error('Najpierw wykonaj migracje portalu.');

            return self::FAILURE;
        }
        $count = 0;
        foreach (PortalLink::query()->whereNotNull('order_id')->lazyById(100) as $link) {
            if ($this->option('dry-run')) {
                $order = $link->order;
                $settings = $access->settings();
                if (strlen($link->token) !== 10 && $settings->is_enabled && $order && ! $link->revoked_at
                    && $link->expires_at->isFuture() && $access->expiry($order, $settings)->isFuture()) {
                    $count++;
                }
            } elseif ($access->replaceLegacyLink($link->order_id)) {
                $count++;
            }
        }
        $this->info(($this->option('dry-run') ? 'Potencjalne stare linki (bez zmian): ' : 'Wymienione aktywne linki: ').$count);

        return self::SUCCESS;
    }
}
