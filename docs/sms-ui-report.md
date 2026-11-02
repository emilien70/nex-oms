# SMS - spojny formularz i historia wiadomosci

## Zakres

Lokalna zmiana z 2026-10-29. Bez operacji na VPS, wysylki SMS/e-mail,
konfiguracji telefonu, migracji, restartow kolejek, commit i push.
Wczesniejsza zmiana docs/006-Deployment.md pozostala nienaruszona.

## Zachowanie

- SMS wspoldzieli klasy formularza e-mail: tlo, obramowanie, odstepy,
  kolumny etykiet, pole tresci i przycisk Wyslij. Styl samego e-maila
  pozostaje bez zmian. Wspolny wybor kanalu w trybie SMS jest pod szablonem;
  po powrocie do e-maila wraca na swoje dotychczasowe miejsce.
- Szablon ma domyslne Nie wybrano. Pozostawiono zmienne OMS, walidacje,
  potwierdzenie odbiorcy i blokade powtornego klikniecia podczas zadania.
- Nadawca jest readonly, pobierany tylko z SmsDevice.sender_phone.
  Pusty numer SIM pokazuje placeholder, nie numer klienta. To informacja,
  nie parametr pozwalajacy podmienic rzeczywistego nadawce lub karte SIM.
- Formularz do klienta pobiera jego numer z zamowienia; pokazuje go w
  potwierdzeniu przed wysylka. Po zapisie zmian danych zamowienia lub
  odswiezeniu jego stanu aktualizowany jest tez ukryty numer odbiorcy.
  Endpointy i obsluga innych numerow w API/automatyzacjach sa bez zmian.
- Licznik reaguje od razu na tekst. Po bezpiecznym podgladzie liczy znaki
  wyrenderowanej tresci; kodowanie/jednostki/czesci sa w podpowiedzi.
  Podglad tresci pozostaje pod przyciskiem z ikona oka. Nie dodano
  drugiego algorytmu limitow SMS ani nowej zaleznosci.
- Historia SMS uzywa istniejacych klas metadanych, odbiorcy i tresci
  e-maila. Usunieto widoczny techniczny wiersz z typem wysylki/liczba czesci.
  Snapshoty, statusy i te metadane pozostaja w bazie bez zmian.
- Tylko submitted ma biala etykiete Wyslane na zielonym tle. Wyjasnienie,
  ze wykonanie akcji telefonu nie potwierdza doreczenia, jest w title.
  Pozostale stany zachowuja odpowiednie etykiety; niepewnosc nadal ma
  ostrzezenie, a nierozpoczete zlecenia mozna anulowac.
- Komunikat pustej historii uwzglednia SMS i e-mail przy pierwszym
  renderowaniu i po AJAX. Znika od razu po przyjeciu pierwszego SMS,
  takze gdy pozniejsze pobranie historii jest opoznione. Odswiezenie
  pustej listy SMS nie odslania komunikatu, jezeli sa juz e-maile.

## Pliki

Zmodyfikowane:
- app/View/Components/OrderEmailExchange.php
- resources/views/components/order-email-exchange.blade.php
- resources/views/components/order-sms-compose.blade.php
- resources/views/components/order-sms-history.blade.php
- resources/views/orders/show.blade.php
- tests/Feature/MacroDroidSmsTest.php

Utworzone:
- tests/Feature/OrderSmsUiTest.php
- tests/Support/Sms/message-empty-state.test.cjs
- docs/sms-ui-report.md

Nie usunieto funkcji transportowych ani istniejacych plikow projektu.
Brak migracji; nie ma zadnej komendy migracyjnej do wykonania.

## Testy

PHP 8.2, SQLite :memory:, istniejacy run-isolated.php zapewnia osobny
storage/cache i blokade rzeczywistych transportow. Fake kolejki/HTTP;
nie uruchamiano testow na bazie roboczej ani testow modulu KSeF.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit --filter="OrderSmsUiTest|MacroDroidSmsTest|SmsSegmentsTest|MacroDroidMacroConfigurationTest|OrderEmailManualUiTest|OrderEmailDispatchTest|OrderEmailRecoveryTest|OrderEmailMessageFoundationTest|EmailTemplateSettingsTest|EmailSettingsPageTest|SmtpTransportFactoryTest" --log-junit=storage/app/private/qa/sms-ui-regression.xml
```

Wynik koncowy: 180 testow, 1109 asercji, 0 failures/errors, 14.11 s.
Nowe testy obejmuja wszystkie osiem statusow SMS, pusty e-mail/SMS,
historie mieszana, readonly numer SIM, brak konfiguracji, wylaczona bramke,
escaping tresci i brak modyfikacji zapisanych rekordow podczas renderowania.
W przebiegach roboczych poprawiono sposob przekazywania danych komponentu
w testach oraz porownanie rekordow po odczycie z bazy; bledy te sa usuniete.

```powershell
node --test tests/Support/Sms/message-empty-state.test.cjs
```

4/4 testy JavaScript: pierwszy SMS bez przeladowania, poczatkowe historie,
odswiezenie pustych SMS przy e-mailu i powrot komunikatu tylko przy obu
pustych listach. Uzyto wbudowanych modulow Node, bez instalacji zaleznosci.

Pint --test dla komponentu i obu zmienionych/nowych testow PHP: PASS.
php -l dla siedmiu plikow PHP/Blade: PASS. git diff --check: PASS.
Nie uruchamiano pelnego zestawu projektu; nie deklarujemy jego wyniku.

## Przegladarka i reczna weryfikacja

Sprawdzono lokalnie zamowienie 192 bez wykonywania wysylki:
- otwieranie, przelaczanie E-mail/SMS i zamkniecie/ponowne otwarcie;
- poprawny licznik po podgladzie Test [id_zamowienia] -> Test 192 (8 znakow);
- placeholder SIM i zablokowana wysylka przy lokalnie wylaczonej bramce;
- zwykly desktop i viewport 390x844: formularz miesci sie w swojej kolumnie;
- przywrocono zwykly viewport i pozostawiono pusty formularz.

Odczyt roboczego tematu e-mail jest maskowany przez narzedzie przegladarki,
wiec nie deklarujemy automatycznego porownania jego dokladnej zawartosci
po przelaczeniu. Nie wykonywano rzeczywistego wyslania ani testu telefonu.
Zrzuty lokalne: storage/app/private/qa/sms-compose-{desktop,mobile}.jpg.

Do recznego sprawdzenia:
1. Zamowienie -> Wymiana wiadomosci -> Napisz wiadomosc -> SMS.
2. Sprawdz szablon, licznik, podglad i Nadawca z ustawien SIM.
3. Przelacz na e-mail i z powrotem: formularze maja ten sam styl.
4. Dla historii z SMS bez e-maila pusty komunikat ma byc ukryty.
5. submitted pokazuje Wyslane; queued/failed/uncertain nigdy nie sa zielone.
6. Potwierdzenie doreczenia nie jest dostepne; techniczny status nadal
   nie daje podstawy do automatycznego ponowienia SMS.

Nie trzeba restartowac Main Queues. Zmiana nie zostala wdrozona na VPS.
