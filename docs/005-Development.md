# Development

## Srodowisko lokalne

Projekt jest rozwijany lokalnie bez Dockera i bez GitHuba.

Wymagane narzedzia:

- PHP zgodny z wymaganiami aktualnej wersji Laravela.
- Composer.
- MySQL albo MariaDB.
- Node.js i npm.

## Uruchomienie lokalne

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Panel powinien byc dostepny pod adresem:

```text
http://127.0.0.1:8000
```

## Frontend

Na etapie fundamentu Bootstrap 5 jest podlaczony przez CDN w layoucie Blade. Vite zostaje w projekcie Laravel, ale nie jest wymagany do wyswietlenia podstawowego panelu.

## Zasady pracy

- Nie implementowac integracji, dopoki rdzen OMS nie bedzie gotowy.
- Nie dodawac Dockera.
- Nie usuwac domyslnych plikow Laravel bez potrzeby.
- Zmiany powinny byc male, czytelne i zgodne ze struktura modularnego monolitu.
