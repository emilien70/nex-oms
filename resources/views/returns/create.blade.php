@extends('layouts.app')
@section('title', 'Stwórz zwrot - NEX-OMS')
@section('content')
@include('orders.partials.list-styles')
<style>
    .return-create-panel{background:#fff;border:1px solid #d5dbe3;border-radius:6px;margin-bottom:18px;padding:18px}
    .return-create-panel h2{font-size:16px;margin:0 0 16px}.return-create-panel .form-label{font-size:13px}.return-create-panel .form-control,.return-create-panel .form-select{font-size:13px}
    .return-create-table{width:100%;min-width:860px;font-size:12px}.return-create-table th{font-size:10px;font-weight:400;border-bottom:1px solid #cfd5df;padding:10px 8px}.return-create-table td{padding:8px;border-bottom:1px solid #e4e7eb}.return-create-table .form-control{min-height:28px;padding:3px 8px}.return-create-table .quantity{width:90px}.return-create-table .price{width:115px}.return-create-table .product-id{width:85px}.return-create-actions .btn{border-radius:999px;font-size:13px;padding:8px 20px}.return-create-tabs{margin-bottom:18px}.return-existing{font-size:13px}
</style>
<div class="orders-page">
    <header class="orders-header mb-4"><h1 class="orders-status-heading">Stwórz zwrot @if ($order)<span class="text-secondary">do zamówienia {{ $order->id }}</span>@endif</h1><a class="btn btn-sm btn-outline-secondary" href="{{ route('returns.index') }}"><i class="bi bi-arrow-return-left" aria-hidden="true"></i> Lista zwrotów</a></header>
    @if ($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if (! $order)
        <nav class="nav nav-tabs return-create-tabs" role="tablist"><button class="nav-link {{ ! $errors->any() ? 'active' : '' }}" type="button" data-bs-toggle="tab" data-bs-target="#returnOrderLookup" role="tab">Z zamówienia</button><button class="nav-link {{ $errors->any() ? 'active' : '' }}" type="button" data-bs-toggle="tab" data-bs-target="#returnManualForm" role="tab">Bez zamówienia</button></nav>
        <div class="tab-content">
            <section class="tab-pane fade {{ ! $errors->any() ? 'show active' : '' }}" id="returnOrderLookup" role="tabpanel">
                <div class="return-create-panel">
                    <form method="GET" class="d-flex gap-2"><label class="visually-hidden" for="lookupOrder">Szukaj zamówienia</label><input class="form-control" id="lookupOrder" name="q" value="{{ request('q') }}" placeholder="Numer zamówienia, klient, e-mail" maxlength="255" required><button class="btn btn-outline-primary" type="submit" title="Szukaj zamówienia" aria-label="Szukaj zamówienia"><i class="bi bi-search"></i></button></form>
                    @if (request()->filled('q'))
                        <div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Zamówienie</th><th>Klient</th><th>Źródło</th><th></th></tr></thead><tbody>@forelse ($candidates as $candidate)<tr><td>{{ $candidate->id }}@if ($candidate->external_id)<span class="text-secondary"> ({{ $candidate->external_id }})</span>@endif</td><td>{{ $candidate->shipping_name ?: $candidate->customer_login }}</td><td>{{ $candidate->source }}</td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('returns.create', ['order_id' => $candidate->id]) }}">Wybierz</a></td></tr>@empty<tr><td colspan="4">Brak zamówień.</td></tr>@endforelse</tbody></table></div>
                    @endif
                </div>
            </section>
            <div class="tab-pane fade {{ $errors->any() ? 'show active' : '' }}" id="returnManualForm" role="tabpanel">
    @endif
    @if ($order && $order->returns->isNotEmpty())
        <section class="return-create-panel return-existing"><h2>Wcześniejsze zwroty</h2>@foreach ($order->returns as $existing)<div class="mb-2"><a href="{{ route('returns.show', $existing) }}">Zwrot {{ $existing->id }}</a> &middot; {{ $existing->localStatusLabel() }}@foreach ($existing->items as $existingItem)<div class="text-secondary ms-3">{{ $existingItem->quantity }}x {{ $existingItem->name }}</div>@endforeach</div>@endforeach</section>
    @endif
    <form method="POST" action="{{ route('returns.store') }}">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
        @if ($order)<input type="hidden" name="order_id" value="{{ $order->id }}"><input type="hidden" name="order_fingerprint" value="{{ $orderFingerprint }}">@endif
        <section class="return-create-panel"><h2>Dane zwrotu</h2><div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="returnReference">Numer referencyjny</label><input class="form-control" id="returnReference" name="reference_number" value="{{ old('reference_number') }}" maxlength="255"></div>
            <div class="col-md-4"><label class="form-label" for="returnCurrency">Waluta</label>@if ($order)<input class="form-control" id="returnCurrency" value="{{ $order->currency }}" readonly>@else<select class="form-select" id="returnCurrency" name="currency">@foreach ($currencies as $code => $label)<option value="{{ $code }}" @selected(old('currency', 'PLN') === $code)>{{ $label }}</option>@endforeach</select>@endif</div>
            <div class="col-md-4"><label class="form-label">Status</label><div class="form-control bg-light">{{ \App\Models\ReturnStatusSetting::initial()->name }}</div></div>
        </div></section>
        <section class="return-create-panel"><h2>Dane klienta</h2><div class="row g-3">
            @foreach (['buyer_name'=>'Imię i nazwisko', 'buyer_login'=>'Login', 'buyer_email'=>'E-mail', 'buyer_phone'=>'Telefon'] as $field => $label)
                @php($linkedValue = $order ? match ($field) {'buyer_name'=>$order->shipping_name, 'buyer_login'=>$order->customer_login, 'buyer_email'=>$order->customer_email, 'buyer_phone'=>$order->customer_phone} : null)
                <div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" @if (! $order)name="{{ $field }}"@endif type="{{ $field === 'buyer_email' ? 'email' : 'text' }}" value="{{ $order ? $linkedValue : old($field) }}" maxlength="255" @readonly($order !== null) @required(! $order && $field === 'buyer_name')></div>
            @endforeach
        </div></section>
        @if (! $order)
            <section class="return-create-panel"><h2>Adres dostawy</h2><div class="row g-3">
                @foreach (['name'=>'Imię i nazwisko', 'company_name'=>'Firma', 'street'=>'Ulica', 'building_number'=>'Nr budynku', 'apartment_number'=>'Nr lokalu', 'postal_code'=>'Kod pocztowy', 'city'=>'Miasto', 'province'=>'Województwo'] as $field=>$label)<div class="col-md-{{ in_array($field, ['building_number','apartment_number','postal_code']) ? '4' : '6' }}"><label class="form-label" for="address_{{ $field }}">{{ $label }}</label><input class="form-control" id="address_{{ $field }}" name="address[{{ $field }}]" value="{{ old('address.'.$field) }}" maxlength="255"></div>@endforeach
                <div class="col-md-6"><label class="form-label" for="address_country">Kraj</label><select class="form-select" id="address_country" name="address[country_code]"><option value="">...</option>@foreach ($countries as $code=>$name)<option value="{{ $code }}" @selected(old('address.country_code') === $code)>{{ $name }}</option>@endforeach</select></div>
            </div></section>
        @endif
        <section class="return-create-panel"><div class="d-flex justify-content-between align-items-center mb-2"><h2 class="mb-0">Produkty zwrotu</h2>@if ($order)<button class="btn btn-sm btn-outline-secondary" type="button" id="returnAllProducts"><i class="bi bi-check2-all" aria-hidden="true"></i> Wszystkie pozostałe</button>@endif</div>
            <div class="table-responsive"><table class="return-create-table"><thead><tr><th>ID PROD.</th><th>NAZWA PRODUKTU</th>@if ($order)<th>POZOSTAŁO</th>@endif<th>ILOŚĆ</th><th>CENA</th><th>POWÓD</th><th>KOMENTARZ</th>@if(! $order)<th></th>@endif</tr></thead><tbody id="returnItemsBody">
            @if ($order)
                @foreach ($order->items as $index=>$item)<tr><td>{{ $item->product_id ?: '...' }}<input type="hidden" name="items[{{ $index }}][order_item_id]" value="{{ $item->id }}"></td><td>{{ $item->product_name }}</td><td>{{ $remaining[$item->id] }}</td><td><input class="form-control quantity" type="number" name="items[{{ $index }}][quantity]" min="0" max="{{ $remaining[$item->id] }}" step="1" value="{{ old('items.'.$index.'.quantity', 0) }}" aria-label="Ilość: {{ $item->product_name }}" data-return-quantity></td><td>{{ $item->unit_price_gross }} {{ $order->currency }}</td><td><input class="form-control" name="items[{{ $index }}][reason]" value="{{ old('items.'.$index.'.reason') }}" maxlength="100" aria-label="Powód: {{ $item->product_name }}"></td><td><input class="form-control" name="items[{{ $index }}][comment]" value="{{ old('items.'.$index.'.comment') }}" maxlength="20000" aria-label="Komentarz: {{ $item->product_name }}"></td></tr>@endforeach
            @else
                @foreach (old('items', [['quantity'=>1]]) as $index=>$item)@include('returns._manual-item', ['index'=>$index, 'item'=>$item])@endforeach
            @endif
            </tbody></table></div>
            @if (! $order)<button class="btn btn-sm btn-outline-secondary mt-3" type="button" id="addReturnItem"><i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj produkt</button>@endif
        </section>
        <section class="return-create-panel"><label class="form-label" for="returnNotes">Uwagi do zwrotu</label><textarea class="form-control" id="returnNotes" name="notes" rows="3" maxlength="20000">{{ old('notes') }}</textarea></section>
        <div class="d-flex gap-2 return-create-actions"><button class="btn btn-primary" type="submit">Stwórz zwrot</button><a class="btn btn-outline-secondary" href="{{ $order ? route('orders.show', $order) : route('returns.index') }}">Anuluj</a></div>
    </form>
    @if (! $order)</div></div>@endif
</div>
@if (! $order)<template id="returnItemTemplate">@include('returns._manual-item', ['index'=>'__INDEX__', 'item'=>['quantity'=>1]])</template>@endif
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('returnItemsBody');
    let next = {{ $order ? 0 : max([0, ...array_map('intval', array_keys(old('items', [[]])))]) + 1 }};
    document.getElementById('addReturnItem')?.addEventListener('click', () => {
        const template = document.getElementById('returnItemTemplate').innerHTML.replaceAll('__INDEX__', String(next++));
        body.insertAdjacentHTML('beforeend', template);
    });
    body.addEventListener('click', event => { const button = event.target.closest('[data-remove-return-item]'); if (button && body.rows.length > 1) button.closest('tr').remove(); });
    document.getElementById('returnAllProducts')?.addEventListener('click', () => { body.querySelectorAll('[data-return-quantity]').forEach(input => { input.value = input.max; }); });
});
</script>
@endsection
