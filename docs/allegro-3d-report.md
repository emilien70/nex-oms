# ALLEGRO.3D - raport wdrozenia

Data: 2026-10-21. Zakres: przekazywanie zamknietych Korekt z OMS do zamowien Allegro.

## Wykonane zmiany

- Niezalezna sekcja Korekty w Integracje -> Allegro -> Faktury. Domyslnie Nie przekazuj; opcjonalnie Po zamknieciu korekty albo Po wybranym statusie OMS.
- Statusy z biezacej konfiguracji OMS, takze wlasne i poczatkowy. Brak filtra zadania Faktury VAT dla Korekt.
- Zapis ustawien nie wysyla historycznych Korekt. Przyszle zamkniecie albo wejscie w wybrany status moze zakwalifikowac dokument; obie kolejnosci sa obslugiwane.
- Nowe zdarzenie domenowe po zatwierdzeniu rzeczywistej finalizacji. Edytowalna, ponumerowana Korekta nie jest wysylana. Finalizacja jest idempotentna, rollback nie uruchamia eksportu.
- Walidacja Faktury zrodlowej i centralnego liniowego lancucha Korekt. Nie zmieniono numerow, kwot, snapshotow ani regul wystawiania/usuwania dokumentow.
- Osobne rekordy eksportu, pliki PDF, sumy SHA-256 i stan przy kazdej zamknietej Korekcie. Wspoldzielone klient, kolejka, blokada, recovery i reconciliation z ALLEGRO.3C.
- Istniejaca Faktura i poprzednie Korekty w Allegro nie blokuja dodania kolejnej Korekty. Nie sa adoptowane, podmieniane ani usuwane.
- Brak potwierdzenia Faktury zrodlowej przez OMS wstrzymuje automat. Operator widzi ostrzezenie i moze sprawdzic Allegro oraz jawnie potwierdzic reczne przekazanie Korekty. Nie powstaje automatyczna wysylka Faktury zrodlowej. Potwierdzenie jej eksportu budzi znane oczekujace Korekty.
- Anulowanie w OMS lub Allegro nie blokuje Korekty do prawidlowo zaimportowanego zamowienia. Zastapione, niepelne, obce i usuniete zrodla pozostaja blokowane. Odmowa API nie zmienia zamowienia ani dokumentu.
- Reczne przekazanie wymaga potwierdzenia i biezacej wersji; dziala przy wylaczonym automacie. Po niejednoznacznym wyniku OMS tylko sprawdza Allegro, bez drugiego slepego POST.
- Wlasna akceptacja Korekty KSeF i finalny PDF sa wymagane, gdy KSeF dotyczy dokumentu lub jego Faktury zrodlowej. Offline korzysta z istniejacego serwisu zamrozonej zaakceptowanej wizualizacji. Dokumenty TEST/DEMO nie trafiaja do produkcyjnego Allegro.
- Wszystkie sciezki HTTP/OAuth zachowuja zatwierdzony User-Agent. Nie dodano scope, zaleznosci, publicznych PDF ani drugiego silnika PDF.

Stan roboczy po kontroli: invoice_sync_mode=disabled, correction_sync_mode=disabled, zero rekordow eksportu dokumentow i zero logow upload_order_invoice. Nie przekazano zadnego rzeczywistego dokumentu do Allegro. Wlasciciel potwierdzil restart Main Queues; nowy proces PHP rozpoczal prace 2026-10-21 o 20:19:33 czasu lokalnego.

## Zmienione pliki

Utworzone w tym etapie:
- `database/migrations/2026_10_21_040000_add_allegro_correction_exports.php`
- `Modules/Invoices/Events/CorrectionFinalized.php`
- `Modules/Integrations/Allegro/Http/Requests/UpdateAllegroCorrectionSettingsRequest.php`
- `tests/Feature/AllegroCorrectionExportTest.php`
- `tests/Feature/AllegroCorrectionSettingsTest.php`
- `docs/allegro-3d-report.md`

