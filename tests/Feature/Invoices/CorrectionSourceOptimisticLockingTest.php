<?php

namespace Tests\Feature\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Enums\InvoiceSeriesSystemKey;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\CorrectionService;
use Modules\Invoices\Services\CorrectionSourceStateService;
use Modules\Invoices\Services\InvoiceEditService;
use Modules\Invoices\Services\InvoiceIssuingService;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class CorrectionSourceOptimisticLockingTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_create_form_contains_source_lock_version_while_edit_uses_correction_lock_version(): void
    {
        $source = $this->issuedInvoice();

        $this->get(route('invoices.corrections.create', $source))
            ->assertOk()
            ->assertSee('name="expected_source_lock_version" value="1"', false);

        $correction = $this->issueBuyerCorrection($source, $source->lock_version);

        $this->get(route('invoices.corrections.edit', $correction))
            ->assertOk()
            ->assertSee('name="expected_lock_version" value="1"', false)
            ->assertDontSee('name="expected_source_lock_version"', false);
    }

    public function test_create_request_requires_source_lock_version(): void
    {
        $source = $this->issuedInvoice();
        $payload = $this->buyerCorrectionPayload($source);
        unset($payload['expected_source_lock_version']);

        $this->post(route('invoices.corrections.store', $source), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('expected_source_lock_version');

        $this->assertNoCorrectionSideEffects($source);
    }

    public function test_stale_buyer_form_is_rejected_preserves_old_token_and_does_not_consume_number(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $staleVersion = $source->lock_version;
        $createUrl = route('invoices.corrections.create', [
            'invoice' => $source,
            'series_id' => $series->getKey(),
        ]);

        app(InvoiceEditService::class)->updateBuyer($source, $this->buyerEditPayload($source, [
            'name' => 'Nabywca po edycji Faktury',
        ]));
        $this->assertSame($staleVersion + 1, $source->fresh()->lock_version);

        $stalePayload = $this->buyerCorrectionPayload($source, [
            'expected_source_lock_version' => $staleVersion,
            'buyer' => array_merge($source->buyer_snapshot, ['name' => 'Stare dane formularza']),
        ]);

        $this->from($createUrl)
            ->post(route('invoices.corrections.store', $source), $stalePayload)
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('correction');

        $this->assertNoCorrectionSideEffects($source);

        $this->get($createUrl)
            ->assertOk()
            ->assertSee('name="expected_source_lock_version" value="'.$staleVersion.'"', false)
            ->assertDontSee('name="expected_source_lock_version" value="'.($staleVersion + 1).'"', false);

        $this->get($createUrl)
            ->assertOk()
            ->assertSee('name="expected_source_lock_version" value="'.($staleVersion + 1).'"', false);

        $currentSource = $source->fresh();
        $this->post(route('invoices.corrections.store', $currentSource), $this->buyerCorrectionPayload($currentSource, [
            'buyer' => array_merge($currentSource->buyer_snapshot, ['name' => 'Poprawna Korekta']),
        ]))->assertRedirect();

        $correction = Invoice::query()
            ->where('document_type', InvoiceDocumentType::Correction)
            ->sole();

        $this->assertSame(1, $correction->sequence_number);
        $this->assertDatabaseHas('order_document_slots', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
            'invoice_id' => $correction->getKey(),
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $source->order_id,
            'event_type' => 'correction_issued',
        ]);
    }

    public function test_stale_item_form_is_rejected_after_real_invoice_money_edit(): void
    {
        $source = $this->issuedInvoice();
        $series = $this->correctionSeries();
        $staleVersion = $source->lock_version;
        $staleItems = $this->submittedItems($source);
        $staleItems[0]['quantity'] = 0;
        $sourceItem = $source->items()->where('line_type', 'product')->firstOrFail();

        app(InvoiceEditService::class)->updateItem($source, $sourceItem, [
            'expected_lock_version' => $staleVersion,
            'name' => $sourceItem->name,
            'description' => $sourceItem->description,
            'unit_name' => $sourceItem->unit_name,
            'quantity' => '1',
            'unit_price_gross' => '80.00',
            'vat_rate' => '23.00',
            'vat_code' => $sourceItem->vat_code,
            'position' => $sourceItem->position,
        ]);

        try {
            app(CorrectionService::class)->issue(
                $source,
                $series,
                $staleVersion,
                $this->itemCorrectionPayload($source, $staleItems),
                $this->documentContext('2026-08-05 10:00:00'),
            );
            $this->fail('Korekta ze starego formularza pozycji nie powinna zostać wystawiona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_source_changed', $exception->errorCode());
            $this->assertSame(
                'Korygowana Faktura została w międzyczasie zmieniona. Odśwież formularz Korekty i ponownie sprawdź dane.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($staleVersion + 1, $source->fresh()->lock_version);
        $this->assertNoCorrectionSideEffects($source);
    }

    public function test_future_source_lock_version_is_rejected_as_source_conflict(): void
    {
        $source = $this->issuedInvoice();

        try {
            app(CorrectionService::class)->issue(
                $source,
                $this->correctionSeries(),
                $source->lock_version + 1,
                $this->buyerCorrectionPayload($source),
                $this->documentContext('2026-08-05 10:00:00'),
            );
            $this->fail('Korekta z przyszłą wersją źródła nie powinna zostać wystawiona.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame('correction_source_changed', $exception->errorCode());
        }

        $this->assertNoCorrectionSideEffects($source);
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

    private function correctionSeries(): InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('system_key', InvoiceSeriesSystemKey::Correction)
            ->firstOrFail();
    }

    private function issueBuyerCorrection(Invoice $source, int $expectedSourceLockVersion): Invoice
    {
        return app(CorrectionService::class)->issue(
            $source,
            $this->correctionSeries(),
            $expectedSourceLockVersion,
            $this->buyerCorrectionPayload($source),
            $this->documentContext('2026-08-05 10:00:00'),
        );
    }

    /** @return array<string, mixed> */
    private function buyerCorrectionPayload(Invoice $source, array $overrides = []): array
    {
        return array_replace_recursive([
            'expected_source_lock_version' => $source->lock_version,
            'correction_series_id' => $this->correctionSeries()->getKey(),
            'reason' => CorrectionReason::BuyerDataUpdate->value,
            'other_reason' => null,
            'issue_date' => '2026-08-05',
            'sale_date' => '2026-07-20',
            'payment_method' => 'Przelew',
            'issuer_name' => 'Tester korekty',
            'additional_information' => null,
            'change_items' => false,
            'change_buyer' => true,
            'items' => [],
            'buyer' => array_merge($source->buyer_snapshot, [
                'name' => 'Nabywca po korekcie',
                'company_name' => null,
            ]),
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function itemCorrectionPayload(Invoice $source, array $items): array
    {
        return array_replace($this->buyerCorrectionPayload($source), [
            'reason' => CorrectionReason::InvoiceError->value,
            'change_items' => true,
            'change_buyer' => false,
            'items' => $items,
            'buyer' => [],
        ]);
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

    /** @return array<string, mixed> */
    private function buyerEditPayload(Invoice $source, array $changes = []): array
    {
        $buyer = $source->buyer_snapshot;

        return array_merge([
            'expected_lock_version' => $source->lock_version,
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
        ], $changes);
    }

    private function assertNoCorrectionSideEffects(Invoice $source): void
    {
        $this->assertDatabaseMissing('invoices', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
        $this->assertDatabaseMissing('order_document_slots', [
            'order_id' => $source->order_id,
            'document_type' => InvoiceDocumentType::Correction->value,
        ]);
        $this->assertDatabaseMissing('invoice_number_counters', [
            'invoice_series_id' => $this->correctionSeries()->getKey(),
        ]);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $source->order_id,
            'event_type' => 'correction_issued',
        ]);
    }

    private function twoDecimals(mixed $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
