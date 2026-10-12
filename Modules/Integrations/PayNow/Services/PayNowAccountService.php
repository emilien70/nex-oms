<?php

namespace Modules\Integrations\PayNow\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Models\PayNowAccount;

class PayNowAccountService
{
    private const EDITABLE_FIELDS = [
        'name',
        'environment',
        'link_validity_seconds',
        'description_template',
        'continue_url',
    ];

    private const RESET_TEST_STATE = [
        'last_tested_at' => null,
        'last_test_status' => null,
        'last_test_message' => null,
    ];

    public function get(): PayNowAccount
    {
        return PayNowAccount::query()->firstOrCreate(
            ['account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY],
            $this->defaults(),
        );
    }

    public function update(array $data): PayNowAccount
    {
        return DB::transaction(function () use ($data): PayNowAccount {
            $this->get();

            $account = PayNowAccount::query()
                ->where('account_key', PayNowAccount::DEFAULT_ACCOUNT_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $newEnvironment = PayNowEnvironment::from($data['environment']);
            $environmentChanged = $account->environment !== $newEnvironment;
            $newApiKey = trim((string) ($data['api_key'] ?? ''));
            $newSignatureKey = trim((string) ($data['signature_key'] ?? ''));

            if ($environmentChanged && ($newApiKey === '' || $newSignatureKey === '')) {
                throw ValidationException::withMessages([
                    'api_key' => 'Po zmianie środowiska wpisz nowy Klucz API.',
                    'signature_key' => 'Po zmianie środowiska wpisz nowy Klucz podpisu.',
                ]);
            }

            $credentialsChanged = $environmentChanged;

            if ($newApiKey !== '' && $newApiKey !== $account->api_key) {
                $account->api_key = $newApiKey;
                $credentialsChanged = true;
            }

            if ($newSignatureKey !== '' && $newSignatureKey !== $account->signature_key) {
                $account->signature_key = $newSignatureKey;
                $credentialsChanged = true;
            }

            $account->fill(collect($data)->only(self::EDITABLE_FIELDS)->all());

            if ($credentialsChanged) {
                $account->forceFill(self::RESET_TEST_STATE);
            }

            $wantsActivation = (bool) $data['is_active'];

            if ($wantsActivation && ! $account->hasCompleteCredentials()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Aby włączyć integrację, najpierw zapisz oba klucze PayNow.',
                ]);
            }

            if ($wantsActivation && $account->last_test_status !== PayNowConnectionTestStatus::Success) {
                throw ValidationException::withMessages([
                    'is_active' => 'Aby włączyć integrację, wykonaj poprawny test połączenia dla zapisanych kluczy.',
                ]);
            }

            $account->is_active = $wantsActivation;
            $account->save();

            return $account->refresh();
        });
    }

    private function defaults(): array
    {
        return [
            'name' => 'PayNow',
            'environment' => PayNowEnvironment::Sandbox,
            'is_active' => false,
            'link_validity_seconds' => 604800,
            'description_template' => 'Płatność za zamówienie nr [id_zamowienia]',
        ];
    }
}
