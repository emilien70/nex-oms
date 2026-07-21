# Moduly

## Zasada glowna

Kazdy wiekszy obszar systemu ma wlasny modul w katalogu `Modules/`. Projekt pozostaje modularnym monolitem, czyli jedna aplikacja Laravel z logicznie rozdzielonymi obszarami.

## Planowane moduly

- `Dashboard` - ekran startowy panelu.
- `Orders` - wspolny model zamowien dla Allegro i PrestaShop.
- `Products` - produkty i ich dane bazowe.
- `SerialNumbers` - osobna obsluga numerow seryjnych.
- `Shipments` - przesylki i statusy wysylek.
- `Invoices` - faktury i dokumenty sprzedazy.
- `Emails` - komunikacja e-mail.
- `Integrations` - integracje z uslugami zewnetrznymi.
- `Automation` - reguly reagujace na zdarzenia zamowien i przesylek.

## Modul Orders

Modul `Orders` jest centralnym obszarem OMS. Zamowienia z roznych zrodel maja docelowo trafiac do wspolnego modelu `orders`, niezaleznie od tego, czy pochodza z integracji, czy zostaly dodane manualnie.

Orders obsluguje trzy oddzielne obszary danych osobowych i adresowych bez osobnej bazy klientow ani osobnej bazy adresow:

- dane kontaktowe kupujacego bezposrednio w `orders`,
- adres dostawy bezposrednio w polach `orders.shipping_*`,
- dane do faktury bezposrednio w polach `orders.billing_*`.

Dane do faktury moga byc inne niz dane kupujacego i zawieraja osobny NIP w `orders.billing_tax_id`.

Podstawowe statusy v0.4.0:

- `new` - Nowe.
- `pending` - Oczekujace.
- `shipped` - Wyslane.
- `cancelled` - Anulowane.

Workflow statusow:

- nowe zamowienie ma domyslnie status `new`,
- zamowienie moze zostac oznaczone jako oczekujace,
- zamowienie moze zostac oznaczone jako wyslane,
- zamowienie moze zostac anulowane,
- zamowienie moze zostac przywrocone jako nowe,
- kazda zmiana statusu zapisuje zdarzenie w `order_events`.

Statusy sa na tym etapie stale w modelu `Order`. Nie tworzymy osobnej tabeli statusow.

Od v0.5.0 zamowienia mozna dodawac i edytowac recznie z panelu. Formularz zapisuje dane zamowienia, dane kontaktowe kupujacego, adres dostawy, dane do faktury oraz do 5 pozycji zamowienia bezposrednio w danych konkretnego zamowienia.

Od v0.5.1 szczegoly zamowienia sa edytowalne sekcjami jak karta operacyjna OMS. Uzytkownik moze zapisac osobno informacje o zamowieniu, produkty, adres dostawy, dane do faktury, platnosc oraz numery S/N. Kazda sekcja zapisuje tylko swoje dane i dopisuje zdarzenie do historii zamowienia.

Od v0.6.0 widok szczegolow i lista zamowien maja bardziej operacyjny uklad inspirowany Base.com:

- pola w szczegolach zamowienia wlaczaja edycje calej sekcji inline,
- pole "Zaplacono" ma osobny mini-edytor wplaty,
- dane punktu odbioru sa osobna sekcja edytowalna,
- lista zamowien pokazuje klikalny numer zamowienia bez znaku `#`,
- oznaczenie gwiazdka ma wybor koloru na liscie.

Sekcja "Informacje o zamowieniu" zawiera kompaktowe dane operacyjne:

- login klienta,
- e-mail,
- telefon,
- zrodlo zamowienia,
- sposob wysylki,
- informacje o pobraniu,
- koszt wysylki,
- sposob platnosci,
- kwote zaplacona i laczna wartosc zamowienia.

Sekcja "Odbior w punkcie" zawiera w MVP:

