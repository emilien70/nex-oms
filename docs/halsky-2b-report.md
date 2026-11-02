# HALSKY.2B - powiazania ofert Von Halsky z katalogiem OMS

## Zakres

Lokalne rozszerzenie recznego importu HALSKY.2A, bez automatycznego importu,
nowych scopes OAuth, pobierania katalogu ofert i zapisow do marketplace.
Zachowano istniejace zmiany SMS, automatyzacji i HALSKY.1/2A.
Preflight: main, HEAD 591127154d10b9dc61e50b3005fd5343228837de.
Nie wykonano commit, push ani operacji na VPS.

## Dzialanie

- Istniejacy modal produktow/zestawow ma dodatkowy typ InPost Von Halsky.
  Mozna dodawac, edytowac i usuwac wiele ofert dla jednej pozycji OMS.
  ID pozostaje stringiem; bez automatycznego dopasowania nazwy, SKU lub EAN.
- Oferta jest unikalna w srodowisku i organizacji. Import dodatkowo wymaga
  biezacego konta. Stare powiazania innej organizacji/srodowiska nie dzialaja.
  Formularz sprawdza connection_revision, a zapis blokuje rekord konta.
- Wszystkie trzy rodzaje integracji zachowuja pozostale grupy w odpowiedzi
  AJAX. Nie utworzono drugiego modala ani zgadywanego linku do oferty.
- Ustawienia Zamowienia: use_linked_products, use_catalog_product_names,
  use_catalog_vat. Domyslnie wszystkie Nie. Dwie ostatnie opcje maja efekt
  tylko wtedy, gdy pierwsza jest wlaczona i istnieje jawne powiazanie.
- Importowane ceny, laczna kwota, rabaty, dostawa i deklaracja oplacenia
  nadal pochodza z zamowienia Von Halsky. Cena/stany produktu OMS nie sa
  kopiowane i ich zmiana nie uniewaznia podgladu.
- VAT korzysta z OrderImportVatResolver: poprawny katalog przy wlaczonej
  opcji, poprawna stawka kanalu, opcjonalny domyslny VAT. Jawne 0 pozostaje
  zerem. Invalid/special po stronie kanalu nie uruchamia fallbacku. Katalog
  i domyslny VAT wymagaja potwierdzonego krajowego kontekstu obu adresow.
  Obecne API Von Halsky nie dostarcza VAT, wiec normalizer zachowuje missing;
  nie dodano nieudokumentowanego pola API. Testy wspolnej reguly obejmuja
  rowniez prawidlowa, niepoprawna i szczegolna stawke kanalu.
- Podglad prezentuje docelowa nazwe, powiazanie OMS i pochodzenie VAT.
  Brak mapowania jest ostrzezeniem, nie blokada kompletnego importu.
  Nieprawidlowy powiazany zestaw blokuje zapis.
- Zestaw pozostaje jedna pozycja. Jego sklad i waga sa kopiowane do
  istniejacych historycznych struktur. Nie odejmuje sie stanu magazynu.
  Pozniejsze akcje dokumentow/magazynu zachowuja dotychczasowe reguly OMS.
- Fingerprint katalogu obejmuje uzyte ustawienia, powiazanie, nazwe, VAT,
  wage i sklad, ale nie cene/stany. Jest czescia HMAC source_hash. Nie zawiera
  recznie dopisywanych ilosci; brak quantity z API nadal wymaga potwierdzenia.
- Przed importem ponawiany jest odczyt API. W transakcji katalog sprawdzany
  jest ponownie pod blokadami konta, powiazan, produktow i skladu zestawu.
  Zmiana od podgladu wymaga nowego potwierdzenia. Zapis pozycji ma whitelist.
- Istniejace zamowienie przy ponownym imporcie jest jedynie otwierane.
  Nowe mapowania nie zmieniaja historycznych pozycji, faktur ani platnosci.
  Lokalnego zamowienia 343 nie aktualizowano i nie przelinkowano.

## Pliki

Utworzone:

- Modules/Integrations/VonHalsky/Models/VonHalskyProductLink.php
- Modules/Integrations/VonHalsky/Http/Requests/SaveVonHalskyProductLinkRequest.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskyProductLinkController.php
- Modules/Integrations/VonHalsky/Services/VonHalskyProductLinkService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderCatalogService.php
- database/migrations/2026_10_29_030000_add_von_halsky_product_links.php
- tests/Support/VonHalsky/CatalogFixtures.php
- tests/Feature/Products/VonHalskyProductLinkTest.php
- tests/Feature/VonHalskyOrderCatalogTest.php
- docs/halsky-2b-report.md

