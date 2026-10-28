# SMS.1 - MacroDroid, szablony i automatyzacje

## Stan

Lokalna czesc OMS jest wdrozona do dalszej weryfikacji. Integracja pozostaje
wylaczona: brak urzadzenia, klucza, szablonow i zlecen w nowych tabelach.
Nie wykonano rzeczywistego HTTP do MacroDroid ani wysylki SMS, operacji na
VPS, commit/push, zmian APP_KEY, .env lub zaleznosci. Wczesniejsze lokalne
zmiany portalu pozostawiono bez cofania i nie zaliczamy ich do SMS.1.

Pliki importu **sa dostepne w konfiguracji integracji**. Odczytano jako dane eksporty
Test_sms.macro, Test_sms2.macro i Test_sms3.macro z telefonu wlasciciela:
Android 10 / MacroDroid 6.0.11 Pro / jedna SIM. Nie uruchamiano tych makr.
Poznano format HTTP, naglowka, zmiennych, JSON Parse i If/End If. Testowe
eksporty nie sa gotowa bramka; akcja SMS poza blokiem If nie jest chroniona.

Po dodaniu MacroDroid Macro Builder odczytano katalog i dokladny schemat
wybranych funkcji. Walidator zwrocil VALID dla obu kompletnych profili:
SMS (1 trigger, 24 akcje, 2 ograniczenia) i test (1 trigger, 13 akcji,
1 ograniczenie). Walidacja nie potwierdza jeszcze importu ani zachowania
rzeczywistego Androida. Nie publikowano kluczy ani prywatnych eksportow.

Profile resources/macrodroid/{sms,probe}.macro sa domyslnie wylaczone,
bez logowania, z pustym klucz_oms i zastepczym adresem HTTPS .invalid.
Kontroler podmienia tylko oms_url, jezeli app.url jest poprawnym HTTPS
bez credentiali/query/fragmentu, z publicznym hostem. Nie pobiera klucza
ani webhooka z bazy. Endpoint pobierania ma no-store/nosniff i ograniczenie
domeny klienta, tak jak inne strony administracyjne.

Makra resetuja kod i tresc odpowiedzi przed kazdym blokujacym HTTP;
nie sledza przekierowan i sprawdzaja certyfikat. SMS jest zagniezdzony
pod HTTP 200 claim, available=true, HTTP 200 begin i may_send=true.
Brak petli ponawiania SMS. Makro SMS wymaga zasiegu, oba maja ograniczenie
nieuruchomionej instancji po dokladnej nazwie. Nalezy sprawdzic je po imporcie;
nie zmieniac nazw makr ani uruchamiac z pominieciem ograniczen. Test uzywa
wylacznie heartbeat i nigdy nie zawiera SendSMSAction/claim/begin.

## Funkcje OMS

- Integracja w liscie integracji, formularz ustawien z optimistic lock,
  oddzielny klucz urzadzenia wyswietlany raz, szyfrowany webhook,
  ostatni kontakt i test z wyzwaniem UUID; odczyt niczego nie wlacza.
- Szablony E-mail/SMS: niezmieniona sekcja e-mail i nowa tabela SMS ponizej,
  tworzenie/edycja/usuwanie z ochrona wersji, katalog OrderVariableService,
  podglad na zamowieniu i szacowanie segmentow po renderowaniu.
- GSM-7 uwzglednia znaki rozszerzone, a UTF-16 pary surogatow; granice
  segmentow nie przecinaja tych jednostek. Brak transliteracji.
- Wymiana wiadomosci ma przelacznik E-mail/SMS, opcjonalny szablon,
  edytowalny numer i tresc, potwierdzenie recznej wysylki, podglad,
  odswiezanie historii oraz anulowanie tylko nierozpoczetych zlecen.
- send_sms w automatyzacjach: szablon, Do klienta, maks. 10 dodatkowych
  numerow. Kazdy odbiorca ma osobny rekord; numery sa normalizowane i
  deduplikowane. Brak wymaganego numeru blokuje cala akcje przed zapisem.
