# ALLEGRO.3C - raport wdrozenia

Data: 2026-10-21. Zakres: przekazywanie Faktur VAT wystawionych w OMS do zamowien Allegro.

## Wykonane zmiany

- Aktywna zakladka Integracje -> Allegro -> Faktury. Tryby: Nie przekazuj (domyslny), Po wystawieniu faktury i Po wybranym statusie OMS.
- Statusy pochodza z aktualnej konfiguracji OMS, bez blokady statusu poczatkowego. Filtr Wszystkie wystawione faktury VAT (domyslny) albo Tylko gdy klient poprosil o fakture.
- Sam zapis lub wlaczenie ustawien nie skanuje i nie przekazuje dokumentow historycznych. Przyszla zmiana statusu moze zakwalifikowac istniejaca Fakture.
- Jawne reczne przekazanie z potwierdzeniem i kontrola wersji Faktury jest mozliwe rowniez przy wylaczonym automacie. Reczna decyzja omija filtr zadania faktury i bramke statusowa, ale nie zabezpieczenia zrodla, dokumentu ani KSeF.
- Trwale zgloszenie, prywatna niezmienna kopia PDF, suma SHA-256 i odrebna tozsamosc pliku. Nie powstaje druga Faktura ani nowy silnik PDF.
- Nowy POST /order/{orderId}/billing-documents/files przesyla multipart z PDF i numerem. Odczyt listy dokumentow przed pierwszym zapisem. Limit lokalny: 3 000 000 bajtow, poprawny PDF.
- Zapis zamiaru wysylki przed HTTP. Timeout, niejednoznaczne potwierdzenie, HTTP 409 lub 5xx po POST prowadza do odczytu Allegro, bez kolejnego slepego POST. Jednoznaczne odrzucenia, np. 429, pozwalaja ponowic te sama zamrozona kopie.
- Numer Faktury sam nie identyfikuje przeslanego pliku. Weryfikacja korzysta z ID Allegro, numeru i unikatowej nazwy pliku OMS z czescia sumy kontrolnej.
- Przeslanie pliku i pozytywna weryfikacja bezpieczenstwa Allegro sa osobnymi stanami. WAITING nie jest zielonym potwierdzeniem; REJECTED nie powoduje kolejnego uploadu.
- Zdarzenia wystawienia, zmiany statusu i akceptacji KSeF korzystaja z kolejki integrations po zatwierdzeniu transakcji. Wspolna blokada zamowienia obejmuje import i eksport statusow/przesylek.
- Harmonogram co minute obsluguje do 100 znanych zgloszen: oczekiwanie, przerwana kolejke i terminowe sprawdzenie pliku. Automatyczna weryfikacja jest ograniczona do 10 odczytow; pozniej mozna jawnie zlecic sprawdzenie.
- Niepelne, anulowane, obce, usuniete lub zastapione zakupy nie sa kwalifikowane do nowego uploadu. Odczyt wyniku rozpoczetego uploadu moze zachowac potwierdzenie mimo pozniejszego usuniecia lokalnej Faktury.
- Podglady i niezaakceptowane dokumenty KSeF nie sa wysylane. Dla Offline uzywany jest istniejacy serwis finalnej wizualizacji po akceptacji. KSeF TEST/DEMO nie trafia do produkcyjnego Allegro.
- Przy Fakturze widoczny jest stan, przycisk reczny lub sprawdzenie/ponowienie. Zmiana dokumentu powoduje ostrzezenie, bez podmiany pliku; dozwolona edycja i usuwanie w OMS nie zostaly zablokowane.
- Usuniecie Faktury lub konta nie usuwa eksportu ani jego prywatnej kopii. Historia zamowienia zawiera fakty przeslania, potwierdzenia i bledow, bez kopii finansowych snapshotow.
- Kazda sciezka API i OAuth, w tym multipart po 401, zachowuje zatwierdzony User-Agent z services.allegro.user_agent. Nowe logi nie zawieraja PDF ani numerow dokumentow.

Stan po wdrozeniu i kontroli w przegladarce: invoice_sync_mode=disabled, shipment_sync_mode=disabled, zero rekordow eksportu Faktur, zero rzeczywistych POST upload_order_invoice. Nie zapisywano konfiguracji ani nie przekazywano dokumentow do rzeczywistego Allegro.

