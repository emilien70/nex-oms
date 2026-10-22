# ALLEGRO.4B.1 - raport wdrozenia

Data: 22.10.2026.

## Wykonane zmiany

- Odczyt refundacji Allegro przez GET /payments/refunds, niezalezny od zgloszen zwrotu towaru.
- Osobne ustawienia Zwroty wplat w Integracje -> Allegro -> Zwroty, domyslnie wylaczone, z data poczatkowa i cyklem okolo 5 minut.
- Historia refundacji w platnosciach zamowienia oraz Historia platnosci zwrotu na karcie zwrotu, z paginacja po 20 pozycji.
- Jawne lokalne przypisanie potwierdzonej kwoty do karty zwrotu: osobno produkty i dostawa, bez automatycznego przypisywania.
- Ochrona przed przypisaniem tych samych srodkow do kilku kart, przekroczeniem wartosci produktow i nadpisaniem zmian z innego okna.
- Historia zmian przypisania i zachowanie danych po usunieciu powiazanego zamowienia, konta lub karty zwrotu.
- Ikona dolara i podsumowanie korzystaja z potwierdzonych przypisan; nie udaja przelewu na podstawie wartosci produktow.
- Bledy API nie zeruja zapisanych kwot. Zmiana rzeczywistego statusu lub limitow pozostawia historie i wymusza sprawdzenie niespojnego przypisania.
- Obowiazkowy User-Agent jest zachowany, rowniez po odnowieniu OAuth po 401.

Nie dodano wysylania pieniedzy, payments:write, POST refundacji, Korekt, operacji magazynowych ani zwrotu prowizji. Nie zmieniono orders.paid_amount i statusu platnosci. Decyzja o faktycznym zwrocie kosztu dostawy nalezy do ALLEGRO.4B.2; obecne pola rozdzielaja juz wykonane refundacje przy ich lokalnym przypisaniu.

## Stan lokalnego wdrozenia

- Migracja zostala wykonana, batch 99.
- Main Queues: restart potwierdzony przez wlasciciela.
- Konto posiada juz allegro:api:payments:read; nie wymaga ponownego laczenia dla tego odczytu.
- Pobieranie zwrotow towarowych pozostaje wlaczone jak przed zadaniem.
- Nowe pobieranie refundacji pozostaje wylaczone; nie wykonano rzeczywistego odczytu refundacji ani przelewu.
- Kontrola koncowa: 20 kart zwrotu, 1 konto z wlaczonym importem zwrotow, 0 kont z wlaczonym importem refundacji, 0 refundacji i 0 przypisan.
- SQLite quick_check: ok; foreign_key_check: 0 naruszen.

## Zmienione pliki

### Utworzone

- `Modules/Integrations/Allegro/Http/Controllers/AllegroRefundSettingsController.php`
- `Modules/Integrations/Allegro/Jobs/DiscoverAllegroRefundsJob.php`
- `Modules/Integrations/Allegro/Jobs/SyncAllegroRefundJob.php`
- `Modules/Integrations/Allegro/Models/AllegroPaymentRefund.php`
- `Modules/Integrations/Allegro/Models/AllegroPaymentRefundEvent.php`
- `Modules/Integrations/Allegro/Services/AllegroRefundImportService.php`
- `Modules/Integrations/Allegro/Services/AllegroRefundNormalizer.php`
- `Modules/Integrations/Allegro/Services/AllegroRefundSyncService.php`
- `app/Http/Controllers/OrderReturnRefundController.php`
- `app/Models/OrderReturnRefundAllocation.php`
- `app/Services/OrderReturnRefundService.php`
- `database/migrations/2026_10_22_030000_add_allegro_payment_refunds.php`
- `resources/views/integrations/allegro/_refunds.blade.php`
- `resources/views/orders/partials/refunds.blade.php`
- `resources/views/returns/_refunds.blade.php`
- `tests/Feature/AllegroRefundsTest.php`
- `tests/Support/AllegroRefundFixture.php`
- `tests/Unit/AllegroRefundNormalizerTest.php`
- `docs/allegro-4b1-report.md`

### Zmodyfikowane

- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Models/OrderReturn.php`
- `app/Models/OrderReturnEvent.php`
- `app/Services/OrderReturnService.php`
- `config/services.php`
- `resources/views/integrations/allegro/_returns.blade.php`
- `resources/views/orders/partials/payments.blade.php`
- `resources/views/returns/index.blade.php`
- `resources/views/returns/show.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/AllegroSettingsTest.php`
- `docs/product-spec.md`
- `docs/architecture.md`

### Usuniete

Brak. Nie zmieniano .env, README, lockfile ani zaleznosci. Nie wykonano commita ani pusha.

## Migracja i kopia bazy

Nowa migracja: `2026_10_22_030000_add_allegro_payment_refunds`.

Przed wykonaniem utworzono spojna kopie SQLite przez VACUUM INTO:

```text
storage/app/private/backups/database_before_allegro_4b1_20261022_154359.sqlite
SHA-256: 2ee122cccf4bd7af025719aa67c62d7853aab6d822c02c16b128e5059d3ebd88
```

Migracja dodaje nowe tabele oraz pola bez kasowania danych. Wykonana lokalnie; ponowne uruchomienie standardowej komendy nie powtarza wykonanej migracji:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_22_030000_add_allegro_payment_refunds.php
```

Nie ma automatycznego down usuwajacego historie finansowa. Nie wykonano rollback, migrate:fresh, reset ani wipe.

## Testy

Przed uruchomieniem sprawdzono izolowany runner: SQLite :memory:, prywatne sciezki testowe i blokada rzeczywistego HTTP. Testy nie korzystaly z roboczej bazy.

