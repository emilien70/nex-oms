# Allegro Ceny - import zamowien

Data: 2026-11-02. Zakres: lokalny OMS, bez wdrozenia na VPS.

## Przyczyna

Normalizator blokowal kazde niepuste `reconciliation` na pozycji, platnosci
lub doplacie. Poprawne Allegro Ceny trafialo przez to do `review`.
Zapis tymczasowy celowo nie ksieguje wplat i nie uzupelnia punktu odbioru.
Nie byl to brak danych w Allegro ani powod do recznego oznaczenia wplaty.

Lokalne zamowienie 382 mialo kompletne `READY_FOR_PROCESSING`, cene i sume
513.13 PLN, potwierdzone `paidAmount=513.13 PLN` oraz jednostkowe wyrownanie
`BILLING=10.47 PLN`, ilosc 1. Tylko obecnosc tego wyrownania blokowala import.

## Kontrakt

- `BILLING` i `WALLET` na pozycji sa rozpoznawane i walidowane.
- Ilosc musi byc dodatnia liczba calkowita, nie wieksza od ilosci pozycji.
- Kwoty sa nieujemne, w istniejacym zakresie i precyzji, w walucie zamowienia.
- Rekonsyliacja platnosci i doplat ma format kwoty, nie rekonsyliacji pozycji.
- Brak/null oznacza brak wyrownania; falsy, puste lub bledne struktury nie
  omijaja walidacji.
- Ceny nadal pochodza z `price`, a nie z `originalPrice`.
- Kwota klienta nadal pochodzi z potwierdzonego `paidAmount`. Wyrownanie
  sprzedawcy nie jest dodatkowa wplata klienta ani dodatkowa pozycja.
- Brak wymagania konkretnego oznaczenia rabatu lub rownania miedzy
  wyrownaniami pozycji i platnosci. W szczegolnosci `CAMPAIGN` nie blokuje
  poprawnego zakupu.
- Kontrole sumy pozycji/uslug/dostawy, waluty, nadplaty, dat, ID platnosci,
  pobrania, rodzaju realizacji i kompletnosci pozostaja aktywne.

