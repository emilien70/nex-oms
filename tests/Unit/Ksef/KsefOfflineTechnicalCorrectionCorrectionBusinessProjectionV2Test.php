<?php

namespace Tests\Unit\Ksef;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionDocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionFinancialEvidencePayloadValidator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionBusinessFingerprintService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

final class KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2Test extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_frozen_correction_and_kor_payload_have_the_same_v2_projection(): void
    {
        [$correction, $xml] = $this->correctionAndXml();
        $projection = app(KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2::class);

        $this->assertSame(
            $projection->projectInvoice($correction, KsefEnvironment::Test),
            $projection->projectPayload($xml),
        );
        $fingerprints = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $this->assertSame(
            $fingerprints->fromInvoice($correction, 2, KsefEnvironment::Test),
            $fingerprints->fromPayload($xml, 2),
        );
        $this->assertSame(44, strlen($fingerprints->fromPayload($xml, 2)));
        Http::assertNothingSent();
    }

    public function test_generated_at_is_excluded_while_business_fields_remain_protected(): void
    {
        [$correction, $first] = $this->correctionAndXml();
        $second = app(KsefFa3CorrectionDocumentGenerator::class)->generate(
            $correction,
            CarbonImmutable::parse('2026-09-07T11:12:13Z'),
            KsefFa3EligibilityMode::Authoritative,
        )->xml;
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);

        $this->assertNotSame(hash('sha256', $first), hash('sha256', $second));
        $this->assertSame($service->fromPayload($first, 2), $service->fromPayload($second, 2));
        $this->assertSame(
            $service->fromInvoice($correction, 2, KsefEnvironment::Test),
            $service->fromPayload($second, 2),
        );
        Http::assertNothingSent();
    }

    #[DataProvider('businessTampering')]
    public function test_each_material_kor_business_change_changes_the_v2_fingerprint(
        string $expression,
        string $replacement,
    ): void {
        [, $xml] = $this->correctionAndXml(buyerChange: true);
        $tampered = $this->replaceValue($xml, $expression, $replacement);
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);

        $this->assertNotSame(
            $service->fromPayload($xml, 2),
            $service->fromPayload($tampered, 2),
        );
        Http::assertNothingSent();
    }

    public static function businessTampering(): array
    {
        return [
            'issue date' => ['/fa:Faktura/fa:Fa/fa:P_1', '2026-08-22'],
            'number' => ['/fa:Faktura/fa:Fa/fa:P_2', 'KOR-TAMPERED'],
            'reason' => ['/fa:Faktura/fa:Fa/fa:PrzyczynaKorekty', 'Synthetic changed reason'],
            'source KSeF number' => ['/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:NrKSeFFaKorygowanej', '9876543210-20260819-000000000099-03'],
            'source invoice number' => ['/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:NrFaKorygowanej', 'FV-SOURCE-TAMPERED'],
            'source invoice date' => ['/fa:Faktura/fa:Fa/fa:DaneFaKorygowanej/fa:DataWystFaKorygowanej', '2026-08-19'],
            'buyer after' => ['/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa', 'Changed buyer after'],
            'buyer before' => ['/fa:Faktura/fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:Nazwa', 'Changed buyer before'],
            'before line name' => ['/fa:Faktura/fa:Fa/fa:FaWiersz[fa:StanPrzed]/fa:P_7', 'Changed before line'],
            'after line quantity' => ['/fa:Faktura/fa:Fa/fa:FaWiersz[not(fa:StanPrzed)]/fa:P_8B', '3'],
            'tax bucket' => ['/fa:Faktura/fa:Fa/fa:P_13_1', '101.00'],
            'gross total' => ['/fa:Faktura/fa:Fa/fa:P_15', '247.00'],
        ];
    }

    public function test_foreign_currency_projection_uses_frozen_pln_vat_without_http(): void
    {
        [$correction, $xml, $evidence] = $this->correctionAndXml(foreign: true);
        $projection = app(KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2::class);

        $this->assertSame(
            $projection->projectInvoice($correction, KsefEnvironment::Test),
            $projection->projectPayload($xml),
        );
        $this->assertSame(
            $evidence['tax_buckets']['standard_1']['pln_vat'],
            data_get($projection->projectPayload($xml), 'invoice.tax_buckets.standard_1.pln_vat'),
        );
        app(KsefFa3CorrectionFinancialEvidencePayloadValidator::class)->validate($xml, $evidence);
        Http::assertNothingSent();
    }

    public function test_versions_are_document_specific_and_swaps_fail_closed(): void
    {
        [$correction, $korXml] = $this->correctionAndXml();
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $invoice = $correction->correctedInvoice;

        $this->assertTrue($service->supportsVersion(1));
        $this->assertTrue($service->supportsVersion(2));
        $this->assertFalse($service->supportsVersion(0));
        $this->assertFalse($service->supportsVersion(3));
        $this->assertSame(1, $service->versionFor($invoice));
        $this->assertSame(2, $service->versionFor($correction));
        foreach ([
            fn () => $service->fromInvoice($invoice, 2, KsefEnvironment::Test),
            fn () => $service->fromInvoice($correction, 1, KsefEnvironment::Test),
            fn () => $service->fromPayload($korXml, 1),
            fn () => $service->fromPayload($korXml, 3),
            fn () => $service->versionFor(new Invoice(['document_type' => InvoiceDocumentType::Proforma])),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Version/type mismatch must fail closed.');
            } catch (KsefApiException $exception) {
                $this->assertContains($exception->safeCode, [
                    'ksef_technical_correction_business_projection_invalid',
                    'ksef_technical_correction_business_fingerprint_version_unsupported',
                    'ksef_technical_correction_document_type_not_supported',
                ]);
            }
        }
        Http::assertNothingSent();
    }

    public function test_doctype_duplicate_business_nodes_and_unknown_structure_fail_closed(): void
    {
        [, $xml] = $this->correctionAndXml();
        $service = app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $payloads = [
            '<!DOCTYPE Faktura [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'.$xml,
            str_replace('</P_2>', '</P_2><P_2 xmlns="'.KsefFa3XmlBuilder::NAMESPACE.'">DUPLICATE</P_2>', $xml),
            str_replace('</Fa>', '<Unexpected xmlns="'.KsefFa3XmlBuilder::NAMESPACE.'">x</Unexpected></Fa>', $xml),
        ];

        foreach ($payloads as $payload) {
            try {
                $service->fromPayload($payload, 2);
                $this->fail('Unsafe or ambiguous KOR XML must fail closed.');
            } catch (KsefApiException $exception) {
                $this->assertSame('ksef_technical_correction_business_projection_invalid', $exception->safeCode);
            }
        }
        Http::assertNothingSent();
    }

    /** @return array{0: Invoice, 1: string, 2: array<string, mixed>} */
    private function correctionAndXml(bool $buyerChange = false, bool $foreign = false): array
    {
        $settings = $this->ksefSettings(KsefEnvironment::Test);
        $settings->forceFill(['is_active' => true, 'context_nip' => '9876543210'])->save();
        $root = $this->issueKsefRoot();
        if ($foreign) {
            $root = $this->makeKsefForeign($root);
        }
        $root = app(InvoiceFinalizationService::class)->finalize($root);
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $items = $this->submittedKsefItems($root);
        $items[0]['quantity'] = 2;
        $buyer = $root->buyer_snapshot;
        $buyer['company_name'] = 'Changed Buyer Sp. z o.o.';
        $correction = $this->issueKsefCorrection(
            $root,
            $items,
            $buyerChange ? $buyer : null,
        );
        $correction = $this->finalizeKsefCorrection($correction);
        $generated = app(KsefFa3CorrectionDocumentGenerator::class)->generate(
            $correction,
            CarbonImmutable::parse('2026-09-07T10:11:12Z'),
            KsefFa3EligibilityMode::Authoritative,
        );

        return [
            $correction->fresh(['items', 'correctedInvoice']),
            $generated->xml,
            $generated->integrityEvidence,
        ];
    }

    private function replaceValue(string $xml, string $expression, string $replacement): string
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $expression);
        $nodes->item(0)->nodeValue = $replacement;
        $result = $document->saveXML();
        $this->assertIsString($result);

        return $result;
    }
}
