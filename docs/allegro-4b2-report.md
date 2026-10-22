# ALLEGRO.4B.2 - reczne zwroty wplat

Data: 22.10.2026.

## Stan

- Galaz `main`, poczatkowy HEAD `f9a3ce98472a8b9d6affdc4fb9aad2c612b303c3`, poczatkowy worktree czysty.
- Nowa migracja zostala wykonana LOKALNIE po osobnej zgodzie wlasciciela i wykonaniu kopii SQLite.
- Nowe zlecanie i prosba o scope write pozostaja wylaczone. Nie zmieniono dotychczasowych ustawien pobierania zwrotow ani refundacji.
- Wlasciciel potwierdzil restart Main Queues po lokalnej migracji.
- Nie wykonano rzeczywistej refundacji, reautoryzacji ani odczytu prywatnego API Allegro. HTTP w testach bylo w calosci fikcyjne; siec testowa zablokowana.
- Nie wykonywano operacji na VPS, commita, pusha ani zmian zaleznosci, .env, README lub lockfile.

## Funkcja

Karta zwrotu zawiera Zwroc wplate. Formularz wybiera produkty, ilosci, kwoty, powod i opcjonalny komentarz. Koszt dostawy ma osobny, domyslnie wylaczony checkbox i oddzielna kwote. Podsumowanie wymaga dodatkowej zgody oraz Zlec zwrot X PLN. Sam podglad nie wysyla pieniedzy. Draft wygasa po 5 minutach i nalezy do sesji, w ktorej go przygotowano.

Proces wspiera reczne i importowane karty powiazane z lokalnym zamowieniem Allegro. Standardowa platnosc musi byc pojedyncza, ONLINE, zakonczona i zgodna z kwota produktow oraz dostawy. Pozycje musza wskazywac oryginalne lineItem.id przez order_item_id. Nie ma dopasowania po nazwie/SKU. Kwoty sa liczone przez BigDecimal; API otrzymuje AMOUNT, a ilosc ogranicza maksymalna wybrana kwote produktu.

Kwoty wczesniejszych i oczekujacych refundacji oraz lokalne zlecenia sa uwzgledniane wspolnie dla zamowienia. Rownolegle zlecenia sa blokowane pod blokada konta i AllegroOrderLink. Zmieniony checkout, historia, wersja zwrotu, konfiguracja lub lokalny rejestr zlecen uniewazniaja potwierdzenie przed wysylka. UUID i tresc zlecenia nie sa zmieniane.

Tylko zgodne SUCCESS jest automatycznie przypisane do karty zlecajacej. NEW, VALIDATED, IN_PROGRESS i WAITING nie zwiekszaja Zwrocono srodki. PARTIAL i nieznane statusy wymagaja sprawdzenia. Nie zmienia sie paid_amount, statusu zamowienia, dokumentow, magazynu ani prowizji. Uzgodnienie juz znanego wyniku dziala rowniez przy wylaczeniu nowych zlecen i przy wylaczonym imporcie 4B.1.

## Timeout i ponowienia

- Podwojne potwierdzenie oraz ponowne wykonanie tego samego joba nie wysylaja nowego POST.
- Po HTTP 401 transport odnawia token i ponawia identyczny payload z tym samym commandId, po sprawdzeniu konta i konfiguracji.
- Timeout/5xx/niepelne HTTP 200/przerwany worker => uncertain i nadal zarezerwowana kwota. Nie tworzymy nowego UUID ani automatycznego resend.
- Znany ID refundacji jest sprawdzany odczytem co okolo 5 minut. Brak ID nie jest uzgadniany po samej kwocie.
- Operator moze wskazac ID refundacji z Allegro i jawnie potwierdzic tozsamosc. OMS sprawdza konto, zamowienie, platnosc, linie, kwoty, powod, komentarz i czas. Jest to wylacznie odczyt, nie ponowny przelew.
- Bez mozliwosci jednoznacznego uzgodnienia blokada pozostaje. Nie udostepniono przycisku recznego zwolnienia rezerwacji lub ponownej wysylki w ciemno.

## Ograniczenia

