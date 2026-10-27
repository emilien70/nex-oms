# PORTAL.2 - platnosci PayNow w portalu klienta

## Zakres

- Ustawienia: platnosci i retry osobno, obie flagi false; manual domyslnie,
  PrestaShop opt-in, Allegro poza zakresem platnosci.
- Klient nie tworzy pierwszej proby. Operator/automatyzacja przygotowuje
  link PayNow istniejaca sciezka OMS.
- GET portalu i podgladu jest tylko odczytem; brak wywolan API,
  ksiegowania, nowych prob i aliasow.
- POST pay kontynuuje te sama probe przez prywatny alias /pay/.
- POST status korzysta z istniejacego odczytu statusu i ksiegowania.
- POST retry wymaga swiezej weryfikacji REJECTED/ERROR/EXPIRED;
  nowa proba jest przygotowywana przez centralny serwis PayNow.
- Powrot do zamowienia z istniejacej strony powrotu jest ograniczony
  do sesji przegladarki, ktora miala dostep do portalu.

## Bezpieczenstwo

CSRF, limit IP i kodu, blokada cache, HMAC intencji oraz ponowna kontrola
pod blokadami rekordow. Zmiana salda, waluty, nabywcy, konfiguracji,
proby lub generacji linku uniewaznia formularz. Klient nie wybiera
order_id, konta, ID dostawcy ani kwoty. API jest poza transakcjami.
Callback autoryzacji chroni portal rowniez po odpowiedzi statusu i przed
przygotowaniem/wywolaniem retry; wywolania PayNow bez callbacka zachowuja
dotychczasowe zachowanie. Nie powstaje druga sciezka ksiegowania.

Potwierdzenie niezaksiegowane, needs_review i inne nierozstrzygniete
proby blokuja akcje portalu. Lokalny uplyw terminu nie stanowi potwierdzenia
EXPIRED. Powtorzony POST ze starego formularza nie tworzy kolejnej proby.
HTTPS do dostawcy przechodzi przez walidator istniejacych krotkich linkow.
Odpowiedzi maja no-store, no-referrer, noindex i CSP z nonce.

## Pliki etapu

Nowe:

- Modules/CustomerPortal/Services/PortalPaymentService.php
- Modules/CustomerPortal/Http/Controllers/PortalPaymentController.php
- database/migrations/2026_10_26_050000_add_paynow_to_customer_portal_settings.php
- tests/Feature/CustomerPortalPaymentsTest.php
- docs/portal-2-report.md

Zmienione wzgledem PORTAL.1:

- Modules/CustomerPortal/Models/PortalSetting.php
- Modules/CustomerPortal/Services/PortalAccessService.php
- Modules/CustomerPortal/Services/PortalPresentationService.php
- Modules/CustomerPortal/Http/Controllers/PortalController.php
- Modules/CustomerPortal/Http/Controllers/PortalSettingsController.php
- Modules/Integrations/PayNow/Services/PayNowCustomerPaymentService.php
- Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php
- Modules/Integrations/PayNow/Http/Controllers/PayNowCustomerReturnController.php
- resources/views/customer-portal/show.blade.php
- resources/views/settings/customer-portal/edit.blade.php
- resources/views/paynow/return.blade.php
- routes/web.php
- app/Providers/AppServiceProvider.php
- docs/006-Deployment.md
- docs/architecture.md
- docs/product-spec.md

## Migracja i wdrozenie

Migracja dodaje tylko trzy pola w customer_portal_settings, bez backfillu.
Nie zmienia zamowien, kwot, dokumentow ani kont PayNow. Down odmawia
usuniecia skonfigurowanych ustawien. Zwykly odczyt PORTAL.1 nadal dziala
przed migracja; funkcje platnicze sa wtedy zamkniete.

Procedura i scisly regex Nginx: docs/006-Deployment.md, sekcja PORTAL.2.
Nie ma nowego workera ani crona. Nie zmieniono konfiguracji VPS.

Lokalnie migracja zostala wykonana za zgoda wlasciciela po testach zakresu.
Kopia: storage/app/private/backups/portal-2-20261026_215358-8e280f5e.sqlite.
SHA256: aa7debd2fa210113929a845f2e5bb75d138405e9c222715b862f7e72410d3046.
Kontrola integralnosci kopii i bazy: ok. Porownanie zamowien, pozycji,
dokumentow, serii, licznikow, kont i historii PayNow oraz dotychczasowych
danych portalu: bez zmian. Ustawienia portalu nie mialy jeszcze rekordu;
nie utworzono go podczas migracji. Flagi platnosci i retry pozostaja false.

## Testy

