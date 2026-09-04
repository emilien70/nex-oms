<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Modules\Invoices\Services\InvoiceAmountInWordsFormatter;
use Modules\Invoices\Services\InvoicePdfFontResolver;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\ValueObjects\KsefOfflinePresentationData;
use Throwable;

class KsefOfflinePresentationPdfRenderer
{
    public function __construct(
        private readonly InvoicePdfFontResolver $fonts,
        private readonly InvoiceAmountInWordsFormatter $amountInWords,
    ) {}

    public function renderOfflineInvoice(KsefOfflinePresentationData $document): string
    {
        return $this->render(
            $this->offlineInvoiceHtml($document),
            'Faktura Offline '.$document->invoiceNumber,
            $this->offlineInvoiceQrBlocks($document),
        );
    }

    public function renderTransactionConfirmation(KsefOfflinePresentationData $document): string
    {
        return $this->render(
            $this->transactionConfirmationHtml($document),
            'Potwierdzenie transakcji '.$document->invoiceNumber,
            $this->transactionConfirmationQrBlocks($document),
        );
    }

    public function renderAcceptedOfflineInvoice(
        KsefOfflinePresentationData $document,
        string $ksefNumber,
    ): string {
        return $this->render(
            $this->offlineInvoiceHtml($document),
            'Faktura KSeF '.$document->invoiceNumber,
            $this->acceptedOfflineInvoiceQrBlocks($document, $ksefNumber),
            1,
        );
    }

    public function offlineInvoiceHtml(KsefOfflinePresentationData $document): string
    {
        return view('invoices.pdf.ksef-offline-invoice', [
            'document' => $this->viewData($document),
            'fonts' => $this->fontData(),
        ])->render();
    }

    public function transactionConfirmationHtml(KsefOfflinePresentationData $document): string
    {
        return view('invoices.pdf.ksef-transaction-confirmation', [
            'document' => $this->viewData($document),
            'fonts' => $this->fontData(),
        ])->render();
    }

    /** @return list<array{heading: string, payload: string, label: ?string}> */
    public function offlineInvoiceQrBlocks(KsefOfflinePresentationData $document): array
    {
        return [
            [
                'heading' => 'KOD I',
                'payload' => $document->invoiceVerificationUrl,
                'label' => 'OFFLINE',
            ],
            [
                'heading' => 'KOD II',
                'payload' => $document->certificateVerificationUrl,
                'label' => 'CERTYFIKAT',
            ],
        ];
    }

    /** @return list<array{heading: string, payload: string, label: ?string}> */
    public function transactionConfirmationQrBlocks(KsefOfflinePresentationData $document): array
    {
        return [
            [
                'heading' => 'sprawdź fakturę w KSeF',
                'payload' => $document->invoiceVerificationUrl,
                'label' => null,
            ],
            [
                'heading' => 'zweryfikuj wystawcę faktury',
                'payload' => $document->certificateVerificationUrl,
                'label' => null,
            ],
        ];
    }

    /** @return list<array{heading: string, payload: string, label: ?string}> */
    public function acceptedOfflineInvoiceQrBlocks(
        KsefOfflinePresentationData $document,
        string $ksefNumber,
    ): array {
        return [[
            'heading' => 'KOD I',
            'payload' => $document->invoiceVerificationUrl,
            'label' => $ksefNumber,
        ]];
    }

