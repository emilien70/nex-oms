# HALSKY.2C - cykliczne wykrywanie zamowien Von Halsky

## Zakres i bezpieczenstwo

Kontynuacja lokalnych HALSKY.1/2A/2B. Preflight: main,
HEAD 591127154d10b9dc61e50b3005fd5343228837de. Zachowano zastane zmiany
SMS, automatyzacji i poprzednich etapow. Bez commit, push i operacji na VPS.
Nie wlaczono pobierania, nie uruchomiono schedule:run ani recznego skanu
rzeczywistego konta. Nie zaimportowano ponownie PG76P11/OMS 343.

## Funkcje

- Ustawienia pobierania: Tak/Nie (domyslnie Nie), interwal 1/10 minut,
  jawna data i godzina od kiedy uwzgledniac utworzone zakupy.
- Sprawdz teraz zleca jednorazowa prace na integrations. Wymaga polaczenia,
  zapisanej daty i aktualnej lock_version. Nie wlacza cyklicznego pobierania.
- Istniejacy Laravel Scheduler sprawdza nalezne konta co minute; bez nowego
  crona, portu, webhooks, programu Supervisor lub providera modulu.
- Stronicowany odczyt changed orders, 5 minut zakladki i minuta stabilizacji.
  Raz dziennie uzgodnienie od wybranej daty poczatku, rowniez po dluzszej
  przerwie. Nie bazuje sie na zdarzeniach wygasajacych po 72 godzinach.
- Kandydaci sa trwale zapisywani przed przesunieciem kursora. Niepelna
  strona, obca organizacja, bledne sortowanie, limit zakresu i HTTP failure
  nie przesuwaja kursora. Juz zapisane proby pozostaja dostepne do odzyskania.
- Jeden wiersz na environment + organization_id + external_order_id.
  Historia ma 10 najnowszych zakupow bez paginacji. Wymagaja uwagi obejmuje
  needs_quantity/blocked/failed w biezacym zakresie i ma paginacje po 20;
  starsze nierozwiazane sprawy nie znikaja po przekroczeniu 10 zakupow.
- Tabela historii zawiera tylko techniczne metadane, nie payload API/PII.
  Wyjscie Blade escapuje ID, statusy i komunikaty. Token lease jest ukryty.
- Kompletne ACCEPTED z poprawna pojedyncza platnoscia i wszystkimi ilosciami
  api moga zostac automatycznie zapisane. Nie ma domyslnej jednej sztuki,
  wyliczania ilosci z ceny, dopasowan EAN/nazwy ani zmiany kwot kanalu.
- Brak ilosci daje Wymaga potwierdzenia ilosci; akcja otwiera istniejacy
  POST podgladu. Reczny import nadal wymaga obu checkboxow i zgodnosci hashy.
- Reczny i automatyczny zapis wspoldziela istniejaca transakcje. Automatyczny
  tryb pobiera szczegoly ponownie bez recznych ilosci. Sprawdza hash,
  kontekst konfiguracji, biezacy token i jego waznosc przed zapisem.
  Reguly katalogu HALSKY.2B i snapshoty zestawow pozostaja wspolne.
- Zapis wyniku imported jest atomowy z Order, pozycjami, linkiem i zdarzeniem.
  Nie sa emitowane OrderCreated/OrderDataCompleted/OrderPaymentRegistered.
  Nie tworzy sie faktur, SMS, e-maili ani ruchow magazynowych. Obserwator
  portalu moze jak dotychczas przygotowac link, bez wysylania go klientowi.
- Nie nadpisuje sie istniejacego Order, pozycji, wplat lub dokumentow.
  Aktualny status platformy jest informacja w historii pobierania, nie
  automatyczna zmiana statusu OMS. Refundacje/zwroty pozostaja poza etapem.
- Kosz i tombstone nie pozwalaja odtworzyc zakupu. Usuniecie konta/zamowienia
  zeruje FK historii, ale nie usuwa technicznej tozsamosci pobierania.
- order_import_revision uniewaznia stare joby po zmianie ustawien.
  Zmiana credentials, organizacji, srodowiska, rozlaczenie lub nowy test
  polaczenia wylacza pobieranie i zmienia rewizje. Nazwa konta nie zmienia
  tozsamosci. Stare joby nie moga wpisac wyniku do nowej konfiguracji.
- Processing ma token UUID/lease 180 s. Po przerwaniu mozna odzyskac probe;
  stary worker nie moze zapisac zamowienia ani nadpisac wyniku nowego.
  Bledy chwilowe maja trwaly termin kolejnej proby i respektuja Retry-After.

## Kontrakt API

Publiczna specyfikacja sprawdzona 2026-10-29: wersja 1.7.6.
GET /v1/organizations/{organizationId}/orders posiada updatedAtGte, sort,
limit/offset oraz paymentStatus. Bez jawnego filtra paymentStatus API
domyslnie zwraca PAID; OMS wysyla PAID i NOT_PAID jako powtorzone klucze,
zgodnie z OpenAPI form/explode, kodowane przez Guzzle Psr7 Query.
Sortowanie updatedAt i createdAt jest rosnace, strona ma 30 rekordow.
Nie dodano scopes; pozostaje openid api:orders:read.

