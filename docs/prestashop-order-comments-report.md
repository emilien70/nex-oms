# PrestaShop - komentarze kupujacego do zamowienia

## Zakres

Dodano pobieranie publicznych komentarzy klienta z natywnego zasobu `messages`.
Nie dodano pelnej korespondencji `customer_threads` / `customer_messages` ani
wysylania wiadomosci do sklepu. Nie zmieniono pol uwag sprzedawcy, platnosci,
produktow, dokumentow, numeracji ani automatyzacji.

Po zatwierdzeniu importu powstaje osobny job na kolejce `integrations`.
Pobieranie i retry dotycza tylko komentarzy. Brak GET do `messages` lub blad
kolejki nie zamienia udanego importu zamowienia w nieudany. Diagnostyka loguje
lokalne ID powiazania i etap bledu, bez tresci komentarza, danych klienta i klucza API.
Nie ma nowego crona ani masowego uzupelniania starych zamowien.

Wpisy wymagaja zgodnych ID zamowienia i klienta, publicznego `private=0` oraz
`id_employee=0`. Autor pochodzi z danych klienta, nie adresata przesylki.
Idempotencje zapewnia unikalny indeks oraz transakcja blokujaca konto,
powiazanie i zamowienie. Po HTTP ponownie sprawdzana jest tozsamosc powiazania
i konta. Puste odpowiedzi i bledy nie usuwaja historii.

Karta zamowienia pokazuje komentarze chronologicznie w istniejacym niebieskim
bloku ponad formularzem e-mail. Lista otrzymuje szara koperte przez `withExists`.
Istniejace odswiezanie stanu porownuje hash lokalnego fragmentu; nie pobiera
komentarzy ze sklepu i nie nadpisuje rozpisanej wiadomosci e-mail.

## Pliki

Zmienione:

- `Modules/Integrations/Prestashop/Models/PrestashopOrderLink.php`
- `Modules/Integrations/Prestashop/Services/PrestashopClient.php`
- `Modules/Integrations/Prestashop/Services/PrestashopOrderImportService.php`
- `app/Http/Controllers/OrderStatusController.php`
- `app/Http/Controllers/OrdersController.php`
- `app/Models/Order.php`
- `resources/views/components/order-email-exchange.blade.php`
- `resources/views/orders/partials/list-indicators.blade.php`
- `resources/views/orders/show.blade.php`
- `tests/Feature/PrestashopOrderImportTest.php`
- `docs/architecture.md`
- `docs/product-spec.md`

Nowe:

- `Modules/Integrations/Prestashop/Jobs/FetchPrestashopOrderMessagesJob.php`
- `Modules/Integrations/Prestashop/Models/PrestashopOrderMessage.php`
- `Modules/Integrations/Prestashop/Services/PrestashopOrderMessageService.php`
- `app/Console/Commands/FetchPrestashopOrderMessages.php`
- `database/migrations/2026_10_26_020000_create_prestashop_order_messages_table.php`
- `resources/views/orders/partials/buyer-comments.blade.php`
- `tests/Feature/PrestashopOrderMessagesTest.php`
- `docs/prestashop-order-comments-report.md`

Nie usunieto plikow. Prywatne skrypty weryfikacyjne, zrzuty i raporty JUnit
sa w ignorowanym `storage/app/private/qa/prestashop-comments/`.

## Migracja i lokalne uzupelnienie

Po osobnej zgodzie operatora utworzono spojna kopie SQLite przez PDO
`VACUUM INTO`, ze zrodlem otwartym tylko do odczytu:

`storage/app/private/backups/prestashop-comments-20261026_150939-97414b66.sqlite`

- Rozmiar: 10801152 bajtow.
- SHA256: `f05748b7f491cf9286f79b2c950558b3bf6888518d23469b3f0300e0bab01adf`.
- `PRAGMA integrity_check`: `ok`.

