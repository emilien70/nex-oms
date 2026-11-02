# HALSKY.1 - konfiguracja i podglad zamowienia

## Zakres

Nowa integracja InPost Von Halsky w NEX-OMS, lokalnie. Polaczenie wlasnej
aplikacji sprzedawcy przez OAuth2 Client Credentials, test uprawnienia
odczytu zamowien i podglad jednego zamowienia po jego zewnetrznym ID.
Brak importu, akceptacji/odrzucania zakupow, zmian ofert, tworzenia
przesylek i dokumentow, ksiegowania wplat, kolejek i harmonogramu.
Aktywna integracja Base pozostaje nietknieta; zamowienia Von Halsky
nie sa traktowane jako zamowienia ze sklepu PrestaShop.

Preflight: main, HEAD 591127154d10b9dc61e50b3005fd5343228837de.
Zastane lokalne zmiany SMS i automatyzacji zachowano. Nie sa czescia
tego etapu. Nie wykonano commit, push, zmiany zdalnego Git ani operacji VPS.

## Kontrakt dostawcy i rozbieznosci

Sprawdzono 2026-10-29 dokumentacje InPost Von Halsky API 1.7.6 oraz:

- [Konfiguracja integracji, v0.4](https://inpsa-api-portal.inpost-group.com/pl/InPost-Von-Halsky-Integration-Configuration.html).
- [Wytyczne integracji, v0.26](https://inpsa-api-portal.inpost-group.com/pl/InPost-Von-Halsky-Integration-Guidelines.html).
- [API produkcyjne, v1.7.6](https://inpsa-api-portal.inpost-group.com/gokart-api.html).
- [Uwierzytelnianie InPost](https://developers.inpost-group.com/authentication).
- [Konfiguracja w Base](https://base.com/pl-PL/pomoc/wiedza/inpost-buy-konfiguracja-integracji/).
- [Zasady integracji publikowane przez InPost](https://inpost.pl/aktualnosci-inpost-von-halsky-integracja).

Client Credentials dotyczy wlasnego sklepu sprzedawcy; nie zastepuje
autoryzacji integratora obslugujacego cudzych sprzedawcow. Organization ID
to UUID marki/sklepu (org level 2), nie liczbowy identyfikator ShipX.
Nie kopiuje sie credentials aplikacji Base ani tokenu przewoznika InPost.

Instrukcja konfiguracji aplikacji InPost wymaga pieciu zakresow biznesowych
(kategorie read, oferty read/write, zamowienia read/write) oraz openid.
OMS prosi o podzbior openid api:orders:read i wykonuje tylko odczyty zamowien.
Nie testowano jeszcze na koncie sprzedawcy, czy dostawca wymaga calego
zestawu takze w zadaniu tokenu. Ewentualna odmowa zakresu ma zakonczyc test
kontrolowanym bledem; kod nie rozszerza samoczynnie zadanych uprawnien.

Wytyczne opisuja automatyczna akceptacje zakupow po oplaceniu; pomoc Base
zawiera rowniez ustawienie akceptacji. HALSKY.1 nie wysyla accept ani refuse.
Nie nalezy wnioskowac o mozliwosci rownoleglej produkcji tylko na podstawie
tego, ze technicznie mozna utworzyc druga aplikacje. Przed takim polaczeniem
uzgodnic z InPost dostep read-only obok Base i zasady przypisania integracji.

Obecny schemat OrderLineItem zawiera offer, bez jawnego pola quantity.
Podglad wyswietla przy ilosci brak danych, nigdy domyslne 1. Przed importem
potwierdzic z dostawca reprezentacje kilku sztuk tego samego produktu oraz
znaczenie ceny pozycji na rzeczywistym testowym payloadzie. Brak VAT w tym
schemacie nie oznacza stawki 0. Etap nie dopasowuje katalogu, VAT, SKU ani EAN.

## Bezpieczenstwo i zachowanie

- Nowe konto domyslnie Stage / niepolaczone. Sam GET nie zapisuje konta.
- Sekret i access token sa zaszyfrowane i ukryte w serializacji modelu.
- Client Secret nie wraca w HTML ani starych danych formularza po bledzie.
- Zmiana danych autoryzacji, organizacji lub srodowiska usuwa token i wynik
  testu. Zmiana samej nazwy i puste pole sekretu ich nie uniewazniaja.
- expected_lock_version chroni zapis, test, rozlaczenie i podglad.
  connection_revision odrzuca wynik sieciowy ze starej konfiguracji.
- Hosty sa stale HTTPS; formularz nie moze podmienic adresu API.
  Przekierowania sa wylaczone, timeout polaczenia 5 s, zadania 20 s.
- Odczyt 401 odnawia token maksymalnie raz. 401/403 uniewazniaja polaczenie.
  429 zapisuje czas oczekiwania w cache dla organizacji/srodowiska;
  Retry-After obsluguje sekundy i date HTTP, zakres 1-86400 s, domyslnie 60 s.
- Bledy sa stale, bez surowego body dostawcy, credentials i danych klienta.
- Test odczytuje najwyzej jedna pozycje listy. Pusta poprawna lista to sukces.
- Podglad odrzuca inna organizacje lub ID, bledny JSON i zla strukture.
  Brak kupujacego/faktury przy nieoplaconym zakupie nie jest bledem.
- Kwoty z JSON sa wybranymi wartosciami do wyswietlenia, nie danymi do
  obliczen finansowych lub importu. BigDecimal/istniejacy formatter odpowiada
  za format dwoch miejsc bez zaokraglania, zakres 0..999999999.99.
  Etap importu bedzie wymagal osobnego kontraktu precyzji liczb JSON.
- Zewnetrzne teksty sa escapowane, nie ma HTML komentarza ani surowego JSON.
  Dane podgladu nie sa utrwalane w bazie, sesji ani logach API. Widok ma
  Cache-Control private/no-store, Referrer-Policy no-referrer i noindex.
- Nowe trasy korzystaja z istniejacego panelu web/CSRF. Na VPS pozostaja
  za Basic Auth panelu; nie dodawac publicznego wyjatku Nginx dla Von Halsky.
  Host klienta nie uzyskuje dostepu do konfiguracji integracji.
- Rozlacz w OMS usuwa lokalny stan polaczenia, nie zmienia Base ani InPost.

## Pliki

Utworzone:

- Modules/Integrations/VonHalsky/Enums/VonHalskyEnvironment.php
- Modules/Integrations/VonHalsky/Exceptions/VonHalskyApiException.php
- Modules/Integrations/VonHalsky/Models/VonHalskyAccount.php
- Modules/Integrations/VonHalsky/Services/VonHalskyAccountService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyClient.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderPreviewService.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskySettingsController.php
- Modules/Integrations/VonHalsky/Http/Requests/UpdateVonHalskyAccountRequest.php
- Modules/Integrations/VonHalsky/Http/Requests/VonHalskyAccountActionRequest.php
- database/migrations/2026_10_29_010000_create_von_halsky_accounts_table.php
- resources/views/integrations/von-halsky/edit.blade.php
- resources/views/integrations/von-halsky/_preview.blade.php
- tests/Feature/VonHalskySettingsTest.php
- tests/Feature/VonHalskyOrderPreviewTest.php
- docs/halsky-1-report.md

Zmienione: routes/web.php, resources/views/integrations/index.blade.php,
docs/product-spec.md, docs/architecture.md. Nie usunieto plikow.

## Migracja

2026_10_29_010000_create_von_halsky_accounts_table tworzy tylko nowa tabele
ustawien. Nie ma backfillu ani dotykania zamowien/faktur. Unikalny indeks
von_halsky_account_key_unique ma krotka jawna nazwe zgodna z MySQL.
down odmawia usuniecia tabeli z zapisana konfiguracja.

Bezpieczna komenda po kopii i kontroli lokalnego srodowiska:

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_29_010000_create_von_halsky_accounts_table.php --force
```

Za osobna zgoda wlasciciela wykonano spojna kopie SQLite przez VACUUM INTO
ze zrodlem otwartym tylko do odczytu:

`storage/app/private/backups/halsky-1-20261029_152456-4b021525.sqlite`

Rozmiar 16621568 B, SHA-256:
`e50a2a798ed0defa49a0aab7ac1e72133c2e8f24b368b5a9295036240dcce6ac`.
Kopia: quick_check=ok, foreign_key_check=0. Uruchomiono tylko powyzsza
migracje lokalnie: PASS. Kontrola po: quick_check=ok, foreign_key_check=0,
335 zamowien i 89 dokumentow (bez zmian), von_halsky_accounts=0.
Nie dodawano danych dostepu ani nie wykonywano rzeczywistego testu API.
Na VPS nie wykonano migracji ani restartu.

## Testy

Testy uzywaja SQLite :memory:, Http::fake i preventStrayRequests.
Nie wykonuja rzeczywistych wywolan Von Halsky, ShipX, Allegro ani KSeF.
Zakres obejmuje konfiguracje/szyfrowanie, konflikt wersji, zmiane polaczenia
w trakcie odpowiedzi, ograniczenia HTTP, odczyt nieoplaconego zakupu,
tozsamosc organizacji/zamowienia, nieprawidlowe dane, XSS i brak skutkow
w tabelach biznesowych. Koncowy zestaw HALSKY.1: 60 testow / 362 asercje, PASS.
Szersza regresja: 828 testow / 5447 asercji, PASS (83.94 s).
Zakres: HALSKY.1, ustawienia Allegro/PayNow/PrestaShop, importy i platnosci,
automatyzacje, SMS, portal, adresy oraz dokumenty w automatyzacjach.
Nie jest to pelna regresja calego repozytorium ani test API KSeF.

Komendy w katalogu projektu:

```powershell
& C:\xampp\php\php.exe artisan test tests/Feature/VonHalskySettingsTest.php tests/Feature/VonHalskyOrderPreviewTest.php --compact
$testFiles = Get-ChildItem tests/Feature -Filter '*.php' |
    Where-Object { $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)' } |
    Select-Object -ExpandProperty FullName
$testFiles += (Resolve-Path tests/Feature/Products/InvoiceStockAutomationTest.php).Path
& C:\xampp\php\php.exe artisan test @testFiles --compact
& C:\xampp\php\php.exe vendor/bin/pint --test Modules/Integrations/VonHalsky database/migrations/2026_10_29_010000_create_von_halsky_accounts_table.php tests/Feature/VonHalskySettingsTest.php tests/Feature/VonHalskyOrderPreviewTest.php routes/web.php
git diff --check
```

Pint: PASS. PHP lint: 13 plikow PHP, PASS. route:list: 5 tras, PASS.
git diff --check: PASS; zastane ostrzezenie CRLF w product-spec.md bez
masowej zmiany zakonczen linii. Nie instalowano zaleznosci.
Widoki konfiguracji i zablokowanego podgladu sprawdzono w przegladarce
lokalnej na komputerze oraz przy szerokosci 390 i 320 px: brak poziomego
przepelnienia, poprawne kontrolki i kafelek integracji. Nie zapisano testowych
credentials w bazie operatora. Dane w podgladzie sprawdzaja testy Feature
z symulowanym API, nie rzeczywisty zakup. Nie ma testu MySQL 8 ani rzeczywistej
wspolbieznosci na tej bazie; konflikty wersji odtworzono w testach SQLite.

## Weryfikacja reczna

1. Integracje -> InPost Von Halsky: domyslnie Stage, niepolaczone.
2. Po uzyskaniu wlasnych danych Stage wpisac nazwe, UUID sklepu, Client ID
   i Client Secret. Zapisac. Nie uzywac credentials Base/ShipX.
3. Testuj polaczenie ma potwierdzic takze pusta liste zamowien.
4. Zamowienia -> podac zewnetrzne ID testowego zamowienia -> Pobierz podglad.
5. Sprawdzic klienta, adres/punkt odbioru, platnosci, ceny i komentarz.
   Porownac dane z panelem Von Halsky; nie powstaje zamowienie OMS.
6. Zmienic tylko nazwe: polaczenie zachowane. Zmienic UUID/srodowisko:
   polaczenie wyczyszczone i podglad zablokowany do nowego testu.
7. Rozlacz w OMS: brak dalszego podgladu; konfiguracja Base bez zmian.

Nie potrzeba restartu workerow: ten etap nie zmienia zadnej kolejki.
Nie oznaczac integracji jako sprawdzonej produkcyjnie przed uzgodnieniem
InPost i rzeczywistym testem Stage, szczegolnie ilosci, scopes i autoryzacji.
