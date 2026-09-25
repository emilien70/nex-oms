<?php

namespace App\Http\Requests;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\EmailTemplateFormat;
use App\Models\EmailTemplate;
use App\Services\OrderVariableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'subject_template' => trim((string) $this->input('subject_template')),
            'is_hidden' => $this->boolean('is_hidden'),
            'attachment_1_type' => $this->input('attachment_1_type', EmailTemplateAttachmentType::None->value),
            'attachment_2_type' => $this->input('attachment_2_type', EmailTemplateAttachmentType::None->value),
        ]);
    }

    public function rules(): array
    {
        return [
            'email_template_id' => ['nullable', 'integer'],
            'email_account_id' => ['required', 'integer', Rule::exists('email_accounts', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'subject_template' => ['required', 'string', 'max:255'],
            'format' => ['required', Rule::enum(EmailTemplateFormat::class)],
            'body_template' => ['required', 'string', 'max:25000'],
            'is_hidden' => ['required', 'boolean'],
            'attachment_1_type' => ['required', Rule::enum(EmailTemplateAttachmentType::class)],
            'attachment_1_document_type' => [
                'exclude_unless:attachment_1_type,'.EmailTemplateAttachmentType::Document->value,
                'nullable',
                Rule::enum(EmailTemplateDocumentType::class),
            ],
            'attachment_1_file' => [
                'exclude_unless:attachment_1_type,'.EmailTemplateAttachmentType::Upload->value,
                'nullable',
                File::types($this->allowedFileTypes())->max('10mb'),
            ],
            'attachment_2_type' => ['required', Rule::enum(EmailTemplateAttachmentType::class)],
            'attachment_2_document_type' => [
                'exclude_unless:attachment_2_type,'.EmailTemplateAttachmentType::Document->value,
                'nullable',
                Rule::enum(EmailTemplateDocumentType::class),
            ],
            'attachment_2_file' => [
                'exclude_unless:attachment_2_type,'.EmailTemplateAttachmentType::Upload->value,
                'nullable',
                File::types($this->allowedFileTypes())->max('10mb'),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variables = app(OrderVariableService::class);

                $this->validateVariables(
                    $validator,
                    $variables,
                    'subject_template',
                    (string) $this->input('subject_template'),
                );
                $this->validateVariables(
                    $validator,
                    $variables,
                    'body_template',
                    (string) $this->input('body_template'),
                );

                foreach ([1, 2] as $slot) {
                    $this->validateAttachment($validator, $slot);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'email_account_id.required' => 'Wybierz konto e-mail dla szablonu.',
            'email_account_id.exists' => 'Wybrane konto e-mail nie istnieje.',
            'name.required' => 'Podaj nazwę wyświetlaną szablonu.',
            'subject_template.required' => 'Podaj temat wiadomości.',
            'format.required' => 'Wybierz format wiadomości.',
            'body_template.required' => 'Podaj treść wiadomości.',
            'attachment_1_file.max' => 'Załącznik 1 może mieć maksymalnie 10 MB.',
            'attachment_2_file.max' => 'Załącznik 2 może mieć maksymalnie 10 MB.',
            'attachment_1_file.mimes' => 'Załącznik 1 ma niedozwolony format pliku.',
            'attachment_2_file.mimes' => 'Załącznik 2 ma niedozwolony format pliku.',
        ];
    }

    /** @return array<string, mixed> */
    public function templateData(): array
    {
        return $this->safe()->only([
            'email_account_id',
            'name',
            'subject_template',
            'format',
            'body_template',
            'is_hidden',
        ]);
    }

    /**
     * @return array<int, array{
     *     type: EmailTemplateAttachmentType,
     *     document_type: ?EmailTemplateDocumentType,
     *     file: ?UploadedFile
     * }>
     */
    public function attachmentData(): array
    {
        $data = [];

        foreach ([1, 2] as $slot) {
            $data[$slot] = [
                'type' => EmailTemplateAttachmentType::from((string) $this->input("attachment_{$slot}_type")),
                'document_type' => EmailTemplateDocumentType::tryFrom(
                    (string) $this->input("attachment_{$slot}_document_type"),
                ),
                'file' => $this->file("attachment_{$slot}_file"),
            ];
        }

        return $data;
    }

    private function validateVariables(
        Validator $validator,
        OrderVariableService $variables,
        string $field,
        string $template,
    ): void {
        $unknown = $variables->unknownVariables($template);

        if ($unknown === []) {
            return;
        }

        $validator->errors()->add(
            $field,
            'Nieznane zmienne: '.implode(', ', $unknown).'.',
        );
    }

    private function validateAttachment(Validator $validator, int $slot): void
    {
        $type = EmailTemplateAttachmentType::tryFrom(
            (string) $this->input("attachment_{$slot}_type"),
        );

        if ($type === EmailTemplateAttachmentType::Document
            && EmailTemplateDocumentType::tryFrom((string) $this->input("attachment_{$slot}_document_type")) === null) {
            $validator->errors()->add(
                "attachment_{$slot}_document_type",
                "Wybierz dokument dla załącznika {$slot}.",
            );
        }

        if ($type !== EmailTemplateAttachmentType::Upload
            || $this->file("attachment_{$slot}_file") instanceof UploadedFile
            || $this->hasExistingUpload($slot)) {
            return;
        }

        $validator->errors()->add(
            "attachment_{$slot}_file",
            "Wybierz plik dla załącznika {$slot}.",
        );
    }

    private function hasExistingUpload(int $slot): bool
    {
        $template = $this->route('emailTemplate');

        if (! $template instanceof EmailTemplate) {
            return false;
        }

        return $template->attachments()
            ->where('slot', $slot)
            ->where('type', EmailTemplateAttachmentType::Upload->value)
            ->whereNotNull('file_path')
            ->exists();
    }

    /** @return list<string> */
    private function allowedFileTypes(): array
    {
        return [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'csv',
            'txt',
            'xml',
            'jpg',
            'jpeg',
            'png',
            'zip',
        ];
    }
}
