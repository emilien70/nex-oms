<?php

namespace Modules\Shipments\Services;

use App\Models\OrderReturnParcel;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Allegro\Services\AllegroClient;
use Modules\Shipments\Models\CourierAccount;
use Throwable;

class ReturnParcelTrackingClient
{
    public function __construct(private readonly AllegroClient $allegro, private readonly ShipmentStatusMapper $mapper) {}

    public function fetch(OrderReturnParcel $parcel): array
    {
        $parcel->loadMissing('orderReturn.account');
        $record = $parcel->orderReturn;
        $carrier = strtoupper(trim((string) $parcel->carrier_id));
        $number = trim((string) $parcel->waybill);
        if ($carrier === '' || $number === '') {
            $carrier = strtoupper(trim((string) $parcel->transporting_carrier_id));
            $number = trim((string) $parcel->transporting_waybill);
        }
        if ($number === '' || strlen($number) > 255 || preg_match('/^[A-Z0-9_-]{1,50}$/D', $carrier) !== 1) {
            throw new DomainException('Brak numeru lub rozpoznanego przewoźnika przesyłki zwrotnej.');
        }
        if ($carrier === 'INPOST') {
            if ($record->environment === 'sandbox') {
                throw new DomainException('InPost nie udostępnia historii śledzenia w środowisku sandbox.');
            }
            $response = Http::acceptJson()->connectTimeout(10)->timeout(25)->withoutRedirecting()
                ->get(rtrim((string) config('services.inpost.production_url'), '/').'/v1/tracking/'.rawurlencode($number));
            if (! $response->successful()) {
                throw new DomainException('Nie udało się pobrać śledzenia InPost (HTTP '.$response->status().').');
            }

            return $this->inPost($response->json(), $number);
        }
        $account = $record->account;
        if (! $account || $account->environment !== $record->environment || $account->allegro_user_id !== $record->seller_user_id) {
            throw new DomainException('Automatyczne śledzenie tego przewoźnika wymaga właściwego konta Allegro.');
        }

        return $this->allegro($this->allegro->returnParcelTracking($account, $carrier, $number), $carrier, $number);
    }

    private function inPost(mixed $raw, string $number): array
    {
        if (! is_array($raw) || ($raw['tracking_number'] ?? null) !== $number || ! is_array($raw['tracking_details'] ?? null)
            || ! array_is_list($raw['tracking_details']) || count($raw['tracking_details']) > 1000) {
            throw new DomainException('Nieprawidłowa odpowiedź śledzenia InPost.');
        }
        $events = [];
        foreach ($raw['tracking_details'] as $event) {
            if (! is_array($event)) {
                throw new DomainException('Nieprawidłowe zdarzenie śledzenia InPost.');
            }
            $events[] = $this->event($event['status'] ?? null, $event['datetime'] ?? null, null, CourierAccount::PROVIDER_INPOST_LOCKERS);
        }
        $current = $this->event($raw['status'] ?? null, $raw['updated_at'] ?? null, null, CourierAccount::PROVIDER_INPOST_LOCKERS);
        $latest = collect($events)->sortByDesc(fn ($event) => $event['occurred_at']->getTimestampMs())->first();
        if ($latest === null || ($latest['provider_status'] !== $current['provider_status'] && $current['occurred_at']->greaterThanOrEqualTo($latest['occurred_at']))) {
            $events[] = $current;
        }

        return $events;
    }

    private function allegro(array $raw, string $carrier, string $number): array
    {
        if (($raw['carrierId'] ?? null) !== $carrier || ! is_array($raw['waybills'] ?? null)) {
            throw new DomainException('Nieprawidłowy przewoźnik odpowiedzi Allegro.');
        }
        $row = collect($raw['waybills'])->first(fn ($row) => is_array($row) && ($row['waybill'] ?? null) === $number);
        if (! is_array($row)) {
            throw new DomainException('Odpowiedź Allegro dotyczy innej przesyłki.');
        }
        $statuses = data_get($row, 'trackingDetails.statuses');
        if ($statuses === null) {
            return [];
        }
        if (! is_array($statuses) || ! array_is_list($statuses) || count($statuses) > 1000 || collect($statuses)->contains(fn ($event) => ! is_array($event))) {
            throw new DomainException('Nieprawidłowa historia przesyłki Allegro.');
        }

        return array_map(fn ($event) => $this->event($event['code'] ?? null, $event['occurredAt'] ?? null,
            $event['description'] ?? null, CourierAccount::PROVIDER_ALLEGRO_SHIPPING), $statuses);
    }

    private function event(mixed $status, mixed $date, mixed $description, string $provider): array
    {
        if (! is_string($status) || trim($status) === '' || strlen($status) > 100
            || ! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date) !== 1
            || ($description !== null && ! is_string($description))) {
            throw new DomainException('Nieprawidłowy status lub czas zdarzenia kurierskiego.');
        }
        try {
            $parsed = date_parse($date);
            if ($parsed['warning_count'] || $parsed['error_count']) {
                throw new DomainException('Nieprawidłowa data zdarzenia kurierskiego.');
            }
            $occurredAt = CarbonImmutable::parse($date)->utc();
        } catch (Throwable) {
            throw new DomainException('Nieprawidłowa data zdarzenia kurierskiego.');
        }
        $status = strtolower(trim($status));

        return ['provider_status' => $status, 'tracking_status' => $this->mapper->map($provider, $status),
            'occurred_at' => $occurredAt, 'description' => $description === null ? null : mb_substr($description, 0, 500)];
    }
}
