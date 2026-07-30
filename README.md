# NEX-OMS

NEX-OMS to lokalnie rozwijany system OMS do obsługi zamówień, przesyłek i dokumentów sprzedaży. Aplikacja powstaje jako modularny monolit oparty na Laravel 12 i PHP 8.2 lub nowszym.

Projekt jest aktywnie rozwijany i nie jest jeszcze gotowym produktem produkcyjnym. Aktualny kod należy uruchamiać w kontrolowanym środowisku lokalnym; przed publicznym wdrożeniem konieczne jest między innymi wdrożenie pełnego uwierzytelniania i autoryzacji.

## Aktualny zakres funkcjonalny

### Zamówienia

- lista zamówień z wyszukiwaniem, filtrowaniem, paginacją, oznaczeniami i operacjami zbiorczymi;
- karta szczegółów zamówienia z częściowymi aktualizacjami AJAX;
- ręczne tworzenie zamówień, edycja sekcji oraz kosz z przywracaniem i trwałym usuwaniem;
- konfigurowalne statusy zamówień wraz z kolorami, opisami i kolejnością;
- dane klienta przechowywane bezpośrednio w zamówieniu;
- informacje o zamówieniu, adres dostawy, dane do faktury, punkt odbioru i uwagi;
- kopiowanie danych pomiędzy adresem dostawy i danymi do faktury;
- pozycje zamówienia z ilością, ceną, VAT i wagą oraz przeliczaniem wartości;
- obsługa kwoty wpłaconej, kosztu dostawy, sposobu płatności i pobrania;
- historia zdarzeń zamówienia;
- wyszukiwanie po numerze zamówienia i numerze przesyłki, także z użyciem skanera kodów;
- pobieranie danych polskiej firmy z GUS/REGON po NIP do edytowanych danych fakturowych.

### Kraje adresów

Adres dostawy i dane do faktury mają niezależne pola kraju. W bazie przechowywany jest kod ISO 3166-1 alpha-2, na przykład `PL` albo `DE`, natomiast użytkownik widzi polską nazwę kraju.

Centralny `CountryCatalog` korzysta z Symfony Intl. Polska znajduje się na początku listy, a pozostałe kraje są sortowane według polskich nazw. Kopiowanie adresu kopiuje również kod kraju, a pobranie polskiej firmy z GUS jawnie ustawia `PL` dla danych do faktury. Historyczne zamówienie bez kraju pozostaje bez kraju i nie otrzymuje automatycznie Polski.

### Przesyłki i integracje

- wspólny model przesyłek, paczek składowych, zdarzeń i prób utworzenia;
- nadawanie, etykiety, śledzenie, synchronizacja statusów i anulowanie przesyłek;
- konfiguracja kont kurierskich i szablonów wymiarów;
- integracje kurierskie: InPost Paczkomaty, InPost Kurier, DPD i Wysyłam z Allegro;
- integracja GUS/REGON do pobierania danych firmy;
- logowanie komunikacji z zewnętrznymi API.

Wysyłam z Allegro obsługuje przesyłki. Import zamówień z Allegro i PrestaShop nie jest obecnie wdrożony.

### Automatyczne akcje

Moduł automatyzacji obsługuje reguły złożone ze zdarzeń, warunków i uporządkowanych kroków. Dostępne akcje obejmują między innymi zmianę statusu, utworzenie przesyłki, opóźnienie i wywołanie adresu URL metodą GET. Operacje przesyłek, synchronizacji i automatyzacji korzystają z kolejek.

### Faktury — Etapy 2A–2D

Moduł Faktur nie jest już pustym szkieletem. Aktualna implementacja obejmuje:

