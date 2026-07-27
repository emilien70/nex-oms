@php
    $correctionValue = static fn (string $field): mixed => $useOldInput
        ? old($field, $values[$field] ?? null)
        : ($values[$field] ?? null);
    $correctionHasError = static fn (string $field): bool => $showValidationErrors && $errors->has($field);
@endphp

<div class="alert alert-info invoice-series-readiness-note mt-3 mb-0" role="note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>Dane prawne sprzedawcy i logo przyszłej korekty będą pochodziły ze snapshotu faktury źródłowej.</span>
</div>

<div class="invoice-series-sections">
    @include('invoices.series.partials.correction._settings')
    @include('invoices.series.partials.correction._issuer-payment')
    @include('invoices.series.partials.correction._information')
    @include('invoices.series.partials.correction._items-header')
    @include('invoices.series.partials.correction._print')
</div>
