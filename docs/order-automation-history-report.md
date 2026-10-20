# Historia automatycznych akcji w zamowieniu

Data: 2026-10-20.

## Wykonane zmiany

- Nowa zwijana sekcja `Informacje dodatkowe -> Historia automatycznych akcji`.
- Uklad wedlug referencji: data i zdarzenie nad tabela, ikona wyniku, szara kolumna nazwy akcji i biala kolumna szczegolow.
- Historia korzysta z istniejacych `automation_runs`, `automation_run_steps`, snapshotow regul i wynikow krokow. Nie uruchamia ponownie zadnej akcji.
- Pokazuje wykonanie, blad, oczekiwanie, wykonywanie, kolejke i pominiete kroki. Uzywa tych samych zasad interpretacji statusow co globalny panel aktywnosci.
- Dziesiec wykonan na stronie, najnowsze najpierw. Stronicowanie i odswiezanie dzialaja bez przeladowania zamowienia.
- Odswiezanie przy otwarciu sekcji, po zakonczeniu automatyzacji i co 15 sekund, kiedy sekcja pozostaje rozwinieta i karta jest widoczna.
- URL oraz czas odpowiedzi sa pobierane z zapisanego logu zgodnego z zamowieniem, wykonaniem i request_id. Widok nie otwiera adresu ani nie wykonuje zewnetrznego HTTP.
- Dane dokumentu, drukarki i szablonu wiadomosci sa odczytywane z zachowanych wynikow/snapshotow, jesli sa dostepne.
- Zachowano osobna historie zmian zamowienia i dotychczasowy panel aktywnosci.

## Zmienione pliki

Utworzone:

- `C:/projekty/nex-oms/Modules/Automation/Services/OrderAutomationHistory.php`
- `C:/projekty/nex-oms/resources/views/orders/partials/automation-history.blade.php`
- `C:/projekty/nex-oms/tests/Feature/OrderAutomationHistoryTest.php`
- `C:/projekty/nex-oms/tests/Frontend/order-automation-history.test.cjs`
- `C:/projekty/nex-oms/docs/order-automation-history-report.md`

Zmienione:

- `C:/projekty/nex-oms/Modules/Automation/Services/AutomationActivityFeed.php`
- `C:/projekty/nex-oms/app/Http/Controllers/OrdersController.php`
- `C:/projekty/nex-oms/app/Http/Controllers/OrderStatusController.php`
- `C:/projekty/nex-oms/resources/views/components/order-additional-information.blade.php`
- `C:/projekty/nex-oms/resources/views/orders/show.blade.php`

Usuniete: brak. Nie zmieniano regul automatyzacji, danych zamowien ani istniejacych wykonan.

## Migracje

Brak nowych migracji. Nie uruchamiano migracji ani seederow.

## Testy

Konfiguracja: SQLite `:memory:`, srodowisko `testing`. Odpowiedzi zewnetrzne sa symulowane; testy nie korzystaja z roboczej bazy operatora.

```powershell
C:\xampp\php\php.exe artisan test --compact tests/Feature/OrderAutomationHistoryTest.php tests/Feature/AutomationActivityFeedTest.php tests/Feature/OrderAjaxUpdatesTest.php tests/Feature/AutomationCallUrlTest.php tests/Feature/AutomationInvoiceActionTest.php tests/Feature/AutomationEmailActionTest.php tests/Feature/AutomationNexPrinterActionTest.php tests/Feature/Products/InvoiceStockAutomationTest.php tests/Feature/AllegroOrderInformationTest.php tests/Feature/AllegroOrderFeesTest.php
```

Wynik: 104 testy poprawne, 814 asercji.

```powershell
node --test tests/Frontend/order-automation-history.test.cjs tests/Frontend/order-inline-editors.test.cjs tests/Frontend/allegro-order-information.test.cjs
```

Wynik: 26 testow poprawnych. Obejmuja stronicowanie, blokade rownoleglych zapytan, stan kontrolek podczas pobierania, bledy odpowiedzi, zachowanie poprzedniej historii oraz zgodnosc z edycja inline i danymi Allegro.

```powershell
C:\xampp\php\php.exe vendor/bin/pint --test Modules/Automation/Services/AutomationActivityFeed.php Modules/Automation/Services/OrderAutomationHistory.php app/Http/Controllers/OrdersController.php app/Http/Controllers/OrderStatusController.php tests/Feature/OrderAutomationHistoryTest.php
git diff --check
```

Wynik: poprawny. Git sygnalizuje zastana konwersje CRLF/LF dla `docs/product-spec.md`; plik nie byl zmieniany w tym zadaniu.

## Weryfikacja reczna

1. Otworz karte zamowienia, np. `http://127.0.0.1:8000/orders/24`.
2. Rozwin `Informacje dodatkowe -> Historia automatycznych akcji`.
3. Sprawdz daty, zdarzenia, akcje, cele i oznaczenia wynikow.
4. Uzyj strzalek `Starsze akcje` i `Nowsze akcje`. Sekcja pozostaje otwarta, a cale zamowienie nie jest przeladowywane.
5. Na zamowieniu bez wykonan sprawdz komunikat pustej historii.

W przegladarce potwierdzono przejscie na druga strone historii zamowienia 24 oraz wyglad historii zamowienia 21. Sprawdzono szerokosci 1200, 900 i 390 px. Tabela historii na telefonie nie wychodzi poza swoj kontener. Brak bledow konsoli. Do testow wykorzystano istniejace dane bez uruchamiania akcji i bez tworzenia demonstracyjnych wykonan w roboczej bazie.

## Ograniczenia i ryzyka

- Wykonawca jest opisany jako `Automat`: biezace dane Automation nie przechowuja wiarygodnego snapshotu imienia i nazwiska operatora. Widok nie przypisuje fikcyjnego autora.
- Zielone oznaczenie oznacza sukces kroku Automation. Dla e-maila, przesylki i NEX Printer moze oznaczac przekazanie do kolejki, nie koncowy wynik dostarczenia lub wydruku; podpowiedz wyjasnia te roznice.
- Usuniety log URL oznacza fallback do utrwalonej konfiguracji, bez ponownego podstawiania aktualnych danych zamowienia. Brak snapshotu nazwy szablonu lub dokumentu oznacza pokazanie dostepnego ID zamiast odtwarzania nieznanej historii.
- Nie zmieniono uprawnien, wyzwalaczy ani zachowania silnika automatyzacji. Nie dodano brakujacych historycznych wykonan ani nowych zdarzen importu.
- Nie wykonywano commita ani pusha.
