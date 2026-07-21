<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Integrations\DPD\Jobs\RefreshDpdShipmentJob;
use Modules\Integrations\AllegroShipping\Jobs\RefreshAllegroShipmentJob;
use Modules\Integrations\InPost\Jobs\RefreshInPostShipmentJob;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Services\IntegrationApiLogPruner;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $syncCutoff = now()->subMinutes(max(1, (int) config('services.inpost.sync_interval_minutes', 60)));

    Shipment::query()
        ->whereIn('provider', [
            CourierAccount::PROVIDER_INPOST_LOCKERS,
            CourierAccount::PROVIDER_INPOST_COURIER,
        ])
        ->whereNotNull('external_id')
        ->whereNotIn('oms_status', Shipment::terminalOmsStatuses())
        ->whereNotIn('status', [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY, 'canceled'])
        ->where(fn ($query) => $query
            ->whereNull('last_synced_at')
            ->orWhere('last_synced_at', '<=', $syncCutoff))
        ->eachById(fn (Shipment $shipment) => RefreshInPostShipmentJob::dispatch($shipment));
})->name('inpost-refresh-active-shipments')->hourly()->withoutOverlapping();

Schedule::call(function (): void {
    $syncCutoff = now()->subMinutes(max(1, (int) config('services.dpd.sync_interval_minutes', 60)));

    Shipment::query()
        ->where('provider', CourierAccount::PROVIDER_DPD)
        ->whereNotNull('external_id')
        ->whereNotNull('tracking_number')
        ->whereNotIn('oms_status', Shipment::terminalOmsStatuses())
        ->whereNotIn('status', [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY])
        ->where(fn ($query) => $query
            ->whereNull('last_synced_at')
            ->orWhere('last_synced_at', '<=', $syncCutoff))
        ->eachById(fn (Shipment $shipment) => RefreshDpdShipmentJob::dispatch($shipment));
})->name('dpd-refresh-active-shipments')->hourly()->withoutOverlapping();

Schedule::call(fn () => app(IntegrationApiLogPruner::class)->prune())
    ->name('inpost-prune-api-logs')
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::call(function (): void {
    $syncCutoff = now()->subMinutes(max(1, (int) config('services.allegro_shipping.sync_interval_minutes', 60)));

    Shipment::query()
        ->where('provider', CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
        ->whereNotNull('external_id')
        ->whereNotIn('oms_status', Shipment::terminalOmsStatuses())
        ->whereNotIn('status', [Shipment::STATUS_CANCELLED, Shipment::STATUS_CANCELLED_LOCALLY])
        ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $syncCutoff))
        ->eachById(fn (Shipment $shipment) => RefreshAllegroShipmentJob::dispatch($shipment));
})->name('allegro-shipping-refresh-active-shipments')->hourly()->withoutOverlapping();
