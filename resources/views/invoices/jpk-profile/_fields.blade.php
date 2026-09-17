<div class="sr-row"><label class="sr-label" for="sr-jpk_type">Typ podatnika</label><select class="form-select" id="sr-jpk_type" name="jpk_type">
    <option value="">Wybierz</option>
    <option value="person" @selected($values['jpk_type'] === 'person')>Osoba fizyczna / JDG</option>
    <option value="organization" @selected($values['jpk_type'] === 'organization')>Osoba niefizyczna</option>
</select></div>
@foreach (['jpk_nip' => ['NIP podatnika', 'text', null, 10], 'jpk_name' => ['Pełna nazwa podatnika', 'text', 'organization', 240], 'jpk_first_name' => ['Pierwsze imię', 'text', 'person', 30], 'jpk_last_name' => ['Nazwisko', 'text', 'person', 81], 'jpk_birth_date' => ['Data urodzenia', 'date', 'person', 10], 'jpk_email' => ['E-mail', 'email', null, 255], 'jpk_phone' => ['Telefon (opcjonalnie)', 'text', null, 16]] as $field => [$label, $type, $taxpayer, $limit])
    <div class="sr-row" @if ($taxpayer) data-taxpayer="{{ $taxpayer }}" @endif>
        <label class="sr-label" for="sr-{{ $field }}">{{ $label }}</label>
        <input class="form-control" id="sr-{{ $field }}" type="{{ $type }}" name="{{ $field }}" maxlength="{{ $limit }}" value="{{ $values[$field] }}">
    </div>
@endforeach
<div class="sr-row">
    <label class="sr-label" for="sr-jpk_office">Urząd skarbowy</label>
    <div data-tax-office>
        <div data-office-search-wrap hidden><label for="sr-office-search" class="visually-hidden">Szukaj urzędu po nazwie lub kodzie</label><input id="sr-office-search" class="form-control mb-2" type="search" placeholder="Nazwa urzędu lub kod" autocomplete="off" data-office-search></div>
        <select class="form-select" id="sr-jpk_office" name="jpk_office" data-office-select>
            <option value="">Wybierz urząd</option>
            @if ($values['jpk_office'] !== '' && ! array_key_exists($values['jpk_office'], $offices))
                <option value="{{ $values['jpk_office'] }}" selected>{{ $values['jpk_office'] }} — nieznany kod, wybierz urząd ponownie</option>
            @endif
            @foreach ($offices as $code => $name)<option value="{{ $code }}" @selected($values['jpk_office'] === (string) $code)>{{ $name }} — {{ $code }}</option>@endforeach
        </select>
        <span class="small text-muted" data-office-results aria-live="polite"></span>
        <div class="form-text">Słownik przypiętego schematu JPK. Właściwość urzędu wymaga sprawdzenia przez podatnika.</div>
    </div>
</div>
