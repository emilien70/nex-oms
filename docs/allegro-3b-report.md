# ALLEGRO.3B - raport wdrozenia

Data: 2026-10-21. Zakres: przekazywanie numerow przesylek do zamowien Allegro.

## Wykonane zmiany

- Zakladka Integracje -> Allegro -> Przesylki z trybami Nie przekazuj (domyslny), Po uzyskaniu numeru nadania i Po przejsciu do wybranego statusu OMS.
- Dynamiczna lista aktualnych statusow OMS, lacznie z Nowe i statusami wlasnymi. Bez sztywnej listy w integracji.
- Nowe numery z InPost Paczkomaty, InPost Kurier i DPD sa zapisywane jako odrebne zgloszenia. Obslugiwane sa numery kolejnych paczek pojawiajace sie pozniej.
- Osobny stan i historia przekazania, niezalezny od statusu transportu i bledu nadania u kuriera.
- Odczyt Allegro przed kazdym zapisem/ponowieniem, sprawdzenie przewoznika oraz przypisanie numeru do wszystkich zrodlowych pozycji Allegro.
- Ochrona przed duplikatami, starymi numerami i zadaniami, obcym kontem, niepelnym/anulowanym zakupem i anulowana/usunieta przesylka.
- Wysylam z Allegro: odczyt wszystkich transportingInfo, zachowanie aliasow i wylacznie potwierdzanie automatycznego przypisania, bez POST. Historia rozroznia numer lokalny od numeru, ktorym faktycznie potwierdzono paczke.
- Kolejka integrations, cztery proby z odstepami, reczne ponowienie bledu oraz odzyskiwanie znanych, zablokowanych zgloszen po przerwie kolejki. Bez backfillu i bez samoczynnego ponawiania bledow stalych.
- Brak automatycznej zmiany statusu OMS na Wyslane. Wspolny, zatwierdzony User-Agent takze po 401 i odnowieniu OAuth.

Stan roboczej bazy sprawdzony po wdrozeniu: shipment_sync_mode=disabled, zero zgloszen w allegro_shipment_exports. Nie wlaczano funkcji i nie przekazywano numerow do rzeczywistego Allegro. Nie zmieniano istniejacych ustawien importu i eksportu statusow.

## Zmienione pliki

Utworzone:

- `database/migrations/2026_10_21_020000_add_allegro_shipment_exports.php`
- `Modules/Integrations/Allegro/Enums/AllegroShipmentSyncMode.php`
- `Modules/Integrations/Allegro/Models/AllegroShipmentExport.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroShipmentSettingsRequest.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroShipmentSettingsController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroShipmentExportController.php`
- `Modules/Integrations/Allegro/Services/AllegroShipmentSettingsService.php`
- `Modules/Integrations/Allegro/Services/AllegroShipmentExportService.php`
- `Modules/Integrations/Allegro/Support/AllegroShipmentNumbers.php`
- `Modules/Integrations/Allegro/Observers/AllegroShipmentTrackingObserver.php`
- `Modules/Integrations/Allegro/Listeners/DispatchAllegroShipmentExports.php`
- `Modules/Integrations/Allegro/Jobs/ExportAllegroShipmentJob.php`
- `resources/views/integrations/allegro/_shipments.blade.php`
- `resources/views/orders/partials/allegro-shipment-exports.blade.php`
- `tests/Feature/AllegroShipmentExportTest.php`
- `tests/Feature/AllegroShipmentSettingsTest.php`
- `docs/allegro-3b-report.md`

Zmodyfikowane (z zachowaniem wczesniejszych zmian):

- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Integrations/AllegroShipping/Services/AllegroShippingSynchronizer.php`
- `Modules/Shipments/Models/Shipment.php`
- `Modules/Shipments/Models/ShipmentParcel.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/OrdersController.php`
- `routes/web.php`
- `routes/console.php`
- `resources/views/integrations/allegro/edit.blade.php`
- `resources/views/orders/partials/shipment-row.blade.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete: brak. Nie zmieniano .env, README, plikow lock ani zaleznosci. Nie wykonano commita ani pusha.

