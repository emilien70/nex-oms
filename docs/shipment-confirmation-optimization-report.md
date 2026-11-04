# PRZESYLKI.OPT.1 - szybsze udostepnianie numeru nadawczego

## Zakres

Zmiana lokalna, bez migracji i bez zmian na VPS. Nie zmienia KSeF, danych
zamowien, nadawania kolejnej paczki, normalnego harmonogramu sledzenia ani
konfiguracji kont kurierskich. Testy nie tworza rzeczywistych przesylek.

## Przyczyna opoznien

- Lokalny pojedynczy worker najpierw obslugiwal integracje, automatyzacje
  i default, a dopiero potem shipments-actions i shipments-sync.
- InPost po utworzeniu przesylki czekal 8 s przed jednym GET. Poprawna
  odpowiedz bez numeru nie zlecala kolejnego szybkiego sprawdzenia.
- Allegro stosowalo stale 5 s zamiast Retry-After. Resolver konczyl prace
  po zapisaniu identyfikatora zewnetrznego nawet bez numeru nadawczego.
- Przegladarka odczytywala stan lokalny co 2 s.

Nie znamy najwczesniejszego momentu gotowosci numeru w rzeczywistym API.
Poprawka usuwa opoznienia OMS, ale nie gwarantuje czasu odpowiedzi kuriera.

## Potwierdzanie nowej przesylki

InPost Paczkomaty i Kurier: pierwszy odczyt po 1 s. Nastepne odczyty
oczekujacej przesylki po 1 s przez pierwsze 10 s, po 2 s do 30 s, po 5 s
do minuty, potem co 30 s. GET pozostaje na shipments-actions, oddzielnie
od normalnego sledzenia na shipments-sync. Odczyt moze byc pozniejszy
z powodu czasu API, kolejki, blokady lub istniejacego limitu zadan.

Wysylam z Allegro: Retry-After z przyjetego POST lub oczekujacego GET
okresla najwczesniejszy kolejny odczyt. Bez naglowka zachowany jest
pierwszy odczyt bezposrednio po przyjeciu POST, a dalsze sprawdzenia
uzywaja powyzszego interwalu. Po uzyskaniu shipmentId odczytywane sa
juz tylko szczegoly tej przesylki, az do otrzymania numeru nadawczego.
Publiczne metody klienta zwracajace tablice oraz resolveCreation(): bool
pozostaja dostepne; false oznacza takze brak numeru w szczegolach.

Oba resolvery maja termin 15 minut od ich utworzenia; nowa przesylka
nie traci tego czasu podczas wczesniejszego oczekiwania na wykonanie POST.
Stare, juz zapisane zadania Allegro bez nowego pola korzystaja ze stalego
czasu utworzenia przesylki. Uplyw terminu konczy szybkie potwierdzanie
stanem niepewnym i komunikatem wymagajacym sprawdzenia panelu kuriera.
Nie usuwa istniejacej przesylki i nie odblokowuje slepego ponowienia POST.
Naglowek siegajacy poza termin nie powoduje wczesniejszego GET: po uplywie
terminu zapisywany jest stan niepewny, bez kolejnego odczytu API.

HTTP 404/408/429/5xx i blad polaczenia podczas GET sa ponawiane nie
wczesniej niz po 10 s i zgodnie z Retry-After. Pozostale bledy API koncza
potwierdzanie komunikatem wymagajacym sprawdzenia; nie sa nowym nadaniem.
Nie zmieniono obslugi niepewnej odpowiedzi samego POST tworzenia przesylki.
Zachowane sa wspolne blokady per przesylka i istniejace limitery integracji.
Pierwszy numer nadal uruchamia istniejace zdarzenie ShipmentCreated tylko
przy przejsciu z pustego numeru; wydruki i automatyzacje nie maja nowej sciezki.

