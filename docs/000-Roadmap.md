# NEX-OMS Roadmap

## Cel projektu

NEX-OMS to lokalnie rozwijany system OMS dla obslugi zamowien, klientow, produktow, numerow seryjnych, wysylek, faktur, e-maili oraz przyszlych integracji z zewnetrznymi uslugami.

## v0.1.1 - Fundament aplikacji - gotowe

- Przygotowanie dokumentacji projektowej.
- Ustalenie struktury modularnego monolitu.
- Utworzenie bazowego panelu Blade + Bootstrap 5.
- Przygotowanie miejsca na przyszle moduly i integracje.
- Brak implementacji logowania, bazy Orders i zewnetrznych API.

## v0.2.0 - Orders

- Model zamowien wspolny dla Allegro i PrestaShop.
- Produkty, pozycje zamowien i statusy.
- Migracje dla pierwotnego fundamentu klientow, adresow, zamowien, pozycji i historii zdarzen.
- Modele Eloquent z podstawowymi relacjami.
- Lista zamowien i widok szczegolow.
- Seeder z danymi testowymi dla zrodel Allegro i PrestaShop.

## v0.3.0 - Serial Numbers

- Numery seryjne sa osobnym modulem domenowym.
- Aktualny widok szczegolow ma kafelke Zarzadzanie jako miejsce na przyszle funkcje operacyjne.
- Tabela `serial_numbers` pozostaje nieuzywana i zarezerwowana na przyszlosc.
- Brak przypisywania numerow seryjnych do produktow lub `order_items`.

## v0.4.0 - Statusy i workflow zamowienia

- Statusy zamowien sa stale zdefiniowane w modelu `Order`.
- NEX-OMS uzywa tylko czterech statusow zamowienia: `new`, `pending`, `shipped`, `cancelled`.
- Nowe zamowienie ma domyslnie status `new`.
- Lista zamowien ma filtrowanie po statusie przez query string.
- Status jest prezentowany jako badge Bootstrap.
- Szczegoly zamowienia pozwalaja zmienic status przez formularz.
- Szybkie akcje pozwalaja oznaczyc zamowienie jako oczekujace, wyslane, anulowane albo przywrocic je jako nowe.
- Zmiana statusu zapisuje wpis w `order_events`.
- Dashboard pokazuje realne liczniki zamowien z bazy.

## v0.5.0 - Reczne dodawanie i edycja zamowien

- Panel pozwala recznie dodac zamowienie.
- Panel pozwala edytowac dane zamowienia.
- Kazde nowe zamowienie ma domyslnie status `new`.
- Dane kontaktowe kupujacego sa zapisywane bezposrednio w `orders`; osobna baza klientow nie jest uzywana.
- Adres dostawy jest zapisywany bezposrednio w polach `orders.shipping_*`; osobna baza adresow nie jest uzywana.
- Dane do faktury sa zapisywane bezposrednio w polach `orders.billing_*`.
- Dane do faktury zawieraja NIP w `orders.billing_tax_id`.
- Formularz obsluguje do 5 statycznych pozycji zamowienia.
- Kafelka Zarzadzanie pozostaje placeholderem na przyszle akcje.

## v0.5.1 - Inline editable order cards

- Szczegoly zamowienia sa pokazane jako karta operacyjna OMS.
- Produkty, informacje o zamowieniu, adres dostawy, dane do faktury i platnosc sa edytowalne sekcjami.
- Kazda sekcja zapisuje tylko swoje dane i dodaje wpis do `order_events`.
- W MVP produkty w interfejsie uzywaja tylko nazwy, ilosci i ceny.
- Pola SKU/EAN moga pozostac w bazie, ale nie sa pokazywane w formularzach ani tabelach produktow.

## v0.7.0 - InPost Paczkomaty

- Konfiguracja konta ShipX dla srodowiska sandbox albo produkcyjnego.
- Tworzenie przesylek InPost Paczkomaty z poziomu zamowienia.
- Obsluga gabarytow A, B i C, punktu docelowego, pobrania oraz ubezpieczenia.
- Asynchroniczne nadawanie i odswiezanie przesylek przez kolejki Laravela.
- Pobieranie etykiet PDF, ZPL albo EPL bez zapisywania pliku binarnego w bazie.
- Anulowanie przesylki, jezeli pozwala na to aktualny status ShipX.
- Historia przesylki, historia zamowienia i logowanie komunikacji request/response.
- Pozostale integracje kurierskie nadal sa tylko zaplanowanymi pozycjami.

## v0.7.1 - Stabilizacja integracji kurierskich

- Wspolny kontrakt i rejestr sterownikow kurierskich.
- Kontrolowane ponawianie blednych nadan bez ryzyka automatycznego duplikowania paczek.
- Osobny status dla niepewnego wyniku komunikacji z API.
- Pelniejsza historia zmian statusu przesylki w karcie zamowienia i panelu InPost.
- Neutralne zdarzenia domenowe dla przyszlego modulu Automatyczne akcje.
- Kolejny etap: operacje masowe, podjazdy kuriera, protokoly oraz wybor punktow z API.

## v0.8.0 - Automatyczne akcje

- Edytor regul oparty na zdarzeniu, warunkach i uporzadkowanych dzialaniach.
- Zdarzenia zmiany statusu zamowienia oraz utworzenia, zmiany statusu i bledu przesylki.
- Warunki zamowienia, platnosci, wysylki i przesylki.
- Dzialania: zmiana statusu zamowienia, utworzenie przesylki InPost, opoznienie i wywolanie URL metoda GET.
- Asynchroniczne wykonanie przez kolejke `automation`.
- Ochrona przed duplikatami i petlami regul.
- Historia wykonan zapisywana przy zamowieniu.

## v0.9.0 - DPD

- Konfiguracja pojedynczego konta DPD dla srodowiska demo albo produkcyjnego.
- Nadawanie krajowych przesylek DPD z jedna albo wieloma paczkami.
- Pobranie, wartosc deklarowana, doreczenie w sobote i zwrot dokumentow.
- Etykiety PDF, ZPL i EPL oraz link do trackingu DPD.
- Synchronizacja statusow przez DPD InfoServices i wspolny workflow przesylek OMS.
- Kolejki, limitowanie API, blokada rownoleglych operacji i logowanie request/response.
- Panel przesylek z filtrowaniem, paginacja i operacjami zbiorczymi.

## Etap 1 - Rdzen OMS

- Dalszy rozwoj statusow zamowien.
- Operacje manualne na zamowieniach.
- Podstawowe przeplywy pakowania i realizacji.

## Etap 2 - Operacje magazynowo-sprzedazowe

- Pakowanie zamowien.
- Obsluga wysylek.
- Fakturowanie.
- Historia zmian i podstawowe logi operacji.

## Etap 3 - Integracje

- Allegro.
- PrestaShop.
- InPost Paczkomaty - podstawowa integracja ShipX gotowa.
- Kurier InPost - podstawowa integracja ShipX gotowa.
- Allegro Wysylam - planowane.
- DPD - podstawowa integracja DPD Services i InfoServices gotowa.
- Fakturownia.
- SMTP.
- Logowanie request/response dla kazdego API.
- Kolejki dla operacji zewnetrznych.

## Etap 4 - Stabilizacja

- Testy kluczowych przeplywow.
- Role i uprawnienia.
- Monitorowanie bledow integracji.
- Procedury wdrozen na serwer testowy.
