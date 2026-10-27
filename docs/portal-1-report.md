# PORTAL.1 - strona informacyjna klienta

## Zakres

Odczyt zamowienia dowolnego zrodla przez `/zamowienie/<32-znakowy-kod>`.
Publiczne pola: etap i osobny komunikat, pozycje z zamowienia, kwoty,
platnosc, sposob dostawy, wiele przesylek, sledzenie, dozwolone PDF,
opcjonalne zdjecia/adres oraz kontakt i tekst sprzedawcy. Brak edycji,
platnosci, formularza wiadomosci, API integracji i automatyzacji na GET.
Brak surowych notatek sprzedawcy, wewnetrznych opisow statusow i SKU/EAN.

Domyslnie: udostepnianie wylaczone, waznosc 90 dni od daty zakupu
(awaryjnie od utworzenia), adres ukryty, faktury i zamkniete korekty
dozwolone, pro formy ukryte. Obowiazuja rowniez reguly dokumentow ponizej.
Wydluzenie polityki nie przedluza juz wydanego linku; skrocenie moze go
od razu uniewaznic. Zmiana linku jest swiadoma operacja. Ponowne wlaczenie
calego portalu przywraca nadal wazne, nieodwolane linki.

## Panel

**Strony informacyjne** w menu ustawien: wlaczenie, marka, prywatne logo,
kolor, okres dostepnosci, kontakt, tekst, widoczne sekcje oraz mapowanie
aktualnych statusow OMS na osobne nazwy i opisy klienta. Nowy status nie
jest automatycznie publikowany. Zapis ma kontrole wersji ustawien.
Logo: JPEG/PNG/WebP, do 2 MB i 2400 x 2400 px; brak SVG i zdalnych URL.

Na karcie zamowienia menu **Strona klienta** udostepnia podglad,
utworzenie/skopiowanie linku, zmiane kodu i wylaczenie linku.
Zmiana/wylaczenie wymagaja potwierdzenia i aktualnej generacji linku.
Podglad administratora dziala bez wlaczania portalu i bez tworzenia tokenu.

## Bezpieczenstwo

Trzy nowe tabele: customer_portal_settings, customer_portal_statuses,
customer_portal_links. Brak backfillu i zmian dokumentow/zamowien.
FK sa nullable z nullOnDelete; nazwy ograniczen sa jawne i krotsze od
64 znakow. Down odmawia usuniecia niepustej konfiguracji/historii linkow.

Kod losowy 192 bity, skrot SHA-256 do wyszukiwania, szyfrowany token do
ponownego skopiowania, osobny HMAC tozsamosci zamowienia/kupujacego.
Tokeny i skroty sa ukryte przy serializacji modeli linkow. Zamrozona tresc
e-maila zawiera jednak URL; historia wiadomosci i kopie bazy musza pozostac
prywatne. Zmiana kupujacego,
wygasniecie, odwolanie, kosz i usuniecie zamowienia blokuja odczyt.
Kazdy PDF i obraz ponownie wymaga waznego tokenu i przynaleznosci zasobu
do tego zamowienia. Query string nie wybiera zamowienia ani celu.
Niepowodzenie zwraca neutralny komunikat, bez szczegolow zamowienia.

Odpowiedzi: no-store, no-referrer, noindex, nosniff, DENY; HTML ma CSP
z nonce, bez skryptow i obcych zasobow. Limit 180 zadan/minute/IP obejmuje
obrazy i PDF. Ochrona administracyjna jest zgodna z obecna aplikacja:
HTTPS i Basic Auth reverse proxy, POST/PUT z CSRF. Nie dodano logowania
Laravel. Bez prawidlowego zabezpieczenia reverse proxy nie nalezy
publikowac panelu ani podgladow administratora.

## Dokumenty

Tylko wystawione dokumenty z numerem. Korekta musi byc zamknieta i nalezec
do tego zamowienia. Pro forma wymaga osobnego wlaczenia i nie moze byc
zastapiona Faktura. Zasady i obliczenia dokumentow nie sa zmieniane.

Historia Online/Offline lub aktywna seria KSeF dokumentu/faktury pierwotnej
blokuja zwykly PDF, dopoki nie ma poprawnej akceptacji. Publiczny portal
wymaga Production. DEMO/TEST moga wystapic tylko w podgladzie panelu.
Online korzysta z obecnego finalnego renderera i prezentera KSeF bez
ponownego uzycia starego podgladu PDF. Offline tylko po akceptacji tej
samej emisji, przez istniejacy serwis finalnego PDF z kontrola integralnosci.
Brak udostepniania Offline przed akceptacja lub automatycznej finalizacji.

