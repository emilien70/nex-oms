<div class="modal fade inpost-modal" id="inpostCourierAccountModal" tabindex="-1" aria-labelledby="inpostCourierAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="inpostCourierAccountModalLabel">Konfiguracja InPost Kurier</h2>
                    <div class="small text-secondary">Jedno konto API u&#380;ywane do nadawania przesy&#322;ek kurierskich.</div>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#courierConnectionTab" type="button" role="tab">Po&#322;&#261;czenie API</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#courierSenderTab" type="button" role="tab">Nadawca</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#courierShipmentTab" type="button" role="tab">Ustawienia przesy&#322;ek</button></li>
                </ul>

                <form id="inpostCourierAccountForm" method="POST" action="{{ route('integrations.couriers.inpost-courier.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div
                        id="inpostCourierValidationAlert"
                        class="alert alert-danger mx-3 mt-3 mb-0 py-2 px-3 small {{ $errors->hasAny($configurationFields) ? '' : 'd-none' }}"
                        role="alert"
                    >
                        Uzupe&#322;nij wymagane pola oznaczone na czerwono.
                    </div>

                    <div class="tab-content p-3">
                        <div class="tab-pane fade show active" id="courierConnectionTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="courier_account_name">Nazwa konta</label>
                                    <input id="courier_account_name" class="form-control form-control-sm @error('name') is-invalid @enderror" name="name" value="{{ old('name', $account->name ?: 'InPost Kurier') }}" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="courier_account_environment">&#346;rodowisko</label>
                                    <select id="courier_account_environment" class="form-select form-select-sm @error('environment') is-invalid @enderror" name="environment" required>
                                        <option value="sandbox" @selected(old('environment', $account->environment) === 'sandbox')>Sandbox - testowe</option>
                                        <option value="production" @selected(old('environment', $account->environment) === 'production')>Produkcja</option>
                                    </select>
                                    @error('environment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="courier_account_organization_id">Organization ID</label>
                                    <input id="courier_account_organization_id" class="form-control form-control-sm @error('organization_id') is-invalid @enderror" name="organization_id" value="{{ old('organization_id', $account->organization_id) }}" required>
                                    @error('organization_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="courier_account_api_token">Token API</label>
                                    <input id="courier_account_api_token" class="form-control form-control-sm @error('api_token') is-invalid @enderror" type="password" name="api_token" autocomplete="new-password" placeholder="{{ $account->resolvedApiToken() ? 'Pozostaw puste, aby zachowac token' : 'Wpisz token API' }}">
                                    @error('api_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <input type="hidden" name="is_active" value="0">
                                    <div class="form-check form-switch">
                                        <input id="courier_account_is_active" class="form-check-input" type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $account->is_active))>
                                        <label class="form-check-label small" for="courier_account_is_active">Konto aktywne</label>
                                    </div>
                                </div>
                                @if ($account->exists)
                                    <div class="col-12">
                                        <div class="alert {{ $account->last_error ? 'alert-danger' : 'alert-light border' }} py-2 px-3 mb-0 small">
                                            @if ($account->last_error)
                                                <div>{{ $account->last_error }}</div>
                                            @elseif ($account->last_tested_at)
                                                <div>Po&#322;&#261;czenie sprawdzone {{ $account->last_tested_at->format('d.m.Y H:i') }}.</div>
                                                @if (session('inpost_courier_connection_success'))
                                                    <div class="mt-1 text-success fw-semibold">{{ session('inpost_courier_connection_success') }}</div>
                                                @endif
                                            @else
                                                <div>Po&#322;&#261;czenie nie by&#322;o jeszcze testowane.</div>
                                            @endif
                                            @error('inpost_courier_connection')<div class="mt-1">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="tab-pane fade" id="courierSenderTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="courier_sender_company_name">Nadawca - nazwa firmy</label><input id="courier_sender_company_name" class="form-control form-control-sm @error('sender_company_name') is-invalid @enderror" name="sender_company_name" value="{{ old('sender_company_name', data_get($settings, 'sender_company_name')) }}" required>@error('sender_company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="courier_sender_contact_name">Nadawca - osoba kontaktowa</label><input id="courier_sender_contact_name" class="form-control form-control-sm @error('sender_contact_name') is-invalid @enderror" name="sender_contact_name" value="{{ old('sender_contact_name', data_get($settings, 'sender_contact_name')) }}" required><div class="inpost-modal-help">Podaj imi&#281; i nazwisko.</div>@error('sender_contact_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="courier_sender_street">Nadawca - ulica</label><input id="courier_sender_street" class="form-control form-control-sm @error('sender_street') is-invalid @enderror" name="sender_street" value="{{ old('sender_street', data_get($settings, 'sender_street')) }}" required>@error('sender_street')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-3"><label class="form-label" for="courier_sender_building_number">Numer domu</label><input id="courier_sender_building_number" class="form-control form-control-sm @error('sender_building_number') is-invalid @enderror" name="sender_building_number" value="{{ old('sender_building_number', data_get($settings, 'sender_building_number')) }}" required>@error('sender_building_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-3"><label class="form-label" for="courier_sender_apartment_number">Numer lokalu</label><input id="courier_sender_apartment_number" class="form-control form-control-sm @error('sender_apartment_number') is-invalid @enderror" name="sender_apartment_number" value="{{ old('sender_apartment_number', data_get($settings, 'sender_apartment_number')) }}">@error('sender_apartment_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label class="form-label" for="courier_sender_postal_code">Kod pocztowy</label><input id="courier_sender_postal_code" class="form-control form-control-sm @error('sender_postal_code') is-invalid @enderror" name="sender_postal_code" value="{{ old('sender_postal_code', data_get($settings, 'sender_postal_code')) }}" required>@error('sender_postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-5"><label class="form-label" for="courier_sender_city">Miasto</label><input id="courier_sender_city" class="form-control form-control-sm @error('sender_city') is-invalid @enderror" name="sender_city" value="{{ old('sender_city', data_get($settings, 'sender_city')) }}" required>@error('sender_city')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-3"><label class="form-label" for="courier_sender_country_code">Kraj</label><input id="courier_sender_country_code" class="form-control form-control-sm text-uppercase @error('sender_country_code') is-invalid @enderror" name="sender_country_code" maxlength="2" value="{{ old('sender_country_code', data_get($settings, 'sender_country_code', 'PL')) }}" required>@error('sender_country_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="courier_sender_phone">Telefon</label><input id="courier_sender_phone" class="form-control form-control-sm @error('sender_phone') is-invalid @enderror" type="tel" name="sender_phone" value="{{ old('sender_phone', data_get($settings, 'sender_phone')) }}" required>@error('sender_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="courier_sender_email">E-mail</label><input id="courier_sender_email" class="form-control form-control-sm @error('sender_email') is-invalid @enderror" type="email" name="sender_email" value="{{ old('sender_email', data_get($settings, 'sender_email')) }}" required>@error('sender_email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="courierShipmentTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="courier_default_service">Domy&#347;lna us&#322;uga</label>
                                    <select id="courier_default_service" class="form-select form-select-sm @error('default_service') is-invalid @enderror" name="default_service" required>
                                        @foreach ($serviceLabels as $service => $label)
                                            <option value="{{ $service }}" @selected(old('default_service', data_get($settings, 'default_service', \Modules\Shipments\Models\Shipment::SERVICE_INPOST_COURIER_STANDARD)) === $service)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('default_service')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                @foreach (['default_weight' => ['Waga (kg)', 1, 50], 'default_length' => ['D&#322;ugo&#347;&#263; (cm)', 25, 350], 'default_width' => ['Szeroko&#347;&#263; (cm)', 20, 350], 'default_height' => ['Wysoko&#347;&#263; (cm)', 10, 350]] as $field => [$label, $default, $max])
                                    <div class="col-md-3"><label class="form-label" for="courier_{{ $field }}">{!! $label !!}</label><input id="courier_{{ $field }}" class="form-control form-control-sm @error($field) is-invalid @enderror" name="{{ $field }}" type="number" min="0.01" max="{{ $max }}" step="0.01" value="{{ old($field, data_get($settings, $field, $default)) }}" required>@error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                @endforeach
                                <div class="col-md-6"><label class="form-label" for="courier_default_insurance_amount">Domy&#347;lne ubezpieczenie (PLN)</label><input id="courier_default_insurance_amount" class="form-control form-control-sm @error('default_insurance_amount') is-invalid @enderror" name="default_insurance_amount" type="number" min="0" max="999999.99" step="0.01" value="{{ old('default_insurance_amount', data_get($settings, 'default_insurance_amount', 0)) }}">@error('default_insurance_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="courier_content_description_source">Opis zawarto&#347;ci</label><select id="courier_content_description_source" class="form-select form-select-sm" name="content_description_source" required><option value="order_id" @selected(old('content_description_source', data_get($settings, 'content_description_source', 'order_id')) === 'order_id')>Numer zam&oacute;wienia</option><option value="customer_login" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_login')>Login kupuj&#261;cego</option><option value="customer_email" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_email')>E-mail kupuj&#261;cego</option><option value="customer_phone" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_phone')>Telefon kupuj&#261;cego</option></select></div>
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach (['default_sms' => 'Powiadomienie SMS', 'default_email' => 'Powiadomienie e-mail', 'default_saturday' => 'Dor&#281;czenie w sobot&#281;', 'default_return_documents' => 'Zwrot dokument&oacute;w'] as $field => $label)
                                            <div class="form-check"><input type="hidden" name="{{ $field }}" value="0"><input class="form-check-input" id="courier_{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked((bool) old($field, data_get($settings, $field, false)))><label class="form-check-label small" for="courier_{{ $field }}">{!! $label !!}</label></div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="col-md-6"><label class="form-label" for="courier_label_format">Format etykiety</label><select id="courier_label_format" class="form-select form-select-sm" name="label_format" required>@foreach (['Pdf' => 'PDF', 'Zpl' => 'ZPL', 'Epl' => 'EPL'] as $format => $label)<option value="{{ $format }}" @selected(old('label_format', data_get($settings, 'label_format', 'Pdf')) === $format)>{{ $label }}</option>@endforeach</select></div>
                                <div class="col-md-6"><label class="form-label" for="courier_label_type">Rozmiar etykiety</label><select id="courier_label_type" class="form-select form-select-sm" name="label_type" required><option value="A6" @selected(old('label_type', data_get($settings, 'label_type', 'A6')) === 'A6')>A6</option><option value="normal" @selected(old('label_type', data_get($settings, 'label_type')) === 'normal')>Normalna</option></select></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                @if ($account->exists)<form class="me-auto" method="POST" action="{{ route('integrations.couriers.inpost-courier.test') }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Testuj po&#322;&#261;czenie</button></form>@endif
                <button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-sm btn-primary" type="submit" form="inpostCourierAccountForm">Zapisz</button>
            </div>
        </div>
    </div>
</div>