Zrodlo kontraktu:
[Dokumentacja Allegro - Allegro Ceny](https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR#jak-rozpoznac-zamowienie-zlozone-w-ramach-programu-allegro-ceny).

Zmiana dotyczy importu i kompletowania. Osobna blokada zlecania zwrotow wplat
dla Allegro Ceny w `AllegroRefundContextService` pozostaje bez zmian.

## Pliki

Zmienione:

- `Modules/Integrations/Allegro/Services/AllegroOrderPreviewService.php`
- `docs/product-spec.md`
- `docs/architecture.md`

Utworzone:

- `tests/Feature/AllegroPriceReconciliationTest.php`
- `docs/allegro-price-reconciliation-report.md`

Nie usuwano plikow. Zastane zmiany pozostaly zachowane. Bez migracji,
nowych zaleznosci, zmian ustawien i operacji Git zapisujacych historie.

## Testy

Wszystkie testy korzystaly z SQLite `:memory:` i symulowanego HTTP.

1. Baseline przed zmiana: cztery zestawy OrderImport, AutomaticOrderImport,
   OrderCatalog, OrderInformation: 151 testow, 847 asercji, PASS.
2. Te same zestawy i nowy PriceReconciliation: 195 testow, 1179 asercji, PASS.
3. Wszystkie `tests/Feature/Allegro*Test.php`: 535 testow, 3746 asercji, PASS.
4. Pint dla dwoch zmienionych/nowych plikow PHP: PASS.
5. `php -l` obu plikow PHP: PASS.
6. `git diff --check`: PASS.

Nie uruchamiano pelnego zestawu aplikacji ani osobnego zestawu KSeF.
Testy integracji Allegro obejmuja istniejace scenariusze dokumentow z fake API;
nie wysylaly rzeczywistych dokumentow, SMS, e-maili ani przesylek.

## Lokalna kopia

Przed ponowieniem pobrania wykonano spojna kopie SQLite przez `VACUUM INTO`
z polaczenia tylko do odczytu:

`storage/app/private/backups/allegro-ceny-382-20261102_143414-6760d194.sqlite`

SHA-256:
`af0e6abb5f4b550f38aef9793fe0908459e51cfd3288276afeb72e62fe29caa6`

Rozmiar: 19050496 bajtow. `integrity_check=ok`, brak naruszen FK.
Kopia jest prywatna i nie nalezy do plikow przeznaczonych do Git.

## Ponowienie lokalne

Przed ponowieniem sprawdzono tozsamosc konta, srodowisko, UUID zakupu,
stan `review`, brak dokumentow, przesylek, prob nadania, zwrotow,
powiazan magazynowych pozycji oraz aktywnych regul zdarzen utworzenia,
skompletowania i rejestracji wplaty. Ponowienie korzysta z istniejacej sciezki
`SyncAllegroOrderJob` i jej wspolnej blokady zamowienia. Nie resetuje kursora
ani `completed_at`, nie uruchamia masowego pobierania historii.

Ponowiono tylko trzy nieudane zdarzenia tego zakupu, od najnowszego:
519 (`READY_FOR_PROCESSING`), 518 (`FILLED_IN`), 517 (`BOUGHT`). Kazde
odczytalo aktualny checkout-form istniejacym klientem z wymaganym User-Agent.
Wszystkie maja teraz `processed`; nie zerowano licznikow prob. Pierwsza
proba odczytu byla zablokowana ograniczeniami sieci narzedzia i nie zmienila
zamowienia. Po przyznaniu dostepu wykonano trzy poprawne GET. Inne metody
i adresy HTTP byly na czas tej operacji blokowane.

Wynik lokalny:

- Ten sam numer OMS 382 i powiazanie Allegro 144, bez duplikatu.
- `import_state=complete`, `completed_at` zapisane, `processing_error=null`.
- `total_gross=513.13`, `paid_amount=513.13`, `payment_status=paid`.
- Jedna zapisana wplata, jedno zdarzenie `allegro_order_completed`.
- Uzupelnione dane rozliczeniowe i punkt odbioru.
- Status, uwagi, gwiazdka i data utworzenia pozostaly bez zmian.
- Porownanie z kopia: brak zmian innych zamowien i ich pozycji,
  wszystkich Faktur, pozycji dokumentow i przesylek.
- Istniejacy observer portalu zmienil tylko `generation` i `updated_at`
  linku 382 po uzupelnieniu adresow. Kod i adres portalu pozostaly niezmienne.
- `quick_check=ok`, brak naruszen FK.

Nie zmieniano recznie kwot ani flagi oplacenia. Nie wyslano niczego do Allegro,
KSeF, klientow ani przewoznika. Aktywne reguly wymienionych zdarzen nie
istnialy, wiec normalne zdarzenie kompletowania nie uruchomilo akcji.

Panel sterowania nie byl widoczny jako otwarte okno. Poproszono wlasciciela
o jego otwarcie do restartu tylko lokalnego Main Queues albo o samodzielny
restart. Wczytanie kodu przez dlugo dzialajacy worker wymaga potwierdzenia;
nie wykonywano globalnego `queue:restart`, restartu KSeF ani VPS.

## Weryfikacja reczna

1. Po wczytaniu kodu przez lokalne Main Queues otworzyc zamowienie 382.
2. Sprawdzic brak komunikatu o koniecznosci sprawdzenia Allegro Ceny.
3. Sprawdzic sume 513.13 PLN i oplacenie 513.13 PLN, bez dodawania 10.47 PLN.
4. Sprawdzic dane fakturowe i punkt odbioru oraz zachowane uwagi/status/gwiazdke.
5. Ponowne pobranie kompletnego zakupu nie powinno nadpisywac recznych zmian
   ani tworzyc duplikatu zamowienia lub wplaty.
