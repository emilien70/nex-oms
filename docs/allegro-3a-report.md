# ALLEGRO.3A - raport wdrozenia

Data: 2026-10-21.

## Wykonane zmiany

- Nowa zakladka "Statusy zamowien" w istniejacej konfiguracji Allegro, z ukladem wierszy OMS -> Allegro.
- Lista statusow, ich nazwy i kolejnosc pochodza z konfiguracji OMS. Nie sa zaszyte w integracji.
- Wszystkie mapowania domyslnie "Nie przekazuj". Zaden status nie jest zablokowany, takze "Nowe". Nowy status OMS zaczyna bez mapowania.
- Globalne przekazywanie domyslnie wylaczone. Zapis konfiguracji ani wlaczenie nie wysyla historycznych zamowien.
- Przyszle zmiany reczne, zbiorcze i automatyczne korzystaja z istniejacego centralnego zdarzenia i kolejki integrations, po zatwierdzeniu transakcji.
- Zadania sprawdzaja konto, srodowisko, sprzedawce, aktualny status OMS, wersje mapowania i aktualny checkout-form. Stare i niebezpieczne zadania sa pomijane.
- API otrzymuje aktualna revision oraz tylko status realizacji. Powtorzenia sa idempotentne; konflikt wymaga kolejnego odczytu, nie slepego ponowienia PUT.
- Szczegoly zamowienia pokazuja stan i blad przekazywania oraz operacje ponowienia. Sukces i blad trafiaja do istniejacej historii zmian.
- Kazde zadanie oraz ponowienie po 401 zachowuje zatwierdzony User-Agent.

Stan roboczej bazy po wdrozeniu: przekazywanie wylaczone, zero zapisanych mapowan. Nie wykonano rzeczywistego PUT do Allegro ani testowej zmiany zamowienia uzytkownika. Istniejacego importu automatycznego nie wylaczano. Main Queues zostalo zrestartowane przez wlasciciela; panel potwierdzil nowy start 2026-10-21 09:50:21 czasu lokalnego.

## Zmienione pliki

Utworzone:

- `database/migrations/2026_10_21_010000_add_allegro_outbound_status_sync.php`
- `Modules/Integrations/Allegro/Enums/AllegroFulfillmentStatus.php`
- `Modules/Integrations/Allegro/Models/AllegroOutboundStatusMapping.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroStatusMappingsRequest.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroStatusMappingController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroStatusSyncController.php`
- `Modules/Integrations/Allegro/Services/AllegroStatusMappingService.php`
- `Modules/Integrations/Allegro/Services/AllegroOutboundStatusSyncService.php`
- `Modules/Integrations/Allegro/Listeners/DispatchAllegroStatusSync.php`
- `Modules/Integrations/Allegro/Jobs/SyncAllegroOrderStatusJob.php`
- `resources/views/integrations/allegro/_statuses.blade.php`
- `tests/Feature/AllegroStatusMappingsTest.php`
- `tests/Feature/AllegroOutboundStatusSyncTest.php`
- `docs/allegro-3a-report.md`

Zmodyfikowane w tym etapie (istniejace wczesniejsze zmiany zachowano):

- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Models/AllegroOrderLink.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Integrations/Allegro/Services/AllegroOrderSyncService.php` - udostepnienie istniejacej operacji anulowania, bez zmiany jej regul.
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `resources/views/integrations/allegro/edit.blade.php`
- `resources/views/orders/partials/allegro-details.blade.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete pliki: brak. Nie zmieniano .env, plikow lock ani zaleznosci. Nie wykonano commita ani pusha.

## Migracje

Migracja `2026_10_21_010000_add_allegro_outbound_status_sync` zostala uruchomiona w lokalnej bazie. Dodaje konfiguracje konta, tabele mapowan i nullable stan eksportu na powiazaniu zamowienia. Nie kasuje ani nie przebudowuje danych zamowien i dokumentow. Domyslne ustawienia nie uruchamiaja eksportu.

Bezpieczna komenda dla innego srodowiska (pomija juz wykonana migracje):

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_21_010000_add_allegro_outbound_status_sync.php --force
```

## Testy

Sprawdzono konfiguracje: SQLite :memory:, bez dostepu do roboczej bazy. Wszystkie wywolania Allegro sa symulowane, niezdefiniowane zadania HTTP blokowane.

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroStatusMappingsTest|AllegroOutboundStatusSyncTest"
C:\xampp\php\php.exe artisan test --compact --filter="(Allegro[^:]*Test|PrestashopOutboundStatusSyncTest|OrderStatusServiceTest|OrdersAjaxListTest|Automation[^:]*Test)::"
```

Wyniki koncowe:

- Nowe testy ALLEGRO.3A: 25 poprawnych, 242 asercje.
- Caly wskazany zestaw integracji i powiazanych mechanizmow: 305 poprawnych, 1866 asercji, 19.83 s.
- Laravel Pint dla nowych klas, klienta API, migracji i testow: poprawnie.
- `git diff --check` dla zmienianego obszaru: brak bledow bialych znakow; jedynie informacja Git o normalizacji CRLF/LF dokumentacji.

