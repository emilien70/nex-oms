# Ochrona przerwanych automatyzacji

Stan: 2026-10-24. Zatwierdzony etap po [audycie kolejek VPS](007-Queues-Scheduler-VPS.md): odczyt parametrow Supervisor oraz lokalna poprawka Automation. Nie obejmuje zmian synchronizacji PrestaShop, ogolnego dashboardu monitoringu, konfiguracji workerow ani wdrozenia na VPS.

## Odczyt VPS

Po osobnej zgodzie operatora polaczono sie przez SSH, weryfikujac zgodnosc odcisku hosta z wartoscia przekazana wczesniej przez operatora. Wykonano tylko odczyt wybranych parametrow plikow Supervisor i `supervisorctl status`.

Potwierdzono siedem programow RUNNING. `stopwaitsecs`: default/automation/shipments-actions/shipments-sync = 120 s, integrations = 240 s, ksef = 90 s, ksef-submit = 180 s. We wszystkich siedmiu konfiguracjach `stopasgroup=true`, `killasgroup=true`. Brak potrzeby zmiany tych limitow przy obecnych timeoutach. Nie wykonywano restartow, migracji, schedulera ani HTTP aplikacji na VPS. Nie odczytywano ponownie jobs/failed_jobs ani ustawien KSeF.

## Zachowanie poprawki

- `ExecuteAutomationRunJob` otrzymuje przed dispatch unikalny token wykonania, przechowywany rowniez w payloadzie kolejki. Stan running moze przejac tylko jedna proba, w krotkiej transakcji z blokada rekordu.
- `AutomationExecutionState` zapisuje token i pieciominutowa dzierzawe. Sprawdza wlasciciela, stan i waznosc dzierzawy przed rozpoczeciem/zapisem kroku oraz zakonczeniem. Start i zapis kroku odnawiaja dzierzawe. Zewnetrzne akcje sa wykonywane poza transakcja tego serwisu.
- Job ma `failOnTimeout=true`, nadal tries=1 i nie zmienia odziedziczonego timeoutu workera (VPS: 60 s). `failed()` oznacza przerwanie tylko wlasnej, nadal aktywnej proby. Callback starego segmentu nie zatrzyma nowej kontynuacji po opoznieniu.
- Twarde zabicie procesu moze ominac failed hook. Nowy callback schedulera `automation-recover-interrupted`, co minute z `withoutOverlapping()`, oznacza wygasle running jako terminalne `interrupted`. Nie dispatchuje retry ani nie wywoluje API.
- Biezacy krok running otrzymuje `uncertain`, komunikat wymagajacy sprawdzenia i czas zakonczenia. Juz zapisane completed/failed oraz ich output sa zachowane. Dalsze, nierozpoczete kroki sa prezentowane jako pominiete.
- Globalny panel aktywnosci i historia automatyzacji zamowienia pokazuja przerwanie oraz wynik niepewny. Nie jest to stwierdzenie, ze zewnetrzna operacja nie doszla do skutku. Nie dodano przycisku ani automatycznego mechanizmu ponowienia.
- Ponowny dispatch tej samej automatyzacji nie wykonuje ponownie terminalnego run. Powtorne recovery i failed hook nie tworza kolejnych zdarzen zamowienia. Wynik spoznionego procesu nie nadpisze interrupted/uncertain.
- Dotychczasowa obsluga zwyklych bledow i stop_on_error pozostaje. Kontynuacja po delay wymaga zgodnego numeru kroku i nadejscia zapisanego resume_at; waiting i queued nie sa uznawane przez recovery za porzucone running.

## Kompatybilnosc i ograniczenia

Nowe pola sa nullable. Dla historycznego running bez lease recovery opiera sie na updated_at starszym o co najmniej 300 s; nie resetuje run ani nie uruchamia jego akcji. Stary payload bez tokenu moze wykonac job z nowym tokenem w runtime, ale nie moze przypisac sobie wlascicielstwa w zrekonstruowanym failed hook. Wtedy bezpieczne oznaczenie przerwania zapewnia scheduler po wygasnieciu dzierzawy.

Pieciominutowa dzierzawa nie wydluza legalnego czasu joba 60 s. Po SIGKILL oznaczenie moze nastapic dopiero po jej wygasnieciu i nastepnym sprawnym przebiegu schedulera. Przy jego awarii nie nastapi do wznowienia harmonogramu. Nie podnosic czasu joba ponad dzierzawe bez osobnej analizy. Stare workery nie maja nowych kontroli; przy wdrozeniu trzeba je lagodnie zakonczyc i przeladowac.

Lancuch wielu akcji nadal wykonuje sie w jednym jobie, wiec moze przekroczyc 60 s. Ta poprawka zapewnia bezpieczne zatrzymanie i widoczny wynik, nie automatyczne dokonczenie dlugiego lancucha. Dzielenie akcji na osobne joby, pomiary pod obciazeniem, odzyskiwanie niedostarczonej kontynuacji delay i trwale klucze idempotencji zewnetrznych webhookow pozostaja osobnym etapem. Rozpoczety request lub proces zewnetrzny moze zakonczyc sie juz po lokalnym oznaczeniu interrupted; wlasnie dlatego wynik pozostaje niepewny i nie jest automatycznie ponawiany.

Nie zmieniono transportu KSeF, numeracji, obliczen finansowych, konfiguracji integracji ani reguly ponawiania zapisow marketplace. Nie wykonano rzeczywistego kill/restart Linux ani testu rownoleglych transakcji na MySQL; testy odtwarzaja przeplot stanow i spoznione wyniki na SQLite.

