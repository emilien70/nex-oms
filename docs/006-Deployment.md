# Deployment

## Serwer testowy

Staging dziala na VPS OVHcloud z Ubuntu, bez Dockera, w `/var/www/nex-oms`. Migawka operatora z 2026-10-24: PHP 8.3.6, Laravel 12.63.0, `APP_ENV=staging`, `APP_DEBUG=false`. Szczegolowa konfiguracja i granice jej weryfikacji: [Kolejki, harmonogram i Supervisor na VPS](007-Queues-Scheduler-VPS.md).

## Katalog publiczny

Document root domeny lub subdomeny musi wskazywac na katalog:

```text
/public
```

Nie nalezy wystawiac katalogu glownego projektu jako katalogu publicznego.

## Podstawowe kroki

Ponizszy przyklad dotyczy pierwszej instalacji. Na istniejacej instalacji nie nadpisywac `.env` i nie generowac ponownie APP_KEY. Wdrozenie kodu i migracje wymagaja odrebnej zgody; audyt kolejek ich nie wykonuje.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Uprawnienia

Katalogi `storage/` i `bootstrap/cache/` musza byc zapisywalne przez oba konteksty wykonawcze aplikacji:

- PHP-FPM dziala jako `www-data` i obsluguje reczne zlecenia wydruku oraz pobieranie pliku przez NEX Printer;
- cron i workery Supervisor dzialaja jako `ubuntu`, w tym automatyczne akcje drukowania.

Samo ustawienie wlasciciela na jednego z tych uzytkownikow nie wystarcza. Zalecana konfiguracja uzywa osobnej grupy systemowej `nex-oms`, bitu `setgid` na katalogach oraz domyslnych ACL. Dzieki temu pliki utworzone przez `www-data` sa dostepne dla `ubuntu` i odwrotnie, niezaleznie od `umask` procesu. Kod aplikacji pozostaje wlasnoscia uzytkownika wdrozeniowego; nie nalezy zmieniac wlasciciela calego repozytorium na `www-data`.

### Prywatne pliki NEX Printer

Dysk Laravel `local` ma katalog bazowy `storage/app/private`. Kod NEX Printer zapisuje kopie PDF zlecen do:

```text
storage/app/private/nex-printer/jobs/{uuid}.pdf
```

Przy Fakturze VAT albo Korekcie PDF moze zostac najpierw wygenerowany i zapisany w `storage/app/private/invoices/{invoice_id}/`, a nastepnie skopiowany do zadania NEX Printer. Etykieta kurierska jest pobierana do pamieci z integracji przewoznika i jej prywatna kopia zadania trafia do `nex-printer/jobs/`. Katalog `storage/app/private/nex-printer/releases/` zawiera manifest i instalator klienta; aplikacja je odczytuje, a publikuje je operator lub proces wdrozeniowy.

Pliki te sa prywatne. Nie wolno wystawiac `storage/app/private` przez serwer WWW ani kierowac do niego publicznego dowiazania `storage`. Agent otrzymuje PDF przez kontrolowany endpoint aplikacji.

Blad:

```text
Unable to create a directory at /var/www/nex-oms/storage/app/private/nex-printer/jobs
```

oznacza, ze proces tworzacy zlecenie nie moze utworzyc katalogu albo pliku w prywatnym dysku. Nie jest to powod do stosowania `chmod 777`.

### Konfiguracja na VPS

Ponizszy wariant zaklada katalog aplikacji `/var/www/nex-oms`, uzytkownika wdrozeniowego i workerow `ubuntu` oraz PHP-FPM `www-data`. Polecenia nalezy wykonac kontrolowanie przy pierwszej instalacji albo po odtworzeniu plikow z kopii:

```bash
getent group nex-oms >/dev/null || sudo groupadd --system nex-oms
sudo usermod -aG nex-oms ubuntu
sudo usermod -aG nex-oms www-data

cd /var/www/nex-oms

sudo install -d -o ubuntu -g nex-oms -m 2770 \
    storage \
    storage/app \
    storage/app/private \
    storage/app/private/nex-printer \
    storage/app/private/nex-printer/jobs \
    storage/app/private/nex-printer/releases \
    bootstrap/cache

sudo chown -R ubuntu:nex-oms storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} +
sudo find storage bootstrap/cache -type f -exec chmod 0660 {} +

sudo setfacl -R -m u::rwX,g::rwX,m::rwX,o::--- storage bootstrap/cache
sudo find storage bootstrap/cache -type d \
    -exec setfacl -m d:u::rwx,d:g::rwx,d:m::rwx,d:o::--- {} +
```

`setfacl` pochodzi z pakietu `acl`; jezeli polecenia nie ma na serwerze, nalezy zainstalowac ten pakiet przed konfiguracja ACL. Katalogi maja tryb `2770`: cyfra `2` wlacza `setgid`, dlatego nowe podkatalogi dziedzicza grupe `nex-oms`. Pliki maja tryb `0660`. Uzytkownicy spoza wlasciciela i grupy nie otrzymuja dostepu.

Po pierwszym dodaniu uzytkownikow do grupy trzeba w kontrolowanym oknie przeladowac PHP-FPM i lagodnie wznowic workery, poniewaz juz uruchomione procesy nie pobiora nowej listy grup:

