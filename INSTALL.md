# Instalacja NEX-OMS

## PHP

Zainstaluj PHP w wersji zgodnej z wymaganiami uzywanej wersji Laravela. Wlacz typowe rozszerzenia wymagane przez Laravel, m.in. `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `curl` i `fileinfo`.

## Composer

Zainstaluj Composer i sprawdz dostepnosc polecenia:

```bash
composer --version
```

Nastepnie w katalogu projektu uruchom:

```bash
composer install
```

## MySQL/MariaDB

Zainstaluj MySQL albo MariaDB, utworz baze danych i uzytkownika dla projektu.

Przykladowe dane do `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nex_oms
DB_USERNAME=root
DB_PASSWORD=
```

## Node.js

Zainstaluj Node.js i npm. Na tym etapie dashboard uzywa Bootstrap 5 przez CDN, ale zaleznosci Node.js pozostaja czescia standardowego projektu Laravel.

```bash
npm install
```

## Konfiguracja .env

Skopiuj przykladowy plik konfiguracyjny:

```bash
cp .env.example .env
```

Wygeneruj klucz aplikacji:

```bash
php artisan key:generate
```

Ustaw polaczenie z baza danych w `.env`.

## Uruchomienie projektu

Wykonaj migracje:

```bash
php artisan migrate
```

Uruchom lokalny serwer:

```bash
php artisan serve
```

Aplikacja bedzie dostepna pod adresem:

```text
http://127.0.0.1:8000
```
