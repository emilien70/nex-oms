# ALLEGRO.2C - raport wdrozenia

## Wykonane zmiany

- Automatyczny odczyt zakupow BOUGHT/FILLED_IN i uzupelnianie tego samego numeru OMS po READY_FOR_PROCESSING.
- Odczyt aktualnego checkout-form, trwale zdarzenia i kursor, ochrona przed duplikatami, proby ponowienia, historia i import starszych niepelnych zakupow po potwierdzeniu.
- Zachowanie uwag, gwiazdek, dokumentow historycznych, kosza i zabezpieczen przed ponownym importem trwale usunietego rekordu.
- Anulowanie i laczenie zakupow po lineItem.id, bez automatycznych zwrotow lub kasowania dokumentow.
- Serwerowe i wizualne blokady realizacji niekompletnego zakupu; statystyki pomijaja powiazania niepotwierdzone, anulowane i zastapione.
- Stale ikony T, $ i samochodu oraz warunkowe ikony Faktury VAT, uwag sprzedawcy i komentarza Allegro. Kolory odczytano z zalaczonych obrazow: zielony #147042, szary #9ba3ad, pomaranczowy #eb840f, niebieski #499fd8.
- Komentarz Allegro na gorze Wymiany wiadomosci: tlo #ccebf6 i akcent #3190c9, tresc escapowana, zachowane nowe linie. Data to data zakupu (tooltip), API nie dostarcza osobnego znacznika czasu komentarza.

## Zmienione pliki

### Utworzone
- `Modules/Integrations/Allegro/Http/Controllers/AllegroAutomaticOrdersController.php`
- `Modules/Integrations/Allegro/Jobs/DiscoverAllegroOrdersJob.php`
- `Modules/Integrations/Allegro/Jobs/SyncAllegroOrderJob.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderImportEvent.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderDiscoveryService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderSyncService.php`
- `database/migrations/2026_10_20_150000_add_allegro_automatic_order_import.php`
- `resources/views/orders/partials/list-indicators.blade.php`
- `tests/Feature/AllegroAutomaticOrderImportTest.php`
- `docs/allegro-2c-report.md`

### Zmienione
- `Modules/Analytics/Services/AnalyticsFilterService.php`
- `Modules/Automation/Services/AutomationActionExecutor.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderLink.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderImportService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderPreviewService.php`
- `Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php`
- `Modules/Invoices/Services/InvoiceDocumentPreparationService.php`
- `Modules/Shipments/Services/ShipmentCreationAttemptService.php`
- `app/Http/Controllers/OrderMetaController.php`
- `app/Http/Controllers/OrderProductController.php`
- `app/Http/Controllers/OrderSectionController.php`
- `app/Http/Controllers/OrdersController.php`
- `app/Models/Order.php`
- `app/Services/OrderCatalogProductService.php`
- `app/Services/OrderStatusService.php`
- `app/Support/OrderListFilterState.php`
- `docs/architecture.md`
- `docs/product-spec.md`
- `resources/views/components/order-email-exchange.blade.php`
- `resources/views/integrations/allegro/_orders.blade.php`
- `resources/views/orders/index.blade.php`
- `resources/views/orders/partials/allegro-details.blade.php`
- `resources/views/orders/partials/order-info-view.blade.php`
- `resources/views/orders/partials/sales-document-actions.blade.php`
- `resources/views/orders/show.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/OrdersAjaxListTest.php`
- `tests/Frontend/order-inline-editors.test.cjs`
- `tests/Support/Invoices/run-isolated.php`

### Usuniete
Brak.

## Migracja

`database/migrations/2026_10_20_150000_add_allegro_automatic_order_import.php` zostala uruchomiona lokalnie 20.10.2026. Istniejace zamowienia pozostaly kompletne, automatyczne pobieranie domyslnie pozostalo wylaczone. Dodano ustawienia konta, metadane kompletowania oraz trwaly dziennik prob. Dane zamowien i dokumentow nie byly usuwane.

Bezpieczna komenda dla innego srodowiska:

