@foreach ($commissionClaims as $commissionClaim)
    <div class="border-bottom py-2">
        <div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ $commissionClaim->product_name }}</strong><span class="{{ $commissionClaim->status === 'GRANTED' ? 'text-success' : '' }}">{{ $commissionClaim->stateLabel() }}</span></div>
        <div class="small text-secondary">Ilość: {{ $commissionClaim->claimed_quantity ?? $commissionClaim->quantity }} · {{ $commissionClaim->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}{{ $commissionClaim->claim_type === 'AUTOMATIC' ? ' · Automatyczny wniosek Allegro' : '' }}</div>
        @if ($commissionClaim->external_claim_id)<div class="small text-secondary text-break">ID wniosku: {{ $commissionClaim->external_claim_id }}</div>@endif
        @if ($commissionClaim->return_number !== $orderReturn->id)<div class="small text-secondary">Utworzono przy zwrocie {{ $commissionClaim->return_number }}</div>@endif
        @if ($commissionClaim->error)<div class="small text-warning-emphasis">{{ $commissionClaim->error }}</div>@endif
    </div>
@endforeach
@if ($commissionClaims->hasPages()){{ $commissionClaims->links() }}@endif
