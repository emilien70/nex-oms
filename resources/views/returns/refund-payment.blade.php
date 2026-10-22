@extends('layouts.app')
@section('title', 'Zwrot wpłaty - NEX-OMS')
@section('content')
<div class="container-fluid py-3" style="max-width:1100px">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h1 class="h5 mb-0">Zwrot wpłaty · Zwrot {{ $orderReturn->id }}</h1>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('returns.show', $orderReturn) }}"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Powrót do zwrotu</a>
    </header>
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if ($command)
        <h2 class="h6">Potwierdzenie zwrotu wpłaty</h2>
        <p>{{ $orderReturn->buyer_name ?: $orderReturn->buyer_login }} · {{ $command->environment === 'production' ? 'Allegro' : 'Allegro Sandbox' }}</p>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Produkt</th><th class="text-end">Ilość</th><th class="text-end">Kwota {{ $command->currency }}</th></tr></thead><tbody>
            @foreach ($command->selection as $row)<tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['quantity'] }}</td><td class="text-end">{{ $row['amount'] }}</td></tr>@endforeach
            <tr><td colspan="2">Dostawa</td><td class="text-end">{{ $command->delivery_value }}</td></tr>
        </tbody><tfoot><tr><th colspan="2">Łącznie do zwrotu</th><th class="text-end">{{ $command->total_value }} {{ $command->currency }}</th></tr></tfoot></table></div>
        <dl class="row small"><dt class="col-sm-3">Powód</dt><dd class="col-sm-9">{{ \Modules\Integrations\Allegro\Models\AllegroRefundCommand::REASONS[$command->payload['reason']] }}</dd>
            @if (! empty($command->payload['sellerComment']))<dt class="col-sm-3">Komentarz sprzedawcy</dt><dd class="col-sm-9 text-break">{{ $command->payload['sellerComment'] }}</dd>@endif
        </dl>
        @if ($command->state === 'draft' && $command->expires_at->isFuture())
            <form method="POST" action="{{ route('returns.refund-payment.confirm', [$orderReturn, $command]) }}">
                @csrf
                <div class="form-check my-3"><input class="form-check-input" type="checkbox" name="confirmed" value="1" id="refundConfirmed" required><label class="form-check-label" for="refundConfirmed">Potwierdzam zlecenie rzeczywistego zwrotu {{ $command->total_value }} {{ $command->currency }} kupującemu.</label></div>
                <div class="d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Zleć zwrot {{ $command->total_value }} {{ $command->currency }}</button><a class="btn btn-outline-secondary" href="{{ route('returns.show', $orderReturn) }}">Anuluj</a></div>
            </form>
        @else
            <div class="alert alert-secondary">{{ $command->state === 'draft' ? 'Potwierdzenie wygasło. Otwórz formularz ponownie.' : $command->stateLabel() }}</div>
        @endif
    @else
        <p>{{ $orderReturn->buyer_name ?: $orderReturn->buyer_login }} · {{ $fresh['account']->name }} · {{ $fresh['account']->environment === 'production' ? 'Allegro' : 'Allegro Sandbox' }}</p>
        <form method="POST" action="{{ route('returns.refund-payment.prepare', $orderReturn) }}">
            @csrf
            <input type="hidden" name="context_hash" value="{{ $fresh['hash'] }}">
            <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th style="width:40px"><span class="visually-hidden">Wybierz</span></th><th>Produkt</th><th style="width:110px">Ilość</th><th style="width:160px">Kwota {{ $fresh['currency'] }}</th></tr></thead><tbody>
                @foreach ($fresh['rows'] as $key => $row)
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="items[{{ $key }}][selected]" value="1" aria-label="Zwróć wpłatę za {{ $row['name'] }}" @checked($row['max'] !== '0.00') @disabled($row['max'] === '0.00')></td>
                        <td>{{ $row['name'] }}<div class="small text-secondary">Dostępne: {{ $row['max'] }} {{ $fresh['currency'] }}</div></td>
                        <td><input class="form-control form-control-sm refund-quantity" type="number" min="1" max="{{ $row['quantity'] }}" name="items[{{ $key }}][quantity]" value="{{ $row['quantity'] }}" aria-label="Ilość: {{ $row['name'] }}" data-unit="{{ \Brick\Math\BigDecimal::of($row['unit'])->multipliedBy(100)->toScale(0) }}" data-max="{{ \Brick\Math\BigDecimal::of($row['max'])->multipliedBy(100)->toScale(0) }}" required></td>
                        <td><input class="form-control form-control-sm refund-amount" type="number" min="0" step="0.01" max="{{ $row['max'] }}" name="items[{{ $key }}][amount]" value="{{ $row['max'] }}" aria-label="Kwota: {{ $row['name'] }}" required></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <div class="row g-3 mb-3 align-items-center">
                <div class="col-sm-8"><div class="form-check"><input class="form-check-input" type="checkbox" name="include_delivery" value="1" id="includeDelivery" @disabled($fresh['delivery_max'] === '0.00')><label class="form-check-label" for="includeDelivery">Zwróć również koszt dostawy</label></div></div>
                <div class="col-sm-4"><label class="form-label small" for="refundDeliveryAmount">Dostawa {{ $fresh['currency'] }} · dostępne {{ $fresh['delivery_max'] }}</label><input class="form-control form-control-sm" type="number" min="0" step="0.01" max="{{ $fresh['delivery_max'] }}" id="refundDeliveryAmount" name="delivery_amount" value="{{ $fresh['delivery_max'] }}" disabled></div>
            </div>
            <div class="row g-3 mb-3"><div class="col-sm-6"><label class="form-label" for="refundReason">Powód zwrotu wpłaty</label><select class="form-select form-select-sm" id="refundReason" name="reason" required>@foreach (\Modules\Integrations\Allegro\Models\AllegroRefundCommand::REASONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="col-sm-6"><label class="form-label" for="refundComment">Komentarz sprzedawcy</label><textarea class="form-control form-control-sm" id="refundComment" name="seller_comment" maxlength="250" rows="2"></textarea></div></div>
            <div class="d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Podsumowanie</button><a class="btn btn-outline-secondary" href="{{ route('returns.show', $orderReturn) }}">Anuluj</a></div>
        </form>
    @endif
</div>
@if (! $command)
<script>
document.getElementById('includeDelivery').addEventListener('change', function () {
    document.getElementById('refundDeliveryAmount').disabled = !this.checked;
});
document.querySelectorAll('.refund-quantity').forEach(input => input.addEventListener('change', function () {
    if (!this.checkValidity()) return;
    const amount = BigInt(this.value) * BigInt(this.dataset.unit);
    const cap = BigInt(this.dataset.max);
    const cents = amount < cap ? amount : cap;
    this.closest('tr').querySelector('.refund-amount').value = `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
}));
</script>
@endif
@endsection
