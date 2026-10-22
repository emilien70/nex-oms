<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\Order;
use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Integrations\Allegro\Enums\AllegroShipmentSyncMode;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\ExportAllegroShipmentJob;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroShipmentExport;
use Modules\Integrations\Allegro\Support\AllegroShipmentNumbers as Numbers;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\Shipment;
use Throwable;

class AllegroShipmentExportService
{
    public function __construct(private readonly AllegroClient $client, private readonly AllegroOrderSyncService $orders) {}

    public function observeNumbers(int $shipmentId, array $newPairs, array $aliases): void
    {
        DB::transaction(function () use ($shipmentId, $newPairs, $aliases): void {
            $shipment = Shipment::query()->find($shipmentId);
            $link = $shipment ? AllegroOrderLink::query()->with(['account', 'order'])->where('order_id', $shipment->order_id)->first() : null;
            if (! $link || ! $this->eligible($link, $shipment)) {
                return;
            }
            foreach ($aliases as $key => $pair) {
                $fingerprint = hash('sha256', implode('|', [$link->environment, $link->seller_user_id, $link->external_order_id, $shipmentId, $key]));
                $export = AllegroShipmentExport::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($export) {
                    if ($export->verification_only) {
                        $export->update(['aliases' => array_values($aliases)]);
                    }

                    continue;
                }
                if (! isset($newPairs[$key])) {
                    continue;
                }
                $export = AllegroShipmentExport::query()->firstOrCreate(['fingerprint' => $fingerprint], [
                    'allegro_account_id' => $link->account->id, 'allegro_order_link_id' => $link->id,
                    'order_id' => $shipment->order_id, 'shipment_id' => $shipmentId,
                    'environment' => $link->environment, 'seller_user_id' => $link->seller_user_id, 'external_order_id' => $link->external_order_id,
                    'carrier_id' => $pair['carrier_id'], 'waybill' => $pair['waybill'], 'aliases' => array_values($aliases),
                    'fingerprint' => $fingerprint, 'verification_only' => $shipment->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
                    'configuration_revision' => $link->account->shipment_sync_revision, 'state' => 'waiting', 'attempt_token' => (string) Str::uuid(),
                ]);
                if ($export->wasRecentlyCreated) {
                    $this->activate($export);
                }
            }
        }, 3);
    }

    public function statusChanged(int $orderId): void
    {
        AllegroShipmentExport::query()->where('order_id', $orderId)->where('state', 'waiting')->each(function ($export): void {
            DB::transaction(function () use ($export): void {
                $locked = AllegroShipmentExport::query()->lockForUpdate()->find($export->id);
                if ($locked?->state === 'waiting') {
                    $this->activate($locked);
                }
            }, 3);
        });
    }

    public function retry(int $exportId): bool
    {
        return DB::transaction(function () use ($exportId): bool {
            $export = AllegroShipmentExport::query()->lockForUpdate()->find($exportId);
            if (! $export || $export->state !== 'failed' || ! $this->validSource($export, false) || ! $this->gateOpen($export)) {
                return false;
            }
            $export->configuration_revision = $export->account->shipment_sync_revision;
            $this->dispatch($export);

            return true;
        }, 3);
    }

    public function recover(): void
    {
        AllegroShipmentExport::query()->where(fn ($query) => $query->where('state', 'waiting')
            ->orWhere(fn ($pending) => $pending->where('state', 'pending')->where('updated_at', '<=', now()->subMinutes(15))))
            ->orderBy('updated_at')->orderBy('id')->limit(100)->get()->each(function ($candidate): void {
                DB::transaction(function () use ($candidate): void {
                    $export = AllegroShipmentExport::query()->lockForUpdate()->find($candidate->id);
                    if (! $export || ! in_array($export->state, ['waiting', 'pending'], true)) {
                        return;
                    }
                    if ($export->state === 'waiting') {
                        $this->activate($export);
                        $export->touch();
                    } elseif ($export->updated_at->lte(now()->subMinutes(15))) {
                        $job = new ExportAllegroShipmentJob($export->id, $export->attempt_token);
                        if ($this->context($job)) {
                            $export->touch();
                            DB::afterCommit(fn () => ExportAllegroShipmentJob::dispatch($job->exportId, $job->token)->afterCommit());
                        }
                    }
                }, 3);
            });
    }

