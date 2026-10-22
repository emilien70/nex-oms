<?php

namespace Modules\Integrations\Allegro\Services;

use App\Models\OrderReturn;
use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroReturnsJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroReturnJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;

class AllegroReturnSyncService
{
    public function __construct(
        private readonly AllegroClient $client,
        private readonly AllegroReturnNormalizer $normalizer,
        private readonly AllegroReturnImportService $importer,
    ) {}

    public function dispatchDue(): void
    {
        AllegroAccount::query()->where('returns_enabled', true)->eachById(function ($account): void {
            if (! $this->active($account, $account->returns_revision)) {
                return;
            }
            if ($account->returns_scan_until || ! $account->returns_last_scan_at || $account->returns_last_scan_at->lte(now()->subMinutes(5))) {
                DiscoverAllegroReturnsJob::dispatch($account->id, $account->returns_revision);
            }
            OrderReturn::query()->where('allegro_account_id', $account->id)->where('environment', $account->environment)
                ->where('seller_user_id', $account->allegro_user_id)->where('next_sync_at', '<=', now())
                ->orderBy('next_sync_at')->orderBy('id')->limit(100)->get()
                ->each(fn ($record) => SyncAllegroReturnJob::dispatch($record->id, $account->returns_revision));
        });
    }

    public function discover(int $accountId, int $revision): void
    {
        $account = DB::transaction(function () use ($accountId, $revision): ?AllegroAccount {
            $account = AllegroAccount::query()->lockForUpdate()->find($accountId);
            if (! $this->active($account, $revision)) {
                return null;
            }
            if (! $account->returns_scan_until) {
                $account->update(['returns_scan_until' => now()->utc(), 'returns_scan_offset' => 0]);
            }

            return $account->fresh();
        }, 3);
        if (! $account) {
            return;
        }
        // A fixed upper date makes the offset traversal independent of newly created returns.
        $response = $this->client->customerReturns($account, [
            'createdAt.gte' => $account->returns_from_at->utc()->toIso8601String(),
            'createdAt.lte' => $account->returns_scan_until->utc()->toIso8601String(),
            'limit' => 100, 'offset' => $account->returns_scan_offset,
        ]);
        $rows = $response['customerReturns'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new AllegroApiException('Allegro zwróciło nieprawidłową listę zwrotów.', 422);
        }
        $data = array_map(fn ($row) => $this->normalizer->normalize($row), $rows);
        if (count(array_unique(array_column($data, 'external_return_id'))) !== count($data)) {
            throw new AllegroApiException('Lista Allegro zawiera powtórzone identyfikatory zwrotów.', 422);
        }
        foreach ($data as $row) {
            if ($row['submitted_at']->lt($account->returns_from_at) || $row['submitted_at']->gt($account->returns_scan_until)) {
                throw new AllegroApiException('Zwrot nie należy do żądanego zakresu dat.', 422);
            }
        }
        DB::transaction(function () use ($account, $revision, $data): void {
            $current = AllegroAccount::query()->lockForUpdate()->find($account->id);
            if (! $this->active($current, $revision) || $current->returns_scan_offset !== $account->returns_scan_offset
                || ! $current->returns_scan_until?->equalTo($account->returns_scan_until)) {
                return;
            }
            foreach ($data as $row) {
                $this->importer->store($current, $row);
            }
            $current->update(count($data) === 100
                ? ['returns_scan_offset' => $current->returns_scan_offset + 100, 'returns_error' => null]
                : ['returns_scan_offset' => 0, 'returns_scan_until' => null, 'returns_last_scan_at' => now(), 'returns_error' => null]);
        }, 3);
    }

    public function refresh(int $returnId, int $revision): void
    {
        $record = OrderReturn::query()->with('account')->find($returnId);
        $account = $record?->account;
        if (! $record || ! $this->active($account, $revision)
            || $record->environment !== $account->environment || $record->seller_user_id !== $account->allegro_user_id) {
            return;
        }
        $data = $this->normalizer->normalize($this->client->customerReturn($account, $record->external_return_id));
        if ($data['external_return_id'] !== $record->external_return_id || $data['external_order_id'] !== $record->external_order_id) {
            throw new AllegroApiException('Odpowiedź Allegro dotyczy innego zwrotu lub zamówienia.', 422);
        }
        DB::transaction(function () use ($account, $revision, $data): void {
            $current = AllegroAccount::query()->lockForUpdate()->find($account->id);
            if ($this->active($current, $revision)) {
                $this->importer->store($current, $data);
            }
        }, 3);
    }

    public function active(?AllegroAccount $account, int $revision): bool
    {
        return $account && $account->returns_enabled && $account->returns_from_at
            && $account->returns_revision === $revision && $account->returns_identity === $account->orderImportIdentity()
            && $account->isOperational();
    }
}
