<?php

namespace App\Services;

use App\Models\OrderReturn;
use App\Models\OrderReturnParcel;
use App\Models\ReturnStatusSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderReturnParcelService
{
    public const CARRIERS = ['INPOST' => 'InPost', 'ALLEGRO' => 'Allegro', 'DPD' => 'DPD', 'DHL' => 'DHL', 'ORLEN' => 'ORLEN Paczka', 'UPS' => 'UPS', 'OTHER' => 'Inny'];

    public function syncImported(OrderReturn $record, array $parcels): void
    {
        $existing = $record->parcels()->where('is_manual', false)->get();
        $position = (int) $record->parcels()->max('position') + 1;
        $kept = [];
        $seen = [];
        foreach ($parcels as $data) {
            $fields = Arr::only($data, ['carrier_id', 'waybill', 'transporting_carrier_id', 'transporting_waybill', 'submitted_at']);
            $candidate = new OrderReturnParcel($fields + ['order_return_id' => $record->id]);
            $identity = $candidate->identity();
            if ((filled($candidate->waybill) || filled($candidate->transporting_waybill)) && isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $parcel = $existing->first(fn ($row) => ! in_array($row->id, $kept, true) && hash_equals($identity, $row->identity()));
            if (! $parcel) {
                $parcel = $record->parcels()->create($fields + ['position' => $position++, 'is_manual' => false]);
            } else {
                $parcel->fill($fields)->save();
            }
            $kept[] = $parcel->id;
        }
        $record->parcels()->where('is_manual', false)->whereNotIn('id', $kept)->delete();
    }

    public function saveManual(OrderReturn $record, ?OrderReturnParcel $parcel, array $data, int $expectedVersion): OrderReturnParcel
    {
        return DB::transaction(function () use ($record, $parcel, $data, $expectedVersion): OrderReturnParcel {
            ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $record = OrderReturn::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['parcel' => 'Zwrot zmienił się. Odśwież stronę.']);
            }
            if ($parcel) {
                $parcel = $record->parcels()->where('is_manual', true)->lockForUpdate()->findOrFail($parcel->id);
            }
            $fields = ['carrier_id' => $data['carrier_id'], 'waybill' => trim($data['waybill']),
                'transporting_carrier_id' => null, 'transporting_waybill' => null];
            $identity = (new OrderReturnParcel($fields + ['order_return_id' => $record->id]))->identity();
            if ($record->parcels()->get()->contains(fn ($row) => $row->id !== $parcel?->id && hash_equals($identity, $row->identity()))) {
                throw ValidationException::withMessages(['waybill' => 'Ta paczka jest już przypisana do zwrotu.']);
            }
            $parcel ??= new OrderReturnParcel(['order_return_id' => $record->id, 'is_manual' => true,
                'position' => (int) $record->parcels()->max('position') + 1, 'submitted_at' => now()]);
            if (! hash_equals($identity, $parcel->identity())) {
                $parcel->fill(array_fill_keys(['tracking_identity', 'tracking_status', 'provider_status', 'tracking_status_at', 'tracking_started_at', 'tracking_checked_at', 'tracking_next_sync_at', 'tracking_error'], null));
            }
            $parcel->fill($fields)->save();
            $record->increment('lock_version');

            return $parcel;
        }, 3);
    }
}
