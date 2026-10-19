@extends('layouts.app')

@section('title', 'Statystyki - NEX-OMS')

@section('content')
    @php
        $sourceCount = count($state['source_options']);
        $selectedSourceCount = count($state['selected_sources']);
        $sourceSummary = $selectedSourceCount === $sourceCount
            ? 'Wszystkie'
            : ($selectedSourceCount === 0 ? 'Brak' : $selectedSourceCount.' z '.$sourceCount);
        $statusCount = count($state['status_options']);
        $selectedStatusCount = count($state['selected_statuses']);
        $statusSummary = $selectedStatusCount === $statusCount
            ? 'Wszystkie'
            : ($selectedStatusCount === 0 ? 'Brak' : $selectedStatusCount.' z '.$statusCount);
        $chartPanels = collect($report['panels'])->mapWithKeys(fn (array $panel): array => [$panel['id'] => $panel['chart']]);
    @endphp

    <style>
        .analytics-page {
            color: #1f2937;
            padding: 18px 20px 36px;
        }

        .analytics-title {
            font-size: 24px;
            font-weight: 400;
            margin: 0 0 22px;
        }

        .analytics-filter-panel,
        .analytics-panel,
        .analytics-summary-card {
            background: #ffffff;
            border: 1px solid #e2e7ee;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
        }

        .analytics-filter-panel {
            padding: 14px 16px;
        }

        .analytics-filter-grid {
            display: grid;
            gap: 10px 12px;
            grid-template-columns: minmax(260px, 1.25fr) minmax(280px, 1.25fr) minmax(270px, .75fr);
        }

        .analytics-control,
        .analytics-filter-button {
            background-color: #ffffff;
            border: 1px solid #cfd8e3;
            border-radius: 999px;
            color: #172033;
            font-size: 13px;
            height: 40px;
            min-width: 0;
            padding: 0 18px;
            width: 100%;
        }

        .analytics-control:focus,
        .analytics-filter-button:focus-visible {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, .12);
            outline: 0;
        }

        .analytics-filter-button {
            align-items: center;
            display: flex;
            justify-content: space-between;
            overflow: hidden;
            text-align: left;
        }

        .analytics-filter-button > span:first-child {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .analytics-filter-button strong {
            font-weight: 600;
        }

        .analytics-actions {
            display: grid;
            gap: 10px;
            grid-template-columns: 92px minmax(150px, 1fr);
        }

        .analytics-period-arrows {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .analytics-period-arrows .btn {
            align-items: center;
            border-color: #cfd8e3;
            border-radius: 999px 0 0 999px;
            color: #475569;
            display: inline-flex;
            justify-content: center;
            padding: 0;
        }

        .analytics-period-arrows .btn + .btn {
            border-left: 0;
            border-radius: 0 999px 999px 0;
        }

        .analytics-period-arrows .btn.disabled {
            color: #c1c8d2;
            pointer-events: none;
        }

        .analytics-show-button {
            align-items: center;
            border-radius: 999px;
            display: inline-flex;
            font-size: 13px;
            font-weight: 600;
            gap: 9px;
            justify-content: center;
        }

        .analytics-filter-menu {
            border: 1px solid #d7dee7;
            border-radius: 7px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .15);
            max-height: min(520px, calc(100vh - 170px));
            min-width: 100%;
            overflow: auto;
            padding: 10px;
            width: 100%;
        }

        .analytics-filter-menu-actions {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }

        .analytics-filter-menu-actions button {
            background: #f8fafc;
            border: 1px solid #d6dee8;
            border-radius: 4px;
            color: #475569;
            font-size: 11px;
            padding: 5px 8px;
        }

        .analytics-filter-group + .analytics-filter-group {
            margin-top: 12px;
        }

        .analytics-filter-group-title {
            color: #4b5563;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .analytics-filter-option {
            align-items: center;
            border: 1px solid #d8dee7;
            display: flex;
            font-size: 13px;
            gap: 8px;
            margin-top: -1px;
            min-height: 36px;
            padding: 7px 9px;
        }

        .analytics-filter-option:first-of-type {
            margin-top: 0;
        }

        .analytics-custom-dates {
            display: none;
            gap: 10px;
            grid-column: 1 / -1;
            grid-template-columns: repeat(2, minmax(180px, 260px));
        }

        .analytics-custom-dates.is-visible {
            display: grid;
        }

        .analytics-date-field label {
            color: #64748b;
            display: block;
            font-size: 11px;
            margin: 0 0 4px 14px;
        }

        .analytics-error {
            color: #b4232f;
            font-size: 12px;
            grid-column: 1 / -1;
            margin: 2px 4px 0;
        }

        .analytics-panels {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-top: 18px;
        }

        .analytics-panel.is-wide {
            grid-column: 1 / -1;
        }

        .analytics-panel-header {
            align-items: center;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            min-height: 64px;
            padding: 14px 28px;
        }

        .analytics-panel-title {
            align-items: center;
            display: flex;
            font-size: 18px;
            font-weight: 600;
            gap: 12px;
            margin: 0;
        }

        .analytics-panel-title::before {
            background: #0d83db;
            border-radius: 50%;
            content: '';
            flex: 0 0 10px;
            height: 10px;
        }

        .analytics-panel-tools {
            align-items: center;
            display: flex;
            gap: 10px;
        }

        .analytics-interval-select {
            border: 1px solid #cfd8e3;
            border-radius: 6px;
            color: #334155;
            font-size: 12px;
            height: 32px;
            padding: 0 30px 0 10px;
        }

        .analytics-csv-button {
            align-items: center;
            border-color: #cfd8e3;
            border-radius: 6px;
            color: #475569;
            display: inline-flex;
            font-size: 12px;
            gap: 5px;
            height: 32px;
            padding: 0 11px;
        }

        .analytics-chart-shell {
            height: 350px;
            padding: 12px 24px 24px;
            position: relative;
        }

        .analytics-panel.is-half .analytics-chart-shell {
            height: 350px;
        }

        .analytics-no-data {
            align-items: center;
            color: #94a3b8;
            display: none;
            font-size: 13px;
            inset: 0;
            justify-content: center;
            position: absolute;
        }

        .analytics-summary-grid {
            display: grid;
            gap: 18px;
            grid-column: 1 / -1;
            grid-template-columns: 290px minmax(320px, 1fr) minmax(420px, 1.6fr);
        }

        .analytics-summary-card {
            min-height: 210px;
            padding: 26px 30px;
        }

        .analytics-summary-icon {
            align-items: center;
            background: #eef7ff;
            border-radius: 50%;
            color: #0d83db;
            display: inline-flex;
            font-size: 24px;
            height: 62px;
            justify-content: center;
            width: 62px;
        }

        .analytics-summary-number {
            color: #172033;
            font-size: 54px;
            font-weight: 300;
            line-height: 1;
            margin-top: 42px;
        }

        .analytics-summary-number small {
            font-size: 20px;
        }

        .analytics-order-count {
            align-items: flex-end;
            display: flex;
            justify-content: space-between;
        }

        .analytics-order-count-label {
            font-size: 20px;
            font-weight: 700;
            padding-bottom: 8px;
            text-align: right;
        }

        .analytics-order-count-label small {
            color: #64748b;
            display: block;
            font-size: 13px;
            font-weight: 400;
        }

        .analytics-summary-heading {
            color: #172033;
            font-size: 19px;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .analytics-summary-heading i {
            color: #0d83db;
        }

        .analytics-summary-table {
            border: 1px solid #d8dee7;
            width: 100%;
        }

        .analytics-summary-table td {
            border-bottom: 1px solid #d8dee7;
            font-size: 13px;
            padding: 9px 14px;
        }

        .analytics-summary-table tr:last-child td {
            border-bottom: 0;
        }

        .analytics-summary-table td:last-child {
            text-align: right;
        }

        .analytics-summary-amount {
            border-radius: 4px;
            color: #ffffff;
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            padding: 3px 5px;
            white-space: nowrap;
        }

        .analytics-summary-amount.is-total {
            background: #157347;
        }

        .analytics-summary-amount.is-products {
            background: #f07c00;
        }

        .analytics-summary-amount.is-shipping {
            background: #98a2ad;
        }

        @media (max-width: 1199.98px) {
            .analytics-filter-grid,
            .analytics-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .analytics-actions,
            .analytics-summary-card:last-child {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 767.98px) {
            .analytics-page {
                padding: 14px 12px 28px;
            }

            .analytics-filter-grid,
            .analytics-panels,
            .analytics-summary-grid {
                grid-template-columns: 1fr;
            }

            .analytics-actions,
            .analytics-panel.is-wide,
            .analytics-summary-grid,
            .analytics-summary-card:last-child {
                grid-column: auto;
            }

            .analytics-panel-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 16px;
            }

            .analytics-panel-title {
                font-size: 16px;
            }

            .analytics-panel-tools {
                justify-content: space-between;
                width: 100%;
            }

            .analytics-chart-shell,
            .analytics-panel.is-half .analytics-chart-shell {
                height: 320px;
                padding: 8px 12px 18px;
            }

            .analytics-custom-dates {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <main class="analytics-page">
        <h1 class="analytics-title">Statystyki</h1>

        <form id="analyticsFilters" class="analytics-filter-panel" method="GET" action="{{ route('analytics.basic') }}">
            <div class="analytics-filter-grid">
                <select class="analytics-control" name="type" aria-label="Rodzaj danych">
                    @foreach ($state['type_options'] as $value => $label)
                        <option value="{{ $value }}" @selected($state['type'] === $value)>Rodzaj danych: {{ $label }}</option>
                    @endforeach
                </select>

                <select class="analytics-control" name="range" aria-label="Zakres dat" data-analytics-range>
                    @foreach ($state['range_options'] as $value => $label)
                        <option value="{{ $value }}" @selected($state['period']['key'] === $value)>
                            Zakres: {{ $label }}{{ $state['period']['key'] === $value ? ': '.$state['period']['label'] : '' }}
                        </option>
                    @endforeach
                </select>

                <div class="analytics-actions">
                    <div class="analytics-period-arrows">
                        <a class="btn btn-outline-secondary" href="{{ $previousUrl }}" aria-label="Poprzedni okres" title="Poprzedni okres">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </a>
                        <a class="btn btn-outline-secondary {{ $state['period']['to']->isToday() ? 'disabled' : '' }}" href="{{ $nextUrl }}" aria-label="Następny okres" title="Następny okres">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <button class="btn btn-primary analytics-show-button" type="submit">
                        <i class="bi bi-bar-chart" aria-hidden="true"></i>
                        Pokaż
                    </button>
                </div>

                <div class="dropdown">
                    <input type="hidden" name="sources_filter" value="1">
                    <button class="analytics-filter-button" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <span>Źródło zamówienia: <strong>{{ $sourceSummary }}</strong></span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu analytics-filter-menu">
                        <div class="analytics-filter-menu-actions">
                            <button type="button" data-check-all="sources[]"><i class="bi bi-check-square me-1"></i>Zaznacz wszystkie</button>
                            <button type="button" data-uncheck-all="sources[]"><i class="bi bi-square me-1"></i>Odznacz wszystkie</button>
                        </div>
                        @foreach ($state['source_groups'] as $group)
                            <div class="analytics-filter-group">
                                <div class="analytics-filter-group-title">{{ $group['name'] }}</div>
                                @foreach ($group['options'] as $option)
                                    <label class="analytics-filter-option">
                                        <input class="form-check-input mt-0" type="checkbox" name="sources[]" value="{{ $option['key'] }}" @checked(in_array($option['key'], $state['selected_sources'], true))>
                                        <span>{{ $option['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="dropdown">
                    <input type="hidden" name="statuses_filter" value="1">
                    <button class="analytics-filter-button" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <span>Status zamówienia: <strong>{{ $statusSummary }}</strong></span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu analytics-filter-menu">
                        <div class="analytics-filter-menu-actions">
                            <button type="button" data-check-all="statuses[]"><i class="bi bi-check-square me-1"></i>Zaznacz wszystkie</button>
                            <button type="button" data-uncheck-all="statuses[]"><i class="bi bi-square me-1"></i>Odznacz wszystkie</button>
                        </div>
                        <div class="analytics-filter-group">
                            <div class="analytics-filter-group-title">Statusy OMS</div>
                            @foreach ($state['status_options'] as $value => $label)
                                <label class="analytics-filter-option">
                                    <input class="form-check-input mt-0" type="checkbox" name="statuses[]" value="{{ $value }}" @checked(in_array($value, $state['selected_statuses'], true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <select class="analytics-control" name="currency" aria-label="Waluta">
                    @foreach ($state['currency_options'] as $value => $label)
                        <option value="{{ $value }}" @selected($state['currency'] === $value)>Waluta: {{ $label }}</option>
                    @endforeach
                </select>

                <div class="analytics-custom-dates {{ $state['period']['key'] === 'custom' ? 'is-visible' : '' }}" data-analytics-custom-dates>
                    <div class="analytics-date-field">
                        <label for="analyticsDateFrom">Data od</label>
                        <input id="analyticsDateFrom" class="analytics-control" type="date" name="date_from" value="{{ $state['period']['from']->toDateString() }}" max="{{ now()->toDateString() }}">
                    </div>
                    <div class="analytics-date-field">
                        <label for="analyticsDateTo">Data do</label>
                        <input id="analyticsDateTo" class="analytics-control" type="date" name="date_to" value="{{ $state['period']['to']->toDateString() }}" max="{{ now()->toDateString() }}">
                    </div>
                </div>

                @if ($errors->any())
                    <div class="analytics-error">{{ $errors->first() }}</div>
                @endif
            </div>
        </form>

        <section class="analytics-panels" aria-label="{{ $report['title'] }}">
            @foreach ($report['panels'] as $panel)
                <article class="analytics-panel {{ $panel['layout'] === 'wide' ? 'is-wide' : 'is-half' }}">
                    <header class="analytics-panel-header">
                        <h2 class="analytics-panel-title">{{ $panel['title'] }}</h2>
                        <div class="analytics-panel-tools">
                            @if ($panel['interval_control'] ?? false)
                                <select class="analytics-interval-select" name="interval" form="analyticsFilters" aria-label="Grupowanie" onchange="this.form.submit()">
                                    @foreach ($state['interval_options'] as $value => $label)
                                        <option value="{{ $value }}" @selected($state['interval'] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <a class="btn btn-sm btn-outline-secondary analytics-csv-button" href="{{ route('analytics.basic.csv', array_merge($state['query'], ['section' => $panel['id']])) }}">
                                <i class="bi bi-download" aria-hidden="true"></i>
                                Pobierz CSV
                            </a>
                        </div>
                    </header>
                    <div class="analytics-chart-shell">
                        <canvas id="chart-{{ $panel['id'] }}" aria-label="{{ $panel['title'] }}" role="img"></canvas>
                        <div class="analytics-no-data" id="empty-{{ $panel['id'] }}">Brak danych dla wybranych filtrów.</div>
                    </div>
                </article>
            @endforeach

            @if (isset($report['summary']))
                <div class="analytics-summary-grid">
                    <article class="analytics-summary-card">
                        <span class="analytics-summary-icon"><i class="bi bi-clock" aria-hidden="true"></i></span>
                        <div class="analytics-summary-number">{{ $report['summary']['days'] }} <small>dni</small></div>
                    </article>
                    <article class="analytics-summary-card analytics-order-count">
                        <div>
                            <span class="analytics-summary-icon"><i class="bi bi-cart3" aria-hidden="true"></i></span>
                            <div class="analytics-summary-number">{{ $report['summary']['orders'] }}</div>
                        </div>
                        <div class="analytics-order-count-label">
                            Zamówień
                            <small>W określonym czasie</small>
                        </div>
                    </article>
                    <article class="analytics-summary-card">
                        <h2 class="analytics-summary-heading"><i class="bi bi-currency-dollar" aria-hidden="true"></i> Podsumowanie</h2>
                        <table class="analytics-summary-table">
                            <tbody>
                                <tr><td>Łączna wartość</td><td><span class="analytics-summary-amount is-total">{{ $report['summary']['total'] }} {{ $report['summary']['currency'] }}</span></td></tr>
                                <tr><td>Wartość produktów</td><td><span class="analytics-summary-amount is-products">{{ $report['summary']['products'] }} {{ $report['summary']['currency'] }}</span></td></tr>
                                <tr><td>Koszt wysyłek</td><td><span class="analytics-summary-amount is-shipping">{{ $report['summary']['shipping'] }} {{ $report['summary']['currency'] }}</span></td></tr>
                            </tbody>
                        </table>
                    </article>
                </div>
            @endif
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-geo@4.3.6/build/index.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const charts = {{ Illuminate\Support\Js::from($chartPanels) }};
            const currency = @json($state['currency']);
            const colors = ['#1677b9', '#f7ad45', '#d92d63', '#e26b9a', '#7e57c2', '#2f9e8f', '#6b7280', '#62a744', '#d66b2c', '#3a86c8'];
            const money = (cents) => new Intl.NumberFormat('pl-PL', { style: 'currency', currency }).format((Number(cents) || 0) / 100);

            const commonOptions = (horizontal = false) => ({
                maintainAspectRatio: false,
                responsive: true,
                indexAxis: horizontal ? 'y' : 'x',
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { labels: { boxWidth: 13, boxHeight: 13, color: '#475569', font: { size: 12 } } },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw && typeof context.raw === 'object' ? context.raw.value : context.raw;
                                return `${context.dataset.label || ''}: ${context.dataset.money ? money(value) : value}`;
                            },
                        },
                    },
                },
                scales: horizontal ? {
                    x: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#64748b' } },
                    y: { grid: { display: false }, ticks: { color: '#475569', autoSkip: false } },
                } : {
                    x: { grid: { color: '#e5e7eb' }, ticks: { color: '#64748b', maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#64748b', precision: 0 } },
                },
            });

            const hasValues = (chart) => {
                if (chart.type === 'geo') {
                    return chart.values.some((item) => Number(item.value) !== 0);
                }
                return chart.datasets.some((dataset) => dataset.data.some((value) => Number(value) !== 0));
            };

            const showEmpty = (id) => {
                document.getElementById(`chart-${id}`).style.display = 'none';
                document.getElementById(`empty-${id}`).style.display = 'flex';
            };

            const renderStandardChart = (id, chart) => {
                const context = document.getElementById(`chart-${id}`);
                const horizontal = chart.horizontal === true;
                const options = commonOptions(horizontal);
                let type = chart.type;
                let datasets = chart.datasets.map((dataset, index) => ({ ...dataset }));

                if (type === 'pie') {
                    type = 'pie';
                    options.scales = undefined;
                    options.interaction = { intersect: true, mode: 'nearest' };
                    datasets = datasets.map((dataset) => ({ ...dataset, backgroundColor: colors, borderColor: '#ffffff', borderWidth: 1 }));
                } else if (type === 'dual-line') {
                    type = 'line';
                    options.scales = {
                        x: { grid: { color: '#e5e7eb' }, ticks: { color: '#64748b', maxRotation: 0, autoSkip: true } },
                        count: { beginAtZero: true, position: 'left', grid: { color: '#e5e7eb' }, ticks: { color: '#64748b', precision: 0 } },
                        money: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#64748b', callback: (value) => money(value) } },
                    };
                    datasets = datasets.map((dataset, index) => ({
                        ...dataset,
                        yAxisID: dataset.axis,
                        borderColor: colors[index],
                        backgroundColor: `${colors[index]}20`,
                        pointBackgroundColor: colors[index],
                        pointRadius: 3,
                        borderWidth: 2,
                        fill: true,
                        tension: .12,
                    }));
                } else if (type === 'dual-bar') {
                    type = 'bar';
                    options.scales = {
                        x: { grid: { display: false }, ticks: { color: '#64748b' } },
                        count: { beginAtZero: true, position: 'left', grid: { color: '#e5e7eb' }, ticks: { color: '#64748b', precision: 0 } },
                        money: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#64748b', callback: (value) => money(value) } },
                    };
                    datasets = datasets.map((dataset, index) => ({ ...dataset, yAxisID: dataset.axis, backgroundColor: colors[index] }));
                } else {
                    datasets = datasets.map((dataset, index) => ({ ...dataset, backgroundColor: colors[index] }));
                    if (datasets.some((dataset) => dataset.money)) {
                        options.scales[horizontal ? 'x' : 'y'].ticks.callback = (value) => money(value);
                    }
                }

                new Chart(context, { type, data: { labels: chart.labels, datasets }, options });
            };

            const renderGeoChart = async (id, chart) => {
                if (!window.ChartGeo) {
                    showEmpty(id);
                    return;
                }

                try {
                    Chart.register(ChartGeo.ChoroplethController, ChartGeo.GeoFeature, ChartGeo.ColorScale, ChartGeo.ProjectionScale);
                    const response = await fetch('https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json');
                    const atlas = await response.json();
                    const countries = ChartGeo.topojson.feature(atlas, atlas.objects.countries).features;
                    const values = new Map(chart.values.map((item) => [item.map_name, item]));
                    const data = countries.map((feature) => ({
                        feature,
                        value: values.get(feature.properties.name)?.value || 0,
                        details: values.get(feature.properties.name) || null,
                    }));

                    new Chart(document.getElementById(`chart-${id}`), {
                        type: 'choropleth',
                        data: {
                            labels: countries.map((feature) => feature.properties.name),
                            datasets: [{ label: 'Ilość zamówień', outline: countries, data }],
                        },
                        options: {
                            maintainAspectRatio: false,
                            responsive: true,
                            showOutline: true,
                            showGraticule: false,
                            scales: { projection: { axis: 'x', projection: 'equalEarth' }, color: { axis: 'x', quantize: 5, legend: { position: 'bottom-right' } } },
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: (context) => `${context.raw.details?.label || context.label}: ${context.raw.value}` } },
                            },
                        },
                    });
                } catch (error) {
                    showEmpty(id);
                }
            };

            Object.entries(charts).forEach(([id, chart]) => {
                if (!hasValues(chart)) {
                    showEmpty(id);
                    return;
                }

                if (chart.type === 'geo') {
                    renderGeoChart(id, chart);
                    return;
                }

                renderStandardChart(id, chart);
            });

            const range = document.querySelector('[data-analytics-range]');
            const customDates = document.querySelector('[data-analytics-custom-dates]');
            range?.addEventListener('change', () => customDates?.classList.toggle('is-visible', range.value === 'custom'));

            document.querySelectorAll('[data-check-all], [data-uncheck-all]').forEach((button) => {
                button.addEventListener('click', () => {
                    const name = button.dataset.checkAll || button.dataset.uncheckAll;
                    const checked = button.hasAttribute('data-check-all');
                    document.querySelectorAll(`input[name="${CSS.escape(name)}"]`).forEach((input) => { input.checked = checked; });
                });
            });
        });
    </script>
@endsection
