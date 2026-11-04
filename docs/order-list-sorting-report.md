# Sortowanie listy zamowien

## Interfejs

Przycisk z ikona sortowania znajduje sie bezposrednio za Zmien status.
Menu ma sekcje Sortuj wedlug oraz Sortowanie i wyswietlanie, z kierunkami
Malejaco i Rosnaco. Wybrane pole i kierunek sa podswietlone.
Wysokosc menu dopasowuje sie do dostepnego miejsca; na mniejszym ekranie
ma wlasne przewijanie. Ikony pochodza z istniejacych Bootstrap Icons.

Domyslna kolejnosc zmienia sie z created_at DESC, id DESC na ID OMS malejaco.
Nie zmienia to dat ani identyfikatorow zamowien. Zmiana pola lub kierunku
zachowuje filtry i liczbe pozycji, ale wraca na pierwsza strone.

## Pola i reguly

| Pole | Zrodlo |
| --- | --- |
| ID zamowienia | orders.id |
| Numer w sklepie | liczbowe external_id zamowienia PrestaShop |
| Data zlozenia | purchased_at, a przy braku created_at |
| Data w statusie | status_changed_at, a przy braku created_at |
| Laczna kwota | total_gross |
| Koszt wysylki | delivery_cost_gross |
| Sposob wysylki | shipping_method |
| Status | kolejnosc aktualnych statusow OMS |
| Stan platnosci | brak wplaty, czesciowa wplata, oplacone/nadplacone |
| Imie i nazwisko | shipping_name, a przy braku customer_login |
| Login kupujacego | customer_login ze wszystkich zrodel |
| Numer transakcji Allegro | external_id tylko dla Allegro |
| Numer nadawczy | ostatnia przesylka z niepustym numerem, wedlug created_at i ID |

Puste wartosci pozostaja na koncu w obu kierunkach. Remisy rozstrzyga ID
w tym samym kierunku co wybrane pole. Numery transakcji i nadawcze sa
tekstowe. Kwoty sa porownywane nominalnie, bez przeliczania walut.
Sortowanie tekstu korzysta z funkcji i kolacji bazy; nie dodano odrebnej
polskiej kolacji dla SQLite. Nie zmieniono stanu platnosci ani kwot.

## Nawigacja i zapisane widoki

Parametry sort i direction sa walidowane po zamknietej liscie, a nie
traktowane jako nazwy kolumn lub fragmenty SQL. Nieprawidlowe wartosci
w adresie daja ustawienia domyslne; zapis widoku odrzuca nieprawidlowy wybor.

OrderListFilterState zapisuje sortowanie w istniejacym JSON filters.
Domyslna para id/desc jest kanonizowana jako brak dodatkowych parametrow,
wiec starsze zapisane widoki nie wymagaja migracji ani nadpisania.
Sortowanie samo w sobie nie aktywuje filtrow. Wyczysc filtry zachowuje
sortowanie. Zakres zaznaczenia pomija sort i direction, poniewaz zmieniaja
one kolejnosc, ale nie zestaw pasujacych zamowien.

Kontekst list zachowuje sortowanie przy powrocie, przeladowaniu strony
i przechodzeniu strzalkami. Strzalki uzywaja tej samej kolejnosci co lista,
takze na granicach stron oraz pomiedzy pustymi i wypelnionymi wartosciami.

OrderListSort wspoldzieli definicje sortowania i wybor sasiadow. Dla ID
stosuje proste porownania ID. Pozostale pola sa porownywane w dwoch
zapytaniach SQL z zapytaniem bazowym i kotwica biezacego zamowienia.
DECIMAL nie przechodzi przez PHP float na potrzeby porownania sasiadow.
Nie laduje sie wszystkich wynikow do PHP w celu ich sortowania.
Sortowanie numeru nadawczego korzysta z podzapytania do shipments;
przy bardzo duzych zbiorach nalezy osobno zmierzyc jego plan i koszt.

## Pliki

Utworzone:

- app/Support/OrderListSort.php
- tests/Unit/OrderListSortTest.php
- tests/Feature/OrderSortingTest.php
- docs/order-list-sorting-report.md

Zmodyfikowane:

