@php($seller = $invoice->seller_snapshot ?? [])
@php($issuer = $invoice->issuer_snapshot ?? [])
@php($payment = $invoice->payment_snapshot ?? [])

<section class="invoice-edit-card invoice-details-card">
    <header class="invoice-edit-card-header invoice-details-card-header">
        <h2 class="fs-6 mb-0">Pozostałe dane</h2>
    </header>
    <form method="POST" action="{{ route('invoices.details.update', $invoice) }}" data-invoice-ajax-form>
        @csrf
        @method('PATCH')
        <input type="hidden" name="expected_lock_version" value="{{ $invoice->lock_version }}" data-lock-version-input>
        <input type="hidden" name="paid_amount" value="{{ $invoice->paid_amount }}">
        <input type="hidden" name="payment_identifier" value="{{ $payment['payment_identifier'] ?? '' }}">
        @foreach([
            'seller_name' => $seller['name'] ?? null,
            'seller_tax_id' => $seller['tax_id'] ?? null,
            'seller_regon' => $seller['regon'] ?? null,
            'seller_bdo' => $seller['bdo'] ?? null,
            'seller_street' => $seller['street'] ?? null,
            'seller_building_number' => $seller['building_number'] ?? null,
            'seller_apartment_number' => $seller['apartment_number'] ?? null,
            'seller_postal_code' => $seller['postal_code'] ?? null,
            'seller_city' => $seller['city'] ?? null,
            'seller_province' => $seller['province'] ?? null,
            'seller_country_code' => $seller['country_code'] ?? null,
            'seller_email' => $seller['email'] ?? null,
            'seller_phone' => $seller['phone'] ?? null,
            'seller_bank_name' => $seller['bank_name'] ?? null,
            'seller_bank_account' => $seller['bank_account'] ?? null,
            'seller_bank_swift' => $seller['bank_swift'] ?? null,
        ] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <div class="invoice-details-section invoice-details-section-first">
            <div class="alert alert-danger invoice-edit-error" data-form-error hidden></div>
            <div class="invoice-details-fields">
                <div class="invoice-details-field invoice-details-field-with-help">
                    <label for="invoice-issue-date">Data utworzenia</label>
                    <div>
                        <input id="invoice-issue-date" class="form-control" type="date" name="issue_date" value="{{ $invoice->issue_date?->toDateString() }}" required>
                        <div class="invoice-details-help">Data wystawienia Faktury.</div>
                    </div>
                </div>
                <div class="invoice-details-field">
                    <label for="invoice-sale-date">Data sprzedaży</label>
                    <input id="invoice-sale-date" class="form-control" type="date" name="sale_date" value="{{ $invoice->sale_date?->toDateString() }}" required>
                </div>
                <div class="invoice-details-field invoice-details-field-with-help">
                    <label for="invoice-payment-due-date">Termin płatności</label>
                    <div>
                        <input id="invoice-payment-due-date" class="form-control" type="date" name="payment_due_date" value="{{ $invoice->payment_due_date?->toDateString() }}">
                        <div class="invoice-details-help">Podaj datę, aby wyświetlić termin płatności na Fakturze.</div>
                    </div>
                </div>
                <div class="invoice-details-field">
                    <label for="invoice-payment-method">Sposób płatności</label>
                    <input id="invoice-payment-method" class="form-control" name="payment_method" value="{{ $payment['effective_payment_method'] ?? '' }}">
                </div>
            </div>
        </div>

        <div class="invoice-details-section invoice-details-section-last">
            <div class="invoice-details-fields">
                <div class="invoice-details-field invoice-details-field-with-help">
                    <label for="invoice-place-of-issue">Miasto</label>
                    <div>
                        <input id="invoice-place-of-issue" class="form-control" name="place_of_issue" value="{{ $issuer['place_of_issue'] ?? '' }}">
                        <div class="invoice-details-help">Nazwa miasta wyświetlana na Fakturze.</div>
                    </div>
                </div>
                <div class="invoice-details-field invoice-details-field-with-help">
                    <label for="invoice-issuer-name">Wystawiający</label>
                    <div>
                        <input id="invoice-issuer-name" class="form-control" name="issuer_name" value="{{ $issuer['issuer_name'] ?? '' }}">
                        <div class="invoice-details-help">Osoba upoważniona do wystawienia Faktury.</div>
                    </div>
                </div>
                <div class="invoice-details-field invoice-details-field-textarea">
                    <label for="invoice-additional-information">Informacje</label>
                    <div>
                        <textarea id="invoice-additional-information" class="form-control" name="additional_information_text" rows="6">{{ $invoice->additional_information_text }}</textarea>
                        <div class="invoice-details-help">Dodatkowy tekst wyświetlany na dole Faktury.</div>
                    </div>
                </div>
                <div class="invoice-details-actions">
                    <button class="invoice-save-button" type="submit">Zapisz</button>
                </div>
            </div>
        </div>
    </form>
</section>
