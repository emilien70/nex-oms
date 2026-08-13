<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Models\OrderDocumentSlot;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceDeletionService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceFinalizationService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\ProformaService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class InvoiceFinalizationTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_invoice_finalization_is_idempotent_and_proforma_is_rejected(): void
    {
        Storage::fake('local');

        $invoice = $this->issuedInvoice();
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        Storage::disk('local')->put($path, '%PDF-test');
        $lockVersion = $invoice->lock_version;
        $eventCount = $invoice->order->events()->count();

        $first = app(InvoiceFinalizationService::class)->finalize($invoice);
        $firstTimestamp = $first->finalized_at?->toISOString();
        $second = app(InvoiceFinalizationService::class)->finalize($first);

        $this->assertNotNull($firstTimestamp);
        $this->assertSame($firstTimestamp, $second->finalized_at?->toISOString());
        $this->assertSame($lockVersion, $second->lock_version);
        $this->assertSame($eventCount, $invoice->order->events()->count());
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Invoice->value,
            'invoice_id' => $invoice->getKey(),
        ]);
        Storage::disk('local')->assertExists($path);

        $proformaOrder = $this->createDocumentOrder(['external_id' => 'PROFORMA-FINALIZATION']);
        $this->createDocumentItem($proformaOrder);
        $proforma = app(ProformaService::class)->createOrRefresh(
            $proformaOrder,
            $this->createDocumentSeries(InvoiceDocumentType::Proforma),
            $this->documentContext(),
        )->invoice;

        $this->expectDomainError(
            'invoice_finalization_not_allowed',
            fn () => app(InvoiceFinalizationService::class)->finalize($proforma),
        );
        $this->assertNull($proforma->fresh()->finalized_at);
    }

    public function test_finalized_invoice_rejects_content_mutation_and_deletion_but_allows_first_correction(): void
    {
        $invoice = app(InvoiceFinalizationService::class)->finalize($this->issuedInvoice());
        $itemCount = $invoice->items()->count();
        $lockVersion = $invoice->lock_version;
        $buyer = $invoice->buyer_snapshot;

        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceEditService::class)->updateBuyer(
            $invoice,
            array_merge($buyer, [
                'expected_lock_version' => $lockVersion,
                'name' => 'Changed buyer',
            ]),
        ));
        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceEditService::class)->addItem(
            $invoice,
            ['expected_lock_version' => $lockVersion],
        ));
        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceEditService::class)->copyItemsFromOrder(
            $invoice,
            $lockVersion,
        ));
        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceDeletionService::class)->delete(
            $invoice,
            $lockVersion,
            $this->documentContext(),
        ));

        $fresh = $invoice->fresh();
        $this->assertSame($lockVersion, $fresh->lock_version);
        $this->assertSame($buyer, $fresh->buyer_snapshot);
        $this->assertSame($itemCount, $fresh->items()->count());

        $correction = $this->issueBuyerCorrection(
            $fresh,
            $this->correctionSeries(),
            'Buyer after finalization',
        );

        $this->assertSame($fresh->getKey(), $correction->corrected_invoice_id);
        $this->assertNull($correction->previous_correction_id);
        $this->assertNull($correction->finalized_at);
    }

    public function test_three_corrections_form_a_linear_chain_and_use_effective_buyer_state(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->correctionSeries();

        $first = $this->issueBuyerCorrection($invoice, $series, 'Buyer B', '2026-08-05 10:00:00');
        $first = app(InvoiceFinalizationService::class)->finalize($first);
        $firstFinalizedAt = $first->finalized_at?->toISOString();
        $firstAgain = app(InvoiceFinalizationService::class)->finalize($first);
        $this->assertSame($firstFinalizedAt, $firstAgain->finalized_at?->toISOString());
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);

        $second = $this->issueBuyerCorrection($invoice, $series, 'Buyer C', '2026-08-05 11:00:00');
        app(InvoiceFinalizationService::class)->finalize($second);
        $third = $this->issueBuyerCorrection($invoice, $series, 'Buyer D', '2026-08-05 12:00:00');

        $this->assertNull($first->previous_correction_id);
        $this->assertSame($first->getKey(), $second->previous_correction_id);
        $this->assertSame($second->getKey(), $third->previous_correction_id);
        $this->assertSame($invoice->getKey(), $first->corrected_invoice_id);
        $this->assertSame($invoice->getKey(), $second->corrected_invoice_id);
        $this->assertSame($invoice->getKey(), $third->corrected_invoice_id);
        $this->assertSame('Buyer B', data_get($second->order_snapshot, 'correction.buyer_before.name'));
        $this->assertSame('Buyer C', data_get($third->order_snapshot, 'correction.buyer_before.name'));

        $state = app(CorrectionSourceStateService::class)->chain($invoice);
        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            $state->finalizedCorrections->pluck('id')->all(),
        );
        $this->assertTrue($state->finalizedTail?->is($second));
        $this->assertTrue($state->currentCorrection?->is($third));
        $this->assertTrue($state->effectiveSourceDocument->is($second));
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $third->getKey(),
        ]);

        $this->get(route('invoices.corrections.edit', $first))
            ->assertOk()
            ->assertSee('data-finalized-correction-notice', false)
            ->assertDontSee('data-bs-target="#correctionDeleteModal"', false)
            ->assertSee('data-correction-print-button', false);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee(route('invoices.corrections.edit', $third), false)
            ->assertDontSee(route('invoices.corrections.create', $invoice), false);
    }

    public function test_second_item_correction_uses_the_first_correction_after_state_as_before(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->correctionSeries();

        $first = $this->issueItemCorrection($invoice, $series, 0, '2026-08-05 10:00:00');
        app(InvoiceFinalizationService::class)->finalize($first);
        $second = $this->issueItemCorrection($invoice, $series, 1, '2026-08-05 11:00:00');

        $firstProduct = $first->items->firstWhere('line_type.value', 'product')
            ?? $first->items->firstWhere('line_type', 'product');
        $secondProduct = $second->items->firstWhere('line_type.value', 'product')
            ?? $second->items->firstWhere('line_type', 'product');

        $this->assertNotNull($firstProduct);
        $this->assertNotNull($secondProduct);
        $this->assertSame('0.0000', (string) data_get($firstProduct->correction_after_snapshot, 'quantity'));
        $this->assertSame('0.0000', (string) data_get($secondProduct->correction_before_snapshot, 'quantity'));
        $this->assertSame('1.0000', (string) data_get($secondProduct->correction_after_snapshot, 'quantity'));
        $this->assertSame('1.0000', (string) data_get($secondProduct->correction_difference_snapshot, 'quantity'));
    }

    public function test_stale_effective_source_document_is_rejected_without_side_effects(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $first = $this->issueBuyerCorrection($invoice, $series, 'Buyer B', '2026-08-05 10:00:00');
        app(InvoiceFinalizationService::class)->finalize($first);
        $staleId = $first->getKey();
        $staleLock = $first->lock_version;

        $second = $this->issueBuyerCorrection($invoice, $series, 'Buyer C', '2026-08-05 11:00:00');
        app(InvoiceFinalizationService::class)->finalize($second);
        $documentCount = Invoice::query()->count();
        $eventCount = $invoice->order->events()->where('event_type', 'correction_issued')->count();

        $this->expectDomainError('correction_source_changed', fn () => app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $staleId,
            $staleLock,
            $this->correctionPayload(
                buyer: array_merge($second->buyer_snapshot, ['name' => 'Stale buyer']),
            ),
            $this->documentContext('2026-08-05 12:00:00'),
        ));

        $this->assertSame($documentCount, Invoice::query()->count());
        $this->assertSame($eventCount, $invoice->order->events()->where('event_type', 'correction_issued')->count());
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);

        $third = $this->issueBuyerCorrection($invoice, $series, 'Buyer D', '2026-08-05 13:00:00');
        $this->assertSame($second->getKey(), $third->previous_correction_id);
        $this->assertSame($second->sequence_number + 1, $third->sequence_number);
    }

    public function test_vat_identity_chain_uses_the_previous_finalized_after_state(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $first = $this->issueVatCodeCorrection($invoice, $series, 'ZW', '2026-08-05 10:00:00');
        app(InvoiceFinalizationService::class)->finalize($first);
        $second = $this->issueVatCodeCorrection($invoice, $series, 'NP', '2026-08-05 11:00:00');
        $product = $second->items->first(
            static fn ($item): bool => $item->line_type->value === 'product',
        );

        $this->assertNotNull($product);
        $this->assertSame('ZW', data_get($product->correction_before_snapshot, 'vat_code'));
        $this->assertNull(data_get($product->correction_before_snapshot, 'vat_rate'));
        $this->assertSame('NP', data_get($product->correction_after_snapshot, 'vat_code'));
        $this->assertNull(data_get($product->correction_after_snapshot, 'vat_rate'));
    }

    public function test_finalization_rejects_an_inconsistent_slot_and_rolls_back(): void
    {
        $invoice = $this->issuedInvoice();
        $correction = $this->issueBuyerCorrection($invoice, $this->correctionSeries(), 'Buyer B');
        $slot = OrderDocumentSlot::query()
            ->where('order_id', $invoice->order_id)
            ->where('document_type', InvoiceDocumentType::Correction)
            ->firstOrFail();
        $slot->update(['invoice_id' => $invoice->getKey()]);

        $this->expectDomainError(
            'correction_document_slot_inconsistent',
            fn () => app(InvoiceFinalizationService::class)->finalize($correction),
        );

        $this->assertNull($correction->fresh()->finalized_at);
        $this->assertDatabaseHas('order_document_slots', [
            'id' => $slot->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    public function test_current_correction_can_be_deleted_after_finalized_history_and_next_uses_the_tail(): void
    {
        $invoice = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $first = $this->issueBuyerCorrection($invoice, $series, 'Buyer B', '2026-08-05 10:00:00');
        app(InvoiceFinalizationService::class)->finalize($first);

        $this->expectDomainError('invoice_delete_blocked_by_correction', fn () => app(InvoiceDeletionService::class)->delete(
            $invoice,
            $invoice->lock_version,
            $this->documentContext('2026-08-05 10:30:00'),
        ));
        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Wystaw kolejną Korektę')
            ->assertSee(route('invoices.corrections.create', $invoice), false);

        $second = $this->issueBuyerCorrection($invoice, $series, 'Buyer C', '2026-08-05 11:00:00');

        app(InvoiceDeletionService::class)->delete(
            $second,
            $second->lock_version,
            $this->documentContext('2026-08-05 11:30:00'),
        );

        $this->assertDatabaseHas('invoices', [
            'id' => $first->getKey(),
            'finalized_at' => $first->fresh()->finalized_at,
        ]);
        $this->assertDatabaseMissing('invoices', ['id' => $second->getKey()]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $invoice->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);

        $replacement = $this->issueBuyerCorrection($invoice, $series, 'Buyer D', '2026-08-05 12:00:00');
        $this->assertSame($first->getKey(), $replacement->previous_correction_id);
        $this->assertSame('Buyer B', data_get($replacement->order_snapshot, 'correction.buyer_before.name'));
    }

    public function test_finalized_correction_blocks_single_and_atomic_bulk_deletion_and_update(): void
    {
        Storage::fake('local');

        $firstInvoice = $this->issuedInvoice('FINALIZED-DELETE-1');
        $secondInvoice = $this->issuedInvoice('FINALIZED-DELETE-2');
        $series = $this->correctionSeries();
        $finalized = $this->issueBuyerCorrection($firstInvoice, $series, 'Buyer B');
        app(InvoiceFinalizationService::class)->finalize($finalized);
        $current = $this->issueBuyerCorrection($secondInvoice, $series, 'Buyer C');
        $lockVersion = $finalized->lock_version;
        $items = $finalized->items->map->getAttributes()->all();
        $path = app(InvoicePdfFilenameGenerator::class)->storagePath($finalized);
        Storage::disk('local')->put($path, '%PDF-test');

        $this->expectDomainError('correction_finalized', fn () => app(CorrectionService::class)->update(
            $finalized,
            ['expected_lock_version' => $lockVersion],
        ));
        $this->expectDomainError('correction_finalized', fn () => app(InvoiceDeletionService::class)->delete(
            $finalized,
            $lockVersion,
            $this->documentContext(),
        ));
        $this->expectDomainError('correction_finalized', fn () => app(InvoiceDeletionService::class)->deleteMany(
            [
                $finalized->getKey() => $lockVersion,
                $current->getKey() => $current->lock_version,
            ],
            $this->documentContext(),
            InvoiceDocumentType::Correction,
        ));

        $this->assertSame($lockVersion, $finalized->fresh()->lock_version);
        $this->assertSame($items, $finalized->fresh()->items->map->getAttributes()->all());
        $this->assertDatabaseHas('invoices', ['id' => $finalized->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $current->getKey()]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_finalized_invoice_blocks_atomic_bulk_deletion(): void
    {
        $finalized = app(InvoiceFinalizationService::class)->finalize(
            $this->issuedInvoice('FINALIZED-INVOICE-BULK-1'),
        );
        $deletable = $this->issuedInvoice('FINALIZED-INVOICE-BULK-2');

        $this->expectDomainError('invoice_finalized', fn () => app(InvoiceDeletionService::class)->deleteMany(
            [
                $finalized->getKey() => $finalized->lock_version,
                $deletable->getKey() => $deletable->lock_version,
            ],
            $this->documentContext(),
            InvoiceDocumentType::Invoice,
        ));

        $this->assertDatabaseHas('invoices', ['id' => $finalized->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $deletable->getKey()]);
    }

    public function test_chain_with_a_previous_correction_from_another_root_is_rejected(): void
    {
        $firstInvoice = $this->issuedInvoice('CHAIN-ROOT-1');
        $secondInvoice = $this->issuedInvoice('CHAIN-ROOT-2');
        $series = $this->correctionSeries();
        $first = $this->issueBuyerCorrection($firstInvoice, $series, 'Buyer B');
        app(InvoiceFinalizationService::class)->finalize($first);
        $foreign = $this->issueBuyerCorrection($secondInvoice, $series, 'Foreign buyer');
        app(InvoiceFinalizationService::class)->finalize($foreign);
        $first->forceFill(['previous_correction_id' => $foreign->getKey()])->save();

        $this->expectDomainError(
            'correction_chain_inconsistent',
            fn () => app(CorrectionSourceStateService::class)->chain($firstInvoice),
        );

        $this->assertDatabaseHas('invoices', ['id' => $first->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $foreign->getKey()]);
    }

    public function test_chain_with_a_current_correction_before_a_finalized_tail_is_rejected(): void
    {
        $invoice = $this->issuedInvoice('CHAIN-CURRENT-NOT-TAIL');
        $series = $this->correctionSeries();
        $current = $this->issueBuyerCorrection($invoice, $series, 'Buyer B');
        $finalizedTail = $current->replicate();
        $finalizedTail->forceFill([
            'number' => 'CORRUPT-CHAIN-TAIL',
            'sequence_number' => $current->sequence_number + 100,
            'previous_correction_id' => $current->getKey(),
            'finalized_at' => now(),
        ])->save();

        $this->expectDomainError(
            'correction_chain_inconsistent',
            fn () => app(CorrectionSourceStateService::class)->chain($invoice),
        );

        $this->assertDatabaseHas('invoices', ['id' => $current->getKey()]);
        $this->assertDatabaseHas('invoices', ['id' => $finalizedTail->getKey()]);
    }

    private function issuedInvoice(string $externalId = 'FINALIZATION-ORDER'): Invoice
    {
        $order = $this->createDocumentOrder(['external_id' => $externalId]);
        $this->createDocumentItem($order);

        return app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(),
            $this->documentContext(),
        );
    }

    private function correctionSeries(): InvoiceSeries
    {
        return $this->createDocumentSeries(InvoiceDocumentType::Correction);
    }

    private function issueBuyerCorrection(
        Invoice $invoice,
        InvoiceSeries $series,
        string $buyerName,
        string $occurredAt = '2026-08-05 10:00:00',
    ): Invoice {
        $state = app(CorrectionSourceStateService::class)->chain($invoice);
        $buyer = app(CorrectionSourceStateService::class)->effectiveBuyer($invoice, false, $state);

        return app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $state->effectiveSourceDocument->getKey(),
            $state->effectiveSourceDocument->lock_version,
            $this->correctionPayload(array_merge($buyer, [
                'name' => $buyerName,
                'company_name' => null,
            ])),
            $this->documentContext($occurredAt),
        );
    }

    private function issueItemCorrection(
        Invoice $invoice,
        InvoiceSeries $series,
        int $quantity,
        string $occurredAt,
    ): Invoice {
        $state = app(CorrectionSourceStateService::class)->chain($invoice);
        $items = app(CorrectionSourceStateService::class)
            ->effectiveItems($invoice, false, $state)
            ->map(function (array $item) use ($quantity): array {
                $snapshot = $item['snapshot'];
                $lineType = $snapshot['line_type'] instanceof \BackedEnum
                    ? $snapshot['line_type']->value
                    : $snapshot['line_type'];

                return [
                    'source_item_id' => $item['source_item_id'],
                    'order_item_id' => $item['source_item']->order_item_id,
                    'line_type' => $lineType,
                    'position' => (int) $snapshot['position'],
                    'name' => $snapshot['name'],
                    'description' => $snapshot['description'],
                    'unit_name' => $snapshot['unit_name'],
                    'quantity' => $lineType === 'product' ? $quantity : (int) $snapshot['quantity'],
                    'unit_price_gross' => (string) $snapshot['unit_price_gross'],
                    'vat_rate' => $snapshot['vat_rate'],
                    'vat_code' => $snapshot['vat_code'],
                ];
            })
            ->all();

        return app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $state->effectiveSourceDocument->getKey(),
            $state->effectiveSourceDocument->lock_version,
            $this->correctionPayload(items: $items),
            $this->documentContext($occurredAt),
        );
    }

    private function issueVatCodeCorrection(
        Invoice $invoice,
        InvoiceSeries $series,
        string $vatCode,
        string $occurredAt,
    ): Invoice {
        $state = app(CorrectionSourceStateService::class)->chain($invoice);
        $items = app(CorrectionSourceStateService::class)
            ->effectiveItems($invoice, false, $state)
            ->map(function (array $item) use ($vatCode): array {
                $snapshot = $item['snapshot'];
                $lineType = $snapshot['line_type'] instanceof \BackedEnum
                    ? $snapshot['line_type']->value
                    : $snapshot['line_type'];

                return [
                    'source_item_id' => $item['source_item_id'],
                    'order_item_id' => $item['source_item']->order_item_id,
                    'line_type' => $lineType,
                    'position' => (int) $snapshot['position'],
                    'name' => $snapshot['name'],
                    'description' => $snapshot['description'],
                    'unit_name' => $snapshot['unit_name'],
                    'quantity' => (int) $snapshot['quantity'],
                    'unit_price_gross' => (string) $snapshot['unit_price_gross'],
                    'vat_rate' => $lineType === 'product' ? '23.00' : $snapshot['vat_rate'],
                    'vat_code' => $lineType === 'product' ? $vatCode : $snapshot['vat_code'],
                ];
            })
            ->all();

        return app(CorrectionService::class)->issue(
            $invoice,
            $series,
            $state->effectiveSourceDocument->getKey(),
            $state->effectiveSourceDocument->lock_version,
            $this->correctionPayload(items: $items),
            $this->documentContext($occurredAt),
        );
    }

    /** @return array<string, mixed> */
    private function correctionPayload(?array $buyer = null, ?array $items = null): array
    {
        return [
            'correction_series_id' => 1,
            'reason' => CorrectionReason::InvoiceError->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Transfer',
            'issuer_name' => 'Test issuer',
            'additional_information' => null,
            'change_items' => $items !== null,
            'change_buyer' => $buyer !== null,
            'items' => $items ?? [],
            'buyer' => $buyer ?? [],
        ];
    }

    private function expectDomainError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected InvoiceDomainException with code '.$code.'.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }
}
