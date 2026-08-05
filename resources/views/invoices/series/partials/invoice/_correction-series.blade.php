<section class="invoice-series-form-section invoice-series-form-section--single" aria-labelledby="invoice-series-correction-heading">
    <h3 id="invoice-series-correction-heading">Seria korekt</h3>
    <label class="form-label" for="invoice-series-default-correction">Seria numeracji korekt</label>
    <select class="form-select form-select-sm {{ $invoiceHasError('default_correction_series_id') ? 'is-invalid' : '' }}" id="invoice-series-default-correction" name="default_correction_series_id">
        <option value="">Brak przypisanej serii</option>
        @foreach ($correctionSeries as $correction)
            <option value="{{ $correction->id }}" @selected((string) $invoiceValue('default_correction_series_id') === (string) $correction->id)>
                {{ $correction->name }}{{ $correction->is_active ? '' : ' (ukryta)' }}
            </option>
        @endforeach
    </select>
    @if ($invoiceHasError('default_correction_series_id'))<div class="invalid-feedback">{{ $errors->first('default_correction_series_id') }}</div>@endif
</section>
