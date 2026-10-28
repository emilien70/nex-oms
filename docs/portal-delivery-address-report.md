# Portal - maskowanie adresow, telefon i punkt odbioru

## Zakres

Implementacja lokalna z 2026-10-28. Bez zmian na VPS, commit, push,
migracji i operacji na roboczej bazie. Zachowano ustawienia pokazywania
adresow, maskowania, weryfikacji dokumentow i platnosci.

## Zachowanie

- Maskowanie nazw pozostawia pierwsza litere kazdego czlonu, w tym
  polskie litery i czlony oddzielone lacznikiem: np. M**** J****.
  Reszta czlonu to stale cztery gwiazdki, bez ujawniania jego dlugosci.
- Dotyczy imienia/nazwiska, firmy, ulicy, miasta i identyfikacji punktu.
  Czlony zaczynajace sie cyfra sa ukryte w calosci. Kod pocztowy ma
  maske ******, numery budynku/lokalu i NIP ****. Kraj pozostaje widoczny.
- Telefon dostawy pochodzi z shipping_phone, a przy braku rozpoznawalnego
  numeru z customer_phone. Uzywa istniejacego PhoneNumberFormatter.
  Przed weryfikacja pokazuje prefiks i koncowe trzy cyfry, np. +48 ******060.
  Krotki numer jest zakryty calkowicie; pusty/niepoprawny nie tworzy wiersza.
  Po weryfikacji widac pelny numer, a poprawny numer ma bezpieczny link tel.
  W danych nabywcy nie dodano telefonu.
- Wystarczy niepuste pole nazwy, ID, adresu, kodu lub miasta punktu,
  aby adres dostawy pochodzil tylko z kafli Odbior w punkcie. Biale znaki
  nie sa danymi; tekstowe ID 0 nie jest odrzucane jako puste.
- Punkt pokazuje dostepna nazwe, ID, adres, kod/miasto oraz customer_phone.
  Brakujace dane nie sa uzupelniane nazwiskiem, firma, numerem mieszkania,
  krajem ani telefonem z domowego adresu dostawy. Dane nabywcy sa osobno.
- Przy wlaczonym maskowaniu punkt i telefon sa zakrywane na serwerze.
  Pelny numer nie trafia przed weryfikacja do HTML, ukrytego href ani DTO.
  Wylaczenie maskowania lub podglad operatora zachowuje dotychczasowy
  dostep do pelnych danych. Brak pustych wierszy adresu.
- Obecna weryfikacja e-maila/nazwiska/firmy nie zostala zmieniona.
  Publiczna nazwa/ID punktu nie odblokowuje danych ani dokumentow.
- Zmiana punktu uniewaznia weryfikacje, rowniez po powrocie do poprzednich
  wartosci. URL i kod pozostaja stale. Rollback zapisu cofa takze rewizje.
  Zmiana definicji odcisku moze jednorazowo wymagac ponownej weryfikacji
  w istniejacych sesjach klientow; nie wymaga nowego linku.
- Stary formularz PayNow po zmianie punktu jest odrzucany istniejacym
  mechanizmem generation. Nie zmieniono wyliczen, ksiegowania ani API.

## Pliki

Zmienione:

- app/Models/Order.php - pickupPointData() zgodne z istniejacymi getterami adresow.
- Modules/CustomerPortal/Services/PortalPresentationService.php - wybor danych,
  maskowanie Unicode, kraj, telefon i brak pelnego numeru w maskowanym DTO.
- Modules/CustomerPortal/Services/PortalAccessService.php - punkt w tozsamosci weryfikacji.
- Modules/CustomerPortal/Services/PortalVerificationService.php - punkt w odcisku sesji.
- resources/views/customer-portal/show.blade.php - dostepne wiersze, kraj, ID i telefon.
- tests/Feature/CustomerPortalPaymentsTest.php - ochrona starej intencji po zmianie punktu.
- docs/product-spec.md i docs/architecture.md - uzgodnione zachowanie i mechanizm.

Nowe: tests/Feature/CustomerPortalDeliveryAddressTest.php i ten raport.
Nie usunieto plikow.

## Migracje

Brak nowej migracji. Nie zmieniano zadnych zapisanych zamowien, punktow,
faktur, wplat ani konfiguracji. Nie wykonano lokalnej synchronizacji.
Po zatwierdzeniu potrzebny jest tylko restart lokalnych Main Queues,
aby procesy importu wczytaly nowy getter modelu i odcisk weryfikacji.
VPS nie byl aktualizowany i nie nalezy go restartowac w tym etapie.

## Testy

Sprawdzona konfiguracja testowa: SQLite :memory:, prywatny storage/cache,
zablokowane transporty sieciowe, fake HTTP i kolejki. Bez roboczej bazy.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit '--filter=CustomerPortalDeliveryAddressTest|CustomerPortalPrivacyTest|CustomerPortalPermanentLinksTest|CustomerPortalPaymentsTest|CustomerPortalLandingTest|CustomerPortalAutomaticLinksTest' --log-junit=storage/app/private/qa/portal-delivery-regression.xml
```

Wynik: 236 testow, 1798 asercji, 0 failures/errors/skipped.
Obejmuje maskowanie, polskie litery/laczniki, telefony/fallback, wszystkie
zrodla zamowien, niepelny punkt, ID 0, podglad administracyjny, brak wycieku
danych, ponowna weryfikacje, staly adres, rollback i intencje PayNow.
Testow KSeF ani pelnej regresji systemu nie uruchamiano.
Pint --test, php -l dla wszystkich siedmiu zmienionych/nowych plikow PHP
(w tym Blade) i git diff --check: PASS. Ostrzezenie CRLF w product-spec
nie jest bledem kontroli roznic.

## Weryfikacja reczna

1. Przy wlaczonym pokazywaniu adresow otworzyc lokalny link zamowienia
   w prywatnym oknie. Sprawdzic inicjaly, calkowicie zakryty kod, kraj
   i czesciowy telefon bez aktywnego odsylacza do pelnego numeru.
2. Wybrac Wyswietl pelne dane, podac aktualny e-mail lub nazwisko/ firme:
   adres i numer maja byc pelne, a poprawny telefon klikalny.
3. W testowym zamowieniu uzupelnic kafle Odbior w punkcie. W Adresie
   dostawy portalu maja zostac tylko te dane i telefon klienta.
4. Sprawdzic punkt niepelny oraz puste dane - bez mieszania z adresem domowym.
5. Zmienic dane punktu i odswiezyc portal: ponowna weryfikacja bez wymiany URL.
6. Sprawdzic waski ekran: wiersze zawijaja sie bez nakladania tekstu.

Nie wykonywano fizycznego testu na telefonie ani transmisji do operatorow.
Ustawienia ukrywania adresow nadal maja pierwszenstwo. Istniejace zasady
weryfikacji, PDF i PayNow pozostaja bez zmian; podglad operatora nie maskuje.
