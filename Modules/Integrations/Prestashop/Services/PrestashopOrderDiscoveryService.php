<?php

namespace Modules\Integrations\Prestashop\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Jobs\ImportPrestashopOrderJob;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderImportAttempt;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Throwable;

class PrestashopOrderDiscoveryService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    private const CURSOR_OVERLAP_MINUTES = 2;

    private const STABILIZATION_DELAY_MINUTES = 1;

    private const STALE_PROCESSING_MINUTES = 15;

    public function __construct(private readonly PrestashopClient $client) {}

    /** @return array{discovered: int, queued: int} */
    public function discover(int $accountId): array
    {
        $account = PrestashopAccount::query()->find($accountId);

        if (
            ! $account?->is_active
            || ! $account->order_import_enabled
            || ! $account->hasCompleteCredentials()
        ) {
            throw new PrestashopApiException('Włącz automatyczny import i poprawnie skonfiguruj integrację PrestaShop.');
        }

        $now = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
        $upperBound = $now->subMinutes(self::STABILIZATION_DELAY_MINUTES);
        $startedAt = $this->resolveDate($account->order_import_started_at, $now);
        $cursor = $this->resolveDate($account->last_order_import_scan_at, $startedAt);

        if (! $account->order_import_started_at || ! $account->last_order_import_scan_at) {
            $account->forceFill([
                'order_import_started_at' => $startedAt,
                'last_order_import_scan_at' => $cursor,
            ])->save();
        }

        if ($upperBound->lte($cursor)) {
            $this->clearRunError($account);

            return [
                'discovered' => 0,
                'queued' => $this->dispatchPendingAttempts($account),
            ];
        }

        $from = $cursor->subMinutes(self::CURSOR_OVERLAP_MINUTES);

        if ($from->lt($startedAt)) {
            $from = $startedAt;
        }

        $rows = $this->createdOrderRows($account, $from, $upperBound);
        $rows = collect($rows)->keyBy('id')->values();
        $externalIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $links = $externalIds === []
            ? collect()
            : PrestashopOrderLink::query()
                ->where('prestashop_account_id', $account->getKey())
                ->whereIn('external_order_id', $externalIds)
                ->get()
                ->keyBy('external_order_id');
        $attemptIds = [];

        foreach ($rows as $row) {
            $link = $links->get($row['id']);
            $attempt = PrestashopOrderImportAttempt::query()->firstOrCreate(
                [
                    'prestashop_account_id' => $account->getKey(),
                    'external_order_id' => $row['id'],
                ],
                [
                    'external_reference' => $row['reference'],
                    'status' => PrestashopOrderImportAttempt::STATUS_PENDING,
                    'discovered_at' => now(),
                    'external_created_at' => $row['date_add'],
                ],
            );

            if ($link) {
                $attempt->forceFill([
                    'order_id' => $link->order_id,
                    'external_reference' => $link->external_reference,
                    'status' => PrestashopOrderImportAttempt::STATUS_IMPORTED,
                    'imported_at' => $link->imported_at ?? now(),
                    'last_error' => null,
                ])->save();

                continue;
            }

            if (
                $attempt->status === PrestashopOrderImportAttempt::STATUS_PROCESSING
                && $attempt->last_attempt_at?->lt(now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ) {
                $attempt->forceFill(['status' => PrestashopOrderImportAttempt::STATUS_PENDING])->save();
            }

            if ($attempt->status === PrestashopOrderImportAttempt::STATUS_PENDING) {
                $attemptIds[] = $attempt->getKey();
            }
        }

        $account->forceFill([
            'last_order_import_scan_at' => $upperBound,
            'last_order_import_error_at' => null,
            'last_order_import_error' => null,
        ])->save();

        return [
            'discovered' => $rows->count(),
            'queued' => $this->dispatchPendingAttempts($account, $attemptIds),
        ];
    }

    public function recordRunFailure(int $accountId, Throwable $exception): void
    {
        $message = trim($exception->getMessage());

        PrestashopAccount::query()
            ->whereKey($accountId)
            ->update([
                'last_order_import_error_at' => now(),
                'last_order_import_error' => mb_substr(
                    $message !== '' ? $message : 'Wystąpił nieoczekiwany błąd pobierania zamówień.',
                    0,
                    1000,
                ),
                'updated_at' => now(),
            ]);
    }

    /** @return array<int, array{id: int, reference: ?string, date_add: string}> */
    private function createdOrderRows(
        PrestashopAccount $account,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $pageRows = $this->client->ordersCreatedBetween(
                $account,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $page * self::PAGE_SIZE,
                self::PAGE_SIZE,
            );
            array_push($rows, ...$pageRows);

            if (count($pageRows) < self::PAGE_SIZE) {
                return $rows;
            }
        }

        throw new PrestashopApiException(
            'Zakres nowych zamówień PrestaShop jest zbyt duży dla jednego przebiegu importu.',
        );
    }

    private function clearRunError(PrestashopAccount $account): void
    {
        if ($account->last_order_import_error_at === null && $account->last_order_import_error === null) {
            return;
        }

        $account->forceFill([
            'last_order_import_error_at' => null,
            'last_order_import_error' => null,
        ])->save();
    }

    private function resolveDate(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(config('app.timezone'));
        }

        if (! is_string($value)) {
            throw new PrestashopApiException('Nieprawidłowa data kursora automatycznego importu PrestaShop.');
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'))
                ->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new PrestashopApiException('Nieprawidłowa data kursora automatycznego importu PrestaShop.');
        }
    }

    /** @param array<int, int> $attemptIds */
    private function dispatchPendingAttempts(PrestashopAccount $account, array $attemptIds = []): int
    {
        $attemptIds = array_values(array_unique(array_merge(
            $attemptIds,
            $account->orderImportAttempts()
                ->where('status', PrestashopOrderImportAttempt::STATUS_PENDING)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        )));

        foreach ($attemptIds as $attemptId) {
            ImportPrestashopOrderJob::dispatch($attemptId);
        }

        return count($attemptIds);
    }
}
