# ALLEGRO.1 - raport wdrozenia

Data: 2026-10-19.

## Wykonane zmiany

- Nowa integracja Allegro pod `/integrations/allegro`, dostepna z listy integracji.
- Nazwa wyswietlana, wybor produkcja/sandbox, konfiguracja aplikacji i laczenie przez OAuth Device Flow.
- Szyfrowanie Client Secret, access tokenu i refresh tokenu w bazie. Sekret nie trafia do zapamietanych danych blednego formularza.
- Odczyt loginu, ID konta, marketplace, waznosci tokenu, wyniku testu i bledu polaczenia.
- Weryfikacja uprawnien do konta, zamowien i przesylek; testy odczytu API bez zmiany danych sprzedawcy.
- Automatyczne odswiezanie tokenu przy korzystaniu z API oraz jedna ponowna proba po HTTP 401.
- Wspolne konto OAuth dla istniejacego Wysylam z Allegro, z zachowaniem jego konfiguracji i poprzednich danych dostepowych.
- Blad wspolnego konta blokuje uzycie poprzednich danych kuriera. Jawne rozlaczenie usuwa powiazanie i przywraca poprzednia konfiguracje kuriera.
- Oddzielny stan Device Flow dla integracji glownej i dotychczasowego polaczenia kurierskiego.
- Widok sprawdzony na ekranie komputerowym i mobilnym.

## Zmienione pliki

Utworzone:

- `C:/projekty/nex-oms/database/migrations/2026_10_19_030000_create_allegro_accounts_table.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Exceptions/AllegroApiException.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Services/AllegroOAuthService.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Services/AllegroClient.php`
- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `C:/projekty/nex-oms/resources/views/integrations/allegro/edit.blade.php`
- `C:/projekty/nex-oms/tests/Feature/AllegroSettingsTest.php`
- `C:/projekty/nex-oms/docs/allegro-1-report.md`

Zmienione:

- `C:/projekty/nex-oms/config/services.php`
- `C:/projekty/nex-oms/routes/web.php`
- `C:/projekty/nex-oms/bootstrap/app.php`
- `C:/projekty/nex-oms/Modules/Shipments/Models/CourierAccount.php`
- `C:/projekty/nex-oms/Modules/Integrations/AllegroShipping/Services/AllegroShippingClient.php`
- `C:/projekty/nex-oms/resources/views/integrations/index.blade.php`
- `C:/projekty/nex-oms/resources/views/integrations/couriers/allegro-shipping.blade.php`
- `C:/projekty/nex-oms/tests/Feature/IntegrationListTest.php`

Usuniete: brak. Pozostale zastane zmiany w katalogu projektu pozostawiono bez cofania.

## Migracja

`2026_10_19_030000_create_allegro_accounts_table` zostala uruchomiona (batch 86).
Tworzy `allegro_accounts` i opcjonalne powiazanie `courier_accounts.allegro_account_id` z `nullOnDelete`.
Po migracji zweryfikowano zachowanie czterech istniejacych kont kurierskich oraz ich kolumn.

Komenda do uruchomienia tej migracji w innym srodowisku:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_19_030000_create_allegro_accounts_table.php
```

## Testy

Konfiguracja PHPUnit: SQLite `:memory:`, srodowisko `testing`.

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroSettingsTest|AllegroShippingTest|IntegrationListTest|InPostShipmentTest|InPostCourierShipmentTest|DpdShipmentTest"
```

Wynik koncowy: **77 testow poprawnych, 532 asercje**.
Obejmuje szyfrowanie, uprawnienia, Device Flow, izolacje sesji, odswiezanie tokenu, wspolne konto kurierskie, rozlaczenie, usuniecie relacji oraz dotychczasowe integracje kurierskie.

```powershell
C:\xampp\php\php.exe artisan test --compact
```

Pelny zestaw uruchomiony przed ostatnimi poprawkami: **3186 poprawnych, 68 nieudanych, 32530 asercji**.
Bledy obejmuja m.in. brak zamowienia przy generowaniu linku NEX Printer z listy faktur, nieoczekiwane zadania automatyzacji w testach KSeF oraz utrate powiazania produktu podczas kopiowania zamowienia.
Osobno powtorzony test `test_duplicating_order_preserves_catalog_product_link_and_manual_items` takze nie przechodzi: `product_id` jest `null` zamiast ID produktu (`tests/Feature/Products/OrderCatalogProductTest.php:139`).
Te obszary nie byly zmieniane w ALLEGRO.1; nie przeprowadzono porownania pelnego zestawu z wersja sprzed wdrozenia.

