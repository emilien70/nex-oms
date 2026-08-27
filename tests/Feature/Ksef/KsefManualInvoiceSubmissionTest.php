<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceMutationPolicy;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Jobs\KsefSubmissionFollowUpJob;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefInvoiceUpo;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\Fa3\KsefFa3DocumentGenerator;
use Modules\Ksef\Services\KsefInvoiceSubmissionService;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefSubmissionFollowUpProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefManualInvoiceSubmissionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
    }

    public function test_first_manual_send_posts_invoice_once_and_checks_status_once(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $response = $this->post(route('invoices.ksef.submissions.store', $invoice));

        $response->assertRedirect()
            ->assertSessionHas('success', 'Faktura została przekazana do KSeF TEST. Sprawdź status, aby potwierdzić przyjęcie.');
        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_demo_manual_send_uses_demo_credential_host_and_dynamic_success_message(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHas(
                'success',
                'Faktura została przekazana do KSeF DEMO. Sprawdź status, aby potwierdzić przyjęcie.',
            );

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefEnvironment::Demo, $submission->environment);
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        Http::assertSentCount(5);

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api-demo.ksef.mf.gov.pl', parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public function test_first_attempt_can_be_accepted_by_the_single_immediate_status_check(): void
    {
        Queue::fake();
        Storage::fake('local');
        $invoice = $this->eligibleInvoice();
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->put($pdfPath, '%PDF-1.7 before KSeF acceptance');
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        $fake->statusResponse = [
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'acquisitionDate' => '2026-08-21T10:00:01Z',
            'permanentStorageDate' => '2026-08-21T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => $this->validKsefNumber('9876543210'),
        ];

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success');

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame($fake->statusResponse['ksefNumber'], $submission->ksef_number);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertNotNull($submission->next_follow_up_at);
        Queue::assertPushedOn('ksef', KsefSubmissionFollowUpJob::class, function ($job) use ($submission): bool {
            return $job->submissionId === $submission->getKey()
                && $job->delay?->equalTo($submission->next_follow_up_at) === true;
        });
        Storage::disk('local')->assertMissing($pdfPath);

        $fake->upoResponse = KsefUpoFixture::xml([
            'context_nip' => $submission->context_nip,
            'seller_nip' => $submission->seller_nip,
            'session_reference' => $submission->session_reference_number,
            'ksef_number' => $submission->ksef_number,
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->issue_date->format('Y-m-d'),
            'invoice_hash' => $submission->invoice_hash,
        ]);
        $this->travelTo($submission->next_follow_up_at);
        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));

        $this->assertSame(
            1,
            $fake->upoCalls,
            (string) $submission->refresh()->last_follow_up_error_code,
        );
        $this->assertNull($submission->refresh()->last_follow_up_error_code);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
    }

    public function test_immediate_status_failure_does_not_turn_a_successful_send_into_failure_or_retry(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/sessions/20260819-SO-TEST-REFERENCE/invoices/20260819-INV-TEST-REFERENCE'] = [
            'status' => 503,
            'body' => ['reasonCode' => 'SYNTHETIC_STATUS_UNAVAILABLE'],
        ];
        $route = route('invoices.ksef.submissions.first-attempt', $invoice);

        $this->post($route)
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success');

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Submitted, $submission->status);
        $this->assertSame('SYNTHETIC_STATUS_UNAVAILABLE', $submission->safe_error_code);
        $this->assertNotNull($submission->last_checked_at);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);

        $this->post($route)->assertSessionHasErrors('ksef');
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    #[DataProvider('listFirstAttemptEnvironments')]
    public function test_list_first_attempt_uses_configured_environment_and_ignores_request_tampering(
        KsefEnvironment $configuredEnvironment,
        KsefEnvironment $forgedEnvironment,
        string $expectedHost,
    ): void {
        $invoice = $this->eligibleInvoice(environment: $configuredEnvironment);
        $this->validAccessToken($configuredEnvironment);
        $fake = $this->fakeOnlineApi();
        $route = route('invoices.ksef.submissions.first-attempt', $invoice);

        $this->post($route, ['environment' => $forgedEnvironment->value])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionMissing('success');

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame($configuredEnvironment, $submission->environment);
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(1, $fake->statusCalls);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($expectedHost, parse_url($request->url(), PHP_URL_HOST));
        }

        $this->post($route, ['environment' => $forgedEnvironment->value])
            ->assertSessionHasErrors('ksef');
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);

        $this->get(route('invoices.index'))
            ->assertSee(KsefInvoiceSubmissionStatus::Processing->label())
            ->assertDontSee($route, false);
    }

    public static function listFirstAttemptEnvironments(): array
    {
        return [
            'configured TEST ignores forged PRODUCTION' => [
                KsefEnvironment::Test,
                KsefEnvironment::Production,
                'api-test.ksef.mf.gov.pl',
            ],
            'configured DEMO ignores forged TEST' => [
                KsefEnvironment::Demo,
                KsefEnvironment::Test,
                'api-demo.ksef.mf.gov.pl',
            ],
        ];
    }

    public function test_list_first_attempt_atomically_finalizes_unfinalized_invoice_before_transport(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->validAccessToken();
        $fake = new KsefOnlineSessionApiFake;
        $baselineTransactionLevel = DB::transactionLevel();
        Http::fake(function (Request $request) use ($fake, $baselineTransactionLevel) {
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel());

            return $fake($request);
        });

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionMissing('success');

        $submission = KsefInvoiceSubmission::query()->sole();
        $finalized = $invoice->fresh();
        $this->assertTrue($finalized->isFinalized());
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertSame('FA (3) 1-0E', $submission->schema_id);
        $this->assertSame(base64_encode(hash('sha256', $submission->payload_xml, true)), $submission->invoice_hash);
        $this->assertSame(strlen($submission->payload_xml), $submission->invoice_size);
        $this->assertSame(1, $fake->openCalls);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->closeCalls);
        $this->assertSame(1, $fake->statusCalls);

        try {
            app(InvoiceMutationPolicy::class)->assertContentMutable($finalized);
            $this->fail('Finalized invoice should reject content mutation.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_finalized', $exception->errorCode());
        }
    }

    public function test_first_attempt_preflight_failure_rolls_back_finalization_and_sends_no_http(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $metadata = $invoice->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertNull($invoice->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_missing_items_show_a_dedicated_ksef_error_without_generic_form_alert(): void
    {
        $invoice = $this->eligibleInvoice();
        $invoice->items()->delete();
        $metadata = $invoice->tax_metadata_snapshot;
        $metadata['ksef_tax']['line_treatments'] = [];
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('ksef')
            ->assertSessionHas('ksef_error', function (array $error): bool {
                return $error['title'] === 'Nie udało się przekazać Faktury do KSeF'
                    && $error['stage'] === 'Przygotowanie dokumentu FA(3)'
                    && $error['code'] === 'ksef_fa3_items_missing'
                    && $error['message'] === 'Faktura nie zawiera żadnych pozycji.'
                    && $error['details'] === [
                        'Aby przygotować dokument FA(3), Faktura musi zawierać co najmniej jedną pozycję.',
                    ];
            });

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('data-ksef-error', false)
            ->assertSeeText('Nie udało się przekazać Faktury do KSeF')
            ->assertSeeText('Przygotowanie dokumentu FA(3)')
            ->assertSeeText('ksef_fa3_items_missing')
            ->assertSeeText('Faktura nie zawiera żadnych pozycji.')
            ->assertSeeText('Aby przygotować dokument FA(3), Faktura musi zawierać co najmniej jedną pozycję.')
            ->assertDontSee('Nie uda&#322;o si&#281; zapisa&#263; zmian.', false);

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_manual_api_failure_exposes_only_classified_api_diagnostics(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->mock(KsefManualInvoiceSubmissionService::class, function ($mock): void {
            $mock->shouldReceive('submitFirstAttempt')
                ->once()
                ->andThrow(new KsefApiException(
                    'KSeF odrzucił Fakturę podczas weryfikacji.',
                    'invoice_submit_rejected',
                    400,
                    '21170',
                    systemWarning: 'SECRET_API_SYSTEM_WARNING',
                ));
        });

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef')
            ->assertSessionHas('ksef_error', fn (array $error): bool => (
                $error['stage'] === 'Komunikacja z KSeF'
                && $error['code_label'] === 'Kod NEX'
                && $error['code'] === 'invoice_submit_rejected'
                && $error['http_status'] === 400
                && $error['reason_code'] === '21170'
                && ! str_contains(json_encode($error, JSON_THROW_ON_ERROR), 'SECRET_API_SYSTEM_WARNING')
            ));

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSeeText('Komunikacja z KSeF')
            ->assertSeeText('Kod NEX')
            ->assertSeeText('invoice_submit_rejected')
            ->assertSeeText('HTTP')
            ->assertSeeText('400')
            ->assertSeeText('Kod KSeF')
            ->assertSeeText('21170')
            ->assertDontSeeText('SECRET_API_SYSTEM_WARNING')
            ->assertDontSee('Nie uda&#322;o si&#281; zapisa&#263; zmian.', false);
        Http::assertNothingSent();
    }

    public function test_unexpected_manual_failure_never_exposes_technical_message(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->mock(KsefManualInvoiceSubmissionService::class, function ($mock): void {
            $mock->shouldReceive('submitFirstAttempt')
                ->once()
                ->andThrow(new RuntimeException('SECRET_DATABASE_OR_STACK_DETAIL'));
        });

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef')
            ->assertSessionHas('ksef_error', fn (array $error): bool => (
                $error['code'] === 'ksef_operation_failed'
                && $error['message'] === 'Nie udało się wykonać operacji KSeF.'
                && ! str_contains(json_encode($error, JSON_THROW_ON_ERROR), 'SECRET_DATABASE_OR_STACK_DETAIL')
            ));

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSeeText('Nie udało się wykonać operacji KSeF.')
            ->assertDontSeeText('SECRET_DATABASE_OR_STACK_DETAIL')
            ->assertDontSee('Nie uda&#322;o si&#281; zapisa&#263; zmian.', false);
        Http::assertNothingSent();
    }

    public function test_dedicated_ksef_error_is_escaped_by_blade(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->mock(KsefManualInvoiceSubmissionService::class, function ($mock): void {
            $mock->shouldReceive('submitFirstAttempt')
                ->once()
                ->andThrow(new InvoiceDomainException(
                    'ksef_fa3_tax_snapshot_invalid',
                    '<script>SECRET_SCRIPT_MARKER</script>',
                    [
                        'invoice_item' => [
                            'position' => 1,
                            'name' => '<img src=x onerror=SECRET_ITEM_MARKER>',
                        ],
                    ],
                ));
        });

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;SECRET_SCRIPT_MARKER&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=SECRET_ITEM_MARKER&gt;', false)
            ->assertDontSee('<script>SECRET_SCRIPT_MARKER</script>', false)
            ->assertDontSee('<img src=x onerror=SECRET_ITEM_MARKER>', false);
        Http::assertNothingSent();
    }

    public function test_ordinary_validation_errors_keep_the_generic_form_alert(): void
    {
        $errors = (new ViewErrorBag)
            ->put('default', new MessageBag([
                'name' => 'Pole nazwa jest wymagane.',
            ]));

        $this->view('layouts.app', compact('errors'))
            ->assertSee('Nie uda&#322;o si&#281; zapisa&#263; zmian.', false)
            ->assertSeeText('Pole nazwa jest wymagane.')
            ->assertDontSee('data-ksef-error', false);
    }

    public function test_first_attempt_authoritative_prepare_failure_rolls_back_finalization_and_submission(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->mock(KsefFa3DocumentGenerator::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(new InvoiceDomainException(
                    'synthetic_authoritative_failure',
                    'Syntetyczny błąd autorytatywnego FA(3).',
                ));
        });

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertNull($invoice->fresh()->finalized_at);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('crossEnvironmentFirstAttemptCases')]
    public function test_list_first_attempt_is_independent_between_environments(
        KsefEnvironment $activeEnvironment,
        KsefEnvironment $historicalEnvironment,
    ): void {
        $invoice = $this->eligibleInvoice(environment: $activeEnvironment);
        $historical = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Accepted,
            $historicalEnvironment,
        );
        $this->validAccessToken($activeEnvironment);
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $historical->fresh()->status);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'environment' => $activeEnvironment->value,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Processing->value,
        ]);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    public static function crossEnvironmentFirstAttemptCases(): array
    {
        return [
            'TEST history then DEMO first attempt' => [KsefEnvironment::Demo, KsefEnvironment::Test],
            'DEMO history then TEST first attempt' => [KsefEnvironment::Test, KsefEnvironment::Demo],
        ];
    }

    #[DataProvider('allSubmissionStatuses')]
    public function test_list_first_attempt_rejects_any_current_environment_history(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice(finalize: false);
        $this->createSubmission($invoice, $status);

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertNull($invoice->fresh()->finalized_at);
        Http::assertNothingSent();
    }

    public static function allSubmissionStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    #[DataProvider('blockedListFirstAttemptCases')]
    public function test_list_first_attempt_preconditions_block_before_http(string $case): void
    {
        $invoice = $this->eligibleInvoice(
            finalize: false,
            environment: $case === 'production' ? KsefEnvironment::Production : KsefEnvironment::Test,
        );

        if ($case === 'gate_disabled') {
            config()->set('ksef.invoice_submission_enabled', false);
        } elseif ($case === 'inactive') {
            app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        } elseif ($case === 'series_disabled') {
            KsefSeriesSetting::query()
                ->where('invoice_series_id', $invoice->invoice_series_id)
                ->update(['is_enabled' => false]);
        }

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        $this->assertNull($invoice->fresh()->finalized_at);
        Http::assertNothingSent();
    }

    public static function blockedListFirstAttemptCases(): array
    {
        return [
            'PRODUCTION' => ['production'],
            'deployment gate disabled' => ['gate_disabled'],
            'integration inactive' => ['inactive'],
            'series disabled' => ['series_disabled'],
        ];
    }

    public function test_list_first_attempt_detects_environment_change_before_http(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false, environment: KsefEnvironment::Test);
        $settings = app(KsefSettingsService::class)->get();
        $expectedEnvironment = $settings->environment;
        $settings->forceFill(['environment' => KsefEnvironment::Demo])->save();

        try {
            app(KsefManualInvoiceSubmissionService::class)
                ->submitFirstAttempt($invoice, $expectedEnvironment);
            $this->fail('Environment drift should reject the first attempt.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_submission_environment_changed', $exception->safeCode);
        }

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        $this->assertNull($invoice->fresh()->finalized_at);
        Http::assertNothingSent();
    }

    #[DataProvider('postCommitTransportFailureCases')]
    public function test_first_attempt_keeps_invoice_finalized_after_post_commit_transport_failure(
        string $case,
        KsefInvoiceSubmissionStatus $expectedStatus,
    ): void {
        $invoice = $this->eligibleInvoice(finalize: false);

        if ($case === 'access_token') {
            KsefCredential::query()->create([
                'environment' => KsefEnvironment::Test,
                'authentication_method' => KsefAuthenticationMethod::Token,
                'api_token' => 'FAKE_FIRST_ATTEMPT_API_TOKEN',
            ]);
        } else {
            $this->validAccessToken();
        }

        $fake = $this->fakeOnlineApi();
        $failurePath = match ($case) {
            'access_token' => '/auth/challenge',
            'public_key' => '/security/public-key-certificates',
            'session_open' => '/sessions/online',
            'ambiguous_send' => '/sessions/online/20260819-SO-TEST-REFERENCE/invoices',
        };
        $fake->failures[$failurePath] = $case === 'access_token' || $case === 'ambiguous_send'
            ? ['connection' => true]
            : ['status' => 500];

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertTrue($invoice->fresh()->isFinalized());
        $this->assertSame($expectedStatus, $submission->status);
        $this->assertSame(1, $submission->attempt_number);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame($case === 'ambiguous_send' ? 1 : 0, $fake->sendCalls);
        $this->assertSame(0, $fake->closeCalls);
    }

    public static function postCommitTransportFailureCases(): array
    {
        return [
            'access token network failure' => ['access_token', KsefInvoiceSubmissionStatus::TechnicalFailed],
            'public key failure' => ['public_key', KsefInvoiceSubmissionStatus::TechnicalFailed],
            'session open failure' => ['session_open', KsefInvoiceSubmissionStatus::TechnicalFailed],
            'ambiguous invoice send' => ['ambiguous_send', KsefInvoiceSubmissionStatus::Uncertain],
        ];
    }

    public function test_second_manual_post_is_blocked_after_successful_first_attempt(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHas('success');
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_retry_safe_technical_failure_allows_a_new_attempt_and_preserves_history(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/invoices'] = [
            'status' => 400,
            'body' => ['reasonCode' => 'SYNTHETIC_DEFINITE_FAILURE'],
        ];

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');
        $this->assertSame(
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            KsefInvoiceSubmission::query()->sole()->status,
        );
        $first = KsefInvoiceSubmission::query()->sole();
        $firstPayload = $first->payload_xml;
        $firstRawPayload = DB::table('ksef_invoice_submissions')
            ->where('id', $first->getKey())
            ->value('payload_xml');
        unset($fake->failures['/invoices']);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHas('success');

        $second = KsefInvoiceSubmission::query()->orderByDesc('attempt_number')->firstOrFail();
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $second->status);
        $this->assertSame($firstPayload, $first->fresh()->payload_xml);
        $this->assertNotSame(
            $firstRawPayload,
            DB::table('ksef_invoice_submissions')->where('id', $second->getKey())->value('payload_xml'),
        );
        $this->assertSame(2, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    #[DataProvider('blockingStatuses')]
    public function test_any_existing_attempt_in_current_environment_blocks_manual_send(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, $status);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function blockingStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->reject(fn (KsefInvoiceSubmissionStatus $status): bool => $status->allowsNewAttempt())
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    #[DataProvider('retryableStatuses')]
    public function test_retryable_attempt_allows_exactly_one_new_manual_attempt(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $first = $this->createSubmission($invoice, $status);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHas('success');
        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame($status, $first->fresh()->status);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test->value,
            'attempt_number' => 2,
            'status' => KsefInvoiceSubmissionStatus::Processing->value,
        ]);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    public static function retryableStatuses(): array
    {
        return [
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
        ];
    }

    public function test_accepted_history_blocks_retry_even_when_latest_attempt_is_rejected(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted);
        $latest = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Historia KSeF tej Faktury nie pozwala utworzyć kolejnej próby.')
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $invoice).'"', false);
        $this->post(route('invoices.ksef.submissions.store', $invoice))->assertSessionHasErrors('ksef');
        $this->assertSame(KsefInvoiceSubmissionStatus::Rejected, $latest->fresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        Http::assertNothingSent();
    }

    public function test_attempt_in_another_environment_does_not_block_first_attempt_in_current_environment(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Rejected,
            KsefEnvironment::Demo,
        );
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('ksef_invoice_submissions', 2);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test->value,
            'attempt_number' => 1,
            'status' => KsefInvoiceSubmissionStatus::Processing->value,
        ]);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_manual_send_preconditions_reject_without_submission_or_http(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $invoice = app(InvoiceFinalizationService::class)->finalize($invoice);
        app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        app(KsefSettingsService::class)->get()->forceFill(['is_active' => true])->save();
        KsefSeriesSetting::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->update(['is_enabled' => false]);
        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');
        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_deployment_gate_rejects_manual_send_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        config()->set('ksef.invoice_submission_enabled', false);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');
        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_production_is_blocked_without_http(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Production);

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('unsupportedDocumentTypes')]
    public function test_proforma_and_correction_cannot_use_manual_send_route(
        InvoiceDocumentType $documentType,
    ): void {
        $invoice = $this->eligibleInvoice();
        $invoice->forceFill(['document_type' => $documentType])->saveQuietly();

        $this->post(route('invoices.ksef.submissions.store', $invoice))
            ->assertSessionHasErrors('ksef');
        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function unsupportedDocumentTypes(): array
    {
        return [
            'proforma' => [InvoiceDocumentType::Proforma],
            'correction' => [InvoiceDocumentType::Correction],
        ];
    }

    public function test_submitted_attempt_can_be_refreshed_once_to_processing(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefInvoiceSubmissionService::class)
            ->submit(app(KsefInvoiceSubmissionService::class)->prepare($invoice));

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHas('success', 'Status KSeF został odświeżony.');

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->refresh()->status);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);
    }

    public function test_list_status_refresh_returns_without_success_message(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $submission = app(KsefInvoiceSubmissionService::class)
            ->submit(app(KsefInvoiceSubmissionService::class)->prepare($invoice));

        $this->from(route('invoices.index'))
            ->post(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), [
                'return_to' => 'invoices',
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionMissing('success');

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->refresh()->status);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_processing_attempt_can_be_refreshed_once_to_accepted(): void
    {
        Queue::fake();
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Processing,
            attributes: [
                'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
                'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            ],
        );
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'acquisitionDate' => '2026-08-21T10:00:01Z',
            'permanentStorageDate' => '2026-08-21T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => $this->validKsefNumber('9876543210'),
        ];
        $fake->upoResponse = KsefUpoFixture::xml([
            'context_nip' => $submission->context_nip,
            'seller_nip' => $submission->seller_nip,
            'session_reference' => $submission->session_reference_number,
            'ksef_number' => $fake->statusResponse['ksefNumber'],
            'invoice_number' => $invoice->number,
            'invoice_hash' => $submission->invoice_hash,
        ]);

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHas('success');

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame($fake->statusResponse['ksefNumber'], $submission->ksef_number);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(0, $fake->sendCalls);

        $this->travelTo($submission->next_follow_up_at);
        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));

        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame($fake->upoResponse, KsefInvoiceUpo::query()->sole()->payload_xml);
    }

    public function test_accepted_status_schedules_upo_without_waiting_for_its_availability(): void
    {
        Queue::fake();
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'acquisitionDate' => '2026-08-21T10:00:01Z',
            'permanentStorageDate' => '2026-08-21T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => $this->validKsefNumber('9876543210'),
        ];

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);

        $this->travelTo($submission->next_follow_up_at);
        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));

        $submission->refresh();
        $this->assertSame('ksef_upo_not_available', $submission->last_follow_up_error_code);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->upoCalls);
    }

    #[DataProvider('nonRefreshableStatuses')]
    public function test_non_refreshable_states_are_blocked_without_http(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status);

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHasErrors('ksef');

        Http::assertNothingSent();
    }

    public static function nonRefreshableStatuses(): array
    {
        return collect(KsefInvoiceSubmissionStatus::cases())
            ->reject(fn (KsefInvoiceSubmissionStatus $status): bool => in_array($status, [
                KsefInvoiceSubmissionStatus::Submitted,
                KsefInvoiceSubmissionStatus::Processing,
            ], true))
            ->mapWithKeys(fn (KsefInvoiceSubmissionStatus $status): array => [
                $status->value => [$status],
            ])
            ->all();
    }

    public function test_cross_invoice_refresh_returns_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($otherInvoice, KsefInvoiceSubmissionStatus::Submitted);

        $this->post(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_uncertain_attempt_can_be_reconciled_once_without_invoice_resend(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-21T10:00:00Z',
            'ordinalNumber' => 1,
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];

        $route = route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]);
        $this->post($route)->assertSessionHas('success', 'Wynik transmisji KSeF został sprawdzony.');
        $this->post($route)->assertSessionHasErrors('ksef');

        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->refresh()->status);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_cross_invoice_reconciliation_returns_404_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $otherInvoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($otherInvoice, KsefInvoiceSubmissionStatus::Uncertain);

        $this->post(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_manual_routes_do_not_accept_get_requests(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Submitted);

        $this->get(route('invoices.ksef.submissions.store', $invoice))->assertMethodNotAllowed();
        $this->get(route('invoices.ksef.submissions.first-attempt', $invoice))->assertMethodNotAllowed();
        $this->get(route('invoices.ksef.submissions.refresh', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertMethodNotAllowed();
        $this->get(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertMethodNotAllowed();
        Http::assertNothingSent();
    }

    public function test_read_only_invoice_panel_shows_manual_test_send_and_no_secrets(): void
    {
        $invoice = $this->eligibleInvoice();

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('KSeF')
            ->assertSee('TEST')
            ->assertSee('Nie wysłano')
            ->assertSee('Wyślij do KSeF TEST')
            ->assertSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertSee('Wysłać tę Fakturę do KSeF TEST?')
            ->assertDontSee('data-ksef-demo-warning', false)
            ->assertDontSee('FAKE_SUBMISSION_API_TOKEN');
    }

    public function test_current_status_ignores_other_environment_attempt_but_history_and_test_send_remain_visible(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Rejected,
            KsefEnvironment::Demo,
            ['safe_error_message' => 'Bezpieczny historyczny opis DEMO.'],
        );

        $response = $this->get(route('invoices.edit', $invoice));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('Wyślij do KSeF TEST');
        $this->assertMatchesRegularExpression(
            '/<div class="invoice-ksef-status-row">.*?data-ksef-current-status[^>]*>Nie wysłano<\/span>.*?<\/div>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<table[^>]*data-ksef-submission-history[^>]*>.*?DEMO.*?Odrzucona.*?<\/table>/s',
            $html,
        );
    }

    public function test_demo_panel_uses_demo_current_submission_warning_labels_and_actions(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Demo);
        $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Accepted,
            KsefEnvironment::Test,
            ['ksef_number' => $this->validKsefNumber('9876543210')],
        );

        $response = $this->get(route('invoices.edit', $invoice));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('DEMO')
            ->assertSee('Wyślij do KSeF DEMO')
            ->assertSee('data-ksef-demo-warning', false)
            ->assertSee('Środowisko DEMO / przedprodukcyjne.')
            ->assertSee('Wysłać tę Fakturę do KSeF DEMO?')
            ->assertSee('Upewnij się, że dokument zawiera wyłącznie dane testowe lub fikcyjne.');
        $this->assertMatchesRegularExpression(
            '/<div class="invoice-ksef-status-row">.*?data-ksef-current-status[^>]*>Nie wysłano<\/span>.*?<\/div>/s',
            $html,
        );

        $submission = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            KsefEnvironment::Demo,
        );
        $this->get(route('invoices.edit', $invoice))
            ->assertSee('Utwórz nową próbę KSeF DEMO')
            ->assertSee('Utworzyć nową próbę KSeF DEMO?');

        $submission->forceFill(['status' => KsefInvoiceSubmissionStatus::Submitted])->save();
        $this->get(route('invoices.edit', $invoice))
            ->assertSee(route('invoices.ksef.submissions.refresh', compact('invoice', 'submission')), false)
            ->assertSee('Sprawdź status');

        $submission->forceFill(['status' => KsefInvoiceSubmissionStatus::Uncertain])->save();
        $this->get(route('invoices.edit', $invoice))
            ->assertSee(route('invoices.ksef.submissions.reconcile', compact('invoice', 'submission')), false)
            ->assertSee('Sprawdź wynik transmisji');

        $submission->forceFill([
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'ksef_number' => $this->validKsefNumber('9876543210'),
        ])->save();
        $this->get(route('invoices.edit', $invoice))
            ->assertSee(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')), false)
            ->assertSee('Pobierz UPO z KSeF');
    }

    public function test_production_panel_keeps_history_but_hides_all_remote_actions(): void
    {
        $invoice = $this->eligibleInvoice(environment: KsefEnvironment::Production);
        $submission = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Submitted,
            KsefEnvironment::Production,
        );

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('PRODUCTION')
            ->assertSee('Wysłana')
            ->assertSee('data-ksef-submission-history', false)
            ->assertSee('Operacyjny transport Faktur do KSeF PRODUCTION nie został jeszcze odblokowany.')
            ->assertDontSee('data-ksef-demo-warning', false)
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $invoice).'"', false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', compact('invoice', 'submission')), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', compact('invoice', 'submission')), false)
            ->assertDontSee(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')), false);
    }

    public function test_accepted_panel_shows_full_number_without_send_or_refresh(): void
    {
        $invoice = $this->eligibleInvoice();
        $number = $this->validKsefNumber('9876543210');
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted, attributes: [
            'ksef_number' => $number,
            'acquisition_date' => now(),
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Zaakceptowana')
            ->assertSee($number)
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $invoice).'"', false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    #[DataProvider('refreshableStatuses')]
    public function test_submitted_and_processing_panels_show_only_manual_refresh(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee($status->label())
            ->assertSee('Sprawdź status')
            ->assertSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false)
            ->assertDontSee('Wyślij do KSeF TEST');
    }

    public static function refreshableStatuses(): array
    {
        return [
            'submitted' => [KsefInvoiceSubmissionStatus::Submitted],
            'processing' => [KsefInvoiceSubmissionStatus::Processing],
        ];
    }

    #[DataProvider('failedUiStatuses')]
    public function test_rejected_and_technical_failed_panels_show_safe_error_and_controlled_new_attempt(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, $status, attributes: [
            'safe_error_message' => 'Bezpieczny komunikat dla użytkownika.',
            'invoice_reference_number' => 'HIDDEN_RAW_REFERENCE',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee($status->label())
            ->assertSee('Bezpieczny komunikat dla użytkownika.')
            ->assertSee('Utwórz nową próbę KSeF TEST')
            ->assertSee('Poprzednia próba pozostanie w historii.')
            ->assertDontSee('HIDDEN_RAW_REFERENCE')
            ->assertSee(route('invoices.ksef.submissions.store', $invoice), false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    public static function failedUiStatuses(): array
    {
        return [
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
            'technical_failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
        ];
    }

    public function test_rejected_panel_and_history_show_safe_ksef_status_code_without_raw_details(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected, attributes: [
            'ksef_status_code' => 415,
            'safe_error_message' => 'KSeF odrzucił Fakturę podczas weryfikacji.',
            'invoice_reference_number' => 'RAW_SYNTHETIC_REJECTION_DETAIL',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Odrzucona')
            ->assertSee('data-ksef-current-status-code', false)
            ->assertSee('data-ksef-history-status-code', false)
            ->assertSee('Kod KSeF: <strong>415</strong>', false)
            ->assertSee('Kod KSeF: 415')
            ->assertSee('KSeF odrzucił Fakturę podczas weryfikacji.')
            ->assertDontSee('RAW_SYNTHETIC_REJECTION_DETAIL');
    }

    public function test_uncertain_and_failed_history_exposes_only_safe_diagnostics_latest_first(): void
    {
        $invoice = $this->eligibleInvoice();
        $first = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Rejected, attributes: [
            'safe_error_message' => 'Bezpieczny opis odrzucenia.',
            'session_reference_number' => 'HIDDEN_SESSION_REFERENCE',
        ]);
        $second = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, attributes: [
            'safe_error_message' => 'Bezpieczny opis stanu niepewnego.',
            'payload_xml' => '<Faktura>HIDDEN_XML_CONTENT</Faktura>',
            'invoice_hash' => 'HIDDEN_INVOICE_HASH',
            'context_nip' => '1234567890',
            'seller_nip' => '1234567890',
        ]);

        $response = $this->get(route('invoices.edit', $invoice));
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('Stan niepewny')
            ->assertSee('Nie wolno ponownie wysłać dokumentu przed ustaleniem wyniku poprzedniej transmisji.')
            ->assertSee('Sprawdź wynik transmisji')
            ->assertSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $second,
            ]), false)
            ->assertSee('Bezpieczny opis stanu niepewnego.')
            ->assertSee('Bezpieczny opis odrzucenia.')
            ->assertDontSee('Wyślij do KSeF TEST')
            ->assertDontSee('Ponów')
            ->assertDontSee('HIDDEN_XML_CONTENT')
            ->assertDontSee('HIDDEN_INVOICE_HASH')
            ->assertDontSee('HIDDEN_SESSION_REFERENCE')
            ->assertDontSee('1234567890');
        $this->assertLessThan(
            strpos($html, 'data-ksef-submission-id="'.$first->getKey().'"'),
            strpos($html, 'data-ksef-submission-id="'.$second->getKey().'"'),
        );
    }

    public function test_uncertain_without_session_reference_blocks_resend_and_has_no_reconciliation_action(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, attributes: [
            'session_reference_number' => null,
            'invoice_reference_number' => null,
        ]);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Brak referencji sesji potrzebnej do bezpiecznego sprawdzenia wyniku.')
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $invoice).'"', false)
            ->assertDontSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);

        $this->post(route('invoices.ksef.submissions.reconcile', [
            'invoice' => $invoice,
            'submission' => $submission,
        ]))->assertSessionHasErrors('ksef');
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_gate_false_keeps_history_visible_but_hides_manual_actions(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Submitted);
        config()->set('ksef.invoice_submission_enabled', false);

        $response = $this->get(route('invoices.edit', $invoice));

        $response->assertOk()
            ->assertSee('Wysłana')
            ->assertSee('Wysyłka KSeF jest wyłączona na poziomie wdrożenia.')
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $invoice).'"', false)
            ->assertDontSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $invoice,
                'submission' => $submission,
            ]), false);
    }

    public function test_enabling_automatic_submission_does_not_retroactively_submit_existing_invoice(): void
    {
        $invoice = $this->eligibleInvoice(finalize: false);
        app(KsefSettingsService::class)->get()->forceFill(['automatic_submission' => true])->save();

        app(InvoiceFinalizationService::class)->finalize($invoice);

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    private function eligibleInvoice(
        bool $finalize = true,
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-MANUAL-'.uniqid(),
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

        return $finalize
            ? app(InvoiceFinalizationService::class)->finalize($invoice)->load('items')
            : $invoice;
    }

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_MANUAL_API_TOKEN',
            'access_token' => 'FAKE_VALID_MANUAL_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_VALID_MANUAL_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function createSubmission(
        Invoice $invoice,
        KsefInvoiceSubmissionStatus $status,
        KsefEnvironment $environment = KsefEnvironment::Test,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        $attemptNumber = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;
        $payload = '<Faktura>FAKE MANUAL PAYLOAD '.$attemptNumber.'</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinutes(10 - $attemptNumber),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'session_reference_number' => '20260821-SO-MANUAL-REFERENCE',
            'invoice_reference_number' => '20260821-INV-MANUAL-REFERENCE',
        ], $attributes));
    }

    private function validKsefNumber(string $sellerNip): string
    {
        $base = $sellerNip.'-20260821-0100001AF629';
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
}
