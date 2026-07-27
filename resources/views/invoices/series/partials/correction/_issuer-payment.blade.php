<section class="invoice-series-form-section" aria-labelledby="correction-issuer-payment-heading">
    <h3 id="correction-issuer-payment-heading">Wystawiający i płatność</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="correction-issuer-source">Wystawiający</label>
            <select class="form-select form-select-sm {{ $correctionHasError('correction_issuer_source') ? 'is-invalid' : '' }}" id="correction-issuer-source" name="correction_issuer_source" data-correction-control required>
                @foreach ($correctionIssuerSources as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('correction_issuer_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('correction_issuer_source'))<div class="invalid-feedback">{{ $errors->first('correction_issuer_source') }}</div>@endif
        </div>
        <div class="col-md-6" data-correction-dependent="issuer-name">
            <label class="form-label" for="correction-issuer-name">Osoba wystawiająca</label>
            <input class="form-control form-control-sm {{ $correctionHasError('issuer_name') ? 'is-invalid' : '' }}" id="correction-issuer-name" name="issuer_name" type="text" value="{{ $correctionValue('issuer_name') }}" maxlength="255" data-required-when-visible>
            @if ($correctionHasError('issuer_name'))<div class="invalid-feedback">{{ $errors->first('issuer_name') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="correction-payment-source">Sposób płatności</label>
            <select class="form-select form-select-sm {{ $correctionHasError('correction_payment_method_source') ? 'is-invalid' : '' }}" id="correction-payment-source" name="correction_payment_method_source" data-correction-control required>
                @foreach ($correctionPaymentMethodSources as $option)
                    <option value="{{ $option->value }}" @selected($correctionValue('correction_payment_method_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($correctionHasError('correction_payment_method_source'))<div class="invalid-feedback">{{ $errors->first('correction_payment_method_source') }}</div>@endif
        </div>
        <div class="col-md-6" data-correction-dependent="fixed-payment-method">
            <label class="form-label" for="correction-fixed-payment-method">Stały sposób płatności</label>
            <input class="form-control form-control-sm {{ $correctionHasError('fixed_payment_method') ? 'is-invalid' : '' }}" id="correction-fixed-payment-method" name="fixed_payment_method" type="text" value="{{ $correctionValue('fixed_payment_method') }}" maxlength="80" data-required-when-visible>
            @if ($correctionHasError('fixed_payment_method'))<div class="invalid-feedback">{{ $errors->first('fixed_payment_method') }}</div>@endif
        </div>
    </div>
</section>
