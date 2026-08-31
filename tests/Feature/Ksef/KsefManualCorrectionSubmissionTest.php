<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Events\KsefInvoiceAccepted;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Jobs\KsefSubmissionFollowUpJob;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionPolicy;
use Modules\Ksef\Services\KsefInvoiceSourceService;
use Modules\Ksef\Services\KsefInvoiceStatusFollowUpService;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefInvoiceUpoService;
use Modules\Ksef\Services\KsefManualCorrectionSubmissionService;
use Modules\Ksef\Services\KsefOperationalEnvironmentPolicy;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefSubmissionFollowUpProcessor;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;
use Throwable;

class KsefManualCorrectionSubmissionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_first_manual_correction_send_atomically_finalizes_prepares_and_reuses_transport(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->validAccessToken(KsefEnvironment::Production);
        $fake = $this->fakeOnlineApi();

        $submission = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $correction,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
        );

        $this->assertTrue($correction->fresh()->isFinalized());
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $correction->getKey()]);
        $this->assertSame($correction->getKey(), $submission->invoice_id);
        $this->assertSame(KsefEnvironment::Production, $submission->environment);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('FA (3) 1-0E', $submission->schema_id);
        $this->assertSame(KsefUpoFixture::CONTEXT_NIP, $submission->context_nip);
        $this->assertSame(KsefUpoFixture::SELLER_NIP, $submission->seller_nip);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertStringContainsString('<RodzajFaktury>KOR</RodzajFaktury>', $submission->payload_xml);
        $this->assertSame($submission->payload_xml, $this->decryptSentXml($fake));
        $this->assertSame($submission->invoice_hash, $fake->sendPayload['invoiceHash']);
        $this->assertSame($submission->invoice_size, $fake->sendPayload['invoiceSize']);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
    }

    public function test_failed_source_resolution_rolls_back_finalization_slot_and_submission_without_http(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->assertManualFailure(
            $correction,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
            'ksef_fa3_correction_source_ksef_unresolved',
        );
    }

    #[DataProvider('preparationGuards')]
    public function test_preparation_guards_fail_closed_and_roll_back_finalization(
        bool $active,
        bool $seriesEnabled,
        KsefEnvironment $actualEnvironment,
        KsefEnvironment $expectedEnvironment,
        string $actualContext,
        string $expectedContext,
        string $expectedCode,
    ): void {
        $this->configure($actualEnvironment, $active, $seriesEnabled, $actualContext);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, $actualEnvironment);
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->assertManualFailure(
            $correction,
            $expectedEnvironment,
            $expectedContext,
            $expectedCode,
        );
    }

    public static function preparationGuards(): array
    {
        return [
            'inactive integration' => [
                false, true, KsefEnvironment::Test, KsefEnvironment::Test,
                KsefUpoFixture::CONTEXT_NIP, KsefUpoFixture::CONTEXT_NIP,
                'ksef_submission_configuration_inactive',
            ],
            'correction series disabled' => [
                true, false, KsefEnvironment::Test, KsefEnvironment::Test,
                KsefUpoFixture::CONTEXT_NIP, KsefUpoFixture::CONTEXT_NIP,
                'ksef_submission_series_disabled',
            ],
            'environment changed' => [
                true, true, KsefEnvironment::Demo, KsefEnvironment::Test,
                KsefUpoFixture::CONTEXT_NIP, KsefUpoFixture::CONTEXT_NIP,
                'ksef_submission_environment_changed',
            ],
            'context changed' => [
                true, true, KsefEnvironment::Test, KsefEnvironment::Test,
                '1234567890', KsefUpoFixture::CONTEXT_NIP,
                'ksef_submission_context_changed',
            ],
        ];
    }

    public function test_first_attempt_history_is_strictly_isolated_per_correction_and_environment(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $this->createSubmission($correction, KsefEnvironment::Demo, KsefInvoiceSubmissionStatus::TechnicalFailed);
        $this->validAccessToken(KsefEnvironment::Production);
        $fake = $this->fakeOnlineApi();

        $production = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $correction,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
        );

        $this->assertSame(1, $production->attempt_number);
        $this->assertSame(KsefEnvironment::Production, $production->environment);
        $this->assertSame(1, $fake->sendCalls);

        try {
            app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
                $correction,
                KsefEnvironment::Production,
                KsefUpoFixture::CONTEXT_NIP,
            );
            $this->fail('A second first attempt in the same environment should be blocked.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_submission_first_attempt_already_exists', $exception->safeCode);
        }

        $this->assertSame(2, KsefInvoiceSubmission::query()
            ->where('invoice_id', $correction->getKey())
            ->count());
        $this->assertSame(1, $fake->sendCalls);
    }

    public function test_demo_root_never_satisfies_production_source_reference(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->assertManualFailure(
            $correction,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
            'ksef_fa3_correction_source_ksef_environment_mismatch',
        );
    }

    public function test_explicit_outside_ksef_root_allows_first_correction_transport(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Production);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->validAccessToken(KsefEnvironment::Production);
        $this->fakeOnlineApi();

        $submission = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $correction,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
        );

        $this->assertStringContainsString('<NrKSeFN>1</NrKSeFN>', $submission->payload_xml);
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
    }

    public function test_c2_and_c3_reuse_exact_accepted_production_chain_without_upo(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $c1 = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 2));
        $this->acceptKsefDocument($c1, KsefEnvironment::Production);
        $c2 = $this->issueKsefFinancialCorrection($root, 3);
        $this->validAccessToken(KsefEnvironment::Production);
        $fake = $this->fakeOnlineApi();

        $c2Submission = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $c2,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
        );
        $this->markAccepted($c2Submission);
        $c3 = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 4));
        $c3Submission = app(KsefInvoiceSubmissionService::class)->prepareCorrection(
            $c3,
            KsefEnvironment::Production,
            true,
            KsefUpoFixture::CONTEXT_NIP,
        );

        $this->assertSame(1, $fake->sendCalls);
        $this->assertStringContainsString('<NrFaKorygowanej>', $c2Submission->payload_xml);
        $this->assertStringContainsString($root->number, $c2Submission->payload_xml);
        $this->assertStringContainsString($root->number, $c3Submission->payload_xml);
        $this->assertSame(KsefInvoiceSubmissionStatus::Preparing, $c3Submission->status);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
    }

    public function test_c2_rejects_previous_correction_accepted_only_in_demo(): void
    {
        $this->allowProductionEnvironment();
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $c1 = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root, 2));
        $this->acceptKsefDocument($c1, KsefEnvironment::Demo);
        $c2 = $this->issueKsefFinancialCorrection($root, 3);

        $this->assertManualFailure(
            $c2,
            KsefEnvironment::Production,
            KsefUpoFixture::CONTEXT_NIP,
            'ksef_fa3_correction_previous_ksef_environment_mismatch',
        );
    }

    public function test_shared_status_lifecycle_accepts_correction_invalidates_pdf_and_emits_no_invoice_event(): void
    {
        Storage::fake('local');
        Queue::fake();
        Event::fake([KsefInvoiceAccepted::class]);
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->validAccessToken(KsefEnvironment::Test);
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $correction,
            KsefEnvironment::Test,
            KsefUpoFixture::CONTEXT_NIP,
        );

        $fake->statusResponse = $this->processingStatus($submission);
        $processing = app(KsefInvoiceStatusFollowUpService::class)->refresh($correction, $submission);
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $processing->status);

        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction->fresh());
        Storage::disk('local')->put($pdfPath, '%PDF-1.7 stale correction');
        $fake->statusResponse = $this->acceptedStatus($processing);
        $accepted = app(KsefInvoiceStatusFollowUpService::class)->refresh($correction, $processing);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $accepted->status);
        $this->assertSame(KsefUpoFixture::ksefNumber(), $accepted->ksef_number);
        $this->assertNotNull($accepted->acquisition_date);
        $this->assertNull($accepted->safe_error_code);
        $this->assertSame('upo', $accepted->follow_up_action);
        Storage::disk('local')->assertMissing($pdfPath);
        Event::assertNotDispatched(KsefInvoiceAccepted::class);
        $this->assertSame(2, $fake->statusCalls);
    }

    public function test_shared_status_lifecycle_rejects_correction_without_new_status_enum(): void
    {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->validAccessToken(KsefEnvironment::Test);
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
            $correction,
            KsefEnvironment::Test,
            KsefUpoFixture::CONTEXT_NIP,
        );
        $fake->statusResponse = array_replace($this->processingStatus($submission), [
            'status' => ['code' => 450, 'description' => 'Odrzucona'],
        ]);

        $rejected = app(KsefInvoiceSubmissionService::class)->refreshStatus($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Rejected, $rejected->status);
        $this->assertSame('ksef_invoice_rejected', $rejected->safe_error_code);
    }

    public function test_uncertain_correction_is_reconciled_without_blind_invoice_resend(): void
    {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->validAccessToken(KsefEnvironment::Test);
        $fake = $this->fakeOnlineApi();
        $fake->failures['/sessions/online/20260819-SO-TEST-REFERENCE/invoices'] = ['connection' => true];

        try {
            app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
                $correction,
                KsefEnvironment::Test,
                KsefUpoFixture::CONTEXT_NIP,
            );
            $this->fail('An ambiguous send should surface a controlled exception.');
        } catch (KsefApiException) {
            // State assertions below prove that the send was classified as uncertain.
        }

        $submission = KsefInvoiceSubmission::query()
            ->where('invoice_id', $correction->getKey())
            ->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame(1, $fake->sendCalls);
        $fake->failures = [];
        $fake->sessionInvoicesResponse = [
            'invoices' => [[
                'referenceNumber' => KsefUpoFixture::INVOICE_REFERENCE,
                'invoiceHash' => $submission->invoice_hash,
                'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
                'ksefNumber' => KsefUpoFixture::ksefNumber(),
                'acquisitionDate' => '2026-08-21T10:00:01Z',
            ]],
        ];

        $reconciled = app(KsefInvoiceSubmissionService::class)->reconcile($submission);

        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $reconciled->status);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
    }

    public function test_accepted_correction_fetches_individual_upo_with_integrity_checks(): void
    {
        [$correction, $submission] = $this->acceptedCorrectionFixture();
        $this->validAccessToken(KsefEnvironment::Test);
        $upoXml = $this->upoXml($correction, $submission);
        Http::fake(['*' => Http::response($upoXml, 200, [
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $this->hash($upoXml),
        ])]);

        $upo = app(KsefInvoiceUpoService::class)->fetch($correction, $submission);

        $this->assertSame($upoXml, $upo->payload_xml);
        $this->assertSame($this->hash($upoXml), $upo->payload_hash);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
    }

    public function test_accepted_correction_fetches_source_xml_with_integrity_checks(): void
    {
        [$correction, $submission] = $this->acceptedCorrectionFixture();
        $this->validAccessToken(KsefEnvironment::Test);

        Http::fake(['*' => Http::response($submission->payload_xml, 200, [
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $submission->invoice_hash,
        ])]);
        $source = app(KsefInvoiceSourceService::class)->fetch($correction, $submission);

        $this->assertSame($submission->payload_xml, $source->body);
        $this->assertSame($submission->invoice_hash, $source->contentHash);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
    }

    #[DataProvider('invalidCorrectionUpoCases')]
    public function test_correction_upo_rejects_wrong_identity_hash_environment_and_schema(
        array $overrides,
        bool $malformed,
    ): void {
        [$correction, $submission] = $this->acceptedCorrectionFixture();
        $this->validAccessToken(KsefEnvironment::Test);
        $xml = $malformed
            ? '<Potwierdzenie/>'
            : $this->upoXml($correction, $submission, $overrides);
        Http::fake(['*' => Http::response($xml, 200, [
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $this->hash($xml),
        ])]);

        try {
            app(KsefInvoiceUpoService::class)->fetch($correction, $submission);
            $this->fail('A mismatched Correction UPO should be rejected.');
        } catch (KsefApiException) {
            $this->assertDatabaseCount('ksef_invoice_upos', 0);
        }
    }

    public static function invalidCorrectionUpoCases(): array
    {
        return [
            'ordinary invoice number' => [['invoice_number' => 'FV 999/2026'], false],
            'different KSeF number' => [['ksef_number' => KsefUpoFixture::ksefNumber('5260250995')], false],
            'different frozen hash' => [['invoice_hash' => base64_encode(hash('sha256', 'different', true))], false],
            'wrong receiver environment' => [[
                'receiver_name' => 'Ministerstwo Finansów - środowisko produkcyjne (PRD)',
            ], false],
            'invalid XSD document' => [[], true],
        ];
    }

    public function test_correction_source_xml_mismatch_fails_closed(): void
    {
        [$correction, $submission] = $this->acceptedCorrectionFixture();
        $this->validAccessToken(KsefEnvironment::Test);
        $differentXml = '<Faktura>DIFFERENT CORRECTION</Faktura>';
        Http::fake(['*' => Http::response($differentXml, 200, [
            'Content-Type' => 'application/xml',
            'x-ms-meta-hash' => $this->hash($differentXml),
        ])]);

        try {
            app(KsefInvoiceSourceService::class)->fetch($correction, $submission);
            $this->fail('A source XML mismatch should be rejected.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_invoice_source_mismatch', $exception->safeCode);
        }
    }

    public function test_proforma_remains_unsupported_by_upo_and_source_services(): void
    {
        $this->configure(KsefEnvironment::Test);
        $proforma = $this->issueKsefRoot();
        $proforma->forceFill(['document_type' => InvoiceDocumentType::Proforma])->saveQuietly();
        $submission = $this->createSubmission(
            $proforma->fresh(),
            KsefEnvironment::Test,
            KsefInvoiceSubmissionStatus::Accepted,
        );

        foreach ([KsefInvoiceUpoService::class, KsefInvoiceSourceService::class] as $service) {
            try {
                $service === KsefInvoiceUpoService::class
                    ? app($service)->stored($proforma->fresh(), $submission)
                    : app($service)->fetch($proforma->fresh(), $submission);
                $this->fail('Proforma should remain unsupported by KSeF document retrieval.');
            } catch (KsefApiException $exception) {
                $this->assertStringContainsString('document_type_invalid', $exception->safeCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_background_follow_up_processes_correction_submission(): void
    {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $submission = app(KsefInvoiceSubmissionService::class)->prepareCorrection($correction);
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Submitted,
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'follow_up_action' => 'status',
            'next_follow_up_at' => now()->subMinute(),
        ])->save();
        $this->validAccessToken(KsefEnvironment::Test);
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);

        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $submission->follow_up_attempts);
        $this->assertNotNull($submission->last_follow_up_at);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_automatic_first_submission_policy_still_excludes_corrections(): void
    {
        Queue::fake();
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->issueKsefFinancialCorrection($root);
        app(KsefSettingsService::class)->get()->forceFill(['automatic_submission' => true])->save();

        $this->assertNull(app(KsefAutomaticInvoiceSubmissionPolicy::class)->snapshotFor($correction));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    private function configure(
        KsefEnvironment $environment,
        bool $active = true,
        bool $seriesEnabled = true,
        string $contextNip = KsefUpoFixture::CONTEXT_NIP,
    ): KsefSetting {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => $active,
            'automatic_submission' => false,
            'environment' => $environment,
            'context_nip' => $contextNip,
        ])->save();
        $series = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
        KsefSeriesSetting::query()->updateOrCreate(
            ['invoice_series_id' => $series->getKey()],
            ['is_enabled' => $seriesEnabled],
        );

        return $settings->refresh();
    }

    private function validAccessToken(KsefEnvironment $environment): KsefCredential
    {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_CORRECTION_API_TOKEN',
            'access_token' => 'FAKE_CORRECTION_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_CORRECTION_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function allowProductionEnvironment(): void
    {
        $this->app->instance(
            KsefOperationalEnvironmentPolicy::class,
            new class extends KsefOperationalEnvironmentPolicy
            {
                public function allows(KsefEnvironment $environment): bool
                {
                    return true;
                }

                public function assertAllowed(KsefEnvironment $environment): void {}
            },
        );
    }

    private function assertManualFailure(
        Invoice $correction,
        KsefEnvironment $expectedEnvironment,
        string $expectedContextNip,
        string $expectedCode,
    ): void {
        try {
            app(KsefManualCorrectionSubmissionService::class)->submitFirstAttempt(
                $correction,
                $expectedEnvironment,
                $expectedContextNip,
            );
            $this->fail('The manual Correction attempt should fail closed.');
        } catch (Throwable $exception) {
            $code = $exception instanceof KsefApiException
                ? $exception->safeCode
                : ($exception instanceof InvoiceDomainException ? $exception->errorCode() : null);
            $this->assertSame($expectedCode, $code, $exception->getMessage());
        }

        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $correction->getKey()]);
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $correction->getKey()]);
        Http::assertNothingSent();
    }

    private function createSubmission(
        Invoice $document,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>HISTORY '.$environment->value.'</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $document->getKey(),
            'environment' => $environment,
            'context_nip' => KsefUpoFixture::CONTEXT_NIP,
            'seller_nip' => KsefUpoFixture::SELLER_NIP,
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinute(),
            'payload_xml' => $payload,
            'invoice_hash' => $this->hash($payload),
            'invoice_size' => strlen($payload),
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'ksef_number' => $status === KsefInvoiceSubmissionStatus::Accepted
                ? KsefUpoFixture::ksefNumber()
                : null,
        ]);
    }

    private function markAccepted(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now(),
        ])->save();

        return $submission->refresh();
    }

    /** @return array{0: Invoice, 1: KsefInvoiceSubmission} */
    private function acceptedCorrectionFixture(): array
    {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->finalizeKsefCorrection($this->issueKsefFinancialCorrection($root));
        $submission = app(KsefInvoiceSubmissionService::class)->prepareCorrection(
            $correction,
            KsefEnvironment::Test,
            true,
            KsefUpoFixture::CONTEXT_NIP,
        );
        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now()->subSecond(),
        ])->save();

        return [$correction->fresh(), $submission->refresh()];
    }

    /** @param array<string, string|bool> $overrides */
    private function upoXml(
        Invoice $correction,
        KsefInvoiceSubmission $submission,
        array $overrides = [],
    ): string {
        return KsefUpoFixture::xml(array_replace([
            'context_nip' => $submission->context_nip,
            'seller_nip' => $submission->seller_nip,
            'session_reference' => $submission->session_reference_number,
            'ksef_number' => $submission->ksef_number,
            'invoice_number' => $correction->number,
            'issue_date' => $correction->issue_date->format('Y-m-d'),
            'invoice_hash' => $submission->invoice_hash,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function processingStatus(KsefInvoiceSubmission $submission): array
    {
        return [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];
    }

    /** @return array<string, mixed> */
    private function acceptedStatus(KsefInvoiceSubmission $submission): array
    {
        return array_replace($this->processingStatus($submission), [
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => KsefUpoFixture::ksefNumber(),
            'acquisitionDate' => '2026-08-21T10:00:01Z',
            'permanentStorageDate' => '2026-08-21T10:00:02Z',
        ]);
    }

    private function decryptSentXml(KsefOnlineSessionApiFake $fake): string
    {
        $encryptedKey = base64_decode(
            data_get($fake->openPayload, 'encryption.encryptedSymmetricKey'),
            true,
        );
        $key = $fake->privateKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->decrypt($encryptedKey);
        $xml = openssl_decrypt(
            base64_decode($fake->sendPayload['encryptedInvoiceContent'], true),
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode(data_get($fake->openPayload, 'encryption.initializationVector'), true),
        );
        $this->assertIsString($xml);

        return $xml;
    }

    private function hash(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
