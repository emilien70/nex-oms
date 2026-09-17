<?php

namespace Modules\Invoices\Services;

use Modules\Invoices\Enums\InvoiceItemType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;

final class SalesRegisterDocumentReader
{
    public function __construct(
        private readonly SalesRegisterValues $values,
        private readonly InvoiceDecimalCalculator $decimal,
        private readonly InvoicePdfCurrencyConversionPresenter $conversions,
    ) {}

    public function buyer(Invoice $invoice): array
    {
        $warnings = [];
        $snapshot = $invoice->buyer_snapshot;
        if (! is_array($snapshot)) {
            $warnings[] = $this->warning($invoice, 'buyer_snapshot_missing', 'buyer');
            $snapshot = [];
        }
        $namePresent = array_key_exists('company_name', $snapshot) || array_key_exists('name', $snapshot);
        $name = $snapshot['company_name'] ?? null;
        if ($name === null || (is_string($name) && trim($name) === '')) {
            $name = $snapshot['name'] ?? null;
        }
        [$name, $nameState] = $this->reconcile($invoice, 'name', $namePresent, $name, $invoice->buyer_name_snapshot, $warnings);
        $taxPresent = array_key_exists('tax_id', $snapshot);
        [$taxId, $taxState] = $this->reconcile($invoice, 'tax_id', $taxPresent, $snapshot['tax_id'] ?? null, $invoice->buyer_tax_id_snapshot, $warnings);
        $country = $this->values->text($snapshot['country_code'] ?? null);
        $country = $country !== null && preg_match('/^[A-Za-z]{2}$/D', $country) ? strtoupper($country) : null;
        if ($country === null) {
            $warnings[] = $this->warning($invoice, 'buyer_country_unknown', 'country');
        }
        if ($name === null) {
            $warnings[] = $this->warning($invoice, 'buyer_name_missing', 'buyer');
        }
        $address = [];
        foreach (['street', 'building_number', 'apartment_number', 'postal_code', 'city', 'province'] as $field) {
            $address[$field] = $this->values->text($snapshot[$field] ?? null);
        }
        if ($address['city'] === null || $address['street'] === null) {
            $warnings[] = $this->warning($invoice, 'buyer_address_incomplete', 'buyer');
        }

        return [
            'name' => $name, 'name_state' => $nameState, 'tax_id' => $taxId, 'tax_id_state' => $taxState,
            'address' => $address, 'country_code' => $country,
            'country_name' => $country !== null ? $this->values->text($snapshot['country_name'] ?? null) : null,
            'warnings' => $warnings,
        ];
    }

    public function read(Invoice $invoice, array $buyer, array $ksef): array
    {
        $warnings = array_merge($buyer['warnings'], $ksef['warnings']);
        unset($buyer['warnings']);
        $attributes = $invoice->getAttributes();
        $totals = $this->values->totals($attributes, 'total_');
        $groups = $this->values->groups($invoice->tax_summary_snapshot);
        $currency = $this->values->text($attributes['currency'] ?? null);
        $currency = $currency !== null && preg_match('/^[A-Za-z]{3}$/D', $currency) ? strtoupper($currency) : null;
        if ($currency === null) {
            $warnings[] = $this->warning($invoice, 'currency_invalid', 'original');
        }
        if ($totals === null) {
            $warnings[] = $this->warning($invoice, 'totals_invalid', 'original');
        }
        if ($invoice->isCorrection()) {
            $difference = data_get($invoice->correction_totals_snapshot, 'difference');
            $storedDifference = is_array($difference) ? $this->values->totals($difference) : null;
            if ($storedDifference === null) {
                $warnings[] = $this->warning($invoice, 'correction_difference_missing', 'correction');
            } elseif ($totals !== null && $storedDifference !== $totals) {
                $warnings[] = $this->warning($invoice, 'correction_difference_conflict', 'original');
                $totals = null;
            }
            $differenceGroups = is_array($difference) ? $this->values->groups($difference['tax_summary_snapshot'] ?? null) : null;
            if ($differenceGroups === null) {
                $warnings[] = $this->warning($invoice, 'correction_tax_difference_missing', 'vat');
            } elseif ($groups !== $differenceGroups) {
                $groups = null;
                $warnings[] = $this->warning($invoice, 'correction_tax_difference_conflict', 'vat');
            }
        }
        if ($groups === null || $totals === null || $this->values->sum($groups) !== $totals
            || ($groups === [] && ! $invoice->isCorrection())) {
            $groups = null;
            $warnings[] = $this->warning($invoice, 'vat_summary_invalid', 'vat');
        }
        $shipping = $this->shipping($invoice);
        if ($shipping === null) {
            $warnings[] = $this->warning($invoice, 'shipping_incomplete', 'shipping');
        }
        $conversion = $this->conversion($invoice, $currency, $totals, $groups, $shipping, $warnings);
        $issueDate = $this->values->date($attributes['issue_date'] ?? null);
        $saleDate = $this->values->date($attributes['sale_date'] ?? null);
        $number = $this->values->text($invoice->number);
        foreach (['number' => $number, 'issue_date' => $issueDate, 'sale_date' => $saleDate] as $field => $value) {
            if ($value === null) {
                $warnings[] = $this->warning($invoice, $field.'_missing', 'document');
            }
        }
        $related = $this->related($invoice, $warnings);
        $paymentMethod = $this->paymentMethod($invoice, $warnings);

        return [
            'id' => (int) $invoice->getKey(), 'type' => $invoice->document_type->value, 'number' => $number,
            'series' => ['id' => (int) $invoice->invoice_series_id, 'name' => $this->values->text($invoice->series_name_snapshot)],
            'issue_date' => $issueDate, 'sale_date' => $saleDate, 'buyer' => $buyer, 'currency' => $currency,
            'totals' => $totals, 'vat_labels' => $groups !== null ? implode(', ', array_column($groups, 'label')) : null,
            'vat_groups' => $groups, 'related_documents' => $related, 'ksef_number' => $ksef['number'],
            'ksef_authorization_date' => $ksef['authorization_date'] ?? null, 'payment_method' => $paymentMethod,
            'shipping' => $shipping, 'pln' => $conversion['pln'], 'shipping_pln' => $conversion['shipping_pln'],
            'exchange_rate' => $conversion['rate'],
            'completeness' => [
                'original' => $currency !== null && $totals !== null,
                'vat' => $currency !== null && $groups !== null,
                'shipping' => $currency !== null && $shipping !== null,
                'pln' => $conversion['pln'] !== null,
                'pln_vat' => $conversion['pln'] !== null && $conversion['pln']['groups'] !== null,
                'shipping_pln' => $conversion['shipping_pln'] !== null,
                'country' => $buyer['country_code'] !== null,
            ],
            'warnings' => array_values(array_unique($warnings, SORT_REGULAR)),
        ];
    }

