<?php

namespace App\Services;

use App\Events\ReturnParcelStatusChanged;
use App\Models\OrderReturn;
use App\Models\OrderReturnParcel;
use App\Models\OrderReturnParcelEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Shipments\Jobs\RefreshReturnParcelJob;
use Modules\Shipments\Services\ReturnParcelTrackingClient;
use Throwable;

class ReturnParcelTrackingService
{
    public function __construct(private readonly ReturnParcelTrackingClient $client) {}

    public function sync(int $parcelId): void
    {
        $parcel = OrderReturnParcel::query()->with('orderReturn.account')->find($parcelId);
        if (! $parcel || ! $parcel->orderReturn || $parcel->isTerminal()) {
            return;
        }
        $identity = $parcel->identity();
        $context = $this->context($parcel->orderReturn);
        try {
            $events = $this->client->fetch($parcel);
            if ($events === [] || count($events) > 1000) {
                throw new DomainException('Przewoźnik nie udostępnia historii tej przesyłki.');
            }
            DB::transaction(function () use ($parcel, $identity, $context, $events): void {
                $record = OrderReturn::query()->lockForUpdate()->find($parcel->order_return_id);
                $managed = $record?->parcels()->lockForUpdate()->find($parcel->id);
                if (! $record || ! $managed || ! hash_equals($identity, $managed->identity()) || $context !== $this->context($record)) {
                    return;
                }
                $latest = null;
                $startedAt = null;
                foreach ($events as $event) {
                    if (in_array($event['tracking_status'], ['created', 'dispatched'], true)
                        && ($startedAt === null || $event['occurred_at']->lessThan($startedAt))) {
                        $startedAt = $event['occurred_at'];
                    }
                    $key = hash('sha256', json_encode([$identity, $event['provider_status'], $event['occurred_at']->utc()->format('Y-m-d H:i:s.u')], JSON_THROW_ON_ERROR));
                    $stored = OrderReturnParcelEvent::query()->firstOrCreate(['event_key' => $key], $event + [
                        'event_id' => (string) Str::uuid(), 'order_return_id' => $record->id,
                        'order_return_parcel_id' => $managed->id, 'tracking_identity' => $identity,
                    ]);
                    if ($latest === null || $stored->occurred_at->greaterThan($latest->occurred_at)) {
                        $latest = $stored;
                    }
                }
                if ($startedAt && (! $managed->tracking_started_at || $startedAt->lessThan($managed->tracking_started_at))) {
                    $managed->tracking_started_at = $startedAt;
                }
                $oldStatus = $managed->statusCode();
                $newer = ! $managed->hasCurrentTracking() || $managed->tracking_status_at === null
                    || $latest->occurred_at->greaterThan($managed->tracking_status_at);
                if ($newer) {
                    $managed->fill(['tracking_identity' => $identity, 'tracking_status' => $latest->tracking_status,
                        'provider_status' => $latest->provider_status, 'tracking_status_at' => $latest->occurred_at]);
                }
                $managed->fill(['tracking_checked_at' => now(), 'tracking_error' => null,
                    'tracking_next_sync_at' => $managed->isTerminal() ? null : now()->addHour()])->save();
                if ($newer && $oldStatus !== $managed->tracking_status) {
                    $record->events()->create(['type' => 'parcel_status_changed', 'previous_status' => $oldStatus,
                        'status' => $managed->tracking_status, 'observed_at' => now()]);
                    $record->order?->events()->create(['event_type' => 'return_parcel_status_changed',
                        'title' => 'Status przesyłki zwrotnej', 'description' => 'Zwrot #'.$record->id.': '.$managed->statusLabel(),
                        'payload' => ['order_return_id' => $record->id, 'return_parcel_id' => $managed->id,
                            'old_status' => $oldStatus, 'new_status' => $managed->tracking_status]]);
                    $latest->update(['automation_pending' => true, 'previous_status' => $oldStatus]);
                }
            }, 3);
            $this->dispatchPendingEvents();
        } catch (Throwable $exception) {
            $message = $exception instanceof DomainException || $exception instanceof AllegroApiException
                ? $exception->getMessage() : 'Nie udało się odczytać statusu przesyłki zwrotnej. Spróbuj ponownie później.';
            DB::transaction(function () use ($parcel, $identity, $context, $message): void {
                $record = OrderReturn::query()->lockForUpdate()->find($parcel->order_return_id);
                $managed = $record?->parcels()->lockForUpdate()->find($parcel->id);
                if ($record && $managed && hash_equals($identity, $managed->identity()) && $context === $this->context($record)) {
                    $managed->update(['tracking_checked_at' => now(), 'tracking_next_sync_at' => now()->addHour(), 'tracking_error' => mb_substr($message, 0, 500)]);
                }
            }, 3);
        }
    }

    public function dispatchDue(): void
    {
        $this->dispatchPendingEvents();
        OrderReturnParcel::query()->where(fn ($query) => $query->whereNotNull('waybill')->orWhereNotNull('transporting_waybill'))
            ->where(fn ($query) => $query->where('submitted_at', '>=', now()->subDays(60))
                ->orWhere(fn ($query) => $query->whereNull('submitted_at')->whereHas('orderReturn', fn ($query) => $query->where('submitted_at', '>=', now()->subDays(60)))))
            ->where(fn ($query) => $query->whereNull('tracking_status')->orWhere('tracking_status', '<>', 'delivered'))
            ->where(fn ($query) => $query->whereNull('provider_status')->orWhereNotIn('provider_status', ['returned', 'returned_to_sender', 'cancelled', 'canceled']))
            ->where(fn ($query) => $query->whereNull('tracking_next_sync_at')->orWhere('tracking_next_sync_at', '<=', CarbonImmutable::now('UTC')))
            ->orderBy('id')->limit(100)->get()->each(function (OrderReturnParcel $parcel): void {
                if ($parcel->isTerminal()) {
                    return;
                }
                DB::transaction(function () use ($parcel): void {
                    $managed = OrderReturnParcel::query()->lockForUpdate()->find($parcel->id);
                    if (! $managed || $managed->isTerminal() || $managed->tracking_next_sync_at?->isFuture()) {
                        return;
                    }
                    $managed->update(['tracking_next_sync_at' => now()->addMinutes(10)]);
                    RefreshReturnParcelJob::dispatch($managed->id)->afterCommit();
                }, 3);
            });
    }

    public function dispatchPendingEvents(): void
    {
        OrderReturnParcelEvent::query()->where('automation_pending', true)->orderBy('id')->limit(100)->get()
            ->each(function (OrderReturnParcelEvent $event): void {
                $parcel = OrderReturnParcel::query()->with('orderReturn')->find($event->order_return_parcel_id);
                if ($parcel?->orderReturn && $parcel->hasCurrentTracking()
                    && hash_equals($parcel->identity(), $event->tracking_identity)
                    && $parcel->statusCode() === $event->tracking_status) {
                    ReturnParcelStatusChanged::dispatch($parcel, $event, $event->previous_status);
                }
                $event->update(['automation_pending' => false]);
            });
    }

    private function context(OrderReturn $record): array
    {
        return [$record->order_id, $record->allegro_account_id, $record->environment, $record->seller_user_id];
    }
}
