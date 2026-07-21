@php
    $parcelRows = old('shipment_provider') === \Modules\Shipments\Models\CourierAccount::PROVIDER_ALLEGRO_SHIPPING
        ? old('parcels', data_get($fields, 'parcels', []))
        : data_get($fields, 'parcels', []);
    $selectedServices = old('additional_services', data_get($fields, 'additional_services', []));
    $availableServices = data_get($fields, 'available_additional_services', []);
    $availablePackageTypes = data_get($fields, 'available_package_types', ['PACKAGE' => 'Paczka']);
@endphp

<div class="shipments-form-wrap" data-courier-form-panel data-courier-provider="{{ $account->provider }}">
    <form
        method="POST"
        action="{{ route('orders.shipments.allegro-shipping.store', $order) }}"
        class="shipments-form-panel"
        data-ajax-shipment-form
        data-courier-shipment-form
        data-courier-form-loaded="1"
    >
        @csrf
        <input type="hidden" name="shipment_provider" value="{{ \Modules\Shipments\Models\CourierAccount::PROVIDER_ALLEGRO_SHIPPING }}">
        <input type="hidden" name="label_format" value="{{ data_get($fields, 'label_format', 'PDF') }}">

        <p class="small text-muted mb-3">Kurier, us&#322;uga oraz dane adresowe s&#261; dobierane automatycznie na podstawie zam&oacute;wienia Allegro.</p>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="allegro_shipping_package_type">Rodzaj</label>
                    <select id="allegro_shipping_package_type" class="form-select form-select-sm" name="package_type" required>
                        @foreach ($availablePackageTypes as $type => $label)
                            <option value="{{ $type }}" @selected(old('package_type', data_get($fields, 'package_type')) === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="allegro_shipping_cod">Pobranie</label>
                    <div class="input-group input-group-sm"><input id="allegro_shipping_cod" class="form-control" type="text" inputmode="decimal" name="cod_amount" value="{{ old('cod_amount', data_get($fields, 'cod_amount')) }}"><span class="input-group-text">PLN</span></div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="allegro_shipping_insurance">Ubezpieczenie</label>
                    <div class="input-group input-group-sm"><input id="allegro_shipping_insurance" class="form-control" type="text" inputmode="decimal" name="insurance_amount" value="{{ old('insurance_amount', data_get($fields, 'insurance_amount')) }}"><span class="input-group-text">PLN</span></div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="allegro_shipping_content">Opis zawarto&#347;ci</label>
                    <input id="allegro_shipping_content" class="form-control form-control-sm" name="content_description" maxlength="100" value="{{ old('content_description', data_get($fields, 'content_description')) }}">
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="allegro_shipping_reference">Nr referencyjny</label>
                    <input id="allegro_shipping_reference" class="form-control form-control-sm" name="reference_number" maxlength="100" value="{{ old('reference_number', data_get($fields, 'reference_number')) }}">
                </div>
                @if ($availableServices !== [])
                    <div class="shipment-form-row is-top">
                        <span class="shipment-form-label">Us&#322;ugi dodatkowe</span>
                        <div class="shipment-size-options">
                            @foreach ($availableServices as $service => $label)
                                <label class="shipment-size-option"><input class="form-check-input" type="checkbox" name="additional_services[]" value="{{ $service }}" @checked(in_array($service, $selectedServices, true))>{{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="shipment-form-row is-top mt-2">
                    <span class="shipment-form-label">Etykieta zwrotna</span>
                    <label class="shipment-size-option">
                        <input type="hidden" name="swap_sender_receiver" value="0">
                        <input class="form-check-input" type="checkbox" name="swap_sender_receiver" value="1" @checked((bool) old('swap_sender_receiver', data_get($fields, 'swap_sender_receiver', false)))>
                        Zamiana danych odbiorcy z nadawc&#261;
                    </label>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="courier-parcels-panel">
                    <div data-courier-parcels>
                        @foreach ($parcelRows as $index => $parcel)
                            <div class="courier-parcel-row is-dpd" data-courier-parcel>
                                @foreach (['weight' => 'Waga', 'length' => 'D&#322;ugo&#347;&#263;', 'width' => 'Szeroko&#347;&#263;', 'height' => 'Wysoko&#347;&#263;'] as $field => $label)
                                    <div class="courier-parcel-field"><label>{!! $label !!}</label><input class="form-control form-control-sm" type="text" inputmode="decimal" name="parcels[{{ $index }}][{{ $field }}]" value="{{ data_get($parcel, $field) }}" required data-courier-parcel-value></div>
                                @endforeach
                                <div class="courier-parcel-template-field">
                                    <label>Szablon</label>
                                    <select class="form-select form-select-sm" data-courier-parcel-template-select aria-label="Wybierz szablon wymiar&oacute;w i wagi">
                                        <option value="">-- wybierz</option>
                                        @foreach ($parcelTemplates as $template)
                                            <option value="{{ $template['id'] }}" data-weight="{{ $template['weight'] }}" data-length="{{ $template['length'] }}" data-width="{{ $template['width'] }}" data-height="{{ $template['height'] }}">{{ $template['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="btn btn-sm btn-light border courier-parcel-remove" type="button" data-remove-courier-parcel aria-label="Usu&#324; paczk&#281;">&times;</button>
                                <div class="courier-volume-note" data-courier-volume></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2"><button class="btn btn-sm btn-light border" type="button" data-add-courier-parcel>+ Kolejna paczka</button></div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 mt-3">
            <button class="btn btn-sm btn-outline-primary shipment-submit" type="submit">Nadaj przez Wysy&#322;am z Allegro</button>
            <button class="btn btn-sm btn-light border" type="button" data-close-courier-form>Anuluj</button>
        </div>
    </form>

    <template data-courier-parcel-template>
        <div class="courier-parcel-row is-dpd" data-courier-parcel>
            @foreach (['weight' => ['Waga', 1], 'length' => ['D&#322;ugo&#347;&#263;', 25], 'width' => ['Szeroko&#347;&#263;', 20], 'height' => ['Wysoko&#347;&#263;', 10]] as $field => [$label, $value])
                <div class="courier-parcel-field"><label>{!! $label !!}</label><input class="form-control form-control-sm" type="text" inputmode="decimal" name="parcels[INDEX][{{ $field }}]" value="{{ $value }}" required data-courier-parcel-value></div>
            @endforeach
            <div class="courier-parcel-template-field">
                <label>Szablon</label>
                <select class="form-select form-select-sm" data-courier-parcel-template-select aria-label="Wybierz szablon wymiar&oacute;w i wagi">
                    <option value="">-- wybierz</option>
                    @foreach ($parcelTemplates as $template)
                        <option value="{{ $template['id'] }}" data-weight="{{ $template['weight'] }}" data-length="{{ $template['length'] }}" data-width="{{ $template['width'] }}" data-height="{{ $template['height'] }}">{{ $template['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-light border courier-parcel-remove" type="button" data-remove-courier-parcel aria-label="Usu&#324; paczk&#281;">&times;</button>
            <div class="courier-volume-note" data-courier-volume></div>
        </div>
    </template>
</div>
