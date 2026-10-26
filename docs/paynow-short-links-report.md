# PayNow - krotkie linki /pay/

## Zachowanie

Obecny przycisk generowania linku kopiuje teraz adres OMS
`https://oms.multi-click.pl/pay/<bezpieczny-kod>` na VPS. Lokalnie generator
tras korzysta z domeny lokalnej instancji. Nie wpisano domeny VPS na sztywno
ani nie zmieniono `.env`, konfiguracji PayNow i APP_KEY.

32-znakowy kod pochodzi z 24 bajtow `random_bytes()` (192 bity), kodowanych
base64url. Kod nie zawiera numeru zamowienia, e-maila ani danych klienta.
Ta sama proba platnosci dostaje jeden alias, ponowne kopiowanie zachowuje URL.
Stare dlugie linki i zaszyfrowany `paynow_payments.payment_url` sa zachowane.

GET/HEAD aliasu nie wywoluje API, nie tworzy proby, nie ksieguje i nie
zapisuje zdarzen ani czasu otwarcia. Dla zgodnej aktywnej proby zwraca 302
do zapisanego PayNow URL. Kwota i waluta musza odpowiadac biezacemu saldu
zamowienia, dane konta i srodowiska nie moga sie zmienic, nie moze istniec
inna konfliktowa proba. Parametry query nie wybieraja celu ani statusu.

Potwierdzona, wymagajaca weryfikacji, starsza, zakonczona lub niezgodna z
aktualnym saldem proba kieruje do istniejacej podpisanej strony powrotu.
Ta strona zachowuje dotychczasowy bezpieczny POST statusu i ponowienia.
Potwierdzenie pochodzi z podpisanego webhooka lub zweryfikowanego API,
nie z parametrow adresu. Nie ma nowej sciezki ksiegowania.

Nieznany, odwolany albo wygasly alias daje neutralna strone 404 bez danych.
Brak aktywnego konta, usuniete/anulowane zamowienie, zmieniona tozsamosc
lub podmieniony cel tez nie uprawniaja do przekierowania. Link jest wazny
do dnia po `valid_until`; przy historycznym null do 92 dni od utworzenia
proby, zgodnie z dotychczasowym podpisem strony powrotu. GET nie przedluza
waznosci ani nie generuje nowego kodu.

## Bezpieczenstwo

- Lookup po indeksowanym SHA256 kodu; pelny kod jest szyfrowany tylko do
  ponownego kopiowania i ukryty w serializacji modelu.
- HMAC z APP_KEY wiaze konto/srodowisko/credentiale, snapshot proby, termin,
  hash kodu i hash celu. Podmiana payment_id lub metadanych aliasu nie
  przenosi uprawnienia na inna probe.
- Wydanie aliasu blokuje probe; publiczny odczyt blokuje konto, zamowienie,
  probe i alias w kolejnosci zgodnej z przygotowaniem platnosci.
- Baza wymusza unikalnosc proby i hasha tokena. Przetwarzanie jest
  idempotentne; ponowne klikniecie nie tworzy kolejnego aliasu tej proby.
- Zewnetrzny cel wymaga HTTPS w domenie PayNow, zgodnosci Sandbox/Produkcja,
  bez userinfo, nietypowych portow, fragmentow, backslash i znakow sterujacych.
  Zmiana zapisanego URL lub ID proby dostawcy nie podmienia celu aliasu.
- Publiczna trasa korzysta z limitera 60/min/IP oraz no-store, no-referrer,
  noindex, nosniff i DENY. Nie jest to publiczny dostep do karty zamowienia.
- Przed migracja generowanie zwraca 503, zanim wywola PayNow. Odczyt aliasu
  bez tabeli daje neutralne 404. Nie ma fallbacku udostepniajacego dlugi URL.

## Pliki

Nowe:

- `Modules/Integrations/PayNow/Models/PayNowShortLink.php`
- `Modules/Integrations/PayNow/Services/PayNowShortLinkService.php`
- `Modules/Integrations/PayNow/Http/Controllers/PayNowShortLinkController.php`
- `database/migrations/2026_10_26_030000_create_paynow_short_links_table.php`
- `tests/Feature/PayNowShortLinkTest.php`
- `docs/paynow-short-links-report.md`

Zmienione w tym zadaniu:

- `Modules/Integrations/PayNow/Http/Controllers/PayNowPaymentLinkController.php`
- `Modules/Integrations/PayNow/Services/PayNowReturnUrlService.php`
- `routes/web.php`
- `tests/Feature/PayNowPaymentLinkTest.php`
- `docs/006-Deployment.md`
- `docs/architecture.md` (sekcja PayNow; zachowano wczesniejsze zmiany PrestaShop)
- `docs/product-spec.md` (analogicznie)

Nie usuwano plikow. Wczesniejsze niezacommitowane zmiany komentarzy
PrestaShop pozostaly zachowane. Bez commit, push, instalacji zaleznosci,
zmian na VPS, wywolan schedulera lub rzeczywistych platnosci.

## Migracja i lokalna kopia

Po osobnej zgodzie operatora wykonano spojna kopie SQLite przez PDO
`VACUUM INTO`, ze zrodlem otwartym tylko do odczytu:

