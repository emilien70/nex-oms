<tr data-shipment-id="{{ $shipment->id }}">
    <td>{{ $shipment->created_at?->format('d.m H:i') ?: '...' }}</td>
    <td @if ($shipment->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_LOCKERS) title="Gabaryt {{ ['small' => 'A', 'medium' => 'B', 'large' => 'C'][$shipment->parcel_template] ?? '...' }}, punkt {{ $shipment->target_point_id ?: '...' }}" @endif>
        @if ($shipment->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_LOCKERS)
            <a
                class="shipment-provider"
                href="{{ route('integrations.couriers.inpost-lockers.edit') }}"
                title="Przejd&#378; do konfiguracji InPost Paczkomaty"
                aria-label="Przejd&#378; do konfiguracji przewo&#378;nika InPost Paczkomaty"
            ><span class="shipment-provider-check">&#10003;</span>InPost Paczkomaty</a>
        @elseif ($shipment->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_INPOST_COURIER)
            <a
                class="shipment-provider"
                href="{{ route('integrations.couriers.inpost-courier.edit') }}"
                title="Przejd&#378; do konfiguracji InPost Kurier"
                aria-label="Przejd&#378; do konfiguracji przewo&#378;nika InPost Kurier"
            ><span class="shipment-provider-check">&#10003;</span>InPost Kurier</a>
        @elseif ($shipment->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_DPD)
            <a
                class="shipment-provider"
                href="{{ route('integrations.couriers.dpd.edit') }}"
                title="Przejd&#378; do konfiguracji DPD"
                aria-label="Przejd&#378; do konfiguracji przewo&#378;nika DPD"
            ><span class="shipment-provider-check">&#10003;</span>DPD</a>
        @elseif ($shipment->provider === \Modules\Shipments\Models\CourierAccount::PROVIDER_ALLEGRO_SHIPPING)
            <a
                class="shipment-provider"
                href="{{ route('integrations.couriers.allegro-shipping.edit') }}"
                title="Przejd&#378; do konfiguracji Wysy&#322;am z Allegro"
                aria-label="Przejd&#378; do konfiguracji Wysy&#322;am z Allegro"
            ><span class="shipment-provider-check">&#10003;</span>Wysy&#322;am z Allegro</a>
        @else
            <span class="shipment-provider"><span class="shipment-provider-check">&#10003;</span>{{ $shipment->courierAccount?->name ?: $shipment->provider }}</span>
        @endif
    </td>
    <td>{{ $shipment->courierAccount?->name ?: '...' }}</td>
    <td>
        @if ($trackingUrl = $shipment->trackingUrl())
            <a
                class="shipment-tracking-number"
                href="{{ $trackingUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                title="Sprawd&#378; aktualny status przesy&#322;ki"
                aria-label="Sprawd&#378; status przesy&#322;ki {{ $shipment->tracking_number }} na stronie przewo&#378;nika"
            >{{ $shipment->tracking_number }}</a>
        @else
            <span class="shipment-tracking-number">{{ $shipment->tracking_number ?: '...' }}</span>
        @endif
    </td>
    <td>
        <div class="shipment-status-line">
            <span class="shipment-status-track" aria-hidden="true">
                <span
                    class="shipment-status-fill {{ $shipment->omsStatusProgressClass() }}"
                    style="width: {{ $shipment->omsStatusProgress() }}%"
                ></span>
            </span>
            <span>{{ $shipment->statusLabel() }}</span>
        </div>
        @if ($shipment->error_message)
            <div class="text-danger mt-1" title="{{ $shipment->error_message }}">{{ \Illuminate\Support\Str::limit($shipment->error_message, 95) }}</div>
        @endif
    </td>
    <td>
        <div class="shipment-actions">
            @if ($shipment->canDownloadLabel())
                <div class="shipment-label-group">
                    <a class="btn btn-sm btn-light" href="{{ route('shipments.label', $shipment) }}">Etykieta</a>
                </div>
            @endif
            <div class="dropdown">
                <button class="shipment-menu-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Operacje na przesy&#322;ce">&#8942;</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @if ($shipment->canRetryCreation())
                        <li>
                            <form method="POST" action="{{ route('shipments.retry', $shipment) }}" data-order-ajax-form>
                                @csrf
                                <button class="dropdown-item" type="submit">Pon&oacute;w nadanie</button>
                            </form>
                        </li>
                    @endif
                    @if ($shipment->requiresCreationVerification())
                        <li><span class="dropdown-item-text text-warning-emphasis">Najpierw sprawd&#378; paczk&#281; w panelu kuriera</span></li>
                    @endif
                    @if ($shipment->external_id
                        && ! in_array($shipment->oms_status, [
                            \Modules\Shipments\Models\Shipment::OMS_STATUS_DELIVERED,
                            \Modules\Shipments\Models\Shipment::OMS_STATUS_RETURNED,
                        ], true)
                        && ! in_array($shipment->status, ['cancelled', 'cancelled_locally', 'canceled'], true))
                        <li>
                            <form method="POST" action="{{ route('shipments.refresh', $shipment) }}" data-order-ajax-form>
                                @csrf
                                <button class="dropdown-item" type="submit">Od&#347;wie&#380; status</button>
                            </form>
                        </li>
                    @endif
                    @if ($shipment->canCancelViaCourier() || $shipment->canCancelLocally())
                        <li><button
                            class="dropdown-item text-danger"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#cancelShipmentModal"
                            data-shipment-cancel-url="{{ route('shipments.cancel', $shipment) }}"
                            data-shipment-cancel-number="{{ $shipment->tracking_number ?: 'ID ' . $shipment->id }}"
                            data-shipment-cancel-local-only="{{ $shipment->canCancelViaCourier() ? '0' : '1' }}"
                            data-shipment-cancel-local-warning="{{ $shipment->external_id && ! $shipment->canCancelViaCourier() ? '1' : '0' }}"
                        >Anuluj przesy&#322;k&#281;</button></li>
                    @endif
                    @if (! $shipment->canCancelViaCourier() && ! $shipment->canCancelLocally())
                        <li><span class="dropdown-item-text text-secondary">Brak dost&#281;pnych operacji</span></li>
                    @endif
                </ul>
            </div>
        </div>
    </td>
</tr>
