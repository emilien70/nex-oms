<?php

namespace App\Http\Controllers;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailAccountTestStatus;
use App\Exceptions\SmtpConnectionException;
use App\Http\Requests\SaveEmailAccountRequest;
use App\Models\EmailAccount;
use App\Services\SmtpConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EmailAccountController extends Controller
{
    public function index(): View
    {
        $accounts = EmailAccount::query()
            ->orderBy('email')
            ->orderBy('name')
            ->get();

        return view('orders.email-settings', [
            'activeTab' => 'accounts',
            'accounts' => $accounts,
            'encryptionOptions' => EmailAccountEncryption::cases(),
            'accountFormData' => $accounts->mapWithKeys(fn (EmailAccount $account): array => [
                (string) $account->getKey() => [
                    'id' => $account->getKey(),
                    'smtp_host' => $account->smtp_host,
                    'smtp_port' => $account->smtp_port,
                    'smtp_username' => $account->smtp_username,
                    'encryption' => $account->encryption->value,
                    'email' => $account->email,
                    'name' => $account->name,
                    'has_password' => $account->hasConfiguredPassword(),
                ],
            ]),
        ]);
    }

    public function store(SaveEmailAccountRequest $request): RedirectResponse
    {
        EmailAccount::query()->create($this->payload($request));

        return redirect()
            ->route('orders.email-accounts.index')
            ->with('success', 'Konto e-mail zostało zapisane.');
    }

    public function update(SaveEmailAccountRequest $request, EmailAccount $emailAccount): RedirectResponse
    {
        $emailAccount->fill($this->payload($request, preserveBlankPassword: true));

        if ($emailAccount->isDirty([
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'encryption',
        ])) {
            $emailAccount->forceFill([
                'last_tested_at' => null,
                'last_test_status' => null,
                'last_test_message' => null,
            ]);
        }

        $emailAccount->save();

        return redirect()
            ->route('orders.email-accounts.index')
            ->with('success', 'Konto e-mail zostało zapisane.');
    }

    public function duplicate(EmailAccount $emailAccount): RedirectResponse
    {
        $copy = EmailAccount::query()->create([
            'name' => 'Kopia - '.$emailAccount->name,
            'email' => $emailAccount->email,
            'smtp_host' => $emailAccount->smtp_host,
            'smtp_port' => $emailAccount->smtp_port,
            'smtp_username' => $emailAccount->smtp_username,
            'smtp_password' => null,
            'encryption' => $emailAccount->encryption,
        ]);

        return redirect()
            ->route('orders.email-accounts.index', ['edit' => $copy->getKey()])
            ->with('success', 'Utworzono kopię konta. Uzupełnij dla niej hasło SMTP.');
    }

    public function destroy(EmailAccount $emailAccount): RedirectResponse
    {
        $emailAccount->delete();

        return redirect()
            ->route('orders.email-accounts.index')
            ->with('success', 'Konto e-mail zostało usunięte.');
    }

    public function test(EmailAccount $emailAccount, SmtpConnectionTester $tester): RedirectResponse
    {
        try {
            $tester->test($emailAccount);

            $emailAccount->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => EmailAccountTestStatus::Success,
                'last_test_message' => 'Połączenie i uwierzytelnienie SMTP działają poprawnie.',
            ])->save();

            return redirect()
                ->route('orders.email-accounts.index')
                ->with('success', 'Połączenie i uwierzytelnienie SMTP działają poprawnie.');
        } catch (SmtpConnectionException $exception) {
            $emailAccount->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => EmailAccountTestStatus::Error,
                'last_test_message' => $exception->getMessage(),
            ])->save();

            return redirect()
                ->route('orders.email-accounts.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function payload(
        SaveEmailAccountRequest $request,
        bool $preserveBlankPassword = false,
    ): array {
        $data = $request->safe()->except('email_account_id');

        if ($preserveBlankPassword && blank($data['smtp_password'] ?? null)) {
            unset($data['smtp_password']);
        }

        return $data;
    }
}
