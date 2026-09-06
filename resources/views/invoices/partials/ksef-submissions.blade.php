@php
    $configuredEnvironment = $ksefSettings?->environment;
    $environmentCode = $configuredEnvironment ? strtoupper($configuredEnvironment->value) : null;
    $isDemoEnvironment = $configuredEnvironment === \Modules\Ksef\Enums\KsefEnvironment::Demo;
    $currentOfflineRow = $ksefOfflineIssuanceRows->first(
        fn (array $row): bool => $row['issuance']->is($currentKsefOfflineIssuance),
    );
    $currentOfflineSubmission = $currentOfflineRow['submission'] ?? null;
    $offlineSubject = $invoice->isCorrection() ? 'Korekta zostanie zamknięta i wystawiona' : 'Faktura zostanie wystawiona';
    $offline24Confirmation = $invoice->isCorrection()
        ? 'Korekta zostanie zamknięta i wystawiona w trybie Offline24 przed przesłaniem do KSeF.'
        : 'Wystawienie w trybie Offline24 tworzy Fakturę przed przesłaniem do KSeF.';
    $canSend = $invoice->isInvoice()
        && $invoice->isFinalized()
        && $currentKsefOfflineIssuance === null
        && $ksefSubmissionGateEnabled
        && $ksefSettings?->is_active
        && $ksefOperationalEnvironmentAllowed
        && $ksefSeriesEnabled
        && $ksefCanCreateAttempt;
    $isRetry = $currentKsefSubmission?->status->allowsNewAttempt() === true;
    $canRefresh = $ksefSubmissionGateEnabled
        && $ksefOperationalEnvironmentAllowed
        && $currentKsefSubmission?->status->allowsStatusRefresh() === true;
    $canReconcile = $ksefSubmissionGateEnabled
        && $ksefOperationalEnvironmentAllowed
        && $currentKsefSubmission?->status->allowsReconciliation() === true
        && filled($currentKsefSubmission->session_reference_number);
    $currentKsefUpo = $currentKsefSubmission?->upo;
    $canFetchUpo = $currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted
        && $currentKsefUpo === null
        && $ksefSubmissionGateEnabled
        && $ksefSettings?->is_active
        && $ksefOperationalEnvironmentAllowed
        && $currentKsefSubmission->environment === $configuredEnvironment;
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

    .invoice-ksef-offline-entry {
        border-top: 1px solid #e5e8ec;
        margin-top: 14px;
        padding-top: 14px;
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
        @if ($currentKsefOfflineIssuance)
            @if ($currentOfflineSubmission)
                <span class="badge text-bg-{{ $currentOfflineSubmission->status->badgeVariant() }}" data-ksef-offline24-status>
                    {{ $currentOfflineSubmission->status->label() }}
                </span>
            @else
                <span class="badge text-bg-warning" data-ksef-offline24-status>{{ $currentKsefOfflineIssuance->procedure->label() }} — wystawiona lokalnie</span>
            @endif
            <span class="text-muted">({{ strtoupper($currentKsefOfflineIssuance->environment->value) }})</span>
        @elseif ($currentKsefSubmission)
            <span class="badge text-bg-{{ $currentKsefSubmission->status->badgeVariant() }}" data-ksef-current-status>
                {{ $currentKsefSubmission->status->label() }}
            </span>
            <span class="text-muted">({{ strtoupper($currentKsefSubmission->environment->value) }})</span>
        @else
            <span class="badge text-bg-secondary" data-ksef-current-status>Nie wysłano</span>
        @endif
    </div>

    @if ($currentKsefOfflineIssuance)
        <p class="invoice-ksef-message" data-ksef-offline24-details>
            P_1: <strong>{{ $currentKsefOfflineIssuance->issue_date->format('d.m.Y') }}</strong><br>
            Wystawiono lokalnie: <strong>{{ $currentKsefOfflineIssuance->issued_at->format('d.m.Y H:i') }}</strong><br>
            Certyfikat Offline: <strong>{{ $currentKsefOfflineIssuance->certificate_serial_number }}</strong><br>
            Status certyfikatu przy wystawieniu: <strong>{{ $currentKsefOfflineIssuance->certificate_remote_status }}</strong><br>
            Numer KSeF:
            <strong>{{ $currentOfflineSubmission?->ksef_number ?: 'jeszcze nie nadano' }}</strong>
        </p>
    @endif

    @if ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted && $currentKsefSubmission->ksef_number)
        <p class="invoice-ksef-message">
            Numer KSeF:
            <strong class="invoice-ksef-number" data-ksef-number>{{ $currentKsefSubmission->ksef_number }}</strong>
        </p>
    @endif

    @if ($currentKsefUpo)
        <p class="invoice-ksef-message" data-ksef-upo-fetched-at>
            UPO pobrano: {{ $currentKsefUpo->fetched_at->format('d.m.Y H:i') }}
        </p>
    @endif

    @if ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Rejected && $currentKsefSubmission->ksef_status_code !== null)
        <p class="invoice-ksef-message" data-ksef-current-status-code>
            Kod KSeF: <strong>{{ $currentKsefSubmission->ksef_status_code }}</strong>
        </p>
    @endif

    @if ($currentKsefSubmission?->safe_error_message)
        <p class="invoice-ksef-message" data-ksef-safe-error>{{ $currentKsefSubmission->safe_error_message }}</p>
    @endif

    @if ($currentKsefSubmission?->session_close_error_message)
        <p class="invoice-ksef-message invoice-ksef-warning">{{ $currentKsefSubmission->session_close_error_message }}</p>
    @endif

    @if ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Uncertain)
        <p class="invoice-ksef-message invoice-ksef-warning">Nie wolno ponownie wysłać dokumentu przed ustaleniem wyniku poprzedniej transmisji.</p>
    @elseif ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Preparing)
        <p class="invoice-ksef-message invoice-ksef-warning">Próba została rozpoczęta. Nie wysyłaj ponownie.</p>
    @elseif ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::SessionOpened)
        <p class="invoice-ksef-message invoice-ksef-warning">Sesja została otwarta. Nie wysyłaj Faktury ponownie.</p>
    @endif

    @if ($isDemoEnvironment)
        <p class="invoice-ksef-message invoice-ksef-warning" data-ksef-demo-warning>
            Środowisko DEMO / przedprodukcyjne. Do testów wysyłaj wyłącznie dokumenty zawierające dane testowe lub fikcyjne. Uwierzytelnienie i uprawnienia środowiska DEMO są rzeczywiste.
        </p>
    @endif

    @foreach ($ksefOfflineIssuanceRows as $offlineRow)
        @php
            $offlineIssuance = $offlineRow['issuance'];
            $offlineSubmission = $offlineRow['submission'];
            $offlineEnvironmentCode = strtoupper($offlineIssuance->environment->value);
            $offlineProcedureActionLabel = mb_strtoupper($offlineIssuance->procedure->label(), 'UTF-8');
            $offlineModeMatches = $offlineSubmission?->hasExpectedInvoicingMode() ?? false;
            $offlineCanTransmit = $ksefSubmissionGateEnabled
                && $ksefSettings?->is_active
                && $offlineRow['environment_allowed']
                && $offlineRow['context_current']
                && ($offlineSubmission === null
                    || $offlineSubmission->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::TechnicalFailed);
            $offlineCanRefresh = $ksefSubmissionGateEnabled
                && $ksefSettings?->is_active
                && $offlineRow['environment_allowed']
                && $offlineRow['context_current']
                && $offlineSubmission?->status->allowsStatusRefresh() === true;
            $offlineCanReconcile = $ksefSubmissionGateEnabled
                && $ksefSettings?->is_active
                && $offlineRow['environment_allowed']
                && $offlineRow['context_current']
                && $offlineSubmission?->status->allowsReconciliation() === true
                && filled($offlineSubmission->session_reference_number);
            $offlineUpo = $offlineSubmission?->upo;
            $technicalCorrection = $offlineRow['technical_correction'];
            $technicalSourceSubmission = $offlineRow['technical_source_submission'];
            $technicalCanPrepare = $ksefSubmissionGateEnabled
                && $ksefSettings?->is_active
                && $offlineRow['environment_allowed']
                && $offlineRow['context_current']
                && $offlineRow['technical_prepare_available'];
            $technicalCanSubmit = $ksefSubmissionGateEnabled
                && $ksefSettings?->is_active
                && $offlineRow['environment_allowed']
                && $offlineRow['context_current']
                && $offlineRow['technical_submit_available'];
        @endphp

        <div class="invoice-ksef-offline-entry" data-ksef-offline-issuance-id="{{ $offlineIssuance->getKey() }}">
            <div class="invoice-ksef-status-row mt-0">
                <strong>{{ $offlineIssuance->procedure->label() }} {{ $offlineEnvironmentCode }}</strong>
                @if ($offlineSubmission)
                    <span class="badge text-bg-{{ $offlineSubmission->status->badgeVariant() }}" data-ksef-offline-submission-status>
                        {{ $offlineSubmission->status->label() }}
                    </span>
                @else
                    <span class="badge text-bg-warning">Wystawiona lokalnie</span>
                @endif
            </div>

            <p class="invoice-ksef-message mb-0">
                P_1: <strong>{{ $offlineIssuance->issue_date->format('d.m.Y') }}</strong>;
                wystawiono: <strong>{{ $offlineIssuance->issued_at->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</strong>.
                @if ($offlineSubmission?->ksef_number)
                    <br>Numer KSeF: <strong class="invoice-ksef-number">{{ $offlineSubmission->ksef_number }}</strong>
                @endif
            </p>

            @if ($offlineSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted && ! $offlineModeMatches)
                <p class="invoice-ksef-message invoice-ksef-warning" data-ksef-offline-mode-mismatch>
                    {{ $offlineSubmission->safe_error_message ?: 'Tryb wystawienia zwrócony przez KSeF nie odpowiada Fakturze Offline. Dokumenty dla nabywcy i UPO są zablokowane.' }}
                </p>
            @elseif ($offlineSubmission?->safe_error_message)
                <p class="invoice-ksef-message" data-ksef-offline-safe-error>{{ $offlineSubmission->safe_error_message }}</p>
            @endif

            <div class="invoice-ksef-actions">
                @if ($offlineSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted && $offlineModeMatches)
                    <a
                        class="btn btn-outline-primary"
                        href="{{ route('invoices.ksef.offline-issuances.accepted-pdf', ['invoice' => $invoice, 'issuance' => $offlineIssuance, 'submission' => $offlineSubmission]) }}"
                        data-ksef-accepted-offline-invoice-download
                    >
                        <i class="bi bi-download" aria-hidden="true"></i>
                        POBIERZ FAKTURĘ KSeF
                    </a>
                    @if ($offlineUpo)
                        <a class="btn btn-outline-secondary" href="{{ route('invoices.ksef.submissions.upo.download', ['invoice' => $invoice, 'submission' => $offlineSubmission]) }}">
                            <i class="bi bi-download" aria-hidden="true"></i>
                            Pobierz UPO
                        </a>
                    @elseif ($ksefSubmissionGateEnabled && $ksefSettings?->is_active && $offlineRow['environment_allowed'] && $offlineRow['context_current'])
                        <form method="POST" action="{{ route('invoices.ksef.submissions.upo.fetch', ['invoice' => $invoice, 'submission' => $offlineSubmission]) }}">
                            @csrf
                            <button class="btn btn-outline-primary" type="submit">Pobierz UPO z KSeF</button>
                        </form>
                    @endif
                @elseif ($technicalCanPrepare)
                    <form
                        method="POST"
                        action="{{ route('invoices.ksef.offline-technical-corrections.prepare', ['invoice' => $invoice, 'issuance' => $offlineIssuance, 'submission' => $technicalSourceSubmission]) }}"
                        data-ksef-technical-correction-prepare-form
                        onsubmit="return window.confirm('Przygotować lokalnie korektę techniczną odrzuconej Faktury Offline? Dane biznesowe zostaną odtworzone z niezmiennego snapshotu Faktury.')"
                    >
                        @csrf
                        <button class="btn btn-outline-warning" type="submit">PRZYGOTUJ KOREKTĘ TECHNICZNĄ</button>
                    </form>
                @elseif ($technicalCanSubmit)
                    <form
                        method="POST"
                        action="{{ route('invoices.ksef.offline-technical-corrections.submit', ['invoice' => $invoice, 'technicalCorrection' => $technicalCorrection]) }}"
                        data-ksef-technical-correction-submit-form
                        onsubmit="return window.confirm('Przekazać przygotowaną korektę techniczną do KSeF {{ $offlineEnvironmentCode }}? Operacja wykona jedną próbę wysyłki.')"
                    >
                        @csrf
                        <button class="btn btn-warning" type="submit">PRZEŚLIJ KOREKTĘ TECHNICZNĄ DO KSeF {{ $offlineEnvironmentCode }}</button>
                    </form>
                @elseif ($offlineCanTransmit)
                    <form
                        method="POST"
                        action="{{ route('invoices.ksef.offline-issuances.submissions.store', ['invoice' => $invoice, 'issuance' => $offlineIssuance]) }}"
                        data-ksef-offline-submit-form
                        onsubmit="return window.confirm('Przekazać do KSeF dokładnie zamrożoną Fakturę Offline? Dokument zostanie wysłany do środowiska {{ $offlineEnvironmentCode }} z trybem offlineMode=true. Treść FA(3) nie zostanie ponownie wygenerowana.')"
                    >
                        @csrf
                        <button class="btn btn-primary" type="submit">
                            {{ $offlineSubmission ? 'PONÓW TRANSMISJĘ '.$offlineProcedureActionLabel.' DO KSeF '.$offlineEnvironmentCode : 'PRZEŚLIJ '.$offlineProcedureActionLabel.' DO KSeF '.$offlineEnvironmentCode }}
                        </button>
                    </form>
                @elseif ($offlineCanRefresh)
                    <form method="POST" action="{{ route('invoices.ksef.submissions.refresh', ['invoice' => $invoice, 'submission' => $offlineSubmission]) }}">
                        @csrf
                        <button class="btn btn-outline-primary" type="submit">Sprawdź status</button>
                    </form>
                @elseif ($offlineCanReconcile)
                    <form method="POST" action="{{ route('invoices.ksef.submissions.reconcile', ['invoice' => $invoice, 'submission' => $offlineSubmission]) }}">
                        @csrf
                        <button class="btn btn-outline-warning" type="submit">Sprawdź wynik transmisji</button>
                    </form>
                @elseif ($offlineSubmission === null || $offlineSubmission->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::TechnicalFailed)
                    <p class="invoice-ksef-message invoice-ksef-warning mb-0">
                        @if (! $offlineRow['context_current'])
                            Ustaw aktywny kontekst NIP zgodny z tym wystawieniem Offline, aby rozpocząć transmisję.
                        @elseif (! $ksefSettings?->is_active)
                            Integracja KSeF nie jest aktywna.
                        @else
                            Transmisja do środowiska {{ $offlineEnvironmentCode }} nie jest obecnie dostępna.
                        @endif
                    </p>
                @elseif ($technicalSourceSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Rejected && $technicalCorrection === null)
                    <p class="invoice-ksef-message invoice-ksef-warning mb-0" data-ksef-technical-correction-blocked>
                        @if ($offlineRow['technical_eligibility'] === \Modules\Ksef\Enums\KsefTechnicalCorrectionEligibility::Ineligible)
                            To odrzucenie nie kwalifikuje się do korekty technicznej KSeF.
                        @else
                            Nie można jednoznacznie potwierdzić, że dokument kwalifikuje się do korekty technicznej KSeF.
                        @endif
                    </p>
                @endif

                @if ($offlineSubmission?->status !== \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted)
                    @if ($offlineRow['delivery_error'])
                        <p class="invoice-ksef-message invoice-ksef-warning mb-0">{{ $offlineRow['delivery_error'] }}</p>
                    @elseif ($offlineRow['delivery_type'] === \Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType::TransactionConfirmation)
                        <a class="btn btn-outline-primary" href="{{ route('invoices.ksef.offline-issuances.transaction-confirmation', ['invoice' => $invoice, 'issuance' => $offlineIssuance]) }}" data-ksef-transaction-confirmation-download>
                            <i class="bi bi-download" aria-hidden="true"></i>
                            POBIERZ POTWIERDZENIE TRANSAKCJI
                        </a>
                    @elseif ($offlineRow['delivery_type'] === \Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType::OfflineInvoice)
                        <a class="btn btn-outline-primary" href="{{ route('invoices.ksef.offline-issuances.invoice-pdf', ['invoice' => $invoice, 'issuance' => $offlineIssuance]) }}" data-ksef-offline-invoice-download>
                            <i class="bi bi-download" aria-hidden="true"></i>
                            POBIERZ FAKTURĘ OFFLINE
                        </a>
                    @endif
                @endif
            </div>

            @if ($offlineSubmission?->status !== \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Accepted)
                @if ($offlineRow['delivery_type'] === \Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType::TransactionConfirmation)
                    <p class="invoice-ksef-message" data-ksef-offline-delivery-note>
                        Faktura zostanie udostępniona nabywcy przez KSeF po jej przesłaniu do systemu.
                    </p>
                @elseif ($offlineRow['delivery_type'] === \Modules\Ksef\Enums\KsefOfflineDeliveryDocumentType::OfflineInvoice)
                    <p class="invoice-ksef-message" data-ksef-offline-delivery-note>
                        Dokument zawiera kody weryfikacyjne KSeF dla Faktury wystawionej {{ $offlineIssuance->procedure->label() }}.
                    </p>
                @endif
            @endif
        </div>
    @endforeach

    @if ($ksefCanIssuePlannedUnavailability && $ksefPlannedUnavailabilityEligibility?->endAt)
        <p class="invoice-ksef-message" data-ksef-offline-unavailability-end>
            Przerwa KSeF do
            <strong>{{ $ksefPlannedUnavailabilityEligibility->endAt->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</strong>.
        </p>
    @endif

    @if ($ksefOfflineCorrectionBlockReason ?? null)
        <p class="invoice-ksef-message" data-ksef-offline-correction-blocked>{{ $ksefOfflineCorrectionBlockReason }}</p>
    @endif
    <div class="invoice-ksef-actions">
        @if ($ksefCanIssueOffline24)
            <form
                method="POST"
                action="{{ route('invoices.ksef.offline24.issue', $invoice) }}"
                data-ksef-offline24-form
                onsubmit="return window.confirm('{{ $offline24Confirmation }} Treść FA(3), data wystawienia oraz dane kodów weryfikacyjnych zostaną trwale zamrożone. Dokument będzie wymagał późniejszego przekazania do KSeF zgodnie z obowiązującym terminem. Kontynuować?')"
            >
                @csrf
                <button class="btn btn-outline-warning" type="submit">WYSTAW OFFLINE24</button>
            </form>
        @endif

        @if ($ksefOfflineProcedureActionsVisible)
            @if ($ksefCanIssuePlannedUnavailability)
                <form
                    method="POST"
                    action="{{ route('invoices.ksef.offline-unavailability.issue', $invoice) }}"
                    data-ksef-offline-unavailability-form
                    onsubmit="return window.confirm('{{ $offlineSubject }} w trybie Offline – niedostępność KSeF. Treść FA(3), data wystawienia i dowód z lokalnych danych Latarni zostaną trwale zamrożone. Kontynuować?')"
                >
                    @csrf
                    <button class="btn btn-outline-warning" type="submit">WYSTAW OFFLINE – NIEDOSTĘPNOŚĆ</button>
                </form>
            @else
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    disabled
                    title="{{ $ksefPlannedUnavailabilityEligibility?->message }}"
                    data-ksef-offline-unavailability-disabled
                >
                    WYSTAW OFFLINE – NIEDOSTĘPNOŚĆ
                </button>
            @endif

            @if ($ksefCanIssueFailure)
                <form
                    method="POST"
                    action="{{ route('invoices.ksef.emergency.issue', $invoice) }}"
                    data-ksef-emergency-form
                    onsubmit="return window.confirm('{{ $offlineSubject }} w trybie awaryjnym. Treść FA(3), data wystawienia i dowód z lokalnych danych Latarni zostaną trwale zamrożone. Kontynuować?')"
                >
                    @csrf
                    <button class="btn btn-outline-danger" type="submit">WYSTAW W TRYBIE AWARYJNYM</button>
                </form>
            @else
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    disabled
                    title="{{ $ksefFailureEligibility?->message }}"
                    data-ksef-emergency-disabled
                >
                    WYSTAW W TRYBIE AWARYJNYM
                </button>
            @endif
        @endif

        @if ($canSend)
            @php
                $sendConfirmation = $isRetry
                    ? "Utworzyć nową próbę KSeF {$environmentCode}? Poprzednia próba pozostanie w historii."
                    : "Wysłać tę Fakturę do KSeF {$environmentCode}?";
                if ($isDemoEnvironment) {
                    $sendConfirmation .= ' Upewnij się, że dokument zawiera wyłącznie dane testowe lub fikcyjne.';
                }
            @endphp
            <form method="POST" action="{{ route('invoices.ksef.submissions.store', $invoice) }}" data-ksef-send-form onsubmit="return window.confirm('{{ $sendConfirmation }}')">
                @csrf
                <button class="btn btn-primary" type="submit">{{ $isRetry ? "Utwórz nową próbę KSeF {$environmentCode}" : "Wyślij do KSeF {$environmentCode}" }}</button>
            </form>
        @elseif ($canRefresh)
            <form method="POST" action="{{ route('invoices.ksef.submissions.refresh', ['invoice' => $invoice, 'submission' => $currentKsefSubmission]) }}" data-ksef-refresh-form>
                @csrf
                <button class="btn btn-outline-primary" type="submit" title="Status jest sprawdzany automatycznie. Kliknij, aby sprawdzić teraz.">Sprawdź status</button>
            </form>
        @elseif ($canReconcile)
            <form method="POST" action="{{ route('invoices.ksef.submissions.reconcile', ['invoice' => $invoice, 'submission' => $currentKsefSubmission]) }}" data-ksef-reconcile-form>
                @csrf
                <button class="btn btn-outline-warning" type="submit">Sprawdź wynik transmisji</button>
            </form>
        @endif


        @if ($currentKsefUpo)
            <a class="btn btn-outline-secondary" href="{{ route('invoices.ksef.submissions.upo.download', ['invoice' => $invoice, 'submission' => $currentKsefSubmission]) }}" data-ksef-upo-download>
                <i class="bi bi-download" aria-hidden="true"></i>
                Pobierz UPO
            </a>
        @elseif ($canFetchUpo)
            <form method="POST" action="{{ route('invoices.ksef.submissions.upo.fetch', ['invoice' => $invoice, 'submission' => $currentKsefSubmission]) }}" data-ksef-upo-fetch-form>
                @csrf
                <button class="btn btn-outline-primary" type="submit" title="Faktura została przyjęta. NEX automatycznie oczekuje na UPO. Kliknij, aby spróbować pobrać teraz.">Pobierz UPO z KSeF</button>
            </form>
        @endif
    </div>

    @if (! $ksefSubmissionGateEnabled)
        <p class="invoice-ksef-message">Wysyłka KSeF jest wyłączona na poziomie wdrożenia.</p>
    @elseif (! $ksefSettings?->is_active)
        <p class="invoice-ksef-message">Integracja KSeF nie jest aktywna.</p>
    @elseif (! $ksefOperationalEnvironmentAllowed)
        <p class="invoice-ksef-message">Operacyjny transport Faktur do KSeF {{ $environmentCode }} nie został jeszcze odblokowany.</p>
    @elseif (! $ksefSeriesEnabled)
        <p class="invoice-ksef-message">Seria numeracji Faktury nie jest włączona do KSeF.</p>
    @elseif ($currentKsefSubmission?->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Uncertain && ! $canReconcile)
        <p class="invoice-ksef-message">Brak referencji sesji potrzebnej do bezpiecznego sprawdzenia wyniku. Kolejna wysyłka pozostaje zablokowana.</p>
    @elseif ($currentKsefSubmission && ! $canSend && $currentKsefSubmission->status->allowsNewAttempt())
        <p class="invoice-ksef-message">Historia KSeF tej Faktury nie pozwala utworzyć kolejnej próby.</p>
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
                            <th>UPO</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ksefSubmissions as $submission)
                            <tr data-ksef-submission-id="{{ $submission->getKey() }}">
                                <td>
                                    {{ $submission->attempt_number }}
                                    @if ($submission->offline_technical_correction_id !== null)
                                        <br><span class="text-muted" data-ksef-technical-correction-history>Korekta techniczna</span>
                                    @endif
                                </td>
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
                                    @elseif ($submission->status === \Modules\Ksef\Enums\KsefInvoiceSubmissionStatus::Rejected && $submission->ksef_status_code !== null)
                                        <span data-ksef-history-status-code>Kod KSeF: {{ $submission->ksef_status_code }}</span>
                                        @if ($submission->safe_error_message)
                                            <br>{{ $submission->safe_error_message }}
                                        @endif
                                    @elseif ($submission->safe_error_message)
                                        {{ $submission->safe_error_message }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if ($submission->upo)
                                        <a href="{{ route('invoices.ksef.submissions.upo.download', ['invoice' => $invoice, 'submission' => $submission]) }}">
                                            Pobierz
                                        </a>
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
