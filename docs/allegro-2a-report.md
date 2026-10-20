# ALLEGRO.2A — raport wdrożenia

Data: 19.10.2026.

## Wykonane zmiany

- Dodano zakładkę Zamówienia w integracji Allegro: podgląd po UUID i oddzielne potwierdzenie importu.
- Potwierdzenie jest związane z kontem, środowiskiem, zamówieniem i hashem danych oraz wygasa po 15 minutach. Import ponownie odczytuje Allegro; zmiana danych wymaga nowego potwierdzenia.
- Import tworzy zamówienie OMS w statusie `new`, pozycje, powiązanie, płatności i jedno zdarzenie historii w jednej transakcji.
- Zachowywane są dane kontaktowe, pełny maskowany e-mail, adres dostawy, osobne dane do faktury, punkt odbioru, Smart, żądanie faktury i wiadomość kupującego. Wiadomość nie trafia do uwag sprzedawcy.
- Zachowano oddzielne numery OMS, zamówienia Allegro, pozycji, ofert i płatności. Pozycje zawierają ceny Allegro po rabatach; usługi dodatkowe są oddzielnymi pozycjami.
- Płatności i dopłaty są widoczne w sekcji Płatności. Pobranie nie oznacza zaksięgowanej wpłaty; częściowa wpłata zachowuje rzeczywistą wartość.
- Ponowienie importu nie nadpisuje ręcznych zmian, nie tworzy kopii ani ponownej wpłaty. Ochrona działa też dla kosza, trwale usuniętego zamówienia i usuniętego konta.
- Nazwa źródła pochodzi z integracji; karta pokazuje Smart, żądanie faktury, wiadomość, datę zakupu i linki do ofert.
- W przyszłej ręcznie wystawianej fakturze używany jest numer OMS oraz identyfikatory wpłat Allegro. Istniejące dokumenty nie są zmieniane.
- Logi odczytu zamówienia nie zapisują pełnych danych klienta; błędy HTTP są mapowane na kontrolowane komunikaty.

## Zmienione pliki

### Utworzone

- `Modules/Integrations/Allegro/Exceptions/AllegroImportException.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroOrderImportController.php`
- `Modules/Integrations/Allegro/Http/Requests/ImportAllegroOrderRequest.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderLink.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderPayment.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderImportService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderPreviewService.php`
- `database/migrations/2026_10_19_040000_create_allegro_order_import_tables.php`
- `resources/views/integrations/allegro/_orders.blade.php`
- `tests/Feature/AllegroOrderImportTest.php`
- `tests/Support/AllegroOrderFixture.php`
- `docs/allegro-2a-report.md`

### Zmienione

- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Invoices/Services/InvoicePaymentIdentifierResolver.php`
- `app/Http/Controllers/OrderStatusController.php`
- `app/Http/Controllers/OrdersController.php`
- `app/Models/Order.php`
- `resources/views/integrations/allegro/edit.blade.php`
- `resources/views/orders/index.blade.php`
- `resources/views/orders/partials/order-info-view.blade.php`
- `resources/views/orders/partials/payments.blade.php`
- `resources/views/orders/partials/product-rows.blade.php`
- `resources/views/orders/show.blade.php`
- `routes/web.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usunięte pliki projektu: brak. Tymczasowy skrypt i wygenerowany HTML kontroli wizualnej zostały usunięte, a dodatkowy serwer QA zatrzymany.

## Migracje

Migracja `2026_10_19_040000_create_allegro_order_import_tables.php` została sprawdzona przez `--pretend`, a następnie uruchomiona lokalnie. Tworzy wyłącznie `allegro_order_links` oraz `allegro_order_payments`. Nie aktualizuje ani nie usuwa istniejących zamówień.

Bezpieczna komenda dla kolejnego środowiska:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_19_040000_create_allegro_order_import_tables.php --force
```

## Testy

Sprawdzono `phpunit.xml`: testy pracują na SQLite `:memory:`, nie na roboczej bazie użytkownika. Zewnętrzne API jest zastępowane odpowiedziami testowymi, a kolejka jest blokowana przez fake.

Końcowa komenda regresji:

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="Allegro|IntegrationsList|Prestashop|OrderAjaxUpdatesTest|OrdersAjaxListTest|OrderIdentifiersTest|OrderNavigationTest|OrderPaymentStateTest|OrdersTrashTest|OrderSalesDocumentActionsTest|InvoiceIssuingServiceTest|InvoiceDocumentPreparationTest"
```

