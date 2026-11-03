<?php

namespace Tests\Unit\Invoices;

use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicePdfFilenameGeneratorTest extends TestCase
{
    #[DataProvider('filenames')]
    public function test_document_kind_is_added_without_changing_the_document_number(
        InvoiceDocumentType $type,
        string $number,
        string $expected,
        string $legacy,
    ): void {
        $invoice = new Invoice(['document_type' => $type, 'number' => $number]);
        $names = new InvoicePdfFilenameGenerator;

        $this->assertSame($expected, $names->downloadName($invoice));
        $this->assertSame($legacy, $names->legacyDownloadName($invoice));
        $this->assertSame($number, $invoice->number);
        $this->assertDoesNotMatchRegularExpression('/[\\\\\/:*?"<>|\x00-\x1f\x7f]/', $names->downloadName($invoice));
    }

    public static function filenames(): array
    {
        return [
            'invoice' => [InvoiceDocumentType::Invoice, 'N 6/2026', 'Faktura N_6-2026.pdf', 'N_6-2026.pdf'],
            'correction' => [InvoiceDocumentType::Correction, 'NK 2/2026', 'Korekta NK_2-2026.pdf', 'NK_2-2026.pdf'],
            'proforma' => [InvoiceDocumentType::Proforma, 'PF 1/2026', 'Proforma PF_1-2026.pdf', 'Proforma_PF_1-2026.pdf'],
            'unsafe separators' => [InvoiceDocumentType::Invoice, 'N 6/2026:*?"<>|', 'Faktura N_6-2026.pdf', 'N_6-2026.pdf'],
        ];
    }
}
