<?php

namespace Modules\Integrations\PayNow\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowAccount;

class PayNowConnectionTestService
{
    public function __construct(private readonly PayNowClient $client) {}

    public function successfulTest(PayNowAccount $account): array
    {
        $methodCount = $this->client->testConnection($account);

        return [
            'last_tested_at' => now(),
            'last_test_status' => PayNowConnectionTestStatus::Success,
            'last_test_message' => "Połączenie z PayNow działa poprawnie. Dostępne metody płatności: {$methodCount}.",
        ];
    }

    public function test(PayNowAccount $account): PayNowAccount
    {
        try {
            $result = $this->successfulTest($account);
        } catch (PayNowApiException $exception) {
            $result = [
                'last_tested_at' => now(),
                'last_test_status' => PayNowConnectionTestStatus::Error,
                'last_test_message' => mb_substr($exception->getMessage(), 0, 1000),
                'is_active' => false,
            ];
        }

        return DB::transaction(function () use ($account, $result): PayNowAccount {
            $locked = PayNowAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            foreach (['environment', 'api_key', 'signature_key'] as $field) {
                if ($locked->getRawOriginal($field) !== $account->getRawOriginal($field)) {
                    throw ValidationException::withMessages([
                        'connection_test' => 'Konfiguracja PayNow zmieniła się podczas testu. Wykonaj test ponownie.',
                    ]);
                }
            }

            $locked->forceFill($result)->save();

            return $locked->refresh();
        });
    }
}