```bash
sudo systemctl restart php8.3-fpm
sudo -u ubuntu /usr/bin/php /var/www/nex-oms/artisan queue:restart
sudo supervisorctl status
```

Przed restartem workerow nalezy uwzglednic aktywne zadania i zasady opisane w sekcji o Supervisorze. Nie stosowac `restart all` ani wymuszonego zabijania procesow. Cron uruchamiany co minute jako nowy proces `ubuntu` pobierze aktualne grupy przy nastepnym wykonaniu.

### Kontrola po konfiguracji

Najpierw sprawdzic rozwiniecie calej sciezki, wlasciciela, grupe, prawa i ACL:

```bash
namei -l /var/www/nex-oms/storage/app/private/nex-printer/jobs
getfacl /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u www-data test -w /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u ubuntu test -w /var/www/nex-oms/storage/app/private/nex-printer/jobs
```

Pelna kontrola zapisu i wzajemnego odczytu, bez uruchamiania wydruku ani integracji:

```bash
cd /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u www-data sh -c 'umask 007; printf test > .permission-test-www-data'
sudo -u ubuntu test -r .permission-test-www-data
sudo -u ubuntu sh -c 'umask 007; printf test > .permission-test-ubuntu'
sudo -u www-data test -r .permission-test-ubuntu
sudo rm -f .permission-test-www-data .permission-test-ubuntu
```

Kazde polecenie `test` powinno zakonczyc sie kodem `0`. Te kontrole nie tworza zlecenia NEX Printer, nie pobieraja etykiety i nie wysylaja niczego do API przewoznika.

### Ponowne wdrozenie i odtworzenie

- Nie uruchamiac `composer`, Artisan ani budowania cache jako `root`, bo utworzy to pliki niedostepne dla procesow aplikacji. Uzywac `ubuntu` albo jawnie naprawic prawa przed przeladowaniem uslug.
- Jezeli wdrozenie podmienia katalog `storage`, `bootstrap/cache` lub ich dowiazania symboliczne, ponownie wykonac konfiguracje wlasciciela, grupy, trybow i ACL na rzeczywistym katalogu docelowym.
- Po odtworzeniu kopii zapasowej ponownie zastosowac `chown`, `chmod` i ACL. Archiwum moze zachowac numery UID/GID z innego serwera albo nie zachowac ACL.
- Kontrole `namei`, `getfacl` i zapisu obu uzytkownikow wykonac przed wznowieniem workerow i przed testowym zleceniem wydruku.
- Katalog `storage/app/private/nex-printer/jobs` nalezy tworzyc jawnie podczas wdrozenia. Laravel potrafi utworzyc brakujacy podkatalog, ale tylko wtedy, gdy proces ma prawo zapisu do wszystkich katalogow nadrzednych.
- Nie stosowac `chmod -R 777`, nie przenosic prywatnych PDF do `public/` i nie rozwiazywac problemu przez uruchamianie workerow jako `root` albo `www-data`.

## Cron Laravel

Na stagingu jeden plik `/etc/cron.d/nex-oms-scheduler` uruchamia istniejacy harmonogram `routes/console.php`:

```cron
* * * * * ubuntu cd /var/www/nex-oms && /usr/bin/php artisan schedule:run >> /var/www/nex-oms/storage/logs/scheduler.log 2>&1
```

W tym formacie `ubuntu` jest polem uzytkownika `/etc/cron.d`; nie kopiowac go do crontaba uzytkownika. Osobne stare crony importu Allegro i odswiezania InPost zostaly wylaczone wedlug operatora. Nie uruchamiac jednoczesnie dodatkowego crona lub `schedule:work`. `schedule:run` ma skutki biznesowe, nie sluzy jako zwykly test zdrowia.

## Kolejki i Supervisor

Supervisor nadzoruje siedem workerow: `default`, `integrations`, `shipments-actions`, `shipments-sync`, `automation`, `ksef` na polaczeniu `database` oraz `ksef-submit` na polaczeniu `ksef_submit`. Importy Allegro/PrestaShop sa juz zaimplementowane i korzystaja z `integrations`; nie jest to kolejka oczekujaca na przyszly etap.

Aktywne retry_after stagingu wynosi 300 s dla `database` oraz 240 s dla `ksef_submit`. Job automatycznej wysylki KSeF ma wlasny timeout 120 s, mimo workerowego 150 s. Automatyczna wysylka jest wedlug operatora wylaczona w OMS; uruchomiony worker jej nie wlacza.

Pelna tabela parametrow siedmiu workerow, 31 jobow, 19 zadan schedulera lokalnego kodu (18 w pierwotnym audycie VPS), diagnostyka bez sekretow i procedura lagodnego restartu po zatwierdzonym wdrozeniu znajduja sie w [dokumencie operacyjnym](007-Queues-Scheduler-VPS.md). Nowy callback recovery Automation wymaga osobnej [migracji i wdrozenia poprawki](008-Automation-Execution-Recovery.md); na VPS ich nie wykonano. Przed zatrzymaniem procesu trzeba uwzglednic stopwaitsecs, istniejace zlecenia oraz wyniki niepewne; nie ponawiac hurtowo failed jobs.
