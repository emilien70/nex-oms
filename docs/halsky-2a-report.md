# HALSKY.2A - reczny import zamowien Von Halsky

## Zakres i decyzje

Reczny import kompletnego, zaakceptowanego i oplaconego zamowienia po
wczesniejszym podgladzie, z ponownym odczytem API przed zapisem.
Wszystkie importy tego etapu sa bez automatycznych akcji. Nie ma schedulera,
automatycznego importu, akceptacji/odrzucania w marketplace, eksportu statusow,
przesylek, faktur lub zwrotow. Aktywna integracja Base pozostaje bez zmian.

Wlasciciel podal PG76P11 do weryfikacji na polaczonym koncie produkcyjnym.
Odczyt potwierdzil ACCEPTED, wartosc 195.00 PLN, dostawe APM za 0.00 PLN
oraz pojedynczy wpis BLIK_CODE z ID i data platnosci. API nie zwrocilo ilosci
pozycji ani odrebnej kwoty wpisu platnosci. Wlasciciel potwierdzil jedna sztuke
i zgodzil sie na reczne podawanie/potwierdzanie ilosci do czasu wyjasnienia
kontraktu z InPost. Nie zastosowano domyslnego quantity=1.

Osobno zatwierdzono lokalna kopie, addytywna migracje i jeden lokalny import
PG76P11 bez automatyzacji. Nie jest to zgoda na zmiany Von Halsky lub VPS.
Preflight: main, HEAD 591127154d10b9dc61e50b3005fd5343228837de.
Zastane lokalne zmiany HALSKY.1, SMS i automatyzacji zachowano.

## Kontrakt importu

- Przycisk Importuj do OMS korzysta z POST/CSRF, limitu zadan, obu jawnych
  potwierdzen i dowodu podgladu waznego 10 minut w tej samej sesji.
- Dowod zawiera tylko ID konta/zamowienia, wersje, hash i termin, nie PII.
  Zmiana konfiguracji uniewaznia dowod. Kwoty i adresy nie pochodza z POST.
- Brak ilosci API wymaga recznego wpisania dodatniej liczby calkowitej
  1..999999 dla pozycji. Ilosci z API nie mozna nadpisac formularzem.
- Sprawdzane jest ceny jednostkowe x ilosci + dostawa == finalPrice,
  bez dopasowywania rabatow lub zgadywania korekt groszowych. Rozbieznosc
  blokuje caly zapis. Nieobslugiwany status, waluta, kraj lub wielokrotna
  platnosc rowniez blokuja import. W tym etapie tylko PLN i krajowy kontekst.
- Dokladne leksymy amount z JSON sa zachowane przed obliczeniami. Standardowy
  parser PHP waliduje dokument; osobny przebieg tokenow chroni liczby kwot
  przed utrata precyzji, nie zmieniajac typow ID, flag ani ilosci. BigDecimal
  wykonuje obliczenia bez float i bez zaokraglania wartosci ponad 2 miejsca.
- Hash HMAC kanonizuje klucze obiektow, ale zachowuje kolejnosc pozycji.
  Zmiana danych API lub ustawien wymaga nowego podgladu. Szyfrowane klucze
  API nie trafiaja do hasha lub historii importu.
- Status poczatkowy pochodzi z aktualnego slownika OMS, domyslnie Nowe.
  Nie ma sztywnej kopii slownika w integracji. Nieistniejacy status blokuje
  zapis; usuniety FK nie moze umozliwic zapisu do niewidocznego statusu.
- Domyslny VAT jest opcjonalny, poczatkowo pusty. Korzysta z istniejacego
  OrderImportVatResolver. Brak VAT nie oznacza 0%, a jawne 0 jest zachowane.
  Nie ma automatycznego ustawienia 23%, powiazan katalogu ani dopasowan EAN.
- Oplacenie jest deklaracja kanalu: ACCEPTED i jeden poprawny wpis platnosci.
  paid_amount zamowienia przyjmuje uzgodniona wartosc zamowienia; nie tworzy
  sie fikcyjnej kwoty indywidualnej transakcji. Historia wyraznie opisuje
  pochodzenie kwoty. Nie importuje sie rozliczen sprzedawcy ani refundacji.
- Dane kontaktowe, adres, punkt odbioru, komentarz i zadanie faktury sa
  kopiowane do zwyklego zamowienia. Bez danych do faktury stosuje sie dane
  kupujacego, nigdy adres punktu odbioru jako adres nabywcy ani fikcyjny NIP.
- Kolejny import tego samego srodowisko + organizacja + ID otwiera istniejace
  zamowienie. Nie aktualizuje pozycji, wplat, uwag ani wystawionych dokumentow.
  Kosz i trwale usuniecie pozostawiaja tombstone i blokuja odtworzenie.
- API dziala poza transakcja. Zapis ponownie blokuje konto/status i sprawdza
  wersje konfiguracji. Order, pozycje, powiazanie i zdarzenie historii sa
  atomowe; unikalny indeks stanowi ostateczna ochrone przed duplikatem.

