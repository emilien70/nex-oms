<section class="invoice-series-form-section" aria-labelledby="correction-items-header-heading">
    <h3 id="correction-items-header-heading">Pozycje i nagłówek</h3>
    <div class="row g-3">
        @foreach ([
            'show_correction_item_sequence' => 'Pokaż numer porządkowy pozycji',
            'show_return_id_in_header' => 'Pokaż identyfikator zwrotu w nagłówku',
            'show_payment_identifier' => 'Pokaż identyfikator płatności',
        ] as $field => $label)
            <div class="col-md-4">
                <div class="form-check">
                    <input type="hidden" name="{{ $field }}" value="0">
                    <input class="form-check-input {{ $correctionHasError($field) ? 'is-invalid' : '' }}" id="correction-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) $correctionValue($field))>
                    <label class="form-check-label" for="correction-{{ str_replace('_', '-', $field) }}">{{ $label }}</label>
                    @if ($correctionHasError($field))<div class="invalid-feedback">{{ $errors->first($field) }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
</section>