- serie numeracji dla Faktur VAT, Pro form i Korekt;
- trzy chronione serie systemowe oraz dodatkowe serie użytkownika;
- konfigurację danych sprzedawcy i ustawień dokumentu bezpośrednio w serii;
- centralne liczniki numeracji i okresy resetowania;
- walidację formatu numeru oraz tokenów `%N`, `%NN...`, `%M`, `%Y` i `%y`;
- podgląd i bezpieczne ustawienie następnego numeru wraz z historią korekt licznika;
- wspólny model dokumentów `invoice`, `proforma` i `correction` oraz ich pozycji;
- niezmienne snapshoty sprzedawcy, nabywcy, odbiorcy, zamówienia, płatności, wysyłki i pozycji;
- jedną istniejącą Fakturę VAT na zamówienie;
- jedną logiczną Pro formę na zamówienie, z kolejnymi rewizjami zachowującymi ten sam numer;
- trwałe zablokowanie dalszego odświeżania Pro formy po wystawieniu Faktury VAT;
- wystawianie Faktury VAT i tworzenie Pro formy z kafelka „Zarządzanie” na karcie zamówienia;
- operacje przez AJAX bez przeładowania strony, modalnego formularza ani komunikatu sukcesu;
- zwykły przycisk dla jednej aktywnej serii i dropdown wyboru przy wielu aktywnych seriach;
- prywatne PDF-y otwierane przez kontrolowaną trasę Laravel;
- generowanie PDF Faktury VAT i Pro formy wyłącznie z zapisanych snapshotów;
- renderer PDF Korekty dla kompletnego, istniejącego rekordu Korekty;
- kraj Nabywcy w formacie takim jak `32-545 Psary, Polska`;
- PDF bez stopki „Wygenerowano w...” i bez ujawniania ścieżki prywatnego storage.

Renderer PDF Korekty jest gotowy, ale wystawianie Korekt i ich interfejs nie są jeszcze wdrożone. Główna strona listy Faktur pozostaje ekranem początkowym; pełne listy i rejestry dokumentów również nie są jeszcze dostępne.

## Zasady dokumentów

- Faktura VAT jest snapshotem danych z momentu wystawienia. Późniejsza zmiana zamówienia lub serii nie zmienia dokumentu.
- Jedno zamówienie może mieć najwyżej jedną istniejącą Fakturę VAT.
- Pro forma zachowuje jeden numer, a zmiana danych może utworzyć kolejną rewizję tej samej logicznej Pro formy.
- Po wystawieniu Faktury VAT akcja i numer Pro formy są ukrywane w kafelku „Zarządzanie”, ale historyczne dane Pro formy pozostają zachowane.
- Faktura VAT może przechowywać w snapshocie powiązanie z wcześniejszą Pro formą.
- PDF nie odczytuje aktualnych danych z zamówienia ani serii.
- Kraj Nabywcy oraz sposób płatności pochodzą ze snapshotu dokumentu.
- PDF nie drukuje osobno nazwy banku ani numeru rachunku.

## Architektura i technologie

Projekt jest modularnym monolitem Laravel. Główna logika aplikacji znajduje się w `app`, a wydzielone obszary domenowe w `Modules`.

| Obszar | Technologia |
|---|---|
| Backend | PHP `^8.2`, Laravel `12.63.0` |
| Widoki | Blade, Bootstrap 5, Bootstrap Icons |
| Zasoby frontendowe | Vite 7, JavaScript, Axios |
| Lokalna baza | SQLite |
| Baza testowa | SQLite `:memory:` |
| PDF | TCPDF `6.11.3` |
| Katalog krajów | Symfony Intl `7.2.0` |
| Zależności | Composer, npm |

Aktywne moduły:

- `Modules/Automation` — reguły i wykonania automatycznych akcji;
- `Modules/Integrations` — klienci i sterowniki integracji;
- `Modules/Invoices` — serie, numeracja, dokumenty, snapshoty i PDF;
- `Modules/Shipments` — wspólna domena przesyłek.

Szczegółowe reguły projektu opisują:

- [`AGENTS.md`](AGENTS.md),
- [`docs/product-spec.md`](docs/product-spec.md),
- [`docs/architecture.md`](docs/architecture.md).

