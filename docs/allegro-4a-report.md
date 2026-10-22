# ALLEGRO.4A - raport wdrozenia

Data: 2026-10-22. Zakres: odczyt zwrotow klienckich Allegro, lista i karta zwrotu w stylistyce zamowien OMS.

## Wykonane zmiany

- Zamowienia -> Zwroty otwiera widok Wszystkie zwroty pod /orders/returns.
- Lista: domyslnie 20 pozycji, wybor rozmiaru i paginacja, wyszukiwanie oraz filtry statusu, konta i daty.
- Karta: produkty, ilosci i ceny, powody i komentarze, dane klienta, status, adres pierwotnej dostawy, dokumenty i wiadomosci zamowienia oraz historia zmian zwrotu.
- Osobne tabele Przesylki (zamowienie) i Przesylki zwrotne. Pierwsza zawiera wszystkie znane numery paczek wyslanych klientowi, w tym aliasy przewoznikow i dodatkowe paczki.
- Karta zamowienia pokazuje odnosniki do powiazanych zwrotow.
- Integracje -> Allegro -> Zwroty: automat domyslnie wylaczony, data poczatkowa domyslnie dzisiaj. Wlasciciel sam wybiera date i wlacza odczyt. Zapis ustawien nie wykonuje HTTP.
- Synchronizacja co okolo 5 minut, z czasem kolejki. Do 100 znanych, naleznych zwrotow na konto jest kolejkowanych w kazdym przebiegu minutowym. Odswiezane sa takze zwroty zakonczone.
- Tozsamosc environment/seller/external_return_id zapobiega duplikatom. Kilka zwrotow do tego samego zamowienia pozostaje oddzielnych.
- Zwrot bez zamowienia pozostaje widoczny; relacja jest uzupelniana przy kolejnym odczycie. Dopasowanie produktu jest wylacznie jednoznaczne i zrodlowe.
- Dokladne obliczenia dziesietne bez float. Wartosc produktow nie jest prezentowana jako wykonany zwrot pieniedzy; refundacja ma oznaczenie Brak danych.
- Bledy API zachowuja poprzednie dane. Nieznane statusy pozostaja widoczne. Historia zawiera tylko zmiany zaobserwowane przez OMS, po 20 wpisow na strone.
- Rozlaczenie lub zmiana konta wylaczaja odczyt, ale nie kasuja historii. Relacje historyczne uzywaja nullOnDelete.
- Nie dodano uprawnien OAuth ani zaleznosci. Wszystkie nowe odczyty i odnowienia tokenu zachowuja wymagany User-Agent.

Nie uruchamiano rzeczywistego pobierania zwrotow z konta Allegro. Automat pozostaje wylaczony. Wlasciciel potwierdzil restart Main Queues w trakcie wdrozenia.

## Zmienione pliki

Utworzone:
- `database/migrations/2026_10_22_010000_add_allegro_customer_returns.php`
- `app/Models/OrderReturn.php`
- `app/Models/OrderReturnItem.php`
- `app/Models/OrderReturnParcel.php`
- `app/Models/OrderReturnEvent.php`
- `app/Http/Controllers/OrderReturnController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroReturnSettingsController.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroReturnSettingsRequest.php`
- `Modules/Integrations/Allegro/Jobs/DiscoverAllegroReturnsJob.php`
- `Modules/Integrations/Allegro/Jobs/SyncAllegroReturnJob.php`
- `Modules/Integrations/Allegro/Services/AllegroReturnNormalizer.php`
- `Modules/Integrations/Allegro/Services/AllegroReturnImportService.php`
- `Modules/Integrations/Allegro/Services/AllegroReturnSyncService.php`
- `resources/views/integrations/allegro/_returns.blade.php`
- `resources/views/returns/index.blade.php`
- `resources/views/returns/show.blade.php`
- `resources/views/returns/_styles.blade.php`
- `tests/Support/AllegroReturnFixture.php`
- `tests/Unit/AllegroReturnNormalizerTest.php`
- `tests/Feature/AllegroReturnsTest.php`
- `docs/allegro-4a-report.md`

