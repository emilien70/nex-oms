# Wdrozenie NEX-OMS

## Wgrywanie na serwer testowy

Wgraj pliki projektu na serwer testowy z pominieciem katalogow, ktore nie powinny byc przenoszone recznie, np. lokalnych cache, logow i zaleznosci generowanych na serwerze.

Na serwerze uruchom:

```bash
composer install --no-dev --optimize-autoloader
```

## Katalog publiczny

Ustaw katalog publiczny domeny lub subdomeny na:

```text
/public
```

To katalog `public/` Laravela powinien byc wystawiony przez serwer WWW.

## Konfiguracja .env

Skopiuj `.env.example` do `.env` i ustaw:

- `APP_NAME=NEX-OMS`
- `APP_ENV=testing` albo odpowiednia nazwe srodowiska testowego.
- `APP_KEY`
- `APP_URL`
- dane polaczenia z MySQL/MariaDB.

Jezeli `APP_KEY` nie istnieje, wygeneruj go:

```bash
php artisan key:generate
```

## Migracje

Po skonfigurowaniu bazy uruchom:

```bash
php artisan migrate --force
```

## Cache aplikacji

Po zmianach konfiguracyjnych mozna odswiezyc cache:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Cron Laravel

Gdy aplikacja zacznie korzystac z harmonogramu, dodaj cron:

```cron
* * * * * cd /sciezka/do/nex-oms && php artisan schedule:run >> /dev/null 2>&1
```

## Kolejki

Operacje kurierskie wymagaja dwoch osobnych workerow. Pierwszy obsluguje nadawanie i anulowanie przesylek, a drugi synchronizacje statusow:

```bash
php artisan queue:work --queue=shipments-actions
php artisan queue:work --queue=shipments-sync
```

Import zamowien z Allegro, PrestaShop i Von Halsky wymaga osobnego workera `orders-import`. Kolejka `integrations` nadal obsluguje pozostale operacje integracji oraz wczesniej zakolejkowane zadania:

```bash
php artisan queue:work database --queue=orders-import --timeout=210
php artisan queue:work database --queue=integrations
```

Asynchroniczna wysylka e-mail wymaga stale dzialajacego workera kolejki `default`:

```bash
php artisan queue:work --queue=default
```

Odzyskiwanie osieroconych wiadomosci `queued` wymaga rowniez wykonywania co minute polecenia `php artisan schedule:run`, zgodnie z konfiguracja cron powyzej.

Na serwerze workery powinny byc uruchamiane stale przez Supervisor, systemd albo analogiczny mechanizm zarzadzania procesami. Instrukcja uruchomienia `orders-import` i wymagania dla `retry_after`: [wdrozenie VPS](docs/006-Deployment.md#pierwsze-uruchomienie-orders-import).
