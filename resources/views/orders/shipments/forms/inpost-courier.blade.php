@php
    $restoreOldInput = old('shipment_provider') === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_COURIER;
    $fieldValue = fn (string $name, mixed $default = null) => $restoreOldInput ? old($name, $default) : $default;
    $parcelDefaults = data_get($fields, 'parcel', []);
    $parcelRows = $restoreOldInput ? old('parcels', [$parcelDefaults]) : [$parcelDefaults];
    $additionalServices = $fieldValue('additional_services', data_get($fields, 'additional_services', []));
@endphp

<div class="shipments-form-wrap" data-courier-form-panel data-courier-provider="{{ $account->provider }}">
    <form method="POST" action="{{ route('orders.shipments.inpost-courier.store', $order) }}" class="shipments-form-panel" data-ajax-shipment-form data-inpost-courier-shipment-form data-courier-shipment-form data-courier-form-loaded="1">
        @csrf
        <input type="hidden" name="shipment_provider" value="{{ \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_COURIER }}">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="courier_shipment_service">Us&#322;uga</label>
                    <select id="courier_shipment_service" class="form-select form-select-sm" name="service" required>
                        @foreach ($serviceLabels as $service => $label)
                            <option value="{{ $service }}" @selected($fieldValue('service', data_get($fields, 'service')) === $service)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="courier_shipment_cod">Pobranie</label>
                    <div class="input-group input-group-sm">
                        <input id="courier_shipment_cod" class="form-control" type="number" name="cod_amount" min="0" step="0.01" value="{{ $fieldValue('cod_amount', data_get($fields, 'cod_amount')) }}" placeholder="0.00" data-courier-cod>
                        <span class="input-group-text">PLN</span>
                    </div>
                </div>
                <div class="shipment-form-row is-top">
                    <label class="shipment-form-label" for="courier_shipment_insurance">Ubezpieczenie</label>
                    <div>
                        <div class="input-group input-group-sm">
                            <input id="courier_shipment_insurance" class="form-control @error('insurance_amount') is-invalid @enderror" type="number" name="insurance_amount" min="0" step="0.01" value="{{ $fieldValue('insurance_amount', data_get($fields, 'insurance_amount')) }}" placeholder="0.00" data-courier-insurance>
                            <span class="input-group-text">PLN</span>
                        </div>
                        <div class="form-text">Przy pobraniu ubezpieczenie musi by&#263; co najmniej r&oacute;wne kwocie pobrania.</div>
                        @error('insurance_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="shipment-form-row">
                    <label class="shipment-form-label" for="courier_shipment_content">Opis zawarto&#347;ci</label>
                    <input id="courier_shipment_content" class="form-control form-control-sm" type="text" name="content_description" maxlength="100" value="{{ $fieldValue('content_description', data_get($fields, 'content_description')) }}">
                </div>
                <div class="shipment-form-row is-top">
                    <span class="shipment-form-label">Us&#322;ugi dodatkowe</span>
                    <div class="shipment-size-options">
                        @foreach ([
                            \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_SMS => 'Powiadomienie SMS',
                            \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_EMAIL => 'Powiadomienie e-mail',
                            \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_SATURDAY => 'Dor&#281;czenie w sobot&#281;',
                            \Modules\Shipments\Models\Shipment::ADDITIONAL_SERVICE_RETURN_DOCUMENTS => 'Zwrot dokument&oacute;w',
                        ] as $service => $label)
                            <label class="shipment-size-option">
                                <input class="form-check-input" type="checkbox" name="additional_services[]" value="{{ $service }}" @checked(in_array($service, $additionalServices, true))>
                                {!! $label !!}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="courier-parcels-panel">
                    <div class="courier-parcels-header">
                        <h3 class="courier-parcels-title">Przesy&#322;ki</h3>
                    </div>
                    <div data-courier-parcels>
                        @foreach ($parcelRows as $index => $parcel)
                            <div class="courier-parcel-row" data-courier-parcel>
                                @foreach (['weight' => 'Waga', 'length' => 'D&#322;ugo&#347;&#263;', 'width' => 'Szeroko&#347;&#263;', 'height' => 'Wysoko&#347;&#263;'] as $field => $label)
                                    <div class="courier-parcel-field">
                                        <label>{!! $label !!}</label>
                                        <input class="form-control form-control-sm" type="number" name="parcels[{{ $index }}][{{ $field }}]" min="0.01" max="{{ $field === 'weight' ? 50 : 350 }}" step="0.01" value="{{ data_get($parcel, $field) }}" required data-courier-parcel-value>
                                    </div>
                                @endforeach
                                <div class="courier-parcel-nonstandard">
                                    <span>Niestandard</span>
                                    <label class="courier-parcel-nonstandard-control" title="Przesy&#322;ka niestandardowa">
                                        <input class="form-check-input" type="checkbox" name="parcels[{{ $index }}][is_non_standard]" value="1" @checked((bool) data_get($parcel, 'is_non_standard', false))>
                                        <span class="visually-hidden">Przesy&#322;ka niestandardowa</span>
                                    </label>
                                </div>
                                <div class="courier-parcel-template-field">
                                    <label>Szablon</label>
                                    <select class="form-select form-select-sm" data-courier-parcel-template-select aria-label="Wybierz szablon wymiar&oacute;w i wagi">
                                        <option value="">-- wybierz</option>
                                        @foreach ($parcelTemplates as $template)
                                            <option
                                                value="{{ $template['id'] }}"
                                                data-weight="{{ $template['weight'] }}"
                                                data-length="{{ $template['length'] }}"
                                                data-width="{{ $template['width'] }}"
                                                data-height="{{ $template['height'] }}"
                                            >{{ $template['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="btn btn-sm btn-light border courier-parcel-remove" type="button" data-remove-courier-parcel aria-label="Usu&#324; podpaczk&#281;">&times;</button>
                                <div class="courier-volume-note" data-courier-volume></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2">
                        <button class="btn btn-sm btn-light border" type="button" data-add-courier-parcel>+ Kolejna podpaczka</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3">
            <button class="btn btn-sm btn-outline-primary shipment-submit" type="submit">Nadaj paczk&#281; InPost Kurier</button>
            <button class="btn btn-sm btn-light border" type="button" data-close-courier-form>Anuluj</button>
        </div>
    </form>

    <template data-courier-parcel-template>
        <div class="courier-parcel-row" data-courier-parcel>
            @foreach (['weight' => ['Waga', data_get($parcelDefaults, 'weight'), 50], 'length' => ['D&#322;ugo&#347;&#263;', data_get($parcelDefaults, 'length'), 350], 'width' => ['Szeroko&#347;&#263;', data_get($parcelDefaults, 'width'), 350], 'height' => ['Wysoko&#347;&#263;', data_get($parcelDefaults, 'height'), 350]] as $field => [$label, $value, $max])
                <div class="courier-parcel-field">
                    <label>{!! $label !!}</label>
                    <input class="form-control form-control-sm" type="number" name="parcels[INDEX][{{ $field }}]" min="0.01" max="{{ $max }}" step="0.01" value="{{ $value }}" required data-courier-parcel-value>
                </div>
            @endforeach
            <div class="courier-parcel-nonstandard">
                <span>Niestandard</span>
                <label class="courier-parcel-nonstandard-control" title="Przesy&#322;ka niestandardowa">
                    <input class="form-check-input" type="checkbox" name="parcels[INDEX][is_non_standard]" value="1">
                    <span class="visually-hidden">Przesy&#322;ka niestandardowa</span>
                </label>
            </div>
            <div class="courier-parcel-template-field">
                <label>Szablon</label>
                <select class="form-select form-select-sm" data-courier-parcel-template-select aria-label="Wybierz szablon wymiar&oacute;w i wagi">
                    <option value="">-- wybierz</option>
                    @foreach ($parcelTemplates as $template)
                        <option
                            value="{{ $template['id'] }}"
                            data-weight="{{ $template['weight'] }}"
                            data-length="{{ $template['length'] }}"
                            data-width="{{ $template['width'] }}"
                            data-height="{{ $template['height'] }}"
                        >{{ $template['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-light border courier-parcel-remove" type="button" data-remove-courier-parcel aria-label="Usu&#324; podpaczk&#281;">&times;</button>
            <div class="courier-volume-note" data-courier-volume></div>
        </div>
    </template>
</div>
