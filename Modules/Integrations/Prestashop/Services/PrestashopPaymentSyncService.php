<?php

namespace Modules\Integrations\Prestashop\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Modules\Integrations\Prestashop\Models\PrestashopOrderPayment;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;
use Throwable;

class PrestashopPaymentSyncService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    private const CURSOR_OVERLAP_MINUTES = 2;

    public function __construct(
        private readonly PrestashopClient $client,
        private readonly PrestashopOrderPreviewService $preview,
        private readonly PrestashopPaymentAccountingService $accounting,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceFinancialValueValidator $financial,
    ) {}

    /** @return array{checked: int, discovered: int, accounted: int, mirrored: int, review: int, observed: int} */
    public function sync(int $accountId, bool $fullScan = false): array
    {
        $account = PrestashopAccount::query()->find($accountId);

        if (! $account?->is_active || ! $account->hasCompleteCredentials()) {
            throw new PrestashopApiException('Włącz i poprawnie skonfiguruj integrację PrestaShop.');
        }

        $startedAt = CarbonImmutable::now(config('app.timezone'))->startOfSecond();
        $rows = $fullScan || ! $account->last_payment_sync_at
            ? $this->fullPaymentRows($account)
            : $this->changedPaymentRows($account, $startedAt);
        $rows = collect($rows)->keyBy('id')->values()->all();
        $result = [
            'checked' => count($rows),
            'discovered' => 0,
            'accounted' => 0,
            'mirrored' => 0,
            'review' => 0,
            'observed' => 0,
        ];
        $apiFailure = null;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $outcomes = $this->synchronizeRow($account, $row);

                foreach ($outcomes as $outcome => $count) {
                    $result[$outcome] += $count;
                }
            } catch (PrestashopApiException $exception) {
                $apiFailure ??= $exception;
            } catch (Throwable) {
                $failed++;
            }
        }

        if ($apiFailure) {
            throw $apiFailure;
        }

        if ($failed > 0) {
            throw new PrestashopImportException(sprintf(
                'Nie udało się bezpiecznie zsynchronizować płatności %d zamówień PrestaShop.',
                $failed,
            ));
        }

        $account->forceFill([
            'last_payment_sync_at' => $startedAt,
            'last_payment_sync_error_at' => null,
            'last_payment_sync_error' => null,
        ])->save();

        return $result;
    }

    public function recordRunFailure(int $accountId, Throwable $exception): void
    {
        $message = trim($exception->getMessage());

        PrestashopAccount::query()
            ->whereKey($accountId)
            ->update([
                'last_payment_sync_error_at' => now(),
                'last_payment_sync_error' => mb_substr(
                    $message !== '' ? $message : 'Wystąpił nieoczekiwany błąd synchronizacji płatności.',
                    0,
                    1000,
                ),
                'updated_at' => now(),
            ]);
    }

    /** @return array<int, array{id: int, total_paid_real: string, date_upd: ?string}> */
    private function fullPaymentRows(PrestashopAccount $account): array
    {
        $rows = [];

        $account->orderLinks()
            ->orderBy('id')
            ->pluck('external_order_id')
            ->chunk(self::PAGE_SIZE)
            ->each(function (Collection $ids) use ($account, &$rows): void {
                array_push(
                    $rows,
                    ...$this->client->orderPaymentSummariesByIds(
                        $account,
                        $ids->map(fn ($id): int => (int) $id)->all(),
                    ),
                );
            });

        return $rows;
    }

    /** @return array<int, array{id: int, total_paid_real: string, date_upd: ?string}> */
    private function changedPaymentRows(PrestashopAccount $account, CarbonImmutable $startedAt): array
    {
        $from = CarbonImmutable::instance($account->last_payment_sync_at)
            ->setTimezone(config('app.timezone'))
            ->subMinutes(self::CURSOR_OVERLAP_MINUTES)
            ->format('Y-m-d H:i:s');
        $to = $startedAt->addSecond()->format('Y-m-d H:i:s');
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $pageRows = $this->client->orderPaymentSummariesUpdatedBetween(
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
            'Zakres zmian płatności PrestaShop jest zbyt duży dla jednego przebiegu synchronizacji.',
        );
    }

    /**
     * @param  array{id: int, total_paid_real: string, date_upd: ?string}  $row
     * @return array{discovered: int, accounted: int, mirrored: int, review: int, observed: int}
     */
    private function synchronizeRow(PrestashopAccount $account, array $row): array
    {
        $link = PrestashopOrderLink::query()
            ->with('order')
            ->where('prestashop_account_id', $account->getKey())
            ->where('external_order_id', $row['id'])
            ->first();

        if (! $link?->order || $link->order->trashed()) {
            return $this->emptyOutcome(observed: 1);
        }

        $remotePaid = $this->money($row['total_paid_real']);
        $payments = $this->preview->payments(
            $account,
            $link->external_reference,
            strtoupper((string) $link->order->currency),
        );
        $this->assertPaymentsMatch($payments, $remotePaid, (string) $link->order->currency);

        $pendingIds = DB::transaction(function () use ($link, $payments): array {
            $managedLink = PrestashopOrderLink::query()
                ->whereKey($link->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $known = $managedLink->payments()
                ->get()
                ->keyBy('external_payment_id');
            $remoteIds = collect($payments)->pluck('external_payment_id')->map(fn ($id): int => (int) $id);

            if ($known->keys()->diff($remoteIds)->isNotEmpty()) {
                throw new PrestashopImportException(
                    'PrestaShop usunął lub wycofał wcześniej zapisaną płatność. Zwroty nie są obsługiwane w tym etapie.',
                );
            }

            $pendingIds = [];

            foreach ($payments as $paymentData) {
                $existing = $known->get($paymentData['external_payment_id']);

                if ($existing) {
                    $this->assertPaymentUnchanged($existing, $paymentData);

                    if ($existing->accounting_status === PrestashopOrderPayment::STATUS_PENDING) {
                        $pendingIds[] = $existing->getKey();
                    }

                    continue;
                }

                $payment = $managedLink->payments()->create($paymentData + [
                    'accounting_status' => PrestashopOrderPayment::STATUS_PENDING,
                ]);
                $pendingIds[] = $payment->getKey();
            }

            return $pendingIds;
        }, 3);

        $outcome = $this->emptyOutcome(discovered: count($pendingIds));

        if ($pendingIds === []) {
            $outcome['observed'] = 1;

            return $outcome;
        }

        foreach ($pendingIds as $paymentId) {
            $status = $this->accounting->account($paymentId, $remotePaid);

            if (array_key_exists($status, $outcome)) {
                $outcome[$status]++;
            }
        }

        return $outcome;
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function assertPaymentsMatch(array $payments, string $remotePaid, string $currency): void
    {
        $sum = '0.00';

        foreach ($payments as $payment) {
            $sum = $this->decimal->add($sum, $payment['amount']);
        }

        if ($this->decimal->compare($sum, $remotePaid) !== 0) {
            throw new PrestashopImportException(
                "Suma płatności ({$sum} {$currency}) nie zgadza się z kwotą zapłaconą ({$remotePaid} {$currency}).",
            );
        }
    }

    /** @param array<string, mixed> $remote */
    private function assertPaymentUnchanged(PrestashopOrderPayment $payment, array $remote): void
    {
        if (
            $this->decimal->compare((string) $payment->amount, $remote['amount']) !== 0
            || strtoupper((string) $payment->currency) !== strtoupper((string) $remote['currency'])
            || (string) ($payment->transaction_id ?? '') !== (string) ($remote['transaction_id'] ?? '')
        ) {
            throw new PrestashopImportException(
                'PrestaShop zmienił dane wcześniej zapisanej płatności. Wymagana jest ręczna weryfikacja.',
            );
        }
    }

    private function money(mixed $value): string
    {
        try {
            $normalized = $this->decimal->normalize((string) $value, 2);

            if ($this->decimal->compare($normalized, '0.00') < 0) {
                throw new PrestashopImportException('PrestaShop zwrócił ujemną kwotę zapłaconą.');
            }

            return $this->financial->assertOrderMoney(
                $normalized,
                'Kwota zapłacona PrestaShop przekracza zakres obsługiwany przez OMS.',
            );
        } catch (InvoiceDomainException $exception) {
            throw new PrestashopImportException($exception->getMessage(), previous: $exception);
        }
    }

    /** @return array{discovered: int, accounted: int, mirrored: int, review: int, observed: int} */
    private function emptyOutcome(
        int $discovered = 0,
        int $accounted = 0,
        int $mirrored = 0,
        int $review = 0,
        int $observed = 0,
    ): array {
        return compact('discovered', 'accounted', 'mirrored', 'review', 'observed');
    }
}
