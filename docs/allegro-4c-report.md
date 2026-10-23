# ALLEGRO.4C - Korekty z kart zwrotow

Wdrozenie lokalne: 2026-10-22. Koncowa weryfikacja: 2026-10-23, Europe/Warsaw.

## Stan repozytorium

Preflight: main, HEAD 09958a0e7408b93746f889592cd542ef0eb500dd, worktree czyste. Wczesniejsze ALLEGRO.4B.2 oraz wnioski o prowizje byly juz zapisane przez wlasciciela w aktualnym kodzie. Nie wykonywano commit, push ani innych operacji zmieniajacych Git. Nie zmieniano konfiguracji srodowiska ani zaleznosci.

## Dzialanie

- Karta zwrotu ma Wystaw korekte w Zamowienie i dokumenty. Funkcja dziala dla kazdego zrodla zamowienia OMS, nie tylko Allegro.
- Obecny formularz Korekty otrzymuje domyslny powod zwrot sprzedawcy towarow, liczbe zwracanych sztuk i osobna decyzje o korekcie dostawy.
- Ceny, VAT i waluta pochodza z Faktury lub AFTER ostatniej zamknietej Korekty. Korygowany jest pozostaly stan dokumentu, nie aktualna oferta/produkt ani cena karty zwrotu.
- Otwarcie/anulowanie/zmiana serii nie tworza dokumentu ani numeru. Wystawienie pozostaje operacja centralnego CorrectionService po zatwierdzeniu formularza.
- Dostawa jest domyslnie wylaczona. Mozna skorygowac cala pozostala kwote albo jej czesc. Decyzja jest niezalezna od zwrotu wplaty.
- Jedna karta ma jedna istniejaca Korekte, a Korekta nie scala kilku kart. Numer, PDF i stan zamkniecia sa widoczne na karcie; edytor ma link i powrot do zwrotu.
- Niezfinalizowana Korekta innego zwrotu lub utworzona recznie jest otwierana z ostrzezeniem, bez podmiany pozycji i bez przypisania. Starszych dokumentow nie laczy sie automatycznie.
- Wystawienie, zamkniecie i dozwolone usuniecie trafiaja do historii zwrotu. Zwykla edycja nie tworzy historii kopii dokumentu.
- Snapshot numeru i limitow zwrotu pozostaje na dokumencie po zmianie/usunieciu karty. Numer pojawia sie na PDF, jezeli zamrozona konfiguracja serii zawiera show_return_id_in_header=true.
- Zachowano obecna numeracje, jeden otwarty dokument, liniowy lancuch, zasady edycji/usuwania/finalizacji, KSeF oraz ALLEGRO.3D. Nie zmieniono ustawien automatycznych wysylek.
- Brak automatycznej refundacji, wniosku o prowizje, zmiany statusu zwrotu/zamowienia lub operacji magazynowej.

## Pliki utworzone

- database/migrations/2026_10_22_060000_link_return_corrections_to_invoices.php
- Modules/Invoices/Services/OrderReturnCorrectionService.php
- resources/views/invoices/corrections/_return-items.blade.php
- tests/Feature/Invoices/OrderReturnCorrectionsTest.php
- tests/Unit/OrderReturnCorrectionMigrationTest.php
- docs/allegro-4c-report.md

## Pliki zmienione

- Modules/Invoices/Http/Controllers/CorrectionController.php
- Modules/Invoices/Http/Requests/CorrectionDraftRequest.php
- Modules/Invoices/Models/Invoice.php
- Modules/Invoices/Services/CorrectionService.php
- Modules/Invoices/Services/CorrectionStateComparator.php
- Modules/Invoices/Services/InvoiceDeletionService.php
- Modules/Invoices/Services/InvoiceFinalizationService.php
- Modules/Invoices/Services/InvoicePdfFilenameGenerator.php
- Modules/Invoices/Services/InvoicePdfViewModelFactory.php
- Modules/Invoices/Support/InvoiceReturnContext.php
- app/Http/Controllers/OrderReturnController.php
- app/Models/OrderReturn.php
- resources/views/invoices/corrections/create.blade.php
- resources/views/invoices/pdf/correction.blade.php
- resources/views/returns/show.blade.php
- tests/Feature/Invoices/InvoicePdfTest.php
- docs/product-spec.md
- docs/architecture.md

Nie usunieto plikow. Nie dodano tras, providera modulu, zaleznosci ani drugiego silnika PDF. Zmieniono tylko wersje layoutu Korekty v46 -> v47; layout Faktury i Pro formy pozostal bez zmian. Testy nazw cache dostosowano do tej zmiany.

## Granica domenowa i zabezpieczenia

OrderReturnCorrectionService sprawdza istniejace zamowienie, zgodna walute, status zwrotu, dokladne powiazanie order_item_id i brak wieloznacznosci w skutecznym stanie pozycji Faktury. Nie dopasowuje po nazwie, SKU, EAN, kwocie ani kolejnosci. Formularz wymaga rzeczywistej Faktury VAT; Pro forma lub sam nieustrukturyzowany PDF nie jest zrodlem do automatycznego przygotowania.

