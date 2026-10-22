<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderReturn;
use App\Models\ReturnStatusSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;

class AllegroReturnImportService
{
    public function store(AllegroAccount $account, array $data): OrderReturn
    {
        return DB::transaction(function () use ($account, $data): OrderReturn {
            $record = OrderReturn::query()->firstOrNew([
                'environment' => $account->environment, 'seller_user_id' => $account->allegro_user_id,
                'external_return_id' => $data['external_return_id'],
            ]);
            if ($record->exists) {
                $record = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
                if ($record->external_order_id !== $data['external_order_id']) {
                    throw new \RuntimeException('Zmieniono tożsamość zamówienia zwrotu Allegro.');
                }
            }
            $link = AllegroOrderLink::query()->with(['order.items.product'])->where('environment', $account->environment)
                ->where('seller_user_id', $account->allegro_user_id)->where('external_order_id', $data['external_order_id'])->first();
            $order = $link?->order;
            if ($order?->source !== 'allegro') {
                $order = null;
            }
            $created = ! $record->exists;
            $previousStatus = $record->status;
            $changed = $record->source_hash !== $data['source_hash'];
            $record->fill(Arr::except($data, ['items', 'parcels']));
            $record->fill([
                'source' => 'allegro', 'creation_method' => 'allegro_import',
                'allegro_account_id' => $account->id, 'account_name' => $account->name, 'order_id' => $order?->id,
                'buyer_name' => $order?->shipping_name ?: $record->buyer_name,
                'buyer_phone' => ($order?->customer_phone ?: $order?->shipping_phone) ?: $record->buyer_phone,
                'synced_at' => now(), 'next_sync_at' => now()->addMinutes(5), 'sync_error' => null,
                'status_observed_at' => $created || $previousStatus !== $data['status'] ? now() : $record->status_observed_at,
            ]);
            if ($created) {
                $record->local_status_id = ReturnStatusSetting::initial()->id;
                $record->local_status_changed_at = now();
            }
            if ($order && ! $record->order_snapshot) {
                $record->order_snapshot = (array) $order->shippingAddressData();
            }
            $record->save();
            $offerCounts = collect($data['items'])->countBy('offer_id');
            foreach ($data['items'] as $item) {
                $candidates = $order?->items->filter(fn ($candidate) => (string) $candidate->offer_id === $item['offer_id']
                    && $candidate->currency === $item['currency'] && $candidate->unit_price_gross === $item['unit_price']
                    && $candidate->quantity >= $item['quantity']);
                $match = $offerCounts[$item['offer_id']] === 1 && $candidates?->count() === 1 ? $candidates->first() : null;
                $record->items()->updateOrCreate(['position' => $item['position']], $item + [
                    'order_item_id' => $match?->id, 'product_id' => $match?->product?->id,
                ]);
            }
            $record->items()->where('position', '>=', count($data['items']))->delete();
            foreach ($data['parcels'] as $parcel) {
                $record->parcels()->updateOrCreate(['position' => $parcel['position']], $parcel);
            }
            $record->parcels()->where('position', '>=', count($data['parcels']))->delete();
            if ($created || $changed) {
                $record->events()->create([
                    'type' => $created ? 'imported' : ($previousStatus !== $record->status ? 'status_changed' : 'updated'),
                    'previous_status' => $previousStatus, 'status' => $record->status, 'observed_at' => now(),
                ]);
            }

            return $record;
        }, 3);
    }
}