```powershell
C:\\xampp\\php\\php.exe artisan migrate --path=database/migrations/2026_10_20_150000_add_allegro_automatic_order_import.php
```

## Testy

Konfiguracja testow: SQLite :memory:. HTTP jest symulowane; szerokie przebiegi korzystaja dodatkowo z izolowanego storage i blokady rzeczywistej sieci. Nie testowano na roboczej bazie zamowien.

- `php artisan test --compact --filter=AllegroAutomaticOrderImportTest`: 22 testy, 136 asercji, wynik poprawny.
- `php artisan test --compact --filter='AllegroOrder'`: 131 testow, 603 asercje, wynik poprawny (przed dodaniem nowego pliku testowego).
- `php artisan test --compact --filter='AllegroAutomaticOrderImportTest|OrdersAjaxListTest|OrderAjaxUpdatesTest|BasicStatisticsTest|OrderEmailManualUiTest'`: 56 testow, 543 asercje, wynik poprawny.
- `php artisan test --compact --filter='OrderSavedViewsTest|OrderStatusServiceTest|OrderCurrencyTest|OrderPaymentStateTest|AllegroAutomaticOrderImportTest|OrdersAjaxListTest'`: 98 testow, 542 asercje, wynik poprawny.
- `node --test tests/Frontend/order-inline-editors.test.cjs tests/Frontend/order-automation-history.test.cjs tests/Frontend/allegro-order-information.test.cjs`: 27 testow, wszystkie poprawne.
- Koncowy izolowany zestaw integracji i powiazanych modulow: 430 testow, 2859 asercji, 0 bledow, 0 niepowodzen i 0 pominiec. Raport JUnit: `storage/logs/allegro-2c-targeted-tests.xml`.
- Pelne przebiegi (`php tests/Support/Invoices/run-isolated.php` oraz wariant `--direct-phpunit`) zostaly przerwane przed koncowym wynikiem po wykryciu niezgodnych starszych asercji widokow i bardzo dlugim czasie ich obslugi. Nie potwierdzono poprawnosci calej suity. M.in. dwa testy OrderIdentifiersTest oczekuja dawnego ciagu `<div class="nex-value">multi-click.pl<span` / `PrestaShop<span`, choc wersja HEAD tego widoku juz korzystala z przycisku partiala inline-edit-value. Nie zmieniano tych niezaleznych asercji ani nie kwalifikowano wszystkich pozostalych wykrytych bledow jako historyczne.
- `php vendor/bin/pint --dirty --test` i `git diff --check`: poprawne; Git ostrzega jedynie o dotychczasowej normalizacji koncow linii product-spec.md.

Do izolowanego runnera dodano opcje `--direct-phpunit`, pozwalajaca zapisac raport JUnit bez kosztownego wypisywania calych stron HTML przy starszych asercjach widokow.

Dokladna komenda koncowego zestawu 430 testow:

```powershell
$files = @((Get-ChildItem tests/Feature/Allegro*.php).FullName) + @((Get-ChildItem tests/Feature/Prestashop*.php).FullName)
$files += @('tests/Feature/OrderAjaxUpdatesTest.php', 'tests/Feature/OrderEmailManualUiTest.php', 'tests/Feature/OrdersAjaxListTest.php', 'tests/Feature/OrderCurrencyTest.php', 'tests/Feature/OrderPaymentStateTest.php', 'tests/Feature/OrderStatusServiceTest.php', 'tests/Feature/OrderSavedViewsTest.php', 'tests/Feature/Invoices/InvoiceIssuingServiceTest.php', 'tests/Feature/Invoices/InvoiceDocumentPreparationTest.php', 'tests/Feature/Invoices/ProformaServiceTest.php', 'tests/Feature/InPostShipmentTest.php', 'tests/Feature/InPostCourierShipmentTest.php', 'tests/Feature/DpdShipmentTest.php', 'tests/Feature/Analytics/BasicStatisticsTest.php')
& C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit --log-junit=storage/logs/allegro-2c-targeted-tests.xml @files
```

## Weryfikacja reczna

