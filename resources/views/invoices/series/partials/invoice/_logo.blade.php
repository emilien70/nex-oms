<section class="invoice-series-form-section" aria-labelledby="invoice-series-logo-heading">
    <h3 id="invoice-series-logo-heading">Logo</h3>
    <div class="row g-3 align-items-end">
        <div class="col-md-8">
            <label class="form-label" for="invoice-series-logo">Plik logo</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('logo') ? 'is-invalid' : '' }}" id="invoice-series-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">PNG, JPG, JPEG lub WEBP, maksymalnie 2 MB. Plik zostanie zapisany prywatnie.</div>
            @if ($invoiceHasError('logo'))<div class="invalid-feedback">{{ $errors->first('logo') }}</div>@endif
        </div>
        <div class="col-md-4">
            @if ($series?->logo_path)
                <div class="invoice-series-current-logo mb-2">
                    <i class="bi bi-image" aria-hidden="true"></i>
                    Aktualne logo jest zapisane.
                </div>
                <div class="form-check">
                    <input type="hidden" name="remove_logo" value="0">
                    <input class="form-check-input {{ $invoiceHasError('remove_logo') ? 'is-invalid' : '' }}" id="invoice-series-remove-logo" name="remove_logo" type="checkbox" value="1" @checked((bool) $invoiceValue('remove_logo'))>
                    <label class="form-check-label" for="invoice-series-remove-logo">Usuń logo</label>
                    @if ($invoiceHasError('remove_logo'))<div class="invalid-feedback">{{ $errors->first('remove_logo') }}</div>@endif
                </div>
            @else
                <input type="hidden" name="remove_logo" value="0">
                <div class="invoice-series-current-logo">Brak zapisanego logo.</div>
            @endif
        </div>
    </div>
</section>
