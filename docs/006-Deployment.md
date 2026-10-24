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

Katalogi `storage/` i `bootstrap/cache/` musza byc zapisywalne przez uzytkownika procesu PHP.

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
