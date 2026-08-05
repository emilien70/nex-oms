@extends('layouts.app')

@section('title', 'Edycja faktury '.$invoice->number.' - NEX-OMS')

@section('content')
    <style>
        .invoice-edit-blocked-page {
            background: #f4f6f8;
            color: #374151;
            margin: -1.5rem;
            min-height: 100vh;
            padding: 20px;
        }

        .invoice-edit-blocked-header {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .invoice-edit-blocked-title {
            color: #20242a;
            font-size: 28px;
            font-weight: 600;
            line-height: 1.2;
            margin: 0;
        }

        .invoice-edit-blocked-alert {
            align-items: flex-start;
            background: #fff;
            border: 1px solid #dfe4ea;
            border-left: 3px solid #f08a16;
            border-radius: 7px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .07);
            display: flex;
            font-size: 13px;
            gap: 14px;
            line-height: 1.55;
            padding: 18px 16px;
        }

        .invoice-edit-blocked-alert-icon {
            color: #f08a16;
            flex: 0 0 auto;
            font-size: 17px;
            line-height: 1.35;
        }

        .invoice-edit-blocked-correction-link {
            color: #087fe5;
            font-weight: 600;
            text-decoration: none;
        }

        .invoice-edit-blocked-correction-link:hover,
        .invoice-edit-blocked-correction-link:focus {
            color: #066fc7;
            text-decoration: underline;
        }

        @media (max-width: 991.98px) {
            .invoice-edit-blocked-page {
                margin: -1rem;
                padding: 12px;
            }

            .invoice-edit-blocked-title {
                font-size: 22px;
            }
        }
    </style>

    <main class="invoice-edit-blocked-page" data-invoice-edit-blocked>
        <div class="invoice-edit-blocked-header">
            <h1 class="invoice-edit-blocked-title">Faktura VAT {{ $invoice->number }}</h1>
            <a class="btn btn-outline-secondary" href="{{ route('orders.show', $invoice->order_id) }}">
                <i class="bi bi-reply me-1" aria-hidden="true"></i>Powrót do zamówienia
            </a>
        </div>

        <div class="invoice-edit-blocked-alert" role="alert">
            <i class="bi bi-info-circle invoice-edit-blocked-alert-icon" aria-hidden="true"></i>
            <div>
                Nie możesz edytować faktury, do której została już wystawiona faktura korygująca.<br>
                Jeśli chcesz edytować tę fakturę, usuń fakturę korygującą
                <a
                    class="invoice-edit-blocked-correction-link"
                    href="{{ route('invoices.corrections.edit', $correction) }}"
                >{{ $correction->number ?: 'Korekta #'.$correction->getKey() }}</a>.
            </div>
        </div>
    </main>
@endsection
