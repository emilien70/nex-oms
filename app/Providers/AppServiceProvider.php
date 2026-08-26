<?php

namespace App\Providers;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Automation\Listeners\DispatchAutomationEvent;
use Modules\Integrations\AllegroShipping\Drivers\AllegroShippingDriver;
use Modules\Integrations\DPD\Drivers\DpdDriver;
use Modules\Integrations\InPost\Drivers\InPostCourierDriver;
use Modules\Integrations\InPost\Drivers\InPostLockerDriver;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Observers\ShipmentObserver;
use Modules\Shipments\Services\CourierDriverRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CourierDriverRegistry::class, function ($app): CourierDriverRegistry {
            return new CourierDriverRegistry([
                CourierAccount::PROVIDER_INPOST_LOCKERS => $app->make(InPostLockerDriver::class),
                CourierAccount::PROVIDER_INPOST_COURIER => $app->make(InPostCourierDriver::class),
                CourierAccount::PROVIDER_DPD => $app->make(DpdDriver::class),
                CourierAccount::PROVIDER_ALLEGRO_SHIPPING => $app->make(AllegroShippingDriver::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('inpost-api', function (): Limit {
            $perMinute = max(1, (int) config('services.inpost.rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by('inpost-api');
        });

        RateLimiter::for('dpd-api', function (): Limit {
            $perMinute = max(1, (int) config('services.dpd.rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by('dpd-api');
        });

        RateLimiter::for('allegro-shipping-api', function (): Limit {
            $perMinute = max(1, (int) config('services.allegro_shipping.rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by('allegro-shipping-api');
        });

        Shipment::observe(ShipmentObserver::class);
        Event::listen(OrderStatusChanged::class, DispatchAutomationEvent::class);
        Event::listen(ShipmentCreated::class, DispatchAutomationEvent::class);
        Event::listen(ShipmentStatusChanged::class, DispatchAutomationEvent::class);
        Event::listen(ShipmentCreationFailed::class, DispatchAutomationEvent::class);
        Event::listen(KsefInvoiceAccepted::class, DispatchAutomationEvent::class);

        View::composer('layouts.app', function ($view): void {
            $statusSettings = OrderStatusSetting::orderedSettings();
            $statusCounts = array_fill_keys($statusSettings->pluck('code')->all(), 0);
            $trashCount = 0;

            if (Schema::hasTable('orders')) {
                Order::query()
                    ->whereIn('status', array_keys($statusCounts))
                    ->selectRaw('status, COUNT(*) as orders_count')
                    ->groupBy('status')
                    ->pluck('orders_count', 'status')
                    ->each(function ($count, $status) use (&$statusCounts): void {
                        $statusCounts[$status] = (int) $count;
                    });

                if (Schema::hasColumn('orders', 'deleted_at')) {
                    $trashCount = Order::onlyTrashed()->count();
                }
            }

            $view->with([
                'layoutOrderStatuses' => $statusSettings,
                'layoutOrderStatusCounts' => $statusCounts,
                'layoutTrashOrdersCount' => $trashCount,
            ]);
        });
    }
}
