# SMS i e-mail - aktualny odbiorca przy wysylce

## Zakres

Praca lokalna na main, HEAD 591127154d10b9dc61e50b3005fd5343228837de.
Zachowano zastane zmiany automatyzacji, SMS i Von Halsky, w tym HALSKY.3B.
Bez commit/push, VPS, zmian konfiguracji, migracji i zmian danych uzytkownika.

Przyczyna SMS: formularz przesylal zapamietany numer i traktowal go jak
odbiorce wskazanego jawnie. Aktualizacja widoku zmniejszala ryzyko, lecz nie
gwarantowala swiezosci numeru. E-mail juz pobieral adres przy POST, ale nie
rozwiazywal go ponownie pod blokada oraz nie kontrolowal zmiany po kolejkowaniu.
Nie przypisano przyczyny konkretnej historycznej wiadomosci bez jej audytu.

## Zachowanie

- Dopiero Wyślij wywoluje prywatny GET aktualnego odbiorcy zamowienia.
- Formularz czeka na trwajacy zapis kontaktu. Niezapisana edycja kontaktu,
  blad zapisu/odczytu lub brak poprawnego kontaktu blokuja wysylke.
- Potwierdzenie SMS pokazuje aktualnie odczytany, znormalizowany numer.
- HMAC jest zwiazany z celem, ID zamowienia, kanalem i odbiorca. POST
  ponownie sprawdza go pod blokada zamowienia. Zmiana w miedzyczasie wymaga
  ponownego Wyślij, bez utraty tresci i bez wysylki na poprzedni kontakt.
- Numer nie jest przechowywany w ukrytym polu formularza SMS przy otwarciu.
  Stary klient moze przeslac recipient tylko jako oczekiwana wartosc:
  rozbieznosc jest odrzucana, nie zamieniana na odbiorce dodatkowego.
- Token jest opcjonalny tylko dla kompatybilnosci starszego klienta.
  Podany pusty, null, niepoprawnego typu lub sfalszowany token jest odrzucany.
- Nowy e-mail do klienta rozwiazuje kontakt i nazwe z aktualnego Order pod
  blokada; nie ufa staremu modelowi ani adresowi przeslanemu w formularzu.
- Pochodzenie odbiorcy e-mail jest zapisane w niezmiennym actor_snapshot
  jako scisly boolean recipient_customer; decyzja klient/staly jest tez
  sprawdzana obok fingerprintu intencji. Nie zmieniono historycznego formatu
  hashy ani UUID automatyzacji. Legacy manual moze zwrocic ten sam rekord lub
  wznowic nietkniety queue_dispatch_failed bez przepisywania proweniencji.
- Automatyzacja ponownie odczytuje Order. Zmiana kontaktu podczas zbierania
  odbiorcow zatrzymuje przygotowanie zamiast tworzyc dwie wiadomosci do
  nowego adresu, ktory mogl juz byc wskazany jawnie.
- Odbiorca przygotowanej wiadomosci pozostaje niezmienny. E-mail sprawdza
  kontakt przy claim i po przygotowaniu zalacznikow, przed SMTP. Rozbieznosc
  daje failed/recipient_changed bez transportu. SMS zachowuje istniejace
  kontrole przy claim i begin, a reczna wysylka zawsze jest klientem.
- Dodatkowe stale adresy i numery automatyzacji nie sa podmieniane. Nie ma
  automatycznego ponawiania wiadomosci z niepewnym wynikiem ani duplikatu po
  podwojnym kliknieciu. Historia i dokumenty pozostaja niezmienne.

## Zgodnosc starszych zlecen

Nie ma backfillu ani migracji. Historyczny manual e-mail bez znacznika jest
traktowany jak wysylka do klienta, zgodnie z dawnym formularzem. Starszy
automation e-mail bez znacznika nie pozwala wiarygodnie ustalic pochodzenia
odbiorcy. Taki niewyslany rekord konczy sie recipient_context_missing bez
SMTP; dotyczy to takze starszych poprawnych adresow dodatkowych. Nalezy
utworzyc nowe, swiadome zlecenie, a nie przepisywac starego rekordu.
Wyslane/failed/unknown wiadomosci nie sa ponownie uruchamiane przez sender.

## Zmienione pliki

Nowe:

- app/Services/OrderMessageRecipientService.php
- app/Http/Controllers/OrderMessageRecipientController.php
- tests/Feature/OrderMessageRecipientTest.php
- tests/Frontend/order-message-recipient.test.cjs
- docs/message-recipient-report.md

Zmienione w tym etapie (oprocz wczesniejszych zachowanych zmian):

- app/Http/Controllers/OrderEmailMessageController.php
- app/Http/Requests/StoreOrderEmailMessageRequest.php
- app/Services/OrderEmailPreparationService.php
- app/Services/OrderEmailIntentFingerprint.php
- app/Services/OrderEmailSender.php
- app/ValueObjects/OrderEmailPreparationData.php
- Modules/Automation/Services/AutomationEmailActionService.php
- Modules/Integrations/MacroDroid/Http/Controllers/OrderSmsController.php
- Modules/Integrations/MacroDroid/Services/SmsPreparationService.php
- routes/web.php
- resources/views/orders/show.blade.php
- resources/views/components/order-sms-compose.blade.php
- tests/Feature/OrderSmsUiTest.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Migracja nie jest potrzebna ani uruchamiana.

