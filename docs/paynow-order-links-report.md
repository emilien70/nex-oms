# Stale linki platnosci PayNow

## Nowy format adresu i numer prob

Przycisk kopiuje /zamowienie/{numer-NEX}/{kod}/pay z 10 rozrozniajacymi
wielkosc liter znakami alfanumerycznymi. Kod jest wyprowadzany ze
stalego losowego tokenu zamowienia, niezaleznie od strony informacyjnej.
Nie zmieniano tabel ani historycznych kodow. Publiczne odwolanie
poprzednich adresow /pay/ pozostaje osobnym krokiem VPS po sprawdzeniu
nowej trasy; instrukcja w docs/006-Deployment.md. Portal klienta nadal
dziala po odwolaniu, bo po dotychczasowej kontroli kieruje wprost do PayNow.

externalId nowych prob to NEX-{numer}-{kolejnosc}. Wybiera go transakcja
pod blokada zamowienia. Istniejaca proba zachowuje externalId i klucz
idempotencji takze przy powtorzeniu zadania, a stare wpisy nie zmieniaja
identyfikatorow. Bez nowej migracji, wywolan prawdziwego API ani zmian VPS.

W tym kroku zmieniono routes/web.php, app/Providers/AppServiceProvider.php,
Modules/CustomerPortal/Http/Middleware/RestrictCustomerPortalHost.php,
Modules/CustomerPortal/Services/PortalPaymentService.php,
Modules/Integrations/PayNow/Http/Controllers/PayNowShortLinkController.php,
Modules/Integrations/PayNow/Services/PayNowOrderLinkService.php,
Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php,
tests/Feature/PayNowPaymentLinkTest.php, tests/Feature/CustomerPortalPaymentsTest.php,
tests/Frontend/paynow-checkout-countdown.test.cjs i dokumentacje.

W izolacji SQLite :memory: testy PHP z filtrem PayNow|CustomerPortal|OrderAjaxUpdates
przeszly: 476 testow. Testy Node odliczania: 2 testy. Pint: PASS.
Weryfikacja reczna: na zamowieniu testowym skopiowac link dwa razy,
porownac adresy, otworzyc ekran z odliczaniem, a osobno podstawic numer
innego zamowienia w tej samej sciezce (oczekiwane 404).

## Poprzedni etap stalych linkow

Stan: 2026-11-18. Implementacja i migracja lokalna po zgodzie wlasciciela.
Bez wdrozenia VPS, commita, prawdziwych wywolan API i tworzenia platnosci.

## Zmienione pliki

Utworzone:

- Modules/Integrations/PayNow/Models/PayNowOrderLink.php
- Modules/Integrations/PayNow/Services/PayNowOrderLinkService.php
- database/migrations/2026_11_17_020000_create_paynow_order_links_table.php
- tests/Frontend/paynow-checkout-countdown.test.cjs
- docs/paynow-order-links-report.md

Zmienione:

- Modules/Integrations/PayNow/Http/Controllers/PayNowPaymentLinkController.php
- Modules/Integrations/PayNow/Http/Controllers/PayNowShortLinkController.php
- Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php
- Modules/Integrations/PayNow/Services/PayNowShortLinkService.php
- resources/views/paynow/return.blade.php
- tests/Feature/PayNowPaymentLinkTest.php
- docs/architecture.md
- docs/product-spec.md
- docs/006-Deployment.md

Brak usunietych plikow. Zachowano osobne, wczesniejsze zmiany podpowiedzi
drukarki i animacji powiadomien; nie naleza do tego etapu.

## Dzialanie

Adres /pay/{kod} dla zamowienia jest staly pomiedzy przygotowanymi przez
operatora probami tego samego kupujacego. Powiazanie aktualizuje sie dopiero
po jawnym przygotowaniu proby w OMS. Stare aliasy konkretnych prob oraz
bezposrednie URL PayNow nie zostaly przepiete.

Nowy adres pokazuje kwote, Zaplac i odliczanie 5 sekund. Przekierowanie
przechodzi przez ponowny odczyt tej samej sciezki z continue=1. GET i HEAD
nie wywoluja API, nie tworza prob, nie ksieguja i nie aktualizuja historii.
HEAD nie wykonuje 302 do PayNow. Bez JavaScript pozostaje dzialajacy link.

