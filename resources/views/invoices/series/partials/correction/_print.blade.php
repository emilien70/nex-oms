<section class="invoice-series-form-section" aria-labelledby="correction-print-heading">
    <h3 id="correction-print-heading">Ustawienia wydruku</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="correction-document-title">Nazwa dokumentu</label>
            <input class="form-control form-control-sm {{ $correctionHasError('document_title') ? 'is-invalid' : '' }}" id="correction-document-title" name="document_title" type="text" value="{{ $correctionValue('document_title') }}" maxlength="120" required>
            @if ($correctionHasError('document_title'))<div class="invalid-feedback">{{ $errors->first('document_title') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="correction-print-template">Szablon wydruku</label>
            <select class="form-select form-select-sm {{ $correctionHasError('print_template') ? 'is-invalid' : '' }}" id="correction-print-template" name="print_template" required>
                @foreach ($printTemplates as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('print_template') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('print_template'))<div class="invalid-feedback">{{ $errors->first('print_template') }}</div>@endif
        </div>
        <div class="col-12">
            <label class="form-label" for="correction-print-header">Nagłówek</label>
            <input class="form-control form-control-sm {{ $correctionHasError('print_header') ? 'is-invalid' : '' }}" id="correction-print-header" name="print_header" type="text" value="{{ $correctionValue('print_header') }}" maxlength="255">
            <div class="form-text">Tekst wyświetlany u góry dokumentu. Jeśli pole pozostanie puste, użyta zostanie nazwa sprzedawcy dokumentu źródłowego.</div>
            @if ($correctionHasError('print_header'))<div class="invalid-feedback">{{ $errors->first('print_header') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="correction-primary-language">Język główny</label>
            <select class="form-select form-select-sm {{ $correctionHasError('primary_language') ? 'is-invalid' : '' }}" id="correction-primary-language" name="primary_language" required>
                @foreach ($primaryLanguages as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('primary_language') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('primary_language'))<div class="invalid-feedback">{{ $errors->first('primary_language') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="correction-secondary-language">Język dodatkowy</label>
            <select class="form-select form-select-sm {{ $correctionHasError('secondary_language') ? 'is-invalid' : '' }}" id="correction-secondary-language" name="secondary_language">
                <option value="">Brak</option>
                @foreach ($secondaryLanguages as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('secondary_language') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('secondary_language'))<div class="invalid-feedback">{{ $errors->first('secondary_language') }}</div>@endif
        </div>
        <div class="col-md-2">
            <label class="form-label" for="correction-copies-count">Liczba kopii</label>
            <input class="form-control form-control-sm {{ $correctionHasError('copies_count') ? 'is-invalid' : '' }}" id="correction-copies-count" name="copies_count" type="number" min="1" max="10" step="1" value="{{ $correctionValue('copies_count') }}" required>
            @if ($correctionHasError('copies_count'))<div class="invalid-feedback">{{ $errors->first('copies_count') }}</div>@endif
        </div>
        <div class="col-md-4">
            <label class="form-label" for="correction-unit-price-mode">Cena jednostkowa</label>
            <select class="form-select form-select-sm {{ $correctionHasError('unit_price_mode') ? 'is-invalid' : '' }}" id="correction-unit-price-mode" name="unit_price_mode" required>
                @foreach ($unitPriceModes as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('unit_price_mode') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('unit_price_mode'))<div class="invalid-feedback">{{ $errors->first('unit_price_mode') }}</div>@endif
        </div>
        @foreach ([
            'show_vat_column' => 'Pokaż kolumnę VAT',
            'show_order_number' => 'Pokaż numer zamówienia',
            'show_buyer_signature' => 'Pokaż miejsce na podpis odbiorcy',
            'show_original_copy' => 'Oryginał/kopia',
        ] as $field => $label)
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check mb-1">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input class="form-check-input {{ $correctionHasError($field) ? 'is-invalid' : '' }}" id="correction-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) $correctionValue($field))>
                    <label class="form-check-label" for="correction-{{ str_replace('_', '-', $field) }}">{{ $label }}</label>
                    @if ($correctionHasError($field))<div class="invalid-feedback">{{ $errors->first($field) }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
</section>