- app/Support/OrderListFilterState.php
- app/Http/Controllers/OrdersController.php
- app/Http/Controllers/OrderSavedViewController.php
- resources/views/orders/index.blade.php
- resources/views/orders/partials/list-styles.blade.php
- tests/Feature/OrderNavigationTest.php
- tests/Feature/OrderListContextNavigationTest.php
- tests/Frontend/order-list-navigation.test.cjs
- docs/architecture.md
- docs/product-spec.md
- docs/order-list-navigation-report.md

Nie usunieto plikow. Dotychczasowe lokalne zmiany pozostaja zachowane.
W testach nawigacji kolejnosc tworzenia rekordow dostosowano do nowego
domyslnego ID DESC; scenariusze dat wskazuja jawnie sortowanie daty.

## Testy i ograniczenia

PHP 8.2.12, APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
pusty DB_URL i osobny testowy APP_KEY tylko w procesie testowym.
Nowe testy blokuja prawdziwy HTTP i symuluja kolejke.

```text
C:\xampp\php\php.exe vendor/bin/phpunit
  tests/Unit/OrderListSortTest.php
  tests/Feature/OrderSortingTest.php
  tests/Unit/OrderListNavigationContextTest.php
  tests/Feature/OrderListContextNavigationTest.php
  tests/Feature/OrderNavigationTest.php
  tests/Feature/OrdersSearchTest.php
  tests/Feature/OrderSavedViewsTest.php
  tests/Feature/OrdersAjaxListTest.php
  tests/Feature/OrderAjaxUpdatesTest.php
  tests/Feature/OrdersPaginationTest.php
  tests/Feature/OrdersTrashTest.php
  tests/Feature/OrdersMobileLayoutTest.php
  tests/Feature/OrderStatusSettingsTest.php
  tests/Feature/OrderStatusPollingTest.php
  tests/Feature/OrderStatusServiceTest.php
```

Wynik: 133 testy, 912 asercji, bez niepowodzen. Macierz obejmuje wszystkie
13 pol w obu kierunkach oraz sasiadow kazdego wyniku, remisy, puste dane,
ostatni numer przesylki, filtry, paginacje, zapisane widoki i niepoprawne
parametry. Dodatkowy test kompiluje 39 zapytan gramatyka MySQL w trybie
pretend, bez serwera MySQL. Pamieciowy PDO SQLite sluzy w nim wylacznie
do cytowania wartosci w logu; zapytania MySQL nie sa wykonywane.
Nie jest to weryfikacja na rzeczywistym MySQL 8 ani test obciazeniowy.

```text
node --test tests/Frontend/order-list-navigation.test.cjs tests/Deployment/main-queues.test.cjs
```

Wynik: 16 testow bez niepowodzen. 9 testow sprawdza odswiezanie listy,
historie oraz dopasowanie wysokosci menu; 7 dotyczy wczesniejszych zmian
uruchamiania Main Queues i pozostaje poprawnych.
Pint --test: PASS dla 8 plikow PHP. php -l: PASS dla 8 plikow PHP
i 2 widokow Blade. git diff --check: PASS.
Testy KSeF i pelna regresja innych modulow nie naleza do tego etapu.

W dzialajacym lokalnym panelu sprawdzono menu przy 1366x900 i 390x844,
jego granice i przewijanie oraz wybor Laczna kwota / Rosnaco przez AJAX.
Nie zmieniono zamowien, wplat, dokumentow ani ustawien integracji.

## Weryfikacja reczna

1. Odswiez liste i otworz ikone za Zmien status.
2. Wybierz pole i kierunek; sprawdz podswietlenie oraz pierwsza strone.
3. Ustaw filtr i zmien sortowanie; filtr ma pozostac aktywny.
4. Otworz zamowienie, uzyj strzalek i Powrot do listy.
5. Zapisz widok, zmien sortowanie, a potem zastosuj zapisany widok.
6. Sprawdz, ze Wyczysc filtry nie resetuje wybranego sortowania.
7. Na telefonie sprawdz przewijanie menu i dostep do obu kierunkow.

Brak migracji, backfillu i restartu kolejek. Bez zmian .env, zaleznosci,
VPS, commit, push lub deploymentu.
