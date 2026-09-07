<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\ProformaService;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionDocumentGenerator;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class KsefOfflineTechnicalCorrectionDocumentGeneratorTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_it_dispatches_vat_to_the_authoritative_invoice_generator(): void
    {
        $this->ksefSettings();
        $invoice = app(InvoiceFinalizationService::class)->finalize($this->issueKsefRoot());

        $generated = $this->generator()->generate($invoice, $this->generatedAt());
        $xpath = $this->ksefXpath($generated->xml);

        $this->assertSame('VAT', $this->ksefValue($xpath, '//fa:Fa/fa:RodzajFaktury'));
        $this->assertSame('FA (3) 1-0E', $generated->schemaId);
        Http::assertNothingSent();
    }

    public function test_it_dispatches_kor_to_the_existing_authoritative_correction_generator(): void
    {
        $this->ksefSettings();
        $root = app(InvoiceFinalizationService::class)->finalize($this->issueKsefRoot());
        $rootSubmission = $this->acceptKsefDocument($root);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));

        $generated = $this->generator()->generate($correction, $this->generatedAt());
        $xpath = $this->ksefXpath($generated->xml);

        $this->assertSame('KOR', $this->ksefValue($xpath, '//fa:Fa/fa:RodzajFaktury'));
        $this->assertSame('1', $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrKSeF'));
        $this->assertSame(
            $rootSubmission->ksef_number,
            $this->ksefValue($xpath, '//fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej'),
        );
        Http::assertNothingSent();
    }

    public function test_it_rejects_proforma_without_generator_fallback(): void
    {
        $this->ksefSettings();
        $order = $this->createDocumentOrder(['external_id' => 'KSEF-R2B-PROFORMA']);
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $order,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;

        try {
            $this->generator()->generate($proforma, $this->generatedAt());
            $this->fail('Pro forma should not be supported by the technical correction generator.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_technical_correction_document_type_not_supported', $exception->safeCode);
        }

        Http::assertNothingSent();
    }

    private function generator(): KsefOfflineTechnicalCorrectionDocumentGenerator
    {
        return app(KsefOfflineTechnicalCorrectionDocumentGenerator::class);
    }

    private function generatedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-07T10:11:12Z');
    }
}
