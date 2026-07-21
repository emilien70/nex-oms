@extends('layouts.app')

@section('title', 'Zmienne - NEX-OMS')

@section('content')
    <style>
        .variables-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .variables-card {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .variables-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 22px;
        }

        .variables-title {
            color: #111827;
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .variables-intro {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .variables-example {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #334155;
            display: block;
            font-size: 12px;
            margin-top: 12px;
            overflow-wrap: anywhere;
            padding: 9px 11px;
        }

        .variables-group-title {
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            border-top: 1px solid #e5e7eb;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            margin: 0;
            padding: 9px 16px;
            text-transform: uppercase;
        }

        .variables-group:first-child .variables-group-title {
            border-top: 0;
        }

        .variables-table {
            font-size: 13px;
            margin: 0;
        }

        .variables-table th {
            background: #ffffff;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            padding: 10px 14px;
            text-transform: uppercase;
        }

        .variables-table td {
            border-color: #eef0f3;
            color: #475569;
            padding: 10px 14px;
            vertical-align: middle;
        }

        .variables-table code {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            color: #0369a1;
            font-size: 12px;
            padding: 3px 6px;
            white-space: nowrap;
        }

        .variable-label {
            color: #111827;
            display: block;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .variable-example-value {
            color: #64748b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
        }

        @media (max-width: 767.98px) {
            .variables-page {
                margin: -1rem;
                padding: 14px;
            }

            .variables-table th:nth-child(3),
            .variables-table td:nth-child(3) {
                display: none;
            }
        }
    </style>

    <div class="variables-page">
        <section class="variables-card">
            <header class="variables-header">
                <h1 class="variables-title">Zmienne</h1>
                <p class="variables-intro">
                    Zmiennych mo&#380;na u&#380;ywa&#263; w automatycznych akcjach, a w przysz&#322;o&#347;ci tak&#380;e w wiadomo&#347;ciach e-mail i innych szablonach OMS.
                </p>
                <code class="variables-example">https://multi-click.pl/sndb/add.php?serial=[uwagi_sprzedawcy]&amp;sale_date=[data_zamowienia]&amp;key=SNDB700</code>
            </header>

            @foreach ($variableGroups as $group => $variables)
                <div class="variables-group">
                    <h2 class="variables-group-title">{{ $group }}</h2>
                    <div class="table-responsive">
                        <table class="table variables-table align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Zmienna</th>
                                    <th style="width: 25%;">Dane</th>
                                    <th>Opis</th>
                                    <th style="width: 16%;">Przyk&#322;ad</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variables as $variable)
                                    <tr>
                                        <td><code>{{ $variable['token'] }}</code></td>
                                        <td><span class="variable-label">{{ $variable['label'] }}</span></td>
                                        <td>{{ $variable['description'] }}</td>
                                        <td><span class="variable-example-value">{{ $variable['example'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </section>
    </div>
@endsection
