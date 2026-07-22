# NEX-OMS

NEX-OMS to rozwijany system OMS do zarządzania zamówieniami, produktami zamówień, danymi klientów, płatnościami, wysyłkami, dokumentami sprzedaży i przyszłymi integracjami z kanałami sprzedaży.

Nie wszystkie wymienione obszary są ukończone. Poniższy dokument rozdziela funkcje działające, będące w budowie i planowane.

## Status projektu

Projekt jest aktywnie rozwijany i nie jest jeszcze gotową wersją produkcyjną. Nie ma obecnie logowania ani kontroli dostępu, dlatego nie powinien być publicznie udostępniany bez dodatkowych zabezpieczeń.

- Głównym środowiskiem rozwoju jest środowisko lokalne.
- Kolejne sprawdzone i stabilne wersje będą mogły być wdrażane na VPS.
- Git przechowuje historię zmian kodu.
- Projekt nie wymaga Dockera ani n8n.

Projekt nie ma obecnie jednoznacznie zdefiniowanego numeru wydania w kodzie, dlatego README nie przypisuje mu sztucznej wersji.

## Aktualne funkcje

Na podstawie aktualnych tras, kontrolerów, modeli, widoków, migracji i testów działają:

- dashboard z licznikami podstawowych statusów zamówień;
- lista zamówień z filtrowaniem, wyszukiwaniem, paginacją, oznaczeniami kolorową gwiazdką i operacjami zbiorczymi;
- wyszukiwanie zamówień między innymi po numerze zamówienia i numerze przesyłki oraz obsługa skanera kodów;
- szczegóły zamówienia z częściowym odświeżaniem danych przez żądania AJAX;
- tworzenie pustego zamówienia ręcznego, klasyczny formularz tworzenia oraz edycja istniejącego zamówienia;
- kosz zamówień, przywracanie i trwałe usuwanie zaznaczonych zamówień;
- dane kontaktowe kupującego, adres dostawy, dane do faktury, punkt odbioru i uwagi zapisane bezpośrednio w zamówieniu;
- sekcyjna edycja informacji o zamówieniu, adresów, punktu odbioru i kwoty wpłaty;
- pobieranie danych firmy z GUS/REGON po NIP do roboczych danych fakturowych;
- produkty zamówienia: dodawanie, edycja, usuwanie, ilość, cena, VAT, waga i przeliczanie wartości zamówienia;
- rejestrowanie historii zdarzeń zamówienia;
- płatności: kwota wpłacona, koszt dostawy, metoda płatności, pobranie i wizualna informacja o stanie wpłaty;
- zarządzanie statusami zamówień, ich kolorami, opisami i kolejnością;
- tworzenie, synchronizacja, etykiety, śledzenie i anulowanie przesyłek dla skonfigurowanych integracji kurierskich;
- integracje kurierskie: InPost Paczkomaty, InPost Kurier, DPD oraz Wysyłam z Allegro;
- konfiguracja kont kurierskich i szablonów wymiarów przesyłek tam, gdzie obsługuje je dana integracja;
- automatyczne akcje oparte na zdarzeniach, warunkach i uporządkowanych krokach;
- akcje automatyczne: zmiana statusu, utworzenie przesyłki, opóźnienie i wywołanie adresu URL metodą GET;
- zmienne zamówienia możliwe do wykorzystania między innymi w akcjach URL;
- kolejki dla operacji przesyłek, synchronizacji i automatyzacji;
- logowanie komunikacji z zewnętrznymi API oraz okresowe czyszczenie logów integracyjnych.

### Ograniczenia aktualnego stanu

- Nie istnieje osobna baza klientów. Dane kupującego należą wyłącznie do konkretnego zamówienia.
- Nie istnieje osobna tabela adresów w docelowym schemacie. Dane dostawy i faktury znajdują się w `orders`.
- Moduł faktur ma tylko wejście w menu, trasę, kontroler, ekran początkowy i test dostępności. Nie wystawia jeszcze dokumentów.
- Import zamówień z Allegro i PrestaShop nie jest jeszcze zaimplementowany.
- Katalog produktów niezależny od zamówień nie jest jeszcze zaimplementowany.

## Statusy zamówień

Kod definiuje cztery statusy bazowe:

| Kod | Nazwa |
|---|---|
| `new` | Nowe |
| `pending` | Oczekujące |
| `shipped` | Wysłane |
| `cancelled` | Anulowane |