    private function reconcile(Invoice $invoice, string $field, bool $present, mixed $nested, mixed $scalar, array &$warnings): array
    {
        $nestedText = $this->values->text($nested);
        $scalarText = $this->values->text($scalar);
        if (! $present) {
            $warnings[] = $this->warning($invoice, 'buyer_'.$field.'_legacy_scalar', 'buyer');

            return [$scalarText, $scalarText === null ? 'missing' : 'legacy'];
        }
        if ($nested !== null && ! is_string($nested)) {
            $warnings[] = $this->warning($invoice, 'buyer_'.$field.'_invalid', 'buyer');

            return [null, 'invalid'];
        }
        if ($scalarText !== null && $scalarText !== $nestedText) {
            $warnings[] = $this->warning($invoice, 'buyer_'.$field.'_conflict', 'buyer');

            return [null, 'conflict'];
        }

        return [$nestedText, $nestedText === null ? 'empty' : 'value'];
    }

    private function paymentMethod(Invoice $invoice, array &$warnings): ?string
    {
        $payment = $invoice->payment_snapshot;
        if (! is_array($payment) || ! array_key_exists('effective_payment_method', $payment)) {
            $warnings[] = $this->warning($invoice, 'payment_method_missing', 'payment');

            return null;
        }
        $method = $payment['effective_payment_method'];
        if ($method !== null && ! is_string($method)) {
            $warnings[] = $this->warning($invoice, 'payment_method_invalid', 'payment');

            return null;
        }

        // An explicit null/empty value also represents the persisted "do not show" choice.
        return is_string($method) && trim($method) === '' ? null : $method;
    }

    private function shipping(Invoice $invoice): ?array
    {
        if ($invoice->items->isEmpty()) {
            return null;
        }
        $groups = [];
        foreach ($invoice->items as $item) {
            $states = $invoice->isCorrection()
                ? [[$item->correction_before_snapshot, -1], [$item->correction_after_snapshot, 1]]
                : [[$item->getAttributes(), 1]];
            foreach ($states as [$state, $sign]) {
                if (! is_array($state) || ! in_array($state['line_type'] ?? null, array_column(InvoiceItemType::cases(), 'value'), true)) {
                    return null;
                }
                if ($state['line_type'] !== InvoiceItemType::Shipping->value) {
                    continue;
                }
                $totals = $this->values->totals($state, 'total_');
                $identity = $this->values->identity($state);
                if ($totals === null || $identity === null) {
                    return null;
                }
                $key = $identity['key'];
                $groups[$key] ??= $identity + $this->values->zero();
                foreach ($totals as $field => $amount) {
                    $groups[$key][$field] = $sign === 1
                        ? $this->decimal->add($groups[$key][$field], $amount)
                        : $this->decimal->subtract($groups[$key][$field], $amount);
                }
            }
        }

        return ['totals' => $this->values->sum($groups), 'groups' => $this->values->sortGroups($groups), 'source' => 'stored_items'];
    }

