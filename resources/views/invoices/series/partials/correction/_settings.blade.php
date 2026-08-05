<section class="invoice-series-form-section" aria-labelledby="correction-settings-heading">
    <h3 id="correction-settings-heading">Ustawienia korekty</h3>
    @php
        $selectedCorrectionReason = (string) $correctionValue('default_correction_reason');
        $knownCorrectionReason = collect($correctionReasons)
            ->contains(static fn ($reason): bool => $reason->value === $selectedCorrectionReason);
    @endphp
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label" for="correction-default-reason">Domyślny powód korekty</label>
            <select class="form-select form-select-sm {{ $correctionHasError('default_correction_reason') ? 'is-invalid' : '' }}" id="correction-default-reason" name="default_correction_reason">
                <option value="" @selected($selectedCorrectionReason === '')>— Brak domyślnego powodu —</option>
                @if ($selectedCorrectionReason !== '' && ! $knownCorrectionReason)
                    <option value="{{ $selectedCorrectionReason }}" selected>Dotychczasowy powód: {{ $selectedCorrectionReason }}</option>
                @endif
                @foreach ($correctionReasons as $reason)
                    <option value="{{ $reason->value }}" @selected($selectedCorrectionReason === $reason->value)>{{ $reason->label() }}</option>
                @endforeach
            </select>
            <div class="form-text">Wartość zostanie podpowiedziana przy wystawianiu korekty i będzie mogła zostać zmieniona dla konkretnego dokumentu.</div>
            @if ($correctionHasError('default_correction_reason'))<div class="invalid-feedback">{{ $errors->first('default_correction_reason') }}</div>@endif
        </div>
        <div class="col-12">
            <label class="form-label" for="correction-sale-date-source">Data sprzedaży</label>
            <select class="form-select form-select-sm {{ $correctionHasError('correction_sale_date_source') ? 'is-invalid' : '' }}" id="correction-sale-date-source" name="correction_sale_date_source" required>
                @foreach ($correctionSaleDateSources as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('correction_sale_date_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('correction_sale_date_source'))<div class="invalid-feedback">{{ $errors->first('correction_sale_date_source') }}</div>@endif
        </div>
    </div>
</section>