Panel ustawień pozwala również dodawać własne statusy, ustalać ich kolor i opis, zmieniać kolejność oraz usuwać status po wskazaniu statusu zastępczego dla istniejących zamówień.

## Moduły

| Moduł | Stan | Opis |
|---|---|---|
| Dashboard | Działa | Liczniki zamówień według podstawowych statusów. |
| Orders | Działa | Lista, wyszukiwanie, filtry, kosz, karta zamówienia, dane kupującego i adresowe, płatności oraz historia. |
| Produkty zamówienia | Działa | Pozycje należące do zamówienia, ich ceny, ilości, VAT i waga. Nie jest to jeszcze samodzielny katalog produktów. |
| Statusy zamówień | Działa | Konfigurowalne nazwy, opisy, kolory i kolejność statusów. |
| Shipments | Działa | Wspólny model przesyłek, paczki składowe, etykiety, statusy OMS, synchronizacja i zdarzenia. |
| Integrations | Działa | Sterowniki InPost Paczkomaty, InPost Kurier, DPD i Wysyłam z Allegro oraz logowanie API. |
| Automation | Działa | Reguły zdarzeń, warunki, akcje, wykonania i podgląd aktywności. |
| GUS/REGON | Działa | Pobranie danych firmy po NIP przez backendowy klient HTTP/XML; wymaga klucza API. |
| Invoices | W budowie | Utworzone wejście do modułu i ekran początkowy; pełna obsługa dokumentów jest w przygotowaniu. |
| Katalog Products | Planowany | Katalog produktów niezależny od pozycji konkretnego zamówienia. |
| Emails | Planowany | Moduł wiadomości i szablonów nie ma jeszcze implementacji. |
| Zwroty | Planowany | Pozycja jest widoczna jako element przyszłej nawigacji, bez obsługi domenowej. |

Aktywną implementację wewnątrz `Modules/` zawierają obecnie `Automation`, `Integrations`, `Invoices` i `Shipments`. Katalogi `Customers`, `Dashboard`, `Emails`, `Orders` i `Products` są pustymi punktami organizacyjnymi; sama obecność katalogu nie oznacza gotowego modułu.

## Model danych w skrócie

Najważniejsze aktywne obszary bazy danych to:

- `orders` — zamówienie wraz z danymi kupującego, dostawy, faktury, płatności i punktu odbioru;
- `order_items` — produkty przypisane do zamówienia;
- `order_events` — historia operacji na zamówieniu;
- `order_status_settings` — konfiguracja statusów;
- `courier_accounts`, `shipments`, `shipment_parcels`, `shipment_events` i `shipment_creation_attempts` — konfiguracja i obsługa przesyłek;
- `integration_api_logs` — logi komunikacji z integracjami;
- `automation_rules`, `automation_actions`, `automation_runs` i `automation_run_steps` — reguły i przebiegi automatyzacji;
- tabele systemowe Laravel dla kolejek, cache, sesji i użytkowników.

Migracje tworzące dawne tabele `customers` i `addresses` pozostają w historii, ale późniejsze migracje przenoszą dane do `orders` i usuwają te tabele.

## Moduł dokumentów sprzedaży

### Stan obecny

Moduł faktur jest szkieletem. Dostępne są pozycja `Faktury` w menu zamówień, trasa `/invoices`, kontroler, ekran informacyjny oraz test dostępności strony. Nie istnieją jeszcze modele, tabele, numeracja, generowanie PDF ani zapis wystawionych dokumentów.

### Założenia planowanej implementacji

- System będzie obsługiwał jedną firmę sprzedającą.
- Nie będzie tabeli ani funkcji wielu profili sprzedawców.
- Dane jednej firmy będą przechowywane w konfiguracji firmy.
- Wystawiona faktura będzie przechowywała snapshot danych sprzedawcy i nabywcy, aby późniejsze zmiany zamówienia nie zmieniały historycznego dokumentu.
- Faktura będzie tworzona z danych zamówienia.
- Użytkownik będzie wystawiał dokument przyciskiem z karty zamówienia.

Planowane typy dokumentów:

- faktura VAT;
- faktura pro forma;
- korekta;
- duplikat.

Planowane funkcje:

- serie numeracji;
- generowanie i pobieranie PDF;
- rejestr sprzedaży;
- dokument zewnętrzny;
- wysyłka e-mail;
- historia zmian;
- przyszła integracja z Fakturownią.