## Wiadomosci

`[link_zamowienia]` jest nowa opcjonalna zmienna. Podglad bez wydanego linku
pokazuje bezpieczny adres zastepczy, ktory nie daje dostepu do danych.
Przy przygotowaniu wysylki link powstaje wewnatrz transakcji wiadomosci,
tylko dla adresu kupujacego sprawdzonego ponownie pod blokada aktualnego
zamowienia, takze gdy przekazany model jest nieaktualny. Nieudane
przygotowanie wycofuje nowy token.
Starszy podglad zawierajacy zmieniony token jest odrzucany. Powtorzenie
tej samej intencji wiadomosci zachowuje dotychczasowa idempotencje.
Istniejace szablony i automatyzacje nie sa samoczynnie zmieniane.

## Weryfikacja reczna

1. Otworz Strony informacyjne i podglad wybranego zamowienia; nie wlaczaj
   portalu tylko po to, aby sprawdzic wyglad.
2. Po swiadomej konfiguracji ustaw publiczne nazwy statusow i wlacz portal.
3. Utworz link zamowienia i otworz go w oknie prywatnym. Sprawdz kwoty,
   przesylki, brak notatek wewnetrznych i dokumenty.
4. Zmien link. Poprzedni adres oraz jego PDF/obrazy musza przestac dzialac.
5. Wylacz link, a potem caly portal; sprawdz neutralna odmowe dostepu.
6. Dodaj zmienna do osobnego szablonu testowego i sprawdz podglad.
   Nie wysylaj wiadomosci do rzeczywistych klientow podczas testu.

## Pliki

Utworzone:

- Modules/CustomerPortal/Http/Controllers/PortalController.php
- Modules/CustomerPortal/Http/Controllers/PortalSettingsController.php
- Modules/CustomerPortal/Models/PortalSetting.php
- Modules/CustomerPortal/Models/PortalStatus.php
- Modules/CustomerPortal/Models/PortalLink.php
- Modules/CustomerPortal/Services/PortalAccessService.php
- Modules/CustomerPortal/Services/PortalDocumentService.php
- Modules/CustomerPortal/Services/PortalPresentationService.php
- database/migrations/2026_10_26_040000_create_customer_portal_tables.php
- resources/views/customer-portal/show.blade.php
- resources/views/customer-portal/order-actions.blade.php
- resources/views/settings/customer-portal/edit.blade.php
- tests/Feature/CustomerPortalTest.php
- docs/portal-1-report.md

Zmienione:

- app/Providers/AppServiceProvider.php
- app/Services/OrderEmailPreparationService.php
- app/Services/OrderVariableService.php
- routes/web.php
- resources/views/layouts/app.blade.php
- resources/views/orders/show.blade.php
- docs/product-spec.md
- docs/architecture.md
- docs/006-Deployment.md

Nie usunieto plikow. Pomocnicze wyniki QA i kopie pozostaja prywatne
w ignorowanym storage/app/private, poza commitem.

## Kontrola lokalna

`C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --no-output --log-junit storage/app/private/qa/portal-tests.xml tests/Feature/CustomerPortalTest.php`:
41 testow, 458 asercji w koncowym przebiegu ukierunkowanym, 0 bledow
i niepowodzen, SQLite :memory:, HTTP
zablokowane bez jawnego fake i kolejka fake. Zakres obejmuje wszystkie
zrodla, brak migracji, tokeny i odwolanie, wygasanie, zakres dokumentow
i obrazow, CSRF, limity, pro formy, KSeF Production vs DEMO, odmowe
Offline bez zgodnej akceptacji, email preview, rollback i idempotencje.

PHP lint: 14 plikow PASS. Pint --test dla zmienionych plikow PHP: PASS.
git diff --check: PASS. Playwright/System Edge: 1280, 390 i 320 px,
poprawnie zaladowany obraz, brak poziomego overflow i kolizji w kontrolach;
sprawdzono screenshoty szerokiego i mobilnego widoku. Dane wizualne sa
odseparowana fikcyjna probka, nie zamowieniem rzeczywistego klienta.

Koncowe testy ukierunkowane po dodatkowej kontroli aktualnego odbiorcy:

