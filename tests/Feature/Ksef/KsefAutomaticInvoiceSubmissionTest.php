<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Jobs\KsefAutomaticInvoiceSubmissionJob;
use Modules\Ksef\Jobs\KsefSubmissionFollowUpJob;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionProcessor;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionRateLimiter;
use Modules\Ksef\Services\KsefSettingsService;
use Modules\Ksef\Services\KsefSubmissionFollowUpProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\Support\KsefUpoFixture;
use Tests\TestCase;

class KsefAutomaticInvoiceSubmissionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-08-26 10:00:00'));
    }

    public function test_eligible_new_invoice_is_dispatched_after_commit_on_dedicated_queue(): void
    {
        Queue::fake();

        $invoice = $this->issueInvoice();

        Queue::assertPushedOn('ksef-submit', KsefAutomaticInvoiceSubmissionJob::class, function ($job) use ($invoice): bool {
            return $job->invoiceId === $invoice->getKey()
                && $job->environment === KsefEnvironment::Test->value
                && $job->contextNip === '9876543210'
                && $job->afterCommit === true;
        });

        $job = new KsefAutomaticInvoiceSubmissionJob(
            (int) $invoice->getKey(),
            KsefEnvironment::Test,
            '9876543210',
        );

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('ksef_submit', $job->connection);
        $this->assertSame('ksef-submit', $job->queue);
        $this->assertSame(120, $job->timeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.ksef_submit.retry_after'));
        $this->assertSame(1, $job->tries);
        $this->assertSame(21600, $job->uniqueFor);
        $this->assertSame(
            'ksef-automatic-submission-'.$invoice->getKey().'-test-'.hash('sha256', '9876543210'),
            $job->uniqueId(),
        );
        $this->assertFalse($invoice->isFinalized());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('ineligibleConfigurations')]
    public function test_ineligible_invoice_is_not_dispatched(
        bool $gateEnabled,
        bool $integrationActive,
        bool $automaticSubmission,
        bool $seriesEnabled,
        KsefEnvironment $environment,
    ): void {
        config()->set('ksef.invoice_submission_enabled', $gateEnabled);
        Queue::fake();

        $this->issueInvoice(
            integrationActive: $integrationActive,
            automaticSubmission: $automaticSubmission,
            seriesEnabled: $seriesEnabled,
            environment: $environment,
        );

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function ineligibleConfigurations(): array
    {
        return [
            'deployment gate disabled' => [false, true, true, true, KsefEnvironment::Test],
            'integration inactive' => [true, false, true, true, KsefEnvironment::Test],
            'automatic submission disabled' => [true, true, false, true, KsefEnvironment::Test],
            'series disabled' => [true, true, true, false, KsefEnvironment::Test],
            'production blocked operationally' => [true, true, true, true, KsefEnvironment::Production],
        ];
    }

    public function test_changed_environment_before_job_execution_cancels_send_without_http(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();

        app(KsefSettingsService::class)->get()->forceFill([
            'environment' => KsefEnvironment::Demo,
        ])->save();

        $this->runJob($invoice, KsefEnvironment::Test);

        $this->assertFalse($invoice->refresh()->isFinalized());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_changed_context_nip_before_job_execution_cancels_send_without_http(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();

        app(KsefSettingsService::class)->get()->forceFill([
            'context_nip' => '1111111111',
        ])->save();

        $this->runJob($invoice);

        $this->assertFalse($invoice->refresh()->isFinalized());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('cancellingConfigurationChanges')]
    public function test_configuration_change_before_job_execution_cancels_without_recovery(
        string $change,
    ): void {
        Queue::fake();
        $invoice = $this->issueInvoice();

        match ($change) {
            'gate' => config()->set('ksef.invoice_submission_enabled', false),
            'integration' => app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save(),
            'automatic' => app(KsefSettingsService::class)->get()->forceFill(['automatic_submission' => false])->save(),
            'series' => KsefSeriesSetting::query()
                ->where('invoice_series_id', $invoice->invoice_series_id)
                ->update(['is_enabled' => false]),
        };

        $this->runJob($invoice);

        $this->assertFalse($invoice->refresh()->isFinalized());
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function cancellingConfigurationChanges(): array
    {
        return [
            'deployment gate disabled' => ['gate'],
            'integration disabled' => ['integration'],
            'automatic submission disabled' => ['automatic'],
            'series disabled' => ['series'],
        ];
    }

    public function test_first_submission_limiter_reserves_delayed_slots_per_environment_and_context(): void
    {
        config()->set('ksef.automatic_submission.rate_limits', [
            'per_second' => 1,
            'per_minute' => 1,
            'per_hour' => 1,
        ]);
        $limiter = app(KsefAutomaticInvoiceSubmissionRateLimiter::class);

        $this->assertSame(0, $limiter->reserveDelay(KsefEnvironment::Test, '1111111111'));
        $this->assertSame(3600, $limiter->reserveDelay(KsefEnvironment::Test, '1111111111'));
        $this->assertSame(0, $limiter->reserveDelay(KsefEnvironment::Demo, '1111111111'));
        $this->assertSame(0, $limiter->reserveDelay(KsefEnvironment::Test, '2222222222'));

        Queue::fake();
        $this->issueInvoice();
        $this->issueInvoice();
        $jobs = Queue::pushed(KsefAutomaticInvoiceSubmissionJob::class)->values();

        $this->assertCount(2, $jobs);
        $this->assertSame(0, $jobs[0]->delay);
        $this->assertSame(3600, $jobs[1]->delay);
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_invoice_can_be_edited_before_worker_and_current_version_is_frozen_for_ksef(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();
        $item = $invoice->items->firstOrFail();
        $this->assertFalse($invoice->isFinalized());

        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $item,
            $this->itemPayload($invoice, $item, ['name' => 'Aktualna wersja wysłana do KSeF']),
        );
        $this->validAccessToken();
        $this->fakeOnlineApi();

        $this->runJob($invoice);

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertTrue($invoice->refresh()->isFinalized());
        $this->assertStringContainsString('Aktualna wersja wysłana do KSeF', $submission->payload_xml);
    }

    public function test_automatic_job_sends_once_and_hands_processing_to_existing_follow_up(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->runJob($invoice);

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertTrue($invoice->refresh()->isFinalized());
        $this->assertSame(KsefInvoiceSubmissionStatus::Processing, $submission->status);
        $this->assertSame('status', $submission->follow_up_action);
        $this->assertNotNull($submission->next_follow_up_at);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);

        $this->runJob($invoice);

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
    }

    public function test_immediate_acceptance_schedules_upo_without_fetching_it_in_first_send_job(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();
        $this->validAccessToken();
        $fake = new KsefOnlineSessionApiFake;
        $fake->openResponse['referenceNumber'] = KsefUpoFixture::SESSION_REFERENCE;
        $fake->sendResponse['referenceNumber'] = KsefUpoFixture::INVOICE_REFERENCE;
        $fake->statusResponse = [
            'invoicingDate' => '2026-08-26T10:00:00Z',
            'acquisitionDate' => '2026-08-26T10:00:01Z',
            'permanentStorageDate' => '2026-08-26T10:00:02Z',
            'status' => ['code' => 200, 'description' => 'Zaakceptowana'],
            'ksefNumber' => KsefUpoFixture::ksefNumber(),
        ];

        Http::fake(function (Request $request) use ($fake, $invoice) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if (str_ends_with($path, '/upo') && $fake->upoResponse === null) {
                $submission = KsefInvoiceSubmission::query()->sole();
                $fake->upoResponse = KsefUpoFixture::xml([
                    'context_nip' => $submission->context_nip,
                    'seller_nip' => $submission->seller_nip,
                    'session_reference' => $submission->session_reference_number,
                    'ksef_number' => KsefUpoFixture::ksefNumber(),
                    'invoice_number' => $invoice->number,
                    'issue_date' => $invoice->issue_date->format('Y-m-d'),
                    'invoice_hash' => $submission->invoice_hash,
                ]);
            }

            return $fake($request);
        });

        $this->runJob($invoice);

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame(KsefInvoiceSubmissionStatus::Accepted, $submission->status);
        $this->assertSame(KsefUpoFixture::ksefNumber(), $submission->ksef_number);
        $this->assertNull(
            $submission->last_follow_up_error_code,
            (string) $submission->last_follow_up_error_message,
        );
        $this->assertSame('upo', $submission->follow_up_action);
        $this->assertSame(0, $submission->follow_up_attempts);
        $this->assertSame('2026-08-26 10:01:00', $submission->next_follow_up_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('ksef_invoice_upos', 0);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(0, $fake->upoCalls);
        Queue::assertPushedOn('ksef', KsefSubmissionFollowUpJob::class, function ($job) use ($submission): bool {
            return $job->submissionId === $submission->getKey()
                && $job->delay?->equalTo($submission->next_follow_up_at) === true;
        });

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

        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertNull($submission->refresh()->next_follow_up_at);
        $this->assertSame(1, $fake->upoCalls);
    }

    public function test_manual_and_automatic_first_attempt_race_never_posts_twice(): void
    {
        Queue::fake();
        $invoice = $this->issueInvoice();
        $this->validAccessToken();
        $fake = $this->fakeOnlineApi();

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasNoErrors();
        $this->runJob($invoice);

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(1, KsefInvoiceSubmission::query()->sole()->attempt_number);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);

        $invoice = $this->issueInvoice();
        $this->runJob($invoice);
        $before = $fake->sendCalls;

        $this->post(route('invoices.ksef.submissions.first-attempt', $invoice))
            ->assertSessionHasErrors('ksef');

        $this->assertSame(2, KsefInvoiceSubmission::query()->count());
        $this->assertSame($before, $fake->sendCalls);
    }

    #[DataProvider('terminalAutomaticStates')]
    public function test_existing_terminal_or_uncertain_attempt_is_never_automatically_resent(
        KsefInvoiceSubmissionStatus $status,
    ): void {
        Queue::fake();
        $invoice = $this->issueInvoice();
        KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => KsefEnvironment::Test,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA (3)',
            'generated_at' => now(),
            'payload_xml' => '<Invoice />',
            'invoice_hash' => hash('sha256', '<Invoice />'),
            'invoice_size' => 11,
        ]);

        $this->runJob($invoice);

        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        Http::assertNothingSent();
    }

    public static function terminalAutomaticStates(): array
    {
        return [
            'rejected' => [KsefInvoiceSubmissionStatus::Rejected],
            'technical failed' => [KsefInvoiceSubmissionStatus::TechnicalFailed],
            'uncertain' => [KsefInvoiceSubmissionStatus::Uncertain],
        ];
    }

    private function issueInvoice(
        bool $integrationActive = true,
        bool $automaticSubmission = true,
        bool $seriesEnabled = true,
        KsefEnvironment $environment = KsefEnvironment::Test,
    ): Invoice {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => $integrationActive,
            'automatic_submission' => $automaticSubmission,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();

        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-AUTOMATIC-'.uniqid(),
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
            'is_enabled' => $seriesEnabled,
        ]);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        )->refresh()->load('items');
    }

    private function validAccessToken(): KsefCredential
    {
        return KsefCredential::query()->create([
            'environment' => KsefEnvironment::Test,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_AUTOMATIC_API_TOKEN',
            'access_token' => 'FAKE_AUTOMATIC_ACCESS_TOKEN',
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_AUTOMATIC_REFRESH_TOKEN',
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

    private function runJob(
        Invoice $invoice,
        KsefEnvironment $environment = KsefEnvironment::Test,
        string $contextNip = '9876543210',
    ): void {
        (new KsefAutomaticInvoiceSubmissionJob(
            (int) $invoice->getKey(),
            $environment,
            $contextNip,
        ))->handle(app(KsefAutomaticInvoiceSubmissionProcessor::class));
    }

    /** @return array<string, mixed> */
    private function itemPayload(Invoice $invoice, InvoiceItem $item, array $overrides = []): array
    {
        $item = $item->fresh();

        return array_replace([
            'expected_lock_version' => $invoice->fresh()->lock_version,
            'name' => $item->name,
            'description' => $item->description,
            'unit_name' => $item->unit_name,
            'quantity' => $item->quantity,
            'unit_price_gross' => $item->unit_price_gross,
            'vat_rate' => $item->vat_rate,
            'vat_code' => $item->vat_code,
            'position' => $item->position,
        ], $overrides);
    }
}
