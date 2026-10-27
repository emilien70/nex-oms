# Regresja: 72 znane niepowodzenia

## Zakres i stan poczatkowy

- Galaz: `main`.
- HEAD: `b6951894ef429124e28502e06a9125fb95cc273a`.
- Zachowano zastane, niezacommitowane zmiany PORTAL/PayNow.
- Nie wykonano commita, push, operacji na VPS ani migracji bazy roboczej.
- Lokalny runtime: PHP 8.2.12 i SQLite 3.39.2 przez PDO.

Pelny raport sprzed tego etapu zawieral 4521 testow, 41577 asercji,
72 niepowodzenia, zero bledow wykonania i zero pominietych testow.
Przed edycja ponowiono wszystkie 12 klas z niepowodzeniami: 388 testow,
3605 asercji, dokladnie te same 72 niepowodzenia, zero bledow.

## Przyczyny i poprawki

### NEX-Printer: dokument bez zamowienia

`invoices.order_id` jest opcjonalny. Widok menu wydruku i kontroler odpowiedzi
JSON bezwarunkowo budowaly adres `orders.show`, co dla pustej relacji
powodowalo HTTP 500. Widok przekazuje teraz puste dane odsylacza, a JSON
zwraca `order: null`. Powiazane dokumenty zachowuja dotychczasowy adres.
Nie zmieniono generowania PDF, uprawnien ani serwisu zlecania wydruku.

Nowy test korzysta z wylacznie syntetycznej faktury bez relacji do zamowienia.
Sprawdza liste, akcje PDF i drukowania, prywatny plik, sume kontrolna,
niezmiennosc dokumentu i ponowienie tego samego zlecenia bez duplikatu.
Istniejacy test sprawdza dodatkowo adres powiazanego zamowienia w JSON.

### Kolejki KSeF: 60 niepowodzen

Przygotowanie faktury generowalo prawidlowe zadanie
`EvaluateAutomationEventJob`, a testy oczekiwaly calkowicie pustej kolejki.
W pomocniku scenariuszy recovery tylko zdarzenie `InvoiceIssued` podczas
przygotowania faktury jest lokalnie symulowane przez `Event::fakeFor()`.
Po przygotowaniu przywracany jest poprzedni dispatcher; pozostawiono
dotychczasowe asercje braku HTTP, zadan i zdarzen badanej operacji.

Testy polityki automatycznej wysylki dopuszczaja dokladnie jedno oczekiwane
zadanie automatyzacji dla wystawionej faktury i wykluczaja pozostale zadania.
W przypadku Faktury sprawdzaja tez jawnie brak zadania automatycznej wysylki
KSeF. Produkcyjnych listenerow i logiki KSeF nie zmieniono.

### Certyfikaty Offline: 4 niepowodzenia

Domyslne daty przykladowej odpowiedzi API wygasly 2026-10-01.
Testy uzywaja teraz przedzialu obejmujacego biezacy czas, z zachowaniem
jawnych dat dla scenariuszy wygasniecia, przyszlej waznosci i niezgodnosci.
Zachowano sprawdzanie konwersji UTC / Europe/Warsaw oraz ponownego odczytu.
Nie zmieniono walidatora ani zasad gotowosci rzeczywistych certyfikatow.

### Interfejs: 4 niepowodzenia

- Dwa testy zrodla zamowienia sprawdzaja aktualna nazwe integracji z kanalem,
  zamiast dawnej struktury HTML. Zachowano przypadek braku powiazania.
- Test odswiezania zamowienia uwzglednia historie automatycznych akcji.
- Test edycji korekty oczekuje braku pustego panelu KSeF dla niewlaczonej serii.
  Istniejace testy widocznosci nadal sprawdzaja wlaczone serie oraz historie
  Online/Offline. Asercje zapisu korekty i ochrony jej tozsamosci pozostaly.

### Kopiowanie pozycji: 1 niepowodzenie

Kod zachowywal `product_id`, ale test zakladal niegwarantowana kolejnosc
odczytu relacji. SQLite wykorzystywalo indeks `(order_id, product_id)`,
ktory moze zwracac pozycje reczna przed katalogowa. Test identyfikuje teraz
obie pozycje po powiazaniach, wymaga dokladnie dwoch pozycji i sprawdza ich
snapshoty, ceny, VAT, wage oraz brak tworzenia dodatkowych produktow.
Nie zmieniono kontrolera kopiowania ani kolejnosci relacji w aplikacji.

## Zmienione pliki

Zmodyfikowane w tym etapie:

