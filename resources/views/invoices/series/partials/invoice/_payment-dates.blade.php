<section class="invoice-series-form-section" aria-labelledby="invoice-series-payment-heading">
    <h3 id="invoice-series-payment-heading">Płatność i daty</h3>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-payment-source">Sposób płatności</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('payment_method_source') ? 'is-invalid' : '' }}" id="invoice-series-payment-source" name="payment_method_source" data-invoice-control required>
                @foreach ($paymentMethodSources as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('payment_method_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('payment_method_source'))<div class="invalid-feedback">{{ $errors->first('payment_method_source') }}</div>@endif
        </div>
        <div class="col-md-6" data-invoice-dependent="fixed-payment-method">
            <label class="form-label" for="invoice-series-fixed-payment">Stały sposób płatności</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('fixed_payment_method') ? 'is-invalid' : '' }}" id="invoice-series-fixed-payment" name="fixed_payment_method" type="text" value="{{ $invoiceValue('fixed_payment_method') }}" maxlength="80">
            @if ($invoiceHasError('fixed_payment_method'))<div class="invalid-feedback">{{ $errors->first('fixed_payment_method') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="invoice-series-sale-date-source">Data sprzedaży</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('sale_date_source') ? 'is-invalid' : '' }}" id="invoice-series-sale-date-source" name="sale_date_source" required>
                @foreach ($saleDateSources as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('sale_date_source') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('sale_date_source'))<div class="invalid-feedback">{{ $errors->first('sale_date_source') }}</div>@endif
        </div>
        <div class="col-md-3">
            <label class="form-label" for="invoice-series-payment-due-mode">Termin płatności</label>
            <select class="form-select form-select-sm {{ $invoiceHasError('payment_due_mode') ? 'is-invalid' : '' }}" id="invoice-series-payment-due-mode" name="payment_due_mode" data-invoice-control required>
                @foreach ($paymentDueModes as $option)
                    <option value="{{ $option->value }}" @selected($invoiceValue('payment_due_mode') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($invoiceHasError('payment_due_mode'))<div class="invalid-feedback">{{ $errors->first('payment_due_mode') }}</div>@endif
        </div>
        <div class="col-md-3" data-invoice-dependent="payment-due-days">
            <label class="form-label" for="invoice-series-payment-due-days">Liczba dni</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('payment_due_days') ? 'is-invalid' : '' }}" id="invoice-series-payment-due-days" name="payment_due_days" type="number" min="0" max="365" step="1" value="{{ $invoiceValue('payment_due_days') }}">
            @if ($invoiceHasError('payment_due_days'))<div class="invalid-feedback">{{ $errors->first('payment_due_days') }}</div>@endif
        </div>
    </div>
</section>
