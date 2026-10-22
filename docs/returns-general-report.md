# Zwroty ogolne OMS - raport wdrozenia

Data: 22.10.2026. Rozszerzenie istniejacego ALLEGRO.4A, bez zmiany zakresu operacji API.

## Wykonane zmiany

- Lista zwrotow wspoldzieli dokladnie te same 945 linii stylow co lista zamowien. Porownanie z poprzednim blokiem CSS zamowien potwierdzilo brak zmian w stylach.
- Osobny panel boczny: Stworz zwrot, Wszystkie, statusy z licznikami, zarzadzanie statusami. Na telefonie tworzenie jest dostepne nad tabela, a status w filtrach.
- Filtrowanie po lokalnym statusie, zrodle, integracji, sposobie utworzenia, dacie, gwiazdce i tekscie; zachowano zgodnosc dotychczasowego parametru statusu zewnetrznego. Sortowanie i paginacja 20/30/50/75/100/150/200.
- Dzialajace zaznaczanie biezacej strony, gwiazdki i atomowa zmiana statusu zaznaczonych zwrotow. Nie ma atrap operacji finansowych lub magazynowych.
- Tworzenie z dowolnego zamowienia OMS albo samodzielnie. Wejscie z listy oraz z Akcje -> Stworz zwrot na karcie zamowienia.
- Wiele zwrotow czesciowych, poprzednie zgloszenia, pozostale ilosci, ceny z serwera i dokladne sumowanie BigDecimal. Przy recznym samodzielnym zwrocie opcjonalny jawny ID produktu, bez dopasowania po nazwie.
- Ochrona przed powtornym wyslaniem, innymi danymi pod tym samym kluczem, zmiana zamowienia, obcymi/powtorzonymi pozycjami, przekroczeniem ilosci i nieaktualna wersja statusu/gwiazdki.
- Niezalezne lokalne statusy, historia ich zmian, osobny status Allegro. Import nie nadpisuje lokalnej obslugi. Reczny zwrot Allegro nie tworzy zgloszenia w Allegro ani nie jest automatycznie adoptowany przez importer.
- Karta zachowuje dane historyczne, dokumenty i osobne sekcje przesylek zamowienia oraz przesylek zwrotnych.

## Zmienione pliki w tym rozszerzeniu

Nowe:

- `app/Models/ReturnStatusSetting.php`
- `app/Services/OrderReturnService.php`
- `app/Http/Requests/StoreOrderReturnRequest.php`
- `app/Http/Controllers/ReturnStatusSettingsController.php`
- `database/migrations/2026_10_22_020000_generalize_order_returns.php`
- `database/migrations/2026_10_22_021000_preserve_legacy_return_import_defaults.php`
- `resources/views/orders/partials/list-styles.blade.php`
- `resources/views/returns/_sidebar.blade.php`
- `resources/views/returns/create.blade.php`
- `resources/views/returns/_manual-item.blade.php`
- `resources/views/returns/statuses.blade.php`
- `tests/Feature/OrderReturnsTest.php`
- `docs/returns-general-report.md`

Rozszerzone istniejace, takze pliki utworzone we wczesniejszym ALLEGRO.4A:

- `app/Models/OrderReturn.php`
- `app/Models/OrderReturnItem.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Http/Controllers/OrdersController.php`
- `Modules/Integrations/Allegro/Services/AllegroReturnImportService.php`
- `resources/views/returns/index.blade.php`
- `resources/views/returns/show.blade.php`
- `resources/views/orders/index.blade.php`
- `resources/views/orders/show.blade.php`
- `resources/views/layouts/app.blade.php`
- `routes/web.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete pliki: brak. Nie zmieniano .env, README, lockfile ani zaleznosci. Nie wykonano operacji zapisujacych Git. Pozostawiono wczesniejsze niezacommitowane zmiany ALLEGRO.4A.

## Migracje i dane robocze

Obie migracje wykonane w lokalnej bazie:

- `2026_10_22_020000_generalize_order_returns`: batch 97.
- `2026_10_22_021000_preserve_legacy_return_import_defaults`: batch 98.

Polecenia dla innej kopii aplikacji, po wykonaniu kopii bazy:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_22_020000_generalize_order_returns.php
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_22_021000_preserve_legacy_return_import_defaults.php
```

