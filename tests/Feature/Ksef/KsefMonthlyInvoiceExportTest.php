<?php

namespace Tests\Feature\Ksef;

use Carbon\CarbonImmutable;
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
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefCredential;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefManualInvoiceSubmissionService;
use Modules\Ksef\Services\KsefMonthlyInvoiceExportService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\KsefOnlineSessionApiFake;
use Tests\TestCase;

class KsefMonthlyInvoiceExportTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-24 12:00:00');
        config()->set('ksef.invoice_submission_enabled', true);
        Http::preventStrayRequests();
        $this->configure(KsefEnvironment::Test);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_export_tab_is_active_simple_and_contains_no_environment_field(): void
    {
        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertOk()
            ->assertSee('Eksportuj faktury z miesiąca')
            ->assertSee('name="month"', false)
            ->assertSee('value="2026-08" selected', false)
            ->assertSee('value="2025-08"', false)
            ->assertSee('data-ksef-export-environment="TEST"', false)
            ->assertSee('>Eksportuj</button>', false)
            ->assertDontSee('data-ksef-export-demo-warning', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*data-ksef-tab="export")(?=[^>]*is-active)[^>]*>/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:input|select)[^>]+name="environment"/i',
            $html,
        );
        preg_match_all('/<option value="\d{4}-\d{2}"/', $html, $options);
        $this->assertCount(13, $options[0]);
        $this->assertDoesNotMatchRegularExpression(
            '/<button(?=[^>]*type="submit")(?=[^>]*\bdisabled\b)[^>]*>Eksportuj<\/button>/s',
            $html,
        );
    }

    public function test_demo_tab_has_warning_and_confirmation_context_without_environment_input(): void
    {
        $this->configure(KsefEnvironment::Demo);

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertOk()
            ->assertSee('data-ksef-export-environment="DEMO"', false)
            ->assertSee('data-ksef-export-demo', false)
            ->assertSee('data-ksef-export-demo-warning', false)
            ->assertSee('wyłącznie dokumenty zawierające dane testowe lub fikcyjne')
            ->assertSee('Wyeksportować niewysłane Faktury z', false)
            ->assertSee('Upewnij się, że dokumenty zawierają wyłącznie dane testowe lub fikcyjne.', false);

        $this->assertDoesNotMatchRegularExpression(
            '/<(?:input|select)[^>]+name="environment"/i',
            $response->getContent(),
        );
    }

    #[DataProvider('tamperedEnvironmentCases')]
    public function test_request_environment_never_controls_export_environment(
        KsefEnvironment $configured,
        string $forged,
        string $expectedHost,
    ): void {
        $this->configure($configured);
        $this->validAccessToken($configured);
        $invoice = $this->invoice();
        $fake = $this->fakeOnlineApi();

        $this->post(route('integrations.ksef.export'), [
            'month' => '2026-08',
            'environment' => $forged,
        ])->assertSessionHas(
            'success',
            'Eksport zakończony. Przekazano: 1. Błędy: 0.',
        );

        $submission = KsefInvoiceSubmission::query()->sole();
        $this->assertSame($configured, $submission->environment);
        $this->assertSame($invoice->getKey(), $submission->invoice_id);
        $this->assertSame(1, $fake->sendCalls);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame($expectedHost, parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public static function tamperedEnvironmentCases(): array
    {
        return [
            'DEMO ignores forged TEST' => [
                KsefEnvironment::Demo,
                'test',
                'api-demo.ksef.mf.gov.pl',
            ],
            'TEST ignores forged PRODUCTION' => [
                KsefEnvironment::Test,
                'production',
                'api-test.ksef.mf.gov.pl',
            ],
        ];
    }

    public function test_eligibility_uses_finalized_vat_issue_month_enabled_series_and_no_current_environment_history(): void
    {
        $this->configure(KsefEnvironment::Demo);
        $this->validAccessToken(KsefEnvironment::Demo);
        $eligible = $this->invoice();
        $draft = $this->invoice(finalized: false);
        $proforma = $this->invoice(documentType: InvoiceDocumentType::Proforma);
        $correction = $this->invoice(documentType: InvoiceDocumentType::Correction);
        $disabledSeries = $this->invoice(seriesEnabled: false);
        $outsideMonth = $this->invoice(issueDate: '2026-07-31');
        $crossEnvironment = $this->invoice();
        $this->submission($crossEnvironment, KsefEnvironment::Test, KsefInvoiceSubmissionStatus::Accepted);

        foreach (KsefInvoiceSubmissionStatus::cases() as $status) {
            $blocked = $this->invoice();
            $this->submission($blocked, KsefEnvironment::Demo, $status);
        }

        $fake = $this->fakeOnlineApi();
        $result = app(KsefMonthlyInvoiceExportService::class)->export('2026-08');

        $this->assertSame(2, $result->eligibleCount);
        $this->assertSame(2, $result->submittedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertFalse($result->stoppedEarly);
        $this->assertSame(2, $fake->sendCalls);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $eligible->getKey(),
            'environment' => 'demo',
            'attempt_number' => 1,
        ]);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $crossEnvironment->getKey(),
            'environment' => 'demo',
            'attempt_number' => 1,
        ]);
        foreach ([$draft, $proforma, $correction, $disabledSeries, $outsideMonth] as $skipped) {
            $this->assertDatabaseMissing('ksef_invoice_submissions', [
                'invoice_id' => $skipped->getKey(),
                'environment' => 'demo',
            ]);
        }
        $this->assertNull($draft->fresh()->finalized_at);
    }

    public function test_three_first_attempts_are_exactly_once_and_same_environment_rerun_is_empty(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $firstInvoice = $this->invoice();
        $secondInvoice = $this->invoice();
        $thirdInvoice = $this->invoice();
        $invoices = collect([$firstInvoice, $secondInvoice, $thirdInvoice]);
        $fake = $this->fakeOnlineApi();
        $service = app(KsefMonthlyInvoiceExportService::class);

        $first = $service->export('2026-08');
        $second = $service->export('2026-08');

        $this->assertSame(3, $first->eligibleCount);
        $this->assertSame(3, $first->submittedCount);
        $this->assertSame(0, $first->failedCount);
        $this->assertSame(0, $second->eligibleCount);
        $this->assertSame(0, $second->submittedCount);
        $this->assertSame(3, $fake->sendCalls);
        $this->assertSame(3, $fake->openCalls);
        $this->assertSame(3, $fake->closeCalls);
        $this->assertSame(0, $fake->statusCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 3);
        $this->assertSame(
            [$firstInvoice->getKey(), $secondInvoice->getKey(), $thirdInvoice->getKey()],
            KsefInvoiceSubmission::query()->orderBy('id')->pluck('invoice_id')->all(),
        );
        $this->assertSame(
            [1, 1, 1],
            KsefInvoiceSubmission::query()
                ->whereIn('invoice_id', $invoices->pluck('id'))
                ->orderBy('invoice_id')
                ->pluck('attempt_number')
                ->all(),
        );
    }

    #[DataProvider('crossEnvironmentCases')]
    public function test_same_invoices_can_be_exported_once_in_each_environment(
        KsefEnvironment $firstEnvironment,
        KsefEnvironment $secondEnvironment,
    ): void {
        $this->configure($firstEnvironment);
        $invoices = collect([$this->invoice(), $this->invoice(), $this->invoice()]);
        $this->validAccessToken(KsefEnvironment::Test);
        $this->validAccessToken(KsefEnvironment::Demo);
        $fake = $this->fakeOnlineApi();
        $service = app(KsefMonthlyInvoiceExportService::class);

        $first = $service->export('2026-08');
        $this->configure($secondEnvironment);
        $second = $service->export('2026-08');

        $this->assertSame(3, $first->submittedCount);
        $this->assertSame(3, $second->submittedCount);
        $this->assertSame(6, $fake->sendCalls);
        foreach ($invoices as $invoice) {
            $this->assertDatabaseHas('ksef_invoice_submissions', [
                'invoice_id' => $invoice->getKey(),
                'environment' => 'test',
                'attempt_number' => 1,
            ]);
            $this->assertDatabaseHas('ksef_invoice_submissions', [
                'invoice_id' => $invoice->getKey(),
                'environment' => 'demo',
                'attempt_number' => 1,
            ]);
        }
    }

    public static function crossEnvironmentCases(): array
    {
        return [
            'TEST history then DEMO export' => [KsefEnvironment::Test, KsefEnvironment::Demo],
            'DEMO history then TEST export' => [KsefEnvironment::Demo, KsefEnvironment::Test],
        ];
    }

    public function test_local_document_failure_is_counted_without_stopping_later_invoices(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $invalid = $this->invoice();
        $metadata = $invalid->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $invalid->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $valid = $this->invoice();
        $fake = $this->fakeOnlineApi();

        $result = app(KsefMonthlyInvoiceExportService::class)->export('2026-08');

        $this->assertSame(2, $result->eligibleCount);
        $this->assertSame(1, $result->submittedCount);
        $this->assertSame(1, $result->failedCount);
        $this->assertFalse($result->stoppedEarly);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseMissing('ksef_invoice_submissions', ['invoice_id' => $invalid->getKey()]);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $valid->getKey(),
            'status' => KsefInvoiceSubmissionStatus::Submitted->value,
        ]);
    }

    public function test_past_issue_date_is_counted_as_local_failure_without_http(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $invoice = $this->invoice(issueDate: '2026-08-23');
        $this->fakeOnlineApi();

        $result = app(KsefMonthlyInvoiceExportService::class)->export('2026-08');

        $this->assertSame(1, $result->eligibleCount);
        $this->assertSame(0, $result->submittedCount);
        $this->assertSame(1, $result->failedCount);
        $this->assertFalse($result->stoppedEarly);
        $this->assertDatabaseHas('ksef_invoice_submissions', [
            'invoice_id' => $invoice->getKey(),
            'status' => KsefInvoiceSubmissionStatus::TechnicalFailed->value,
            'safe_error_code' => 'ksef_online_submission_issue_date_not_today',
        ]);
        Http::assertNothingSent();
    }

    public function test_rate_limit_stops_remaining_invoices_without_retry(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $invoices = collect([$this->invoice(), $this->invoice(), $this->invoice()]);
        $fake = $this->fakeOnlineApi();
        $fake->failures['/invoices'] = [
            'status' => 429,
            'body' => ['reasonCode' => 'RATE_LIMIT'],
            'headers' => ['Retry-After' => '30'],
        ];

        $result = app(KsefMonthlyInvoiceExportService::class)->export('2026-08');

        $this->assertTrue($result->stoppedEarly);
        $this->assertSame(0, $result->submittedCount);
        $this->assertSame(1, $result->failedCount);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        $this->assertSame(
            KsefInvoiceSubmissionStatus::TechnicalFailed,
            KsefInvoiceSubmission::query()->sole()->status,
        );
        $this->assertSame(2, $invoices->filter(
            fn (Invoice $invoice): bool => ! $invoice->ksefSubmissions()->exists(),
        )->count());
    }

    public function test_environment_change_after_first_invoice_stops_without_switching_hosts(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $this->invoice();
        $this->invoice();
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(function (Request $request) use ($fake) {
            $response = $fake($request);

            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/close')) {
                $this->configure(KsefEnvironment::Demo);
            }

            return $response;
        });

        $result = app(KsefMonthlyInvoiceExportService::class)->export('2026-08');

        $this->assertTrue($result->stoppedEarly);
        $this->assertSame(KsefEnvironment::Test, $result->environment);
        $this->assertSame(1, $result->submittedCount);
        $this->assertSame(1, $result->failedCount);
        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api-test.ksef.mf.gov.pl', parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public function test_first_attempt_guard_rejects_a_second_logical_first_attempt(): void
    {
        $this->validAccessToken(KsefEnvironment::Test);
        $invoice = $this->invoice();
        $fake = $this->fakeOnlineApi();
        $manual = app(KsefManualInvoiceSubmissionService::class);

        $manual->submitFirstAttempt($invoice, KsefEnvironment::Test);

        try {
            $manual->submitFirstAttempt($invoice, KsefEnvironment::Test);
            $this->fail('Expected duplicate first-attempt guard.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_submission_first_attempt_already_exists', $exception->safeCode);
        }

        $this->assertSame(1, $fake->sendCalls);
        $this->assertDatabaseCount('ksef_invoice_submissions', 1);
    }

    public function test_production_is_blocked_in_ui_and_post_before_http(): void
    {
        $this->configure(KsefEnvironment::Production);
        $this->validAccessToken(KsefEnvironment::Production);
        $this->invoice();

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertOk()
            ->assertSee('Operacyjny transport Faktur do środowiska produkcyjnego KSeF nie został jeszcze odblokowany.');
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*type="submit")(?=[^>]*\bdisabled\b)[^>]*>Eksportuj<\/button>/s',
            $response->getContent(),
        );

        $this->post(route('integrations.ksef.export'), ['month' => '2026-08'])
            ->assertSessionHasErrors('export');
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_gate_blocks_ui_and_crafted_post(): void
    {
        config()->set('ksef.invoice_submission_enabled', false);
        $this->invoice();

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertOk()
            ->assertSee('Wysyłka KSeF jest wyłączona na poziomie wdrożenia.');
        $this->assertMatchesRegularExpression('/<button(?=[^>]*\bdisabled\b)[^>]*>Eksportuj<\/button>/s', $response->getContent());

        $this->post(route('integrations.ksef.export'), ['month' => '2026-08'])
            ->assertSessionHasErrors('export');
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_integration_blocks_ui_and_crafted_post(): void
    {
        app(KsefSettingsService::class)->get()->forceFill(['is_active' => false])->save();
        $this->invoice();

        $response = $this->get(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertOk()
            ->assertSee('Integracja KSeF nie jest aktywna.');
        $this->assertMatchesRegularExpression('/<button(?=[^>]*\bdisabled\b)[^>]*>Eksportuj<\/button>/s', $response->getContent());

        $this->post(route('integrations.ksef.export'), ['month' => '2026-08'])
            ->assertSessionHasErrors('export');
        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public function test_current_and_oldest_allowed_months_are_accepted(): void
    {
        foreach (['2026-08' => '08.2026', '2025-08' => '08.2025'] as $month => $label) {
            $this->post(route('integrations.ksef.export'), ['month' => $month])
                ->assertSessionHasNoErrors()
                ->assertSessionHas(
                    'success',
                    "Brak nowych Faktur do przekazania do KSeF za {$label}.",
                );
        }

        Http::assertNothingSent();
    }

    #[DataProvider('invalidMonths')]
    public function test_invalid_future_and_too_old_months_are_rejected(string $month): void
    {
        $this->post(route('integrations.ksef.export'), ['month' => $month])
            ->assertRedirect(route('integrations.ksef.edit', ['tab' => 'export']))
            ->assertSessionHasErrors('month');

        $this->assertDatabaseCount('ksef_invoice_submissions', 0);
        Http::assertNothingSent();
    }

    public static function invalidMonths(): array
    {
        return [
            'invalid month number' => ['2026-13'],
            'display format' => ['08.2026'],
            'letters' => ['abc'],
            'future' => ['2026-09'],
            'too old' => ['2025-07'],
        ];
    }

    private function configure(KsefEnvironment $environment): void
    {
        app(KsefSettingsService::class)->get()->forceFill([
            'is_active' => true,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
    }

    private function invoice(
        string $issueDate = '2026-08-24',
        bool $finalized = true,
        bool $seriesEnabled = true,
        InvoiceDocumentType $documentType = InvoiceDocumentType::Invoice,
    ): Invoice {
        $order = $this->createDocumentOrder([
            'external_id' => 'KSEF-MONTHLY-'.uniqid(),
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
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext($issueDate.' 12:00:00'),
        )->refresh()->load('items');

        if ($finalized) {
            $invoice = app(InvoiceFinalizationService::class)->finalize($invoice)->load('items');
        }

        if ($documentType !== InvoiceDocumentType::Invoice) {
            $invoice->forceFill(['document_type' => $documentType])->saveQuietly();
        }

        return $invoice->refresh();
    }

    private function validAccessToken(KsefEnvironment $environment): KsefCredential
    {
        return KsefCredential::query()->create([
            'environment' => $environment,
            'authentication_method' => KsefAuthenticationMethod::Token,
            'api_token' => 'FAKE_MONTHLY_API_TOKEN_'.$environment->value,
            'access_token' => 'FAKE_MONTHLY_ACCESS_TOKEN_'.$environment->value,
            'access_token_valid_until' => now()->addHour(),
            'refresh_token' => 'FAKE_MONTHLY_REFRESH_TOKEN_'.$environment->value,
            'refresh_token_valid_until' => now()->addDay(),
        ]);
    }

    private function fakeOnlineApi(): KsefOnlineSessionApiFake
    {
        $fake = new KsefOnlineSessionApiFake;
        Http::fake(fn (Request $request) => $fake($request));

        return $fake;
    }

    private function submission(
        Invoice $invoice,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status,
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>FAKE MONTHLY HISTORY</Faktura>';

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $invoice->getKey(),
            'environment' => $environment,
            'context_nip' => '9876543210',
            'seller_nip' => '9876543210',
            'attempt_number' => 1,
            'status' => $status,
            'schema_id' => 'FA (3) 1-0E',
            'generated_at' => now(),
            'payload_xml' => $payload,
            'invoice_hash' => base64_encode(hash('sha256', $payload, true)),
            'invoice_size' => strlen($payload),
        ]);
    }
}
