# PORTAL.2A - automatyczne aktywne linki zamowien

## Zachowanie

- Po utworzeniu zamowienia observer czeka na zatwierdzenie transakcji,
  odczytuje koncowe dane i przygotowuje jeden link.
- Model nowej konfiguracji ma is_enabled=true. Juz zapisane false
  pozostaje bez zmian. Prawa platnicze i ustawienia PayNow sa zachowane.
- Gotowy niebieski adres oraz szara etykieta i podpowiedz znajduja sie
  na dole kafelki Zarzadzanie. Usunieto przycisk Strona klienta i jego menu.
- Odnowienie/wylaczenie jest dostepne w Ustawienia -> Strony informacyjne
  po wybraniu numeru zamowienia. Te akcje nadal wymagaja CSRF,
  potwierdzenia i oczekiwanej generacji linku.
- Podglady i GET nie tworza ustawien ani linkow. Zmienna [link_zamowienia]
  korzysta z gotowego adresu; dla historycznego braku pozostaje dotychczasowy
  odczytowy placeholder, dopoki operator nie wykona uzupelnienia.

## Bezpieczenstwo i granice

Zwykla aktualizacja statusu, kwoty lub kolejny odczyt nie zmienia kodu.
Provisioning i uzupelnianie nie modyfikuja istniejacych rekordow linkow,
takze wygaslych, odwolanych i niezgodnych z nabywca. Nowy kod zawsze
wymaga swiadomej akcji operatora w ustawieniach. Aktualna ochrona
tozsamosci, waznosci, kosza, usuniecia oraz reguly udostepniania PDF zostaja.

Zmiana nabywcy po utworzeniu zamowienia uniewaznia dotychczasowy link,
rowniez przy uzupelnianiu pustego zamowienia recznego. Widok pokazuje
powod zamiast klikalnego nieaktywnego adresu. Operator moze odnowic link
po zakonczeniu wprowadzania danych. Nie poluzowano tej ochrony.

Nie ma dodatkowej kolejki, crona ani migracji. Blad przygotowania linku
po commit jest bezpiecznie logowany bez sekretow i nie cofa zamowienia.
Brakujacy link moze byc pozniej uzupelniony jawna komenda.

## Pliki etapu

Nowe:

- Modules/CustomerPortal/Observers/PortalOrderObserver.php
- app/Console/Commands/PrepareCustomerPortalLinks.php
- resources/views/customer-portal/order-link.blade.php
- tests/Feature/CustomerPortalAutomaticLinksTest.php
- docs/portal-auto-links-report.md

Zmienione:

- Modules/CustomerPortal/Models/PortalSetting.php
- Modules/CustomerPortal/Services/PortalAccessService.php
- Modules/CustomerPortal/Http/Controllers/PortalSettingsController.php
- app/Providers/AppServiceProvider.php
- resources/views/orders/show.blade.php
- resources/views/settings/customer-portal/edit.blade.php
- tests/Feature/CustomerPortalTest.php
- tests/Feature/CustomerPortalPaymentsTest.php
- docs/product-spec.md
- docs/architecture.md
- docs/006-Deployment.md

Usuniete:

- resources/views/customer-portal/order-actions.blade.php

## Uruchomienie

Po kopii bazy, przegladzie i swiadomej zgodzie operatora:

```bash
php artisan customer-portal:prepare-links --dry-run
php artisan customer-portal:prepare-links --enable
```

Bez --enable istniejace globalne wylaczenie pozostaje zachowane.
Dry-run jest odczytowy nawet z --enable. Zadna komenda nie wysyla
wiadomosci, nie ksieguje platnosci i nie odnawia istniejacych linkow.

### Uruchomienie lokalne 2026-10-27

Wykonano za zgoda wlasciciela po testach:

- kopia SQLite: storage/app/private/backups/portal-auto-20261027_100940-0d2c1b85.sqlite;
- SHA256: 8d4cda71612ebb682b87b41e42445df0f6e2058b750d7185daa3bc8dd8281559;
- PRAGMA integrity_check kopii i bazy: ok;
- customer-portal:prepare-links --enable: 280 nowych linkow, 282 lacznie,
  0 brakujacych linkow zamowien poza koszem;
- 2 istniejace rekordy linkow pozostaly identyczne;
- porownanie sum kontrolnych zamowien, pozycji, dokumentow, serii,
  licznikow, konta i historii PayNow: bez zmian;
- dotychczasowe ustawienia portalu poza jawnym wlaczeniem/revision
  pozostaly zachowane, w szczegolnosci ustawienia platnosci;
- portal byl juz wlaczony przed komenda; --enable nie zmienil go ponownie;
- wlasciciel potwierdzil restart tylko lokalnych Main Queues.

Nie uruchamiano migracji, schedulera ani operacji na VPS.

## Weryfikacja reczna

1. Otworz zamowienie: na dole Zarzadzania jest etykieta, znak zapytania
   i gotowy adres, bez przycisku Strona klienta.
