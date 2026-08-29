<?php

namespace Tests\Feature\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Enums\KsefZeroVatClassification;
use Modules\Ksef\Services\KsefFa3CorrectionSemanticSnapshotService;
use Modules\Ksef\Services\KsefSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3CorrectionSemanticSnapshotServiceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_first_correction_materializes_semantics_for_every_item(): void
    {
        $root = $this->issueRoot(array_fill(0, 5, ['vat_rate' => '23.00']));
        $items = $this->submittedItems($root);
        $items[2]['quantity'] = 2;

        $correction = $this->issueCorrection($root, $items);
        $snapshot = $this->snapshot($correction);

        $this->assertSame(1, $snapshot['version']);
        $this->assertSame('correction', $snapshot['profile']);
        $this->assertSame([
            'invoice_id' => $root->getKey(),
            'document_type' => 'invoice',
        ], $snapshot['source_document']);
        $this->assertCount(5, $snapshot['line_treatments']);
        $this->assertCount(5, $correction->items);
        $this->assertNull($snapshot['buyer_before_semantics']);

        foreach ($snapshot['line_treatments'] as $index => $treatment) {
            $item = $correction->items[$index];
            $this->assertSame($item->getKey(), $treatment['invoice_item_id']);
            $this->assertSame($item->source_invoice_item_id, $treatment['source_invoice_item_id']);
            $this->assertSame($item->position, $treatment['position']);
            $this->assertSame('rate:23.00', $treatment['before']['tax_identity']);
            $this->assertSame('resolved', $treatment['before']['status']);
            $this->assertSame('standard', $treatment['before']['treatment']);
            $this->assertSame('23', $treatment['before']['fa3_rate']);
            $this->assertSame($treatment['before'], $treatment['after']);
        }
    }

    public function test_buyer_before_and_after_semantics_are_materialized_from_raw_business_data(): void
    {
        $root = $this->issueRoot(
            [['vat_rate' => '23.00']],
            ['billing_tax_id' => '5260250995'],
        );
        $rawBefore = $root->buyer_snapshot;
        $after = array_merge($rawBefore, [
            'tax_id' => '5210080410',
            'name' => 'Nabywca po Korekcie',
            'company_name' => null,
        ]);

        $correction = $this->issueCorrection($root, buyer: $after);
        $snapshot = $this->snapshot($correction);

        $this->assertSame('5260250995', $snapshot['buyer_before_semantics']['tax_identity']['identifier']);
        $this->assertSame('5210080410', $correction->buyer_snapshot['tax_identity']['identifier']);
        $this->assertSame([
            'version' => 1,
            'jst' => false,
            'vat_group' => false,
        ], $correction->buyer_snapshot['subject_flags']);
        $this->assertSame($rawBefore, data_get($correction->order_snapshot, 'correction.buyer_before'));

        $orderSnapshot = $correction->order_snapshot;
        data_set($orderSnapshot, 'correction.buyer_before.tax_identity.identifier', 'STALE-TECHNICAL-VALUE');
        $correction->forceFill(['order_snapshot' => $orderSnapshot])->save();
        app(KsefFa3CorrectionSemanticSnapshotService::class)->refresh($correction);

        $this->assertNotNull($this->snapshot($correction)['buyer_before_semantics']);
        $this->assertSame('5210080410', $correction->fresh()->buyer_snapshot['tax_identity']['identifier']);
    }

    public function test_unchanged_buyer_ignores_technical_semantic_fields(): void
    {
        $root = $this->issueRoot();
        $items = $this->submittedItems($root);
        $items[0]['quantity'] = 2;
        $correction = $this->issueCorrection($root, $items);
        $orderSnapshot = $correction->order_snapshot;
        data_set($orderSnapshot, 'correction.buyer_before.tax_identity.identifier', 'STALE');
        data_set($orderSnapshot, 'correction.buyer_before.subject_flags.jst', true);
        $correction->forceFill(['order_snapshot' => $orderSnapshot])->save();

        app(KsefFa3CorrectionSemanticSnapshotService::class)->refresh($correction);

        $this->assertNull($this->snapshot($correction)['buyer_before_semantics']);
    }

    #[DataProvider('historicalZeroVatCases')]
    public function test_historical_zero_treatment_survives_global_setting_changes(
        KsefZeroVatClassification $historical,
        KsefZeroVatClassification $current,
        string $expectedTreatment,
        string $expectedRate,
    ): void {
        $this->setZeroClassification($historical);
        $root = $this->issueRoot([['vat_rate' => '0.00']]);
        $this->setZeroClassification($current);
        $items = $this->submittedItems($root);
        $items[0]['quantity'] = 0;

        $correction = $this->issueCorrection($root, $items);
        $treatment = $this->snapshot($correction)['line_treatments'][0];

        $this->assertSame($expectedTreatment, $treatment['before']['treatment']);
        $this->assertSame($expectedRate, $treatment['before']['fa3_rate']);
        $this->assertSame($treatment['before'], $treatment['after']);
    }

    public static function historicalZeroVatCases(): array
    {
        return [
            'WDT to Export' => [
                KsefZeroVatClassification::Wdt,
                KsefZeroVatClassification::Export,
                'wdt',
                '0 WDT',
            ],
            'Export to domestic' => [
                KsefZeroVatClassification::Export,
                KsefZeroVatClassification::Domestic,
                'export',
                '0 EX',
            ],
            'Domestic to WDT' => [
                KsefZeroVatClassification::Domestic,
                KsefZeroVatClassification::Wdt,
                'domestic_zero',
                '0 KR',
            ],
        ];
    }

    public function test_changed_tax_identity_and_added_zero_line_use_current_semantics(): void
    {
        $this->setZeroClassification(KsefZeroVatClassification::Domestic);
        $root = $this->issueRoot();
        $items = $this->submittedItems($root);
        $items[0]['vat_rate'] = '8';
        $items[] = [
            'source_item_id' => null,
            'order_item_id' => null,
            'line_type' => 'custom',
            'position' => 2,
            'name' => 'Nowa pozycja 0%',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => 1,
            'unit_price_gross' => '10.00',
            'vat_rate' => '0',
            'vat_code' => null,
        ];

        $correction = $this->issueCorrection($root, $items);
        $treatments = collect($this->snapshot($correction)['line_treatments']);
        $changed = $treatments->firstWhere('source_invoice_item_id', $root->items->first()->getKey());
        $added = $treatments->firstWhere('source_invoice_item_id', null);

        $this->assertSame('23', $changed['before']['fa3_rate']);
        $this->assertSame('8', $changed['after']['fa3_rate']);
        $this->assertSame('domestic_zero', $added['before']['treatment']);
        $this->assertSame('0 KR', $added['before']['fa3_rate']);
        $this->assertSame($added['before'], $added['after']);
    }

    public function test_change_from_standard_rate_to_zero_uses_current_zero_classification(): void
    {
        $root = $this->issueRoot();
        $this->setZeroClassification(KsefZeroVatClassification::Export);
        $items = $this->submittedItems($root);
        $items[0]['vat_rate'] = '0';

        $treatment = $this->snapshot($this->issueCorrection($root, $items))['line_treatments'][0];

        $this->assertSame('23', $treatment['before']['fa3_rate']);
        $this->assertSame('export', $treatment['after']['treatment']);
        $this->assertSame('0 EX', $treatment['after']['fa3_rate']);
    }

    public function test_missing_inconsistent_and_unsupported_source_semantics_are_preserved_as_states(): void
    {
        $missingRoot = $this->issueRoot([['vat_rate' => '0.00']]);
        $metadata = $missingRoot->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $missingRoot->forceFill(['tax_metadata_snapshot' => $metadata])->save();
        $missingItems = $this->submittedItems($missingRoot);
        $missingItems[0]['quantity'] = 2;
        $missing = $this->snapshot($this->issueCorrection($missingRoot, $missingItems))['line_treatments'][0];
        $this->assertSame('unresolved', $missing['before']['status']);
        $this->assertSame('source_semantics_missing', $missing['before']['reason']);
        $this->assertSame($missing['before'], $missing['after']);

        $inconsistentRoot = $this->issueRoot();
        $metadata = $inconsistentRoot->tax_metadata_snapshot;
        $metadata['ksef_tax']['line_treatments'][0]['tax_identity'] = 'rate:8.00';
        $inconsistentRoot->forceFill(['tax_metadata_snapshot' => $metadata])->save();
        $inconsistentItems = $this->submittedItems($inconsistentRoot);
        $inconsistentItems[0]['quantity'] = 2;
        $inconsistent = $this->snapshot($this->issueCorrection($inconsistentRoot, $inconsistentItems))['line_treatments'][0];
        $this->assertSame('unresolved', $inconsistent['before']['status']);
        $this->assertSame('source_semantics_inconsistent', $inconsistent['before']['reason']);
        $this->assertSame($inconsistent['before'], $inconsistent['after']);

        $unsupportedRoot = $this->issueRoot([['vat_rate' => '6.00']]);
        $unsupportedItems = $this->submittedItems($unsupportedRoot);
        $unsupportedItems[0]['quantity'] = 2;
        $unsupported = $this->snapshot($this->issueCorrection($unsupportedRoot, $unsupportedItems))['line_treatments'][0];
        $this->assertSame('unsupported', $unsupported['before']['status']);
        $this->assertSame('unsupported_percentage', $unsupported['before']['reason']);
        $this->assertSame($unsupported['before'], $unsupported['after']);
    }

    public function test_second_and_third_corrections_inherit_the_immediate_previous_after_semantics(): void
    {
        $this->setZeroClassification(KsefZeroVatClassification::Wdt);
        $root = $this->issueRoot([['vat_rate' => '0.00']]);
        $firstItems = $this->submittedItems($root);
        $firstItems[0]['quantity'] = 2;
        $firstItems[] = [
            'source_item_id' => null,
            'order_item_id' => null,
            'line_type' => 'custom',
            'position' => 2,
            'name' => 'Pozycja dodana w C1',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => 1,
            'unit_price_gross' => '20.00',
            'vat_rate' => '0',
            'vat_code' => null,
        ];
        $first = $this->issueCorrection($root, $firstItems);
        $first = app(InvoiceFinalizationService::class)->finalize($first);

        $this->setZeroClassification(KsefZeroVatClassification::Export);
        $secondItems = $this->submittedItems($root);
        $secondItems[1]['quantity'] = 2;
        $second = $this->issueCorrection($root, $secondItems);
        $secondSnapshot = $this->snapshot($second);
        $firstAddedItem = $first->fresh('items')->items->firstWhere('name', 'Pozycja dodana w C1');
        $secondAddedTreatment = collect($secondSnapshot['line_treatments'])
            ->firstWhere('source_invoice_item_id', $firstAddedItem->getKey());

        $this->assertSame($first->getKey(), $secondSnapshot['source_document']['invoice_id']);
        $this->assertSame('correction', $secondSnapshot['source_document']['document_type']);
        $this->assertSame('wdt', $secondAddedTreatment['before']['treatment']);
        $this->assertSame('0 WDT', $secondAddedTreatment['after']['fa3_rate']);

        $second = app(InvoiceFinalizationService::class)->finalize($second);
        $this->setZeroClassification(KsefZeroVatClassification::Domestic);
        $thirdItems = $this->submittedItems($root);
        $thirdItems[0]['quantity'] = 3;
        $third = $this->issueCorrection($root, $thirdItems);
        $thirdSnapshot = $this->snapshot($third);

        $this->assertSame($second->getKey(), $thirdSnapshot['source_document']['invoice_id']);
        $this->assertSame('wdt', $thirdSnapshot['line_treatments'][0]['before']['treatment']);
        $this->assertSame('0 WDT', $thirdSnapshot['line_treatments'][0]['after']['fa3_rate']);
    }

    public function test_next_correction_propagates_unresolved_and_unsupported_source_states(): void
    {
        $missingRoot = $this->issueRoot([['vat_rate' => '0.00']]);
        $metadata = $missingRoot->tax_metadata_snapshot;
        unset($metadata['ksef_tax']);
        $missingRoot->forceFill(['tax_metadata_snapshot' => $metadata])->save();
        $firstItems = $this->submittedItems($missingRoot);
        $firstItems[0]['quantity'] = 2;
        $first = app(InvoiceFinalizationService::class)->finalize(
            $this->issueCorrection($missingRoot, $firstItems),
        );
        $secondItems = $this->submittedItems($missingRoot);
        $secondItems[0]['quantity'] = 3;
        $second = $this->snapshot($this->issueCorrection($missingRoot, $secondItems))['line_treatments'][0];
        $this->assertSame('unresolved', $second['before']['status']);
        $this->assertSame('source_semantics_missing', $second['before']['reason']);
        $this->assertSame($second['before'], $second['after']);

        $unsupportedRoot = $this->issueRoot([['vat_rate' => '6.00']]);
        $firstItems = $this->submittedItems($unsupportedRoot);
        $firstItems[0]['quantity'] = 2;
        app(InvoiceFinalizationService::class)->finalize(
            $this->issueCorrection($unsupportedRoot, $firstItems),
        );
        $secondItems = $this->submittedItems($unsupportedRoot);
        $secondItems[0]['quantity'] = 3;
        $second = $this->snapshot($this->issueCorrection($unsupportedRoot, $secondItems))['line_treatments'][0];
        $this->assertSame('unsupported', $second['before']['status']);
        $this->assertSame('unsupported_percentage', $second['before']['reason']);
        $this->assertSame($second['before'], $second['after']);
        $this->assertNotNull($first->finalized_at);
    }

    public function test_update_refreshes_buyer_and_line_semantics_without_stale_item_ids(): void
    {
        $root = $this->issueRoot();
        $items = $this->submittedItems($root);
        $items[0]['unit_price_gross'] = '90.00';
        $correction = $this->issueCorrection($root, $items);
        $oldIds = $correction->items->modelKeys();
        $submitted = $this->submittedCorrectionItems($correction);
        $submitted[0]['vat_rate'] = '8';
        $buyer = array_merge($correction->buyer_snapshot, [
            'tax_id' => '5210080410',
            'name' => 'Nabywca po aktualizacji',
            'company_name' => null,
        ]);

        $updated = app(CorrectionService::class)->update($correction, $this->payload([
            'expected_lock_version' => $correction->lock_version,
            'change_items' => true,
            'items' => $submitted,
            'change_buyer' => true,
            'buyer' => $buyer,
        ]));
        $snapshot = $this->snapshot($updated);
        $newIds = $updated->items->modelKeys();

        $this->assertEmpty(array_intersect($oldIds, $newIds));
        $this->assertSame($newIds, array_column($snapshot['line_treatments'], 'invoice_item_id'));
        $this->assertCount($updated->items->count(), $snapshot['line_treatments']);
        $this->assertSame('8', $snapshot['line_treatments'][0]['after']['fa3_rate']);
        $this->assertSame('5210080410', $updated->buyer_snapshot['tax_identity']['identifier']);
        $this->assertSame('5260250995', $snapshot['buyer_before_semantics']['tax_identity']['identifier']);
    }

    public function test_refresh_preserves_currency_metadata_and_rejects_structural_source_mismatch(): void
    {
        $root = $this->issueRoot();
        $items = $this->submittedItems($root);
        $items[0]['quantity'] = 2;
        $correction = $this->issueCorrection($root, $items);
        $metadata = $correction->tax_metadata_snapshot;
        $currencyConversion = ['rate' => '4.1234', 'table' => 'A', 'number' => '1/A/NBP/2026'];
        $convertedSummary = [['vat_rate' => '23.00', 'net' => '100.00']];
        $metadata['currency_conversion'] = $currencyConversion;
        $metadata['converted_tax_summary'] = $convertedSummary;
        $correction->forceFill(['tax_metadata_snapshot' => $metadata])->save();

        app(KsefFa3CorrectionSemanticSnapshotService::class)->refresh($correction);
        $refreshedMetadata = $correction->fresh()->tax_metadata_snapshot;
        $this->assertSame($currencyConversion, $refreshedMetadata['currency_conversion']);
        $this->assertSame($convertedSummary, $refreshedMetadata['converted_tax_summary']);
        $this->assertArrayHasKey('ksef_correction', $refreshedMetadata);

        $rootMetadata = $root->tax_metadata_snapshot;
        $rootMetadata['ksef_tax']['line_treatments'][] = $rootMetadata['ksef_tax']['line_treatments'][0];
        $root->forceFill(['tax_metadata_snapshot' => $rootMetadata])->save();
        try {
            app(KsefFa3CorrectionSemanticSnapshotService::class)->refresh($correction->fresh());
            $this->fail('Zduplikowana semantyka pozycji źródłowej powinna zostać odrzucona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('ksef_fa3_correction_semantic_source_invalid', $exception->errorCode());
        }
        array_pop($rootMetadata['ksef_tax']['line_treatments']);
        $root->forceFill(['tax_metadata_snapshot' => $rootMetadata])->save();

        $foreignRoot = $this->issueRoot();
        $correctionItem = $correction->items()->firstOrFail();
        $correctionItem->update([
            'source_invoice_item_id' => $foreignRoot->items()->firstOrFail()->getKey(),
        ]);

        try {
            app(KsefFa3CorrectionSemanticSnapshotService::class)->refresh($correction->fresh());
            $this->fail('Pozycja spoza skutecznego dokumentu źródłowego powinna zostać odrzucona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('ksef_fa3_correction_semantic_source_invalid', $exception->errorCode());
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function issueRoot(
        array $items = [['vat_rate' => '23.00']],
        array $orderAttributes = [],
    ): Invoice {
        $count = count($items);
        $order = $this->createDocumentOrder(array_merge([
            'external_id' => 'KSEF-CORRECTION-'.uniqid(),
            'billing_tax_id' => '5260250995',
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
            'total_gross' => ($count * 100).'.00',
        ], $orderAttributes));

        foreach ($items as $index => $item) {
            $this->createDocumentItem($order, array_merge([
                'product_name' => 'Pozycja '.($index + 1),
                'unit_price_gross' => '100.00',
                'total_price_gross' => '100.00',
                'vat_rate' => '23.00',
            ], $item));
        }

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext(),
        );
    }

    /** @param array<int, array<string, mixed>>|null $items
     * @param  array<string, mixed>|null  $buyer
     */
    private function issueCorrection(Invoice $root, ?array $items = null, ?array $buyer = null): Invoice
    {
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
            $this->documentContext('2026-08-05 10:00:00'),
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

    /** @return array<int, array<string, mixed>> */
    private function submittedCorrectionItems(Invoice $correction): array
    {
        return $correction->items->map(fn ($item): array => $this->submittedItem(
            $item->correction_after_snapshot,
            $item->getKey(),
            $item->order_item_id,
        ))->values()->all();
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
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester Korekty',
            'additional_information' => 'Test semantyki Korekty',
            'change_items' => false,
            'change_buyer' => false,
            'items' => [],
            'buyer' => [],
        ], $overrides);
    }

    private function correctionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    private function twoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function setZeroClassification(KsefZeroVatClassification $classification): void
    {
        app(KsefSettingsService::class)->get()
            ->forceFill(['zero_vat_classification' => $classification])
            ->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(Invoice $correction): array
    {
        return $correction->fresh('items')->tax_metadata_snapshot['ksef_correction'];
    }
}
