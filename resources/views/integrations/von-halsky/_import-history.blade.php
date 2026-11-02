@php
    $importLabels = ['pending' => 'Oczekuje na sprawdzenie', 'processing' => 'Przetwarzanie', 'imported' => 'Zaimportowano',
        'needs_quantity' => 'Wymaga potwierdzenia ilości', 'waiting' => 'Oczekuje na potwierdzenie lub płatność',
        'blocked' => 'Wymaga weryfikacji', 'failed' => 'Błąd pobierania', 'ignored' => 'Anulowane lub odrzucone',
        'deleted' => 'Usunięte lub nieprawidłowe powiązanie'];
    $importGroups = [];
    if ($attentionImports && $attentionImports->total()) $importGroups['Wymagają uwagi ('.$attentionImports->total().')'] = $attentionImports;
    $importGroups['Ostatnie 10 zamówień'] = $recentImports;
@endphp
@foreach ($importGroups as $heading => $attempts)
    <section class="halsky-section" data-halsky-import-history>
        <h2>{{ $heading }}</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Zamówienie Von Halsky / OMS</th><th>Status Von Halsky</th><th>Wynik pobierania</th><th>Ostatnie sprawdzenie</th><th class="text-end">Akcja</th></tr></thead>
                <tbody>
                    @forelse ($attempts as $attempt)
                        <tr data-halsky-attempt="{{ $attempt->id }}">
                            <td>{{ $attempt->external_order_id }} @if ($attempt->order_id)<div class="text-muted small">OMS #{{ $attempt->order_id }}</div>@endif</td>
                            <td>{{ $attempt->external_status ?? '—' }}</td>
                            <td>{{ $importLabels[$attempt->status] ?? 'Nieznany wynik' }}
                                @if ($attempt->last_message)<div class="small text-muted" style="max-width:360px;overflow-wrap:anywhere">{{ $attempt->last_message }}</div>@endif
                            </td>
                            <td class="text-nowrap">{{ ($attempt->last_attempt_at ?? $attempt->last_seen_at)?->format('d.m.Y H:i:s') }}</td>
                            <td class="text-end">
                                @if ($attempt->status === 'imported' && $attempt->order && ! $attempt->order->trashed())
                                    <a class="btn btn-outline-secondary btn-sm text-nowrap" href="{{ route('orders.show', $attempt->order_id) }}"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz zamówienie</a>
                                @elseif (! in_array($attempt->status, ['deleted', 'ignored'], true))
                                    <form method="POST" action="{{ route('integrations.von-halsky.preview') }}" data-halsky-submit>
                                        @csrf
                                        <input type="hidden" name="expected_lock_version" value="{{ $account->lock_version }}">
                                        <input type="hidden" name="order_identifier" value="{{ $attempt->external_order_id }}">
                                        <button class="btn btn-outline-primary btn-sm text-nowrap" type="submit" @disabled(! $account->is_active)><i class="bi bi-search me-1" aria-hidden="true"></i>{{ $attempt->status === 'needs_quantity' ? 'Potwierdź ilości' : 'Otwórz podgląd' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted py-3">Brak historii pobierania.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($attempts === $attentionImports)<div class="mt-3">{{ $attentionImports->links() }}</div>@endif
    </section>
@endforeach
