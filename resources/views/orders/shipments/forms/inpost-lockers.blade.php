@php
    $restoreOldInput = old('shipment_provider') === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_LOCKERS;
    $fieldValue = fn (string $name, mixed $default = null) => $restoreOldInput ? old($name, $default) : $default;
    $selectedService = $fieldValue('service', data_get($fields, 'service'));
    $parcelTemplate = $fieldValue('parcel_template', data_get($fields, 'parcel_template', 'medium'));
    $additionalServices = $fieldValue('additional_services', data_get($fields, 'additional_services', []));
@endphp

<div class="shipments-form-wrap" data-courier-form-panel data-courier-provider="{{ $account->provider }}">
    <form method="POST" action="{{ route('orders.shipments.inpost.store', $order) }}" class="shipments-form-panel" data-ajax-shipment-form data-courier-form-loaded="1">
        @csrf
        <input type="hidden" name="shipment_provider" value="{{ \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_LOCKERS }}">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_service">Us&#322;uga</label>
                    <select id="shipment_service" class="form-select form-select-sm" name="service" required>
                        <option value="{{ \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_STANDARD }}" @selected($selectedService === \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_STANDARD)>Paczkomaty 24/7 - Przesy&#322;ka standardowa</option>
                        <option value="{{ \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_ALLEGRO }}" @selected($selectedService === \Modules\Shipments\Models\Shipment::SERVICE_INPOST_LOCKER_ALLEGRO)>Allegro Paczkomaty 24/7 InPost</option>
                    </select>
                </div>
                <div class="shipment-form-row is-top">
                    <span class="shipment-form-label">Rozmiar</span>
                    <div class="shipment-size-options">
                        <label class="shipment-size-option"><input class="form-check-input" type="radio" name="parcel_template" value="small" @checked($parcelTemplate === 'small') required> Gabaryt A (8 x 38 x 64 cm)</label>
                        <label class="shipment-size-option"><input class="form-check-input" type="radio" name="parcel_template" value="medium" @checked($parcelTemplate === 'medium') required> Gabaryt B (19 x 38 x 64 cm)</label>
                        <label class="shipment-size-option"><input class="form-check-input" type="radio" name="parcel_template" value="large" @checked($parcelTemplate === 'large') required> Gabaryt C (41 x 38 x 64 cm)</label>
                    </div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_target_point">Paczkomat docelowy</label>
                    <input id="shipment_target_point" class="form-control form-control-sm @error('target_point_id') is-invalid @enderror" type="text" name="target_point_id" value="{{ $fieldValue('target_point_id', data_get($fields, 'target_point_id')) }}" placeholder="np. WAW01M" required>
                    @error('target_point_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_cod_amount">Pobranie</label>
                    <div class="input-group input-group-sm"><input id="shipment_cod_amount" class="form-control" type="number" name="cod_amount" min="0" step="0.01" value="{{ $fieldValue('cod_amount', data_get($fields, 'cod_amount')) }}" placeholder="0.00"><span class="input-group-text">PLN</span></div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_insurance_amount">Ubezpieczenie</label>
                    <div class="input-group input-group-sm"><input id="shipment_insurance_amount" class="form-control" type="number" name="insurance_amount" min="0" step="0.01" value="{{ $fieldValue('insurance_amount', data_get($fields, 'insurance_amount')) }}" placeholder="0.00"><span class="input-group-text">PLN</span></div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_content_description">Opis zawarto&#347;ci</label>
                    <input id="shipment_content_description" class="form-control form-control-sm @error('content_description') is-invalid @enderror" type="text" name="content_description" maxlength="100" value="{{ $fieldValue('content_description', data_get($fields, 'content_description')) }}">
                    @error('content_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="shipment_sending_method">Spos&oacute;b nadania</label>
                    <select id="shipment_sending_method" class="form-select form-select-sm" name="sending_method" required>
                        <option value="parcel_locker" @selected($fieldValue('sending_method', data_get($fields, 'sending_method')) === 'parcel_locker')>Nadanie w paczkomacie</option>
                        <option value="dispatch_order" @selected($fieldValue('sending_method', data_get($fields, 'sending_method')) === 'dispatch_order')>Odbi&oacute;r przez kuriera</option>
                    </select>
                </div>
                <div class="shipment-form-row is-top">
                    <span class="shipment-form-label">Us&#322;ugi dodatkowe</span>
                    <div class="shipment-size-options">
                        <label class="shipment-size-option">
                            <input class="form-check-input" type="checkbox" name="additional_services[]" value="{{ \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_WEEKEND }}" @checked(in_array(\Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_WEEKEND, $additionalServices, true))>
                            Paczka w Weekend
                        </label>
                        <label class="shipment-size-option">
                            <input class="form-check-input" type="checkbox" name="additional_services[]" value="{{ \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_RETURN_LABEL }}" @checked(in_array(\Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_RETURN_LABEL, $additionalServices, true))>
                            Etykieta zwrotna <span class="text-secondary">(Paczkomat klienta &raquo; Paczkomat sprzedawcy)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="shipment-side-panel">
                    <label for="shipment_count">Liczba przesy&#322;ek</label>
                    <input id="shipment_count" class="form-control form-control-sm shipment-count-input" type="number" value="1" min="1" readonly>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3">
            <button class="btn btn-sm btn-outline-primary shipment-submit" type="submit">Nadaj paczk&#281; InPost Paczkomaty</button>
            <button class="btn btn-sm btn-light border" type="button" data-close-courier-form>Anuluj</button>
        </div>
    </form>
</div>
