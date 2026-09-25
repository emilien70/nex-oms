<?php

namespace App\Http\Requests;

use App\Enums\EmailAccountEncryption;
use App\Models\EmailAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveEmailAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $account = $this->route('emailAccount');

        return [
            'email_account_id' => ['nullable', 'integer'],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => [$account instanceof EmailAccount ? 'nullable' : 'required', 'string', 'max:4096'],
            'encryption' => ['required', Rule::enum(EmailAccountEncryption::class)],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:160'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('emailAccount');

                if ($account instanceof EmailAccount
                    && ! $account->hasConfiguredPassword()
                    && ! filled($this->input('smtp_password'))) {
                    $validator->errors()->add('smtp_password', 'Wpisz hasło SMTP dla tego konta.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'smtp_host.required' => 'Wpisz adres serwera SMTP.',
            'smtp_host.regex' => 'Wpisz sam adres serwera SMTP, bez protokołu i numeru portu.',
            'smtp_port.required' => 'Wpisz port serwera SMTP.',
            'smtp_port.between' => 'Port SMTP musi mieścić się w zakresie od 1 do 65535.',
            'smtp_username.required' => 'Wpisz login SMTP.',
            'smtp_password.required' => 'Wpisz hasło SMTP.',
            'encryption.enum' => 'Wybierz prawidłowe zabezpieczenie połączenia.',
            'email.required' => 'Wpisz pełny adres e-mail nadawcy.',
            'email.email' => 'Wpisz prawidłowy adres e-mail nadawcy.',
            'name.required' => 'Wpisz nazwę wyświetlaną nadawcy.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'smtp_host' => mb_strtolower(trim((string) $this->input('smtp_host', ''))),
            'smtp_username' => trim((string) $this->input('smtp_username', '')),
            'email' => trim((string) $this->input('email', '')),
            'name' => trim((string) $this->input('name', '')),
        ]);
    }
}
