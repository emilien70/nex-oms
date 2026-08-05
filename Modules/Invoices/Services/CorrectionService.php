<?php

namespace Modules\Invoices\Services;

use App\Support\CountryCatalog;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Invoices\Enums\CorrectionIssuerSource;
use Modules\Invoices\Enums\CorrectionReason;
use Modules\Invoices\Enums\CorrectionSaleDateSource;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\ValueObjects\InvoiceOperationContext;

class CorrectionService
{
    public function __construct(
        private readonly CorrectionSourceStateService $sourceState,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly InvoiceNumberingService $numbering,
        private readonly CountryCatalog $countries,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(
        Invoice $sourceInvoice,
        InvoiceSeries $series,
        array $data,
        InvoiceOperationContext $context,
    ): Invoice {
        $this->sourceState->assertSourceInvoice($sourceInvoice);
        $this->assertSeries($series);

        return DB::transaction(function () use ($sourceInvoice, $series, $data, $context): Invoice {
            $source = Invoice::query()->lockForUpdate()->findOrFail($sourceInvoice->getKey());
            $managedSeries = InvoiceSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $this->sourceState->assertSourceInvoice($source);
            $this->assertSeries($managedSeries);

            $previousCorrection = $this->sourceState->latestIssuedCorrection($source, true);
            $effective = $previousCorrection ?? $source;
            $effectiveItems = $this->sourceState->effectiveItems($source, true);
            $buyerBefore = $this->sourceState->effectiveBuyer($source, true);
            $buyerAfter = $data['change_buyer']
                ? $this->buyerAfter($buyerBefore, (array) ($data['buyer'] ?? []))
                : $buyerBefore;
            $itemAttributes = $this->correctionItems(
                $effectiveItems->all(),
                (array) ($data['items'] ?? []),
                (bool) $data['change_items'],
                $source,
            );
            $beforeTotals = $this->documentTotals($itemAttributes, 'correction_before_snapshot');
            $afterTotals = $this->documentTotals($itemAttributes, 'correction_after_snapshot');
            $differenceTotals = $this->differenceTotals($beforeTotals, $afterTotals);

            if (! $this->hasActualChange($itemAttributes, $buyerBefore, $buyerAfter)) {
                throw new InvoiceDomainException(
                    'correction_has_no_changes',
                    'Nie można wystawić Korekty bez wskazania rzeczywistej zmiany.',
                );
            }

            $issueDate = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $data['issue_date'],
                config('app.timezone'),
            );
            $reason = $this->reason($data);
            $issuer = $this->issuerSnapshot($source, $managedSeries, $data);
            $payment = $this->paymentSnapshot($effective, $managedSeries, $data);
            $orderSnapshot = $this->orderSnapshot($source, $previousCorrection, $buyerBefore);
            $settings = $this->seriesSettingsSnapshot($source, $managedSeries);
            $buyerName = $this->partyName($buyerAfter);

            $correction = Invoice::query()->create([
                'order_id' => $source->order_id,
                'invoice_series_id' => $managedSeries->getKey(),
                'document_type' => InvoiceDocumentType::Correction,
                'status' => InvoiceDocumentStatus::Draft,
                'issue_date' => $issueDate->toDateString(),
                'sale_date' => $this->saleDate($source, $managedSeries, $data),
                'payment_due_date' => null,
                'issued_at' => null,
                'lock_version' => 1,
                'corrected_invoice_id' => $source->getKey(),
                'previous_correction_id' => $previousCorrection?->getKey(),
                'correction_reason' => $reason,
                'correction_totals_snapshot' => [
                    'source_invoice' => [
                        'invoice_id' => $source->getKey(),
                        'number' => $source->number,
                        'issue_date' => $source->issue_date?->toDateString(),
                    ],
                    'before' => $this->compactTotals($beforeTotals),
                    'after' => $this->compactTotals($afterTotals),
                    'difference' => $differenceTotals,
                ],
                'order_reference_snapshot' => $source->order_reference_snapshot,
                'seller_name_snapshot' => $source->seller_name_snapshot,
                'seller_tax_id_snapshot' => $source->seller_tax_id_snapshot,
                'buyer_name_snapshot' => $buyerName,
                'buyer_tax_id_snapshot' => $buyerAfter['tax_id'] ?? null,
                'recipient_name_snapshot' => $source->recipient_name_snapshot,
                'seller_snapshot' => $source->seller_snapshot,
                'buyer_snapshot' => $buyerAfter,
                'recipient_snapshot' => $source->recipient_snapshot,
                'issuer_snapshot' => $issuer,
                'order_snapshot' => $orderSnapshot,
                'payment_snapshot' => $payment,
                'shipping_snapshot' => $effective->shipping_snapshot,
                'series_settings_snapshot' => $settings,
                'tax_summary_snapshot' => $afterTotals['tax_summary_snapshot'],
                'tax_metadata_snapshot' => $effective->tax_metadata_snapshot,
                'additional_information_text' => trim((string) ($data['additional_information'] ?? '')),
                'currency' => $source->currency,
                'total_net' => $differenceTotals['net'],
                'total_vat' => $differenceTotals['vat'],
                'total_gross' => $differenceTotals['gross'],
                'paid_amount' => '0.00',
                'amount_due' => $this->decimal->max($differenceTotals['gross'], '0.00'),
            ]);

            $correction->items()->createMany($itemAttributes);

            try {
                $correction = $this->numbering->assignNextNumber($correction, $issueDate);
            } catch (DomainException $exception) {
                throw new InvoiceDomainException(
                    'correction_numbering_failed',
                    'Nie udało się nadać numeru Korekty.',
                    ['reason' => $exception->getMessage()],
                    $exception,
                );
            }

            $correction->status = InvoiceDocumentStatus::Issued;
            $correction->issued_at = $context->occurredAt;
            $correction->save();

            $event = $source->order->events()->make([
                'event_type' => 'correction_issued',
                'title' => 'Wystawiono korektę',
                'description' => 'Wystawiono korektę do Faktury VAT.',
                'payload' => [
                    'invoice_id' => $source->getKey(),
                    'invoice_number' => $source->number,
                    'correction_id' => $correction->getKey(),
                    'correction_number' => $correction->number,
                    'previous_correction_id' => $previousCorrection?->getKey(),
                    'reason' => $reason,
                    'difference_gross' => $differenceTotals['gross'],
                    'currency' => $correction->currency,
                    'source' => $context->source->value,
                ],
            ]);
            $event->created_at = $context->occurredAt;
            $event->updated_at = $context->occurredAt;
            $event->save();

            return $correction->refresh()->load(['items', 'correctedInvoice', 'previousCorrection']);
        }, 3);
    }

