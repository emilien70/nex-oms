@php
    $invoiceValue = static fn (string $field): mixed => $useOldInput
        ? old($field, $values[$field] ?? null)
        : ($values[$field] ?? null);
    $invoiceHasError = static fn (string $field): bool => $showValidationErrors && $errors->has($field);
    $showPaymentIdentifierOption = true;
@endphp

<div class="alert alert-info invoice-series-readiness-note mt-3 mb-0" role="note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>Przed wystawieniem pro formy wymagane będzie uzupełnienie podstawowych danych sprzedawcy.</span>
</div>

<div class="invoice-series-sections">
    @include('invoices.series.partials.invoice._seller')
    @include('invoices.series.partials.invoice._bank')
    @include('invoices.series.partials.invoice._issuing')
    @include('invoices.series.partials.invoice._vat-items')
    @include('invoices.series.partials.invoice._payment-dates')
    @include('invoices.series.partials.invoice._information')
    @include('invoices.series.partials.invoice._print')
    @include('invoices.series.partials.invoice._logo')
</div>