Zmienione w tym etapie (wzgledem stanu HALSKY.2A, nie tylko HEAD):

- Modules/Integrations/VonHalsky/Models/VonHalskyAccount.php
- Modules/Integrations/VonHalsky/Services/VonHalskyAccountService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderNormalizer.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportService.php
- Modules/Integrations/VonHalsky/Http/Requests/SaveVonHalskyImportSettingsRequest.php
- Modules/Integrations/Allegro/Http/Controllers/AllegroProductLinkController.php
- Modules/Integrations/Prestashop/Http/Controllers/PrestashopProductLinkController.php
- Modules/Products/Models/Product.php
- Modules/Products/Http/Controllers/ProductController.php
- routes/web.php
- resources/views/products/index.blade.php
- resources/views/products/partials/integration-links.blade.php
- resources/views/integrations/von-halsky/edit.blade.php
- resources/views/integrations/von-halsky/_preview.blade.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow.

## Migracja

Addytywna 2026_10_29_030000_add_von_halsky_product_links dodaje trzy flagi
false oraz tabele konfiguracji von_halsky_product_links. Nie ma backfillu.
Unikalny indeks vh_product_offer_unique oraz vh_product_account_index maja
krotkie nazwy. FK konfiguracyjne maja cascadeOnDelete; nie usuwaja zamowien
ani historycznych snapshotow. down blokuje usuwanie uzytej konfiguracji.

