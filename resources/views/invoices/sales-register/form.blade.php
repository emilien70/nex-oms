@extends('layouts.app')
@section('title', 'Rejestr sprzedaży - NEX-OMS')
@section('content')
@include('invoices._navigation')
<style>
    .sales-register { background: #fff; border: 1px solid #dfe3e8; border-radius: 6px; padding: 24px; font-size: 13px; }
    .sales-register h1 { font-size: 19px; margin: 0 0 28px; }
    .sales-register h1::before { content: ''; display: inline-block; width: 8px; height: 8px; background: #0878cf; border-radius: 50%; margin-right: 12px; }
    .sr-row { display: grid; grid-template-columns: minmax(170px, 1fr) minmax(0, 2fr); gap: 20px; margin-bottom: 18px; align-items: start; }
    .sr-row[hidden] { display: none; }
    .sr-label { padding-top: 8px; color: #424c58; }
    .sr-range { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 14px; }
    .sr-range > div { min-width: 0; }
    .sales-register .form-control, .sales-register .form-select { font-size: 13px; min-width: 0; width: 100%; min-height: 38px; }
    .sr-options { border-top: 1px solid #dfe3e8; padding-top: 20px; margin-top: 24px; }
    .sr-toggles { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; margin-top: 12px; }
    .sr-toggles button { background: none; border: 0; padding: 0; font-size: 12px; color: #086bc2; text-align: left; }
    .sr-series { display: flex; flex-direction: column; gap: 8px; padding-top: 8px; }
    .sr-actions { display: flex; gap: 12px; align-items: center; }
    .sr-error { color: #b42318; margin-top: 5px; }
    .sr-jpk-table td { overflow-wrap: anywhere; }
    @media (max-width: 700px) {
        .sr-jpk-table thead { display: none; }
        .sr-jpk-table tr { display: grid; grid-template-columns: 36px minmax(0, 1fr) 100px; padding: 8px 0; border-bottom: 1px solid #dfe3e8; }
        .sr-jpk-table td { border: 0; }
        .sr-jpk-table td:last-child { grid-column: 1 / -1; }
    }
    @media (max-width: 700px) { .sales-register { padding: 16px; } .sr-row { grid-template-columns: minmax(0, 1fr); gap: 6px; } .sr-range { gap: 6px; } }
</style>
<div class="sales-register">
    <h1>Rejestr sprzedaży — Faktury</h1>
    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><strong>Nie można wygenerować rejestru.</strong><ul class="mb-0">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('invoices.sales-register.export') }}" target="{{ $values['format'] === 'html' ? '_blank' : '_self' }}" id="salesRegisterForm">
        @csrf
        <input type="hidden" name="mode" value="{{ $values['mode'] }}">
        <input type="hidden" name="return_to" value="{{ $returnContext->returnTo() }}">
        <input type="hidden" name="return_query" value="{{ $returnContext->query() }}">
        @if ($values['mode'] === 'ids')
            <input type="hidden" name="document_ids" value="{{ $values['document_ids'] }}">
            <p>Wybrane dokumenty: <strong>{{ $selectedCount }}</strong></p>
            @error('document_ids')<p class="sr-error">{{ $message }}</p>@enderror
        @else
            <div class="sr-row">
                <label class="sr-label" for="sr-month">Okres — miesiąc</label>
                <div class="sr-range">
                    <div><select class="form-select" name="month" id="sr-month" aria-describedby="sr-month-error">@foreach ($months as $number => $name)<option value="{{ $number }}" @selected((int) $values['month'] === $number)>{{ $name }}</option>@endforeach</select><div class="sr-error" id="sr-month-error">{{ $errors->first('month') }}</div></div>
                    <span></span>
                    <div><select class="form-select" name="year" id="sr-year" aria-label="Rok" aria-describedby="sr-year-error">@foreach (array_unique([...$years, (int) $values['year']]) as $year)<option value="{{ $year }}" @selected((int) $values['year'] === $year)>{{ $year }}</option>@endforeach</select><div class="sr-error" id="sr-year-error">{{ $errors->first('year') }}</div></div>
                </div>
            </div>
            @foreach (['issue' => 'Data wystawienia', 'sale' => 'Data sprzedaży'] as $prefix => $label)
                <div class="sr-row">
                    <label class="sr-label" for="sr-{{ $prefix }}-from">{{ $label }} od</label>
                    <div>
                        <div class="sr-range">
                            <div><input class="form-control" type="date" name="{{ $prefix }}_from" id="sr-{{ $prefix }}-from" value="{{ $values[$prefix.'_from'] }}" aria-describedby="sr-{{ $prefix }}-from-error"></div>
                            <label for="sr-{{ $prefix }}-to">do</label>
                            <div><input class="form-control" type="date" name="{{ $prefix }}_to" id="sr-{{ $prefix }}-to" value="{{ $values[$prefix.'_to'] }}" aria-label="{{ $label }} do" aria-describedby="sr-{{ $prefix }}-to-error"></div>
                        </div>
                        @foreach (['from', 'to'] as $bound)<div id="sr-{{ $prefix }}-{{ $bound }}-error" class="sr-error">{{ $errors->first($prefix.'_'.$bound) }}</div>@endforeach
                        @if ($prefix === 'issue')<small id="sr-custom-range" @if ($values['issue_from'] === '' && $values['issue_to'] === '') hidden @endif>Własny zakres</small>@endif
                    </div>
                </div>
            @endforeach
            <div class="sr-row">
                <div class="sr-label" id="sr-series-label">Seria numeracji
                    <div class="sr-toggles">
                        @foreach (['all' => 'wszystkie', 'invoice' => 'serie faktur', 'correction' => 'serie korekt'] as $type => $label)
                            <button type="button" data-series-toggle="{{ $type }}"><i class="bi bi-check-square" aria-hidden="true"></i> Zaznacz/Odznacz {{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="sr-series" role="group" aria-labelledby="sr-series-label" aria-describedby="sr-series-error">
                    @foreach ($series as $item)
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="series_ids[]" value="{{ $item->id }}" data-series-type="{{ $item->document_type->value }}" @checked(in_array((string) $item->id, array_map('strval', $values['series_ids']), true))><span class="form-check-label">{{ $item->name }} ({{ $item->document_type->value === 'invoice' ? 'Faktura' : 'Korekta' }}){{ $item->is_active ? '' : ' — ukryta' }}</span></label>
                    @endforeach
                    <div class="sr-error" id="sr-series-error">{{ $errors->first('series_ids') }}</div>
                </div>
            </div>
            @foreach (['tax_id_presence' => ['NIP', ['all' => 'Wszystkie faktury', 'with' => 'Z NIP', 'without' => 'Bez NIP']], 'currency' => ['Waluta', ['' => 'Wszystkie waluty'] + $currencies], 'country' => ['Kraj (kod)', ['' => 'Wszystkie kraje'] + $countries]] as $field => [$label, $choices])
                <div class="sr-row"><label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label><div><select class="form-select" id="sr-{{ $field }}" name="{{ $field }}">@foreach ($choices as $value => $text)<option value="{{ $value }}" @selected($values[$field] === (string) $value)>{{ $text }}</option>@endforeach</select><div class="sr-error">{{ $errors->first($field) }}</div></div></div>
            @endforeach
        @endif
        <div class="sr-row sr-options"><span class="sr-label">Format eksportu</span><div>
            @foreach (['html' => 'HTML', 'xlsx' => 'XLSX', 'xml' => 'XML', 'jpk_v7m3' => 'JPK_V7M (3) – KSeF'] as $format => $label)
                <label class="form-check mt-2"><input type="radio" class="form-check-input" name="format" value="{{ $format }}" @checked($values['format'] === $format)><span class="form-check-label">{{ $label }}</span></label>
            @endforeach
            <div class="sr-error">{{ $errors->first('format') }}</div>
        </div></div>
        @foreach (['include_header' => 'Dodaj nagłówek', 'include_exchange_rates' => 'Dodaj tabelę walut', 'include_ksef' => 'Dodaj numer KSeF dokumentu'] as $field => $label)
            <div class="sr-row" @if ($field !== 'include_ksef') data-html-option @if ($values['format'] !== 'html') hidden @endif @else data-ksef-option @if ($values['format'] === 'jpk_v7m3') hidden @endif @endif><label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label><div><select class="form-select" id="sr-{{ $field }}" name="{{ $field }}" @disabled($field !== 'include_ksef' && $values['format'] !== 'html')><option value="1" @selected($values[$field] === '1')>Tak</option><option value="0" @selected($values[$field] === '0')>Nie</option></select><div class="sr-error">{{ $errors->first($field) }}</div></div></div>
        @endforeach
        @include('invoices.sales-register._jpk')
        <div class="sr-row"><span></span><div class="sr-actions"><button class="btn btn-primary" type="submit" name="jpk_action" value="review" id="sr-generate">{{ $values['format'] === 'jpk_v7m3' ? 'Sprawdź eksport' : 'Generuj' }}</button><a href="{{ $returnContext->url(0) }}" class="btn btn-link">Powrót do listy</a></div></div>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('salesRegisterForm');
    const updateFormat = () => {
        const download = form.querySelector('[name="format"]:checked')?.value !== 'html';
        form.target = download ? '_self' : '_blank';
        form.querySelectorAll('[data-html-option]').forEach(row => {
            row.hidden = download;
            row.querySelector('select').disabled = download;
        });
        const jpk = form.querySelector('[name="format"]:checked')?.value === 'jpk_v7m3';
        const section = document.getElementById('sr-jpk');
        section.hidden = !jpk;
        section.disabled = !jpk;
        const ksef = form.querySelector('[data-ksef-option]');
        ksef.hidden = jpk;
        document.getElementById('sr-generate').textContent = jpk ? 'Sprawdź eksport' : 'Generuj';
    };
    form.querySelectorAll('[name="format"]').forEach(input => input.addEventListener('change', updateFormat));
    updateFormat();
    const taxpayer = form.querySelector('[name="jpk_type"]');
    const updateTaxpayer = () => form.querySelectorAll('[data-taxpayer]').forEach(row => {
        row.hidden = row.dataset.taxpayer !== taxpayer.value;
        row.querySelector('input').disabled = row.hidden;
    });
    taxpayer.addEventListener('change', updateTaxpayer);
    updateTaxpayer();
    form.querySelectorAll('[data-series-toggle]').forEach(button => button.addEventListener('click', () => {
        const boxes = [...form.querySelectorAll('[data-series-type]')].filter(box => button.dataset.seriesToggle === 'all' || box.dataset.seriesType === button.dataset.seriesToggle);
        const checked = !boxes.every(box => box.checked);
        boxes.forEach(box => box.checked = checked);
    }));
    const from = document.getElementById('sr-issue-from');
    const to = document.getElementById('sr-issue-to');
    const updateRange = () => document.getElementById('sr-custom-range').hidden = !from.value && !to.value;
    [from, to].forEach(input => input?.addEventListener('input', updateRange));
    ['sr-month', 'sr-year'].forEach(id => document.getElementById(id)?.addEventListener('change', () => { from.value = ''; to.value = ''; updateRange(); }));
});
</script>
@endsection
