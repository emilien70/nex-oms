@extends('layouts.app')

@section('title', 'Lista integracji - NEX-OMS')

@section('content')
    <style>
        .integrations-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .integrations-header {
            margin-bottom: 18px;
        }

        .integrations-title {
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .integrations-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fill, 140px);
        }

        .integration-tile {
            align-items: center;
            background: #ffffff;
            border: 1px solid #cfd6df;
            border-radius: 4px;
            color: inherit;
            cursor: pointer;
            display: flex;
            height: 140px;
            justify-content: center;
            width: 140px;
            text-decoration: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .integration-tile:hover,
        .integration-tile:focus-visible {
            border-color: #0d6efd;
            box-shadow: 0 2px 8px rgba(13, 110, 253, .14);
            color: inherit;
            outline: none;
        }

        .integration-ksef-logo {
            color: #050505;
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
        }

        .integration-ksef-logo-mark {
            color: #df111a;
        }
    </style>

    <div class="integrations-page">
        <header class="integrations-header">
            <h1 class="integrations-title">Lista integracji</h1>
        </header>

        <section class="integrations-grid" aria-label="Dostępne integracje">
            <a
                class="integration-tile"
                data-integration="ksef"
                aria-label="KSeF"
                href="{{ route('integrations.ksef.edit') }}"
            >
                <span class="integration-ksef-logo" aria-hidden="true">K<span class="integration-ksef-logo-mark">S</span>eF</span>
            </a>
        </section>
    </div>
@endsection
