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
- PayNow - konfiguracja, fundament danych i reczne linki platnosci gotowe.

## Zasady

- Kazda integracja powinna byc osobnym modulem lub podmodulem w `Modules/Integrations/`.
- Kazde zewnetrzne API powinno miec logowanie request/response.
- Operacje API docelowo powinny dzialac przez kolejki.
- Bledy komunikacji powinny byc widoczne w panelu.
- Dane zrodlowe z integracji powinny byc mapowane do wewnetrznych modeli NEX-OMS.

## VAT pozycji zamowien Allegro i PrestaShop

- Allegro: stawka pochodzi z historycznego `lineItems[].tax.rate` zamowienia, bez odczytu aktualnej oferty. Jawne zwolnienie lub nieprawidlowy VAT wymaga recznej weryfikacji i nie jest zastepowany katalogiem ani wartoscia domyslna.
- PrestaShop: stawka nadal jest wyliczana z historycznych cen netto/brutto pozycji. Przy braku ceny jednostkowej netto uzywamy sum netto/brutto calej pozycji, jesli sa dostepne. Brak danych netto oznacza brak stawki, a nie 0%. Nieprawidlowa cena nadal blokuje import.
- Jawne powiazanie produktu pozwala uzyc nazwy i VAT z OMS zgodnie z ustawieniami konta. Allegro z wlaczonym VAT katalogowym oraz tryb PrestaShop `catalog` preferuja poprawna stawke OMS, a przy jej braku zachowuja stawke kanalu. Tryby PrestaShop `store` i `fallback` zachowuja swoje pierwszenstwo kanalu.
- Kazde konto posiada opcjonalna `default_vat_rate` (0-100%, liczba calkowita), poczatkowo `null`. Puste pole w zakladce Zamowienia oznacza brak automatycznego uzupelniania. Domyslna stawka jest ostatnim fallbackiem po kanale i dozwolonym katalogu; jawne 0% nie jest brakiem.
- Katalogowy i domyslny VAT stosujemy tylko przy jawnych krajach dostawy i rozliczenia `PL`; Allegro wymaga dodatkowo marketplace `allegro-pl`. Zamowienie zagraniczne zachowuje VAT kanalu, bez krajowego fallbacku. To nie jest implementacja OSS ani automatycznej kwalifikacji podatkowej.
- Import nie zmienia cen brutto, ilosci ani wplat. Zdarzenie importu zawiera `payload.vat_sources` (stawka kanalu, wynik, pochodzenie i status zrodla); zastosowanie domyslnej stawki ma tez czytelny opis w historii.
- Brak backfillu: zmiana ustawien nie uzupelnia VAT zakonczonych importow ani wystawionych dokumentow. Dotychczasowe uzupelnianie jawnych powiazan produktow PrestaShop pozostaje, ale nie stosuje nowej stawki domyslnej do starych pozycji. Niepelne zakupy Allegro nadal moga otrzymac kompletne dane podczas ich standardowego dokonczenia.
- Migracja `2026_10_25_010000_add_default_vat_rate_to_integration_accounts` dodaje tylko dwie kolumny nullable; nie zapisuje VAT w historycznych pozycjach i nie wlacza zadnej wartosci domyslnej.

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
- asynchroniczne utworzenie przesylki przez kolejke `shipments-actions`,
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

Integracja korzysta z tego samego klienta ShipX, logow API i kolejek przesylek co InPost Paczkomaty, ale ma osobna konfiguracje konta i formularz przesylki. Obsluguje:

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
- asynchroniczne nadanie przez kolejke `shipments-actions`,
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
- asynchroniczne nadanie i anulowanie przez komendy Shipment Management oraz kolejke `shipments-actions`,
- pobieranie etykiet PDF lub ZPL,
- zbiorcze odswiezanie i usuwanie przesylek w panelu operacyjnym,
- wykorzystanie przewoznika w ogolnej akcji automatycznej `Utworz przesylke`,
- szyfrowane przechowywanie access tokenu, refresh tokenu i Client Secret,
- automatyczne odnawianie tokenu OAuth oraz logowanie operacji bez zapisywania tokenow, device code ani Client Secret w logach.

Formularz jest dostepny tylko dla zamowien ze zrodlem `allegro` i uzupelnionym `external_id`, ktory jest identyfikatorem zamowienia Allegro. Nadawca, odbiorca i dostepne opcje sa pobierane z propozycji Allegro, dlatego NEX-OMS nie utrzymuje lokalnej kopii katalogu metod dostawy. Integracja nie importuje zamowien z Allegro.

