@extends('layouts.app')

@section('title', 'Faktury - NEX-OMS')

@section('content')
    <style>
        .invoices-page {
            background: #f4f6f8;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 24px;
        }

        .invoices-card {
            background: #ffffff;
            border: 1px solid #dfe4ea;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .invoices-header {
            border-bottom: 1px solid #e5e7eb;
            padding: 18px 22px;
        }

        .invoices-title {
            color: #111827;
            font-size: 17px;
            font-weight: 700;
            margin: 0;
        }

        .invoices-placeholder {
            color: #64748b;
            font-size: 13px;
            margin: 0;
            padding: 22px;
        }

        @media (max-width: 767.98px) {
            .invoices-page {
                margin: -1rem;
                padding: 14px;
            }
        }
    </style>

    <div class="invoices-page">
        <section class="invoices-card">
            <header class="invoices-header">
                <h1 class="invoices-title">Faktury</h1>
            </header>
            <p class="invoices-placeholder">Obs&#322;uga faktur zostanie dodana p&oacute;&#378;niej.</p>
        </section>
    </div>
@endsection
