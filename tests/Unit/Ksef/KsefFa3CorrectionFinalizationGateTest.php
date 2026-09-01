<?php

namespace Tests\Unit\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\Support\Ksef\CreatesKsefFa3CorrectionScenarios;
use Tests\TestCase;

class KsefFa3CorrectionFinalizationGateTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use CreatesKsefFa3CorrectionScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_missing_ksef_settings_skips_correction_preflight(): void
    {
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        KsefSetting::query()->delete();

        $this->assertDatabaseCount('ksef_settings', 0);
        $this->assertTrue($this->finalize($correction)->isFinalized());
        $this->assertSlotDeleted($correction);
        Http::assertNothingSent();
    }

    public function test_inactive_ksef_skips_correction_preflight(): void
    {
        $this->configure(KsefEnvironment::Production, active: false);
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($correction);

        $finalized = $this->finalize($correction);

        $this->assertTrue($finalized->isFinalized());
        $this->assertSlotDeleted($correction);
        Http::assertNothingSent();
    }

    public function test_disabled_correction_series_skips_preflight(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);

        $finalized = $this->finalize($correction);

        $this->assertTrue($finalized->isFinalized());
        $this->assertSlotDeleted($correction);
        Http::assertNothingSent();
    }

    public function test_same_environment_accepted_root_passes_preflight_without_upo(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($correction);

        $finalized = $this->finalize($correction);

        $this->assertTrue($finalized->isFinalized());
        $this->assertSlotDeleted($correction);
        Http::assertNothingSent();
    }

    public function test_explicit_same_environment_outside_root_passes_preflight(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->markKsefOutside($root, KsefEnvironment::Production);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($correction);

        $this->assertTrue($this->finalize($correction)->isFinalized());
        $this->assertSlotDeleted($correction);
        Http::assertNothingSent();
    }

    public function test_unresolved_root_fails_before_finalization_and_slot_deletion(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($correction);

        $this->assertFinalizationFailure(
            $correction,
            'ksef_fa3_correction_source_ksef_unresolved',
        );
    }

    public function test_other_environment_root_fails_before_finalization_and_slot_deletion(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Demo);
        $correction = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($correction);

        $this->assertFinalizationFailure(
            $correction,
            'ksef_fa3_correction_source_ksef_environment_mismatch',
        );
    }

    public function test_previous_correction_must_be_accepted_in_exact_environment_without_upo(): void
    {
        $this->configure(KsefEnvironment::Production);
        $root = $this->issueKsefRoot();
        $this->acceptKsefDocument($root, KsefEnvironment::Production);
        $first = $this->issueKsefFinancialCorrection($root);
        $this->enableSeries($first);
        $this->finalize($first);
        $this->acceptKsefDocument($first, KsefEnvironment::Demo);
        $second = $this->issueKsefFinancialCorrection($root, 3);

        $this->assertFinalizationFailure(
            $second,
            'ksef_fa3_correction_previous_ksef_environment_mismatch',
        );

        $this->acceptKsefDocument($first, KsefEnvironment::Production);
        $this->assertTrue($this->finalize($second)->isFinalized());
        $this->assertSlotDeleted($second);
        Http::assertNothingSent();
    }

    private function configure(KsefEnvironment $environment, bool $active = true): void
    {
        $settings = $this->ksefSettings($environment);
        $settings->forceFill([
            'is_active' => $active,
            'context_nip' => '9876543210',
        ])->save();
    }

    private function enableSeries(Invoice $correction): void
    {
        KsefSeriesSetting::query()->updateOrCreate(
            ['invoice_series_id' => $correction->invoice_series_id],
            ['is_enabled' => true],
        );
    }

    private function finalize(Invoice $correction): Invoice
    {
        return app(InvoiceFinalizationService::class)->finalize($correction);
    }

    private function assertFinalizationFailure(Invoice $correction, string $code): void
    {
        try {
            $this->finalize($correction);
            $this->fail('Expected Correction finalization to fail with '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }

        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', [
            'invoice_id' => $correction->getKey(),
        ]);
        Http::assertNothingSent();
    }

    private function assertSlotDeleted(Invoice $correction): void
    {
        $this->assertDatabaseMissing('order_document_slots', [
            'invoice_id' => $correction->getKey(),
        ]);
    }
}
