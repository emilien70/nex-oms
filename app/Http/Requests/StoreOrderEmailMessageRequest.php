<?php

namespace App\Http\Requests;

use App\Enums\EmailTemplateFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderEmailMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $templateId = $this->input('email_template_id');

        $this->merge([
            'email_template_id' => $templateId === '' ? null : $templateId,
            'subject' => trim((string) $this->input('subject')),
        ]);
    }

    public function rules(): array
    {
        return [
            'message_type' => ['required', Rule::in(['email'])],
            'email_template_id' => [
                'nullable',
                'integer',
                Rule::exists('email_templates', 'id')->where('is_hidden', 0),
            ],
            'email_account_id' => ['required', 'integer', Rule::exists('email_accounts', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'format' => ['required', Rule::enum(EmailTemplateFormat::class)],
            'body' => ['required', 'string', 'max:100000'],
            'content_rendered' => ['sometimes', 'boolean'],
            'sending_option' => ['required', Rule::in(['page_and_email'])],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'message_type.in' => 'Wysyłka SMS nie jest jeszcze dostępna.',
            'email_template_id.exists' => 'Wybrany szablon e-mail nie jest dostępny.',
            'email_account_id.required' => 'Wybierz konto e-mail.',
            'email_account_id.exists' => 'Wybrane konto e-mail nie istnieje.',
            'subject.required' => 'Podaj temat wiadomości.',
            'body.required' => 'Podaj treść wiadomości.',
            'idempotency_key.uuid' => 'Nie udało się przygotować bezpiecznego identyfikatora wysyłki.',
        ];
    }
}