`storage/app/private/backups/paynow-short-links-20261026_154127-e84478c6.sqlite`

- Rozmiar: 10903552 bajtow.
- SHA256: `f47c540b4d4e2b0e691590a509be36da33cdb87795ed835bd50fa37c6907e854`.
- `PRAGMA integrity_check`: `ok`.

Uruchomiono tylko lokalnie:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_26_030000_create_paynow_short_links_table.php --force --no-interaction
```

Wynik: DONE. Tabela `paynow_short_links` zawierala po weryfikacji **0** wierszy.
Nie generowano aliasow ani nowych prob na danych operatora.
Porownanie z kopia potwierdzilo niezmienione wszystkie wiersze i kolumny
`orders`, `order_items`, `invoices`, `invoice_items`, `invoice_series`,
`invoice_number_counters`, `paynow_accounts`, `paynow_payments` i
`paynow_payment_events`. Integralnosc lokalnej bazy po migracji: `ok`.

Migracja jest addytywna, bez backfillu. Ograniczenia:
`paynow_short_payment_fk` (nullable, nullOnDelete),
`paynow_short_payment_unique`, `paynow_short_token_unique`.
Wszystkie sa krotsze niz 64 znaki. `down()` odmawia usuniecia niepustej
tabeli, zeby rollback nie niszczyl wydanych linkow.

## Testy

Sprawdzono `phpunit.xml`: testy uzywaja SQLite `:memory:`, a HTTP jest
symulowane z blokada nieoczekiwanych polaczen. Nie wysylano platnosci,
webhookow potwierdzajacych wplate ani dokumentow do KSeF.

```powershell
C:\xampp\php\php.exe artisan test --compact tests/Feature/PayNowShortLinkTest.php
C:\xampp\php\php.exe artisan test --compact --filter="PayNow|OrderPaymentState|PrestashopPaymentSync|PrestashopPaidStatus|PrestashopOrderMessages"
```

- Nowe testy krotkich linkow: **47 PASS, 416 asercji**.
- Zestaw powiazany: **214 PASS, 1292 asercje**, lacznie z powyzszymi testami.
- Pint dla 9 plikow PHP tego zadania: PASS.
- `php -l` dla tych 9 plikow: PASS.
- `git diff --check`: PASS.
- Trasa GET/HEAD `/pay/{token}` widoczna w `route:list`.

Testy obejmuja stabilnosc kodu i szyfrowanie, brak efektow GET/HEAD,
falszowanie query/statusu, oplacona probe z numerem transakcji, waznosc,
saldo/walute/e-mail, nowa i starsza nierozstrzygnieta probe, needs_review,
zmiane konta i credentiali, podmiane ID/celu/metadanych, zlosliwe URL,
Sandbox/Produkcja, brak migracji, limity, unikalnosc i nullOnDelete,
blokade destrukcyjnego down i zachowanie dotychczasowego powrotu/retry.

SQL migracji sprawdzono gramatyka MySQL 8.0.46. Nie wykonywano jej na
rzeczywistym MySQL ani testu wielu rownoleglych procesow. Nie uruchamiano
pelnego zestawu wszystkich modulow; wynik dotyczy zakresu powyzej.

Playwright / lokalny Edge, szerokosci 1280 i 390 px: neutralna strona
nieistniejacego kodu daje 404, bez przepelnienia, formularzy, zewnetrznych
zadan i bledow JavaScript. Zrzuty oraz prywatny skrypt kontroli sa w
`storage/app/private/qa/paynow-short-links/`. Nie otwierano prawdziwej bramki.

## Weryfikacja reczna i VPS

1. Na lokalnym zamowieniu testowym z poprawnymi danymi kliknac dotychczasowe
   generowanie linku. To swiadoma akcja tworzaca probe w PayNow; do testow
   uzywac wylacznie konta Sandbox.
2. Skopiowany adres ma miec `/pay/` i 32-znakowy kod, bez dlugiego tokena JWT.
3. Ponowne kopiowanie tej samej nadal waznej proby ma dac ten sam adres.
4. Po otwarciu linku przegladarka przechodzi do PayNow. Po potwierdzeniu
   wplaty alias ma skierowac do strony OMS z aktualnym potwierdzeniem/ID.
5. Nieistniejacy lub odwolany kod nie moze ujawniac danych klienta i kwoty.

Wdrozenie na VPS wymaga migracji i waskiego wyjatku Basic Auth dla
`^/pay/[A-Za-z0-9_-]{32}$`, z prawidlowym front controllerem PHP oraz
ochrona tokenow w logach. Dokladny blok i testy sa w
`docs/006-Deployment.md`, sekcja Krotkie linki platnosci PayNow /pay/.
Wyjatki trzech tras powrotu nadal sa wymagane. Nie otwierac calego `/pay/`
ani panelu. Nie dodaje sie nowego crona ani workera.

W tym zadaniu nie sprawdzano konfiguracji Nginx ani Basic Auth na VPS.
Alias nie uniewaznia starego dlugiego URL PayNow i nie anuluje platnosci
u operatora. Samo skrocenie linku nie zmienia tego ograniczenia.
