<div
    class="invoice-series-form-layout"
    data-series-form-fragment
    data-series-id="{{ $series?->id }}"
    data-document-type="{{ $documentType->value }}"
    data-system-series="{{ $series?->is_system ? '1' : '0' }}"
>
    @include('invoices.series.partials._common-fields')

    @include("invoices.series.partials._{$documentType->value}-fields")
</div>