- Nie obslugujemy w write doplat, uslug dodatkowych, kaucji, rozliczen mieszanych, pobrania bez zwrotnej platnosci online ani nieustalonego podzialu.
- Historyczne refundacje QUANTITY sa blokowane, gdy nie dowodza kwot konkretnej pozycji. Aktualna cena nie sluzy do zgadywania starego rozliczenia.
- Potrzebne jest lokalne, nieusuniete zamowienie oraz jednoznaczne relacje pozycji; sam importowany zwrot bez zamowienia pozostaje read-only.
- Basic Auth w Nginx/Apache zostalo potwierdzone przez wlasciciela. Nie testowano konfiguracji VPS. Przed wlaczeniem write cale HTTPS i wszystkie trasy musza byc chronione, bez bezposrednio publicznego portu aplikacji omijajacego proxy.
- Nie ma testu LIVE zlecajacego rzeczywisty zwrot. Nie nalezy utozsamiac testow fikcyjnego HTTP z potwierdzeniem operacji na koncie Allegro.
- Lokalnie dostepny jest XAMPP MariaDB 10.4.32, nie MySQL 8. Nie uruchamiano go ani nie zmieniano jego baz. SQL nowej migracji zweryfikowano gramatyka MySQL 8.0.46, a wykonanie struktury na SQLite :memory: i lokalnym SQLite.
- Test zmiany rejestru pomiedzy HTTP a blokada jest deterministycznym testem regresyjnym, nie dowodem wieloprocesowym na MySQL.

## Zmienione pliki

### Utworzone

- `Modules/Integrations/Allegro/Http/Controllers/AllegroRefundCommandController.php`
- `Modules/Integrations/Allegro/Jobs/ProcessAllegroRefundCommandJob.php`
- `Modules/Integrations/Allegro/Models/AllegroRefundCommand.php`
- `Modules/Integrations/Allegro/Services/AllegroRefundCommandService.php`
- `Modules/Integrations/Allegro/Services/AllegroRefundContextService.php`
- `database/migrations/2026_10_22_040000_add_allegro_refund_commands.php`
- `resources/views/returns/_refund-commands.blade.php`
- `resources/views/returns/refund-payment.blade.php`
- `tests/Feature/AllegroRefundCommandsTest.php`
- `tests/Unit/AllegroRefundCommandMigrationTest.php`
- `docs/allegro-4b2-report.md`

### Zmodyfikowane

- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Models/AllegroPaymentRefund.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Integrations/Allegro/Services/AllegroOAuthService.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Services/OrderReturnRefundService.php`
- `resources/views/integrations/allegro/_refunds.blade.php`
- `resources/views/orders/partials/refunds.blade.php`
- `resources/views/returns/_refunds.blade.php`
- `resources/views/returns/show.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/AllegroRefundsTest.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete pliki: brak.

## Migracja i lokalna kopia

Migracja: `2026_10_22_040000_add_allegro_refund_commands`.

Kopia spojna wykonana przez SQLite VACUUM INTO:

`storage/app/private/backups/database_before_allegro_4b2_20261022_220840.sqlite`

SHA-256: `fe931c2603e0e9a6d3491c08a799f08fa9ce84c72bce7df783bb7ab367d7278f`, rozmiar 7766016 bajtow, quick_check=ok, foreign_key_check=0 naruszen. Kopia jest prywatna i nie trafia do Git.

Po migracji: quick_check=ok, foreign_key_check=0, 0 zlecen i 0 kont z wlaczonym write. Porownano licznosc i hash wszystkich wierszy orders, order_items, invoices, invoice_items, order_returns, order_return_items, allegro_payment_refunds i order_return_refund_allocations przed/po: bez zmian.

Nowe FK: `arc_account_fk`, `arc_order_fk`, `arc_return_fk` (nullOnDelete) i `arc_refund_fk` (restrictOnDelete). Indeksy unikalne: `arc_command_unique`, `arc_refund_unique`, `arc_external_unique`. Pozostale: `arc_order_identity_index`, `arc_due_index`. Wszystkie sa jawne i mieszcza sie w 64 znakach. Nie zmieniano wczesniej wykonanych migracji. Down odmawia usuniecia finansowej historii.

## Testy

