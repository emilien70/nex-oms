@php
    $displaySubmission = $currentKsefSubmission ?? $latestKsefSubmission;
    $configuredEnvironment = $ksefSettings?->environment;
    $isTestEnvironment = $configuredEnvironment === \Modules\Ksef\Enums\KsefEnvironment::Test;
    $canSend = $invoice->isInvoice()
        && $invoice->isFinalized()
        && $ksefSubmissionGateEnabled
        && $ksefSettings?->is_active
        && $isTestEnvironment
        && $ksefSeriesEnabled
        && $currentKsefSubmission === null;
    $canRefresh = $ksefSubmissionGateEnabled
        && $isTestEnvironment
        && in_array($currentKsefSubmission?->status, [
            \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Submitted,
            \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Processing,
        ], true);
@endphp

<style>
    .invoice-ksef-panel {
        background: #fff;
        border: 1px solid #dfe4ea;
        border-left: 3px solid #087fe5;
        border-radius: 7px;
        margin-top: 16px;
        padding: 16px;
    }

    .invoice-ksef-header,
    .invoice-ksef-status-row {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: space-between;
    }

    .invoice-ksef-title {
        color: #20242a;
        font-size: 17px;
        font-weight: 600;
        margin: 0;
    }

    .invoice-ksef-status-row {
        justify-content: flex-start;
        margin-top: 14px;
    }

    .invoice-ksef-number {
        overflow-wrap: anywhere;
        user-select: all;
    }

    .invoice-ksef-message {
        color: #5b6470;
        font-size: 13px;
        margin: 10px 0 0;
    }

    .invoice-ksef-warning {
        color: #8a4b08;
        font-weight: 600;
    }

    .invoice-ksef-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .invoice-ksef-history {
        margin-top: 18px;
    }

    .invoice-ksef-history-title {
        font-size: 14px;
        font-weight: 600;
        margin: 0 0 8px;
    }

    .invoice-ksef-history .table {
        font-size: 12px;
        margin-bottom: 0;
    }
</style>

<section class="invoice-ksef-panel" data-ksef-submissions-panel>
    <div class="invoice-ksef-header">
        <h2 class="invoice-ksef-title">KSeF</h2>
        <span class="badge text-bg-dark" data-ksef-environment>
            {{ $configuredEnvironment ? strtoupper($configuredEnvironment->value) : 'BRAK KONFIGURACJI' }}
        </span>
    </div>

    <div class="invoice-ksef-status-row">
        <span>Bieżący status:</span>
        @if ($displaySubmission)
            <span class="badge text-bg-{{ $displaySubmission->status->badgeVariant() }}" data-ksef-current-status>
                {{ $displaySubmission->status->label() }}
            </span>
            <span class="text-muted">({{ strtoupper($displaySubmission->environment->value) }})</span>
        @else
            <span class="badge text-bg-secondary" data-ksef-current-status>Nie wysłano</span>
        @endif
    </div>

    @if ($displaySubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted && $displaySubmission->ksef_number)
        <p class="invoice-ksef-message">
            Numer KSeF:
            <strong class="invoice-ksef-number" data-ksef-number>{{ $displaySubmission->ksef_number }}</strong>
        </p>
    @endif

    @if ($displaySubmission?->safe_error_message)
        <p class="invoice-ksef-message" data-ksef-safe-error>{{ $displaySubmission->safe_error_message }}</p>
    @endif

    @if ($displaySubmission?->session_close_error_message)
        <p class="invoice-ksef-message invoice-ksef-warning">{{ $displaySubmission->session_close_error_message }}</p>
    @endif

    @if ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Uncertain)
        <p class="invoice-ksef-message invoice-ksef-warning">Nie wysyłaj ponownie. Stan dostarczenia jest niepewny.</p>
    @elseif ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Preparing)
        <p class="invoice-ksef-message invoice-ksef-warning">Próba została rozpoczęta. Nie wysyłaj ponownie.</p>
    @elseif ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::SessionOpened)
        <p class="invoice-ksef-message invoice-ksef-warning">Sesja została otwarta. Nie wysyłaj Faktury ponownie.</p>
    @endif

    <div class="invoice-ksef-actions">
        @if ($canSend)
            <form method="POST" action="{{ route('invoices.ksef.submissions.store', $invoice) }}" data-ksef-send-form onsubmit="return window.confirm('Wyślij Fakturę do KSeF TEST?')">
                @csrf
                <button class="btn btn-primary" type="submit">Wyślij do KSeF TEST</button>
            </form>
        @elseif ($canRefresh)
            <form method="POST" action="{{ route('invoices.ksef.submissions.refresh', ['invoice' => $invoice, 'submission' => $currentKsefSubmission]) }}" data-ksef-refresh-form>
                @csrf
                <button class="btn btn-outline-primary" type="submit">Sprawdź status</button>
            </form>
        @endif
    </div>

    @if (! $ksefSubmissionGateEnabled)
        <p class="invoice-ksef-message">Wysyłka KSeF jest wyłączona na poziomie wdrożenia.</p>
    @elseif (! $ksefSettings?->is_active)
        <p class="invoice-ksef-message">Integracja KSeF nie jest aktywna.</p>
    @elseif (! $isTestEnvironment)
        <p class="invoice-ksef-message">Ręczna wysyłka jest w tym etapie dostępna wyłącznie w środowisku TEST.</p>
    @elseif (! $ksefSeriesEnabled)
        <p class="invoice-ksef-message">Seria numeracji Faktury nie jest włączona do KSeF.</p>
    @elseif ($currentKsefSubmission && ! $canRefresh && ! in_array($currentKsefSubmission->status, [
        \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted,
        \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Uncertain,
        \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Preparing,
        \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::SessionOpened,
    ], true))
        <p class="invoice-ksef-message">Ponowienie wysyłki nie jest dostępne w tym workflow.</p>
    @endif

    <div class="invoice-ksef-history">
        <h3 class="invoice-ksef-history-title">Historia prób</h3>
        @if ($ksefSubmissions->isEmpty())
            <p class="invoice-ksef-message mb-0">Brak prób wysyłki.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle" data-ksef-submission-history>
                    <thead>
                        <tr>
                            <th>Próba</th>
                            <th>Środowisko</th>
                            <th>Status</th>
                            <th>Wygenerowano</th>
                            <th>Sprawdzono</th>
                            <th>Wynik</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ksefSubmissions as $submission)
                            <tr data-ksef-submission-id="{{ $submission->getKey() }}">
                                <td>{{ $submission->attempt_number }}</td>
                                <td>{{ strtoupper($submission->environment->value) }}</td>
                                <td><span class="badge text-bg-{{ $submission->status->badgeVariant() }}">{{ $submission->status->label() }}</span></td>
                                <td>{{ $submission->generated_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td>{{ $submission->last_checked_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td>
                                    @if ($submission->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted && $submission->ksef_number)
                                        <span class="invoice-ksef-number">{{ $submission->ksef_number }}</span>
                                        @if ($submission->acquisition_date)
                                            <br><span class="text-muted">Przyjęto: {{ $submission->acquisition_date->format('d.m.Y H:i') }}</span>
                                        @endif
                                    @elseif ($submission->safe_error_message)
                                        {{ $submission->safe_error_message }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
