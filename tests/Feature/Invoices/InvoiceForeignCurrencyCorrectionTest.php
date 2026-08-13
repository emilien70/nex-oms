<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceCurrencyConversionService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfRenderer;
use Modules\Invoices\Services\InvoicePdfViewModelFactory;
use Modules\Invoices\ValueObjects\NbpExchangeRate;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceForeignCurrencyCorrectionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_zero_rate_eur_correction_uses_source_historical_rate_without_nbp_request(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '0.00']]);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';

        $correction = $this->issueItemCorrection($source, $items, '2026-08-10');

        $this->assertSame('EUR', $correction->currency);
        $this->assertSame('-25.00', $correction->total_net);
        $this->assertSame('0.00', $correction->total_vat);
        $this->assertSame('-25.00', $correction->total_gross);
        $this->assertSame($correction->correction_totals_snapshot['difference']['tax_summary_snapshot'], $correction->tax_summary_snapshot);
        $this->assertSame('-25.00', $correction->tax_summary_snapshot[0]['net']);
        $this->assertSame('0.00', $correction->tax_summary_snapshot[0]['vat']);
        $this->assertSame('-25.00', $correction->tax_summary_snapshot[0]['gross']);

        $conversion = $correction->tax_metadata_snapshot['currency_conversion'];
        $converted = $correction->tax_metadata_snapshot['converted_tax_summary'];
        $this->assertSame('4.342000', $conversion['rate']);
        $this->assertSame('2026-07-17', $conversion['effective_date']);
        $this->assertSame('137/A/NBP/2026', $conversion['table_number']);
        $this->assertSame('-108.55', $converted['total_net']);
        $this->assertSame('0.00', $converted['total_vat']);
        $this->assertSame('-108.55', $converted['total_gross']);
        Http::assertNothingSent();
    }

    public function test_zw_eur_reduction_uses_historical_rate_and_canonical_code_without_http(): void
    {
        Http::preventStrayRequests();
        $source = $this->sourceInvoice([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $source = $this->updateSourceTaxIdentity($source, null, 'zw');
        $source = $this->makeForeign($source);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';
        $items[0]['vat_rate'] = '23.00';
        $items[0]['vat_code'] = ' zw ';

        $correction = $this->issueItemCorrection($source, $items, '2026-08-10');
        $converted = $correction->tax_metadata_snapshot['converted_tax_summary'];

        $this->assertSame('-25.00', $correction->total_net);
        $this->assertSame('0.00', $correction->total_vat);
        $this->assertSame('-25.00', $correction->total_gross);
        $this->assertSame('ZW', $correction->tax_summary_snapshot[0]['vat_code']);
        $this->assertNull($correction->tax_summary_snapshot[0]['vat_rate']);
        $this->assertSame('-108.55', $converted['total_net']);
        $this->assertSame('0.00', $converted['total_vat']);
        $this->assertSame('-108.55', $converted['total_gross']);
        $this->assertSame('ZW', $converted['groups'][0]['vat_code']);
        $this->assertNull($converted['groups'][0]['vat_rate']);
        $this->assertSame('4.342000', $correction->tax_metadata_snapshot['currency_conversion']['rate']);
        Http::assertNothingSent();
    }

    public function test_rate_to_code_eur_correction_preserves_both_source_and_pln_groups(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '123.00', 'vat_rate' => '23.00']]);
        $items = $this->submittedItems($source);
        $items[0]['vat_code'] = 'zw';
        $items[0]['vat_rate'] = '23.00';

        $correction = $this->issueItemCorrection($source, $items);
        $converted = $correction->tax_metadata_snapshot['converted_tax_summary'];
        $html = app(InvoicePdfRenderer::class)->html($correction->fresh('items'));

        $this->assertSame(['ZW', null], array_column($correction->tax_summary_snapshot, 'vat_code'));
        $this->assertSame([null, '23.00'], array_column($correction->tax_summary_snapshot, 'vat_rate'));
        $this->assertSame(['ZW', null], array_column($converted['groups'], 'vat_code'));
        $this->assertSame([null, '23.00'], array_column($converted['groups'], 'vat_rate'));
        $this->assertSame('99.87', $converted['total_net']);
        $this->assertSame('-99.87', $converted['total_vat']);
        $this->assertSame('0.00', $converted['total_gross']);
        $this->assertStringContainsString('23%', $html);
        $this->assertStringContainsString('ZW', $html);
        $this->assertStringNotContainsString('ZW%', $html);
        $this->assertStringContainsString('137/A/NBP/2026', $html);
        Http::assertNothingSent();
    }

    public function test_code_to_code_eur_correction_converts_nonzero_groups_despite_zero_aggregate(): void
    {
        Http::preventStrayRequests();
        $source = $this->sourceInvoice([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $source = $this->updateSourceTaxIdentity($source, null, 'ZW');
        $source = $this->makeForeign($source);
        $items = $this->submittedItems($source);
        $items[0]['vat_code'] = 'np';
        $items[0]['vat_rate'] = '8.00';

        $correction = $this->issueItemCorrection($source, $items);
        $converted = $correction->tax_metadata_snapshot['converted_tax_summary'];
        $html = app(InvoicePdfRenderer::class)->html($correction->fresh('items'));

        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame(['NP', 'ZW'], array_column($correction->tax_summary_snapshot, 'vat_code'));
        $this->assertCount(2, $converted['groups']);
        $this->assertSame(['NP', 'ZW'], array_column($converted['groups'], 'vat_code'));
        $this->assertSame(['434.20', '-434.20'], array_column($converted['groups'], 'gross'));
        $this->assertSame('0.00', $converted['total_net']);
        $this->assertSame('0.00', $converted['total_vat']);
        $this->assertSame('0.00', $converted['total_gross']);
        $this->assertStringContainsString('ZW', $html);
        $this->assertStringContainsString('NP', $html);
        $this->assertStringNotContainsString('ZW%', $html);
        $this->assertStringNotContainsString('NP%', $html);
        Http::assertNothingSent();
    }

    public function test_vat_rate_change_keeps_two_difference_groups_and_component_signs(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $items = $this->submittedItems($source);
        $items[0]['vat_rate'] = '8.00';

        $correction = $this->issueItemCorrection($source, $items);
        $groups = $correction->tax_summary_snapshot;

        $this->assertSame('11.29', $correction->total_net);
        $this->assertSame('-11.29', $correction->total_vat);
        $this->assertSame('0.00', $correction->total_gross);
        $this->assertCount(2, $groups);
        $this->assertSame(['23.00', '8.00'], array_column($groups, 'vat_rate'));
        $this->assertSame(['-100.00', '100.00'], array_column($groups, 'gross'));
        $this->assertSame('0.00', $correction->tax_metadata_snapshot['converted_tax_summary']['total_gross']);
        Http::assertNothingSent();
    }

    public function test_multi_vat_correction_stores_before_after_and_difference_groups(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([
            ['gross' => '123.00', 'vat_rate' => '23.00'],
            ['gross' => '108.00', 'vat_rate' => '8.00'],
        ]);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '100.00';
        $items[1]['unit_price_gross'] = '54.00';

        $correction = $this->issueItemCorrection($source, $items);
        $snapshot = $correction->correction_totals_snapshot;

        $this->assertSame(['23.00', '8.00'], array_column($snapshot['before']['tax_summary_snapshot'], 'vat_rate'));
        $this->assertSame(['23.00', '8.00'], array_column($snapshot['after']['tax_summary_snapshot'], 'vat_rate'));
        $this->assertSame(['23.00', '8.00'], array_column($snapshot['difference']['tax_summary_snapshot'], 'vat_rate'));
        $this->assertSame(['-23.00', '-54.00'], array_column($snapshot['difference']['tax_summary_snapshot'], 'gross'));
        $this->assertSame($snapshot['difference']['tax_summary_snapshot'], $correction->tax_summary_snapshot);
        $this->assertSame('-77.00', $correction->total_gross);
        $this->assertCount(2, $correction->tax_metadata_snapshot['converted_tax_summary']['groups']);
        Http::assertNothingSent();
    }

    public function test_foreign_buyer_only_correction_does_not_require_currency_snapshot(): void
    {
        Http::preventStrayRequests();
        $source = $this->sourceInvoice([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $source->update(['currency' => 'EUR', 'tax_metadata_snapshot' => []]);

        $correction = app(CorrectionService::class)->issue(
            $source->fresh('items'),
            $this->correctionSeries(),
            $source->getKey(),
            $source->lock_version,
            $this->payload([
                'change_buyer' => true,
                'buyer' => array_merge($source->buyer_snapshot, ['name' => 'Anna Zmieniona']),
            ]),
            $this->documentContext('2026-08-10 10:00:00'),
        );

        $this->assertSame('0.00', $correction->total_net);
        $this->assertSame('0.00', $correction->total_vat);
        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame([], $correction->tax_summary_snapshot);
        $this->assertSame([], $correction->correction_totals_snapshot['difference']['tax_summary_snapshot']);
        $this->assertSame([], $correction->tax_metadata_snapshot);
        $document = app(InvoicePdfViewModelFactory::class)->make($correction->fresh('items'));
        $html = app(InvoicePdfRenderer::class)->html($correction->fresh('items'));
        $this->assertNotNull($document['buyer_change']);
        $this->assertNull($document['pln_conversion']);
        $this->assertStringContainsString('class="correction-buyer-change"', $html);
        $this->assertStringNotContainsString('Kurs waluty:', $html);
        Http::assertNothingSent();
    }

    public function test_stale_foreign_item_correction_is_rejected_without_nbp_request(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $staleVersion = $source->lock_version;
        $staleItems = $this->submittedItems($source);
        $staleItems[0]['unit_price_gross'] = '75.00';
        $sourceItem = $source->items()->where('line_type', 'product')->firstOrFail();

        app(InvoiceEditService::class)->updateItem($source, $sourceItem, [
            'expected_lock_version' => $staleVersion,
            'name' => $sourceItem->name,
            'description' => $sourceItem->description,
            'unit_name' => $sourceItem->unit_name,
            'quantity' => '1',
            'unit_price_gross' => '90.00',
            'vat_rate' => '23.00',
            'vat_code' => $sourceItem->vat_code,
            'position' => $sourceItem->position,
        ]);

        try {
            app(CorrectionService::class)->issue(
                $source,
                $this->correctionSeries(),
                $source->getKey(),
                $staleVersion,
                $this->payload([
                    'change_items' => true,
                    'items' => $staleItems,
                ]),
                $this->documentContext('2026-08-10 10:00:00'),
            );
            $this->fail('Walutowa Korekta ze starego formularza nie powinna zostać wystawiona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_source_changed', $exception->errorCode());
        }

        $this->assertDatabaseMissing('invoices', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
        Http::assertNothingSent();
    }

    public function test_missing_or_invalid_source_snapshot_rejects_monetary_correction_without_side_effects(): void
    {
        Http::preventStrayRequests();

        foreach (['missing', 'invalid'] as $case) {
            $source = $this->sourceInvoice([['gross' => '100.00', 'vat_rate' => '0.00']]);
            $source = $this->makeForeign($source);
            if ($case === 'missing') {
                $source->update(['tax_metadata_snapshot' => []]);
            } else {
                $metadata = $source->tax_metadata_snapshot;
                $metadata['currency_conversion']['rate'] = '0';
                $source->update(['tax_metadata_snapshot' => $metadata]);
            }

            $items = $this->submittedItems($source->fresh('items'));
            $items[0]['unit_price_gross'] = '75.00';

            try {
                $this->issueItemCorrection($source->fresh('items'), $items);
                $this->fail('Korekta walutowa bez poprawnego kursu powinna zostać odrzucona.');
            } catch (InvoiceDomainException $exception) {
                $this->assertSame(
                    $case === 'missing' ? 'correction_currency_snapshot_missing' : 'correction_currency_snapshot_invalid',
                    $exception->errorCode(),
                );
            }

            $this->assertDatabaseMissing('invoices', [
                'order_id' => $source->order_id,
                'document_type' => InvoiceDocumentType::Correction->value,
            ]);
            $this->assertDatabaseMissing('order_document_slots', [
                'order_id' => $source->order_id,
                'document_type' => InvoiceDocumentType::Correction->value,
            ]);
            $this->assertDatabaseMissing('order_events', [
                'order_id' => $source->order_id,
                'event_type' => 'correction_issued',
            ]);
        }

        Http::assertNothingSent();
    }

    public function test_foreign_correction_identical_update_is_a_no_op_with_historical_rate_and_cache(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '23.00']]);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';
        $correction = $this->issueItemCorrection($source, $items)->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');
        $lockVersion = $correction->lock_version;
        $metadata = $correction->tax_metadata_snapshot;
        $itemIds = $correction->items->modelKeys();

        $updated = app(CorrectionService::class)->update($correction, $this->payload([
            'expected_lock_version' => $lockVersion,
            'change_items' => true,
            'items' => $this->submittedCorrectionItems($correction),
            'issue_date' => $correction->issue_date?->toDateString(),
        ]));

        $this->assertSame($lockVersion, $updated->lock_version);
        $this->assertSame($metadata, $updated->tax_metadata_snapshot);
        $this->assertSame($itemIds, $updated->items->modelKeys());
        Storage::disk('local')->assertExists($cachePath);
        Http::assertNothingSent();
    }

    public function test_foreign_correction_update_recalculates_with_the_same_historical_rate(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '0.00']]);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';
        $correction = $this->issueItemCorrection($source, $items);
        $identity = [
            $correction->number,
            $correction->invoice_series_id,
            $correction->numbering_period_key,
        ];
        $legacyTotals = $correction->correction_totals_snapshot;
        foreach (['before', 'after', 'difference'] as $key) {
            unset($legacyTotals[$key]['tax_summary_snapshot']);
        }
        $correction->update([
            'correction_totals_snapshot' => $legacyTotals,
            'tax_summary_snapshot' => $source->tax_summary_snapshot,
            'tax_metadata_snapshot' => $source->tax_metadata_snapshot,
        ]);
        $correction = $correction->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-old');
        $updatedItems = $this->submittedCorrectionItems($correction);
        $updatedItems[0]['unit_price_gross'] = '50.00';

        $updated = app(CorrectionService::class)->update($correction, $this->payload([
            'expected_lock_version' => $correction->lock_version,
            'change_items' => true,
            'items' => $updatedItems,
            'issue_date' => '2026-08-12',
        ]));

        $this->assertSame($identity, [$updated->number, $updated->invoice_series_id, $updated->numbering_period_key]);
        $this->assertSame('-50.00', $updated->total_gross);
        $this->assertSame('-217.10', $updated->tax_metadata_snapshot['converted_tax_summary']['total_gross']);
        $this->assertSame('4.342000', $updated->tax_metadata_snapshot['currency_conversion']['rate']);
        $this->assertSame('2026-07-17', $updated->tax_metadata_snapshot['currency_conversion']['effective_date']);
        foreach (['before', 'after', 'difference'] as $key) {
            $this->assertIsArray($updated->correction_totals_snapshot[$key]['tax_summary_snapshot']);
        }
        $this->assertSame(
            $updated->correction_totals_snapshot['difference']['tax_summary_snapshot'],
            $updated->tax_summary_snapshot,
        );
        $this->assertSame($correction->lock_version + 1, $updated->lock_version);
        Storage::disk('local')->assertMissing($cachePath);
        Http::assertNothingSent();
    }

    public function test_pln_correction_uses_difference_tax_summary_without_currency_metadata(): void
    {
        $source = $this->sourceInvoice([['gross' => '123.00', 'vat_rate' => '23.00']]);
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '100.00';

        $correction = $this->issueItemCorrection($source, $items);

        $this->assertSame('-18.70', $correction->total_net);
        $this->assertSame('-4.30', $correction->total_vat);
        $this->assertSame('-23.00', $correction->total_gross);
        $this->assertSame($correction->correction_totals_snapshot['difference']['tax_summary_snapshot'], $correction->tax_summary_snapshot);
        $this->assertSame([], $correction->tax_metadata_snapshot);
    }

    public function test_second_foreign_correction_uses_effective_after_state_and_root_historical_rate(): void
    {
        Http::preventStrayRequests();
        $source = $this->foreignSource([['gross' => '100.00', 'vat_rate' => '0.00']]);
        $firstItems = $this->submittedItems($source);
        $firstItems[0]['unit_price_gross'] = '75.00';
        $first = $this->issueItemCorrection($source, $firstItems, '2026-08-05');
        app(InvoiceFinalizationService::class)->finalize($first);

        $secondItems = $this->submittedItems($source);
        $secondItems[0]['unit_price_gross'] = '50.00';
        $second = $this->issueItemCorrection($source, $secondItems, '2026-08-06');
        $product = $second->items->sole();

        $this->assertSame($first->getKey(), $second->previous_correction_id);
        $this->assertSame($source->getKey(), $second->corrected_invoice_id);
        $this->assertSame('75.0000', (string) data_get($product->correction_before_snapshot, 'unit_price_gross'));
        $this->assertSame('50.0000', (string) data_get($product->correction_after_snapshot, 'unit_price_gross'));
        $this->assertSame('-25.0000', (string) data_get($product->correction_difference_snapshot, 'unit_price_gross'));
        $this->assertSame('-25.00', $second->total_gross);
        $this->assertSame('-108.55', $second->tax_metadata_snapshot['converted_tax_summary']['total_gross']);
        $this->assertSame('4.342000', $second->tax_metadata_snapshot['currency_conversion']['rate']);
        $this->assertSame('137/A/NBP/2026', $second->tax_metadata_snapshot['currency_conversion']['table_number']);
        Http::assertNothingSent();
    }

    /** @param array<int, array{gross: string, vat_rate: string}> $items */
    private function sourceInvoice(array $items): Invoice
    {
        $order = $this->createDocumentOrder([
            'delivery_cost_gross' => '0.00',
            'paid_amount' => '0.00',
        ]);

        foreach ($items as $index => $item) {
            $this->createDocumentItem($order, [
                'product_name' => 'Produkt '.($index + 1),
                'unit_price_gross' => $item['gross'],
                'total_price_gross' => $item['gross'],
                'vat_rate' => $item['vat_rate'],
            ]);
        }

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext(),
        );
    }

    /** @param array<int, array{gross: string, vat_rate: string}> $items */
    private function foreignSource(array $items): Invoice
    {
        return $this->makeForeign($this->sourceInvoice($items));
    }

    private function makeForeign(Invoice $source): Invoice
    {
        $metadata = app(InvoiceCurrencyConversionService::class)->metadataForHistoricalRate(
            $source->tax_summary_snapshot,
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
        $source->update([
            'currency' => 'EUR',
            'tax_metadata_snapshot' => $metadata,
        ]);

        return $source->fresh('items');
    }

    private function updateSourceTaxIdentity(Invoice $source, ?string $vatRate, ?string $vatCode): Invoice
    {
        $item = $source->fresh('items')->items->sole();

        return app(InvoiceEditService::class)->updateItem($source, $item, [
            'expected_lock_version' => $source->lock_version,
            'name' => $item->name,
            'description' => $item->description,
            'unit_name' => $item->unit_name,
            'quantity' => (string) $item->quantity,
            'unit_price_gross' => (string) $item->unit_price_gross,
            'vat_rate' => $vatRate,
            'vat_code' => $vatCode,
            'position' => $item->position,
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function issueItemCorrection(Invoice $source, array $items, string $issueDate = '2026-08-05'): Invoice
    {
        $state = app(CorrectionSourceStateService::class)->chain($source);

        return app(CorrectionService::class)->issue(
            $source,
            $this->correctionSeries(),
            $state->effectiveSourceDocument->getKey(),
            $state->effectiveSourceDocument->lock_version,
            $this->payload([
                'issue_date' => $issueDate,
                'change_items' => true,
                'items' => $items,
            ]),
            $this->documentContext($issueDate.' 10:00:00'),
        );
    }

    private function correctionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedItems(Invoice $source): array
    {
        return app(CorrectionSourceStateService::class)
            ->effectiveItems($source)
            ->map(fn (array $item): array => $this->submittedItem($item['source_item']->getKey(), $item['snapshot']))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedCorrectionItems(Invoice $correction): array
    {
        return $correction->items
            ->map(fn ($item): array => $this->submittedItem($item->getKey(), $item->correction_after_snapshot))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function submittedItem(int $sourceItemId, array $snapshot): array
    {
        return [
            'source_item_id' => $sourceItemId,
            'order_item_id' => null,
            'line_type' => $snapshot['line_type'],
            'position' => $snapshot['position'],
            'name' => $snapshot['name'],
            'description' => $snapshot['description'],
            'unit_name' => $snapshot['unit_name'],
            'quantity' => (int) $snapshot['quantity'],
            'unit_price_gross' => $this->twoDecimals($snapshot['unit_price_gross']),
            'vat_rate' => $snapshot['vat_rate'] !== null
                ? rtrim(rtrim($this->twoDecimals($snapshot['vat_rate']), '0'), '.')
                : null,
            'vat_code' => $snapshot['vat_code'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'correction_series_id' => $this->correctionSeries()->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester korekty',
            'additional_information' => null,
            'change_items' => false,
            'change_buyer' => false,
            'items' => [],
            'buyer' => [],
        ], $overrides);
    }

    private function twoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