Bezpieczny runner izoluje SQLite :memory:, storage, cache, logi i temp oraz blokuje rzeczywista siec:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=AllegroRefundCommand --stop-on-defect
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="Allegro|OrderReturnsTest|InvoiceReturnContextTest|MySqlMigrationIdentifiersTest" --stop-on-defect
```

Wyniki koncowe: 56 testow nowego zakresu (540 asercji) i 526 testow lacznego zakresu (3296 asercji) przechodzi. Testy obejmuja walidacje kwot, dostawy, tozsamosci, scope, sesji, expiry, podwojny submit, stale dane/rejestr, HTTP 401/422/5xx, timeout, recovery workera, PARTIAL, wszystkie strony historii, normalizacje JSON MySQL, prywatnosc logow, User-Agent na wszystkich nagranych zadaniach, zachowanie finansowej historii i SQL MySQL.

PHP lint: 24 pliki PHP/Blade bez bledow. Pint: 18 plikow PHP, wynik passed. git diff --check: bez bledow bialych znakow; Git sygnalizuje tylko normalizacje CRLF/LF w product-spec.md. Test 4B.1 zaktualizowano tylko w zakresie nowego, domyslnie nieaktywnego przycisku.

Polecenia kontroli plikow (PowerShell, bez zmian danych):

```powershell
$paths = @(git diff --name-only -- '*.php') + @(git ls-files --others --exclude-standard -- '*.php')
foreach ($path in $paths) { & C:\xampp\php\php.exe -l $path }
$php = $paths | Where-Object { $_ -notlike '*.blade.php' }
C:\xampp\php\php.exe vendor/bin/pint --test @php
git diff --check
git diff --stat
```

git diff --stat dla sledzonych plikow: 16 plikow, 163 dodane i 8 usunietych linii. Dodatkowo powstalo 11 nowych plikow wymienionych powyzej, ktorych zwykly diff --stat nie uwzglednia przed dodaniem do indeksu. Nie wykonywano git add.

Kontrola przegladarki na lokalnym dzialajacym serwerze potwierdzila liste zwrotow, karte zwrotu z nieaktywnym przyciskiem oraz nowa sekcje z oboma przelacznikami wylaczonymi. Nie zapisywano ustawien i nie otwierano aktywnego formularza z prawdziwym API; formularz oraz potwierdzenie sa renderowane w testach z fikcyjnym HTTP.

## Wdrozenie na VPS

1. Wykonaj sprawdzona kopie bazy i wdroz pliki standardowym procesem wlasciciela. Nie wykonuj migrate:fresh, rollback ani kasowania tabel.
2. Po zweryfikowaniu `.env` wskazujacego staging wykonaj z katalogu aplikacji:

```bash
php artisan migrate:status --path=database/migrations/2026_10_22_040000_add_allegro_refund_commands.php
php artisan migrate --path=database/migrations/2026_10_22_040000_add_allegro_refund_commands.php --force
```

3. Odswiez cache aplikacji zgodnie z dotychczasowa procedura wdrozenia i zrestartuj procesy integrations/Main Queues. Wlaczony scheduler musi wykonywac istniejace schedule:run.
4. Otworz Integracje -> Allegro -> Zwroty. Sprawdz, ze reczne zlecanie jest wylaczone, a import towarow i refundacji zachowal ustawienia.
5. Sprawdz Basic Auth bez zalogowania na calym panelu, w tym nowych trasach, oraz brak obejscia reverse proxy. Nie wlaczaj write, jesli ten warunek nie jest spelniony.

Przy czesciowej awarii DDL na MySQL sprawdz SHOW CREATE TABLE oraz wpis migrations przed wznowieniem. Nie usuwaj tabeli ani historii zlecen. Poprawka wymaga wtedy osobnej oceny rzeczywistej struktury.

## Reczna aktywacja i weryfikacja

1. Integracje -> Allegro -> Zwroty -> Reczne zwroty wplat. Zaznacz zgode na uprawnienie write i zapisz, pozostawiajac samo zlecanie wylaczone.
2. Jesli konto nie ma payments:write, wlasciciel ponownie laczy konto Allegro i wykonuje test polaczenia. Zmiana sprzedawcy/srodowiska wylacza write.
3. Po sprawdzeniu zabezpieczen wlasciciel moze wlaczyc reczne zlecanie. Import 4B.1 nie musi byc wlaczony dla odczytu wyniku wlasnego zlecenia.
4. Otworz zgodna karte zwrotu, Zwroc wplate. Sprawdz produkty, kwoty i domyslnie niezaznaczona dostawe. Przejdz do podsumowania i anuluj, bez zatwierdzania pieniedzy.
5. Rzeczywisty test finansowy jest osobna decyzja wlasciciela, najlepiej na przygotowanym koncie sandbox; zadanie wdrozeniowe go nie wykonuje.

## Dokumentacja API

- [Oficjalny Swagger Allegro](https://developer.allegro.pl/swagger.yaml): InitializeRefund, RefundDetails i GET/POST /payments/refunds.
- [Obsluga zamowien](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR).
- [Wymagane order.id i commandId](https://developer.allegro.pl/news/zwroty-platnosci-16-grudnia-2025-pola-order-id-i-commandid-oznaczymy-jako-wymagane-k1bEgZLMvSM).
- [OAuth i zakresy uprawnien](https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR).