    private function assertSeries(InvoiceSeries $series): void
    {
        if (! $series->is_active || $series->document_type !== InvoiceDocumentType::Correction) {
            throw new InvoiceDomainException(
                'correction_series_invalid',
                'Wybrana seria numeracji nie może zostać użyta do wystawienia Korekty.',
            );
        }
    }

    /**
     * @param  array<int, array{source_item_id: int, source_item: mixed, snapshot: array<string, mixed>}>  $effectiveItems
     * @param  array<int, array<string, mixed>>  $submittedItems
     * @return array<int, array<string, mixed>>
     */
    private function correctionItems(
        array $effectiveItems,
        array $submittedItems,
        bool $changeItems,
        Invoice $sourceInvoice,
    ): array {
        $submittedBySource = [];
        $newItems = [];

        foreach ($submittedItems as $item) {
            $sourceItemId = (int) ($item['source_item_id'] ?? 0);
            if ($sourceItemId > 0) {
                $submittedBySource[$sourceItemId] = $item;
            } else {
                $newItems[] = $item;
            }
        }

        $result = [];
        $allowedSourceIds = [];

        foreach ($effectiveItems as $effectiveItem) {
            $sourceItemId = $effectiveItem['source_item_id'];
            $allowedSourceIds[] = $sourceItemId;
            $before = $this->normalizeSnapshot($effectiveItem['snapshot']);
            $submitted = $submittedBySource[$sourceItemId] ?? null;

            if (! $changeItems) {
                $after = $before;
            } elseif ($submitted === null) {
                $after = $this->zeroQuantity($before);
            } else {
                $after = $this->afterSnapshot($submitted);
            }

            $result[] = $this->itemAttributes(
                $effectiveItem['source_item'],
                $sourceItemId,
                $before,
                $after,
            );
        }

        foreach (array_keys($submittedBySource) as $sourceItemId) {
            if (! in_array($sourceItemId, $allowedSourceIds, true)) {
                throw new InvoiceDomainException(
                    'correction_item_source_invalid',
                    'Jedna z pozycji nie należy do skutecznego stanu korygowanego dokumentu.',
                );
            }
        }

        if ($changeItems) {
            foreach ($newItems as $item) {
                $after = $this->afterSnapshot($item);
                $before = $this->zeroQuantity($after);
                $orderItemId = isset($item['order_item_id']) ? (int) $item['order_item_id'] : null;

                if ($orderItemId !== null && $orderItemId > 0
                    && ! $sourceInvoice->order->items()->whereKey($orderItemId)->exists()) {
                    throw new InvoiceDomainException(
                        'correction_order_item_invalid',
                        'Jedna z kopiowanych pozycji nie należy do zamówienia dokumentu.',
                    );
                }

                $result[] = $this->itemAttributes(null, null, $before, $after, $orderItemId);
            }
        }

        foreach ($result as $index => &$item) {
            $item['position'] = $index + 1;
            $item['correction_before_snapshot']['position'] = $index + 1;
            $item['correction_after_snapshot']['position'] = $index + 1;
            $item['correction_difference_snapshot']['position'] = $index + 1;
        }
        unset($item);

        return $result;
    }

