<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Jobs\KsefSubmissionFollowUpJob;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefSubmissionFollowUpDispatcher;
use Modules\Ksef\Services\KsefSubmissionFollowUpProcessor;
use Modules\Ksef\Services\KsefSubmissionFollowUpRateLimiter;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefSubmissionFollowUpTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-08-25 10:00:00'));
    }

    public function test_schema_model_job_and_scheduler_contracts_are_present(): void
    {
        foreach ([
            'next_follow_up_at',
            'follow_up_attempts',
            'follow_up_action',
            'last_follow_up_at',
            'last_follow_up_error_code',
            'last_follow_up_error_message',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('ksef_invoice_submissions', $column));
        }

        $job = new KsefSubmissionFollowUpJob(123);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('ksef-submission-123', $job->uniqueId());
        $this->assertSame(1, $job->tries);
        $this->assertSame(21600, $job->uniqueFor);
        $this->assertSame('database', $job->connection);
        $this->assertSame('ksef', $job->queue);
    }

    public function test_immediate_processing_is_completed_in_background_with_one_invoice_post_and_upo(): void
    {
        Storage::fake('local');
        $invoice = $this->eligibleInvoice();
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->put($pdfPath, '%PDF-1.7 before background acceptance');
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasNoErrors();

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame('2026-08-25 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('status', $submission->follow_up_action);

        $fake->statusResponse = $this->acceptedStatus($submission);
        $fake->upoResponse = $this->upoXml($invoice, $submission, $fake->statusResponse['ksefNumber']);
        $this->travel(61)->seconds();

        Queue::fake();
        $this->assertSame(1, app(KsefSubmissionFollowUpDispatcher::class)->dispatchDue());
        Queue::assertPushedOn('ksef', KsefSubmissionFollowUpJob::class, function ($job) use ($submission): bool {
            return $job->submissionId === $submission->getKey();
        });

        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertNull($submission->follow_up_action);
        $this->assertNull($submission->next_follow_up_at);
        $this->assertNull($submission->last_follow_up_error_code);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(2, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
        Storage::disk('local')->assertMissing($pdfPath);
    }

    public function test_processing_backoff_progresses_from_one_to_five_to_fifteen_minutes(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice));
        $submission = KsefInvoiceSubmission::query()->sole();

        $this->travelTo($submission->next_follow_up_at->addSecond());
        $fake->statusResponse = $this->processingStatus($submission);
        $this->runJob($submission);
        $submission->refresh();
        $this->assertSame('2026-08-25 10:06:01', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));

        $this->travelTo($submission->next_follow_up_at);
        $this->runJob($submission);
        $submission->refresh();
        $this->assertSame('2026-08-25 10:21:01', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));

        $this->travelTo($submission->next_follow_up_at);
        $fake->statusResponse = $this->acceptedStatus($submission);
        $fake->upoResponse = $this->upoXml($invoice, $submission, $fake->statusResponse['ksefNumber']);
        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertNull($submission->follow_up_action);
        $this->assertNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(4, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
    }

    public function test_immediate_accepted_with_upo_not_ready_starts_upo_backoff_at_one_minute(): void
    {
        $invoice = $this->eligibleInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = [
            'invoicingDate' => '2026-08-25T10:00:00Z',
            'acquisitionDate' => '2026-08-25T10:00:01Z',
            'permanentStorageDate' => '2026-08-25T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => KsefUpoFixture::ksefNumber(),
        ];

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasNoErrors();

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('2026-08-25 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertSame('ksef_upo_not_available', $submission->last_follow_up_error_code);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
    }

    public function test_status_to_upo_resets_high_backoff_before_upo_retry(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing, [
            'follow_up_attempts' => 4,
            'follow_up_action' => 'status',
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->acceptedStatus($submission);

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('2026-08-25 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertSame('ksef_upo_not_available', $submission->last_follow_up_error_code);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_reconcile_to_status_resets_high_backoff(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, [
            'follow_up_attempts' => 4,
            'follow_up_action' => 'reconcile',
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame('status', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('2026-08-25 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_reconcile_to_upo_resets_high_backoff_before_upo_retry(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, [
            'follow_up_attempts' => 4,
            'follow_up_action' => 'reconcile',
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->acceptedStatus($submission);

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('2026-08-25 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_retry_after_remains_authoritative_after_status_to_upo_change(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing, [
            'follow_up_attempts' => 4,
            'follow_up_action' => 'status',
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->acceptedStatus($submission);
        $fake->failures['/sessions/'.$submission->session_reference_number.'/invoices/ksef/'.$fake->statusResponse['ksefNumber'].'/upo'] = [
            'status' => 429,
            'headers' => ['Retry-After' => '600'],
        ];

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertGreaterThanOrEqual(now()->addSeconds(600)->timestamp, $submission->next_follow_up_at?->timestamp);
        $this->assertSame('rate_limited', $submission->last_follow_up_error_code);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_accepted_upo_not_ready_is_retried_without_invoice_post(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now(),
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('ksef_upo_not_available', $submission->last_follow_up_error_code);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);

        $fake->upoResponse = $this->upoXml($invoice, $submission, $submission->ksef_number);
        $this->travelTo($submission->next_follow_up_at);
        $this->runJob($submission);

        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertNull($submission->refresh()->next_follow_up_at);
        $this->assertSame(2, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_network_error_preserves_processing_and_reschedules_without_invoice_post(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $statusPath = '/sessions/'.$submission->session_reference_number.'/invoices/'.$submission->invoice_reference_number;
        $fake->failures[$statusPath] = ['connection' => true];

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame('network_error', $submission->last_follow_up_error_code);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(0, $fake->sendCalls);

        unset($fake->failures[$statusPath]);
        $fake->statusResponse = $this->processingStatus($submission);
        $this->travelTo($submission->next_follow_up_at);
        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertNull($submission->last_follow_up_error_code);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_retry_after_takes_precedence_over_normal_backoff(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->failures['/sessions/'.$submission->session_reference_number.'/invoices/'.$submission->invoice_reference_number] = [
            'status' => 429,
            'headers' => ['Retry-After' => '600'],
        ];

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame('rate_limited', $submission->last_follow_up_error_code);
        $this->assertGreaterThanOrEqual(
            now()->addSeconds(600)->timestamp,
            $submission->next_follow_up_at?->timestamp,
        );
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_application_limiter_isolated_by_environment_and_context_and_blocks_without_http(): void
    {
        config()->set('ksef.follow_up.rate_limits.status', [
            'per_second' => 1,
            'per_minute' => 100,
            'per_hour' => 100,
        ]);
        $limiter = app(KsefSubmissionFollowUpRateLimiter::class);

        $this->assertNull($limiter->reserve('status', KsefEnvironment::Test, '1111111111'));
        $this->assertNotNull($limiter->reserve('status', KsefEnvironment::Test, '1111111111'));
        $this->assertNull($limiter->reserve('status', KsefEnvironment::Demo, '1111111111'));
        $this->assertNull($limiter->reserve('status', KsefEnvironment::Test, '2222222222'));

        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing, [
            'context_nip' => '1111111111',
        ]);
        $before = $submission->next_follow_up_at;
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertTrue($submission->next_follow_up_at->greaterThan($before));
        $this->assertSame(0, $fake->statusCalls);
        Http::assertNothingSent();
    }

    public function test_upo_integrity_failure_stops_automation_without_changing_accepted(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now(),
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->upoResponse = $this->upoXml($invoice, $submission, $submission->ksef_number);
        $fake->upoContentHash = base64_encode(str_repeat('x', 32));

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame('ksef_upo_hash_mismatch', $submission->last_follow_up_error_code);
        $this->assertNull($submission->next_follow_up_at);
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_uncertain_reconciliation_to_processing_never_posts_invoice(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_uncertain_reconciliation_to_accepted_fetches_upo_without_invoice_post(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->acceptedStatus($submission);
        $fake->upoResponse = $this->upoXml($invoice, $submission, $fake->statusResponse['ksefNumber']);

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertNull($submission->next_follow_up_at);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_unresolved_reconciliation_is_rescheduled_without_invoice_post(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Uncertain, [
            'invoice_reference_number' => null,
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->sessionInvoicesResponse = ['invoices' => []];

        $this->runJob($submission);

        $submission->refresh();
        $this->assertSame(KsefInvoiceSubmissionStatus::Uncertain, $submission->status);
        $this->assertSame('ksef_reconciliation_result_unresolved', $submission->last_follow_up_error_code);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->sessionInvoicesCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_follow_up_uses_submission_demo_environment_after_settings_switch_to_test(): void
    {
        $invoice = $this->eligibleInvoice(KsefEnvironment::Test);
        $submission = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Processing,
            environment: KsefEnvironment::Demo,
        );
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);

        $this->runJob($submission);

        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(KsefEnvironment::Demo, $submission->refresh()->environment);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api-demo.ksef.mf.gov.pl', parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public function test_gate_inactive_and_production_leave_due_work_without_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $fake = $this->fakeOnlineApi();

        config()->set('ksef.invoice_submission_enabled', false);
        $this->runJob($submission);
        $this->assertNotNull($submission->refresh()->next_follow_up_at);

        config()->set('ksef.invoice_submission_enabled', true);
        app(KsefSettingsService::class)->getExisting()->forceFill(['is_active' => false])->save();
        $this->runJob($submission);
        $this->assertNotNull($submission->refresh()->next_follow_up_at);

        app(KsefSettingsService::class)->getExisting()->forceFill(['is_active' => true])->save();
        $production = $this->createSubmission(
            $invoice,
            KsefInvoiceSubmissionStatus::Processing,
            environment: KsefEnvironment::Production,
        );
        $this->runJob($production);

        $this->assertNotNull($production->refresh()->next_follow_up_at);
        $this->assertSame(0, $fake->statusCalls);
        Http::assertNothingSent();
    }

    public function test_due_work_is_recovered_after_gate_is_disabled_for_two_hours(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $credential = $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);
        $dispatcher = app(KsefSubmissionFollowUpDispatcher::class);

        config()->set('ksef.invoice_submission_enabled', false);
        for ($run = 0; $run < 120; $run++) {
            $this->assertSame(0, $dispatcher->dispatchDue());
            $this->travel(1)->minute();
        }

        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();

        config()->set('ksef.invoice_submission_enabled', true);
        $this->assertSame(1, $dispatcher->dispatchDue());
        $credential->forceFill(['access_token_valid_until' => now()->addHour()])->save();

        $this->artisan('queue:work database --queue=ksef --once --sleep=0 --tries=1 --timeout=60')
            ->assertExitCode(0);

        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_dispatcher_recovers_due_database_work_and_job_is_idempotent(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);

        Queue::fake();
        $dispatcher = app(KsefSubmissionFollowUpDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchDue());
        Queue::assertPushed(KsefSubmissionFollowUpJob::class, 1);
        Http::assertNothingSent();

        $job = new KsefSubmissionFollowUpJob($submission->getKey());
        $job->handle(app(KsefSubmissionFollowUpProcessor::class));
        $job->handle(app(KsefSubmissionFollowUpProcessor::class));

        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $submission->refresh()->follow_up_attempts);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_unique_job_prevents_linear_queue_growth_during_thirty_scheduler_runs(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);
        $dispatcher = app(KsefSubmissionFollowUpDispatcher::class);

        for ($run = 0; $run < 30; $run++) {
            $dispatcher->dispatchDue();
            $this->travel(1)->minute();
        }

        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work database --queue=ksef --once --sleep=0 --tries=1 --timeout=60')
            ->assertExitCode(0);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_lost_queued_job_is_recovered_after_bounded_unique_lock_expiry(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing);
        $credential = $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->statusResponse = $this->processingStatus($submission);
        $dispatcher = app(KsefSubmissionFollowUpDispatcher::class);

        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertDatabaseCount('jobs', 1);
        DB::table('jobs')->where('queue', 'ksef')->delete();
        $this->assertDatabaseCount('jobs', 0);

        $this->travel(config('ksef.follow_up.unique_for_seconds') + 1)->seconds();
        $this->assertSame(1, $dispatcher->dispatchDue());
        $this->assertDatabaseCount('jobs', 1);
        $credential->forceFill(['access_token_valid_until' => now()->addHour()])->save();

        $this->artisan('queue:work database --queue=ksef --once --sleep=0 --tries=1 --timeout=60')
            ->assertExitCode(0);

        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_manual_upo_fetch_wins_race_and_later_job_performs_no_http(): void
    {
        $invoice = $this->eligibleInvoice();
        $submission = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now(),
        ]);
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();
        $fake->upoResponse = $this->upoXml($invoice, $submission, $submission->ksef_number);

        $this->post(route('invoices.ksef.submissions.upo.fetch', compact('invoice', 'submission')))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $fake->upoCalls);
        $this->assertNull($submission->refresh()->next_follow_up_at);

        $this->runJob($submission);

        $this->assertSame(1, $fake->upoCalls);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertSame(0, $fake->sendCalls);
    }

    public function test_terminal_states_are_not_dispatched_and_background_tooltips_are_visible(): void
    {
        $invoice = $this->eligibleInvoice();
        $processing = $this->createSubmission($invoice, KsefInvoiceSubmissionStatus::Processing, [
            'next_follow_up_at' => now()->addMinute(),
        ]);
        $terminalInvoice = $this->eligibleInvoice();
        foreach ([
            KsefInvoiceSubmissionStatus::Rejected,
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            KsefInvoiceSubmissionStatus::Preparing,
            KsefInvoiceSubmissionStatus::SessionOpened,
        ] as $terminalStatus) {
            $terminal = $this->createSubmission($terminalInvoice, $terminalStatus, [
                'next_follow_up_at' => null,
            ]);
            $this->assertNull($terminal->next_follow_up_at);
        }
        $acceptedInvoice = $this->eligibleInvoice();
        $this->createSubmission($acceptedInvoice, KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => KsefUpoFixture::ksefNumber(),
            'acquisition_date' => now(),
            'next_follow_up_at' => now()->addMinute(),
        ]);

        Queue::fake();
        $this->assertSame(0, app(KsefSubmissionFollowUpDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Status jest sprawdzany automatycznie. Kliknij, aby sprawdzić teraz.')
            ->assertSee('Faktura została przyjęta. NEX automatycznie oczekuje na UPO. Kliknij, aby spróbować pobrać teraz.')
            ->assertSee($processing->status->label());
    }

    private function runJob(KsefInvoiceSubmission $submission): void
    {
        (new KsefSubmissionFollowUpJob($submission->getKey()))
            ->handle(app(KsefSubmissionFollowUpProcessor::class));
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
            'external_id' => 'KSEF-FOLLOW-UP-'.uniqid(),
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

    private function validAccessToken(
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefCredential {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_FOLLOW_UP_API_TOKEN',
            'access_token' => 'FAKE_VALID_FOLLOW_UP_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_VALID_FOLLOW_UP_REFRESH_TOKEN',
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function createSubmission(
        Invoice $invoice,
        KsefInvoiceSubmissionStatus $status,
        array $attributes = [],
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): KsefInvoiceSubmission {
        $attempt = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;
        $payload = '<Faktura>FAKE FOLLOW UP '.$attempt.'</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_replace([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => $attempt,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now()->subMinute(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
            'session_reference_number' => KsefUpoFixture::SESSION_REFERENCE,
            'invoice_reference_number' => KsefUpoFixture::INVOICE_REFERENCE,
            'next_follow_up_at' => now()->subMinute(),
            'follow_up_action' => match ($status) {
                KsefInvoiceSubmissionStatus::Submitted,
                KsefInvoiceSubmissionStatus::Processing => 'status',
                KsefInvoiceSubmissionStatus::Uncertain => 'reconcile',
                KsefInvoiceSubmissionStatus::Accepted => 'upo',
                default => null,
            },
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function processingStatus(KsefInvoiceSubmission $submission): array
    {
        return [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-25T10:00:00Z',
            'status' => ['code' => 150, 'description' => 'Przetwarzanie'],
        ];
    }

    /** @return array<string, mixed> */
    private function acceptedStatus(KsefInvoiceSubmission $submission): array
    {
        return [
            'referenceNumber' => $submission->invoice_reference_number,
            'invoiceHash' => $submission->invoice_hash,
            'invoicingDate' => '2026-08-25T10:00:00Z',
            'acquisitionDate' => '2026-08-25T10:00:01Z',
            'permanentStorageDate' => '2026-08-25T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => KsefUpoFixture::ksefNumber(),
        ];
    }

    private function upoXml(
        Invoice $invoice,
        KsefInvoiceSubmission $submission,
        string $ksefNumber,
    ): string {
        return KsefUpoFixture::xml([
            'context_nip' => $submission->context_nip,
            'seller_nip' => $submission->seller_nip,
            'session_reference' => $submission->session_reference_number,
            'ksef_number' => $ksefNumber,
            'invoice_number' => $invoice->number,
            'issue_date' => $invoice->issue_date->format('Y-m-d'),
            'invoice_hash' => $submission->invoice_hash,
        ]);
    }
}
