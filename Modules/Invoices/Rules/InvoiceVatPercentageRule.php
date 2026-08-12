<?php

namespace Modules\Invoices\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class InvoiceVatPercentageRule implements ValidationRule
{
    public function __construct(
        private readonly InvoiceFinancialValueValidator $validator,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->validator->isBusinessVatInput($value)) {
            $fail('Stawka VAT musi być liczbą całkowitą od 0 do 100%.');
        }
    }
}
