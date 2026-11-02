<section class="halsky-section" data-halsky-preview>
    <h2>Zamówienie {{ $preview['id'] }}</h2>
    <dl class="halsky-details">
        <dt>Status Von Halsky</dt><dd>{{ $preview['status_label'] }} <span class="text-muted">({{ $preview['status'] }})</span></dd>
        <dt>Data złożenia</dt><dd>{{ $preview['created_at'] }}</dd>
        <dt>Ostatnia zmiana</dt><dd>{{ $preview['updated_at'] ?? '—' }}</dd>
        <dt>Metoda płatności</dt><dd>{{ $preview['payment_type'] ?? '—' }}</dd>
        <dt>Płatność w API</dt><dd>{{ $preview['payment_recorded'] ? 'Zarejestrowana' : 'Brak zarejestrowanej płatności' }}</dd>
        <dt>Wartość bazowa</dt><dd>{{ $preview['base_price'] ?? '—' }}</dd>
        <dt>Wartość łączna</dt><dd class="fw-semibold">{{ $preview['total'] ?? '—' }}</dd>
    </dl>
</section>
<section class="halsky-section">
    <h2>Przedmioty</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Nazwa produktu</th><th>Powiązanie OMS</th><th>VAT</th><th class="text-end">Ilość</th><th class="text-end">Cena</th></tr></thead>
            <tbody>
                @foreach ($preview['lines'] as $index => $line)
                    @php($catalogItem = $preview['import']['items'][$index] ?? null)
                    <tr><td>{{ $catalogItem['product_name'] ?? $line['name'] }}
                        @if ($catalogItem && $catalogItem['product_name'] !== $line['name'])<div class="text-muted small">Von Halsky: {{ $line['name'] }}</div>@endif
                    </td><td>
                        @if ($catalogItem && $catalogItem['product_id'])
                            #{{ $catalogItem['product_id'] }} · {{ $catalogItem['catalog']['name'] }}
                        @else — @endif
                    </td><td class="text-nowrap">
                        {{ ($catalogItem['vat_rate'] ?? null) !== null ? $catalogItem['vat_rate'].'%' : '—' }}
                        @if ($catalogItem && $catalogItem['vat_source'])
                            <div class="text-muted small">{{ ['catalog' => 'Produkt OMS', 'channel' => 'Von Halsky', 'default' => 'Domyślny VAT'][$catalogItem['vat_source']] ?? '' }}</div>
                        @endif
                    </td><td class="text-end">
                        @if (! $existingLink && $preview['import']['blockers'] === [] && isset($preview['import']['items'][$index]))
                            @php($importItem = $preview['import']['items'][$index])
                            @if ($importItem['quantity_source'] === 'manual')
                                <input type="number" form="halskyImportForm" name="quantities[{{ $index }}]" min="1" max="999999" step="1" required value="{{ old('quantities.'.$index) }}" class="form-control form-control-sm ms-auto" style="width: 90px" aria-label="Potwierdzona ilość: {{ $line['name'] }}">
                            @else
                                {{ $importItem['quantity'] }}<input type="hidden" form="halskyImportForm" name="quantities[{{ $index }}]" value="{{ $importItem['quantity'] }}">
                            @endif
                        @else
                            <span title="Ilość niepotwierdzona w podglądzie">—</span>
                        @endif
                    </td><td class="text-end text-nowrap">{{ $line['price'] ?? '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
<section class="halsky-section halsky-preview-grid">
    @foreach (['customer' => 'Kupujący', 'delivery' => 'Dostawa', 'invoice' => 'Dane do faktury'] as $key => $heading)
        <div class="halsky-contact">
            <h2>{{ $heading }}</h2>
            @php($contact = $preview[$key])
            @if ($key === 'customer' && ! $preview['customer_available'])
                <p class="text-muted mb-0">Dane kupującego nie są jeszcze dostępne w API.</p>
            @elseif ($contact === null)
                <p class="text-muted mb-0">Nie przekazano danych do faktury.</p>
            @else
                @foreach (['name', 'company'] as $field)
                    @if (filled($contact[$field])) <div>{{ $contact[$field] }}</div> @endif
                @endforeach
                @foreach ($contact['address'] as $line) <div>{{ $line }}</div> @endforeach
                @if (filled($contact['email'])) <div class="mt-1">{{ $contact['email'] }}</div> @endif
                @if (filled($contact['phone'])) <div>{{ $contact['phone'] }}</div> @endif
                @if ($key === 'invoice' && filled($contact['tax_id'])) <div class="mt-2">NIP: {{ $contact['tax_id'] }}</div> @endif
            @endif
            @if ($key === 'delivery')
                <div class="mt-2">{{ $contact['type'] }} @if ($contact['point']) · {{ $contact['point'] }} @endif</div>
                <div>Koszt: {{ $contact['price'] ?? '—' }}</div>
                @if ($contact['expected_at']) <div>Termin: {{ $contact['expected_at'] }}</div> @endif
                @if ($contact['note']) <div class="halsky-comment mt-2">{{ $contact['note'] }}</div> @endif
            @endif
        </div>
    @endforeach
</section>
@if ($preview['payments'])
    <section class="halsky-section">
        <h2>Płatności w Von Halsky</h2>
        <div class="table-responsive">
            <table class="table table-sm mb-0"><thead><tr><th>ID płatności</th><th>Typ</th><th>Data</th></tr></thead><tbody>
                @foreach ($preview['payments'] as $payment)
                    <tr><td>{{ $payment['id'] ?? '—' }}</td><td>{{ $payment['type'] ?? '—' }}</td><td>{{ $payment['date'] ?? '—' }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    </section>
@endif
@if (filled($preview['comment']))
    <section class="halsky-section"><h2>Komentarz kupującego</h2><div class="halsky-comment">{{ $preview['comment'] }}</div></section>
@endif
<section class="halsky-section">
    @foreach ($preview['import']['catalog_warnings'] ?? [] as $warning)
        <div class="alert alert-warning py-2 small" role="status">{{ $warning }}</div>
    @endforeach
    @if ($existingLink)
        @if ($existingLink->order && ! $existingLink->order->trashed())
            <a class="btn btn-outline-primary btn-sm" href="{{ route('orders.show', $existingLink->order_id) }}"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz zamówienie #{{ $existingLink->order_id }}</a>
        @else
            <div class="alert alert-warning mb-0">Zamówienie było już importowane i zostało usunięte lub przeniesione do Kosza. Ponowny import jest zablokowany.</div>
        @endif
    @elseif ($preview['import']['blockers'])
        <div class="alert alert-warning mb-0" role="status">@foreach ($preview['import']['blockers'] as $blocker)<div>{{ $blocker }}</div>@endforeach</div>
    @else
        @if ($preview['import']['requires_quantities'])
            <div class="alert alert-warning py-2 small" role="status">Von Halsky nie przekazuje ilości pozycji. Uzupełnij ją na podstawie zamówienia źródłowego. Suma zostanie sprawdzona przed importem.</div>
        @endif
        <form id="halskyImportForm" method="POST" action="{{ route('integrations.von-halsky.import') }}" data-halsky-submit>
            @csrf
            <input type="hidden" name="order_identifier" value="{{ $preview['id'] }}">
            <input type="hidden" name="preview_hash" value="{{ $preview['import']['source_hash'] }}">
            <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirmed_quantities" value="1" required><span class="form-check-label">Potwierdzam ilości pokazanych pozycji.</span></label>
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-download me-1" aria-hidden="true"></i>Importuj do OMS</button>
        </form>
    @endif
</section>
