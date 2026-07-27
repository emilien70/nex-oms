<div
    data-series-form-fragment
    data-document-type="{{ $documentType->value }}"
    data-system-series="{{ $series?->is_system ? '1' : '0' }}"
>
    @include('invoices.series.partials._common-fields')

    @include("invoices.series.partials._{$documentType->value}-fields")
</div>
