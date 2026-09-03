<?php

namespace Modules\Invoices\Services;

use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Ksef\Services\KsefOfflineStandardPdfGuard;
use Throwable;

class InvoicePdfRenderer
{
    public function __construct(
        private readonly InvoicePdfViewModelFactory $viewModels,
        private readonly InvoicePdfFontResolver $fonts,
        private readonly KsefOfflineStandardPdfGuard $offlineGuard,
    ) {}

    public function render(Invoice $invoice): string
    {
        $this->offlineGuard->assertAllowed($invoice);

        return $this->renderDocuments(collect([$invoice]), (string) $invoice->number);
    }

    /** @param Collection<int, Invoice> $invoices */
    public function renderMany(Collection $invoices, InvoiceDocumentType $documentType): string
    {
        $metadata = $this->bulkMetadata($documentType);

        if ($invoices->isEmpty()) {
            throw new InvoiceDomainException(
                'invoice_bulk_pdf_empty',
                $metadata['empty_message'],
            );
        }

        $this->offlineGuard->assertManyAllowed($invoices);

        return $this->renderDocuments($invoices, $metadata['title']);
    }

    /** @return array{title: string, empty_message: string} */
    private function bulkMetadata(InvoiceDocumentType $documentType): array
    {
        return match ($documentType) {
            InvoiceDocumentType::Invoice => [
                'title' => 'Faktury zbiorcze',
                'empty_message' => 'Zaznacz co najmniej jedną fakturę do wydruku.',
            ],
            InvoiceDocumentType::Proforma => [
                'title' => 'Pro formy zbiorcze',
                'empty_message' => 'Zaznacz co najmniej jedną Pro formę do wydruku.',
            ],
            InvoiceDocumentType::Correction => [
                'title' => 'Korekty zbiorcze',
                'empty_message' => 'Zaznacz co najmniej jedną Korektę do wydruku.',
            ],
        };
    }

    /** @param Collection<int, Invoice> $invoices */
    private function renderDocuments(Collection $invoices, string $title): string
    {
        try {
            $pdf = $this->createPdf();
            $pdf->SetCreator('');
            $pdf->SetAuthor('');
            $pdf->SetTitle($title);
            $pdf->SetSubject('');
            $pdf->SetKeywords('');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(7, 12, 7);
            $pdf->SetAutoPageBreak(true, 12);
            $pdf->setFontSubsetting(true);
            $pdf->SetFont($this->fonts->body(), '', 8);

            foreach ($invoices as $invoice) {
                $pdf->AddPage('P', 'A4');
                $document = $this->viewModels->make($invoice);
                $pdf->writeHTML($this->htmlDocument($document), true, false, true, false, '');
                $this->writeKsefQr($pdf, $document);
            }

            return $pdf->Output('', 'S');
        } catch (InvoiceDomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvoiceDomainException(
                'invoice_pdf_generation_failed',
                'Nie udało się wygenerować pliku PDF dokumentu.',
                [],
                $exception,
            );
        }
    }

    public function html(Invoice $invoice): string
    {
        $this->offlineGuard->assertAllowed($invoice);

        return $this->htmlDocument($this->viewModels->make($invoice));
    }

    /** @param array<string, mixed> $document */
    private function htmlDocument(array $document): string
    {
        $view = match ($document['type']) {
            'invoice' => 'invoices.pdf.invoice',
            'proforma' => 'invoices.pdf.proforma',
            'correction' => 'invoices.pdf.correction',
            default => throw new InvoiceDomainException(
                'invoice_pdf_unsupported_document_type',
                'Ten typ dokumentu nie jest obsługiwany przez generator PDF.',
            ),
        };

        return view($view, [
            'document' => $document,
            'fonts' => [
                'heading' => $this->fonts->heading(),
                'body' => $this->fonts->body(),
            ],
        ])->render();
    }

    /** @param array<string, mixed> $document */
    protected function createPdf(): \TCPDF
    {
        return new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    }

    /** @param array<string, mixed> $document */
    private function writeKsefQr(\TCPDF $pdf, array $document): void
    {
        $url = $document['ksef']['verification_url'] ?? null;
        $number = $document['ksef']['number'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($number) || $number === '') {
            return;
        }

        $x = 8.0;
        $blockWidth = 35.0;
        $qrSize = 27.0;
        $blockHeight = 45.0;
        $y = $pdf->GetY() + 4.0;

        $movedToNewPage = ($y + $blockHeight) > ($pdf->getPageHeight() - 12.0);

        if ($movedToNewPage) {
            $pdf->AddPage('P', 'A4');
            $y = 12.0;

            $documentType = $document['type'] === InvoiceDocumentType::Correction->value
                ? 'Faktura korygująca'
                : 'Faktura VAT';
            $pdf->SetFont($this->fonts->body(), '', 9);
            $pdf->SetXY($x, $y);
            $pdf->Cell(120.0, 5.0, $documentType.' '.$document['number'], 0, 1, 'L');
            $pdf->SetX($x);
            $pdf->Cell(120.0, 5.0, 'Weryfikacja KSeF', 0, 1, 'L');
            $y = $pdf->GetY() + 2.0;
        }

        $pdf->SetFont($this->fonts->body(), '', 7);
        $pdf->SetXY($x, $y);
        $pdf->Cell($blockWidth, 4.0, 'Sprawdź w KSeF', 0, 1, 'C', false, $url);

        $qrX = $x + (($blockWidth - $qrSize) / 2);
        $qrY = $pdf->GetY() + 1.0;
        $this->writeQrCode($pdf, $url, $qrX, $qrY, $qrSize, [
            'border' => false,
            'padding' => 'auto',
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ]);
        $pdf->Link($qrX, $qrY, $qrSize, $qrSize, $url);

        $pdf->SetFont($this->fonts->body(), '', 6.5);
        $pdf->SetXY($x, $qrY + $qrSize + 1.0);
        $pdf->MultiCell($blockWidth, 3.0, $number, 0, 'C', false, 1, $x, '', true);
        $pdf->SetY(max($pdf->GetY(), $y + $blockHeight));
    }

    /** @param array<string, mixed> $style */
    protected function writeQrCode(
        \TCPDF $pdf,
        string $payload,
        float $x,
        float $y,
        float $size,
        array $style,
    ): void {
        $pdf->write2DBarcode($payload, 'QRCODE,M', $x, $y, $size, $size, $style);
    }
}
