@extends('layouts.app')
@section('title', 'Zwrot prowizji - NEX-OMS')
@section('content')
<div class="container-fluid py-3" style="max-width:1100px">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h5 mb-0">Zwrot prowizji · Zwrot {{ $orderReturn->id }}</h1>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('returns.show', $orderReturn) }}"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Powrót do zwrotu</a>
    </header>
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p>{{ $orderReturn->buyer_name ?: $orderReturn->buyer_login }} · {{ $context['account']->name }} · {{ $context['account']->environment === 'production' ? 'Allegro' : 'Allegro Sandbox' }}</p>
    <div class="alert alert-warning py-2">Allegro pozwala złożyć tylko jeden wniosek dla danej pozycji zakupu. Sprawdź liczbę sztuk przed wysłaniem. Wniosek nie jest zwrotem pieniędzy kupującemu.</div>
    <form method="POST" action="{{ route('returns.commission-refund.store', $orderReturn) }}">
        @csrf
        <input type="hidden" name="quote_token" value="{{ $quote['token'] }}">
        @php
            $available = false;
        @endphp
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th style="width:40px"><span class="visually-hidden">Wybierz</span></th><th>Produkt</th><th style="width:110px">Ilość</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($context['rows'] as $key => $row)
                    @php
                        $known = $context['known'][$row['id']] ?? null;
                        $local = $context['local']->get($row['id']);
                        $canSend = ! $known && (! $local || ($local->state === 'not_sent' && ! $local->started_at && ! $local->external_claim_id));
                        $available = $available || $canSend;
                    @endphp
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="items[{{ $key }}][selected]" value="1" aria-label="Wniosek za {{ $row['name'] }}" @checked($canSend) @disabled(! $canSend)></td>
                        <td>{{ $row['name'] }}<div class="small text-secondary">W zwrocie: {{ $row['max'] }} · Zakupiono: {{ $row['purchased_quantity'] }}</div></td>
                        <td><input class="form-control form-control-sm" type="number" name="items[{{ $key }}][quantity]" min="1" max="{{ $row['max'] }}" value="{{ $row['max'] }}" aria-label="Ilość: {{ $row['name'] }}" required @readonly(! $canSend)></td>
                        <td>
                            @if ($known)
                                <span>{{ \Modules\Integrations\Allegro\Models\AllegroCommissionRefundClaim::statusLabel($known['status']) }}</span>
                                <div class="small text-secondary">Wniosek dotyczy {{ $known['quantity'] }} szt.{{ $known['type'] === 'AUTOMATIC' ? ' · Automatyczny wniosek Allegro' : '' }}</div>
                            @elseif ($local)
                                {{ $local->stateLabel() }}
                                @if ($local->error)<div class="small text-warning-emphasis">{{ $local->error }}</div>@endif
                            @else
                                <span class="text-secondary">Brak wniosku</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        @if ($available)
            <div class="form-check my-3"><input class="form-check-input" type="checkbox" name="confirmed" value="1" id="commissionConfirmed" required><label class="form-check-label" for="commissionConfirmed">Potwierdzam wysłanie do Allegro wniosku o zwrot prowizji za zaznaczone produkty i liczbę sztuk.</label></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-percent me-1" aria-hidden="true"></i>Wyślij wniosek</button>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('returns.show', $orderReturn) }}">{{ $available ? 'Anuluj' : 'Powrót' }}</a>
    </form>
</div>
@endsection