Allegro realizuje utworzenie i anulowanie asynchronicznie. NEX-OMS zapisuje identyfikator komendy i sprawdza jej wynik w kolejce. Brak jednoznacznego wyniku po wyczerpaniu prob jest oznaczany jako `creation_unknown`, aby nie doprowadzic do przypadkowego podwojnego nadania.

## Uruchomienie operacyjne

- Operacje wywolane przez uzytkownika, takie jak tworzenie i anulowanie przesylek, korzystaja z kolejki `shipments-actions`.
- Okresowe i reczne odswiezanie statusow korzysta z osobnej kolejki `shipments-sync`, aby synchronizacja nie blokowala nadawania przesylek.
- Dla obu kolejek powinny dzialac osobne workery: `php artisan queue:work --queue=shipments-actions` oraz `php artisan queue:work --queue=shipments-sync`.
- Kolejka `integrations` obsluguje juz importy zamowien Allegro i PrestaShop, synchronizacje oraz eksporty marketplace; operacje kurierskie pozostaja na osobnych kolejkach przesylek.
- Laravel Scheduler powinien byc uruchamiany co minute przez cron albo `php artisan schedule:work` w srodowisku lokalnym.
- Przed pierwszym nadaniem nalezy zapisac konfiguracje i wykonac test polaczenia w panelu odpowiedniej integracji: `InPost Paczkomaty`, `InPost Kurier`, `DPD` albo `Wysylam z Allegro`.

Aktualny staging korzysta z jednego systemowego crona i siedmiu workerow Supervisor. Nie uruchamiac dodatkowego schedulera ani starych cronow importu/odswiezania obok niego. Pelna mapa zadan, aktywne retry_after, limity i ograniczenia idempotencji sa opisane w [dokumencie operacyjnym VPS](007-Queues-Scheduler-VPS.md); PayNow i Latarnia wykonuja czesc HTTP bezposrednio w schedulerze, a nie w jobach.

## PayNow

Etap PAYNOW.1 udostepnia panel jednego logicznego konta PayNow, srodowiska Sandbox i Produkcja, szyfrowane klucze, ustawienia przyszlych linkow oraz podpisany test polaczenia v3. Test pobiera liste metod platnosci dla 1,00 PLN i nie tworzy transakcji. Aktywacja wymaga poprawnego testu biezacych danych dostepowych.

Tabele `paynow_accounts`, `paynow_payments` i `paynow_payment_events` stanowia fundament wielu wplat i doplat do zamowienia oraz niezmiennej historii zdarzen dostawcy.

Etap PAYNOW.2 dodaje reczne generowanie linku dla pozostalej kwoty zamowienia w PLN. Operacja jest wykonywana przez AJAX, a wazny link o tych samych parametrach jest ponownie uzywany. Pojedyncze klikniecie kopiuje link, podwojne otwiera go w nowej karcie. URL i odpowiedz dostawcy sa szyfrowane, historia zamowienia nie przechowuje linku, a bledy nie ujawniaja surowej odpowiedzi PayNow.

Webhook, odswiezanie statusu, ksiegowanie na zamowieniu, historia zarejestrowanych platnosci i automatyczne akcje pozostaja poza PAYNOW.2.

Etap PAYNOW.3 dodaje podpisany webhook, odpornosc na duplikaty i zdarzenia poza kolejnoscia oraz jednokrotne ksiegowanie potwierdzonej wplaty na zamowieniu. Wplata czesciowa aktualizuje zaplacona kwote, a pelna oznacza zamowienie jako oplacone. Nadplata, konflikt identyfikatorow, niezgodna waluta albo brak zamowienia wymagaja sprawdzenia i nie zmieniaja salda.

W `Informacjach dodatkowych` zamowienia znajduje sie sekcja `Platnosci`. Awaryjne sprawdzanie statusu dziala co minute przez Laravel Scheduler i uzywa tego samego mechanizmu ksiegowania co webhook. Adres powiadomien jest widoczny w ustawieniach PayNow; na VPS musi byc publicznym adresem HTTPS. Zwroty, chargebacki, reczne dopasowywanie osieroconych platnosci i automatyczne akcje platnicze pozostaja poza tym etapem.

## Etap obecny

Gotowe sa MVP InPost Paczkomaty, InPost Kurier, DPD i Wysylam z Allegro oraz panel operacyjny pojedynczego konta dla kazdej z tych integracji. Sekcja zlecen odbioru jest przygotowana w interfejsie, ale samo zamawianie podjazdu kuriera nie jest jeszcze zaimplementowane. Kolejny etap obejmuje podjazdy kuriera, protokoly, wybor punktu z API oraz webhooki statusow. Nie zaimplementowano jeszcze importu zamowien z Allegro ani PrestaShop.