## Testy

Sprawdzono phpunit.xml i brak nadpisujacych zmiennych DB. Testy:
APP_ENV=testing, SQLite :memory:, fake Queue/Storage, symulowany transport
SMTP i zablokowane nieoczekiwane polaczenia HTTP. Nie wyslano prawdziwej
wiadomosci, nie wywolano schedulera ani jobow roboczej bazy.

- Baseline przed edycja: 123 testy / 702 asercje, PASS, 10.93 s
  (OrderEmailMessageFoundation/Dispatch/Recovery/ManualUi, MacroDroidSms,
  OrderSmsUi).
- Nowe testy backendu: OrderMessageRecipientTest (44 przypadki), wlaczone
  do ponizszej regresji.
- Koncowa regresja: 535 testow / 3707 asercji, PASS, 47.55 s. Zakres:
  powyzsze oraz AutomationEmailAction, AutomationOrderModification,
  EmailTemplateSettings, EmailSettingsPage, CustomerPortal* i Unit/MacroDroid.
- Dodatkowa weryfikacja legacy manual i kolejnosci blokad portalu/SMS:
  101 testow / 551 asercji, PASS, 7.32 s (nowe testy odbiorcow, dispatch,
  recovery i automatyczne e-maile), nastepnie potwierdzona powyzsza regresja.
- node --test tests/Frontend/order-message-recipient.test.cjs:
  11 PASS. Testy wykonuja rzeczywiste fragmenty skryptow formularza w VM
  z symulowanym DOM i fetch; zadne zadanie nie opuszcza procesu testowego.
- PHP lint i Pint --test: PASS dla 14 plikow PHP.
- git diff --check oraz kontrola nowych plikow: PASS.

Komenda regresji PowerShell:

```powershell
$files = @(Get-ChildItem tests/Feature -Filter '*.php' | Where-Object { $_.Name -match '^(OrderEmail|OrderSms|OrderMessageRecipient|MacroDroid|AutomationEmail|AutomationOrderModification|EmailTemplate|EmailSettings|CustomerPortal)' } | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test @files tests/Unit/MacroDroid --compact
node --test tests/Frontend/order-message-recipient.test.cjs
```

W trakcie regresji zachowano dotychczasowy kod bledu portalu dla jawnego,
nieaktualnego odbiorcy. Nie zmieniono jego testu w celu ukrycia regresji.
Poprawiono tez kolejnosc sprawdzen hosta w nowym tescie, aby testowy generator
URL nie przejmowal domeny klienta przy kolejnych zadaniach administracyjnych.
Kontrola kolejnosci zapytan potwierdza settings przed Order w obu kanalach;
nie jest to test prawdziwej wspolbieznosci blokad na MySQL.

Kontrola przegladarki: lokalny widok OMS 343 otwiera formularz i przelacza
E-mail/SMS bez bledow konsoli; formularz SMS nie ma zapamietanego numeru.
Nie klikano Wyślij w roboczej aplikacji. MacroDroid pozostal wylaczony.

## Weryfikacja reczna

Na testowym zamowieniu i wlasnych danych kontaktowych:

1. Odswiez karte, otworz Napisz wiadomosc i przygotuj tresc.
2. Zmien kontakt bez zapisu: Wyślij powinno poprosic o zapis lub anulowanie,
   bez utworzenia wiadomosci.
3. Zapisz nowy kontakt bez przeladowania karty. SMS powinien potwierdzic nowy
   numer; e-mail powinien zapisac nowy adres odbiorcy w historii.
4. Przy wolniejszym zapisie kliknij Wyślij: formularz ma poczekac na sukces.
   Po bledzie zapisu nie powinien wyslac na poprzedni kontakt.
5. Podwojne klikniecie nie tworzy dwoch wiadomosci. Blad odczytu lub
   anulowanie potwierdzenia zachowuje wpisana tresc.
6. Zmiana kontaktu przed obsluzeniem kolejki blokuje wiadomosc do klienta,
   ale nie zmienia jej historycznego odbiorcy ani jawnych odbiorcow dodatkowych.

Wczytanie kodu: restart tylko lokalnego Main Queues; bez KSeF i VPS.
Poproszono wlasciciela o restart; oczekuje na potwierdzenie.

## Ograniczenia

Nie wykonywano pelnego zestawu repozytorium, osobnej regresji KSeF ani testow
wspolbieznosci na MySQL 8. Testy UI w VM nie zastepuja recznego scenariusza
na telefonie. Nie zmieniono bazy roboczej ani ustawien kont wysylkowych.

Ostatnia kontrola kontaktu nie jest rozproszona transakcja z serwerem SMTP
lub modemem SIM. Zmiana po rozpoczeciu rzeczywistego transportu nie moze
cofnac wysylki; nie trzyma sie blokady zamowienia przez operacje sieciowa.
Nie zmieniono automatycznie tresci/zalacznikow gotowej wiadomosci ani danych
kontaktowych zamowienia.
