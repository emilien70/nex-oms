# Sledzenie przesylek zwrotnych

Implementacja: 2026-11-02; koncowa weryfikacja: 2026-11-03.
Wdrozenie lokalne, bez commit/push i bez operacji na VPS.

## Zakres

- Paczki wychodzace i zwrotne w jednej tabeli na karcie zwrotu; paczki zwrotne takze na karcie zamowienia.
- Strzalka z podpowiedzia Przesylka zwrotna, pasek postepu, status, odnosnik do zwrotu i historia po 20 zdarzen.
- Reczne dodanie/edycja numeru na karcie dowolnego zwrotu. Nie jest to nadanie etykiety.
- Odczyt InPost bez tokenu albo przez Allegro z wlasciwego konta. Brak historii nie oznacza doreczenia.
- Kontrole w tle co okolo godzine, kolejka shipments-sync, bez HTTP do kuriera podczas otwierania strony.
- Zdarzenie return.parcel_status_changed, warunki statusu paczki/zwrotu i akcja change_return_status. Domyslnie wszystkie paczki doreczone, opcjonalnie dowolna paczka zdarzenia.
- Ponowna kontrola relacji i danych przy wykonaniu, atomowy receipt kroku, deduplikacja historii i zdarzen. Brak automatycznego otwierania Zakonczonych/Odrzuconych.
- Nie utworzono ani nie wlaczono reguly; bez refundacji, dokumentow, wiadomosci i zmian magazynowych.

## Pliki

Nowe:

- database/migrations/2026_11_02_010000_add_return_parcel_tracking.php
- app/Casts/UtcDateTime.php
- app/Models/OrderReturnParcelEvent.php
- app/Events/ReturnParcelStatusChanged.php
- app/Services/OrderReturnParcelService.php
- app/Services/ReturnParcelTrackingService.php
- app/Http/Controllers/OrderReturnParcelController.php
- app/Http/Requests/SaveOrderReturnParcelRequest.php
- Modules/Shipments/Services/ReturnParcelTrackingClient.php
- Modules/Shipments/Jobs/RefreshReturnParcelJob.php
- Modules/Automation/Services/AutomationReturnContext.php
- Modules/Automation/Services/AutomationReturnStatusService.php
- resources/views/returns/_parcel-row.blade.php
- resources/views/returns/_parcel-form.blade.php
- resources/views/returns/_parcel-history.blade.php
- resources/views/returns/_parcel-assets.blade.php
- resources/views/returns/parcel-history.blade.php
- resources/views/returns/_automation-history.blade.php
- tests/Feature/ReturnParcelTrackingTest.php
- docs/return-parcel-tracking-report.md

Zmienione:

- app/Models/OrderReturnParcel.php
- app/Http/Controllers/OrderReturnController.php
- app/Http/Controllers/OrdersController.php
- app/Http/Controllers/OrderStatusController.php
- app/Providers/AppServiceProvider.php
- Modules/Integrations/Allegro/Services/AllegroClient.php
- Modules/Integrations/Allegro/Services/AllegroReturnImportService.php
- Modules/Automation/Http/Requests/SaveAutomationRuleRequest.php
- Modules/Automation/Listeners/DispatchAutomationEvent.php
- Modules/Automation/Models/AutomationRun.php
- Modules/Automation/Services/AutomationEngine.php
- Modules/Automation/Services/AutomationConditionEvaluator.php
- Modules/Automation/Services/AutomationCatalog.php
- Modules/Automation/Services/AutomationActionExecutor.php
- Modules/Automation/Services/OrderAutomationHistory.php
- resources/views/orders/automatic-actions.blade.php
- resources/views/orders/show.blade.php
- resources/views/returns/show.blade.php
- routes/web.php
- routes/console.php
- docs/product-spec.md
- docs/architecture.md

Nie usuwano plikow projektu.

## Migracja lokalna

Po zgodzie wlasciciela wykonano spojna kopie SQLite przez VACUUM INTO, z kontrola integrity_check oraz foreign_key_check:

`storage/app/private/backups/before-return-parcel-tracking-20261102_162803.sqlite`

SHA256: `5de7974d7ace045f791268f2c229c1c938c679706328fcabc62d14b5522ee0a9`

