<h3 class="h6">{{ $parcel->waybill ?: $parcel->transporting_waybill }} · Przesyłka zwrotna</h3>
@if($parcel->tracking_checked_at)<p class="small text-secondary">Sprawdzono: {{ $parcel->tracking_checked_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>@endif
@if($parcel->tracking_error)<p class="text-danger">{{ $parcel->tracking_error }}</p>@endif
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th>Status</th><th>Opis</th></tr></thead><tbody>
@forelse($events as $event)<tr><td class="text-nowrap">{{ $event->occurred_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td><td>{{ $event->statusLabel() }}<div class="small text-secondary">{{ $event->provider_status }}</div></td><td>{{ $event->description ?: '...' }}</td></tr>
@empty<tr><td colspan="3">Brak historii udostępnionej przez przewoźnika.</td></tr>@endforelse
</tbody></table></div>
{{ $events->links() }}
