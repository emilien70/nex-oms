# PrestaShop: oplacenie wedlug flagi i opcjonalny brak ID

## Zakres

PrestaShop jest zrodlem deklaracji oplacenia: rzeczywisty status z `paid=true`
pozwala uznac pelna kwote zamowienia nawet przy braku natywnego rekordu wplaty.
Nie tworzymy fikcyjnego ID transakcji ani fikcyjnej wplaty `order_payment`.
Zasada odpowiada opisowi w [pomocy Base](https://base.com/pl-PL/pomoc/faq/sklep/).
Nie weryfikuje niezaleznie salda u operatora platnosci.

Nowe ustawienie w Platnosciach: `Wyswietlaj ostrzezenie w przypadku braku
identyfikatora transakcji: Tak/Nie`, domyslnie Nie. Dla `paid=true` i braku
transakcji pokazuje czerwony wykrzyknik obok kwoty, z podpowiedzia `Brak ID
platnosci`. W interfejsie teksty maja polskie znaki. Ostrzezenie jest dostepne
przez hover, focus i click, rowniez po podmianie fragmentu AJAX. Czytanie karty
nie odpytuje API PrestaShop.

## Integralnosc

- Transakcyjna obserwacja blokuje powiazanie i zamowienie.
- Flaga jest czytana z konfiguracji statusow danego konta, nie z nazwy lub ID 2.
- Status nie zeruje wplat po zmianie na nieoplacony i nie zleca zwrotu srodkow.
- Waluta i wartosc zdalnego zamowienia musza odpowiadac zamowieniu OMS.
- Uznanie jest zapisane osobno jako `status_paid_amount` / `status_paid_at`.
- Natywne rekordy mieszczace sie lacznie w uznanej kwocie sa odzwierciedlone
  jako `mirrored`, `matched_payment_source=prestashop_status`, bez dopisania
  kolejnej kwoty ani ponownego wywolania automatyzacji.
- `payment.registered` nadal ma zrodlo `prestashop`; osobna tozsamosc UUID
  deklaracji nie koliduje z natywnym rekordem o takim samym liczbowym ID.
- Wolno uzupelnic puste ID transakcji. Nie wolno podmienic niepustego ID,
  zmienic kwoty lub waluty zapisanej wplaty ani wycofac starego rekordu.
- Ograniczony start: do 10 najnowszych zywych powiazan bez obserwacji na
  przebieg, bez zerowania istniejacego kursora. Dalszy import i synchronizacja
  zachowuja dotychczasowa kolejke `integrations` i scheduler co minute.
- Zmiana salda moze uruchomic skonfigurowane akcje automatyczne otrzymania wplaty.

## Zmienione pliki

- `Modules/Integrations/Prestashop/Http/Controllers/PrestashopPaymentSyncSettingsController.php`
- `Modules/Integrations/Prestashop/Http/Requests/UpdatePrestashopPaymentSyncSettingsRequest.php`
- `Modules/Integrations/Prestashop/Models/PrestashopAccount.php`
- `Modules/Integrations/Prestashop/Models/PrestashopOrderLink.php`
- `Modules/Integrations/Prestashop/Services/PrestashopClient.php`
- `Modules/Integrations/Prestashop/Services/PrestashopOrderImportService.php`
- `Modules/Integrations/Prestashop/Services/PrestashopOrderPreviewService.php`
- `Modules/Integrations/Prestashop/Services/PrestashopPaymentAccountingService.php`
- `Modules/Integrations/Prestashop/Services/PrestashopPaymentStatusService.php` (nowy)
- `Modules/Integrations/Prestashop/Services/PrestashopPaymentSyncService.php`
- `Modules/Integrations/Prestashop/Services/PrestashopPaymentSyncSettingsService.php`
- `app/Events/OrderPaymentRegistered.php`
- `app/Http/Controllers/OrderStatusController.php`
- `database/migrations/2026_10_26_010000_add_prestashop_paid_status_tracking.php` (nowy)
- `resources/views/integrations/prestashop/edit.blade.php`
- `resources/views/orders/partials/order-info-view.blade.php`
- `resources/views/orders/partials/payments.blade.php`
- `resources/views/orders/show.blade.php`
- `tests/Feature/PrestashopOrderImportTest.php`
- `tests/Feature/PrestashopPaidStatusTest.php` (nowy)
- `docs/architecture.md`
- `docs/product-spec.md`
- `docs/prestashop-paid-status-report.md` (nowy)

Nie usunieto plikow. Bez commit, push, zmian konfiguracji KSeF ani operacji na VPS.

## Migracja i kopia lokalna

Po zgodzie operatora wykonano kopie spojnego stanu lokalnego SQLite:

`storage/app/private/backups/prestashop-paid-status-20261026_101920-b4f6fb5b.sqlite`

- Rozmiar: 10219520 bajtow.
- SHA256: `5b6e8c5c06e7bd6a37aad2926d41d77427fc93f89bcff1888660a343b6ef19ae`.
- `PRAGMA integrity_check`: `ok`.
- Kopia przez PDO `VACUUM INTO` ze zrodlem otwartym `mode=ro`. Jest to
  [spojna kopia bez modyfikowania zrodla](https://www.sqlite.org/lang_vacuum.html#vacuum_with_an_into_clause).
  Rozszerzenie PHP SQLite3 nie jest dostepne; proba jego uzycia zakonczyla sie
  przed utworzeniem kopii lub uruchomieniem migracji. Nie instalowano zaleznosci.

Uruchomiona tylko lokalnie:

```text
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_26_010000_add_prestashop_paid_status_tracking.php --force --no-interaction
```

Wynik: DONE. Dodaje bool `warn_missing_transaction_id` (false) na koncie i trzy
pola techniczne powiazania. Nie ma DML aktualizujacego historyczne zamowienia,
faktury lub wplaty. Pozniejsza zwykla synchronizacja stosuje nowa polityke.
Down odmawia usuniecia zapisanej historii uznania. Nie wykonywano rollback.
Przed lokalna migracja nowa polityka jest chroniona sprawdzeniem dostepnosci
wszystkich kolumn, aby uniknac czesciowego uruchomienia przy starym schemacie.

## Weryfikacja

- PHP 8.2/SQLite `:memory:` wedlug `phpunit.xml`, API symulowane.
- Testy wszystkich klas `tests/Feature/Prestashop*.php`, powiazan produktow
  PrestaShop, wszystkich `PayNow*.php`, `OrderPaymentStateTest` i
  `OrderAjaxUpdatesTest`: 201 testow PASS, 1456 asercji.
- `php vendor/bin/pint --dirty`: PASS.
- `php -l` wszystkich zmienionych i nowych plikow PHP/Blade: PASS.
- `git diff --check`: PASS; Git ostrzega jedynie o normalizacji CRLF/LF dokumentacji.
- Wczesniejszy szerszy filtr `Prestashop|PrestaShop` zglosil niepowodzenia,
  w tym asercje etykiety zrodla PrestaShop w `OrderIdentifiersTest`.
  Przebieg przerwano podczas formatowania duzego raportu bledow. Nie jest on
  liczony jako zakonczona regresja. Testy te nie byly zmieniane ani naprawiane.
  Nie wykonano nowego, izolowanego baseline HEAD ani pelnej regresji projektu.
- Lokalny GET ustawien PrestaShop: HTTP 200, nowe pole obecne, domyslnie Nie.
- Nie testowano na rzeczywistym MySQL ani rzeczywistej transakcji PayNow.
- Nie uruchamiano recznej synchronizacji ani wysylek do zewnetrznych API.

## Reczna weryfikacja

1. Zrestartowac tylko lokalny proces Main Queues, nie VPS.
2. W Integracje -> PrestaShop -> Platnosci sprawdzic, ze ostrzezenie ma Nie.
3. Poczekac na zwykla synchronizacje. Dla statusu paid=true i braku wplaty
   ma zostac uznana pelna kwota bez fikcyjnego rekordu platnosci. Przy koncowym
   odczycie zamowienie 270 mialo juz natywna wplate 609,00 PLN z ID transakcji,
   zaksiegowana przez zwykla synchronizacje 26.10.2026 o 09:11:05, a nie przez
   nowa polityke. Nie nalezy usuwac tego wpisu w celu testowania ikony.
4. Przelaczyc ostrzezenie na Tak i zapisac. Dla zamowienia z paid=true i brakiem
   natywnego ID ma sie pokazac czerwony wykrzyknik z podpowiedzia Brak ID
   platnosci. Zamowienie 270 nie powinno go teraz pokazywac, poniewaz ma ID.
5. Sprawdzic hover, klawiature i dotkniecie w telefonie. Wylaczenie opcji
   ma usunac ikone bez zmiany kwoty lub statusu.
6. Pozniejsze uzupelnienie ID ma usunac ikone po synchronizacji i odswiezeniu
   stanu strony, bez ponownego naliczenia oraz zdarzenia payment.registered.

Nie potwierdzano rzeczywistej wplaty u operatora; zastosowano jawnie wybrana
polityke zaufania deklaracji PrestaShop. Reczne zmiany salda po wczesniejszym
uznaniu nie sa automatycznie nadpisywane ta sama deklaracja. Kwota lub waluta
inna niz w OMS wymaga weryfikacji, nie jest automatycznie przeliczana.
