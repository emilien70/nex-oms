<?php

namespace Modules\Integrations\PayNow\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;
use Modules\Integrations\PayNow\Exceptions\PayNowApiException;
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

    public function __construct(private readonly PayNowConnectionTestService $connectionTest) {}

    public function get(): PayNowAccount
    {
        return PayNowAccount::query()->firstOrCreate(
            ['account_key' => PayNowAccount::DEFAULT_ACCOUNT_KEY],
            $this->defaults(),
        );
    }

    public function update(array $data): PayNowAccount
    {
        $account = $this->get()->refresh();
        $original = $account->getRawOriginal();
        $environmentChanged = $account->environment !== PayNowEnvironment::from($data['environment']);
        $newApiKey = trim((string) ($data['api_key'] ?? ''));
        $newSignatureKey = trim((string) ($data['signature_key'] ?? ''));

        if ($environmentChanged && ($newApiKey === '' || $newSignatureKey === '')) {
            throw ValidationException::withMessages([
                'api_key' => 'Po zmianie środowiska wpisz nowy Klucz API.',
                'signature_key' => 'Po zmianie środowiska wpisz nowy Klucz podpisu.',
            ]);
        }

        $credentialsChanged = $environmentChanged;
        foreach (['api_key' => $newApiKey, 'signature_key' => $newSignatureKey] as $field => $key) {
            if ($key !== '' && $key !== $account->{$field}) {
                $account->{$field} = $key;
                $credentialsChanged = true;
            }
        }

        $account->fill(collect($data)->only(self::EDITABLE_FIELDS)->all());
        $wantsActivation = (bool) $data['is_active'];
        $testRequired = $credentialsChanged
            || ($wantsActivation && (! $account->is_active || $account->last_test_status !== PayNowConnectionTestStatus::Success));

        if (($wantsActivation || $testRequired) && ! $account->hasCompleteCredentials()) {
            throw ValidationException::withMessages([
                'connection_test' => 'Podaj oba klucze PayNow, aby sprawdzić połączenie i zapisać ustawienia.',
            ]);
        }

        if ($testRequired) {
            try {
                $account->forceFill($this->connectionTest->successfulTest($account));
            } catch (PayNowApiException $exception) {
                throw ValidationException::withMessages([
                    'connection_test' => 'Nie zapisano ustawień PayNow. '.$exception->getMessage(),
                ]);
            }
        }

        $account->is_active = $wantsActivation;

        // The network request must not hold a database lock.
        return DB::transaction(function () use ($account, $original): PayNowAccount {
            $locked = PayNowAccount::query()->lockForUpdate()->findOrFail($account->getKey());
            if ($locked->getRawOriginal() !== $original) {
                throw ValidationException::withMessages([
                    'connection_test' => 'Ustawienia PayNow zmieniły się w trakcie zapisu. Odśwież stronę i spróbuj ponownie.',
                ]);
            }

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