Testy obejmuja m.in. dynamiczne i usuniete statusy, domyslne wartosci, brak blokady statusu poczatkowego, walidacje, wersjonowanie konfiguracji, brak backfillu, afterCommit i rollback, niepelne/anulowane zakupy, zmiane tozsamosci, nowe zdarzenie o tym samym statusie, zmiane podczas GET, aktualny cel bez PUT, konflikt revision, 401 i User-Agent, bledy stale/przejsciowe, ponowienia oraz ochrone zamowien i historii przy usuwaniu konfiguracji.

Szerszy poczatkowy filtr `Allegro|PrestashopOutboundStatusSyncTest|OrderStatusServiceTest|OrdersAjaxListTest|Automation` objal rowniez `OrderStatusPollingTest::test_order_view_refreshes_after_automation_without_periodic_order_polling`. Ten starszy test nie przeszedl: oczekuje literalnego `refreshOrderState(['sales-documents', 'history'])`, podczas gdy istniejacy widok odswieza tez `automation-history`. Widoku show ani tego testu nie zmieniano w ALLEGRO.3A. Przebieg przerwano podczas generowania bardzo duzego komunikatu HTML; nie jest raportowany jako zakonczony zestaw. Pelnego zestawu wszystkich testow projektu nie uruchamiano.

Widok sprawdzono w przegladarce na komputerze oraz przy 390 x 844 px, bez zapisywania ustawien: wszystkie selektory byly aktywne z "Nie przekazuj", widoczne byly rzeczywiste statusy OMS, brak ostrzezen i bledow konsoli. Tymczasowa karta zostala zamknieta, rozmiar przegladarki przywrocony, karta uzytkownika nietknieta.

## Weryfikacja reczna

1. Otworz `http://127.0.0.1:8000/integrations/allegro?tab=statuses`.
2. Sprawdz liste z konfiguracja statusow OMS. Kazdy wiersz, lacznie z Nowe i Dostarczone, powinien miec aktywny wybor "Nie przekazuj". Przelacznik powinien pozostac wylaczony.
3. Zmiana nazwy lub kolejnosci statusu w ustawieniach OMS powinna byc widoczna po odswiezeniu zakladki. Nowy status zaczyna bez mapowania. Nie trzeba wykonywac tych zmian na danych roboczych tylko dla testu.
4. Dalszy test wykonaj dopiero na kontrolowanym zamowieniu, najlepiej na osobno skonfigurowanym koncie sandbox. Jawnie wybierz pojedyncze mapowanie i wlacz przekazywanie. Sam zapis nie powinien aktualizowac zadnego starego zamowienia.
5. Zmien status kompletnego zamowienia przez OMS. W Szczegolach zamowienia sprawdz stan przekazywania, a w historii wpis potwierdzenia; zweryfikuj status realizacji w Allegro. Ponowne ustawienie tej samej lokalnej wartosci nie powinno tworzyc nowego zadania.
6. Blad lub proba ponowienia nie moze wycofac lokalnego statusu ani zmienic faktury, wplat lub pozycji. Nie testuj bledow przez celowe psucie prawdziwych danych dostepowych; pokrywaja to testy automatyczne.

## Ograniczenia i ryzyka

- Nie potwierdzono rzeczywistego zapisu statusu w Allegro. Pierwszy kontrolowany test API pozostaje do wykonania przez wlasciciela po wyborze mapowan.
- Anulowane w tym etapie oznacza fulfillment.CANCELLED. Nie jest to refund ani automatyczna operacja anulowania zakupu kupujacego. Obsluga importowanego checkout.status=CANCELLED pozostaje osobna.
- Nie ma mapowania OMS Dostarczone na dostarczenie paczki: PICKED_UP oznacza odbior osobisty, nie przesylke kurierska. RETURNED jest tylko do odczytu w API.
- Ustawienie PROCESSING moze ograniczac samodzielne anulowanie zakupu przez klienta. Nie jest wysylane automatycznie po imporcie.
- Wylaczenie lub zmiana konfiguracji zatrzymuje nieaktualne zadania, ale nie cofa zadania juz przyjetego przez zewnetrzne API. Nie istnieje wspolna transakcja bazy i Allegro.
- Status przekazywania w otwartej karcie moze wymagac odswiezenia strony. Nie dodano osobnego cyklicznego odpytywania ani ekranu logow.
- Cale OMS nadal wymaga oddzielnego etapu logowania i kontroli dostepu przed publicznym udostepnieniem; nie dodano uwierzytelniania w tym zadaniu.
- Numery listow przewozowych, faktury, oferty, stany magazynowe i zwroty pieniedzy nie naleza do ALLEGRO.3A.

Referencje: [obsluga zamowien Allegro](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR), [oficjalny schemat API](https://developer.allegro.pl/swagger.yaml).