Przegladarka odczytuje tylko zapisany status OMS co 1 s, maksymalnie przez
4 minuty. Nie wykonuje zapytan do API kuriera. Po tym czasie podaje, ze
sprawdzanie trwa w tle, i ostrzega przed ponownym nadaniem. Osiagniecie
stanu koncowego zatrzymuje odczyty od razu.

## Lokalny Main Queues

scripts/run-main-queues.ps1 uruchamia trzy oddzielne workery pod jednym
przyciskiem Main Queues w nex-control.ps1 i start-oms.ps1:

| Worker | Kolejki | Sleep |
| --- | --- | --- |
| main | integrations,automation,default | 3 s |
| shipments-actions | shipments-actions | 1 s |
| shipments-sync | shipments-sync | 1 s |

Helper pilnuje pojedynczej grupy lokalnym mutexem i wznawia zakonczone
procesy, m.in. po max-time=3600. Kazdy worker ma timeout=60 (jawny
dotychczasowy domyslny timeout); wlasny timeout zadania nadal ma pierwszenstwo.
Logi trafiaja do %LOCALAPPDATA%\NEX-OMS\logs\queues-*.log.
Procesy KSeF pozostaja oddzielne i maja niezmienione polecenia.
Pliki nex-control.ps1 i start-oms.ps1 sa lokalne i ignorowane przez Git.
Nowy helper jest wersjonowany; nie usunieto wyjatkow w .gitignore.

Wczytanie zmiany w otwartym panelu:

1. Zaczekaj na zakonczenie biezacych operacji Main Queues.
2. W menu ikony panelu wybierz "Wyjdz z panelu (procesy zostaja)".
3. Otworz ponownie nex-control.ps1 z -NoAutoStart.
4. Zrestartuj tylko Main Queues. Nie uzywaj Restart All ani queue:restart.
5. Sprawdz trzy logi i liste procesow: pojedynczy worker dla kazdej grupy,
   brak dawnego workera obslugujacego wszystkie piec kolejek naraz.