## Uruchomienie lokalne — Windows/XAMPP

1. Sklonuj repozytorium i przejdź do jego katalogu:

```powershell
git clone <adres-repozytorium>
cd nex-oms
```

2. Zainstaluj zależności PHP:

```powershell
composer install
```

3. Utwórz lokalną konfigurację i klucz aplikacji:

```powershell
Copy-Item .env.example .env
C:\xampp\php\php.exe artisan key:generate
```

4. Utwórz pusty plik SQLite, jeżeli jeszcze nie istnieje:

```powershell
New-Item database\database.sqlite -ItemType File -ErrorAction SilentlyContinue
```

Domyślne `.env.example` używa `DB_CONNECTION=sqlite`. Własną bazę MySQL lub MariaDB można skonfigurować przez odpowiednie zmienne `DB_*` w lokalnym `.env`.

5. Uruchom migracje na świadomie wybranej, lokalnej bazie:

```powershell
C:\xampp\php\php.exe artisan migrate
```

Nie używaj `migrate:fresh` ani innych komend resetujących bazę zawierającą dane.

6. Zainstaluj zależności frontendowe i zbuduj zasoby:

```powershell
npm install
npm.cmd run build
```

W trakcie pracy można zamiast buildu uruchomić:

```powershell
npm.cmd run dev
```

7. Uruchom aplikację:

```powershell
C:\xampp\php\php.exe artisan serve
```

Jeżeli PHP jest dostępne globalnie w `PATH`, prefiks `C:\xampp\php\php.exe` można zastąpić poleceniem `php`.

Do obsługi operacji asynchronicznych potrzebny jest worker kolejek, a do zadań cyklicznych scheduler. Dostępny jest również skrypt `composer run dev`, który uruchamia lokalny serwer, kolejkę, logi i Vite.

## Testy i jakość

```powershell
php artisan test
npm run build
composer validate
composer audit --locked
vendor\bin\pint
```

Odpowiedniki przydatne w Windows/XAMPP:

```powershell
C:\xampp\php\php.exe artisan test
npm.cmd run build
C:\xampp\php\php.exe vendor\bin\pint
```

Testy używają SQLite `:memory:` i synchronicznej kolejki. Nie należy uruchamiać ich na produkcyjnej bazie danych.

## Bezpieczeństwo i dane prywatne

- `.env`, bazy danych, kopie SQL, dane klientów, logi lokalne i wygenerowane dokumenty nie mogą trafiać do repozytorium.
- PDF-y dokumentów są zapisywane na prywatnym dysku `local` i zwracane przez kontroler z prywatnymi nagłówkami bez cache.
- Trasy nie ujawniają fizycznej ścieżki pliku w `storage`.
- Projekt nie ma jeszcze kompletnej produkcyjnej warstwy uwierzytelniania i autoryzacji użytkowników.
- Przed publicznym wdrożeniem panel i trasy dokumentów muszą zostać objęte pełną kontrolą dostępu.
- Sekrety integracji i klucze API należy przechowywać wyłącznie w zmiennych środowiskowych.

## Funkcje jeszcze niewdrożone

- wystawianie Korekt i ich interfejs użytkownika;
- pełne listy Faktur, Pro form i Korekt oraz rejestr sprzedaży;
- ręczna edycja i usuwanie wystawionych dokumentów;
- generowanie duplikatów;
- wysyłka dokumentów e-mailem;
- załączniki i zewnętrzne pliki PDF;
- automatyczne wystawianie dokumentów;
- eksport JPK i integracja KSeF;
- integracja z Fakturownią;
- import zamówień z Allegro i PrestaShop;
- produkcyjne uwierzytelnianie i autoryzacja użytkowników;
- samodzielny katalog produktów niezależny od pozycji zamówień.

Paragony, e-paragony i drukarki fiskalne pozostają poza aktualnym zakresem projektu.

## Licencja

Licencja projektu nie została jeszcze określona.
