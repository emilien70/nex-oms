@php($seller = $invoice->seller_snapshot ?? [])
@php($issuer = $invoice->issuer_snapshot ?? [])
@php($payment = $invoice->payment_snapshot ?? [])

<section class="invoice-edit-card">
    <header class="invoice-edit-card-header">
        <h2 class="fs-6 mb-0">Pozostałe dane</h2>
    </header>
    <div class="invoice-edit-card-body">
        <form method="POST" action="{{ route('invoices.details.update', $invoice) }}" data-invoice-ajax-form>
            @csrf
            @method('PATCH')
            <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>

            <h3 class="fs-6 mb-3">Daty i płatność</h3>
            <div class="invoice-edit-form-grid mb-4">
                <div>
                    <label for="invoice-issue-date">Data wystawienia</label>
                    <input id="invoice-issue-date" class="form-control" type="date" name="issue_date" value="{{ $invoice->issue_date?->toDateString() }}" required>
                </div>
                <div>
                    <label for="invoice-sale-date">Data sprzedaży</label>
                    <input id="invoice-sale-date" class="form-control" type="date" name="sale_date" value="{{ $invoice->sale_date?->toDateString() }}" required>
                </div>
                <div>
                    <label for="invoice-payment-due-date">Termin płatności</label>
                    <input id="invoice-payment-due-date" class="form-control" type="date" name="payment_due_date" value="{{ $invoice->payment_due_date?->toDateString() }}">
                </div>
                <div>
                    <label for="invoice-paid-amount">Zapłacono ({{ $invoice->currency }})</label>
                    <input id="invoice-paid-amount" class="form-control" name="paid_amount" value="{{ $invoice->paid_amount }}" inputmode="decimal" required>
                </div>
                <div>
                    <label for="invoice-payment-method">Sposób płatności</label>
                    <input id="invoice-payment-method" class="form-control" name="payment_method" value="{{ $payment['effective_payment_method'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-payment-identifier">Identyfikator płatności</label>
                    <input id="invoice-payment-identifier" class="form-control" name="payment_identifier" value="{{ $payment['payment_identifier'] ?? '' }}">
                </div>
            </div>

            <h3 class="fs-6 mb-3">Wystawienie i sprzedawca</h3>
            <div class="invoice-edit-form-grid mb-4">
                <div>
                    <label for="invoice-place-of-issue">Miejsce wystawienia</label>
                    <input id="invoice-place-of-issue" class="form-control" name="place_of_issue" value="{{ $issuer['place_of_issue'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-issuer-name">Wystawiający</label>
                    <input id="invoice-issuer-name" class="form-control" name="issuer_name" value="{{ $issuer['issuer_name'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-name">Nazwa sprzedawcy</label>
                    <input id="invoice-seller-name" class="form-control" name="seller_name" value="{{ $seller['name'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-tax-id">NIP sprzedawcy</label>
                    <input id="invoice-seller-tax-id" class="form-control" name="seller_tax_id" value="{{ $seller['tax_id'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-regon">REGON</label>
                    <input id="invoice-seller-regon" class="form-control" name="seller_regon" value="{{ $seller['regon'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-bdo">BDO</label>
                    <input id="invoice-seller-bdo" class="form-control" name="seller_bdo" value="{{ $seller['bdo'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-street">Ulica</label>
                    <input id="invoice-seller-street" class="form-control" name="seller_street" value="{{ $seller['street'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-building-number">Numer budynku</label>
                    <input id="invoice-seller-building-number" class="form-control" name="seller_building_number" value="{{ $seller['building_number'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-apartment-number">Numer lokalu</label>
                    <input id="invoice-seller-apartment-number" class="form-control" name="seller_apartment_number" value="{{ $seller['apartment_number'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-postal-code">Kod pocztowy</label>
                    <input id="invoice-seller-postal-code" class="form-control" name="seller_postal_code" value="{{ $seller['postal_code'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-city">Miasto</label>
                    <input id="invoice-seller-city" class="form-control" name="seller_city" value="{{ $seller['city'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-province">Województwo</label>
                    <input id="invoice-seller-province" class="form-control" name="seller_province" value="{{ $seller['province'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-country">Kraj</label>
                    <select id="invoice-seller-country" class="form-select" name="seller_country_code">
                        <option value="">— Wybierz kraj —</option>
                        @foreach($countries as $code => $name)
                            <option value="{{ $code }}" @selected(($seller['country_code'] ?? null) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="invoice-seller-email">E-mail</label>
                    <input id="invoice-seller-email" class="form-control" type="email" name="seller_email" value="{{ $seller['email'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-phone">Telefon</label>
                    <input id="invoice-seller-phone" class="form-control" name="seller_phone" value="{{ $seller['phone'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-bank-name">Bank</label>
                    <input id="invoice-seller-bank-name" class="form-control" name="seller_bank_name" value="{{ $seller['bank_name'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-bank-account">Numer rachunku</label>
                    <input id="invoice-seller-bank-account" class="form-control" name="seller_bank_account" value="{{ $seller['bank_account'] ?? '' }}">
                </div>
                <div>
                    <label for="invoice-seller-bank-swift">SWIFT/BIC</label>
                    <input id="invoice-seller-bank-swift" class="form-control" name="seller_bank_swift" value="{{ $seller['bank_swift'] ?? '' }}">
                </div>
                <div class="full">
                    <label for="invoice-additional-information">Informacje dodatkowe</label>
                    <textarea id="invoice-additional-information" class="form-control" name="additional_information_text" rows="5">{{ $invoice->additional_information_text }}</textarea>
                </div>
            </div>

            <button class="btn btn-sm btn-primary" type="submit">Zapisz</button>
        </form>
    </div>
</section>
