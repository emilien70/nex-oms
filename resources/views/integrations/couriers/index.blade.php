@extends('layouts.app')

@section('title', 'Kurierzy - NEX-OMS')

@section('content')
    @php
        $inpostStatus = $inpostAccount?->last_error
            ? ['label' => 'Błąd konfiguracji', 'class' => 'is-error']
            : ($inpostAccount?->isOperational()
                ? ['label' => 'Aktywna', 'class' => 'is-active']
                : ['label' => 'Do konfiguracji', 'class' => 'is-pending']);
        $inpostCourierStatus = $inpostCourierAccount?->last_error
            ? ['label' => 'Błąd konfiguracji', 'class' => 'is-error']
            : ($inpostCourierAccount?->isOperational()
                ? ['label' => 'Aktywna', 'class' => 'is-active']
                : ['label' => 'Do konfiguracji', 'class' => 'is-pending']);
        $dpdStatus = $dpdAccount?->last_error
            ? ['label' => 'Błąd konfiguracji', 'class' => 'is-error']
            : ($dpdAccount?->isOperational()
                ? ['label' => 'Aktywna', 'class' => 'is-active']
                : ['label' => 'Do konfiguracji', 'class' => 'is-pending']);
        $allegroShippingStatus = $allegroShippingAccount?->last_error
            ? ['label' => 'B&#322;&#261;d konfiguracji', 'class' => 'is-error']
            : ($allegroShippingAccount?->isOperational()
                ? ['label' => 'Aktywna', 'class' => 'is-active']
                : ['label' => 'Do konfiguracji', 'class' => 'is-pending']);
        $couriers = [
            [
                'name' => 'InPost Paczkomaty',
                'description' => 'Nadawanie przesyłek paczkomatowych i pobieranie etykiet.',
                'status' => $inpostStatus,
                'url' => route('integrations.couriers.inpost-lockers.edit'),
            ],
            [
                'name' => 'Kurier InPost',
                'description' => 'Przesyłki kurierskie InPost dostarczane pod wskazany adres.',
                'status' => $inpostCourierStatus,
                'url' => route('integrations.couriers.inpost-courier.edit'),
            ],
            [
                'name' => 'Allegro Wysyłam',
                'description' => 'Obsługa przesyłek rozliczanych za pośrednictwem Allegro.',
                'status' => $allegroShippingStatus,
                'url' => route('integrations.couriers.allegro-shipping.edit'),
            ],
            [
                'name' => 'DPD',
                'description' => 'Nadawanie krajowych przesyłek kurierskich DPD.',
                'status' => $dpdStatus,
                'url' => route('integrations.couriers.dpd.edit'),
            ],
        ];
    @endphp

    <style>
        .couriers-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .couriers-header {
            margin-bottom: 18px;
        }

        .couriers-title {
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .couriers-description {
            color: #6b7280;
            font-size: 13px;
            margin: 0;
        }

        .couriers-card {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .couriers-card-header,
        .courier-row {
            align-items: center;
            display: grid;
            gap: 16px;
            grid-template-columns: minmax(220px, 1fr) 170px 140px;
        }

        .couriers-card-header {
            border-bottom: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            padding: 12px 18px;
            text-transform: uppercase;
        }

        .courier-row {
            border-bottom: 1px solid #eef2f7;
            min-height: 72px;
            padding: 12px 18px;
        }

        .courier-row:last-child {
            border-bottom: 0;
        }

        .courier-name {
            color: #111827;
            font-size: 14px;
            font-weight: 600;
        }

        a.courier-name {
            display: inline-block;
            text-decoration: none;
        }

        a.courier-name:hover {
            color: #0877c9;
            text-decoration: underline;
        }

        .courier-note {
            color: #6b7280;
            font-size: 12px;
            margin-top: 2px;
        }

        .courier-status {
            align-items: center;
            color: #92400e;
            display: inline-flex;
            font-size: 12px;
            gap: 7px;
        }

        .courier-status-dot {
            background: #f59e0b;
            border-radius: 50%;
            height: 8px;
            width: 8px;
        }

        .courier-status.is-active {
            color: #166534;
        }

        .courier-status.is-active .courier-status-dot {
            background: #22c55e;
        }

        .courier-status.is-error {
            color: #b91c1c;
        }

        .courier-status.is-error .courier-status-dot {
            background: #ef4444;
        }

        @media (max-width: 767.98px) {
            .couriers-card-header {
                display: none;
            }

            .courier-row {
                align-items: start;
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="couriers-page">
        <header class="couriers-header">
            <h1 class="couriers-title">Kurierzy</h1>
            <p class="couriers-description">Konta kurierskie dost&#281;pne do konfiguracji w NEX-OMS.</p>
        </header>

        <section class="couriers-card" aria-label="Lista kurier&oacute;w">
            <div class="couriers-card-header">
                <div>Kurier</div>
                <div>Status</div>
                <div class="text-end">Ustawienia</div>
            </div>

            @foreach ($couriers as $courier)
                <div class="courier-row">
                    <div>
                        @if ($courier['url'])
                            <a class="courier-name" href="{{ $courier['url'] }}">{{ $courier['name'] }}</a>
                        @else
                            <div class="courier-name">{{ $courier['name'] }}</div>
                        @endif
                        <div class="courier-note">{{ $courier['description'] }}</div>
                    </div>
                    <div>
                        <span class="courier-status {{ $courier['status']['class'] }}">
                            <span class="courier-status-dot" aria-hidden="true"></span>
                            {{ $courier['status']['label'] }}
                        </span>
                    </div>
                    <div class="text-end">
                        @if ($courier['url'])
                            <a class="btn btn-sm btn-outline-primary" href="{{ $courier['url'] }}">Ustawienia</a>
                        @else
                            <button class="btn btn-sm btn-outline-primary" type="button" disabled>Ustawienia</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    </div>
@endsection
