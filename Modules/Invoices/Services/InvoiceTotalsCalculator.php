<?php

namespace Modules\Invoices\Services;

use App\Models\Order;
use Modules\Invoices\Exceptions\InvoiceDomainException;

class InvoiceTotalsCalculator
{
    public function __construct(
        private readonly InvoiceDecimalCalculator $decimal,
    ) {}

    /**
     * @return array{unit_price_net: string, unit_price_gross: string, total_net: string, total_vat: string, total_gross: string}
     */
    public function calculateLine(
        string $unitPriceGross,
        string $totalGross,
        ?string $vatRate,
        ?string $vatCode = null,
    ): array {
        $unitGross = $this->decimal->normalize($unitPriceGross, 4);
        $gross = $this->decimal->normalize($totalGross, 2);

        if ($vatCode !== null) {
            return [
                'unit_price_net' => $unitGross,
                'unit_price_gross' => $unitGross,
                'total_net' => $gross,
                'total_vat' => '0.00',
                'total_gross' => $gross,
            ];
        }

        if ($vatRate === null) {
            throw new InvoiceDomainException(
                'invoice_tax_calculation_failed',
                'Nie można prawidłowo obliczyć wartości podatkowych dokumentu.',
            );
        }

        $rate = $this->decimal->normalize($vatRate, 2);
        $unitNet = $this->decimal->netFromGross($unitGross, $rate, 4);
        $net = $this->decimal->netFromGross($gross, $rate, 2);

        return [
            'unit_price_net' => $unitNet,
            'unit_price_gross' => $unitGross,
            'total_net' => $net,
            'total_vat' => $this->decimal->subtract($gross, $net),
            'total_gross' => $gross,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{total_net: string, total_vat: string, total_gross: string, paid_amount: string, amount_due: string, tax_summary_snapshot: array<int, array<string, ?string>>}
     */
    public function calculateDocument(array $items, Order $order): array
    {
        return $this->calculateForPaidAmount($items, (string) ($order->paid_amount ?? '0'), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{total_net: string, total_vat: string, total_gross: string, paid_amount: string, amount_due: string, tax_summary_snapshot: array<int, array<string, ?string>>}
     */
    public function calculateEditedDocument(array $items, string $paidAmount): array
    {
        return $this->calculateForPaidAmount($items, $paidAmount, false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{total_net: string, total_vat: string, total_gross: string, paid_amount: string, amount_due: string, tax_summary_snapshot: array<int, array<string, ?string>>}
     */
    private function calculateForPaidAmount(array $items, string $paidAmount, bool $clampPaid): array
    {
        $totalNet = '0.00';
        $totalVat = '0.00';
        $totalGross = '0.00';
        $taxGroups = [];

        foreach ($items as $item) {
            $itemNet = $this->decimal->normalize((string) $item['total_net'], 2);
            $itemVat = $this->decimal->normalize((string) $item['total_vat'], 2);
            $itemGross = $this->decimal->normalize((string) $item['total_gross'], 2);
            $totalNet = $this->decimal->add($totalNet, $itemNet);
            $totalVat = $this->decimal->add($totalVat, $itemVat);
            $totalGross = $this->decimal->add($totalGross, $itemGross);
            $vatRate = $item['vat_rate'] !== null
                ? $this->decimal->normalize((string) $item['vat_rate'], 2)
                : null;
            $vatCode = $item['vat_code'] ?? null;
            $key = $vatCode !== null ? 'code:'.$vatCode : 'rate:'.$vatRate;

            $taxGroups[$key] ??= [
                'vat_rate' => $vatRate,
                'vat_code' => $vatCode,
                'net' => '0.00',
                'vat' => '0.00',
                'gross' => '0.00',
            ];
            $taxGroups[$key]['net'] = $this->decimal->add($taxGroups[$key]['net'], $itemNet);
            $taxGroups[$key]['vat'] = $this->decimal->add($taxGroups[$key]['vat'], $itemVat);
            $taxGroups[$key]['gross'] = $this->decimal->add($taxGroups[$key]['gross'], $itemGross);
        }

        ksort($taxGroups, SORT_STRING);
        $paid = $this->decimal->normalize($paidAmount, 2);
        $paid = $this->decimal->max($paid, '0.00');
        if (! $clampPaid && $this->decimal->compare($paid, $totalGross) > 0) {
            throw new InvoiceDomainException(
                'invoice_paid_amount_exceeds_total',
                'Kwota zapłacona nie może przekraczać wartości brutto Faktury.',
            );
        }
        if ($clampPaid) {
            $paid = $this->decimal->min($paid, $totalGross);
        }
        $due = $this->decimal->max($this->decimal->subtract($totalGross, $paid), '0.00');

        return [
            'total_net' => $totalNet,
            'total_vat' => $totalVat,
            'total_gross' => $totalGross,
            'paid_amount' => $paid,
            'amount_due' => $due,
            'tax_summary_snapshot' => array_values($taxGroups),
        ];
    }
}