## Migracje

Migracja `2026_10_21_020000_add_allegro_shipment_exports` zostala uruchomiona lokalnie. Dodaje ustawienia konta, nullable liste aliasow w shipment_parcels i tabele zgloszen eksportu. Historyczne relacje nowej tabeli maja nullOnDelete; istnieje unikalny fingerprint. Stare numery i dane zamowien pozostaja bez zmian. Nie ma backfillu ani destrukcyjnej przebudowy bazy.

Bezpieczna komenda dla innego srodowiska (pomija juz wykonana migracje):

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_21_020000_add_allegro_shipment_exports.php --force
```

## Testy

Przed uruchomieniem sprawdzono phpunit.xml: SQLite :memory:, DB_URL pusty. Testy nie korzystaja z roboczej bazy. Nowe testy blokuja niezdefiniowane zadania HTTP i sprawdzaja dokladny User-Agent na wszystkich wywolaniach, w tym OAuth i POST po 401.

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroShipmentExportTest|AllegroShipmentSettingsTest"
C:\xampp\php\php.exe artisan test --compact --filter="(Allegro[^:]*Test|InPost[^:]*Test|Dpd[^:]*Test|Shipment[^:]*Test|PrestashopOutboundStatusSyncTest|OrderStatusServiceTest|OrdersAjaxListTest|Automation[^:]*Test)::"
```

Wyniki koncowe:

- 32 nowe testy ALLEGRO.3B: wszystkie poprawne, 216 asercji, 3.98 s.
- 425 testow integracji i powiazanych zachowan: wszystkie poprawne, 2519 asercji, 21.90 s.
- Laravel Pint: poprawnie.
- git diff --check dla zmienianego obszaru: bez bledow.

Zakres: konfiguracja domyslna i dynamiczne statusy, walidacja, konflikt wersji/tozsamosci, reset konta, afterCommit i rollback, brak backfillu, obie kolejnosci bramki, opuszczenie i ponowne wejscie w status, pozniejsze numery, idempotencja, zmiana danych podczas HTTP, potwierdzenie juz istniejacego numeru, timeout po POST, 401/429/503, bledy stale, reczne i automatyczne odzyskiwanie, wiele aliasow Wysylam bez POST, bledne/niepelne dane zrodla, usuniecie konta i zachowanie historii po usunieciu przesylki.

Laravel Pint uruchomiono tylko dla zmienianych klas i testow. git diff --check nie wykazal bledow w zmienianym obszarze. Pelnego zestawu wszystkich testow projektu nie uruchamiano; szerszy, dobrany zestaw obejmuje integracje i wspoldzielone zachowania przesylek/automatyzacji. Znany starszy problem OrderStatusPollingTest opisany w raporcie ALLEGRO.3A pozostaje poza zakresem.

W przegladarce sprawdzono konfiguracje w widoku komputerowym i 390 x 844 px: domyslne Nie przekazuj, rzeczywiste statusy OMS, poprawne etykiety i wyrownanie, bez bledow konsoli. Sprawdzono rowniez istniejaca karte zamowienia z paczka: wyswietla brak potwierdzenia bez zmiany statusu transportu. Nie zapisywano formularzy ani nie uruchamiano operacji kurierskich. Tymczasowa karta zostala zamknieta, rozmiar przegladarki przywrocony, karta uzytkownika pozostala bez zmian. Stany sukcesu, bledu i ponowien sprawdzono na danych testowych, nie przez tworzenie ich na koncie produkcyjnym.

## Weryfikacja reczna