Zmienione:
- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `app/Http/Controllers/OrdersController.php`
- `app/Models/Order.php`
- `routes/web.php`
- `routes/console.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/orders/show.blade.php`
- `resources/views/integrations/allegro/edit.blade.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete: brak. Nie wykonywano commita, pusha ani innych operacji zapisu Git. Nie zmieniano .env, README, zaleznosci ani plikow lock.

## Migracje

Addytywna migracja `2026_10_22_010000_add_allegro_customer_returns` zostala uruchomiona, batch 96. Dodaje konfiguracje konta i cztery nowe tabele; nie przelicza ani nie usuwa zamowien i dokumentow.

Wykonano:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_22_010000_add_allegro_customer_returns.php --force
```

Bezpieczne sprawdzenie lub wykonanie na kolejnym srodowisku:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_22_010000_add_allegro_customer_returns.php --no-interaction
```

## Testy

Istniejacy izolowany runner wymusza SQLite :memory:, prywatne storage/logi/cache/temp i blokade rzeczywistej sieci. Testy nie korzystaja z roboczej bazy.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=AllegroReturn
```

Wynik koncowy nowych testow: 38 testow, 149 asercji, wszystkie poprawne. Obejmuja konfiguracje, walidacje, deduplikacje, pieniadze, stronicowanie API i UI, pozne powiazanie zamowienia, dane historyczne po usunieciu relacji, bledy i zmiane konfiguracji podczas HTTP, nieznany status, oryginalne i zwrotne paczki oraz escapowanie tresci.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=Allegro
```

Koncowa regresja Allegro po wszystkich zmianach: 381 testow, 2247 asercji, wszystkie poprawne; czas 43,56 s. Obejmuje 38 nowych przypadkow zwrotow i dotychczasowa integracje.

Uruchomiono rowniez pelny zestaw projektu:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php
```

Pelny zestaw nie jest poprawny. Odczytany raport zawieral 68 niepowodzen w 9 klasach poza nowymi testami Allegro. Po zakonczeniu wykonywania testow zatrzymano proces podczas wielominutowego, obciazajacego CPU formatowania bardzo duzego bledu OrderIdentifiersTest; brak koncowego podsumowania liczby poprawnych testow i asercji. Nie przedstawiamy tego przebiegu jako pelnej pozytywnej weryfikacji projektu.

Zaobserwowane grupy problemow:
- `Invoices/InvoiceListTest` (2 przypadki) i `Invoices/SalesRegisterHttpTest` (1): generowanie linku orders.show bez wymaganego zamowienia dla dokumentu bez relacji.
- `Ksef/KsefAutomaticInvoiceSubmissionTest` (4), `Ksef/KsefManualCorrectionSubmissionTest` (1), `Ksef/KsefSubmissionRecoveryConcurrencyTest` (2), `Ksef/KsefSubmissionRecoveryTest` (13) i `Ksef/KsefSubmissionTransactionBoundaryTest` (40): testy oczekuja pustej kolejki, ale wykrywaja EvaluateAutomationEventJob.
- `Ksef/KsefOfflineCertificateRemoteVerificationTest` (4): niespelniona gotowosc certyfikatu; widoczne w fixture daty waznosci koncza sie 01.10.2026, przed aktualna data.
- `OrderIdentifiersTest` (1): oczekiwanie starego fragmentu HTML prezentacji zrodla PrestaShop.

Powyzsze sciezki faktur, KSeF, automatyzacji i prezentacji PrestaShop nie byly zmieniane w ALLEGRO.4A. Nie rozszerzano zadania o ich naprawy. Wynik pelnego przebiegu wymaga osobnego uporzadkowania testow i analizy; nie wykonano porownawczego uruchomienia na innej wersji repozytorium.

Pint --test dla 24 zmienionych plikow PHP: poprawny. Kontrola tras, harmonogramu i statusu migracji: poprawna. git diff --check: bez bledow; ostrzezenie o istniejacej normalizacji CRLF w product-spec.md.

