# Baza danych

## Silnik

Projekt zaklada uzycie MySQL albo MariaDB.

## Zasady

- Schemat bazy powinien byc zarzadzany migracjami Laravela.
- Nazwy tabel powinny byc czytelne i spojne z domena.
- Dane z Allegro i PrestaShop maja trafiac do wspolnego modelu zamowien.
- Numery seryjne sa osobnym obszarem domenowym.
- Numery seryjne sa przypisane do calego zamowienia, a nie do konkretnej pozycji `order_items`.

## Dodane tabele w v0.2.0

### orders

Tabela przechowuje wspolny model zamowien dla roznych zrodel, np. `manual`, `allegro`, `prestashop`.

Najwazniejsze pola:

- `id` jako wewnetrzny numer zamowienia NEX-OMS,
- `external_id` jako opcjonalny numer zamowienia w sklepie lub numer transakcji z integracji,
- status zamowienia, domyslnie `new`,
- data wejscia w aktualny status w polu `status_changed_at`,
- login, e-mail i telefon kupujacego zapisane bezposrednio w zamowieniu,
- adres dostawy zapisany bezposrednio w polach `shipping_*`,
- dane do faktury zapisane bezposrednio w polach `billing_*`,
- waluta,
- kwota brutto,
- kwota zaplacona w polu `paid_amount`,
- koszt dostawy brutto,
- kolor oznaczenia zamowienia w polu `star_color`,
- sposob wysylki,
- informacje, czy zamowienie jest pobraniowe,
- dane punktu odbioru w polach `pickup_point_name`, `pickup_point_id`, `pickup_point_address`, `pickup_point_postal_code`, `pickup_point_city`,
- status platnosci,
- metoda platnosci,
- daty zakupu i platnosci,
- uwagi w polu `notes`.

Relacje:

- zamowienie ma wiele pozycji,
- zamowienie ma wiele zdarzen historii,
- zamowienie ma wiele przesylek.

Statusy zamowienia:

- `new` - Nowe.
- `pending` - Oczekujace.
- `shipped` - Wyslane.
- `cancelled` - Anulowane.

NEX-OMS uzywa tylko tych czterech statusow. Nowe zamowienie z integracji albo dodane recznie powinno startowac ze statusem `new`.

Identyfikatory zamowienia:

- `orders.id` jest jedynym numerem wewnetrznym NEX-OMS i jest pokazywany jako numer zamowienia,
- `orders.external_id` przechowuje identyfikator z Allegro, PrestaShop albo innego systemu zewnetrznego,
- osobne pole `order_number` zostalo usuniete, aby uniknac dublowania numerow.

Manualne dodawanie i edycja v0.5.0+:

- NEX-OMS nie prowadzi osobnej bazy klientow,
- `orders.customer_login`, `orders.customer_email`, `orders.customer_phone` przechowuja dane kontaktowe w ramach konkretnego zamowienia,
- NEX-OMS nie prowadzi osobnej bazy adresow,
- pola `orders.shipping_name`, `orders.shipping_company_name`, `orders.shipping_street`, `orders.shipping_building_number`, `orders.shipping_apartment_number`, `orders.shipping_postal_code`, `orders.shipping_city`, `orders.shipping_province`, `orders.shipping_country_code`, `orders.shipping_phone`, `orders.shipping_email` przechowuja adres dostawy dla konkretnego zamowienia,
- pola `orders.billing_name`, `orders.billing_company_name`, `orders.billing_tax_id`, `orders.billing_street`, `orders.billing_building_number`, `orders.billing_apartment_number`, `orders.billing_postal_code`, `orders.billing_city`, `orders.billing_province`, `orders.billing_country_code`, `orders.billing_phone`, `orders.billing_email` przechowuja dane do faktury dla konkretnego zamowienia,
- dane do faktury, w tym NIP, nie musza byc takie same jak adres dostawy,
- tabela `addresses` zostala usunieta z aktualnego schematu; stare dane sa migrowane do pól `orders.shipping_*` i `orders.billing_*`.

Rozszerzenie informacji o zamowieniu:

- `orders.shipping_method` przechowuje opis sposobu wysylki,
- `orders.cash_on_delivery` okresla, czy zamowienie jest pobraniowe.
- `orders.paid_amount` przechowuje kwote wplacona przez klienta.
- `orders.star_color` przechowuje kolor gwiazdki/oznaczenia na liscie zamowien.
- `orders.pickup_point_*` przechowuja dane odbioru w punkcie w MVP.
- `orders.shipping_province` i `orders.billing_province` moga przechowywac wojewodztwo dla konkretnego zamowienia.

### order_items

Tabela przechowuje pozycje zamowienia:

- nazwe produktu,
- SKU,
- EAN,
- identyfikator oferty,
- ilosc,
- cene jednostkowa brutto,
- wartosc pozycji brutto.

