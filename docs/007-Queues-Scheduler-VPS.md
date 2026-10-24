# Kolejki, harmonogram i Supervisor na VPS

## 1. Zakres i pochodzenie ustalen

Stan dokumentu: 2026-10-24. Uzupelnienie wykonawcze: odczyt SSH i lokalna poprawka Automation, bez wdrozenia na VPS; szczegoly w [dokumencie poprawki](008-Automation-Execution-Recovery.md). Audyt statyczny lokalnego kodu na galezi `main`, HEAD `30e7ff99d2ca21e192b248283e5bfc06cc378434`, Laravel z `composer.lock`: 12.63.0. Zachowano zastane, niezacommitowane zmiany widoku zrodla zamowienia i jego testu; nie dotycza kolejek.

Zrodla ustalen:

- **Kod**: aktualne pliki aplikacji oraz lokalne zrodla Laravel 12.63.0 w `vendor/`. To podstawa ponizszej analizy zachowania.
- **VPS, migawka operatora z 2026-10-24**: parametry przekazane do tego audytu, w tym odczyt aktywnej konfiguracji Laravel przez Tinker. Nie sa wnioskowane z wartosci domyslnych repozytorium.
- **Wczesniejsza kontrola w tej rozmowie**: dodatkowe odczyty `stopwaitsecs` dla `integrations` i `shipments-sync`. Nie zostaly ponownie odczytane podczas audytu dokumentacyjnego.
- **Pozniejszy odczyt SSH z 2026-10-24**: po osobnej zgodzie operatora odczytano wybrane parametry Supervisor i status siedmiu workerow. Dane w tabeli stopwaitsecs zostaly uzupelnione tym odczytem, bez zmian lub restartow.

Pierwotny audyt byl dokumentacyjny, bez polaczen z VPS, aplikacji, testow i API. W pozniejszym, osobno zatwierdzonym etapie odczytano wybrane parametry Supervisor i status workerow przez SSH oraz przygotowano lokalna poprawke Automation z testami na izolowanej bazie. Nie zmieniono konfiguracji VPS, nie uruchomiono na nim migracji, schedulera ani restartow. Nie jest to test wydajnosci, pelna weryfikacja kazdej integracji ani dopuszczenie automatycznej wysylki finansowej/KSeF do Production. Lokalne pliki nie dowodza, jaki dokladnie kod lub payloady jobow sa aktualnie zaladowane przez procesy VPS.

Powiazane dokumenty: [wdrozenie](006-Deployment.md), [development](005-Development.md), [architektura](architecture.md), [integracje](004-Integrations.md), [specyfikacja produktu](product-spec.md).

## 2. Architektura wykonania

```text
Systemowy cron (co minute)
  -> Laravel Scheduler: routes/console.php
       -> dispatch joba -> tabela jobs -> worker queue:work -> serwis domenowy/API
       -> wybrane serwisy wykonywane bezposrednio w procesie schedulera

Supervisor -> uruchamia, nadzoruje i ponownie uruchamia workery
Uzytkownik / zdarzenie domenowe -> moze kolejkowac job bez udzialu crona
```

Skrot Cron -> Scheduler -> Queue -> Worker -> Supervisor opisuje uczestnikow, ale Supervisor nie wykonuje nastepnego etapu joba: zarzadza procesem workera. Cron nie zastepuje workerow, a workery nie uruchamiaja same harmonogramu.

**Polaczenie** kolejki to wpis w `config/queue.php`, a **nazwa kolejki** to kanal wybierany przez `--queue`. `database/ksef` i `ksef_submit/ksef-submit` sa odrebnymi parami. Oba polaczenia maja driver `database` i wskazuja te sama baze/tabele przez `DB_QUEUE_CONNECTION` i `DB_QUEUE_TABLE` (domyslnie `jobs`), lecz rozny `retry_after`. Nie jest to fizyczna izolacja baz danych. Nie dodawac `ksef-submit` do workera polaczenia `database`.

Domyslne `after_commit=false` polaczen nie stanowi globalnej gwarancji dispatchu po zatwierdzeniu transakcji. Istotne sciezki stosuja wlasne transakcje i/lub jawne `afterCommit()`; dispatcher automatycznej wysylki KSeF stosuje `afterCommit()`.

## 3. Potwierdzona konfiguracja stagingu

### 3.1. Srodowisko i workery

