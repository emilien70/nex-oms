@php
    $correctionSubmission = $correctionKsefState['submission'];
    $correctionCardUrl = route('invoices.corrections.edit', ['correction' => $ksefCorrection, 'return_to' => 'order']);
@endphp

@if ($correctionKsefState['pdfDownloadAvailable'])
    <a
        class="management-issued-invoice-ksef management-issued-invoice-ksef-reference management-issued-invoice-ksef-download"
        href="{{ route('invoices.ksef.submissions.invoice.download', ['invoice' => $ksefCorrection, 'submission' => $correctionSubmission]) }}"
        data-order-ksef-reference
        data-order-ksef-invoice-pdf
        data-ksef-pdf-open="1"
        data-ksef-invoice-source-url="{{ route('invoices.ksef.submissions.invoice.download', ['invoice' => $ksefCorrection, 'submission' => $correctionSubmission]) }}"
        data-ksef-pdf-generator-src="{{ asset('vendor/ksef-pdf-generator/1.1.31/ksef-fe-invoice-converter.umd.js') }}"
        data-ksef-number="{{ $correctionSubmission->ksef_number }}"
        data-ksef-acquisition-date="{{ $correctionSubmission->acquisition_date?->format('d.m.Y H:i:s') }}"
        data-ksef-verification-url="{{ $correctionKsefState['verificationUrl'] }}"
        data-ksef-pdf-filename="{{ $correctionKsefState['pdfFilename'] }}"
        title="Otwórz PDF Korekty z KSeF"
        aria-label="Otwórz PDF Korekty {{ $ksefCorrection->number }} z KSeF"
    >KSeF: {{ $ksefCorrection->number }}</a>
@elseif ($correctionSubmission)
    @if ($correctionSubmission->status->isTerminal() || $correctionSubmission->status->requiresReconciliation())
        <a class="btn btn-sm management-issued-invoice-ksef" href="{{ $correctionCardUrl }}#correction-ksef"
            data-order-ksef-tooltip title="KSeF: {{ $correctionSubmission->status->label() }}. Otwórz kartę Korekty, aby sprawdzić szczegóły."
            data-bs-title="KSeF: {{ $correctionSubmission->status->label() }}. Otwórz kartę Korekty, aby sprawdzić szczegóły."
        >KSeF</a>
    @else
        <span class="btn btn-sm management-issued-invoice-ksef" role="button" aria-disabled="true" tabindex="0"
            data-order-ksef-pending data-order-ksef-tooltip
            title="Korekta jest przekazywana do KSeF: {{ $correctionSubmission->status->label() }}"
            data-bs-title="Korekta jest przekazywana do KSeF: {{ $correctionSubmission->status->label() }}"
        >KSeF</span>
    @endif
@elseif ($correctionKsefState['canSend'])
    @php
        $correctionSendQuestion = 'Czy przekazać Korektę '.$ksefCorrection->number.' do KSeF '.strtoupper($correctionKsefState['environment']->value).'?';
        if (! $ksefCorrection->isFinalized()) {
            $correctionSendQuestion .= ' Korekta zostanie zamknięta i nie będzie można jej edytować ani usunąć.';
        }
        if ($correctionKsefState['environment'] === \Modules\Ksef\Enums\KsefEnvironment::Demo) {
            $correctionSendQuestion .= ' Upewnij się, że dokument zawiera wyłącznie dane testowe lub fikcyjne.';
        }
    @endphp
    <form method="POST" action="{{ route('invoices.ksef.submissions.first-attempt', $ksefCorrection) }}"
        class="management-issued-invoice-ksef-form" data-order-ksef-send-form
        data-ksef-confirm-question="{{ $correctionSendQuestion }}"
    >
        @csrf
        <input type="hidden" name="return_to" value="order">
        <button class="btn btn-sm management-issued-invoice-ksef" type="submit"
            data-order-ksef-send-trigger data-order-ksef-tooltip data-bs-toggle="modal" data-bs-target="#orderKsefSendConfirmationModal"
            title="Przekaż Korektę do KSeF" data-bs-title="Przekaż Korektę do KSeF"
            aria-label="Przekaż Korektę {{ $ksefCorrection->number }} do KSeF"
        >KSeF</button>
    </form>
@elseif ($correctionKsefState['seriesEnabled'] && ! $correctionKsefState['outsideKsef'])
    <button class="btn btn-sm management-issued-invoice-ksef" type="button" disabled
        title="{{ $correctionKsefState['hasOfflineIssuance'] ? 'Obsługa KSeF dokumentu Offline jest dostępna na karcie Korekty.' : 'Wysyłka Korekty jest niedostępna przy bieżącej konfiguracji KSeF.' }}"
    >KSeF</button>
@endif
