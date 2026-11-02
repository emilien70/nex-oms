<?php

namespace Modules\Integrations\VonHalsky\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Integrations\VonHalsky\Models\VonHalskyAccount;
use Modules\Integrations\VonHalsky\Models\VonHalskyOrderImportAttempt;

class VonHalskyOrderImportState
{
    public function ready(): bool
    {
        return Schema::hasTable('von_halsky_order_import_attempts')
            && Schema::hasColumn('von_halsky_accounts', 'order_import_revision')
            && DB::table('migrations')->where('migration', '2026_10_29_040000_add_von_halsky_order_discovery')->exists();
    }

    public function account(int $id, int $revision, bool $manual = false, bool $lock = false): ?VonHalskyAccount
    {
        $account = VonHalskyAccount::query()->whereKey($id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $account || (int) $account->order_import_revision !== $revision || ! $account->is_active
            || $account->last_test_status !== 'success' || ! $account->hasCompleteCredentials()
            || ! $account->order_import_started_at || $account->order_import_started_at->isFuture()
            || (! $manual && ! $account->order_import_enabled)) {
            return null;
        }

        return $account;
    }

    public function owns(VonHalskyAccount $account, VonHalskyOrderImportAttempt $attempt): bool
    {
        return $attempt->von_halsky_account_id === $account->id
            && $attempt->environment === $account->environment->value
            && $attempt->organization_id === $account->organization_id
            && $attempt->external_created_at->gte($account->order_import_started_at);
    }
}