    /** @param array<string, mixed> $snapshot */
    private function normalizeSnapshot(array $snapshot): array
    {
        $gross = $this->decimal->normalize((string) ($snapshot['total_gross'] ?? '0'), 2);
        $vatRate = $snapshot['vat_rate'] ?? null;
        $vatCode = $snapshot['vat_code'] ?? null;
        $amounts = $this->totals->calculateLine(
            (string) ($snapshot['unit_price_gross'] ?? '0'),
            $gross,
            $vatRate !== null ? (string) $vatRate : null,
            $vatCode !== null ? (string) $vatCode : null,
        );

        return array_merge([
            'line_type' => $this->enumValue($snapshot['line_type'] ?? 'custom'),
            'position' => (int) ($snapshot['position'] ?? 1),
            'name' => trim((string) ($snapshot['name'] ?? 'Pozycja')),
            'description' => $this->nullableText($snapshot['description'] ?? null),
            'unit_name' => trim((string) ($snapshot['unit_name'] ?? 'szt.')),
            'quantity' => $this->decimal->normalize((string) ($snapshot['quantity'] ?? '0'), 4),
            'vat_rate' => $vatRate !== null ? $this->decimal->normalize((string) $vatRate, 2) : null,
            'vat_code' => $vatCode !== null ? trim((string) $vatCode) : null,
        ], $amounts);
    }

    /** @param array<string, mixed> $submitted */
    private function afterSnapshot(array $submitted): array
    {
        $quantity = $this->decimal->normalize((string) ($submitted['quantity'] ?? '0'), 4);
        $unitGross = $this->decimal->normalize((string) ($submitted['unit_price_gross'] ?? '0'), 4);
        $totalGross = $this->decimal->multiplyAndRound($unitGross, $quantity, 2);
        $vatRate = $submitted['vat_rate'] ?? null;
        $vatCode = $this->nullableText($submitted['vat_code'] ?? null);

        if ($vatRate === null && $vatCode === null) {
            throw new InvoiceDomainException(
                'correction_tax_missing',
                'Każda pozycja Korekty musi posiadać stawkę albo kod VAT.',
            );
        }

        $amounts = $this->totals->calculateLine(
            $unitGross,
            $totalGross,
            $vatRate !== null ? (string) $vatRate : null,
            $vatCode,
        );

        return array_merge([
            'line_type' => (string) ($submitted['line_type'] ?? 'custom'),
            'position' => (int) ($submitted['position'] ?? 1),
            'name' => trim((string) ($submitted['name'] ?? '')),
            'description' => $this->nullableText($submitted['description'] ?? null),
            'unit_name' => trim((string) ($submitted['unit_name'] ?? 'szt.')),
            'quantity' => $quantity,
            'vat_rate' => $vatRate !== null ? $this->decimal->normalize((string) $vatRate, 2) : null,
            'vat_code' => $vatCode,
        ], $amounts);
    }