1. `Modules/Integrations/NexPrinter/Http/Controllers/NexPrinterInvoiceController.php`
2. `resources/views/integrations/nex-printer/_print-dropdown.blade.php`
3. `tests/Feature/Integrations/NexPrinterTest.php`
4. `tests/Support/Ksef/CreatesSubmissionRecoveryScenario.php`
5. `tests/Feature/Ksef/KsefAutomaticInvoiceSubmissionTest.php`
6. `tests/Feature/Ksef/KsefManualCorrectionSubmissionTest.php`
7. `tests/Feature/Ksef/KsefOfflineCertificateRemoteVerificationTest.php`
8. `tests/Feature/OrderIdentifiersTest.php`
9. `tests/Feature/OrderStatusPollingTest.php`
10. `tests/Feature/Invoices/InvoiceCorrectionTest.php`
11. `tests/Feature/Products/OrderCatalogProductTest.php`

Utworzono `docs/regression-72-report.md`. Nie usunieto zadnych plikow.

## Weryfikacja

Testy uruchamiano przez istniejacy izolowany runner:

```powershell
[xml]$before = Get-Content storage/app/private/qa/regression-72-before.xml -Raw
$names = @($before.SelectNodes('//testcase[failure or error]') | ForEach-Object {
    $_.name -replace ' with data set.*$', ''
} | Sort-Object -Unique)
$filter = ($names -join '|') + '|NexPrinterTest|KsefCorrectionPanelVisibilityTest'
& C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --direct-phpunit "--filter=$filter" --log-junit=storage/app/private/qa/regression-72-focused.xml
& C:\xampp\php\php.exe tests/Support/Invoices/run-isolated.php --log-junit=storage/app/private/qa/regression-72-full.xml
```

Runner wymusza `APP_ENV=testing`, SQLite `:memory:`, prywatne pliki,
logi, cache i katalogi tymczasowe. Testy wspolbieznosci uzywaja wlasnych
jednorazowych plikow SQLite. PHP blokuje rzeczywiste transporty sieciowe;
testy korzystaja z symulowanego API. Nie uruchamiano schedulera ani workerow.

- Kontrola przed zmianami: 388 testow / 3605 asercji / 72 niepowodzenia.
- Zestaw ukierunkowany: 110 testow / 1249 asercji / PASS.
- Wszystkie 72 poprzednio nieudane przypadki sa obecne i przechodza
  w zestawie ukierunkowanym; brak pominietych przypadkow.
- Nowy test wydruku osobno: 1 test / 19 asercji / PASS.
- Pelna regresja: 4522 testy / 41742 asercje / PASS, zero niepowodzen,
  bledow wykonania i pominietych testow. Czas runnera: 1605.96 s.
- Porownanie z pelnym baseline: 72 niepowodzenia ustapily, zero nowych
  lub wspolnych niepowodzen, zero usunietych testow. Jedyny dodany test
  NEX-Printer przechodzi. Tozsamosci przypadkow porownano po klasie i nazwie
  wraz z zestawem danych, nie tylko po licznikach.
- Pint `--test`: PASS dla 10 zmienionych plikow PHP.
- `php -l`: PASS dla 10 zmienionych plikow PHP; Blade sprawdzany przez rendering.
- `git diff --check`: PASS. Istniejace ostrzezenie CRLF dla product-spec
  nie jest bledem bialych znakow i nie wymaga zmiany tego pliku.

Raporty maszynowe pozostaja lokalnie w `storage/app/private/qa/`:
`regression-72-before.xml`, `regression-72-focused.xml`,
`regression-72-printer.xml`, `regression-72-full.xml`.

## Migracje i weryfikacja reczna

Nie dodano migracji. Nie trzeba migrowac bazy ani restartowac kolejek.

1. Otworz liste Faktur: sprawdz numery, PDF i menu drukarek.
2. Dla dokumentu powiazanego sprawdz, ze odniesienie do zamowienia dziala.
3. Sprawdz etykiete zrodla PrestaShop i ukrycie panelu KSeF dla korekty
   w niewlaczonej serii bez historii KSeF.
4. Na zamowieniu testowym skopiuj pozycje katalogowa i reczna; porownaj
   nazwy, ceny, VAT i wage, nie zakladajac ich kolejnosci.

Brak relacji do zamowienia jest pokryty testem syntetycznym: nie odpinaj
rzeczywistych dokumentow ani nie usuwaj zamowien w celu sprawdzenia poprawki.
Nie trzeba zlecac rzeczywistego wydruku ani transmisji KSeF.

## Ograniczenia

Nie wykonywano testow na VPS, MySQL ani fizycznej drukarce. Nowy test NEX-Printer
symuluje zawartosc PDF; nie modyfikuje istniejacego silnika dokumentow.
Pelna lokalna regresja zostala zakonczona i porownana z baseline. Nie jest
to deklaracja sprawdzenia infrastruktury VPS ani rzeczywistych integracji.

## Statystyka zmian

`git diff --stat` ograniczony do 11 plikow kodu i testow tego etapu:
99 dodanych i 37 usunietych linii. Nowy raport jest osobnym, niesledzonym
jeszcze plikiem dokumentacji. Statystyka nie obejmuje zastanych zmian portalu.
