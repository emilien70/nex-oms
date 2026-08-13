@extends('layouts.app')

@section('title', 'Edycja faktury '.$invoice->number.' - NEX-OMS')

@section('content')
    @php
        $backToInvoiceList = $returnContext->isList();
        $backUrl = $returnContext->url($invoice->order_id);
    @endphp

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

        .invoice-edit-blocked-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
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
            <div class="btn-group" role="group" aria-label="Akcje Faktury VAT">
                <a class="btn btn-outline-secondary" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj
                </a>
                <a class="btn btn-outline-secondary" href="{{ $backUrl }}" data-invoice-back-button>
                    <i class="bi bi-reply me-1" aria-hidden="true"></i>{{ $backToInvoiceList ? 'Powrót' : 'Powrót do zamówienia' }}
                </a>
            </div>
        </div>

        <div class="invoice-edit-blocked-alert" role="alert">
            <i class="bi bi-info-circle invoice-edit-blocked-alert-icon" aria-hidden="true"></i>
            <div>
                @if ($invoice->isFinalized())
                    Dokument został zamknięty i nie może być edytowany.

                    @if ($latestFinalizedCorrection)
                        <br>
                        Ostatnia zamknięta Korekta:
                        <a
                            class="invoice-edit-blocked-correction-link"
                            href="{{ route('invoices.corrections.edit', ['correction' => $latestFinalizedCorrection, ...$returnContext->parameters()]) }}"
                        >{{ $latestFinalizedCorrection->number ?: 'Korekta #'.$latestFinalizedCorrection->getKey() }}</a>.
                    @endif

                    @if ($currentCorrection)
                        <br>
                        Bieżąca Korekta:
                        <a
                            class="invoice-edit-blocked-correction-link"
                            href="{{ route('invoices.corrections.edit', ['correction' => $currentCorrection, ...$returnContext->parameters()]) }}"
                        >{{ $currentCorrection->number ?: 'Korekta #'.$currentCorrection->getKey() }}</a>.
                    @endif
                @elseif ($latestFinalizedCorrection)
                    Faktura posiada zamknięte Korekty i nie może być edytowana.<br>
                    Ostatnia zamknięta Korekta:
                    <a
                        class="invoice-edit-blocked-correction-link"
                        href="{{ route('invoices.corrections.edit', ['correction' => $latestFinalizedCorrection, ...$returnContext->parameters()]) }}"
                    >{{ $latestFinalizedCorrection->number ?: 'Korekta #'.$latestFinalizedCorrection->getKey() }}</a>.

                    @if ($currentCorrection)
                        <br>
                        Bieżąca Korekta:
                        <a
                            class="invoice-edit-blocked-correction-link"
                            href="{{ route('invoices.corrections.edit', ['correction' => $currentCorrection, ...$returnContext->parameters()]) }}"
                        >{{ $currentCorrection->number ?: 'Korekta #'.$currentCorrection->getKey() }}</a>.
                    @endif
                @elseif ($currentCorrection)
                    Nie możesz edytować faktury, do której została już wystawiona faktura korygująca.<br>
                    Jeśli chcesz edytować tę fakturę, usuń fakturę korygującą
                    <a
                        class="invoice-edit-blocked-correction-link"
                        href="{{ route('invoices.corrections.edit', ['correction' => $currentCorrection, ...$returnContext->parameters()]) }}"
                    >{{ $currentCorrection->number ?: '#'.$currentCorrection->getKey() }}</a>.
                @else
                    Faktura nie może być edytowana.
                @endif

                @if (! $currentCorrection)
                    <div class="invoice-edit-blocked-actions">
                        @if ($correctionSeries->count() === 1)
                            <a class="btn btn-primary" href="{{ route('invoices.corrections.create', ['invoice' => $invoice, 'series_id' => $correctionSeries->first()->id, ...$returnContext->parameters()]) }}">
                                Wystaw {{ $latestFinalizedCorrection ? 'kolejną ' : '' }}Korektę
                            </a>
                        @elseif ($correctionSeries->count() > 1)
                            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#blockedInvoiceCorrectionSeriesModal" data-correction-url="{{ route('invoices.corrections.create', $invoice) }}">
                                Wystaw {{ $latestFinalizedCorrection ? 'kolejną ' : '' }}Korektę
                            </button>
                        @else
                            <button class="btn btn-primary" type="button" disabled title="Brak aktywnej serii numeracji dla Korekt">Wystaw Korektę</button>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @include('invoices.partials.correction-series-modal', [
            'correctionSeries' => $correctionSeries,
            'modalId' => 'blockedInvoiceCorrectionSeriesModal',
            'returnContext' => $returnContext,
        ])
    </main>
@endsection