Poza obecnym zakresem pozostają: KSeF, paragony, e-paragony, drukarki fiskalne i JPK.

## Planowane integracje

### Istniejące

| Integracja | Zakres |
|---|---|
| GUS/REGON | Wyszukiwanie danych firmy po NIP. |
| InPost Paczkomaty | Konfiguracja konta, nadawanie, etykiety, anulowanie, śledzenie i synchronizacja statusów. |
| InPost Kurier | Konfiguracja konta i szablonów, nadawanie paczek, etykiety, anulowanie, śledzenie i synchronizacja. |
| DPD | Konfiguracja konta i szablonów, nadawanie, etykiety, anulowanie, śledzenie i synchronizacja. |
| Wysyłam z Allegro | Połączenie OAuth Device Flow, propozycje przewoźnika, nadawanie, etykiety, anulowanie, śledzenie i synchronizacja. |

Integracje kurierskie wymagają poprawnej konfiguracji konta i danych dostępowych. Wysyłam z Allegro dotyczy obsługi przesyłek i nie oznacza importowania zamówień z Allegro.

### Rozpoczęte

W aktualnym kodzie nie ma odrębnej integracji pozostającej wyłącznie na etapie pustego konfiguratora lub placeholdera. Moduł faktur jest w budowie, ale nie stanowi jeszcze integracji z zewnętrznym systemem.

### Planowane

- import i synchronizacja zamówień z Allegro;
- import i synchronizacja zamówień z PrestaShop;
- Fakturownia jako integracja dokumentów sprzedaży;
- obsługa wysyłki wiadomości przez skonfigurowany transport pocztowy w przyszłym module e-mail.

## Technologie

| Obszar | Technologia wykryta w projekcie |
|---|---|
| Backend | PHP `^8.2`, Laravel `^12.0` (wersja w `composer.lock`: `12.63.0`) |
| Widoki | Blade |
| Interfejs panelu | Bootstrap `5.3.3` i Bootstrap Icons `1.11.3` przez CDN oraz lokalny CSS/JavaScript |
| Frontend build | Vite `^7.0.7`, Tailwind CSS `^4.0.0`, Axios `^1.11.0` |
| Zależności PHP | Composer |
| Zależności frontendowe | Node.js i npm; projekt nie przypina ich wersji w `package.json` |
| Baza lokalna testów | SQLite w pamięci |
| Baza aplikacji | `.env.example` domyślnie wskazuje SQLite; konfiguracja zawiera również MySQL i MariaDB, zalecane dla XAMPP i przyszłego VPS |
| Kolejki | Sterownik bazodanowy Laravel |

## Wymagania lokalne

- PHP `8.2` lub nowsze zgodne z ograniczeniem `^8.2` w `composer.json`;
- Composer;
- Node.js i npm;
- MySQL/MariaDB dla instalacji XAMPP albo SQLite dla prostego środowiska lokalnego;
- rozszerzenia PHP wymagane przez Laravel: `ctype`, `filter`, `hash`, `mbstring`, `openssl`, `session` i `tokenizer`;
- `PDO` oraz `pdo_mysql` przy korzystaniu z MySQL/MariaDB;
- rozszerzenia XML udostępniające `SimpleXML` i `DOMDocument`, używane przez GUS/REGON i DPD.

Aktualna integracja GUS buduje komunikaty SOAP samodzielnie i wysyła je klientem HTTP Laravel. Rozszerzenie PHP SOAP nie jest przez nią używane.

## Instalacja lokalna — Windows/XAMPP

Przykładowy katalog projektu:

```text
C:\projekty\nex-oms
```

1. Przejdź do katalogu projektu:

```powershell
cd C:\projekty\nex-oms
```

2. Zainstaluj zależności PHP:

```powershell
composer install
```

3. Utwórz lokalny plik środowiska:

```powershell
Copy-Item .env.example .env
```

4. Wygeneruj klucz aplikacji:

```powershell
php artisan key:generate
```

Jeśli PHP nie jest dostępne globalnie w `PATH`, użyj:

```powershell
C:\xampp\php\php.exe artisan key:generate
```

5. Utwórz pustą bazę, na przykład `nex_oms`, w MySQL/MariaDB przez phpMyAdmin i ustaw połączenie w `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nex_oms
DB_USERNAME=root
DB_PASSWORD=
```

