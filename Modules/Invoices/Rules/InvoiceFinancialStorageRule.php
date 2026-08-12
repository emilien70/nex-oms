<?php

namespace Modules\Invoices\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Invoices\Services\InvoiceFinancialValueValidator;

class InvoiceFinancialStorageRule implements ValidationRule
{
    /**
     * @param  array{precision: int, scale: int, signed: bool}  $contract
     */
    public function __construct(
        private readonly InvoiceFinancialValueValidator $validator,
        private readonly array $contract,
        private readonly string $message,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->validator->fits($value, $this->contract)) {
            $fail($this->message);
        }
    }
}
