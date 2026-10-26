# Zestawy - waga i wymiary opakowania

Data: 2026-10-26. Zakres lokalny, bez operacji na VPS, commita i push.

## Zachowanie

- Tworzenie i edycja zestawu wspoldziela sekcje Opakowanie zwyklego produktu.
- Opcjonalna waga jest zapisywana w kg (3 miejsca po przecinku, minimum 0).
- Opcjonalne wymiary sa zapisywane w cm (2 miejsca po przecinku). Musza byc wszystkie puste albo wszystkie dodatnie.
- Parametry dotycza jednej sztuki kompletnego zestawu. Nie sumujemy wag ani wymiarow skladnikow.
- Reczne dodanie zestawu z katalogu korzysta z istniejacego kopiowania wagi do `order_items.weight`.
- Nowa pozycja importowana z PrestaShop lub Allegro, powiazana z zestawem OMS, otrzymuje jego wage. Przy pustej wadze OMS zachowujemy wage zrodla, jezeli jest dostepna. Jawne zero nie uruchamia fallbacku.
- Ponowne rozwiazanie podgladu Allegro zachowuje oryginalna wage zrodla. Zmiana docelowej wagi po podgladzie wymaga nowego potwierdzenia importu.
- Zmiana katalogu i ponowny import kompletnego zamowienia nie nadpisuja jego wagi. Pozniejsze uzupelnianie powiazan starych pozycji PrestaShop rowniez nie zmienia wagi.
- Istniejaca obsluga uzupelniania niepelnych zakupow Allegro pozostaje bez zmian; tworzone w jej ramach pozycje korzystaja z aktualnego mapowania.
- Wymiary pozostaja danymi katalogowymi. Nie zmieniono wyliczania paczek, domyslnych ustawien kuriera, istniejacych przesylek, VAT, cen ani stanow magazynowych.

## Pliki tego etapu

Zmodyfikowane:

- `Modules/Products/Http/Requests/SaveProductBundleRequest.php`
- `Modules/Products/Services/ProductBundleManagementService.php`
- `resources/views/products/form.blade.php`
- `Modules/Integrations/Prestashop/Services/PrestashopOrderImportService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderCatalogService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderImportService.php`
- `tests/Feature/Products/ProductBundleTest.php`
- `tests/Feature/PrestashopOrderImportTest.php`
- `tests/Feature/AllegroOrderCatalogTest.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Utworzony: `docs/bundle-packaging-report.md`. Brak usunietych plikow.

Istniejace przed tym etapem zmiany dotyczace platnosci PrestaShop pozostawiono. Wspoldzielone pliki importu, testow i dokumentacji zawieraja takze tamte niezacommitowane zmiany; powyzsza lista nie jest lista wszystkich roznic wzgledem HEAD.

## Migracje i dane

Brak nowej migracji. Wykorzystano istniejace kolumny `products.package_*` i `order_items.weight`.
Nie uruchamiano migracji, backfillu ani recznej synchronizacji zamowien. Nie zmieniano danych istniejacych zamowien.
Formularz w przegladarce otwarto odczytowo, bez zapisywania produktu.

## Testy

Konfiguracja sprawdzona przed uruchomieniem: `APP_ENV=testing`, SQLite `:memory:`, kolejka `sync`, poczta `array`.
API integracji w testach jest symulowane. Nie wykonywano transmisji biznesowych ani operacji na VPS.

Testy ukierunkowane po finalnym formatowaniu:

```powershell
& C:\xampp\php\php.exe artisan test --compact tests/Feature/Products/ProductBundleTest.php tests/Feature/PrestashopOrderImportTest.php tests/Feature/AllegroOrderCatalogTest.php
```

Wynik: **102 passed, 634 assertions**, 8.25 s.

Pokrycie obejmuje zapis, edycje i czyszczenie parametrow, przecinek dziesietny, bledne dane, niekompletne wymiary, wage jednostkowa przy wielu zestawach, brak sumowania skladnikow, fallback, zero, ochrone historycznej wagi i zmiane podgladu Allegro.

Szerszy zakres produktow i importu zamowien:

```powershell
$paths = @(Get-ChildItem tests/Feature/Products/*Test.php | Select-Object -ExpandProperty FullName)
$paths += @(Get-ChildItem tests/Feature/Prestashop*.php | Select-Object -ExpandProperty FullName)
$paths += @(Get-ChildItem tests/Feature/AllegroOrder*.php | Select-Object -ExpandProperty FullName)
& C:\xampp\php\php.exe artisan test --compact @paths
```

Wynik: **329 passed, 1 failed, 2064 assertions**, 25.29 s.
Niepowodzenie: `Tests\Feature\Products\OrderCatalogProductTest::test_duplicating_order_preserves_catalog_product_link_and_manual_items`, linia 139: oczekiwano `product_id=1` dla pierwszej skopiowanej pozycji, otrzymano `null`.
Test zaklada kolejnosc pozycji, ktorej nie gwarantuje relacja bez `orderBy`; istniejacy indeks `(order_id, product_id)` moze zwracac najpierw pozycje reczna. Kontroler duplikowania, modele zamowienia/pozycji i ten test sa niezmienione wzgledem HEAD. Nie poprawiano ich w tym etapie.
Nie wykonano odizolowanego baseline HEAD ani pelnego zestawu projektu, dlatego nie deklarujemy potwierdzonego braku regresji calej aplikacji.

Kontrole statyczne:

- `vendor/bin/pint --test` dla osmiu zmienianych plikow PHP: PASS.
- `php -l` dla tych osmiu plikow i widoku Blade: 9/9 PASS.
- `git diff --check`: PASS; Git ostrzega jedynie o przyszlej normalizacji CRLF w `docs/product-spec.md`.

Kontrola przegladarki: lokalny formularz tworzenia zestawu, Edge/Playwright, 1440x1000 i 390x844. Cztery pola widoczne, poprawnie zaladowane style, brak poziomego przepelnienia strony. Zrzuty: `storage/app/private/qa/bundle-packaging/desktop.png` i `mobile.png`.

## Weryfikacja reczna

1. Otworz Produkty -> Zestawy i edytuj zestaw. W sekcji Opakowanie wpisz np. 1,234 kg oraz 30 x 20 x 10 cm. Zapisz i otworz ponownie.
2. Dodaj ten zestaw z katalogu do nowego testowego zamowienia z iloscia 2. Waga jednej sztuki powinna wynosic 1,234 kg, bez doliczania skladnikow.
3. Zmien wage w katalogu. Waga juz zapisanej pozycji zamowienia powinna pozostac bez zmian.
4. Przy najblizszym normalnym imporcie nowego zamowienia z powiazanym zestawem sprawdz przejecie wagi OMS. Przy pustej wadze OMS PrestaShop zachowa wage sklepu, a Allegro pozostawi brak wagi, jezeli zrodlo jej nie dostarczylo.
5. Sprawdz walidacje pojedynczego wypelnionego wymiaru oraz mozliwosc wyczyszczenia wszystkich parametrow.

Dlugotrwale lokalne workery musza wczytac nowy kod importu przez restart lokalnego Main Queues. Bez restartow na VPS w ramach tego zadania.
