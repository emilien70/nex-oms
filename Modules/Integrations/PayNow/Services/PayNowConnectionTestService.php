<?php

namespace Modules\Integrations\PayNow\Services;

use Illuminate\Support\Facades\DB;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
use Modules\Integrations\PayNow\Models\PayNowAccount;

class PayNowConnectionTestService
{
    public function __construct(
        private readonly PayNowAccountService $accountService,
        private readonly PayNowClient $client,
    ) {}

    public function test(): PayNowAccount
    {
        $account = $this->accountService->get();

        try {
            $methodCount = $this->client->testConnection($account);

            return $this->record(
                $account,
                PayNowConnectionTestStatus::Success,
                "Połączenie z PayNow działa poprawnie. Dostępne metody płatności: {$methodCount}.",
            );
        } catch (PayNowApiException $exception) {
            return $this->record(
                $account,
                PayNowConnectionTestStatus::Error,
                $exception->getMessage(),
            );
        }
    }

    private function record(
        PayNowAccount $account,
        PayNowConnectionTestStatus $status,
        string $message,
    ): PayNowAccount {
        return DB::transaction(function () use ($account, $status, $message): PayNowAccount {
            $locked = PayNowAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => $status,
                'last_test_message' => mb_substr($message, 0, 1000),
                'is_active' => $status === PayNowConnectionTestStatus::Success
                    ? $locked->is_active
                    : false,
            ])->save();

            return $locked->refresh();
        });
    }
}