## Automatyzacje i dokumenty

Importer nie emituje OrderCreated, OrderDataCompleted ani OrderPaymentRegistered.
Zapisuje zdarzenie order_events z informacja automations_suppressed=true.
Nie jest to globalne wylaczenie regul OMS: pozniejsza swiadoma zmiana statusu
lub inne czynnosci uzytkownika nadal moga uruchamiac zwykle automatyzacje.
Nie uzyto Order::withoutEvents; obserwator portalu moze normalnie przygotowac
link klienta po zatwierdzeniu transakcji, bez wysylania wiadomosci.

Etap nie dodaje Von Halsky do wyboru zrodel automatycznych akcji. To zadanie
na pozniejsze, swiadome uruchomienie automatyzacji tego kanalu. Nie zmieniono
procesow FA(3), KSeF, numeracji, VAT dokumentow ani transportu platnosci.

## Widoki

Nowe ustawienia w zakladce Zamowienia oraz ilosci/potwierdzenia w podgladzie.
Po imporcie: standardowa karta i lista zamowien OMS, zrodlo nazwa konta
(InPost Von Halsky), numer transakcji, data zakupu i historia potwierdzenia
oplacenia. Komentarz kupujacego jest u gory wymiany wiadomosci, escapowany,
z ikona koperty na liscie. Ikony kompletnosci/platnosci/przesylki pozostaja
wspolne. Dziala filtr zrodla i etykieta zmiennej zrodlo_zamowienia.

## Pliki tego etapu

Utworzone:

- Modules/Integrations/VonHalsky/Support/VonHalskyOrderJson.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderNormalizer.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportService.php
- Modules/Integrations/VonHalsky/Models/VonHalskyOrderLink.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskyOrderImportController.php
- Modules/Integrations/VonHalsky/Http/Requests/ImportVonHalskyOrderRequest.php
- Modules/Integrations/VonHalsky/Http/Requests/SaveVonHalskyImportSettingsRequest.php
- database/migrations/2026_10_29_020000_add_von_halsky_manual_order_import.php
- tests/Feature/VonHalskyOrderImportTest.php
- tests/Unit/VonHalsky/VonHalskyOrderJsonTest.php
- docs/halsky-2a-report.md

Zmienione:

- Modules/Integrations/VonHalsky/Services/VonHalskyClient.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderPreviewService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyAccountService.php
- Modules/Integrations/VonHalsky/Models/VonHalskyAccount.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskySettingsController.php
- app/Models/Order.php
- app/Http/Controllers/OrdersController.php
- app/Services/OrderVariableService.php
- routes/web.php
- resources/views/integrations/von-halsky/edit.blade.php
- resources/views/integrations/von-halsky/_preview.blade.php
- resources/views/orders/index.blade.php
- resources/views/orders/show.blade.php
- resources/views/orders/partials/order-info-view.blade.php
- resources/views/orders/partials/list-indicators.blade.php
- resources/views/orders/partials/list-styles.blade.php
- resources/views/orders/partials/buyer-comments.blade.php
- resources/views/orders/partials/payments.blade.php
- tests/Feature/VonHalskyOrderPreviewTest.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Nie wykonano commit, push ani deploymentu.

## Migracja i lokalna kontrola