Przy świadomym użyciu sterownika MariaDB można ustawić `DB_CONNECTION=mariadb`. Szablon `.env.example` domyślnie korzysta z SQLite, więc przed migracją trzeba wybrać właściwe połączenie.

6. Uruchom migracje:

```powershell
php artisan migrate
```

Wariant XAMPP:

```powershell
C:\xampp\php\php.exe artisan migrate
```

Nie używaj `migrate:fresh` na bazie zawierającej dane.

7. Zainstaluj zależności frontendowe:

```powershell
npm install
```

8. Zbuduj zasoby albo uruchom serwer Vite na czas pracy:

```powershell
npm run build
```

lub:

```powershell
npm run dev
```

9. Uruchom aplikację:

```powershell
php artisan serve
```

Wariant XAMPP:

```powershell
C:\xampp\php\php.exe artisan serve
```

Domyślny adres serwera deweloperskiego Laravel to `http://127.0.0.1:8000`.

## Konfiguracja `.env`

Najważniejsze grupy zmiennych:

- `APP_*` — nazwa, środowisko, klucz, tryb debugowania, adres i strefa czasowa aplikacji;
- `DB_*` — sterownik i dane dostępowe do bazy;
- `MAIL_*` — transport pocztowy, host, port, użytkownik, hasło i dane nadawcy;
- `QUEUE_CONNECTION`, `CACHE_STORE` i `SESSION_DRIVER` — kolejki, cache i sesje;
- `GUS_API_KEY`, `GUS_API_URL` — dostęp do GUS/REGON;
- `INPOST_*` — ShipX, organizacja, środowiska API, timeout, limit zapytań, synchronizacja i retencja logów;
- `DPD_*` — dane konta, adresy usług, timeout, limit zapytań i synchronizacja;
- `ALLEGRO_*` — OAuth, API produkcyjne/sandbox, timeout, limit zapytań i synchronizacja przesyłek;
- `AUTOMATION_URL_*` — timeouty akcji automatycznej wywołującej URL;
- `AWS_*` — opcjonalne ustawienia usług AWS obecne w bazowej konfiguracji Laravel;
- `VITE_APP_NAME` — nazwa dostępna dla zasobów frontendowych.

Zasady bezpieczeństwa konfiguracji:

- `.env` nie może trafiać do Git;
- tokenów, kluczy API i haseł nie wolno wpisywać do README ani commitów;
- `.env.example` powinien zawierać wyłącznie puste lub przykładowe wartości.

## Uruchamianie projektu

Podstawowe komendy dostępne w projekcie:

```powershell
php artisan serve
npm run dev
npm run build
```

Skrypt Composer uruchamia równolegle serwer Laravel, worker kolejek, Pail i Vite:

```powershell
composer run dev
```

Wymaga on wcześniej zainstalowanych zależności oraz skonfigurowanej bazy. Projekt ma też skrypt `composer run setup`, który instaluje zależności, tworzy `.env`, generuje klucz, uruchamia migracje i buduje frontend. Przy istniejącej instalacji bezpieczniej wykonać kroki ręcznie i świadomie sprawdzić konfigurację bazy.

Operacje kurierskie i automatyczne akcje wymagają działającego workera kolejek, na przykład:

```powershell
php artisan queue:work --queue=shipments-actions,shipments-sync,integrations,automation,default --tries=1
```

Zadania cyklicznej synchronizacji można lokalnie obsłużyć przez:

```powershell
php artisan schedule:work
```

## Testy

Pełny zestaw testów PHP:

```powershell
php artisan test
```

Wariant XAMPP:

```powershell
C:\xampp\php\php.exe artisan test
```

Dostępny jest również skrypt Composer:

```powershell
composer test
```

Testy obejmują między innymi zamówienia, wyszukiwanie, kosz, statusy, aktualizacje AJAX, integracje kurierskie, automatyzacje, zmienne i dostępność strony faktur. `phpunit.xml` wymusza dla testów SQLite w pamięci oraz synchroniczne kolejki. Testów nie należy konfigurować ani uruchamiać na produkcyjnej bazie danych.

Projekt nie definiuje obecnie skryptów `npm test` ani `npm run lint`.

## Struktura projektu

