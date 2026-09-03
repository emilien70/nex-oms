<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefOfflineInvoiceSubmissionService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Modules\Ksef\Services\KsefOfflinePresentationDataExtractor;
use Modules\Ksef\Services\KsefOfflinePresentationPdfRenderer;
use Modules\Ksef\Services\KsefSettingsService;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefCertificateFixtureFactory;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefOfflineSubmissionTest extends TestCase
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
        config()->set('ksef.invoice_submission_enabled', true);
        $this->offlineFixture = KsefCertificateFixtureFactory::offlineEc();
        $this->testNow = CarbonImmutable::createFromTimestamp(
            $this->offlineFixture['valid_from'],
            'UTC',
        )->addHour();
        $this->travelTo($this->testNow);
    }

    public function test_migration_relations_and_prepare_copy_the_exact_encrypted_offline_issuance(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $generator = Mockery::mock(KsefFa3DocumentGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(KsefFa3DocumentGenerator::class, $generator);

        $submission = app(KsefOfflineInvoiceSubmissionService::class)->prepare($invoice, $issuance);
        $rawPayload = DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->value('payload_xml');

        $this->assertTrue(Schema::hasColumn('ksef_invoice_submissions', 'offline_issuance_id'));
        $this->assertSame($issuance->getKey(), $submission->offline_issuance_id);
        $this->assertSame($issuance->payload_xml, $submission->payload_xml);
        $this->assertSame($issuance->invoice_hash, $submission->invoice_hash);
        $this->assertSame($issuance->invoice_size, $submission->invoice_size);
        $this->assertSame($issuance->environment, $submission->environment);
        $this->assertSame($issuance->context_identifier_value, $submission->context_nip);
        $this->assertSame($issuance->seller_nip, $submission->seller_nip);
        $this->assertSame($issuance->schema_id, $submission->schema_id);
        $this->assertSame($issuance->issued_at->getTimestamp(), $submission->generated_at->getTimestamp());
        $this->assertNotSame($issuance->payload_xml, $rawPayload);
        $this->assertStringNotContainsString('<Faktura', $rawPayload);
        $this->assertTrue($submission->offlineIssuance->is($issuance));
        $this->assertTrue($issuance->submissions()->firstOrFail()->is($submission));
        $this->assertSame(KsefInvoicingMode::Offline, $submission->expectedInvoicingMode());
        Http::assertNothingSent();
    }

    public function test_next_day_submission_uses_frozen_test_payload_despite_current_configuration_drift(): void
    {
        [$invoice, $issuance, $certificate] = $this->issueOffline();
        $frozenXml = $issuance->payload_xml;
        $this->travelTo($this->testNow->addDay());
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
        ])->save();
        KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->update(['is_enabled' => false]);
        $certificate->delete();
        $invoice->forceFill(['additional_information_text' => 'LATER SOURCE CHANGE'])->saveQuietly();
        $this->validAccessToken(KsefEnvironment::Test);
        $fake = $this->fakeOnlineApi();

        $submission = app(KsefOfflineInvoiceSubmissionService::class)
            ->submitAttempt($invoice, $issuance->refresh());

        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame(KsefEnvironment::Test, $submission->environment);
        $this->assertTrue($fake->sendPayload['offlineMode']);
        $this->assertIsBool($fake->sendPayload['offlineMode']);
        $this->assertArrayNotHasKey('hashOfCorrectedInvoice', $fake->sendPayload);
        $this->assertSame($issuance->invoice_hash, $fake->sendPayload['invoiceHash']);
        $this->assertSame($issuance->invoice_size, $fake->sendPayload['invoiceSize']);
        $this->assertSame($frozenXml, $this->decryptInvoice($fake));
        $this->assertNull($issuance->refresh()->offline_certificate_id);
        $this->assertSame(1, $fake->publicKeyCalls);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            config('ksef.base_urls.test'),
        ));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            config('ksef.base_urls.demo'),
        ));
    }

    public function test_current_context_mismatch_blocks_before_attempt_and_http(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        app(KsefSettingsService::class)->get()->forceFill([
            'context_nip' => '5260250995',
        ])->save();

        $this->expectKsefError(
            'ksef_offline_submission_context_not_current',
            fn () => app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($invoice, $issuance),
        );

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_pre_network_and_second_guard_block_tampered_links_without_invoice_post(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $this->validAccessToken();
        $offline = app(KsefOfflineInvoiceSubmissionService::class);
        $submission = $offline->prepare($invoice, $issuance);
        $submission->forceFill(['seller_nip' => '5260250995'])->save();

        $this->expectKsefError(
            'ksef_offline_submission_integrity_invalid',
            fn () => app(KsefInvoiceSubmissionService::class)->submitOffline($submission),
        );
        Http::assertNothingSent();

        DB::table('ksef_invoice_submissions')->where('id', $submission->getKey())->delete();
        $submission = $offline->prepare($invoice, $issuance);
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(function (Request $request) use ($fake, $submission): mixed {
            $response = $fake($request);
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if (str_ends_with($path, '/sessions/online')) {
                DB::table('ksef_invoice_submissions')
                    ->where('id', $submission->getKey())
                    ->update(['seller_nip' => '5260250995']);
            }

            return $response;
        });

        $this->expectKsefError(
            'ksef_offline_submission_integrity_invalid',
            fn () => app(KsefInvoiceSubmissionService::class)->submitOffline($submission),
        );

        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(0, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $submission->refresh()->status);
    }

    public function test_double_click_sends_once_and_technical_failure_allows_exact_manual_retry(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $offline = app(KsefOfflineInvoiceSubmissionService::class);

        $first = $offline->submitAttempt($invoice, $issuance);
        $this->expectKsefError(
            'ksef_offline_submission_attempt_blocked',
            fn () => $offline->submitAttempt($invoice, $issuance),
        );
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);

        $first->forceFill(['status' => KsefInvoiceSubmissionStatus::TechnicalFailed])->save();
        $retry = $offline->prepare($invoice, $issuance);
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame($issuance->payload_xml, $retry->payload_xml);
        $this->assertSame($issuance->invoice_hash, $retry->invoice_hash);
        $this->assertSame($issuance->invoice_size, $retry->invoice_size);
        $this->assertSame($issuance->getKey(), $retry->offline_issuance_id);
    }

    #[DataProvider('blindRetryBlockingStatuses')]
    public function test_rejected_and_uncertain_never_allow_blind_resend(
        KsefInvoiceSubmissionStatus $status,
        string $safeCode,
    ): void {
        [$invoice, $issuance] = $this->issueOffline();
        $offline = app(KsefOfflineInvoiceSubmissionService::class);
        $submission = $offline->prepare($invoice, $issuance);
        $submission->forceFill(['status' => $status])->save();

        $this->expectKsefError($safeCode, fn () => $offline->prepare($invoice, $issuance));

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function blindRetryBlockingStatuses(): array
    {
        return [
            'rejected' => [
                KsefInvoiceSubmissionStatus::Rejected,
                'ksef_offline_submission_rejected_retry_blocked',
            ],
            'uncertain' => [
                KsefInvoiceSubmissionStatus::Uncertain,
                'ksef_offline_submission_reconciliation_required',
            ],
        ];
    }

    public function test_uncertain_offline_submission_is_reconciled_by_exact_hash_without_resend(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefOfflineInvoiceSubmissionService::class)
            ->submitAttempt($invoice, $issuance);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Uncertain,
            'invoice_reference_number' => null,
        ])->save();
        $number = $this->validKsefNumber($issuance->seller_nip, $issuance->issue_date->format('Ymd'));
        $fake->sessionInvoicesResponse = [
            'invoices' => [array_merge([
                'referenceNumber' => '20260819-INV-OFFLINE-RECOVERED',
                'invoiceHash' => $submission->invoice_hash,
            ], $this->acceptedStatus($number, KsefInvoicingMode::Offline))],
        ];

        $reconciled = app(KsefInvoiceSubmissionService::class)
            ->reconcile($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $reconciled->status);
        $this->assertSame('20260819-INV-OFFLINE-RECOVERED', $reconciled->invoice_reference_number);
        $this->assertSame($number, $reconciled->ksef_number);
        $this->assertSame(KsefInvoicingMode::Offline, $reconciled->invoicing_mode);
        $this->assertNull($reconciled->safe_error_code);
        $this->assertSame('upo', $reconciled->follow_up_action);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->statusCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
    }

    public function test_legitimate_offline_acceptance_schedules_upo_dispatches_event_and_builds_one_qr_pdf(): void
    {
        Event::fake([KsefInvoiceAccepted::class]);
        [$invoice, $issuance] = $this->issueOffline();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        $submission = app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($invoice, $issuance);
        $number = KsefUpoFixture::ksefNumber($issuance->seller_nip);
        $fake->statusResponse = $this->acceptedStatus($number, KsefInvoicingMode::Offline);

        $accepted = app(KsefInvoiceSubmissionService::class)
            ->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $accepted->status);
        $this->assertSame(KsefInvoicingMode::Offline, $accepted->invoicing_mode);
        $this->assertNull($accepted->safe_error_code);
        $this->assertSame('upo', $accepted->follow_up_action);
        $this->assertTrue($accepted->hasExpectedInvoicingMode());
        Event::assertDispatched(KsefInvoiceAccepted::class);

        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
        ])->save();

        $document = app(KsefAcceptedOfflineInvoicePdfService::class)
            ->document($invoice, $issuance, $accepted);
        $presentation = app(KsefOfflinePresentationDataExtractor::class)
            ->extract($issuance);
        $blocks = app(KsefOfflinePresentationPdfRenderer::class)
            ->acceptedOfflineInvoiceQrBlocks($presentation, $number);

        $this->assertStringStartsWith('%PDF-', $document['contents']);
        $this->assertCount(1, $blocks);
        $this->assertSame($issuance->invoice_verification_url, $blocks[0]['payload']);
        $this->assertSame($number, $blocks[0]['label']);
        $this->assertSame('KOD I', $blocks[0]['heading']);
        $this->assertStringNotContainsString('OFFLINE', $blocks[0]['label']);
        $this->assertStringNotContainsString('CERTYFIKAT', $blocks[0]['label']);

        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $issuance]))
            ->assertForbidden();
        $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$invoice, $issuance]))
            ->assertForbidden();
        $this->get(route('invoices.ksef.offline-issuances.accepted-pdf', [$invoice, $issuance, $accepted]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $upoXml = KsefUpoFixture::xml([
            'session_reference' => $accepted->session_reference_number,
            'ksef_number' => $number,
            'invoice_number' => $invoice->number,
            'invoice_hash' => $accepted->invoice_hash,
            'mode' => 'Offline',
        ]);
        $fake->upoResponse = $upoXml;
        $fake->upoContentHash = base64_encode(hash('sha256', $upoXml, true));

        $upo = app(KsefInvoiceUpoService::class)->fetch($invoice, $accepted);
        $this->assertTrue($accepted->upo()->firstOrFail()->is($upo));
        $this->assertSame(1, $fake->upoCalls);
    }

    public function test_offline_submission_reported_as_online_preserves_truth_but_blocks_downstream(): void
    {
        Event::fake([KsefInvoiceAccepted::class]);
        [$invoice, $issuance] = $this->issueOffline();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($invoice, $issuance);
        $number = $this->validKsefNumber($issuance->seller_nip, $issuance->issue_date->format('Ymd'));
        $fake->statusResponse = $this->acceptedStatus($number, KsefInvoicingMode::Online);

        $accepted = app(KsefInvoiceSubmissionService::class)
            ->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $accepted->status);
        $this->assertSame(KsefInvoicingMode::Online, $accepted->invoicing_mode);
        $this->assertSame('ksef_invoice_unexpected_online_mode', $accepted->safe_error_code);
        $this->assertNull($accepted->follow_up_action);
        $this->assertFalse($accepted->hasExpectedInvoicingMode());
        Event::assertNotDispatched(KsefInvoiceAccepted::class);

        $requestsBefore = $fake->statusCalls + $fake->upoCalls;
        $this->expectKsefError(
            'ksef_upo_invoicing_mode_mismatch',
            fn () => app(KsefInvoiceUpoService::class)->fetch($invoice, $accepted),
        );
        $this->assertSame($requestsBefore, $fake->statusCalls + $fake->upoCalls);
        $this->get(route('invoices.ksef.offline-issuances.accepted-pdf', [$invoice, $issuance, $accepted]))
            ->assertForbidden();
        $this->get(route('invoices.ksef.offline-issuances.invoice-pdf', [$invoice, $issuance]))
            ->assertForbidden();
        $this->get(route('invoices.ksef.offline-issuances.transaction-confirmation', [$invoice, $issuance]))
            ->assertForbidden();
    }

    public function test_ui_keeps_historical_test_issuance_visible_while_current_environment_is_demo(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
        ])->save();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Offline24 TEST')
            ->assertSee('PRZEŚLIJ OFFLINE24 DO KSeF TEST')
            ->assertSee(route('invoices.ksef.offline-issuances.submissions.store', [$invoice, $issuance]), false)
            ->assertSee('offlineMode=true')
            ->assertSee('Treść FA(3) nie zostanie ponownie wygenerowana.')
            ->assertDontSee('PRZEŚLIJ OFFLINE24 DO KSeF DEMO');
        Http::assertNothingSent();
    }

    /** @return array{0: Invoice, 1: KsefOfflineIssuance, 2: KsefOfflineCertificate} */
    private function issueOffline(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): array {
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-OFFLINE-SEND-'.uniqid(),
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
        $date = $this->testNow->setTimezone(config('app.timezone'))->toDateString();
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext($date.' 10:00:00'),
        )->refresh()->load('items');
        $invoice = app(InvoiceFinalizationService::class)->finalize($invoice)->load('items');
        $certificate = $this->readyCertificate($environment);
        $issuance = app(KsefOfflineIssuanceService::class)->issueOffline24($invoice);

        return [$invoice, $issuance, $certificate];
    }

    private function readyCertificate(KsefEnvironment $environment): KsefOfflineCertificate
    {
        $certificate = app(KsefOfflineCertificateService::class)->import(
            $environment,
            'Offline24 submission test certificate',
            $this->offlineFixture['certificate'],
            $this->offlineFixture['private_key'],
            null,
        );
        $certificate->forceFill([
            'remote_status' => 'Active',
            'remote_certificate_name' => 'Offline24 submission test certificate',
            'remote_valid_from' => $this->testNow->subDay(),
            'remote_valid_until' => $this->testNow->addDay(),
            'remote_verified_at' => $this->testNow->subMinute(),
        ])->save();
        app(KsefOfflineCertificateService::class)->setPreferred($certificate, $environment);

        return $certificate->fresh();
    }

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_OFFLINE_SUBMISSION_API_TOKEN',
            'access_token' => 'FAKE_OFFLINE_SUBMISSION_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_OFFLINE_SUBMISSION_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function decryptInvoice(KsefOnlineSessionApiFake $fake): string
    {
        $key = $fake->privateKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->decrypt(base64_decode(data_get($fake->openPayload, 'encryption.encryptedSymmetricKey'), true));
        $plaintext = openssl_decrypt(
            base64_decode($fake->sendPayload['encryptedInvoiceContent'], true),
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode(data_get($fake->openPayload, 'encryption.initializationVector'), true),
        );

        $this->assertIsString($plaintext);

        return $plaintext;
    }

    /** @return array<string, mixed> */
    private function acceptedStatus(string $number, KsefInvoicingMode $mode): array
    {
        return [
            'status' => ['code' => 200, 'description' => 'Przyjęto'],
            'ksefNumber' => $number,
            'invoicingMode' => $mode->value,
            'acquisitionDate' => $this->testNow->addMinute()->toIso8601String(),
            'invoicingDate' => $this->testNow->toIso8601String(),
            'permanentStorageDate' => $this->testNow->addMinutes(2)->toIso8601String(),
        ];
    }

    private function validKsefNumber(string $sellerNip, string $date): string
    {
        $base = $sellerNip.'-'.$date.'-0100001AF629';
        $checksum = 0;
        foreach (str_split($base) as $character) {
            $checksum ^= ord($character);
            for ($bit = 0; $bit < 8; $bit++) {
                $checksum = ($checksum & 0x80) !== 0
                    ? (($checksum << 1) ^ 0x07) & 0xFF
                    : ($checksum << 1) & 0xFF;
            }
        }

        return $base.'-'.strtoupper(str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT));
    }

    private function expectKsefError(string $safeCode, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected KSeF exception was not thrown.');
        } catch (KsefApiException $exception) {
            $this->assertSame($safeCode, $exception->safeCode);
        }
    }
}