Nowy obszar:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=AllegroRefund --stop-on-failure
```

Wynik: **49 passed, 222 assertions**.

Szersza weryfikacja po koncowych zmianach:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="Allegro|OrderReturnsTest|OrdersAjaxListTest|OrderSavedViewsTest|OrderStatusSettingsTest|OrderStatusServiceTest" --stop-on-failure
```

Wynik: **474 passed, 2794 assertions**, 42,52 s.

Pierwszy szeroki przebieg zatrzymal sie na oczekiwaniu starej listy scope w AllegroSettingsTest. Oczekiwanie zaktualizowano o opcjonalne payments:read; powtorzony pelny powyzszy zakres zakonczyl sie bez bledow.

Pint --test dla 26 nowych/zmienionych plikow PHP: **passed**. Nie formatowano calego repozytorium. Nie uruchamiano pelnego zestawu aplikacji; znane niezalezne problemy w innych obszarach pozostaja poza zakresem, patrz docs/allegro-4a-report.md.

Pokryto m.in. idempotencje, milisekundy i strefy dat, stronicowanie 100+1, niepelna/bledna strone API, zmiane konfiguracji w trakcie HTTP, brak scope, 401 i User-Agent, sanitacje logow, niezalezne kwoty produktow/dostawy, roznice groszowe, kilka kart i refundacji, niepotwierdzone statusy, bledy odczytu, zmiane realnej kwoty, optimistic lock, reczna karte Allegro po usunieciu zamowienia, historyczne relacje i escapowanie komentarza.

## Weryfikacja w przegladarce

Sprawdzono bez zapisywania danych roboczych:

- Karte zwrotu 20: podsumowanie Brak danych, rozwijana historia i prawidlowy pusty stan.
- Karte zamowienia 213: po otwarciu Platnosci widoczna sekcja Zwroty wplat Allegro z poprawnym pustym stanem.
- Ustawienia Allegro -> Zwroty: nowa sekcja Zwroty wplat, poprawny wymagany scope i wylaczony przelacznik, bez zmiany istniejacego importu zwrotow towarowych.
- Mobilny viewport 390x844: sekcja historii miesci sie w kolumnie; brak poziomego przewijania calej strony. Istniejace tabele przesylek zachowuja wewnetrzne przewijanie.
- Przywrocono rozmiar przegladarki po kontroli.

Formularz z rzeczywistymi refundacjami jest pokryty testami HTML, ale nie zostal obejrzany z prawdziwymi kwotami w roboczej bazie, poniewaz import pozostaje wylaczony. Nie wstawiano fikcyjnych danych finansowych.

## Weryfikacja reczna po wlaczeniu

1. Otworz Integracje -> Allegro -> Zwroty i sekcje Zwroty wplat.
2. Wybierz date poczatkowa, wlacz Pobieraj zwroty wplat i zapisz. Data dotyczy refundacji, nie daty zakupu. Zapis sam nie wykonuje API.
3. Po pracy harmonogramu i Main Queues otworz znane zamowienie z refundacja Allegro. W platnosciach sprawdz status, kwote zlecenia oraz rozbicie produktow/dostawy.
4. Otworz powiazana karte zwrotu i Historia platnosci zwrotu. Sama obecnosc refundacji nie ma automatycznie zwiekszac Zwrocono srodki.
5. Dla SUCCESS ze znanym podzialem rozwin Przypisanie do zwrotu, wpisz produkty i osobno dostawe (mozna pozostawic 0.00), zapisz. Operacja jest lokalna i nie zleca przelewu.
6. Sprawdz podsumowanie, ikone dolara na liscie i historie zmiany przypisania. Przy kilku kartach suma przypisan nie moze przekroczyc osobnych pul refundacji.
7. Otworz te sama karte w dwoch oknach. Zapis w pierwszym powinien uniemozliwic nadpisanie przez nieaktualny formularz w drugim.
8. Wyzeruj przypisanie, gdy potrzebna jest korekta lokalnego powiazania; historia pozostaje, a pieniadze nie sa wysylane ponownie.

## Ograniczenia i ryzyka

- Pierwszy odczyt rzeczywistego GET /payments/refunds pozostaje do wykonania po jawnym wlaczeniu pobierania. Zadnego testowego przelewu nie wykonano.
- PARTIAL i nieznane statusy nie potwierdzaja calej kwoty. Nieustalony podzial lub doplaty surcharges zachowuja kwote zlecenia, ale blokuja przypisanie domniemanej kwoty produktow.
- Refundacje bez order.id sa zachowane w bazie i nie sa zgadywane po payment.id. Ten etap nie dodaje osobnej listy administracyjnej niepowiazanych refundacji.
- Historia pokazuje stany zaobserwowane przez OMS, nie pelna historie Allegro sprzed wlaczenia importu. createdAt oznacza zlecenie, nie date rzeczywistego przelewu.
- Pelny przeglad zakresu dat i odswiezanie znanych refundacji generuja odczyty API proporcjonalne do historii; szeroka data poczatkowa wydluza pierwszy cykl.
- Kwoty pozostalych skladnikow nie sa przypisywane do produktow lub dostawy na sile. Wykonanie refundacji, w tym decyzja o zwrocie wysylki, to nastepny ALLEGRO.4B.2.
- Uprawnienia uzytkownikow i logowanie calego lokalnego OMS pozostaja osobnym etapem przed udostepnieniem systemu publicznie.

## Zrodla kontraktu

Sprawdzone 22.10.2026, wylacznie oficjalne materialy Allegro:

- [Obsluga zamowien i zwroty wplat](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR)
- [Swagger API Allegro](https://developer.allegro.pl/swagger.yaml)
- [Nowe pola zwrotow platnosci](https://developer.allegro.pl/news/zwroty-platnosci-do-kupujacych-wprowadzamy-obsluge-nowych-pol-b2mRL9zG2u6)
