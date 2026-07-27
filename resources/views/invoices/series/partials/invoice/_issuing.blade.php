<section class="invoice-series-form-section" aria-labelledby="invoice-series-issuing-heading">
    <h3 id="invoice-series-issuing-heading">Wystawienie dokumentu</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-place-of-issue">Miejsce wystawienia</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('place_of_issue') ? 'is-invalid' : '' }}" id="invoice-series-place-of-issue" name="place_of_issue" type="text" value="{{ $invoiceValue('place_of_issue') }}" maxlength="120">
            @if ($invoiceHasError('place_of_issue'))<div class="invalid-feedback">{{ $errors->first('place_of_issue') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-issuer-name">Osoba wystawiająca</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('issuer_name') ? 'is-invalid' : '' }}" id="invoice-series-issuer-name" name="issuer_name" type="text" value="{{ $invoiceValue('issuer_name') }}" maxlength="255">
            @if ($invoiceHasError('issuer_name'))<div class="invalid-feedback">{{ $errors->first('issuer_name') }}</div>@endif
        </div>
    </div>
</section>
