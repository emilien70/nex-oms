<fieldset id="sr-jpk" @if ($values['format'] !== 'jpk_v7m3') hidden disabled @endif>
    <p class="alert alert-info">Eksport części sprzedażowej do programu księgowego. Nie zawiera deklaracji VAT ani ewidencji zakupów.</p>
    <p>Oznaczenie KSeF jest obowiązkowe. Dane odczytywane są lokalnie dla środowiska PROD w chwili eksportu.</p>
    <div class="sr-row">
        <label class="sr-label" for="sr-jpk-month">Okres JPK</label>
        <div class="sr-range">
            <select class="form-select" name="jpk_month" id="sr-jpk-month"><option value="">Wybierz miesiąc</option>@foreach ($months as $number => $name)<option value="{{ $number }}" @selected((int) $values['jpk_month'] === $number)>{{ $name }}</option>@endforeach</select>
            <span></span>
            <input class="form-control" type="number" min="2026" max="2090" name="jpk_year" aria-label="Rok JPK" placeholder="Rok JPK" value="{{ $values['jpk_year'] }}">
        </div>
    </div>
    <p class="text-muted">Daty i filtry powyżej wybierają dokumenty. Okres JPK wymaga odrębnego potwierdzenia i nie wyznacza automatycznie obowiązku podatkowego.</p>
    @foreach (['jpk_type' => ['Typ podatnika', ['' => 'Wybierz', 'person' => 'Osoba fizyczna', 'organization' => 'Osoba niefizyczna']], 'jpk_purpose' => ['Cel pliku', ['1' => '1 – złożenie', '2' => '2 – korekta']]] as $field => [$label, $choices])
        <div class="sr-row"><label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label><select class="form-select" id="sr-{{ $field }}" name="{{ $field }}">@foreach ($choices as $value => $text)<option value="{{ $value }}" @selected($values[$field] === (string) $value)>{{ $text }}</option>@endforeach</select></div>
    @endforeach
    @foreach (['jpk_nip' => ['NIP podatnika', 'text', null], 'jpk_name' => ['Pełna nazwa podatnika', 'text', 'organization'], 'jpk_first_name' => ['Pierwsze imię', 'text', 'person'], 'jpk_last_name' => ['Nazwisko', 'text', 'person'], 'jpk_birth_date' => ['Data urodzenia', 'date', 'person'], 'jpk_email' => ['E-mail', 'email', null], 'jpk_phone' => ['Telefon (opcjonalnie)', 'text', null], 'jpk_office' => ['Kod urzędu skarbowego', 'text', null]] as $field => [$label, $type, $taxpayer])
        <div class="sr-row" @if ($taxpayer) data-taxpayer="{{ $taxpayer }}" @if ($values['jpk_type'] !== $taxpayer) hidden @endif @endif><label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label><input class="form-control" id="sr-{{ $field }}" type="{{ $type }}" name="{{ $field }}" value="{{ $values[$field] }}" @disabled($taxpayer && $values['jpk_type'] !== $taxpayer)></div>
    @endforeach
    <p class="text-muted">Cel pliku nie wynika z obecności Korekt. Ten eksport nie jest kompletnym rozliczeniem ani plikiem gotowym do złożenia w MF. Zerowy ZakupCtrl dotyczy wyłącznie tego eksportu.</p>
    @isset($jpkReview)
        <section class="sr-options" aria-labelledby="sr-jpk-review-title">
            <h2 class="h5" id="sr-jpk-review-title">Kontrola eksportu. Liczba dokumentów: {{ count($jpkReview['documents']) }}</h2>
            <p>Okres JPK: {{ $values['jpk_year'] }}-{{ str_pad($values['jpk_month'], 2, '0', STR_PAD_LEFT) }}. Zakres obejmuje wyłącznie poniższą listę, nie całą ewidencję podatnika.</p>
            @foreach ($jpkReview['selection_warnings'] as $warning)<p class="text-warning">ID {{ $warning['document_id'] }}: {{ app(\Modules\Invoices\Services\SalesRegisterHtmlPresenter::class)->warning($warning['code']) }}</p>@endforeach
            @if ($jpkReview['errors'])<div class="alert alert-warning" role="alert"><ul class="mb-0">@foreach ($jpkReview['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
            <div class="table-responsive"><table class="table table-sm align-middle sr-jpk-table"><thead><tr><th>ID</th><th>Dokument</th><th>Data wystawienia</th><th>Oznaczenie KSeF</th></tr></thead><tbody>
            @forelse ($jpkReview['documents'] as $document)
                <tr><td>{{ $document['id'] }}</td><td>{{ $document['number'] }}</td><td>{{ $document['issue_date'] }}</td><td>
                    @if ($document['manual'])
                        <select class="form-select" name="jpk_markers[{{ $document['id'] }}]" aria-label="Sposób wystawienia dokumentu ID {{ $document['id'] }}">
                            <option value="">Potwierdź sposób wystawienia</option>
                            @foreach (['BFK' => 'BFK – poza KSeF', 'OFF' => 'OFF – awaria (art. 106nf)', 'DI' => 'DI – offline24 / niedostępność'] as $code => $label)<option value="{{ $code }}" @selected($document['confirmation'] === $code)>{{ $label }}</option>@endforeach
                        </select>
                    @else
                        {{ $document['choice']['NrKSeF'] ?? implode(', ', array_keys($document['choice'])) ?: 'Wymaga wyjaśnienia' }}
                    @endif
                </td></tr>
            @empty<tr><td colspan="4">Brak dokumentów w wybranym zakresie.</td></tr>@endforelse
            </tbody></table></div>
            <input type="hidden" name="jpk_fingerprint" value="{{ $jpkReview['fingerprint'] }}">
            <label class="form-check my-3"><input class="form-check-input" type="checkbox" name="jpk_confirm" value="1"><span class="form-check-label">Potwierdzam wskazany zestaw sprzedaży i okres JPK do dalszej weryfikacji oraz importu księgowego. Nie potwierdzam kompletności rozliczenia VAT.</span></label>
            <button class="btn btn-primary mb-3" type="submit" name="jpk_action" value="download">Pobierz JPK</button>
        </section>
    @endisset
</fieldset>