    /** @param array<string, mixed> $snapshot */
    private function zeroQuantity(array $snapshot): array
    {
        $snapshot['quantity'] = '0.0000';
        $snapshot['total_net'] = '0.00';
        $snapshot['total_vat'] = '0.00';
        $snapshot['total_gross'] = '0.00';

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function itemAttributes(
        mixed $sourceItem,
        ?int $sourceItemId,
        array $before,
        array $after,
        ?int $orderItemId = null,
    ): array {
        return [
            'order_item_id' => $orderItemId ?? $sourceItem?->order_item_id,
            'product_id' => $sourceItem?->product_id,
            'source_invoice_item_id' => $sourceItemId,
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
            'gtu_codes' => $sourceItem?->gtu_codes ?? [],
            'product_snapshot' => $sourceItem?->product_snapshot,
            'metadata' => ['source' => $sourceItemId === null ? 'correction_manual' : 'correction_source_item'],
            'correction_before_snapshot' => $before,
            'correction_after_snapshot' => $after,
            'correction_difference_snapshot' => $this->differenceItem($before, $after),
        ];
    }

    /** @return array<string, mixed> */
    private function differenceItem(array $before, array $after): array
    {
        return array_merge($after, [
            'quantity' => $this->decimal->subtract((string) $after['quantity'], (string) $before['quantity'], 4),
            'unit_price_net' => $this->decimal->subtract((string) $after['unit_price_net'], (string) $before['unit_price_net'], 4),
            'unit_price_gross' => $this->decimal->subtract((string) $after['unit_price_gross'], (string) $before['unit_price_gross'], 4),
            'total_net' => $this->decimal->subtract((string) $after['total_net'], (string) $before['total_net']),
            'total_vat' => $this->decimal->subtract((string) $after['total_vat'], (string) $before['total_vat']),
            'total_gross' => $this->decimal->subtract((string) $after['total_gross'], (string) $before['total_gross']),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function documentTotals(array $items, string $snapshotKey): array
    {
        return $this->totals->calculateEditedDocument(
            array_map(static fn (array $item): array => $item[$snapshotKey], $items),
            '0.00',
        );
    }

    /** @return array{net: string, vat: string, gross: string} */
    private function differenceTotals(array $before, array $after): array
    {
        return [
            'net' => $this->decimal->subtract($after['total_net'], $before['total_net']),
            'vat' => $this->decimal->subtract($after['total_vat'], $before['total_vat']),
            'gross' => $this->decimal->subtract($after['total_gross'], $before['total_gross']),
        ];
    }

    /** @return array{net: string, vat: string, gross: string} */
    private function compactTotals(array $totals): array
    {
        return [
            'net' => $totals['total_net'],
            'vat' => $totals['total_vat'],
            'gross' => $totals['total_gross'],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function hasActualChange(array $items, array $buyerBefore, array $buyerAfter): bool
    {
        if ($this->comparableParty($buyerBefore) !== $this->comparableParty($buyerAfter)) {
            return true;
        }

        foreach ($items as $item) {
            $difference = $item['correction_difference_snapshot'];
            if ($this->decimal->compare((string) $difference['quantity'], '0', 4) !== 0
                || $this->decimal->compare((string) $difference['unit_price_gross'], '0', 4) !== 0
                || $this->decimal->compare((string) $difference['total_gross'], '0') !== 0
                || $item['correction_before_snapshot']['name'] !== $item['correction_after_snapshot']['name']
                || $item['correction_before_snapshot']['vat_rate'] !== $item['correction_after_snapshot']['vat_rate']
                || $item['correction_before_snapshot']['vat_code'] !== $item['correction_after_snapshot']['vat_code']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function buyerAfter(array $before, array $buyer): array
    {
        $countryCode = $this->countries->normalize($buyer['country_code'] ?? null);

        return array_merge($before, [
            'name' => $this->nullableText($buyer['name'] ?? null),
            'company_name' => $this->nullableText($buyer['company_name'] ?? null),
            'tax_id' => $this->nullableText($buyer['tax_id'] ?? null),
            'street' => $this->nullableText($buyer['street'] ?? null),
            'building_number' => $this->nullableText($buyer['building_number'] ?? null),
            'apartment_number' => $this->nullableText($buyer['apartment_number'] ?? null),
            'postal_code' => $this->nullableText($buyer['postal_code'] ?? null),
            'city' => $this->nullableText($buyer['city'] ?? null),
            'country_code' => $countryCode,
            'country_name' => $this->countries->name($countryCode),
        ]);
    }

    /** @return array<string, mixed> */
    private function issuerSnapshot(Invoice $source, InvoiceSeries $series, array $data): array
    {
        $issuer = $series->correction_issuer_source === CorrectionIssuerSource::Series
            ? ['issuer_name' => $series->issuer_name, 'place_of_issue' => $series->place_of_issue]
            : (is_array($source->issuer_snapshot) ? $source->issuer_snapshot : []);

        $issuer['issuer_name'] = $this->nullableText($data['issuer_name'] ?? null);

        return $issuer;
    }

    /** @param array<string, mixed> $data */
    private function paymentSnapshot(Invoice $effective, InvoiceSeries $series, array $data): array
    {
        $payment = is_array($effective->payment_snapshot) ? $effective->payment_snapshot : [];
        $payment['effective_payment_method'] = $this->nullableText($data['payment_method'] ?? null);

        return $payment;
    }

    /** @return array<string, mixed> */
    private function orderSnapshot(Invoice $source, ?Invoice $previous, array $buyerBefore): array
    {
        $snapshot = is_array($source->order_snapshot) ? $source->order_snapshot : [];
        $snapshot['corrected_invoice'] = [
            'invoice_id' => $source->getKey(),
            'number' => $source->number,
            'issue_date' => $source->issue_date?->toDateString(),
        ];
        $snapshot['correction'] = [
            'previous_correction_id' => $previous?->getKey(),
            'previous_correction_number' => $previous?->number,
            'buyer_before' => $buyerBefore,
        ];

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function seriesSettingsSnapshot(Invoice $source, InvoiceSeries $series): array
    {
        $sourceSettings = is_array($source->series_settings_snapshot) ? $source->series_settings_snapshot : [];

        return array_merge($sourceSettings, [
            'document_type' => InvoiceDocumentType::Correction->value,
            'series_id' => $series->getKey(),
            'series_name' => $series->name,
            'number_format' => $series->number_format,
            'reset_period' => $this->enumValue($series->reset_period),
            'fiscal_year_start_month' => $series->fiscal_year_start_month,
            'document_title' => $series->document_title,
            'print_header' => $series->print_header,
            'correction_sale_date_source' => $this->enumValue($series->correction_sale_date_source),
            'correction_issuer_source' => $this->enumValue($series->correction_issuer_source),
            'correction_payment_method_source' => $this->enumValue($series->correction_payment_method_source),
            'show_correction_item_sequence' => (bool) $series->show_correction_item_sequence,
            'show_return_id_in_header' => (bool) $series->show_return_id_in_header,
            'show_payment_identifier' => (bool) $series->show_payment_identifier,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function saleDate(Invoice $source, InvoiceSeries $series, array $data): string
    {
        return $series->correction_sale_date_source === CorrectionSaleDateSource::IssueDate
            ? (string) $data['issue_date']
            : (string) ($data['sale_date'] ?? $source->sale_date?->toDateString());
    }

    /** @param array<string, mixed> $data */
    private function reason(array $data): string
    {
        $reason = CorrectionReason::from((string) $data['reason']);

        return $reason === CorrectionReason::Other
            ? trim((string) ($data['other_reason'] ?? ''))
            : $reason->label();
    }

    /** @return array<string, mixed> */
    private function comparableParty(array $party): array
    {
        return array_intersect_key($party, array_flip([
            'name', 'company_name', 'tax_id', 'street', 'building_number',
            'apartment_number', 'postal_code', 'city', 'country_code',
        ]));
    }

    private function partyName(array $party): ?string
    {
        return $this->nullableText($party['company_name'] ?? null)
            ?? $this->nullableText($party['name'] ?? null);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
