<div class="modal fade inpost-modal" id="inpostAccountModal" tabindex="-1" aria-labelledby="inpostAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="inpostAccountModalLabel">Konfiguracja InPost Paczkomaty</h2>
                    <div class="small text-secondary">Jedno konto API u&#380;ywane do nadawania przesy&#322;ek.</div>
                </div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#accountConnectionTab" type="button" role="tab">Po&#322;&#261;czenie API</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#accountSenderTab" type="button" role="tab">Nadawca</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#accountShipmentTab" type="button" role="tab">Ustawienia przesy&#322;ek</button></li>
                </ul>

                <form id="inpostAccountForm" method="POST" action="{{ route('integrations.couriers.inpost-lockers.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div
                        id="inpostAccountValidationAlert"
                        class="alert alert-danger mx-3 mt-3 mb-0 py-2 px-3 small {{ $errors->hasAny($configurationFields) ? '' : 'd-none' }}"
                        role="alert"
                    >
                        Uzupe&#322;nij wymagane pola oznaczone na czerwono.
                    </div>
                    <div class="tab-content p-3">
                        <div class="tab-pane fade show active" id="accountConnectionTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="account_name">Nazwa konta</label><input id="account_name" class="form-control form-control-sm @error('name') is-invalid @enderror" name="name" value="{{ old('name', $account->name ?: 'InPost Paczkomaty') }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="account_environment">&#346;rodowisko</label><select id="account_environment" class="form-select form-select-sm" name="environment" required><option value="sandbox" @selected(old('environment', $account->environment) === 'sandbox')>Sandbox - testowe</option><option value="production" @selected(old('environment', $account->environment) === 'production')>Produkcja</option></select></div>
                                <div class="col-md-6"><label class="form-label" for="account_organization_id">Organization ID</label><input id="account_organization_id" class="form-control form-control-sm @error('organization_id') is-invalid @enderror" name="organization_id" value="{{ old('organization_id', $account->organization_id) }}" required>@error('organization_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="account_api_token">Token API</label><input id="account_api_token" class="form-control form-control-sm @error('api_token') is-invalid @enderror" type="password" name="api_token" autocomplete="new-password" placeholder="{{ $account->resolvedApiToken() ? 'Pozostaw puste, aby zachowac token' : 'Wpisz token API' }}">@error('api_token')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-12"><div class="form-check form-switch"><input id="account_is_active" class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))><label class="form-check-label small" for="account_is_active">Konto aktywne</label></div></div>
                                @if ($account->exists)
                                    <div class="col-12">
                                        <div class="alert {{ $account->last_error ? 'alert-danger' : 'alert-light border' }} py-2 px-3 mb-0 small">
                                            @if ($account->last_error)
                                                <div>{{ $account->last_error }}</div>
                                            @elseif ($account->last_tested_at)
                                                <div>Po&#322;&#261;czenie sprawdzone {{ $account->last_tested_at->format('d.m.Y H:i') }}.</div>
                                                @if (session('inpost_connection_success'))
                                                    <div class="mt-1 text-success fw-semibold">{{ session('inpost_connection_success') }}</div>
                                                @endif
                                            @else
                                                <div>Po&#322;&#261;czenie nie by&#322;o jeszcze testowane.</div>
                                            @endif
                                            @error('inpost_connection')
                                                <div class="mt-1">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="tab-pane fade" id="accountSenderTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label" for="sender_company_name">Nadawca - nazwa firmy</label><input id="sender_company_name" class="form-control form-control-sm @error('sender_company_name') is-invalid @enderror" name="sender_company_name" value="{{ old('sender_company_name', data_get($settings, 'sender_company_name')) }}" required>@error('sender_company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="sender_contact_name">Nadawca - osoba kontaktowa</label><input id="sender_contact_name" class="form-control form-control-sm @error('sender_contact_name') is-invalid @enderror" name="sender_contact_name" value="{{ old('sender_contact_name', data_get($settings, 'sender_contact_name')) }}" required><div class="inpost-modal-help">Podaj imi&#281; i nazwisko.</div>@error('sender_contact_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label" for="sender_street">Nadawca - ulica</label><input id="sender_street" class="form-control form-control-sm" name="sender_street" value="{{ old('sender_street', data_get($settings, 'sender_street')) }}" required></div>
                                <div class="col-md-3"><label class="form-label" for="sender_building_number">Numer domu</label><input id="sender_building_number" class="form-control form-control-sm" name="sender_building_number" value="{{ old('sender_building_number', data_get($settings, 'sender_building_number')) }}" required></div>
                                <div class="col-md-3"><label class="form-label" for="sender_apartment_number">Numer lokalu</label><input id="sender_apartment_number" class="form-control form-control-sm" name="sender_apartment_number" value="{{ old('sender_apartment_number', data_get($settings, 'sender_apartment_number')) }}"></div>
                                <div class="col-md-4"><label class="form-label" for="sender_postal_code">Kod pocztowy</label><input id="sender_postal_code" class="form-control form-control-sm" name="sender_postal_code" value="{{ old('sender_postal_code', data_get($settings, 'sender_postal_code')) }}" required></div>
                                <div class="col-md-5"><label class="form-label" for="sender_city">Miasto</label><input id="sender_city" class="form-control form-control-sm" name="sender_city" value="{{ old('sender_city', data_get($settings, 'sender_city')) }}" required></div>
                                <div class="col-md-3"><label class="form-label" for="sender_country_code">Kraj</label><input id="sender_country_code" class="form-control form-control-sm text-uppercase" name="sender_country_code" maxlength="2" value="{{ old('sender_country_code', data_get($settings, 'sender_country_code', 'PL')) }}" required></div>
                                <div class="col-md-6"><label class="form-label" for="sender_phone">Telefon</label><input id="sender_phone" class="form-control form-control-sm" type="tel" name="sender_phone" value="{{ old('sender_phone', data_get($settings, 'sender_phone')) }}" required></div>
                                <div class="col-md-6"><label class="form-label" for="sender_email">E-mail</label><input id="sender_email" class="form-control form-control-sm" type="email" name="sender_email" value="{{ old('sender_email', data_get($settings, 'sender_email')) }}" required></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="accountShipmentTab" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="account_default_parcel_template">Domy&#347;lny gabaryt</label><select id="account_default_parcel_template" class="form-select form-select-sm" name="default_parcel_template" required><option value="small" @selected(old('default_parcel_template', data_get($settings, 'default_parcel_template', 'medium')) === 'small')>A - ma&#322;y</option><option value="medium" @selected(old('default_parcel_template', data_get($settings, 'default_parcel_template', 'medium')) === 'medium')>B - &#347;redni</option><option value="large" @selected(old('default_parcel_template', data_get($settings, 'default_parcel_template', 'medium')) === 'large')>C - du&#380;y</option></select></div>
                                <div class="col-md-4"><label class="form-label" for="account_label_format">Format etykiety</label><select id="account_label_format" class="form-select form-select-sm" name="label_format" required>@foreach (['Pdf' => 'PDF', 'Zpl' => 'ZPL', 'Epl' => 'EPL'] as $value => $label)<option value="{{ $value }}" @selected(old('label_format', data_get($settings, 'label_format', 'Pdf')) === $value)>{{ $label }}</option>@endforeach</select></div>
                                <div class="col-md-4"><label class="form-label" for="account_label_type">Rozmiar etykiety</label><select id="account_label_type" class="form-select form-select-sm" name="label_type" required><option value="A6" @selected(old('label_type', data_get($settings, 'label_type', 'A6')) === 'A6')>A6</option><option value="normal" @selected(old('label_type', data_get($settings, 'label_type', 'A6')) === 'normal')>Normalna</option></select></div>
                                <div class="col-md-6"><label class="form-label" for="account_content_description_source">Opis zawarto&#347;ci</label><select id="account_content_description_source" class="form-select form-select-sm" name="content_description_source" required><option value="order_id" @selected(old('content_description_source', data_get($settings, 'content_description_source', 'order_id')) === 'order_id')>Numer zam&oacute;wienia</option><option value="customer_login" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_login')>Login kupuj&#261;cego</option><option value="customer_email" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_email')>E-mail kupuj&#261;cego</option><option value="customer_phone" @selected(old('content_description_source', data_get($settings, 'content_description_source')) === 'customer_phone')>Telefon kupuj&#261;cego</option></select></div>
                                <div class="col-md-6"><label class="form-label" for="account_sending_method">Spos&oacute;b nadania</label><select id="account_sending_method" class="form-select form-select-sm" name="sending_method" required><option value="parcel_locker" @selected(old('sending_method', data_get($settings, 'sending_method', 'dispatch_order')) === 'parcel_locker')>Nadanie w paczkomacie</option><option value="dispatch_order" @selected(old('sending_method', data_get($settings, 'sending_method', 'dispatch_order')) === 'dispatch_order')>Odbi&oacute;r przez kuriera</option></select></div>
                                <div class="col-md-6" data-sender-point-field><label class="form-label" for="account_sender_point_id">Paczkomat nadawczy</label><input id="account_sender_point_id" class="form-control form-control-sm @error('sender_point_id') is-invalid @enderror" name="sender_point_id" value="{{ old('sender_point_id', data_get($settings, 'sender_point_id')) }}" placeholder="np. PXS01M">@error('sender_point_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                @if ($account->exists)<form class="me-auto" method="POST" action="{{ route('integrations.couriers.inpost-lockers.test') }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Testuj po&#322;&#261;czenie</button></form>@endif
                <button class="btn btn-sm btn-light border" type="button" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-sm btn-primary" type="submit" form="inpostAccountForm">Zapisz</button>
            </div>
        </div>
    </div>
</div>
