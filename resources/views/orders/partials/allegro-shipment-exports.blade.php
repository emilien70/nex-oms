@php($allegroShipmentStatus = $allegroShipmentStatus ?? \Modules\Integrations\Allegro\Support\AllegroShipmentExportPresentation::forShipment($shipment))
@if ($allegroShipmentStatus)
    @php($allegroShipmentError = ($errors ?? null)?->first('allegro_shipment_export'))
    <button type="button"
        class="allegro-shipment-badge is-{{ $allegroShipmentError ? 'danger' : $allegroShipmentStatus['tone'] }}"
        data-allegro-shipment-tooltip data-bs-trigger="hover focus click"
        title="{{ $allegroShipmentError ?: $allegroShipmentStatus['message'] }}"
        aria-label="{{ $allegroShipmentError ?: $allegroShipmentStatus['message'] }}"
    >al</button>
@endif
