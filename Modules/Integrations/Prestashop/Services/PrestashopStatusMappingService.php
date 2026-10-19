<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Models\OrderStatusSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopStatusMappingService
{
    public function __construct(private readonly PrestashopAccountService $accountService) {}

    /**
     * @param  array<int|string, mixed>  $inbound
     * @param  array<int|string, mixed>  $outbound
     */
    public function update(
        array $inbound,
        array $outbound,
        bool $notifyCustomer,
        bool $statusSyncEnabled,
        int $statusSyncIntervalMinutes,
    ): void {
        OrderStatusSetting::syncDefaults();
        $this->accountService->get();

        DB::transaction(function () use (
            $inbound,
            $outbound,
            $notifyCustomer,
            $statusSyncEnabled,
            $statusSyncIntervalMinutes,
        ): void {
            $account = PrestashopAccount::query()
                ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->last_test_status !== PrestashopConnectionTestStatus::Success) {
                throw ValidationException::withMessages([
                    'status_mappings' => 'Przed zapisaniem mapowań wykonaj poprawny test połączenia z PrestaShop.',
                ]);
            }

            if ($statusSyncEnabled && ! $account->is_active) {
                throw ValidationException::withMessages([
                    'status_sync_enabled' => 'Włącz integrację PrestaShop przed uruchomieniem automatycznej synchronizacji.',
                ]);
            }

            $states = $account->orderStates()->get()->keyBy(
                fn ($state): string => (string) $state->external_id,
            );
            $statusSettings = OrderStatusSetting::query()
                ->lockForUpdate()
                ->get()
                ->keyBy('status');
            $now = now();
            $inboundRows = [];
            $outboundRows = [];

            foreach ($inbound as $externalId => $statusCode) {
                $externalId = (string) $externalId;
                $statusCode = is_string($statusCode) ? trim($statusCode) : '';

                if (! ctype_digit($externalId) || ! $states->has($externalId)) {
                    throw ValidationException::withMessages([
                        'inbound' => 'Lista statusów PrestaShop jest nieaktualna. Wykonaj ponownie test połączenia.',
                    ]);
                }

                if ($statusCode === '') {
                    continue;
                }

                $statusSetting = $statusSettings->get($statusCode);

                if (! $statusSetting) {
                    throw ValidationException::withMessages([
                        "inbound.{$externalId}" => 'Wybrany status OMS nie jest już dostępny.',
                    ]);
                }

                $inboundRows[] = [
                    'prestashop_account_id' => $account->getKey(),
                    'external_order_state_id' => (int) $externalId,
                    'order_status_setting_id' => $statusSetting->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($outbound as $statusCode => $externalId) {
                $statusCode = (string) $statusCode;
                $externalId = is_scalar($externalId) ? trim((string) $externalId) : '';
                $statusSetting = $statusSettings->get($statusCode);

                if (! $statusSetting) {
                    throw ValidationException::withMessages([
                        'outbound' => 'Lista statusów OMS jest nieaktualna. Odśwież stronę i spróbuj ponownie.',
                    ]);
                }

                if ($externalId === '') {
                    continue;
                }

                if (! ctype_digit($externalId) || ! $states->has($externalId)) {
                    throw ValidationException::withMessages([
                        "outbound.{$statusCode}" => 'Wybrany status PrestaShop nie jest już dostępny.',
                    ]);
                }

                $state = $states->get($externalId);

                if (! $state->is_available || $state->deleted) {
                    throw ValidationException::withMessages([
                        "outbound.{$statusCode}" => 'Nie można przekazywać zamówień do niedostępnego statusu PrestaShop.',
                    ]);
                }

                $outboundRows[] = [
                    'prestashop_account_id' => $account->getKey(),
                    'order_status_setting_id' => $statusSetting->getKey(),
                    'external_order_state_id' => (int) $externalId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $account->inboundStatusMappings()->delete();
            $account->outboundStatusMappings()->delete();

            if ($inboundRows !== []) {
                $account->inboundStatusMappings()->insert($inboundRows);
            }

            if ($outboundRows !== []) {
                $account->outboundStatusMappings()->insert($outboundRows);
            }

            $account->forceFill([
                'notify_customer_on_status_change' => $notifyCustomer,
                'status_sync_enabled' => $statusSyncEnabled,
                'status_sync_interval_minutes' => $statusSyncIntervalMinutes,
                'last_status_sync_at' => $statusSyncEnabled && ! $account->status_sync_enabled
                    ? null
                    : $account->last_status_sync_at,
                'last_status_sync_error_at' => $statusSyncEnabled ? null : $account->last_status_sync_error_at,
                'last_status_sync_error' => $statusSyncEnabled ? null : $account->last_status_sync_error,
            ])->save();
        }, 3);
    }
}