Migawka operatora: OVHcloud, Ubuntu Linux, katalog `/var/www/nex-oms`, PHP 8.3.6, Laravel 12.63.0, `APP_ENV=staging`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database`. Pliki Supervisor: `/etc/supervisor/conf.d/nex-oms-*.conf`.

| Program Supervisor | Polaczenie | Kolejka | `--timeout` s | `--tries` | `--max-time` s | `stopwaitsecs` s / zrodlo |
|---|---|---|---:|---:|---:|---|
| `nex-oms-default` | `database` | `default` | 60 | 1 | 3600 | 120, odczyt SSH |
| `nex-oms-integrations` | `database` | `integrations` | 210 | 1 | 3600 | 240, odczyt SSH |
| `nex-oms-shipments-actions` | `database` | `shipments-actions` | 60 | 1 | 3600 | 120, odczyt SSH |
| `nex-oms-shipments-sync` | `database` | `shipments-sync` | 60 | 1 | 3600 | 120, odczyt SSH |
| `nex-oms-automation` | `database` | `automation` | 60 | 1 | 3600 | 120, odczyt SSH |
| `nex-oms-ksef` | `database` | `ksef` | 60 | 1 | 3600 | 90, odczyt SSH |
| `nex-oms-ksef-submit` | `ksef_submit` | `ksef-submit` | 150 | 1 | 3600 | 180, odczyt SSH |

Wszystkie: `--sleep=3`, `autostart=true`, `autorestart=true`, uzytkownik `ubuntu`. Operator potwierdzil siedem programow `RUNNING`, 0 zadan w kolejce i 0 `failed_jobs` w chwili kontroli. To migawka, nie gwarancja pozniejszego stanu ani poprawnosci biznesowej.

Pozniejszy odczyt SSH z 2026-10-24 potwierdzil siedem programow RUNNING, ponizsze stopwaitsecs oraz stopasgroup=true i killasgroup=true we wszystkich siedmiu plikach. Zweryfikowano zgodnosc odcisku hosta. Nie odczytywano ponownie backlogu ani failed_jobs. Nie potwierdzono limitow zasobow, konfiguracji logowania ani efektywnych wartosci parametrow niewystepujacych w wybranych liniach.

### 3.2. Aktywne limity a domyslne wartosci kodu

| Parametr | Wartosc domyslna repozytorium | Aktywna wartosc VPS, operator |
|---|---:|---:|
| `queue.connections.database.retry_after` | 90 s, `DB_QUEUE_RETRY_AFTER` | **300 s** |
| `queue.connections.ksef_submit.retry_after` | 240 s, `KSEF_SUBMIT_RETRY_AFTER` | **240 s** |
| `ksef.automatic_submission.timeout_seconds` | 120 s | **120 s** |

Domyslne 90 s w kodzie **nie oznacza bledu obecnego VPS**. Nie nalezy jednak przenosic tej wartosci bezwarunkowo na nowy serwer: job importu PrestaShop ma 180 s, a job statusu wychodzacego dziedziczy tutaj 210 s. Ocenia sie aktywne `config()`, a nie sam `.env`; proces dlugo zyjacy moze nadal miec stara konfiguracje. Zmiana konfiguracji wymaga pozniejszego, kontrolowanego przeladowania workerow.

### 3.3. Jeden cron

Aktywny plik `/etc/cron.d/nex-oms-scheduler` wedlug operatora:

```cron
* * * * * ubuntu cd /var/www/nex-oms && /usr/bin/php artisan schedule:run >> /var/www/nex-oms/storage/logs/scheduler.log 2>&1
```

To format `/etc/cron.d` z polem uzytkownika; w crontabie konkretnego uzytkownika pole `ubuntu` nie wystepuje. Nie instalowac obu wersji naraz.

Operator wylaczyl osobne crony `nex-oms-order-import` i `nex-oms-inpost-refresh`; wskazal kopie w `/root/nex-oms-cron-backup-20261024_221438`. Audyt nie odczytywal katalogu kopii. Potwierdzono w dostarczonych informacjach minutowe uruchamianie schedulera. Nie dokladac drugiego crona, `schedule:work` ani starych bezposrednich komend importu do tego samego stagingu.

## 4. Znaczenie limitow w Laravel 12.63.0

Podstawa: `Illuminate/Queue/{Queue,Worker,DatabaseQueue,Listener}.php`, `Queue/Console/WorkCommand.php`, middleware `WithoutOverlapping` i `RateLimited`, `Bus/UniqueLock.php`, `Console/Scheduling/ManagesAttributes.php` w lokalnym `vendor/laravel/framework/src/`.

| Parametr | Rzeczywiste znaczenie |
|---|---|
| Job `$timeout` | Pierwszenstwo przed `--timeout` workera. Wartosc trafia do payloadu przy kolejkowaniu; stary payload nie zmienia sie przez sama edycje klasy. |
| Worker `--timeout` | Limit wykonania joba bez wlasnego timeoutu. W trybie daemon `queue:work` Laravel stosuje alarm `pcntl`; jego dostepnosc trzeba potwierdzic dla PHP CLI. Timeout calego joba to nie timeout pojedynczego HTTP. |
| Polaczenie `retry_after` | Wiek rezerwacji, po ktorym niedokonczony job moze byc ponownie pobrany. Nie jest opoznieniem zwyklego retry, czasem oczekiwania w kolejce ani limitem API. |
| Job `$tries` / `tries()` | Pierwszenstwo przed `--tries` workera. Obejmuje rowniez pobrania zakonczone `release()` przez middleware lub sam job. `--tries=1` nie ogranicza klasy z `$tries=100` do jednej proby. |
| `retryUntil()` / `$retryUntil` | Deadline ponawiania moze zastapic ograniczenie liczby prob. W zadnej z 31 klas nie znaleziono takiego ustawienia. |
| `$maxExceptions` | Osobny limit nieobsluzonych wyjatkow. `release()` bez wyjatku nie jest tym samym. Wysokie `$tries` nie znosi np. `$maxExceptions=1`. |
| `$backoff` | Opoznienie retry po wyjatku; payload joba ma pierwszenstwo przed workerem. Kolejne elementy tablicy dotycza kolejnych prob, ostatni jest ponownie uzywany. Jawne `release(n)` i domenowy `next_attempt_at` sa odrebne. |
| `$failOnTimeout` | Jawne zakonczenie joba jako failed przy alarmie. Wlasne `true` ma `SendOrderEmailJob`, a po lokalnej poprawce rowniez `ExecuteAutomationRunJob`; pozostale moga osiagnac failed przez limit prob/wyjatkow. `SIGKILL` moze ominac cleanup i `failed()`. |
| `$uniqueFor` | TTL cache locka `ShouldBeUnique`, ograniczajacego dispatch tej samej intencji. Nie jest TTL joba ani trwalym potwierdzeniem wykonania operacji zewnetrznej. Po zakonczeniu lock jest zwalniany; po twardym przerwaniu moze pozostac do wygasniecia. |
| `WithoutOverlapping` joba | Blokada wykonania po kluczu; `shared()` wspoldzieli klucz pomiedzy klasami. TTL musi obejmowac wykonanie i zapas. `dontRelease()` oznacza pominiecie przy zajetej blokadzie, nie automatyczne odlozenie tego joba. |
| `RateLimited` | Przy limicie job jest zwykle odkladany. Zuzywa probe nawet bez wykonania `handle()`. Nie zapewnia idempotencji operacji. |
| `--sleep=3` | Odczekanie po braku zadan; nie trzysekundowa przerwa po kazdym wykonanym jobie. |
| `--max-time=3600` | Planowa rotacja procesu sprawdzana pomiedzy jobami. Nie zabija joba po godzinie ani nie zastepuje timeoutu. |
| `stopwaitsecs` | Czas oczekiwania Supervisora po sygnale zatrzymania, przed wymuszeniem zabicia procesu. Powinien przekraczac najdluzsze legalne wykonanie joba z marginesem. |
| `autostart` / `autorestart=true` | Start programu wraz z Supervisorem i wznowienie po zakonczeniu procesu, takze rotacji/restart signal. Nie uruchamiaja ponownie samej operacji biznesowej ani nie wlaczaja ustawien integracji. |

Relacja podstawowa: **najwiekszy efektywny timeout joba < `retry_after` polaczenia**, z zapasem na przerwanie i cleanup; **`stopwaitsecs` > najdluzszy czas joba**, rowniez z zapasem. Nie wolno dopuscic, aby rezerwacja wygasla, kiedy stary proces nadal wykonuje zewnetrzny zapis. Ograniczenia HTTP/podprocesow, pcntl, restart i trwale zabezpieczenia domenowe nadal sa potrzebne. Timeout nie gwarantuje, ze zewnetrzny serwis nie przyjal zadania.

### 4.1. Ocena obecnych wartosci

- `database`: najwiekszy znaleziony efektywny limit to **210 s**, `SyncPrestashopOrderStatusJob` bez wlasnego timeoutu. Dalej import PrestaShop 180 s, rozne joby 120/80/60 s. **210 < 300**, margines 90 s. Nie znaleziono joba `database` z timeoutem >= 300 s ani jawnym timeoutem 0.
- `ksef_submit`: job ma wlasne **120 s**, wiec workerowe 150 s nie wydluza jego limitu. **120 < 240**, margines 120 s. Nie trzeba teraz zmniejszac 150 s ani podnosic 240 s.
- Znane `stopwaitsecs`: integrations 240 > 210; sync 120 > 60; ksef 90 > 60; ksef-submit 180 > 120 i > workerowe 150. Podstawowe relacje sa poprawne. Pozniejszy odczyt SSH potwierdzil takze default/automation/shipments-actions: 120 > 60 s. Podstawowe relacje sa poprawne dla wszystkich siedmiu programow.
- Dla default/automation/shipments-actions odczytano **120 s**, przy limicie 60 s; zmiana nie jest potrzebna. Dla integrations obecny zapas 30 s nalezy obserwowac przy dlugim cleanup. Kazda przyszla zmiana timeoutu wymaga ponownego sprawdzenia retry_after, blokad i stopwaitsecs, nie samej jednej flagi.
- Te relacje pozwalaja pozostawic obecne timeouty/retry_after bez zmian; **nie dowodza**, ze kazda partia importu lub lancuch automatyzacji miesci sie w swoim budzecie czasu.

### 4.2. Budzet pojedynczego requestu a calego joba

Ponizsze wartosci pochodza z kodu klientow i domyslnej konfiguracji, **nie sa nowym odczytem VPS**. Ustawienia `services.*` moga zostac nadpisane zmiennymi srodowiskowymi; najpierw odczytac aktywne, wybrane klucze, zamiast publikowac cala konfiguracje integracji.

| Klient / sciezka | Timeout pojedynczego requestu / operacji w kodzie |
|---|---|
| `InPostClient` | `services.inpost.timeout`, domyslnie 20 s |
| `DpdClient`, `DpdInfoServicesClient` | `services.dpd.timeout`, domyslnie 25 s |
| `AllegroClient`, `AllegroOAuthService` | `services.allegro.timeout`, domyslnie 25 s |
| `AllegroShippingClient`, device auth | `services.allegro_shipping.timeout`, domyslnie 25 s |
| `PrestashopClient` | connect 5 s, request 15 s |
| `PayNowClient::getPaymentStatus` | connect 5 s, request 15 s; test polaczenia ma request 10 s, nie jest zadaniem schedulera |
| `KsefHttpClient`, `KsefLatarniaClient` | connect 5 s, request 15 s z `config/ksef.php` |
| `AutomationUrlActionService` | connect 5 s, request 10 s, nadpisywalne `services.automation_url.*` |
| `SmtpTransportFactory` | timeout strumienia 10 s; nie limit calego joba z zalacznikami |

Zrodla konfiguracji: [services.php](../config/services.php), [ksef.php](../config/ksef.php). Autoryzacja/odnowienie tokenu, paginacja, odczyt wyniku, zapis do bazy i PDF moga skladac sie na jedno wykonanie. KSeF ma takze polling auth (domyslnie do 20 prob z interwalem 500 ms); samo 15 s request timeout nie ogranicza calej sesji do 15 s. Limit workera nadal przerywa job przy przekroczeniu jego budzetu. Nie zwiekszac timeoutow HTTP do wartosci bliskich/wyzszych od timeoutu joba bez przegladu calej sciezki.

## 5. Inwentaryzacja wszystkich jobow

W `app/` i `Modules/` znaleziono **31 klas `ShouldQueue`**. Nie znaleziono dodatkowych kolejkowanych listenerow/mailables ani pozniejszych override `onQueue`/`onConnection` zmieniajacych ponizsza mape. `Modules/Integrations/NexPrinter/Models/NexPrintJob.php` to model, nie job Laravel. PayNow nie ma osobnej klasy joba w tym zakresie.

Wartosci w sekundach. `W60`/`W210` oznacza brak `$timeout` i dziedziczenie z konkretnego workera VPS. `-` w backoff oznacza brak ustawienia klasy (bez `--backoff` worker stosuje 0); w kolumnie ME brak wlasnego `$maxExceptions`; w U brak `ShouldBeUnique`. U jest TTL `uniqueFor`, nie cooldown po sukcesie. Poza dwoma jawnymi przypisaniami KSeF polaczenie jest domyslne, na VPS `database`.

### 5.1. E-mail i Automation

| Klasa | Kolejka | Timeout | Tries | Backoff | ME | U |
|---|---|---:|---:|---|---:|---:|
| [SendOrderEmailJob](../app/Jobs/SendOrderEmailJob.php) | default | 60 | 1 | - | - | 300 |
| [EvaluateAutomationEventJob](../Modules/Automation/Jobs/EvaluateAutomationEventJob.php) | automation | W60 | 3 | 5,15,30 | - | 300 |
| [ExecuteAutomationRunJob](../Modules/Automation/Jobs/ExecuteAutomationRunJob.php) | automation | W60 | 1 | - | - | 300 |

### 5.2. Allegro, kolejka integrations

Katalog klas: [Modules/Integrations/Allegro/Jobs](../Modules/Integrations/Allegro/Jobs).

| Klasa | Timeout | Tries | Backoff | ME | U |
|---|---:|---:|---|---:|---:|
| `DiscoverAllegroOrdersJob` | 80 | 1 | - | - | 600 |
| `SyncAllegroOrderJob` | 80 | 10 | - | - | 300 |
| `SyncAllegroOrderStatusJob` | 80 | 4 | 15,60,180 | - | 600 |
| `ExportAllegroShipmentJob` | 80 | 4 | 15,60,180 | - | 600 |
| `ExportAllegroInvoiceJob` | 80 | 4 | 15,60,180 | - | 600 |
| `DiscoverAllegroReturnsJob` | 60 | 1 | - | - | 180 |
| `SyncAllegroReturnJob` | 60 | 1 | - | - | 180 |
| `DiscoverAllegroRefundsJob` | 60 | 1 | - | - | 180 |
| `SyncAllegroRefundJob` | 60 | 1 | - | - | 180 |
| `ProcessAllegroRefundCommandJob` | 120 | 1 | - | - | 180 |
| `ProcessAllegroCommissionRefundJob` | 120 | 1 | - | - | 180 |

### 5.3. PrestaShop, kolejka integrations

Katalog klas: [Modules/Integrations/Prestashop/Jobs](../Modules/Integrations/Prestashop/Jobs).

| Klasa | Timeout | Tries | Backoff | ME | U |
|---|---:|---:|---|---:|---:|
| `DiscoverPrestashopOrdersJob` | 120 | 4 | 15,60,180 | - | 600 |
| `ImportPrestashopOrderJob` | 180 | 4 | 30,120,300 | - | 900 |
| `SyncPrestashopInboundStatusesJob` | 120 | 4 | 15,60,180 | - | 600 |
| `SyncPrestashopPaymentsJob` | 120 | 4 | 15,60,180 | - | 600 |
| `SyncPrestashopOrderStatusJob` | W210 | 4 | 15,60,180 | - | 600 |

### 5.4. Kurierzy

Katalogi: [InPost](../Modules/Integrations/InPost/Jobs), [DPD](../Modules/Integrations/DPD/Jobs), [Allegro Shipping](../Modules/Integrations/AllegroShipping/Jobs). Nazwy kolejek pochodza z [ShipmentQueue](../Modules/Shipments/Support/ShipmentQueue.php).

| Klasa | Kolejka | Timeout | Tries | Backoff | ME | U |
|---|---|---:|---:|---|---:|---:|
| `CreateInPostShipmentJob` | shipments-actions | W60 | 100 | - | 1 | 300 |
| `CancelInPostShipmentJob` | shipments-actions | W60 | 100 | - | 1 | - |
| `RefreshInPostShipmentJob` | shipments-sync | W60 | 100 | 10,30,60 | 3 | 120 |
| `CreateDpdShipmentJob` | shipments-actions | W60 | 5 | 15,60,180 | 1 | 300 |
| `RefreshDpdShipmentJob` | shipments-sync | W60 | 5 | 30,120,300 | 3 | 120 |
| `CreateAllegroShipmentJob` | shipments-actions | W60 | 4 | 15,60,180 | 1 | 300 |
| `CancelAllegroShipmentJob` | shipments-actions | W60 | 5 | - | - | - |
| `ResolveAllegroShipmentCommandJob` | shipments-actions | W60 | 30 | - | - | - |
| `ResolveAllegroCancellationJob` | shipments-actions | W60 | 30 | - | - | - |
| `RefreshAllegroShipmentJob` | shipments-sync | W60 | 5 | 30,120,300 | - | 120 |

### 5.5. KSeF

| Klasa | Polaczenie / kolejka | Timeout | Tries | Backoff joba | ME | U |
|---|---|---:|---:|---|---:|---:|
| [KsefAutomaticInvoiceSubmissionJob](../Modules/Ksef/Jobs/KsefAutomaticInvoiceSubmissionJob.php) | ksef_submit / ksef-submit | 120 | 1 | - | - | 21600 |
| [KsefSubmissionFollowUpJob](../Modules/Ksef/Jobs/KsefSubmissionFollowUpJob.php) | database / ksef | W60 | 1 | - | - | 21600 |

Konstruktor automatycznego joba odczytuje timeout/unikalnosc/polaczenie/kolejke z `config/ksef.php` (min. timeout 30 s, min. U 60 s). Follow-up jawnie ustawia `database`, kolejke z konfiguracji i U co najmniej 60 s, aktualnie 21600 s. Domenowy backoff follow-up **60,300,900,3600 s** jest odrebny od backoff joba Laravel.

## 6. Harmonogram: kompletna mapa 19 zadan lokalnego kodu

Zrodlo: [routes/console.php](../routes/console.php). Wszystkie ponizsze callbacki maja nazwe i `withoutOverlapping()`. Nie stosuja `onOneServer()`. Domyslny TTL blokady schedulera Laravel wynosi 1440 minut. Blokada callbacka dispatchujacego konczy sie po callbacku, **nie po wykonaniu jobow**. Nie zastepuje unikalnosci i blokad joba. Po twardym przerwaniu moze wymagac diagnostyki pozostawionego locka.

| Nazwa | Czestotliwosc wyzwalania | Wykonanie / docelowa kolejka |
|---|---|---|
| `inpost-refresh-active-shipments` | co godzine | `RefreshInPostShipmentJob`, shipments-sync |
| `dpd-refresh-active-shipments` | co godzine | `RefreshDpdShipmentJob`, shipments-sync |
| `inpost-prune-api-logs` | codziennie 03:30 | bezposrednio `IntegrationApiLogPruner`, usuwa stare logi zgodnie z retencja |
| `allegro-shipping-refresh-active-shipments` | co godzine | `RefreshAllegroShipmentJob`, shipments-sync |
| `ksef-submission-follow-up` | co minute | dispatcher, database/ksef |
| `ksef-latarnia-sync` | co 5 minut | bezposrednio `KsefLatarniaOperationalSyncService`, opcjonalny HTTP |
| `order-email-recover-queued` | co 5 minut | recovery, `SendOrderEmailJob`, default |
| `automation-recover-interrupted` | co minute | oznaczenie porzuconych running jako interrupted, bez retry i API; nowy kod, jeszcze niewdrozony na VPS |
| `paynow-reconcile-pending-payments` | co minute | bezposrednio `PayNowReconciliationService`, HTTP i lokalne ksiegowanie |
| `prestashop-sync-order-statuses` | co minute | przychodzace `SyncPrestashopInboundStatusesJob`, integrations |
| `prestashop-import-new-orders` | co minute | `DiscoverPrestashopOrdersJob`, potem `ImportPrestashopOrderJob`, integrations |
| `prestashop-sync-payments` | co minute | `SyncPrestashopPaymentsJob`, integrations |
| `allegro-import-orders` | co minute | `DiscoverAllegroOrdersJob`, potem `SyncAllegroOrderJob`, integrations |
| `allegro-recover-shipment-exports` | co minute | recovery zapisanych eksportow, `ExportAllegroShipmentJob`, integrations |
| `allegro-recover-invoice-exports` | co minute | recovery zapisanych eksportow, `ExportAllegroInvoiceJob`, integrations |
| `allegro-import-customer-returns` | co minute | discover/sync zwrotow, integrations |
| `allegro-import-payment-refunds` | co minute | discover/sync refundacji, integrations |
| `allegro-refund-commands` | co minute | proces zapisanych zlecen, integrations |
| `allegro-commission-refund-claims` | co minute | proces zapisanych wnioskow, integrations |

Czestotliwosc callbacka nie oznacza wykonania HTTP co minute dla kazdego rekordu. Obowiazuja ustawienia kont, daty `next_*`, interwaly, warunki i limity partii w dispatcherach. Przykladowo zwroty/refundacje zwykle czekaja okolo 5 minut na kolejne sprawdzenie; PrestaShop przychodzace statusy dopuszczaja interwal 1/5/10 minut. Przesylki sa wybierane wedlug dostawcy, istnienia zewnetrznego ID, nieterminalnego statusu oraz `last_synced_at` (domyslny prog 60 minut); DPD wymaga takze tracking number.

Godzina 03:30 i granice godzin zaleza od aktywnej strefy aplikacji; samo Ubuntu nie dowodzi zgodnosci z Europe/Warsaw. Nalezy odczytac `config('app.timezone')` i zegar serwera.

PayNow obsluguje do 20 oczekujacych platnosci na przebieg, ponowny odczyt danego rekordu po co najmniej 2 minutach. `PayNowPaymentStatusService` ksieguje transakcyjnie, z blokada i deduplikacja zdarzen; scheduler nie inicjuje nowej platnosci. Latarnia ma wlasny gate `ksef.latarnia.sync_enabled` (domyslnie false), do dwoch srodowisk TEST/Production i po jednym GET status/messages; DEMO nie jest zrodlem Latarni. Obie sciezki HTTP dzialaja **poza kolejka**. Limity workerow i retry_after ich nie obejmuja; dlugi callback moze opoznic kolejne zadania danego przebiegu.

Nie znaleziono zadania harmonogramu kierowanego do kolejki nieobslugiwanej przez siedem zadeklarowanych workerow. Nie ma okresowego wyszukiwania historycznych faktur do automatycznego first-send KSeF.

## 7. Ochrona przed powtorzeniem i granice gwarancji

### 7.1. KSeF

[KsefAutomaticInvoiceSubmissionPolicy](../Modules/Ksef/Services/KsefAutomaticInvoiceSubmissionPolicy.php) wymaga deployment gate `ksef.invoice_submission_enabled`, aktywnych ustawien, **`automatic_submission=true`**, dozwolonego srodowiska, poprawnego kontekstu NIP, wlaczonej serii i wystawionej zwyklej Faktury (nie Pro formy ani Korekty), bez istniejacej proby/Offline w danym srodowisku. Dispatcher zapisuje oczekiwane srodowisko i kontekst, job przed wykonaniem ponownie je sprawdza. Samo uruchomienie workera nie wlacza tych warunkow.

Wedlug migawki VPS aktywne jest **DEMO**, a `automatic_submission=false`. Nie wywnioskowano z tego wartosci deployment gate. Follow-up wymaga swoich warunkow gate/aktywnosci i odczytuje status, uzgadnia wynik albo pobiera UPO; nie wymaga wlaczenia automatic_submission i nie wykonuje nowego invoice POST. Samo wylaczenie automatic_submission nie zatrzymuje innych integracji/Automation.

[KsefManualInvoiceSubmissionService](../Modules/Ksef/Services/KsefManualInvoiceSubmissionService.php) i [KsefInvoiceSubmissionService](../Modules/Ksef/Services/KsefInvoiceSubmissionService.php) finalizuja/przygotowuja w transakcji, sprawdzaja istniejace proby, Offline i OutsideKsef, a transport wykonuja poza transakcja. [KsefSubmissionExecution](../Modules/Ksef/Services/KsefSubmissionExecution.php) stosuje trwaly claim, wlasciciela, lease (obecnie 300 s) oraz warunkowo utrwalona, jednokierunkowa granice `invoice_post_started_at` **przed** HTTP. To dowod mozliwosci POST, nie potwierdzenie odbioru przez MF. Tego POST nie wolno slepo ponawiac po awarii.

Follow-up ma blokade cache (120 s), claim w bazie i odlozenie nastepnej proby przed odczytem HTTP. `tries=1` nie wylacza domenowego follow-up schedulera. Przerwane `Preparing`/`SessionOpened` wymagaja oceny istniejacego protokolu recovery; dispatcher due wybiera Submitted/Processing/Uncertain i Accepted bez UPO, nie jest ogolnym automatycznym recovery kazdego przerwanego first-send. Nie obchodzic tej polityki przez `queue:retry all`.

### 7.2. Allegro

- Import ma trwale tozsamosci konta/checkoutu, rekordy zdarzen i transakcyjne powiazania zamowienia; odczyt aktualizuje istniejace zamowienie zamiast traktowac kazde zdarzenie jako nowy zakup.
- `SyncAllegroOrderJob` oraz joby statusu/eksportow wspoldziela blokade zamowienia z TTL 180 s i `releaseAfter(15)`, przy timeoutach 80 s. Osobne discover/sync zwrotow i refundacji maja blokade konta 120 s, `dontRelease()`, timeout 60 s. Powtorne wyzwalanie zapewnia dispatcher, nie pominiety job.
- Import: `$tries=10` Laravel to nie liczba biznesowych prob pobrania. `SyncAllegroOrderJob` zapisuje blad i `next_attempt_at`; discovery ponownie dispatchuje zdarzenia z `attempts < 4`. Zlapany wyjatek moze zakonczyc job jako DONE, z biznesowym bledem w historii. Po wyczerpaniu 4 prob potrzebna jest diagnoza rekordu, nie automatyczne resetowanie licznika.
- [AllegroShipmentExportService](../Modules/Integrations/Allegro/Services/AllegroShipmentExportService.php) i [AllegroInvoiceExportService](../Modules/Integrations/Allegro/Services/AllegroInvoiceExportService.php): fingerprint intencji, trwale rekordy eksportow, blokady i odczyt istniejacego wyniku przed kolejnym zapisem. Recovery dotyczy zapisanych eksportow, nie masowego eksportu calej historii.
- [AllegroRefundCommandService](../Modules/Integrations/Allegro/Services/AllegroRefundCommandService.php): claim queued -> sending w transakcji, niezmienny command ID/tresc, pojedynczy zapis, wynik niepewny zachowuje rezerwacje. Stale sending po 5 minutach staje sie uncertain; dalszy proces odczytuje znany ID refundacji, nie tworzy nowego przelewu. Brak ID moze wymagac jawnego uzgodnienia przez operatora.
- [AllegroCommissionRefundService](../Modules/Integrations/Allegro/Services/AllegroCommissionRefundService.php): sprawdzenie istniejacego wniosku, transakcyjny claim, po bledzie/awarii uncertain i uzgadnianie odczytem, bez slepego ponownego POST; stale sending po 5 minutach staje sie uncertain.

[AllegroClient](../Modules/Integrations/Allegro/Services/AllegroClient.php) dopuszcza techniczne powtorzenie requestu po jednoznacznym HTTP 401 i odnowieniu OAuth, z ta sama trescia (dla refundacji takze tym samym commandId), po ponownym sprawdzeniu tozsamosci/uprawnien. To nie retry niepewnego timeoutu/5xx ani nowe zlecenie biznesowe. Nie nalezy interpretowac pojedynczego claimu jako gwarancji doslownie jednego requestu HTTP we wszystkich scenariuszach.

Ochrony domenowe sa istotniejsze od samych unique locks, ale audyt statyczny nie daje gwarancji exactly-once zewnetrznego API przy kazdej awarii. Nie wykonywano zadnego rzeczywistego wniosku, refundacji ani importu w tym audycie.

### 7.3. PrestaShop

[PrestashopOrderImportService](../Modules/Integrations/Prestashop/Services/PrestashopOrderImportService.php) blokuje konto i powiazanie zewnetrznego zamowienia w transakcji. Proby automatyczne maja osobne rekordy, platnosci sa uzgadniane przez [PrestashopPaymentAccountingService](../Modules/Integrations/Prestashop/Services/PrestashopPaymentAccountingService.php) z blokadami i dowodami zrodla. Retry importu nie powinno byc utozsamiane z ponownym tworzeniem zamowienia/ksiegowaniem bez weryfikacji.

**Ryzyko wymagajace osobnego etapu przed skalowaniem**: [SyncPrestashopOrderStatusJob](../Modules/Integrations/Prestashop/Jobs/SyncPrestashopOrderStatusJob.php) deduplikuje po zdarzeniu, ale nie ma wspolnego middleware locka na zamowienie. [PrestashopOutboundStatusSyncService](../Modules/Integrations/Prestashop/Services/PrestashopOutboundStatusSyncService.php) robi GET aktualnego stanu, ponownie sprawdza status OMS i dopiero POST historii (opcjonalnie z powiadomieniem klienta), potem zapisuje lokalny wynik. GET-before-POST nie jest atomowa idempotencja zewnetrzna: dwa rozne zdarzenia lub niepewny wynik POST moga wymagac uzgodnienia; przy rownoleglych wykonaniach moga powstac dwa wpisy/powiadomienia. Obecny jeden worker integrations ogranicza rownoleglosc tej sciezki, lecz nie gwarantuje braku innych wywolan. Nie wykazano rzeczywistego duplikatu ani nie zmieniono kodu. Przed zwiekszeniem liczby workerow zalecany oddzielny plan serializacji i trwalego sledzenia wyniku zapisu.

HTTP PrestaShop ma ograniczenia pojedynczego requestu; wielokrotne odczyty/paginacja nadal sumuja sie do budzetu joba 120/180/210 s. Nie potwierdzono pomiarami, ze najwieksza partia zawsze zmiesci sie w tym czasie.

### 7.4. Przesylki

Wspolne middleware per przesylka: InPost/DPD `releaseAfter(5)`, TTL 120 s; Allegro Shipping TTL 180 s, rowniez `releaseAfter(5)`. Wszystkie maja limitery API i obecnie limit joba 60 s. Zatem TTL przekracza legalny czas wykonania, lecz opoznienia/rezerwacje w kolejce i wyczerpanie prob nadal wymagaja monitoringu.

[ShipmentCreationAttemptService](../Modules/Shipments/Services/ShipmentCreationAttemptService.php) zachowuje UUID i historie proby, rozroznia failed i unknown. Joby create maja `maxExceptions=1`; wysokie tries sa glownie potrzebne do release middleware, nie do 100 ponownych POST nadania. Wynik niepewny nie jest dowodem, ze przesylka nie powstala. Rezerwacja/ID komendy i odczyt wyniku sa wazniejsze niz ponowienie joba.

`ResolveAllegroShipmentCommandJob` i `ResolveAllegroCancellationJob` odkladaja nierozstrzygniety odczyt o 5 s, z tries 30. Nie oznacza to gwarantowanego okna 150 s: proby moga zuzywac takze limiter i overlap. Brak unikalnosci w cancel/resolverach nie oznacza braku ochrony w serwisach, ale sam middleware nie dowodzi jednokrotnego zewnetrznego zapisu. Przed retry nadania trzeba sprawdzic istniejacy wynik u kuriera.

### 7.5. E-mail i Automation

[OrderEmailSender](../app/Services/OrderEmailSender.php) atomowo przejmuje queued -> sending, weryfikuje snapshot/zalaczniki/konfiguracje SMTP; przerwanie po mozliwej wysylce daje Unknown bez automatycznego resend. [OrderEmailQueuedRecoveryService](../app/Services/OrderEmailQueuedRecoveryService.php) co 5 minut redispatchuje tylko nietkniete queued starsze niz **10 minut**, bez rozpoczetego transportu i bez prob. Nie odzyskuje automatycznie Sending/Unknown. `SendOrderEmailJob` ma tries 1 i failOnTimeout true. Twarde zabicie moze ominac failed hook; claim chroni przed podwojna wysylka, ale nie gwarantuje samoczynnego rozwiazania takiej sytuacji.

[AutomationEngine](../Modules/Automation/Services/AutomationEngine.php) deduplikuje run dla reguly/zdarzenia, ogranicza lancuch do 10 poziomow i zapisuje snapshot akcji. To nie limit czasu calego wykonania.

**Istotne ryzyko 60 s**: [ExecuteAutomationRunJob](../Modules/Automation/Jobs/ExecuteAutomationRunJob.php) bez wlasnego timeoutu uruchamia [AutomationRunner](../Modules/Automation/Services/AutomationRunner.php), ktory w jednym jobie przechodzi przez wszystkie pozostale akcje, az do opoznienia lub konca. Wielokrotne HTTP, PDF/druk czy operacje lokalne moga lacznie przekroczyc 60 s. Pierwotny audyt wykazal brak failed() i recovery porzuconych running. Lokalna poprawka dodaje token/lease wykonania, failed hook i scheduler oznaczajacy przerwane wykonania bez ich ponawiania; szczegoly oraz wymagania migracji opisuje [dokument poprawki](008-Automation-Execution-Recovery.md). Nie zostala wdrozona na VPS. Zwykle Throwable sa obslugiwane, zabicie procesu nie musi przejsc ta sciezka. Mozliwy jest pozostawiony running i juz wykonany efekt zewnetrzny bez completed kroku.

[AutomationUrlActionService](../Modules/Automation/Services/AutomationUrlActionService.php) stosuje limit HTTP (domyslnie connect 5 s, request 10 s), ale nowy `X-Request-ID` przy wykonaniu nie jest trwalym kluczem idempotencji. Ponowienie przerwanego kroku URL moze powtorzyc jego efekt. Unique lock 300 s i pomijanie completed krokow nie zamykaja okna pomiedzy zewnetrznym efektem a zapisem completed. Nie ponawiac calej automatyzacji bez przegladu wykonanych akcji.

Do osobnej decyzji: pomiary czasu, obsluga przerwanego run/step i idempotencja akcji; ewentualne dzielenie akcji na joby. Podwyzszenie timeoutu bez tej analizy nie rozwiazuje problemu i mogloby naruszyc retry_after/TTL.

## 8. Lokalne Windows/XAMPP a staging Linux

Aktualny [composer.json](../composer.json) uruchamia w `composer dev` wspolny listener:

```text
php artisan queue:listen --queue=shipments-actions,shipments-sync,integrations,automation,default --tries=1 --timeout=0
```

- Jeden listener obsluguje piec kolejek w podanej kolejnosci; dluga praca i staly naplyw wczesniejszych kolejek moga opozniac pozniejsze. VPS ma rozdzielone procesy, co izoluje takie zatory.
- Ten skrypt **nie uruchamia schedulera**, `ksef` ani odrebnego polaczenia `ksef_submit`/kolejki `ksef-submit`. Ich lokalny nadzor to osobna swiadoma decyzja operatora, nie skutek `composer dev`.
- `queue:listen` tworzy podproces `queue:work --once` dla kazdego joba. `--timeout=0` w listenerze wylacza timeout zewnetrznego procesu Symfony; nie oznacza, ze job VPS ma nieograniczony czas. W Laravel 12.63.0 listener nie przekazuje `--timeout` do dziecka, a sciezka `--once` nie rejestruje alarmu petli daemon `Worker::daemon`.
- Windows/XAMPP nie daje takiej samej obslugi pcntl/SIGALRM jak PHP CLI Linux. Sukces lokalnego listenera bez limitu nie dowodzi, ze job zmiesci sie w 60/80/120/180/210 s na VPS.
- Lokalne testy SQLite/sync nie odtwarzaja rezerwacji MySQL, kill/restart, limiterow wspoldzielonego cache ani opoznien prawdziwego API. W tym audycie nie uruchamiano nawet tych testow.
- `queue:listen --timeout=0` nie jest zalecanym odpowiednikiem produkcyjnego zestawu workerow. Nie zmieniono jednak lokalnego skryptu w ramach dokumentacji.

## 9. Diagnostyka tylko do odczytu, do wykonania przez operatora

Ponizszych polecen **nie wykonano w tym audycie**. Dzialaja na VPS po zalogowaniu uprawnionego operatora. Nie publikowac `.env`, pelnego `config:show`, payloadow jobow, tokenow, danych klientow ani surowych wyjatkow/API logow. Dostep do logow nie powinien oznaczac ich publicznego udostepnienia.

### 9.1. Procesy, parametry i cron

```bash
cd /var/www/nex-oms
sudo supervisorctl status
ps -eo user,pid,etimes,args | grep '[a]rtisan queue:work'
sudo grep -HEn '^\[program:|^(command|directory|user|numprocs|autostart|autorestart|stopsignal|stopwaitsecs|stopasgroup|killasgroup|stdout_logfile|stderr_logfile)[[:space:]]*=' /etc/supervisor/conf.d/nex-oms-*.conf
systemctl is-active cron supervisor
systemctl is-enabled cron supervisor
timedatectl status
/usr/bin/php -r 'echo PHP_VERSION, PHP_EOL; foreach (["pcntl", "posix", "pdo_mysql", "openssl"] as $e) { echo $e, "=", extension_loaded($e) ? "yes" : "no", PHP_EOL; }'
sudo cat /etc/cron.d/nex-oms-scheduler
sudo grep -RnsE 'artisan[[:space:]]+(schedule:(run|work)|.*(inpost|allegro|orders:import))' /etc/crontab /etc/cron.d /var/spool/cron/crontabs
/usr/bin/php artisan schedule:list
tail -n 80 storage/logs/scheduler.log
sudo journalctl -u cron --since '30 minutes ago' --no-pager
```

Grep to pomoc w szukaniu drugiego wyzwalacza, nie kompletny dowod jego braku (np. inne timery systemd lub zewnetrzny panel). Odczytac rowniez uzywane timery/panel wdrozeniowy. Stopwaitsecs odczytano w pozniejszej kontroli SSH; polecenia sluza rowniez ich przyszlej kontroli. Pozostaje sprawdzac efektywne numprocs, stopsignal i log paths. `RUNNING` oznacza zywy proces, nie wykonanie poprawnej operacji API.

### 9.2. Aktywna konfiguracja bez sekretow

Tinker odczytuje tu tylko jawnie wskazane, niesekretne wartosci; nie wywoluje jobow ani transportu:

```bash
/usr/bin/php artisan tinker --execute='dump(app()->environment(), config("app.debug"), config("app.timezone"), config("cache.default"), config("queue.default")); foreach (["database", "ksef_submit"] as $c) { dump($c, collect(config("queue.connections.".$c))->only(["driver", "connection", "table", "queue", "retry_after", "after_commit"])->all()); } dump(config("ksef.automatic_submission"), config("ksef.follow_up.queue"), config("ksef.invoice_submission_enabled"), config("ksef.latarnia.sync_enabled"));'
/usr/bin/php artisan tinker --execute='$s=\Modules\Ksef\Models\KsefSetting::query()->where("singleton_key", \Modules\Ksef\Models\KsefSetting::SINGLETON_KEY)->first(); dump($s?->only(["is_active", "environment", "automatic_submission"]));'
```

To konfiguracja nowego procesu CLI, nie dowod przeladowania juz dzialajacych workerow ani metadanych dawniej zserializowanych jobow. Porownac czas uruchomienia procesow i ostatniego kontrolowanego wdrozenia.

### 9.3. Backlog i failed jobs bez payloadow

```bash
/usr/bin/php artisan queue:failed
/usr/bin/php artisan tinker --execute='foreach (["database", "ksef_submit"] as $c) { $q=config("queue.connections.".$c); dump($c, \Illuminate\Support\Facades\DB::connection($q["connection"])->table($q["table"])->select("queue")->selectRaw("COUNT(*) AS total, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved, MIN(created_at) AS oldest_created_at, MIN(available_at) AS earliest_available_at")->groupBy("queue")->get()->all()); } dump(\Illuminate\Support\Facades\DB::connection(config("queue.failed.database"))->table(config("queue.failed.table", "failed_jobs"))->select("connection", "queue")->selectRaw("COUNT(*) AS total, MAX(failed_at) AS latest_failed_at")->groupBy("connection", "queue")->get()->all());'
```

Jesli oba polaczenia wskazuja te sama tabele, dwa wyniki nie oznaczaja dwoch kopii backlogu. `available_at` w przyszlosci oznacza odlozony job; `reserved_at` oznacza pobranie, nie automatycznie zawieszenie. Starsza rezerwacje porownac z retry_after wlasciwego polaczenia (300/240 s); brak nowego PId/zywego procesu i brak postepu wymagaja analizy domenowej, nie kasowania rezerwacji.

Z `queue:failed` ustalic klase, kolejke, czas i UUID. Nastepnie lokalnie w panelu sprawdzic historie integracji, eksportu, refundacji, wiadomosci lub transmisji KSeF. `failed_jobs=0` nie obejmuje zlapanych wyjatkow zapisanych w tych rekordach, stanu Unknown/Uncertain, skipow middleware ani pustej kolejki bez dzialajacego dispatchera. Porownywac kolejne odczyty wieku backlogu, liczby failed, ostatniego poprawnego przebiegu i postepu rekordow biznesowych; uzgodnic progi alarmowe z operatorem.

**Nie stosowac jako diagnostyki**: `schedule:run`, `queue:work --once`, `queue:retry all`, `queue:clear`, `queue:flush`, `cache:clear`, `schedule:clear-cache`, usuwania jobs/lockow ani resetowania licznikow prob. Moga wykonac, powtorzyc albo utracic rzeczywiste operacje. `schedule:run` moze uruchomic import, API, prunowanie logow, platnosci i juz zapisane zlecenia finansowe; wymaga odrebnej zgody i nie jest health checkiem.

## 10. Kontrolowane przeladowanie po wdrozeniu

To instrukcja do przyszlego, zatwierdzonego wdrozenia, **nie operacja wykonana teraz**.

1. Przejrzec backlog/failed i stany operacji o niepewnym wyniku. Potwierdzic docelowe srodowisko KSeF, ustawienia automatyzacji, konfiguracje cache i aktualne limity/stopwaitsecs. Nie wlaczac funkcji finansowych tylko dlatego, ze startuje worker.
2. Wdrozyc zatwierdzony kod i jego konfiguracje zgodnie z osobna procedura. Nie odtwarzac `.env`, nie zmieniac APP_KEY. Skonfigurowac poprawne aktywne retry_after **przed** nowymi workerami; nie czyscic przy okazji wspolnego cache/lockow.
3. Gdy zmienil sie tylko kod, preferowac lagodne zakonczenie joba sygnalem Laravel (ten sam cache store/prefix i katalog wydania co workery):

```bash
cd /var/www/nex-oms
sudo -u ubuntu /usr/bin/php artisan queue:restart
sudo supervisorctl status
ps -eo user,pid,etimes,args | grep '[a]rtisan queue:work'
```

4. `queue:restart` zapisuje znacznik w cache, nie zabija joba i nie zmienia konfiguracji Supervisor. Workery odczytuja go pomiedzy jobami, wychodza, a `autorestart=true` je wznawia. Polecenie moze objac rowniez inne workery korzystajace z tego samego cache/prefixu. Obserwowac nowe PID-y i dalszy postep, nie oczekiwac natychmiastowego zatrzymania dlugiego joba. Niezgodny store/prefix lub stare wydanie moze uniemozliwic odebranie znacznika.
5. Jezeli zatwierdzono zmiane plikow Supervisor: osobno wykonac `sudo supervisorctl reread`, przejrzec wykryte roznice, a nastepnie `sudo supervisorctl update NAZWA_ZMIENIONEGO_PROGRAMU`. `NAZWA_ZMIENIONEGO_PROGRAMU` jest placeholderem, nie nazwa do doslownego uruchomienia. Update/restart moze zatrzymac proces; najpierw sprawdzic stopwaitsecs i nie przerywac slepo operacji o niepewnym wyniku. Nie stosowac `restart all`, kill -9 ani restartu calego Supervisora jako rutynowej aktualizacji kodu.
6. Potwierdzic siedem programow RUNNING, nowe PID-y, brak rosnacego backlogu, nowe wpisy schedulera i prawidlowy wynik biznesowy. Nie uruchamiac dodatkowego `schedule:run`, aby tylko potwierdzic dzialanie istniejacego crona.

Rotacja `--max-time=3600` nie zastepuje kontrolowanego restartu po wdrozeniu. Restart nie uniewaznia starych payloadow, nie naprawia automatycznie failed i nie uzgadnia niepewnego wyniku zewnetrznego.

## 11. Wnioski i rekomendacje bez zmian wykonawczych

| Ocena | Ustalenie | Zalecenie do osobnej decyzji |
|---|---|---|
| Poprawna relacja czasowa | database max 210 < retry_after 300; ksef-submit job 120 < 240 | Pozostawic obecne wartosci, utrwalic je w procedurze kolejnych serwerow; nie diagnozowac VPS na podstawie domyslnego 90. |
| Weryfikacja procesu stop uzupelniona | Odczyt SSH potwierdzil 120 s dla trzech wczesniej nieustalonych workerow | Nie zmieniac tych wartosci; nadal kontrolowac pozostale aspekty zatrzymywania i monitorowac rzeczywiste operacje. |
| Ryzyko funkcjonalne Automation | Caly lancuch nadal ma budzet 60 s; lokalnie dodano recovery przerwan bez retry, jeszcze niewdrozone na VPS | Wdrozyc osobno zatwierdzona migracje i kod; nie ponawiac niepewnych efektow. Pomiary czasu i dzielenie dlugich lancuchow pozostaja osobnym etapem. |
| Ryzyko skalowania PrestaShop | Brak per-order locka wychodzacego statusu i atomowej idempotencji GET/POST | Nie zwiekszac liczby workerow integrations bez analizy serializacji/uzgadniania zapisu i powiadomien. |
| Ograniczenie unikalnosci | TTL i lock cache nie sa trwalym dowodem wykonania; po kill moze zostac lock, KSeF U do 6 godzin | Monitorowac backlog/stany biznesowe; nie czyscic lockow bez diagnozy. |
| Czesc pracy poza kolejka | PayNow i Latarnia HTTP w callbackach schedulera | Monitorowac czas przebiegu i log schedulera, nie tylko jobs/failed; nie dodawac drugiego wyzwalacza. |
| Brak dowodu czasowego | Paginacja/importy i lancuchy requestow moga wyczerpac timeout | Zbierac czasy z rzeczywistych, uprawnionych operacji; ewentualnie zaplanowac mniejsze partie, zamiast automatycznie wydluzac limity. |
| Ograniczona walidacja stagingu | Migawka 7 RUNNING / 0 jobs / 0 failed nie testuje kazdej sciezki | Osobny plan kontrolowanych testow integracyjnych/restartow, bez domyslnego wlaczania automatic_submission. |

Nie ma podstaw do natychmiastowej zmiany podanych timeoutow/retry_after. Mozna je pozostawic, z zastrzezeniem opisanych ryzyk domenowych; brakujace stopwaitsecs zostaly uzupelnione odczytem SSH. Potwierdzenie dzialajacego crona i workerow nie zamyka rollout'u KSeF Production ani nie dowodzi pelnej bezobslugowosci finansowych operacji. Zadnych zmian wykonawczych nie wdrozono na VPS. Lokalna poprawka Automation jest opisana osobno.