Nowa migracja tworzy von_halsky_order_links i dodaje dwa nullable pola
konfiguracji konta. Bez backfillu; zachowuje produkcyjne credentials.
Powiazania historyczne maja nullOnDelete, tozsamosc zrodla pozostaje po
usunieciu zamowienia/konta. payment_evidence jest szyfrowane. Nazwy indeksow
sa jawne i krotkie (vh_order_identity_unique, vh_order_link_order_unique).
down odmawia usuniecia historii lub uzytych ustawien.

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_29_020000_add_von_halsky_manual_order_import.php --force
```

Za zgoda wlasciciela wykonano kopie SQLite przez VACUUM INTO ze zrodlem
otwartym tylko do odczytu:

`storage/app/private/backups/halsky-2a-20261029_160955-078c6802.sqlite`

Rozmiar 16740352 B, SHA-256:
`32bf1c6fdcaa6ed7fdcbadb9d8ecd5c08e667a40971d7e9b927fe96eaed557b7`.
Kopia: quick_check=ok, foreign_key_check=0. Uruchomiono tylko nowa migracje:
PASS. Zachowano credentials i aktywne produkcyjne polaczenie.

PG76P11 zaimportowano jeden raz przez nowy formularz do lokalnego Order 343:
1 sztuka, 195.00 PLN, paid, paid_amount=195.00, VAT=null (API i domyslna
stawka integracji puste). Zapisano manual w quantities_snapshot i
automations_suppressed=true. Portal przygotowal link, bez wyslania go klientowi.

Kontrola po imporcie: orders 335->336, order_items 424->425,
order_events 1592->1593, von_halsky_order_links 0->1. Bez zmian invoices=89,
invoice_items=174, automation_runs=102. Hashy wszystkich istniejacych wierszy
orders/order_items/invoices/invoice_items/automation_runs oraz szyfrogramu
client_secret porownano z kopia: identyczne. quick_check=ok, FK violations=0.
Nie wykonano zadnej operacji na VPS ani zadnego zapisu do API Von Halsky.

## Weryfikacja

Testy: SQLite :memory:, fake HTTP, bez rzeczywistych wywolan finansowych,
KSeF, SMS lub maili. Obejmuja precyzje JSON, ilosci, sumy, zmiane zrodla,
konfiguracji, powtorzenia, Kosz/usuniecie, rollback transakcji, dynamiczny
status, VAT, historie, ochrone sesji i brak automatyzacji.
Regresja integracji/importow/automatyzacji/platnosci/SMS/portalu:
876 testow / 5669 asercji, PASS (63.17 s). Rozszerzenie o wszystkie testy
Order*: 1082 PASS, 1 FAIL / 7175 asercji (84.79 s). Jedyny blad to
OrdersAjaxListTest::test_orders_list_exposes_ajax_state_metadata: oczekuje
Szablony E-Mail zamiast istniejacego Szablony E-mail/SMS. Test oraz layout
sa niezmienione w tym etapie i juz HEAD 5911271 zawiera ta rozbieznosc.
Nie poprawiano jej w ramach Von Halsky. Nie wykonano osobnego pelnego
baseline na HEAD, wiec wynik nie jest deklaracja pelnej regresji repozytorium.

Testy HALSKY.1/2A po ostatniej korekcie ikonki zrodla: 108 testow /
585 asercji, PASS (8.35 s). Ostatnia zmiana dotyczyla tylko klasy ikonki,
jej CSS i dodatkowej asercji; szersza regresja powyzej poprzedzala te zmiane.

Pint --test: PASS. PHP lint 24 plikow PHP: PASS. git diff --check: PASS
(zastane ostrzezenie CRLF w product-spec.md, bez masowej zmiany EOL).
W lokalnej przegladarce potwierdzono pusta wymagana ilosc, zaznaczenie obu
potwierdzen, poprawny formularz przy 390 px i rzeczywisty import do /orders/343.
Widok pokazuje numer transakcji i historie oplacenia wedlug Von Halsky.
Na przefiltrowanej liscie potwierdzono osobna ikonke zrodla, zielone T i $
oraz szara ikonke przesylki (brak utworzonej przesylki OMS).
Nie uruchamiano zestawu testow KSeF, nie wysylano dokumentow ani SMS.

Komenda zestawu przechodzacego regresje (PowerShell, katalog projektu):

```powershell
$files = Get-ChildItem tests/Feature -Filter '*.php' |
    Where-Object { $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)' } |
    Select-Object -ExpandProperty FullName
$files += (Resolve-Path tests/Feature/Products/InvoiceStockAutomationTest.php).Path
$files += (Get-ChildItem tests/Unit/VonHalsky -Filter '*.php' | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files --compact
& C:\xampp\php\php.exe vendor/bin/pint --test Modules/Integrations/VonHalsky tests/Feature/VonHalskyOrderImportTest.php tests/Feature/VonHalskyOrderPreviewTest.php tests/Unit/VonHalsky database/migrations/2026_10_29_020000_add_von_halsky_manual_order_import.php app/Models/Order.php app/Http/Controllers/OrdersController.php app/Services/OrderVariableService.php routes/web.php
git diff --check
```

Recznie:

1. Integracje -> Von Halsky -> Zamowienia: ustaw status i ewentualny VAT.
2. Pobierz podglad wskazanego zamowienia. Ilosc z API nieobecna: pole puste.
3. Potwierdz faktyczne ilosci i import bez automatyzacji. Nie wpisuj cen.
4. Importuj do OMS. Sprawdz kwote, platnosc, adres/punkt, komentarz i zrodlo.
5. Ponowny import ma otworzyc ten sam numer OMS, bez ponownej zmiany wplat.
6. Do testow Kosza/bladnych ilosci uzywaj osobnych danych testowych, nie
   usuwaj historycznego zakupu w marketplace.

Ograniczenia: brak MySQL 8 do rzeczywistego testu rownoleglych transakcji;
brak automatycznych synchronizacji pozniejszych zmian/zwrotow. Nie ma
podstaw do automatycznego ustalenia ilosci w obecnym kontrakcie API.
Przed automatyzacjami trzeba uzgodnic go z InPost i potwierdzic zasady
rownoleglego uzywania Base. Odczyty produkcyjne tego etapu nie zmieniaja
integratora, ofert, statusow ani przyjecia zamowienia u dostawcy.
Ten etap uruchamia import przez HTTP i nie dodaje jobow. Nie restartowano
workerow ani nie wymuszano harmonogramu; nie wlaczono automatyzacji Von Halsky.