Kontrola skladni nowych plikow PHP i formatowania zmienionych plikow PHP zakonczona poprawnie. Nie zmieniano plikow niezaleznych od etapu w celu poprawy formatowania.

## Weryfikacja reczna

1. Otworz `http://127.0.0.1:8000/integrations/allegro`.
2. Ustaw nazwe wyswietlana, np. `multi-click`, oraz wlasciwe srodowisko.
3. Zweryfikuj dane aplikacji Allegro. Istniejace Client ID i Client Secret z Wysylam z Allegro sa proponowane przy pierwszym utworzeniu konfiguracji.
4. Aplikacja musi obslugiwac Device Flow i dopuszczac zakresy widoczne w ustawieniach: profile:read, orders:read/write, shipments:read/write.
5. Kliknij `Polacz z Allegro`, otworz strone Allegro i potwierdz polaczenie na wlasciwym koncie sprzedawcy.
6. Po zatwierdzeniu ekran OMS powinien pokazac `Polaczono`, login, ID konta, marketplace i czas waznosci tokenu.
7. Kliknij `Sprawdz polaczenie` i sprawdz wynik.
8. Otworz Wysylam z Allegro i sprawdz wskazanie wspolnego konta oraz zachowanie ustawien przesylek.

## Ograniczenia i ryzyka

- Potwierdzenie OAuth na rzeczywistym koncie sprzedawcy i test rzeczywistego API pozostaja do wykonania przez wlasciciela. Testy automatyczne uzywaly odpowiedzi symulowanych.
- Import zamowien, wysylka faktur i numerow przewozowych nalezy do kolejnych etapow. ALLEGRO.1 przygotowuje wspolne polaczenie i uprawnienia.
- Pelny pakiet testow wymaga osobnej diagnostyki opisanych wyzej niepowodzen.
- Nie instalowano zaleznosci. Nie wykonywano commita ani pusha.

Dokumentacja OAuth: https://developer.allegro.pl/tutorials/uwierzytelnianie-i-autoryzacja-zlq9e75GdIR

## Poprawka bledu invalid_scope

Data: 2026-10-19.

Rzeczywiste proby laczenia zwracaly HTTP 400, `invalid_scope`, `OAuth 2.0 Parameter: scope`.
Nazwy zakresow byly poprawne. Test rzeczywistego API z tymi samymi zakresami przekazanymi w adresie zadania, z kodowaniem RFC 3986 i spacjami `%20`, zwrocil HTTP 200.
Metoda `AllegroOAuthService::start` przekazuje teraz `client_id` i `scope` w adresie zadania, bez powielania ich w formularzu POST.
Po poprawce wywolano bezposrednio metode serwisu OMS na rzeczywistym API i otrzymano prawidlowe dane Device Flow. Nie zatwierdzano dostepu do konta sprzedawcy i nie pobierano tokenow.

Zmienione pliki tej poprawki:

- `C:/projekty/nex-oms/Modules/Integrations/Allegro/Services/AllegroOAuthService.php`
- `C:/projekty/nex-oms/tests/Feature/AllegroSettingsTest.php`
- `C:/projekty/nex-oms/docs/allegro-1-report.md`

Utworzone i usuniete pliki: brak. Migracje: brak.

Test regresji najpierw odtworzyl identyczny blad, a po poprawce przeszedl.

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroSettingsTest|AllegroShippingTest|IntegrationListTest"
```

Wynik: **26 poprawnych testow, 177 asercji**. Formatowanie obu plikow PHP: poprawne.
Pelnego pakietu nie powtarzano dla tej poprawki; jego poprzedni wynik opisano wyzej.

Weryfikacja reczna: odswiez ustawienia Allegro, rozpocznij nowe laczenie przyciskiem `Polacz z Allegro` i zatwierdz dostep na stronie Allegro. Jesli pozostalo aktywne poprzednie laczenie, anuluj je przed rozpoczeciem nowego.
