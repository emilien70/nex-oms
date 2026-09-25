<?php

namespace App\Services;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateAttachment;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EmailTemplateManagementService
{
    /**
     * @param  array<string, mixed>  $templateData
     * @param  array<int, array{type: EmailTemplateAttachmentType, document_type: ?EmailTemplateDocumentType, file: ?UploadedFile}>  $attachments
     */
    public function create(array $templateData, array $attachments): EmailTemplate
    {
        $newPaths = [];

        try {
            return DB::transaction(function () use ($templateData, $attachments, &$newPaths): EmailTemplate {
                $template = EmailTemplate::query()->create($templateData);
                $this->syncAttachments($template, $attachments, $newPaths);

                return $template->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->deletePaths($newPaths);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $templateData
     * @param  array<int, array{type: EmailTemplateAttachmentType, document_type: ?EmailTemplateDocumentType, file: ?UploadedFile}>  $attachments
     */
    public function update(EmailTemplate $template, array $templateData, array $attachments): EmailTemplate
    {
        $newPaths = [];
        $obsoletePaths = [];

        try {
            $updated = DB::transaction(function () use (
                $template,
                $templateData,
                $attachments,
                &$newPaths,
                &$obsoletePaths,
            ): EmailTemplate {
                $managed = EmailTemplate::query()
                    ->whereKey($template->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $managed->update($templateData);
                $this->syncAttachments($managed, $attachments, $newPaths, $obsoletePaths);

                return $managed->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->deletePaths($newPaths);

            throw $exception;
        }

        $this->deletePaths($obsoletePaths, $template->getKey());

        return $updated;
    }

    public function duplicate(EmailTemplate $template): EmailTemplate
    {
        $newPaths = [];

        try {
            return DB::transaction(function () use ($template, &$newPaths): EmailTemplate {
                $template->loadMissing('attachments');
                $copy = EmailTemplate::query()->create([
                    'email_account_id' => $template->email_account_id,
                    'name' => 'Kopia - '.$template->name,
                    'subject_template' => $template->subject_template,
                    'format' => $template->format,
                    'body_template' => $template->body_template,
                    'is_hidden' => $template->is_hidden,
                ]);

                foreach ($template->attachments as $attachment) {
                    $attributes = $attachment->only([
                        'slot',
                        'type',
                        'document_type',
                        'original_name',
                        'mime_type',
                        'file_size',
                    ]);
                    $attributes['file_path'] = null;

                    if ($attachment->type === EmailTemplateAttachmentType::Upload) {
                        $attributes['file_path'] = $this->copyUpload($attachment, $copy);
                        $newPaths[] = $attributes['file_path'];
                    }

                    $copy->attachments()->create($attributes);
                }

                return $copy->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->deletePaths($newPaths);

            throw $exception;
        }
    }

    public function delete(EmailTemplate $template): void
    {
        $templateId = $template->getKey();

        DB::transaction(function () use ($templateId): void {
            EmailTemplate::query()
                ->whereKey($templateId)
                ->lockForUpdate()
                ->firstOrFail()
                ->delete();
        });

        Storage::disk('local')->deleteDirectory("email-templates/{$templateId}");
    }

    /**
     * @param  array<int, array{type: EmailTemplateAttachmentType, document_type: ?EmailTemplateDocumentType, file: ?UploadedFile}>  $attachments
     * @param  list<string>  $newPaths
     * @param  list<string>  $obsoletePaths
     */
    private function syncAttachments(
        EmailTemplate $template,
        array $attachments,
        array &$newPaths,
        array &$obsoletePaths = [],
    ): void {
        $existing = $template->attachments()->get()->keyBy('slot');

        foreach ([1, 2] as $slot) {
            $configuration = $attachments[$slot];
            /** @var EmailTemplateAttachment|null $current */
            $current = $existing->get($slot);

            if ($configuration['type'] === EmailTemplateAttachmentType::None) {
                $this->markObsolete($current, $obsoletePaths);
                $current?->delete();

                continue;
            }

            if ($configuration['type'] === EmailTemplateAttachmentType::Document) {
                $this->markObsolete($current, $obsoletePaths);
                $template->attachments()->updateOrCreate(
                    ['slot' => $slot],
                    [
                        'type' => EmailTemplateAttachmentType::Document,
                        'document_type' => $configuration['document_type'],
                        'file_path' => null,
                        'original_name' => null,
                        'mime_type' => null,
                        'file_size' => null,
                    ],
                );

                continue;
            }

            $file = $configuration['file'];

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("email-templates/{$template->getKey()}/attachments", 'local');

            if (! is_string($path) || $path === '') {
                throw new DomainException('Nie udało się zapisać załącznika szablonu e-mail.');
            }

            $newPaths[] = $path;
            $this->markObsolete($current, $obsoletePaths);
            $template->attachments()->updateOrCreate(
                ['slot' => $slot],
                [
                    'type' => EmailTemplateAttachmentType::Upload,
                    'document_type' => null,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ],
            );
        }
    }

    private function copyUpload(EmailTemplateAttachment $attachment, EmailTemplate $copy): string
    {
        if ($attachment->file_path === null || ! Storage::disk('local')->exists($attachment->file_path)) {
            throw new DomainException('Nie można skopiować szablonu, ponieważ jego załącznik nie istnieje.');
        }

        $extension = pathinfo($attachment->file_path, PATHINFO_EXTENSION);
        $filename = (string) Str::uuid().($extension === '' ? '' : '.'.$extension);
        $path = "email-templates/{$copy->getKey()}/attachments/{$filename}";

        if (! Storage::disk('local')->copy($attachment->file_path, $path)) {
            throw new DomainException('Nie udało się skopiować załącznika szablonu e-mail.');
        }

        return $path;
    }

    /** @param list<string> $obsoletePaths */
    private function markObsolete(?EmailTemplateAttachment $attachment, array &$obsoletePaths): void
    {
        if ($attachment?->file_path !== null) {
            $obsoletePaths[] = $attachment->file_path;
        }
    }

    /** @param list<string> $paths */
    private function deletePaths(array $paths, ?int $templateId = null): void
    {
        $prefix = $templateId === null
            ? 'email-templates/'
            : "email-templates/{$templateId}/attachments/";

        $ownedPaths = array_values(array_filter(
            array_unique($paths),
            static fn (mixed $path): bool => is_string($path)
                && $path !== ''
                && str_starts_with($path, $prefix),
        ));

        if ($ownedPaths !== []) {
            Storage::disk('local')->delete($ownedPaths);
        }
    }
}
