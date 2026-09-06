<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefInvoicingMode;
use Modules\Ksef\Enums\KsefLatarniaEvidenceCoverage;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Enums\KsefOfflineSubmissionObligationStatus;
use Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefOfflineCertificate;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\Fa3\KsefFa3InvoiceMapper;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\Services\Fa3\KsefFa3OptionalBlocksResolver;
use Modules\Ksef\Services\Fa3\KsefFa3SchemaValidator;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use Modules\Ksef\Services\KsefAcceptedOfflineInvoicePdfService;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefOfflineCertificateService;
use Modules\Ksef\Services\KsefOfflineInvoiceSubmissionService;
use Modules\Ksef\Services\KsefOfflineIssuanceService;
use Modules\Ksef\Services\KsefOfflinePresentationDataExtractor;
use Modules\Ksef\Services\KsefOfflinePresentationPdfRenderer;
use Modules\Ksef\Services\KsefOfflineSubmissionIntegrityService;
use Modules\Ksef\Services\KsefOfflineSubmissionObligationEngine;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionBusinessFingerprintService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionEligibilityService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionIntegrityService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionService;
use Modules\Ksef\Services\KsefOfflineTechnicalCorrectionSubmissionService;
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
        $rawIssuedAt = DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->value('issued_at');
        $rawGeneratedAt = DB::table('ksef_invoice_submissions')
            ->where('id', $submission->getKey())
            ->value('generated_at');
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
        $this->assertSame($issuance->issued_at->format('Y-m-d H:i:s'), $rawIssuedAt);
        $this->assertSame($rawIssuedAt, $rawGeneratedAt);
        $this->assertTrue(
            app(KsefOfflineSubmissionIntegrityService::class)->linkedIssuance($submission)->is($issuance),
        );
        $this->assertNotSame($issuance->payload_xml, $rawPayload);
        $this->assertStringNotContainsString('<Faktura', $rawPayload);
        $this->assertTrue($submission->offlineIssuance->is($issuance));
        $this->assertTrue($issuance->submissions()->firstOrFail()->is($submission));
        $this->assertSame(KsefInvoicingMode::Offline, $submission->expectedInvoicingMode());
        Http::assertNothingSent();
    }

    public function test_technical_correction_migration_is_reversible_on_the_isolated_test_database(): void
    {
        $migration = require database_path('migrations/2026_08_13_088000_create_ksef_offline_technical_corrections.php');

        $this->assertTrue(Schema::hasTable('ksef_offline_technical_corrections'));
        $this->assertTrue(Schema::hasColumns('ksef_offline_technical_corrections', [
            'invoice_id',
            'offline_issuance_id',
            'rejected_submission_id',
            'environment',
            'context_nip',
            'seller_nip',
            'schema_id',
            'generated_at',
            'payload_xml',
            'invoice_hash',
            'invoice_size',
            'hash_of_corrected_invoice',
            'source_status_code',
            'eligibility_policy_version',
            'business_fingerprint',
            'business_fingerprint_version',
        ]));
        $this->assertTrue(Schema::hasColumn('ksef_invoice_submissions', 'offline_technical_correction_id'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('ksef_offline_technical_corrections'));
        $this->assertFalse(Schema::hasColumn('ksef_invoice_submissions', 'offline_technical_correction_id'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ksef_offline_technical_corrections'));
        $this->assertTrue(Schema::hasColumn('ksef_invoice_submissions', 'offline_technical_correction_id'));
        Http::assertNothingSent();
    }

    #[DataProvider('fallOfflineInstantProvider')]
    public function test_prepare_preserves_each_fall_repeated_hour_instant_and_exact_integrity(
        string $instantValue,
        string $expectedRaw,
    ): void {
        [$invoice, $issuance] = $this->issueOffline();
        DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->update(['issued_at' => $expectedRaw]);
        $issuance = $issuance->fresh();

        $submission = app(KsefOfflineInvoiceSubmissionService::class)
            ->prepare($invoice, $issuance)
            ->fresh();
        $linked = app(KsefOfflineSubmissionIntegrityService::class)->linkedIssuance($submission);
        $expected = CarbonImmutable::parse($instantValue);

        $this->assertSame($expectedRaw, DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->value('issued_at'));
        $this->assertSame($expectedRaw, DB::table('ksef_invoice_submissions')->where('id', $submission->getKey())->value('generated_at'));
        $this->assertSame($expected->getTimestamp(), $issuance->issued_at->getTimestamp());
        $this->assertSame($expected->getTimestamp(), $submission->generated_at->getTimestamp());
        $this->assertSame($issuance->issued_at->getTimestamp(), $submission->generated_at->getTimestamp());
        $this->assertTrue($linked->is($issuance));
        Http::assertNothingSent();
    }

    public static function fallOfflineInstantProvider(): array
    {
        return [
            'first repeated-hour instant' => ['2026-10-25T00:30:00Z', '2026-10-25 00:30:00'],
            'second repeated-hour instant' => ['2026-10-25T01:30:00Z', '2026-10-25 01:30:00'],
        ];
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

    #[DataProvider('offlineProcedureProvider')]
    public function test_legitimate_offline_acceptance_schedules_upo_dispatches_event_and_builds_one_qr_pdf(
        KsefOfflineIssuanceProcedure $procedure,
    ): void {
        Event::fake([KsefInvoiceAccepted::class]);
        [$invoice, $issuance] = $this->issueOffline();
        DB::table('ksef_offline_issuances')
            ->where('id', $issuance->getKey())
            ->update(['procedure' => $procedure->value]);
        $issuance = $issuance->fresh();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        $submission = app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($invoice, $issuance);
        $this->assertTrue($fake->sendPayload['offlineMode']);
        $this->assertArrayNotHasKey('hashOfCorrectedInvoice', $fake->sendPayload);
        $this->assertSame($issuance->payload_xml, $this->decryptInvoice($fake));
        $this->assertSame($issuance->issued_at->getTimestamp(), $submission->generated_at->getTimestamp());
        $this->assertSame($issuance->getKey(), $submission->offline_issuance_id);
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

    public static function offlineProcedureProvider(): array
    {
        return [
            'Offline24' => [KsefOfflineIssuanceProcedure::Offline24],
            'planned unavailability' => [KsefOfflineIssuanceProcedure::PlannedUnavailability],
            'ordinary failure' => [KsefOfflineIssuanceProcedure::Failure],
        ];
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

    public function test_technical_prepare_regenerates_current_xsd_from_the_frozen_invoice_without_trusting_broken_source_xml(): void
    {
        [$invoice, $issuance, $source, $brokenXml] = $this->rejectedOfflineSource(450);
        $originalInvoiceUrl = $issuance->invoice_verification_url;
        $originalCertificateUrl = $issuance->certificate_verification_url;
        $invoice->order()->update(['billing_name' => 'LATER ORDER VALUE MUST NOT BE USED']);

        try {
            app(KsefFa3SchemaValidator::class)->validate($brokenXml);
            $this->fail('The synthetic rejected source should not pass the current XSD.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('ksef_fa3_schema_validation_failed', $exception->errorCode());
        }

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('PRZYGOTUJ KOREKTĘ TECHNICZNĄ')
            ->assertDontSee('PONÓW TRANSMISJĘ OFFLINE24');

        $artifact = app(KsefOfflineTechnicalCorrectionService::class)
            ->prepare($invoice, $issuance, $source)
            ->fresh();

        app(KsefFa3SchemaValidator::class)->validate($artifact->payload_xml);
        app(KsefOfflineTechnicalCorrectionIntegrityService::class)->assertArtifact($artifact);
        $rawPayload = DB::table('ksef_offline_technical_corrections')
            ->where('id', $artifact->getKey())
            ->value('payload_xml');

        $this->assertSame($invoice->issue_date->toDateString(), app(KsefFa3IssueDateReader::class)->read($artifact->payload_xml));
        $this->assertSame($invoice->number, $this->fa3Value($artifact->payload_xml, '/fa:Faktura/fa:Fa/fa:P_2'));
        $this->assertSame($invoice->buyer_name_snapshot, $this->fa3Value($artifact->payload_xml, '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa'));
        $this->assertSame($invoice->currency, $this->fa3Value($artifact->payload_xml, '/fa:Faktura/fa:Fa/fa:KodWaluty'));
        $this->assertSame($invoice->total_gross, $this->fa3Value($artifact->payload_xml, '/fa:Faktura/fa:Fa/fa:P_15'));
        $this->assertSame($this->hash($artifact->payload_xml), $artifact->invoice_hash);
        $this->assertSame(strlen($artifact->payload_xml), $artifact->invoice_size);
        $this->assertSame($issuance->invoice_hash, $artifact->hash_of_corrected_invoice);
        $this->assertSame(450, $artifact->source_status_code);
        $this->assertSame(1, $artifact->eligibility_policy_version);
        $this->assertSame(1, $artifact->business_fingerprint_version);
        $this->assertSame(44, strlen($artifact->business_fingerprint));
        $this->assertSame(
            $artifact->business_fingerprint,
            app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class)
                ->fromInvoice($invoice, $artifact->business_fingerprint_version),
        );
        $this->assertSame(
            $artifact->business_fingerprint,
            app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class)
                ->fromPayload($artifact->payload_xml, $artifact->business_fingerprint_version),
        );
        $this->assertNotSame($artifact->invoice_hash, $artifact->hash_of_corrected_invoice);
        $this->assertNotSame($artifact->payload_xml, $rawPayload);
        $this->assertStringNotContainsString('<Faktura', $rawPayload);
        $this->assertSame($brokenXml, $issuance->fresh()->payload_xml);
        $this->assertSame($originalInvoiceUrl, $issuance->fresh()->invoice_verification_url);
        $this->assertSame($originalCertificateUrl, $issuance->fresh()->certificate_verification_url);
        $this->assertSame(KsefInvoiceSubmissionStatus::Rejected, $source->fresh()->status);
        $this->assertDatabaseCount('ksef_offline_technical_corrections', 1);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('PRZEŚLIJ KOREKTĘ TECHNICZNĄ DO KSeF TEST')
            ->assertDontSee('PRZYGOTUJ KOREKTĘ TECHNICZNĄ')
            ->assertDontSee('PONÓW TRANSMISJĘ OFFLINE24')
            ->assertDontSee('PRZEŚLIJ OFFLINE24 DO KSeF');

        try {
            app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
            $this->fail('Second technical prepare should be blocked.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_technical_correction_already_prepared', $exception->safeCode);
        }

        try {
            $artifact->forceFill(['seller_nip' => '5260250995'])->save();
            $this->fail('Technical artifact update should be immutable.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        Http::assertNothingSent();
    }

    public function test_technical_prepare_rejects_kor_in_r1(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $invoice->forceFill(['document_type' => InvoiceDocumentType::Correction])->saveQuietly();
        $this->expectKsefError(
            'ksef_technical_correction_document_type_not_supported',
            fn () => app(KsefOfflineTechnicalCorrectionService::class)
                ->prepare($invoice->fresh(), $issuance, $source),
        );

        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        Http::assertNothingSent();
    }

    public function test_technical_prepare_rejects_an_accepted_sibling(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'offline_issuance_id' => $issuance->getKey(),
            'environment' => $issuance->environment,
            'context_nip' => $issuance->context_identifier_value,
            'seller_nip' => $issuance->seller_nip,
            'attempt_number' => 2,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => $issuance->schema_id,
            'generated_at' => $issuance->issued_at,
            'payload_xml' => $issuance->payload_xml,
            'invoice_hash' => $issuance->invoice_hash,
            'invoice_size' => $issuance->invoice_size,
            'ksef_number' => KsefUpoFixture::ksefNumber($issuance->seller_nip),
            'invoicing_mode' => KsefInvoicingMode::Offline,
        ]);
        $this->expectKsefError(
            'ksef_technical_correction_already_accepted',
            fn () => app(KsefOfflineTechnicalCorrectionService::class)
                ->prepare($invoice, $issuance, $source),
        );

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertDontSee('PRZYGOTUJ KOREKTĘ TECHNICZNĄ');
        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        Http::assertNothingSent();
    }

    public function test_technical_prepare_rejects_an_unchanged_payload(): void
    {
        [$invoice, $issuance] = $this->issueOffline();
        $source = app(KsefOfflineInvoiceSubmissionService::class)->prepare($invoice, $issuance);
        $source->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Rejected,
            'ksef_status_code' => 450,
        ])->save();
        $this->expectKsefError(
            'ksef_technical_correction_payload_unchanged',
            fn () => app(KsefOfflineTechnicalCorrectionService::class)
                ->prepare($invoice, $issuance, $source),
        );

        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('sourceFreezeTampering')]
    public function test_technical_prepare_rejects_source_freeze_tampering(
        string $field,
        mixed $value,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $source->forceFill([$field => $value])->save();

        $this->expectKsefError(
            'ksef_technical_correction_source_integrity_invalid',
            fn () => app(KsefOfflineTechnicalCorrectionService::class)
                ->prepare($invoice, $issuance, $source->fresh()),
        );

        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        Http::assertNothingSent();
    }

    public static function sourceFreezeTampering(): array
    {
        return [
            'issuance link' => ['offline_issuance_id', null],
            'environment' => ['environment', KsefEnvironment::Demo],
            'context' => ['context_nip', '5260250995'],
            'seller' => ['seller_nip', '5260250995'],
            'payload' => ['payload_xml', '<Faktura>TAMPERED</Faktura>'],
            'hash' => ['invoice_hash', str_repeat('A', 44)],
            'size' => ['invoice_size', 1],
        ];
    }

    public function test_tampered_technical_artifact_is_blocked_before_submission_and_http(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        DB::table('ksef_offline_technical_corrections')
            ->where('id', $artifact->getKey())
            ->update(['invoice_size' => $artifact->invoice_size + 1]);

        $this->expectKsefError(
            'ksef_technical_correction_integrity_invalid',
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact->fresh()),
        );

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('blockedTechnicalCorrectionSources')]
    public function test_technical_prepare_fails_closed_for_noneligible_codes_and_nonrejected_states(
        int $statusCode,
        KsefInvoiceSubmissionStatus $status,
        string $expectedCode,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource($statusCode);
        if ($status !== KsefInvoiceSubmissionStatus::Rejected) {
            $source->forceFill(['status' => $status])->save();
        }

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineTechnicalCorrectionService::class)->prepare(
                $invoice,
                $issuance,
                $source->fresh(),
            ),
        );

        $response = $this->get(route('invoices.edit', $invoice))->assertOk();
        $response->assertDontSee('PRZYGOTUJ KOREKTĘ TECHNICZNĄ');
        if ($status === KsefInvoiceSubmissionStatus::Rejected) {
            $response->assertSee($statusCode === 410
                ? 'To odrzucenie nie kwalifikuje się do korekty technicznej KSeF.'
                : 'Nie można jednoznacznie potwierdzić, że dokument kwalifikuje się do korekty technicznej KSeF.');
        }

        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        Http::assertNothingSent();
    }

    public static function blockedTechnicalCorrectionSources(): array
    {
        return [
            'invalid permissions' => [410, KsefInvoiceSubmissionStatus::Rejected, 'ksef_technical_correction_source_nontechnical'],
            'unknown code' => [500, KsefInvoiceSubmissionStatus::Rejected, 'ksef_technical_correction_source_unconfirmed'],
            'accepted state' => [450, KsefInvoiceSubmissionStatus::Accepted, 'ksef_technical_correction_source_integrity_invalid'],
            'processing state' => [450, KsefInvoiceSubmissionStatus::Processing, 'ksef_technical_correction_source_integrity_invalid'],
            'uncertain state' => [450, KsefInvoiceSubmissionStatus::Uncertain, 'ksef_technical_correction_source_integrity_invalid'],
            'technical failed state' => [450, KsefInvoiceSubmissionStatus::TechnicalFailed, 'ksef_technical_correction_source_integrity_invalid'],
        ];
    }

    public function test_technical_submission_sends_once_accepts_offline_and_uses_corrected_payload_for_pdf_upo_and_obligation(): void
    {
        [$invoice, $issuance, $source, $originalPayload] = $this->rejectedOfflineSource(450);
        $originalHash = $issuance->invoice_hash;
        $originalSize = $issuance->invoice_size;
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $this->disableCurrentInvoiceProjectionServices();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;

        $submission = app(KsefOfflineTechnicalCorrectionSubmissionService::class)
            ->submitAttempt($invoice, $artifact)
            ->fresh();

        $this->assertSame(2, $submission->attempt_number);
        $this->assertSame($artifact->getKey(), $submission->offline_technical_correction_id);
        $this->assertSame($issuance->getKey(), $submission->offline_issuance_id);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertTrue($fake->sendPayload['offlineMode']);
        $this->assertSame($artifact->invoice_hash, $fake->sendPayload['invoiceHash']);
        $this->assertSame($artifact->invoice_size, $fake->sendPayload['invoiceSize']);
        $this->assertSame($originalHash, $fake->sendPayload['hashOfCorrectedInvoice']);
        $this->assertSame($artifact->payload_xml, $this->decryptInvoice($fake));
        $this->assertSame(1, $fake->sendCalls);

        try {
            app(KsefOfflineTechnicalCorrectionSubmissionService::class)->submitAttempt($invoice, $artifact);
            $this->fail('Second technical POST attempt should be blocked.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_technical_correction_submission_attempt_blocked', $exception->safeCode);
        }
        $this->assertSame(1, $fake->sendCalls);

        $ksefNumber = KsefUpoFixture::ksefNumber($issuance->seller_nip);
        $fake->statusResponse = $this->acceptedStatus($ksefNumber, KsefInvoicingMode::Offline);
        $accepted = app(KsefInvoiceSubmissionService::class)->refreshStatus($submission)->fresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $accepted->status);
        $this->assertSame(KsefInvoicingMode::Offline, $accepted->invoicing_mode);

        $pdf = app(KsefAcceptedOfflineInvoicePdfService::class)->document($invoice, $issuance, $accepted);
        $presentation = app(KsefOfflinePresentationDataExtractor::class)
            ->extractTechnical($issuance, $artifact);
        $blocks = app(KsefOfflinePresentationPdfRenderer::class)
            ->acceptedOfflineInvoiceQrBlocks($presentation, $ksefNumber);
        $this->assertStringStartsWith('%PDF-', $pdf['contents']);
        $this->assertCount(1, $blocks);
        $this->assertSame('KOD I', $blocks[0]['heading']);
        $this->assertSame($ksefNumber, $blocks[0]['label']);
        $this->assertStringContainsString(rtrim(strtr($artifact->invoice_hash, '+/', '-_'), '='), $blocks[0]['payload']);

        $fake->upoResponse = KsefUpoFixture::xml([
            'context_nip' => $accepted->context_nip,
            'seller_nip' => $accepted->seller_nip,
            'session_reference' => $accepted->session_reference_number,
            'ksef_number' => $accepted->ksef_number,
            'invoice_number' => $invoice->number,
            'invoice_hash' => $accepted->invoice_hash,
            'mode' => 'Offline',
        ]);
        $upo = app(KsefInvoiceUpoService::class)->fetch($invoice, $accepted);
        $this->assertSame($accepted->getKey(), $upo->ksef_invoice_submission_id);
        $this->assertStringContainsString('<TrybWysylki>Offline</TrybWysylki>', $upo->payload_xml);
        $this->assertStringContainsString($artifact->invoice_hash, $upo->payload_xml);

        $obligation = app(KsefOfflineSubmissionObligationEngine::class)->evaluate(
            $issuance,
            [],
            KsefInvoiceSubmission::query()->where('offline_issuance_id', $issuance->getKey())->get(),
            $this->testNow->addDay(),
            KsefLatarniaEvidenceCoverage::Complete,
        );
        $this->assertSame(KsefOfflineSubmissionObligationStatus::Fulfilled, $obligation->status);
        $this->assertSame($originalPayload, $issuance->fresh()->payload_xml);
        $this->assertSame($originalHash, $issuance->fresh()->invoice_hash);
        $this->assertSame($originalSize, $issuance->fresh()->invoice_size);
        $this->assertSame(KsefInvoiceSubmissionStatus::Rejected, $source->fresh()->status);
        $this->assertDatabaseCount('ksef_offline_technical_corrections', 1);
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(1, $fake->sendCalls);
    }

    #[DataProvider('deterministicTechnicalSendFailures')]
    public function test_official_technical_errors_are_terminal_and_never_retried(
        string $reasonCode,
        string $expectedSafeCode,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/sessions/online/20260819-SO-TEST-REFERENCE/invoices'] = [
            'status' => 400,
            'body' => ['reasonCode' => $reasonCode],
        ];

        $this->expectKsefError(
            $expectedSafeCode,
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact),
        );

        $submission = $artifact->submission()->firstOrFail();
        $this->assertSame(KsefInvoiceSubmissionStatus::TechnicalFailed, $submission->status);
        $this->assertSame($expectedSafeCode, $submission->safe_error_code);
        $this->assertSame(1, $fake->sendCalls);
        $this->expectKsefError(
            'ksef_technical_correction_submission_attempt_blocked',
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact),
        );
        $this->assertSame(1, $fake->sendCalls);
    }

    public static function deterministicTechnicalSendFailures(): array
    {
        return [
            '21166 unavailable' => ['21166', 'ksef_technical_correction_unavailable'],
            '21167 source status' => ['21167', 'ksef_technical_correction_source_status_invalid'],
        ];
    }

    public function test_uncertain_technical_post_requires_reconciliation_and_never_creates_a_second_attempt(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(440);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/sessions/online/20260819-SO-TEST-REFERENCE/invoices'] = ['connection' => true];

        $this->expectKsefError(
            'network_error',
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact),
        );

        $submission = $artifact->submission()->firstOrFail();
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertTrue($submission->status->allowsReconciliation());
        $this->assertSame(1, $fake->sendCalls);
        $this->expectKsefError(
            'ksef_technical_correction_submission_attempt_blocked',
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact),
        );
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(1, $fake->sendCalls);
    }

    public function test_technical_prepare_rolls_back_when_invoice_and_payload_business_fingerprints_differ(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $fingerprints = Mockery::mock(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class);
        $fingerprints->shouldReceive('fromInvoice')->once()->andReturn($this->hash('invoice-business'));
        $fingerprints->shouldReceive('fromPayload')->once()->andReturn($this->hash('payload-business'));
        $this->app->instance(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class, $fingerprints);

        $this->expectKsefError(
            'ksef_technical_correction_business_semantics_mismatch',
            fn () => app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source),
        );

        $this->assertDatabaseCount('ksef_offline_technical_corrections', 0);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public function test_historical_artifact_uses_frozen_policy_v1_when_simulated_current_policy_rejects_450(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $futurePolicy = new class extends KsefOfflineTechnicalCorrectionEligibilityService
        {
            public function classify(?int $statusCode): KsefTechnicalCorrectionEligibility
            {
                return KsefTechnicalCorrectionEligibility::Ineligible;
            }
        };
        $this->assertSame(KsefTechnicalCorrectionEligibility::Ineligible, $futurePolicy->classify(450));

        $integrity = new KsefOfflineTechnicalCorrectionIntegrityService(
            $futurePolicy,
            app(KsefFa3SchemaValidator::class),
            app(KsefFa3IssueDateReader::class),
            app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class),
        );
        $integrity->assertArtifact($artifact, $invoice, $issuance, $source);

        $this->assertTrue(true);
        Http::assertNothingSent();
    }

    public function test_historical_artifact_integrity_does_not_use_the_current_mapper_options_or_generator(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);

        $this->disableCurrentInvoiceProjectionServices();

        app(KsefOfflineTechnicalCorrectionIntegrityService::class)
            ->assertArtifact($artifact, $invoice, $issuance, $source);

        $this->assertSame(
            $artifact->business_fingerprint,
            app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class)
                ->fromInvoice($invoice, 1),
        );
        Http::assertNothingSent();
    }

    #[DataProvider('frozenArtifactMetadataTampering')]
    public function test_frozen_policy_and_business_fingerprint_metadata_tampering_fails_closed(
        string $field,
        mixed $value,
        string $expectedCode,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        DB::table('ksef_offline_technical_corrections')
            ->where('id', $artifact->getKey())
            ->update([$field => $value]);

        $this->expectKsefError(
            $expectedCode,
            fn () => app(KsefOfflineTechnicalCorrectionIntegrityService::class)
                ->assertArtifact($artifact->fresh()),
        );

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function frozenArtifactMetadataTampering(): array
    {
        return [
            'unknown eligibility policy' => ['eligibility_policy_version', 999, 'ksef_technical_correction_integrity_invalid'],
            'unknown business fingerprint version' => ['business_fingerprint_version', 999, 'ksef_technical_correction_integrity_invalid'],
            'stored business fingerprint' => [
                'business_fingerprint',
                base64_encode(hash('sha256', 'tampered-business', true)),
                'ksef_technical_correction_business_semantics_mismatch',
            ],
        ];
    }

    public function test_frozen_source_status_code_must_still_match_the_rejected_submission(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $source->forceFill(['ksef_status_code' => 440])->save();

        $this->expectKsefError(
            'ksef_technical_correction_integrity_invalid',
            fn () => app(KsefOfflineTechnicalCorrectionIntegrityService::class)
                ->assertArtifact($artifact, $invoice, $issuance, $source->fresh()),
        );

        Http::assertNothingSent();
    }

    #[DataProvider('technicalPayloadBusinessTampering')]
    public function test_business_payload_tampering_is_blocked_after_outer_hash_and_size_are_recomputed(
        string $expression,
        string $replacement,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $tamperedPayload = $this->replaceFa3Value($artifact->payload_xml, $expression, $replacement);
        app(KsefFa3SchemaValidator::class)->validate($tamperedPayload);
        DB::table('ksef_offline_technical_corrections')
            ->where('id', $artifact->getKey())
            ->update([
                'payload_xml' => Crypt::encryptString($tamperedPayload),
                'invoice_hash' => $this->hash($tamperedPayload),
                'invoice_size' => strlen($tamperedPayload),
            ]);

        $this->expectKsefError(
            'ksef_technical_correction_business_semantics_mismatch',
            fn () => app(KsefOfflineTechnicalCorrectionSubmissionService::class)
                ->submitAttempt($invoice, $artifact->fresh()),
        );

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function technicalPayloadBusinessTampering(): array
    {
        return [
            'buyer' => ['/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa', 'Changed Buyer SA'],
            'currency' => ['/fa:Faktura/fa:Fa/fa:KodWaluty', 'EUR'],
            'quantity' => ['/fa:Faktura/fa:Fa/fa:FaWiersz[1]/fa:P_8B', '2'],
            'unit price' => ['/fa:Faktura/fa:Fa/fa:FaWiersz[1]/fa:P_9A', '101.00'],
            'VAT treatment' => ['/fa:Faktura/fa:Fa/fa:FaWiersz[1]/fa:P_12', '8'],
            'VAT summary' => ['/fa:Faktura/fa:Fa/fa:P_14_1', '24.00'],
            'gross total' => ['/fa:Faktura/fa:Fa/fa:P_15', '124.00'],
        ];
    }

    public function test_immutable_invoice_snapshot_tampering_breaks_the_three_way_business_invariant(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $buyer = $invoice->buyer_snapshot;
        $buyer['company_name'] = 'Tampered immutable buyer';
        DB::table('invoices')->where('id', $invoice->getKey())->update([
            'buyer_snapshot' => json_encode($buyer, JSON_THROW_ON_ERROR),
        ]);

        $this->expectKsefError(
            'ksef_technical_correction_business_semantics_mismatch',
            fn () => app(KsefOfflineTechnicalCorrectionIntegrityService::class)
                ->assertArtifact($artifact, $invoice->fresh(), $issuance, $source),
        );

        Http::assertNothingSent();
    }

    public function test_current_order_item_series_and_ksef_settings_drift_do_not_change_the_frozen_invoice_fingerprint(): void
    {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        DB::table('orders')->where('id', $invoice->order_id)->update([
            'billing_name' => 'Later mutable customer and address value',
        ]);
        DB::table('order_items')->where('order_id', $invoice->order_id)->update([
            'product_name' => 'Later mutable product value',
        ]);
        $invoice->series()->update([
            'seller_name' => 'Later mutable seller value',
            'seller_bank_account' => 'INVALID-LIVE-ACCOUNT',
        ]);
        app(KsefSettingsService::class)->get()->forceFill([
            'include_recipient_data' => false,
            'include_buyer_contact_data' => false,
            'include_additional_information' => false,
            'include_order_reference' => false,
            'include_bank_account' => false,
            'include_gtu' => false,
            'include_seller_vat_prefix' => true,
        ])->save();

        app(KsefOfflineTechnicalCorrectionIntegrityService::class)
            ->assertArtifact($artifact, $invoice->fresh(), $issuance, $source);

        $this->assertSame(
            $artifact->business_fingerprint,
            app(KsefOfflineTechnicalCorrectionBusinessFingerprintService::class)
                ->fromInvoice($invoice->fresh(), 1),
        );
        Http::assertNothingSent();
    }

    #[DataProvider('ordinaryResendBlockedTechnicalStates')]
    public function test_ordinary_offline_resend_is_blocked_after_technical_remediation_exists(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $technical = app(KsefOfflineTechnicalCorrectionSubmissionService::class)->prepare($invoice, $artifact);
        $technical->forceFill(['status' => $status])->save();
        $count = KsefInvoiceSubmission::query()->count();

        $this->expectKsefError(
            'ksef_offline_submission_technical_remediation_exists',
            fn () => app(KsefOfflineInvoiceSubmissionService::class)->submitAttempt($invoice, $issuance),
        );

        $this->assertSame($count, KsefInvoiceSubmission::query()->count());
        Http::assertNothingSent();
    }

    public static function ordinaryResendBlockedTechnicalStates(): array
    {
        return [
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
        ];
    }

    #[DataProvider('technicalUiStates')]
    public function test_technical_history_never_exposes_ordinary_resend_or_a_second_technical_send(
        KsefInvoiceSubmissionStatus $status,
        bool $manualAnalysis,
    ): void {
        [$invoice, $issuance, $source] = $this->rejectedOfflineSource(450);
        $artifact = app(KsefOfflineTechnicalCorrectionService::class)->prepare($invoice, $issuance, $source);
        $technical = app(KsefOfflineTechnicalCorrectionSubmissionService::class)->prepare($invoice, $artifact);
        $attributes = ['status' => $status];
        if ($status === KsefInvoiceSubmissionStatus::Accepted) {
            $attributes += [
                'invoicing_mode' => KsefInvoicingMode::Offline,
                'ksef_number' => $this->validKsefNumber($issuance->seller_nip, $issuance->issue_date->format('Ymd')),
            ];
        }
        $technical->forceFill($attributes)->save();

        $response = $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertDontSee('PONÓW TRANSMISJĘ OFFLINE24')
            ->assertDontSee('PRZEŚLIJ OFFLINE24 DO KSeF')
            ->assertDontSee('PRZEŚLIJ KOREKTĘ TECHNICZNĄ DO KSeF');

        if ($manualAnalysis) {
            $response->assertSee('Korekta techniczna nie została przyjęta. Dalsza transmisja wymaga ręcznej analizy.');
        }
        Http::assertNothingSent();
    }

    public static function technicalUiStates(): array
    {
        return [
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted, false],
            'processing' => [KsefInvoiceSubmissionStatus::Processing, false],
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain, false],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed, true],
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected, true],
            'accepted' => [KsefInvoiceSubmissionStatus::Accepted, false],
        ];
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

    /** @return array{0: Invoice, 1: KsefOfflineIssuance, 2: KsefInvoiceSubmission, 3: string} */
    private function rejectedOfflineSource(int $statusCode): array
    {
        [$invoice, $issuance] = $this->issueOffline();
        $source = app(KsefOfflineInvoiceSubmissionService::class)->prepare($invoice, $issuance);
        $source->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Rejected,
            'ksef_status_code' => $statusCode,
            'safe_error_code' => 'ksef_invoice_rejected',
            'safe_error_message' => 'Synthetic rejected Offline invoice.',
        ])->save();

        $date = $invoice->issue_date->toDateString();
        $brokenXml = str_replace(
            '<P_1>'.$date.'</P_1>',
            '<P_1>NOT-A-DATE</P_1>',
            $issuance->payload_xml,
        );
        $this->assertNotSame($issuance->payload_xml, $brokenXml);
        $hash = $this->hash($brokenXml);
        $encrypted = Crypt::encryptString($brokenXml);
        DB::table('ksef_offline_issuances')->where('id', $issuance->getKey())->update([
            'payload_xml' => $encrypted,
            'invoice_hash' => $hash,
            'invoice_size' => strlen($brokenXml),
        ]);
        DB::table('ksef_invoice_submissions')->where('id', $source->getKey())->update([
            'payload_xml' => $encrypted,
            'invoice_hash' => $hash,
            'invoice_size' => strlen($brokenXml),
        ]);

        return [$invoice->fresh()->load('items'), $issuance->fresh(), $source->fresh(), $brokenXml];
    }

    private function fa3Value(string $xml, string $expression): string
    {
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length);

        return trim($nodes->item(0)?->textContent ?? '');
    }

    private function replaceFa3Value(string $xml, string $expression, string $replacement): string
    {
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('fa', KsefFa3XmlBuilder::NAMESPACE);
        $nodes = $xpath->query($expression);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length);
        $nodes->item(0)->textContent = $replacement;
        $payload = $document->saveXML();
        $this->assertIsString($payload);

        return $payload;
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }

    private function disableCurrentInvoiceProjectionServices(): void
    {
        foreach ([
            KsefFa3InvoiceMapper::class => 'map',
            KsefFa3OptionalBlocksResolver::class => 'resolve',
            KsefFa3DocumentGenerator::class => 'generate',
        ] as $service => $method) {
            $mock = Mockery::mock($service);
            $mock->shouldNotReceive($method);
            $this->app->instance($service, $mock);
        }
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
