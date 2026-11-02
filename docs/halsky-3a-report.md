# HALSKY.3A - informacyjne statusy Von Halsky

## Zakres i preflight

Lokalne repozytorium: main, HEAD
591127154d10b9dc61e50b3005fd5343228837de. Zachowano zastane niezacommitowane
zmiany SMS, automatyzacji i HALSKY.1/2A/2B/2C/2D. Bez operacji Git
zmieniajacych repozytorium i bez operacji na VPS.

Wlasciciel zatwierdzil lokalna kopie, addytywna migracje i normalne odczyty
statusow w tle przy juz wlaczonym pobieraniu co minute. Nie zlecono
recznego skanu konta, importu, zadania kolejki ani schedule:run.

## Zachowanie

- Znane zamowienia sa sprawdzane przez obecny harmonogram i integrations,
  z interwalem konta 1/10 minut, rowniez starsze importy reczne, np. OMS 343.
- Sprawdz teraz zleca ta sama sciezke. Bez daty nowych importow, ale ze
  znanymi zamowieniami, sprawdza tylko ich statusy. Nie wlacza pobierania.
- Informacje dodatkowe > Szczegoly zamowienia pokazuja status Von Halsky.
  CANCELED oznacza anulowanie przez kupujacego, REFUSED odrzucenie przez
  sprzedawce, REJECTED odrzucenie przez platforme. Te stany maja oddzielne
  komunikaty na karcie i podpowiedzi czerwonego !! zamiast T na liscie.
  Rozmiary/kolory ikon pozostaja z obecnego stylu OMS.
- CREATED i ACCEPTED sa zwyklymi statusami informacyjnymi. UNKNOWN oraz
  przyszly poprawny kod wymagaja sprawdzenia, nie udaja anulowania.
- Rzeczywista zmiana ma jeden order_events. Powtorzony odczyt nie tworzy
  wpisu ani zdarzen importu/platnosci/automatyzacji. Historia pobierania
  zachowuje jeden wiersz na zakup i limit ostatnich 10 zamowien; dla
  starszego importu moze powstac pierwszy wiersz historii.
- Status OMS, kwoty, wplaty, pozycje, adresy, uwagi, dokumenty i przesylki
  pozostaja bez zmian. Anulowany zakup moze nadal miec lokalna wplate
  i wyslana przesylke; prezentacja tego nie ukrywa.
- Blad zachowuje ostatni status. Komunikat nie zawiera payloadu API,
  danych klienta ani tokenow. Pozniejszy przebieg ponawia odczyt, a klient
  respektuje wspolne Retry-After po 429.
- GET karty/listy/konfiguracji i orders.state nie odpytuje marketplace.
  Otwarte zamowienie odswieza status/ostrzezenie przez obecny mechanizm OMS.

## Kontrakt i bezpieczenstwo

