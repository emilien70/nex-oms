# Integracje

## Zakres

Planowane integracje:

- Allegro.
- PrestaShop.
- InPost Paczkomaty - podstawowa integracja ShipX gotowa.
- Kurier InPost - podstawowa integracja ShipX gotowa.
- Wysylam z Allegro - podstawowa integracja Shipment Management gotowa.
- DPD - podstawowa integracja DPD Services i InfoServices gotowa.
- Fakturownia.
- SMTP.

## Zasady

- Kazda integracja powinna byc osobnym modulem lub podmodulem w `Modules/Integrations/`.
- Kazde zewnetrzne API powinno miec logowanie request/response.
- Operacje API docelowo powinny dzialac przez kolejki.
- Bledy komunikacji powinny byc widoczne w panelu.
- Dane zrodlowe z integracji powinny byc mapowane do wewnetrznych modeli NEX-OMS.

## Standard panelu integracji kurierskiej

Widok `InPost Kurier` jest wzorcem UX dla kolejnych integracji kurierskich. W miare mozliwosci nowy panel powinien zachowywac nastepujacy uklad:

- wyszukiwanie zaawansowane i filtry w zwijanym panelu,
- tabele utworzonych przesylek z checkboxami, akcjami zbiorczymi i wspolna paginacja NEX-OMS,
- szczegoly przesylki dostepne bez przechodzenia do zamowienia,
- sekcje zlecen odbioru lub innych operacji przewoznika, jesli sa wspierane,
- pojedyncza sekcje konta API z edycja w kompaktowym oknie modalnym,
- dodatkowe ustawienia przewoznika, takie jak szablony wymiarow i wag, umieszczone pod kontem API,
- spojne komunikaty bledow, status polaczenia i test polaczenia.

Wspolny uklad nie oznacza identycznego zestawu pol. Uslugi, parametry paczki, dodatkowe operacje, etykiety i wymagania walidacyjne musza odpowiadac mozliwosciom API konkretnego kuriera. Nieobslugiwane funkcje powinny byc pomijane zamiast prezentowane jako niedzialajace kontrolki.

## InPost Paczkomaty

Integracja korzysta z API ShipX i obsluguje:

- osobna konfiguracje sandbox/production,
- test polaczenia z organizacja ShipX,
- nadanie przesylki Paczkomaty 24/7 z gabarytem A, B albo C,
- punkt docelowy, pobranie i ubezpieczenie,
- asynchroniczne utworzenie przesylki przez kolejke `integrations`,
- okresowe odswiezanie statusu przez Laravel Scheduler,
- pobranie etykiety PDF, ZPL albo EPL,
- anulowanie tylko w statusach, w ktorych ShipX pozwala anulowac przesylke,
- logowanie request/response bez zapisywania tokenu API.
- panel operacyjny z filtrowaniem przesylek, paginacja i operacjami odswiezania,
- pojedyncze konto API edytowane w oknie konfiguracji,
- dane nadawcy przypisane do konta,
- domyslny sposob nadania: Paczkomat nadawczy albo odbior przez kuriera,
- opis zawartosci budowany z numeru zamowienia, loginu, e-maila albo telefonu kupujacego.
- osobny sterownik `InPostLockerDriver` obslugiwany przez `CourierDriverRegistry`,
- blokade ponowienia po `creation_failed` i `creation_unknown` oraz komunikat wymagajacy sprawdzenia panelu InPost,
- historie zmian statusu z czasem zwroconym przez przewoznika,
- mapowanie statusow ShipX na siedem wspolnych statusow operacyjnych OMS,
- zdarzenia domenowe przygotowane dla przyszlych automatycznych akcji.

Konfiguracja moze byc zapisana w tabeli `courier_accounts`. Zmienne `INPOST_API_TOKEN` i `INPOST_ORGANIZATION_ID` stanowia bezpieczny fallback srodowiskowy. Adresy produkcyjny i sandboxowy oraz timeout sa konfigurowane w `config/services.php`.

Tworzenie przesylki nie jest ponawiane automatycznie na poziomie klienta HTTP, aby timeout nie spowodowal przypadkowego podwojnego nadania. Zarowno `creation_failed`, jak i `creation_unknown` wymagaja sprawdzenia panelu InPost przed dalszym dzialaniem i sa prezentowane jako `Wystapil problem`.

## Kurier InPost

Integracja korzysta z tego samego klienta ShipX, logow API i kolejki `integrations` co InPost Paczkomaty, ale ma osobna konfiguracje konta i formularz przesylki. Obsluguje:

- usluge standardowa oraz doreczenie Express do 10:00, 12:00 i 17:00,
- jedna lub wiele podpaczek z waga, wymiarami i oznaczeniem elementu niestandardowego,
- pobranie oraz ubezpieczenie; przy pobraniu ubezpieczenie musi byc co najmniej rowne kwocie pobrania,
- powiadomienie SMS i e-mail, doreczenie w sobote oraz zwrot dokumentow,
- konfiguracje domyslnych parametrow paczki, uslugi, opisu zawartosci, etykiety i danych nadawcy,
- asynchroniczne nadanie, odswiezanie statusu, etykiete, tracking i anulowanie przez osobny `InPostCourierDriver`,
- mapowanie statusow na wspolny workflow przesylek OMS,
- zdarzenia domenowe dostepne dla warunkow automatycznych akcji.