    public function sync(ExportAllegroShipmentJob $job): void
    {
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        $raw = $this->client->order($export->account, $export->external_order_id);
        if (! is_string($raw['id'] ?? null) || strtolower($raw['id']) !== strtolower($export->external_order_id)) {
            throw new AllegroApiException('Allegro zwróciło inne zamówienie. Numer nie został przekazany.', 422);
        }
        if (($raw['status'] ?? null) === 'CANCELLED') {
            $this->orders->cancel($export->link);
            $this->finishState($job, 'skipped');

            return;
        }
        if (($raw['status'] ?? null) !== 'READY_FOR_PROCESSING' || data_get($raw, 'fulfillment.provider.id') !== 'SELLER'
            || data_get($raw, 'fulfillment.status') === 'CANCELLED') {
            throw new AllegroApiException('Zamówienie Allegro nie jest gotowe do przekazania numeru przesyłki.', 422);
        }
        $items = $raw['lineItems'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new AllegroApiException('Brakuje pozycji źródłowego zamówienia Allegro.', 422);
        }
        $lineIds = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! Str::isUuid($item['id'])) {
                throw new AllegroApiException('Allegro zwróciło nieprawidłowe identyfikatory pozycji zamówienia.', 502);
            }
            $lineIds[] = $item['id'];
        }
        $lineIds = array_values(array_unique($lineIds));
        $response = $this->client->orderShipments($export->account, $export->external_order_id);
        if (! is_array($response['shipments'] ?? null) || ! array_is_list($response['shipments'])) {
            throw new AllegroApiException('Allegro zwróciło nieprawidłową listę przesyłek.', 502);
        }
        foreach ($response['shipments'] as $remote) {
            if (! is_array($remote) || ! is_string($remote['carrierId'] ?? null) || trim($remote['carrierId']) === ''
                || ! is_string($remote['waybill'] ?? null) || trim($remote['waybill']) === '') {
                throw new AllegroApiException('Nie można bezpiecznie sprawdzić istniejących numerów przesyłek w Allegro.', 502);
            }
        }
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        $pairs = $export->verification_only ? Numbers::normalize($export->aliases ?? [])
            : Numbers::normalize([['carrier_id' => $export->carrier_id, 'waybill' => $export->waybill]]);
        $match = collect($response['shipments'])->first(function ($remote) use ($pairs): bool {
            return is_array($remote) && is_string($remote['carrierId'] ?? null) && is_string($remote['waybill'] ?? null)
                && isset($pairs[strtoupper($remote['carrierId']).'|'.$remote['waybill']]);
        });
        if ($match) {
            if (array_diff($lineIds, array_column((array) ($match['lineItems'] ?? []), 'id')) !== []) {
                throw new AllegroApiException('Numer jest w Allegro, ale nie potwierdzono przypisania do wszystkich pozycji zamówienia.', 422);
            }
            $this->confirm($job, (string) ($match['id'] ?? ''), true, ['carrier_id' => strtoupper($match['carrierId']), 'waybill' => $match['waybill']]);

            return;
        }
        if ($export->verification_only) {
            throw new AllegroApiException('Allegro nie potwierdziło jeszcze automatycznego przypisania tej przesyłki. Spróbuj ponownie.', 409);
        }
        $carriers = $this->client->carriers($export->account);
        if (! is_array($carriers['carriers'] ?? null)) {
            throw new AllegroApiException('Allegro zwróciło nieprawidłową listę przewoźników.', 502);
        }
        if (! in_array($export->carrier_id, array_column($carriers['carriers'], 'id'), true)) {
            throw new AllegroApiException('Przewoźnik tej przesyłki nie jest obsługiwany przez Allegro.', 422);
        }
        $export = $this->context($job);
        if (! $export) {
            return;
        }
        $added = $this->client->addOrderShipment($export->account, $export->external_order_id, $export->carrier_id, $export->waybill, $lineIds);
        if (! is_string($added['id'] ?? null) || $added['id'] === '' || ($added['carrierId'] ?? null) !== $export->carrier_id || ($added['waybill'] ?? null) !== $export->waybill) {
            throw new AllegroApiException('Nie otrzymano jednoznacznego potwierdzenia zapisu numeru. Kolejna próba najpierw sprawdzi Allegro.', 502);
        }
        $this->confirm($job, $added['id'], false);
    }

    public function failure(ExportAllegroShipmentJob $job, Throwable $exception): void
    {
        if (! $this->context($job)) {
            return;
        }
        DB::transaction(function () use ($job, $exception): void {
            $export = AllegroShipmentExport::query()->lockForUpdate()->find($job->exportId);
            if (! $export || $export->attempt_token !== $job->token || $export->state !== 'pending') {
                return;
            }
            $message = $exception instanceof AllegroApiException ? mb_substr($exception->getMessage(), 0, 900)
                : 'Nie udało się przekazać numeru przesyłki do Allegro. Spróbuj ponownie.';
            $export->update(['state' => 'failed', 'error' => $message]);
            $this->event($export, 'allegro_shipment_export_failed', 'Błąd przekazania numeru przesyłki do Allegro', $message);
        }, 3);
    }

    private function context(ExportAllegroShipmentJob $job): ?AllegroShipmentExport
    {
        $export = AllegroShipmentExport::query()->find($job->exportId);
        if (! $export || $export->attempt_token !== $job->token || $export->state !== 'pending') {
            return null;
        }
        if (! $this->validSource($export)) {
            $this->finishState($job, 'skipped');

            return null;
        }
        if (! $this->gateOpen($export)) {
            $this->finishState($job, 'waiting');

            return null;
        }

        return $export;
    }

    private function eligible(AllegroOrderLink $link, Shipment $shipment): bool
    {
        $account = $link->account;
        $order = $link->order;

        return $account && $order && ! $order->trashed() && $order->source === 'allegro' && $order->status !== Order::STATUS_CANCELLED
            && $link->isReady() && $link->external_status === 'READY_FOR_PROCESSING' && $link->fulfillment_status !== 'CANCELLED'
            && $account->isOperational() && $account->shipment_sync_mode !== AllegroShipmentSyncMode::Disabled
            && $account->shipment_sync_identity === $account->orderImportIdentity()
            && $link->environment === $account->environment && $link->seller_user_id === $account->allegro_user_id
            && $shipment->order_id === $order->id && ! $shipment->cancelled_at
            && ! in_array($shipment->status, ['cancelled', 'cancelled_locally', 'canceled', 'creation_failed', 'creation_unknown'], true)
            && ($shipment->provider !== CourierAccount::PROVIDER_ALLEGRO_SHIPPING || $shipment->courierAccount?->allegro_account_id === $account->id);
    }

    private function validSource(AllegroShipmentExport $export, bool $checkRevision = true): bool
    {
        $link = $export->link;
        $shipment = $export->shipment;

        return $link && $shipment && $this->eligible($link, $shipment) && $export->account
            && $link->allegro_account_id === $export->allegro_account_id && $export->order_id === $link->order_id
            && $export->environment === $link->environment && $export->seller_user_id === $link->seller_user_id && $export->external_order_id === $link->external_order_id
            && (! $checkRevision || $export->configuration_revision === $export->account->shipment_sync_revision)
            && $export->verification_only === ($shipment->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            && isset(Numbers::current($shipment)[$export->carrier_id.'|'.$export->waybill]);
    }

    private function gateOpen(AllegroShipmentExport $export): bool
    {
        if ($export->verification_only || $export->account->shipment_sync_mode === AllegroShipmentSyncMode::Immediate) {
            return true;
        }
        $status = OrderStatusSetting::query()->find($export->account->shipment_sync_status_setting_id);

        return $status && $status->status === $export->order?->status;
    }

    private function activate(AllegroShipmentExport $export): void
    {
        if (! $this->validSource($export)) {
            $export->update(['state' => 'skipped']);
        } elseif ($this->gateOpen($export)) {
            $this->dispatch($export);
        }
    }

    private function dispatch(AllegroShipmentExport $export): void
    {
        $export->forceFill(['attempt_token' => (string) Str::uuid(), 'state' => 'pending', 'error' => null])->save();
        $id = $export->id;
        $token = $export->attempt_token;
        DB::afterCommit(fn () => ExportAllegroShipmentJob::dispatch($id, $token)->afterCommit());
    }

    private function finishState(ExportAllegroShipmentJob $job, string $state): void
    {
        AllegroShipmentExport::query()->whereKey($job->exportId)->where('attempt_token', $job->token)->where('state', 'pending')
            ->update(['state' => $state, 'error' => null]);
    }

    private function confirm(ExportAllegroShipmentJob $job, string $remoteId, bool $alreadyPresent, ?array $matchedNumber = null): void
    {
        DB::transaction(function () use ($job, $remoteId, $alreadyPresent, $matchedNumber): void {
            $export = AllegroShipmentExport::query()->lockForUpdate()->find($job->exportId);
            // Preserve the acknowledgement even if configuration changed while HTTP was in flight.
            if (! $export || $export->attempt_token !== $job->token || $export->state === 'synced') {
                return;
            }
            $export->update(['state' => 'synced', 'error' => null, 'remote_id' => $remoteId ?: null, 'confirmed_at' => now()]);
            $confirmed = $matchedNumber ?? ['carrier_id' => $export->carrier_id, 'waybill' => $export->waybill];
            $description = ($alreadyPresent ? 'Potwierdzono przypisanie: ' : 'Przekazano numer: ').$confirmed['waybill'].' ('.$confirmed['carrier_id'].').';
            if ($export->verification_only && $confirmed['waybill'] !== $export->waybill) {
                $description = 'Potwierdzono paczkę '.$export->waybill.' ('.$export->carrier_id.') przez numer '.$confirmed['waybill'].' ('.$confirmed['carrier_id'].').';
            }
            $this->event($export, 'allegro_shipment_exported', $export->verification_only ? 'Przesyłka potwierdzona w Allegro' : 'Numer przesyłki potwierdzony w Allegro',
                $description, ['confirmed_carrier_id' => $confirmed['carrier_id'], 'confirmed_waybill' => $confirmed['waybill']]);
        }, 3);
    }

    private function event(AllegroShipmentExport $export, string $type, string $title, string $description, array $details = []): void
    {
        $export->order?->events()->create([
            'event_type' => $type, 'title' => $title, 'description' => $description,
            'payload' => ['allegro_shipment_export_id' => $export->id, 'waybill' => $export->waybill, 'carrier_id' => $export->carrier_id] + $details,
        ]);
    }
}
