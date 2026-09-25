<fieldset id="sr-jpk" @if ($values['format'] !== 'jpk_v7m3') hidden disabled @endif>
    <p class="alert alert-info">Eksport części sprzedażowej do programu księgowego. Nie zawiera deklaracji VAT ani ewidencji zakupów.</p>
    @foreach (['jpk_purpose' => ['Cel pliku', ['1' => '1 – złożenie', '2' => '2 – korekta']]] as $field => [$label, $choices])
        <div class="sr-row"><label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label><select class="form-select" id="sr-{{ $field }}" name="{{ $field }}">@foreach ($choices as $value => $text)<option value="{{ $value }}" @selected($values[$field] === (string) $value)>{{ $text }}</option>@endforeach</select></div>
    @endforeach
    @include('invoices.jpk-profile._fields')
    <div class="sr-row">
        <label class="sr-label" for="sr-jpk-bfk-outside-ksef">Oznacz faktury wystawione poza KSeF jako BFK</label>
        <div class="pt-2">
            <input type="hidden" name="jpk_bfk_outside_ksef" value="0">
            <input class="form-check-input" type="checkbox" id="sr-jpk-bfk-outside-ksef" name="jpk_bfk_outside_ksef" value="1" @checked($values['jpk_bfk_outside_ksef'] === '1')>
        </div>
    </div>
    <p class="text-muted">Cel pliku nie wynika z obecności Korekt. Ten eksport nie jest kompletnym rozliczeniem ani plikiem gotowym do złożenia w MF. Zerowy ZakupCtrl dotyczy wyłącznie tego eksportu.</p>
    @isset($jpkReview)
        <section class="sr-options" aria-labelledby="sr-jpk-review-title">
            <h2 class="h5" id="sr-jpk-review-title">Kontrola eksportu. Liczba dokumentów: {{ count($jpkReview['documents']) }}</h2>
            <p>Okres: {{ $values['year'] }}-{{ str_pad($values['month'], 2, '0', STR_PAD_LEFT) }}. Zakres obejmuje wyłącznie poniższą listę, nie całą ewidencję podatnika.</p>
            @foreach ($jpkReview['selection_warnings'] as $warning)<p class="text-warning">ID {{ $warning['document_id'] }}: {{ app(\Modules\Invoices\Services\SalesRegisterHtmlPresenter::class)->warning($warning['code']) }}</p>@endforeach
            @if ($jpkReview['errors'])<div class="alert alert-warning" role="alert"><ul class="mb-0">@foreach ($jpkReview['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
            @foreach ($jpkReview['diagnostics'] as $issue)<p class="text-danger">{{ $issue['field'] }}: {{ $issue['hint'] }}</p>@endforeach
            <div class="table-responsive"><table class="table table-sm align-middle sr-jpk-table"><thead><tr><th>ID</th><th>Dokument / status</th><th>Data wystawienia</th><th>Wynikowe GTU</th><th>Oznaczenia / procedury</th><th>Oznaczenie KSeF</th><th>Kontrola danych</th></tr></thead><tbody>
            @forelse ($jpkReview['documents'] as $document)
                <tr>
                    <td data-label="ID">{{ $document['id'] }}</td>
                    <td data-label="Dokument"><a href="{{ $document['url'] }}" target="_blank" rel="noopener">{{ $document['number'] }}</a><div class="small text-muted">{{ $document['status'] }}{{ $document['finalized'] ? ' / zamknięty' : '' }}</div></td>
                    <td data-label="Data wystawienia">{{ $document['issue_date'] }}</td>
                    <td data-label="GTU">{{ $document['gtu'] === null ? 'Nie ustalono — błąd danych' : (implode(', ', $document['gtu']) ?: 'Brak zapisanych GTU') }}</td>
                    <td data-label="Oznaczenia / procedury">{{ $document['markers'] === null ? 'Nie ustalono — błąd danych' : (implode(', ', $document['markers']) ?: 'Brak oznaczeń w eksporcie') }}
                        @foreach ($document['diagnostics'] as $issue)
                            @if ($issue['detected_codes'])<div class="text-danger">Wykryte, nieeksportowane: {{ implode(', ', $issue['detected_codes']) }}</div>@endif
                        @endforeach
                    </td>
                    <td data-label="KSeF">
                    @if ($document['manual'])
                        <select class="form-select" name="jpk_markers[{{ $document['id'] }}]" aria-label="Sposób wystawienia dokumentu ID {{ $document['id'] }}">
                            <option value="">{{ $document['choice'] !== [] && $document['confirmation'] === '' ? 'Automatycznie: '.implode(', ', array_keys($document['choice'])) : 'Potwierdź sposób wystawienia' }}</option>
                            @foreach (['BFK' => 'BFK – poza KSeF', 'OFF' => 'OFF – awaria (art. 106nf)', 'DI' => 'DI – offline24 / niedostępność'] as $code => $label)<option value="{{ $code }}" @selected($document['confirmation'] === $code)>{{ $label }}</option>@endforeach
                        </select>
                    @else
                        {{ $document['choice']['NrKSeF'] ?? implode(', ', array_keys($document['choice'])) ?: 'Wymaga wyjaśnienia' }}
                    @endif
                    </td>
                    <td data-label="Kontrola danych">
                        @if (! $document['errors'])<span class="text-success">Gotowy do eksportu</span>@endif
                        @foreach ($document['diagnostics'] as $issue)
                            <div class="text-danger" data-jpk-problem="{{ $issue['code'] }}">{{ $issue['message'] }}</div><div class="small">{{ $issue['hint'] }}</div>
                        @endforeach
                    </td>
                </tr>
            @empty<tr><td colspan="7">Brak dokumentów w wybranym zakresie.</td></tr>@endforelse
            </tbody></table></div>
            <p class="small text-muted">GTU można zmienić na pozycji edytowalnej Faktury. Zamknięcie, KSeF lub istniejąca Korekta mogą blokować edycję. Podgląd nie zmienia dokumentów.</p>
        </section>
    @endisset
</fieldset>