OrderLineItem ma obecnie tylko offer i nie definiuje quantity. Brak zostal
tez potwierdzony na PG76P11 w HALSKY.2A. Nie deklaruje sie, ze pelny import
bez potwierdzenia ilosci bedzie obecnie mozliwy. Obsluga jawnego quantity
jest testowana jako bezpieczna sciezka na wypadek jego rzeczywistego
przekazania. Puste lub niepoprawne quantity jest bledem, nie domyslna 1.

Zrodla:

- https://inpsa-api-portal.inpost-group.com/gokart-api.html
- https://inpsa-api-portal.inpost-group.com/pl/InPost-Von-Halsky-Integration-Guidelines.html
- https://base.com/pl-PL/pomoc/wiedza/inpost-buy-konfiguracja-integracji/

Odczyty dotyczyly jedynie publicznej dokumentacji; bez konta sprzedawcy.

## Pliki tego etapu

Utworzone:

- database/migrations/2026_10_29_040000_add_von_halsky_order_discovery.php
- Modules/Integrations/VonHalsky/Models/VonHalskyOrderImportAttempt.php
- Modules/Integrations/VonHalsky/Http/Requests/SaveVonHalskyAutomaticImportRequest.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportState.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportDispatcher.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderDiscoveryService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderProcessingService.php
- Modules/Integrations/VonHalsky/Jobs/DiscoverVonHalskyOrdersJob.php
- Modules/Integrations/VonHalsky/Jobs/ImportVonHalskyOrderJob.php
- resources/views/integrations/von-halsky/_import-history.blade.php
- tests/Feature/VonHalskyAutomaticOrderImportTest.php
- docs/halsky-2c-report.md

Zmienione wzgledem lokalnego HALSKY.2B:

- Modules/Integrations/VonHalsky/Models/VonHalskyAccount.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskySettingsController.php
- Modules/Integrations/VonHalsky/Services/VonHalskyClient.php
- Modules/Integrations/VonHalsky/Services/VonHalskyAccountService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderNormalizer.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportService.php
- resources/views/integrations/von-halsky/edit.blade.php
- routes/web.php
- routes/console.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Poprzednie etapy nadal sa lokalne/niezacommitowane.

## Migracja

