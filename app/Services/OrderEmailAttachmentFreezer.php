<?php

namespace App\Services;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\OrderEmailAttachmentKind;
use App\Enums\OrderEmailAttachmentSource;
use App\Exceptions\OrderEmailException;
use App\Models\EmailTemplateAttachment;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;

class OrderEmailAttachmentFreezer
{
    public function __construct(
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

        $message->attachments()->create([
            'position' => $attachment->slot,
            'kind' => OrderEmailAttachmentKind::Upload,
            'source_mode' => OrderEmailAttachmentSource::TemplateFile,
            'disk' => 'local',
            'file_path' => $sourcePath,
            'file_name' => $this->safeFileName($attachment->original_name, 'zalacznik'),
            'mime_type' => $attachment->mime_type ?: 'application/octet-stream',
            'file_size' => null,
            'checksum_sha256' => null,
            'source_snapshot' => [
                'email_template_attachment_id' => $attachment->getKey(),
                'email_template_id' => $attachment->email_template_id,
                'source_file_name' => $attachment->original_name,
            ],
        ]);
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

        if (! $invoice instanceof Invoice || blank($invoice->number)) {
            throw new OrderEmailException(
                'document_attachment_missing',
                'Nie można przygotować wiadomości, ponieważ wybrany dokument nie istnieje.',
            );
        }

        $message->attachments()->create([
            'position' => $attachment->slot,
            'kind' => $kind,
            'source_mode' => OrderEmailAttachmentSource::Document,
            'disk' => 'local',
            'file_path' => null,
            'file_name' => $this->invoicePdfFilenames->downloadName($invoice),
            'mime_type' => 'application/pdf',
            'file_size' => null,
            'checksum_sha256' => null,
            'source_snapshot' => [
                'invoice_id' => $invoice->getKey(),
                'document_type' => $invoice->document_type->value,
                'document_number' => $invoice->number,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
            ],
        ]);
    }

    private function safeFileName(?string $fileName, string $fallback): string
    {
        $fileName = basename(str_replace('\\', '/', trim((string) $fileName)));
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?? '';

        return $fileName !== '' ? $fileName : $fallback;
    }
}
