<?php

namespace Tests\Feature\Invoices;

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
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $correction->getKey(),
        ]);
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
