<section class="invoice-series-form-section" aria-labelledby="invoice-series-information-heading">
    <h3 id="invoice-series-information-heading">Informacje</h3>
    <label class="form-label" for="invoice-series-information">Informacje</label>
    <textarea class="form-control form-control-sm {{ $invoiceHasError('additional_information_template') ? 'is-invalid' : '' }}" id="invoice-series-information" name="additional_information_template" rows="5">{{ $invoiceValue('additional_information_template') }}</textarea>
    <div class="form-text">
        Token <code>[uwagi_sprzedawcy]</code> zostanie zastąpiony treścią uwag zamówienia dopiero podczas wystawiania dokumentu. Na tym etapie zapisywany jest wyłącznie szablon.
    </div>
    @if ($invoiceHasError('additional_information_template'))<div class="invalid-feedback">{{ $errors->first('additional_information_template') }}</div>@endif
</section>
