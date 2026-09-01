<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class KsefCorrectionListTest extends TestCase
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

    public function test_correction_list_has_ksef_column_and_actionable_first_send_without_extra_correction_column(): void
    {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = $this->issueKsefFinancialCorrection($root);

        $response = $this->get(route('invoices.corrections.index'));

        $response->assertOk()
            ->assertSee(route('invoices.ksef.submissions.first-attempt', $correction), false)
            ->assertSee('Korekta nieprzekazana - przekaż do KSeF')
            ->assertSee('data-ksef-confirm-question="Czy przekazać Korektę do KSeF 2.0?"', false)
            ->assertSee('name="return_to" value="corrections"', false);
        $head = $this->tableHead($response->getContent());
        $this->assertStringContainsString('>KSeF</th>', $head);
        $this->assertStringNotContainsString('>Korekta</th>', $head);
    }

    public function test_disabled_correction_series_renders_non_actionable_not_sent_status(): void
    {
        $this->configure(KsefEnvironment::Test, seriesEnabled: false);
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('Nie wysłano')
            ->assertDontSee(route('invoices.ksef.submissions.first-attempt', $correction), false);
    }

    public function test_processing_correction_has_manual_refresh_action_with_document_aware_aria(): void
    {
        [$correction, $submission] = $this->correctionWithSubmission(KsefInvoiceSubmissionStatus::Processing);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee(route('invoices.ksef.submissions.refresh', [
                'invoice' => $correction,
                'submission' => $submission,
            ]), false)
            ->assertSee('Sprawdź status Korekta '.$correction->number.' w KSeF')
            ->assertSee('Status jest sprawdzany automatycznie. Kliknij, aby sprawdzić teraz.');
    }

    public function test_uncertain_correction_offers_reconciliation_and_never_retry(): void
    {
        [$correction, $submission] = $this->correctionWithSubmission(KsefInvoiceSubmissionStatus::Uncertain);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee(route('invoices.ksef.submissions.reconcile', [
                'invoice' => $correction,
                'submission' => $submission,
            ]), false)
            ->assertSee('Stan niepewny')
            ->assertSee('bez ponownego wysyłania dokumentu')
            ->assertDontSee('action="'.route('invoices.ksef.submissions.store', $correction).'"', false)
            ->assertDontSee('>Ponów</button>', false);
    }

    public function test_rejected_correction_shows_safe_details_read_only_link_and_explicit_retry(): void
    {
        [$correction, $submission] = $this->correctionWithSubmission(KsefInvoiceSubmissionStatus::Rejected, [
            'ksef_status_code' => 415,
            'safe_error_message' => 'Bezpieczny komunikat odrzucenia Korekty.',
        ]);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('Kod KSeF: 415')
            ->assertSee('Bezpieczny komunikat odrzucenia Korekty.')
            ->assertSee(route('invoices.corrections.edit', [
                'correction' => $correction,
                'return_to' => 'corrections',
            ]), false)
            ->assertSee(route('invoices.ksef.submissions.store', $correction), false)
            ->assertSee('data-ksef-confirm-question="Czy ponownie przekazać Korektę do KSeF 2.0?"', false)
            ->assertSee('>Ponów</button>', false);
    }

    public function test_technical_failure_allows_explicit_retry(): void
    {
        [$correction] = $this->correctionWithSubmission(KsefInvoiceSubmissionStatus::TechnicalFailed, [
            'safe_error_message' => 'Kontrolowany błąd techniczny.',
        ]);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('Błąd techniczny')
            ->assertSee('Kontrolowany błąd techniczny.')
            ->assertSee(route('invoices.ksef.submissions.store', $correction), false)
            ->assertSee('>Ponów</button>', false);
    }

    public function test_accepted_correction_has_document_aware_upo_action(): void
    {
        [$correction, $submission] = $this->correctionWithSubmission(KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => '9876543210-20260821-000000000001-15',
            'acquisition_date' => '2026-08-21 10:00:01',
        ]);

        $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('Zaakceptowana')
            ->assertSee(route('invoices.ksef.submissions.upo.fetch', [
                'invoice' => $correction,
                'submission' => $submission,
            ]), false)
            ->assertSee('Korekta została przyjęta. NEX automatycznie oczekuje na UPO.')
            ->assertSee('data-ksef-list-upo-trigger', false);
    }

    #[DataProvider('crossEnvironmentCases')]
    public function test_current_environment_never_falls_back_to_other_environment_submission(
        KsefEnvironment $activeEnvironment,
        KsefEnvironment $historicalEnvironment,
        bool $firstSendAvailable,
    ): void {
        $this->configure($activeEnvironment);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, $activeEnvironment);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->createSubmission($correction, $historicalEnvironment, KsefInvoiceSubmissionStatus::Accepted, [
            'ksef_number' => '9876543210-20260821-000000000001-15',
        ]);

        $response = $this->get(route('invoices.corrections.index'))
            ->assertOk()
            ->assertSee('Nie wysłano')
            ->assertDontSee('Zaakceptowana');

        if ($firstSendAvailable) {
            $response->assertSee(route('invoices.ksef.submissions.first-attempt', $correction), false);
        } else {
            $response->assertDontSee(route('invoices.ksef.submissions.first-attempt', $correction), false);
        }
    }

    public static function crossEnvironmentCases(): array
    {
        return [
            'TEST ignores DEMO' => [KsefEnvironment::Test, KsefEnvironment::Demo, true],
            'DEMO ignores PRODUCTION' => [KsefEnvironment::Demo, KsefEnvironment::Production, true],
            'PRODUCTION ignores DEMO but remains operationally blocked' => [
                KsefEnvironment::Production,
                KsefEnvironment::Demo,
                false,
            ],
        ];
    }

    /** @return array{0: Invoice, 1: KsefInvoiceSubmission} */
    private function correctionWithSubmission(
        KsefInvoiceSubmissionStatus $status,
        array $attributes = [],
    ): array {
        $this->configure(KsefEnvironment::Test);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Test);
        $correction = app(InvoiceFinalizationService::class)->finalize(
            $this->issueKsefFinancialCorrection($root),
        );

        return [
            $correction,
            $this->createSubmission($correction, KsefEnvironment::Test, $status, $attributes),
        ];
    }

    private function configure(KsefEnvironment $environment, bool $seriesEnabled = true): void
    {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'is_active' => true,
            'automatic_submission' => false,
            'environment' => $environment,
            'context_nip' => '9876543210',
        ])->save();
        $series = InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
        KsefSeriesSetting::query()->updateOrCreate(
            ['invoice_series_id' => $series->getKey()],
            ['is_enabled' => $seriesEnabled],
        );
    }

    private function createSubmission(
        Invoice $correction,
        KsefEnvironment $environment,
        KsefInvoiceSubmissionStatus $status,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        $payload = '<Faktura>LISTA KOREKT</Faktura>';

        return KsefInvoiceSubmission::query()->create(array_merge([
            'invoice_id' => $correction->getKey(),
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
            'session_reference_number' => '20260821-SE-000000000001-01',
            'invoice_reference_number' => '20260821-IN-000000000001-01',
        ], $attributes));
    }

    private function tableHead(string $html): string
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $matches);

        return $matches[1] ?? '';
    }
}
