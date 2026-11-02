<?php

namespace Modules\Integrations\VonHalsky\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\VonHalsky\Jobs\DiscoverVonHalskyOrdersJob;
use Modules\Integrations\VonHalsky\Jobs\ImportVonHalskyOrderJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;

class VonHalskyOrderImportDispatcher
{
    public function __construct(private readonly VonHalskyOrderImportState $state,
        private readonly VonHalskyOrderStatusSyncService $statuses,
        private readonly VonHalskyOrderCommandService $commands) {}

    public function dispatchDue(): int
    {
        if (! $this->state->ready()) {
            return 0;
        }
        $count = $this->commands->dispatchDue();
        foreach (VonHalskyAccount::query()->where('order_import_enabled', true)->where('is_active', true)->get() as $account) {
            $revision = (int) $account->order_import_revision;
            $reserved = DB::transaction(function () use ($account, $revision): bool {
                $current = $this->state->account($account->id, $revision, lock: true);
                if (! $current || $current->last_order_scan_requested_at?->gt(now()->subMinutes(max(1, (int) $current->order_import_interval_minutes)))) {
                    return false;
                }
                $current->forceFill(['last_order_scan_requested_at' => now()])->save();
                DiscoverVonHalskyOrdersJob::dispatch($current->id, $revision)->afterCommit();

                return true;
            });
            $count += $reserved ? 1 : 0;
            if ($reserved) {
                $count += $this->statuses->dispatchDue($account);
            }
            if ($this->state->account($account->id, $revision)) {
                $count += $this->dispatchPending($account);
            }
        }

        return $count;
    }

    public function requestNow(VonHalskyAccount $account, int $expectedVersion): void
    {
        if (! $account->exists) {
            throw ValidationException::withMessages(['von_halsky_scan' => 'Najpierw skonfiguruj polaczenie Von Halsky.']);
        }
        if (! $this->state->ready()) {
            throw ValidationException::withMessages(['von_halsky_scan' => 'Najpierw wykonaj migracje pobierania Von Halsky.']);
        }
        DB::transaction(function () use ($account, $expectedVersion): void {
            $importAccount = $this->state->account($account->id, (int) $account->order_import_revision, manual: true, lock: true);
            $current = $importAccount ?? $this->statuses->account($account->id, (int) $account->order_import_revision, manual: true, lock: true);
            if (! $current || $current->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['von_halsky_scan' => 'Sprawdz polaczenie, date rozpoczecia i aktualnosc ustawien.']);
            }
            if ($current->last_order_scan_requested_at?->gt(now()->subSeconds(30))) {
                throw ValidationException::withMessages(['von_halsky_scan' => 'Sprawdzenie zostalo juz zlecone. Odczekaj chwile.']);
            }
            if (! $importAccount && ! $this->statuses->hasKnownOrders($current)) {
                throw ValidationException::withMessages(['von_halsky_scan' => 'Wybierz datę pobierania lub zaimportuj wcześniej zamówienie do sprawdzania statusu.']);
            }
            $current->forceFill(['last_order_scan_requested_at' => now()])->save();
            if ($importAccount) {
                DiscoverVonHalskyOrdersJob::dispatch($current->id, (int) $current->order_import_revision, true)->afterCommit();
            }
            $this->statuses->dispatchDue($current, manual: true, force: true);
        });
    }

    public function dispatchPending(VonHalskyAccount $account, bool $manual = false): int
    {
        $attempts = VonHalskyOrderImportAttempt::query()->where('von_halsky_account_id', $account->id)
            ->where('environment', $account->environment->value)->where('organization_id', $account->organization_id)
            ->where('external_created_at', '>=', $account->order_import_started_at)
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(fn ($q) => $q->whereIn('status', ['waiting', 'failed'])->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->where('processing_expires_at', '<=', now()));
            })->orderBy('id')->limit(100)->get();
        foreach ($attempts as $attempt) {
            ImportVonHalskyOrderJob::dispatch($account->id, (int) $account->order_import_revision, $attempt->id, $manual)->afterCommit();
        }

        return $attempts->count();
    }
}
