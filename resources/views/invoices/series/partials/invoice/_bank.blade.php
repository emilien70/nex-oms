<section class="invoice-series-form-section" aria-labelledby="invoice-series-bank-heading">
    <h3 id="invoice-series-bank-heading">Rachunek bankowy</h3>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label" for="invoice-series-bank-name">Nazwa banku</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_bank_name') ? 'is-invalid' : '' }}" id="invoice-series-bank-name" name="seller_bank_name" type="text" value="{{ $invoiceValue('seller_bank_name') }}" maxlength="255">
            @if ($invoiceHasError('seller_bank_name'))<div class="invalid-feedback">{{ $errors->first('seller_bank_name') }}</div>@endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="invoice-series-bank-account">Numer rachunku</label>
            <input class="form-control form-control-sm {{ $invoiceHasError('seller_bank_account') ? 'is-invalid' : '' }}" id="invoice-series-bank-account" name="seller_bank_account" type="text" value="{{ $invoiceValue('seller_bank_account') }}" maxlength="64">
            @if ($invoiceHasError('seller_bank_account'))<div class="invalid-feedback">{{ $errors->first('seller_bank_account') }}</div>@endif
        </div>
        <div class="col-md-2">
            <label class="form-label" for="invoice-series-bank-swift">SWIFT/BIC</label>
            <input class="form-control form-control-sm text-uppercase {{ $invoiceHasError('seller_bank_swift') ? 'is-invalid' : '' }}" id="invoice-series-bank-swift" name="seller_bank_swift" type="text" value="{{ $invoiceValue('seller_bank_swift') }}" maxlength="11">
            @if ($invoiceHasError('seller_bank_swift'))<div class="invalid-feedback">{{ $errors->first('seller_bank_swift') }}</div>@endif
        </div>
    </div>
</section>