1. Otworz `http://127.0.0.1:8000/integrations/allegro?tab=shipments`. Sprawdz Nie przekazuj i dynamiczna liste statusow.
2. Przed pierwszym wlaczeniem zrestartuj Main Queues w panelu NEX-OMS. Scheduler musi dzialac dla odzyskiwania znanych zgloszen; nie nadaje paczek i nie wykonuje POST bezposrednio.
3. Dalsza probe wykonaj dopiero na kontrolowanym zamowieniu, najlepiej z osobno skonfigurowanym kontem sandbox i kurierem testowym. Utworzenie etykiety u rzeczywistego kuriera moze rodzic oplaty.
4. Jawnie wlacz Po uzyskaniu numeru nadania. Sam zapis nie powinien zlecic przekazania starych numerow. Dopiero nowy numer otrzymany z kuriera powinien pokazac Oczekuje, a po pracy kolejki potwierdzenie lub blad. Po zakonczeniu kolejki odswiez karte zamowienia.
5. W kontrolowanym trybie statusowym sprawdz numer przed wybranym statusem oraz numer uzyskany juz w tym statusie. Lista i bramka powinny korzystac z rzeczywistego statusu OMS, rowniez wlasnego.
6. Dla kilku paczek sprawdz wszystkie numery. Dla Wysylam z Allegro oczekuj jedynie potwierdzenia paczki; historia ma wskazywac odnaleziony numer, a nie deklarowac ponowny POST aliasow.
7. Blad przekazania nie moze zmieniac danych paczki, zamowienia ani dokumentow. Przycisk z okragla strzalka ponawia tylko aktualne, nieudane zgloszenie. Testy bledow i timeoutow wykonuj przez symulowane HTTP, nie przez psucie produkcyjnych danych dostepowych.

## Ograniczenia i ryzyka

- Nie potwierdzono rzeczywistego POST do Allegro ani platnego nadania u kuriera. Testy API sa symulowane; pierwszy kontrolowany test produkcyjny/sandbox pozostaje do wykonania przez wlasciciela po wlaczeniu funkcji.
- Restart Main Queues potwierdzono 2026-10-21 o 14:58:47 czasu Europe/Warsaw, po wdrozeniu ALLEGRO.3B. Proces dziala i wykonuje zadania. Przekazywanie numerow pozostaje ustawione na Nie przekazuj; w chwili weryfikacji brak zgloszen eksportu.
- Tryb Nie przekazuj dotyczy operacji OMS, nie zatrzymuje automatycznego przypisania przez Wysylam z Allegro. Weryfikacja tej uslugi po wlaczeniu nie czeka na status OMS, bo sama operacja Allegro nie jest opozniana przez OMS.
- Kazdy numer dotyczy calego zamowienia. Wysylki czesciowe i przydzielanie produktow do konkretnych paczek nie sa zaimplementowane. Istniejacego przypisania czesciowego OMS nie nadpisuje automatycznie.
- Anulowanie/usuniecie lokalnej paczki nie usuwa numeru z Allegro. Potwierdzenie przyjete w czasie zmiany konfiguracji jest zachowywane; API i baza nie maja wspolnej transakcji. Nie ma gwarancji cofniecia operacji juz przyjetej przez Allegro.
- Stan w otwartej karcie moze wymagac odswiezenia strony. Nie dodano osobnego ekranu logow ani nowego pollingu.
- Dla weryfikacji Wysylam z Allegro konto kurierskie musi byc powiazane z tym samym kontem integracji Allegro; nie laczymy dowolnych starszych danych OAuth na podstawie podobnej nazwy konta.
- Faktury, reczne numery spoza OMS, automatyczne usuwanie przypisan i import numerow nadanych poza OMS pozostaja poza ALLEGRO.3B.
- Logowanie i uprawnienia calego OMS nadal wymagaja osobnego etapu przed publicznym udostepnieniem.

Referencje: [obsluga zamowien i numerow Allegro](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR), [zmiana transportingInfo od 29.10.2026](https://developer.allegro.pl/news/wysylam-z-allegro-29-pazdziernika-przestaniemy-zwracac-numery-przewozowe-w-obiekcie-additionalproperties-WvgDEDPAlfX), [pomoc Base o przekazywaniu numerow](https://base.com/pl-PL/pomoc/wiedza/przekazywanie-numerow-przesylek/).