```text
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --no-output --log-junit storage/app/private/qa/portal-final-focused.xml --filter "CustomerPortal|PayNow|OrderEmail|EmailTemplateSettings|SettingsVariables|InvoicePdf|ProformaService|Ksef.*(Pdf|Presentation)|AllegroInvoiceDocument"
```

455 testow, 3753 asercje, 0 bledow i niepowodzen (w tym PORTAL.1).
Ponownie sprawdzono Pint --test i PHP lint ostatnich zmienionych plikow.

Pelny zestaw uruchomiono przed ostatnim zaostrzeniem kontroli odbiorcy:
4382 testy, 40561 asercji, 0 bledow wykonania, 72 niepowodzenia, 0 pominiec,
czas 1416 s. Raport lokalny: storage/app/private/qa/portal-final-regression.xml.
Bez wiarygodnego pelnego baseline HEAD nie deklarujemy, ze wszystkie
niepowodzenia byly wczesniejsze, ani pelnego braku regresji. Nie poprawiano
innych modulow; wynik nie stanowi potwierdzenia gotowosci wdrozenia.

Niepowodzenia wedlug klasy testowej:

| Klasa (Tests/Feature) | Liczba |
| --- | ---: |
| Invoices/InvoiceCorrectionTest | 1 |
| Invoices/InvoiceListTest | 2 |
| Invoices/SalesRegisterHttpTest | 1 |
| Ksef/KsefAutomaticInvoiceSubmissionTest | 4 |
| Ksef/KsefManualCorrectionSubmissionTest | 1 |
| Ksef/KsefOfflineCertificateRemoteVerificationTest | 4 |
| Ksef/KsefSubmissionRecoveryConcurrencyTest | 2 |
| Ksef/KsefSubmissionRecoveryTest | 13 |
| Ksef/KsefSubmissionTransactionBoundaryTest | 40 |
| OrderIdentifiersTest | 2 |
| OrderStatusPollingTest | 1 |
| Products/OrderCatalogProductTest | 1 |

Panel ustawien i podglad lokalny: HTTP 200, 5 aktualnych statusow OMS,
przelacznik udostepniania wylaczony, brak poziomego overflow podgladu przy
390 px. Zablokowano wszystkie zadania przegladarki inne niz GET/HEAD;
nieistniejacy kod dal 404. Pelna ocena wizualna panelu z Bootstrap jest
ograniczona: srodowisko przegladarki testowej nie moglo pobrac zewnetrznych
styli CDN. Publiczna strona ma wlasne style i zostala oceniona wizualnie.

## Lokalna kopia i migracja

Za zgoda wlasciciela wykonano kopie SQLite przez VACUUM INTO, sprawdzono
PRAGMA integrity_check = ok i SHA-256:

```text
storage/app/private/backups/customer-portal-20261026_193330-ac76f8b4.sqlite
a600d103d71b4245c4c335acb5b1301d8449f7ab2d6370403624489fb6d950ab
```

Uruchomiono tylko:

```text
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_26_040000_create_customer_portal_tables.php --force --no-interaction
```

Migracja DONE, zapis w migrations potwierdzony, trzy tabele puste,
udostepnianie false. Natychmiastowe porownanie sum kontrolnych zamowien,
pozycji, dokumentow, serii, licznikow, platnosci i kont integracji:
bez zmian. Pozniejsza kontrola po podgladzie wykazala tylko normalne
znaczniki czasu dzialajacych synchronizacji: allegro_accounts
updated_at/last_order_scan_at/returns_last_scan_at i prestashop_accounts
updated_at/last_order_import_scan_at/last_payment_sync_at. Nie cofano ich.
Nie zmienily sie dane finansowe ani ustawienia kont. Nie utworzono linku
klienta ani wiadomosci. Wlasciciel potwierdzil restart lokalnych Main Queues.

Przelacznik adresu dotyczy sekcji strony, a nie anonimizacji PDF.
Dokument zachowuje oryginalne snapshoty, w tym informacje wybrane podczas
wystawiania; portal nie redaguje dokumentow finansowych.

Konfiguracja Nginx i migracja: [wdrozenie](006-Deployment.md#strony-informacyjne-klienta---portal1).
Nie wykonano wdrozenia ani zmian VPS; brak testu rzeczywistego Nginx
i MySQL 8. Test MySQL sprawdza wygenerowany SQL, bez polaczenia z serwerem.
PORTAL.1 pozostaje lokalnie niezacommitowany. Wczesniejsze prace zapisano
i wyslano przed jego rozpoczeciem jako b695189 zgodnie z osobna zgoda.