## Weryfikacja reczna

1. Otworz Zamowienia -> Zwroty: naglowek Wszystkie zwroty, domyslnie 20 pozycji, dzialajace filtry i paginacja.
2. Otworz Integracje -> Allegro -> Zwroty. Sprawdz wylaczony przelacznik i date poczatkowa. Samo otwarcie strony nie pobiera zwrotow.
3. Aby zaczac odczyt rzeczywistych danych, wybierz pozadana date, wlacz automat i zapisz. Nie wymaga to nowego scope ani ponownego laczenia konta. Scheduler i Main Queues musza dzialac.
4. Po przebiegu kolejki otworz zwrot. Porownaj numer, status, produkty, ilosci, ceny, powody i komentarz z Allegro. Otworz powiazane zamowienie i dokument.
5. W Przesylki (zamowienie) sprawdz numer paczki wyslanej klientowi oraz dodatkowe paczki; w Przesylki zwrotne sprawdz osobny numer Allegro i przewoznika doreczajacego, jezeli sa dostepne.
6. Powtorne pobranie nie powinno tworzyc drugiej pozycji. Inny zwrot do tego samego zamowienia powinien byc odrebny. Brak zamowienia w OMS nie moze ukrywac zgloszenia.
7. Sprawdz aktualizacje po zmianie statusu w Allegro. Stan zamowienia, dokumenty i stan magazynowy nie powinny zmienic sie z powodu importu zwrotu.
8. Wylacz automat, jezeli odczyt ma byc zatrzymany. Dotychczasowe zwroty i historia pozostaja dostepne.

W przegladarce sprawdzono dzialajaca pusta liste i ustawienia oraz liste/filtry przy szerokosci 390 px. Karta na danych testowych przeszla test odpowiedzi HTTP, powiazan, numerow paczek i bezpiecznej tresci. Zasady przegladarki zablokowaly otwarcie lokalnego pliku podgladu, dlatego nie wykonano jej wizualnej kontroli na danych testowych; nalezy ja dokonczyc po pierwszym rzeczywistym imporcie.

## Ograniczenia i ryzyka

- Endpoint zwrotow ma obecnie kontrakt beta. Wymagany Accept beta jest osobny od pozostalych operacji Allegro. Zmiana kontraktu moze wymagac aktualizacji; niepoprawne dane sa odrzucane bez nadpisania zapisanych rekordow.
- Nie wykonano rzeczywistego GET zwrotow na koncie wlasciciela. Pierwszy import jest kontrolowanym testem integracyjnym.
- Nie ma refundacji, zwrotu prowizji, automatycznej Korekty, zmiany magazynu, akceptacji/odrzucenia zwrotu, tworzenia przesylki ani wysylki wiadomosci. Nie pobieramy danych bankowych i nie prezentujemy domniemanej kwoty refundacji.
- Status jest odczytem Allegro, a data jego zaobserwowania pochodzi z OMS. API nie dostarcza tu pelnej historii dawnych zmian.
- Dla duzej historii ponowny przeglad wybranego okresu i odswiezanie znanych rekordow wydluzaja czas aktualizacji. Nie usuwamy starych zgloszen tylko dlatego, ze nie znalazly sie na kolejnej stronie API.
- Lokalne logowanie i uprawnienia calego OMS pozostaja osobnym etapem. Nie rozszerzano zabezpieczen calej aplikacji przy tej funkcji.

## Zrodla

- [Allegro: obsluga zamowien i zwrotow](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR#zwroty-klienckie)
- [Allegro: aktualny kontrakt OpenAPI](https://developer.allegro.pl/swagger.yaml)
- [Base: zwroty](https://base.com/pl-PL/pomoc/wiedza/zwroty/)

Kontrakt API oraz powody zwrotow sprawdzono 22.10.2026. Base sluzy jako odniesienie organizacji widoku; karta zachowuje konwencje OMS i rzeczywiscie wdrozony zakres, bez kopiowania nieobslugiwanych operacji.