- `app/` — modele zamówień, kontrolery HTTP, serwisy wspólne, zdarzenia i klasy pomocnicze;
- `Modules/Automation/` — reguły, akcje, wykonania, kolejki i panel automatyzacji;
- `Modules/Integrations/` — klienci i sterowniki InPost, DPD oraz Wysyłam z Allegro;
- `Modules/Invoices/` — obecnie wyłącznie kontroler startowego ekranu faktur;
- `Modules/Shipments/` — wspólny model domenowy przesyłek i usługi kurierskie;
- `database/migrations/` — pełna historia zmian schematu bazy;
- `resources/views/` — widoki Blade panelu, zamówień, przesyłek, integracji, ustawień i faktur;
- `resources/css/` i `resources/js/` — zasoby budowane przez Vite;
- `routes/` — trasy webowe i harmonogram zadań konsolowych;
- `tests/Unit/` — testy jednostkowe;
- `tests/Feature/` — testy funkcjonalne aplikacji i integracji;
- `docs/` — dokumentacja architektury, bazy, modułów, integracji, rozwoju i wdrożenia.

## Praca z Git

Typowy schemat pracy:

1. Zmień kod lokalnie.
2. Sprawdź działanie aplikacji.
3. Uruchom testy.
4. Sprawdź zmienione pliki.
5. Dodaj świadomie wybrane pliki do commitu.
6. Utwórz commit.
7. Wyślij commit do zdalnego repozytorium.

```powershell
git status
git add README.md
git commit -m "docs: update project README"
git push origin main
```

Git przechowuje historię kodu, ale nie zastępuje kopii bazy danych ani katalogu `storage`. Pliki `.env`, eksporty SQL, dane klientów, etykiety i inne dane operacyjne nie mogą trafiać do repozytorium.

## Wdrożenie na VPS

- Kod jest rozwijany i sprawdzany lokalnie, a na VPS trafiają tylko zweryfikowane wersje.
- Katalog główny serwera WWW musi wskazywać na katalog `public` projektu.
- Produkcja ma własny plik `.env` z `APP_ENV=production`, `APP_DEBUG=false` i osobnymi sekretami.
- Produkcyjnej bazy nie wolno nadpisywać bazą lokalną.
- Zmiany struktury bazy wykonuje się migracjami po wcześniejszym backupie.
- Należy wykonywać kopie bazy danych oraz potrzebnych plików z `storage`.
- Po wdrożeniu należy wyczyścić stare cache i odbudować cache produkcyjny, na przykład przez `php artisan optimize:clear` i `php artisan optimize`.
- Scheduler wymaga wpisu cron wywołującego co minutę `php artisan schedule:run`.
- Workery kolejek powinny działać jako nadzorowane procesy, na przykład pod kontrolą Supervisor.
- Aktualizacja kodu nie może ujawniać ani zastępować produkcyjnego `.env`.

Docker nie jest wymagany przez projekt.

## Dokumentacja

- [`docs/000-Roadmap.md`](docs/000-Roadmap.md) — etapy rozwoju i planowane obszary.
- [`docs/001-Architecture.md`](docs/001-Architecture.md) — architektura modularnego monolitu i podział odpowiedzialności.
- [`docs/002-Database.md`](docs/002-Database.md) — opis modelu danych i relacji.
- [`docs/003-Modules.md`](docs/003-Modules.md) — zakres modułów aplikacji.
- [`docs/004-Integrations.md`](docs/004-Integrations.md) — zasady oraz aktualny zakres integracji.
- [`docs/005-Development.md`](docs/005-Development.md) — informacje dla lokalnego rozwoju.
- [`docs/006-Deployment.md`](docs/006-Deployment.md) — podstawowe założenia wdrożenia na serwer.

README opisuje stan potwierdzony w aktualnym kodzie. Pliki roadmapy mogą dodatkowo opisywać funkcje przyszłe.

## Bezpieczeństwo

- Nie publikuj pliku `.env`.
- Nie commituj eksportów SQL ani kopii bazy.
- Nie umieszczaj danych klientów, tokenów, etykiet i logów API w repozytorium.
- Regularnie wykonuj kopie bazy danych i potrzebnych plików `storage`.
- Na produkcji ustaw `APP_DEBUG=false`.
- Klucze API przechowuj wyłącznie w zmiennych środowiskowych lub bezpiecznej konfiguracji serwera.
- Przed publicznym wdrożeniem dodaj uwierzytelnianie, autoryzację i zabezpieczenia dostępu do panelu.

## Licencja

Licencja projektu nie została jeszcze określona.