Wynik: **253 testy, 1717 asercji, 0 błędów**. Zakres obejmuje nowy import, ustawienia i OAuth Allegro, współdzieloną obsługę przesyłek, PrestaShop, widoki i operacje zamówień oraz wystawianie i snapshoty faktur. Testy importu obejmują poprawne wpłaty, pobrania, dopłaty, częściową wpłatę, walidację, zmianę podglądu, idempotencję, awarię zapisu i rollback, historyczne powiązania, brak zmian magazynowych, izolację sandbox/produkcja oraz bezpieczne wyświetlanie treści.

Dodatkowe kontrole:

- `vendor/bin/pint --test` dla zmienionych plików PHP i modułu Allegro: PASS.
- `php -l` dla 17 nowych lub zmienionych plików PHP: PASS.
- `artisan route:list --path=integrations/allegro`: 8 tras, w tym nowa trasa importu.
- `git diff --check`: PASS; jedynie informacja Git o konwersji końców linii dokumentacji.
- Kontrola przeglądarkowa pustego formularza w lokalnym OMS oraz pełnego podglądu na odizolowanej bazie w pamięci: desktop i telefon 390 × 844. Brak poziomego rozszerzenia strony; tabele mają własne przewijanie.

Pełnego zestawu wszystkich testów projektu w tym etapie nie uruchamiano.

## Weryfikacja ręczna

1. Otwórz `http://127.0.0.1:8000/integrations/allegro?tab=orders`.
2. Wpisz pełny UUID zamówienia z połączonego konta Allegro i wybierz `Podgląd zamówienia`.
3. Sprawdź produkty, ceny, sumę, koszt dostawy, identyfikatory płatności, adresy, punkt odbioru, Smart i wiadomość kupującego. Sam podgląd nie tworzy zamówienia.
4. Dla kompletnego, obsługiwanego zamówienia wybierz `Importuj do OMS`.
5. Na otwartej karcie sprawdź własny numer OMS, status Nowe, nazwę integracji w źródle oraz dane kontaktowe, oferty i sekcję Płatności.
6. W przypadku pobrania saldo powinno wynosić 0,00, a zamówienie powinno pozostać nieopłacone. Dla częściowej wpłaty saldo ma odpowiadać rzeczywistej wpłacie, bez oznaczenia jako w pełni opłacone.
7. Powtórz podgląd tego samego UUID: powinien pojawić się przycisk otwarcia istniejącego zamówienia, bez kolejnego importu. Ręczne poprawki w OMS muszą pozostać bez zmian.
8. Przed ręcznym wystawieniem faktury sprawdź VAT pozycji. Import nie zgaduje stawki. Przy wystawieniu dokument otrzyma numer zamówienia OMS i osobne ID wpłat Allegro.

## Ograniczenia i ryzyka

- Nie wykonano importu rzeczywistego zamówienia Allegro, ponieważ nie wskazano testowego UUID. Funkcjonalność wymaga jeszcze potwierdzenia na takim zamówieniu przez właściciela.
- Import obsługuje `READY_FOR_PROCESSING`, realizację `SELLER`, płatności `ONLINE` i `CASH_ON_DELIVERY`. Niezgodne kwoty, nadpłaty, Allegro Ceny, split payment, płatności odroczone i inne sposoby realizacji są blokowane, a nie automatycznie korygowane.
- Brak aktualizacji po imporcie: późniejsza płatność, zmiana adresu lub statusu w Allegro nie odświeża jeszcze zamówienia OMS.
- Brak automatycznego dopasowania produktów i zdjęć, wystawiania dokumentów, uruchamiania automatyzacji, e-maili i przesyłek. Nie dodano zmian magazynowych ani zapisów do Allegro.
- Nie instalowano zależności ani nie zmieniano plików środowiska i locków. Nie wykonano commita ani pusha.

## Źródła

- [Allegro Developer: obsługa zamówień](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR)
- [Base: zamówienia Allegro](https://base.com/pl-PL/pomoc/wiedza/zamowienia-allegro/)
- [Base: Allegro Smart](https://base.com/pl-PL/pomoc/wiedza/allegro-smart/)
