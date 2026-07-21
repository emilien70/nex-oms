@extends('layouts.app')

@section('title', 'Edytuj zamowienie #' . $order->id . ' - NEX-OMS')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Edytuj zam&oacute;wienie {{ $order->id }}</h1>
            <div class="text-secondary">Numer wewn&#281;trzny NEX-OMS</div>
        </div>
        <div class="align-self-lg-center">
            <a class="btn btn-outline-secondary" href="{{ route('orders.show', $order) }}">Wr&oacute;&#263; do szczeg&oacute;&#322;&oacute;w</a>
        </div>
    </div>

    @include('orders._form', [
        'action' => route('orders.update', $order),
        'method' => 'PUT',
        'submitLabel' => 'Zapisz zmiany',
    ])
@endsection
