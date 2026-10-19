<?php

namespace Modules\Integrations\Prestashop\Services;

use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use RuntimeException;
use Throwable;

class PrestashopInboundStatusSyncService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    private const CURSOR_OVERLAP_MINUTES = 2;

    private const PENDING_OUTBOUND_TTL_MINUTES = 15;

    public function __construct(
        private readonly PrestashopClient $client,
        private readonly OrderStatusService $orderStatuses,
    ) {}

    /**
     * @return array{checked: int, changed: int, observed: int, pending: int}
     */
    public function sync(int $accountId, bool $fullScan = false): array
    {
        $account = PrestashopAccount::query()->find($accountId);

        if (! $account?->is_active || ! $account->hasCompleteCredentials()) {
            throw new PrestashopApiException('Włącz i poprawnie skonfiguruj integrację PrestaShop.');
        }

        $startedAt = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
        $rows = $fullScan || ! $account->last_status_sync_at
            ? $this->fullStatusRows($account)
            : $this->changedStatusRows($account, $startedAt);
        $rows = collect($rows)
            ->keyBy('id')
            ->values()
            ->all();
        $stateNames = $account->orderStates()
            ->pluck('name', 'external_id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name]);
        $mappings = $account->inboundStatusMappings()
            ->with('statusSetting')
            ->get()
            ->keyBy(fn ($mapping): string => (string) $mapping->external_order_state_id);
        $result = [
            'checked' => count($rows),
            'changed' => 0,
            'observed' => 0,
            'pending' => 0,
        ];
        $failedOrderIds = [];

        foreach ($rows as $row) {
            try {
                $outcome = $this->synchronizeRow($account, $row, $stateNames, $mappings);
                $result[$outcome]++;
            } catch (Throwable) {
                $failedOrderIds[] = $row['id'];
            }
        }

        if ($failedOrderIds !== []) {
            throw new RuntimeException(sprintf(
                'Nie udało się zsynchronizować statusów %d zamówień PrestaShop.',
                count($failedOrderIds),
            ));
        }

        $account->forceFill([
            'last_status_sync_at' => $startedAt,
            'last_status_sync_error_at' => null,
            'last_status_sync_error' => null,
        ])->save();

        return $result;
    }

    public function recordRunFailure(int $accountId, Throwable $exception): void
    {
        $message = trim($exception->getMessage());

        PrestashopAccount::query()
            ->whereKey($accountId)
            ->update([
                'last_status_sync_error_at' => now(),
                'last_status_sync_error' => mb_substr(
                    $message !== '' ? $message : 'Wystąpił nieoczekiwany błąd synchronizacji statusów.',
                    0,
                    1000,
                ),
                'updated_at' => now(),
            ]);
    }

    public function applyStoredOrderState(int $accountId, int $externalOrderId): string
    {
        $account = PrestashopAccount::query()->find($accountId);
        $link = PrestashopOrderLink::query()
            ->where('prestashop_account_id', $accountId)
            ->where('external_order_id', $externalOrderId)
            ->first();

        if (! $account || ! $link || ! $link->external_order_state_id) {
            return 'observed';
        }

        $stateNames = $account->orderStates()
            ->pluck('name', 'external_id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name]);
        $mappings = $account->inboundStatusMappings()
            ->with('statusSetting')
            ->get()
            ->keyBy(fn ($mapping): string => (string) $mapping->external_order_state_id);

        return $this->synchronizeRow(
            $account,
            [
                'id' => $externalOrderId,
                'current_state' => $link->external_order_state_id,
                'date_upd' => $link->external_updated_at?->format('Y-m-d H:i:s'),
            ],
            $stateNames,
            $mappings,
            'prestashop_order_import',
        );
    }

    /** @return array<int, array{id: int, current_state: ?int, date_upd: ?string}> */
    private function fullStatusRows(PrestashopAccount $account): array
    {
        $rows = [];

        $account->orderLinks()
            ->orderBy('id')
            ->pluck('external_order_id')
            ->chunk(self::PAGE_SIZE)
            ->each(function (Collection $ids) use ($account, &$rows): void {
                array_push(
                    $rows,
                    ...$this->client->orderStatusesByIds($account, $ids->map(fn ($id): int => (int) $id)->all()),
                );
            });

        return $rows;
    }

    /** @return array<int, array{id: int, current_state: ?int, date_upd: ?string}> */
    private function changedStatusRows(PrestashopAccount $account, CarbonImmutable $startedAt): array
    {
        $from = CarbonImmutable::instance($account->last_status_sync_at)
            ->setTimezone(config('app.timezone'))
            ->subMinutes(self::CURSOR_OVERLAP_MINUTES)
            ->format('Y-m-d H:i:s');
        $to = $startedAt->addSecond()->format('Y-m-d H:i:s');
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $pageRows = $this->client->orderStatusesUpdatedBetween(
                $account,
                $from,
                $to,
                $page * self::PAGE_SIZE,
                self::PAGE_SIZE,
            );
            array_push($rows, ...$pageRows);

            if (count($pageRows) < self::PAGE_SIZE) {
                return $rows;
            }
        }

        throw new PrestashopApiException(
            'Zakres zmian statusów PrestaShop jest zbyt duży dla jednego przebiegu synchronizacji.',
        );
    }

    /**
     * @param  array{id: int, current_state: ?int, date_upd: ?string}  $row
     * @param  Collection<string, string>  $stateNames
     * @param  Collection<string, mixed>  $mappings
     */
    private function synchronizeRow(
        PrestashopAccount $account,
        array $row,
        Collection $stateNames,
        Collection $mappings,
        string $source = 'prestashop_status_sync',
    ): string {
        return DB::transaction(function () use ($account, $row, $stateNames, $mappings, $source): string {
            $link = PrestashopOrderLink::query()
                ->where('prestashop_account_id', $account->getKey())
                ->where('external_order_id', $row['id'])
                ->lockForUpdate()
                ->first();

            if (! $link) {
                return 'observed';
            }

            if ($this->hasActivePendingExport($link)) {
                return 'pending';
            }

            if ($link->pending_outbound_event_id !== null) {
                $this->clearPendingExport($link);
            }

            $remoteUpdatedAt = $this->remoteDate($row['date_upd']);

            if (
                $remoteUpdatedAt
                && $link->external_updated_at
                && $remoteUpdatedAt->lt($link->external_updated_at)
            ) {
                return 'observed';
            }

            $previousStateId = $link->external_order_state_id;
            $stateId = $row['current_state'];

            if ($stateId === null) {
                $link->forceFill([
                    'external_order_state_id' => null,
                    'external_order_state_name' => null,
                    'external_updated_at' => $remoteUpdatedAt ?? $link->external_updated_at,
                ])->save();

                return 'observed';
            }

            $stateName = $stateNames->get((string) $stateId)
                ?? ($previousStateId === $stateId ? $link->external_order_state_name : null)
                ?? "Status ID {$stateId}";
            $link->forceFill([
                'external_order_state_id' => $stateId,
                'external_order_state_name' => $stateName,
                'external_updated_at' => $remoteUpdatedAt ?? $link->external_updated_at,
            ])->save();

            $order = $link->order;
            $mapping = $mappings->get((string) $stateId);
            $targetStatus = $mapping?->statusSetting?->status;

            if (! $order || $order->trashed() || ! $targetStatus || $order->status === $targetStatus) {
                return 'observed';
            }

            $oldStatus = $order->status;
            $changed = $this->orderStatuses->change(
                $order,
                $targetStatus,
                $source,
            );

            if (! $changed) {
                return 'observed';
            }

            $order->events()->create([
                'event_type' => 'prestashop_status_imported',
                'title' => 'Zmieniono status na podstawie PrestaShop',
                'description' => "Status PrestaShop: {$stateName}.",
                'payload' => [
                    'prestashop_account_id' => $account->getKey(),
                    'external_order_id' => $row['id'],
                    'previous_external_order_state_id' => $previousStateId,
                    'external_order_state_id' => $stateId,
                    'external_order_state_name' => $stateName,
                    'old_oms_status' => $oldStatus,
                    'new_oms_status' => $targetStatus,
                    'external_updated_at' => $remoteUpdatedAt?->toIso8601String(),
                ],
            ]);

            return 'changed';
        }, 3);
    }

    private function hasActivePendingExport(PrestashopOrderLink $link): bool
    {
        return $link->pending_outbound_event_id !== null
            && $link->pending_outbound_since !== null
            && $link->pending_outbound_since->gt(
                now()->subMinutes(self::PENDING_OUTBOUND_TTL_MINUTES),
            );
    }

    private function clearPendingExport(PrestashopOrderLink $link): void
    {
        $link->forceFill([
            'pending_outbound_state_id' => null,
            'pending_outbound_event_id' => null,
            'pending_outbound_since' => null,
        ])->save();
    }

    private function remoteDate(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $value,
                config('app.timezone'),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
