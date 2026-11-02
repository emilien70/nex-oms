# HALSKY.2D - automatyczne akcje Von Halsky

## Zakres

Preflight: main, HEAD 591127154d10b9dc61e50b3005fd5343228837de.
Zachowano wszystkie zastane zmiany SMS, automatyzacji i HALSKY.1/2A/2B/2C.
Wlasciciel odrzucil dodatkowy przelacznik automatyzacji oraz osobne blokady
oczekujacych zdarzen Von Halsky. Zastosowano istniejacy mechanizm OMS,
zgodny z Allegro i PrestaShop. Bez commit/push i operacji na VPS.

## Zachowanie

- Nowy poprawny import reczny i automatyczny przekazuje OrderCreated oraz
  OrderDataCompleted po zatwierdzeniu zapisu calego zamowienia. Przy obecnych
  wymaganiach importu oba dotycza tego samego kompletnego, oplaconego zakupu.
- Lista zrodel obejmuje Dowolne konto InPost Von Halsky i nazwe konta.
  Trwale identyfikatory to von_halsky i von_halsky:ID, nie nazwa integracji.
  Mozna laczyc je z Allegro/PrestaShop. Starszy warunek source tez dziala.
- Wspolny engine uruchamia aktywne reguly spelniajace warunki. Wszystkie
  zrodla oraz brak warunku zrodla obejmuja Von Halsky. Nie dodaje sie
  dodatkowej zgody, ustawienia konta ani osobnego mechanizmu wykonania.
- Nie emituje sie zdarzen przy samym podgladzie lub wykryciu zakupu,
  niepotwierdzonej ilosci, niepoprawnych danych albo wycofaniu transakcji.
- Ponowny import otwiera istniejace zamowienie, bez powtorzenia zdarzen.
  Deterministyczne ID i unikalnosc automation_runs chronia jak w innych
  integracjach przed drugim runem tej samej reguly dla tego samego zdarzenia.
  Istniejace zabezpieczenia krokow i zewnetrznych efektow pozostaja wspolne.
- Nie dodano anulowania poprawnego zdarzenia z powodu pozniejszej zmiany
  nazwy konta, testu polaczenia lub jego wylaczenia. Zabezpieczenia samego
  importu HALSKY.2C pozostaja niezmienne.
- Potwierdzenie ilosci pozostaje. Usunieto confirmed_without_automations,
  checkbox i komunikaty obiecujace brak akcji. Komunikat sukcesu nie twierdzi,
  ze wykonano reguly, gdy zadna nie pasowala. Nowe linki i historia maja
  automations_suppressed=false, starsze dane zachowuja wartosc historyczna.
- Dodano import_version=2 do istniejacego podpisu podgladu, bo stary ekran
  potwierdzal import bez akcji. Starszy podglad nalezy pobrac ponownie.
  Nie zmienia to kodow portalu ani zawartosci istniejacych zamowien.
- Nie uruchomiono zaleglych zdarzen, w tym dla OMS 343. Pozniejsze zwykle
  zmiany statusu nadal podlegaja normalnym regulom OMS.

Nie dodano nowego zdarzenia platnosci, transmisji marketplace, akceptowania
zakupow, aktualizacji finansow ani zmiany reguly brakujacych ilosci.
Osobne reguly podpiete pod Utworzono i Pobrano kompletne dane pozostaja
osobnymi regulami: moga swiadomie wykonac podobne akcje dwukrotnie.

## Pliki

Nowe:

- tests/Feature/VonHalskyOrderAutomationTest.php
- docs/halsky-2d-report.md

Zmienione wzgledem lokalnego HALSKY.2C:

- Modules/Automation/Services/AutomationOrderSources.php
- Modules/Automation/Services/AutomationCatalog.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportService.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderNormalizer.php
- Modules/Integrations/VonHalsky/Http/Requests/ImportVonHalskyOrderRequest.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskyOrderImportController.php
- resources/views/integrations/von-halsky/_preview.blade.php
- tests/Feature/VonHalskyOrderImportTest.php
- tests/Feature/VonHalskyAutomaticOrderImportTest.php
- tests/Feature/VonHalskyOrderPreviewTest.php
- tests/Feature/VonHalskyOrderCatalogTest.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Usunieto szosty argument metody import, ktory wymagano
wylacznie jako potwierdzenie pomijania akcji; przeanalizowano i poprawiono
wszystkie lokalne wywolania. Chroniony endpoint POST zachowuje swoj adres.
Nie zmieniano .env, zaleznosci, migracji ani danych operatora.

## Migracje

