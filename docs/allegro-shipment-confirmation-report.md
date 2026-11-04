# Wysylam z Allegro - potwierdzenie paczki i ikona al

Data: 2026-11-04. Zakres: lokalny OMS, bez wdrozenia na VPS, commita i pusha.

## Przyczyna

W zamowieniu 423 szczegoly przesylki A0064NEYM1 zwrocily carrier=ALLEGRO
i transportingInfo z ALLEGRO_ONE_KURIER oraz takim samym numerem. Lista
przesylek zakupu zwrocila te paczke jako ALLEGRO, z poprawnym przypisaniem
pozycji. Stary helper usuwal pare glowna po samym podobienstwie numeru.
Weryfikacja porownujaca pelne pary nie mogla jej potem odnalezc.

Osobna przyczyna pierwszego komunikatu "brak potwierdzenia" bylo wylaczone
przekazywanie numerow. Ustawienie zostalo wlaczone przez wlasciciela, nie przez
te poprawke. Nie zmieniono zasad wlaczania funkcji ani bramki statusowej.

## Wykonane zmiany

- Zachowanie wszystkich par tej samej paczki; deduplikacja po przewozniku
  i numerze, bez globalnego utozsamiania przewoznikow lub dopasowania po nazwie.
- Poprawna para glowna do sledzenia i regul anulowania; aliasy transportingInfo
  pozostaja dostepne dla weryfikacji i historii.
- Aktualizacja aliasow istniejacego verification_only bez samoczynnego retry
  failed i bez zbiorczego wyszukiwania historycznych numerow.
- Snapshot nazwy/ID metody dostawy oraz prezentacja Allegro.pl (nazwa uslugi).
  Nazwa nie jest pobierana z pozniejszej edycji zamowienia i nie jest zgadywana.
- Jedna ikona al obok numeru: zielona, szara lub czerwona; opis w tooltipie.
  Rozmiar 16x16 px, font 9 px, radius 3 px, jak dotychczasowy znaczek listy.
  Kolory tla: #16834b, #9ca3af, #dc3545; napis bialy.
- Osobne komunikaty dla pending, waiting, skipped, disabled i braku sprawdzenia.
  Ponowienie bledu pozostaje w menu, z dotychczasowa ochrona backendu i CSRF.
- Podpowiedzi hover/focus/click, bez HTML; inicjalizacja i czyszczenie przy AJAX.
- Sygnatura sekcji obejmuje potwierdzenia, wiec sam wynik kolejki odswieza ikone.
  Wykorzystano istniejace odczyty stanu, bez nowego pollingu i API w przegladarce.

## Zmienione pliki

Zmodyfikowane:

- Modules/Integrations/Allegro/Observers/AllegroShipmentTrackingObserver.php
- Modules/Integrations/Allegro/Support/AllegroShipmentNumbers.php
- Modules/Integrations/AllegroShipping/Drivers/AllegroShippingDriver.php
- Modules/Integrations/AllegroShipping/Services/AllegroShippingSynchronizer.php
- Modules/Shipments/Models/Shipment.php
- app/Http/Controllers/OrderStatusController.php
- resources/views/orders/partials/allegro-shipment-exports.blade.php
- resources/views/orders/partials/shipment-row.blade.php
- resources/views/orders/show.blade.php
- tests/Feature/AllegroShipmentExportTest.php
- docs/product-spec.md
- docs/architecture.md

Utworzone:

- Modules/Integrations/Allegro/Support/AllegroShipmentExportPresentation.php
- database/migrations/2026_11_04_010000_add_delivery_method_snapshot_to_shipments.php
- tests/Unit/AllegroShipmentNumbersTest.php
- tests/Frontend/allegro-shipment-tooltips.test.cjs
- docs/allegro-shipment-confirmation-report.md

Nie usunieto plikow. Nie zmieniono .env, zaleznosci ani konfiguracji integracji.

## Migracja i lokalna weryfikacja

Za zgoda wlasciciela wykonano spojna kopie SQLite przez backup API
z polaczeniem zrodlowym mode=ro; integralnosc kopii: ok.

```text
storage/app/private/backups/nex-oms-before-allegro-shipment-confirmation-20261104-090024.sqlite
SHA256 D26E38603318586A951819CC88FD4F135EC6DED512DE3601896DF38FE8DA3CBF
```

Uruchomiono lokalnie tylko:

```text
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_11_04_010000_add_delivery_method_snapshot_to_shipments.php --force --no-interaction
```

Dwa nowe pola sa nullable. Migracja nie uzupelnia historycznych rekordow.
Nie uruchamiano migrate:fresh, rollbacku ani innych migracji na bazie wlasciciela.