- [link_zamowienia] jest dopuszczony tylko dla numeru klienta. Przed claim
  i begin ponownie sprawdzany jest numer i uprawnienia portalu.

## Dostarczenie i granice bezpieczenstwa

SmsPreparationService zamraza tresc i numer w transakcji, szyfruje oba pola,
zapisuje snapshot nazwy szablonu i HMAC intencji. batch_key chroni cale
zlecenie rowniez po zmianie odbiorcy; idempotency_key jest unikalny dla SMS.
Usuniecie szablonu nie usuwa wiadomosci. Usuniecie zamowienia pozostawia
historie z nullable FK, ale blokuje pobranie nierozpoczetej wiadomosci.

Kolejnosc blokad: portal settings -> device -> order -> message.
Claim przydziela zlecenie na maks. 120 s i ujawnia losowy claim_token raz.
Begin wydaje zgode tylko jeden raz. Brak odpowiedzi begin nie pozwala
ponownie wywolac skutku SMS. Powtorzony ack nie powtarza efektow.
Po 5 min rozpoczetego, niepotwierdzonego zadania stan jest niepewny;
pozne potwierdzenie nie zmienia niepewnosci na sukces. Nie ma automatycznego
ponawiania wysylki ani przycisku, ktory ponownie wydaje ten sam claim.

API POST: /api/macrodroid/v1/heartbeat, /claim, /begin, /acknowledge.
Wymagane HTTPS i 64-znakowy Bearer token. Token w bazie to tylko SHA-256.
CSRF wylaczono tylko dla tych czterech sciezek, nie dla panelu. Limity
IP/token po 120/min, panelowe akcje 10/min, podglady 60/min. Domenie
klienta nie wolno korzystac z tych tras. Nie dodano logowania uzytkownikow;
panel nadal wymaga istniejacej ochrony dostepu, na VPS HTTP Basic Auth.

Telefon moze podac job_id lub pobrac najstarsze zlecenie przez claim z {}.
Odmowa claim/begin ma HTTP 409 i nie zawiera numeru ani tresci.
Stan submitted oznacza zgloszenie wykonania akcji przez telefon, nie
potwierdzenie operatora ani doreczenia. Telefon/makro wymagaja weryfikacji
importu, kolejnosci akcji i pracy w tle przed rzeczywistym uzyciem.

NotifySmsDeviceJob w kolejce default wysyla jedynie pobudke na dokladny
HTTPS trigger.macrodroid.com, bez SMS, tokenu ani UUID zlecenia w zapytaniu.
Nie sledzi przekierowan. Budzi tylko poczatek nierozpoczetej kolejki;
pobudki sa ograniczane do 30/min na generacje/typ. Odmowa HTTP nie oznacza
bledu samego SMS i nie przestawia go na wyslany. Recovery co minute budzi
wylacznie jeszcze niepobrana kolejke. Backend egzekwuje tempo wysylek
pod blokada urzadzenia. Zmiana konfiguracji/klucza anuluje oczekujace
i ustawia niepewnosc dla rozpoczetego skutku; generacja uniewaznia stare zadania.

Test polaczenia wywoluje identyfikator webhooka z dopiskiem -probe.
Makro sondy pobiera probe_id z uwierzytelnionego heartbeat i potwierdza go
drugim heartbeat; nie korzysta z claim/begin/SMS. Dzieki temu test bez SMS
nie pobiera zlecenia oczekujacego w kolejce.

## Migracja i lokalna kopia

Nowa migracja:
`database/migrations/2026_10_28_010000_create_macrodroid_sms_tables.php`.
Tworzy trzy nowe tabele bez backfillu; FK historii sa nullable/nullOnDelete.
Down odmawia usuniecia niepustej konfiguracji, szablonow lub historii.
Nie edytowano wczesniejszych migracji ani danych biznesowych.

Za osobna zgoda wlasciciela wykonano spojna kopie SQLite przez VACUUM INTO
(rozszerzenie SQLite3 nie jest wlaczone w lokalnym PHP; wykorzystano PDO):
`storage/app/private/backups/sms-1-20261028_142840-5efd0c8a.sqlite`.
SHA-256: `3c715ac1ede1fcdc663f752fa990b902bd6ca7811b717105b9a9c96a158643a6`.
Integrity check kopii i bazy roboczej: ok.