Zmienione, z zachowaniem zmian istniejacych przed tym etapem:
- `Modules/Integrations/Allegro/Enums/AllegroInvoiceSyncMode.php`
- `Modules/Integrations/Allegro/Models/AllegroAccount.php`
- `Modules/Integrations/Allegro/Models/AllegroInvoiceExport.php`
- `Modules/Integrations/Allegro/Services/AllegroAccountService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceSettingsService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceExportService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceDocumentService.php`
- `Modules/Integrations/Allegro/Services/AllegroInvoiceExportView.php`
- `Modules/Integrations/Allegro/Listeners/DispatchAllegroInvoiceExports.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroInvoiceSettingsController.php`
- `Modules/Integrations/Allegro/Http/Controllers/AllegroInvoiceExportController.php`
- `Modules/Invoices/Services/InvoiceFinalizationService.php`
- `Modules/Invoices/Services/OrderSalesDocumentActionsView.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `resources/views/integrations/allegro/_invoices.blade.php`
- `resources/views/orders/partials/allegro-invoice-export.blade.php`
- `resources/views/orders/partials/sales-document-actions.blade.php`
- `tests/Feature/AllegroInvoiceDocumentTest.php`
- `tests/Feature/Ksef/KsefOfflineCorrectionTest.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Usuniete: brak. Nie wykonywano operacji zapisu Git ani zmian .env, README, zaleznosci i plikow lock.

## Migracje

Wykonana bezpieczna, addytywna migracja, batch 95:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_10_21_040000_add_allegro_correction_exports.php --no-interaction
```

Dodaje tylko pola konfiguracji Korekt, typ eksportowanego dokumentu (default invoice dla dotychczasowych danych) i historyczny numer Faktury zrodlowej. Nullable relacja wybranego statusu uzywa nullOnDelete. Dane zamowien i dokumentow nie sa przeliczane ani usuwane. Ponowne wykonanie tej samej komendy po migracji nie wykonuje jej drugi raz.

## Testy

Testy uruchamiano przez istniejacy izolowany runner: SQLite :memory:, prywatne storage/logi/cache/temp i zablokowane rzeczywiste polaczenia sieciowe. Dokladny User-Agent jest sprawdzany na zarejestrowanych zadaniach; scenariusz timeoutu sprawdza go dodatkowo wewnatrz fakes.

Koncowy zestaw dotyczacy dokumentow i ALLEGRO.3D:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="(AllegroCorrectionExportTest|AllegroCorrectionSettingsTest|AllegroInvoiceDocumentTest)::|KsefOfflineCorrectionTest::test_allegro_correction"
```

Wynik: 34 testy, 237 asercji, wszystkie poprawne.

Szersza regresja:

```powershell
C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --filter="(Allegro[^:]*Test|InPost[^:]*Test|Dpd[^:]*Test|Shipment[^:]*Test|PrestashopOutboundStatusSyncTest|OrderStatusServiceTest|OrdersAjaxListTest|Automation[^:]*Test|OrderSalesDocumentActionsTest|InvoiceIssuingServiceTest|InvoiceEditingTest|InvoiceDeletionTest|InvoiceCurrencyIssuingTest|InvoiceBulkPdfTest|InvoicePdfTest|InvoiceFinalizationTest|CorrectionServiceTest|InvoiceCorrection[^:]*Test|KsefOfflinePresentationTest|KsefOfflineCorrectionTest)::"
```

Wynik: 952 testy, 7346 asercji, wszystkie poprawne; czas 286,28 s. Obejmuje dotychczasowe eksporty Allegro, kurierow, statusy, automatyzacje, zamowienia, wystawianie/edycje/usuwanie/PDF dokumentow, finalizacje i Korekty oraz KSeF Offline. Nie uruchamiano calej pozostalej, niepowiazanej kolekcji testow projektu. Poczatkowe bledy zalozen w nowych testach zostaly poprawione; powyzsze wyniki dotycza poprawionej wersji.