Brak nowych migracji. Nie trzeba uruchamiac artisan migrate ani wykonywac
backfillu. Istniejace pole automations_suppressed zachowuje historie.

## Weryfikacja

Sprawdzono phpunit.xml i brak nadpisujacych zmiennych DB w sesji:
SQLite :memory:, APP_ENV=testing, queue sync, array mail/cache/session.
Testy uzywaja fake HTTP/Queue/Mail i testowego prywatnego Storage.
Nie wykonano rzeczywistego skanu/importu, SMS ani polaczenia do KSeF.

Baseline przed zmianami: 248 PASS / 1305 asercji (17.81 s), zakres
HALSKY.1/2A/2B/2C i AutomationOrderModificationTest.
Nowy zestaw HALSKY.2D: 21 PASS / 173 asercje (3.25 s).
Regresja tego samego zakresu wraz z HALSKY.2D: 269 PASS / 1477 asercji
(17.93 s). Usunieta asercja dotyczy wymagalnosci dawnego potwierdzenia
bez automatyzacji. Szersza regresja: 1070 PASS / 6967 asercji (80.06 s).
Obejmuje Allegro/PrestaShop/VonHalsky, automatyzacje, platnosci, SMS,
portal i wszystkie Feature/Products. Nie jest to pelny zestaw repozytorium.

Komendy:

```powershell
& C:\xampp\php\php.exe artisan test tests/Feature/VonHalskyOrderAutomationTest.php --compact

& C:\xampp\php\php.exe artisan test tests/Feature/VonHalskyOrderAutomationTest.php tests/Feature/VonHalskyAutomaticOrderImportTest.php tests/Feature/VonHalskySettingsTest.php tests/Feature/VonHalskyOrderImportTest.php tests/Feature/VonHalskyOrderPreviewTest.php tests/Feature/VonHalskyOrderCatalogTest.php tests/Feature/Products/VonHalskyProductLinkTest.php tests/Unit/VonHalsky tests/Feature/AutomationOrderModificationTest.php --compact

$files = Get-ChildItem tests/Feature -Filter '*.php' | Where-Object {
    $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)'
} | Select-Object -ExpandProperty FullName
$files += (Get-ChildItem tests/Feature/Products -Filter '*.php' | Select-Object -ExpandProperty FullName)
$files += (Get-ChildItem tests/Unit/VonHalsky -Filter '*.php' | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files --compact
```

Pint dla 11 zmienionych plikow PHP: PASS. php -l dla tych samych plikow:
PASS. git diff --check: PASS (zastane ostrzezenie CRLF dla product-spec).
Szersza regresja zostala wykonana po Pint; nie bylo pozniejszych zmian PHP.
Nie naprawiano niezaleznych testow ani innych funkcjonalnosci OMS.

## Lokalne procesy

Nie wykonywano globalnego queue:restart, restartu KSeF ani schedulera.
Proba odczytu okien nie udostepnila panelu NEX-OMS. Wlasciciel odpowiedzial,
ze sam zrestartuje lokalne Main Queues. Nie zglaszal jeszcze zakonczenia,
dlatego restart pozostaje do potwierdzenia; agent nie zatrzymywal procesow.
Ustawienia pobierania i reguly operatora pozostaly niezmienione.

## Kontrola reczna

1. Otworz Zamowienia > Automatyczne akcje. Sprawdz Von Halsky w wyborze
   zrodel oraz mozliwosc zaznaczenia go wraz z innymi kanalami/kontami.
2. Przed przyszlym rzeczywistym importem przejrzyj aktywne reguly Wszystkie
   zrodla i reguly bez warunku zrodla: po wdrozeniu obejma nowe zakupy.
3. W podgladzie Von Halsky ma pozostac potwierdzenie ilosci, bez checkboxa
   importu bez akcji. Odswiez podglad otwarty przed aktualizacja.
4. Dalsza kontrola na rzeczywistym koncie wymaga swiadomego importu nowego
   zakupu. PG76P11/OMS 343 jest juz importowany: jego ponowne otwarcie nie
   sluzy do ponownego uruchamiania zdarzen utworzenia/kompletnych danych.
5. Przy nowym imporcie sprawdz zwykla Historie automatycznych akcji.
   Brak aktywnej pasujacej reguly nie jest bledem. Kolejny odczyt nie ma
   tworzyc drugiego runu dla tego samego zdarzenia i tej samej reguly.

## Ograniczenia

Nie zweryfikowano rzeczywistego ruchu marketplace, fizycznego SMS ani
wspolbieznosci MySQL. Obecne API bez ilosci nadal wymaga ich potwierdzenia.
Testy KSeF i zmiany niezaleznych modulow pozostaja poza zakresem.
