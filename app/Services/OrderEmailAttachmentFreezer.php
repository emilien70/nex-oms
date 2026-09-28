<?php

namespace App\Services;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\OrderEmailAttachmentKind;
use App\Exceptions\OrderEmailException;
use App\Models\EmailTemplateAttachment;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfService;

class OrderEmailAttachmentFreezer
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly InvoicePdfService $invoicePdfs,
        private readonly InvoicePdfFilenameGenerator $invoicePdfFilenames,
    ) {}

    public function freeze(OrderEmailMessage $message, Order $order, EmailTemplateAttachment $attachment): void
    {
        if ($attachment->type === EmailTemplateAttachmentType::Upload) {
            $this->freezeUpload($message, $attachment);

            return;
        }

        if ($attachment->type === EmailTemplateAttachmentType::Document) {
            $this->freezeDocument($message, $order, $attachment);
        }
    }

    public function deleteMessageDirectory(int $messageId): void
    {
        Storage::disk('local')->deleteDirectory("order-email-messages/{$messageId}");
    }

    private function freezeUpload(OrderEmailMessage $message, EmailTemplateAttachment $attachment): void
    {
        $sourcePath = $attachment->file_path;
        $expectedPrefix = "email-templates/{$attachment->email_template_id}/attachments/";

        if (! is_string($sourcePath)
            || ! str_starts_with($sourcePath, $expectedPrefix)
            || ! Storage::disk('local')->exists($sourcePath)) {
            throw new OrderEmailException(
                'attachment_missing',
                'Nie można przygotować wiadomości, ponieważ załącznik szablonu nie istnieje.',
            );
        }

        $contents = Storage::disk('local')->get($sourcePath);
        $this->store(
            message: $message,
            position: $attachment->slot,
            kind: OrderEmailAttachmentKind::Upload,
            contents: $contents,
            fileName: $this->safeFileName($attachment->original_name, 'zalacznik'),
            sourceSnapshot: [
                'email_template_attachment_id' => $attachment->getKey(),
                'email_template_id' => $attachment->email_template_id,
                'source_file_name' => $attachment->original_name,
            ],
        );
    }

    private function freezeDocument(
        OrderEmailMessage $message,
        Order $order,
        EmailTemplateAttachment $attachment,
    ): void {
        $documentType = $attachment->document_type;

        if (! $documentType instanceof EmailTemplateDocumentType) {
            throw new OrderEmailException(
                'document_attachment_invalid',
                'Konfiguracja załącznika dokumentu jest nieprawidłowa.',
            );
        }

        [$invoiceType, $kind] = match ($documentType) {
            EmailTemplateDocumentType::InvoicePdf => [
                InvoiceDocumentType::Invoice,
                OrderEmailAttachmentKind::InvoicePdf,
            ],
            EmailTemplateDocumentType::ProformaPdf => [
                InvoiceDocumentType::Proforma,
                OrderEmailAttachmentKind::ProformaPdf,
            ],
            EmailTemplateDocumentType::LatestCorrectionPdf => [
                InvoiceDocumentType::Correction,
                OrderEmailAttachmentKind::LatestCorrectionPdf,
            ],
        };

        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('document_type', $invoiceType->value)
            ->where('status', InvoiceDocumentStatus::Issued->value)
            ->whereNotNull('number')
            ->latest('id')
            ->first();

        if (! $invoice instanceof Invoice) {
            throw new OrderEmailException(
                'document_attachment_missing',
                'Nie można przygotować wiadomości, ponieważ wybrany dokument nie istnieje.',
            );
        }

        $contents = $this->invoicePdfs->contents($invoice);
        $this->store(
            message: $message,
            position: $attachment->slot,
            kind: $kind,
            contents: $contents,
            fileName: $this->invoicePdfFilenames->downloadName($invoice),
            sourceSnapshot: [
                'invoice_id' => $invoice->getKey(),
                'document_type' => $invoice->document_type->value,
                'document_number' => $invoice->number,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
            ],
        );
    }

    /** @param array<string, mixed> $sourceSnapshot */
    private function store(
        OrderEmailMessage $message,
        int $position,
        OrderEmailAttachmentKind $kind,
        string $contents,
        string $fileName,
        array $sourceSnapshot,
    ): void {
        $size = strlen($contents);

        if ($size === 0 || $size > self::MAX_FILE_SIZE) {
            throw new OrderEmailException(
                'attachment_size_invalid',
                'Załącznik wiadomości jest pusty albo przekracza limit 10 MB.',
            );
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $storedName = (string) Str::uuid().($extension === '' ? '' : '.'.mb_strtolower($extension));
        $path = "order-email-messages/{$message->getKey()}/attachments/{$storedName}";

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new OrderEmailException(
                'attachment_storage_failed',
                'Nie udało się bezpiecznie zapisać załącznika wiadomości.',
            );
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $message->attachments()->create([
            'position' => $position,
            'kind' => $kind,
            'disk' => 'local',
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => is_string($detectedMime) && $detectedMime !== ''
                ? $detectedMime
                : 'application/octet-stream',
            'file_size' => $size,
            'checksum_sha256' => hash('sha256', $contents),
            'source_snapshot' => $sourceSnapshot,
        ]);
    }

    private function safeFileName(?string $fileName, string $fallback): string
    {
        $fileName = basename(str_replace('\\', '/', trim((string) $fileName)));
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?? '';

        return $fileName !== '' ? $fileName : $fallback;
    }
}
