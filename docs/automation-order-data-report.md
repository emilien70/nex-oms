# Automatyzacje - kompletne dane i modyfikacja zamowienia

## Zakres

Lokalne wdrozenie nowych zdarzen, wielokrotnego wyboru zrodel i akcji
Zmodyfikuj zamowienie w istniejacym edytorze i silniku automatycznych akcji.
Bez zmian na VPS, migracji, ustawien integracji, nowych regul uzytkownika,
rzeczywistych importow, wiadomosci lub transmisji KSeF.

## Zachowanie

- Utworzono zamowienie: pierwsze tworzenie reczne, dla klienta, kopiowanie,
  nowy import PrestaShop lub Allegro. Zdarzenie nie powtarza sie po edycji.
- Pobrano kompletne dane zamowienia: Allegro po poprawnym READY_FOR_PROCESSING;
  PrestaShop po pierwszym imporcie pozycji, adresow, dostawy i danych platnosci.
  Nie wymaga wplaty, np. dla pobrania. Nie obejmuje pustego zamowienia recznego.
- Niepelne Allegro, FILLED_IN, review, cancelled i superseded nie uruchamiaja
  zdarzenia kompletnych danych. Dotychczasowe blokady wykonania pozostaja.
- Pierwszy import od razu kompletnego zamowienia takze uruchamia zdarzenie.
- Ponowna synchronizacja lub ponowne dostarczenie tego samego zdarzenia nie
  powtarza wykonania tej samej reguly. Brak uruchamiania dla dawnych importow.
- Wybrane zrodla sa laczone OR, dodatkowe warunki AND. Konto jest identyfikowane
  przez ID i kanal, nigdy nazwe. Dostepne sa konkretne konta i cale kanaly.
  Wszystkie zrodla to osobny wybor, nie domniemanie z pustej listy.
- Stare reguly i warunki source/equals, source/not_equals pozostaja obslugiwane.
- Jedna modyfikacja zmienia jedno pole; kolejne modyfikacje sa osobnymi krokami.
  Pusta wartosc czysci pole opcjonalne; '0' nie jest traktowane jako puste.
  Kraj musi miec poprawny kod ISO z centralnego katalogu.
- Zmienne sa rozwijane jednokrotnie, a wynik ponownie walidowany. Brak eval,
  skryptow, dowolnych nazw kolumn ani masowego przypisywania konfiguracji.
- Kwoty, wplaty, produkty, status, zrodlo, numery transakcji i dokumenty nie
  znajduja sie na liscie pol do zmiany. Status ma osobna dotychczasowa akcje.
- Model Order zachowuje obserwatory portalu. Link klienta nie jest zmieniany;
  zmiana danych osobowych uniewaznia poprzednia weryfikacje klienta.
- Istniejace dokumenty zachowuja snapshoty. Nowo wystawiana faktura widzi
  dane z poprzedzajacego kroku. Nie ma modyfikacji transportu ani polityki KSeF.
- Lokalna mutacja i potwierdzenie wykonania sa transakcyjne. Ponowne wywolanie
  zakonczonej modyfikacji nie nadpisuje pozniejszej recznej poprawki.

## Pliki

Utworzone:

- app/Events/OrderCreated.php
- app/Events/OrderDataCompleted.php
- Modules/Automation/Services/AutomationOrderSources.php
- Modules/Automation/Services/AutomationOrderFields.php
- Modules/Automation/Services/AutomationOrderModificationService.php
- tests/Feature/AutomationOrderModificationTest.php
- docs/automation-order-data-report.md

Zmienione:

- app/Http/Controllers/OrdersController.php
- app/Providers/AppServiceProvider.php
- Modules/Integrations/Allegro/Services/AllegroOrderImportService.php
- Modules/Integrations/Prestashop/Services/PrestashopOrderImportService.php
- Modules/Automation/Listeners/DispatchAutomationEvent.php
- Modules/Automation/Http/Requests/SaveAutomationRuleRequest.php
- Modules/Automation/Services/AutomationCatalog.php
- Modules/Automation/Services/AutomationConditionEvaluator.php
- Modules/Automation/Services/AutomationActionExecutor.php
- Modules/Automation/Services/OrderAutomationHistory.php
- resources/views/orders/_automatic-action-inline-editor.blade.php
- resources/views/orders/automatic-actions.blade.php
- tests/Feature/AllegroAutomaticOrderImportTest.php
- tests/Feature/PrestashopOrderImportTest.php
- docs/architecture.md
- docs/product-spec.md