Nowa addytywna migracja dodaje ustawienia/kursory konta i historie, bez
backfillu, zmian Order, dokumentow, platnosci, credentiali lub mapowan.
Krotkie indeksy vh_import_identity_unique i vh_import_pending_index.
FK nullable/nullOnDelete; down odmawia usuniecia uzytych danych lub ustawien.
Daty historii maja precyzje 6, by zachowac source updatedAt z mikrosekundami.
Przed kompletnym wykonaniem migracji dispatcher i nowy zapis historii sa
nieaktywne. Sam GET konfiguracji nie tworzy konta ani nie wywoluje API.

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_29_040000_add_von_halsky_order_discovery.php --force
```

Po osobnej zgodzie wlasciciela wykonano lokalna kopie i tylko te migracje.
Status: Ran, batch 116. Dodano 9 kolumn konfiguracji i jedna tabele historii.
Pobieranie pozostaje wylaczone, interwal 10 minut, rewizja 0, data i kursor
puste, historia 0 wierszy. Nie uruchomiono recznego skanowania produkcji.

Prywatna, spojna kopia SQLite wykonana przez VACUUM INTO z polaczenia
read-only, przed migracja:

```text
storage/app/private/backups/halsky-2c-20261029_215929-90b691e9.sqlite
17608704 B
SHA256 c5d069cbd7a16157958817f4b43725454f4abb4af2e2b90b1650f7e994d60a51
```

Kopia i baza po migracji: quick_check=ok, foreign_key_check bez naruszen.
Porownano wszystkie oryginalne kolumny 100 tabel z kopia. W 94 tabelach
(poza osobno sprawdzona migrations) wszystkie wiersze pozostaly identyczne.
Pieciu pozostalych tabel nie opisuje sie jako identycznych: dzialajace
w tle synchronizacje zmienily znaczniki skanowania Allegro/PrestaShop,
last_synced_at/updated_at przesylek, cache oraz dopisaly 3 integration_api_logs.
Nie usunieto ani nie nadpisano zadnego starego logu. Po pominieciu tych
kolumn wszystkie pozostale dane wskazanych tabel pozostaly identyczne.
Historia migracji zachowala stare wiersze i otrzymala tylko wpis tego etapu.
Nie usunieto zadnej tabeli; jedyna nowa to von_halsky_order_import_attempts.

Zamowienia 343, pozycje 432, dokumenty 89, pozycje dokumentow 174,
zdarzenia zamowien 1601, automatyzacje 102 - bez zmian danych i liczby.
OMS 343 porownano rowniez osobno: caly wiersz niezmieniony. Ustawienia
polaczenia Von Halsky, credentiale, powiazania i katalog pozostaly identyczne.

Nie restartowano KSeF ani schedulera. Main Queues jest lokalnym queue:work;
panel sterowania nie udostepnial aktywnego okna, wiec nie wymuszano zabicia
procesu. Przed wlaczeniem pobierania nalezy zrestartowac tylko Main Queues
w istniejacym panelu. Nie ma potrzeby ponownego wykonywania migracji.

## Weryfikacja

Sprawdzono phpunit.xml: SQLite :memory:, testing, queue sync, cache/session
array. Testy uzywaja fake HTTP, Queue/Mail oraz prywatnego testowego Storage.
Nie wykonano rzeczywistych polaczen finansowych ani KSeF.
Koncowe testy HALSKY.1/2A/2B/2C: 218 PASS / 1197 asercji (13.94 s).
Sam nowy zestaw HALSKY.2C: 57 PASS / 220 asercji (4.71 s).
Szersza regresja importow, wszystkich Feature/Products, automatyzacji,
platnosci, SMS i portalu: 1049 PASS / 6795 asercji (69.47 s).

Komenda szerszej regresji:

```powershell
$files = Get-ChildItem tests/Feature -Filter '*.php' | Where-Object {
    $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)'
} | Select-Object -ExpandProperty FullName
$files += (Get-ChildItem tests/Feature/Products -Filter '*.php' | Select-Object -ExpandProperty FullName)
$files += (Get-ChildItem tests/Unit/VonHalsky -Filter '*.php' | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files --compact
```

Pint --test na 18 plikach PHP etapu: PASS. php -l: PASS dla tych samych
plikow (powtorzony dla ostatnich zmian). git diff --check: PASS, jedynie
istniejace ostrzezenie o CRLF product-spec. Osobny diff --no-index --check
dla nowych plikow: PASS. Node: skladnia skryptu formularzy PASS.
route:list potwierdza 12 tras Von Halsky, w tym nowe POST automatic-import
i check-now. Po zatwierdzonej migracji migrate:status potwierdza Ran [116].
Powtorzony lokalnie zestaw VonHalskyAutomaticOrderImportTest: 57 PASS /
220 asercji (4.91 s), na SQLite :memory: i z symulowanym API. Nie wykonano
ponownie szerszej regresji, bo kod aplikacji w tym kroku nie zostal zmieniony.
Kontrola wizualna po migracji pozostaje do wykonania. Nie uruchamiano
schedule:run, queue:work --once ani prawdziwych zadan marketplace.

## Ograniczenia

- Nie testowano rzeczywistego pobierania z konta produkcyjnego ani na MySQL 8.
  SQLite nie dowodzi prawdziwej wspolbieznosci MySQL, kill lub Supervisor.
  Testy sprawdzaja fencing rewizji/tokena i odzyskiwanie lease w izolacji.
- API nie zapewnia atomowego snapshotu listy offsetowej. Zakladka, opóźnienie
  i dobowe uzgodnienie ograniczaja ryzyko przemieszczenia rekordow miedzy
  stronami; nie stanowia gwarancji dowolnego opoznienia po stronie API.
- Bezpieczny limit przebiegu: 50 stron (1500 rekordow) i 45 s przed kolejnym
  HTTP. Przekroczenie pozostawia blad i kursor, nie pomija zakupu. Bardzo
  duzy zakres pobierania lub dobowego uzgodnienia wymaga oddzielnego planu
  porcjowania historii. Do tego czasu mechanizm zglasza blad zamiast
  samoczynnie pominac czesc zakupow.
- Ponawianie cykliczne dziala przy wlaczonym pobieraniu. Gdy jest wylaczone,
  kolejny reczny Sprawdz teraz moze odzyskac osierocone lub nalezne proby.
- Nie wlaczono automatycznych akcji i nie zmieniono integratora Base.
- Nie uruchamiano calego repozytorium ani niezaleznych testow KSeF.
  Znany wczesniej OrdersAjaxListTest oczekujacy starej nazwy Szablony E-Mail
  nie jest naprawiany ani objety deklaracja zielonej regresji tego etapu.

## Reczna kontrola

1. Po migracji otworz Integracje > InPost Von Halsky > Zamowienia. Pobieranie
   ma pozostac Nie, interwal 10 minut, data pusta. Historia jest pusta.
2. Nie zapisuj Tak ani nie klikaj Sprawdz teraz bez swiadomego wyboru daty
   i zgody na odczyt konta produkcyjnego oraz tworzenie lokalnych zamowien.
3. Po takim uruchomieniu brak ilosci ma trafic do Wymagaja uwagi. Potwierdz
   ilosci w istniejacym podgladzie; sprawdz katalog, VAT, sumy i brak akcji.
4. Kolejny odczyt tego samego zakupu ma aktualizowac jeden wiersz, a nie
   tworzyc kolejne Order. Historyczny OMS 343 pozostaje niezmieniony.
5. Historia pokazuje tylko 10 najnowszych zakupow, starsze sprawy wymagajace
   uwagi sa dostepne niezaleznie od niej. Zmiana konfiguracji lub rozlaczenie
   ma uniewaznic stare zlecenia, bez wykonywania ich dla nowej organizacji.