Uruchomiono lokalnie tylko:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_28_010000_create_macrodroid_sms_tables.php --force --no-interaction
```

Migracja zakonczona poprawnie, trzy nowe tabele pozostaly puste.
Nie wstrzymywano istniejacych workerow/importow. W porownaniu 93 wczesniejszych
tabel 87 bylo identycznych; 6 zmienialo sie rownolegle: techniczne daty skanow
Allegro/PrestaShop, logi API i zwroty (jeden nowy zwrot z pozycja/zdarzeniem
oraz daty synchronizacji). Nie cofano tych zmian. Porownanie bez zatrzymania
workerow nie stanowi dowodu niezmiennosci calej bazy w czasie prac.
Kod migracji wykonuje wylacznie DDL nowych tabel; nie ma aktualizacji
zamowien, faktur, numeracji, wplat ani konfiguracji innych integracji.

## Testy i UI

PHP 8.2.12, SQLite :memory:, prywatny storage/cache przez istniejacy
run-isolated.php, blokada prawdziwych transportow, symulowane HTTP/kolejka.
Nie testowano na bazie roboczej. Nie wykonano testow KSeF ani pelnej
regresji calego projektu; nie deklarujemy ich wyniku.

Koncowy wynik: **525 testow, 3793 asercje, 0 niepowodzen, 0 bledow**,
56.87 s. Raport JUnit: `storage/app/private/qa/sms-final-regression.xml`.
Komenda:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit --filter="MacroDroidMacroConfigurationTest|MacroDroidSmsTest|SmsSegmentsTest|EmailTemplateSettingsTest|OrderEmailMessageFoundationTest|Automation|CustomerPortal|EmailSettingsPageTest|OrderEmailRecoveryTest|OrderEmailManualUiTest|OrderEmailDispatchTest|SmtpTransportFactoryTest" --log-junit=storage/app/private/qa/sms-final-regression.xml
```

W toku testow poprawiono kolejnosc idempotentnego wyniku i podwojny wpis
kolumny nowej, jeszcze niewdrozonej migracji. Dwa stare testy UI dostosowano
do uzgodnionej nazwy menu i uruchomionego wyboru SMS, zachowujac asercje e-mail.
Ostateczny przebieg nalezy oceniac po tych poprawkach, nie po wczesniejszych
nieudanych przebiegach roboczych. PHP lint, Pint i git diff --check obejmuja
pliki tego zadania, bez formatowania wczesniejszych zmian portalu.

Nowe 17 testow konfiguracji obejmuje brak sekretow/zapisow, wybor profilu,
publiczny HTTPS, blokade domeny klienta, bezpieczne HTTP i dodatnie bramki
w pliku SMS oraz brak SMS w sondzie. Test roboczy domeny klienta poprawiono
tak, aby uzywal rzeczywistego singleton_key portalu (default, nie primary).
Pint --test i php -l: PASS dla 32 plikow PHP zakresu SMS. git diff --check: PASS.

W przegladarce sprawdzono formularz konfiguracji, modal szablonu, podglad
Test [id_zamowienia] -> Test 192 (8 jednostek GSM-7 / 1 czesc) i przelaczanie
formularza e-mail/SMS. Kontrola desktop i 390x844; przywrocono viewport.
Nie zapisano szablonu, nie wygenerowano klucza, nie zmieniono konfiguracji
ani nie kliknieto wysylki. Serwer lokalny: http://127.0.0.1:8000.
Po dodaniu profili sprawdzono widocznosc obu przyciskow i rzeczywiste pobranie
NEX-OMS-SMS.macro przez przegladarke. Nie wykonano importu ani uruchomienia
pliku na Androidzie.

## Zmienione pliki

Nowe:

