<section class="invoice-series-form-section" aria-labelledby="correction-information-heading">
    <h3 id="correction-information-heading">Informacje</h3>
    <label class="form-label" for="correction-information">Informacje</label>
    <textarea class="form-control form-control-sm {{ $correctionHasError('additional_information_template') ? 'is-invalid' : '' }}" id="correction-information" name="additional_information_template" rows="5">{{ $correctionValue('additional_information_template') }}</textarea>
    <div class="form-text">Token <code>[uwagi_sprzedawcy]</code> zostanie rozwiązany dopiero podczas wystawiania korekty. Obecnie zapisywany jest wyłącznie szablon.</div>
    @if ($correctionHasError('additional_information_template'))<div class="invalid-feedback">{{ $errors->first('additional_information_template') }}</div>@endif
</section>