Hash kontekstu obejmuje wersje i pozycje zwrotu oraz stan bazowych pozycji dokumentu. Przy issue sprawdzenie jest powtarzane w transakcji, po blokadzie zamowienia/Faktury/lancucha i z blokada zwrotu/pozycji. Dotychczasowy optimistic lock skutecznego dokumentu nadal dziala. Unikalny invoices.order_return_id chroni pojedyncze przypisanie. Podwojny POST wraca do istniejacej Korekty bez nowej numeracji.

Serwer buduje wszystkie pozycje AFTER z historycznego BEFORE i wybranych ilosci/dostawy. Pola items przeslane z zewnatrz nie nadpisuja cen/VAT w tej sciezce. BigDecimal i istniejace kalkulatory licza kwoty bez float. Edycja wykorzystuje snapshot limitow z momentu wystawienia, nie pozniejszy import zwrotu; pozostaje na tym samym numerze i korzysta z lock_version. Zamkniety dokument jest nieedytowalny.

Powiazanie ma snapshot w order_snapshot.return_correction i nullable FK do zywej karty. Usuniecie karty zeruje FK bez usuwania dokumentu ani snapshotu. Dopuszczone usuniecie niezfinalizowanej Korekty zwalnia powiazanie, lecz zachowuje numer w order_return_events. Nie zmieniono dotychczasowych ograniczen usuwania dokumentow posiadajacych inne Korekty lub blokady KSeF.

Nowy cel nawigacji order_return przyjmuje whitelistowany identyfikator, sprawdza przynaleznosc do zamowienia i wraca do zamowienia dla obcej/usunietej karty. Nie przyjmuje dowolnego URL. CSRF pozostaje standardowym middleware web istniejacych tras. Zabezpieczenie calego panelu nadal zapewnia potwierdzony przez wlasciciela Basic Auth reverse proxy i HTTPS; nie dodano systemu logowania ani nowych uprawnien OAuth. Istniejacy User-Agent Allegro pozostaje bez zmian.

## Migracja i kopia

Migracja: 2026_10_22_060000_link_return_corrections_to_invoices. Dodaje nullable invoices.order_return_id, unique invoices_return_unique i FK invoices_return_fk z nullOnDelete. Nazwy sa krotsze niz 64 znaki. Nie przypisuje zadnego historycznego dokumentu. Automatyczny down jest celowo zablokowany przed utrata powiazan.

Po osobnej zgodzie wlasciciela wykonano spojna kopie SQLite przez VACUUM INTO:

```text
C:\projekty\nex-oms\storage\app\private\backups\database_before_return_corrections_20261022_231817.sqlite
SHA256: 04efea749da8707e8521d1d27e02da50b2d53d136985a7893aab2bb4e3a004de
Rozmiar: 7950336 bajtow
```

Migracje sprawdzono najpierw na oddzielnej kopii:

```text
C:\projekty\nex-oms\storage\app\private\backups\return_corrections_validation_20261022_231817.sqlite
```

Nastepnie wykonano tylko migracje 060000 na lokalnej bazie. Porownano fingerprinty wszystkich oryginalnych kolumn i wierszy 16 tabel: orders, order_items, invoices, invoice_items, invoice_series, invoice_number_counters, invoice_number_counter_adjustments, order_returns, order_return_items, order_return_parcels, order_return_events, allegro_accounts, allegro_payment_refunds, order_return_refund_allocations, allegro_refund_commands i allegro_commission_refund_claims. Dane pozostaly identyczne na kopii oraz po migracji lokalnej. quick_check=ok, 0 naruszen FK, 0 automatycznie powiazanych dokumentow. Wlasciciel potwierdzil restart Main Queues.

## Testy

Testy korzystaly z istniejacego runnera izolujacego storage/logi/cache/temp, SQLite :memory: i blokujacego rzeczywista siec. Nie uruchamiano ich na roboczej bazie. Migracja SQLite ma dodatkowy test na osobnej nowej bazie w pamieci z istniejacymi wierszami, unikalnoscia i nullOnDelete. SQL MySQL 8.0.46 jest sprawdzany przez pretend, bez polaczenia z serwerem.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter=OrderReturnCorrection --stop-on-defect
```

Wynik: 31 testow, 213 asercji, wszystkie poprawne.

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter='Allegro|OrderReturnCorrection|InvoiceCorrectionTest|CorrectionSourceOptimisticLockingTest|CorrectionSeriesSourceResolutionTest|InvoiceForeignCurrencyCorrectionTest|InvoicePdfTest|InvoiceReturnContextTest|OrderReturnsTest|MySqlMigrationIdentifiersTest|KsefFa3CorrectionSemanticSnapshotServiceTest|KsefFa3CorrectionDocumentGeneratorTest|KsefFa3CorrectionFinalizationGateTest' --stop-on-defect
```

Wynik: 767 testow, 5521 asercji, wszystkie poprawne. Nie jest to pelny zestaw testow calego projektu.

