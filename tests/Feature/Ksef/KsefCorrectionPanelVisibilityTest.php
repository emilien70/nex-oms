<?php

namespace Tests\Feature\Ksef;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Invoices\Services\ManualCorrectionFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefOfflineIssuanceProcedure;
use Modules\Ksef\Models\KsefOfflineIssuance;
use Modules\Ksef\Models\KsefSeriesSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\Support\Ksef\UsesAutocommitDatabase;
use Tests\TestCase;

class KsefCorrectionPanelVisibilityTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use UsesAutocommitDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->ksefSettings(KsefEnvironment::Demo)->forceFill(['is_active' => true, 'context_nip' => '9876543210'])->save();
    }

    #[DataProvider('disabledSeriesSettings')]
    public function test_disabled_or_unconfigured_series_hides_empty_panel_but_keeps_manual_closing(?bool $setting): void
    {
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);
        if ($setting !== null) {
            KsefSeriesSetting::query()->create(['invoice_series_id' => $correction->invoice_series_id, 'is_enabled' => $setting]);
        }
        $before = [$root->fresh()->getAttributes(), $correction->getAttributes()];

        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertDontSee('data-ksef-submissions-panel', false)
            ->assertDontSee('id="correction-ksef"', false)
            ->assertDontSee('href="#correction-ksef"', false)
            ->assertSee('data-correction-finalize-trigger', false);

        $this->assertSame($before, [$root->fresh()->getAttributes(), $correction->fresh()->getAttributes()]);
        Http::assertNothingSent();
    }

    public static function disabledSeriesSettings(): array
    {
        return [[null], [false]];
    }

    #[DataProvider('seriesCombinations')]
    public function test_panel_depends_on_the_correction_series_not_the_root_series(bool $rootEnabled, bool $correctionEnabled): void
    {
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $root->invoice_series_id, 'is_enabled' => $rootEnabled]);
        KsefSeriesSetting::query()->create(['invoice_series_id' => $correction->invoice_series_id, 'is_enabled' => $correctionEnabled]);
        $response = $this->get(route('invoices.corrections.edit', $correction))->assertOk();

        foreach (['data-ksef-submissions-panel', 'href="#correction-ksef"'] as $marker) {
            $correctionEnabled ? $response->assertSee($marker, false) : $response->assertDontSee($marker, false);
        }
        Http::assertNothingSent();
    }

    public static function seriesCombinations(): array
    {
        return [[false, true], [true, false]];
    }

    public function test_root_history_does_not_make_an_empty_correction_panel_visible(): void
    {
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);

        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertDontSee('data-ksef-submissions-panel', false)
            ->assertDontSee('href="#correction-ksef"', false);
        Http::assertNothingSent();
    }

    #[DataProvider('onlineHistories')]
    public function test_online_history_remains_visible_with_series_disabled_in_any_environment(KsefInvoiceSubmissionStatus $status, KsefEnvironment $environment): void
    {
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        $submission = $this->acceptKsefDocument($correction, $environment);
        $submission->forceFill(['status' => $status,
            'ksef_number' => $status === KsefInvoiceSubmissionStatus::Accepted ? $submission->ksef_number : null])->save();
        if ($status === KsefInvoiceSubmissionStatus::Accepted) {
            $submission->upo()->create(['schema_id' => 'UPO', 'payload_xml' => '<UPO/>',
                'payload_hash' => hash('sha256', '<UPO/>'), 'payload_size' => 6, 'fetched_at' => now('UTC')]);
        }
        KsefSeriesSetting::query()->create(['invoice_series_id' => $correction->invoice_series_id, 'is_enabled' => false]);
        $before = $submission->fresh()->getAttributes();

        $response = $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-ksef-submissions-panel', false)
            ->assertSee('href="#correction-ksef"', false)
            ->assertSee($status->label())
            ->assertDontSee('data-ksef-send-form', false);
        if ($status === KsefInvoiceSubmissionStatus::Accepted) {
            $response->assertSee($submission->ksef_number)
                ->assertSee(route('invoices.ksef.submissions.upo.download', ['invoice' => $correction, 'submission' => $submission]), false);
        }
        $this->assertSame($before, $submission->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public static function onlineHistories(): array
    {
        $cases = [];
        foreach ([KsefEnvironment::Demo, KsefEnvironment::Production] as $environment) {
            foreach ([KsefInvoiceSubmissionStatus::Accepted, KsefInvoiceSubmissionStatus::Rejected,
                KsefInvoiceSubmissionStatus::Uncertain, KsefInvoiceSubmissionStatus::Preparing] as $status) {
                $cases[] = [$status, $environment];
            }
        }

        return $cases;
    }

    #[DataProvider('historyEnvironments')]
    public function test_offline_history_without_online_attempts_remains_visible_in_any_environment(KsefEnvironment $environment): void
    {
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        $issuance = KsefOfflineIssuance::query()->create([
            'invoice_id' => $correction->id, 'environment' => $environment,
            'procedure' => KsefOfflineIssuanceProcedure::Offline24, 'issue_date' => '2026-08-21', 'issued_at' => now('UTC'),
            'seller_nip' => '9876543210', 'context_identifier_type' => 'Nip', 'context_identifier_value' => '9876543210',
            'schema_id' => 'FA (3) 1-0E', 'payload_xml' => '<Faktura/>', 'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => 10, 'certificate_serial_number' => 'TEST', 'certificate_fingerprint_sha256' => str_repeat('A', 64),
            'certificate_valid_from' => '2026-01-01', 'certificate_valid_until' => '2027-01-01', 'certificate_remote_status' => 'Active',
            'certificate_remote_valid_from' => '2026-01-01', 'certificate_remote_valid_until' => '2027-01-01', 'certificate_remote_verified_at' => now('UTC'),
            'invoice_verification_url' => 'https://example.test/invoice', 'certificate_verification_url' => 'https://example.test/certificate',
        ]);
        $before = $issuance->fresh()->getAttributes();

        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-ksef-submissions-panel', false)
            ->assertSee('href="#correction-ksef"', false)
            ->assertSee('Offline24');
        $this->assertSame($before, $issuance->fresh()->getAttributes());
        $this->assertSame(0, $correction->ksefSubmissions()->count());
        Http::assertNothingSent();
    }

    public static function historyEnvironments(): array
    {
        return [[KsefEnvironment::Demo], [KsefEnvironment::Production]];
    }

    public function test_manually_closed_outside_correction_keeps_the_closed_notice_without_empty_ksef_panel(): void
    {
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);
        app(ManualCorrectionFinalizationService::class)->finalize($correction, $correction->lock_version, KsefEnvironment::Demo, true);

        $this->get(route('invoices.corrections.edit', $correction))->assertOk()
            ->assertSee('data-finalized-correction-notice', false)
            ->assertDontSee('data-ksef-submissions-panel', false)
            ->assertDontSee('href="#correction-ksef"', false)
            ->assertDontSee('data-correction-finalize-trigger', false);
        Http::assertNothingSent();
    }

    public function test_create_form_has_no_ksef_panel_or_anchor(): void
    {
        $root = $this->issueKsefRoot();
        $this->get(route('invoices.corrections.create', $root))->assertOk()
            ->assertDontSee('data-ksef-submissions-panel', false)
            ->assertDontSee('href="#correction-ksef"', false);
        Http::assertNothingSent();
    }

    public function test_ordinary_invoice_ksef_panel_is_unchanged(): void
    {
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Demo);
        $this->get(route('invoices.edit', $root))->assertOk()->assertSee('data-ksef-submissions-panel', false);
        Http::assertNothingSent();
    }
}
