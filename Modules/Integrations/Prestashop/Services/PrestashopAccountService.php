<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopAccountService
{
    private const RESET_CONNECTION_STATE = [
        'shop_id' => null,
        'shop_name' => null,
        'language_id' => null,
        'language_iso_code' => null,
        'available_languages' => null,
        'available_currencies' => null,
        'available_resources' => null,
        'order_enrichment_resource' => null,
        'detected_version' => null,
        'last_tested_at' => null,
        'last_test_status' => null,
        'last_test_message' => null,
        'status_sync_enabled' => false,
        'last_status_sync_at' => null,
        'last_status_sync_error_at' => null,
        'last_status_sync_error' => null,
        'order_import_enabled' => false,
        'order_import_started_at' => null,
        'last_order_import_scan_at' => null,
        'last_order_import_error_at' => null,
        'last_order_import_error' => null,
        'payment_sync_enabled' => false,
        'last_payment_sync_at' => null,
        'last_payment_sync_error_at' => null,
        'last_payment_sync_error' => null,
    ];

    public function get(): PrestashopAccount
    {
        return PrestashopAccount::query()->firstOrCreate(
            ['account_key' => PrestashopAccount::DEFAULT_ACCOUNT_KEY],
            [
                'name' => 'multi-click.pl',
                'base_url' => 'https://multi-click.pl',
                'product_url_template' => 'https://multi-click.pl/index.php?controller=product&id_product=[product_id]',
                'is_active' => false,
            ],
        );
    }

    public function update(array $data): PrestashopAccount
    {
        return DB::transaction(function () use ($data): PrestashopAccount {
            $this->get();

            $account = PrestashopAccount::query()
                ->where('account_key', PrestashopAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $baseUrl = $this->normalizeBaseUrl((string) $data['base_url']);
            $newApiKey = trim((string) ($data['api_key'] ?? ''));
            $storeChanged = $baseUrl !== $account->base_url;
            $connectionChanged = $storeChanged;

            if ($storeChanged && $account->orderLinks()->exists()) {
                throw ValidationException::withMessages([
                    'base_url' => 'Nie można zmienić sklepu po zaimportowaniu zamówień. Zachowaj obecny adres integracji.',
                ]);
            }

            if ($newApiKey !== '' && $newApiKey !== $account->api_key) {
                $account->api_key = $newApiKey;
                $connectionChanged = true;
            }

            $account->fill([
                'name' => $data['name'],
                'base_url' => $baseUrl,
                'product_url_template' => $data['product_url_template'],
            ]);

            if ($connectionChanged) {
                $account->forceFill(self::RESET_CONNECTION_STATE);
                $account->is_active = false;
            }

            $wantsActivation = $connectionChanged
                ? false
                : (bool) $data['is_active'];

            if ($wantsActivation && ! $account->hasCompleteCredentials()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Aby włączyć integrację, najpierw zapisz adres sklepu i Klucz API.',
                ]);
            }

            if ($wantsActivation && $account->last_test_status !== PrestashopConnectionTestStatus::Success) {
                throw ValidationException::withMessages([
                    'is_active' => 'Aby włączyć integrację, wykonaj poprawny test połączenia dla zapisanej konfiguracji.',
                ]);
            }

            $account->is_active = $wantsActivation;
            $account->save();

            if ($connectionChanged) {
                $account->orderStates()->delete();
            }

            if ($storeChanged) {
                $account->inboundStatusMappings()->delete();
                $account->outboundStatusMappings()->delete();
            }

            return $account->refresh();
        });
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');

        if (preg_match('#/api$#i', $normalized) === 1) {
            $normalized = substr($normalized, 0, -4);
        }

        return rtrim($normalized, '/');
    }
}