Relacje:

- pozycja nalezy do zamowienia.

### order_events

Tabela przechowuje historie zamowienia:

- typ zdarzenia,
- tytul,
- opis,
- opcjonalny payload JSON.

Relacje:

- zdarzenie nalezy do zamowienia.

## Integracje kurierskie

### courier_accounts

Tabela przechowuje konfiguracje kont kurierskich. Najwazniejsze pola to:

- `provider` - identyfikator integracji, m.in. `inpost_lockers`, `inpost_courier`, `dpd` albo `allegro_shipping`,
- `name` - nazwa konta widoczna w panelu,
- `environment` - `sandbox` albo `production`,
- `api_token` - token ShipX szyfrowany przez Eloquent,
- `api_secret` - szyfrowany sekret klienta OAuth, uzywany przez Wysylam z Allegro,
- `api_refresh_token` - szyfrowany token odswiezenia OAuth,
- `organization_id` - identyfikator organizacji ShipX,
- `settings` - ustawienia gabarytu, etykiety, sposobu nadania, opisu zawartosci oraz danych nadawcy,
- `is_active` - informacja, czy konto moze obslugiwac nowe operacje,
- `last_tested_at` i `last_error` - wynik ostatniego testu polaczenia.

Dla Wysylam z Allegro `organization_id` przechowuje Client ID aplikacji Device. Krotkotrwaly `device_code` nie jest zapisywany w bazie - istnieje tylko w sesji uzytkownika podczas laczenia konta. Po potwierdzeniu OAuth tabela przechowuje zaszyfrowane access token, refresh token i Client Secret.

### shipments

Tabela przechowuje przesylki powiazane z konkretnym zamowieniem:

- lokalny identyfikator przesylki i UUID zabezpieczajacy przed przypadkowym powtorzeniem operacji,
- konto kurierskie i dostawce,
- kod faktycznego przewoznika w `carrier_code`, gdy przesylka zostala utworzona przez posrednika Wysylam z Allegro,
- identyfikator ShipX oraz numer nadania,
- usluge, gabaryt, punkt docelowy i opcjonalny Paczkomat nadawczy,
- sposob nadania oraz opis zawartosci faktycznie wyslany do ShipX,
- kwote pobrania i ubezpieczenia,
- format oraz typ etykiety,
- surowy status przewoznika w `status` i jego date zmiany w `status_changed_at`,
- wspolny status operacyjny NEX-OMS w `oms_status` i date jego zmiany w `oms_status_changed_at`,
- ostatni blad i daty utworzenia, nadania oraz anulowania.

Rekord `shipments` jest elementem widocznym operacyjnie dopiero po otrzymaniu
numeru nadawczego. Techniczny rekord przygotowywany na potrzeby kolejki nie jest
pokazywany na karcie ani na liscie zamowien, dopoki przewoznik nie zwroci
`tracking_number`.

`oms_status` przyjmuje jedna z siedmiu wartosci: `created`, `dispatched`, `out_for_delivery`, `ready_for_pickup`, `delivered`, `problem` albo `returned`. Surowy status przewoznika pozostaje zachowany, poniewaz jest potrzebny do operacji API. Interfejs i przyszle automatyczne akcje korzystaja ze statusu OMS.

Relacje:

- przesylka nalezy do zamowienia,
- przesylka nalezy do konta kurierskiego,
- przesylka ma wiele zdarzen.

### shipment_creation_attempts

Tabela przechowuje techniczny przebieg kazdej proby utworzenia przesylki, zanim
powstanie widoczna przesylka z numerem nadawczym:

- `order_id`, `courier_account_id` i `provider` wskazuja kontekst operacji,
- `request_uuid` jest unikalnym kluczem idempotencji,
- `request_data` zachowuje dane potrzebne do diagnostyki i bezpiecznego
  rozstrzygniecia niepewnego wyniku,
- `status` przyjmuje `queued`, `processing`, `succeeded`, `failed` albo `unknown`,
- `error_message` przechowuje czytelny blad zwrocony do formularza,
- `outcome_unknown` odroznia znany blad walidacji od timeoutu, przy ktorym
  przewoznik mogl przyjac zlecenie.

Znany blad konczy probe statusem `failed` i usuwa techniczny, pusty rekord
przesylki. Wynik `unknown` zachowuje niewidoczny rekord techniczny oraz logi API,
aby pozniej mozna bylo sprawdzic wynik bez ryzyka podwojnego nadania.

### shipment_parcels

Tabela przechowuje podpaczki przesylki kurierskiej InPost:

- `shipment_id` - przesylka nadrzedna,
- `position` - kolejnosc podpaczki,
- `external_id` i `tracking_number` - identyfikatory nadane przez ShipX,
- `weight` - waga rzeczywista w kilogramach,
- `length`, `width`, `height` - wymiary w centymetrach,
- `is_non_standard` - oznaczenie elementu niestandardowego.

Jedna przesylka kurierska ma od jednej do 99 podpaczek. Usuniecie przesylki usuwa rowniez jej podpaczki.

### shipment_events

Tabela zapisuje historie techniczna przesylki, m.in. dodanie do kolejki, ponowienie nadania, utworzenie, zmiane statusu i blad. Opcjonalny `payload` przechowuje poprzedni i nowy status, numer nadawczy, zrodlo aktualizacji oraz czas zwrocony przez przewoznika. `occurred_at` oznacza czas zdarzenia u przewoznika, a w razie jego braku czas lokalnego odswiezenia.

Historyczne przesylki moga nadal zawierac statusy `creation_failed` i
`creation_unknown`. Nowy przeplyw zapisuje wynik nieudanego nadania w
`shipment_creation_attempts`: znany blad ma status `failed`, a niepewny wynik
API status `unknown`.

### integration_api_logs

Tabela zapisuje metadane request/response dla zewnetrznych API:

- nazwe integracji i operacji,
- metode oraz adres zadania,
- payload request i response,
- kod HTTP, czas wykonania i komunikat bledu,
- powiazanie z zamowieniem, przesylka i kontem kurierskim.
- powiazanie z proba utworzenia przesylki, rowniez gdy widoczna przesylka nie
  zostala utworzona.

Token API nie jest zapisywany w logu. Log moze zawierac dane operacyjne odbiorcy wymagane do diagnostyki integracji, dlatego dostep do tej tabeli powinien byc ograniczony.

## Automatyczne akcje

### automation_rules

Tabela przechowuje definicje regul: opcjonalna nazwe, zdarzenie uruchamiajace, warunki JSON, stan aktywnosci i kolejnosc. Gdy uzytkownik nie poda nazwy, system tworzy ja automatycznie ze zdarzenia i pierwszego dzialania. Reguly nie sa dzielone na grupy.

### automation_actions

Tabela przechowuje uporzadkowane dzialania reguly wraz z konfiguracja JSON i informacja, czy blad ma zatrzymac dalsze kroki.

### automation_runs

Tabela jest dziennikiem wykonan. Przechowuje regule, zamowienie, unikalny identyfikator zdarzenia, lancuch automatyzacji, glebie wykonania, migawke reguly i wynik. Unikalny indeks reguly i zdarzenia zabezpiecza przed powtornym wykonaniem.

### automation_run_steps

Tabela zapisuje wynik kazdego kroku, jego konfiguracje, dane wyjsciowe, blad oraz czasy rozpoczecia i zakonczenia.

Relacje:

- regula ma wiele dzialan i wykonan,
- wykonanie nalezy do zamowienia i ma wiele krokow,
- usuniecie definicji reguly nie usuwa historii zamowienia; przebieg zachowuje migawke konfiguracji.

## Zmiany w v0.3.0

### Kafelka Zarzadzanie

Kafelka Zarzadzanie w szczegolach zamowienia jest placeholderem dla przyszlych akcji operacyjnych. Nie zapisuje obecnie danych w osobnej kolumnie bazy.

### serial_numbers

Tabela `serial_numbers`, jesli istnieje w bazie po wczesniejszym etapie, nie jest usuwana i nie jest uzywana w aktualnym MVP. Pozostaje zarezerwowana na przyszlosc, gdyby projekt wrocil do osobnych rekordow S/N.

Historyczne pola tabeli:

- `order_id` - zamowienie, do ktorego przypisano numer,
- `serial_number` - numer seryjny, unikalny globalnie w calej tabeli,
- `source` - zrodlo dodania numeru, domyslnie `manual`,
- `scanned_by` - opcjonalna informacja, kto zeskanowal numer,
- `scanned_at` - data zeskanowania lub dodania numeru,
- `notes` - opcjonalne uwagi.

Indeksy:

- indeks na `order_id`,
- unikalny indeks na `serial_number`.

Relacje:

- historycznie numer seryjny nalezal do zamowienia,
- numer seryjny nie ma relacji do `order_items`.

## Planowane obszary danych

- Zamowienia.
- Produkty.
- Numery seryjne.
- Wysylki.
- Faktury.
- E-maile.
- Logi integracji.
- Ustawienia integracji.

## Etap obecny

Zamowienia mozna dodawac i edytowac recznie. Podstawowe integracje InPost Paczkomaty, InPost Kurier, DPD i Wysylam z Allegro zapisuja konfiguracje konta, przesylki, paczki, historie przesylek i logi API. Import zamowien z Allegro i PrestaShop nie jest jeszcze zaimplementowany.