## Pliki implementacji

- `Modules/Automation/Jobs/ExecuteAutomationRunJob.php`
- `Modules/Automation/Models/AutomationRun.php`
- `Modules/Automation/Services/AutomationExecutionState.php` (nowy)
- `Modules/Automation/Services/AutomationRunner.php`
- `Modules/Automation/Services/AutomationActivityFeed.php`
- `routes/console.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/orders/show.blade.php`
- `resources/views/orders/partials/automation-history.blade.php`
- `database/migrations/2026_10_24_010000_add_execution_lease_to_automation_runs.php` (nowa)
- `tests/Feature/AutomationExecutionRecoveryTest.php` (nowy)
- `docs/006-Deployment.md`
- `docs/007-Queues-Scheduler-VPS.md`
- `docs/architecture.md`
- `docs/008-Automation-Execution-Recovery.md` (nowy)

Nie usunieto zadnych plikow. Zastane zmiany w `order-info-view.blade.php` i `OrderSourceDisplayTest.php` zachowano bez zmian. Operator potwierdzil restart lokalnego Main Queues po migracji; restartu ani wdrozenia na VPS nie wykonano.

## Migracja i lokalna kopia

Migracja dodaje do automation_runs tylko nullable execution_token, nullable execution_expires_at i indeks `automation_runs_execution_expiry_index`. Nie zmienia kluczy obcych, zamowien ani dokumentow finansowych.

Po osobnej zgodzie operatora wykonano lokalna kopie przez SQLite backup API z polaczenia tylko do odczytu, obejmujaca spojny stan rowniez przy WAL. Plik prywatny, ignorowany przez Git:

```text
storage/app/private/backups/automation-recovery-20261024-232141.sqlite
```

Integrity check kopii i lokalnej bazy: `ok`. Nastepnie uruchomiono **tylko powyzsza migracje** na potwierdzonej bazie local SQLite `C:\projekty\nex-oms\database\database.sqlite`. Porownanie tresci dotychczasowych kolumn z kopia potwierdzilo brak zmian w orders (241), order_items (325), invoices (86), invoice_items (168), invoice_number_counters (7), automation_runs (99), automation_run_steps (122). Nie uruchamiano recovery na danych operatora.

Odpowiadajaca komenda do osobnego, zatwierdzonego wdrozenia po backupie i sprawdzeniu srodowiska:

```bash
php artisan migrate --path=database/migrations/2026_10_24_010000_add_execution_lease_to_automation_runs.php --force
```

Na VPS migracji **nie wykonano**. Nie wlaczac nowego kodu workerow/schedulera przed dodaniem kolumn. Wdrozenie musi zapewnic lagodne zakonczenie starych wykonan, migracje i wczytanie zgodnego kodu, bez slepego ponawiania failed jobs; patrz procedura [kolejek](007-Queues-Scheduler-VPS.md). Nie usuwac pol przez rollback przy dzialajacych nowych workerach.

## Weryfikacja

PHP CLI lokalnie 8.2.12; phpunit.xml i zmienne procesu wymuszaly testing, SQLite :memory:, queue sync i cache array. Nie uruchamiano testow na roboczej bazie operatora. Nowe testy blokuja niezafalszowane HTTP i stosuja fake kolejki.

| Zakres | Wynik |
|---|---|
| Przed zmiana: filtr `Automation\|OrderAutomationHistory` | 67 testow, 451 asercji, 1 istniejace niepowodzenie |
| Nowy `AutomationExecutionRecoveryTest` | 18 testow, 113 asercji, PASS |
| Po zmianie: ten sam filtr | 85 testow, 564 asercje, to samo 1 niepowodzenie, bez nowych |
| `node --test tests/Frontend/order-automation-history.test.cjs` | 8 testow, PASS |
| Pint dla 8 plikow PHP | PASS |
| php -l dla 8 plikow PHP i 3 widokow Blade | PASS |
| git diff --check i sprawdzenie nowych plikow | PASS |

Wspolne niepowodzenie przed/po: `Tests\Feature\OrderStatusPollingTest::test_order_view_refreshes_after_automation_without_periodic_order_polling`, linia 78, oczekiwany fragment `refreshOrderState(['sales-documents', 'history'])`. Wystepowalo przed poprawka; nie zmieniono testu ani niezaleznej obslugi odswiezania. Nie wykonywano pelnej regresji wszystkich modulow i nie deklaruje sie jej zaliczenia.

Polecenia testow (Windows: uzyc C:\xampp\php\php.exe; uruchamiac tylko w zweryfikowanym srodowisku testing/:memory:):

```text
php vendor/phpunit/phpunit/phpunit tests/Feature/AutomationExecutionRecoveryTest.php --no-progress
php vendor/phpunit/phpunit/phpunit --filter "Automation|OrderAutomationHistory" --no-progress
node --test tests/Frontend/order-automation-history.test.cjs
```

Reczna kontrola na osobnym srodowisku testowym: odtworzyc wygasly running z completed, running i przyszlym krokiem; uruchomic wylacznie recovery tego scenariusza, otworzyc historie zamowienia i panel aktywnosci. Completed ma zachowac output, running ma pokazac wynik niepewny, dalszy krok ma byc pominiety. Drugie recovery nie moze utworzyc nowego zdarzenia lub joba. Nie zabijac rzeczywistych zlecen wysylek/platnosci w celu sprawdzenia tej poprawki.
