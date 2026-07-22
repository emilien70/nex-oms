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

Kolejka `integrations` jest zachowana dla przyszlego importu zamowien z Allegro i PrestaShop:

```bash
php artisan queue:work --queue=integrations
```

Na serwerze workery powinny byc uruchamiane stale przez Supervisor, systemd albo analogiczny mechanizm zarzadzania procesami. Do czasu wdrozenia importu zamowien worker `integrations` pozostanie bezczynny.
