<?php

namespace Modules\Integrations\Allegro\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Allegro\Exceptions\AllegroApiException;
use Modules\Integrations\Allegro\Jobs\DiscoverAllegroRefundsJob;
use Modules\Integrations\Allegro\Jobs\SyncAllegroRefundJob;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Integrations\Allegro\Models\AllegroPaymentRefund;

class AllegroRefundSyncService
{
    public function __construct(private readonly AllegroClient $client, private readonly AllegroRefundNormalizer $normalizer, private readonly AllegroRefundImportService $importer) {}

    public function active(?AllegroAccount $account, int $revision): bool
    {
        return $account && $account->refunds_enabled && $account->refunds_from_at && $account->isOperational()
            && $account->refunds_revision === $revision && $account->refunds_identity === $account->orderImportIdentity()
            && in_array(AllegroAccount::PAYMENTS_READ_SCOPE, $account->granted_scopes ?? [], true);
    }

    public function dispatchDue(): void
    {
        AllegroAccount::query()->where('refunds_enabled', true)->eachById(function ($account): void {
            if (! $this->active($account, $account->refunds_revision)) {
                return;
            }
            if ((! $account->refunds_retry_after || $account->refunds_retry_after->lte(now()))
                && ($account->refunds_scan_until || ! $account->refunds_last_scan_at || $account->refunds_last_scan_at->lte(now()->subMinutes(5)))) {
                DiscoverAllegroRefundsJob::dispatch($account->id, $account->refunds_revision);
            }
            AllegroPaymentRefund::query()->where('allegro_account_id', $account->id)->where('environment', $account->environment)
                ->where('seller_user_id', $account->allegro_user_id)->where('next_sync_at', '<=', now())->orderBy('next_sync_at')->orderBy('id')->limit(100)->get()
                ->each(fn ($refund) => SyncAllegroRefundJob::dispatch($account->id, $account->refunds_revision, $refund->id));
        });
    }

    public function discover(int $accountId, int $revision): void
    {
        $account = DB::transaction(function () use ($accountId, $revision): ?AllegroAccount {
            $account = AllegroAccount::query()->lockForUpdate()->find($accountId);
            if (! $this->active($account, $revision)) {
                return null;
            }
            if (! $account->refunds_scan_until) {
                $account->update(['refunds_scan_until' => now(), 'refunds_scan_offset' => 0]);
            }

            return $account->fresh();
        }, 3);
        if (! $account) {
            return;
        }
        $response = $this->client->refunds($account, ['occurredAt.gte' => $account->refunds_from_at->utc()->toIso8601String(),
            'occurredAt.lte' => $account->refunds_scan_until->utc()->toIso8601String(), 'limit' => 100, 'offset' => $account->refunds_scan_offset]);
        $data = $this->page($response, $account->refunds_scan_offset);
        $hasMore = $account->refunds_scan_offset + count($data) < $response['totalCount'];
        DB::transaction(function () use ($account, $revision, $data, $hasMore): void {
            $current = AllegroAccount::query()->lockForUpdate()->find($account->id);
            if (! $this->active($current, $revision) || $current->refunds_scan_offset !== $account->refunds_scan_offset
                || ! $current->refunds_scan_until?->equalTo($account->refunds_scan_until)) {
                return;
            }
            foreach ($data as $row) {
                $this->importer->store($current, $row);
            }
            $current->update($hasMore ? ['refunds_scan_offset' => $current->refunds_scan_offset + count($data), 'refunds_error' => null, 'refunds_retry_after' => null]
                : ['refunds_scan_offset' => 0, 'refunds_scan_until' => null, 'refunds_last_scan_at' => now(), 'refunds_error' => null, 'refunds_retry_after' => null]);
        }, 3);
    }

    public function refresh(int $accountId, int $revision, int $refundId): void
    {
        $account = AllegroAccount::query()->find($accountId);
        $record = AllegroPaymentRefund::query()->find($refundId);
        if (! $this->active($account, $revision) || ! $record || $record->allegro_account_id !== $account->id
            || $record->environment !== $account->environment || $record->seller_user_id !== $account->allegro_user_id) {
            return;
        }
        $rows = $this->page($this->client->refunds($account, ['id' => $record->external_refund_id, 'limit' => 100]));
        if (count($rows) !== 1 || $rows[0]['external_refund_id'] !== $record->external_refund_id) {
            $this->invalid();
        }
        DB::transaction(function () use ($account, $revision, $rows): void {
            $current = AllegroAccount::query()->lockForUpdate()->find($account->id);
            if ($this->active($current, $revision)) {
                $this->importer->store($current, $rows[0]);
            }
        }, 3);
    }

    private function page(array $response, int $offset = 0): array
    {
        $rows = $response['refunds'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! is_int($response['count'] ?? null)
            || $response['count'] !== count($rows) || ! is_int($response['totalCount'] ?? null) || $response['totalCount'] < count($rows)) {
            $this->invalid();
        }
        if ((count($rows) > 0 && $offset + count($rows) > $response['totalCount']) || (count($rows) === 0 && $offset < $response['totalCount'])) {
            $this->invalid();
        }
        $data = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $this->invalid();
            } $data[] = $this->normalizer->normalize($row);
        }
        if (count(array_unique(array_column($data, 'external_refund_id'))) !== count($data)) {
            $this->invalid();
        }

        return $data;
    }

    private function invalid(): never
    {
        throw new AllegroApiException('Nieprawidłowa odpowiedź listy refundacji Allegro. Zachowano poprzednie dane.', 422);
    }
}