## Zmienione pliki

Utworzone:
- `database/migrations/2026_10_21_030000_add_allegro_invoice_exports.php`
- `Modules/Integrations/Allegro/Enums/AllegroInvoiceSyncMode.php`
- `Modules/Integrations/Allegro/Models/AllegroInvoiceExport.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroInvoiceSettingsRequest.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroInvoiceSettingsController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroInvoiceExportController.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceSettingsService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceDocumentService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceExportService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceExportView.php`
- `Modules/Integrations/Allegro/Jobs/ExportAllegroInvoiceJob.php`
- `Modules/Integrations/Allegro/Listeners/DispatchAllegroInvoiceExports.php`
- `resources/views/integrations/allegro/_invoices.blade.php`
- `resources/views/orders/partials/allegro-invoice-export.blade.php`
- `tests/Feature/AllegroInvoiceSettingsTest.php`
- `tests/Feature/AllegroInvoiceExportTest.php`
- `tests/Feature/AllegroInvoiceDocumentTest.php`
- `docs/allegro-3c-report.md`

Zmienione (zachowano istniejace zmiany innych etapow):
- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroClient.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroSettingsController.php`
- `Modules/Invoices/Services/OrderSalesDocumentActionsView.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `routes/console.php`
- `resources/views/integrations/allegro/edit.blade.php`
- `resources/views/orders/partials/sales-document-actions.blade.php`
- `docs/product-spec.md`
- `docs/architecture.md`
- `tests/Feature/Ksef/KsefOfflinePresentationTest.php`

Usuniete: brak. Nie wykonywano operacji zapisu Git ani zmian zaleznosci, .env, README i plikow lock.

## Migracje

Migracja `2026_10_21_030000_add_allegro_invoice_exports.php` zostala uruchomiona lokalnie z powodzeniem. Dodaje ustawienia konta i tabele allegro_invoice_exports; nie usuwa danych ani nie wykonuje backfillu. Historyczne FK sa nullable i uzywaja nullOnDelete.

Bezpieczna komenda dla innego srodowiska:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_21_030000_add_allegro_invoice_exports.php --force
```

## Testy

Sprawdzono konfiguracje testowa: SQLite :memory:, pusty DB_URL, bez roboczej bazy. Nowe testy uzywaja symulowanego HTTP, prywatnego testowego storage i sprawdzaja dokladny User-Agent wszystkich zarejestrowanych zadan.

- Nowe trzy klasy testowe oraz test finalnej Faktury Offline: 39 testow, 246 asercji, wynik pozytywny.
- Szerszy zestaw przed ostatnimi dodatkami: 567 testow, 3639 asercji, wynik pozytywny.
- Koncowy izolowany zestaw regresji: 683 testy, 4509 asercji, wynik pozytywny (73,38 s). Blokada rzeczywistej sieci, prywatne katalogi testowe i SQLite :memory:.
- Pint --test uruchomiony tylko dla plikow PHP tego etapu: wynik pozytywny.
- Trasy Allegro poprawnie zarejestrowane; git diff --check bez bledow bialych znakow (informacyjne ostrzezenie CRLF/LF docs/product-spec.md).

Komenda nowego zestawu:

```powershell
C:\xampp\php\php.exe artisan test --compact --filter="AllegroInvoiceDocumentTest|AllegroInvoiceExportTest|AllegroInvoiceSettingsTest|test_allegro_export_waits_for_offline_acceptance_and_uses_existing_final_pdf"
```

Komenda izolowanej regresji:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="(Allegro[^:]*Test|InPost[^:]*Test|Dpd[^:]*Test|Shipment[^:]*Test|PrestashopOutboundStatusSyncTest|OrderStatusServiceTest|OrdersAjaxListTest|Automation[^:]*Test|OrderSalesDocumentActionsTest|InvoiceIssuingServiceTest|InvoiceEditingTest|InvoiceDeletionTest|InvoiceCurrencyIssuingTest|InvoiceBulkPdfTest|InvoicePdfTest|KsefOfflinePresentationTest)::"
```

## Weryfikacja reczna

