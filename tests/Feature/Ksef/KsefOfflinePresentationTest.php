<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Closure;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceBulkPdfService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfService;
use Modules\Ksef\Enums\KsefContextIdentifierType;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefOfflineBuyerClassification;
use Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineCertificateSelection;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefInvoiceVerificationLinkBuilder;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefOfflineCertificateVerificationLinkBuilder;
use Modules\Ksef\Services\KsefOfflineDeliveryPolicy;
use Modules\Ksef\Services\KsefOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Modules\Ksef\Services\KsefOfflinePresentationDataExtractor;
use Modules\Ksef\Services\KsefOfflinePresentationPdfRenderer;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefTransactionConfirmationPdfService;
use Modules\Ksef\ValueObjects\KsefContextIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\TestCase;

class KsefOfflinePresentationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $offlineFixture;

    private CarbonImmutable $testNow;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config()->set('ksef.invoice_submission_enabled', true);
        $this->offlineFixture = KsefCertificateFixtureFactory::offlineEc();
        $this->testNow = CarbonImmutable::createFromTimestamp(
            $this->offlineFixture['valid_from'],
            'UTC',
        )->addHour();
        $this->travelTo($this->testNow);
    }

    public function test_polish_nip_allows_only_transaction_confirmation_from_frozen_xml(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $issuanceBeforePresentation = DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->first();
        $presentation = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);
        $policy = app(KsefOfflineDeliveryPolicy::class);

        $this->assertSame(KsefOfflineBuyerClassification::DomesticPlNip, $presentation->buyerClassification);
        $this->assertSame(KsefOfflineDeliveryDocumentType::TransactionConfirmation, $policy->primaryDocument($issuance));
        $this->assertSame('NEX Seller sp. z o.o.', $presentation->seller['name']);
        $this->assertSame('9876543210', $presentation->seller['nip']);
        $this->assertSame('Kowalski Handel', $presentation->buyer['name']);
        $this->assertSame('5260250995', $presentation->buyer['identity_value']);
        $this->assertSame($invoice->number, $presentation->invoiceNumber);
        $this->assertSame('123.00', $presentation->totalGross);

        $renderer = app(KsefOfflinePresentationPdfRenderer::class);
        $html = $renderer->transactionConfirmationHtml($presentation);
        $blocks = $renderer->transactionConfirmationQrBlocks($presentation);
        $document = app(KsefTransactionConfirmationPdfService::class)->document($issuance);

        $this->assertStringContainsString('POTWIERDZENIE TRANSAKCJI', $html);
        $this->assertStringContainsString('Ten dokument nie jest fakturą.', $html);
        $this->assertStringNotContainsString('Produkt testowy', $html);
        $this->assertSame([
            ['heading' => 'sprawdź fakturę w KSeF', 'payload' => $issuance->invoice_verification_url, 'label' => null],
            ['heading' => 'zweryfikuj wystawcę faktury', 'payload' => $issuance->certificate_verification_url, 'label' => null],
        ], $blocks);
        $this->assertStringStartsWith('%PDF-', $document['contents']);

        $response = $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$invoice, $issuance]));
        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $issuance]))
            ->assertForbidden();
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('POBIERZ POTWIERDZENIE TRANSAKCJI')
            ->assertDontSee('POBIERZ FAKTURĘ OFFLINE')
            ->assertSee('Faktura zostanie udostępniona nabywcy przez KSeF po jej przesłaniu do systemu.');
        $this->assertEquals(
            $issuanceBeforePresentation,
            DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->first(),
        );
        Http::assertNothingSent();
    }

    public function test_eu_vat_buyer_allows_only_full_offline_invoice_with_exact_qr_contract(): void
    {
        [$invoice, $issuance] = $this->issueOffline(
            KsefEnvironment::Demo,
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
        );
        $presentation = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);
        $policy = app(KsefOfflineDeliveryPolicy::class);
        $renderer = app(KsefOfflinePresentationPdfRenderer::class);
        $html = $renderer->offlineInvoiceHtml($presentation);
        $blocks = $renderer->offlineInvoiceQrBlocks($presentation);
        $document = app(KsefOfflineInvoicePdfService::class)->document($issuance);

        $this->assertSame(KsefOfflineBuyerClassification::NoPlNip, $presentation->buyerClassification);
        $this->assertSame(KsefOfflineDeliveryDocumentType::OfflineInvoice, $policy->primaryDocument($issuance));
        $this->assertSame('VAT UE', $presentation->buyer['identity_label']);
        $this->assertSame('DE123456789', $presentation->buyer['identity_value']);
        $this->assertStringContainsString('KSeF DEMO — DOKUMENT TESTOWY', $html);
        $this->assertStringContainsString('Produkt testowy', $html);
        $this->assertStringContainsString((string) $invoice->number, $html);
        $this->assertSame([
            ['heading' => 'KOD I', 'payload' => $issuance->invoice_verification_url, 'label' => 'OFFLINE'],
            ['heading' => 'KOD II', 'payload' => $issuance->certificate_verification_url, 'label' => 'CERTYFIKAT'],
        ], $blocks);
        $this->assertStringStartsWith('%PDF-', $document['contents']);

        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $issuance]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$invoice, $issuance]))
            ->assertForbidden();
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('POBIERZ FAKTURĘ OFFLINE')
            ->assertDontSee('POBIERZ POTWIERDZENIE TRANSAKCJI')
            ->assertSee('Dokument zawiera kody weryfikacyjne KSeF dla Faktury wystawionej Offline24.');
        Http::assertNothingSent();
    }

    public function test_buyer_without_tax_identifier_is_classified_as_no_pl_nip(): void
    {
        [, $issuance] = $this->issueOffline(
            KsefEnvironment::Test,
            ['billing_tax_id' => null],
        );

        $presentation = app(KsefOfflinePresentationDataExtractor::class)->extract($issuance);

        $this->assertSame(KsefOfflineBuyerClassification::NoPlNip, $presentation->buyerClassification);
        $this->assertNull($presentation->buyer['identity_value']);
        $this->assertSame(KsefOfflineDeliveryDocumentType::OfflineInvoice, app(KsefOfflineDeliveryPolicy::class)->primaryDocument($issuance));
        Http::assertNothingSent();
    }

    public function test_ambiguous_frozen_buyer_fails_closed_in_policy_endpoints_and_ui(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $issuance = $this->replaceFrozenXml($issuance, function (DOMDocument $document): void {
            $identities = $document->getElementsByTagName('DaneIdentyfikacyjne');
            $buyerIdentity = $identities->item(1);
            $buyerIdentity?->appendChild($document->createElementNS(
                $document->documentElement?->namespaceURI,
                'BrakID',
                '1',
            ));
        });

        $this->assertKsefError(
            'ksef_offline_delivery_buyer_identity_invalid',
            fn () => app(KsefOfflineDeliveryPolicy::class)->primaryDocument($issuance),
        );
        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $issuance]))
            ->assertUnprocessable();
        $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$invoice, $issuance]))
            ->assertUnprocessable();
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Nie można jednoznacznie ustalić sposobu wydania dokumentu')
            ->assertDontSee('POBIERZ FAKTURĘ OFFLINE')
            ->assertDontSee('POBIERZ POTWIERDZENIE TRANSAKCJI');
        Http::assertNothingSent();
    }

    #[DataProvider('integrityTamperCases')]
    public function test_presentation_integrity_tampering_fails_closed(string $field): void
    {
        [, $issuance] = $this->issueOffline();
        $value = match ($field) {
            'invoice_hash' => base64_encode(str_repeat('x', 32)),
            'invoice_size' => $issuance->invoice_size + 1,
            'issue_date' => $issuance->issue_date->addDay()->toDateString(),
            'seller_nip' => '5260250995',
            'invoice_verification_url' => $issuance->invoice_verification_url.'/changed',
            'certificate_verification_url' => $issuance->certificate_verification_url.'/changed',
        };
        DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->update([$field => $value]);

        $this->assertKsefError(
            'ksef_offline_presentation_integrity_invalid',
            fn () => app(KsefOfflinePresentationDataExtractor::class)->extract($issuance->fresh()),
        );
        Http::assertNothingSent();
    }

    public static function integrityTamperCases(): array
    {
        return [
            'hash' => ['invoice_hash'],
            'size' => ['invoice_size'],
            'P_1' => ['issue_date'],
            'seller' => ['seller_nip'],
            'KOD I' => ['invoice_verification_url'],
            'KOD II' => ['certificate_verification_url'],
        ];
    }

    public function test_frozen_presentation_survives_invoice_settings_and_certificate_changes(): void
    {
        [$invoice, $issuance, $certificate] = $this->issueOffline(
            KsefEnvironment::Demo,
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
        );
        $extractor = app(KsefOfflinePresentationDataExtractor::class);
        $before = $extractor->extract($issuance);
        $invoiceUrl = $issuance->invoice_verification_url;
        $certificateUrl = $issuance->certificate_verification_url;

        $invoice->forceFill([
            'buyer_name_snapshot' => 'LATER BUYER',
            'seller_name_snapshot' => 'LATER SELLER',
            'additional_information_text' => 'LATER INFORMATION',
            'total_gross' => '999.99',
        ])->saveQuietly();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Test,
        ])->save();
        app(KsefOfflineCertificateService::class)->delete($certificate);

        $issuance = $issuance->fresh();
        $after = $extractor->extract($issuance);
        $document = app(KsefOfflineInvoicePdfService::class)->document($issuance);

        $this->assertNull($issuance->offline_certificate_id);
        $this->assertEquals($before, $after);
        $this->assertSame(KsefEnvironment::Demo, $after->environment);
        $this->assertSame($invoiceUrl, $after->invoiceVerificationUrl);
        $this->assertSame($certificateUrl, $after->certificateVerificationUrl);
        $this->assertStringStartsWith('%PDF-', $document['contents']);
        $this->assertStringNotContainsString('LATER BUYER', app(KsefOfflinePresentationPdfRenderer::class)->offlineInvoiceHtml($after));
        Http::assertNothingSent();
    }

    public function test_standard_pdf_and_preexisting_cache_are_blocked_after_offline24(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->readyCertificate(KsefEnvironment::Test);
        $pdfs = app(InvoicePdfService::class);

        $this->assertStringStartsWith('%PDF-', $pdfs->contents($invoice));
        $filesBefore = Storage::disk('local')->allFiles();
        app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        $this->assertInvoiceError(
            'invoice_pdf_ksef_offline24_requires_delivery_policy',
            fn () => $pdfs->contents($invoice->fresh()),
        );
        $this->assertInvoiceError(
            'invoice_pdf_ksef_offline24_requires_delivery_policy',
            fn () => app(InvoicePdfRenderer::class)->render($invoice->fresh()),
        );
        $this->get(route('invoices.pdf', $invoice))
            ->assertUnprocessable()
            ->assertSee('Pobierz dokument właściwy dla nabywcy z panelu KSeF.');
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_bulk_pdf_cannot_include_offline24_while_standard_documents_still_work(): void
    {
        [$offlineInvoice] = $this->issueOffline();
        $ordinaryInvoice = $this->eligibleInvoice();
        $bulk = app(InvoiceBulkPdfService::class);

        $this->assertInvoiceError(
            'invoice_pdf_ksef_offline24_requires_delivery_policy',
            fn () => $bulk->contents([$ordinaryInvoice->getKey(), $offlineInvoice->getKey()]),
        );
        $this->assertInvoiceError(
            'invoice_pdf_ksef_offline24_requires_delivery_policy',
            fn () => app(InvoicePdfRenderer::class)->renderMany(
                collect([$ordinaryInvoice, $offlineInvoice]),
                InvoiceDocumentType::Invoice,
            ),
        );
        $this->post(route('invoices.bulk-pdf'), [
            'selection' => json_encode([$offlineInvoice->getKey()], JSON_THROW_ON_ERROR),
        ])->assertRedirect()->assertSessionHasErrors('invoice_ids');
        $this->assertStringStartsWith('%PDF-', app(InvoicePdfService::class)->contents($ordinaryInvoice));
        $this->assertStringStartsWith('%PDF-', $bulk->contents([$ordinaryInvoice->getKey()]));
        Http::assertNothingSent();
    }

    public function test_presentation_route_rejects_an_issuance_owned_by_another_invoice(): void
    {
        [, $issuance] = $this->issueOffline(
            KsefEnvironment::Test,
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
        );
        $otherInvoice = $this->eligibleInvoice();

        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$otherInvoice, $issuance]))
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_test_and_demo_marks_come_from_each_frozen_issuance_environment(): void
    {
        [, $testIssuance] = $this->issueOffline(
            KsefEnvironment::Test,
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
        );
        [, $demoIssuance] = $this->issueOffline(
            KsefEnvironment::Demo,
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE987654321'],
        );
        $extractor = app(KsefOfflinePresentationDataExtractor::class);

        $test = $extractor->extract($testIssuance);
        $demo = $extractor->extract($demoIssuance);

        $this->assertSame('KSeF TEST — DOKUMENT TESTOWY', $test->testMark());
        $this->assertSame('KSeF DEMO — DOKUMENT TESTOWY', $demo->testMark());
        $this->assertStringStartsWith('https://qr-test.ksef.mf.gov.pl/', $test->invoiceVerificationUrl);
        $this->assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/', $demo->invoiceVerificationUrl);
        Http::assertNothingSent();
    }

    public function test_offline24_issue_action_is_only_a_hint_for_the_current_warsaw_day(): void
    {
        $invoice = $this->eligibleInvoice(
            issueDate: $this->testNow->setTimezone('Europe/Warsaw')->subDay()->toDateString(),
        );

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertDontSee('WYSTAW OFFLINE24');
        $this->assertDatabaseCount('ksef_offline_issuances', 0);
        Http::assertNothingSent();
    }

    /** @return array{0: Invoice, 1: KsefOfflineIssuance, 2: KsefOfflineCertificate} */
    private function issueOffline(
        KsefEnvironment $environment = KsefEnvironment::Test,
        array $orderAttributes = [],
    ): array {
        $invoice = $this->eligibleInvoice($environment, $orderAttributes);
        $certificate = $this->readyCertificate($environment);
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        return [$invoice, $issuance, $certificate];
    }

    private function eligibleInvoice(
        KsefEnvironment $environment = KsefEnvironment::Test,
        array $orderAttributes = [],
        ?string $issueDate = null,
    ): Invoice {
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
            'send_without_buyer_nip' => true,
            'include_additional_information' => true,
            'include_order_reference' => true,
            'include_bank_account' => true,
            'include_gtu' => true,
        ])->save();
        $order = $this->createDocumentOrder(array_replace([
            'external_id' => 'KSEF-OFFLINE-PRESENTATION-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ], $orderAttributes));
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
            'vat_rate' => '23.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false,
            'seller_tax_id' => '9876543210',
            'seller_bank_name' => 'Bank testowy',
            'seller_bank_account' => 'PL61109010140000071219812874',
            'seller_bank_swift' => 'WBKPPLPP',
        ]);
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);
        $date = $issueDate ?? $this->testNow->setTimezone('Europe/Warsaw')->toDateString();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext($date.' 10:00:00'),
        )->refresh()->load('items');

        return app(InvoiceFinalizationService::class)->finalize($invoice)->load('items');
    }

    private function readyCertificate(KsefEnvironment $environment): KsefOfflineCertificate
    {
        $selection = KsefOfflineCertificateSelection::query()
            ->with('certificate')
            ->where('environment', $environment->value)
            ->first();

        if ($selection?->certificate !== null) {
            return $selection->certificate;
        }

        $certificate = app(KsefOfflineCertificateService::class)->import(
            $environment,
            'Offline presentation test certificate',
            $this->offlineFixture['certificate'],
            $this->offlineFixture['private_key'],
            null,
        );
        $certificate->forceFill([
            'remote_status' => 'Active',
            'remote_certificate_name' => 'Offline presentation test certificate',
            'remote_valid_from' => $this->testNow->subDay(),
            'remote_valid_until' => $this->testNow->addDay(),
            'remote_verified_at' => $this->testNow->subMinute(),
        ])->save();
        app(KsefOfflineCertificateService::class)->setPreferred($certificate, $environment);

        return $certificate->fresh();
    }

    private function replaceFrozenXml(KsefOfflineIssuance $issuance, Closure $change): KsefOfflineIssuance
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($issuance->payload_xml, LIBXML_NONET));
        $change($document);
        $xml = $document->saveXML();
        $this->assertIsString($xml);
        $hash = base64_encode(hash('sha256', $xml, true));
        $invoiceUrl = app(KsefInvoiceVerificationLinkBuilder::class)->buildFor(
            $issuance->environment,
            (string) $issuance->seller_nip,
            $issuance->issue_date,
            $hash,
        );
        $certificateUrl = app(KsefOfflineCertificateVerificationLinkBuilder::class)->build(
            $issuance->environment,
            KsefContextIdentifier::make(
                KsefContextIdentifierType::Nip,
                (string) $issuance->context_identifier_value,
            ),
            (string) $issuance->seller_nip,
            (string) $issuance->certificate_serial_number,
            $hash,
            $this->offlineFixture['private_key'],
        )->url;

        DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->update([
                'payload_xml' => Crypt::encryptString($xml),
                'invoice_hash' => $hash,
                'invoice_size' => strlen($xml),
                'invoice_verification_url' => $invoiceUrl,
                'certificate_verification_url' => $certificateUrl,
            ]);

        return $issuance->fresh();
    }

    private function assertKsefError(string $safeCode, Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu KSeF: '.$safeCode);
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);
        }
    }

    private function assertInvoiceError(string $errorCode, Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu PDF: '.$errorCode);
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($errorCode, $exception->errorCode());
        }
    }
}