Wykonano odczyt w lokalnej przegladarce: lista zamowien, zielone T dla PrestaShop, pomaranczowe pobranie, ikona wystawionej Faktury VAT przy zamowieniu 164, filtr kompletnosci oraz ustawienia Allegro z wylaczonym pobieraniem. Sprawdzono widoki o szerokosci ok. 1265 i 736 pikseli. Nie zmieniano zamowien podczas tej kontroli i zamknieto wylacznie wlasna karte testowa.

1. Otworz /orders. T, $ i samochod sa stale widoczne; PrestaShop ma zielone T. Sprawdz zamowienie oplacone, nieoplacone i pobraniowe.
2. Dla zamowienia z Faktura VAT sprawdz ikone dokumentu. Dla niepustego pola Informacje o zamowieniu -> Uwagi sprawdz szare i oraz podpowiedz z trescia uwag.
3. Dla nowego zakupu z komentarzem kupujacego sprawdz szara koperte oraz komentarz na gorze Wymiany wiadomosci. Uwagi sprzedawcy pozostaja odrebne.
4. W Integracje -> Allegro -> Zamowienia wlacz pobieranie i zapisz. Pierwsze wlaczenie ustala biezacy kursor; nie pobiera automatycznie starej historii.
5. Przy dzialajacym harmonogramie i kolejce poczekaj na BOUGHT/FILLED_IN. Rekord ma szare T i komunikat o niekompletnosci; nie mozna wystawic dokumentu ani nadac przesylki. Uwagi i gwiazdka pozostaja edytowalne.
6. Dokoncz zakup testowy w Allegro. Po READY_FOR_PROCESSING ma zostac uzupelniony ten sam numer OMS. Sprawdz adres, punkt odbioru, pozycje, VAT katalogowy i wplate. Ponowienie nie moze zdublowac platnosci.
7. Opcjonalnie uzyj Starsze zakupy niepelne -> Podglad, wybierz zakres i potwierdz wskazane zakupy. Bez potwierdzenia nic nie jest importowane.
8. Przy problemie sprawdz Historie pobierania i uzyj Ponow. Zmiana konta lub srodowiska nie moze przetwarzac zdarzen poprzedniego sprzedawcy.

Procesy wymagane lokalnie (uruchom ponownie istniejace workery po wdrozeniu; nie uruchamiaj duplikatow):

```powershell
C:\\xampp\\php\\php.exe artisan schedule:work
C:\\xampp\\php\\php.exe artisan queue:work --queue=integrations --timeout=85
```

Limit rezerwacji retry_after polaczenia kolejki musi byc wiekszy niz 80 sekund. Domyslnie database ma 90 sekund; nie zmieniano .env ani tej konfiguracji.

## Ograniczenia i ryzyka

- Nie wlaczano importu produkcyjnego ani nie pobierano rzeczywistych zakupow w ramach testow. Konto nie wymaga nowych uprawnien OAuth; kazde zapytanie zachowuje zatwierdzony User-Agent.
- W roboczej bazie nie znaleziono niepustych buyer_message Allegro. Widok komentarza sprawdzono na danych testowych; istnieje test escapowania HTML i zachowania komentarza oddzielnie od notes.
- Komentarze sa odczytywane z dostepnego buyer_message Allegro. Nie reinterpretowano uwag PrestaShop jako komentarzy kupujacego ani nie dodano nowego API pobierania wiadomosci sklepu.
- Automatyzacje po imporcie, zmniejszanie stanow, wysylanie statusow, Faktur i numerow przesylek do Allegro pozostaja poza zakresem.
- Nietypowe rozliczenia wymagaja sprawdzenia i nie sa automatycznie uznawane za gotowe do realizacji. Bledy strukturalne API sa widoczne w historii prob zamiast tworzyc rekord z wymyslonymi kwotami.
- Dziennik Allegro obejmuje 60 dni; przerwa ponad 59 dni jest zatrzymywana do kontrolowanej decyzji operatora. Kursor nigdy nie jest automatycznie przesuwany z pominieciem brakow. Podglad historii jest ograniczony do 1000 niepelnych zakupow.
- Nie wykonywano commita ani pusha; nie zmieniano zaleznosci, .env, plikow lock ani README.