Wymiary sa wpisywane w panelu w centymetrach i mapowane do milimetrow wymaganego payloadu ShipX. Waga jest podawana w kilogramach, a pojedyncza podpaczka jest walidowana do 50 kg.

## DPD

Integracja DPD korzysta z dwoch oficjalnych interfejsow przewoznika:

- DPD Services REST do tworzenia krajowych przesylek i pobierania etykiet,
- DPD InfoServices SOAP do okresowego pobierania ostatniego zdarzenia przesylki.

Panel DPD obsluguje jedno konto API, srodowisko demo albo produkcyjne, test polaczenia, dane nadawcy i domyslne parametry paczki. Konfiguracja wymaga loginu, hasla, Master FID oraz kanalu InfoServices. Dane dostepowe sa przechowywane w `courier_accounts`, a haslo korzysta z szyfrowanego pola `api_token`. Zmienne `DPD_API_LOGIN`, `DPD_API_PASSWORD`, `DPD_MASTER_FID` i `DPD_INFO_CHANNEL` sa bezpiecznym fallbackiem srodowiskowym.

Z poziomu zamowienia dostepne sa:

- przesylka krajowa standardowa, DPD Next Day oraz doreczenie do 09:30 lub 12:00,
- jedna albo wiele paczek z waga i wymiarami,
- pobranie, wartosc deklarowana, doreczenie w sobote i zwrot dokumentow,
- asynchroniczne nadanie przez kolejke `integrations`,
- pobranie etykiety PDF, ZPL albo EPL,
- tracking na stronie DPD,
- reczne i zaplanowane odswiezanie statusow co 60 minut,
- zbiorcze odswiezanie i lokalne usuwanie przesylek z panelu.

Surowe kody zdarzen DPD sa mapowane na wspolne statusy przesylek OMS. Przesylki doreczone i zwrocone nie sa ponownie synchronizowane. Zapytania sa limitowane, a rownolegle zadania dla tej samej przesylki sa blokowane. DPD Services nie udostepnia w uzywanym kontrakcie bezpiecznej operacji anulowania utworzonej przesylki, dlatego usuniecie z NEX-OMS jest lokalnym fallbackiem i nie anuluje paczki u przewoznika.

## Wysylam z Allegro

Integracja korzysta z aktualnego zasobu Allegro Shipment Management i obsluguje:

- pojedyncze konto OAuth typu Device w srodowisku produkcyjnym albo testowym,
- polaczenie konta przyciskiem `Polacz z Allegro`, kodem uzytkownika i automatycznym pollingiem zgodnym z interwalem Allegro,
- zasieg `allegro:api:shipments:read` oraz `allegro:api:shipments:write`,
- pobranie propozycji dostawy dla zamowienia Allegro dopiero po otwarciu formularza przewoznika,
- utworzenie od jednej do dziesieciu paczek w ramach propozycji dostawy,
- konfigurowalne szablony wag i wymiarow, wybierane osobno dla kazdej paczki w formularzu zamowienia,
- pobranie, ubezpieczenie i uslugi dodatkowe udostepnione przez Allegro dla konkretnego zamowienia,
- asynchroniczne nadanie i anulowanie przez komendy Shipment Management oraz kolejke `integrations`,
- pobieranie etykiet PDF lub ZPL,
- zbiorcze odswiezanie i usuwanie przesylek w panelu operacyjnym,
- wykorzystanie przewoznika w ogolnej akcji automatycznej `Utworz przesylke`,
- szyfrowane przechowywanie access tokenu, refresh tokenu i Client Secret,
- automatyczne odnawianie tokenu OAuth oraz logowanie operacji bez zapisywania tokenow, device code ani Client Secret w logach.

Formularz jest dostepny tylko dla zamowien ze zrodlem `allegro` i uzupelnionym `external_id`, ktory jest identyfikatorem zamowienia Allegro. Nadawca, odbiorca i dostepne opcje sa pobierane z propozycji Allegro, dlatego NEX-OMS nie utrzymuje lokalnej kopii katalogu metod dostawy. Integracja nie importuje zamowien z Allegro.

Allegro realizuje utworzenie i anulowanie asynchronicznie. NEX-OMS zapisuje identyfikator komendy i sprawdza jej wynik w kolejce. Brak jednoznacznego wyniku po wyczerpaniu prob jest oznaczany jako `creation_unknown`, aby nie doprowadzic do przypadkowego podwojnego nadania.

## Uruchomienie operacyjne

- Worker kolejki powinien obslugiwac kolejke `integrations`.
- Laravel Scheduler powinien byc uruchamiany co minute przez cron albo `php artisan schedule:work` w srodowisku lokalnym.
- Przed pierwszym nadaniem nalezy zapisac konfiguracje i wykonac test polaczenia w panelu odpowiedniej integracji: `InPost Paczkomaty`, `InPost Kurier`, `DPD` albo `Wysylam z Allegro`.

## Etap obecny

Gotowe sa MVP InPost Paczkomaty, InPost Kurier, DPD i Wysylam z Allegro oraz panel operacyjny pojedynczego konta dla kazdej z tych integracji. Sekcja zlecen odbioru jest przygotowana w interfejsie, ale samo zamawianie podjazdu kuriera nie jest jeszcze zaimplementowane. Kolejny etap obejmuje podjazdy kuriera, protokoly, wybor punktu z API oraz webhooki statusow. Nie zaimplementowano jeszcze importu zamowien z Allegro ani PrestaShop.