Po migracji zweryfikowano i uzupelniono tylko Shipment 56 / zamowienie 423 /
A0064NEYM1. Zastosowano rzeczywiste szczegoly Allegro i istniejacy serwis
potwierdzenia. Lokalna naprawa wymuszala tylko GET do api.allegro.pl i blokowala
inne metody, w tym odnowienie OAuth. Potwierdzenie: success; glowny carrier:
ALLEGRO; nazwa: Allegro One Kurier. Porownanie przed/po potwierdzilo brak zmian
orders, pozycji zamowienia, faktur i innych przesylek zamowienia.
Nie nadano paczki, nie pobrano etykiety, nie anulowano i nie dopisano numeru.
Zachowano dawna historie nieudanych prob oraz dotychczasowa druga przesylke.

## Testy

PHP 8.2.12, jawne APP_ENV=testing, SQLite :memory:, pusty DB_URL i oddzielny
testowy APP_KEY. Nowe testy stosuja symulowane HTTP i kolejke; rzeczywiste
polaczenie opisane wyzej bylo osobna, zatwierdzona kontrola paczki 423.

```text
C:\xampp\php\php.exe vendor/bin/phpunit
  tests/Unit/AllegroShipmentNumbersTest.php
  tests/Unit/ShipmentStatusMapperTest.php
  tests/Unit/ShipmentQueueTest.php
  tests/Unit/ShipmentConfirmationScheduleTest.php
  tests/Feature/ShipmentConfirmationTest.php
  tests/Feature/ReturnParcelTrackingTest.php
  tests/Feature/OrderStatusPollingTest.php
  tests/Feature/OrderSortingTest.php
  tests/Feature/OrderNavigationTest.php
  tests/Feature/OrderAjaxUpdatesTest.php
  tests/Feature/AllegroShippingTest.php
  tests/Feature/AllegroShipmentSettingsTest.php
  tests/Feature/AllegroShipmentExportTest.php
  tests/Feature/DpdShipmentTest.php
  tests/Feature/InPostShipmentTest.php
  tests/Feature/InPostCourierShipmentTest.php
```

Wynik: 270 testow, 1684 asercje, bez niepowodzen. Pokrycie obejmuje wspolny
numer z roznymi przewoznikami, odrebne numery i paczki, puste numery, nieznane
oznaczenia, brak dopasowania bez udowodnionego aliasu, starszy failed i jego
reczne retry, brak POST, dane historyczne, escapowanie bledu, stany ikony,
sygnature AJAX oraz niezmiennosc nazwy. Test migracji chroni uzywany snapshot.

Pint --test: PASS dla 10 plikow PHP. PHP lint: PASS dla tych plikow i trzech
widokow Blade, lacznie 13 plikow. git diff --check: PASS; Git ostrzega jedynie
o dotychczasowej normalizacji CRLF w docs/product-spec.md.

```text
node --test tests/Frontend/allegro-shipment-tooltips.test.cjs tests/Frontend/order-list-navigation.test.cjs tests/Deployment/main-queues.test.cjs
```

Wynik: 19 testow, bez niepowodzen. Trzy nowe testy sprawdzaja plain-text
Tooltip, zwalnianie instancji i inicjalizacje w obu sciezkach wymiany wierszy.
Pozostale 16 potwierdza zachowanie wczesniejszej nawigacji i launcherow.

## Weryfikacja reczna i ograniczenia

W lokalnym panelu sprawdzono 1366x900 i 390x844: zielona ikona ma 16x16 px,
bialy napis, znajduje sie 5 px po prawej stronie numeru, a podpowiedz podaje
Potwierdzono w Allegro. Szara ikona podaje brak sprawdzenia, nie udaje kolejki.
Numer A0064NEYM1 prowadzi do sledzenia Allegro. Nie ma tekstu Allegro pod nim.

1. Odswiez zamowienie 423 i sprawdz Allegro.pl (Allegro One Kurier).
2. Najedz lub dotknij zielonego al obok A0064NEYM1.
3. Sprawdz opis szarego al przy niezweryfikowanej paczce.
4. Przy rzeczywistym bledzie otworz menu przesylki; ponowienie wymaga osobnego
   klikniecia. Klikniecie samego al jedynie pokazuje opis.

Nowy kod dlugo zyjacych workerow wymaga restartu tylko lokalnego Main Queues.
Nie uzywac globalnego queue:restart, ktory moglby objac KSeF. Nie wykonywano
testow KSeF, pelnej regresji wszystkich modulow ani migracji na MySQL/VPS.
Nie zaprojektowano automatycznej migracji wszystkich starych etykiet i nazw.

## Zrodla

- https://developer.allegro.pl/tutorials/jak-zarzadzac-przesylkami-przez-wysylam-z-allegro-LRVjK7K21sY
- https://developer.allegro.pl/news/wysylam-z-allegro-29-pazdziernika-przestaniemy-zwracac-numery-przewozowe-w-obiekcie-additionalproperties-WvgDEDPAlfX