Bezpieczna komenda po kopii i weryfikacji srodowiska:

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_29_030000_add_von_halsky_product_links.php --force
```

Po osobnej zgodzie wlasciciela wykonano lokalna kopie przez VACUUM INTO
ze zrodlem SQLite otwartym tylko do odczytu:

`storage/app/private/backups/halsky-2b-20261029_205400-edb75c23.sqlite`

Rozmiar: 17436672 B. SHA-256:
`34f281c50688a6c8bc2dd273b40a7dc66361e7bef9d85da19c3c9d3c793f8e1e`.
Kopia: quick_check=ok, foreign_key_check=0.

Przed zapisem potwierdzono APP_ENV=local, SQLite w katalogu projektu,
niezmienny hash kopii i migracji oraz brak czesciowo wykonanej struktury.
Uruchomiono tylko powyzsza migracje: DONE (36.05 ms). Dodano dokladnie
jeden wpis do migrations. Nowa tabela pozostaje pusta, wszystkie trzy
ustawienia sa false. Nie utworzono powiazan ani nie wykonano importu.

Lokalne dane w chwili kopii i kontroli po migracji: orders=343,
order_items=432, invoices=89, invoice_items=174, automation_runs=102,
order_events=1601, von_halsky_accounts=1, von_halsky_order_links=1.
Pelny wiersz zamowienia 343 pozostaje identyczny z kopia.

Porownano hashe danych 97 istniejacych tabel, uzywajac kolumn sprzed
migracji. Nie wykryto nieoczekiwanych zmian. Credentials i ustawienia
integracji, zamowienia, pozycje, dokumenty oraz historia zostaly zachowane.
Kontrola calej bazy: quick_check=ok, foreign_key_check=0.

Harmonogram i workery pozostawaly aktywne. Pierwsze porownanie wszystkich
98 tabel poza migrations wykrylo zmiany tla w allegro_accounts,
prestashop_accounts, cache_locks i integration_api_logs. Sprawdzono pola:
Allegro zmienilo tylko updated_at/last_order_scan_at, PrestaShop tylko
updated_at/last_order_import_scan_at/last_payment_sync_at. Logi API wzrosly
z 23793 do 23795, a liczba aktywnych blokad cache z 1 do 0. To nie sa
operacje migracji. Koncowe porownanie dopuszcza tylko wymienione kolumny
postepu synchronizacji, nowe wpisy logow i zmienne blokady cache; wszystkie
stare wpisy logow i pozostale pola kont nadal sa porownywane scisle.
Nie deklaruje sie pelnej identycznosci bajtowej aktywnej bazy z kopia.

Nie wykonano migracji na VPS. Nie potrzeba restartu kolejki: etap nie dodaje
jobow, listenerow ani zmian dzialajacych w workerach; reczny import jest HTTP.

## Testy

Konfiguracja sprawdzona: SQLite :memory:, APP_ENV=testing, fake HTTP,
fake Queue/Mail oraz prywatny testowy Storage. Bez rzeczywistych API,
importow na koncie operatora, SMS, e-maili lub transmisji KSeF.

Nowe testy HALSKY.2B: VonHalskyOrderCatalogTest 40 PASS / 295 asercji
(3.75 s) oraz Products/VonHalskyProductLinkTest 13 PASS / 97 asercji
(2.27 s). Obejmuja flagi, walidacje, trzy grupy AJAX, zakres tozsamosci,
unikalnosc, brak HTTP z CRUD, VAT 0/invalid/special, brak mapowania,
zmiane katalogu przed transakcja i pod blokadami, snapshoty zestawow,
historie po usunieciu produktu, brak backfillu oraz brak efektow ubocznych.

Koncowe testy HALSKY.1/2A/2B: 161 PASS / 977 asercji (10.82 s).
Koncowa szersza regresja importow, wszystkich testow Feature/Products,
automatyzacji, platnosci, SMS i portalu: 992 PASS / 6575 asercji (68.33 s).

Komenda (PowerShell, sciezki selekcjonowane przed uruchomieniem):

```powershell
$files = Get-ChildItem tests/Feature -Filter '*.php' | Where-Object {
    $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)'
} | Select-Object -ExpandProperty FullName
$files += (Get-ChildItem tests/Feature/Products -Filter '*.php' | Select-Object -ExpandProperty FullName)
$files += (Get-ChildItem tests/Unit/VonHalsky -Filter '*.php' | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files --compact
```

Pint na 19 plikach PHP tego etapu: PASS. php -l dla tych samych plikow: PASS.
git diff --check: PASS (istniejace ostrzezenie o CRLF product-spec).
Osobny diff --no-index --check dla 10 nowych plikow etapu: PASS.
Node sprawdzil skladnie istniejacego skryptu modala oraz walidacje ID
w aktualnym trybie RegExp v: PASS. route:list potwierdza 9 tras powiazan,
w tym 3 nowe Von Halsky. Przed wykonaniem lokalnej migracji powtorzono
53 testy HALSKY.2B na SQLite :memory:: 53 PASS / 392 asercje (4.41 s).
Kontrola wizualna nie zostala wykonana: narzedzie sterowania przegladarka
bylo niedostepne. Nie zapisywano formularzy ustawien ani powiazan.

Nie uruchamiano calego repozytorium ani niezaleznych testow KSeF. Znany
z HALSKY.2A blad OrdersAjaxListTest oczekujacy starej nazwy Szablony E-Mail
nie jest naprawiany ani objety deklaracja powyzszego zielonego zestawu.
Brak osobnego MySQL 8 do potwierdzenia rzeczywistych blokad/wyscigow;
testy zmiany katalogu przy wejsciu do transakcji potwierdzaja ponowna kontrole,
ale nie sa dowodem rownoleglej pracy dwoch polaczen MySQL.

## Weryfikacja reczna

1. Integracje > InPost Von Halsky > Zamowienia: trzy opcje sa ustawione Nie.
2. Produkty > Produkty lub Zestawy > plus w kolumnie integracji: wybierz
   aktywne konto Von Halsky. Wpisz ID oferty, zapisz, dodaj kolejna, edytuj
   i usun. Pozostale powiazania PrestaShop/Allegro pozostaja widoczne.
3. Po swiadomym wlaczeniu opcji katalogu pobierz podglad nowego zamowienia.
   Sprawdz nazwe docelowa, produkt OMS i pochodzenie VAT; kwoty ze zrodla.
4. Nie importuj PG76P11 ponownie w celu uzupelnienia mapowania: to istniejace
   lokalne zamowienie 343 i pozostanie niezmienne. Test nowego importu
   powinien dotyczyc innego zakupu i wymaga potwierdzenia ilosci.
5. Zmiana mapowania/nazwy/VAT/skladu/wagi po podgladzie ma zablokowac stary
   import. Pobierz nowy podglad. Wystawianie dokumentow nie jest czescia testu.

Nie utworzono rzeczywistych powiazan za operatora ani nie wlaczono opcji.
