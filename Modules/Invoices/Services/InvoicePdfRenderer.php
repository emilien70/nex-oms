<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Throwable;

class InvoicePdfRenderer
{
    public function __construct(
        private readonly InvoicePdfViewModelFactory $viewModels,
        private readonly InvoicePdfFontResolver $fonts,
    ) {}

    public function render(Invoice $invoice): string
    {
        try {
            $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('');
            $pdf->SetAuthor('');
            $pdf->SetTitle((string) $invoice->number);
            $pdf->SetSubject('');
            $pdf->SetKeywords('');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(7, 12, 7);
            $pdf->SetAutoPageBreak(true, 12);
            $pdf->setFontSubsetting(true);
            $pdf->SetFont($this->fonts->body(), '', 8);
            $pdf->AddPage('P', 'A4');
            $pdf->writeHTML($this->html($invoice), true, false, true, false, '');

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
        $data = $this->viewModels->make($invoice);
        $view = match ($data['type']) {
            'invoice' => 'invoices.pdf.invoice',
            'proforma' => 'invoices.pdf.proforma',
            'correction' => 'invoices.pdf.correction',
            default => throw new InvoiceDomainException(
                'invoice_pdf_unsupported_document_type',
                'Ten typ dokumentu nie jest obsługiwany przez generator PDF.',
            ),
        };

        return view($view, [
            'document' => $data,
            'fonts' => [
                'heading' => $this->fonts->heading(),
                'body' => $this->fonts->body(),
            ],
        ])->render();
    }
}
