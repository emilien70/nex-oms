@extends('layouts.app')
@section('title', 'Dane podatnika JPK - NEX-OMS')
@section('content')
@include('invoices._navigation')
@include('invoices._settings-navigation')
<style>
    .jpk-profile { max-width: 920px; background: #fff; padding: 24px; }
    .jpk-profile h1 { font-size: 20px; margin-bottom: 24px; }
    .sr-row { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 16px; margin-bottom: 16px; }
    .sr-row[hidden] { display: none; }
    .sr-label { padding-top: 7px; }
    .jpk-profile .form-control, .jpk-profile .form-select { min-width: 0; font-size: 13px; }
    .jpk-profile-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
    @media (max-width: 600px) { .jpk-profile { padding: 16px; } .sr-row { grid-template-columns: minmax(0, 1fr); gap: 4px; } }
</style>
<section class="jpk-profile">
    <h1>Dane podatnika do JPK</h1>
    @if ($problems)<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul></div>@endif
    @if ($source)<p class="alert alert-info">Źródło podpowiedzi: seria „{{ $source }}”. Dane nie zostały jeszcze zapisane.</p>@endif
    <form action="{{ route('invoices.jpk-profile.save') }}" method="POST">
        @csrf
        <input type="hidden" name="expected_lock_version" value="{{ $version }}">
        @include('invoices.jpk-profile._fields')
        <div class="border-top pt-3 mt-4">
            <div class="sr-row"><label class="sr-label" for="jpk-source">Źródło danych firmy</label><select id="jpk-source" name="source_series_id" class="form-select"><option value="">Wybierz serię Faktur</option>@foreach ($sources as $series)<option value="{{ $series->id }}">{{ $series->name }}</option>@endforeach</select></div>
            <button class="btn btn-outline-secondary btn-sm" type="submit" formaction="{{ route('invoices.jpk-profile.suggest') }}" formnovalidate onclick="return confirm('Uzupełnić NIP, e-mail, telefon i nazwę danymi wskazanej serii? Obecne wartości tych pól mogą zostać zastąpione.');">Uzupełnij z danych firmy</button>
        </div>
        <div class="jpk-profile-actions"><button class="btn btn-primary" type="submit">Zapisz profil JPK</button><a class="btn btn-link" href="{{ route('invoices.sales-register.create') }}">Rejestr sprzedaży</a></div>
    </form>
</section>
@include('invoices.jpk-profile._script')
@endsection