Panel musi zostac ponownie otwarty, bo stara instancja przechowuje poprzednie
polecenie uruchomienia w pamieci. Test -Describe nie startuje zadnych workerow:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\run-main-queues.ps1 -Describe
```

## Weryfikacja i granice

Automatyczne testy uzywaja SQLite :memory:, symulowanego HTTP i kolejki.
Obejmuja oba warianty InPost, oczekujaca komende Allegro, pozny numer po
shipmentId, Retry-After (sekundy i date HTTP), bledy GET, termin, anulowanie,
usuniecie rekordu, wspolna blokade, kompatybilnosc starego zadania oraz
jednokrotne zdarzenia. Testy launcherow sprawdzaja rozdzielenie kolejek,
skladnie PowerShell i niezmienione polecenia KSeF bez startu aplikacji.

Po wczytaniu lokalnego kodu recznie porownaj zwykle nadanie InPost Paczkomaty,
InPost Kurier i Wysylam z Allegro: czas POST, kolejne GET w historii API,
pojawienie sie numeru i etykiety, pojedynczy wydruk oraz pojedyncze zdarzenie.
Nie wykonano tych rzeczywistych operacji podczas implementacji.

Na VPS pozostaja dotychczasowe workery i ich ustawienia. Ewentualna zmiana
sleep=3 na sleep=1 dla workerow przesylek wymaga osobnego wdrozenia,
weryfikacji aktualnej konfiguracji Supervisor i lagodnego restartu tylko
wlasciwych procesow. Nie zmieniac retry_after ani kolejek KSeF przy tej okazji.

Referencja naglowka Retry-After:
[dokumentacja Wysylam z Allegro](https://developer.allegro.pl/tutorials/jak-zarzadzac-przesylkami-przez-wysylam-z-allegro-LRVjK7K21sY).

## Zmienione pliki

Zmodyfikowane:

- Modules/Integrations/AllegroShipping/Exceptions/AllegroShippingApiException.php
- Modules/Integrations/AllegroShipping/Jobs/ResolveAllegroShipmentCommandJob.php
- Modules/Integrations/AllegroShipping/Services/AllegroShippingClient.php
- Modules/Integrations/AllegroShipping/Services/AllegroShippingOperations.php
- Modules/Integrations/InPost/Exceptions/InPostApiException.php
- Modules/Integrations/InPost/Services/InPostClient.php
- Modules/Integrations/InPost/Services/InPostShipmentOperations.php
- resources/views/orders/show.blade.php
- tests/Unit/ShipmentQueueTest.php
- docs/architecture.md
- docs/product-spec.md
- nex-control.ps1 (lokalny, ignorowany przez Git)
- start-oms.ps1 (lokalny, ignorowany przez Git)

Utworzone:

- Modules/Integrations/InPost/Jobs/ResolveInPostShipmentJob.php
- Modules/Shipments/Jobs/Concerns/TracksShipmentConfirmation.php
- Modules/Shipments/Support/ShipmentConfirmationResult.php
- Modules/Shipments/Support/ShipmentConfirmationSchedule.php
- scripts/run-main-queues.ps1
- tests/Feature/ShipmentConfirmationTest.php
- tests/Unit/ShipmentConfirmationScheduleTest.php
- tests/Deployment/main-queues.test.cjs
- docs/shipment-confirmation-optimization-report.md

Nie usunieto plikow. Zachowano istniejace zmiany wlasciciela w
docs/006-Deployment.md, scripts/nex-oms-deploy.sh oraz dwoch testowych
plikach wdrozenia. Nie wykonano commit ani push.

## Testy lokalne

PHP 8.2.12, SQLite :memory:, jawne APP_ENV=testing, DB_CONNECTION=sqlite,
DB_DATABASE=:memory: i oddzielny testowy APP_KEY tylko dla procesu testow.
Nie zmieniono lokalnej bazy ani .env. Testy KSeF i pelna regresja niezwiazanych
modulow nie byly uruchamiane.

```text
C:\xampp\php\php.exe vendor/bin/phpunit
  tests/Unit/ShipmentConfirmationScheduleTest.php
  tests/Unit/ShipmentQueueTest.php
  tests/Unit/ShipmentStatusMapperTest.php
  tests/Feature/ShipmentConfirmationTest.php
  tests/Feature/InPostShipmentTest.php
  tests/Feature/InPostCourierShipmentTest.php
  tests/Feature/AllegroShippingTest.php
  tests/Feature/DpdShipmentTest.php
  tests/Feature/AllegroShipmentExportTest.php
  tests/Feature/AllegroShipmentSettingsTest.php
  tests/Feature/AutomationNexPrinterActionTest.php
  tests/Feature/Integrations/NexPrinterTest.php
  tests/Feature/AutomationExecutionRecoveryTest.php
  tests/Feature/OrderAutomationHistoryTest.php
```

Wynik: 233 testy, 1335 asercji, bez niepowodzen.

```text
node --test tests/Deployment/main-queues.test.cjs
```

Wynik: 7 testow, bez niepowodzen. Obejmuja rowniez symulowane wykonanie
funkcji uruchamiania z podstawionym Start-Process (bez uruchamiania PHP),
sciezki ze spacjami/apostrofem i wykonanie kodu polling z podstawionym
zegarem oraz fetch. Faktyczne trzy procesy aplikacji nie byly uruchamiane.

Pint --test dla 14 zmienionych/nowych plikow PHP: PASS.
php -l dla tych plikow i widoku Blade (15 plikow): PASS.
Parser PowerShell dla helpera i dwoch lokalnych launcherow: PASS.
git diff --check: PASS.

Przy wiekszej rownoleglej aktywnosci lokalnego SQLite nalezy obserwowac
ewentualne konflikty zapisu w logach. Nie zmieniono ustawien SQLite ani
nie wykonywano testu obciazeniowego na bazie wlasciciela. Czas rzeczywistej
gotowosci numeru i wydruk na telefonie/drukarce wymagaja zwyklego testu
uzytkownika po wczytaniu kodu; nie sa potwierdzone symulacja API.
