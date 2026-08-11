<?php

namespace Tests\Feature\Invoices;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceCorrectionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_correction_form_uses_effective_invoice_state_and_polish_reasons(): void
    {
        $invoice = $this->issuedInvoice();

        $this->get(route('invoices.corrections.create', $invoice))
            ->assertOk()
            ->assertSeeText('Tworzenie korekty')
            ->assertSeeText('Faktura korygująca')
            ->assertSeeText('Korekcie uległy pozycje faktury')
            ->assertSeeText('Korekcie uległy inne dane na fakturze')
            ->assertSeeText(CorrectionReason::GoodsReturn->label())
            ->assertSeeText('Produkt testowy')
            ->assertSeeText('Jan Kowalski');
    }

    public function test_correction_form_displays_existing_vat_code_instead_of_an_empty_rate(): void
    {
        $invoice = $this->updateSourceTaxIdentity($this->issuedInvoice(), '23.00', ' zw ');

        $this->get(route('invoices.corrections.create', $invoice))
            ->assertOk()
            ->assertSee('"vat_rate":null,"vat_code":"ZW"', false)
            ->assertSee('vatCode ||', false);
    }

    public function test_correction_tab_lists_only_issued_corrections_with_shared_document_actions(): void
    {
        $series = $this->systemCorrectionSeries();
        $firstSource = $this->issuedInvoice();
        $first = $this->issueBuyerCorrection($firstSource, $series, 'Pierwszy Nabywca');
        $secondSource = $this->issuedInvoice();
        $second = $this->issueBuyerCorrection($secondSource, $series, 'Drugi Nabywca', '2026-08-05 11:00:00');
        $invoiceWithoutCorrection = $this->issuedInvoice();

        $response = $this->get(route('invoices.corrections.index'));

        $response->assertOk()
            ->assertSeeInOrder([$second->number, $first->number])
            ->assertDontSee($invoiceWithoutCorrection->number)
            ->assertSee('Pierwszy Nabywca')
            ->assertSee('Drugi Nabywca')
            ->assertSee(route('invoices.pdf', $first), false)
            ->assertSee(route('invoices.corrections.edit', [
                'correction' => $first,
                'return_to' => 'corrections',
            ]), false)
            ->assertSee(route('invoices.destroy', $first), false)
            ->assertSee(route('invoices.corrections.bulk-pdf'), false)
            ->assertSee(route('invoices.corrections.bulk-delete'), false)
            ->assertSee('REJESTR SPRZEDAŻY')
            ->assertSee('Pełny numer Korekty')
            ->assertSee('Numer Korekty')
            ->assertSee('name="return_to" value="corrections"', false);

        $this->assertSame(25, $response->viewData('perPage'));
        $this->assertSame([25, 50, 75, 100, 150, 200, 300, 500, 1000], $response->viewData('perPageOptions'));
        $this->assertTrue($response->viewData('isCorrectionList'));
        $this->assertFalse($response->viewData('isInvoiceList'));
        $this->assertFalse($response->viewData('isProformaList'));

        $html = $response->getContent();
        $this->assertStringContainsString('id="bulkInvoicePrintForm"', $html);
        $this->assertStringContainsString('id="bulkInvoiceDeleteForm"', $html);
        $this->assertStringContainsString('data-invoice-id="'.$first->getKey().'"', $html);
        $this->assertStringContainsString('data-lock-version="'.$first->lock_version.'"', $html);
        $this->assertSame(2, substr_count($html, 'name="selection"'));
        $this->assertStringNotContainsString('name="invoice_ids[]"', $html);
        $this->assertStringNotContainsString('name="lock_versions[', $html);

        $this->get(route('invoices.corrections.index', ['buyer' => 'Pierwszy']))
            ->assertOk()
            ->assertSee($first->number)
            ->assertDontSee($second->number);

        $this->get(route('invoices.corrections.index', [
            'total_from' => '-100.00',
            'total_to' => '0.00',
        ]))->assertOk();
    }

    public function test_correction_deleted_from_list_returns_to_the_full_correction_list_context(): void
    {
        $source = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $source,
            $this->systemCorrectionSeries(),
            'Nabywca po korekcie',
        );
        $filters = [
            'page' => 2,
            'buyer' => 'XYZ',
            'sort' => 'gross',
            'direction' => 'asc',
        ];

        $this->delete(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version,
            'return_to' => 'corrections',
            'return_query' => http_build_query($filters, '', '&', PHP_QUERY_RFC3986),
        ])->assertRedirect(route('invoices.corrections.index', $filters))
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('invoices', ['id' => $correction->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $source->getKey()]);
    }

    public function test_correction_deleted_from_editor_returns_to_the_full_list_context_or_order(): void
    {
        $source = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $source,
            $this->systemCorrectionSeries(),
            'Nabywca po korekcie',
        );
        $filters = [
            'page' => 4,
            'year' => 2026,
        ];
        $returnQuery = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);

        $response = $this->delete(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version,
            'return_to' => 'corrections',
            'return_query' => $returnQuery,
        ]);

        $response->assertRedirect(route('invoices.corrections.index', $filters));
        $response->assertSessionMissing('success');

        $secondSource = $this->issuedInvoice();
        $secondCorrection = $this->issueBuyerCorrection(
            $secondSource,
            $this->systemCorrectionSeries(),
            'Drugi nabywca',
        );

        $this->delete(route('invoices.destroy', $secondCorrection), [
            'expected_lock_version' => $secondCorrection->lock_version,
            'return_to' => 'order',
            'return_query' => '',
        ])->assertRedirect(route('orders.show', $secondSource->order_id));
    }

    public function test_selected_corrections_can_be_deleted_from_correction_list_atomically(): void
    {
        $series = $this->systemCorrectionSeries();
        $first = $this->issueBuyerCorrection($this->issuedInvoice(), $series, 'Pierwszy Nabywca');
        $second = $this->issueBuyerCorrection($this->issuedInvoice(), $series, 'Drugi Nabywca');

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $first->getKey() => $first->lock_version,
                    $second->getKey() => $second->lock_version,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHas('success', 'Usunięto 2 Korekty.');

        $this->assertDatabaseMissing('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('invoices', ['id' => $second->getKey()]);
    }

    public function test_selected_legacy_correction_without_slot_is_deleted_with_related_state(): void
    {
        Storage::fake('local');

        $source = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $source,
            $this->systemCorrectionSeries(),
            'Nabywca legacy',
        );
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($pdfPath, '%PDF-test');
        OrderDocumentSlot::query()
            ->where('order_id', $source->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $correction->getKey() => $correction->lock_version,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHas('success', 'Usunięto 1 Korektę.');

        $this->assertDatabaseMissing('invoices', ['id' => $correction->getKey()]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $correction->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $source->order_id,
            'event_type' => 'correction_deleted',
        ]);
        Storage::disk('local')->assertMissing($pdfPath);
    }

    public function test_bulk_deletion_accepts_normal_and_legacy_corrections_from_different_orders(): void
    {
        $series = $this->systemCorrectionSeries();
        $normalSource = $this->issuedInvoice();
        $normal = $this->issueBuyerCorrection($normalSource, $series, 'Nabywca normalny');
        $legacySource = $this->issuedInvoice();
        $legacy = $this->issueBuyerCorrection($legacySource, $series, 'Nabywca legacy');
        OrderDocumentSlot::query()
            ->where('order_id', $legacySource->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $normal->getKey() => $normal->lock_version,
                    $legacy->getKey() => $legacy->lock_version,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHas('success', 'Usunięto 2 Korekty.');

        $this->assertDatabaseMissing('invoices', ['id' => $normal->getKey()]);
        $this->assertDatabaseMissing('invoices', ['id' => $legacy->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $normal->getKey(),
        ]);
        $this->assertDatabaseMissing('order_document_slots', [
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $legacy->getKey(),
        ]);
    }

    public function test_stale_legacy_correction_in_bulk_rolls_back_reconciled_slot_and_all_deletions(): void
    {
        $series = $this->systemCorrectionSeries();
        $normalSource = $this->issuedInvoice();
        $normal = $this->issueBuyerCorrection($normalSource, $series, 'Nabywca normalny');
        $legacySource = $this->issuedInvoice();
        $legacy = $this->issueBuyerCorrection($legacySource, $series, 'Nabywca legacy');
        OrderDocumentSlot::query()
            ->where('order_id', $legacySource->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $normal->getKey() => $normal->lock_version,
                    $legacy->getKey() => $legacy->lock_version + 1,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $normal->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $legacy->getKey()]);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $normalSource->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $normal->getKey(),
        ]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $legacySource->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_multiple_legacy_corrections_without_slot_are_rejected_by_single_and_bulk_deletion(): void
    {
        $source = $this->issuedInvoice();
        $first = $this->issueBuyerCorrection(
            $source,
            $this->systemCorrectionSeries(),
            'Pierwsza zmiana',
        );
        $second = $first->replicate(['number', 'sequence_number', 'lock_version']);
        $second->number = 'BLK 999/2026';
        $second->sequence_number = 999;
        $second->lock_version = 1;
        $second->save();
        OrderDocumentSlot::query()
            ->where('order_id', $source->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->deleteJson(route('invoices.destroy', $first), [
            'expected_lock_version' => $first->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'correction_delete_inconsistent_document');

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $first->getKey() => $first->lock_version,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_correction_slot_pointing_to_another_document_is_rejected(): void
    {
        $source = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $source,
            $this->systemCorrectionSeries(),
            'Poprawiony nabywca',
        );
        OrderDocumentSlot::query()
            ->where('order_id', $source->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->update(['invoice_id' => $source->getKey()]);

        $this->deleteJson(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'correction_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $correction->getKey()]);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $source->getKey(),
        ]);
    }

    public function test_inconsistent_legacy_correction_rolls_back_other_bulk_deletions(): void
    {
        $series = $this->systemCorrectionSeries();
        $normalSource = $this->issuedInvoice();
        $normal = $this->issueBuyerCorrection($normalSource, $series, 'Nabywca normalny');
        $inconsistentSource = $this->issuedInvoice();
        $first = $this->issueBuyerCorrection($inconsistentSource, $series, 'Pierwsza zmiana');
        $second = $first->replicate(['number', 'sequence_number', 'lock_version']);
        $second->number = 'BLK 999/2026';
        $second->sequence_number = 999;
        $second->lock_version = 1;
        $second->save();
        OrderDocumentSlot::query()
            ->where('order_id', $inconsistentSource->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->from(route('invoices.corrections.index'))
            ->delete(route('invoices.corrections.bulk-delete'), [
                'selection' => json_encode((object) [
                    $normal->getKey() => $normal->lock_version,
                    $first->getKey() => $first->lock_version,
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect(route('invoices.corrections.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $normal->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $normalSource->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $normal->getKey(),
        ]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $inconsistentSource->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_issued_correction_can_be_opened_and_updated_without_changing_its_identity(): void
    {
        Storage::fake('local');

        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $items = $this->submittedItems($invoice);
        $items[0]['quantity'] = 0;

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $items,
        ]))->assertRedirect();

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();
        $number = $correction->number;
        $period = $correction->numbering_period_key;
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');

        $this->get(route('invoices.corrections.edit', $correction))
            ->assertOk()
            ->assertSeeText('Edycja korekty')
            ->assertSee(route('invoices.corrections.update', $correction), false)
            ->assertSee(route('invoices.pdf', $correction), false)
            ->assertSee('data-correction-edit-actions', false)
            ->assertSeeText('Drukuj')
            ->assertSeeText('Wgraj')
            ->assertSeeText('Przekaż do KSeF')
            ->assertSeeText('Usuń')
            ->assertSeeText('Powrót')
            ->assertSee('Wgrywanie dokumentów nie jest jeszcze dostępne.', false)
            ->assertSee('Integracja KSeF nie jest jeszcze dostępna.', false)
            ->assertSee(route('invoices.destroy', $correction), false)
            ->assertSee('data-correction-delete-form', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('name="expected_lock_version" value="1"', false);

        $response = $this->patch(route('invoices.corrections.update', $correction), $this->payload($series, [
            'expected_lock_version' => $correction->lock_version,
            'change_items' => true,
            'items' => $this->submittedCorrectionItems($correction),
            'additional_information' => 'Zmieniona informacja korekty',
        ]));

        $correction->refresh();
        $response->assertRedirect(route('invoices.corrections.edit', $correction));
        $this->assertSame($number, $correction->number);
        $this->assertSame($period, $correction->numbering_period_key);
        $this->assertSame($series->getKey(), $correction->invoice_series_id);
        $this->assertSame($invoice->getKey(), $correction->corrected_invoice_id);
        $this->assertSame(2, $correction->lock_version);
        $this->assertSame('Zmieniona informacja korekty', $correction->additional_information_text);
        Storage::disk('local')->assertMissing($cachePath);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $correction->getKey(),
        ]);
    }

    public function test_buyer_only_correction_update_is_a_true_no_op_for_identical_canonical_state(): void
    {
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-08-05 10:00:00'));
        $source = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($source, $series, 'Nabywca po korekcie')->fresh('items');
        $correction->update(['buyer_snapshot' => array_reverse($correction->buyer_snapshot, true)]);
        $correction = $correction->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');
        $lockVersion = $correction->lock_version;
        $updatedAt = $correction->updated_at;
        $snapshots = [
            $correction->buyer_snapshot,
            $correction->correction_totals_snapshot,
            $correction->tax_summary_snapshot,
            $correction->tax_metadata_snapshot,
        ];
        $itemState = $correction->items->map(fn ($item): array => [
            $item->getKey(),
            $item->updated_at?->toJSON(),
        ])->all();
        $eventCount = $source->order->events()->count();
        $this->travelTo(CarbonImmutable::parse('2026-08-06 10:00:00'));

        $updated = app(CorrectionService::class)->update($correction, $this->payload($series, [
            'expected_lock_version' => $lockVersion,
            'change_buyer' => true,
            'buyer' => $correction->buyer_snapshot,
        ]));

        $this->assertSame($lockVersion, $updated->lock_version);
        $this->assertTrue($updatedAt->equalTo($updated->updated_at));
        $this->assertSame($snapshots, [
            $updated->buyer_snapshot,
            $updated->correction_totals_snapshot,
            $updated->tax_summary_snapshot,
            $updated->tax_metadata_snapshot,
        ]);
        $this->assertSame($itemState, $updated->items->map(fn ($item): array => [
            $item->getKey(),
            $item->updated_at?->toJSON(),
        ])->all());
        $this->assertSame($eventCount, $source->order->events()->count());
        Storage::disk('local')->assertExists($cachePath);
    }

    public function test_exact_item_update_and_equivalent_decimal_input_are_true_no_ops(): void
    {
        Storage::fake('local');
        $source = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '80.00';
        $correction = app(CorrectionService::class)->issue(
            $source,
            $series,
            $source->lock_version,
            $this->payload($series, ['change_items' => true, 'items' => $items]),
            $this->documentContext('2026-08-05 10:00:00'),
        )->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');
        $itemIds = $correction->items->modelKeys();
        $lockVersion = $correction->lock_version;
        $submittedItems = $this->submittedCorrectionItems($correction);

        $exact = app(CorrectionService::class)->update($correction, $this->payload($series, [
            'expected_lock_version' => $lockVersion,
            'change_items' => true,
            'items' => $submittedItems,
        ]));

        $this->assertSame($lockVersion, $exact->lock_version);
        $this->assertSame($itemIds, $exact->items->modelKeys());
        Storage::disk('local')->assertExists($cachePath);

        $submittedItems[0]['unit_price_gross'] = '80';

        $updated = app(CorrectionService::class)->update($exact, $this->payload($series, [
            'expected_lock_version' => $lockVersion,
            'change_items' => true,
            'items' => $submittedItems,
        ]));

        $this->assertSame($lockVersion, $updated->lock_version);
        $this->assertSame($itemIds, $updated->items->modelKeys());
        $this->assertSame('80.0000', $updated->items->first()->unit_price_gross);
        Storage::disk('local')->assertExists($cachePath);
    }

    public function test_case_only_vat_code_change_does_not_issue_a_correction(): void
    {
        $source = $this->updateSourceTaxIdentity($this->issuedInvoice(), null, 'ZW');
        $items = $this->submittedItems($source);
        $items[0]['vat_code'] = ' zw ';
        $items[0]['vat_rate'] = '23.00';

        try {
            app(CorrectionService::class)->issue(
                $source,
                $this->systemCorrectionSeries(),
                $source->lock_version,
                $this->payload($this->systemCorrectionSeries(), [
                    'change_items' => true,
                    'items' => $items,
                ]),
                $this->documentContext('2026-08-05 10:00:00'),
            );
            $this->fail('Zmiana wyłącznie zapisu kodu VAT nie powinna tworzyć Korekty.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_has_no_changes', $exception->errorCode());
        }

        $this->assertDatabaseMissing('invoices', [
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_rate_to_code_correction_persists_canonical_snapshots_and_difference_groups(): void
    {
        $source = $this->issuedInvoiceWithoutShipping('123.00');
        $items = $this->submittedItems($source);
        $items[0]['vat_code'] = ' zw ';
        $items[0]['vat_rate'] = '23.00';

        $correction = app(CorrectionService::class)->issue(
            $source,
            $this->systemCorrectionSeries(),
            $source->lock_version,
            $this->payload($this->systemCorrectionSeries(), [
                'change_items' => true,
                'items' => $items,
            ]),
            $this->documentContext('2026-08-05 10:00:00'),
        )->fresh('items');

        $item = $correction->items->sole();
        $this->assertSame('23.00', $item->correction_before_snapshot['vat_rate']);
        $this->assertNull($item->correction_before_snapshot['vat_code']);
        $this->assertNull($item->correction_after_snapshot['vat_rate']);
        $this->assertSame('ZW', $item->correction_after_snapshot['vat_code']);
        $this->assertSame('ZW', $item->correction_difference_snapshot['vat_code']);
        $this->assertSame('23.00', $correction->total_net);
        $this->assertSame('-23.00', $correction->total_vat);
        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame([
            ['vat_rate' => null, 'vat_code' => 'ZW', 'net' => '123.00', 'vat' => '0.00', 'gross' => '123.00'],
            ['vat_rate' => '23.00', 'vat_code' => null, 'net' => '-100.00', 'vat' => '-23.00', 'gross' => '-123.00'],
        ], $correction->tax_summary_snapshot);
        $this->assertSame(
            $correction->correction_totals_snapshot['difference']['tax_summary_snapshot'],
            $correction->tax_summary_snapshot,
        );
    }

    public function test_code_to_code_correction_is_real_even_when_aggregate_totals_are_zero(): void
    {
        $source = $this->updateSourceTaxIdentity($this->issuedInvoiceWithoutShipping('100.00'), null, 'ZW');
        $items = $this->submittedItems($source);
        $items[0]['vat_code'] = 'np';
        $items[0]['vat_rate'] = '23.00';

        $correction = app(CorrectionService::class)->issue(
            $source,
            $this->systemCorrectionSeries(),
            $source->lock_version,
            $this->payload($this->systemCorrectionSeries(), [
                'change_items' => true,
                'items' => $items,
            ]),
            $this->documentContext('2026-08-05 10:00:00'),
        );

        $this->assertSame('0.00', $correction->total_net);
        $this->assertSame('0.00', $correction->total_vat);
        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame([
            ['vat_rate' => null, 'vat_code' => 'NP', 'net' => '100.00', 'vat' => '0.00', 'gross' => '100.00'],
            ['vat_rate' => null, 'vat_code' => 'ZW', 'net' => '-100.00', 'vat' => '0.00', 'gross' => '-100.00'],
        ], $correction->tax_summary_snapshot);
    }

    public function test_code_correction_update_is_canonical_no_op_and_different_code_is_real_change(): void
    {
        Storage::fake('local');
        $source = $this->updateSourceTaxIdentity($this->issuedInvoiceWithoutShipping('100.00'), null, 'ZW');
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';
        $correction = app(CorrectionService::class)->issue(
            $source,
            $this->systemCorrectionSeries(),
            $source->lock_version,
            $this->payload($this->systemCorrectionSeries(), ['change_items' => true, 'items' => $items]),
            $this->documentContext('2026-08-05 10:00:00'),
        )->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-current');
        $itemIds = $correction->items->modelKeys();
        $lockVersion = $correction->lock_version;
        $submitted = $this->submittedCorrectionItems($correction);
        $submitted[0]['vat_code'] = ' zw ';
        $submitted[0]['vat_rate'] = '23.00';

        $unchanged = app(CorrectionService::class)->update($correction, $this->payload($this->systemCorrectionSeries(), [
            'expected_lock_version' => $lockVersion,
            'change_items' => true,
            'items' => $submitted,
        ]));

        $this->assertSame($lockVersion, $unchanged->lock_version);
        $this->assertSame($itemIds, $unchanged->items->modelKeys());
        Storage::disk('local')->assertExists($cachePath);

        $submitted[0]['vat_code'] = 'np';
        $changed = app(CorrectionService::class)->update($unchanged, $this->payload($this->systemCorrectionSeries(), [
            'expected_lock_version' => $lockVersion,
            'change_items' => true,
            'items' => $submitted,
        ]));

        $this->assertSame($lockVersion + 1, $changed->lock_version);
        $this->assertSame('NP', $changed->items->sole()->correction_after_snapshot['vat_code']);
        $this->assertNull($changed->items->sole()->correction_after_snapshot['vat_rate']);
        Storage::disk('local')->assertMissing($cachePath);
    }

    public function test_legacy_lowercase_vat_code_is_canonicalized_once_and_the_next_update_is_a_no_op(): void
    {
        Storage::fake('local');
        $source = $this->updateSourceTaxIdentity($this->issuedInvoiceWithoutShipping('100.00'), null, 'ZW');
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '75.00';
        $correction = app(CorrectionService::class)->issue(
            $source,
            $this->systemCorrectionSeries(),
            $source->lock_version,
            $this->payload($this->systemCorrectionSeries(), ['change_items' => true, 'items' => $items]),
            $this->documentContext('2026-08-05 10:00:00'),
        )->fresh('items');
        $item = $correction->items->sole();
        $legacyAfter = array_merge($item->correction_after_snapshot, ['vat_code' => 'zw']);
        $legacyDifference = array_merge($item->correction_difference_snapshot, ['vat_code' => 'zw']);
        $item->update([
            'vat_code' => 'zw',
            'correction_after_snapshot' => $legacyAfter,
            'correction_difference_snapshot' => $legacyDifference,
        ]);
        $correction = $correction->fresh('items');
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-legacy');
        $submitted = $this->submittedCorrectionItems($correction);
        $submitted[0]['vat_code'] = ' zw ';
        $submitted[0]['vat_rate'] = '23.00';

        $canonicalized = app(CorrectionService::class)->update($correction, $this->payload($this->systemCorrectionSeries(), [
            'expected_lock_version' => $correction->lock_version,
            'change_items' => true,
            'items' => $submitted,
        ]));

        $this->assertSame($correction->lock_version + 1, $canonicalized->lock_version);
        $this->assertSame('ZW', $canonicalized->items->sole()->vat_code);
        $this->assertSame('ZW', $canonicalized->items->sole()->correction_after_snapshot['vat_code']);
        $this->assertNull($canonicalized->items->sole()->vat_rate);
        Storage::disk('local')->assertMissing($cachePath);

        Storage::disk('local')->put($cachePath, '%PDF-canonical');
        $canonicalItemIds = $canonicalized->items->modelKeys();
        $submitted = $this->submittedCorrectionItems($canonicalized);
        $submitted[0]['vat_code'] = ' zw ';
        $submitted[0]['vat_rate'] = '23.00';

        $unchanged = app(CorrectionService::class)->update($canonicalized, $this->payload($this->systemCorrectionSeries(), [
            'expected_lock_version' => $canonicalized->lock_version,
            'change_items' => true,
            'items' => $submitted,
        ]));

        $this->assertSame($canonicalized->lock_version, $unchanged->lock_version);
        $this->assertSame($canonicalItemIds, $unchanged->items->modelKeys());
        Storage::disk('local')->assertExists($cachePath);
    }

    public function test_identical_correction_payload_still_rejects_a_stale_expected_lock_version(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($source, $series, 'Nabywca po korekcie');

        try {
            app(CorrectionService::class)->update($correction, $this->payload($series, [
                'expected_lock_version' => $correction->lock_version + 1,
                'change_buyer' => true,
                'buyer' => $correction->buyer_snapshot,
            ]));
            $this->fail('Nieaktualna wersja Korekty powinna zostac odrzucona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_edit_conflict', $exception->errorCode());
        }

        $this->assertSame($correction->lock_version, $correction->fresh()->lock_version);
    }

    public function test_reverting_the_only_buyer_change_is_not_treated_as_a_no_op(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($source, $series, 'Nabywca po korekcie');

        try {
            app(CorrectionService::class)->update($correction, $this->payload($series, [
                'expected_lock_version' => $correction->lock_version,
                'change_buyer' => true,
                'buyer' => $source->buyer_snapshot,
            ]));
            $this->fail('Korekta bez rzeczywistej zmiany wzgledem Faktury powinna zostac odrzucona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_has_no_changes', $exception->errorCode());
        }

        $this->assertSame($correction->lock_version, $correction->fresh()->lock_version);
    }

    public function test_legacy_correction_is_canonicalized_once_and_then_identical_update_is_a_no_op(): void
    {
        Storage::fake('local');
        $source = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $items = $this->submittedItems($source);
        $items[0]['unit_price_gross'] = '80.00';
        $correction = app(CorrectionService::class)->issue(
            $source,
            $series,
            $source->lock_version,
            $this->payload($series, ['change_items' => true, 'items' => $items]),
            $this->documentContext('2026-08-05 10:00:00'),
        )->fresh('items');
        $legacyTotals = $correction->correction_totals_snapshot;
        foreach (['before', 'after', 'difference'] as $key) {
            unset($legacyTotals[$key]['tax_summary_snapshot']);
        }
        $correction->update([
            'correction_totals_snapshot' => $legacyTotals,
            'tax_summary_snapshot' => $source->tax_summary_snapshot,
        ]);
        $correction = $correction->fresh('items');
        $legacyItemIds = $correction->items->modelKeys();
        $cachePath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($cachePath, '%PDF-legacy');

        $canonical = app(CorrectionService::class)->update($correction, $this->payload($series, [
            'expected_lock_version' => $correction->lock_version,
            'change_items' => true,
            'items' => $this->submittedCorrectionItems($correction),
        ]));

        $this->assertSame($correction->lock_version + 1, $canonical->lock_version);
        $this->assertNotSame($legacyItemIds, $canonical->items->modelKeys());
        foreach (['before', 'after', 'difference'] as $key) {
            $this->assertIsArray($canonical->correction_totals_snapshot[$key]['tax_summary_snapshot']);
        }
        Storage::disk('local')->assertMissing($cachePath);

        Storage::disk('local')->put($cachePath, '%PDF-canonical');
        $canonicalItemIds = $canonical->items->modelKeys();
        $unchanged = app(CorrectionService::class)->update($canonical, $this->payload($series, [
            'expected_lock_version' => $canonical->lock_version,
            'change_items' => true,
            'items' => $this->submittedCorrectionItems($canonical),
        ]));

        $this->assertSame($canonical->lock_version, $unchanged->lock_version);
        $this->assertSame($canonicalItemIds, $unchanged->items->modelKeys());
        Storage::disk('local')->assertExists($cachePath);
    }

    public function test_correction_editor_returns_to_the_correction_list_when_opened_from_that_list(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $invoice,
            $this->systemCorrectionSeries(),
            'Nabywca po korekcie',
        );
        $returnQuery = http_build_query([
            'page' => 2,
            'year' => 2026,
            'buyer' => 'Nabywca',
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
        ], '', '&', PHP_QUERY_RFC3986);
        $expectedListUrl = route('invoices.corrections.index', [
            'page' => 2,
            'year' => 2026,
            'buyer' => 'Nabywca',
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
        ]);

        $this->get(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'corrections',
            'return_query' => $returnQuery,
        ]))
            ->assertOk()
            ->assertSee(
                'data-correction-back-button href="'.e($expectedListUrl).'"',
                false,
            );

        $this->get(route('invoices.corrections.edit', $correction))
            ->assertOk()
            ->assertSee(
                'data-correction-back-button href="'.route('orders.show', $invoice->order_id).'"',
                false,
            );
    }

    public function test_correction_update_returns_to_the_editor_and_preserves_list_context(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $invoice,
            $this->systemCorrectionSeries(),
            'Nabywca po korekcie',
        );
        $returnQuery = http_build_query([
            'page' => 2,
            'year' => 2026,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->patch(route('invoices.corrections.update', $correction), $this->payload(
            $correction->series,
            [
                'expected_lock_version' => $correction->lock_version,
                'change_buyer' => true,
                'buyer' => array_merge($correction->buyer_snapshot, [
                    'name' => 'Nabywca po zapisie',
                ]),
                'return_to' => 'corrections',
                'return_query' => $returnQuery,
            ],
        ));

        $response->assertRedirect(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'corrections',
            'return_query' => $returnQuery,
        ]));
        $response->assertSessionMissing('success');
    }

    public function test_correction_editor_returns_to_the_order_when_opened_from_the_order(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $invoice,
            $this->systemCorrectionSeries(),
            'Nabywca po korekcie',
        );

        $this->get(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'order',
        ]))
            ->assertOk()
            ->assertSee(
                'data-correction-back-button href="'.route('orders.show', $invoice->order_id).'"',
                false,
            );
    }

    public function test_create_route_redirects_to_the_existing_correction_editor(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($invoice, $series, 'Pierwsza zmiana');

        $returnQuery = http_build_query([
            'page' => 3,
            'buyer' => 'Jan',
        ], '', '&', PHP_QUERY_RFC3986);

        $this->get(route('invoices.corrections.create', [
            'invoice' => $invoice,
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ]))->assertRedirect(route('invoices.corrections.edit', [
            'correction' => $correction,
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ]));
    }

    public function test_correction_creation_preserves_the_invoice_list_context_until_the_editor(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $returnQuery = http_build_query([
            'page' => 4,
            'year' => 2026,
            'buyer' => 'Jan',
        ], '', '&', PHP_QUERY_RFC3986);
        $context = [
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ];

        $this->get(route('invoices.corrections.create', [
            'invoice' => $invoice,
            'series_id' => $series->getKey(),
            ...$context,
        ]))
            ->assertOk()
            ->assertSee('name="return_to" value="invoices"', false)
            ->assertSee('name="return_query" value="'.e($returnQuery).'"', false);

        $response = $this->post(
            route('invoices.corrections.store', $invoice),
            $this->payload($series, [
                'change_buyer' => true,
                'buyer' => array_merge($invoice->buyer_snapshot, [
                    'name' => 'Nabywca po korekcie',
                ]),
                ...$context,
            ]),
        );

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $response->assertRedirect(route('invoices.corrections.edit', [
            'correction' => $correction,
            ...$context,
        ]));
    }

    public function test_free_text_default_reason_is_presented_as_other_reason(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->createDocumentSeries(InvoiceDocumentType::Correction, [
            'default_correction_reason' => 'Uzgodniony zwrot handlowy',
        ]);

        $this->get(route('invoices.corrections.create', [
            'invoice' => $invoice,
            'series_id' => $series->getKey(),
        ]))
            ->assertOk()
            ->assertSee('value="other" selected', false)
            ->assertSee('value="Uzgodniony zwrot handlowy"', false);
    }

    public function test_invoice_edit_uses_direct_link_for_one_series_and_modal_for_many_series(): void
    {
        $invoice = $this->issuedInvoice();
        $returnQuery = http_build_query([
            'page' => 3,
            'buyer' => 'Jan',
        ], '', '&', PHP_QUERY_RFC3986);
        $context = [
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ];

        $this->get(route('invoices.edit', [
            'invoice' => $invoice,
            ...$context,
        ]))
            ->assertOk()
            ->assertSee(e(route('invoices.corrections.create', [
                'invoice' => $invoice,
                'series_id' => $this->systemCorrectionSeries()->getKey(),
                ...$context,
            ])), false)
            ->assertDontSee('id="invoiceEditCorrectionSeriesModal"', false);

        $additional = $this->createDocumentSeries(InvoiceDocumentType::Correction);

        $this->get(route('invoices.edit', [
            'invoice' => $invoice,
            ...$context,
        ]))
            ->assertOk()
            ->assertSee('id="invoiceEditCorrectionSeriesModal"', false)
            ->assertSee('name="return_to" value="invoices"', false)
            ->assertSee('name="return_query" value="'.e($returnQuery).'"', false)
            ->assertSeeText($additional->name);
    }

    public function test_item_correction_is_issued_numbered_snapshotted_and_rendered_as_pdf(): void
    {
        Storage::fake('local');

        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $items = $this->submittedItems($invoice);
        $items[0]['quantity'] = 0;

        $response = $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $items,
        ]));

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $response->assertRedirect(route('invoices.corrections.edit', $correction));
        $this->assertSame(InvoiceDocumentStatus::Issued, $correction->status);
        $this->assertSame($invoice->getKey(), $correction->corrected_invoice_id);
        $this->assertNull($correction->previous_correction_id);
        $this->assertNotNull($correction->number);
        $this->assertSame('-100.00', $correction->total_gross);
        $this->assertSame('123.00', $correction->correction_totals_snapshot['before']['gross']);
        $this->assertSame('23.00', $correction->correction_totals_snapshot['after']['gross']);
        $this->assertSame('-100.00', $correction->correction_totals_snapshot['difference']['gross']);
        $this->assertSame('123.00', $invoice->fresh()->total_gross);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $invoice->order_id,
            'event_type' => 'correction_issued',
        ]);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $correction->getKey(),
        ]);

        $this->get(route('invoices.pdf', $correction))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_buyer_only_correction_keeps_amounts_and_snapshots_changed_buyer(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $payload = $this->payload($series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, [
                'name' => 'Anna Zmieniona',
                'company_name' => null,
                'country_code' => 'DE',
            ]),
        ]);

        $this->post(route('invoices.corrections.store', $invoice), $payload)->assertRedirect();

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $this->assertSame('0.00', $correction->total_gross);
        $this->assertSame('Anna Zmieniona', $correction->buyer_snapshot['name']);
        $this->assertSame('DE', $correction->buyer_snapshot['country_code']);
        $this->assertSame('Niemcy', $correction->buyer_snapshot['country_name']);
        $this->assertSame('Jan Kowalski', $invoice->fresh()->buyer_snapshot['name']);
    }

    public function test_second_store_redirects_to_the_existing_correction_without_creating_another_document(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $firstItems = $this->submittedItems($invoice);
        $firstItems[0]['unit_price_gross'] = '80.00';

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $firstItems,
        ]))->assertRedirect();

        $first = Invoice::query()->where('document_type', InvoiceDocumentType::Correction)->sole();
        $originalNumber = $first->number;
        $secondItems = $this->submittedItems($invoice);
        $secondItems[0]['unit_price_gross'] = '60.00';

        $response = $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $secondItems,
        ]));

        $response->assertRedirect(route('invoices.corrections.edit', $first));
        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame($originalNumber, $first->fresh()->number);
        $this->assertSame('80.0000', $first->fresh()->items->firstWhere('line_type', 'product')->correction_after_snapshot['unit_price_gross']);
    }

    public function test_unchanged_correction_is_rejected_without_consuming_a_number(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($series, [
            'change_items' => true,
            'items' => $this->submittedItems($invoice),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('correction');

        $this->assertDatabaseMissing('invoices', [
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_non_correction_series_is_rejected_by_backend(): void
    {
        $invoice = $this->issuedInvoice();

        $this->post(route('invoices.corrections.store', $invoice), $this->payload($invoice->series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, ['name' => 'Zmieniony nabywca']),
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('correction');

        $this->assertDatabaseMissing('invoices', [
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    public function test_service_rejects_a_second_correction_and_keeps_the_single_document_slot(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $service = app(CorrectionService::class);
        $data = $this->payload($series, [
            'change_buyer' => true,
            'buyer' => array_merge($invoice->buyer_snapshot, ['name' => 'Pierwsza zmiana']),
        ]);

        $first = $service->issue($invoice, $series, $invoice->lock_version, $data, $this->documentContext('2026-08-05 10:00:00'));
        $data['buyer']['name'] = 'Druga zmiana';

        try {
            $service->issue($invoice, $series, $invoice->lock_version + 1, $data, $this->documentContext('2026-08-05 10:01:00'));
            $this->fail('Druga Korekta nie powinna zostać utworzona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_already_exists', $exception->errorCode());
            $this->assertSame($first->getKey(), $exception->metadata()['correction_id']);
        }

        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame('Pierwsza zmiana', $first->fresh()->buyer_snapshot['name']);
        $this->assertNull($first->previous_correction_id);
        $this->assertDatabaseCount('order_document_slots', 2);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $first->getKey(),
        ]);
    }

    public function test_only_correction_can_be_deleted_with_slot_items_pdf_numbering_and_audit_cleanup(): void
    {
        Storage::fake('local');

        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($invoice, $series, 'Poprawiony nabywca');
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($correction);
        Storage::disk('local')->put($pdfPath, '%PDF-test');

        $response = $this->delete(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version,
        ]);

        $response->assertRedirect(route('orders.show', $invoice->order_id));
        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseMissing('invoices', ['id' => $correction->getKey()]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $correction->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $invoice->order_id,
            'event_type' => 'correction_deleted',
            'title' => 'Usunięto korektę',
        ]);
        Storage::disk('local')->assertMissing($pdfPath);
        $counter = InvoiceNumberCounter::query()
            ->where('invoice_series_id', $series->getKey())
            ->firstOrFail();

        $this->assertSame(0, $counter->last_sequence_number);
        $this->assertDatabaseHas('invoice_number_counter_adjustments', [
            'invoice_number_counter_id' => $counter->getKey(),
            'new_last_sequence_number' => 0,
            'reason' => 'Automatyczne cofnięcie wolnego końca numeracji po usunięciu Korekty.',
        ]);
    }

    public function test_invoice_list_links_the_correction_action_to_the_existing_correction_editor(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $correction = $this->issueBuyerCorrection($invoice, $series, 'Pierwsza zmiana');

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee(route('invoices.corrections.edit', $correction), false)
            ->assertDontSee(route('invoices.corrections.create', [
                'invoice' => $invoice,
                'series_id' => $series->getKey(),
            ]), false);
    }

    public function test_legacy_multiple_corrections_are_rejected_as_inconsistent(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->systemCorrectionSeries();
        $first = $this->issueBuyerCorrection($invoice, $series, 'Pierwsza zmiana');
        $second = $first->replicate(['number', 'sequence_number', 'lock_version']);
        $second->number = 'BLK 999/2026';
        $second->sequence_number = 999;
        $second->lock_version = 1;
        $second->save();

        $this->deleteJson(route('invoices.destroy', $first), [
            'expected_lock_version' => $first->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'correction_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $first->getKey(),
        ]);
    }

    public function test_correction_deletion_rejects_stale_lock_version(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $invoice,
            $this->systemCorrectionSeries(),
            'Poprawiony nabywca',
        );

        $this->deleteJson(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version + 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'correction_delete_conflict');

        $this->assertDatabaseHas('invoices', ['id' => $correction->getKey()]);
    }

    public function test_legacy_latest_correction_without_slot_can_be_deleted_safely(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection(
            $invoice,
            $this->systemCorrectionSeries(),
            'Poprawiony nabywca',
        );
        OrderDocumentSlot::query()
            ->where('order_id', $invoice->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->delete();

        $this->deleteJson(route('invoices.destroy', $correction), [
            'expected_lock_version' => $correction->lock_version,
        ])->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $correction->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
    }

    private function issuedInvoice(): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
    }

    private function issuedInvoiceWithoutShipping(string $gross): Invoice
    {
        $order = $this->createDocumentOrder([
            'total_gross' => $gross,
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => $gross,
            'total_price_gross' => $gross,
        ]);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext(),
        );
    }

    private function updateSourceTaxIdentity(Invoice $source, ?string $vatRate, ?string $vatCode): Invoice
    {
        $item = $source->fresh('items')->items->firstWhere('line_type', 'product');
        $this->assertNotNull($item);

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

    private function systemCorrectionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    private function issueBuyerCorrection(
        Invoice $invoice,
        InvoiceSeries $series,
        string $buyerName,
        string $occurredAt = '2026-08-05 10:00:00',
    ): Invoice {
        return app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $invoice->lock_version,
            $this->payload($series, [
                'change_buyer' => true,
                'buyer' => array_merge($invoice->buyer_snapshot, [
                    'name' => $buyerName,
                    'company_name' => null,
                ]),
            ]),
            $this->documentContext($occurredAt),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedItems(Invoice $source): array
    {
        return app(CorrectionSourceStateService::class)
            ->effectiveItems($source)
            ->map(function (array $item): array {
                $snapshot = $item['snapshot'];

                return [
                    'source_item_id' => $item['source_item_id'],
                    'order_item_id' => $item['source_item']->order_item_id,
                    'line_type' => $snapshot['line_type'],
                    'position' => $snapshot['position'],
                    'name' => $snapshot['name'],
                    'description' => $snapshot['description'],
                    'unit_name' => $snapshot['unit_name'],
                    'quantity' => (int) $snapshot['quantity'],
                    'unit_price_gross' => $this->twoDecimals($snapshot['unit_price_gross']),
                    'vat_rate' => $snapshot['vat_rate'] !== null
                        ? $this->twoDecimals($snapshot['vat_rate'])
                        : null,
                    'vat_code' => $snapshot['vat_code'],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function submittedCorrectionItems(Invoice $correction): array
    {
        return $correction->items->map(function ($item): array {
            $snapshot = $item->correction_after_snapshot;

            return [
                'source_item_id' => $item->getKey(),
                'order_item_id' => $item->order_item_id,
                'line_type' => $snapshot['line_type'],
                'position' => $snapshot['position'],
                'name' => $snapshot['name'],
                'description' => $snapshot['description'],
                'unit_name' => $snapshot['unit_name'],
                'quantity' => (int) $snapshot['quantity'],
                'unit_price_gross' => $this->twoDecimals($snapshot['unit_price_gross']),
                'vat_rate' => $snapshot['vat_rate'] !== null
                    ? $this->twoDecimals($snapshot['vat_rate'])
                    : null,
                'vat_code' => $snapshot['vat_code'],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function payload(InvoiceSeries $series, array $overrides = []): array
    {
        return array_replace_recursive([
            'expected_source_lock_version' => 1,
            'correction_series_id' => $series->getKey(),
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester korekty',
            'additional_information' => 'Informacja korekty',
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
