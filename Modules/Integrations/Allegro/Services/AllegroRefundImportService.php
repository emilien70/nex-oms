<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroOrderLink;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;

class AllegroRefundImportService
{
    public function store(AllegroAccount $account, array $data): AllegroPaymentRefund
    {
        return DB::transaction(function () use ($account, $data): AllegroPaymentRefund {
            $identity = ['environment' => $account->environment, 'seller_user_id' => $account->allegro_user_id, 'external_refund_id' => $data['external_refund_id']];
            $record = AllegroPaymentRefund::query()->where($identity)->lockForUpdate()->first();
            if ($record && ($record->external_payment_id !== $data['external_payment_id']
                || ($record->external_order_id && $data['external_order_id'] && $record->external_order_id !== $data['external_order_id'])
                || $record->currency !== $data['currency'] || ! $record->requested_at->equalTo($data['requested_at']))) {
                throw new AllegroApiException('Tożsamość refundacji Allegro zmieniła się. Zachowano poprzednie dane.', 422);
            }
            $externalOrder = $data['external_order_id'] ?? $record?->external_order_id;
            $link = $externalOrder ? AllegroOrderLink::query()->where('environment', $account->environment)
                ->where('seller_user_id', $account->allegro_user_id)->where('external_order_id', $externalOrder)->first() : null;
            $changed = ! $record || $record->source_hash !== $data['source_hash'];
            $record ??= new AllegroPaymentRefund($identity);
            $record->fill($data + ['allegro_account_id' => $account->id, 'account_name' => $account->name]);
            $record->requested_at = $data['requested_at']->setTimezone(config('app.timezone'));
            $record->external_order_id = $externalOrder;
            $record->order_id = $link?->order_id;
            $record->synced_at = now();
            $record->next_sync_at = now()->addMinutes(5);
            $record->sync_error = null;
            if ($changed) {
                $record->lock_version = $record->exists ? $record->lock_version + 1 : 0;
            }
            $record->save();
            if ($changed) {
                $record->events()->create(['observed_at' => now(), 'data' => [
                    'status' => $record->status, 'total_value' => $record->total_value, 'currency' => $record->currency,
                    'products_value' => $record->products_value, 'delivery_value' => $record->delivery_value,
                    'other_value' => $record->other_value,
                ]]);
            }

            return $record;
        }, 3);
    }
}