Zakres: zwroty czesciowe, 3 -> 2 sztuki, wszystkie zrodla, ceny/VAT ze snapshotu, calkowita/czesciowa dostawa, falszywe ceny z POST, pozycje zerowe, walidacja ilosci i kwot, hash, zmiany zwrotu/zrodla, podwojne potwierdzenie, konflikt otwartej Korekty, poprzedni zamkniety AFTER, pozniejsza edycja limitow karty, optimistic lock, historia po usunieciu karty, opcjonalny numer na PDF, dozwolone usuniecie dokumentu, blokada zamknietego dokumentu i niedopasowanych pozycji. Regresja obejmuje dotychczasowe Allegro, Korekty, PDF i wybrane sciezki KSeF.

PHP lint: 21 zmienionych/nowych plikow PHP i Blade. Pint: zmienione pliki PHP, bez globalnego formatowania. git diff --check: poprawny. Koncowe kontrole sa wykonywane po formatowaniu i dokumentacji.

## Weryfikacja reczna

1. Otworz Zwroty i karte zwrotu do zamowienia z Faktura VAT. W Zamowienie i dokumenty wybierz Wystaw korekte.
2. Jezeli jest juz niezfinalizowana Korekta tego zamowienia, zobaczysz istniejacy dokument i ostrzezenie. To nie przypisuje go do karty. Sam zdecyduj o dalszej obsludze tego dokumentu zgodnie z jego zawartoscia; nie zamykaj go tylko po to, aby ominac komunikat.
3. Przy wolnym slocie formularz pokazuje numer zwrotu, powod zwrotu, pozycje i ilosci. Sprawdz ilosc przed, do korekty oraz pozostajaca. Ceny powinny odpowiadac dokumentowi, nawet jesli zamowienie/zwrot zostaly pozniej zmienione.
4. Dostawa ma pozostac niezaznaczona. Zaznacz ja tylko przy zamierzonej korekcie dostawy i sprawdz kwote. To nie zleca przelewu klientowi.
5. Anuluj, aby zakonczyc bez dokumentu. Tylko przy rzeczywiscie zamierzonej operacji zatwierdz wystawienie. Karta otrzyma numer/PDF i Edytuj korekte, a historia wpis o wystawieniu.
6. Sprawdz powrot z edytora, edycje tego samego numeru i numer zwrotu na PDF zgodnie z ustawieniem serii. Po zamknieciu karta pokazuje Podglad korekty i stan Zamknieta.
7. Dla Allegro sprawdz dotychczasowy stan eksportu dokumentu w zamowieniu. Sam etap nie wlacza automatycznej wysylki. Refundacja i wniosek o prowizje nadal wymagaja osobnych decyzji.

W lokalnej przegladarce zweryfikowano nowy przycisk na /orders/returns/20, jego rozmieszczenie, przejscie do istniejacej BLK 4/2026 i ostrzezenie, bez zapisu formularza. Dokument ten istnial przed etapem i nie zostal automatycznie przypisany. Nowy formularz ilosci/dostawy sprawdzono testami renderowania i HTTP na fikcyjnych danych; nie tworzono dodatkowego dokumentu w roboczej bazie tylko do podgladu.

## Wdrozenie VPS

Nie wykonywano zadnych operacji na VPS. Przed wgraniem kodu wykonaj i zweryfikuj kopie docelowej bazy. Wgraj caly zakres plikow i sprawdz, czy poprzednie etapy zwrotow i dokumentow sa wdrozone. Przy kompletnych zaleznosciach i braku nowej kolumny:

```bash
php artisan migrate:status
php artisan migrate --force --path=database/migrations/2026_10_22_060000_link_return_corrections_to_invoices.php
```

Po migracji przeladuj Main Queues istniejacym panelem/menedzerem procesow. Nie zmieniaj przy tym ustawien integracji. W razie czesciowego niepowodzenia porownaj strukture invoices i zapis migracji przed ponowieniem; nie usuwaj tabel/dokumentow ani kolumny z powiazaniami. Wylaczenie kodu nie wymaga kasowania historii. Nie uruchamiaj resetowania lub odtwarzania istniejacej bazy.

## Ograniczenia

- Nie ma rzeczywistego wykonania na MySQL 8; potwierdzono generowany SQL oraz migracje SQLite na kopii i lokalnie.
- Nie wykonano rzeczywistej wysylki Allegro/KSeF, refundacji ani nowej Korekty w roboczej bazie. Istniejace bramki transmisji pozostaja niezalezne.
- Nie laczymy starych Korekt ani kilku kart w jeden dokument. Brak jednoznacznego powiazania pozycji wymaga recznego sprawdzenia.
- Automatyczne przygotowanie wymaga calkowitych ilosci do 1000000, do 100 pozycji zwrotu i cen jednostkowych dokladnie reprezentowalnych do 2 miejsc. Nieobslugiwane historyczne przypadki sa blokowane, nie przeliczane przyblizeniem.
- Osobnego wizualnego testu nowego formularza na telefonie i rzeczywistego wydruku nie wykonano. Formularz ma responsywna tabele i wspoldzieli istniejacy responsywny ekran Korekty; renderowanie/HTTP i dotychczasowy renderer PDF objeto testami.