Dodatkowo: Pint --test dla 22 wskazanych plikow PHP poprawny, kontrola tras i statusu migracji poprawna, git diff --check dla zmienionych sledzonych plikow bez bledow (dotychczasowe ostrzezenie o normalizacji CRLF w product-spec.md).

## Weryfikacja reczna

Wykonano bez zapisu konfiguracji:
1. Otworzono `/integrations/allegro?tab=invoices` i potwierdzono dwie niezalezne sekcje z domyslnym Nie przekazuj.
2. Sprawdzono aktualna liste statusow, w tym Dostarczone wystepujace w tej instalacji.
3. Oceniono zrzuty widoku 1265x712 oraz 390x844: formularze i przyciski mieszcza sie, etykiety nie nachodza na kontrolki. Przywrocono rozmiar przegladarki i zamknieto tylko tymczasowa karte kontrolna.
4. Odczytano stan lokalnej bazy: brak wysylek dokumentow, brak zmiany ustawien na aktywne.

Kontrola na wybranym przez wlasciciela zamowieniu, przed pierwszym rzeczywistym eksportem:
1. Pozostaw automat wylaczony. Otwarta Korekta nie powinna miec operacji wysylki do Allegro.
2. Zamknij prawidlowa Korekte. Przy jej numerze powinien pojawic sie osobny stan Allegro i przycisk przekazania.
3. Jesli OMS nie potwierdzil Faktury zrodlowej, najpierw sprawdz jej obecnosc w Allegro. Dopiero swiadomie potwierdz reczne przekazanie Korekty; jest to rzeczywista wysylka PDF do klienta przez Allegro.
4. Po wysylce oczekuj stanu weryfikacji, a nastepnie potwierdzenia. Nie traktuj samego uploadu jako pozytywnej weryfikacji pliku.
5. Sprawdz, ze Faktura i poprzednie Korekty pozostaly w Allegro, a historia OMS dotyczy wlasciwego numeru Korekty.
6. Wlaczanie automatu i proby statusowe wykonuj dopiero na kontrolowanych zamowieniach. Zapis konfiguracji nie powinien uruchomic eksportu starych dokumentow.

## Ograniczenia i ryzyka

- Nie wykonano rzeczywistego uploadu ani nie wlaczono automatu. Warunki i limity konkretnego zamowienia Allegro moga spowodowac odmowe API; OMS pokaze blad.
- W roboczej bazie brak zamknietych Korekt powiazanych z Allegro. Ich karty, poprawne finalizacje, scenariusze wielu dokumentow, brak potwierdzenia zrodla oraz KSeF/Offline sprawdzono na izolowanych danych testowych, bez tworzenia dokumentow w bazie wlasciciela.
- Testy korzystaja z kontrolowanych odpowiedzi Allegro i KSeF. Stan Allegro widoczny po odpowiedzi API nie jest dowodem odczytania PDF przez klienta.
- GET dokumentow Allegro nie zwraca sumy kontrolnej zdalnego pliku; reconciliation korzysta z ID, numeru, losowej nazwy pliku i danych uploadu. Timeout nie powoduje slepego ponownego POST.
- Brak potwierdzenia Faktury zrodlowej w OMS nie oznacza, ze pliku nie ma w Allegro, np. dodanego przez Base lub recznie. Dlatego automat oczekuje na potwierdzenie, a reczna decyzja pozostaje dostepna z ostrzezeniem.
- Automaty i kolejki korzystaja z istniejacego lokalnego harmonogramu. Uwierzytelnianie calego OMS pozostaje osobnym etapem.

## Podstawa API

- [Allegro: uproszczone zalaczanie faktur](https://developer.allegro.pl/news/zamowienia-uproscilismy-zalaczanie-faktur-jn0aj75mxcW)
- [Oficjalna dokumentacja Allegro REST API](https://developer.allegro.pl/documentation/)
- [Base: integracja Allegro](https://base.com/pl-PL/pomoc/wiedza/allegro/)
