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
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefAuthenticationMethod;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Jobs\KsefAutomaticInvoiceSubmissionJob;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefAutomaticInvoiceSubmissionProcessor;
use Modules\Ksef\Services\KsefSettingsService;
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

    public function test_eligible_new_invoice_is_dispatched_after_commit_on_existing_ksef_queue(): void
    {
        Queue::fake();

        $invoice = $this->issueInvoice();

        Queue::assertPushedOn('ksef', KsefAutomaticInvoiceSubmissionJob::class, function ($job) use ($invoice): bool {
            return $job->invoiceId === $invoice->getKey()
                && $job->environment === KsefEnvironment::Test->value
                && $job->afterCommit === true;
        });

        $job = new KsefAutomaticInvoiceSubmissionJob(
            (int) $invoice->getKey(),
            KsefEnvironment::Test,
        );

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('database', $job->connection);
        $this->assertSame('ksef', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(21600, $job->uniqueFor);
        $this->assertSame(
            'ksef-automatic-submission-'.$invoice->getKey().'-test',
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

    public function test_immediate_acceptance_downloads_upo_and_finishes_follow_up(): void
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
        $this->assertNull($submission->follow_up_action);
        $this->assertNull($submission->next_follow_up_at);
        $this->assertDatabaseCount('ksef_invoice_upos', 1);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertSame(1, $fake->statusCalls);
        $this->assertSame(1, $fake->upoCalls);
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
    ): void {
        (new KsefAutomaticInvoiceSubmissionJob(
            (int) $invoice->getKey(),
            $environment,
        ))->handle(app(KsefAutomaticInvoiceSubmissionProcessor::class));
    }
}