Migracja 020000 celowo blokuje automatyczny rollback, aby nie utracic zwrotow recznych. Ewentualne cofniecie wymaga osobnej migracji danych lub odtworzenia kopii. Stary worker importu jest obslugiwany przez zgodne domyslne wartosci nowych kolumn. Wlasciciel potwierdzil restart Main Queues po wdrozeniu.

Koncowa kontrola odczytem: 2 zwroty, 0 testowych/recznych zwrotow, 0 rekordow bez lokalnego statusu, 1 konto z wlaczonym pobieraniem zwrotow. Nie zmieniono ustawienia pobierania, daty poczatkowej ani istniejacych zgloszen. Testy nie korzystaly z tej bazy.

## Testy

Sprawdzono `phpunit.xml` i izolowany runner: SQLite `:memory:`, prywatny storage/cache/logi, blokada rzeczywistej sieci. Testy HTTP nie wykonaly polaczen Allegro, wysylki poczty ani operacji finansowych.

Koncowy szeroki przebieg:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="Allegro|OrderReturnsTest|OrdersAjaxListTest|OrderSavedViewsTest|OrderStatusSettingsTest|OrderStatusServiceTest"
```

Wynik: **424 testy, 2565 asercji, wszystkie poprawne**. Obejmuje caly aktualny zestaw Allegro oraz tworzenie/statusy zwrotow i powiazane listy zamowien.

Po ostatnich poprawkach widokow:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="OrderReturnsTest|OrderStatusPollingTest::test_order_state_endpoint|OrderStatusPollingTest::test_status_can_be_changed"
```

Wynik: **33 testy, 254 asercje, wszystkie poprawne**. Z tego 30 to ponownie wykonane nowe testy zwrotow; 3 dodatkowe sprawdzaja stan zamowienia i jego aktualizacje. Lacznie sprawdzono 427 roznych testow w tych dwoch poprawnych przebiegach.

Nowe testy obejmuja: wszystkie zrodla zamowien, reczny zwrot bez zamowienia, dokladnosc groszowa, snapshoty i usuniecie zamowienia/pozycji, idempotencje, walidacje, limity ilosci, odrzucenie i ponowne otwarcie, optimistic lock i atomowosc bulk, konfiguracje/ochrone statusow, niezaleznosc importu, zgodnosc starego workera, bezpieczne HTML i wyszukiwanie, odtworzenie formularza po bledzie.

W czasie kontroli wykryto i poprawiono blad wyszukiwania zamowienia po liczbowym ID (nieistniejaca metoda `orWhereKey`) oraz nieprzetworzona dyrektywe Blade przy ikonie Allegro. Ponowne testy i przegladarka potwierdzily poprawki.

Pierwszy szerszy przebieg ujawnil niezalezny starszy test `OrderStatusPollingTest::test_order_view_refreshes_after_automation_without_periodic_order_polling`. Oczekuje on `refreshOrderState(['sales-documents', 'history'])`, podczas gdy aktualny, niezmieniany w tym zadaniu kod odswieza tez `automation-history`. Przebieg przerwano podczas generowania bardzo duzego porownania HTML; nie zmieniano tego testu ani logiki automatyzacji. Nie uruchamiano ponownie calego zestawu aplikacji; pozostale znane problemy pelnego zestawu opisano we wczesniejszym `docs/allegro-4a-report.md`.

Pint uruchomiono na 11 nowych/rozszerzonych plikach PHP tego etapu; wynik poprawny. `artisan migrate:status` potwierdzil oba batche.

