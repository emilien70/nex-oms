<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefFa3EligibilityMode;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Models\KsefSeriesSetting;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Services\KsefFa3EligibilityValidator;
use Modules\Ksef\Services\KsefFa3SemanticSnapshotService;
use Modules\Ksef\Services\KsefSettingsService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3PreparationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_new_invoice_freezes_ordinary_tax_buyer_and_subject_semantics(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice([
            'billing_tax_id' => 'PL526-025-09-95',
        ]);

        $snapshot = $invoice->tax_metadata_snapshot['ksef_tax'];
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame('ordinary', $snapshot['profile']);
        $this->assertSame([
            'cash_accounting' => false,
            'self_billing' => false,
            'reverse_charge' => false,
            'split_payment' => false,
            'exemption' => null,
            'new_transport_mean' => false,
            'triangular_transaction' => false,
            'margin_scheme' => false,
        ], $snapshot['annotations']);
        $this->assertCount(2, $snapshot['line_treatments']);
        $this->assertSame('standard', $snapshot['line_treatments'][0]['treatment']);
        $this->assertSame('23', $snapshot['line_treatments'][0]['fa3_rate']);
        $this->assertSame($invoice->items->first()->getKey(), $snapshot['line_treatments'][0]['invoice_item_id']);
        $this->assertSame([
            'version' => 1,
            'status' => 'resolved',
            'type' => 'pl_nip',
            'country_code' => 'PL',
            'identifier' => '5260250995',
        ], $invoice->buyer_snapshot['tax_identity']);
        $this->assertSame([
            'version' => 1,
            'jst' => false,
            'vat_group' => false,
        ], $invoice->buyer_snapshot['subject_flags']);

        $before = $invoice->fresh()->getAttributes();
        app(KsefFa3SemanticSnapshotService::class)->refresh($invoice);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_zero_vat_and_split_payment_are_frozen_while_new_or_retaxed_lines_use_current_settings(): void
    {
        Http::preventStrayRequests();
        $this->settings()->forceFill([
            'zero_vat_classification' => KsefZeroVatClassification::Wdt,
            'default_split_payment' => false,
        ])->save();
        $invoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $item = $invoice->items->first();

        $this->settings()->forceFill([
            'zero_vat_classification' => KsefZeroVatClassification::Export,
            'default_split_payment' => true,
        ])->save();

        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $item,
            $this->itemPayload($invoice, $item, [
                'name' => 'Nazwa zmieniona',
                'unit_price_gross' => '90.00',
            ]),
        );
        $this->assertSame('wdt', $this->treatmentFor($invoice, $item->getKey())['treatment']);
        $this->assertFalse($invoice->tax_metadata_snapshot['ksef_tax']['annotations']['split_payment']);

        $invoice = app(InvoiceEditService::class)->addItem($invoice, [
            'expected_lock_version' => $invoice->lock_version,
            'name' => 'Nowa pozycja zero',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => '1',
            'unit_price_gross' => '25.00',
            'vat_rate' => '0',
            'vat_code' => null,
            'position' => 2,
        ]);
        $newItem = $invoice->items->firstWhere('name', 'Nowa pozycja zero');
        $this->assertSame('export', $this->treatmentFor($invoice, $newItem->getKey())['treatment']);

        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $invoice->items->firstWhere('id', $item->getKey()),
            $this->itemPayload($invoice, $item, ['vat_rate' => '23']),
        );
        $this->assertSame('standard', $this->treatmentFor($invoice, $item->getKey())['treatment']);

        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $invoice->items->firstWhere('id', $item->getKey()),
            $this->itemPayload($invoice, $item, ['vat_rate' => '0']),
        );
        $this->assertSame('export', $this->treatmentFor($invoice, $item->getKey())['treatment']);

        $newInvoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->assertSame('export', $newInvoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0]['treatment']);
        $this->assertTrue($newInvoice->tax_metadata_snapshot['ksef_tax']['annotations']['split_payment']);

        $this->settings()->forceFill(['default_split_payment' => false])->save();
        $this->assertTrue($newInvoice->fresh()->tax_metadata_snapshot['ksef_tax']['annotations']['split_payment']);
    }

    public function test_copying_items_from_order_creates_new_item_semantics_without_name_matching(): void
    {
        Http::preventStrayRequests();
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Wdt])->save();
        $invoice = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $oldItemId = $invoice->items->first()->getKey();
        $invoice->order->items()->firstOrFail()->update(['product_name' => 'Nowa pozycja zamówienia']);
        $this->settings()->forceFill(['zero_vat_classification' => KsefZeroVatClassification::Domestic])->save();

        $invoice = app(InvoiceEditService::class)->copyItemsFromOrder($invoice, $invoice->lock_version);
        $newItem = $invoice->items->first();

        $this->assertNotSame($oldItemId, $newItem->getKey());
        $this->assertSame('domestic_zero', $this->treatmentFor($invoice, $newItem->getKey())['treatment']);
        $this->assertNull(collect($invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'])
            ->firstWhere('invoice_item_id', $oldItemId));
    }

    public function test_unsupported_rate_and_vat_code_do_not_block_issue_but_fail_ksef_eligibility(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(
            ['billing_tax_id' => '5260250995'],
            ['vat_rate' => '6.00'],
            ['include_shipping' => false],
        );
        $this->assertSame('unsupported', $invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0]['status']);
        $this->assertSame('unsupported_percentage', $invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0]['reason']);
        $this->expectDomainError(
            'ksef_fa3_unsupported_vat_rate',
            fn () => $this->eligibility($invoice, KsefFa3EligibilityMode::Preflight),
        );

        $item = $invoice->items->first();
        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $item,
            $this->itemPayload($invoice, $item, ['vat_rate' => '23', 'vat_code' => 'zw']),
        );
        $treatment = $this->treatmentFor($invoice, $item->getKey());
        $this->assertSame('unsupported_vat_code', $treatment['reason']);
        $this->assertSame('ZW', $treatment['vat_code']);
        $this->expectDomainError(
            'ksef_fa3_unsupported_vat_code',
            fn () => $this->eligibility($invoice, KsefFa3EligibilityMode::Preflight),
        );
    }

    public function test_each_supported_standard_vat_rate_has_an_exact_fa3_mapping(): void
    {
        Http::preventStrayRequests();

        foreach (['23.00' => '23', '22.00' => '22', '8.00' => '8', '7.00' => '7', '5.00' => '5'] as $rate => $fa3Rate) {
            $invoice = $this->issueInvoice(
                ['billing_tax_id' => '5260250995'],
                ['vat_rate' => $rate],
                ['include_shipping' => false],
            );
            $treatment = $invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0];

            $this->assertSame('resolved', $treatment['status']);
            $this->assertSame('standard', $treatment['treatment']);
            $this->assertSame($fa3Rate, $treatment['fa3_rate']);
            $this->eligibility($invoice, KsefFa3EligibilityMode::Preflight);
        }
    }

    public function test_zw_np_oo_and_unknown_vat_codes_are_all_rejected_only_by_fa3_eligibility(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(
            ['billing_tax_id' => '5260250995'],
            series: ['include_shipping' => false],
        );
        $item = $invoice->items->first();

        foreach (['ZW', 'NP', 'OO', 'UNKNOWN_CODE'] as $code) {
            $invoice = app(InvoiceEditService::class)->updateItem(
                $invoice,
                $item,
                $this->itemPayload($invoice, $item, ['vat_rate' => '23', 'vat_code' => $code]),
            );
            $this->assertSame($code, $this->treatmentFor($invoice, $item->getKey())['vat_code']);
            $this->expectDomainError(
                'ksef_fa3_unsupported_vat_code',
                fn () => $this->eligibility($invoice, KsefFa3EligibilityMode::Preflight),
            );
        }
    }

    public function test_three_four_and_other_unmapped_percentage_rates_remain_valid_nex_invoices_but_not_fa3_eligible(): void
    {
        Http::preventStrayRequests();

        foreach (['3.00', '4.00', '20.00'] as $rate) {
            $invoice = $this->issueInvoice(
                ['billing_tax_id' => '5260250995'],
                ['vat_rate' => $rate],
                ['include_shipping' => false],
            );

            $this->assertSame('unsupported', $invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0]['status']);
            $this->expectDomainError(
                'ksef_fa3_unsupported_vat_rate',
                fn () => $this->eligibility($invoice, KsefFa3EligibilityMode::Preflight),
            );
        }
    }

    public function test_eu_vat_is_required_for_wdt_and_ambiguous_foreign_identity_is_rejected(): void
    {
        Http::preventStrayRequests();
        $valid = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => 'DE123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->assertSame('eu_vat', $valid->buyer_snapshot['tax_identity']['type']);
        $this->assertSame('DE', $valid->buyer_snapshot['tax_identity']['country_code']);
        $this->assertSame('123456789', $valid->buyer_snapshot['tax_identity']['identifier']);
        $this->eligibility($valid, KsefFa3EligibilityMode::Preflight);

        $ambiguous = $this->issueInvoice(
            ['billing_country_code' => 'DE', 'billing_tax_id' => '123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->assertSame('unresolved', $ambiguous->buyer_snapshot['tax_identity']['status']);
        $this->assertSame('eu_vat_identity_ambiguous', $ambiguous->buyer_snapshot['tax_identity']['reason']);
        $this->expectDomainError(
            'ksef_fa3_buyer_identity_unresolved',
            fn () => $this->eligibility($ambiguous, KsefFa3EligibilityMode::Preflight),
        );

        $greek = $this->issueInvoice(
            ['billing_country_code' => 'GR', 'billing_tax_id' => 'EL123456789'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->assertSame('EL', $greek->buyer_snapshot['tax_identity']['country_code']);

        $polishBuyer = $this->issueInvoice(
            ['billing_tax_id' => '5260250995'],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->expectDomainError(
            'ksef_fa3_wdt_buyer_mismatch',
            fn () => $this->eligibility($polishBuyer, KsefFa3EligibilityMode::Preflight),
        );

        $noIdBuyer = $this->issueInvoice(
            ['billing_tax_id' => null],
            ['vat_rate' => '0.00'],
            ['include_shipping' => false],
        );
        $this->settings()->forceFill(['send_without_buyer_nip' => true])->save();
        $this->expectDomainError(
            'ksef_fa3_wdt_buyer_mismatch',
            fn () => $this->eligibility($noIdBuyer, KsefFa3EligibilityMode::Preflight),
        );
    }

    public function test_deleting_an_item_removes_its_persisted_line_treatment(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(['billing_tax_id' => '5260250995'], series: ['include_shipping' => false]);
        $invoice = app(InvoiceEditService::class)->addItem($invoice, [
            'expected_lock_version' => $invoice->lock_version,
            'name' => 'Pozycja do usunięcia',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => '1',
            'unit_price_gross' => '10.00',
            'vat_rate' => '23',
            'vat_code' => null,
            'position' => 2,
        ]);
        $deletedItem = $invoice->items->firstWhere('name', 'Pozycja do usunięcia');
        $this->assertNotNull($this->treatmentFor($invoice, $deletedItem->getKey()));

        $invoice = app(InvoiceEditService::class)->deleteItem(
            $invoice,
            $deletedItem,
            $invoice->lock_version,
        );

        $this->assertNull(collect($invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'])
            ->firstWhere('invoice_item_id', $deletedItem->getKey()));
    }

    public function test_buyer_edit_rebuilds_semantics_while_true_no_op_preserves_lock_version(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(['billing_tax_id' => '5260250995']);
        $before = $invoice->buyer_snapshot;
        $lockVersion = $invoice->lock_version;

        $invoice = app(InvoiceEditService::class)->updateBuyer($invoice, $this->buyerPayload($invoice));
        $this->assertSame($lockVersion, $invoice->lock_version);
        $this->assertSame($before, $invoice->buyer_snapshot);

        $invoice = app(InvoiceEditService::class)->updateBuyer($invoice, $this->buyerPayload($invoice, [
            'country_code' => 'DE',
            'tax_id' => 'DE123456789',
        ]));
        $this->assertSame($lockVersion + 1, $invoice->lock_version);
        $this->assertSame('eu_vat', $invoice->buyer_snapshot['tax_identity']['type']);
        $this->assertSame(['version' => 1, 'jst' => false, 'vat_group' => false], $invoice->buyer_snapshot['subject_flags']);
    }

    public function test_active_enabled_ksef_gate_finalizes_eligible_invoice_and_rejects_unsupported_tax(): void
    {
        Http::preventStrayRequests();
        $eligible = $this->issueInvoice(['billing_tax_id' => '5260250995']);
        $this->enableKsefFor($eligible);
        $eligible = app(InvoiceFinalizationService::class)->finalize($eligible);
        $this->assertNotNull($eligible->finalized_at);
        $this->eligibility($eligible, KsefFa3EligibilityMode::Authoritative);

        $unsupported = $this->issueInvoice(
            ['billing_tax_id' => '5260250995'],
            ['vat_rate' => '6.00'],
            ['include_shipping' => false],
        );
        $this->enableKsefFor($unsupported);
        $this->expectDomainError(
            'ksef_fa3_unsupported_vat_rate',
            fn () => app(InvoiceFinalizationService::class)->finalize($unsupported),
        );
        $this->assertNull($unsupported->fresh()->finalized_at);
    }

    public function test_active_enabled_ksef_never_blocks_ordinary_issue_of_an_ineligible_invoice(): void
    {
        Http::preventStrayRequests();
        $order = $this->createDocumentOrder([
            'external_id' => 'FA3-ACTIVE-ISSUE',
            'billing_tax_id' => '5260250995',
        ]);
        $this->createDocumentItem($order, ['vat_rate' => '6.00']);
        $series = $this->createDocumentSeries(InvoiceDocumentType::Invoice, ['include_shipping' => false]);
        $this->settings()->forceFill(['is_active' => true])->save();
        KsefSeriesSetting::query()->create([
            'invoice_series_id' => $series->getKey(),
            'is_enabled' => true,
        ]);

        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $series,
            $this->documentContext(),
        );

        $this->assertTrue($invoice->isIssued());
        $this->assertSame('unsupported', $invoice->tax_metadata_snapshot['ksef_tax']['line_treatments'][0]['status']);
        $this->expectDomainError(
            'ksef_fa3_unsupported_vat_rate',
            fn () => app(InvoiceFinalizationService::class)->finalize($invoice),
        );
    }

    public function test_inactive_or_series_disabled_ksef_does_not_gate_invoice_finalization(): void
    {
        Http::preventStrayRequests();
        $inactive = $this->issueInvoice(item: ['vat_rate' => '6.00'], series: ['include_shipping' => false]);
        $this->enableKsefFor($inactive, active: false);
        $this->assertNotNull(app(InvoiceFinalizationService::class)->finalize($inactive)->finalized_at);

        $seriesDisabled = $this->issueInvoice(item: ['vat_rate' => '6.00'], series: ['include_shipping' => false]);
        $this->enableKsefFor($seriesDisabled, seriesEnabled: false);
        $this->assertNotNull(app(InvoiceFinalizationService::class)->finalize($seriesDisabled)->finalized_at);
    }

    public function test_buyer_without_tax_id_obeys_policy_and_incomplete_seller_is_rejected(): void
    {
        Http::preventStrayRequests();
        $withoutTaxId = $this->issueInvoice(['billing_tax_id' => null]);
        $this->assertSame('none', $withoutTaxId->buyer_snapshot['tax_identity']['type']);
        $this->enableKsefFor($withoutTaxId, sendWithoutBuyerNip: false);
        $this->expectDomainError(
            'ksef_fa3_buyer_tax_id_required',
            fn () => app(InvoiceFinalizationService::class)->finalize($withoutTaxId),
        );
        $this->settings()->forceFill(['send_without_buyer_nip' => true])->save();
        $this->assertNotNull(app(InvoiceFinalizationService::class)->finalize($withoutTaxId)->finalized_at);

        foreach ([
            ['seller_tax_id' => null],
            ['seller_name' => null],
            [
                'seller_street' => null,
                'seller_building_number' => null,
                'seller_postal_code' => null,
                'seller_city' => null,
            ],
        ] as $sellerOverride) {
            $incompleteSeller = $this->issueInvoice(
                ['billing_tax_id' => '5260250995'],
                series: $sellerOverride,
            );
            $this->settings()->forceFill(['context_nip' => '9876543210'])->save();
            $this->enableKsefFor($incompleteSeller);
            $this->expectDomainError(
                'ksef_fa3_seller_incomplete',
                fn () => app(InvoiceFinalizationService::class)->finalize($incompleteSeller),
            );
            $this->assertNull($incompleteSeller->fresh()->finalized_at);
        }
    }

    public function test_historical_missing_or_unknown_semantic_snapshot_is_not_backfilled(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(['billing_tax_id' => '5260250995']);
        $metadata = $invoice->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $invoice->forceFill(['tax_metadata_snapshot' => $metadata, 'finalized_at' => now()])->saveQuietly();
        $before = $invoice->fresh()->getAttributes();

        $this->expectDomainError(
            'ksef_fa3_tax_snapshot_missing',
            fn () => $this->eligibility($invoice->fresh(), KsefFa3EligibilityMode::Authoritative),
        );
        $this->assertSame($before, $invoice->fresh()->getAttributes());

        $invoice->forceFill([
            'tax_metadata_snapshot' => ['ksef_tax' => ['version' => 99]],
            'finalized_at' => null,
        ])->saveQuietly();
        $this->expectDomainError(
            'ksef_fa3_snapshot_version_unsupported',
            fn () => $this->eligibility($invoice->fresh(), KsefFa3EligibilityMode::Preflight),
        );
    }

    public function test_editing_legacy_invoice_does_not_backfill_missing_semantics_from_current_settings(): void
    {
        Http::preventStrayRequests();
        $invoice = $this->issueInvoice(['billing_tax_id' => '5260250995']);
        $invoice->forceFill([
            'buyer_snapshot' => $this->withoutBuyerSemantics($invoice->buyer_snapshot),
        ])->saveQuietly();
        $item = $invoice->items->first();

        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $item,
            $this->itemPayload($invoice, $item, ['name' => 'Legacy edited']),
        );
        $this->assertArrayNotHasKey('tax_identity', $invoice->buyer_snapshot);
        $this->assertArrayNotHasKey('subject_flags', $invoice->buyer_snapshot);

        $invoice->forceFill(['tax_metadata_snapshot' => []])->saveQuietly();
        $invoice = app(InvoiceEditService::class)->updateItem(
            $invoice,
            $item,
            $this->itemPayload($invoice, $item, ['name' => 'Legacy edited again']),
        );
        $this->assertArrayNotHasKey('ksef_tax', $invoice->tax_metadata_snapshot);

        $invoice = app(InvoiceEditService::class)->updateBuyer(
            $invoice,
            $this->buyerPayload($invoice, ['city' => 'Kraków']),
        );
        $this->assertArrayNotHasKey('tax_identity', $invoice->buyer_snapshot);
        $this->assertArrayNotHasKey('subject_flags', $invoice->buyer_snapshot);
    }

    private function issueInvoice(array $order = [], array $item = [], array $series = []): Invoice
    {
        $orderModel = $this->createDocumentOrder(array_merge([
            'external_id' => 'FA3-'.uniqid(),
        ], $order));
        $this->createDocumentItem($orderModel, $item);

        return app(InvoiceIssuingService::class)->issue(
            $orderModel,
            $this->createDocumentSeries(InvoiceDocumentType::Invoice, $series),
            $this->documentContext(),
        )->refresh()->load('items');
    }

    private function settings(): KsefSetting
    {
        return app(KsefSettingsService::class)->get()->refresh();
    }

    private function enableKsefFor(
        Invoice $invoice,
        bool $active = true,
        bool $seriesEnabled = true,
        bool $sendWithoutBuyerNip = false,
    ): void {
        $this->settings()->forceFill([
            'is_active' => $active,
            'send_without_buyer_nip' => $sendWithoutBuyerNip,
        ])->save();
        KsefSeriesSetting::query()->updateOrCreate(
            ['invoice_series_id' => $invoice->invoice_series_id],
            ['is_enabled' => $seriesEnabled],
        );
    }

    private function eligibility(Invoice $invoice, KsefFa3EligibilityMode $mode): void
    {
        app(KsefFa3EligibilityValidator::class)->assertEligible($invoice, $this->settings(), $mode);
    }

    /** @return array<string, mixed> */
    private function treatmentFor(Invoice $invoice, int $itemId): array
    {
        return collect($invoice->fresh()->tax_metadata_snapshot['ksef_tax']['line_treatments'])
            ->firstWhere('invoice_item_id', $itemId);
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

    /** @return array<string, mixed> */
    private function buyerPayload(Invoice $invoice, array $overrides = []): array
    {
        $invoice = $invoice->fresh();
        $buyer = $invoice->buyer_snapshot;

        return array_replace([
            'expected_lock_version' => $invoice->lock_version,
            'name' => $buyer['name'] ?? null,
            'company_name' => $buyer['company_name'] ?? null,
            'tax_id' => $buyer['tax_id'] ?? null,
            'street' => $buyer['street'] ?? null,
            'building_number' => $buyer['building_number'] ?? null,
            'apartment_number' => $buyer['apartment_number'] ?? null,
            'postal_code' => $buyer['postal_code'] ?? null,
            'city' => $buyer['city'] ?? null,
            'province' => $buyer['province'] ?? null,
            'country_code' => $buyer['country_code'] ?? null,
            'email' => $buyer['email'] ?? null,
            'phone' => $buyer['phone'] ?? null,
        ], $overrides);
    }

    private function expectDomainError(string $code, callable $operation): InvoiceDomainException
    {
        try {
            $operation();
            $this->fail('Oczekiwano kontrolowanego błędu domenowego '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());

            return $exception;
        }
    }

    /** @param array<string, mixed> $buyer
     * @return array<string, mixed>
     */
    private function withoutBuyerSemantics(array $buyer): array
    {
        unset($buyer['tax_identity'], $buyer['subject_flags']);

        return $buyer;
    }
}