Uruchomiono lokalnie tylko:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_26_020000_create_prestashop_order_messages_table.php --force --no-interaction
C:\xampp\php\php.exe artisan prestashop:order-comments 253
```

Migracja: DONE. Nowa tabela `prestashop_order_messages`, bez backfillu.
Nazwy ograniczen MySQL: `ps_order_messages_link_fk` (nullable, nullOnDelete)
i `ps_order_messages_external_unique`. `down()` odmawia usuniecia niepustej
historii. Nie wykonywano rollbacku ani odtwarzania bazy.

Pierwsze pobranie bylo zablokowane przez ograniczenie sieci narzedzia i nie
zapisalo komentarza. Po dopuszczeniu polaczenia odczytowego wykonano GET do
sklepu: OMS 253 / PrestaShop 13646, komentarz 966, 184 znaki, data zrodlowa
`2026-10-25 17:25:41`. SHA256 tekstu:
`22d7170f5c238bbcc37c22193b98242ba71823895a2ca34ab8a6d978d1056745`.

Po operacji tabela zawierala dokladnie jeden komentarz. Porownanie z kopia
potwierdzilo niezmienione wszystkie kolumny zamowienia 253, jego pozycji,
zdarzen, powiazania i platnosci PrestaShop, a takze wszystkich faktur,
pozycji dokumentow, licznikow oraz serii numeracji. Integralnosc bazy: `ok`.
Nie uruchamiano importu, synchronizacji platnosci ani zmian statusu.

## Testy

Konfiguracja testowa: SQLite `:memory:`, HTTP symulowane i blokada nieoczekiwanych
polaczen; brak rzeczywistych transmisji platniczych lub KSeF.

- Nowe `PrestashopOrderMessagesTest`: **28 PASS, 89 asercji**.
- Zestaw wszystkich glownych testow `Prestashop*Test.php`,
  `AllegroAutomaticOrderImportTest`, `AllegroOrderImportTest` i
  `OrderEmail*Test.php`: **288 PASS, 1710 asercji**.
- Szerszy filtr `Prestashop|AllegroAutomaticOrderImport|AllegroOrderImport|OrderEmail|OrderStatusPolling`:
  **320 testow, 317 PASS, 3 FAIL, 1899 asercji, 0 errors**.

Trzy niepowodzenia to:

- `OrderIdentifiersTest::test_prestashop_source_uses_configured_integration_name_in_order_views`
  (linia 71, oczekiwanie starego HTML nazwy zrodla).
- `OrderIdentifiersTest::test_prestashop_source_falls_back_to_channel_name_without_integration_link`
  (linia 99, analogiczne oczekiwanie starego HTML).
- `OrderStatusPollingTest::test_order_view_refreshes_after_automation_without_periodic_order_polling`
  (linia 78, oczekiwanie starego wywolania `refreshOrderState`).

Nie poprawiano tych asercji w tym zadaniu. Odpowiedni stary wzorzec wywolania
jest nieobecny takze w widoku na HEAD `dd5f369b4174d5810082e7743a6d240539d1175b`;
partial zrodel zamowienia nie byl zmieniany. Nie wykonano pelnego izolowanego
baseline na HEAD ani pelnego zestawu wszystkich modulow, zatem szerszego
przebiegu nie deklarujemy jako calkowicie zielonej regresji.

Testy obejmuja prywatne notatki, pracownikow, obcego klienta/zamowienie,
brakujace flagi, duplikaty, limit i paginacje, atomowy zapis, zmiane konta
podczas HTTP, bledy 403/503, wycofanie transakcji, odseparowanie bledu
dispatchu, komenda dla jednego zamowienia, bezpieczne wyswietlanie HTML,
sygnatury widoku, FK nullOnDelete i blokade destrukcyjnego rollbacku.
Zabezpieczenie duplikatow sprawdzono tez na poziomie indeksu bazy.

Zweryfikowano SQL generowany przez gramatyke MySQL 8.0.46, bez polaczenia
z prawdziwym serwerem MySQL. Nie wykonano testu rownoleglych procesow na
rzeczywistym MySQL; blokady i indeks sa obecne, ale nie jest to test obciazeniowy.

`vendor/bin/pint --dirty`: PASS. `php -l` dla wszystkich zmienionych i nowych
plikow PHP/Blade: PASS. `git diff --check`: PASS.

Playwright / lokalny Edge, szerokosci 1440 i 390 px: komentarz widoczny,
184 znaki, niebieskie tlo `rgb(204, 235, 246)`, bez przepelnienia poziomego;
koperta widoczna na liscie; 0 bledow JavaScript. Nie klikano akcji biznesowych.

## Uruchomienie i reczna kontrola

1. Zrestartowac tylko lokalne Main Queues, aby workery wczytaly nowa klase joba.
2. Otworzyc `/orders/253`: komentarz ma byc u gory Wymiany wiadomosci,
   z autorem klienta i data ze sklepu.
3. Na liscie wyszukac 253: szara koperta ma byc widoczna przy ikonach.
4. Dla nowego importu PrestaShop komentarz powinien pojawic sie po pracy
   kolejki; odswiezenie strony nie wykonuje importu ani HTTP do sklepu.

Na innym srodowisku potrzebna jest powyzsza migracja przed wznowieniem
aplikacji i restart workerow `integrations`. Klucz PrestaShop potrzebuje GET
do `messages`; nie trzeba dodawac nowego crona ani modulu w sklepie.
Nie wykonywano zadnych operacji na VPS, commit, push ani deploymentu.