1. Otworz /integrations/allegro?tab=invoices. Domyslnie ma byc Nie przekazuj oraz Wszystkie wystawione faktury VAT. Zweryfikuj aktualne statusy OMS, takze Nowe i statusy wlasne.
2. Przed pierwszym uzyciem w innym srodowisku zrestartuj tylko Main Queues w panelu OMS, aby worker wczytal ALLEGRO.3C. Lokalny restart potwierdzono 2026-10-21 o 19:54:56 czasu Europe/Warsaw, po wdrozeniu kodu. Proces dziala i wykonuje zadania. Automat pozostaje w trybie Nie przekazuj; brak zgloszen eksportu i rzeczywistych uploadow Faktur.
3. Na kontrolowanym kompletnym zamowieniu Allegro z wystawiona Faktura sprawdz w Zarzadzanie wiersz Allegro: Nie przekazano i ikone chmury z podpowiedzia Przekaz do Allegro.
4. Rzeczywiste przekazanie wykonaj dopiero swiadomie, na wybranym zamowieniu. Potwierdz operacje; po pracy kolejki oczekuj Przeslano, trwa weryfikacja Allegro, a dopiero po kontroli Allegro zielonego potwierdzenia. Sprawdz rowniez dokument w Sales Center. Otwarte zamowienie moze wymagac odswiezenia.
5. W kontrolowanym tescie trybu statusowego sprawdz obie kolejnosci: Faktura przed statusem i Faktura wystawiona juz w danym statusie. Zapis ustawien nie moze sam przekazac dawnych dokumentow.
6. Dla Faktury KSeF oczekuj na akceptacje i wlasciwy finalny PDF. Nie wysylaj testowych dokumentow do produkcyjnego Allegro.
7. Zmiana przygotowanej/przekazanej Faktury powinna dac ostrzezenie bez automatycznej podmiany. Lokalne usuniecie pozostawia informacje o dawnym eksporcie i nie kasuje pliku w Allegro.
8. Bledy transportu, timeouty i odrzucenie pliku testuj przez symulowane HTTP, bez psucia danych dostepowych produkcyjnej integracji.

Sprawdzono w przegladarce konfiguracje przy 1265 x 712 i 390 x 844 oraz istniejace zamowienie 164 z Faktura. Stan i przycisk poprawnie mieszcza sie w kafelku. Konsola bez ostrzezen i bledow. Nie wysylano formularzy, nie tworzono testowych danych w roboczej bazie. Tymczasowa karta zamknieta, rozmiar przegladarki przywrocony.

## Ograniczenia i ryzyka

- Pierwszy rzeczywisty upload do Allegro pozostaje do kontrolowanej weryfikacji przez wlasciciela. Wszystkie testy zapisu API byly symulowane.
- API Allegro nie zwraca sumy kontrolnej PDF w liscie dokumentow. Identyfikacja po timeoutach opiera sie na unikatowej nazwie pliku, numerze, ID i danych uploadu, nie na kryptograficznym porownaniu zdalnej zawartosci. Niejednoznaczny wynik nie uruchamia kolejnego uploadu.
- Nie ma atomowej transakcji miedzy SQLite, prywatnym plikiem a Allegro. Potwierdzenie zaakceptowanego uploadu jest zachowywane mimo pozniejszej zmiany konfiguracji.
- Istniejacy dokument w Allegro przed pierwszym uploadem OMS wymaga recznego sprawdzenia. Nie jest automatycznie uznawany za dokument OMS ani zastepowany.
- Kopie eksportu sa celowo zachowywane po usunieciu lokalnej Faktury. Polityka retencji i osobne narzedzia czyszczenia nie zostaly dodane.
- Nie dodano korekt, pro form, dokumentow zewnetrznych, doreczen Offline przed akceptacja KSeF, usuwania/podmiany dokumentow Allegro ani masowego eksportu historii.
- Zielone potwierdzenie dotyczy powiazania pliku i pozytywnej weryfikacji bezpieczenstwa, nie odrebnej akceptacji finansowania lub podatkowej.
- Uwierzytelnianie calego OMS pozostaje osobnym etapem przed publicznym udostepnieniem.

Referencje: [nowy upload faktury Allegro](https://developer.allegro.pl/news/zamowienia-uproscilismy-zalaczanie-faktur-jn0aj75mxcW), [obsluga dokumentow zamowien](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR#faktury-i-e-paragony), [aktualny kontrakt API](https://developer.allegro.pl/swagger.yaml), [konfiguracja Base](https://base.com/pl-PL/pomoc/wiedza/allegro/).
