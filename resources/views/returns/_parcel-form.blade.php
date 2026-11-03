<form class="return-parcel-form" method="POST" action="{{ isset($editingParcel) ? route('returns.parcels.update', [$orderReturn, $editingParcel]) : route('returns.parcels.store', $orderReturn) }}">
    @csrf @isset($editingParcel) @method('PATCH') @endisset
    <input type="hidden" name="expected_lock_version" value="{{ $orderReturn->lock_version }}">
    <label>Przewoźnik<select name="carrier_id" class="form-select form-select-sm" required>@foreach(\App\Services\OrderReturnParcelService::CARRIERS as $code => $label)<option value="{{ $code }}" @selected(($editingParcel->carrier_id ?? 'INPOST') === $code)>{{ $label }}</option>@endforeach</select></label>
    <label class="return-parcel-number">Numer przesyłki<input class="form-control form-control-sm" name="waybill" value="{{ $editingParcel->waybill ?? '' }}" maxlength="255" required></label>
    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-{{ isset($editingParcel) ? 'check-lg' : 'plus-lg' }}" aria-hidden="true"></i> {{ isset($editingParcel) ? 'Zapisz' : 'Dodaj przesyłkę zwrotną' }}</button>
</form>
