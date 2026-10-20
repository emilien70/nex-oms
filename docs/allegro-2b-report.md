# ALLEGRO.2B — raport wdrożenia

Data: 19.10.2026.

## Wykonane zmiany

- Rozszerzono istniejące okno powiązań produktów i zestawów o połączone konta Allegro, bez tworzenia drugiego katalogu.
- `+` rozpoczyna nowe powiązanie. Produkt lub zestaw może mieć wiele ofert tego samego konta. Kafelek pokazuje liczbę, a kliknięcie otwiera listę z edycją i linkami do ofert.
- Po zapisaniu okno pozostaje otwarte i pokazuje numer oferty prowadzący do Allegro. Obsługiwane są oddzielne adresy produkcji i sandbox.
- Import korzysta wyłącznie z ręcznych powiązań numeru oferty. Przypisuje ID OMS, zdjęcie i opcjonalnie nazwę oraz VAT katalogowy. Cena, ilość, waluta, dostawa i płatności pozostają z zamówienia Allegro.
- Brak powiązania daje ostrzeżenie, nie blokuje importu i nie tworzy nowego produktu. Usługi dodatkowe nie dziedziczą powiązania oferty głównej.
- Zestaw jest jedną pozycją zamówienia z własnym ID OMS i zapisanym składem. Stan magazynowy nie jest zmieniany.
- Zmiana powiązania, danych używanego produktu, składu zestawu albo ustawień po podglądzie wymaga nowego potwierdzenia. Powtórna kontrola wykonywana jest również pod blokadami w transakcji zapisu.
- Wcześniejsze zamówienia i wystawione faktury nie są aktualizowane. Nie dodano dopasowania po nazwie, EAN lub SKU, masowych powiązań ani zapisów do Allegro.

## Zmienione pliki

### Utworzone

- `database/migrations/2026_10_19_050000_add_allegro_product_links_and_import_settings.php`
- `Modules/Integrations/Allegro/Support/AllegroOfferUrl.php`
- `Modules/Integrations/Allegro/Models/AllegroProductLink.php`
- `Modules/Integrations/Allegro/Http/Requests/SaveAllegroProductLinkRequest.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroOrderImportSettingsRequest.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroProductLinkController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroOrderImportSettingsController.php`
- `Modules/Integrations/Allegro/Services/AllegroProductLinkService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderCatalogService.php`
- `tests/Feature/Products/AllegroProductLinkTest.php`
- `tests/Feature/AllegroOrderCatalogTest.php`
- `docs/allegro-2b-report.md`

### Zmienione w tym etapie

- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderLink.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderPreviewService.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderImportService.php`
- `Modules/Integrations/Prestashop/Http/Controllers/PrestashopProductLinkController.php`
- `Modules/Products/Models/Product.php`
- `Modules/Products/Http/Controllers/ProductController.php`
- `Modules/Products/Services/OrderItemBundleSnapshotService.php`
- `resources/views/products/index.blade.php`
- `resources/views/products/partials/integration-links.blade.php`
- `resources/views/integrations/allegro/_orders.blade.php`
- `routes/web.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usunięte pliki projektu: brak. Wcześniejsze, niezatwierdzone zmiany ALLEGRO.2A zostały zachowane. Tymczasowy router, baza, sesje i kopia miniatury QA zostały usunięte; dodatkowy serwer zatrzymano.

## Migracje

Migracja `2026_10_19_050000_add_allegro_product_links_and_import_settings.php` została sprawdzona przez `--pretend`, następnie uruchomiona lokalnie, batch 88. Dodaje tabelę konfiguracji powiązań i trzy przełączniki importu. Nie aktualizuje pozycji ani dokumentów historycznych.

Komenda dla kolejnego środowiska:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_19_050000_add_allegro_product_links_and_import_settings.php --force
```

Konfiguracja powiązania jest usuwana wraz z trwale usuwanym kontem lub produktem. Historyczna pozycja zamówienia zachowuje nazwę, cenę i VAT, a istniejąca relacja do produktu może przejść na null. Przełączenie sprzedawcy lub środowiska nie przenosi konfiguracji powiązań na nową tożsamość.

## Ustawienia

W Allegro → Zamówienia domyślnie włączone są:

- używanie powiązanych produktów OMS,
- nazwy produktów z katalogu OMS,
- VAT z katalogu OMS dla zamówień krajowych allegro.pl.

VAT jest kopiowany wyłącznie przy jawnym rynku `allegro-pl` i krajach dostawy oraz faktury `PL`. Dla innych lub nieznanych danych pozostaje pusty z ostrzeżeniem. Importer nie przypisuje samodzielnie 23%; kopiuje zwalidowaną stawkę produktu. Nie wdrożono reguł VAT dla sprzedaży zagranicznej.

## Testy

Przed wykonaniem sprawdzono `phpunit.xml`: SQLite `:memory:`, nie baza robocza. API Allegro jest zastąpione odpowiedziami testowymi.

Nowe testy:

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroProductLinkTest|AllegroOrderCatalogTest"
```

