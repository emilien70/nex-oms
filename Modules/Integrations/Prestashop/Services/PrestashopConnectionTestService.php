<?php

namespace Modules\Integrations\Prestashop\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;
use Modules\Integrations\Prestashop\Exceptions\PrestashopApiException;
use Modules\Integrations\Prestashop\Models\PrestashopAccount;

class PrestashopConnectionTestService
{
    public function __construct(
        private readonly PrestashopAccountService $accountService,
        private readonly PrestashopClient $client,
    ) {}

    public function test(): PrestashopAccount
    {
        $account = $this->accountService->get();

        try {
            $discovery = $this->client->discover($account);

            return $this->recordSuccess($account, $discovery);
        } catch (PrestashopApiException $exception) {
            return $this->recordFailure($account, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $discovery
     */
    private function recordSuccess(PrestashopAccount $account, array $discovery): PrestashopAccount
    {
        return DB::transaction(function () use ($account, $discovery): PrestashopAccount {
            $locked = PrestashopAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $refreshedAt = now();
            $externalIds = [];

            foreach ($discovery['order_states'] as $state) {
                $externalIds[] = $state['external_id'];
                $locked->orderStates()->updateOrCreate(
                    ['external_id' => $state['external_id']],
                    array_merge($state, [
                        'is_available' => true,
                        'refreshed_at' => $refreshedAt,
                    ]),
                );
            }

            $locked->orderStates()
                ->whereNotIn('external_id', $externalIds)
                ->update([
                    'is_available' => false,
                    'refreshed_at' => $refreshedAt,
                ]);

            $version = $discovery['version'] ?: 'nierozpoznana';
            $message = sprintf(
                'Połączenie z PrestaShop działa poprawnie. Sklep: %s, wersja: %s, statusy zamówień: %d.',
                $discovery['shop']['name'],
                $version,
                count($discovery['order_states']),
            );

            $locked->forceFill([
                'shop_id' => $discovery['shop']['id'],
                'shop_name' => $discovery['shop']['name'],
                'language_id' => $discovery['language']['id'],
                'language_iso_code' => $discovery['language']['iso_code'],
                'available_languages' => $discovery['languages'],
                'available_currencies' => $discovery['currencies'],
                'available_resources' => $discovery['resources'],
                'order_enrichment_resource' => $discovery['order_enrichment_resource'],
                'detected_version' => $discovery['version'],
                'last_tested_at' => $refreshedAt,
                'last_test_status' => PrestashopConnectionTestStatus::Success,
                'last_test_message' => mb_substr($message, 0, 1000),
            ])->save();

            return $locked->refresh()->load('orderStates');
        });
    }

    private function recordFailure(PrestashopAccount $account, string $message): PrestashopAccount
    {
        return DB::transaction(function () use ($account, $message): PrestashopAccount {
            $locked = PrestashopAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => PrestashopConnectionTestStatus::Error,
                'last_test_message' => mb_substr($message, 0, 1000),
                'is_active' => false,
            ])->save();

            return $locked->refresh();
        });
    }
}
