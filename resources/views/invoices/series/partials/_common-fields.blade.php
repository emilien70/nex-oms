@php
    $fieldValue = static fn (string $field): mixed => $useOldInput
        ? old($field, $values[$field] ?? null)
        : ($values[$field] ?? null);
    $fieldHasError = static fn (string $field): bool => $showValidationErrors && $errors->has($field);
@endphp

<div class="row g-3">
    <div class="col-12">
        <label class="form-label" for="invoice-series-document-type">Typ dokumentu</label>
        @if ($series?->is_system)
            <input type="hidden" name="document_type" value="{{ $series->document_type->value }}">
            <div class="form-control form-control-sm bg-light" data-role="system-document-type">
                {{ $series->document_type->label() }}
            </div>
        @else
            <select
                class="form-select form-select-sm {{ $fieldHasError('document_type') ? 'is-invalid' : '' }}"
                id="invoice-series-document-type"
                name="document_type"
                data-series-document-type
                required
            >
                @foreach ($documentTypes as $type)
                    <option value="{{ $type->value }}" @selected($fieldValue('document_type') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </select>
            @if ($fieldHasError('document_type'))
                <div class="invalid-feedback">{{ $errors->first('document_type') }}</div>
            @endif
        @endif
    </div>

    <div class="col-12">
        <label class="form-label" for="invoice-series-name">Nazwa serii</label>
        <input
            class="form-control form-control-sm {{ $fieldHasError('name') ? 'is-invalid' : '' }}"
            id="invoice-series-name"
            name="name"
            type="text"
            value="{{ $fieldValue('name') }}"
            maxlength="120"
            required
        >
        @if ($fieldHasError('name'))
            <div class="invalid-feedback">{{ $errors->first('name') }}</div>
        @endif
    </div>

    <div class="col-12">
        <label class="form-label" for="invoice-series-number-format">Format numeracji</label>
        <input
            class="form-control form-control-sm {{ $fieldHasError('number_format') ? 'is-invalid' : '' }}"
            id="invoice-series-number-format"
            name="number_format"
            type="text"
            value="{{ $fieldValue('number_format') }}"
            maxlength="120"
            required
        >
        <div class="form-text">Format musi zawierać token %N, np. {{ $documentType->defaultNumberFormat() }}.</div>
        @if ($fieldHasError('number_format'))
            <div class="invalid-feedback">{{ $errors->first('number_format') }}</div>
        @endif
    </div>

    <div class="col-md-6">
        <label class="form-label" for="invoice-series-reset-period">Resetowanie numeracji</label>
        <select
            class="form-select form-select-sm {{ $fieldHasError('reset_period') ? 'is-invalid' : '' }}"
            id="invoice-series-reset-period"
            name="reset_period"
            required
        >
            @foreach ($resetPeriods as $period)
                <option value="{{ $period->value }}" @selected($fieldValue('reset_period') === $period->value)>
                    {{ $period->label() }}
                </option>
            @endforeach
        </select>
        @if ($fieldHasError('reset_period'))
            <div class="invalid-feedback">{{ $errors->first('reset_period') }}</div>
        @endif
    </div>

    <div class="col-md-6">
        <label class="form-label" for="invoice-series-fiscal-month">Początek roku fiskalnego</label>
        <select
            class="form-select form-select-sm {{ $fieldHasError('fiscal_year_start_month') ? 'is-invalid' : '' }}"
            id="invoice-series-fiscal-month"
            name="fiscal_year_start_month"
            required
        >
            @foreach (range(1, 12) as $month)
                <option value="{{ $month }}" @selected((int) $fieldValue('fiscal_year_start_month') === $month)>
                    {{ $month }}
                </option>
            @endforeach
        </select>
        @if ($fieldHasError('fiscal_year_start_month'))
            <div class="invalid-feedback">{{ $errors->first('fiscal_year_start_month') }}</div>
        @endif
    </div>

    <div class="col-md-6">
        <label class="form-label" for="invoice-series-currency">Domyślna waluta</label>
        <input
            class="form-control form-control-sm text-uppercase {{ $fieldHasError('default_currency') ? 'is-invalid' : '' }}"
            id="invoice-series-currency"
            name="default_currency"
            type="text"
            value="{{ $fieldValue('default_currency') }}"
            maxlength="3"
            required
        >
        @if ($fieldHasError('default_currency'))
            <div class="invalid-feedback">{{ $errors->first('default_currency') }}</div>
        @endif
    </div>

    <div class="col-md-6 d-flex align-items-end">
        @if ($series?->is_system)
            <input type="hidden" name="is_active" value="1">
            <div class="invoice-series-system-note" role="note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Seria systemowa jest zawsze aktywna, a jej typu dokumentu nie można zmienić.
            </div>
        @else
            <div class="form-check mb-1">
                <input type="hidden" name="is_active" value="0">
                <input
                    class="form-check-input {{ $fieldHasError('is_active') ? 'is-invalid' : '' }}"
                    id="invoice-series-active"
                    name="is_active"
                    type="checkbox"
                    value="1"
                    @checked((bool) $fieldValue('is_active'))
                >
                <label class="form-check-label" for="invoice-series-active">Seria aktywna</label>
                @if ($fieldHasError('is_active'))
                    <div class="invalid-feedback">{{ $errors->first('is_active') }}</div>
                @endif
            </div>
        @endif
    </div>
</div>
