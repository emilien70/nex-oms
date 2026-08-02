<?php

namespace App\Rules;

use App\Support\CurrencyCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCurrencyCode implements ValidationRule
{
    public function __construct(
        private readonly CurrencyCatalog $currencies,
        private readonly mixed $unchangedHistoricalCode = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->currencies->isAllowed($value, $this->unchangedHistoricalCode)) {
            $fail(CurrencyCatalog::INVALID_CURRENCY_MESSAGE);
        }
    }
}