- CustomerPortalPaymentsTest: 55 testow, 361 asercji, PASS.
- Portal, PayNow, e-mail, PDF i powiazane prezentacje dokumentow:
  510 testow, 4114 asercji, PASS; siec zablokowana, API symulowane.
- PHP lint 14 plikow, Pint --test i git diff --check: PASS.
- Edge/Playwright: pending, failed, paid, review i preview dla 1280,
  390 i 320 px. 15 widokow bez overflow/kolizji, brak akcji dla paid,
  review i preview. Formularz POST do symulowanego /pay/: PASS.
- Rzeczywistej nawigacji do PayNow nie testowano; dostep zewnetrzny
  jest ograniczony w srodowisku QA. Brak rzeczywistych platnosci.

Komendy zakresu:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit tests/Feature/CustomerPortalPaymentsTest.php
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'CustomerPortal|PayNow|OrderEmail|EmailTemplateSettings|SettingsVariables|InvoicePdf|ProformaService|Ksef.*(Pdf|Presentation)|AllegroInvoiceDocument'
```

### Pelna regresja wzgledem PORTAL.1

Obie kopie kodu byly odizolowane od roboczej bazy, z SQLite :memory:,
PHP 8.2.12, SQLite 3.39.2, Laravel 12.63.0 i identycznymi 11332 plikami
zaleznosci. Nie kopiowano rzeczywistego .env ani bazy; siec byla zablokowana.
Wynik bazowy jest wczesniej wykonanym pelnym przebiegiem PORTAL.1,
nie deklaracja oparta wylacznie na liczbie bledow.

| Wariant | Testy | Asercje | Niepowodzenia | Bledy wykonania |
| --- | ---: | ---: | ---: | ---: |
| PORTAL.1, p1b-201045/b | 4382 | 40563 | 72 | 0 |
| PORTAL.2, p1b-214636/b | 4437 | 40924 | 72 | 0 |

Porownano identyfikatory wszystkich przypadkow: 0 nowych niepowodzen,
72 wspolne, 0 ustapionych, 55 dodanych testow PASS, 0 usunietych.
Kod roboczy byl identyczny z zamrozona kopia podczas porownania.
Szesc roznic w surowej tresci niepowodzen sprawdzono osobno: dotycza
wylacznie nazw cache Blade, losowych tokenow CSRF i godzin danych fixture.
Przyczyny, oczekiwane wartosci i miejsca asercji pozostaly te same.
Nie naprawiano istniejacych niepowodzen innych modulow; pelny zestaw
nadal nie jest zielony, mimo braku nowych niepowodzen dla tego etapu.

Prywatne artefakty QA: storage/app/private/qa/portal-2-comparison.json,
raporty JUnit w obu katalogach przebiegow oraz zrzuty ekranu i wyniki
w storage/app/private/qa/portal-2-visual/. Pelny przebieg uruchomiono
przez portal-baseline-tools/run.ps1 -Variant b -Label full, a porownanie
przez portal-2-compare.php. Nie wykonywano operacji Git ani wdrozenia.

## Weryfikacja reczna

1. Ustawienia -> Strony informacyjne: sprawdz nowe przelaczniki wylaczone.
2. Wylaczony portal/platnosci: brak aktywnych akcji klienta.
3. Na danych testowych i PayNow Sandbox przygotuj link w OMS, nastepnie
   swiadomie wlacz platnosci dla recznego zamowienia testowego.
4. Podglad administratora nie moze zawierac formularzy platniczych.
5. Link klienta pokazuje kontynuacje; GET nie tworzy proby.
6. Kontynuacja prowadzi przez /pay/ do tej samej proby PayNow.
7. Potwierdzona i zaksiegowana wplata pokazuje ID, bez ponownego placenia.
8. Retry wlacz oddzielnie i sprawdz tylko na testowej nieudanej probie.
9. Po zmianie kwoty lub odwolaniu linku stary formularz nie dziala.
10. Strona powrotu w tej samej przegladarce ma link do zamowienia;
    samo skopiowanie URL platnosci do innej sesji nie daje tego dostepu.

## Ograniczenia

- Bez rzeczywistych platnosci, wiadomosci do klientow i operacji VPS.
- Stare lub wlasne continueUrl nie sa przepisywane. Wlasna strona
  powrotu nie pokazuje przycisku OMS; sesja w innej przegladarce tez nie.
- Odwolanie portalu nie anuluje istniejacej zdalnej transakcji.
- Zmiana kwoty istniejacego linku wymaga kontroli operatora w OMS.
- Nie zmieniac srodowiska/kluczy PayNow przy nierozstrzygnietych probach.
- Rzeczywista rownoleglosc MySQL i komunikacja z PayNow wymagaja osobnego
  zatwierdzonego testu; lokalna regresja uzywa SQLite i symulowanego API.
