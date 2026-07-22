# Deployment

## Serwer testowy

Projekt bedzie testowany na serwerze bez Dockera. Wdrozenie polega na wgraniu plikow aplikacji Laravel, konfiguracji `.env`, instalacji zaleznosci i uruchomieniu migracji.

## Katalog publiczny

Document root domeny lub subdomeny musi wskazywac na katalog:

```text
/public
```

Nie nalezy wystawiac katalogu glownego projektu jako katalogu publicznego.

## Podstawowe kroki

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

W przyszlosci, gdy pojawia sie zadania cykliczne i kolejki, nalezy dodac cron Laravela:

```cron
* * * * * cd /sciezka/do/nex-oms && php artisan schedule:run >> /dev/null 2>&1
```

## Kolejki przesylek

Nadawanie i anulowanie przesylek oraz synchronizacja statusow dzialaja na osobnych kolejkach, aby zadania okresowe nie opoznialy operacji uzytkownika:

```bash
php artisan queue:work --queue=shipments-actions
php artisan queue:work --queue=shipments-sync
```

Kolejka `integrations` pozostaje przeznaczona dla przyszlego importu zamowien z Allegro i PrestaShop:

```bash
php artisan queue:work --queue=integrations
```

Na serwerze workery powinny byc utrzymywane przez Supervisor, systemd albo analogiczny mechanizm zarzadzania procesami. Worker `integrations` moze pozostac bezczynny do czasu wdrozenia importu zamowien.