## Weryfikacja w przegladarce

Sprawdzono rzeczywista aplikacje pod `http://127.0.0.1:8000`, bez tworzenia danych testowych:

- Lista, panel statusow, ikony, zaznaczanie i odznaczanie pozycji.
- Wyszukiwanie zamowienia po ID, wybor zamowienia i Wszystkie pozostale.
- Zakladka Bez zamowienia i dynamiczne dodawanie pozycji z unikalnymi indeksami.
- Karta istniejacego zwrotu, oddzielny status Allegro i status lokalny, nawigacja.
- Menu Akcje -> Stworz zwrot na rzeczywistej karcie zamowienia.
- Widoki desktop oraz 390x844: brak poziomego rozpychania calej strony, tabela przewijana w swoim obszarze, widoczny przycisk tworzenia i filtr statusu na telefonie.

Tymczasowe karty testowe zamknieto, a rozmiar przegladarki przywrocono.

## Weryfikacja reczna dla wlasciciela

1. Otworz Zamowienia -> Zwroty. Sprawdz wyglad tabeli, liczniki i przycisk Stworz zwrot.
2. Kliknij Stworz zwrot, wyszukaj zamowienie reczne, PrestaShop albo Allegro i wybierz je. Ustaw dodatnia ilosc wybranego produktu i zapisz.
3. Otworz to samo zamowienie, Akcje -> Stworz zwrot. Sprawdz poprzednie zgloszenie i pomniejszona pozostala ilosc. Utworz drugi zwrot czesciowy, jesli ilosc na to pozwala.
4. W Stworz zwrot wybierz Bez zamowienia. Wpisz klienta, walute, nazwe/cene/ilosc produktu i zapisz. Nie jest wymagany rekord zamowienia ani integracja.
5. Na liscie zaznacz zwroty i wybierz wspolny status. Sprawdz liczniki i historie w karcie. Zmien gwiazdke i uzyj filtrow.
6. W Dodaj status sprawdz dodawanie, zmiane nazwy/koloru/kolejnosci i ochrone statusu poczatkowego lub uzywanego.
7. Dla powiazanego zamowienia z przesylka otworz zwrot: numer pierwotnej paczki powinien byc w Przesylki (zamowienie), oddzielnie od Przesylek zwrotnych.

## Ograniczenia i ryzyka

- Brak refundacji, roszczen prowizyjnych, korekt, ruchow magazynowych, akceptacji/odrzucenia w API, nadawania paczek i wysylki wiadomosci. Lokalny status Odrzucone nie odrzuca zgloszenia w Allegro.
- Ikona pieniedzy jest szara: brak danych o rzeczywistej refundacji. Niebieska ikona paczki oznacza znany numer przesylki zwrotnej, nie dowod doreczenia.
- Zgloszenia Allegro oraz reczne pozostaja osobne. Przed recznym dodaniem trzeba sprawdzic poprzednie zwroty; nie ma automatycznego scalania.
- Ilosci sa chronione dla jednoznacznie powiazanych `order_item_id`. Import bez jednoznacznego dopasowania pozycji nie rezerwuje ilosci konkretnej pozycji. Nowy fakt z Allegro moze ujawnic kolizje z recznym zgloszeniem; nie jest z tego powodu odrzucany.
- Dane zewnetrzne Allegro pozostaja tylko do odczytu. Edycja danych/pozycji juz utworzonego zwrotu nie nalezy do tego etapu; dostepne sa status i gwiazdka.
- Uwierzytelnianie calego lokalnego OMS pozostaje osobnym etapem; obecne trasy zachowuja istniejacy model dostepu i ochrone CSRF. Nie wystawiac aplikacji publicznie bez jej docelowej ochrony.
- Nie wykonano rzeczywistej refundacji ani operacji zapisu Allegro. Zapis nowych zwrotow sprawdzono w izolowanych testach; w roboczej przegladarce pozostawiono wylacznie istniejace dane.
