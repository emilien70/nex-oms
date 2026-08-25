<?php

namespace Tests\Feature\Ksef;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefInvoiceUpo;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefUpoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefInvoiceUpoTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_schema_and_model_store_one_encrypted_immutable_upo_per_submission(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $xml = $this->upoXml($invoice, $submission);
        $upo = $this->storeUpo($submission, $xml);

        $this->assertTrue(Schema::hasColumns('ksef_invoice_upos', [
            'ksef_invoice_submission_id',
            'schema_id',
            'payload_xml',
            'payload_hash',
            'payload_size',
            'fetched_at',
        ]));
        $this->assertSame($submission->getKey(), $upo->submission->getKey());
        $this->assertSame($upo->getKey(), $submission->upo->getKey());
        $this->assertSame($xml, $upo->payload_xml);
        $this->assertArrayNotHasKey('payload_xml', $upo->toArray());
        $this->assertNotSame(
            $xml,
            DB::table('ksef_invoice_upos')->where('id', $upo->getKey())->value('payload_xml'),
        );

        $this->expectException(QueryException::class);
        $this->storeUpo($submission, $xml);
    }

    public function test_upo_foreign_key_prevents_deleting_its_submission(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->storeUpo($submission, $this->upoXml($invoice, $submission));

        $this->expectException(QueryException::class);
        $submission->delete();
    }

    public function test_fetch_uses_exact_individual_endpoint_stores_raw_xml_once_and_keeps_accepted(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $this->upoXml($invoice, $submission)."\r\n";
        $hash = $this->hash($xml);
        $this->fakeUpo($xml, $hash);

        $route = route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission'));
        $this->post($route)
            ->assertRedirect()
            ->assertSessionHas('success', 'UPO zostało pobrane i bezpiecznie zapisane.');

        $upo = KsefInvoiceUpo::query()->sole();
        $this->assertSame($xml, $upo->payload_xml);
        $this->assertSame($hash, $upo->payload_hash);
        $this->assertSame(strlen($xml), $upo->payload_size);
        $this->assertStringContainsString(
            '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"',
            $upo->payload_xml,
        );
        $this->assertStringContainsString(
            '<NazwaPodmiotuPrzyjmujacego>Ministerstwo Finansów - środowisko testowe (TE)</NazwaPodmiotuPrzyjmujacego>',
            $upo->payload_xml,
        );
        $this->assertNotNull($upo->fetched_at);
        $this->assertSame(KsefUpoValidator::SCHEMA_ID, $upo->schema_id);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-test.ksef.mf.gov.pl/v2/sessions/'
                .rawurlencode(KsefUpoFixture::SESSION_REFERENCE)
                .'/invoices/ksef/'.rawurlencode($submission->ksef_number).'/upo'
            && $request->hasHeader('Authorization', 'Bearer FAKE_UPO_ACCESS_TOKEN'));
        Http::assertSentCount(1);

        config()->set('ksef.invoice_submission_enabled', false);
        $this->post($route)->assertSessionHas('success');
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertSame($xml, $upo->fresh()->payload_xml);
        Http::assertSentCount(1);

        $download = $this->post($route, ['download' => '1']);
        $download->assertOk()
            ->assertDownload('UPO_'.$submission->ksef_number.'.xml')
            ->assertHeader('Content-Type', 'application/xml');
        $this->assertSame($xml, $download->streamedContent());
        Http::assertSentCount(1);
    }

    #[DataProvider('nonAcceptedStatuses')]
    public function test_only_accepted_submission_can_fetch_upo(KsefInvoiceSubmissionStatus $status): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice, ['status' => $status]);

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame($status, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertNothingSent();
    }

    public function test_ajax_pdf_source_failure_returns_safe_json_without_persistence(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice, [
            'status' => KsefInvoiceSubmissionStatus::Processing,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')), [
                'download' => '1',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'UPO można pobrać wyłącznie dla Faktury przyjętej przez KSeF.',
            ]);

        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertNothingSent();
    }

    public static function nonAcceptedStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->reject(fn (KsefInvoiceSubmissionStatus $status): bool => $status === KsefInvoiceSubmissionStatus::Accepted)
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    public function test_demo_fetch_uses_demo_host_and_preserves_original_signed_tr_upo(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Demo);
        $submission = $this->acceptedSubmission($invoice, [
            'environment' => KsefEnvironment::Demo,
        ]);
        $this->validAccessToken(KsefEnvironment::Demo);
        $xml = $this->upoXml($invoice, $submission, [
            'receiver_name' => 'Ministerstwo Finansów - środowisko przedprodukcyjne (TR)',
        ])."\r\n";
        $this->fakeUpo($xml, $this->hash($xml));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHas('success');

        $upo = KsefInvoiceUpo::query()->sole();
        $this->assertSame($xml, $upo->payload_xml);
        $this->assertStringContainsString(
            '<NazwaPodmiotuPrzyjmujacego>Ministerstwo Finansów - środowisko przedprodukcyjne (TR)</NazwaPodmiotuPrzyjmujacego>',
            $upo->payload_xml,
        );
        $this->assertStringContainsString(
            '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"',
            $upo->payload_xml,
        );
        $this->assertNotSame(
            $xml,
            DB::table('ksef_invoice_upos')->where('id', $upo->getKey())->value('payload_xml'),
        );
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api-demo.ksef.mf.gov.pl'
            && str_contains($request->url(), '/sessions/'.rawurlencode(KsefUpoFixture::SESSION_REFERENCE).'/invoices/ksef/'));
    }

    public function test_production_fetch_is_blocked_before_http_and_non_invoice_is_rejected(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Production);
        $production = $this->acceptedSubmission($invoice, [
            'environment' => KsefEnvironment::Production,
        ]);

        try {
            app(KsefInvoiceUpoService::class)->fetch($invoice, $production);
            $this->fail('Expected production UPO environment block.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_operational_environment_blocked', $exception->safeCode);
        }

        Http::assertNothingSent();

        foreach ([InvoiceDocumentType::Proforma, InvoiceDocumentType::Correction] as $type) {
            $document = $this->eligibleInvoice();
            $document->forceFill(['document_type' => $type])->save();
            $submission = $this->acceptedSubmission($document);

            $this->post(route('invoices.ksef.submissions.upo.fetch', [
                'invoice' => $document,
                'submission' => $submission,
            ]))->assertSessionHasErrors('ksef');
        }

        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('incompleteSubmissionProvider')]
    public function test_incomplete_or_inconsistent_accepted_submission_is_rejected_before_http(
        array $attributes,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice, $attributes);

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertNothingSent();
    }

    public static function incompleteSubmissionProvider(): array
    {
        return [
            'session reference missing' => [['session_reference_number' => null]],
            'invoice reference missing' => [['invoice_reference_number' => null]],
            'KSeF number missing' => [['ksef_number' => null]],
            'context missing' => [['context_nip' => null]],
            'seller missing' => [['seller_nip' => null]],
            'payload size mismatch' => [['invoice_size' => 1]],
            'payload hash mismatch' => [['invoice_hash' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']],
        ];
    }

    #[DataProvider('identityMismatchProvider')]
    public function test_semantic_identity_mismatch_never_persists_upo(
        array $overrides,
        string $expectedMessage,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $this->upoXml($invoice, $submission, $overrides);
        $this->fakeUpo($xml, $this->hash($xml));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors(['ksef' => $expectedMessage]);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertSentCount(1);
    }

    public static function identityMismatchProvider(): array
    {
        return [
            'session' => [[
                'session_reference' => '20260821-SO-AAAAAAAAAA-BBBBBBBBBB-CC',
            ], 'UPO nie odpowiada sesji wysyłki Faktury.'],
            'context' => [[
                'context_nip' => '5265877635',
            ], 'UPO nie odpowiada kontekstowi wysyłki Faktury.'],
            'seller' => [[
                'seller_nip' => '5265877635',
            ], 'UPO nie odpowiada sprzedawcy wysłanej Faktury.'],
            'KSeF number' => [[
                'ksef_number' => KsefUpoFixture::ksefNumber('5265877635'),
            ], 'UPO nie odpowiada numerowi KSeF wysłanej Faktury.'],
            'invoice number' => [[
                'invoice_number' => 'FV OTHER/2026',
            ], 'UPO nie odpowiada numerowi wysłanej Faktury.'],
            'invoice hash' => [[
                'invoice_hash' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ], 'UPO nie odpowiada utrwalonemu XML-owi wysłanej Faktury.'],
            'logical structure' => [[
                'logical_structure' => 'Schemat_FA(2)_v1-0E.xsd',
            ], 'UPO wskazuje nieobsługiwaną strukturę Faktury.'],
            'form code' => [[
                'form_code' => 'FA (2)',
            ], 'UPO wskazuje nieobsługiwany formularz Faktury.'],
            'delivery mode' => [[
                'mode' => 'Offline',
            ], 'UPO nie dotyczy obsługiwanego trybu wysyłki online.'],
        ];
    }

    public function test_compatibility_projection_never_hides_an_unrelated_xsd_failure(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $this->upoXml($invoice, $submission, ['issue_date' => 'NOT-A-DATE']);
        $this->fakeUpo($xml, $this->hash($xml));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors([
                'ksef' => 'Dokument UPO nie jest zgodny z oficjalnym schematem UPO v4-3.',
            ]);

        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_hash_is_checked_against_exact_raw_body_before_xml_validation(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $this->upoXml($invoice, $submission);
        $this->fakeUpo($xml."\n", $this->hash($xml));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors([
                'ksef' => 'Skrót odpowiedzi UPO jest niezgodny z odebranym dokumentem.',
            ]);

        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_or_invalid_hash_header_never_persists_upo(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = $this->upoXml($invoice, $submission);
        $this->fakeUpo($xml, 'not-base64');

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
    }

    public function test_malformed_success_response_never_changes_accepted_submission(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        $xml = '<Potwierdzenie>malformed';
        $this->fakeUpo($xml, $this->hash($xml));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
    }

    public function test_empty_success_response_is_rejected_without_persistence_or_retry(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        Http::fake(['*' => Http::response('', 200, [
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $this->hash(''),
        ])]);

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertSentCount(1);
    }

    #[DataProvider('httpFailureProvider')]
    public function test_http_failure_does_not_change_accepted_or_create_upo(
        int $status,
        ?string $reasonCode,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        Http::fake(['*' => Http::response(
            $reasonCode === null ? [] : ['reasonCode' => $reasonCode],
            $status,
            $status === 429 ? ['Retry-After' => '5'] : [],
        )]);

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public static function httpFailureProvider(): array
    {
        return [
            'bad request' => [400, 'INVALID_REQUEST'],
            'unauthorized' => [401, 'AUTH_FAILED'],
            'forbidden' => [403, 'PERMISSION_DENIED'],
            'not ready' => [404, '21178'],
            'rate limited' => [429, 'RATE_LIMITED'],
            'server error' => [500, null],
        ];
    }

    public function test_connection_failure_does_not_retry_or_change_accepted(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $this->validAccessToken();
        Http::fake(fn (Request $request) => (Http::failedConnection('Synthetic UPO failure'))($request));

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        Http::assertSentCount(1);
    }

    public function test_panel_switches_from_live_fetch_to_local_download_without_exposing_xml(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $xml = $this->upoXml($invoice, $submission).'SECRET_UPO_XML_MARKER';

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Pobierz UPO z KSeF')
            ->assertSee(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')), false)
            ->assertDontSee('Pobierz UPO</a>', false);

        $this->storeUpo($submission, $xml);
        config()->set('ksef.invoice_submission_enabled', false);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Pobierz UPO')
            ->assertSee(route('invoices.ksef.submissions.upo.download', compact('invoice', 'submission')), false)
            ->assertDontSee('Pobierz UPO z KSeF')
            ->assertDontSee('SECRET_UPO_XML_MARKER');
        Http::assertNothingSent();
    }

    public function test_local_download_returns_exact_private_xml_without_gate_or_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice);
        $xml = $this->upoXml($invoice, $submission)."\r\n";
        $this->storeUpo($submission, $xml);
        config()->set('ksef.invoice_submission_enabled', false);
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Production,
        ])->save();
        KsefCredential::query()->delete();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.ksef.submissions.upo.download', compact('invoice', 'submission')), false)
            ->assertDontSee('data-ksef-upo-fetch-form', false);

        $response = $this->get(route('invoices.ksef.submissions.upo.download', compact('invoice', 'submission')));

        $response->assertOk()
            ->assertDownload('UPO_'.$submission->ksef_number.'.xml')
            ->assertHeader('Content-Type', 'application/xml');
        $this->assertSame($xml, $response->streamedContent());
        $this->assertStringContainsString(
            '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"',
            $response->streamedContent(),
        );
        $this->assertStringContainsString(
            'Ministerstwo Finansów - środowisko testowe (TE)',
            $response->streamedContent(),
        );
        Http::assertNothingSent();
    }

    public function test_missing_local_upo_and_cross_invoice_submission_return_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($otherInvoice);

        $this->get(route('invoices.ksef.submissions.upo.download', [
            'invoice' => $otherInvoice,
            'submission' => $submission,
        ]))->assertNotFound();
        $this->get(route('invoices.ksef.submissions.upo.download', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();
        $this->post(route('invoices.ksef.submissions.upo.fetch', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_non_accepted_panel_has_no_upo_fetch_action(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->acceptedSubmission($invoice, [
            'status' => KsefInvoiceSubmissionStatus::Processing,
        ]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertDontSee(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')), false)
            ->assertDontSee('Pobierz UPO z KSeF');
    }

    private function eligibleInvoice(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => KsefUpoFixture::CONTEXT_NIP,
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-UPO-'.uniqid(),
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
            'seller_tax_id' => KsefUpoFixture::SELLER_NIP,
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
    private function acceptedSubmission(Invoice $invoice, array $attributes = []): KsefInvoiceSubmission
    {
        $payload = '<Faktura>TEST</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => KsefUpoFixture::CONTEXT_NIP,
            'seller_nip' => KsefUpoFixture::SELLER_NIP,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinute(),
            'payload_xml' => $payload,
            'invoice_hash' => $this->hash($payload),
            'invoice_size' => strlen($payload),
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'ksef_number' => KsefUpoFixture::ksefNumber(),
        ], $attributes));
    }

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_UPO_API_TOKEN',
            'access_token' => 'FAKE_UPO_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_UPO_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    /** @param array<string, string|bool> $overrides */
    private function upoXml(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        array $overrides = [],
    ): string {
        return KsefUpoFixture::xml(array_replace([
            'context_nip' => $submission->context_nip,
            'seller_nip' => $submission->seller_nip,
            'session_reference' => $submission->session_reference_number,
            'ksef_number' => $submission->ksef_number,
            'invoice_number' => $invoice->number,
            'invoice_hash' => $submission->invoice_hash,
        ], $overrides));
    }

    private function fakeUpo(string $xml, ?string $hash): void
    {
        Http::fake(['*' => Http::response($xml, 200, array_filter([
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $hash,
        ]))]);
    }

    private function storeUpo(KsefInvoiceSubmission $submission, string $xml): KsefInvoiceUpo
    {
        return KsefInvoiceUpo::query()->create([
            'ksef_invoice_submission_id' => $submission->getKey(),
            'schema_id' => KsefUpoValidator::SCHEMA_ID,
            'payload_xml' => $xml,
            'payload_hash' => $this->hash($xml),
            'payload_size' => strlen($xml),
            'fetched_at' => now(),
        ]);
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
