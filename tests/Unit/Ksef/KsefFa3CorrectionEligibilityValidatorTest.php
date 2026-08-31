<?php

namespace Tests\Unit\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\CorrectionTotalsCalculator;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\Services\InvoiceDecimalCalculator;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Modules\Ksef\Enums\KsefEnvironment;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefInvoiceSubmission;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefFa3CorrectionEligibilityValidator;
use Modules\Ksef\Services\KsefInvoiceProvenanceService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3CorrectionEligibilityValidatorTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    private const SELLER_NIP = '9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_preflight_accepts_current_correction_and_authoritative_requires_finalization(): void
    {
        [$root, $correction, $settings] = $this->financialScenario();

        $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Preflight);
        $this->expectDomainError(
            'ksef_fa3_correction_document_not_finalized',
            fn () => $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Authoritative),
        );

        $correction = app(InvoiceFinalizationService::class)->finalize($correction);
        $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Authoritative);
        $this->assertSame($root->getKey(), $correction->corrected_invoice_id);
    }

    public function test_explicit_outside_root_preserves_preflight_and_authoritative_finalization_rules(): void
    {
        $settings = $this->settings(KsefEnvironment::Production);
        $root = $this->issueRoot();
        $this->markOutside($root, KsefEnvironment::Production);
        $correction = $this->issueFinancialCorrection($root);

        $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Preflight);
        $this->expectDomainError(
            'ksef_fa3_correction_document_not_finalized',
            fn () => $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Authoritative),
        );

        $correction = app(InvoiceFinalizationService::class)->finalize($correction);
        $this->assertEligible($correction, $settings, KsefFa3EligibilityMode::Authoritative);
    }

    public function test_outside_wrong_environment_and_unknown_root_fail_closed(): void
    {
        $settings = $this->settings(KsefEnvironment::Production);
        $wrongEnvironmentRoot = $this->issueRoot();
        $this->markOutside($wrongEnvironmentRoot, KsefEnvironment::Demo);
        $wrongEnvironment = $this->issueFinancialCorrection($wrongEnvironmentRoot);

        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_unresolved',
            fn () => $this->assertEligible($wrongEnvironment, $settings),
        );

        $unknownRoot = $this->issueRoot();
        $unknown = $this->issueFinancialCorrection($unknownRoot);
        $this->expectDomainError(
            'ksef_fa3_correction_source_ksef_unresolved',
            fn () => $this->assertEligible($unknown, $settings),
        );
    }

    public function test_only_an_issued_correction_is_supported(): void
    {
        $settings = $this->settings();
        $root = $this->issueRoot();

        $this->expectDomainError(
            'ksef_fa3_correction_document_not_supported',
            fn () => $this->assertEligible($root, $settings),
        );

        $correction = $this->issueFinancialCorrection($root);
        $correction->forceFill(['status' => InvoiceDocumentStatus::Draft])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_document_not_supported',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
    }

    public function test_production_never_uses_demo_or_test_acceptance(): void
    {
        $settings = $this->settings(KsefEnvironment::Production);

        foreach ([KsefEnvironment::Demo, KsefEnvironment::Test] as $environment) {
            $root = $this->issueRoot();
            $correction = $this->issueFinancialCorrection($root);
            $this->acceptedSubmission($root, $environment);

            $error = $this->expectDomainError(
                'ksef_fa3_correction_source_ksef_environment_mismatch',
                fn () => $this->assertEligible($correction, $settings),
            );
            $this->assertSame($environment->value, $error->metadata()['accepted_environments'][0]);
        }
    }

    public function test_previous_correction_gap_is_rejected_and_complete_three_link_chain_passes(): void
    {
        $settings = $this->settings();
        $root = $this->issueRoot();
        $this->acceptedSubmission($root);
        $first = app(InvoiceFinalizationService::class)->finalize(
            $this->issueFinancialCorrection($root, quantity: 2),
        );
        $second = $this->issueFinancialCorrection($root, quantity: 3);

        $this->expectDomainError(
            'ksef_fa3_correction_previous_ksef_not_accepted',
            fn () => $this->assertEligible($second, $settings),
        );

        $this->acceptedSubmission($first);
        $this->assertEligible($second, $settings);
        $second = app(InvoiceFinalizationService::class)->finalize($second);
        $this->acceptedSubmission($second);
        $third = $this->issueFinancialCorrection($root, quantity: 4);

        $this->assertEligible($third, $settings);
        $this->assertSame($second->getKey(), $third->previous_correction_id);
    }

    #[DataProvider('invalidSnapshotCases')]
    public function test_missing_invalid_version_and_profile_snapshots_fail_closed(
        string $case,
        string $expectedCode,
    ): void {
        [, $correction, $settings] = $this->financialScenario();
        $metadata = $correction->tax_metadata_snapshot;

        match ($case) {
            'missing' => data_forget($metadata, 'ksef_correction'),
            'version' => data_set($metadata, 'ksef_correction.version', 2),
            'profile' => data_set($metadata, 'ksef_correction.profile', 'ordinary'),
            'buyer_before_key' => data_forget($metadata, 'ksef_correction.buyer_before_semantics'),
        };
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $this->expectDomainError(
            $expectedCode,
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
    }

    public static function invalidSnapshotCases(): array
    {
        return [
            'missing' => ['missing', 'ksef_fa3_correction_snapshot_missing'],
            'version' => ['version', 'ksef_fa3_correction_snapshot_version_unsupported'],
            'profile' => ['profile', 'ksef_fa3_correction_snapshot_invalid'],
            'missing buyer before key' => ['buyer_before_key', 'ksef_fa3_correction_buyer_snapshot_invalid'],
        ];
    }

    public function test_effective_source_document_is_revalidated_for_second_correction(): void
    {
        $settings = $this->settings();
        $root = $this->issueRoot();
        $this->acceptedSubmission($root);
        $first = app(InvoiceFinalizationService::class)->finalize(
            $this->issueFinancialCorrection($root, quantity: 2),
        );
        $this->acceptedSubmission($first);
        $second = $this->issueFinancialCorrection($root, quantity: 3);
        $metadata = $second->tax_metadata_snapshot;
        data_set($metadata, 'ksef_correction.source_document', [
            'invoice_id' => $root->getKey(),
            'document_type' => 'invoice',
        ]);
        $second->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $error = $this->expectDomainError(
            'ksef_fa3_correction_snapshot_invalid',
            fn () => $this->assertEligible($second->refresh(), $settings),
        );
        $this->assertSame('source_document_mismatch', $error->metadata()['reason']);
    }

    #[DataProvider('lineCoverageTamperCases')]
    public function test_line_treatments_require_exact_current_item_coverage(string $case): void
    {
        [, $correction, $settings] = $this->financialScenario();
        $metadata = $correction->tax_metadata_snapshot;
        $treatments = $metadata['ksef_correction']['line_treatments'];

        if ($case === 'missing') {
            $treatments = [];
        } elseif ($case === 'extra') {
            $extra = $treatments[0];
            $extra['invoice_item_id'] = 999999;
            $treatments[] = $extra;
        } elseif ($case === 'duplicate') {
            $treatments[] = $treatments[0];
        } elseif ($case === 'source') {
            $treatments[0]['source_invoice_item_id'] = 999999;
        } elseif ($case === 'source_key') {
            unset($treatments[0]['source_invoice_item_id']);
        } else {
            $treatments[0]['position'] = 999;
        }

        $metadata['ksef_correction']['line_treatments'] = $treatments;
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $this->expectDomainError(
            'ksef_fa3_correction_tax_snapshot_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
    }

    public static function lineCoverageTamperCases(): array
    {
        return [
            'missing treatment' => ['missing'],
            'extra old item ID' => ['extra'],
            'duplicate item ID' => ['duplicate'],
            'source item mismatch' => ['source'],
            'source item key missing' => ['source_key'],
            'position mismatch' => ['position'],
        ];
    }

    public function test_resolved_standard_semantics_must_be_canonical(): void
    {
        [, $correction, $settings] = $this->financialScenario();
        $metadata = $correction->tax_metadata_snapshot;
        $metadata['ksef_correction']['line_treatments'][0]['before']['fa3_rate'] = '8';
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();

        $error = $this->expectDomainError(
            'ksef_fa3_correction_tax_snapshot_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
        $this->assertSame('tax_treatment_inconsistent', $error->metadata()['reason']);
        $this->assertSame('before', $error->metadata()['side']);
    }

    public function test_zero_semantics_are_canonical_and_historical_wdt_ignores_current_setting(): void
    {
        $settings = $this->settings(
            zeroClassification: KsefZeroVatClassification::Wdt,
        );
        $root = $this->issueRoot(
            [['vat_rate' => '0.00']],
            $this->euBuyer(),
        );
        $this->acceptedSubmission($root);
        $settings->forceFill([
            'zero_vat_classification' => KsefZeroVatClassification::Export,
        ])->save();
        $correction = $this->issueFinancialCorrection($root);

        $this->assertEligible($correction, $settings->refresh());
        $treatment = data_get(
            $correction->tax_metadata_snapshot,
            'ksef_correction.line_treatments.0.before',
        );
        $this->assertSame('wdt', $treatment['treatment']);
        $this->assertSame('0 WDT', $treatment['fa3_rate']);

        $metadata = $correction->tax_metadata_snapshot;
        $metadata['ksef_correction']['line_treatments'][0]['before']['fa3_rate'] = '0 EX';
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_tax_snapshot_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings->refresh()),
        );
    }

    public function test_unresolved_and_unsupported_history_have_specific_errors(): void
    {
        $settings = $this->settings();

        $missingRoot = $this->issueRoot();
        $metadata = $missingRoot->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $missingRoot->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->acceptedSubmission($missingRoot);
        $missing = $this->issueFinancialCorrection($missingRoot);
        $this->expectDomainError(
            'ksef_fa3_correction_tax_semantics_unresolved',
            fn () => $this->assertEligible($missing, $settings),
        );

        $inconsistentRoot = $this->issueRoot();
        $metadata = $inconsistentRoot->tax_metadata_snapshot;
        $metadata['ksef_tax']['line_treatments'][0]['tax_identity'] = 'rate:8.00';
        $inconsistentRoot->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->acceptedSubmission($inconsistentRoot);
        $inconsistent = $this->issueFinancialCorrection($inconsistentRoot);
        $error = $this->expectDomainError(
            'ksef_fa3_correction_tax_semantics_unresolved',
            fn () => $this->assertEligible($inconsistent, $settings),
        );
        $this->assertSame('source_semantics_inconsistent', $error->metadata()['reason']);

        $rateRoot = $this->issueRoot([['vat_rate' => '6.00']]);
        $this->acceptedSubmission($rateRoot);
        $rate = $this->issueFinancialCorrection($rateRoot);
        $this->expectDomainError(
            'ksef_fa3_correction_unsupported_vat_rate',
            fn () => $this->assertEligible($rate, $settings),
        );

        $codeRoot = $this->updateRootTaxIdentity($this->issueRoot(), null, 'ZW');
        $this->acceptedSubmission($codeRoot);
        $code = $this->issueFinancialCorrection($codeRoot);
        $this->expectDomainError(
            'ksef_fa3_correction_unsupported_vat_code',
            fn () => $this->assertEligible($code, $settings),
        );
    }

    public function test_buyer_after_and_before_semantics_are_revalidated_without_repair(): void
    {
        $settings = $this->settings();
        $root = $this->issueRoot();
        $this->acceptedSubmission($root);
        $changedBuyer = array_replace($root->buyer_snapshot, ['street' => 'Nowa ulica']);
        $correction = $this->issueBuyerCorrection($root, $changedBuyer);

        $buyer = $correction->buyer_snapshot;
        $buyer['tax_identity']['identifier'] = '5210080410';
        $correction->forceFill(['buyer_snapshot' => $buyer])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_snapshot_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
        $this->assertSame('5210080410', data_get($correction->fresh()->buyer_snapshot, 'tax_identity.identifier'));

        $unsupported = $this->issueBuyerCorrectionOnNewRoot($settings);
        $buyer = $unsupported->buyer_snapshot;
        $buyer['subject_flags']['jst'] = true;
        $unsupported->forceFill(['buyer_snapshot' => $buyer])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_snapshot_unsupported',
            fn () => $this->assertEligible($unsupported->refresh(), $settings),
        );

        $correction = $this->issueBuyerCorrectionOnNewRoot($settings);
        $metadata = $correction->tax_metadata_snapshot;
        $metadata['ksef_correction']['buyer_before_semantics'] = null;
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_snapshot_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );

        $incomplete = $this->issueBuyerCorrectionOnNewRoot($settings);
        $orderSnapshot = $incomplete->order_snapshot;
        data_set($orderSnapshot, 'correction.buyer_before.street', null);
        data_set($orderSnapshot, 'correction.buyer_before.building_number', null);
        data_set($orderSnapshot, 'correction.buyer_before.apartment_number', null);
        $incomplete->forceFill(['order_snapshot' => $orderSnapshot])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_before_incomplete',
            fn () => $this->assertEligible($incomplete->refresh(), $settings),
        );

        [, $financial] = $this->financialScenario($settings);
        $metadata = $financial->tax_metadata_snapshot;
        $metadata['ksef_correction']['buyer_before_semantics'] = [
            'tax_identity' => $financial->buyer_snapshot['tax_identity'],
            'subject_flags' => $financial->buyer_snapshot['subject_flags'],
        ];
        $financial->forceFill(['tax_metadata_snapshot' => $metadata])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_snapshot_invalid',
            fn () => $this->assertEligible($financial->refresh(), $settings),
        );
    }

    public function test_buyer_address_change_and_tax_id_formatting_pass_but_identity_change_fails(): void
    {
        $settings = $this->settings();

        $addressRoot = $this->issueRoot();
        $this->acceptedSubmission($addressRoot);
        $address = $this->issueBuyerCorrection(
            $addressRoot,
            array_replace($addressRoot->buyer_snapshot, ['street' => 'Nowa ulica']),
        );
        $this->assertEligible($address, $settings);

        $formatRoot = $this->issueRoot();
        $this->acceptedSubmission($formatRoot);
        $formatted = $this->issueBuyerCorrection(
            $formatRoot,
            array_replace($formatRoot->buyer_snapshot, ['tax_id' => '526-025-09-95']),
        );
        $this->assertEligible($formatted, $settings);

        $identityRoot = $this->issueRoot();
        $this->acceptedSubmission($identityRoot);
        $changed = $this->issueBuyerCorrection(
            $identityRoot,
            array_replace($identityRoot->buyer_snapshot, ['tax_id' => '5210080410']),
        );
        $this->expectDomainError(
            'ksef_fa3_correction_buyer_identity_change_not_supported',
            fn () => $this->assertEligible($changed, $settings),
        );
    }

    public function test_buyer_without_tax_id_obeys_the_saved_configuration_policy(): void
    {
        $settings = $this->settings(sendWithoutBuyerNip: false);
        $root = $this->issueRoot(orderAttributes: ['billing_tax_id' => null]);
        $this->acceptedSubmission($root);
        $correction = $this->issueFinancialCorrection($root);

        $this->expectDomainError(
            'ksef_fa3_correction_buyer_tax_id_required',
            fn () => $this->assertEligible($correction, $settings),
        );

        $settings->forceFill(['send_without_buyer_nip' => true])->save();
        $this->assertEligible($correction, $settings->refresh());
    }

    public function test_buyer_only_and_financial_only_corrections_are_eligible(): void
    {
        $settings = $this->settings();

        $buyerRoot = $this->issueRoot();
        $this->acceptedSubmission($buyerRoot);
        $buyerOnly = $this->issueBuyerCorrection(
            $buyerRoot,
            array_replace($buyerRoot->buyer_snapshot, ['street' => 'Zmieniona']),
        );
        $this->assertSame('0.00', $buyerOnly->total_gross);
        $this->assertEligible($buyerOnly, $settings);

        [, $financial] = $this->financialScenario($settings);
        $this->assertNull(data_get(
            $financial->tax_metadata_snapshot,
            'ksef_correction.buyer_before_semantics',
        ));
        $this->assertEligible($financial, $settings);
    }

    public function test_tampered_no_effect_correction_is_rejected(): void
    {
        [, $correction, $settings] = $this->financialScenario();
        $item = $correction->items()->firstOrFail();
        $after = $item->correction_after_snapshot;
        $item->forceFill([
            'correction_before_snapshot' => $after,
            'correction_difference_snapshot' => $this->lineDifference($after, $after),
        ])->saveQuietly();
        $totals = app(CorrectionTotalsCalculator::class)->calculate([[
            'correction_before_snapshot' => $after,
            'correction_after_snapshot' => $after,
        ]]);
        $correctionTotals = $correction->correction_totals_snapshot;
        $correctionTotals['before'] = $totals['before'];
        $correctionTotals['after'] = $totals['after'];
        $correctionTotals['difference'] = $totals['difference'];
        $correction->forceFill([
            'correction_totals_snapshot' => $correctionTotals,
            'tax_summary_snapshot' => $totals['difference']['tax_summary_snapshot'],
            'total_net' => '0.00',
            'total_vat' => '0.00',
            'total_gross' => '0.00',
        ])->saveQuietly();

        $this->expectDomainError(
            'ksef_fa3_correction_effect_missing',
            fn () => $this->assertEligible($correction->refresh('items'), $settings),
        );
    }

    public function test_wdt_requires_a_non_polish_eu_vat_buyer_for_both_states(): void
    {
        $settings = $this->settings(
            zeroClassification: KsefZeroVatClassification::Wdt,
        );
        $validRoot = $this->issueRoot([['vat_rate' => '0.00']], $this->euBuyer());
        $this->acceptedSubmission($validRoot);
        $this->assertEligible($this->issueFinancialCorrection($validRoot), $settings);

        $invalidRoot = $this->issueRoot([['vat_rate' => '0.00']]);
        $this->acceptedSubmission($invalidRoot);
        $invalid = $this->issueFinancialCorrection($invalidRoot);
        $this->expectDomainError(
            'ksef_fa3_wdt_buyer_mismatch',
            fn () => $this->assertEligible($invalid, $settings),
        );
    }

    public function test_seller_and_currency_must_match_the_root_and_effective_source(): void
    {
        [$root, $correction, $settings] = $this->financialScenario();
        $seller = $correction->seller_snapshot;
        $seller['tax_id'] = '5260250995';
        $correction->forceFill(['seller_snapshot' => $seller])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_seller_mismatch',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );

        $correction->forceFill([
            'seller_snapshot' => $root->seller_snapshot,
            'currency' => 'EUR',
        ])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_currency_invalid',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );

        $seller = $root->seller_snapshot;
        $seller['street'] = null;
        $seller['building_number'] = null;
        $correction->forceFill([
            'seller_snapshot' => $seller,
            'currency' => $root->currency,
        ])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_correction_seller_incomplete',
            fn () => $this->assertEligible($correction->refresh(), $settings),
        );
    }

    public function test_foreign_monetary_and_buyer_only_corrections_use_only_frozen_data(): void
    {
        $settings = $this->settings();
        $root = $this->makeForeign($this->issueRoot());
        $this->acceptedSubmission($root);
        $monetary = $this->issueFinancialCorrection($root);

        $this->assertIsArray($monetary->tax_metadata_snapshot['currency_conversion']);
        $this->assertIsArray($monetary->tax_metadata_snapshot['converted_tax_summary']);
        $this->assertEligible($monetary, $settings);

        $buyerRoot = $this->issueRoot();
        $buyerRoot->forceFill(['currency' => 'EUR'])->saveQuietly();
        $this->acceptedSubmission($buyerRoot);
        $buyerOnly = $this->issueBuyerCorrection(
            $buyerRoot,
            array_replace($buyerRoot->buyer_snapshot, ['street' => 'Walutowa zmiana']),
        );
        $this->assertArrayNotHasKey('currency_conversion', $buyerOnly->tax_metadata_snapshot);
        $this->assertEligible($buyerOnly, $settings);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Invoice, 1: Invoice, 2: KsefSetting}
     */
    private function financialScenario(?KsefSetting $settings = null): array
    {
        $settings ??= $this->settings();
        $root = $this->issueRoot();
        $this->acceptedSubmission($root, $settings->environment);
        $correction = $this->issueFinancialCorrection($root);

        return [$root, $correction, $settings];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $orderAttributes
     */
    private function issueRoot(
        array $items = [['vat_rate' => '23.00']],
        array $orderAttributes = [],
    ): Invoice {
        $total = '0.00';
        foreach ($items as $item) {
            $total = app(InvoiceDecimalCalculator::class)->add(
                $total,
                (string) ($item['total_price_gross'] ?? $item['unit_price_gross'] ?? '123.00'),
            );
        }

        $order = $this->createDocumentOrder(array_merge([
            'external_id' => 'KSEF-ELIGIBILITY-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
            'total_gross' => $total,
        ], $orderAttributes));
        foreach ($items as $index => $item) {
            $gross = (string) ($item['unit_price_gross'] ?? '123.00');
            $this->createDocumentItem($order, array_merge([
                'product_name' => 'Pozycja '.($index + 1),
                'unit_price_gross' => $gross,
                'total_price_gross' => $gross,
                'vat_rate' => '23.00',
            ], $item));
        }

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext('2026-08-20 10:00:00'),
        )->fresh('items');
    }

    private function issueFinancialCorrection(Invoice $root, int $quantity = 2): Invoice
    {
        $items = $this->submittedItems($root);
        $items[0]['quantity'] = $quantity;

        return $this->issueCorrection($root, $items);
    }

    /** @param array<string, mixed> $buyer */
    private function issueBuyerCorrection(Invoice $root, array $buyer): Invoice
    {
        return $this->issueCorrection($root, buyer: $buyer);
    }

    private function issueBuyerCorrectionOnNewRoot(KsefSetting $settings): Invoice
    {
        $root = $this->issueRoot();
        $this->acceptedSubmission($root, $settings->environment);

        return $this->issueBuyerCorrection(
            $root,
            array_replace($root->buyer_snapshot, ['street' => 'Inna ulica']),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @param  array<string, mixed>|null  $buyer
     */
    private function issueCorrection(
        Invoice $root,
        ?array $items = null,
        ?array $buyer = null,
    ): Invoice {
        $effective = app(CorrectionSourceStateService::class)->effectiveDocument($root);

        return app(CorrectionService::class)->issue(
            $root,
            $this->correctionSeries(),
            $effective->getKey(),
            $effective->lock_version,
            $this->payload([
                'expected_source_document_id' => $effective->getKey(),
                'expected_source_lock_version' => $effective->lock_version,
                'change_items' => $items !== null,
                'items' => $items ?? [],
                'change_buyer' => $buyer !== null,
                'buyer' => $buyer ?? [],
            ]),
            $this->documentContext('2026-08-21 10:00:00'),
        )->fresh(['items', 'correctedInvoice', 'previousCorrection']);
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedItems(Invoice $root): array
    {
        return app(CorrectionSourceStateService::class)
            ->effectiveItems($root)
            ->map(fn (array $item): array => $this->submittedItem(
                $item['snapshot'],
                $item['source_item_id'],
                $item['source_item']->order_item_id,
            ))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function submittedItem(array $snapshot, int $sourceItemId, ?int $orderItemId): array
    {
        return [
            'source_item_id' => $sourceItemId,
            'order_item_id' => $orderItemId,
            'line_type' => $snapshot['line_type'],
            'position' => $snapshot['position'],
            'name' => $snapshot['name'],
            'description' => $snapshot['description'],
            'unit_name' => $snapshot['unit_name'],
            'quantity' => (int) $snapshot['quantity'],
            'unit_price_gross' => $this->twoDecimals($snapshot['unit_price_gross']),
            'vat_rate' => $snapshot['vat_rate'] !== null
                ? rtrim(rtrim((string) $snapshot['vat_rate'], '0'), '.')
                : null,
            'vat_code' => $snapshot['vat_code'],
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'expected_source_document_id' => 1,
            'expected_source_lock_version' => 1,
            'expected_lock_version' => 1,
            'correction_series_id' => $this->correctionSeries()->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-21',
            'sale_date' => '2026-08-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester Korekty',
            'additional_information' => 'Test eligibility Korekty',
            'change_items' => false,
            'change_buyer' => false,
            'items' => [],
            'buyer' => [],
        ], $overrides);
    }

    private function settings(
        KsefEnvironment $environment = KsefEnvironment::Production,
        bool $sendWithoutBuyerNip = false,
        KsefZeroVatClassification $zeroClassification = KsefZeroVatClassification::Wdt,
    ): KsefSetting {
        $settings = app(KsefSettingsService::class)->get();
        $settings->forceFill([
            'environment' => $environment,
            'send_without_buyer_nip' => $sendWithoutBuyerNip,
            'zero_vat_classification' => $zeroClassification,
        ])->save();

        return $settings->refresh();
    }

    private function correctionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    private function acceptedSubmission(
        Invoice $document,
        KsefEnvironment $environment = KsefEnvironment::Production,
    ): KsefInvoiceSubmission {
        $attempt = ((int) KsefInvoiceSubmission::query()
            ->where('invoice_id', $document->getKey())
            ->where('environment', $environment->value)
            ->max('attempt_number')) + 1;

        return KsefInvoiceSubmission::query()->create([
            'invoice_id' => $document->getKey(),
            'environment' => $environment,
            'context_nip' => self::SELLER_NIP,
            'seller_nip' => self::SELLER_NIP,
            'attempt_number' => $attempt,
            'status' => KsefInvoiceSubmissionStatus::Accepted,
            'schema_id' => 'FA3',
            'generated_at' => '2026-08-29 10:00:00',
            'payload_xml' => '<Faktura/>',
            'invoice_hash' => base64_encode(hash('sha256', '<Faktura/>', true)),
            'invoice_size' => strlen('<Faktura/>'),
            'ksef_number' => $this->validKsefNumber(self::SELLER_NIP, '0100001AF629'),
        ]);
    }

    private function markOutside(Invoice $invoice, KsefEnvironment $environment): void
    {
        app(KsefInvoiceProvenanceService::class)->markOutsideKsef($invoice, $environment);
    }

    private function validKsefNumber(string $sellerNip, string $reference): string
    {
        $base = $sellerNip.'-20260819-'.$reference;
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

    /** @return array<string, mixed> */
    private function euBuyer(): array
    {
        return [
            'billing_name' => 'Max Mustermann',
            'billing_company_name' => 'Muster GmbH',
            'billing_tax_id' => 'DE123456789',
            'billing_street' => 'Musterstrasse',
            'billing_building_number' => '5',
            'billing_apartment_number' => null,
            'billing_postal_code' => '10115',
            'billing_city' => 'Berlin',
            'billing_country_code' => 'DE',
        ];
    }

    private function makeForeign(Invoice $root): Invoice
    {
        $currency = app(InvoiceCurrencyConversionService::class)->metadataForHistoricalRate(
            $root->tax_summary_snapshot,
            new NbpExchangeRate(
                source: 'NBP',
                currencyCode: 'EUR',
                tableType: 'A',
                tableNumber: '137/A/NBP/2026',
                effectiveDate: '2026-07-17',
                referenceDate: '2026-07-20',
                rate: '4.342000',
            ),
            'vat_art_31a_standard_v1',
        );
        $root->forceFill([
            'currency' => 'EUR',
            'tax_metadata_snapshot' => array_merge($root->tax_metadata_snapshot, $currency),
        ])->saveQuietly();

        return $root->fresh('items');
    }

    private function updateRootTaxIdentity(
        Invoice $root,
        ?string $vatRate,
        ?string $vatCode,
    ): Invoice {
        $item = $root->fresh('items')->items->sole();

        return app(InvoiceEditService::class)->updateItem($root, $item, [
            'expected_lock_version' => $root->lock_version,
            'name' => $item->name,
            'description' => $item->description,
            'unit_name' => $item->unit_name,
            'quantity' => (string) $item->quantity,
            'unit_price_gross' => (string) $item->unit_price_gross,
            'vat_rate' => $vatRate,
            'vat_code' => $vatCode,
            'position' => $item->position,
        ])->fresh('items');
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function lineDifference(array $before, array $after): array
    {
        $decimal = app(InvoiceDecimalCalculator::class);

        return array_replace($after, [
            'quantity' => $decimal->subtract((string) $after['quantity'], (string) $before['quantity'], 4),
            'unit_price_net' => $decimal->subtract((string) $after['unit_price_net'], (string) $before['unit_price_net'], 4),
            'unit_price_gross' => $decimal->subtract((string) $after['unit_price_gross'], (string) $before['unit_price_gross'], 4),
            'total_net' => $decimal->subtract((string) $after['total_net'], (string) $before['total_net']),
            'total_vat' => $decimal->subtract((string) $after['total_vat'], (string) $before['total_vat']),
            'total_gross' => $decimal->subtract((string) $after['total_gross'], (string) $before['total_gross']),
        ]);
    }

    private function twoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function assertEligible(
        Invoice $correction,
        KsefSetting $settings,
        KsefFa3EligibilityMode $mode = KsefFa3EligibilityMode::Preflight,
    ): void {
        app(KsefFa3CorrectionEligibilityValidator::class)->assertEligible(
            $correction,
            $settings,
            $mode,
        );

        $this->addToAssertionCount(1);
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Expected domain error '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }
}
