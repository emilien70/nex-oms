<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body { font-family: "Times New Roman", Times, serif; color: #171717; background: white; margin: 3px 7px; font-size: 14px; line-height: 1.15; }
        h1 { font-size: 32px; line-height: 1.15; margin: 0; }
        h2 { font-size: 16px; margin: 10px 0 5px; }
        .description, .count { margin: 4px 0 6px; }
        table { border-collapse: collapse; width: auto; max-width: 100%; table-layout: auto; }
        th { background: #d1d5dc; padding: 3px 5px; text-align: left; }
        td { border-top: 1px solid #777; vertical-align: top; padding: 2px 5px; overflow-wrap: anywhere; }
        th { overflow-wrap: anywhere; }
        .number { width: 132px; } .buyer { width: 200px; } .date { width: 78px; } .ksef { width: 288px; }
        .report-title td, .empty-heading { background: #d1d5dc; text-align: center; padding: 8px 5px 24px; border: 0; border-bottom: 2px solid white; }
        .report-title .title-gap { background: white; }
        .column-headings th { text-align: center; font-size: 16px; line-height: 1.15; padding: 5px; border-right: 2px solid white; }
        .column-headings th:last-child { border-right: 0; }
        .column-headings .number, .column-headings .date { white-space: nowrap; }
        .report-metadata { margin-top: 8px; }
        .money { text-align: right; font-weight: bold; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .summary-heading td { background: #e9eaec; padding: 4px 5px; font-weight: bold; }
        .coverage td, .note td { border-top: 0; font-size: 11px; }
        .coverage-item + .coverage-item::before { content: " | "; color: #777; }
        .total td { border-top: 2px solid #555; font-weight: bold; }
        .partial { color: #8b3400; font-weight: bold; }
        .warnings { margin-top: 12px; border-top: 2px solid #777; }
        .warnings ul { margin: 5px 0; padding-left: 20px; }
        .warnings li { margin-bottom: 3px; overflow-wrap: anywhere; }
        .rates { margin-top: 12px; }
        tr { break-inside: avoid; }
        @media (max-width: 800px) { .register { min-width: 950px; } }
        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body { margin: 0; font-size: 10px; }
            .register { min-width: 0; width: 100%; }
            thead { display: table-header-group; }
            h1 { font-size: 17px; } .coverage td, .note td { font-size: 9px; }
            .summary-heading { break-after: avoid; } .warnings { break-inside: auto; }
            tbody.summary { break-inside: avoid; }
        }
    </style>
</head>
<body>
@if ($report['records'] === [])
    @if ($options['include_header'])<header class="empty-heading"><h1>{{ $title }}</h1></header>@endif
    <p>Brak dokumentów spełniających wybrane kryteria.</p>
@else
    <table class="register">
        <thead>
        @if ($options['include_header'])
            <tr class="report-title">
                <td colspan="10"><header><h1>{{ $title }}</h1></header></td>
                @if ($options['include_ksef'])<td class="title-gap" aria-hidden="true"></td>@endif
            </tr>
        @endif
        <tr class="column-headings">
            <th class="ordinal" scope="col">Lp.</th><th class="number" scope="col">Numer dokumentu</th><th class="buyer" scope="col">Nabywca</th>
            <th class="date" scope="col">Data<br>wystawienia</th><th class="date" scope="col">Data<br>sprzedaży</th><th scope="col">Netto</th>
            <th class="tax" scope="col">VAT</th><th scope="col">Kwota VAT</th><th scope="col">Brutto</th><th scope="col">Dokument powiązany</th>
            @if ($options['include_ksef'])<th class="ksef" scope="col">Numer KSeF</th>@endif
        </tr></thead>
        <tbody>
        @foreach ($report['records'] as $record)
            <tr data-document-id="{{ $record['id'] }}">
                <td>{{ $record['ordinal'] }}</td><td>{{ $record['number'] ?? '—' }}</td>
                <td>@forelse ($record['buyer_lines'] as $line)<div>{{ $line }}</div>@empty — @endforelse</td>
                <td>{{ $presenter->date($record['issue_date']) }}</td><td>{{ $presenter->date($record['sale_date']) }}</td>
                <td class="money">{{ $presenter->money($record['totals']['net'] ?? null, $record['currency']) }}</td>
                <td>{{ $record['vat_labels'] ?? '—' }}</td>
                <td class="money">{{ $presenter->money($record['totals']['vat'] ?? null, $record['currency']) }}</td>
                <td class="money">{{ $presenter->money($record['totals']['gross'] ?? null, $record['currency']) }}</td>
                <td>@forelse ($record['related_documents'] as $related)<div>{{ $related['type'] === 'correction' ? 'Korekta' : 'Faktura' }} {{ $related['number'] ?? '—' }}</div>@empty — @endforelse</td>
                @if ($options['include_ksef'])<td>{{ $record['ksef_number'] ?? '—' }}</td>@endif
            </tr>
        @endforeach
        </tbody>
        @foreach ($report['summaries']['currencies'] as $currency => $bucket)
            @include('invoices.sales-register.summary', ['heading' => $currency === 'unknown' ? 'PODSUMOWANIE — Nieustalona waluta' : 'PODSUMOWANIE DOKUMENTÓW WYSTAWIONYCH W '.$currency])
        @endforeach
        @if ($report['summaries']['foreign_in_pln']['document_count'] > 0)
            @include('invoices.sales-register.summary', ['heading' => 'PODSUMOWANIE DOKUMENTÓW WALUTOWYCH PRZELICZONYCH NA PLN', 'currency' => 'PLN', 'bucket' => $report['summaries']['foreign_in_pln'], 'converted' => true])
            @include('invoices.sales-register.summary', ['heading' => 'ŁĄCZNE PODSUMOWANIE W PLN', 'currency' => 'PLN', 'bucket' => $report['summaries']['combined_pln'], 'converted' => true])
        @endif
        @foreach ($report['summaries']['countries'] as $country)
            @foreach ($country['currencies'] as $currency => $bucket)
                @include('invoices.sales-register.summary', ['heading' => 'PODSUMOWANIE WG KRAJU — '.$country['label'].' — '.($currency === 'unknown' ? 'Nieustalona waluta' : $currency)])
            @endforeach
        @endforeach
    </table>
    @if ($options['include_exchange_rates'])
        <section class="rates"><h2>Tabela walut</h2>
        @if ($report['summaries']['exchange_rates'] === [])<p>Brak zapisanych kursów walut dla wybranych dokumentów.</p>
        @else
            <table><thead><tr><th>Numer dokumentu</th><th>Waluta</th><th>Kurs</th><th>Data kursu</th><th>Tabela NBP</th></tr></thead><tbody>
            @foreach ($report['summaries']['exchange_rates'] as $rate)<tr><td>{{ $rate['number'] ?? 'ID '.$rate['document_id'] }}</td><td>{{ $rate['source_currency'] }}</td><td>1 {{ $rate['source_currency'] }} = {{ $rate['rate'] }} PLN</td><td>{{ $presenter->date($rate['effective_date']) }}</td><td>{{ $rate['table_number'] }}</td></tr>@endforeach
            </tbody></table>
        @endif
        </section>
    @endif
@endif
<div class="report-metadata">
    @if ($options['include_header'])<p class="description">{{ $description }}</p>@endif
    <p class="count">Liczba dokumentów: {{ $report['selection']['record_count'] }}</p>
</div>
@if ($report['warnings'] !== [])
    <section class="warnings"><h2>Kompletność danych i ostrzeżenia</h2>
        <ul>@foreach ($report['summaries']['completeness'] as $section => $coverage)@if (! $coverage['complete'])<li><strong>{{ $presenter->section($section) }}:</strong> uwzględniono {{ $coverage['included_count'] }}, pominięto {{ $coverage['excluded_count'] }}. Dokumenty: {{ implode(', ', array_map(fn ($id) => $labels[$id] ?? 'ID '.$id, $coverage['excluded_ids'])) }}.</li>@endif @endforeach</ul>
        <ul>@foreach ($report['warnings'] as $warning)<li><strong>{{ $labels[$warning['document_id']] ?? 'ID '.$warning['document_id'] }}</strong> — {{ $presenter->section($warning['section']) }}: {{ $presenter->warning($warning['code']) }}</li>@endforeach</ul>
    </section>
@endif
</body>
</html>
