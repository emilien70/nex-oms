<?php

namespace App\Services;

use App\Enums\OrderEmailAttachmentKind;
use App\Enums\OrderEmailAttachmentSource;
use App\Exceptions\OrderEmailException;
use App\Models\OrderEmailMessage;
use App\Models\OrderEmailMessageAttachment;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfService;
use Throwable;

class OrderEmailAttachmentResolver
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(private readonly InvoicePdfService $pdfs) {}

    /** @return array{contents: string, name: string, mime: string} */
    public function resolve(OrderEmailMessage $message, OrderEmailMessageAttachment $attachment): array
    {
        if ($attachment->order_email_message_id !== $message->getKey()) {
            throw new \RuntimeException('Email attachment does not belong to this message.');
        }

        if ($attachment->source_mode === null) {
            $contents = Storage::disk($attachment->disk)->get($attachment->file_path);
            if (strlen($contents) !== $attachment->file_size
                || ! hash_equals($attachment->checksum_sha256, hash('sha256', $contents))) {
                throw new \RuntimeException('Attachment snapshot mismatch.');
            }

            return ['contents' => $contents, 'name' => $attachment->file_name, 'mime' => $attachment->mime_type];
        }

        if ($attachment->disk !== 'local'
            || $attachment->file_size !== null
            || $attachment->checksum_sha256 !== null) {
            throw new \RuntimeException('Invalid email attachment reference.');
        }

        $contents = match ($attachment->source_mode) {
            OrderEmailAttachmentSource::Document => $this->document($message, $attachment),
            OrderEmailAttachmentSource::TemplateFile => $this->templateFile($attachment),
        };
        if ($contents === '' || strlen($contents) > self::MAX_FILE_SIZE) {
            throw new OrderEmailException('attachment_size_invalid', 'Załącznik wiadomości jest pusty albo przekracza limit 10 MB.');
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return [
            'contents' => $contents,
            'name' => $attachment->file_name,
            'mime' => $attachment->source_mode === OrderEmailAttachmentSource::Document
                ? 'application/pdf'
                : (is_string($detectedMime) && $detectedMime !== '' ? $detectedMime : $attachment->mime_type),
        ];
    }

    private function document(OrderEmailMessage $message, OrderEmailMessageAttachment $attachment): string
    {
        $source = $attachment->source_snapshot;
        $type = match ($attachment->kind) {
            OrderEmailAttachmentKind::InvoicePdf => InvoiceDocumentType::Invoice,
            OrderEmailAttachmentKind::ProformaPdf => InvoiceDocumentType::Proforma,
            OrderEmailAttachmentKind::LatestCorrectionPdf => InvoiceDocumentType::Correction,
            default => null,
        };
        if ($attachment->file_path !== null || $type === null
            || ! is_array($source) || ! is_int($source['invoice_id'] ?? null)
            || $source['invoice_id'] < 1 || ($source['document_type'] ?? null) !== $type->value
            || ! is_string($source['document_number'] ?? null)) {
            throw new \RuntimeException('Invalid document attachment reference.');
        }

        $invoice = Invoice::query()
            ->whereKey($source['invoice_id'])
            ->where('order_id', $message->order_id)
            ->where('document_type', $type->value)
            ->where('status', InvoiceDocumentStatus::Issued->value)
            ->first();
        if (! $invoice || blank($invoice->number) || $invoice->number !== $source['document_number']) {
            throw new OrderEmailException('document_attachment_missing', 'Nie można wysłać wiadomości, ponieważ wskazany dokument nie jest już dostępny.');
        }

        try {
            $contents = $this->pdfs->contents($invoice);
        } catch (InvoiceDomainException $exception) {
            throw new OrderEmailException('document_attachment_unavailable', 'Nie można przygotować pliku PDF wskazanego dokumentu.', $exception);
        }
        if (! str_starts_with($contents, '%PDF-')) {
            throw new OrderEmailException('document_attachment_unavailable', 'Nie można przygotować pliku PDF wskazanego dokumentu.');
        }

        return $contents;
    }

    private function templateFile(OrderEmailMessageAttachment $attachment): string
    {
        $source = $attachment->source_snapshot;
        if ($attachment->kind !== OrderEmailAttachmentKind::Upload
            || ! is_array($source) || ! is_int($source['email_template_id'] ?? null)
            || $source['email_template_id'] < 1 || ! is_string($attachment->file_path)) {
            throw new \RuntimeException('Invalid template attachment reference.');
        }
        $directory = "email-templates/{$source['email_template_id']}/attachments";
        if (dirname($attachment->file_path) !== $directory
            || str_contains($attachment->file_path, '\\') || str_contains($attachment->file_path, "\0")) {
            throw new \RuntimeException('Invalid template attachment path.');
        }

        try {
            $stream = Storage::disk('local')->readStream($attachment->file_path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Template attachment is unavailable.');
            }
            try {
                $contents = stream_get_contents($stream, self::MAX_FILE_SIZE + 1);
            } finally {
                fclose($stream);
            }
            if (! is_string($contents)) {
                throw new \RuntimeException('Template attachment cannot be read.');
            }
        } catch (Throwable $exception) {
            throw new OrderEmailException('attachment_missing', 'Nie można wysłać wiadomości, ponieważ plik źródłowy załącznika nie jest już dostępny.', $exception);
        }

        return $contents;
    }
}
