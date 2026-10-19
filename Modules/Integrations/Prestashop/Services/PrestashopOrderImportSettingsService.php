<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopOrderImportSettingsService
{
    /** @param array<string, mixed> $settings */
    public function update(array $settings): PrestashopAccount
    {
        return DB::transaction(function () use ($settings): PrestashopAccount {
            $account = PrestashopAccount::query()
                ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $enabled = (bool) $settings['order_import_enabled'];

            if ($enabled && (! $account->is_active || ! $account->hasCompleteCredentials())) {
                throw ValidationException::withMessages([
                    'order_import_enabled' => 'Włącz i poprawnie skonfiguruj integrację przed uruchomieniem automatycznego importu.',
                ]);
            }

            if ($enabled && ! $account->order_import_enabled) {
                $startedAt = now()->startOfSecond();
                $account->forceFill([
                    'order_import_started_at' => $startedAt,
                    'last_order_import_scan_at' => $startedAt,
                    'last_order_import_error_at' => null,
                    'last_order_import_error' => null,
                ]);
            }

            $account->order_import_enabled = $enabled;

            foreach (['use_linked_products', 'use_catalog_product_names', 'catalog_vat_mode'] as $field) {
                if (array_key_exists($field, $settings)) {
                    $account->{$field} = $settings[$field];
                }
            }

            $account->save();

            return $account->refresh();
        });
    }
}