    /** @param list<array{heading: string, payload: string, label: ?string}> $qrBlocks */
    private function render(
        string $html,
        string $title,
        array $qrBlocks,
        int $requiredQrCount = 2,
    ): string {
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
            $pdf->AddPage('P', 'A4');
            $pdf->writeHTML($html, true, false, true, false, '');
            $this->writeQrBlocks($pdf, $qrBlocks, $requiredQrCount);

            return $pdf->Output('', 'S');
        } catch (KsefApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KsefApiException(
                'Nie udało się wygenerować dokumentu PDF dla wystawienia Offline.',
                'ksef_offline_presentation_pdf_generation_failed',
            );
        }
    }

    /** @param list<array{heading: string, payload: string, label: ?string}> $blocks */
    private function writeQrBlocks(\TCPDF $pdf, array $blocks, int $requiredQrCount): void
    {
        if (count($blocks) !== $requiredQrCount || ! in_array($requiredQrCount, [1, 2], true)) {
            throw new KsefApiException(
                'Dokument Offline zawiera nieprawidłową liczbę kodów weryfikacyjnych.',
                'ksef_offline_presentation_integrity_invalid',
            );
        }

        $qrSize = 36.0;
        $blockWidth = 78.0;
        $blockHeight = 53.0;
        $y = $pdf->GetY() + 7.0;

        if (($y + $blockHeight) > ($pdf->getPageHeight() - 12.0)) {
            $pdf->AddPage('P', 'A4');
            $y = 15.0;
        }

        foreach ($blocks as $index => $block) {
            $x = $requiredQrCount === 1
                ? 66.0
                : ($index === 0 ? 18.0 : 114.0);
            $pdf->SetFont($this->fonts->body(), '', 8);
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($blockWidth, 5.0, $block['heading'], 0, 'C', false, 1, $x, $y, true);
            $qrX = $x + (($blockWidth - $qrSize) / 2);
            $qrY = $pdf->GetY() + 1.0;
            $this->writeQrCode($pdf, $block['payload'], $qrX, $qrY, $qrSize, [
                'border' => false,
                'padding' => 'auto',
                'fgcolor' => [0, 0, 0],
                'bgcolor' => [255, 255, 255],
            ]);
            $pdf->Link($qrX, $qrY, $qrSize, $qrSize, $block['payload']);

            if ($block['label'] !== null) {
                $pdf->SetFont($this->fonts->body(), 'B', 8);
                $pdf->SetXY($x, $qrY + $qrSize + 1.0);
                $pdf->Cell($blockWidth, 4.0, $block['label'], 0, 0, 'C');
            }
        }

        $pdf->SetY($y + $blockHeight);
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

    protected function createPdf(): \TCPDF
    {
        return new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    }

    /** @return array<string, mixed> */
    private function viewData(KsefOfflinePresentationData $document): array
    {
        $buyerIdentity = $document->buyer['identity_value'] !== null
            ? $document->buyer['identity_label'].': '.$document->buyer['identity_value']
            : 'Brak identyfikatora podatkowego';

        return [
            'test_mark' => $document->testMark(),
            'seller' => [
                ...$document->seller,
                'lines' => [
                    $document->seller['name'],
                    ...$document->seller['address'],
                    'NIP: '.$document->seller['nip'],
                ],
            ],
            'buyer' => [
                ...$document->buyer,
                'lines' => [
                    $document->buyer['name'],
                    ...$document->buyer['address'],
                    $buyerIdentity,
                ],
            ],
            'number' => $document->invoiceNumber,
            'issue_date' => $this->displayDate($document->issueDate),
            'sale_date' => $document->saleDate !== null ? $this->displayDate($document->saleDate) : null,
            'place_of_issue' => $document->placeOfIssue,
            'currency' => $document->currency,
            'total_net' => $document->totalNet,
            'total_vat' => $document->totalVat,
            'total_gross' => $document->totalGross,
            'amount_in_words' => $this->amountInWords->format($document->totalGross, $document->currency),
            'lines' => $document->lines,
            'tax_rows' => $document->taxRows,
            'additional_descriptions' => $document->additionalDescriptions,
            'payment' => $document->payment,
            'order_number' => $document->orderNumber,
        ];
    }

    /** @return array{heading: string, body: string} */
    private function fontData(): array
    {
        return [
            'heading' => $this->fonts->heading(),
            'body' => $this->fonts->body(),
        ];
    }

    private function displayDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Warsaw')->format('d.m.Y');
    }
}
