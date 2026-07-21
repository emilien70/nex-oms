@extends('layouts.app')

@section('title', 'Dodaj zamowienie - NEX-OMS')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Dodaj zam&oacute;wienie</h1>
            <div class="text-secondary">R&#281;czne utworzenie zam&oacute;wienia w NEX-OMS.</div>
        </div>
        <div class="align-self-lg-center">
            <a class="btn btn-outline-secondary" href="{{ route('orders.index') }}">Wr&oacute;&#263; do listy</a>
        </div>
    </div>

    @include('orders._form', [
        'action' => route('orders.store'),
        'method' => 'POST',
        'submitLabel' => 'Utw&oacute;rz zam&oacute;wienie',
    ])
@endsection
