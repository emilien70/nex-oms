@extends('layouts.app')
@section('title', 'MacroDroid SMS - NEX-OMS')
@section('content')
<style>
    .sms-settings { max-width: 1100px; margin: 0 auto; }
    .sms-settings h1 { font-size: 20px; margin-bottom: 20px; }
    .sms-settings h2 { font-size: 16px; }
    .sms-settings section { border-top: 1px solid #d5dce4; padding: 20px 0; }
    .sms-settings input,.sms-settings code { max-width: 100%; overflow-wrap: anywhere; }
    .sms-settings pre { white-space: pre-wrap; overflow-wrap: anywhere; }
    .sms-history { font-size: 12px; }
    .sms-settings details { margin: 15px 0; }
</style>
<div class="sms-settings">
    <h1><i class="bi bi-phone me-2" aria-hidden="true"></i>MacroDroid - bramka SMS</h1>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if(!$ready)<div class="alert alert-warning">Obsluga SMS wymaga uruchomienia migracji 2026_10_28_010000_create_macrodroid_sms_tables.</div>@endif
    <form method="POST" action="{{ route('integrations.macrodroid.update') }}" id="smsSettingsForm">
        @csrf @method('PUT')
        <input type="hidden" name="expected_lock_version" value="{{ $device?->lock_version ?? 0 }}" data-sms-version>
        <fieldset @disabled(!$ready)>
            <div class="row g-3">
                <div class="col-md-7"><label class="form-label" for="smsDeviceName">Nazwa urzadzenia</label><input class="form-control" id="smsDeviceName" name="name" maxlength="120" required value="{{ old('name', $device?->name ?? 'Telefon SMS') }}"></div>
                <div class="col-md-5"><label class="form-label" for="smsEnabled">Integracja</label><select class="form-select" id="smsEnabled" name="enabled"><option value="0">Wylaczona</option><option value="1" @selected(old('enabled', $device?->enabled))>Wlaczona</option></select></div>
                <div class="col-md-7"><label class="form-label" for="smsWebhook">Adres webhooka MacroDroid</label><input class="form-control" id="smsWebhook" name="webhook_url" type="password" autocomplete="new-password" maxlength="500" placeholder="{{ $device?->webhook_url ? 'Zapisany - pozostaw puste, aby zachowac' : 'https://trigger.macrodroid.com/…/nex-oms-sms' }}"></div>
                <div class="col-md-5"><label class="form-label" for="smsSender">Numer karty SIM (informacyjnie)</label><input class="form-control" id="smsSender" name="sender_phone" type="tel" maxlength="32" value="{{ old('sender_phone', $device?->sender_phone) }}"></div>
                @foreach(['per_minute' => ['SMS / minute', 6, 1, 30], 'per_day' => ['SMS / 24 godziny', 200, 1, 1000], 'lifetime_minutes' => ['Waznosc zlecenia (min)', 60, 5, 1440], 'max_parts' => ['Maks. czesci wiadomosci', 5, 1, 10]] as $field => [$label, $default, $min, $max])
                <div class="col-6 col-md-3"><label class="form-label" for="sms_{{ $field }}">{{ $label }}</label><input class="form-control" type="number" id="sms_{{ $field }}" name="{{ $field }}" min="{{ $min }}" max="{{ $max }}" required value="{{ old($field, $device?->{$field} ?? $default) }}"></div>
                @endforeach
            </div>
            <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check2 me-1"></i>Zapisz</button>
        </fieldset>
    </form>
    <section>
        <h2>Polaczenie z telefonem</h2>
        <dl class="row small"><dt class="col-sm-4">Ostatni kontakt</dt><dd class="col-sm-8">{{ $device?->last_seen_at?->format('d.m.Y H:i:s') ?? 'Brak kontaktu' }}</dd><dt class="col-sm-4">Ostatni test</dt><dd class="col-sm-8">{{ $device?->probe_confirmed_at?->format('d.m.Y H:i:s') ?? ($device?->probe_expires_at?->isFuture() ? 'Oczekiwanie na telefon' : 'Brak potwierdzenia') }}</dd></dl>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-secondary btn-sm" id="smsRotateKey" type="button" @disabled(!$ready)><i class="bi bi-key me-1"></i>{{ $device?->token_hash ? 'Zmien klucz urzadzenia' : 'Wygeneruj klucz urzadzenia' }}</button>
            <form method="POST" action="{{ route('integrations.macrodroid.probe') }}">@csrf<input type="hidden" name="expected_lock_version" value="{{ $device?->lock_version ?? 0 }}" data-sms-version><button class="btn btn-outline-primary btn-sm" @disabled(!$device?->enabled)><i class="bi bi-wifi me-1"></i>Test polaczenia (bez SMS)</button></form>
        </div>
        <div id="smsKeyResult" class="d-none mt-3"><label for="smsOneTimeKey" class="form-label">Klucz widoczny tylko teraz</label><div class="input-group"><input class="form-control font-monospace" id="smsOneTimeKey" readonly autocomplete="off"><button type="button" class="btn btn-outline-secondary" id="smsCopyKey" title="Kopiuj klucz" aria-label="Kopiuj klucz"><i class="bi bi-copy"></i></button></div></div>
        <div class="small text-danger mt-2" id="smsKeyError" role="alert"></div>
    </section>
    <section>
        <h2>SMS testowy</h2>
        <form method="POST" action="{{ route('integrations.macrodroid.test-sms') }}" onsubmit="return confirm('Wyslac rzeczywisty SMS na wskazany numer testowy?')">
            @csrf<input type="hidden" name="idempotency_key" value="{{ Illuminate\Support\Str::uuid() }}">
            <div class="row g-2 align-items-end"><div class="col-sm-6"><label class="form-label" for="smsTestRecipient">Numer testowy</label><input class="form-control" type="tel" id="smsTestRecipient" name="recipient" maxlength="32" required></div><div class="col-sm-6"><button class="btn btn-outline-primary" @disabled(!$device?->enabled)><i class="bi bi-send me-1"></i>Wyslij SMS testowy</button></div></div>
            <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" required><span class="form-check-label small">Potwierdzam rzeczywista wysylke na moj numer testowy.</span></label>
        </form>
    </section>
    <section>
        <h2>Konfiguracja telefonu</h2>
        <p class="small">Android 10 / MacroDroid 6.0.11 Pro / jedna karta SIM. Oba pliki przeszly walidator MacroDroid Macro Builder. Import na telefonie i proba SMS wymagaja jeszcze sprawdzenia.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="{{ route('integrations.macrodroid.configuration', 'sms') }}"><i class="bi bi-download me-1"></i>Pobierz makro SMS</a>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('integrations.macrodroid.configuration', 'probe') }}"><i class="bi bi-download me-1"></i>Pobierz makro testu polaczenia</a>
        </div>
        <details><summary>Instrukcja konfiguracji MacroDroid</summary>
            <ol class="small mt-3">
                <li>Pobierz oba pliki .macro i zaimportuj je w MacroDroid przez <strong>Makra - Import</strong>. Oba makra sa domyslnie wylaczone. Zezwol na wysylanie SMS i prace w tle, wylacz ograniczenie baterii. Nie wlaczaj makr przed uzupelnieniem zmiennych i sprawdzeniem ustawien.</li>
                <li>W makrze <strong>NEX-OMS SMS</strong> sprawdz wyzwalacz <strong>Webhook (URL)</strong> z identyfikatorem <code>nex-oms-sms</code>. Skopiuj jego adres do pola webhooka w OMS. Nie zmieniaj identyfikatorow makr. Webhook jest tylko sygnalem pobudki; nie przekazuje numeru, tresci ani identyfikatora zlecenia i nie wymaga globalnych zmiennych. Makro bramki i makro testu polaczenia sa osobne.</li>
                <li>Wygeneruj klucz urzadzenia w OMS. W <strong>obu makrach</strong> ustaw lokalne zmienne <code>klucz_oms</code> (ten sam klucz) i <code>oms_url</code> (adres panelu HTTPS bez koncowego /). Pliki nie zawieraja klucza; dla lokalnego HTTP maja zastepczy adres <code>https://uzupelnij-adres-oms.invalid</code>. Nie wpisuj domeny portalu klienta. Adres musi byc osiagalny z telefonu; 127.0.0.1 oznacza sam telefon. Wymagany jest wyjatek Basic Auth dla czterech endpointow API, opisany w dokumentacji wdrozenia, nie haslo panelu w makrze.</li>
                <li>Osobne makro testowe ma identyfikator webhooka taki jak bramka z dopiskiem <code>-probe</code> (domyslnie <code>nex-oms-sms-probe</code>). Zawiera tylko POST na <code>{{ route('macrodroid.api.heartbeat') }}</code> z JSON <code>{}</code>, analize odpowiedzi i drugi POST z <code>{"probe_id":"&lt;probe_id z odpowiedzi&gt;"}</code>. Nie zawiera pobierania zlecen ani akcji SMS.</li>
                <li>Makro bramki wykonuje POST na <code>{{ route('macrodroid.api.claim') }}</code> z JSON <code>{}</code>, pobierajac najstarsze dostepne zlecenie. Jezeli HTTP nie jest 200 lub <code>available</code> nie jest dokladnie true, zakoncz bez SMS. Odpowiedz HTTP zapisz w lokalnym tekscie, akcja <strong>Analiza JSON</strong> parsuje go do lokalnego slownika. Korzystaj z pol <code>phone</code>, <code>text</code>, <code>claim_token</code> i <code>job_id</code>. Przed kazdym HTTP ustaw lokalny kod odpowiedzi na 0 i zaznacz blokowanie nastepnych akcji do zakonczenia zadania. Nie sledz przekierowan i nie wylaczaj kontroli certyfikatu.</li>
                <li>Przed akcja SMS wykonaj POST na <code>{{ route('macrodroid.api.begin') }}</code> z JSON <code>{"job_id":"&lt;job_id&gt;","claim_token":"&lt;claim_token&gt;"}</code>. Wysylaj <strong>tylko po HTTP 200 i may_send=true</strong>, dokladnie jeden raz. Utrata odpowiedzi, blad albo may_send=false oznaczaja zakonczenie bez SMS. Nie ponawiaj begin ani akcji SMS.</li>
                <li>W akcji <strong>Send SMS / Wyslij SMS</strong> uzyj pobranego numeru i tekstu, bez otwierania edytora. Po wykonaniu akcji zglos POST na <code>{{ route('macrodroid.api.acknowledge') }}</code> z <code>{"job_id":"&lt;job_id&gt;","claim_token":"&lt;claim_token&gt;","outcome":"submitted"}</code>. Oznacza to wykonanie akcji telefonu, nie dowod doreczenia. Jesli nie wiadomo, czy akcja sie wykonala, zglos <code>uncertain</code>; nie wysylaj SMS ponownie. Samo acknowledge mozna powtorzyc z tym samym wynikiem.</li>
                <li>Sprawdz ograniczenie <strong>makro nie jest uruchomione</strong>, zgodnosc jego nazwy z nazwa makra, kontrolki If/End If oraz domyslna karte SIM w akcji SMS. Nie zmieniaj nazw makr ani nie uruchamiaj ich recznie z pominieciem ograniczen. Profil SMS wymaga zasiegu sieci komorkowej, a logowanie makr jest wylaczone. Najpierw wlacz i sprawdz makro testu polaczenia, dopiero potem bramke i SMS na swoim numerze. Sprawdz takze powtorzona pobudke, brak sieci i utrate odpowiedzi; nie testuj na kliencie.</li>
            </ol>
        </details>
        <details><summary>Problemy i znaczenie statusow</summary><p class="small mt-2">Brak kontaktu: sprawdz internet, klucz, uprawnienia, ograniczenia baterii i adres webhooka. Telefon wykonal akcje SMS nie oznacza doreczenia. Niepotwierdzono oznacza, ze nie wolno automatycznie ponawiac wiadomosci. Wygasle zlecenia nie sa wydawane telefonowi. Zmiana klucza lub konfiguracji anuluje nierozpoczete zlecenia; rozpoczetym nadaje stan niepewny. Klucza ani eksportu zawierajacego klucz nie udostepniaj publicznie.</p></details>
    </section>
    <section><h2>Ostatnie 20 zlecen</h2><div class="table-responsive"><table class="table table-sm sms-history"><thead><tr><th>Data</th><th>Zamowienie</th><th>Odbiorca</th><th>Zrodlo</th><th>Status</th></tr></thead><tbody>@forelse($messages as $message)<tr><td>{{ $message->created_at->format('d.m.Y H:i') }}</td><td>@if($message->order_id)<a href="{{ route('orders.show', $message->order_id) }}">{{ $message->order_id }}</a>@else Test @endif</td><td>{{ $message->recipient }}</td><td>{{ $message->source }}</td><td>{{ $message->label() }}</td></tr>@empty<tr><td colspan="5" class="text-secondary">Brak zlecen SMS.</td></tr>@endforelse</tbody></table></div></section>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('smsRotateKey');
    button?.addEventListener('click', async () => {
        if (!confirm('Wygenerowac nowy klucz? Stary klucz przestanie dzialac, integracja zostanie wylaczona, a oczekujace zlecenia anulowane.')) return;
        button.disabled = true;
        try {
            const response = await fetch(@json(route('integrations.macrodroid.key')), { method: 'POST', headers: {'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':document.querySelector('#smsSettingsForm [name="_token"]').value}, body:JSON.stringify({expected_lock_version:Number(document.querySelector('[data-sms-version]').value)}) });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Nie mozna wygenerowac klucza. Odswiez strone.');
            document.getElementById('smsKeyResult').classList.remove('d-none');
            document.getElementById('smsOneTimeKey').value = data.access_token;
            document.querySelectorAll('[data-sms-version]').forEach(input => input.value = data.lock_version);
            document.getElementById('smsEnabled').value = '0';
        } catch (error) { document.getElementById('smsKeyError').textContent = error.message; }
        finally { button.disabled = false; }
    });
    document.getElementById('smsCopyKey')?.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(document.getElementById('smsOneTimeKey').value); }
        catch { document.getElementById('smsOneTimeKey').select(); }
    });
});
</script>
@endsection
