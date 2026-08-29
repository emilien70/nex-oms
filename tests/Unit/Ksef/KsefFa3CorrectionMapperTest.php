<?php

namespace Tests\Unit\Ksef;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Ksef\Services\Fa3\KsefFa3CorrectionMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class KsefFa3CorrectionMapperTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    public function test_it_maps_the_basic_correction_contract_from_local_snapshots(): void
    {
        $root = $this->rootInvoice();
        $before = $this->lineSnapshot(position: 1);
        $after = $this->lineSnapshot(position: 1, overrides: [
            'quantity' => '2.0000',
            'total_net' => '200.00',
            'total_vat' => '46.00',
            'total_gross' => '246.00',
        ]);
        $correction = $this->correction($root, [[$before, $after]], reason: '  Czytelny powód Korekty  ');

        $data = app(KsefFa3CorrectionMapper::class)->map($correction);

        $this->assertSame('KOR', $data->kind);
        $this->assertSame('Czytelny powód Korekty', $data->reason);
        $this->assertNull($data->type);
        $this->assertSame($root->getKey(), $data->rootInvoice->invoiceId);
        $this->assertSame('FV/100/2026', $data->rootInvoice->number);
        $this->assertSame('2026-08-20', $data->rootInvoice->localIssueDate);
        $this->assertCount(1, $data->changedLines);
        $this->assertSame(1, $data->changedLines[0]->logicalPosition);
        $this->assertSame('1.0000', $data->changedLines[0]->before['quantity']);
        $this->assertSame('2.0000', $data->changedLines[0]->after['quantity']);
        $this->assertSame('0.00', $data->differenceTotals['gross']);
        $this->assertSame([], $data->differenceTotals['taxSummary']);
    }

    public function test_reason_is_the_saved_label_and_is_not_reinterpreted_as_an_enum_value(): void
    {
        $correction = $this->correction(
            $this->rootInvoice(),
            [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmieniona nazwa'])]],
            reason: CorrectionReason::GoodsReturn->label(),
        );

        $data = app(KsefFa3CorrectionMapper::class)->map($correction);

        $this->assertSame(CorrectionReason::GoodsReturn->label(), $data->reason);
        $this->assertNotSame(CorrectionReason::GoodsReturn->value, $data->reason);
    }

    public function test_it_maps_only_the_single_changed_line_among_five_items(): void
    {
        $lines = [];
        for ($position = 1; $position <= 5; $position++) {
            $before = $this->lineSnapshot(position: $position, overrides: ['name' => 'Pozycja '.$position]);
            $after = $position === 3
                ? $this->lineSnapshot(position: $position, overrides: ['name' => 'Pozycja 3 po zmianie'])
                : array_reverse($before, true);
            $lines[] = [$before, $after];
        }

        $data = app(KsefFa3CorrectionMapper::class)->map($this->correction($this->rootInvoice(), $lines));

        $this->assertCount(1, $data->changedLines);
        $this->assertSame(3, $data->changedLines[0]->logicalPosition);
    }

    /**
     * @param  array<string, mixed>  $beforeOverrides
     * @param  array<string, mixed>  $afterOverrides
     */
    #[DataProvider('changedLineProvider')]
    public function test_quantity_price_vat_add_and_remove_changes_are_preserved(
        array $beforeOverrides,
        array $afterOverrides,
        string $expectedBeforeQuantity,
        string $expectedAfterQuantity,
    ): void {
        $before = $this->lineSnapshot(overrides: $beforeOverrides);
        $after = $this->lineSnapshot(overrides: $afterOverrides);

        $data = app(KsefFa3CorrectionMapper::class)->map(
            $this->correction($this->rootInvoice(), [[$before, $after]]),
        );

        $this->assertCount(1, $data->changedLines);
        $this->assertSame($expectedBeforeQuantity, $data->changedLines[0]->before['quantity']);
        $this->assertSame($expectedAfterQuantity, $data->changedLines[0]->after['quantity']);
        $this->assertSame($before['vat_rate'], $data->changedLines[0]->before['vat_rate']);
        $this->assertSame($after['vat_rate'], $data->changedLines[0]->after['vat_rate']);
        $this->assertSame($before['vat_code'], $data->changedLines[0]->before['vat_code']);
        $this->assertSame($after['vat_code'], $data->changedLines[0]->after['vat_code']);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, string, string}> */
    public static function changedLineProvider(): array
    {
        return [
            'quantity' => [
                ['quantity' => '3.0000', 'total_net' => '300.00', 'total_vat' => '69.00', 'total_gross' => '369.00'],
                ['quantity' => '2.0000', 'total_net' => '200.00', 'total_vat' => '46.00', 'total_gross' => '246.00'],
                '3.0000',
                '2.0000',
            ],
            'price' => [
                [],
                ['unit_price_net' => '90.0000', 'unit_price_gross' => '110.7000', 'total_net' => '90.00', 'total_vat' => '20.70', 'total_gross' => '110.70'],
                '1.0000',
                '1.0000',
            ],
            'total value' => [
                [],
                ['total_net' => '99.00', 'total_vat' => '24.00'],
                '1.0000',
                '1.0000',
            ],
            'vat rate' => [
                [],
                ['unit_price_net' => '113.8900', 'vat_rate' => '8.00', 'total_net' => '113.89', 'total_vat' => '9.11'],
                '1.0000',
                '1.0000',
            ],
            'vat code' => [
                [],
                ['vat_rate' => null, 'vat_code' => 'ZW', 'total_net' => '123.00', 'total_vat' => '0.00'],
                '1.0000',
                '1.0000',
            ],
            'added line' => [
                ['quantity' => '0.0000', 'total_net' => '0.00', 'total_vat' => '0.00', 'total_gross' => '0.00'],
                [],
                '0.0000',
                '1.0000',
            ],
            'removed line' => [
                [],
                ['quantity' => '0.0000', 'total_net' => '0.00', 'total_vat' => '0.00', 'total_gross' => '0.00'],
                '1.0000',
                '0.0000',
            ],
        ];
    }

    public function test_equivalent_decimal_and_key_representations_do_not_create_a_changed_line(): void
    {
        $before = $this->lineSnapshot(overrides: [
            'quantity' => '1',
            'unit_price_net' => '100',
            'unit_price_gross' => '123',
            'total_net' => '100.0',
            'total_vat' => '23.0',
            'total_gross' => '123.0',
            'vat_rate' => '23',
        ]);
        $after = array_reverse($this->lineSnapshot(), true);

        $data = app(KsefFa3CorrectionMapper::class)->map(
            $this->correction($this->rootInvoice(), [[$before, $after]]),
        );

        $this->assertSame([], $data->changedLines);
    }

    public function test_buyer_change_maps_raw_before_after_and_a_deterministic_link(): void
    {
        $root = $this->rootInvoice();
        $before = $root->buyer_snapshot;
        $after = [
            ...$before,
            'company_name' => 'Nowa Firma sp. z o.o.',
            'tax_id' => '9876543210',
            'city' => 'Kraków',
        ];

        $data = app(KsefFa3CorrectionMapper::class)->map(
            $this->correction($root, [], buyerBefore: $before, buyerAfter: $after),
        );

        $this->assertSame($before, $data->buyerBefore);
        $this->assertSame($after, $data->buyerAfter);
        $this->assertSame('NB/01', $data->buyerLinkId);
        $this->assertSame([], $data->changedLines);
        $this->assertSame('0.00', $data->differenceTotals['net']);
        $this->assertSame('0.00', $data->differenceTotals['vat']);
        $this->assertSame('0.00', $data->differenceTotals['gross']);
    }

    public function test_unchanged_buyer_is_omitted_despite_existing_before_snapshot_and_stale_semantics(): void
    {
        $root = $this->rootInvoice();
        $before = [
            ...$root->buyer_snapshot,
            'name' => '',
            'tax_identity' => ['status' => 'old'],
        ];
        $after = [
            'subject_flags' => ['jst' => true],
            ...array_reverse($root->buyer_snapshot, true),
            'name' => null,
            'tax_identity' => ['status' => 'different'],
        ];

        $data = app(KsefFa3CorrectionMapper::class)->map(
            $this->correction(
                $root,
                [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmiana pozycji'])]],
                buyerBefore: $before,
                buyerAfter: $after,
            ),
        );

        $this->assertNull($data->buyerBefore);
        $this->assertNull($data->buyerLinkId);
        $this->assertSame($after, $data->buyerAfter);
    }

    public function test_second_correction_keeps_the_original_invoice_as_root_and_uses_saved_effective_before(): void
    {
        $root = $this->rootInvoice();
        $firstAfter = $this->lineSnapshot(overrides: ['quantity' => '2.0000']);
        $first = $this->correction(
            $root,
            [[$this->lineSnapshot(), $firstAfter]],
        );
        $first->forceFill(['finalized_at' => '2026-08-21 10:00:00'])->saveQuietly();
        $secondAfter = $this->lineSnapshot(overrides: ['quantity' => '3.0000']);
        $second = $this->correction(
            $root,
            [[$firstAfter, $secondAfter]],
            previousCorrection: $first,
            number: 'KOR/2/2026',
        );

        $data = app(KsefFa3CorrectionMapper::class)->map($second);

        $this->assertSame($root->getKey(), $data->rootInvoice->invoiceId);
        $this->assertNotSame($first->getKey(), $data->rootInvoice->invoiceId);
        $this->assertSame($first->getKey(), $second->previous_correction_id);
        $this->assertSame('2.0000', $data->changedLines[0]->before['quantity']);
    }

    public function test_difference_totals_are_mapped_instead_of_after_totals(): void
    {
        $correction = $this->correction(
            $this->rootInvoice(),
            [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['total_gross' => '100.00'])]],
            difference: [
                'net' => '-18.70',
                'vat' => '-4.30',
                'gross' => '-23.00',
                'tax_summary_snapshot' => [[
                    'vat_rate' => '23.00',
                    'vat_code' => null,
                    'net' => '-18.70',
                    'vat' => '-4.30',
                    'gross' => '-23.00',
                ]],
            ],
        );

        $data = app(KsefFa3CorrectionMapper::class)->map($correction);

        $this->assertSame('-18.70', $data->differenceTotals['net']);
        $this->assertSame('-4.30', $data->differenceTotals['vat']);
        $this->assertSame('-23.00', $data->differenceTotals['gross']);
        $this->assertSame('-23.00', $data->differenceTotals['taxSummary'][0]['gross']);
    }

    public function test_inconsistent_difference_totals_fail_closed(): void
    {
        $correction = $this->correction(
            $this->rootInvoice(),
            [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmiana'])]],
        );
        $snapshot = $correction->correction_totals_snapshot;
        $snapshot['difference']['gross'] = '-0.01';
        $correction->forceFill(['correction_totals_snapshot' => $snapshot])->saveQuietly();

        $this->assertDomainError(
            'ksef_fa3_correction_totals_invalid',
            fn () => app(KsefFa3CorrectionMapper::class)->map($correction->refresh()),
        );
    }

    public function test_non_correction_document_fails_closed(): void
    {
        $this->assertDomainError(
            'ksef_fa3_correction_document_invalid',
            fn () => app(KsefFa3CorrectionMapper::class)->map($this->rootInvoice()),
        );
    }

    public function test_missing_root_relation_fails_closed_without_fallback(): void
    {
        $root = $this->rootInvoice();
        $correction = $this->correction(
            $root,
            [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmiana'])]],
        );
        $correction->forceFill([
            'corrected_invoice_id' => null,
            'previous_correction_id' => $root->getKey(),
        ])->saveQuietly();

        $this->assertDomainError(
            'ksef_fa3_correction_source_invalid',
            fn () => app(KsefFa3CorrectionMapper::class)->map($correction->refresh()),
        );
    }

    public function test_invalid_root_status_number_date_and_order_fail_closed(): void
    {
        foreach (['status', 'number', 'issue_date', 'order'] as $invalidField) {
            $root = $this->rootInvoice();
            $correction = $this->correction(
                $root,
                [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmiana'])]],
            );

            match ($invalidField) {
                'status' => $root->forceFill(['status' => InvoiceDocumentStatus::Draft])->saveQuietly(),
                'number' => $root->forceFill(['number' => null])->saveQuietly(),
                'issue_date' => $root->forceFill(['issue_date' => null])->saveQuietly(),
                'order' => $correction->forceFill([
                    'order_id' => $this->createDocumentOrder(['external_id' => 'OTHER-'.$root->getKey()])->getKey(),
                ])->saveQuietly(),
            };
            $correction->unsetRelation('correctedInvoice');

            $this->assertDomainError(
                'ksef_fa3_correction_source_invalid',
                fn () => app(KsefFa3CorrectionMapper::class)->map($correction->refresh()),
            );
        }
    }

    public function test_empty_reason_fails_closed(): void
    {
        $correction = $this->correction(
            $this->rootInvoice(),
            [[$this->lineSnapshot(), $this->lineSnapshot(overrides: ['name' => 'Zmiana'])]],
        );
        $correction->forceFill(['correction_reason' => '   '])->saveQuietly();

        $this->assertDomainError(
            'ksef_fa3_correction_reason_missing',
            fn () => app(KsefFa3CorrectionMapper::class)->map($correction->refresh()),
        );
    }

    private function rootInvoice(): Invoice
    {
        $order = $this->createDocumentOrder([
            'total_gross' => '123.00',
            'paid_amount' => '0.00',
            'delivery_cost_gross' => '0.00',
        ]);
        $this->createDocumentItem($order, [
            'unit_price_gross' => '123.00',
            'total_price_gross' => '123.00',
        ]);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order,
            $this->createDocumentSeries(attributes: ['include_shipping' => false]),
            $this->documentContext('2026-08-20 10:00:00'),
        );
        $invoice->forceFill([
            'number' => 'FV/100/2026',
            'issue_date' => '2026-08-20',
        ])->saveQuietly();

        return $invoice->refresh();
    }

    /**
     * @param  array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>  $lines
     * @param  array<string, mixed>|null  $buyerBefore
     * @param  array<string, mixed>|null  $buyerAfter
     * @param  array<string, mixed>|null  $difference
     */
    private function correction(
        Invoice $root,
        array $lines,
        string $reason = 'Pomyłka na Fakturze',
        ?array $buyerBefore = null,
        ?array $buyerAfter = null,
        ?Invoice $previousCorrection = null,
        ?array $difference = null,
        string $number = 'KOR/1/2026',
    ): Invoice {
        $buyerBefore ??= $root->buyer_snapshot;
        $buyerAfter ??= $buyerBefore;
        $difference ??= [
            'net' => '0.00',
            'vat' => '0.00',
            'gross' => '0.00',
            'tax_summary_snapshot' => [],
        ];
        $correction = Invoice::query()->create([
            'order_id' => $root->order_id,
            'invoice_series_id' => $this->createDocumentSeries(InvoiceDocumentType::Correction)->getKey(),
            'document_type' => InvoiceDocumentType::Correction,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => $number,
            'issue_date' => '2026-08-21',
            'issued_at' => '2026-08-21 10:00:00',
            'corrected_invoice_id' => $root->getKey(),
            'previous_correction_id' => $previousCorrection?->getKey(),
            'correction_reason' => $reason,
            'correction_totals_snapshot' => [
                'source_invoice' => [
                    'invoice_id' => $root->getKey(),
                    'number' => $root->number,
                    'issue_date' => $root->issue_date?->toDateString(),
                ],
                'before' => [],
                'after' => [],
                'difference' => $difference,
            ],
            'buyer_snapshot' => $buyerAfter,
            'order_snapshot' => [
                ...($root->order_snapshot ?? []),
                'correction' => [
                    'previous_correction_id' => $previousCorrection?->getKey(),
                    'buyer_before' => $buyerBefore,
                ],
            ],
            'currency' => 'PLN',
            'total_net' => $difference['net'],
            'total_vat' => $difference['vat'],
            'total_gross' => $difference['gross'],
            'paid_amount' => '0.00',
            'amount_due' => '0.00',
        ]);

        foreach ($lines as [$before, $after]) {
            $this->createCorrectionItem($correction, $before, $after);
        }

        return $correction->refresh()->load(['items', 'correctedInvoice', 'previousCorrection']);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function createCorrectionItem(Invoice $correction, array $before, array $after): InvoiceItem
    {
        return $correction->items()->create([
            'line_type' => $after['line_type'],
            'position' => $after['position'],
            'name' => $after['name'],
            'description' => $after['description'],
            'unit_name' => $after['unit_name'],
            'quantity' => $after['quantity'],
            'unit_price_net' => $after['unit_price_net'],
            'unit_price_gross' => $after['unit_price_gross'],
            'total_net' => $after['total_net'],
            'total_vat' => $after['total_vat'],
            'total_gross' => $after['total_gross'],
            'vat_rate' => $after['vat_rate'],
            'vat_code' => $after['vat_code'],
            'correction_before_snapshot' => $before,
            'correction_after_snapshot' => $after,
            'correction_difference_snapshot' => ['position' => $after['position']],
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function lineSnapshot(int $position = 1, array $overrides = []): array
    {
        return array_replace([
            'line_type' => 'product',
            'position' => $position,
            'name' => 'Pozycja testowa',
            'description' => null,
            'unit_name' => 'szt.',
            'quantity' => '1.0000',
            'unit_price_net' => '100.0000',
            'unit_price_gross' => '123.0000',
            'total_net' => '100.00',
            'total_vat' => '23.00',
            'total_gross' => '123.00',
            'vat_rate' => '23.00',
            'vat_code' => null,
        ], $overrides);
    }

    private function assertDomainError(string $expectedCode, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected InvoiceDomainException was not thrown.');
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode());
        }
    }
}