Nierozstrzygniete NEW/PENDING/ABANDONED, needs_review lub niezaksiegowane
CONFIRMED blokuja operatorowi dodatkowa probe. Sam uplyw lokalnej waznosci
nie jest uznawany za potwierdzenie EXPIRED. Stalego adresu nie wolno
przypisac do innego zamowienia; zmiana tozsamosci kupujacego wymaga nowego
kodu. Archiwum, anulowanie, kosz i usuniecie zamowienia odbieraja dostep.
Oplacenie zamowienia pokazuje stan rozliczony, a zmiana kwoty blokuje
stara, niezgodna probe zamiast pobierania nieaktualnej kwoty.

Kod jest niezalezny od strony informacyjnej. Nie udostepnia produktow,
dokumentow, adresow, nazwiska ani e-maila. Obowiazuja HTTPS, no-store,
no-referrer, noindex, CSP nonce, limitery dotychczasowej trasy i brak
parametrow wybierajacych zewnetrzny cel.

## Migracja lokalna

Uruchomiono tylko:

```text
php artisan migrate --path=database/migrations/2026_11_17_020000_create_paynow_order_links_table.php --force --no-interaction
```

Wywolanie wykonano przez Artisan w tym samym procesie, ktory sprawdzil
APP_ENV=local i sciezke SQLite. Przyrost historii migracji zawieral
wylacznie ten wpis, a nowa tabela po migracji byla pusta.

Wczesniej wykonano spojny backup SQLite przez VACUUM INTO:

```text
storage/app/private/backups/database_before_paynow_order_links_20261118_091227_4db72c.sqlite
SHA256: 82d3a77bb9196cea2ac4c4bcfe27d888fcce759295f37bfacb29f19f524b175a
PRAGMA integrity_check: ok
```

Nowe nullable FK maja nullOnDelete. Dokumenty i proby platnosci nie sa
usuwane. Cofanie niepustej tabeli jest blokowane.

## Testy

W izolacji SQLite :memory:, array cache/session i z symulowanym HTTP:

```text
php vendor/bin/phpunit --do-not-cache-result --stop-on-failure --filter "PayNow|CustomerPortal"
OK: 457 testow, 3603 asercje

node --test tests/Frontend/paynow-checkout-countdown.test.cjs tests/Frontend/automation-activity-dismissal.test.cjs tests/Frontend/nex-printer-tooltip.test.cjs
OK: 30 testow
```

Pint --test dla osmiu plikow PHP tego etapu: PASS.
Chrome: rzeczywisty widok Blade na danych syntetycznych przy 360x800
i 1440x900. Przycisk, kwota i odliczanie widoczne bez poziomego overflow.
Nie otwierano bramki ani linkow prawdziwego zamowienia.

## Weryfikacja reczna

Wykonywac na koncie Sandbox i przygotowanym zamowieniu testowym, bez
zmiany credentiali rzeczywistego konta z nierozstrzygnietymi platnosciami.

1. Wygenerowac link przy Zaplacono. Ponowne kopiowanie ma dac ten sam adres.
2. Otworzyc adres: kwota, Zaplac i 5 sekund, potem PayNow.
3. Otworzyc adres z wylaczonym JavaScript: pozostaje Zaplac.
4. Po rozstrzygnietym EXPIRED przygotowac nowa probe w OMS. Adres dla
   klienta ma pozostac ten sam; wyslana wczesniej wiadomosc nadal dziala.
5. Zmienic saldo pomiedzy otwarciem a koncem odliczania: przekierowanie
   nie moze skierowac do proby z nieaktualna kwota.
6. Po zaksiegowaniu wplaty otworzyc adres: potwierdzenie, bez Zaplac.

## Ograniczenia

Brak testu prawdziwego zakupu, wywolan API lub wdrozenia VPS.
Istniejacy format i wyjatki Nginx /pay/ pozostaja zgodne; nalezy zachowac
wylaczone logowanie i cache. Zewnetrzne bezposrednie linki PayNow nie sa
zdalnie anulowane ta zmiana. W publicznym GET nie ma automatycznego
odswiezania statusu dostawcy; robi to dotychczasowy webhook/scheduler.
Niepewnej proby nie ponawia sie automatycznie.