2. Otworz adres w nowej karcie: strona dziala bez osobnego wlaczania.
3. Zmien status zamowienia i odswiez: adres pozostaje ten sam.
4. Wybierz zamowienie w ustawieniach stron, wylacz link i sprawdz odmowe.
5. Utworz kolejne zamowienie: wylaczony stary link nie wraca do aktywnosci.
6. Odnow link w ustawieniach: nowy dziala, stary pozostaje nieprawidlowy.
7. Wylacz globalnie portal i utworz zamowienie: portal pozostaje wylaczony.
8. Sprawdz telefon oraz podpowiedz ikony myszka/klawiatura.

## Testy zakresu

- CustomerPortalAutomaticLinksTest: 20 przypadkow, wszystkie uwzglednione
  w koncowym przebiegu powiazanym.
- Powiazany zestaw Portal/PayNow/e-mail/PDF: 530 testow, 4240 asercji,
  0 niepowodzen i bledow. SQLite :memory:, siec zablokowana, API fake.
- PHP lint 9 plikow i Pint --test: PASS. git diff --check: PASS.
- Edge/Playwright: aktywny, globalnie wylaczony i odwolany link przy
  1280, 390 i 320 px. Bez overflow. Niebieski adres rgb(0,117,201),
  font-weight 400, pod etykieta; nieaktywny adres nie jest klikalny.
- DOM pelnego widoku zamowienia potwierdza umieszczenie w Zarzadzaniu
  i brak starego przycisku/menu. Testy ustawien obejmuja odwolanie,
  swiadome odnowienie, konflikt generacji i zachowanie ustawien platnosci.

Komenda zakresu:

```powershell
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'CustomerPortal|PayNow|OrderEmail|EmailTemplateSettings|SettingsVariables|InvoicePdf|ProformaService|Ksef.*(Pdf|Presentation)|AllegroInvoiceDocument'
```

Ocena wizualna jest izolowana probka rzeczywistego partiala Blade
i stylow widoku, a nie pelnym testem Bootstrap z CDN. Ikona i zaawansowany
tooltip wymagaja prawidlowego zaladowania juz uzywanych Bootstrap/Icons.
Podpowiedz ma rowniez natywny title i etykiete dostepnosci.

Dodatkowa kontrola partii na SQLite :memory:: 205 historycznych zamowien,
chunk 100, pierwszy przebieg utworzyl 205 linkow, drugi 0; istniejacy
odwolany link nie zostal zmieniony.

Kontrola lokalnego zamowienia 192: GET karty 200, jeden gotowy adres,
brak starej kontrolki, font-weight 400; GET strony klienta 200.
Przegladarka dopuszczala tylko odczyty i zasoby, blokowala operacje
zapisujace; nie wykonano zadnego POST. Bootstrap z CDN nie byl dostepny
w srodowisku QA, wiec nie deklarujemy pelnej wizualnej kontroli CDN.

### Pelna regresja

Przebieg wykonano na osobnej zamrozonej kopii, z SQLite :memory: i siecia
zablokowana. PHP 8.2.12, SQLite 3.39.2, Laravel 12.63.0, identyczne
11332 pliki zaleznosci jak w wczesniejszym przebiegu PORTAL.2.

| Wariant | Testy | Asercje | Niepowodzenia | Bledy wykonania |
| --- | ---: | ---: | ---: | ---: |
| PORTAL.2, p1b-214636/b | 4437 | 40924 | 72 | 0 |
| Automatyczne linki, p1b-094116/b | 4457 | 41050 | 72 | 0 |

Porownanie konkretnych testow: 0 nowych niepowodzen, 72 wspolne,
0 ustapionych. Dodano 20 przypadkow; dodatkowo dotychczasowy test
domyslnie wylaczonego portalu zostal przemianowany i dostosowany do
zatwierdzonej domyslnej aktywnosci (technicznie 21 dodanych ID i 1 usuniete
ID testu). Zadnego niepowodzenia nie usunieto przez usuniecie testu.
Wszystkie nowe identyfikatory testow sa PASS.

Szesc roznic surowych opisow niepowodzen przejrzano: wyjatki i miejsca
asercji pozostaly te same; roznia sie cache widokow, tokeny, czasy fixture
i uzgodniona prezentacja karty zamowienia. Nie naprawiano niepowodzen
innych modulow; pelny zestaw nadal nie jest zielony.

Kod PHP/Blade podczas porownania byl identyczny z testowana kopia.
Po zamrozeniu uzupelniano tylko dokumentacje. Prywatne artefakty:
storage/app/private/qa/portal-auto-comparison.json, JUnit w katalogach
przebiegow oraz portal-auto-visual/results.json i local-results.json.
Pelny przebieg: portal-baseline-tools/run.ps1 -Variant b -Label full;
porownanie: portal-2-compare.php ze sciezkami obu kopii.

## Ograniczenia

Nie zmieniano VPS, nie wysylano wiadomosci ani rzeczywistych platnosci.
Testy bazy sa odseparowane od danych uzytkownika. Rzeczywista wspolbieznosc
MySQL i konfiguracja Nginx wymagaja osobnej weryfikacji wdrozeniowej.