- nazwe punktu,
- ID punktu,
- adres,
- kod i miasto.

Sekcja produktow w module Orders obsluguje:

- tabele produktow w szczegolach zamowienia,
- dodawanie produktu do zamowienia,
- edycje pojedynczego produktu,
- usuwanie pojedynczego produktu.

W MVP produkty w interfejsie uzywaja tylko:

- nazwy produktu,
- ilosci,
- ceny jednostkowej,
- waluty,
- opcjonalnie VAT i wagi.

Produkty w MVP nie uzywaja w interfejsie SKU, EAN, lokalizacji ani atrybutow. Pola techniczne moga zostac w strukturze bazy, ale sa pominiete w formularzach i tabelach produktow.

W MVP dane do faktury zawieraja NIP, ale w podgladzie i sekcyjnej edycji szczegolow zamowienia nie pokazuja telefonu, e-maila ani kraju. Adres dostawy pokazuje dane adresowe, wojewodztwo i kraj, a telefon oraz e-mail klienta sa prezentowane w sekcji "Informacje o zamowieniu".

## Zasady implementacji

- Kontrolery nie zawieraja logiki biznesowej.
- Logika biznesowa trafia do serwisow.
- Integracje API sa izolowane w osobnych modulach.
- Wspolne kontrakty i DTO powinny byc dodawane dopiero, gdy upraszczaja realny kod.

## Modul Shipments

Modul `Shipments` przechowuje wspolny model przesylki niezalezny od konkretnego przewoznika. Jedno zamowienie moze miec wiele przesylek. Modul odpowiada za:

- konfiguracje kont kurierskich,
- lokalny stan przesylki i numer nadania,
- zdarzenia przesylki,
- pobieranie etykiet,
- kolejkowanie utworzenia, odswiezenia i anulowania przesylki.
- panel operacyjny przesylek z filtrowaniem i paginacja,
- konfiguracje pojedynczych kont InPost Paczkomaty, InPost Kurier, DPD i Wysylam z Allegro,
- podpaczki przesylek kurierskich z waga, wymiarami i oznaczeniem elementu niestandardowego.

Status techniczny zwrocony przez przewoznika jest mapowany na wspolny workflow przesylek OMS:

- `created` - Przesylka utworzona, 0%,
- `dispatched` - Przesylka nadana, 33%,
- `out_for_delivery` - Wydano do doreczenia, 66%,
- `ready_for_pickup` - Oczekuje w punkcie, 90%,
- `delivered` - Doreczono, 100% na zielono,
- `problem` - Wystapil problem, 50% na czerwono,
- `returned` - Zwrot, 100% na czerwono.

Widoki korzystaja ze statusu OMS, a surowy status przewoznika pozostaje dostepny dla sterownika integracji. Zdarzenie `shipment.status_changed` jest publikowane przy zmianie etapu OMS, nie przy kazdej technicznej zmianie po stronie kuriera.

Integracje kurierskie korzystaja ze wspolnego kontraktu `CourierDriver` i rejestru `CourierDriverRegistry`. Sterownik deklaruje obslugiwane mozliwosci, m.in. utworzenie przesylki, odswiezenie statusu, etykiete, anulowanie i tracking. InPost Paczkomaty, InPost Kurier, DPD oraz Wysylam z Allegro maja osobne sterowniki, dzieki czemu ich uslugi, walidacja i formularze przesylek moga rozwijac sie niezaleznie. Kolejni przewoznicy maja byc dodawani jako osobne sterowniki bez kopiowania logiki kontrolerow.

Pierwszym adapterem modulu jest `Modules/Integrations/InPost`. `InPostLockerDriver` i `InPostCourierDriver` wspoldziela klienta ShipX, operacje kolejki oraz synchronizator statusow, ale maja osobne budowanie payloadu i tworzenie lokalnej przesylki. Dzieki temu kolejne integracje kurierskie beda mogly korzystac ze wspolnego modelu `shipments` bez dodawania pol przewoznika bezposrednio do `orders`.