    private function conversion(Invoice $invoice, ?string $currency, ?array $totals, ?array $groups, ?array $shipping, array &$warnings): array
    {
        $pln = $shippingPln = $rate = null;
        if ($currency === 'PLN') {
            $pln = $totals !== null ? ['totals' => $totals, 'groups' => $groups, 'source' => 'stored_pln'] : null;
            $shippingPln = $shipping;
        } elseif ($currency !== null) {
            $noEffect = $invoice->isCorrection() && $totals === $this->values->zero() && $groups !== null;
            foreach ($groups ?? [] as $group) {
                $noEffect = $noEffect && $this->values->totals($group) === $this->values->zero();
            }
            $metadata = $invoice->tax_metadata_snapshot;
            $hasConversion = is_array($metadata) && (array_key_exists('currency_conversion', $metadata) || array_key_exists('converted_tax_summary', $metadata));
            if ($noEffect && ! $hasConversion) {
                $pln = ['totals' => $this->values->zero(), 'groups' => $groups, 'source' => 'no_financial_effect'];
                if ($shipping !== null && $shipping['groups'] === []) {
                    $shippingPln = $shipping;
                } elseif ($shipping !== null && array_filter($shipping['groups'], fn (array $g) => $this->values->totals($g) !== $this->values->zero()) === []) {
                    $shippingPln = $shipping;
                }
            } elseif ($totals !== null && $groups !== null) {
                try {
                    $converted = $this->values->groups(data_get($metadata, 'converted_tax_summary.groups'));
                    $presented = $converted !== null
                        ? $this->conversions->presentSnapshots($currency, $invoice->tax_summary_snapshot, $metadata)
                        : null;
                    if ($presented !== null) {
                        $pln = ['totals' => $presented['totals'], 'groups' => $converted, 'source' => 'stored_conversion'];
                        $rate = array_intersect_key($metadata['currency_conversion'], array_flip([
                            'source', 'source_currency', 'target_currency', 'table_type', 'table_number',
                            'effective_date', 'reference_date', 'rate', 'rounding_mode', 'result_scale',
                        ]));
                        $shippingPln = $shipping !== null ? $this->convertShipping($shipping, $presented['rate']) : null;
                    }
                } catch (InvoiceDomainException) {
                    // Invalid historical conversion is reported without recalculating or changing the document.
                }
            }
        }
        if ($pln === null) {
            $warnings[] = $this->warning($invoice, 'pln_conversion_unavailable', 'pln');
        }
        if ($shippingPln === null) {
            $warnings[] = $this->warning($invoice, 'shipping_pln_unavailable', 'shipping_pln');
        }

        return ['pln' => $pln, 'shipping_pln' => $shippingPln, 'rate' => $rate];
    }

    private function convertShipping(array $shipping, string $rate): array
    {
        $groups = [];
        foreach ($shipping['groups'] as $group) {
            $group['net'] = $this->decimal->multiplyAndRound($group['net'], $rate, 2);
            $group['vat'] = $this->decimal->multiplyAndRound($group['vat'], $rate, 2);
            $group['gross'] = $this->decimal->add($group['net'], $group['vat']);
            $groups[] = $group;
        }

        return ['totals' => $this->values->sum($groups), 'groups' => $groups, 'source' => 'computed_from_stored_items_and_historical_rate'];
    }

    private function related(Invoice $invoice, array &$warnings): array
    {
        if ($invoice->isInvoice()) {
            return $invoice->corrections->map(fn (Invoice $correction) => [
                'id' => (int) $correction->id, 'type' => 'correction', 'number' => $this->values->text($correction->number),
                'issue_date' => $this->values->date($correction->getRawOriginal('issue_date')),
            ])->all();
        }
        foreach ([
            data_get($invoice->order_snapshot, 'corrected_invoice'),
            data_get($invoice->order_snapshot, 'related_documents.invoice'),
            data_get($invoice->correction_totals_snapshot, 'source_invoice'),
        ] as $source) {
            if (is_array($source) && $this->values->text($source['number'] ?? null) !== null) {
                return [[
                    'id' => $invoice->corrected_invoice_id, 'type' => 'invoice', 'number' => trim($source['number']),
                    'issue_date' => $this->values->date($source['issue_date'] ?? null),
                ]];
            }
        }
        $warnings[] = $this->warning($invoice, 'related_invoice_snapshot_missing', 'related_documents');

        return [];
    }

    public function warning(Invoice $invoice, string $code, string $section): array
    {
        return ['code' => 'sales_register_'.$code, 'document_id' => (int) $invoice->getKey(), 'section' => $section];
    }
}
