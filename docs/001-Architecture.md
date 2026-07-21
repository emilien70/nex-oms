# Architektura NEX-OMS

## Styl architektury

NEX-OMS jest modularnym monolitem opartym o Laravel. Aplikacja pozostaje jednym projektem i jednym wdrozeniem, ale wieksze obszary systemu sa wydzielane do katalogu `Modules/`.

## Backend

- Laravel jako glowny framework aplikacji.
- Kontrolery odpowiadaja za obsluge HTTP i przekazywanie danych do widokow.
- Logika biznesowa powinna znajdowac sie w serwisach domenowych.
- Operacje zewnetrzne docelowo powinny dzialac przez kolejki.

## Frontend

- Blade jako warstwa widokow.
- Bootstrap 5 przez CDN na poczatkowym etapie.
- Bez komplikowania konfiguracji Vite do czasu, az bedzie realnie potrzebna.

## Baza danych

- MySQL lub MariaDB.
- Migracje Laravela jako podstawowy sposob zarzadzania schematem.
- Brak modelu Orders na etapie fundamentu.

## Integracje

Integracje z Allegro, PrestaShop, InPost, Fakturownia i SMTP beda rozwijane w osobnych modulach. Kazde zewnetrzne API powinno miec logowanie request/response oraz obsluge bledow.
