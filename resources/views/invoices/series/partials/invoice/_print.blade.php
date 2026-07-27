<section class="invoice-series-form-section" aria-labelledby="invoice-series-print-heading">
    <h3 id="invoice-series-print-heading">Ustawienia wydruku</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-document-title">Nazwa dokumentu</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('document_title') ? 'is-invalid' : '' }}" id="invoice-series-document-title" name="document_title" type="text" value="{{ $invoiceValue('document_title') }}" maxlength="120" required>
            @if ($invoiceHasError('document_title'))<div class="invalid-feedback">{{ $errors->first('document_title') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-print-template">Szablon wydruku</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('print_template') ? 'is-invalid' : '' }}" id="invoice-series-print-template" name="print_template" required>
                @foreach ($printTemplates as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('print_template') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('print_template'))<div class="invalid-feedback">{{ $errors->first('print_template') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="invoice-series-primary-language">Język główny</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('primary_language') ? 'is-invalid' : '' }}" id="invoice-series-primary-language" name="primary_language" required>
                @foreach ($primaryLanguages as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('primary_language') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('primary_language'))<div class="invalid-feedback">{{ $errors->first('primary_language') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="invoice-series-secondary-language">Język dodatkowy</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('secondary_language') ? 'is-invalid' : '' }}" id="invoice-series-secondary-language" name="secondary_language">
                <option value="">Brak</option>
                @foreach ($secondaryLanguages as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('secondary_language') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('secondary_language'))<div class="invalid-feedback">{{ $errors->first('secondary_language') }}</div>@endif
        </div>
        <div class="col-md-2">
            <label class="form-label" for="invoice-series-copies-count">Liczba kopii</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('copies_count') ? 'is-invalid' : '' }}" id="invoice-series-copies-count" name="copies_count" type="number" min="1" max="10" step="1" value="{{ $invoiceValue('copies_count') }}" required>
            @if ($invoiceHasError('copies_count'))<div class="invalid-feedback">{{ $errors->first('copies_count') }}</div>@endif
        </div>
        @foreach ([
            'show_order_number' => 'Pokaż numer zamówienia',
            'show_buyer_signature' => 'Pokaż miejsce na podpis odbiorcy',
            'show_original_copy' => 'Oryginał/kopia',
        ] as $field => $label)
            <div class="col-md-4">
                <div class="form-check">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input class="form-check-input {{ $invoiceHasError($field) ? 'is-invalid' : '' }}" id="invoice-series-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) $invoiceValue($field))>
                    <label class="form-check-label" for="invoice-series-{{ str_replace('_', '-', $field) }}">{{ $label }}</label>
                    @if ($invoiceHasError($field))<div class="invalid-feedback">{{ $errors->first($field) }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
</section>
