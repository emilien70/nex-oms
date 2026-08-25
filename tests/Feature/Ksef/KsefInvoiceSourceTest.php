<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefInvoiceSourceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_accepted_invoice_source_uses_exact_test_endpoint_and_returns_verified_xml_without_persistence(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $submission->payload_xml;
        $updatedAt = $submission->updated_at?->toISOString();
        $this->fakeInvoice($xml, $this->hash($xml));

        $response = $this->withHeader('Accept', 'application/xml')->get($this->route($invoice, $submission));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($xml, $response->getContent());
        $this->assertSame($updatedAt, $submission->fresh()->updated_at?->toISOString());
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-test.ksef.mf.gov.pl/v2/invoices/ksef/'
                .rawurlencode($submission->ksef_number)
            && $request->hasHeader('Authorization', 'Bearer FAKE_INVOICE_SOURCE_ACCESS_TOKEN')
            && $request->hasHeader('Accept', 'application/xml'));
        Http::assertSentCount(1);
    }

    public function test_demo_submission_uses_demo_endpoint(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Demo);
        $submission = $this->acceptedSubmission($invoice, KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $xml = $submission->payload_xml;
        $this->fakeInvoice($xml, $this->hash($xml));

        $this->get($this->route($invoice, $submission))->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api-demo.ksef.mf.gov.pl/v2/invoices/ksef/'
                .rawurlencode($submission->ksef_number));
        Http::assertSentCount(1);
    }

    public function test_download_rejects_source_that_does_not_match_frozen_invoice(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $differentXml = '<Faktura>DIFFERENT DOCUMENT</Faktura>';
        $this->fakeInvoice($differentXml, $this->hash($differentXml));

        $this->getJson($this->route($invoice, $submission))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Faktura pobrana z KSeF jest niezgodna z zamrożonym dokumentem wysłanym przez NEX-OMS.',
            )
            ->assertDontSee($differentXml, false);

        Http::assertSentCount(1);
    }

    public function test_non_accepted_submission_is_rejected_before_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice, attributes: [
            'status' => KsefInvoiceSubmissionStatus::Processing,
        ]);

        $this->getJson($this->route($invoice, $submission))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Dokument z KSeF można pobrać wyłącznie dla zaakceptowanej Faktury.',
            );

        Http::assertNothingSent();
    }

    public function test_cross_invoice_submission_returns_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($otherInvoice);

        $this->getJson($this->route($invoice, $submission))->assertNotFound();

        Http::assertNothingSent();
    }

    private function eligibleInvoice(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-INVOICE-SOURCE-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
            'vat_rate' => '23.00',
        ]);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, [
            'include_shipping' => false,
            'seller_tax_id' => '9876543210',
        ]);
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        )->refresh()->load('items');

        return app(InvoiceFinalizationService::class)->finalize($invoice)->load('items');
    }

    /** @param array<string, mixed> $attributes */
    private function acceptedSubmission(
        Invoice $invoice,
        KsefEnvironment $environment = KsefEnvironment::Test,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        $payload = '<?xml version="1.0" encoding="UTF-8"?><Faktura>TEST SOURCE</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinute(),
            'payload_xml' => $payload,
            'invoice_hash' => $this->hash($payload),
            'invoice_size' => strlen($payload),
            'session_reference_number' => '20260825-SE-TEST-SOURCE',
            'invoice_reference_number' => '20260825-EE-TEST-SOURCE',
            'ksef_number' => '9876543210-20260825-AAAAAAAAAA-BB',
            'acquisition_date' => now()->subSeconds(30),
        ], $attributes));
    }

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_INVOICE_SOURCE_API_TOKEN',
            'access_token' => 'FAKE_INVOICE_SOURCE_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_INVOICE_SOURCE_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeInvoice(string $xml, ?string $hash): void
    {
        Http::fake(['*' => Http::response($xml, 200, array_filter([
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $hash,
        ]))]);
    }

    private function route(Invoice $invoice, KsefInvoiceSubmission $submission): string
    {
        return route('invoices.ksef.submissions.invoice.download', compact('invoice', 'submission'));
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
