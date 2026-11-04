# Lista zamowien - zachowanie kontekstu nawigacji

## Zachowanie

Wejscie w zamowienie z listy przenosi status, filtry, wyszukiwanie,
numer strony i liczbe pozycji w parametrze list adresu karty zamowienia.
Nie zapisuje ich we wspolnej sesji ani ustawieniach konta. Rozne karty
przegladarki moga niezaleznie pracowac na roznych listach.

Powrot do listy oraz klikniecie statusu pochodzenia w bocznym menu
odtwarzaja ten kontekst. Inny status otwiera nowa liste bez filtrow.
Filtry sa nadal aktywne, uzupelnione i widoczne po powrocie; reczne
zwiniecie panelu nie jest wylaczeniem filtrowania.

Strzalki poprzednie/nastepne uzywaja tego samego zapytania z filtrami
co lista. Przechodza rowniez miedzy stronami, ale zachowuja pierwotna
strone powrotu. Po etapie sortowania kolejnosc wyznaczaja wybrane pole
i kierunek, domyslnie ID DESC; szczegoly: docs/order-list-sorting-report.md.
Zapytania o sasiadow wybieraja pojedyncze ID po obu stronach biezacego
zamowienia, bez ladowania wszystkich wynikow do pamieci na karcie.

Zmiana statusu, zaplaty lub innych danych moze usunac zamowienie z wynikow.
Nie zmienia to kontekstu powrotu. Sasiedzi sa wyznaczani z aktualnych
danych, a nie z zamrozonej listy ID. Po zniknieciu ostatniej strony powrot
prowadzi do ostatniej dostepnej, z zachowaniem filtrow. Przy braku wynikow
pokazuje pierwsza strone pustej listy.

Marker list_return w adresie powrotu zapobiega ponownemu przekierowaniu
z wyszukiwania po dokladnym numerze przesylki prosto do zamowienia.
Nie jest filtrem, nie zmienia zapisanych widokow ani zakresu zaznaczenia.
AJAX aktualizuje adres przegladarki po korekcie numeru strony; odswiezanie
w tle zastepuje poprzedni adres bez dodawania wpisu historii.

Bez parametru list karta zachowuje dotychczasowa nawigacje. Nie dodano
trwalego pamietania filtrow miedzy sesjami. Przejscia przez osobne ekrany
edycji dokumentow/faktur nie zostaly rozszerzone o ten kontekst.

## Bezpieczenstwo i kompatybilnosc

OrderListNavigationContext korzysta z listy pol OrderListFilterState.
Pomija obce klucze i tablice w polach skalarnych. Statusy pobiera
z biezacych ustawien OMS. Numer strony musi byc dodatnia liczba calkowita
do 1000000; nieprawidlowy daje 1. Liczba pozycji pochodzi z tej samej
listy dozwolonych wartosci co dotychczasowa lista zamowien.

Adresy sa zawsze budowane przez wewnetrzne trasy orders.index/orders.show.
Nie przyjmujemy dowolnego return_to ani adresu przekierowania. HTML
pozostaje escapowany przez Blade. Nie dodano tras, sesji, tabel, ustawien
ani zmian regul dostepu. Nie zmieniono semantyki filtrow ani zapisanych
widokow; wyszukiwanie globalne nadal obejmuje wszystkie statusy.

## Pliki

Zmodyfikowane:

- app/Http/Controllers/OrdersController.php
- app/Support/OrderListFilterState.php
- resources/views/orders/index.blade.php
- resources/views/layouts/app.blade.php
- tests/Feature/OrdersSearchTest.php
- tests/Feature/OrdersAjaxListTest.php
- docs/architecture.md
- docs/product-spec.md

Utworzone:

- app/Support/OrderListNavigationContext.php
- tests/Unit/OrderListNavigationContextTest.php
- tests/Feature/OrderListContextNavigationTest.php
- tests/Frontend/order-list-navigation.test.cjs
- docs/order-list-navigation-report.md

Nie usunieto plikow. Zachowano wczesniejsze lokalne zmiany dotyczace
potwierdzania przesylek i skryptu wdrozeniowego. W OrdersAjaxListTest
zaktualizowano stara asercje nazwy menu na Szablony E-mail/SMS; rozbieznosc
istniala juz w HEAD, bez zmiany funkcjonalnosci szablonow.

## Testy

PHP 8.2.12, testowy APP_KEY, APP_ENV=testing, DB_CONNECTION=sqlite,
DB_DATABASE=:memory:, DB_URL pusty tylko w procesie testow. Bez zmian .env
lub roboczej bazy. W nowych testach HTTP jest blokowane, a kolejka symulowana.

```text
C:\xampp\php\php.exe vendor/bin/phpunit
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

Wynik: 93 testy, 643 asercje, bez niepowodzen.

```text
node --test tests/Frontend/order-list-navigation.test.cjs
```

Wynik: 6 testow, bez niepowodzen. Testowany jest rzeczywisty fragment JS
z widoku z podstawionymi fetch, DOM i historia, bez rzeczywistych wywolan API.
Obejmuje poprawienie numeru strony, odswiezanie w tle, przekierowanie
wyszukiwania do zamowienia, blad odczytu i niezalezne karty.

Pint --test dla 7 zmienionych/nowych plikow PHP: PASS.
php -l dla tych plikow i 2 widokow Blade (9 plikow): PASS.
git diff --check: PASS.
Dodatkowo powtorzono 7 testow tests/Deployment/main-queues.test.cjs
z poprzedniego etapu: PASS; laczny przebieg JS obejmowal 13 testow.
Testy KSeF, pelna regresja pozostalych modulow i rzeczywiste operacje
integracji nie naleza do tego etapu.
Nie wykonywano testu na MySQL ani recznego scenariusza w dzialajacym panelu;
serwerowe scenariusze sprawdzono na izolowanym SQLite, a JS w symulacji DOM.

## Weryfikacja reczna

1. Otworz liste zamowien i wybierz status z wieksza liczba pozycji.
2. Ustaw filtry, np. zrodlo Allegro i Nieoplacone, oraz liczbe pozycji.
3. Przejdz na druga strone, otworz zamowienie i uzyj Powrot do listy.
4. Sprawdz aktywne filtry, uzupelnione pola, wyniki i numer strony.
5. Ponownie otworz zamowienie i kliknij ten sam status w bocznym menu.
6. Sprawdz strzalki oraz granice stron; powrot zachowuje strone startowa.
7. Wybierz inny status: nie powinien przejac filtrow poprzedniej listy.
8. Uzyj Wyczysc filtry: poprzednie wartosci nie powinny wrocic.
9. W drugiej karcie ustaw inny filtr; obie karty maja byc niezalezne.

Zmiana dziala po odswiezeniu strony. Nie wymaga migracji, backfillu,
restartu kolejek ani zmian na VPS. Nie wykonano commit, push lub deploymentu.
