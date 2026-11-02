<?php

namespace Modules\Integrations\VonHalsky\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\VonHalsky\Enums\VonHalskyOrderStatus;
use Modules\Integrations\VonHalsky\Exceptions\VonHalskyApiException;
use Modules\Integrations\VonHalsky\Jobs\SyncVonHalskyOrderStatusJob;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderLink;
use Throwable;

class VonHalskyOrderStatusSyncService
{
    public function __construct(private readonly VonHalskyClient $client, private readonly VonHalskyOrderImportState $state) {}

    public function ready(): bool
    {
        return $this->state->ready()
            && Schema::hasColumns('von_halsky_order_links', ['external_status_updated_at', 'last_status_checked_at',
                'last_status_sync_requested_at', 'last_status_sync_error'])
            && DB::table('migrations')->where('migration', '2026_10_30_010000_add_von_halsky_order_status_sync')->exists();
    }

    public function account(int $id, int $revision, bool $manual = false, bool $lock = false): ?VonHalskyAccount
    {
        if (! $this->ready()) {
            return null;
        }
        $account = VonHalskyAccount::query()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return $account && (int) $account->order_import_revision === $revision && $account->is_active
            && $account->last_test_status === 'success' && $account->hasCompleteCredentials()
            && ($manual || $account->order_import_enabled) ? $account : null;
    }

    public function hasKnownOrders(VonHalskyAccount $account): bool
    {
        return $account->exists && $this->links($account)->exists();
    }

