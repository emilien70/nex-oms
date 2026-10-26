<?php

namespace App\Providers;

use App\Contracts\OrderEmailTransport;
use App\Events\OrderPaymentRegistered;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderStatusSetting;
use App\Services\SmtpOrderEmailTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Automation\Listeners\DispatchAutomationEvent;
use Modules\Integrations\Allegro\Listeners\DispatchAllegroInvoiceExports;
use Modules\Integrations\Allegro\Listeners\DispatchAllegroShipmentExports;
use Modules\Integrations\Allegro\Listeners\DispatchAllegroStatusSync;
use Modules\Integrations\Allegro\Observers\AllegroShipmentTrackingObserver;
use Modules\Integrations\AllegroShipping\Drivers\AllegroShippingDriver;
use Modules\Integrations\DPD\Drivers\DpdDriver;
use Modules\Integrations\InPost\Drivers\InPostCourierDriver;
use Modules\Integrations\InPost\Drivers\InPostLockerDriver;
use Modules\Integrations\NexPrinter\Services\NexPrinterSelectionService;
use Modules\Integrations\Prestashop\Listeners\DispatchPrestashopStatusSync;
use Modules\Invoices\Events\CorrectionFinalized;
use Modules\Invoices\Events\InvoiceIssued;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Shipments\Events\ShipmentCreated;
use Modules\Shipments\Events\ShipmentCreationFailed;
use Modules\Shipments\Events\ShipmentStatusChanged;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentParcel;
use Modules\Shipments\Observers\ShipmentObserver;
use Modules\Shipments\Services\CourierDriverRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OrderEmailTransport::class, SmtpOrderEmailTransport::class);

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

        RateLimiter::for('nex-printer-pairing', static function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('nex-printer-agent', static function (Request $request): Limit {
            return Limit::perMinute(120)->by(hash(
                'sha256',
                (string) ($request->bearerToken() ?? $request->ip()),
            ));
        });

        RateLimiter::for('paynow-webhook', static function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->ip());
        });

        RateLimiter::for('paynow-customer', static fn (Request $request): Limit => Limit::perMinute(60)->by('paynow-page:'.$request->ip()));
        RateLimiter::for('paynow-customer-status', static fn (Request $request): array => [
            Limit::perMinute(30)->by('paynow-status-ip:'.$request->ip()),
            Limit::perMinute(12)->by('paynow-status-ref:'.hash('sha256', is_string($request->query('ref')) ? $request->query('ref') : 'invalid')),
        ]);
        RateLimiter::for('paynow-customer-retry', static fn (Request $request): array => [
            Limit::perMinute(5)->by('paynow-retry-ip:'.$request->ip()),
            Limit::perMinute(3)->by('paynow-retry-ref:'.hash('sha256', is_string($request->query('ref')) ? $request->query('ref') : 'invalid')),
        ]);

        Shipment::observe(ShipmentObserver::class);
        Shipment::observe(AllegroShipmentTrackingObserver::class);
        ShipmentParcel::observe(AllegroShipmentTrackingObserver::class);
        Event::listen(OrderPaymentRegistered::class, DispatchAutomationEvent::class);
        Event::listen(OrderStatusChanged::class, DispatchAutomationEvent::class);
        Event::listen(OrderStatusChanged::class, DispatchPrestashopStatusSync::class);
        Event::listen(OrderStatusChanged::class, DispatchAllegroStatusSync::class);
        Event::listen(OrderStatusChanged::class, DispatchAllegroShipmentExports::class);
        Event::listen(OrderStatusChanged::class, DispatchAllegroInvoiceExports::class);
        Event::listen(InvoiceIssued::class, DispatchAutomationEvent::class);
        Event::listen(InvoiceIssued::class, DispatchAllegroInvoiceExports::class);
        Event::listen(CorrectionFinalized::class, DispatchAllegroInvoiceExports::class);
        Event::listen(ShipmentCreated::class, DispatchAutomationEvent::class);
        Event::listen(ShipmentStatusChanged::class, DispatchAutomationEvent::class);
        Event::listen(ShipmentCreationFailed::class, DispatchAutomationEvent::class);
        Event::listen(KsefInvoiceAccepted::class, DispatchAutomationEvent::class);
        Event::listen(KsefInvoiceAccepted::class, DispatchAllegroInvoiceExports::class);

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

        View::composer([
            'orders.partials.sales-document-actions',
            'orders.partials.shipment-row',
            'invoices.index',
            'invoices.edit',
            'invoices.edit-blocked-by-correction',
            'invoices.corrections.create',
        ], function ($view): void {
            $view->with(
                'nexPrinterChoices',
                Schema::hasTable('nex_printers')
                    ? app(NexPrinterSelectionService::class)->availableForPdf()
                    : collect(),
            );
        });
    }
}
