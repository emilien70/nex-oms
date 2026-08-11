<?php

namespace Modules\Invoices\Services;

class InvoiceMoneyFormatter
{
    public function __construct(private readonly InvoiceDecimalCalculator $decimal) {}

    public function format(string|int|null $amount): string
    {
        $normalized = $this->decimal->normalize($amount, 2);

        if ($this->decimal->compare($normalized, '0.00') === 0) {
            $normalized = '0.00';
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$integer, $fraction] = explode('.', $unsigned, 2);
        $groups = [];

        while (strlen($integer) > 3) {
            array_unshift($groups, substr($integer, -3));
            $integer = substr($integer, 0, -3);
        }

        array_unshift($groups, $integer);

        return ($negative ? '-' : '').implode(' ', $groups).','.$fraction;
    }

    public function formatForInput(string|int|null $amount): string
    {
        return $this->decimal->normalize($amount, 2);
    }
}