Nie usunieto plikow. Wczesniejsze lokalne zmiany SMS i dokumentacji wdrozenia
zastano przed tym etapem i zachowano; nie naleza do powyzszej listy.

## Testy

Przed zmianami: ukierunkowany zestaw 163 testy / 1111 asercji, PASS.
Po zmianach: rozszerzona regresja 747 testow / 4934 asercje, PASS (74.55 s).
Konfiguracja: SQLite :memory:, brak uzytkowej bazy, symulowane uslugi zewnetrzne.
Zakres: automatyzacje, importy Allegro i PrestaShop, statusy i platnosci
PrestaShop, SMS, historia, adresy, dokumenty w automatyzacjach i portal klienta.
Nie jest to pelna regresja calego repozytorium ani test API KSeF.

Komenda bezpiecznej regresji (PowerShell, katalog projektu):

```powershell
$testFiles = Get-ChildItem tests/Feature -Filter '*.php' |
    Where-Object { $_.Name -match '^(Automation|AutomaticActions|AllegroAutomaticOrderImport|AllegroOrderImport|AllegroOrderCatalog|Prestashop|PayNowPaymentAutomation|OrderAutomationHistory|OrderAddressIsolation|OrderSmsUi|MacroDroidSms|CustomerPortal)' } |
    Select-Object -ExpandProperty FullName
$testFiles += (Resolve-Path tests/Feature/Products/InvoiceStockAutomationTest.php).Path
& C:\xampp\php\php.exe artisan test @testFiles --compact
```

Konfiguracja phpunit.xml wymaga DB_CONNECTION=sqlite i DB_DATABASE=:memory:.
Nie nalezy nadpisywac jej roboczymi zmiennymi polaczenia z baza.
Pint uruchomiono wylacznie dla 18 plikow PHP tego etapu. Uporzadkowal importy;
ponowny `vendor/bin/pint --test <lista plikow>`: PASS. `php -l` wszystkich
18 plikow PHP: PASS. `git diff --check`: PASS (Git informuje tylko o lokalnym
CRLF w product-spec.md; nie przeprowadzano masowej zmiany zakonczen linii).

```powershell
node --test tests/Frontend/order-automation-history.test.cjs tests/Frontend/order-inline-editors.test.cjs
```

Testy frontendowe: 18/18 PASS. W przegladarce lokalnej sprawdzono nowy szkic,
wybor zdarzenia i dwoch konkretnych integracji naraz, automatyczne odznaczenie
Wszystkie zrodla, pola NIP i uwagi, wpisanie zmiennych, dodanie drugiego kroku
oraz zmiane kolejnosci bez utraty wartosci. Nie zapisano ani nie aktywowano
reguly w roboczej bazie. Nie wykonano pelnej regresji calego repozytorium.

## Weryfikacja reczna

1. Otworz Zamowienia -> Automatyczne akcje -> Dodaj automatyczna akcje.
2. Wybierz Pobrano kompletne dane zamowienia.
3. Rozwin Zrodla zamowienia i zaznacz konto Allegro oraz sklep PrestaShop.
   Wszystkie zrodla ma przestac byc zaznaczone. Zamknij i rozwin liste.
4. Wybierz Zmodyfikuj zamowienie, pole Uwagi sprzedawcy i testowa wartosc
   ze zmienna [id_zamowienia]. Sprawdz tez pole NIP, telefon i kraj.
5. Dodaj drugi krok. Sprawdz zmiane kolejnosci i powrot do poprzednich pol.
6. Zachowaj regule nieaktywna do zatwierdzenia jej rzeczywistych skutkow.
7. Na dedykowanych danych testowych sprawdz BOUGHT -> FILLED_IN -> READY oraz
   pierwszy import nieoplaconego PrestaShop. Kazda regula ma wykonac sie raz.
8. Sprawdz historie automatycznych akcji i niezmiennosc starej faktury.

Lokalny restart Main Queues po tym etapie zostal potwierdzony przez wlasciciela.
Na przyszlosc wlasciciel zgodzil sie na potrzebne, lagodne restarty wylacznie
lokalnych Main Queues po testach zmian kolejki. Nie jest to zgoda na restart
VPS, wymuszanie importow, wysylek lub operacji finansowych.
Nie restartowano VPS. Nie potrzeba migracji.
Testy SQLite nie potwierdzaja rzeczywistej wspolbieznosci na MySQL ani efektow
zewnetrznych uslug. Nie przeprowadzono produkcyjnych testow SMS/KSeF/kurierow.