    public function dispatchDue(VonHalskyAccount $context, bool $manual = false, bool $force = false): int
    {
        $account = $this->account($context->id, (int) $context->order_import_revision, $manual);
        if (! $account) {
            return 0;
        }
        $query = $this->links($account);
        if (! $force) {
            $query->where(fn ($q) => $q->whereNull('last_status_sync_requested_at')
                ->orWhere('last_status_sync_requested_at', '<=', now()->subMinutes(max(1, $account->order_import_interval_minutes))->endOfSecond()->format('Y-m-d H:i:s.u')));
        }
        $ids = $query->orderBy('last_status_sync_requested_at')->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($context, $manual, $force, $id): int {
                $current = $this->account($context->id, (int) $context->order_import_revision, $manual, lock: true);
                $pair = $current ? $this->lockedLink($current, $id) : null;
                if (! $pair) {
                    return 0;
                }
                [$link] = $pair;
                $requested = $link->last_status_sync_requested_at;
                if ($requested && ((! $force && $requested->gt(now()->subMinutes(max(1, $current->order_import_interval_minutes))->endOfSecond()))
                    || (! $link->last_status_sync_error && (! $link->last_status_checked_at || $requested->gt($link->last_status_checked_at))
                        && $requested->gt(now()->subSeconds(180))))) {
                    return 0;
                }
                $stamp = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
                DB::table('von_halsky_order_links')->where('id', $id)->update([
                    'last_status_sync_requested_at' => $stamp, 'last_status_sync_error' => null,
                ]);
                SyncVonHalskyOrderStatusJob::dispatch($current->id, (int) $current->order_import_revision, $id, $stamp, $manual)->afterCommit();

                return 1;
            }, 3);
        }

        return $count;
    }

    public function sync(int $accountId, int $revision, int $linkId, string $requestedAt, bool $manual = false): void
    {
        $account = $this->account($accountId, $revision, $manual);
        $link = $account ? $this->links($account)->find($linkId) : null;
        if (! $link || ! $this->isCurrentRequest($link, $requestedAt)) {
            return;
        }
        $data = $this->client->order($account, $link->external_order_id);
        [$created, $updated] = $this->validate($account, $link, $data);
        DB::transaction(function () use ($accountId, $revision, $linkId, $requestedAt, $manual, $data, $created, $updated): void {
            $account = $this->account($accountId, $revision, $manual, lock: true);
            $pair = $account ? $this->lockedLink($account, $linkId) : null;
            if (! $pair) {
                return;
            }
            [$link, $order] = $pair;
            if (! $this->isCurrentRequest($link, $requestedAt)) {
                return;
            }
            if ($updated && $link->external_status_updated_at && $updated->lt($link->external_status_updated_at)) {
                throw new VonHalskyApiException('Von Halsky zwrócił starszy stan zamówienia. Dotychczasowy status został zachowany.');
            }
            $previous = $link->external_status;
            $changes = ['external_status' => $data['status'], 'last_status_checked_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
                'last_status_sync_error' => null, 'updated_at' => now()->format('Y-m-d H:i:s')];
            if ($updated) {
                $changes['external_status_updated_at'] = $updated->format('Y-m-d H:i:s.u');
            }
            // Keep source microseconds without changing storage of historical payment timestamps.
            DB::table('von_halsky_order_links')->where('id', $link->id)->update($changes);
            if ($previous !== $data['status']) {
                $order->events()->create([
                    'event_type' => 'von_halsky_status_changed', 'title' => 'Zmieniono status w InPost Von Halsky',
                    'description' => 'Status Von Halsky: '.$this->label($previous).' -> '.$this->label($data['status']).'.',
                    'payload' => ['from' => $previous, 'to' => $data['status'], 'source_updated_at' => $updated?->utc()->toISOString()],
                ]);
            }
            $this->updateHistory($account, $link, $order, $data['status'], $created, $updated);
        }, 3);
    }

    public function recordFailure(int $accountId, int $revision, int $linkId, string $requestedAt,
        ?Throwable $exception, bool $manual = false): void
    {
        DB::transaction(function () use ($accountId, $revision, $linkId, $requestedAt, $exception, $manual): void {
            $account = $this->account($accountId, $revision, $manual, lock: true);
            $pair = $account ? $this->lockedLink($account, $linkId) : null;
            if ($pair && $this->isCurrentRequest($pair[0], $requestedAt)) {
                DB::table('von_halsky_order_links')->where('id', $linkId)->update([
                    'last_status_sync_error' => $exception instanceof VonHalskyApiException
                        ? mb_substr($exception->getMessage(), 0, 1000) : 'Nie udało się sprawdzić statusu Von Halsky. Dotychczasowy status został zachowany.',
                ]);
            }
        }, 3);
    }

    private function links(VonHalskyAccount $account): Builder
    {
        return VonHalskyOrderLink::query()->where('von_halsky_account_id', $account->id)
            ->where('environment', $account->environment->value)->where('organization_id', $account->organization_id)
            ->whereHas('order', fn ($q) => $q->where('source', 'von_halsky')
                ->whereColumn('orders.external_id', 'von_halsky_order_links.external_order_id'));
    }

    private function isCurrentRequest(VonHalskyOrderLink $link, string $requestedAt): bool
    {
        return $link->getRawOriginal('last_status_sync_requested_at') === $requestedAt
            && (! $link->last_status_checked_at || $link->last_status_checked_at->lt($link->last_status_sync_requested_at));
    }

    private function lockedLink(VonHalskyAccount $account, int $id): ?array
    {
        $snapshot = $this->links($account)->find($id);
        $order = $snapshot ? Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->first() : null;
        $link = $order ? $this->links($account)->where('order_id', $order->id)->lockForUpdate()->find($id) : null;

        return $link ? [$link, $order] : null;
    }

    private function validate(VonHalskyAccount $account, VonHalskyOrderLink $link, array $data): array
    {
        if (($data['id'] ?? null) !== $link->external_order_id || ! is_string($data['organizationId'] ?? null)
            || strtolower($data['organizationId']) !== strtolower($account->organization_id)
            || ! is_string($data['status'] ?? null) || ! preg_match('/\A[A-Z][A-Z0-9_]{0,31}\z/D', $data['status'])) {
            throw new VonHalskyApiException('Nieprawidłowy status lub tożsamość zamówienia Von Halsky.');
        }
        $dates = [];
        foreach (['createdAt', 'updatedAt'] as $field) {
            if ($field === 'updatedAt' && ! isset($data[$field])) {
                $dates[] = null;

                continue;
            }
            if (! is_string($data[$field] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T/', $data[$field])) {
                throw new VonHalskyApiException('Nieprawidłowa data stanu zamówienia Von Halsky.');
            }
            try {
                $dates[] = CarbonImmutable::parse($data[$field])->setTimezone(config('app.timezone'));
            } catch (Throwable) {
                throw new VonHalskyApiException('Nieprawidłowa data stanu zamówienia Von Halsky.');
            }
        }
        if ($dates[1] && $dates[1]->lt($dates[0])) {
            throw new VonHalskyApiException('Niespójne daty stanu zamówienia Von Halsky.');
        }

        return $dates;
    }

    private function updateHistory(VonHalskyAccount $account, VonHalskyOrderLink $link, Order $order,
        string $status, CarbonImmutable $created, ?CarbonImmutable $updated): void
    {
        $attempt = VonHalskyOrderImportAttempt::query()->firstOrCreate([
            'environment' => $account->environment->value, 'organization_id' => $account->organization_id,
            'external_order_id' => $link->external_order_id,
        ], ['von_halsky_account_id' => $account->id, 'external_created_at' => $created,
            'external_updated_at' => $updated ?? $created, 'external_status' => $status,
            'discovered_at' => now(), 'last_seen_at' => now()]);
        $attempt = VonHalskyOrderImportAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        if (($attempt->von_halsky_account_id !== null && $attempt->von_halsky_account_id !== $account->id)
            || ($attempt->order_id !== null && $attempt->order_id !== $order->id)) {
            throw new VonHalskyApiException('Historia statusu Von Halsky ma inne powiązanie zamówienia lub konta.');
        }
        $attempt->fill(['von_halsky_account_id' => $account->id, 'order_id' => $order->id, 'status' => 'imported',
            'last_seen_at' => now(), 'last_attempt_at' => now(), 'last_message' => null, 'next_attempt_at' => null,
            'processing_token' => null, 'processing_expires_at' => null]);
        if (! $updated || $updated->gte($attempt->external_updated_at)) {
            $attempt->external_status = $status;
            if ($updated) {
                $attempt->external_updated_at = $updated;
            }
        }
        $attempt->save();
    }

    private function label(string $status): string
    {
        return (VonHalskyOrderStatus::tryFrom($status) ?? VonHalskyOrderStatus::Unknown)->label()
            .' ('.$status.')';
    }
}