Uruchomiono tylko:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_11_02_010000_add_return_parcel_tracking.php --force
```

Wynik: DONE. Porownano liczbe i skroty wszystkich istniejacych wierszy orders, order_items, invoices, invoice_items, order_returns, order_return_items, pierwotnych kolumn order_return_parcels, return_status_settings, automation_rules i automation_actions: bez zmian. Klucze obce: OK. Nie wykonano recznego skanu rzeczywistych numerow ani synchronizacji platnosci.

Migracja dodaje nullable pola cache, historie paczek i nullable powiazanie wykonania automatyzacji ze zwrotem. Nie robi backfillu. Down blokuje usuniecie uzywanej historii. Przed uruchomieniem nowego kodu na innym serwerze trzeba wykonac migracje; nie uruchamiac rollbacku na uzywanych danych.

## Testy

Testy korzystaja z SQLite :memory:, Http::preventStrayRequests i symulowanych odpowiedzi kurierow. Nie wyslano zadania finansowego, wiadomosci ani transmisji KSeF.

- Baseline przed implementacja: OrderReturnsTest, AllegroReturnsTest, AutomationOrderModificationTest: 81 testow, 477 asercji, PASS.
- Nowy ReturnParcelTrackingTest: 21 testow, 118 asercji, PASS.
- Regresja zwrotow, przesylek InPost/DPD/Allegro, historii automatyzacji i wszystkich tests/Feature/Automation*Test.php oraz testow jednostkowych mapowania/kolejek: 289 testow, 1672 asercje, PASS. Potwierdzono ponownie 2026-11-03 po Pint (26.33 s).
- Polecenie regresji: `php artisan test tests/Feature/ReturnParcelTrackingTest.php tests/Feature/OrderReturnsTest.php tests/Feature/AllegroReturnsTest.php tests/Feature/OrderAutomationHistoryTest.php tests/Feature/InPostShipmentTest.php tests/Feature/InPostCourierShipmentTest.php tests/Feature/DpdShipmentTest.php tests/Feature/AllegroShipmentExportTest.php tests/Feature/AllegroShipmentSettingsTest.php tests/Unit/ShipmentStatusMapperTest.php tests/Unit/ShipmentQueueTest.php` z dopisanymi plikami `tests/Feature/Automation*Test.php`.
- Pint: `php vendor/bin/pint --test` ze zmienionymi i nowymi plikami PHP: PASS. Lint: `php -l` dla 39 plikow: PASS. `git diff --check`: PASS (jedynie informacja Git o normalizacji CRLF/LF w product-spec.md).

Przegladarka: sprawdzono lokalna karte zwrotu 28, oznaczenie paczki, formularz oraz dzialanie modalu historii bez HTTP do kuriera. Na szerokosci 390 px tabela przewija sie w swoim kontenerze, bez rozszerzania strony. Nie dodawano rzeczywistej paczki podczas kontroli wizualnej.

## Weryfikacja reczna

1. Otworz karte zwrotu z numerem paczki. Sprawdz strzalke i podpowiedz, pasek postepu oraz historie po kliknieciu statusu.
2. Przejdz do powiazanego zamowienia: ta sama paczka ma byc w Przesylkach, obok paczki wyslanej klientowi, jesli OMS ja posiada.
3. Przy testowym zwrocie dodaj numer recznie. Blad/stara wersja formularza nie moze nadpisac danych. Nie wpisuj fikcyjnego numeru do rzeczywistego zwrotu.
4. Po restarcie lokalnego Main Queues zaczekaj na harmonogram albo swiadomie zlec Sprawdz status. Brak historii API pozostaje komunikatem, nie doreczeniem. Wlasciciel zadeklarowal samodzielny restart; agent nie restartowal KSeF ani VPS.
5. Automatyczne akcje: wybierz Zmieniono status przesylki zwrotnej u kuriera, warunek Status przesylki zwrotnej = Doreczona, akcje Zmien status zwrotu = Odebrane, zakres Wszystkie paczki doreczone. Docelowa nazwa pochodzi z aktualnej konfiguracji OMS. Wlacz regule dopiero po wlasnej weryfikacji.
6. Sprawdz historie automatycznych akcji zwrotu i zamowienia. Inne zwroty tego zamowienia maja pozostac bez zmian.

## Ograniczenia

- Nie testowano na rzeczywistym MySQL ani rzeczywistych odpowiedziach kurierow. Dostepna lokalna i izolowana baza testowa to SQLite.
- Nie wykonywano testow KSeF ani pelnego zestawu niezwiazanych modulow.
- Dostepnosc historii zalezy od API kuriera, wieku paczki i zgodnego konta Allegro. InPost nie udostepnia tego trackingu w sandbox. Harmonogram obejmuje ostatnie 60 dni.
- Bezposrednie sledzenie recznych DPD/DHL/ORLEN/UPS nie jest dodane; dla importow Allegro wykorzystywany jest tracker Allegro. Bez zgodnego konta lub historii widac brak danych.
- Automat nie reaguje na przesylke wychodzaca i wymaga zwrotu powiazanego z istniejacym, nieusunietym zamowieniem. Samodzielne zwroty mozna sledzic bez sztucznego zamowienia.
- Pierwszy poprawny odczyt moze wykryc juz doreczona paczke; aktywna, pasujaca regula bedzie mogla na to zareagowac. Nie odtwarza sie automatyzacji dla wszystkich historycznych etapow transportu.
- Na VPS niczego nie zmieniono.

## Zrodla

- [Base: zwroty](https://base.com/pl-PL/pomoc/wiedza/zwroty/)
- [Base: zdarzenia systemowe](https://base.com/pl-PL/pomoc/wiedza/akcje-automatycznie-zdarzenia-systemowe/)
- [InPost: Shipment Tracking](https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/18153479/Shipment%2BTracking)
- [Allegro: sledzenie przesylek](https://developer.allegro.pl/tutorials/jak-zarzadzac-przesylkami-przez-wysylam-z-allegro-LRVjK7K21sY)
