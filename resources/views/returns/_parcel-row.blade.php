<tr data-return-parcel-id="{{ $parcel->id }}">
    <td>{{ $parcel->submitted_at?->timezone(config('app.timezone'))->format('d.m H:i') ?: '...' }}</td>
    <td>{{ \App\Services\OrderReturnParcelService::CARRIERS[$parcel->carrier_id] ?? $parcel->carrier_id ?: '...' }}</td>
    <td>{{ $parcel->orderReturn->account_name ?: 'Ręcznie' }}</td>
    <td>
        @if ($url = $parcel->trackingUrl())<a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $parcel->waybill ?: $parcel->transporting_waybill }}</a>@else<span>{{ $parcel->waybill ?: $parcel->transporting_waybill ?: '...' }}</span>@endif
        <span tabindex="0" title="Przesyłka zwrotna" aria-label="Przesyłka zwrotna"><i class="bi bi-arrow-return-left" aria-hidden="true"></i></span>
        <a class="return-parcel-reference" href="{{ route('returns.show', $parcel->order_return_id) }}">Zwrot #{{ $parcel->order_return_id }}</a>
        @if ($parcel->transporting_waybill && $parcel->transporting_waybill !== $parcel->waybill)
            <div class="text-secondary small">{{ $parcel->transporting_carrier_id }}: {{ $parcel->transporting_waybill }}</div>
        @endif
    </td>
    <td>
        <a class="return-parcel-status" href="{{ route('returns.parcels.history', [$parcel->order_return_id, $parcel]) }}" data-return-parcel-history>
            <span class="return-parcel-track" aria-hidden="true"><span class="return-parcel-fill {{ $parcel->statusCode() === 'delivered' ? 'is-delivered' : (in_array($parcel->statusCode(), ['problem', 'returned'], true) ? 'is-problem' : '') }}" style="width:{{ $parcel->progress() }}%"></span></span>
            <span>{{ $parcel->statusLabel() }}@if($duration = $parcel->durationLabel()) ({{ $duration }})@endif</span>
        </a>
        @if ($parcel->tracking_error)<div class="small text-danger" title="{{ $parcel->tracking_error }}">{{ \Illuminate\Support\Str::limit($parcel->tracking_error, 95) }}</div>@endif
    </td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
            @if (! $parcel->isTerminal())<form method="POST" action="{{ route('returns.parcels.refresh', [$parcel->order_return_id, $parcel]) }}">@csrf<button class="btn btn-sm btn-outline-secondary" title="Sprawdź status" aria-label="Sprawdź status" type="submit"><i class="bi bi-arrow-clockwise"></i></button></form>@endif
            @if (($canEditParcel ?? false) && $parcel->is_manual)<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#editReturnParcel{{ $parcel->id }}" title="Edytuj numer" aria-label="Edytuj numer"><i class="bi bi-pencil"></i></button>@endif
        </div>
    </td>
</tr>
@if (($canEditParcel ?? false) && $parcel->is_manual)
<tr class="collapse" id="editReturnParcel{{ $parcel->id }}"><td colspan="6">
    @include('returns._parcel-form', ['editingParcel' => $parcel, 'orderReturn' => $parcel->orderReturn])
</td></tr>
@endif
