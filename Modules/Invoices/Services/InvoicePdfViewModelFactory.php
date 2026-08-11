<?php

namespace Modules\Invoices\Services;

use App\Support\CountryCatalog;
use BackedEnum;
use Illuminate\Support\Collection;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

class InvoicePdfViewModelFactory
{
    private const BUYER_COMPARISON_FIELDS = [
        'name',
        'company_name',
        'tax_id',
        'street',
        'building_number',
        'apartment_number',
        'postal_code',
        'city',
        'country_code',
    ];

    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoiceAmountInWordsFormatter $amountInWords,
        private readonly CountryCatalog $countries,
        private readonly InvoicePdfCurrencyConversionPresenter $currencyConversion,
        private readonly CorrectionTotalsCalculator $correctionTotals,
        private readonly CorrectionCurrencyConversionService $correctionCurrencyConversion,
    ) {}

    /** @return array<string, mixed> */
    public function make(Invoice $invoice): array
    {
        $this->assertAvailable($invoice);
        $invoice->loadMissing($invoice->isCorrection()
            ? ['items', 'correctedInvoice']
            : ['items']);

        return $invoice->isCorrection()
            ? $this->correction($invoice)
            : $this->standard($invoice);
    }

    /** @return array<string, mixed> */
    private function standard(Invoice $invoice): array
    {
        $this->assertCompleteStandardDocument($invoice);
        $settings = $invoice->series_settings_snapshot;
        $seller = $invoice->seller_snapshot;
        $payment = $invoice->payment_snapshot;
        $order = $invoice->order_snapshot;
        $plnConversion = $this->currencyConversion->present($invoice);

        return [
            'type' => $invoice->document_type->value,
            'title' => $this->text($settings['document_title'] ?? null)
                ?: ($invoice->isProforma() ? 'Faktura PRO FORMA' : 'Faktura VAT'),
            'header' => $this->text($settings['print_header'] ?? null)
                ?: $this->text($seller['name'] ?? null)
                ?: $this->text($invoice->seller_name_snapshot),
            'number' => $invoice->number,
            'sale_date' => $invoice->sale_date->format('d.m.Y'),
            'issue_date' => $invoice->issue_date->format('d.m.Y'),
            'payment_due_date' => $invoice->payment_due_date?->format('d.m.Y'),
            'place_of_issue' => $this->text($invoice->issuer_snapshot['place_of_issue'] ?? null),
            'payment_method' => $this->paymentMethod($invoice),
            'payment_identifier' => $this->text($payment['payment_identifier'] ?? null),
            'order_number' => $this->text($order['external_id'] ?? null)
                ?: $this->text($invoice->order_reference_snapshot),
            'related_proforma_number' => $invoice->isInvoice()
                ? $this->text($order['related_documents']['proforma']['number'] ?? null)
                : null,
            'seller' => $this->party($seller, includeCountry: false, includeRegon: false),
            'buyer' => $this->party($invoice->buyer_snapshot),
            'items' => $this->items($invoice->items),
            'tax_rows' => $this->taxRows($invoice->tax_summary_snapshot),
            'tax_row_pairs' => $plnConversion['tax_row_pairs'] ?? [],
            'pln_conversion' => $plnConversion,
            'totals' => $this->totals($invoice),
            'currency' => $invoice->currency,
            'amount_in_words' => $this->amountInWords->format($invoice->total_gross, $invoice->currency),
            'issuer_name' => $this->text($invoice->issuer_snapshot['issuer_name'] ?? null),
            'additional_information' => $this->text($invoice->additional_information_text),
            'seller_bank_name' => $this->text($seller['bank_name'] ?? null),
            'seller_bank_account' => $this->text($seller['bank_account'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function correction(Invoice $invoice): array
    {
        $this->assertCompleteCorrection($invoice);
        $settings = $invoice->series_settings_snapshot;
        $seller = $invoice->seller_snapshot;
        $source = $this->sourceInvoiceReference($invoice);
        $storedTotals = $invoice->correction_totals_snapshot;
        $totals = $this->reconstructedCorrectionTotals($invoice);
        $difference = $totals['difference'];
        $decreasing = $this->decimal->compare((string) $difference['gross'], '0.00') < 0;
        $plnConversion = $this->correctionPlnConversion($invoice, $storedTotals, $difference);
        $buyerChange = $this->buyerChange($invoice);

        return [
            'type' => InvoiceDocumentType::Correction->value,
            'title' => $this->text($settings['document_title'] ?? null) ?: 'Faktura korygująca',
            'header' => $this->text($settings['print_header'] ?? null)
                ?: $this->text($seller['name'] ?? null)
                ?: $this->text($invoice->seller_name_snapshot),
            'number' => $invoice->number,
            'source_invoice' => $source,
            'sale_date' => $invoice->sale_date->format('d.m.Y'),
            'issue_date' => $invoice->issue_date->format('d.m.Y'),
            'reason' => $invoice->correction_reason,
            'place_of_issue' => $this->text($invoice->issuer_snapshot['place_of_issue'] ?? null),
            'payment_method' => $this->paymentMethod($invoice),
            'seller' => $this->party($seller, includeCountry: false, includeRegon: false),
            'buyer' => $this->party($invoice->buyer_snapshot),
            'buyer_change' => $buyerChange,
            'before_items' => $this->correctionItems($invoice->items, 'correction_before_snapshot'),
            'after_items' => $this->correctionItems($invoice->items, 'correction_after_snapshot'),
            'before_totals' => $this->snapshotTotals($totals['before']),
            'after_totals' => $this->snapshotTotals($totals['after']),
            'difference_totals' => $this->snapshotTotals($difference),
            'difference_magnitudes' => $this->snapshotMagnitudeTotals($difference),
            'difference_tax_rows' => $this->taxRows($difference['tax_summary_snapshot']),
            'tax_row_pairs' => $plnConversion['tax_row_pairs'] ?? [],
            'pln_conversion' => $plnConversion,
            'pln_difference_magnitudes' => $plnConversion !== null
                ? $this->snapshotMagnitudeTotals($plnConversion['totals'])
                : null,
            'decreasing' => $decreasing,
            'difference_labels' => $this->correctionDifferenceLabels($difference),
            'currency' => $invoice->currency,
            'amount_in_words' => $this->amountInWords->format((string) $difference['gross'], $invoice->currency),
            'issuer_name' => $this->text($invoice->issuer_snapshot['issuer_name'] ?? null),
            'additional_information' => $this->text($invoice->additional_information_text),
        ];
    }

    private function assertAvailable(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceDocumentStatus::Issued) {
            throw new InvoiceDomainException(
                'invoice_pdf_not_available',
                'Plik PDF jest dostępny wyłącznie dla wystawionego dokumentu.',
            );
        }

        if (! in_array($invoice->document_type, InvoiceDocumentType::cases(), true)) {
            throw new InvoiceDomainException(
                'invoice_pdf_unsupported_document_type',
                'Ten typ dokumentu nie jest obsługiwany przez generator PDF.',
            );
        }
    }

    private function assertCompleteStandardDocument(Invoice $invoice): void
    {
        if ($invoice->number === null
            || $invoice->issue_date === null
            || $invoice->sale_date === null
            || ! is_array($invoice->seller_snapshot)
            || ! is_array($invoice->buyer_snapshot)
            || ! is_array($invoice->issuer_snapshot)
            || ! is_array($invoice->order_snapshot)
            || ! is_array($invoice->payment_snapshot)
            || ! is_array($invoice->series_settings_snapshot)
            || ! is_array($invoice->tax_summary_snapshot)) {
            throw $this->incompleteData();
        }
    }

    private function assertCompleteCorrection(Invoice $invoice): void
    {
        $this->assertCompleteStandardDocument($invoice);
        $totals = $invoice->correction_totals_snapshot;

        if ($invoice->corrected_invoice_id === null
            || trim((string) $invoice->correction_reason) === ''
            || ! is_array($totals)
            || ! $this->hasTotals($totals['before'] ?? null)
            || ! $this->hasTotals($totals['after'] ?? null)
            || ! $this->hasTotals($totals['difference'] ?? null)) {
            throw $this->incompleteData();
        }

        foreach ($invoice->items as $item) {
            if (! is_array($item->correction_before_snapshot)
                || ! is_array($item->correction_after_snapshot)
                || ! is_array($item->correction_difference_snapshot)) {
                throw $this->incompleteData();
            }
        }
    }

    /** @param Collection<int, mixed> $items */
    private function items(Collection $items): array
    {
        return $items->map(fn ($item): array => $this->item([
            'line_type' => $this->enumValue($item->line_type),
            'name' => $item->name,
            'unit_name' => $item->unit_name,
            'quantity' => $item->quantity,
            'unit_price_net' => $item->unit_price_net,
            'total_net' => $item->total_net,
            'total_vat' => $item->total_vat,
            'total_gross' => $item->total_gross,
            'vat_rate' => $item->vat_rate,
            'vat_code' => $item->vat_code,
        ]))->values()->all();
    }

    /** @param Collection<int, mixed> $items */
    private function correctionItems(Collection $items, string $snapshot): array
    {
        return $items->map(fn ($item): array => $this->item($item->{$snapshot}))->values()->all();
    }

    /** @param array<string, mixed> $item */
    private function item(array $item): array
    {
        $vat = $this->vatLabel($item['vat_rate'] ?? null, $item['vat_code'] ?? null);
        $name = $this->text($item['name'] ?? null) ?: 'Pozycja';
        $lineType = $this->enumValue($item['line_type'] ?? null);

        if ($lineType === 'shipping') {
            $name = 'Przesyłka: '.$vat.' VAT: '.$name;
        }

        return [
            'name' => $name,
            'unit_name' => $this->text($item['unit_name'] ?? null) ?: 'szt.',
            'quantity' => $this->quantity((string) ($item['quantity'] ?? '0')),
            'unit_price_net' => $this->money((string) ($item['unit_price_net'] ?? '0')),
            'total_net' => $this->money((string) ($item['total_net'] ?? '0')),
            'vat' => $vat,
            'total_vat' => $this->money((string) ($item['total_vat'] ?? '0')),
            'total_gross' => $this->money((string) ($item['total_gross'] ?? '0')),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function taxRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'vat' => $this->vatLabel($row['vat_rate'] ?? null, $row['vat_code'] ?? null),
            'net' => $this->money((string) ($row['net'] ?? '0')),
            'vat_amount' => $this->money((string) ($row['vat'] ?? '0')),
            'gross' => $this->money((string) ($row['gross'] ?? '0')),
        ], $rows);
    }

    /** @return array<string, string> */
    private function totals(Invoice $invoice): array
    {
        return [
            'net' => $this->money($invoice->total_net),
            'vat' => $this->money($invoice->total_vat),
            'gross' => $this->money($invoice->total_gross),
        ];
    }

    /** @param array<string, mixed> $totals */
    private function snapshotTotals(array $totals): array
    {
        return [
            'net' => $this->money((string) $totals['net']),
            'vat' => $this->money((string) $totals['vat']),
            'gross' => $this->money((string) $totals['gross']),
        ];
    }

    /** @param array<string, mixed> $totals */
    private function snapshotMagnitudeTotals(array $totals): array
    {
        return collect(['net', 'vat', 'gross'])
            ->mapWithKeys(function (string $key) use ($totals): array {
                $value = (string) $totals[$key];
                $magnitude = $this->decimal->compare($value, '0.00') < 0
                    ? $this->decimal->subtract('0.00', $value)
                    : $value;

                return [$key => $this->money($magnitude)];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function reconstructedCorrectionTotals(Invoice $invoice): array
    {
        $items = $invoice->items
            ->map(static fn ($item): array => [
                'correction_before_snapshot' => $item->correction_before_snapshot,
                'correction_after_snapshot' => $item->correction_after_snapshot,
            ])
            ->values()
            ->all();

        return $this->correctionTotals->calculate($items);
    }

    /**
     * @param  array<string, mixed>  $difference
     * @return array<string, mixed>|null
     */
    private function correctionPlnConversion(
        Invoice $invoice,
        mixed $storedTotals,
        array $difference,
    ): ?array {
        $monetary = $this->correctionTotals->isMonetary($difference);
        if (! $monetary || strtoupper(trim((string) $invoice->currency)) === 'PLN') {
            return null;
        }

        if ($this->hasCanonicalCorrectionTaxSummaries($storedTotals)) {
            $presented = $this->currencyConversion->presentSnapshots(
                (string) $invoice->currency,
                $difference['tax_summary_snapshot'],
                $invoice->tax_metadata_snapshot,
            );

            if ($presented === null) {
                throw new InvoiceDomainException(
                    'invoice_pdf_invalid_currency_conversion_snapshot',
                    'Nie można wygenerować PDF, ponieważ zapisane dane przeliczenia walutowego są niekompletne.',
                );
            }

            return $presented;
        }

        $source = $invoice->correctedInvoice()->first();
        if ($source === null) {
            throw $this->incompleteData();
        }

        $metadata = $this->correctionCurrencyConversion->metadataFor(
            $source,
            $difference['tax_summary_snapshot'],
            true,
        );

        return $this->currencyConversion->presentSnapshots(
            (string) $invoice->currency,
            $difference['tax_summary_snapshot'],
            $metadata,
        );
    }

    private function hasCanonicalCorrectionTaxSummaries(mixed $totals): bool
    {
        if (! is_array($totals)) {
            return false;
        }

        foreach (['before', 'after', 'difference'] as $key) {
            if (! is_array($totals[$key] ?? null)
                || ! is_array($totals[$key]['tax_summary_snapshot'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $difference
     * @return array{net: string, vat: string, gross: string}
     */
    private function correctionDifferenceLabels(array $difference): array
    {
        return [
            'net' => $this->differenceLabel(
                (string) $difference['net'],
                'Kwota zmniejszająca podstawę opodatkowania',
                'Kwota zwiększająca podstawę opodatkowania',
                'Różnica podstawy opodatkowania',
            ),
            'vat' => $this->differenceLabel(
                (string) $difference['vat'],
                'Kwota zmniejszająca podatek VAT',
                'Kwota zwiększająca podatek VAT',
                'Różnica podatku VAT',
            ),
            'gross' => $this->differenceLabel(
                (string) $difference['gross'],
                'Do zwrotu',
                'Do zapłaty',
                'Różnica brutto',
            ),
        ];
    }

    private function differenceLabel(
        string $value,
        string $decreasing,
        string $increasing,
        string $neutral,
    ): string {
        return match ($this->decimal->compare($value, '0.00')) {
            -1 => $decreasing,
            1 => $increasing,
            default => $neutral,
        };
    }

    /** @return array<string, mixed> */
    private function sourceInvoiceReference(Invoice $invoice): array
    {
        $snapshot = $invoice->order_snapshot['corrected_invoice']
            ?? $invoice->order_snapshot['related_documents']['invoice']
            ?? $invoice->correction_totals_snapshot['source_invoice']
            ?? null;

        if (is_array($snapshot) && ! empty($snapshot['number']) && ! empty($snapshot['issue_date'])) {
            return [
                'number' => (string) $snapshot['number'],
                'issue_date' => date('d.m.Y', strtotime((string) $snapshot['issue_date'])),
            ];
        }

        $source = $invoice->correctedInvoice()->first();
        if ($source === null || $source->number === null || $source->issue_date === null) {
            throw $this->incompleteData();
        }

        return ['number' => $source->number, 'issue_date' => $source->issue_date->format('d.m.Y')];
    }

    /** @return array{before: array<string, mixed>, after: array<string, mixed>}|null */
    private function buyerChange(Invoice $invoice): ?array
    {
        $before = data_get($invoice->order_snapshot, 'correction.buyer_before');

        if (! is_array($before)) {
            $source = $invoice->correctedInvoice;
            $before = $source !== null && is_array($source->buyer_snapshot)
                ? $source->buyer_snapshot
                : null;
        }

        $after = $invoice->buyer_snapshot;
        if (! is_array($before)
            || ! is_array($after)
            || $this->comparableBuyer($before) === $this->comparableBuyer($after)) {
            return null;
        }

        return [
            'before' => $this->party($before),
            'after' => $this->party($after),
        ];
    }

    /**
     * @param  array<string, mixed>  $buyer
     * @return array<string, ?string>
     */
    private function comparableBuyer(array $buyer): array
    {
        $comparable = [];

        foreach (self::BUYER_COMPARISON_FIELDS as $field) {
            $value = $this->text($buyer[$field] ?? null);
            $comparable[$field] = $field === 'country_code'
                ? $this->countries->normalize($value)
                : $value;
        }

        return $comparable;
    }

    /** @param array<string, mixed> $party */
    private function party(
        array $party,
        bool $includeCountry = true,
        bool $includeRegon = true,
    ): array {
        $name = $this->text($party['company_name'] ?? null) ?: $this->text($party['name'] ?? null);
        $person = $this->text($party['name'] ?? null);
        $street = trim(implode(' ', array_filter([
            $this->text($party['street'] ?? null),
            $this->text($party['building_number'] ?? null),
        ])));

        if ($this->text($party['apartment_number'] ?? null)) {
            $street .= '/'.$this->text($party['apartment_number']);
        }

        return [
            'lines' => array_values(array_filter([
                $name,
                $person !== $name ? $person : null,
                $street,
                $includeCountry ? $this->buyerLocality($party) : $this->postalCity($party),
                $includeRegon && $this->text($party['regon'] ?? null)
                    ? 'REGON: '.$this->text($party['regon'])
                    : null,
                $this->text($party['bdo'] ?? null) ? 'BDO: '.$this->text($party['bdo']) : null,
                $this->text($party['tax_id'] ?? null) ? 'NIP: '.$this->text($party['tax_id']) : null,
            ], fn (?string $line): bool => $line !== null && $line !== '')),
        ];
    }

    /** @param array<string, mixed> $party */
    private function postalCity(array $party): ?string
    {
        $postalCode = $this->text($party['postal_code'] ?? null);
        $city = $this->text($party['city'] ?? null);

        if ($postalCode !== null && $city !== null) {
            return $postalCode.', '.$city;
        }

        return $postalCode ?? $city;
    }

    /** @param array<string, mixed> $party */
    private function buyerLocality(array $party): ?string
    {
        $postalCode = $this->text($party['postal_code'] ?? null);
        $city = $this->text($party['city'] ?? null);
        $country = $this->text($party['country_name'] ?? null)
            ?: $this->countries->name($this->text($party['country_code'] ?? null));
        $locality = trim(implode(' ', array_filter([$postalCode, $city])));

        if ($locality !== '' && $country !== null) {
            return $locality.', '.$country;
        }

        return $locality !== '' ? $locality : $country;
    }

    private function vatLabel(mixed $rate, mixed $code): string
    {
        if ($this->text($code) !== null) {
            return (string) $code;
        }

        $normalized = $this->decimal->normalize((string) ($rate ?? '0'), 2);

        return rtrim(rtrim($normalized, '0'), '.').'%';
    }

    private function money(string $value): string
    {
        return $this->decimal->normalize($value, 2);
    }

    private function quantity(string $value): string
    {
        return rtrim(rtrim($this->decimal->normalize($value, 4), '0'), '.');
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function paymentMethod(Invoice $invoice): ?string
    {
        return $this->text($invoice->payment_snapshot['effective_payment_method'] ?? null);
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function hasTotals(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('net', $value)
            && array_key_exists('vat', $value)
            && array_key_exists('gross', $value);
    }

    private function incompleteData(): InvoiceDomainException
    {
        return new InvoiceDomainException(
            'invoice_pdf_data_incomplete',
            'Nie można wygenerować PDF, ponieważ dane dokumentu są niekompletne.',
        );
    }
}
