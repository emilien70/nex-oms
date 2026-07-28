<div data-next-number-form-fragment data-preview-url="{{ route('invoices.series.next-number.preview', $series) }}">
    <input type="hidden" name="next_number_series_id" value="{{ $series->id }}">

    <div class="invoice-numbering-series-summary mb-3">
        <div><span>Nazwa serii</span><strong>{{ $series->name }}</strong></div>
        <div><span>Typ dokumentu</span><strong>{{ $series->document_type->label() }}</strong></div>
        <div><span>Status</span><strong>{{ $series->is_active ? 'Aktywna' : 'Ukryta' }}</strong></div>
        <div><span>Format</span><strong>{{ $series->number_format }}</strong></div>
        <div><span>Resetowanie</span><strong>{{ $series->reset_period->label() }}</strong></div>
        @if ($series->reset_period === \Modules\Invoices\Enums\InvoiceSeriesResetPeriod::Yearly)
            <div><span>Początek roku fiskalnego</span><strong>{{ $series->fiscal_year_start_month }}</strong></div>
        @endif
    </div>

    @if ($series->reset_period === \Modules\Invoices\Enums\InvoiceSeriesResetPeriod::Monthly)
        <div class="mb-3">
            <label class="form-label" for="invoice-next-period-month">Miesiąc okresu numeracji</label>
            <input
                class="form-control form-control-sm {{ $errors->has('period_month') ? 'is-invalid' : '' }}"
                id="invoice-next-period-month"
                name="period_month"
                type="month"
                value="{{ old('period_month', $numberingDate->format('Y-m')) }}"
                data-next-number-period
                required
            >
            @error('period_month')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @elseif ($series->reset_period === \Modules\Invoices\Enums\InvoiceSeriesResetPeriod::Yearly)
        <div class="mb-3">
            <label class="form-label" for="invoice-next-period-year">Rok rozpoczęcia okresu numeracji</label>
            <input
                class="form-control form-control-sm {{ $errors->has('period_year') ? 'is-invalid' : '' }}"
                id="invoice-next-period-year"
                name="period_year"
                type="number"
                min="1900"
                max="9999"
                step="1"
                value="{{ old('period_year', $numberingDate->year) }}"
                data-next-number-period
                required
            >
            @error('period_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endif

    <div class="small text-muted mb-3" data-next-number-period-description>{{ $periodDescription }}</div>

    <div class="invoice-numbering-preview-grid mb-3" aria-live="polite">
        <div><span>Aktualny ostatni numer kolejny</span><strong data-preview-last>{{ $preview->currentLastSequenceNumber }}</strong></div>
        <div><span>Aktualny chroniony próg</span><strong data-preview-floor>{{ $preview->protectedFloorSequenceNumber }}</strong></div>
        <div><span>Aktualnie przewidywany następny numer</span><strong data-preview-next>{{ $preview->currentNextSequenceNumber }}</strong></div>
        <div><span>Pełny podgląd numeru</span><strong data-preview-formatted>{{ $preview->formattedNumber }}</strong></div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="invoice-next-sequence-number">Nowy następny numer</label>
        <input
            class="form-control form-control-sm {{ $errors->has('next_sequence_number') ? 'is-invalid' : '' }}"
            id="invoice-next-sequence-number"
            name="next_sequence_number"
            type="number"
            min="1"
            step="1"
            value="{{ old('next_sequence_number', $preview->currentNextSequenceNumber) }}"
            data-next-sequence-number
            required
        >
        @error('next_sequence_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="mb-3">
        <label class="form-label" for="invoice-next-number-reason">Powód zmiany</label>
        <textarea
            class="form-control form-control-sm {{ $errors->has('reason') ? 'is-invalid' : '' }}"
            id="invoice-next-number-reason"
            name="reason"
            rows="3"
            minlength="3"
            maxlength="1000"
            required
        >{{ old('reason') }}</textarea>
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="alert alert-light border small mb-0" role="note">
        Podgląd nie rezerwuje numeru. Numer zostanie użyty dopiero podczas nadawania numeru dokumentowi.
    </div>

    <div class="alert alert-danger mt-3 mb-0 d-none" role="alert" data-next-number-preview-error></div>
</div>
