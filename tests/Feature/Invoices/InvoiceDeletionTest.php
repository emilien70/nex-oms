<?php

namespace Tests\Feature\Invoices;

use App\Models\Order;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceNumberCounter;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceDeletionPolicy;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoiceNumberingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfStorage;
use Modules\Invoices\Services\OrderSalesDocumentActionsView;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceDeletionTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_can_be_deleted_through_ajax_with_slot_items_pdf_and_audit_cleanup(): void
    {
        Storage::fake('local');
        [$order, $series, $invoice] = $this->issuedInvoice();
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->put($pdfPath, '%PDF-test');

        $response = $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ]);

        $response->assertOk()
            ->assertJsonPath('redirect_url', route('orders.show', $order))
            ->assertJsonStructure(['html', 'redirect_url']);
        $this->assertStringContainsString('WYSTAW FAKTUR', $response->json('html'));
        $this->assertStringNotContainsString('data-sales-document-delete-form', $response->json('html'));
        $this->assertStringNotContainsString('id="deleteSalesDocumentModal"', $response->json('html'));
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $invoice->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $invoice->getKey()]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'invoice_deleted',
            'title' => 'Usunięto fakturę',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'invoice_issued',
        ]);
        Storage::disk('local')->assertMissing($pdfPath);
        $this->assertSame(0, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        $this->assertDatabaseHas('invoice_number_counter_adjustments', [
            'new_last_sequence_number' => 0,
            'reason' => 'Automatyczne cofnięcie wolnego końca numeracji po usunięciu Faktury VAT.',
        ]);
        $this->assertSame($series->getKey(), InvoiceNumberCounter::query()->firstOrFail()->invoice_series_id);
    }

    public function test_invoice_can_be_deleted_from_edit_page_with_standard_redirect(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.destroy', $invoice), false)
            ->assertSee('Usuń Fakturę');

        $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_deleted_from_editor_returns_to_the_full_invoice_list_context(): void
    {
        [, , $invoice] = $this->issuedInvoice();
        $filters = [
            'buyer' => 'ABC',
            'year' => 2026,
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
            'page' => 3,
        ];
        $returnFilters = [
            'page' => 3,
            'year' => 2026,
            'buyer' => 'ABC',
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => 100,
        ];
        $returnQuery = http_build_query($returnFilters, '', '&', PHP_QUERY_RFC3986);

        $this->get(route('invoices.edit', [
            'invoice' => $invoice,
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ]))
            ->assertOk()
            ->assertSee('name="return_to" value="invoices"', false)
            ->assertSee('name="return_query" value="'.e($returnQuery).'"', false);

        $response = $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
            'return_query' => $returnQuery,
        ]);

        $response->assertRedirect(route('invoices.index', $returnFilters));
        $response->assertSessionMissing('success');
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_deletion_cannot_be_redirected_outside_the_application(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $response = $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
            'return_query' => 'https://evil.example/foo',
        ]);

        $response->assertRedirect(route('invoices.index'));
        $this->assertStringNotContainsString('evil.example', $response->headers->get('Location'));
    }

    public function test_invoice_deleted_from_list_returns_to_the_full_invoice_list_context(): void
    {
        [, , $invoice] = $this->issuedInvoice();
        $filters = [
            'page' => 3,
            'year' => 2026,
            'buyer' => 'ABC',
            'per_page' => 100,
        ];

        $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
            'return_query' => http_build_query($filters, '', '&', PHP_QUERY_RFC3986),
        ])->assertRedirect(route('invoices.index', $filters))
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_deleted_from_unfiltered_list_returns_to_invoice_list(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
            'return_query' => '',
        ])->assertRedirect(route('invoices.index'))
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_proforma_deleted_from_list_returns_to_the_full_proforma_list_context(): void
    {
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $proforma = $this->proformaForNewOrder($series);
        $filters = [
            'page' => 2,
            'year' => 2026,
        ];

        $this->delete(route('invoices.destroy', $proforma), [
            'expected_lock_version' => $proforma->lock_version,
            'return_to' => 'proformas',
            'return_query' => http_build_query($filters, '', '&', PHP_QUERY_RFC3986),
        ])->assertRedirect(route('invoices.proformas.index', $filters))
            ->assertSessionMissing('success');

        $this->assertDatabaseMissing('invoices', ['id' => $proforma->getKey()]);
    }

    public function test_single_deletion_discards_unknown_and_invalid_return_query_parameters(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $response = $this->delete(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
            'return_to' => 'invoices',
            'return_query' => http_build_query([
                'buyer' => 'ABC',
                'page' => 2,
                'redirect' => 'https://evil.example/foo',
                'sort' => 'unsupported',
                'direction' => 'sideways',
                'per_page' => 42,
            ], '', '&', PHP_QUERY_RFC3986),
        ]);

        $response->assertRedirect(route('invoices.index', [
            'page' => 2,
            'buyer' => 'ABC',
        ]));
        $this->assertStringNotContainsString('evil.example', $response->headers->get('Location'));
        $response->assertSessionMissing('success');
    }

    public function test_selected_invoices_can_be_deleted_from_invoice_list_atomically(): void
    {
        $series = $this->createDocumentSeries();
        $first = $this->issueForNewOrder($series);
        $second = $this->issueForNewOrder($series);

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $first->getKey() => $first->lock_version,
                    $second->getKey() => $second->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success', 'Usunięto 2 Faktury.');

        $this->assertDatabaseMissing('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseCount('order_document_slots', 0);
        $this->assertSame(0, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_bulk_invoice_prevalidation_batches_relationship_facts_independently_of_selection_size(): void
    {
        $small = $this->captureBulkInvoiceDeletionQueries(2);
        $larger = $this->captureBulkInvoiceDeletionQueries(20);

        foreach ([$small, $larger] as $queries) {
            $this->assertSame(0, $this->countQueriesMatching($queries, static function (string $sql): bool {
                return str_contains($sql, 'select exists')
                    && (str_contains($sql, 'from invoice_series')
                        || str_contains($sql, 'from orders')
                        || str_contains($sql, 'corrected_invoice_id'));
            }));
            $this->assertSame(1, $this->countQueriesMatching($queries, static function (string $sql): bool {
                return str_starts_with($sql, 'select')
                    && str_contains($sql, 'from invoice_series')
                    && str_contains($sql, ' in (');
            }));
            $this->assertSame(1, $this->countQueriesMatching($queries, static function (string $sql): bool {
                return str_starts_with($sql, 'select')
                    && str_contains($sql, 'from orders')
                    && str_contains($sql, ' in (');
            }));
            $this->assertSame(1, $this->countQueriesMatching($queries, static function (string $sql): bool {
                return str_starts_with($sql, 'select')
                    && str_contains($sql, 'corrected_invoice_id')
                    && str_contains($sql, ' in (');
            }));
            $this->assertSame(1, $this->countQueriesMatching($queries, static function (string $sql): bool {
                return str_starts_with($sql, 'select')
                    && str_contains($sql, 'superseded_by_invoice_id')
                    && str_contains($sql, ' in (');
            }));
        }
    }

    public function test_bulk_invoice_deletion_preloads_and_restores_all_linked_proformas(): void
    {
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $pairs = collect(range(1, 2))->map(function () use ($proformaSeries, $invoiceSeries): array {
            $order = $this->createDocumentOrder();
            $this->createDocumentItem($order);
            $proforma = app(ProformaService::class)
                ->createOrRefresh($order, $proformaSeries, $this->documentContext())
                ->invoice;
            $invoice = app(InvoiceIssuingService::class)
                ->issue($order, $invoiceSeries, $this->documentContext('2026-07-28 13:00:00'));

            return [$order, $proforma, $invoice];
        });
        $queries = [];
        $recording = true;
        DB::listen(function (object $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $this->normalizeSql($query->sql);
            }
        });

        try {
            app(InvoiceDeletionService::class)->deleteMany(
                $pairs->mapWithKeys(static fn (array $pair): array => [
                    $pair[2]->getKey() => $pair[2]->lock_version,
                ])->all(),
                $this->documentContext(),
            );
        } finally {
            $recording = false;
        }

        foreach ($pairs as [$order, $proforma, $invoice]) {
            $this->assertDatabaseMissing('invoices', ['id' => $invoice->getKey()]);
            $this->assertFalse($proforma->refresh()->isProformaSuperseded());
            $this->assertDatabaseHas('order_events', [
                'order_id' => $order->getKey(),
                'event_type' => 'proforma_restored',
            ]);
        }

        $this->assertSame(1, $this->countQueriesMatching($queries, static function (string $sql): bool {
            return str_starts_with($sql, 'select')
                && str_contains($sql, 'superseded_by_invoice_id')
                && str_contains($sql, ' in (');
        }));
        $this->assertSame(0, $this->countQueriesMatching($queries, static function (string $sql): bool {
            return str_starts_with($sql, 'select')
                && str_contains($sql, 'superseded_by_invoice_id = ?');
        }));
    }

    public function test_bulk_invoice_deletion_rejects_multiple_linked_proformas_before_any_delete(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)
            ->createOrRefresh(
                $order,
                $this->createDocumentSeries(InvoiceDocumentType::Proforma),
                $this->documentContext(),
            )
            ->invoice;
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext('2026-07-28 13:00:00'),
        );
        $duplicate = $proforma->replicate();
        $duplicate->invoice_series_id = $this->createDocumentSeries(InvoiceDocumentType::Proforma)->getKey();
        $duplicate->number = 'BLPF 999/2026';
        $duplicate->sequence_number = 999;
        $duplicate->proforma_superseded_at = '2026-07-28 13:00:00';
        $duplicate->superseded_by_invoice_id = $invoice->getKey();
        $duplicate->save();

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $invoice->getKey() => $invoice->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $proforma->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $duplicate->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $invoice->getKey()]);
        $this->assertSame(1, InvoiceNumberCounter::query()
            ->where('invoice_series_id', $invoice->invoice_series_id)
            ->firstOrFail()
            ->last_sequence_number);
    }

    public function test_bulk_invoice_deletion_with_missing_order_is_controlled_and_atomic(): void
    {
        $series = $this->createDocumentSeries();
        $deletable = $this->issueForNewOrder($series);
        $orphaned = $this->issueForNewOrder($series);
        Order::query()->findOrFail($orphaned->order_id)->forceDelete();
        $orphaned->refresh();

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $deletable->getKey() => $deletable->lock_version,
                    $orphaned->getKey() => $orphaned->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $deletable->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $orphaned->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $deletable->getKey()]);
        $this->assertSame(2, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_bulk_deletion_rejects_invalid_json_and_lock_versions(): void
    {
        $invoice = $this->issueForNewOrder($this->createDocumentSeries());

        foreach ([
            '{invalid',
            '[]',
            $this->deleteSelection([$invoice->getKey() => null]),
            $this->deleteSelection([$invoice->getKey() => 0]),
        ] as $selection) {
            $this->from(route('invoices.index'))
                ->delete(route('invoices.bulk-delete'), ['selection' => $selection])
                ->assertRedirect(route('invoices.index'))
                ->assertSessionHasErrors();
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_bulk_deletion_rejects_missing_document(): void
    {
        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([999999 => 1]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Jedna z zaznaczonych Faktur już nie istnieje.',
            ]);
    }

    public function test_each_bulk_deletion_endpoint_rejects_more_than_one_thousand_documents(): void
    {
        $selection = $this->deleteSelection(array_fill_keys(range(1, 1001), 1));

        foreach ([
            [route('invoices.index'), route('invoices.bulk-delete'), 'Jednorazowo można usunąć maksymalnie 1000 Faktur.'],
            [route('invoices.proformas.index'), route('invoices.proformas.bulk-delete'), 'Jednorazowo można usunąć maksymalnie 1000 Pro form.'],
            [route('invoices.corrections.index'), route('invoices.corrections.bulk-delete'), 'Jednorazowo można usunąć maksymalnie 1000 Korekt.'],
        ] as [$from, $route, $message]) {
            $this->from($from)
                ->delete($route, ['selection' => $selection])
                ->assertRedirect($from)
                ->assertSessionHasErrors(['invoice_ids' => $message]);
        }
    }

    public function test_bulk_deletion_cannot_be_bypassed_with_legacy_fields(): void
    {
        $invoice = $this->issueForNewOrder($this->createDocumentSeries());

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => '[]',
                'invoice_ids' => [$invoice->getKey()],
                'lock_versions' => [$invoice->getKey() => $invoice->lock_version],
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_bulk_deletion_with_stale_lock_version_is_atomic(): void
    {
        $series = $this->createDocumentSeries();
        $first = $this->issueForNewOrder($series);
        $second = $this->issueForNewOrder($series);

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $first->getKey() => $first->lock_version,
                    $second->getKey() => $second->lock_version + 1,
                ]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors('invoice_ids');

        $this->assertDatabaseHas('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $first->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $second->getKey()]);
    }

    public function test_bulk_deletion_keeps_every_selected_invoice_when_one_is_blocked(): void
    {
        $series = $this->createDocumentSeries();
        $deletable = $this->issueForNewOrder($series);
        $blocked = $this->issueForNewOrder($series);
        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        Invoice::query()->create([
            'order_id' => $blocked->order_id,
            'invoice_series_id' => $correctionSeries->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'corrected_invoice_id' => $blocked->getKey(),
        ]);

        $this->from(route('invoices.index'))
            ->delete(route('invoices.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $deletable->getKey() => $deletable->lock_version,
                    $blocked->getKey() => $blocked->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Nie można usunąć Faktury, ponieważ została do niej wystawiona Korekta.',
            ]);

        $this->assertDatabaseHas('invoices', ['id' => $deletable->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $blocked->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $deletable->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $blocked->getKey()]);
        $this->assertSame(2, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_selected_proformas_can_be_deleted_from_proforma_list_atomically(): void
    {
        $series = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $first = $this->proformaForNewOrder($series);
        $second = $this->proformaForNewOrder($series);

        $this->from(route('invoices.proformas.index'))
            ->delete(route('invoices.proformas.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $first->getKey() => $first->lock_version,
                    $second->getKey() => $second->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.proformas.index'))
            ->assertSessionHas('success', 'Usunięto 2 Pro formy.');

        $this->assertDatabaseMissing('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseMissing('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseCount('order_document_slots', 0);
        $this->assertSame(0, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
    }

    public function test_bulk_proforma_deletion_rejects_mixed_document_types_atomically(): void
    {
        $proforma = $this->proformaForNewOrder(
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
        );
        $invoice = $this->issueForNewOrder($this->createDocumentSeries());

        $this->from(route('invoices.proformas.index'))
            ->delete(route('invoices.proformas.bulk-delete'), [
                'selection' => $this->deleteSelection([
                    $proforma->getKey() => $proforma->lock_version,
                    $invoice->getKey() => $invoice->lock_version,
                ]),
            ])
            ->assertRedirect(route('invoices.proformas.index'))
            ->assertSessionHasErrors([
                'invoice_ids' => 'Zaznaczone dokumenty muszą być Pro formami.',
            ]);

        $this->assertDatabaseHas('invoices', ['id' => $proforma->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_stale_lock_version_does_not_delete_invoice(): void
    {
        [, , $invoice] = $this->issuedInvoice();

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version + 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_conflict');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_without_order_returns_controlled_inconsistency(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();
        $order->forceDelete();
        $invoice->refresh();

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_invoice_with_correction_cannot_be_deleted(): void
    {
        [$order, , $invoice] = $this->issuedInvoice();
        $correctionSeries = $this->createDocumentSeries(InvoiceDocumentType::Correction);
        Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $correctionSeries->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'corrected_invoice_id' => $invoice->getKey(),
        ]);

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invoice_delete_blocked_by_correction');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_active_proforma_can_be_deleted_with_slot_items_pdf_and_audit_cleanup(): void
    {
        Storage::fake('local');
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;
        $pdfPath = app(InvoicePdfFilenameGenerator::class)->storagePath($proforma);
        Storage::disk('local')->put($pdfPath, '%PDF-test');

        $response = $this->deleteJson(route('invoices.destroy', $proforma), [
            'expected_lock_version' => $proforma->lock_version,
        ]);

        $response->assertOk()
            ->assertJsonPath('redirect_url', route('orders.show', $order));
        $this->assertStringContainsString('PRO FORMA', $response->json('html'));
        $this->assertDatabaseMissing('invoices', ['id' => $proforma->getKey()]);
        $this->assertDatabaseMissing('invoice_items', ['invoice_id' => $proforma->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', ['invoice_id' => $proforma->getKey()]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'proforma_deleted',
            'title' => 'Usunięto Pro formę',
        ]);
        Storage::disk('local')->assertMissing($pdfPath);
        $this->assertSame(0, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        $this->assertDatabaseHas('invoice_number_counter_adjustments', [
            'new_last_sequence_number' => 0,
            'reason' => 'Automatyczne cofnięcie wolnego końca numeracji po usunięciu Pro formy.',
        ]);

        $replacement = app(ProformaService::class)
            ->createOrRefresh($order->refresh(), $proformaSeries, $this->documentContext('2026-07-29 10:00:00'))
            ->invoice;

        $this->assertNotSame($proforma->getKey(), $replacement->getKey());
        $this->assertSame(1, $replacement->sequence_number);
    }

    public function test_superseded_proforma_cannot_be_deleted_while_invoice_exists(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proforma = app(ProformaService::class)
            ->createOrRefresh(
                $order,
                $this->createDocumentSeries(InvoiceDocumentType::Proforma),
                $this->documentContext(),
            )
            ->invoice;
        app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext('2026-07-28 13:00:00'),
        );

        $proforma->refresh();

        $this->from(route('invoices.proformas.index'))
            ->delete(route('invoices.destroy', $proforma), [
                'expected_lock_version' => $proforma->lock_version,
                'return_to' => 'proformas',
            ])
            ->assertRedirect(route('invoices.proformas.index'))
            ->assertSessionHasErrors([
                'invoice' => 'Do Pro Forma została już wystawiona Faktura VAT.',
            ]);

        $this->deleteJson(route('invoices.destroy', $proforma), [
            'expected_lock_version' => $proforma->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'proforma_delete_blocked_by_invoice')
            ->assertJsonPath('message', 'Do Pro Forma została już wystawiona Faktura VAT.');

        $this->assertDatabaseHas('invoices', ['id' => $proforma->getKey()]);
    }

    public function test_inconsistent_document_slot_blocks_deletion(): void
    {

        [, , $invoice] = $this->issuedInvoice();
        OrderDocumentSlot::query()
            ->where('invoice_id', $invoice->getKey())
            ->update(['invoice_id' => null]);

        $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ])->assertConflict()
            ->assertJsonPath('code', 'invoice_delete_inconsistent_document');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
    }

    public function test_deleting_internal_gap_does_not_reuse_number(): void
    {
        $series = $this->createDocumentSeries();
        $first = $this->issueForNewOrder($series);
        $middle = $this->issueForNewOrder($series);
        $last = $this->issueForNewOrder($series);

        app(InvoiceDeletionService::class)->delete(
            $middle,
            $middle->lock_version,
            $this->documentContext(),
        );

        $next = $this->issueForNewOrder($series);

        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(3, $last->sequence_number);
        $this->assertSame(4, $next->sequence_number);
    }

    public function test_deleting_free_tail_reuses_only_that_tail_number(): void
    {
        $series = $this->createDocumentSeries();
        $this->issueForNewOrder($series);
        $tail = $this->issueForNewOrder($series);

        app(InvoiceDeletionService::class)->delete(
            $tail,
            $tail->lock_version,
            $this->documentContext(),
        );

        $next = $this->issueForNewOrder($series);

        $this->assertSame(2, $next->sequence_number);
    }

    public function test_protected_floor_prevents_tail_counter_rollback(): void
    {
        $series = $this->createDocumentSeries();
        $invoice = $this->issueForNewOrder($series);
        InvoiceNumberCounter::query()->update(['protected_floor_sequence_number' => 1]);

        app(InvoiceDeletionService::class)->delete(
            $invoice,
            $invoice->lock_version,
            $this->documentContext(),
        );

        $this->assertSame(1, InvoiceNumberCounter::query()->firstOrFail()->last_sequence_number);
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    public function test_deleting_invoice_restores_exact_superseded_proforma_and_allows_it_to_be_refreshed(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;
        $proformaNumber = $proforma->number;
        $proformaItems = $proforma->items()->get()->toArray();
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $invoiceSeries, $this->documentContext('2026-07-28 13:00:00'));

        $response = $this->deleteJson(route('invoices.destroy', $invoice), [
            'expected_lock_version' => $invoice->lock_version,
        ]);

        $response->assertOk();
        $this->assertFalse($proforma->refresh()->isProformaSuperseded());
        $this->assertNull($proforma->proforma_superseded_at);
        $this->assertNull($proforma->superseded_by_invoice_id);
        $this->assertSame($proformaNumber, $proforma->number);
        $this->assertSame($proformaItems, $proforma->items()->get()->toArray());
        $this->assertStringContainsString($proformaNumber, $response->json('html'));
        $html = app(OrderSalesDocumentActionsView::class)->render($order);
        $this->assertStringContainsString($proformaNumber, $html);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->getKey(),
            'event_type' => 'proforma_restored',
            'title' => 'Przywrócono pro formę',
        ]);

        $refreshed = app(ProformaService::class)->createOrRefresh(
            $order->refresh(),
            $proformaSeries,
            $this->documentContext('2026-07-28 15:00:00'),
        )->invoice;
        $this->assertSame($proforma->getKey(), $refreshed->getKey());
        $this->assertSame($proformaNumber, $refreshed->number);

        $replacementInvoice = app(InvoiceIssuingService::class)
            ->issue($order->refresh(), $invoiceSeries, $this->documentContext('2026-07-28 16:00:00'));

        $this->assertTrue($proforma->refresh()->isProformaSuperseded());
        $this->assertSame($replacementInvoice->getKey(), $proforma->superseded_by_invoice_id);
    }

    public function test_failure_after_counter_release_rolls_back_entire_deletion(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $proformaSeries = $this->createDocumentSeries(InvoiceDocumentType::Proforma);
        $invoiceSeries = $this->createDocumentSeries();
        $proforma = app(ProformaService::class)
            ->createOrRefresh($order, $proformaSeries, $this->documentContext())
            ->invoice;
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $invoiceSeries, $this->documentContext());
        $counter = InvoiceNumberCounter::query()
            ->where('invoice_series_id', $invoiceSeries->getKey())
            ->firstOrFail();
        $eventsBefore = $order->events()->count();
        $service = new class(app(InvoiceDeletionPolicy::class), app(CorrectionSourceStateService::class), app(InvoiceNumberingService::class), app(InvoicePdfStorage::class)) extends InvoiceDeletionService
        {
            protected function afterNumberReleased(Invoice $invoice): void
            {
                throw new DomainException('Wymuszony błąd po zwolnieniu numeru.');
            }
        };

        try {
            $service->delete($invoice, $invoice->lock_version, $this->documentContext());
            $this->fail('Usunięcie z wymuszonym błędem nie zostało wycofane.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('invoice_delete_numbering_inconsistent', $exception->errorCode());
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->getKey()]);
        $this->assertDatabaseHas('order_document_slots', ['invoice_id' => $invoice->getKey()]);
        $this->assertTrue($proforma->refresh()->isProformaSuperseded());
        $this->assertSame($invoice->getKey(), $proforma->superseded_by_invoice_id);
        $this->assertSame(1, $counter->refresh()->last_sequence_number);
        $this->assertSame($eventsBefore, $order->events()->count());
        $this->assertSame(0, $order->events()->where('event_type', 'proforma_restored')->count());
        $this->assertDatabaseCount('invoice_number_counter_adjustments', 0);
    }

    /** @return array{0: Order, 1: InvoiceSeries, 2: Invoice} */
    private function issuedInvoice(): array
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $series = $this->createDocumentSeries();
        $invoice = app(InvoiceIssuingService::class)
            ->issue($order, $series, $this->documentContext());

        return [$order, $series, $invoice];
    }

    private function issueForNewOrder(InvoiceSeries $series): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)
            ->issue($order, $series, $this->documentContext());
    }

    private function proformaForNewOrder(InvoiceSeries $series): Invoice
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);

        return app(ProformaService::class)
            ->createOrRefresh($order, $series, $this->documentContext())
            ->invoice;
    }

    /** @return array<int, string> */
    private function captureBulkInvoiceDeletionQueries(int $documentCount): array
    {
        $series = $this->createDocumentSeries();
        $invoices = collect(range(1, $documentCount))
            ->map(fn (): Invoice => $this->issueForNewOrder($series));
        $queries = [];
        $recording = true;
        DB::listen(function (object $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $this->normalizeSql($query->sql);
            }
        });

        try {
            app(InvoiceDeletionService::class)->deleteMany(
                $invoices->mapWithKeys(static fn (Invoice $invoice): array => [
                    $invoice->getKey() => $invoice->lock_version,
                ])->all(),
                $this->documentContext(),
            );
        } finally {
            $recording = false;
        }

        return $queries;
    }

    /**
     * @param  array<int, string>  $queries
     * @param  callable(string): bool  $predicate
     */
    private function countQueriesMatching(array $queries, callable $predicate): int
    {
        return count(array_filter($queries, $predicate));
    }

    private function normalizeSql(string $sql): string
    {
        $withoutIdentifierQuotes = str_replace(['`', '"', '[', ']'], '', $sql);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $withoutIdentifierQuotes)));
    }

    /** @param array<int, mixed> $lockVersions */
    private function deleteSelection(array $lockVersions): string
    {
        return json_encode((object) $lockVersions, JSON_THROW_ON_ERROR);
    }
}
