<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Services\PortalAccessService;

class PrepareCustomerPortalLinks extends Command
{
    protected $signature = 'customer-portal:prepare-links {--enable : Jednorazowo wlacz portal} {--dry-run : Tylko odczyt, bez zmian}';

    protected $description = 'Przygotuj brakujace linki zamowien bez odnawiania istniejacych';

    public function handle(PortalAccessService $access): int
    {
        if (! $access->ready()) {
            $this->error('Najpierw wykonaj migracje PORTAL.1.');

            return self::FAILURE;
        }
        $existingIds = PortalLink::query()->whereNotNull('order_id')->select('order_id');
        if ($this->option('dry-run')) {
            $this->info('Brakujace linki: '.Order::query()->whereNotIn('id', $existingIds)->count().'. Bez zmian.');

            return self::SUCCESS;
        }
        if ($this->option('enable')) {
            $access->enable();
        }
        $count = 0;
        foreach (Order::query()->whereNotIn('id', $existingIds)->select('id')->lazyById(100) as $order) {
            if ($access->provision($order->id)) {
                $count++;
            }
        }
        $this->info('Przygotowane brakujace linki: '.$count.'. Istniejace linki pozostaly bez zmian.');
        $this->info('Portal: '.($access->settings()->is_enabled ? 'wlaczony' : 'wylaczony').'. Ustawienia platnosci bez zmian.');

        return self::SUCCESS;
    }
}