Drugim adapterem jest `Modules/Integrations/DPD`. `DpdDriver` korzysta z REST DPD Services podczas nadania i generowania etykiety oraz z DPD InfoServices przy synchronizacji statusu. Cala komunikacja jest logowana, wykonywana przez kolejke `integrations`, limitowana i chroniona przed rownoleglym odswiezaniem tej samej przesylki.

Trzecim adapterem jest `Modules/Integrations/AllegroShipping`. `AllegroShippingDriver` korzysta z propozycji dostawy przypisanej do zamowienia Allegro, a tworzenie i anulowanie realizuje przez asynchroniczne komendy Shipment Management. Modul ma osobny klient OAuth, fabryke payloadu, synchronizator, zadania kolejki, szablony wag i wymiarow oraz panel konfiguracyjny zgodny ze wspolnym wzorcem kurierskim.

Modul publikuje neutralne zdarzenia domenowe niezalezne od konkretnego kuriera:

- `shipment.created`,
- `shipment.creation_failed`,
- `shipment.retry_queued`,
- `shipment.status_changed`.

Zdarzenia stanowia punkt rozszerzenia dla planowanego modulu `Automatyczne akcje`. Zmiany statusu zamowienia przechodza przez wspolny `OrderStatusService`, ktory publikuje `order.status_changed`. Tabele `order_events` i `shipment_events` pozostaja historia dla uzytkownika, a nie kolejka automatyzacji.

Bledy tworzenia przesylki zachowuja techniczne rozroznienie `creation_failed` i `creation_unknown`, ale oba wymagaja sprawdzenia panelu InPost i nie udostepniaja akcji ponownego nadania.

## Modul Automation

Modul `Automation` realizuje automatyczne akcje jako reguly: zdarzenie, opcjonalne warunki i uporzadkowana lista dzialan. Nazwa reguly jest opcjonalna i moze zostac wygenerowana automatycznie. Reguly nie korzystaja z grup. Pierwsza wersja obsluguje zdarzenia:

- `order.status_changed`,
- `shipment.created`,
- `shipment.status_changed`,
- `shipment.creation_failed`.

Warunki moga sprawdzac status i zrodlo zamowienia, pobranie, stan platnosci, sposob wysylki, przewoznika, status przesylki oraz laczna kwote. Dostepne dzialania to zmiana statusu zamowienia, utworzenie przesylki InPost Paczkomaty, opoznienie kolejnego kroku i wywolanie zewnetrznego adresu URL metoda GET.

Akcja `Wywolaj URL` jest wykonywana po stronie serwera z kontrolowanym timeoutem i bez automatycznego ponawiania. Wynik HTTP jest zapisywany w dzienniku `integration_api_logs`, a odpowiedz bledna zatrzymuje lub oznacza blad kroku zgodnie z ustawieniem akcji.

Adres URL moze korzystac ze wspolnych zmiennych zamowienia, na przyklad `[uwagi_sprzedawcy]` i `[data_zamowienia]`. Wartosci sa kodowane jako elementy URL przed wykonaniem zapytania. Centralny katalog zmiennych znajduje sie w `Ustawienia -> Zmienne` i jest przygotowany do ponownego wykorzystania w przyszlych szablonach e-mail oraz innych modulach OMS.

Ocena i wykonanie regul odbywaja sie przez kolejke `automation`. Kazde wykonanie ma wlasny przebieg oraz kroki, jest odporne na ponowne przetworzenie tego samego zdarzenia i nie uruchamia tej samej reguly drugi raz w jednym lancuchu automatyzacji. Wynik jest rowniez dopisywany do `order_events`, dlatego pojawia sie w historii zamowienia.

Tworzenie przesylki jest blokowane dla zdarzen rozpoczynajacych sie od `shipment.`, co zabezpiecza system przed petla automatycznego tworzenia paczek.