- Modules/Integrations/MacroDroid/Models/{SmsDevice,SmsTemplate,SmsMessage}.php
- Modules/Integrations/MacroDroid/Services/{SmsPreparationService,SmsGatewayService,SmsPhoneNumber,SmsSegments}.php
- Modules/Integrations/MacroDroid/Jobs/NotifySmsDeviceJob.php
- Modules/Integrations/MacroDroid/Http/Middleware/AuthenticateSmsDevice.php
- Modules/Integrations/MacroDroid/Http/Controllers/{SmsSettingsController,SmsTemplateController,OrderSmsController,SmsDeviceApiController}.php
- Modules/Automation/Services/AutomationSmsActionService.php
- database/migrations/2026_10_28_010000_create_macrodroid_sms_tables.php
- resources/views/integrations/macrodroid/edit.blade.php
- resources/macrodroid/{sms,probe}.macro
- resources/views/orders/_sms-templates.blade.php
- resources/views/components/{order-sms-compose,order-sms-history}.blade.php
- tests/Feature/MacroDroidSmsTest.php
- tests/Feature/MacroDroidMacroConfigurationTest.php
- tests/Unit/MacroDroid/SmsSegmentsTest.php
- docs/sms-1-report.md

Zmodyfikowane:

- Modules/Automation/Http/Controllers/AutomationRuleController.php
- Modules/Automation/Http/Requests/SaveAutomationRuleRequest.php
- Modules/Automation/Services/{AutomationCatalog,AutomationActionExecutor,AutomationActivityFeed,OrderAutomationHistory}.php
- app/Http/Controllers/EmailTemplateController.php
- app/Providers/AppServiceProvider.php
- app/View/Components/OrderEmailExchange.php
- bootstrap/app.php
- routes/{web,console}.php
- resources/views/components/order-email-exchange.blade.php
- resources/views/integrations/index.blade.php
- resources/views/layouts/app.blade.php
- resources/views/orders/{automatic-actions,email-settings,show}.blade.php
- tests/Feature/{EmailSettingsPageTest,OrderEmailManualUiTest}.php
- docs/{006-Deployment,architecture,product-spec}.md (dopisano SMS; zachowano poprzednie tresci)

Nie usunieto istniejacych plikow projektu.

## Reczna weryfikacja i pozostale ograniczenia

1. Integracje -> MacroDroid: konfiguracja jest wylaczona, historia pusta,
   instrukcja i dwa przyciski pobierania widoczne. Pobranie nie wlacza bramki.
2. Zamowienia -> Szablony E-mail/SMS: dotychczasowy e-mail u gory, SMS ponizej.
   Sprawdz modal, katalog zmiennych i podglad dla wybranego zamowienia.
3. Zamowienie -> Wymiana wiadomosci: zmien kanal na SMS. Numer jest pobrany
   z zamowienia, brak pol SMTP/HTML, wysylka zablokowana przy wylaczonej bramce.
4. Automatyczne akcje: typ Wyslij SMS, szablon i odbiorcy Do klienta/inne numery.
   Nie aktywuj produkcyjnej reguly przed testem telefonu i przeladowaniem
   lokalnych Main Queues. Na VPS niczego nie przeladowywano.
5. Pobierz i zaimportuj dwa pliki. W obu ustaw oms_url i klucz_oms; sprawdz
   ograniczenia/If, domyslna SIM, HTTPS, uprawnienia i prace w tle. Najpierw
   test polaczenia, pozniej kontrolowany SMS na wlasny numer. Sprawdz takze
   powtorzone pobudki i utrate sieci/odpowiedzi, bez klientow i bez ponowien SMS.

Wlasciciel potwierdzil restart tylko lokalnych Main Queues. Pozniejsze
dokonczenie plikow importu/kontrolera pobierania nie zmienilo kodu jobow.

Nie ma zweryfikowanej wysylki/doreczenia na Androidzie ani testu rzeczywistej
wspolbieznosci MySQL 8. Backendowe testy powtorzen i blokad nie dowodza
exactly-once po stronie systemu Android/SIM. Nie nazywac SMS.1 w pelni
gotowym do wlaczenia, dopoki import i kontrolowana proba telefonu nie przejda.