Sprawdzono publiczna dokumentacje InPost Gokart API 1.7.6:
[dokumentacja dostawcy](https://inpsa-api-portal.inpost-group.com/gokart-api.html).
OrderStatus obejmuje CREATED, ACCEPTED, CANCELED, REFUSED, REJECTED, UNKNOWN.
updatedAt jest opcjonalne. Odczyt uzywa dotychczasowego api:orders:read;
nie dodano scopes, operacji zapisu ani polaczenia z kontem do testow.

Job ma unikalnosc konto/rewizja/link (180 s), timeout 80 s i jedna probe.
Limit to 100 zlecen na przebieg, od najstarszej rezerwacji, puste pierwsze.
Niezakonczone zlecenie nie jest zastepowane przed 180 s. Generacja z
mikrosekundami i ponowna kontrola pod blokadami odrzucaja stara odpowiedz
po zmianie konfiguracji lub nowym zleceniu. Ukonczony job i spozniony blad
nie nadpisuja sukcesu. Rewizja ustawien, tozsamosc konta/srodowiska/
organizacji oraz zgodnosc source/external_id sa kontrolowane ponownie.
Kosz, usuniecie i rozlaczenie nie pozwalaja aktualizowac zamowienia.
Granica interwalu ma precyzje sekund zgodna z obecna rezerwacja skanu
konta; mikrosekundy pozostaja w tozsamosci zlecenia. Zapobiega to pomijaniu
kolejnego przebiegu przez drobne roznice momentu startu schedulera.

Siec dziala poza transakcja. Zapis blokuje konto, Order i link, atomowo
aktualizuje metadane linku, historie i ewentualne zdarzenie. Nie zapisuje
Order ani nie emituje zdarzen domenowych automatyzacji. Starszy jawny
updatedAt jest odrzucany. Przy jego braku nie da sie udowodnic kolejnosci
stanow u dostawcy; OMS opiera sie na generacji wlasnego odczytu. Nie
fabrykuje external_status_updated_at z daty utworzenia. Wymagalna data
w istniejacej historii pobierania zachowuje fallback createdAt tylko przy
utworzeniu wiersza; nie jest to deklaracja daty zmiany statusu.

## Pliki

Nowe:

- database/migrations/2026_10_30_010000_add_von_halsky_order_status_sync.php
- Modules/Integrations/VonHalsky/Enums/VonHalskyOrderStatus.php
- Modules/Integrations/VonHalsky/Jobs/SyncVonHalskyOrderStatusJob.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderStatusSyncService.php
- resources/views/orders/partials/von-halsky-status.blade.php
- resources/views/orders/partials/von-halsky-warning.blade.php
- tests/Feature/VonHalskyOrderStatusSyncTest.php
- docs/halsky-3a-report.md

Zmienione wzgledem lokalnego HALSKY.2D:

- Modules/Integrations/VonHalsky/Models/VonHalskyOrderLink.php
- Modules/Integrations/VonHalsky/Services/VonHalskyOrderImportDispatcher.php
- Modules/Integrations/VonHalsky/Http/Controllers/VonHalskySettingsController.php
- app/Http/Controllers/OrderStatusController.php
- resources/views/integrations/von-halsky/edit.blade.php
- resources/views/components/order-additional-information.blade.php
- resources/views/orders/partials/list-indicators.blade.php
- resources/views/orders/show.blade.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Bez zmian tras, schedulera, env, zaleznosci,
normalizatora/importera finansow i wspolnego silnika automatyzacji.

## Migracja i lokalna baza

Migracja dodaje tylko nullable external_status_updated_at,
last_status_checked_at, last_status_sync_requested_at (datetime6) i
last_status_sync_error do von_halsky_order_links. Bez zmian FK/indeksow
i historycznych wartosci. down odmawia usuniecia uzywanych metadanych.
Gotowosc wymaga wszystkich kolumn i zapisu migracji.

Po kopii i kontroli docelowej bazy uruchamia sie tylko ten plik:

```powershell
& C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_30_010000_add_von_halsky_order_status_sync.php --force
```

Wykonano lokalnie po zgodzie wlasciciela: batch 117. Kopia przez SQLite
VACUUM INTO (spojny odczyt aktywnej bazy):

```text
storage/app/private/backups/halsky-3a-20261030_122702-16bab569.sqlite
18104320 bajtow
SHA256 bedc748ed18b687c5dd51e1eff25171344dad11d80ab353e90e2067ea6f1c630
```

Kopia i baza po migracji: quick_check oraz foreign_key_check PASS.
W kopii: 345 orders, 434 order_items, 89 invoices, 174 invoice_items,
1606 order_events, 102 automation_runs i jeden link Von Halsky.
Porownano 101 starych tabel po ich starych kolumnach: 94 identyczne.
Roznice to wpis migracji, cache_locks, dopisane integration_api_logs,
znaczniki skanow kont Allegro/PrestaShop/VonHalsky i synced_at/next_sync_at/
updated_at w order_returns. Sa to normalne odczyty dzialajacego tla;
nie deklaruje sie identycznosci bajtowej calej aktywnej bazy. Orders,
pozycje, dokumenty, serie/liczniki, dane platnosci i shipments byly identyczne.
Osobno potwierdzono caly rekord 343 i jego pozycje bez zmian.

Wlasciciel potwierdzil restart lokalnego Main Queues. Odczytowo potwierdzono
naturalny wynik harmonogramu: 343 ma ACCEPTED, last_status_checked_at
2026-10-30 12:28:06.936084 i brak bledu; powstal jeden wiersz imported /
ACCEPTED, bez zdarzenia zmiany statusu (status nie zmienil sie). Nie zlecano
recznego wywolania API. Nie uruchamiac rollbacku na uzywanej strukturze.
Wlasciciel potwierdzil tez koncowy restart po poprawce interwalu. Kolejny
odczyt 343: 2026-10-30 12:34:05.443822, ACCEPTED, brak bledu. Na koncu
dzialajace tlo dodalo Allegro 353 i jego pozycje 466. Wszystkie wczesniej
istniejace orders/order_items, w tym 343, pozostaly identyczne z kopia;
dokumenty, serie/liczniki, sloty, shipments i automation_runs takze identyczne.

## Testy

phpunit.xml: APP_ENV=testing, SQLite :memory:, array cache/mail/sesje.
Fake HTTP/Queue/Mail/Storage, bez rzeczywistych polaczen KSeF/marketplace.
Test zachowania dokumentow wystawia dokument tylko na bazie testowej.

Baseline zakresu Von Halsky + AutomationOrderModificationTest przed
zmianami: 269 PASS / 1477 asercji (18.60 s). Pierwsza regresja: 316 PASS /
1794 asercje (25.03 s), szersza po Pint: 1117 PASS / 7284 asercje (85.15 s).
Po dopracowaniu ulozenia ikony oraz granicy interwalu do sekund harmonogramu:
49 nowych przypadkow statusow PASS / 319 asercji (7.29 s). Wliczono dwa
testy roznicy mikrosekund dla interwalow 1/10 min. Bez zmian oczekiwan
bazowych. Koncowa powtorka zakresu: 318 PASS / 1796 asercji (28.50 s).
Koncowa szersza regresja: 1119 PASS / 7286 asercji (90.42 s).
Szerszy zakres obejmuje automatyzacje, Allegro/PrestaShop/VonHalsky,
platnosci, SMS, portal i Feature/Products, nie caly zestaw repozytorium.

Komendy:

```powershell
& C:\xampp\php\php.exe artisan test tests/Feature/VonHalskyOrderStatusSyncTest.php tests/Feature/VonHalskyOrderAutomationTest.php tests/Feature/VonHalskyAutomaticOrderImportTest.php tests/Feature/VonHalskySettingsTest.php tests/Feature/VonHalskyOrderImportTest.php tests/Feature/VonHalskyOrderPreviewTest.php tests/Feature/VonHalskyOrderCatalogTest.php tests/Feature/Products/VonHalskyProductLinkTest.php tests/Unit/VonHalsky tests/Feature/AutomationOrderModificationTest.php --compact

$files = Get-ChildItem tests/Feature -Filter '*.php' | Where-Object {
    $_.Name -match '^(VonHalsky|Automation|AutomaticActions|AllegroSettings|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowSettings|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)'
} | Select-Object -ExpandProperty FullName
$files += (Get-ChildItem tests/Feature/Products -Filter '*.php' | Select-Object -ExpandProperty FullName)
$files += (Get-ChildItem tests/Unit/VonHalsky -Filter '*.php' | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files --compact
```

Pint dla 9 plikow PHP: PASS. php -l dla tych samych: PASS.
git diff --check: PASS (zastane ostrzezenie CRLF dla product-spec).
Nowe pliki sprawdzono dodatkowo przez diff --no-index --check.
Lokalny podglad 343 w przegladarce: nowa sekcja i status widoczne,
bez bledow/ostrzezen konsoli. Ostatnia zmiana ukladu ma dodatkowo 47 PASS /
317 asercji (8.13 s); pozniejsza zmiana dotyczyla tylko interwalu i testow.
Nie uruchamiano niezaleznych testow KSeF ani napraw innych modulow.

## Kontrola reczna i ograniczenia

1. Po migracji wczytac kod przez restart tylko lokalnego Main Queues.
   Nie restartowac KSeF, globalnej kolejki ani niczego na VPS.
2. Otworzyc OMS 343 i Informacje dodatkowe > Szczegoly zamowienia.
   Przed pierwszym odczytem ikona informuje, ze status pochodzi z importu.
3. Pozwolic dzialac juz wlaczonemu harmonogramowi. Sprawdzic status,
   jeden wiersz historii pobierania i niezmiennosc kwot/dokumentow.
   Brak zmiany statusu nie tworzy zdarzenia w historii zamowienia.
4. Naturalne przyszle anulowanie/odrzucenie pokaze komunikat i czerwone !!.
   Nie anulowac zakupu dla testu UI; te warianty sa testowane na fake API.
5. Sprawdz teraz jest swiadomym odczytem konta; nie jest potrzebne do
   odbioru wdrozenia i nie nalezy go klikac przypadkowo w kontroli wizualnej.

Nie sprawdzono rzeczywistego anulowania, transakcji MySQL 8 ani urzadzen
mobilnych. SQLite :memory: nie dowodzi zachowania blokad MySQL. Nie dodano
synchronizacji finansow, statusow OMS i wysylki statusow do platformy.
Brak ilosci w API nadal wymaga ich potwierdzenia przy imporcie. Normalny
harmonogram moze po wdrozeniu wykonywac odczyty zgodnie z ustawieniem konta
i zgoda wlasciciela. Oba lokalne restarty Main Queues zostaly potwierdzone.
Nie wykonywano globalnego queue:restart ani restartu KSeF/schedulera.
