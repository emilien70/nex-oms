<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Exceptions\PrestashopImportException;
use Modules\Integrations\Prestashop\Models\PrestashopOrderImportAttempt;
use Modules\Integrations\Prestashop\Models\PrestashopOrderLink;
use Throwable;

class PrestashopAutomaticOrderImportService
{
    public function __construct(
        private readonly PrestashopOrderImportService $importer,
        private readonly PrestashopInboundStatusSyncService $statusSync,
    ) {}

    public function importAttempt(int $attemptId): void
    {
        $attempt = DB::transaction(function () use ($attemptId): PrestashopOrderImportAttempt {
            $managedAttempt = PrestashopOrderImportAttempt::query()
                ->with('account')
                ->whereKey($attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($managedAttempt->status === PrestashopOrderImportAttempt::STATUS_IMPORTED) {
                return $managedAttempt;
            }

            if (
                ! $managedAttempt->account?->is_active
                || ! $managedAttempt->account->order_import_enabled
                || ! $managedAttempt->account->hasCompleteCredentials()
            ) {
                throw new PrestashopImportException(
                    'Automatyczny import zamówień PrestaShop jest wyłączony lub nieprawidłowo skonfigurowany.',
                );
            }

            $managedAttempt->forceFill([
                'status' => PrestashopOrderImportAttempt::STATUS_PROCESSING,
                'attempts' => $managedAttempt->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
            ])->save();

            return $managedAttempt->refresh()->load('account');
        }, 3);

        if ($attempt->status === PrestashopOrderImportAttempt::STATUS_IMPORTED) {
            return;
        }

        $account = $attempt->account;

        if (! $account) {
            throw new PrestashopImportException('Konto integracji tego importu już nie istnieje.');
        }

        $order = $this->importer->importForAccount($account, (string) $attempt->external_order_id);
        $link = PrestashopOrderLink::query()
            ->where('prestashop_account_id', $account->getKey())
            ->where('external_order_id', $attempt->external_order_id)
            ->firstOrFail();

        $this->statusSync->applyStoredOrderState(
            $account->getKey(),
            $attempt->external_order_id,
        );

        PrestashopOrderImportAttempt::query()
            ->whereKey($attemptId)
            ->update([
                'order_id' => $order->getKey(),
                'external_reference' => $link->external_reference,
                'status' => PrestashopOrderImportAttempt::STATUS_IMPORTED,
                'imported_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    public function recordFailure(int $attemptId, Throwable $exception): void
    {
        $message = trim($exception->getMessage());

        PrestashopOrderImportAttempt::query()
            ->whereKey($attemptId)
            ->where('status', '!=', PrestashopOrderImportAttempt::STATUS_IMPORTED)
            ->update([
                'status' => PrestashopOrderImportAttempt::STATUS_FAILED,
                'last_error' => mb_substr(
                    $message !== '' ? $message : 'Wystąpił nieoczekiwany błąd importu zamówienia.',
                    0,
                    1000,
                ),
                'updated_at' => now(),
            ]);
    }
}
