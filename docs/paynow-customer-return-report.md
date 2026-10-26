# PayNow - publiczna strona powrotu (PAYNOW.5)

Data: 2026-10-26. Implementacja lokalna, bez commita, push i wdrozenia VPS.

## Zakres

- Nowa, responsywna strona klienta bez panelu administracyjnego: `/paynow/powrot`.
- Potwierdzona platnosc: komunikat sukcesu, kwota i rzeczywisty `provider_payment_id` jako ID transakcji.
- Nieudana/odrzucona/wygasla platnosc: po sprawdzeniu API mozliwosc ponowienia.
- Oczekiwanie, nieznany nowy ID odzyskiwanej platnosci lub niepewny wynik komunikacji: brak tworzenia nowej platnosci.
- Oplacenie zamowienia innym sposobem nie jest falszywie prezentowane jako potwierdzenie tej konkretnej transakcji PayNow.
- Konfiguracja PayNow pokazuje bazowy adres strony z kopiowaniem. Wlasny adres pozostaje zachowany. Przy pustym polu lub dokladnym bazowym adresie OMS generowany jest indywidualny podpisany `continueUrl`.
- Historyczne linki nie sa przerabiane. Brak backfillu i zmian konfiguracji konta w bazie.

## Pliki

Utworzone:

- `Modules/Integrations/PayNow/Http/Controllers/PayNowCustomerReturnController.php`
- `Modules/Integrations/PayNow/Services/PayNowCustomerPaymentService.php`
- `Modules/Integrations/PayNow/Services/PayNowReturnUrlService.php`
- `resources/views/paynow/return.blade.php`
- `tests/Feature/PayNowCustomerReturnTest.php`
- `docs/paynow-customer-return-report.md`

Zmodyfikowane:

- `Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php`
- `Modules/Integrations/PayNow/Http/Controllers/PayNowSettingsController.php`
- `resources/views/integrations/paynow/edit.blade.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `docs/product-spec.md`
- `docs/architecture.md`
- `docs/006-Deployment.md`

Brak usunietych plikow. Pozostawiono istniejace niezacommitowane zmiany poprzednich etapow, w tym platnosci PrestaShop i parametry zestawow. Wspolne pliki dokumentacji zawieraja rowniez tamte zmiany.

## Integralnosc i bezpieczenstwo

Strona bazowa bez podpisu jest neutralna. Dane konkretnej proby wymagaja podpisanego adresu, waznego do dnia po `valid_until`. Podpis jest osobny dla kazdej trasy, oparty na istniejacym APP_KEY, bez jego zmiany. Parametry dopisywane przez PayNow nie stanowia dowodu zaplaty. Inny, nieznany ID z przekierowania nie sluzy do odczytu dowolnej transakcji API; czekamy na podpisany webhook.

GET nie inicjuje platnosci, nie kontaktuje sie z API i nie ksieguje wplaty. POST status korzysta z istniejacej projekcji i ksiegowania PayNow. POST ponowienia sprawdza najnowsza probe przez API, aktualne saldo i pozostale proby. Dodatkowa blokada cache per zamowienie zapobiega jednoczesnym ponowieniom. Przed przygotowaniem nowej proby kontrole sa powtarzane pod blokadami rekordow w centralnym serwisie linkow.

Oplacone, anulowane, usuniete zamowienie, niezgodna waluta, nieaktywne konto, needs_review, nierozstrzygnieta starsza proba lub zmiana od czasu weryfikacji blokuja nowa platnosc. Kolejne klikniecie moze przekierowac do juz utworzonego, zgodnego aktywnego linku, bez tworzenia kolejnego rekordu i kolejnego zlecenia API. Nieznany wynik tworzenia linku (np. timeout przed otrzymaniem ID) pozostaje zablokowany do wyjasnienia, zamiast ryzykowac powtorne zlecenie.

W testach odtworzono wczesniej istniejacy wyscig: webhook CONFIRMED przed odpowiedzia generowania linku, po czym odpowiedz PENDING nadpisywala potwierdzenie. `recordSuccess()` zachowuje teraz CONFIRMED i ID z potwierdzenia; rozbiezny ID odpowiedzi oznacza needs_review, bez podmiany potwierdzonego ID lub URL. Nie zmieniono obliczen, kwot, zasad ksiegowania, faktur ani automatyzacji.

POST zachowuje CSRF. Sa limity per IP i referencja platnosci. Strona ma CSP z nonce, no-store, no-referrer, noindex i zakaz osadzania w ramce. Nie laduje zewnetrznych fontow, skryptow ani analityki; nie ujawnia adresu, e-maila ani credentiali.

## Migracje i dane lokalne

Brak migracji i nowych tabel. Nie uruchamiano migracji, recznej synchronizacji ani schedulera. Nie zmieniono .env, APP_KEY, credentiali, ustawien konta ani danych istniejacych zamowien i platnosci. Nie wykonano rzeczywistych wywolan API PayNow, KSeF ani zadnych operacji VPS.

## Testy

Przed zmianami dotychczasowy zakres PayNow: **33 passed, 233 assertions**. Byl to baseline tego obszaru na aktualnym worktree, nie odizolowany baseline calego HEAD.

Wynik koncowy po poprawkach i formatowaniu: **176 passed, 1056 assertions**, 15.12 s:

```powershell
& C:\xampp\php\php.exe artisan test --compact tests/Feature/PayNowCustomerReturnTest.php tests/Feature/PayNowFoundationTest.php tests/Feature/PayNowSettingsTest.php tests/Feature/PayNowPaymentLinkTest.php tests/Feature/PayNowPaymentRegistrationTest.php tests/Feature/PayNowPaymentAutomationTest.php tests/Unit/PayNow/PayNowSignatureServiceTest.php tests/Feature/PrestashopPaymentSyncTest.php tests/Feature/PrestashopPaidStatusTest.php tests/Feature/OrderPaymentStateTest.php tests/Feature/PrestashopOrderImportTest.php
```

Testy korzystaly ze sprawdzonej konfiguracji SQLite `:memory:`, poczty array, kolejki sync i symulowanego HTTP z blokada nieoczekiwanych wywolan. Nowe testy izolowaly zdarzenie automatyzacji przez Event::fake; dotychczasowy test automatyzacji PayNow pozostaje w powyzszym zakresie.

Pokrycie obejmuje podpisy, zmienione/brakujace parametry, wygasniecie, niezaufany paymentStatus, recovery ID, opoznione potwierdzenie, jednokrotne ksiegowanie, timeout i ten sam payload/klucz idempotencji, statusy bledow, aktualne saldo, powtorne klikniecie, trzymana blokade, zmiane rekordu miedzy sprawdzeniem a przygotowaniem linku, pozostale nierozstrzygniete proby, anulowanie/usuniecie/oplacenie, walute, konto, needs_review, CSRF, metody HTTP, limity i obsluge bez JavaScript.

- Pint dla osmiu zmienianych plikow PHP: PASS.
- PHP lint dla tych osmiu plikow i dwoch widokow Blade: 10/10 PASS.
- `git diff --check`: PASS; tylko ostrzezenie Git o przyszlej normalizacji CRLF w `docs/product-spec.md`.
- `artisan route:list --path=paynow --except-vendor`: osiem tras, w tym trzy nowe trasy klienta.
- Edge/Playwright: cztery stany x 1280x900, 390x844, 320x740; brak poziomego przepelnienia i bledow JavaScript, poprawna widocznosc ponowienia. Symulowany POST ponowienia: jedno zadanie i poprawne przekierowanie, bez ruchu do rzeczywistej bramki.
- Zrzuty i HTML z fikcyjnymi danymi QA: `storage/app/private/qa/paynow-return/` (nie sa publicznie udostepnione).

Nie uruchamiano pelnego zestawu calej aplikacji. Nie wykonywano wieloprocesowego testu MySQL/VPS; rownoleglosc sprawdzono przez zajeta blokade i zmiany danych miedzy weryfikacja a zapisem. Nie potwierdzono rzeczywistego przeplywu Sandbox ani produkcyjnego PayNow.

## Weryfikacja reczna i wdrozenie

1. Lokalnie otworz `/integrations/paynow`; powinien byc widoczny adres strony OMS do skopiowania, a dotychczasowy wlasny adres powinien pozostac bez zmian.
2. Otworz `/paynow/powrot` bez parametrow. Powinna pokazac neutralny komunikat, bez danych zamowienia i bez panelu administracyjnego. Sam bazowy URL nie jest potwierdzeniem wplaty.
3. Dopiero w swiadomym tescie Sandbox wygeneruj nowy link z pustym wlasnym adresem lub bazowym adresem OMS. Udana platnosc powinna pokazac potwierdzone ID, nieudana opcje ponowienia, a oczekujaca nie powinna sklanic do ponownej wplaty.
4. Test na VPS wykonac dopiero po osobnym wdrozeniu i waskich wyjatkach Basic Auth z `docs/006-Deployment.md:134`. Nie otwierac calego panelu. Wymagany jest prawidlowy HTTPS i trwaly cache z atomowymi blokadami. Nie zmieniac APP_KEY i nie przelaczac credentiali dla nierozstrzygnietych prob.

Nie potrzeba nowej migracji, osobnego workera ani zadania schedulera. Nowe trasy sa obslugiwane przez PHP HTTP. Po wdrozeniu nalezy uwzglednic istniejacy proces odswiezania cache tras/widokow aplikacji. Nie wprowadzono zmian ani restartow na VPS.

## Dokumentacja dostawcy

- [Linki platnosci i continueUrl](https://docs.paynow.pl/docs/reference/v3/send-payment-link-request)
- [Statusy, przekierowanie i odzyskiwanie platnosci](https://docs.paynow.pl/pl/docs/v3/payments)
- [Odczyt statusu](https://docs.paynow.pl/pl/docs/reference/v3/get-payment-status)
