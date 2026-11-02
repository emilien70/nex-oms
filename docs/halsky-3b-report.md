# HALSKY.3B - przekazywanie decyzji do Von Halsky

## Preflight i zakres

Repozytorium lokalne: main, HEAD
591127154d10b9dc61e50b3005fd5343228837de. Zachowano wszystkie zastane
zmiany SMS, automatyzacji i HALSKY.1/2A/2B/2C/2D/3A. Bez commit, push,
zmian env/zaleznosci i operacji na VPS. Wlasciciel poinformowal o wylaczeniu
pobierania przez Base oraz zgodzil sie na lokalna kopie i migracje.
Agent nie zmienial konfiguracji Base ani nie wywolywal API konta sprzedawcy.

## Funkcje

- Zakladka Statusy zamowien ma Powiazanie statusow Tak/Nie, domyslnie Nie.
  Statusy pochodza z biezacego slownika OMS, bez blokowania poczatkowego.
  Cele: Nie przekazuj (domyslnie), Zaakceptowano, Anulowane. Zapis formularza
  nie wywoluje API i nie tworzy zlecen historycznych.
- Przyszla zmiana statusu reczna, zbiorcza lub przez zwykle automatyczne
  akcje moze zlecic przekazanie. Import i HALSKY.3A nie potwierdzaja
  realizacji. Sam status platformy ACCEPTED nie jest dowodem wyslania accept.
- Zaakceptowano korzysta z accept dla oplaconego ACCEPTED. Przed POST jest
  nowy GET i kontrola organizacji/ID, stanu oraz obecnosci platnosci.
- Anulowane korzysta z refuse tylko dla CREATED bez platnosci API,
  historycznej kwoty kanalu i wplaty OMS, ze statusem platnosci unpaid.
  Nie ma endpointu refund ani automatycznego zwrotu pieniedzy.
- Aktualny importer przyjmuje tylko oplacone ACCEPTED. Wobec tego
  Anulowane dla obecnie importowanych zakupow daje jawna blokade.
  Test nieoplaconego CREATED jest symulacja przyszlego niepelnego importu,
  a nie dowodem obslugi takiego importu lub zgoda na anulowanie platnosci.
- Karta pokazuje ostatnie zlecenie oraz wynik: oczekiwanie/przekazywanie,
  potwierdzono, blad, zablokowano, pominieto albo wymaga sprawdzenia.
  Definitywny blad mozna ponowic z kontrola lock_version; powstaje nowy
  wiersz retry_of_id, bez utraty starej proby. Sukces/niepewny wynik nie
  pozwalaja na reczne ponowienie POST.
- Koncowe wyniki i niepewnosc maja wpis order_events. Istniejaca sygnatura
  fragmentu statusu aktualizuje wynik na otwartej karcie. GET widokow nie
  wywoluje marketplace. Nie dodano odrebnego odpytywania JS ani crona.
- Zlecenie nie zapisuje Order, pozycji, kwot, wplat, dokumentow ani przesylek.
  Nie cofa lokalnego statusu i nie emituje zdarzen automatyzacji po odpowiedzi.

## Kontrakt dostawcy i ograniczenie anulowania

Zrodla:

- [API InPost Gokart](https://inpsa-api-portal.inpost-group.com/gokart-api.html)
- [Wytyczne InPost](https://inpsa-api-portal.inpost-group.com/pl/InPost-Von-Halsky-Integration-Guidelines.html)
- [Pomoc Base](https://base.com/pl-PL/pomoc/wiedza/inpost-buy-konfiguracja-integracji/)

W sprawdzonym API accept/refuse wymagaja api:orders:write. Odpowiedz 202
zawiera commandId i PENDING/SUCCESS/FAILURE; wynik sprawdza GET orders/
commands/{commandId} z uprawnieniem read. Nie ma udokumentowanego klucza
idempotencji POST ani pola w Order pozwalajacego uznac ACCEPTED za dowod
potwierdzenia przez konkretnego integratora.

Base udostepnia opcje Anulowane, lecz nie opisuje dokladnego backendu.
Wytyczne InPost mowia o automatycznej akceptacji po oplaceniu i braku
mozliwosci anulowania takiego zakupu; dalsza obsluga wymaga refundacji.
Nie rozstrzygnieto inaczej zachowania Base ani nie potwierdzono anulowania
oplaconego zakupu z InPost. Taka funkcja pozostaje NIEOBSLUGIWANA, a nie
zastapiona refundacja. Rozszerzenie wymaga osobnego potwierdzenia kontraktu.

## Odpornosc i kolejka

VonHalskyStatusMappingService sprawdza wersje konfiguracji i ID aktywnych
statusow pod blokada konta. Stare mapowania nie przechodza na inna
organizacje/srodowisko. Zmiana polaczenia/test/rozlaczenie wylacza zapisy.

Zapis zlecenia jest transakcyjny; dispatch korzysta z DB::afterCommit.
event_id chroni przed powtornym zdarzeniem. Unikalny operation_key obejmuje
srodowisko, organizacje i zewnetrzne zamowienie. Rezerwacja jest zachowana
dla queued/sending/pending/uncertain/succeeded. Definitywny blad, blokada
lub pominiecie zwalniaja rezerwacje. Nie gwarantuje to deduplikacji miedzy
niezaleznymi systemami Base/OMS; potwierdzanie powinien prowadzic jeden system.

Po GET i uzyskaniu tokenu write kontekst jest ponownie sprawdzany pod
blokadami. Siec jest poza transakcja. Sending jest trwale zapisane przed
POST. Nie ma automatycznego powtarzania POST po 401, 5xx ani timeout.
Utrata workera po sending daje uncertain, nie kolejne wyslanie. Niepewnosc
bez commandId wymaga wyjasnienia; nie ma obejscia usuwajacego rezerwacje.

Gdy znany jest commandId, dalsza praca jest tylko GET. Wylaczenie mapowania
lub zmiana statusu OMS nie gubia wyniku wyslanego zlecenia. Poll nie przechodzi
na inne konto/organizacje/srodowisko. ID jest kanonizowane i unikalne
w organizacji/srodowisku; ten sam identyfikator nie potwierdzi innego zakupu.
Blad lokalnego zapisu odpowiedzi zachowuje znane ID do odzyskania wyniku.

ProcessVonHalskyOrderCommandJob na integrations ma uniqueFor=180, timeout=80,
tries=1. Obecny VonHalskyOrderImportDispatcher sprawdza do 100 naleznych
zlecen, takze przy wylaczonym imporcie nowych zakupow. Nie dodano schedulera
ani kolejki. Retry-After korzysta z dotychczasowego cooldown klienta.

Tokeny read zachowuja dotychczasowy zakres. Token read+write uzyskuje sie
dopiero przed operacja zapisu, z kontrola przyznanego scope. Brak write
nie wylacza poprawnego dostepu read. Nie zapisuje sie surowych payloadow API,
credentiali ani tresci klienta w historii zlecen. Bledy sa kontrolowane.

## Pliki

Nowe:

- database/migrations/2026_10_30_020000_add_von_halsky_outbound_order_commands.php
- Modules/Integrations/VonHalsky/Enums/VonHalskyOrderAction.php
- Modules/Integrations/VonHalsky/Models/VonHalskyStatusMapping.php
- Modules/Integrations/VonHalsky/Models/VonHalskyOrderCommand.php
- Modules/Integrations/VonHalsky/Http/Requests/SaveVonHalskyStatusMappingsRequest.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskyOrderCommandController.php
- Modules/Integrations/VonHalsky/Exceptions/VonHalskyCommandUncertainException.php
- Modules/Integrations/VonHalsky/Services/VonHalskyStatusMappingService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderCommandService.php
- Modules/Integrations/VonHalsky/Listeners/DispatchVonHalskyOrderCommand.php
- Modules/Integrations/VonHalsky/Jobs/ProcessVonHalskyOrderCommandJob.php
- resources/views/integrations/von-halsky/_status-mappings.blade.php
- resources/views/orders/partials/von-halsky-command.blade.php
- tests/Feature/VonHalskyOrderCommandTest.php
- docs/halsky-3b-report.md

Zmienione wzgledem lokalnego HALSKY.3A:

- Modules/Integrations/VonHalsky/Models/VonHalskyAccount.php
- Modules/Integrations/VonHalsky/Models/VonHalskyOrderLink.php
- Modules/Integrations/VonHalsky/Services/VonHalskyAccountService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyClient.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportDispatcher.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskySettingsController.php
- app/Providers/AppServiceProvider.php
- routes/web.php
- resources/views/integrations/von-halsky/edit.blade.php
- resources/views/orders/partials/von-halsky-status.blade.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Usunieto nieaktualne ostrzezenie konfiguracji,
nakazujace pozostawienie Base aktywnego. Nie zmieniono importerow,
automatycznych akcji, finansow ani KSeF.

## Migracja

Addytywna migracja tworzy dwie tabele i trzy pola konta. Historia ma
nullable FK/nullOnDelete; mapowania sa konfiguracja z cascadeOnDelete.
Wszystkie ograniczenia maja krotkie jawne nazwy. down odmawia usuniecia
uzytej konfiguracji lub historii. Nie ma backfillu, modyfikacji zamowien
ani nowych automatycznie wlaczonych opcji.

Komenda po prywatnej kopii i sprawdzeniu docelowej bazy:

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_30_020000_add_von_halsky_outbound_order_commands.php --force
```

Lokalna kopia i migracja zostaly wykonane po testach, na bazie SQLite
database/database.sqlite w srodowisku local. Kopia powstala spojnie przez
VACUUM INTO z polaczenia tylko do odczytu:

- storage/app/private/backups/halsky-3b-20261030_144349-6c2851dc.sqlite
- rozmiar: 18 407 424 bajty
- SHA256: d253638bcca17ac800bb2c3156bbfdb40ea3f0153b8b09f1c862cebbf15f6b12
- quick_check i foreign_key_check: OK.

Uruchomiono tylko wskazana migracje, batch 118. SHA256 migracji:
7032647aaa0dc9eb89c0f59d9b908ed50fa042f235c7b126f659b141dd55f73e.
Kontrola po operacji: status_sync_enabled=0, revision=0, mapowania=0,
zlecenia=0. Istniejacy import co minute pozostal wlaczony bez zmian.

Porownanie ze spojna kopia potwierdzilo niezmienione zamowienia, pozycje,
dokumenty, serie, liczniki, sloty, przesylki i wykonania automatyzacji.
Poza migracja roznice dotyczyly sesji oraz technicznych dat i logow
dzialajacego juz wczesniej harmonogramu integracji. Nie wykonano recznego
skanu, schedule:run, accept, refuse ani refund. Baza po migracji przeszla
quick_check i foreign_key_check.

## Testy i weryfikacja

Sprawdzono phpunit.xml i brak nadpisujacych zmiennych DB: testing,
SQLite :memory:, array cache/mail/sesje. HTTP/Queue/Mail/Storage sa fake.
Nie wykonano prawdziwego accept/refuse/refund ani transmisji KSeF.

Wyniki koncowe:

- Baseline przed zmianami: 318 PASS / 1796 asercji (23.50 s), zakres
  Von Halsky i AutomationOrderModificationTest.
- Nowy VonHalskyOrderCommandTest: 66 PASS / 359 asercji (9.99 s).
- Regresja Von Halsky i modyfikacji automatycznych: 384 PASS / 2155 asercji
  (37.14 s), czyli bez niepowodzen wobec baseline i z 66 nowymi testami.
- Szerszy zestaw powiazanych integracji, automatyzacji, produktow, portalu
  i SMS: 1185 PASS / 7645 asercji (102.88 s).
- Po ostatniej zmianie CSS zakladek ponowiono CommandTest i SettingsTest:
  93 PASS / 539 asercji (11.45 s).
- Pint --test oraz php -l: PASS dla 20 plikow PHP tego etapu.
- git diff --check oraz kontrola nowych plikow: PASS.

Przykladowa komenda powtorzenia ostatniego zestawu:

```powershell
& C:\xampp\php\php.exe artisan test tests/Feature/VonHalskyOrderCommandTest.php tests/Feature/VonHalskySettingsTest.php --compact
```

Szerszy zestaw obejmowal feature VonHalsky, Automation, AutomaticActions,
AllegroSettings/AutomaticOrderImport/OrderImport/OrderCatalog, Prestashop,
PayNowSettings/PaymentAutomation, OrderAutomationHistory, OrderAddressIsolation,
OrderSmsUi, MacroDroidSms, CustomerPortal, wszystkie Feature/Products oraz
Unit/VonHalsky. Nie jest to pelny zestaw repozytorium; nie uruchamiano
niezaleznych testow KSeF.

Kontrola przegladarki: zakladka Statusy zamowien na desktopie i 390x844,
bez poziomego overflow (szerokosc strony i viewportu 390 px) i bez bledow
konsoli. Potwierdzono Nie oraz Nie przekazuj we wszystkich polach, bez
zapisywania formularza. OMS 343 pokazuje osobne Przekazanie statusu do
Von Halsky: Nie przekazano. Tymczasowy rozmiar przegladarki przywrocono.

Kontrola reczna bez wysylki:

1. Integracje > Von Halsky > Statusy zamowien: Powiazanie statusow Nie,
   kazdy wiersz Nie przekazuj, aktualne nazwy statusow i dostepny poczatkowy.
2. Nie wlaczac przekazywania ani nie zapisywac mapowan w kontroli wizualnej.
3. OMS 343 > Informacje dodatkowe > Szczegoly zamowienia: status platformy
   i osobne Przekazanie statusu do Von Halsky / Nie przekazano.
4. Nie przenosic zamowienia produkcyjnego w celu testowania zapisu API.
   Pierwsza rzeczywista operacja wymaga osobno uzgodnionego scenariusza.
5. Po zakonczeniu lokalnym wczytac kod przez restart tylko Main Queues.
   Nie restartowac KSeF ani niczego na VPS.

Nie potwierdzono rzeczywistych accept/refuse, wspolbieznosci MySQL 8 ani
anulowania zakupow oplaconych. Funkcja pozostaje domyslnie wylaczona.
Poproszono o restart tylko lokalnego Main Queues; jego wykonanie wymaga
potwierdzenia wlasciciela. Nie wykonywano restartow KSeF ani VPS.