Wynik: **49 testów, 256 asercji, PASS**. Obejmują wiele ofert, współistnienie PrestaShop i Allegro w odpowiedziach AJAX, duże numery jako stringi, walidację, własność powiązania, tożsamość konta, sandbox, usuwanie konfiguracji, podgląd i import, ustawienia, zdjęcie, ceny Allegro, zestawy, zmiany między podglądem a zapisem, VAT, brak dopasowania, usługi dodatkowe, brak zmian magazynu i ochronę dokumentów historycznych.

Końcowy zestaw regresji:

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="Allegro|Prestashop|ProductBundleTest|ProductCatalogTest|OrderNavigationTest|OrderAjaxUpdatesTest|InvoiceDocumentPreparationTest|InvoiceIssuingServiceTest|OrderSalesDocumentActionsTest|IntegrationsList"
```

Wynik: **271 testów, 1880 asercji, PASS**.

Osobno uruchomiono `artisan test --compact --filter=OrderCatalogProductTest`: **9 PASS, 1 FAIL**, 97 asercji. Nadal nie przechodzi `test_duplicating_order_preserves_catalog_product_link_and_manual_items`: kopiowanie zamówienia traci `product_id`, linia 139. Ten sam błąd był odnotowany przed ALLEGRO.2B w `docs/allegro-1-report.md:75`. Nie rozszerzano etapu o naprawę kopiowania. Wcześniejszy szerszy przebieg obejmujący tę klasę także wykazał wyłącznie ten błąd: 278 PASS, 1 FAIL.

Dodatkowo: Pint dla zmienionych plików PHP PASS; `php -l` dla 20 plików PASS. Nie uruchamiano całego zestawu testów projektu.

## Weryfikacja przeglądarkowa

Wykonano na osobnej bazie SQLite i lokalnym serwerze QA, z blokadą rzeczywistych wywołań API i z kopią istniejącej miniatury produktu:

1. Otwarcie `+`, wybór konta Allegro i formularz numeru oferty.
2. Dodanie trzeciego powiązania bez zamknięcia okna oraz zaktualizowany licznik.
3. Lista trzech ofert, otwarcie edycji wybranej i zapis innego numeru bez naruszania pozostałych.
4. Równoczesna obecność powiązania PrestaShop.
5. Podgląd zamówienia z ID, nazwą i zdjęciem OMS oraz niezmienioną ceną Allegro. Miniatura poprawnie załadowana, 320 × 320 px.
6. Próbny import przez przeglądarkę do QA i potwierdzenie produktu OMS, zdjęcia, ceny 30,01 PLN, ilości 3 oraz VAT 23% na karcie.
7. Formularz na ekranie 390 × 844: brak rozszerzenia strony i nakładania tekstu. Tymczasowy rozmiar okna przywrócono.

W bazie roboczej nie tworzono próbnych powiązań ani zamówienia.

## Weryfikacja ręczna

1. Otwórz `http://127.0.0.1:8000/products` albo zakładkę Zestawy.
2. Przy wybranej pozycji kliknij `+`, wybierz skonfigurowane konto Allegro i wpisz rzeczywisty numer swojej oferty.
3. Zapisz. Okno powinno pozostać otwarte; link w wierszu Oferta pozwala sprawdzić numer.
4. Dodaj kolejne powiązanie przez `+`. Sprawdź licznik oraz listę po kliknięciu kafelka Allegro.
5. W `http://127.0.0.1:8000/integrations/allegro?tab=orders` sprawdź trzy ustawienia importu.
6. Pobierz podgląd nowego, jeszcze nieimportowanego zamówienia po UUID. Zweryfikuj dopasowanie, nazwę, zdjęcie, VAT i cenę Allegro, a następnie potwierdź import.
7. Dla zestawu sprawdź jego własne ID i jedną pozycję. Stany składników nie powinny się zmienić.
8. Po zmianie powiązania nie oczekuj automatycznego poprawienia starego zamówienia. Ponowny import otworzy dotychczasowy rekord bez nadpisania.

## Ograniczenia i ryzyka

- Nie testowano nowego importu na rzeczywistym zamówieniu Allegro, ponieważ nie wskazano testowego UUID.
- Zapis numeru sprawdza format i konflikty w OMS, ale nie sprawdza istnienia ani własności oferty w API. Link umożliwia ręczną weryfikację bez rozszerzania uprawnień OAuth.
- Zdjęcie jest bieżącą miniaturą powiązanego produktu OMS, nie historyczną kopią. Zmiana zdjęcia w katalogu może zmienić jego prezentację w starszym zamówieniu, bez zmiany danych finansowych.
- Dalsza synchronizacja istniejących zamówień, podpowiedzi, operacje masowe, stany, ceny ofert, wystawianie i modyfikowanie ofert są poza zakresem.
- Pozostaje wcześniejszy błąd kopiowania zamówienia opisany w sekcji testów.
- Nie zmieniono plików środowiska, locków ani zależności. Nie wykonano commita ani pusha.
