# NEX-OMS

NEX-OMS to aplikacja OMS budowana jako modularny monolit w Laravelu. System ma docelowo wspierac obsluge zamowien, produktow, numerow seryjnych, wysylek, faktur, e-maili oraz integracji z Allegro, PrestaShop, InPost, Fakturownia i SMTP.

Na etapie v0.1.1 projekt zawiera fundament aplikacji, dokumentacje, strukture modulow oraz prosty dashboard. Nie ma jeszcze logowania, bazy Orders ani integracji zewnetrznych.

## Technologie

- Backend: Laravel.
- Frontend: Blade + Bootstrap 5.
- Baza danych: MySQL albo MariaDB.
- Srodowisko: lokalne uruchomienie i testowy serwer.
- Bez Dockera.
- Bez GitHuba jako elementu procesu.

## Wymagania lokalne

- PHP zgodny z wymaganiami aktualnej wersji Laravela.
- Composer.
- MySQL albo MariaDB.
- Node.js i npm.

## Uruchomienie

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Po uruchomieniu aplikacja powinna byc dostepna pod adresem:

```text
http://127.0.0.1:8000
```

## Konfiguracja bazy

W pliku `.env` ustaw polaczenie z MySQL/MariaDB:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nex_oms
DB_USERNAME=root
DB_PASSWORD=
```

## Planowane moduly

- Dashboard.
- Zamowienia.
- Produkty.
- Numery seryjne.
- Wysylki.
- Faktury.
- E-maile.
- Integracje.
- Ustawienia.

## Integracje planowane na pozniejsze etapy

- Allegro.
- PrestaShop.
- InPost.
- Fakturownia.
- SMTP.

Integracje nie sa implementowane na etapie fundamentu. Kazde zewnetrzne API powinno docelowo miec logowanie request/response, obsluge bledow i wykonywanie operacji przez kolejki.

## Dokumentacja

Dokumentacja projektowa znajduje sie w katalogu `docs/`.
