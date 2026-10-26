# NEX-OMS — architektura systemu

## Status dokumentu

Ten dokument opisuje:

- aktualną architekturę lokalnego projektu NEX-OMS,
- zasady techniczne obowiązujące przy dalszym rozwoju,
- docelową architekturę modułu faktur,
- granice między modułami,
- sposób etapowego wdrażania zmian.

Stan faktyczny kodu należy zawsze weryfikować w lokalnym katalogu:

```text
C:\projekty\nex-oms
```

Dokument powstał na podstawie audytu Etapu 0 oraz zatwierdzonych wymagań produktowych.

Aktualizacja operacyjna 2026-10-24: staging OVHcloud/Ubuntu ma jeden cron Laravel i siedem workerów Supervisor. [Kolejki, harmonogram i Supervisor na VPS](007-Queues-Scheduler-VPS.md) zawiera pełny audyt 31 jobów, 18 zadań harmonogramu, aktywne limity oraz rozdzielenie danych operatora od analizy kodu. Historyczne wpisy o niewybranym hostingu lub nieuruchomionych workerach opisują stan swoich etapów, nie obecną migawkę stagingu. Nie oznacza to zamknięcia rollout'u KSeF Production ani włączenia automatycznej wysyłki.

Późniejsza lokalna [poprawka odporności Automation](008-Automation-Execution-Recovery.md) dodaje transakcyjny claim z tokenem i dzierżawą, terminalny stan interrupted oraz dziewiętnasty callback harmonogramu, oznaczający porzucone wykonania bez ponawiania ich akcji. Wymaga addytywnej migracji; nie została wdrożona na VPS. Osobny odczyt SSH potwierdził stopwaitsecs wszystkich siedmiu programów i ich stan RUNNING, bez zmian lub restartów.

W przypadku sprzeczności:

1. lokalny kod określa aktualny stan techniczny,
2. `docs/product-spec.md` określa zatwierdzone wymagania biznesowe,
3. ten dokument określa docelowy kierunek architektoniczny,
4. `AGENTS.md` określa zasady pracy Codexa.

---

# 1. Styl architektury

NEX-OMS jest modularnym monolitem opartym na Laravel.

Główne założenia:

- jedna aplikacja,
- jedna baza danych,
- centralny routing,
- centralne migracje,
- wydzielone katalogi modułów domenowych,
- brak mikroserwisów,
- brak osobnych baz per moduł,
- rozwój małymi etapami.

Aktualne moduły:

```text
Modules/Automation
Modules/Analytics
Modules/Integrations
Modules/Invoices
Modules/Shipments
```

Katalogi organizacyjne:

```text
Modules/Customers
Modules/Dashboard
Modules/Emails
Modules/Orders
Modules/Products
```

Nie należy tworzyć nowej konwencji modułowej bez wyraźnej potrzeby.

---

# 2. Środowisko techniczne

Aktualny stos:

| Obszar | Technologia |
|---|---|
| Backend | PHP 8.2+ |
| Framework | Laravel 12 |
| Widoki | Blade |
| UI | Bootstrap 5.3 |
| Ikony | Bootstrap Icons |
| Frontend build | Vite |
| Lokalna baza | SQLite |
| Baza testowa | SQLite `:memory:` |
| Kolejka lokalna | database |
| Kolejka testowa | sync |
| Testy | PHPUnit 11 |
| Autoload modułów | PSR-4 `Modules\` |

Środowisko lokalne działa na Windows/XAMPP.

Jeżeli `php` nie jest dostępne globalnie:

```text
C:\xampp\php\php.exe
```

---

# 3. Aktualny układ projektu

## 3.1. Routing

Trasy są rejestrowane centralnie w:

```text
routes/web.php
```

Aktualnie moduły nie posiadają własnych plików tras.

Dla modułu faktur należy zachować centralny routing, dopóki liczba tras nie uzasadni świadomego wydzielenia.

## 3.2. Migracje

Migracje znajdują się centralnie w:

```text
database/migrations
```

Nowe tabele modułu faktur również powinny trafiać do tego katalogu.

Nie należy umieszczać migracji w `Modules/Invoices` bez osobnej decyzji architektonicznej.

## 3.3. Modele

Modele głównej domeny zamówień znajdują się w:

```text
app/Models
```

Nowe modele modułu faktur powinny znajdować się w:

```text
Modules/Invoices/Models
```

## 3.4. Kontrolery

Kontrolery modułu faktur:

```text
Modules/Invoices/Http/Controllers
```

## 3.5. Form Requesty

Docelowa lokalizacja:

```text
Modules/Invoices/Http/Requests
```

## 3.6. Serwisy domenowe

Docelowa lokalizacja:

```text
Modules/Invoices/Services
```

Dla prostych, jednozadaniowych operacji można później rozważyć:

```text
Modules/Invoices/Actions
```

Nie należy wprowadzać warstwy `Actions` w Etapie 1A, jeśli nie jest jeszcze potrzebna.

## 3.7. Enumy

Docelowa lokalizacja:

```text
Modules/Invoices/Enums
```

## 3.8. Widoki

Aktualnie widoki faktur znajdują się w:

```text
resources/views/invoices
```

Należy zachować tę konwencję.

## 3.9. Testy

Rekomendowany układ:

```text
tests/Unit/Invoices
tests/Feature/Invoices
```

---

# 4. Istniejący szkielet faktur

W projekcie istnieją już:

```text
Modules/Invoices/Http/Controllers/InvoiceController.php
routes/web.php — GET /invoices
resources/views/invoices/index.blade.php
tests/Feature/InvoicesPageTest.php
link „Faktury” w sidebarze
przyciski WYSTAW FAKTURĘ i PRO FORMA na karcie zamówienia
```

Te elementy są szkieletem.

Zasada dalszej rozbudowy:

- rozbudowywać istniejący kontroler,
- zachować istniejącą trasę,
- przebudować istniejący widok,
- rozszerzać istniejący test,
- nie tworzyć równoległego modułu faktur,
- nie tworzyć drugiego `InvoiceController`.

---

# 5. Granice domen

## 5.1. Orders

Odpowiada za:

- przyjęcie zamówienia,
- dane klienta,
- dane do faktury,
- dane dostawy,
- pozycje zamówienia,
- płatność,
- wysyłkę,
- uwagi sprzedawcy,
- status zamówienia,
- historię zamówienia.

Zamówienie jest źródłem danych wejściowych do pierwszego wystawienia dokumentu.

`OrderPaymentStateService` jest centralnym kontraktem relacji `total_gross`, `paid_amount` i `payment_status`. Obsługuje dokładne wartości dziesiętne, utrzymuje dwa trwałe statusy `unpaid`/`paid`, synchronizuje stan po zmianie wpłaty lub sumy i odrzuca sprzeczne jawne dane wejściowe. `InvoiceSnapshotBuilder` wyłącznie waliduje ten stan przed snapshotem i nigdy nie naprawia zamówienia.

Po wystawieniu faktury nie jest źródłem prawdy dla dokumentu historycznego.

## 5.2. Invoices

Odpowiada za:

- serie numeracji,
- liczniki numeracji,
- faktury,
- pro formy,
- korekty,
- duplikaty,
- snapshoty,
- pozycje dokumentu,
- obliczenia podatkowe,
- PDF,
- pliki zewnętrzne,
- zdarzenia cyklu życia dokumentu bez kopii poprzednich stanów,
- rejestr sprzedaży,
- dane do JPK.

## 5.3. Products

Od etapu PRODUCTS.1A odpowiada za lokalny katalog produktów:

- wewnętrzne ID NEX,
- nazwę i opcjonalny EAN,
- cenę brutto i walutę,
- standardową lub własną stawkę VAT,
- informacyjny, również ujemny stan magazynowy,
- wagę i opcjonalny komplet wymiarów opakowania,
- prywatny oryginał zdjęcia i generowaną miniaturę,
- aktywność produktu realizowaną przez ukrywanie i ponowne aktywowanie.

PRODUCTS.1B dodaje opcjonalne powiązanie pozycji zamówienia z katalogiem oraz wybór wielu produktów na karcie zamówienia. Operator może przed zapisem zmienić ilość, cenę brutto pozycji i wspólną walutę dodawanego wyboru bez modyfikowania danych katalogowych. Wybranie waluty nie przelicza kwot. Integracje z Allegro, PrestaShop i innymi kanałami pozostają poza tym etapem.

## 5.4. Integrations

Odpowiada za:

- komunikację z Allegro,
- PrestaShop,
- InPost,
- PayNOW,
- InPost Pay,
- przyszłe zewnętrzne API.

Przyszły klient KSeF powinien znaleźć się w module integracji albo w wydzielonej warstwie integracyjnej modułu faktur, ale dopiero po audycie gotowości KSeF.

## 5.5. Shipments

Odpowiada za operacje przesyłkowe.

Faktura przechowuje snapshot kosztu i nazwy wysyłki, ale nie zarządza logistyką przesyłki.

---

# 6. Model zamówienia jako źródło danych

Aktualnie dane klienta i adresy są zapisane bezpośrednio na `orders`.

To jest korzystne dla wystawiania dokumentów, ponieważ:

- nie ma zależności od osobnego rekordu klienta,
- zamówienie zachowuje własny stan danych,
- faktura może utworzyć snapshot bez dodatkowych joinów.

Przy tworzeniu dokumentu należy mapować dane z `Order`, ale nie przechowywać wyłącznie referencji.

Przykład:

```text
Order.billing_name
→ Invoice.buyer_snapshot.name
```

Zmiana `Order.billing_name` po wystawieniu nie może zmienić faktury.

---

# 7. Pozycje zamówienia i ryzyka

Aktualne pozycje zamówienia przechowują głównie:

- nazwę,
- ilość całkowitą,
- cenę jednostkową brutto,
- wartość brutto,
- opcjonalną stawkę VAT,
- walutę,
- dane techniczne importu.

Aktualne ograniczenia:

- brak ceny netto,
- brak kwoty VAT,
- brak jednostki miary,
- brak rabatu,
- ilość jest całkowita,
- niektóre obliczenia używają `float`,
- edycja pozycji może usuwać i odtwarzać rekordy.

Nie należy przenosić tych ograniczeń do `invoice_items`.

Docelowe `invoice_items` mają być pełnym snapshotem podatkowym.

---

# 8. Architektura serii numeracji

## 8.1. Model

Docelowy model:

```text
Modules\Invoices\Models\InvoiceSeries
```

Tabela:

```text
invoice_series
```

## 8.2. Odpowiedzialność serii

Seria przechowuje:

- typ dokumentu,
- nazwę,
- format numeru,
- zasady resetowania,
- status aktywności,
- informację, czy jest chronioną serią systemową,
- stabilny klucz systemowy dla serii systemowych,
- dane sprzedawcy,
- rachunek bankowy,
- miejsce wystawienia,
- wystawiającego,
- szablon informacji,
- późniejsze ustawienia VAT, wysyłki, płatności, JPK/GTU i wydruku.

Seria nie przechowuje wystawionych numerów bezpośrednio.

Liczniki będą osobną tabelą.

## 8.3. Typy dokumentów

Backed enum:

```text
invoice
proforma
correction
```

Rekomendowana klasa:

```text
Modules\Invoices\Enums\InvoiceDocumentType
```

## 8.4. Resetowanie numeracji

Backed enum:

```text
monthly
yearly
none
```

Rekomendowana klasa:

```text
Modules\Invoices\Enums\InvoiceSeriesResetPeriod
```

## 8.5. Aktywność

Pole:

```text
is_active
```

Znaczenie:

- `true` — seria dostępna dla nowych dokumentów,
- `false` — seria historyczna lub ukryta.

Nie należy używać `is_visible` jako głównego pojęcia domenowego.

## 8.6. Serie systemowe

Pola techniczne:

```text
is_system
system_key
```

System posiada dokładnie trzy serie systemowe z unikalnymi kluczami `invoice`, `correction` i `proforma`. Klucz zawsze odpowiada typowi dokumentu.

Seria systemowa:

- jest zawsze aktywna,
- nie może zostać usunięta,
- nie może zostać ukryta,
- nie może zmienić typu dokumentu ani klucza,
- nie może zostać przekształcona w serię własną,
- może zmienić nazwę, format numeru i ustawienia biznesowe.

Seria własna ma `is_system = false` oraz `system_key = null`. Może być ukryta i bezpiecznie usunięta. Pole `is_default` nie jest używane.

Przy ręcznym wystawianiu dokumentu interfejs udostępnia aktywne serie właściwego typu. Automatyzacja nie rozstrzyga serii po nazwie ani kolejności: konfiguracja akcji przechowuje jawny `invoice_series_id`.

Od Etapu 1C.1 tworzenie i edycja serii korzystają z jednego modala Bootstrap. Partial formularza właściwego typu jest pobierany przez `fetch()`, ale właściwy zapis pozostaje standardowym żądaniem POST albo PATCH. Nowe serie są zawsze własne (`is_system = false`, `system_key = null`), a serwis aktualizacji serii systemowej zachowuje jej typ, klucz, aktywność i status systemowy.

## 8.7. Unikalność nazwy

Zalecane ograniczenie:

```text
UNIQUE(document_type, name)
```

Ta sama nazwa może występować dla różnych typów.

## 8.8. Domyślna seria korekt

Pole:

```text
default_correction_series_id nullable
```

Relacja typu self-reference:

```text
InvoiceSeries belongsTo defaultCorrectionSeries
```

Usunięcie wskazanej serii:

```text
nullOnDelete
```

Relacja może wskazywać wyłącznie serię typu `correction`. Ograniczenie typu należy egzekwować przez walidację domenową, nie przez sam FK.

---

# 9. Dane sprzedawcy w serii

Dane sprzedawcy należą bezpośrednio do serii.

Nie tworzymy:

```text
seller_profiles
company_settings
```

Rekomendowane jawne pola:

```text
seller_name
seller_tax_id
seller_regon
seller_bdo
seller_street
seller_building_number
seller_apartment_number
seller_postal_code
seller_city
seller_province
seller_country_code
seller_email
seller_phone
seller_bank_name
seller_bank_account
seller_bank_swift
place_of_issue
issuer_name
logo_path
```

Pola mogą być nullable podczas tworzenia roboczej serii.

Przed aktywowaniem lub użyciem serii powinny być wymagane co najmniej:

- nazwa,
- NIP,
- ulica,
- numer budynku,
- kod pocztowy,
- miasto,
- kraj.

Pełna walidacja aktywacji będzie osobnym etapem.

---

# 10. Pole „Informacje” i zmienne szablonu

Każda seria ma pole tekstowe definiujące treść sekcji informacji dodatkowych dokumentu.

Preferowana nazwa techniczna:

```text
additional_information_template
```

Przykład:

```text
Numery seryjne zakupionych przedmiotów:
[uwagi_sprzedawcy]
```

Źródło zmiennej:

```text
[uwagi_sprzedawcy]
→ pole uwag sprzedawcy w zamówieniu
→ według audytu obecnie orders.notes
```

Zasada działania:

1. seria przechowuje szablon,
2. przy wystawianiu dokumentu system pobiera szablon,
3. pobiera uwagi sprzedawcy z zamówienia,
4. zastępuje wszystkie wystąpienia `[uwagi_sprzedawcy]`,
5. zapisuje wynik jako snapshot na dokumencie.

Na fakturze powinno istnieć później pole:

```text
additional_information
```

które zawiera już wyrenderowaną treść.

Zmiana szablonu serii albo uwag zamówienia nie może zmienić wystawionego dokumentu.

Gdy zmienna jest pusta:

- podstaw pusty tekst,
- nie zostawiaj literalnego tokenu,
- nie usuwaj samodzielnie całej sekcji, chyba że reguła wydruku tak stanowi.

W przyszłości można dodać kolejne zmienne, ale muszą być:

- jawnie zdefiniowane,
- walidowane,
- dokumentowane,
- rozwiązywane po stronie serwera.

Nie używaj dowolnego kodu wykonywalnego w szablonach.

---

# 11. Planowana tabela `invoice_series`

Zakres Etapu 1A:

```text
id
document_type
name
number_format
reset_period
fiscal_year_start_month
is_active
is_system
system_key
default_correction_series_id
default_currency

seller_name
seller_tax_id
seller_regon
seller_bdo
seller_street
seller_building_number
seller_apartment_number
seller_postal_code
seller_city
seller_province
seller_country_code
seller_email
seller_phone

seller_bank_name
seller_bank_account
seller_bank_swift
place_of_issue
issuer_name
logo_path
additional_information_template

created_at
updated_at
```

Rekomendowane typy:

- krótkie teksty: `string`,
- szablon informacji: `text`,
- flagi: `boolean`,
- miesiąc: `unsignedTinyInteger`,
- typ dokumentu i reset: `string` castowany do enum,
- FK: `foreignId()->nullable()`.

Rekomendowane wartości domyślne:

```text
is_active = false
is_system = false
system_key = null
fiscal_year_start_month = 1
default_currency = PLN
reset_period = yearly
```

Seria nowo utworzona powinna być domyślnie nieaktywna, jeśli nie posiada wymaganych danych sprzedawcy.

Wyjątkiem są trzy serie systemowe tworzone przez migrację. Są zawsze aktywne, a seria `invoice` wskazuje serię `correction` przez `default_correction_series_id`.

---

# 12. Liczniki numeracji

Etap 2B wdraża tabelę `invoice_number_counters`. Każda seria ma niezależny licznik dla każdego okresu:

```text
id
invoice_series_id
numbering_period_key
last_sequence_number
protected_floor_sequence_number
created_at
updated_at
```

Obowiązuje `UNIQUE(invoice_series_id, numbering_period_key)`. Klucz okresu ma wartość `YYYY-MM` dla resetu miesięcznego, rok rozpoczęcia okresu fiskalnego dla resetu rocznego oraz `none` dla braku resetu.

`last_sequence_number` wskazuje ostatnią techniczną sekwencję przygotowaną do kontynuacji, a następny numer to `last_sequence_number + 1`. `protected_floor_sequence_number` jest ręcznie ustanawianym progiem, poniżej którego przyszłe cofnięcie wolnego końca numeracji nie może zejść.

Ręczne operacje „Ustaw następny numer” są zapisywane w niezmiennej tabeli `invoice_number_counter_adjustments` razem z poprzednim i nowym stanem, powodem, snapshotem serii, aktora i metadanymi. Źródłem bieżącego stanu pozostaje licznik, nie historia.

`InvoiceNumberingPeriodResolver` centralnie ustala okres, a `InvoiceNumberFormatter` centralnie składa numer zgodnie z tokenami `%N`, `%NN...`, `%M`, `%Y` i `%y`. `InvoiceNumberingService` nadaje numer istniejącemu szkicowi w transakcji, z blokadą rekordu licznika i obsługą konfliktów. Nie ustawia statusu `issued`, nie tworzy pozycji ani snapshotów zamówienia.

Ograniczenia `UNIQUE(invoice_series_id, numbering_period_key, sequence_number)` oraz istniejące `UNIQUE(invoice_series_id, number)` są końcową ochroną przed duplikatem, również na SQLite, gdzie `lockForUpdate()` ma ograniczone działanie. Numer nie jest obliczany przez niezabezpieczone `MAX + 1` i nie jest generowany w przeglądarce.

---

# 13. Docelowy model faktury

Planowany model:

```text
Modules\Invoices\Models\Invoice
```

Tabela:

```text
invoices
```

Relacje:

```text
Order hasMany Invoices
Invoice belongsTo Order
Invoice belongsTo InvoiceSeries
Invoice hasMany InvoiceItems
Invoice hasMany InvoiceEvents
Invoice hasMany InvoiceFiles
```

Nie dodajemy:

```text
orders.invoice_id
```

Jedno zamówienie może mieć:

- najwyżej jedną istniejącą fakturę VAT,
- najwyżej jedną logiczną Pro formę z trwałą historią wersji,
- korekty,
- zewnętrzne dokumenty.

Relacja nadal pozostaje `Order hasMany Invoices`, ponieważ obejmuje różne typy dokumentów i historię. Zaimplementowany `InvoiceIssuingService` jest centralnym wejściem dla ręcznego wystawiania, automatyzacji, API i integracji. Akcja automatyczna `issue_invoice` przechowuje jawny `invoice_series_id`, a konfigurator dopuszcza wyłącznie aktywne serie typu `invoice`. Wykonanie ponownie sprawdza serię w warstwie domenowej. Regułę jednej faktury VAT egzekwuje transakcja, kontrola domenowa i unikalny slot dokumentu. Próba ponownego wystawienia zwraca błąd biznesowy `invoice_already_exists`, bez pobierania kolejnego numeru.

---

# 14. Snapshoty faktury

Faktura musi być samowystarczalna.

Planowane snapshoty:

```text
seller_snapshot
buyer_snapshot
recipient_snapshot
payment_snapshot
shipping_snapshot
```

Możliwe sposoby przechowywania:

- jawne kolumny,
- JSON,
- kombinacja obu.

Rekomendacja:

- kluczowe pola wyszukiwane i filtrowane przechowywać jawnie,
- pełne snapshoty przechowywać dodatkowo w JSON,
- nie polegać wyłącznie na relacjach do bieżących danych.

Zaimplementowane w Etapie 2A jawne pola dokumentu obejmują:

```text
number
document_type
invoice_series_id
order_id
issue_date
sale_date
payment_due_date
currency
total_net
total_vat
total_gross
paid_amount
amount_due
buyer_name_snapshot
buyer_tax_id_snapshot
status
additional_information_text
```

Pełne dane historyczne są przechowywane równolegle w jawnych polach wyszukiwalnych oraz walidowanych snapshotach JSON. Model nie synchronizuje ich automatycznie z zamówieniem ani serią.

---

# 15. Pozycje faktury

Zaimplementowana tabela:

```text
invoice_items
```

Najważniejsze zaimplementowane pola:

```text
id
invoice_id
order_item_id nullable
product_id nullable
source_invoice_item_id nullable
line_type
position
name
description
quantity
unit_name
unit_price_net
unit_price_gross
vat_rate
vat_code
total_net
total_vat
total_gross
gtu_codes
product_snapshot
metadata
correction_before_snapshot
correction_after_snapshot
correction_difference_snapshot
created_at
updated_at
```

Ważne zasady:

- `product_id` jest opcjonalne, indeksowane i nie posiada jeszcze klucza obcego,
- nazwa i dane podatkowe są snapshotem,
- usunięcie produktu nie usuwa pozycji,
- ilość może być dziesiętna,
- obliczenia nie używają `float`,
- kolejność pozycji jest jawna.

---

# 16. Produkty

Tabela:

```text
products
```

Etap PRODUCTS.1A wdraża model `Modules\Products\Models\Product`, kontroler i Form Requesty katalogu oraz serwisy zapisu produktu i prywatnych zdjęć. Lista pod `/products` obsługuje wyszukiwanie po nazwie, EAN i ID NEX, filtrowanie aktywnych i ukrytych produktów, paginację z wyborem 25, 50, 100, 200, 500 lub wszystkich rekordów, tworzenie, edycję, asynchroniczną zmianę stanu magazynowego bez opuszczania listy oraz zmianę aktywności bez twardego usuwania. Menu operacji jest przenoszone na czas otwarcia ponad kontener przewijanej tabeli, dlatego nie jest przycinane przy jej krawędzi.

Cena brutto, VAT, waga i wymiary są przechowywane jako `DECIMAL`. EAN jest opcjonalny i unikalny. Stan magazynowy jest podpisaną liczbą całkowitą o charakterze informacyjnym; etap nie tworzy ruchów magazynowych, rezerwacji ani automatycznej korekty stanów.

Oryginał zdjęcia i miniatura są przechowywane na prywatnym dysku `local`. Dostęp odbywa się przez kontrolowaną trasę, a miniatura jest generowana lokalnie przez GD. Produkt może istnieć bez zdjęcia.

Relacje:

```text
Product hasMany OrderItems
Product hasMany InvoiceItems
```

`order_items.product_id` jest nullable i posiada klucz obcy do `products`:

```text
nullOnDelete
```

`invoice_items.product_id` pozostaje nullable bez klucza obcego. Wystawienie dokumentu kopiuje `product_id` oraz `internal_id` do snapshotu pozycji, dlatego późniejsze usunięcie produktu nie modyfikuje dokumentu historycznego.

Nie należy automatycznie wiązać historycznych pozycji tylko na podstawie nazwy.

Karta zamówienia udostępnia osobno wybór produktów z magazynu i formularz pozycji ręcznej. Modal magazynu pobiera wyłącznie aktywne produkty, wyszukuje po nazwie, EAN i ID, stronicuje wyniki i przechowuje wybór po stronie klienta do czasu zatwierdzenia. Po prawej stronie pokazuje wybrane pozycje z edytowalną ilością i ceną jednostkową brutto oraz sumę wyliczaną w groszach. Wspólna lista walut przy sumie domyślnie wskazuje walutę zamówienia i przypisuje ją wszystkim pozycjom danego zapisu. Puste finansowo zamówienie może przyjąć tę walutę; zamówienie z istniejącymi pozycjami lub wartościami wymaga zgodności. Mechanizm nie wykonuje przewalutowania.

Zapis wielu pozycji jest jedną transakcją. Serwer ponownie waliduje aktywność produktu, ilość, cenę i walutę, kopiuje snapshot produktu do `order_items`, przelicza zamówienie centralnymi serwisami finansowymi i zapisuje zdarzenie dla każdej pozycji. Nieudany element cofa cały wybór.

Stan magazynowy pozostaje informacyjny. Przekroczenie stanu jest sygnalizowane w interfejsie, ale PRODUCTS.1B nie tworzy rezerwacji, ruchów magazynowych ani automatycznego pomniejszania stanu.

Późniejszy etap dodaje opcjonalne pomniejszanie stanu przez silnik automatyzacji. `InvoiceIssuingService` emituje po zatwierdzeniu transakcji zdarzenie `invoice.issued`. Reguła z tym wyzwalaczem może wykonać akcję `decrease_stock`; walidacja nie dopuszcza tej akcji dla innych wyzwalaczy. Źródło zdarzenia zachowuje identyfikator nadrzędnego `automation_run`, dzięki czemu wystawienie Faktury przez automatyzację oraz następujące po nim pomniejszenie magazynu należą do jednego łańcucha i korzystają z istniejącej ochrony przed pętlą.

Trwały dziennik:

```text
product_stock_movements
```

Każdy ruch `invoice_issued` zapisuje podpisaną zmianę, stan przed i po zmianie, snapshot produktu oraz nullable powiązania z produktem, zamówieniem, pozycją zamówienia, Fakturą, pozycją Faktury i wykonaniem automatyzacji. Powiązania używają `nullOnDelete`, aby późniejsze usunięcie encji nie niszczyło dowodu historycznego.

Klucz idempotencji jest stabilny dla pary zamówienie-pozycja zamówienia. Ponowienie zadania, druga zgodna reguła oraz ponowne wystawienie Faktury nie odejmują ponownie tej samej pozycji. Serwis blokuje produkty w deterministycznej kolejności, wykonuje wszystkie zmiany i zapisuje ruchy w jednej transakcji. Uwzględnia wyłącznie pozycje produktowe z `product_id` i `order_item_id`; pozycje ręczne oraz dostawa są pomijane. Ułamkowa albo niedodatnia ilość powoduje błąd całej akcji, ponieważ bieżący stan produktu jest liczbą całkowitą.

Mechanizm nie tworzy rezerwacji i pozwala na stan ujemny. Nie odwraca ruchu po usunięciu Faktury, anulowaniu zamówienia ani wystawieniu Korekty. Takie operacje pozostają świadomą zmianą ręczną do czasu wdrożenia odrębnej akcji przywracania stanu.

Zestawy są przechowywane w tej samej tabeli products co zwykłe produkty i są rozróżniane przez item_type = bundle. Dzięki temu produkty i zestawy korzystają z jednej sekwencji ID, tych samych opcjonalnych relacji order_items.product_id i invoice_items.product_id oraz istniejącego mechanizmu powiązań ofert PrestaShop.

Formularz zestawu wspoldzieli sekcje Opakowanie produktu. SaveProductBundleRequest waliduje opcjonalne package_weight_kg (3 miejsca, >=0) oraz komplet dodatnich package_length_cm/package_width_cm/package_height_cm (2 miejsca). ProductBundleManagementService zapisuje te istniejace kolumny jako wartosci dziesietne, bez wyliczania wagi lub gabarytow ze skladnikow. OrderCatalogProductService nadal kopiuje wage jednej sztuki do order_items.weight. PrestaShop wybiera wage OMS tylko przy tworzeniu nowej pozycji powiazanego zestawu, nie podczas pozniejszego uzupelniania powiazan. AllegroOrderCatalogService zachowuje source_weight, resetuje wynik przy ponownym rozwiazaniu powiazania i uwzglednia docelowa wage w catalog_hash. Import Allegro zapisuje weight razem z pozycja; zmiana wagi po podgladzie wymaga nowego potwierdzenia.

Waga w order_items jest samodzielnym historycznym zapisem i nie jest automatycznie odczytywana z aktualnego katalogu. order_item_bundle_components nadal opisuje sklad dla magazynu, nie dodatkowa wage do doliczenia. Wymiary nie sa dodawane do order_items i nie zmieniaja ShipmentFormDefaultsService ani istniejacych przesylek. Bez migracji i bez operacji uzupelniajacej historyczne dane.

Tabela prestashop_product_links przechowuje wiele ofert jednego konta PrestaShop przypisanych do tego samego produktu albo zestawu OMS. Unikalna pozostaje trójka prestashop_account_id, external_product_id i external_product_attribute_id, dlatego jedna oferta zewnętrzna nie może wskazywać kilku pozycji OMS. Lista katalogu grupuje rekordy według konta integracji, pokazuje ich liczbę i udostępnia edycję konkretnego rekordu po jego własnym ID. Import nadal rozwiązuje pozycję OMS jednoznacznie przez konto oraz ID produktu PrestaShop.

Aktualny skład zestawu przechowuje tabela product_bundle_components. Każdy rekord wskazuje zestaw, zwykły produkt oraz dodatnią, całkowitą ilość składnika. Zestaw musi mieć co najmniej dwa różne produkty. Zagnieżdżanie zestawów jest zabronione. Dostępność zestawu jest wyliczana jako minimum z całkowitych ilorazów stan produktu / ilość składnika; stan zestawu nie jest zapisywany ani edytowany niezależnie.

Przy dodaniu zestawu do zamówienia jego skład jest kopiowany do order_item_bundle_components. Snapshot przechowuje identyfikator i nazwę składnika oraz ilość na jeden zestaw. Edycja składu katalogowego nie zmienia historycznych zamówień. Faktura nadal zawiera jedną pozycję zestawu, natomiast InvoiceStockDeductionService rozwija ją wyłącznie na potrzeby magazynu według snapshotu zamówienia. Idempotencja ruchu zestawu obejmuje pozycję zamówienia i konkretny produkt-składnik.

---

# 17. VAT i obliczenia

Obecny kod zamówień używa miejscami `float`.

Moduł faktur musi używać bezpiecznego mechanizmu dziesiętnego.

Dopuszczalne strategie:

- wartości w najmniejszej jednostce waluty,
- biblioteka decimal/money po osobnym zatwierdzeniu,
- ścisła arytmetyka na stringach dziesiętnych.

Nie należy instalować biblioteki bez osobnej zgody.

Niezależnie od wybranej strategii:

- zasady zaokrągleń muszą być centralne,
- obliczenia pozycji muszą być testowane,
- sumy dokumentu muszą wynikać z pozycji,
- zapisane netto, VAT i brutto nie mogą być później wyliczane wyłącznie „na żywo”.

Docelowy serwis:

```text
Modules/Invoices/Services/InvoiceCalculationService
```

---

# 18. JPK i GTU

Konfiguracja serii może później zawierać:

```text
default_gtu_codes JSON
jpk_procedure_codes JSON
gtu_source_strategy
```

Strategie:

```text
series_only
products_only
merge
```

Rekomendacja:

```text
merge
```

Listy kodów:

- muszą być walidowane,
- nie mogą przyjmować dowolnego tekstu,
- powinny być opisane enumami lub klasami stałych,
- muszą zostać zapisane jako snapshot dokumentu.

Pełny eksport JPK będzie osobnym modułem funkcjonalnym po uruchomieniu rejestru sprzedaży.

---

# 19. Historia zdarzeń

Aktualny wzorzec `order_events` może zostać wykorzystany koncepcyjnie.

Docelowa tabela:

```text
invoice_events
```

Planowane pola:

```text
id
invoice_id
event_type
title
description
payload JSON
created_at
updated_at
```

Podział odpowiedzialności:

```text
invoice_events — wybrane zdarzenia cyklu życia dokumentu
order_events   — skrócone zdarzenie widoczne na zamówieniu
```

Przykład:

```text
invoice_events:
- document_issued
- pdf_generated
- duplicate_generated
- correction_issued
- email_sent

order_events:
- invoice_issued
- proforma_issued
- correction_issued
```

Istotne operacje powinny zapisywać dokument i zdarzenia w jednej transakcji.

---

# 20. PDF i pliki

Etap 2D korzysta z `tecnickcom/tcpdf`. `InvoicePdfService` orkiestruje `InvoicePdfRenderer`, prywatny `InvoicePdfStorage`, deterministyczne nazwy plików, fonty i centralne formatowanie kwoty słownie. Renderer czyta wyłącznie snapshoty `Invoice` i `InvoiceItem`; zmiana aktualnego `Order` lub `InvoiceSeries` nie zmienia historycznego PDF.

Pliki powstają na żądanie, poza transakcją wystawiania dokumentu. Najpierw zapisywany jest plik tymczasowy, a następnie atomowo przenoszony pod docelową ścieżkę na prywatnym dysku `local`. Każdy dokument ma jedną deterministyczną ścieżkę bieżącego cache zależną od typu i wersji layoutu. Kontrolowana trasa `GET /invoices/{invoice}/pdf` odrzuca szkice i niekompletne snapshoty, nie ujawnia ścieżki storage oraz zwraca `application/pdf`, `Content-Disposition: inline` i prywatne nagłówki bez cache.

TCPDF generuje A4 w orientacji pionowej bez domyślnego nagłówka i stopki, numerów stron oraz oznaczenia generatora. Nagłówki korzystają z Helvetica. Tekst korzysta z Verdany tylko wtedy, gdy font jest zarejestrowany i rzeczywiście dostępny; bezpiecznym fallbackiem Unicode jest DejaVu Sans.

Docelowa tabela plików:

```text
invoice_files
```

Przykładowe pola:

```text
id
invoice_id
type
disk
path
original_name
mime_type
size
checksum
is_customer_primary
metadata JSON
created_at
updated_at
```

Typy:

```text
generated_pdf
external_pdf
duplicate_pdf
correction_pdf
```

Zasady:

- pliki przechowywane prywatnie,
- brak bezpośredniego publicznego URL,
- pobieranie przez kontroler lub podpisany URL,
- kontrola dostępu,
- PDF zewnętrzny bez OCR,
- checksum do wykrywania zmian i duplikatów.

---

# 21. E-mail i kolejki

Wysyłka e-mail powinna być asynchroniczna.

Przed kolejką powstaje niezmienny snapshot treści i prywatnych załączników. Operację chronią `idempotency_key` wraz z fingerprintem intencji, sealed manifest załączników oraz HMAC transportowo istotnej konfiguracji SMTP; jej zmiana przed wysłaniem kończy wiadomość kontrolowanym błędem. Scheduler co pięć minut ponownie dispatchuje wyłącznie stare, nietknięte rekordy `queued`, a atomowy claim w bazie pozostaje ostateczną ochroną przed podwójnym transportem.

Wynik `unknown` nie jest automatycznie ponawiany. Ręczne UI na karcie zamówienia przygotowuje wiadomość przez centralny `OrderEmailPreparationService`, wymaga jawnie wybranego konta SMTP, a opcjonalny szablon może dostarczyć treść i zamrożone załączniki. Wybrane konto jest częścią fingerprintu intencji. Po zapisie interfejs przez krótki polling odświeża wyłącznie status własnej wiadomości; nie ponawia transportu i nie odczytuje poczty przychodzącej.

Automation udostępnia akcję `send_email` z obowiązkowym `email_template_id`. Konto SMTP, treść i załączniki pochodzą z szablonu; szablony ukryte na liście ręcznej pozostają dostępne dla reguł. Konfiguracja odbiorców zawiera domyślnie klienta oraz maksymalnie dziesięć dodatkowych, znormalizowanych adresów. Adres klienta jest rozwiązywany z zamówienia w chwili wykonania. Każdy unikalny odbiorca otrzymuje osobny `OrderEmailMessage`, dzięki czemu adresy nie są ujawniane pozostałym odbiorcom, a wynik transportu jest śledzony niezależnie. Brak wymaganego adresu klienta zatrzymuje akcję przed przygotowaniem jakiejkolwiek wiadomości.

Akcja korzysta z tego samego `OrderEmailPreparationService`, zapisuje `source = automation` i `automation_run_id`, a deterministyczny UUID oparty na wykonaniu, pozycji kroku i odbiorcy zapobiega podwójnej wiadomości. Dla dotychczasowego odbiorcy-klienta zachowano wcześniejszy klucz idempotencji. Krok jest zakończony po utworzeniu niezmiennych snapshotów i dodaniu transportów do kolejki. Końcowy wynik SMTP pozostaje statusem każdego `OrderEmailMessage` i nie zmienia wstecz zakończonego kroku Automation.

Docelowy przepływ:

```text
użytkownik zleca wysyłkę
→ zapis zdarzenia oczekującego
→ job kolejki
→ wysyłka PDF
→ zapis sukcesu albo błędu
```

Generowanie podstawowej faktury i nadanie numeru powinno być synchroniczne i transakcyjne.

Operacje potencjalnie asynchroniczne:

- generowanie PDF,
- wysyłka e-mail,
- operacje masowe,
- eksporty,
- przyszły KSeF.

---

# 22. Pro formy

Pro forma używa tego samego modelu dokumentu z typem:

```text
proforma
```

Pro forma jest przechowywana w tabeli `invoices`; nie istnieje osobna tabela pro form.

Pro forma:

- ma własną serię,
- ma własny numer,
- ma własny snapshot,
- nie zwiększa rejestru sprzedaży VAT,
- posiada `source_snapshot_hash` i `last_refreshed_at`,
- jedno zamówienie ma jedną bieżącą logiczną Pro formę zachowującą numer podczas odświeżenia.

---

# 23. Korekty

Korekta jest dokumentem w tabeli `invoices` z typem:

```text
correction
```

Zaimplementowane powiązania:

```text
corrected_invoice_id
previous_correction_id nullable
```

`corrected_invoice_id` zawsze wskazuje pierwotną Fakturę. Pole `previous_correction_id` jest aktywnym ogniwem liniowego łańcucha odrębnych Korekt: pierwsza Korekta ma wartość `null`, a kolejna wskazuje poprzednią zamkniętą Korektę tej samej Faktury i zamówienia.

Korekta przechowuje:

- powód,
- dane przed,
- dane po,
- różnicę,
- własne pozycje,
- własny numer,
- własny bieżący snapshot.

Nie należy nadpisywać dokumentu źródłowego.

Faktura posiadająca jakąkolwiek Korektę nie jest zwyczajnie edytowalna ani usuwalna. Slot typu `correction` wskazuje wyłącznie bieżącą, niezfinalizowaną Korektę danego zamówienia. Ponowna operacja tworzenia otwiera edycję tego dokumentu, a zapis nadpisuje jego snapshoty i pozycje bez zmiany numeru. Finalizacja usuwa slot; zamknięta Korekta pozostaje niezmiennym dokumentem, a następna Korekta może powstać jako kolejne ogniwo.

Pole `invoices.finalized_at` jest nullable i pozostaje puste dla istniejących dokumentów. `InvoiceFinalizationService` ustawia je idempotentnie wyłącznie na wystawionej Fakturze VAT albo Korekcie, pod blokadami i w transakcji; Pro forma jest odrzucana. Pole nie oznacza statusu KSeF. Zamknięcie nie zmienia treści, `lock_version`, numeracji, cache PDF ani zdarzeń. Centralny `InvoiceMutationPolicy` blokuje edycję i usuwanie zamkniętych dokumentów, natomiast finalizacja bieżącej Korekty dodatkowo usuwa jej slot w tej samej transakcji.

---

# 24. Duplikaty

Duplikat nie jest nowym rekordem sprzedaży.

Rekomendowany model:

- istniejąca faktura pozostaje źródłem,
- generowany jest osobny rekord pliku,
- zapisywane jest zdarzenie duplikatu,
- przechowywana jest data wystawienia duplikatu.

Nie nadaje się nowego numeru dokumentu.

---

# 25. Rejestr sprzedaży

Rejestr sprzedaży powinien być widokiem danych wynikającym z dokumentów, a nie niezależnym źródłem prawdy.

Źródła:

- faktury wystawione,
- korekty wystawione,
- status dokumentu,
- daty podatkowe.

Nie uwzględnia:

- pro form,
- duplikatów jako nowej sprzedaży,
- dokumentów roboczych.

Na początku można generować rejestr zapytaniem.

Osobne tabele agregacyjne należy rozważyć dopiero przy realnym problemie wydajności.

## RS.1A.2 — wspólny kontrakt backendu

`SalesRegisterFilters` jest niemutowalnym VO, bez Form Requestu i zależności HTTP. `SalesRegisterDataService::build()` zwraca jawne tablice, nie modele Eloquent. Nowe komponenty należą do `Modules/Invoices`; nie ma nowego modułu, providera, trasy, eksportera, tabeli ani publicznego API.

```php
use Modules\Invoices\Services\SalesRegisterDataService;
use Modules\Invoices\ValueObjects\SalesRegisterFilters;

$period = SalesRegisterFilters::forPeriod([
    'month' => '2026-08',
    'series_ids' => [1, 2], // Przykładowe ID istniejących serii Faktur/Korekt.
    'tax_id_presence' => 'all', // all / with / without
    'currency' => null, // null lub historyczny kod 3-literowy
    'country' => null, // null lub kod 2-literowy
    'include_ksef' => true,
]);
$report = app(SalesRegisterDataService::class)->build($period);
$selected = app(SalesRegisterDataService::class)->build(
    SalesRegisterFilters::forDocuments([101, 102], includeKsef: false),
);
```

Kompletne `issue_from`/`issue_to` zastępują `month`; częściowy ręczny zakres jest błędem. Opcjonalne `sale_from`/`sale_to` mogą ograniczać każdą granicę niezależnie. Walidacja ścisłych dat odrzuca daty nieistniejące i zakresy odwrotne. Listy ID są niepuste, deduplikowane i sortowane; nieprawidłowe wejście daje `sales_register_filters_invalid`. Brakujące/niekwalifikujące ID dają `sales_register_documents_invalid`, niewłaściwe serie `sales_register_series_invalid`, z ID w metadanych błędu. Nie istnieje parametr środowiska KSeF.

Odpowiedzialności:

- `SalesRegisterDataService`: kwalifikacja `invoice/correction + issued`, odczyt partiami po 200 ID, ograniczone kolumny, eager loading pozycji i wystawionych Korekt, wspólne filtry nabywcy, końcowe sortowanie i numeracja. Wynik materializuje wszystkie rekordy i ID pokrycia, więc zużycie pamięci rośnie z rozmiarem raportu. Brak ukrytego limitu i zależności od paginacji.
- `SalesRegisterDocumentReader`: jeden odczyt snapshotu nabywcy, kontrola sprzeczności osobnych pól, kwoty i podpisane różnice Korekt, powiązania informacyjne, wysyłka BEFORE/AFTER i kompletność sekcji. Relacja do aktualnego zamówienia ani konfiguracja serii nie uzupełniają danych.
- `SalesRegisterValues`: kontrola obecności i formatu kwot przed kalkulatorem, suma netto+VAT=brutto, normalizacja tożsamości VAT istniejącym `InvoiceTaxIdentityNormalizer`, unikalność grup i deterministyczna kolejność. Arytmetyka używa `InvoiceDecimalCalculator`; numeryczne skalary PDO SQLite są najpierw zamieniane na tekst, bez działań float ani SQL SUM. Limit pojedynczego dokumentu nie ogranicza agregacji.
- Zapisana konwersja jest sprawdzana przez czystą metodę `InvoicePdfCurrencyConversionPresenter::presentSnapshots()`, dopiero po kontroli typów grup. Nie uruchamia to PDF, fallbacku Korekty, pobrania kursu ani żadnej operacji domenowej. Całe sumy PLN są odczytywane, nie mnożone ponownie. Pomocnicza wysyłka używa `multiplyAndRound()` per dokument/grupa i ma źródło `computed_from_stored_items_and_historical_rate`.
- `SalesRegisterKsefNumberReader`: zbiorczy odczyt wyłącznie metadanych zaakceptowanych transmisji `KsefEnvironment::Production`. Sprawdza numer przez istniejący validator, powiązanie dokumentu, NIP i kontekst, hash oraz metadane online/offline. Offline wymaga własnego issuance z właściwym środowiskiem/datą/hash/rozmiarem; korekta techniczna wymaga zgodnego artefaktu i odrzuconej transmisji źródłowej. Konflikt provenance, różne zaakceptowane numery albo uszkodzone powiązanie dają kontrolowane ostrzeżenie i `null`. Nie odczytuje payloadu XML, certyfikatów, credentiali ani aktualnego środowiska konfiguracji. Nie przeprowadza ponownego kryptograficznego audytu treści XML. `include_ksef=false` pomija wszystkie zapytania do tabel KSeF.
- `SalesRegisterSummaryService`: oryginalne waluty i grupy VAT, wysyłka jako podzbiór, waluty obce w PLN, łączne PLN oraz kraj/waluta. Bez grupowania sprzedawcy i bez łączenia kwot różnych walut.

Wynik ma klucze `selection`, `records`, `summaries`, `warnings`. `selection.selected_count` liczy kwalifikujące typ/status dokumenty wskazane ID albo objęte seriami/datami, przed dodatkowymi filtrami nabywcy/waluty. `qualified_count` i `record_count` liczą rekordy po wszystkich filtrach. Nieustalony NIP wykluczony przez filtr obecności daje ostrzeżenie `sales_register_tax_id_filter_unresolved`.

Rekord zawiera `ordinal`, `id`, `type`, `number`, `series`, `issue_date`, `sale_date`, `buyer`, `currency`, `totals`, `vat_labels`, `vat_groups`, `related_documents`, `ksef_number`, `shipping`, `pln`, `shipping_pln`, `exchange_rate`, `completeness`, `warnings`. Wszystkie kwoty mają skalę 2 i zachowują znak. Nieznana kwota/data/numer/waluta pozostaje `null`; brak kraju ma kategorię `unknown` z etykietą „Nieustalony kraj”. Stan nazwy/NIP rozróżnia `value`, `empty`, `missing`, `legacy`, `conflict`, `invalid`.

`summaries` zawiera `currencies`, `countries`, `foreign_in_pln`, `combined_pln`, `exchange_rates`, `completeness`. Każdy koszyk udostępnia `document_count`, `totals`, `vat_groups`, `shipping` i `coverage` dla sum/VAT/wysyłki. Pokrycie zawiera `complete`, `included_count`, `excluded_count`, `included_ids`, `excluded_ids`. Dostępne kwoty są sumowane oddzielnie od informacji o pominięciach; gdy wszystkie kwoty niepustego koszyka są nieznane, `totals=null`. Brak poprawnych grup daje pustą listę wraz z niekompletnym pokryciem VAT, nie deklarację zerowego podatku. Ostrzeżenie ma `code` z prefiksem `sales_register_`, `document_id` i `section`, bez raw snapshotów/sekretów.

Nowe testy rejestru używają wyłącznie izolowanego SQLite `:memory:`, fikcyjnych dokumentów, `Http::preventStrayRequests()`, fake HTTP/kolejki i kontroli zapytań tylko SELECT. Nie ma trwałych zapisów raportu ani operacji KSeF/NBP. Warstwę HTTP i HTML dodano w RS.1B bez zmiany tego kontraktu.

## RS.1B — adapter HTTP i prezentacja HTML

Centralne trasy `GET /invoices/sales-register`, `POST /invoices/sales-register/selected` i `POST /invoices/sales-register/export` prowadzą do `SalesRegisterController`, w istniejącej grupie `web`. POST wymaga CSRF. Trasy zachowują granicę dostępu faktur; obecnie brak na nich auth/policy, co jest ograniczeniem wdrożenia, nie nowym publicznym API rejestru.

`SalesRegisterRequest` waliduje jawne `period/ids`, format `html` (od RS.1C także `xlsx`), booleany i daty. Puste granice wystawienia usuwa przed `SalesRegisterFilters::forPeriod()`. Tryb ID dekoduje jedną tablicę JSON dodatnich integerów i nie mapuje filtrów okresowych. Niepełny JSON jest odrzucany bez eksportu podzbioru. Kontrolowane błędy kwalifikacji backendu oraz walidacji zwracają formularz HTTP 422 z zachowanym wejściem; nie są maskowane inne wyjątki. Nie ma przekierowań na dowolny URL. `InvoiceReturnContext` zachowuje wyłącznie dozwolony kontekst listy Faktur/Korekt.

`SalesRegisterFormData` odczytuje dostępne serie oraz historyczne lata/kody walut/kraje bez relacji do bieżących zamówień. Kontroler eksportu wywołuje istniejący `SalesRegisterDataService::build()`. `SalesRegisterHtmlPresenter` wyłącznie formatuje tekst dat/kwot, opis filtrów, etykiety ostrzeżeń i nazwy krajów: jedna zgodna nazwa ze snapshotów, przy konflikcie kod, przy braku kodu „Nieustalony kraj”. Nie wykonuje zapytań ani arytmetyki finansowej.

Blade `invoices/sales-register/export` nie używa layoutu panelu. Ma inline CSS, kolumny 10/11 zależnie od KSeF, jedno Lp./dokument, wyrównane podsumowania i jawne pokrycie. Wszystkie dane są escapowane; raport nie zawiera raw modeli/snapshotów, tokenów CSRF, zewnętrznych zasobów ani skryptów. Wysyłka PLN zachowuje opis pochodzenia z backendu. Nazwa pliku jest generowana przez serwer; odpowiedź: `text/html; charset=UTF-8`, `Content-Disposition: inline`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`. Nie powstaje publiczny plik ani trwały raport.

Regresje HTTP obejmują rzeczywiście włączone middleware CSRF, walidację i zachowanie formularza, wybór ID ponad próg partii 200, XSS, zakres 10/11 kolumn, historyczne kursy, podsumowania i pokrycie, brak zapytań KSeF przy wyłączonej opcji oraz brak zapisów domenowych/HTTP/jobów. Opcjonalne lokalne podglądy testowe korzystają wyłącznie z fixture w `:memory:`. Brak migracji, nowych zależności i zmian finansowego backendu RS.1A.2.

## RS.1C-XLSX-001 — writer XLSX

Ten sam endpoint eksportu rozdziela zwalidowane `html/xlsx` po jednym `SalesRegisterDataService::build()`. `SalesRegisterXlsxExporter` korzysta bezpośrednio z rekordów, nie z Blade/importera HTML. `SalesRegisterSummaryService` pozostaje bez zmian. `SalesRegisterFormData` już zachowuje format po błędzie; formularz przełącza target `_blank/_self`, wyłącza opcje wyłącznie HTML bez kasowania ich wartości. Request wyklucza te opcje dla XLSX, lecz nadal wymaga ich i waliduje je dla HTML. Błędy eksportu zwracają formularz HTML 422, nigdy uszkodzony załącznik.

Writer: `PhpOffice\PhpSpreadsheet\Writer\Xlsx`, zależność `phpoffice/phpspreadsheet ^5.10`, lock 5.10.0, PHP 8.2+. Instalacja bez skryptów/pluginów, bez aktualizacji wcześniejszych pakietów. Wymagane rozszerzenia obejmują GD, ZIP, DOM, SimpleXML, XML/XMLReader/XMLWriter, mbstring, iconv, fileinfo, ctype, filter i zlib. Na serwerze należy sprawdzić `composer check-platform-reqs`; po włączeniu GD w php.ini długotrwały proces PHP musi zostać ponownie uruchomiony, aby zobaczył rozszerzenie.

Addytywny odczyt backendu:
- `payment_snapshot` jest pobierany w istniejącej partii; rekord otrzymuje `payment_method` z własnego `effective_payment_method`. Brak klucza lub błędny typ daje ostrzeżenie, jawne null/puste pozostaje dozwolone. Nie ma odczytu bieżącego zamówienia/serii ani fallbacku do `order_payment_method`.
- `SalesRegisterKsefNumberReader` pobiera dodatkowo tylko `acquisition_date`, zapisywane przez obsługę akceptacji z odpowiedzi MF `acquisitionDate`. Istniejące warunki numeru, NIP-u, provenance i online/offline nadal obowiązują. Metadane czasu przechodzą przez istniejący `KsefInvoiceSubmission`/`KsefUtcInstantCast` bez odczytu payloadu. Identyczne instants powtórzeń dają jedną datę; brak/uszkodzenie lub konflikt daje `ksef_authorization_date=null` i ostrzeżenie. Numer może pozostać poprawny. Data jest `Y-m-d` w strefie `app.timezone`; exporter formatuje ją jako `d.m.Y`. Wyłączona opcja KSeF nadal nie wykonuje żadnego zapytania KSeF.

Wszystkie teksty są ustawiane jawnie jako `TYPE_STRING`, bez apostrofów, formuł, escapowania HTML ani bindera zgadującego typ. Lp. jest integerem z formatem `0`. Kwoty K/M/N i pojedynczy VAT L przechodzą kontrolę stringa dziesiętnego ze skalą 2; przed konwersją odrzucane jest więcej niż 15 cyfr (po usunięciu znaku, kropki i zer wiodących, konserwatywnie z końcowymi zerami). Dodatkowa kontrola reprezentacji liczbowej writera porównuje wartość dziesiętną po serializacji do skali 2. Nie jest to arytmetyka finansowa na float; jest to granica reprezentacji pliku. Wartość niebezpieczna trafia dokładnie do komórki tekstowej i arkusza `Uwagi`. Testy rzeczywiście zapisują/odczytują XLSX, sprawdzając zachowanie groszy, zer i minusów. Kursy zawsze pozostają tekstem z pełną precyzją.

Skoroszyt ma 21 albo 19 kolumn, bez scalania, tytułu i sum. Ewentualny drugi arkusz `Uwagi` używa istniejących polskich opisów `SalesRegisterHtmlPresenter`, w tym ostrzeżeń wyboru poza records. Limit wynosi 1048575 wierszy danych + nagłówek oraz 32767 jednostek UTF-16 na komórkę; przekroczenie/nieprawidłowy tekst daje kontrolowany błąd, bez cichego ucinania. Pamięć rośnie z rozmiarem raportu i skoroszytu; odczyt partiami nie oznacza stałej pamięci.

Kompletny plik powstaje przed nagłówkami w `storage/app/private/sales-register-exports` pod losową nazwą 128-bit, tworzoną wyłącznie przez exclusive create. Odpowiedź `BinaryFileResponse` ma właściwy MIME bez charset, `attachment`, `private, no-store`, `nosniff` i `deleteFileAfterSend(true)`; błąd generowania usuwa tylko własny plik, bez ujawniania szczegółów wyjątku. Brak trwałej historii, publicznej trasy, kolejki i HTTP integracji. Automatyczne testy używają `:memory:`, fikcyjnych danych, blokady HTTP, sprawdzają strukturę ZIP/XML, brak formuł/relacji zewnętrznych oraz sprzątanie plików.

---

## RS.1D-XML-001 — profil XML rejestru sprzedaży

Istniejący endpoint dopuszcza `html/xlsx/xml` i buduje wspólny raport dokładnie raz. `SalesRegisterXmlExporter` używa natywnego XMLWriter, a `SalesRegisterXmlDetailsReader` wzbogaca wyłącznie kwalifikujące rekordy w partiach po 200 (dwa zapytania na partię: dokumenty i pozycje). HTML/XLSX nie odczytują dodatkowych szczegółów. Nie ma nowych zależności, migracji ani zmian kalkulatorów. Target formularza jest `_blank` tylko dla HTML; obie opcje HTML są wyłączane dla pozostałych formatów bez kasowania wartości, również po stronie Requestu.

Profil odtworzono strukturalnie z dostarczonego `report_20260901_20260930.xml`: root `invoices`, 291 dokumentów (281 Faktur/10 Korekt), brak namespace/DTD/XSD. Zachowano kolejność pól i sekcji. Nie jest to FA(3), JPK ani deklaracja zgodności z importerem księgowym. Nie dostarczono oficjalnego XSD tego profilu. XMLWriter może normalizować nazwę kodowania do `UTF-8`, co jest równoważne `utf-8`.

Mapa źródeł XML:
- `no`, numer, daty, nabywca, waluta, końcowe kwoty, kurs i `ksef_id`: gotowy wspólny rekord. Numer ma prefiks Faktura/Korekta, daty `DD.MM.YYYY`; KSeF wyłącznie własne poprawne powiązanie PROD. Opcja wyłączona pomija element i odczyty KSeF. Nie ma daty autoryzacji w drzewie XML.
- `order_id`: `order_reference_snapshot`, ewentualnie zgodne `order_snapshot.id`; konflikt daje puste pole i komentarz. To ID OMS, nie Base ani Faktury. Identyfikatory nie przechodzą przez float.
- `shop_order_id`: zapisane `order_snapshot.external_id` wyłącznie dla `source=prestashop`, zgodnie z lokalnym kontraktem `OrderIdentifiersTest`/pola numeru sklepu. Allegro/manual/nieznane źródło nie jest traktowane jako zamówienie sklepu. Brak daje pusty element i komentarz.
- `fv_seller`: wyłącznie własny `seller_snapshot` (nazwa, adres, kod/miasto, NIP/BDO), również przy Korekcie. Nie eksportuje banku, kontaktów ani pełnego snapshotu.
- `receiver_country_code`: własny `recipient_snapshot.country_code`, nigdy kraj nabywcy ani domyślne PL. `payment`: istniejący odczyt `effective_payment_method` z własnego snapshotu, także jawnie pusta metoda.
- `items`: `InvoiceItem` w kolejności position/id; dla Korekty jej `correction_after_snapshot`. `original_items` to sparowany `correction_before_snapshot` tego samego wiersza, bez dopasowania po nazwie i bez odczytu źródłowych pozycji. Zera pozostają zapisanymi stanami, brak snapshotu nie staje się zerem. Typ shipping jest oceniany osobno dla obu stanów; tylko zapisane pozycje dostawy są eksportowane, bez duplikowania prefiksu.
- `item_product_id`: zapisane `invoice_items.product_id` (nie order_item_id ani source_invoice_item_id); SKU tylko z `product_snapshot.sku`, jeżeli utrwalono. Braki pozostają puste. Bieżący builder zwykle zapisuje tylko name/order_item_id, co nie odtwarza produktu ani zamówienia.
- `correction_to_invoice_number`: jednoznaczny numer pierwotnej Faktury z dedykowanych historycznych odwołań (`order_snapshot.corrected_invoice`, legacy `related_documents.invoice`, `correction_totals_snapshot.source_invoice`); konflikt/brak blokuje XML. Kolejna Korekta nadal eksportuje własny BEFORE, a nie pierwotne pozycje Faktury.
- `order_items`: zawsze pusta sekcja z komentarzem ograniczenia. Obecny zapis NEX nie utrwala pełnej historycznej kolekcji pozycji zamówienia; pozycje Faktury mogą być filtrowane i edytowane. Nie odczytuje się relacji Order/OrderItem ani nie wytwarza fikcyjnych pozycji, wagi lub dat zakupu. Ewentualne utrwalanie takiej kolekcji to osobny przyszły etap.

Kwoty końcowe pochodzą z rejestru (dla Korekty różnica), nie z ponownego sumowania XML. Wymagane liczby są sprawdzane przed normalizacją, z dokładną skalą 2 dla sum/VAT i 4 dla cen jednostkowych/ilości; nadmiar niezerowej precyzji jest błędem, nie zaokrągleniem. Ilości tracą tylko końcowe zera ułamka. PDO SQLite może zwrócić skalary liczbowe, które zamienia się na tekst przed BigDecimal; nie ma arytmetyki finansowej float.

Obsługiwane jest jednoznaczne procentowe VAT (0–100), spójne dla grup i stanów pozycji. Dokument z wieloma stawkami albo kodami VAT (również w BEFORE) jest blokowany kontrolowanym błędem; nie wybiera się pierwszej stawki ani sentinela. Dla jednoznacznej Korekty danych z zerową różnicą i pustymi grupami stawkę określają zgodne zapisane BEFORE/AFTER. `tax` Faktury usuwa końcowe zera, Korekty ma skalę 2. PLN `0.000000` to wyłącznie konwencja serializacji XML; backend i XLSX nadal używają własnego kontraktu. Kurs walutowy zachowuje pełną precyzję i własne pochodzenie, brak nie staje się 0/1.

Nieblokujące braki i wspólne warnings trafiają do komentarzy NEX-OMS po dokumentach, z ID/stabilnym kodem/polskim opisem. Komentarze nie są częścią potwierdzonego kontraktu importera; `--` i końcowy myślnik są zabezpieczone. Brak wymaganych kwot/dat/numeru/stanu Korekty lub niedozwolony tekst daje formularz 422 z ID i polem, bez częściowego pliku i bez wartości wejściowych w wyjątku. UTF-8 i zakres znaków XML 1.0 są sprawdzane; brak writeRaw, HTML escaping, DTD/encji zewnętrznych, XInclude/XSLT i nowych pól metadanych.

Kompletny XML powstaje w prywatnym losowym pliku, jest ponownie parsowany DOM z `LIBXML_NONET`, wyłączonymi external resolution/substituteEntities, i dopiero wtedy zwracany jako attachment `application/xml; charset=UTF-8`, `private, no-store`, `nosniff`. Sprzątanie obejmuje wysłanie i błędy, usuwa tylko własny plik. Weryfikacja drzewa nie oznacza walidacji XSD/importera. Raport i DOM materializują dane, więc pamięć nie jest stała. Testy obejmują rzeczywiste bajty HTTP, historie korekt, ponad 200 dokumentów, zgodność z HTML/XLSX, niebezpieczne znaki, brak zapisów i integracji.

## RS.1E-JPK-V7M3-001 — profil JPK sprzedaży

`format=jpk_v7m3` rozszerza istniejący POST eksportu bez zmiany HTML/XLSX/XML i wspólnego kalkulatora. `SalesRegisterRequest::filters()` bezwarunkowo włącza odczyt KSeF dla tego formatu, również przy `include_ksef=0`. Formularz zachowuje wartość checkboxu pozostałych formatów. Wspólne pola `month/year` ustalają nagłówek JPK również w trybie ID; kontroler mapuje je na wewnętrzne `jpk_month/jpk_year` kontekstu, ignorując takie dodatkowe pola wejścia. Filtry dat i selekcja ID nie wyznaczają innego okresu JPK. Nagłówek/tabela kursów HTML nie dotyczą JPK. Parametry podatnika/okresu są przesyłane POST, nigdy w URL; eksport nie zapisuje ich do bazy. Odrębny zapis profilu dodano w RS.1E-JPK-UX-001.

Podział odpowiedzialności:
- `JpkV7m3Context`: jawny okres od 2026-02 (rok do 2090 zgodnie z typem TRok XSD), dane osoby fizycznej/niefizycznej, NIP, e-mail, urząd i jawny CelZlozenia. `1` nie jest zamieniane na `2` z powodu obecności Korekt ani pominięcia deklaracji. Osobny limit XSD dla czasu wygenerowania to 2050-12-31; walidacja go nie omija.
- `JpkV7m3TaxMapper`: odczyt `ksef_tax`/`ksef_correction` version 1 i profilu ordinary/correction, dokładne ID/pozycje i kanoniczna semantyka każdej pozycji. Korzysta z czystego `isCanonicalSnapshot`, nigdy z initialize/refresh/materialize. Zapisane BEFORE/AFTER są uzgadniane ze wspólnymi grupami różnicy, nie z aktualną Fakturą źródłową. Sumowanie stringów przez `InvoiceDecimalCalculator`, bez float i SQL SUM. PLN rozdziela kategorie według zapisanych pozycji; waluta obca używa istniejących przeliczeń grup, a nie kursu pobieranego/wyliczanego na nowo. Nierozdzielone kategorie jednej tożsamości VAT są blokowane. GTU_01–GTU_13 z `invoice_items.gtu_codes`, bez klasyfikacji nazw produktów. Brak kompletnej semantyki/procedura szczególna daje kontrolowany błąd.
- `JpkV7m3KsefPolicy`: tylko metadane PROD. Utrwalony algorytm decyzji jest następujący: (1) błędne lub niejednoznaczne powiązanie numeru blokuje dokument; (2) poprawny własny `NrKSeF` PROD ma pierwszeństwo; (3) wiarygodne `outside_ksef` daje BFK, `failure` daje OFF, a `offline24` lub `planned_unavailability` daje DI; (4) każda sprzeczność dowodów albo nierozstrzygnięta transmisja blokuje dokument i nie może zostać zamaskowana BFK; (5) dopiero brak numeru, silniejszego dowodu, konfliktu i ręcznego oznaczenia pozwala zastosować domyślne BFK z globalnej opcji. Ręczne BFK/OFF/DI jest dozwolone wyłącznie dla ID bez dowodu PROD, bez nadpisywania numeru ani konfliktu. Dane TEST/DEMO nie są dowodem PROD. Nie ma odczytu credentiali ani payloadu XML.
- `JpkV7m3Exporter::prepare()`: jeden raport wspólnego backendu na żądanie, wzbogacany wyłącznie dla jego rekordów w partiach po 200. Pięć dodatkowych zapytań na partię (dokumenty, pozycje, provenance, offline, submissions), bez N+1. NIP wystawcy każdego dokumentu musi odpowiadać kontekstowi podatnika. BRAK jest wyłącznie rzeczywistym brakiem; PL NIP i VAT UE wymagają zgodnej zapisanej tożsamości. Kraj dostawy nie wyznacza kraju nadania TIN.
- `JpkV7m3SchemaValidator`: DOM, wyłączone DTD/encje, LIBXML_NONET, chwilowy allowlist resolver wyłącznie dla przypiętego root i trzech oficjalnych importów. Przywraca poprzedni loader. Nie korzysta z schemaLocation dokumentu ani z sieci; nie ujawnia wartości z komunikatów libxml. Diagnostyka podaje kod, pole/wiersz i ID dokumentu, gdy możliwe.

Mapowanie: standard 23/22 -> K_19/K_20; 8/7 -> K_17/K_18; 5 -> K_15/K_16; domestic_zero -> K_13; wdt -> K_21; export -> K_22. Brak mapowania oznacza błąd, nie domyślną stawkę/kategorię. Pola kwotowe mają skalę 2. SprzedazCtrl liczy rzeczywiste wiersze i podatek: K_16+18+20+24+26+28+30+32+33+34 minus K_35+36+360, z wyłączeniem FP. Sam eksport FP pozostaje nieobsługiwany; formuła ma odrębny test. Pusty zakres jest zgodny z XSD, bez fikcyjnych dokumentów.

XMLWriter tworzy `Naglowek + Podmiot1 + Ewidencja`, bez Deklaracja/ZakupWiersz, komentarzy i własnych elementów. Namespace główny `http://crd.gov.pl/wzor/2025/12/19/14090/`. Tożsamość osoby fizycznej dziedziczy elementy z namespace eD `2022/09/13`; tożsamość osoby niefizycznej oraz Email/Telefon mają namespace główny. DataWytworzeniaJPK jest konwertowana do UTC przed dodaniem Z. Daty dokumentów pozostają historyczne, DataSprzedazy tylko gdy różni się od wystawienia. NrKontrahenta pozostaje tekstem, prefiks jest rozpoznawany semantycznie; zagraniczne numery nie przechodzą algorytmu polskiego NIP.

**Utrwalona decyzja architektoniczno-produktowa:** `download` (także domyślna akcja) jest samodzielnym, pełnym przebiegiem i nie wymaga wcześniejszego `review`, fingerprintu, HMAC, tokenu autoryzacyjnego ani checkboxu potwierdzenia. Odczytuje bieżący raport dokładnie raz, ponownie wylicza klasyfikacje i przeprowadza wszystkie walidacje requestu, podatnika, VAT, GTU, procedur, dowodów KSeF, kwot oraz oficjalnego XSD. Brak wcześniejszego podglądu nie jest błędem domenowym.

Opcjonalne `review` jest bezstanową diagnostyką tej samej bieżącej projekcji. Pokazuje listę i problemy, lecz nie autoryzuje pobrania, nie zapisuje snapshotu ani decyzji i nie gwarantuje identycznych bajtów przyszłego pliku po zmianie danych. `jpk_bfk_outside_ksef` jest walidowanym booleanem bieżącego POST. `SalesRegisterFormData` ustawia go na `1` przy początkowym GET i otwarciu wybranych dokumentów; jawne `0` pozostaje zachowane. Brak pola w żądaniu eksportu nadal oznacza false, bez automatycznego zastępowania przesłanej decyzji domyślną wartością formularza. Po walidacji tożsamości nabywcy `prepare()` przekazuje polityce możliwość BFK niezależnie od NIP/VAT UE nabywcy. Polityka stosuje ją wyłącznie przy braku numeru, dowodów PROD i ręcznego oznaczenia, po wszystkich guardach transmisji; nie zastępuje NrKSeF/OFF/DI ani niespójnych danych. Dawne pole `jpk_bfk_without_nip` nie jest interpretowane jako nowa, szersza zgoda. Brak NrKSeF nie dodaje pustego elementu. Niezmieniony XSD nadal wymaga jednego wyboru NrKSeF/OFF/BFK/DI. Ręczne oznaczenia są walidowane względem wybranych ID. Przy błędach nie powstaje częściowy eksport. Gotowy XML przechodzi XSD przed odpowiedzią, trafia do prywatnego losowego pliku, jest ponownie sprawdzany i usuwany po wysłaniu/błędzie. Odpowiedź: attachment XML UTF-8, private/no-store/nosniff. Pamięć rośnie z rozmiarem raportu/XML.

Oficjalne zasoby `Modules/Invoices/Resources/Schemas/JPK_V7M3/1-0E/` zawierają oryginalne bajty XSD i importów. `SOURCES.md` dokumentuje cztery SHA-256, źródła CRD/MF, datę pobrania 2026-09-17 i sposób lokalizacji bez zmiany definicji. Test kontroluje wszystkie checksumy. XSD dopuszcza brak deklaracji, lecz ten profil importowy nie jest kompletnym pierwszym złożeniem do MF. CelZlozenia nie służy obchodzeniu wymagań. Prywatny załącznik Base zweryfikowano lokalnie: 291 wierszy, 29 numerów, 262 braki wyboru KSeF; kontrola 291/14674.41, zakupy 0/0, brak deklaracji. XSD FAIL: 262 błędy 1871 (brak wyboru przed polami K_*) i 1831/1839 dla pustego Email. Żadnych danych przykładu nie dodano do fixtures ani repozytorium.

Regresje rozdzielają strukturę/XSD, mapowanie i bezpieczeństwo: rzeczywiste pobranie HTTP, dwa typy podatnika, historyczne okresy, 201 dokumentów, wiele stawek w jednym wierszu, dodatnie/ujemne różnice Korekt i przesunięcie kategorii przy zerowym brutto, zapisane PLN, GTU, konflikty PROD oraz ręczne oznaczenia, negatywne XSD/XXE/UTF-8, cleanup i brak zapisów domenowych/HTTP/jobów. Testy używają SQLite :memory: i fikcyjnych danych. Nie wykonano importu do programu księgowego. Brak migracji, zależności, zmian konfiguracji, transportu, NBP i zadań w tle. Dotychczasowa granica dostępu tras pozostaje bez zmian.

### RS.1E-JPK-V7M3-FIX-001

`JpkV7m3TaxMapper` zbiera GTU dopiero po walidacji obu stanów danej pozycji. Porównuje jawne wartości: ilość/ceny w skali 4, netto/VAT/brutto w skali 2, znormalizowaną tożsamość VAT i kanoniczne `treatment`. Wszystkie pozycje, również niezmienione, nadal uczestniczą w mapowaniu i uzgodnieniu K_*/grup VAT. GTU jest deduplikowane i sortowane z własnych kodów pozycji, bez odczytu aktualnego źródła. Formalne zmiany pozycji z GTU bez zmiany finansowej/podatkowej oraz dokumenty wyłącznie formalne z GTU są blokowane jako nierozstrzygnięty zakres, zamiast zgadywania oznaczeń. `CorrectionService`, wspólne finanse, KSeF policy i przypięte XSD pozostają bez zmian.

`tests/Support/Invoices/IsolatesSalesRegisterFiles` ustawia przed bootem aplikacji unikalny katalog `temp/PID/random` dla storage, logów, widoków, cache bootstrapu i podglądów. Baza to wyłącznie SQLite `:memory:`. Asercja własnych pozostałych eksportów poprzedza cleanup; helper przywraca środowisko, zamyka loggery i usuwa tylko własne drzewo bez podążania za dowiązaniami. Nie zmieniono ścieżek ani cleanupu produkcyjnych eksporterów. `run-isolated.php` uruchamia istniejący `artisan test` z izolacją całego przebiegu i cache PHPUnit; `run-concurrent.php` wykonuje pięć rund po dwa procesy z barierą startu, odrębnymi logami i cache. Kontrolowany test `glob-interleaving.php` reprodukuje wyścig asercji wspólnego katalogu przez sygnały, nie sleep. Osobne regresje sprawdzają współistnienie plików, cleanup po wysłaniu/błędzie, sąsiedni sentinel i przywrócenie ścieżek.

### RS.1E-JPK-UX-001

Brak wspólnego profilu firmy odpowiedniego do Podmiot1: dane sprzedawcy należą do serii. Addytywna migracja `2026_09_17_010000_create_jpk_taxpayer_profiles_table.php` tworzy wyłącznie `jpk_taxpayer_profiles`. `singleton_key` ma PK i ograniczenie enum do `default`, bez seedera; równoległe tworzenie nie może utworzyć drugiego profilu. `JpkTaxpayerProfileService` zapisuje transakcyjnie; wersja 0 oznacza wyłącznie insert, następne zapisy używają atomowego compare-and-swap `lock_version`. Konflikt daje 409. `JpkV7m3Context::taxpayerData()` jest wspólną walidacją eksportu i profilu: typy przed NIP, ograniczenia przypiętego XSD, tylko aktywne pola typu podatnika.

Trasy `GET/POST /invoices/settings/jpk`, `POST /invoices/settings/jpk/suggest` oraz `POST /invoices/sales-register/profile` zachowują istniejącą granicę `web` ustawień faktur i CSRF. Formularz rejestru automatycznie wczytuje singleton podatnika, bez opisu profilu i przycisku ponownego wczytania; dotychczasowy endpoint wczytania pozostaje zgodny, ale nie jest wywoływany z tego UI. Brak auth w tym istniejącym obszarze nadal jest ograniczeniem wdrożenia. Profile nie są zapisywane podczas GET, sugestii, review lub download. Sugestia wymaga konkretnego `source_series_id` Faktur i nie zgaduje danych osoby/urzędu. `SalesRegisterFormData` rozróżnia brak początkowego wejścia od jawnego pustego POST; download korzysta wyłącznie z potwierdzonych parametrów POST, nie z nowszej wersji profilu.

`JpkTaxOfficeCatalog` odczytuje DOM/XPath `enumeration` oraz dokumentację nazw z jednego lokalnego `KodyUrzedowSkarbowych_v8-0E.xsd` z `LIBXML_NONET`. Cache instancji zawiera tylko publiczny słownik. Wspólny partial pól i niewielki skrypt filtrują natywny select po nazwie/kodzie; serwer sprawdza członkostwo, nie sam regex. Nieznany zapisany kod pozostaje widoczny i nie wybiera pierwszego urzędu.

`JpkV7m3Exporter::prepare()` zwraca małe pola prezentacyjne `gtu`, `markers`, `choice`, `status`, `finalized`, `diagnostics` i kontrolowany URL edycji. GTU/procedury są wyodrębniane z tego samego jednokrotnego wyniku `JpkV7m3TaxMapper::map()` co wiersz XML. `null` oznacza brak ustalenia, `[]` brak zapisanych GTU. Wykryte nieobsługiwane kody są wyłącznie metadanymi błędu. `JpkV7m3Context::fail()` zachowuje dotychczasowy kod wyjątku, addytywnie udostępnia pole, ID i stabilny powód; `JpkV7m3Diagnostic` dobiera bezpieczne wskazówki. XSD nadal blokuje pobranie. Wynikowe GTU z hidden input nie są zaufanym wejściem; bezpośredni download wylicza je ponownie z zapisanych pozycji.

Dotychczasowy `PATCH /invoices/{invoice}/items/{item}` obsługuje jawne `gtu_only` i `gtu_codes`. `InvoiceGtuCodes` normalizuje tylko listę GTU_01–GTU_13, deduplikuje/sortuje; brak pola w zwykłej edycji zachowuje kody, `[]` czyści je. HTML wysyła znacznik obecności tylko z dedykowanego formularza GTU pozycji. `InvoiceEditService` zachowuje transakcję, policy, własność pozycji, walutowe guardy, lock_version i unieważnienie cache. Gałąź GTU-only zapisuje wyłącznie oznaczenia, bez przeliczania finansów. `InvoiceItemBuilder` i `CorrectionService` są niezmienione: nowa pozycja startuje z `[]`, Korekta kopiuje utrwalone kody. Nie powstał workflow GTU dla automatycznego wystawiania/wysyłania.

Regresje obejmują singleton/CAS, atomowość, priorytety pól, lokalny słownik, zgodność review z XML, brak zapisów eksportu, zapis GTU przez rzeczywisty endpoint, waluty i blokady Online/Offline. Całość korzysta z `ExportTestWorkspace` oraz runnerów izolowanego/współbieżnego; brak biznesowej migracji, rzeczywistych integracji i zmian przypiętych schematów.

# 26. KSeF — przygotowanie architektury

Stan bieżący i granice dowodu PROD opisuje sekcja KSeF.8D poniżej. Opisy zakresu wcześniejszych etapów są historyczne: ich `TEST-only`, `Production: BLOCKED` i odroczenia nie są globalnymi ograniczeniami obecnego kodu. Production udostępniono we wspólnej implementacji w 8D.2; nie oznacza to LIVE weryfikacji każdego trybu ani zamknięcia rollout'u bez nadzoru.

Wybrany wariant:

```text
architektura gotowa pod KSeF teraz,
integracja dopiero po pełnym sprawdzeniu faktur
```

Moduł `Modules/Ksef` zawiera fundament konfiguracji KSeF. Tabela `ksef_settings` jest chronionym kluczem unikalnym singletonem dla całego OMS. Aktywne środowisko ma jedną z wartości `test`, `demo` lub `production`. Tabela `ksef_credentials` przechowuje osobny, szyfrowany token dla każdego środowiska; jest to techniczny podział danych uwierzytelniających jednej integracji, a nie model wielu integracji. Dostęp do konfiguracji i zachowanie istniejącego tokenu przy pustej aktualizacji centralizuje `KsefSettingsService`.

`ksef_settings.is_active` jest domyślnie wyłączonym globalnym kill-switchem workflow dokumentowego. Nie blokuje konfiguracji ani testu credentiali. Tabela `ksef_series_settings` przechowuje kwalifikację istniejących serii do przekazywania dokumentów. Backend dopuszcza wyłącznie aktywne serie typu Faktura VAT i Korekta oraz odrzuca Pro formę. Globalne `automatic_submission` uruchamia automatyczną pierwszą wysyłkę nowych Faktur z włączonych serii; tryb ręczny pozostaje dostępny niezależnie od tej flagi.

Enum `KsefZeroVatClassification` mapuje ustawienie `zero_vat_classification` na `wdt`, `export` albo `domestic`, z domyślnym `wdt`. To przyszły fallback FA(3) wyłącznie dla numerycznego VAT `0.00` bez jawnego `InvoiceItem.vat_code`; jawny kod ma pierwszeństwo. Planowane wartości FA(3) to `0 WDT`, `0 EX` i `0 KR`, przy czym konfiguracja nie stanowi potwierdzenia spełnienia warunków podatkowych WDT. Usunięto transportowe `include_sale_date`: przyszły mapper pobierze datę sprzedaży bezpośrednio z dokumentu.

`include_seller_vat_prefix` jest domyślnie wyłączoną decyzją treściową. Nowa Faktura zamraża ją w snapshotcie dokumentu, a mapper FA(3) dodaje `Podmiot1/PrefiksPodatnika=PL` wyłącznie dla wartości `true`.

Zapis konfiguracji nie modyfikuje `InvoiceSeries`, dokumentów, pozycji, `vat_rate`, `vat_code`, `finalized_at`, snapshotów ani PDF.

KSeF.2A wprowadza centralny `KsefHttpClient` dla oficjalnych adresów TEST, DEMO i PRODUCTION kończących się na `/v2`, bez przypinania numeru builda API i bez automatycznych retry. Klient zawsze wysyła `Accept: application/json` oraz `X-Error-Format: problem-details`, obsługuje bezpiecznie Problem Details i starszy kształt błędów, `Retry-After` oraz `X-System-Warning`, ale nigdy nie przechowuje surowych body ani nagłówków autoryzacji w wyjątku.

`KsefTokenAuthenticationService` realizuje świeży flow Tokena KSeF: ważny przez 10 minut challenge i `timestampMs` pochodzące z MF, dynamiczny aktualny certyfikat `KsefTokenEncryption` oraz jego `publicKeyId`, RSA-OAEP SHA-256/MGF1 SHA-256 przez `phpseclib/phpseclib`, inicjację, ograniczony polling i dokładnie jeden redeem. Status `100` jest pollowany, `200` prowadzi do redeem, a status terminalny kończy operację bez redeem. Przejściowy authentication token nie jest zapisywany. `KsefCredential` przechowuje szyfrowane access i refresh tokeny oraz ich terminy ważności per środowisko; `KsefAccessTokenManager` zwraca ważny access token, odświeża go ważnym refresh tokenem albo rozpoczyna jeden pełny flow. Błędy sieciowe, `429` i `5xx` nie uruchamiają ukrytego fallbacku.

`KsefConnectionTestService` zawsze wykonuje świeże uwierzytelnienie z zapisanej konfiguracji, niezależnie od `is_active`. Test ma jednakowy kontrakt dla TEST, DEMO i PRODUCTION: kończy się po uzyskaniu access i refresh tokena, nie wywołuje `personal/grants` ani `GET /tokens` i nie diagnozuje `InvoiceWrite`. Uprawnienia wymagane przez konkretny workflow pozostają rozstrzygane przez autorytatywną odpowiedź KSeF podczas tej operacji. Wynik `success`, `warning` albo `error` i niesekretne ostrzeżenie są bieżącym stanem per środowisko, bez tabeli historii. Osobny formularz testu zawiera wyłącznie CSRF; token, NIP i środowisko nie są przyjmowane z browsera. Zmiana Tokena KSeF czyści runtime i wynik testu tego środowiska, a zmiana NIP-u kontekstu robi to dla wszystkich środowisk, zachowując Tokeny KSeF.

KSeF.2B.1 rozszerza `KsefCredential` o szyfrowane i ukryte pola certyfikatu Authentication oraz klucza prywatnego, nadal osobne dla każdego środowiska. `KsefCertificateMaterialService` przyjmuje certyfikat PEM albo DER i typowy klucz prywatny PEM, normalizuje oba materiały do PEM, sprawdza kryptograficzne dopasowanie pary, ważność UTC, `Digital Signature` oraz dokładnie RSA 2048 albo EC P-256. Hasło zaszyfrowanego pliku klucza jest tylko wejściem importu; w bazie pozostaje kanoniczny PEM chroniony szyfrowaniem Laravel. Certyfikat nie jest lokalnie wiązany z NIP-em kontekstu, ponieważ identyfikuje uwierzytelniającego, a uprawnienia i kontekst weryfikuje KSeF.

Token i para certyfikat-klucz mogą współistnieć w jednym rekordzie środowiska, a `authentication_method` wskazuje metodę aktywną. Zmiana metody lub pary unieważnia runtime i wynik testu tylko tego środowiska bez usuwania drugiego credentiala. UI otrzymuje jedynie stan konfiguracji oraz ważność, fingerprint SHA-256 i typ klucza.

KSeF.2B.2 dodaje certyfikatowy inicjator uwierzytelnienia oraz dispatcher wybierający zapisaną metodę. `KsefAuthTokenRequestBuilder` tworzy przez DOM dokument 2.1 z `certificateSubject`, a `KsefXadesSigner` generuje enveloped XAdES-BES zgodny z oficjalnym klientem MF: exclusive C14N, SHA-256, referencje dokumentu i `SignedProperties`, dane certyfikatu oraz czas podpisu UTC. Obsługiwane są RSA 2048 z RSA-SHA256 i EC P-256 z ECDSA-SHA256; podpis EC jest konwertowany z DER do wymaganego 64-bajtowego `R || S`. `KsefHttpClient::postXml()` wysyła podpisany dokument do `/auth/xades-signature` jako `application/xml`, zachowując wspólną obsługę Problem Details i sanityzację ostrzeżeń.

`KsefAuthenticationCompletionService` centralizuje ograniczony polling, statusy terminalne, dokładnie jeden redeem i parsowanie access/refresh tokenów dla uwierzytelnienia Tokenem oraz certyfikatem. `KsefAuthenticationService` jest wspólnym dispatcherem używanym przez test połączenia i `KsefAccessTokenManager`. Przed świeżym flow certyfikat i klucz są ponownie walidowane, a przed zapisem runtime transakcja sprawdza niezmienność NIP-u, metody i obu materiałów. Klucz prywatny, podpisany XML oraz przejściowy authentication token nie są utrwalane. Diagnostyka certyfikatowa używa jawnych `personal/grants`; nie wywołuje `GET /tokens` i uznaje właściciela wyłącznie przy jednoznacznym NIP-ie certyfikatu zgodnym z kontekstem. Enrollment, certyfikat Offline, FA(3), sesje i wysyłka dokumentów pozostają poza zakresem.

KSeF.3B.1 dodaje warstwę semantycznego przygotowania FA(3), nadal bez XML. `KsefFa3SemanticSnapshotService` inicjalizuje na nowej Fakturze `tax_metadata_snapshot.ksef_tax` wersji 1 oraz semantykę nabywcy w `buyer_snapshot`; istniejące metadane walutowe pozostają niezależnymi kluczami tego samego snapshotu. Pozycje są identyfikowane przez trwałe `invoice_item_id`, a zapisane rozstrzygnięcie VAT 0% i MPP nie zależy później od bieżącej konfiguracji. Edycja aktualizuje snapshot wyłącznie dla istniejącego kontraktu wersji 1, dzięki czemu historyczne dokumenty bez warstwy 3B.1 nie są automatycznie backfillowane.

`KsefFa3EligibilityValidator` oddziela poprawność Faktury NEX od gotowości FA(3). Preflight opiera się wyłącznie na snapshotach dokumentu i jego pozycjach, a tryb autorytatywny dodatkowo wymaga `finalized_at`. `KsefFa3FinalizationGate` uruchamia preflight tylko dla Faktury VAT, gdy `ksef_settings.is_active=true` i odpowiadająca seria ma `ksef_series_settings.is_enabled=true`; ustawienie `automatic_submission` nie wpływa na tę bramkę. Pro formy i Korekty zachowują dotychczasowy lifecycle. Pole `default_split_payment` jest domyślną deklaracją MPP dla nowych Faktur, nie wpływa na runtime uwierzytelnienia i nie jest automatycznie wyliczane z kwoty, płatności ani GTU.

KSeF.3B.2 rozdziela generowanie rdzenia FA(3) na mapper snapshotów, immutable DTO, builder `DOMDocument`, walidator lokalnego oficjalnego XSD oraz orkiestrujący generator. Zasoby `FA (3) 1-0E` i ich importy są przechowywane bez modyfikacji wraz z manifestem źródła i hashami SHA-256; walidacja działa z `LIBXML_NONET` i zamkniętą mapą oficjalnych URL-i do plików lokalnych. Mapper nie odczytuje aktualnego zamówienia, serii ani ustawień treściowych KSeF, nie odświeża snapshotów i nie przelicza kwot pozycji. Dla waluty obcej konsumuje wyłącznie zapisane kwoty VAT w PLN. Sam generator zwraca przejściowy obiekt w pamięci; dopiero jawna warstwa transportowa może utrwalić jego dokładny autorytatywny wynik jako payload próby.

KSeF.3C dodaje obok `ksef_tax` wersjonowany `ksef_document`. Wersja 2 zamraża siedem flag zawartości podczas pierwszego wystawienia Faktury, w tym `include_seller_vat_prefix`; wersja 1 z sześcioma flagami pozostaje obsługiwana bez backfillu. `KsefFa3OptionalBlocksResolver` wspólnie dla eligibility i mapowania interpretuje wyłącznie `payment_snapshot`, snapshoty stron i zamówienia, `additional_information_text` oraz zapisane `InvoiceItem.gtu_codes`. Brak `ksef_document` oznacza historyczny tryb core-only; generator niczego nie backfilluje. Resolver waliduje dane warunkowo względem zamrożonych flag, a builder zachowuje kolejność `xsd:sequence` dla prefiksu sprzedawcy, kontaktów, `Podmiot3`, opisów, GTU, płatności i warunków transakcji. Nie korzysta przy tym z bieżącego `Order`, `InvoiceSeries`, `Product` ani flag `include_*`.

`KsefPaymentMethodMappingService` centralizuje case-insensitive i whitespace-insensitive klucze źródłowe, dynamiczne odkrywanie aktywnych `orders.payment_method`, specjalny klucz `**cash_on_delivery**`, zapis override oraz rozstrzygnięcie globalnego `default_payment_type`. Konfiguracja przechowuje semantyczny `KsefPaymentType`, nie kody liczbowe; `original` oznacza opis `PlatnoscInna`, a siedem bezpośrednich typów mapuje się centralnie na `FormaPlatnosci` 1–7. Brak źródła nie tworzy formy płatności.

Przy wystawieniu Faktury wynik jest zamrażany jako `payment_snapshot.ksef_payment` wersji 1. Generator dla tej wersji czyta wyłącznie zapisany typ, kod albo opis i kontroluje ich wzajemną spójność oraz limit XSD 256 znaków. Bieżąca konfiguracja i zamówienie nie są odczytywane podczas generowania; jawna zmiana metody płatności niezfinalizowanej Faktury rozwiązuje snapshot ponownie, a pozostałe edycje zachowują go bez zmian. Starsze dokumenty bez `ksef_payment` zachowują dotychczasową ścieżkę zgodności bez zapisu do bazy. Ta warstwa nie zmienia interpretacji `payment_status`, kwot zapłaty ani znaczników `Zaplacono`.

KSeF.4A.1 dodaje osobny agregat transportowy `KsefInvoiceSubmission` z historią prób dla pary Faktura-środowisko. Rekord zamraża dokładny autorytatywny XML FA(3), schemat, czas generowania, Base64 SHA-256, rozmiar bajtowy, NIP kontekstu uwierzytelnienia i odrębny NIP sprzedawcy z `Podmiot1`; XML jest szyfrowany at rest przez cast `encrypted`, a FK do Faktury używa `restrictOnDelete`. Numer próby jest wyznaczany pod blokadą Faktury, a stany `preparing`, `session_opened`, `submitted`, `processing`, `accepted` i `uncertain` blokują utworzenie kolejnej aktywnej próby. Każdy rekord próby, niezależnie od statusu, daje kontrolowaną blokadę usuwania Faktury zgodną z `restrictOnDelete` i zachowuje audit trail.

`KsefInvoiceSubmissionService` oddziela krótką transakcję przygotowania od transportu HTTP. Korzysta z istniejącego `KsefAccessTokenManager`, `KsefHttpClient` i uogólnionego `KsefPublicKeyResolver`. `KsefOnlineSessionEncryptionService` generuje nowy klucz AES-256 i IV dla każdej próby, stosuje AES-256-CBC/PKCS#7 oraz RSA-OAEP SHA-256/MGF1-SHA256 z aktualnym kluczem `SymmetricKeyEncryption`. Klucz AES, IV i ciphertext istnieją wyłącznie w pamięci na czas pojedynczego flow.

Zaakceptowana próba pozostaje źródłem danych KSeF prezentowanych na PDF Faktury. `InvoicePdfViewModelFactory` odczytuje numer KSeF, `acquisition_date` i status wyłącznie z najnowszego `KsefInvoiceSubmission` w stanie `accepted`; przejście potwierdzone przez orkiestrator statusu unieważnia cache PDF bez modyfikowania snapshotów i danych finansowych Faktury. `KsefInvoiceVerificationLinkBuilder` tworzy oficjalny link KOD I z hosta zamrożonego środowiska, `seller_nip`, daty `P_1` i `invoice_hash` przekształconego do Base64URL. TCPDF generuje kod bezpośrednio jako wektorową macierz z czteromodułowym białym marginesem, bez trwałego obrazu, pliku tymczasowego i odczytu bieżącej konfiguracji KSeF.

### KSeF.8A — CLOSED

`KsefPdfDocumentPresenter` jest wspólną granicą prezentacyjną PDF dla Faktury VAT i Korekty. Wybiera dokładną wartość `KsefSetting.environment`, niezależnie od `APP_ENV`; zapytania o submission nie stosują fallbacku między środowiskami ani wyboru dowolnego historycznego Accepted. Historyczne wymuszenie Production przez profil Laravela z 8A usunięto w 8D.2. Zaakceptowana Korekta korzysta wyłącznie z własnego submissionu, daty wystawienia, sprzedawcy, hasha, numeru i środowiska. Pro forma kończy prezentację przed odczytem KSeF i nie otrzymuje metadanych, ostrzeżenia ani QR.

Od 8D.5.1 presenter przelicza immutable `acquisition_date` na `Europe/Warsaw`, bez zmiany źródłowego instantu. `processed_at` zawiera lokalną datę i godzinę, a `processed_at_timezone` nazwę strefy i offset właściwy dla daty; wspólny partial pokazuje strefę w drugim wierszu. Bieżący cache VAT/KOR to `v46` z sufiksem środowiska, Pro forma pozostaje `v35`. Historycznego PDF `v45` z LIVE 8D.5 nie regenerowano podczas poprawki ani tego closure.

Stan `accepted` jest renderowany fail-closed. Presenter przed utworzeniem modelu sprawdza centralnym `KsefNumberValidator` numer KSeF i jego zgodność z NIP-em sprzedawcy, wymaga daty przetworzenia oraz buduje URL przez istniejący `KsefInvoiceVerificationLinkBuilder`. Dopiero kompletny model trafia do wspólnych partiali metadanych i ostrzeżeń oraz do `InvoicePdfRenderer`, który zapisuje KOD I przez TCPDF. Oznaczenia „KSeF TEST — DOKUMENT TESTOWY” i „KSeF DEMO — DOKUMENT TESTOWY” wynikają z zamrożonego środowiska zaakceptowanego submissionu; Production pozostaje bez oznaczenia. Gdy QR wymaga nowej strony, renderer dodaje typ i numer dokumentu oraz nagłówek „Weryfikacja KSeF”.

Brak zaakceptowanej próby daje wyłącznie bezpieczny stan podglądu: oczekiwanie dla `preparing`, `session_opened`, `submitted` i `processing`, osobne ostrzeżenia dla `uncertain`, `rejected` i `technical_failed`, bez numeru KSeF, QR i trybu OFFLINE. Akceptacja jest historycznym faktem i pozostaje widoczna po późniejszym wyłączeniu serii lub integracji. Wersje cache PDF Faktury i Korekty są niezależnie podniesione, a istniejąca obsługa przyjęcia usuwa cache obu typów; następne pobranie generuje finalny dokument. Renderowanie zbiorcze przekazuje model per dokument, więc KOD I nie może zostać współdzielony ani pomieszany między Fakturami lub Korektami.

Kontrolowana weryfikacja `KSeF.8A-LIVE-DEMO-QR-TEST-001: PASS` objęła rzeczywiste PDF-y Accepted DEMO Faktury `BLF 79/2026` i Korekty `BLKF 2/2026`. Oba KODY I zostały fizycznie zeskanowane telefonem, otworzyły oficjalny serwis `qr-demo.ksef.mf.gov.pl` i wskazały właściwe dokumenty. `Invoice QR scan: PASS`; `Correction QR scan: PASS`; `Correction own P_1: PASS`; `Correction own submission/hash/KSeF number: PASS`; `Cross-environment fallback: NO`; `LIVE INVOICE/CORRECTION POST: 0`. Korekta użyła własnego `P_1 = 01.09.2026`, a nie daty Faktury źródłowej `26.08.2026`.

### KSeF.8B.1 — CLOSED

Certyfikaty Offline stanowią osobną domenę od credentiali uwierzytelniających. `KsefOfflineCertificate` przechowuje wiele certyfikatów dla każdego środowiska bez wiązania certyfikatu z NIP-em kontekstu, a `KsefOfflineCertificateSelection` wskazuje najwyżej jeden lokalnie preferowany certyfikat na środowisko. Preferowanie jest wyłącznie ustawieniem NEX-OMS, nie statusem certyfikatu po stronie MF. Import przyjmuje certyfikat PEM albo DER oraz klucz prywatny PEM, opcjonalnie chroniony hasłem używanym wyłącznie podczas importu. Znormalizowany certyfikat i klucz są szyfrowane at rest, ukryte przed serializacją i nigdy nie są prezentowane w HTML; usunięcie lokalnego rekordu nie oznacza unieważnienia certyfikatu w KSeF.

Granica importu wymaga zgodnej pary certyfikat-klucz, aktualnej ważności, dokładnie 16-znakowego numeru seryjnego `[0-9A-F]{16}` oraz profilu klucza RSA 2048 albo EC P-256. Certyfikat musi mieć przeznaczenie Offline `Non-Repudiation`/`Content Commitment`; materiał przeznaczony do Authentication (`Digital Signature`) nie może zostać użyty jako certyfikat Offline. Rotacja nie usuwa poprzednich certyfikatów, a wybór preferowany nie może przekroczyć granicy TEST/DEMO/Production.

`KsefContextIdentifier` jest zamkniętym typem wartości dla `Nip`, `InternalId`, `NipVatUe` i `PeppolId`. `KsefOfflineCertificateVerificationLinkBuilder` tworzy KOD II wyłącznie dla jawnie wskazanego środowiska i buduje dokładny presign bez `https://`, końcowego ukośnika i podpisu. RSA używa PSS z SHA-256, MGF1 SHA-256 i salt length 32; EC P-256 używa SHA-256 oraz podpisu IEEE P1363 `R||S`. Hash Faktury i podpis są kodowane Base64URL bez paddingu, a wynik nie stosuje fallbacku między środowiskami.

Etap dostarcza wyłącznie bezpieczne zarządzanie lokalnym materiałem i kryptograficzny fundament KODU II. Nie emituje Faktur offline, nie dodaje KODU II do PDF, nie zapisuje kontekstu Faktury w certyfikacie, nie wykonuje enrollmentu, wyszukiwania ani unieważnienia certyfikatu w MF i nie wykonuje żadnego requestu KSeF. Lifecycle i enrollment certyfikatów należą do KSeF.8B.2, a właściwy workflow wystawiania offline do KSeF.8C.

### KSeF.8B.1.1 — CLOSED

X.509 `validFrom` i `validTo` opisują konkretne instanty. `KsefOfflineCertificateMaterialService` nadal odczytuje i waliduje je jako timestampy UTC, natomiast `KsefOfflineCertificateService` przed persistence przekazuje je przez wspólny `KsefInstantStorageNormalizer`, który zachowuje instant i przelicza wall clock do `APP_TIMEZONE`. Jest to wymagane, ponieważ Eloquent zapisuje datetime jako `Y-m-d H:i:s`, a SQLite nie zachowuje offsetu nawet dla `dateTimeTz`; po reloadzie casty `valid_from` i `valid_until` reprezentują dzięki temu dokładnie te same instanty.

Ten kontrakt jest współdzielony z istniejącym KSeF.6F.2: `KsefTokenValidityNormalizer` zachowuje publiczne API i deleguje normalizację persistence do tego samego serwisu. Testy prawdziwego roundtripu SQLite obejmują lato UTC+2, zimę UTC+1, przejście DST, jawny offset oraz realny import certyfikatu Offline. Kryptografia i domena 8B.1 pozostają bez zmian, a remote lifecycle i enrollment nadal należą do KSeF.8B.2.

### KSeF.8B.2A — CLOSED

Ręczna operacja „Sprawdź w KSeF” synchronizuje status istniejącego lokalnego certyfikatu Offline. Query i retrieve są logicznie read-only i działają wyłącznie w dokładnym środowisku zapisanym przy certyfikacie. W 8B.2A osobna polityka dopuszczała TEST/DEMO i blokowała Production przed pobraniem tokena oraz HTTP; od 8D.2 dopuszcza również Production. Certyfikat nie jest wiązany z `context_nip`. Po doprecyzowaniu 8B.2A.3 operacja wymaga access tokena pochodzącego z konfiguracji uwierzytelnienia Certificate/XAdES dla tego samego środowiska; Token KSeF jest blokowany lokalnie przed auth i HTTP. GET zakładki nie wykonuje HTTP.

Weryfikacja wykonuje dokładnie `POST /certificates/query?pageSize=10&pageOffset=0` z numerem seryjnym i typem `Offline`, a następnie `POST /certificates/retrieve` dla tego samego numeru. Wynik query musi być jednoznaczny, bez dalszej strony i z dokładnie zgodnym numerem oraz typem. Pobrany Base64 DER jest dekodowany ściśle; NEX-OMS ponownie sprawdza X.509, numer seryjny, fingerprint SHA-256 oraz zgodność lokalnego klucza prywatnego z certyfikatem zwróconym przez MF. Do bazy nie trafia odpowiedź API ani materiał zdalnego certyfikatu, tylko ograniczony snapshot zdalnego statusu, nazwy, ważności i czasu pełnej weryfikacji.

Statusy `Active`, `Blocked`, `Revoked` i `Expired` są zachowywane jako dane MF; status nieznany jest przechowywany bez zgadywania i fail-closed. Certyfikat jest gotowy wyłącznie wtedy, gdy lokalny materiał nadal istnieje i jest ważny, pełna tożsamość pozostaje zgodna, zdalna weryfikacja zakończyła się poprawnie, `remote_status` jest dokładnie `Active`, a zdalny okres ważności obejmuje bieżący instant. Lokalnie preferowany certyfikat nie jest przez to automatycznie gotowy. Nie ma TTL ani automatycznej synchronizacji.

HTTP odbywa się poza transakcją. Po odpowiedzi krótka transakcja z blokadą ponownie sprawdza `id`, środowisko, numer seryjny, fingerprint oraz digest lokalnego certyfikatu i klucza, dzięki czemu zmiana konfiguracji w trakcie requestu nie zapisze nieaktualnego zaufania. Jednoznaczny mismatch tożsamości unieważnia poprzedni snapshot zdalnego zaufania. Błąd połączenia, timeout, 429, 5xx, problem uwierzytelnienia albo niekompletna odpowiedź przed uzyskaniem poprawnego exact query zachowują ostatni poprawny snapshot i zwracają kontrolowany błąd. Po poprawnym query obowiązuje doprecyzowana semantyka 8B.2A.2: świeże metadane są zapisywane przed retrieve, a poprzedni `remote_verified_at` może pozostać tylko dla zweryfikowanego `Active` przechodzącego do świeżego, obecnie ważnego `Active` przy niezmienionej lokalnej tożsamości. Etap nie implementuje enrollmentu, CSR, zdalnego revoke, katalogu remote-only, synchronizacji w tle, wystawiania Offline24 ani KODU II w PDF.

Fundament powstał według kontraktu KSeF API 2.6.1. Po udostępnieniu na TEST API 2.7.0 porównano implementację z faktycznie serwowanym OpenAPI i nie stwierdzono różnic wymagających zmiany kodu transportu. Wersja API nie jest przypięta w runtime; przed kolejnym live rolloutem trzeba ponownie sprawdzić kontrakt środowiska docelowego.

### KSeF.8B.2A.1 — CLOSED

Świeży, jednoznaczny wynik exact query jest granicą fail-closed jeszcze przed retrieve. Jeżeli MF zwróci status inny niż dokładnie `Active`, status nieznany, zdalny okres ważności rozpoczynający się w przyszłości albo już zakończony, NEX-OMS zapisuje w krótkiej transakcji z blokadą aktualny status, nazwę i zdalne daty oraz natychmiast ustawia `remote_verified_at = null`. Przed tym zapisem ponownie sprawdzane są `id`, środowisko, numer seryjny, fingerprint i digesty lokalnego certyfikatu oraz klucza. Request retrieve nadal odbywa się poza transakcją.

Przejściowa awaria retrieve po takim niebezpiecznym query nie może przywrócić poprzedniego `Active` ani gotowości. Poprawny retrieve kończy zwykłą pełną weryfikację tożsamości i zapisuje nowy `remote_verified_at`, natomiast jednoznaczny mismatch retrieve usuwa cały snapshot zaufania. Awaria lub malformed response samego query nadal zachowuje poprzedni snapshot. Jeżeli exact query zwróci świeży, obecnie ważny `Active`, jego metadane są zapisywane; późniejsza przejściowa awaria retrieve może zachować poprzedni `remote_verified_at` wyłącznie zgodnie z warunkami 8B.2A.2. Etap nie dodaje retry, pollingu, kolejki, TTL, enrollmentu ani automatycznej synchronizacji, nie wymaga migracji i nie wykonuje live HTTP.

### KSeF.8B.2A.2 — CLOSED

Kontrakt został ponownie porównany z oficjalnym OpenAPI MF 2.7.1 oraz klientami referencyjnymi. `POST /certificates/query` jest źródłem aktualnych metadanych certyfikatu: numeru seryjnego, nazwy, typu, statusu i zakresu ważności. `POST /certificates/retrieve` pozostaje osobnym źródłem DER oraz danych potrzebnych do pełnej weryfikacji tożsamości. Każdy poprawny, jednoznaczny exact query z dokładnym numerem seryjnym, typem `Offline`, `hasMore = false`, bezpiecznym status string i poprawnymi datami zapisuje przed retrieve świeże `remote_status`, `remote_certificate_name`, `remote_valid_from` i `remote_valid_until`. Nie są stosowane syntetyczne `MIN`/`MAX` ani łączenie starych i nowych dat.

`remote_verified_at` nadal oznacza czas ostatniej pełnej weryfikacji query + retrieve + DER + serial + typ + fingerprint + klucz prywatny. Sam query nie ustawia nowego czasu. Poprzedni czas jest zachowywany wyłącznie wtedy, gdy poprzedni snapshot był dokładnie `Active`, miał niepusty `remote_verified_at`, świeży query jest dokładnie `Active`, jego okres obejmuje bieżący instant, a lokalna tożsamość pozostała niezmieniona. W każdej innej sytuacji query czyści `remote_verified_at`; dopiero udany retrieve ustawia go ponownie. Dzięki temu przejściowa albo niekompletna awaria retrieve zachowuje świeże metadane, ale nie tworzy nowego zaufania. Jednoznaczny mismatch retrieve usuwa cały zdalny snapshot, a błąd lub malformed response samego query nie zmienia poprzednich danych.

Zapis query i końcowy zapis pełnej weryfikacji pozostają krótkimi transakcjami z blokadą i pełnym race guardem lokalnej tożsamości; oba requesty HTTP są wykonywane poza transakcją. Operacja wykonuje najwyżej jeden query i jeden retrieve bez retry. Etap nie dodaje migracji, UI, kolejki, TTL, enrollmentu ani automatycznej synchronizacji i nie wykonywał live HTTP.

### KSeF.8B.2A.3 — CLOSED

Ograniczenie Production opisane w tym etapie jest historyczne; usunięto je w 8D.2 bez zmiany wymagań provenance Certificate/XAdES.

Kontrolowany test `KSeF.8B.2A-LIVE-DEMO-CERT-VERIFY-001` potwierdził, że access token uzyskany metodą Token KSeF został zaakceptowany przez refresh, lecz dokładny `POST DEMO /certificates/query` zakończył się `HTTP 403`. Jest to zgodne z przypadkiem opisanym w oficjalnym repozytorium CIRFMF w issue [#659](https://github.com/CIRFMF/ksef-api/issues/659); issue [#608](https://github.com/CIRFMF/ksef-api/issues/608) zamknięto jako jego duplikat. OpenAPI 2.7.1 nadal opisuje techniczny Bearer dla endpointów, natomiast rozszerzona dokumentacja [Certyfikaty KSeF](https://github.com/CIRFMF/ksef-api/blob/main/certyfikaty-KSeF.md) wymaga dla danych certyfikacyjnych uwierzytelnienia podpisem XAdES. Zaobserwowane zachowanie DEMO i wyjaśnienie w issue są udokumentowane oddzielnie od formalnego kontraktu OpenAPI.

`KsefCertificateManagementAccessTokenProvider` egzekwuje teraz provenance przed wywołaniem ogólnego `KsefAccessTokenManager`: pobiera `KsefCredential` z dokładnego środowiska certyfikatu Offline, wymaga aktywnej metody `Certificate` oraz kompletnej pary Authentication certificate/private key i dopiero wtedy deleguje pobranie access tokena. Token Auth jest odrzucany przed użyciem ważnego cache, refresh i pełnym auth, więc nie dochodzi do query ani retrieve. Dla konfiguracji Certificate można nadal użyć ważnego cached access tokena lub legalnego refresh tokena; przy braku obu ogólny manager uruchamia istniejący flow Certificate/XAdES. Production pozostaje zablokowane wcześniej przez `KsefOfflineCertificateRemoteOperationPolicy`.

Zmiana metody uwierzytelnienia albo materiału certyfikatu Authentication nadal unieważnia access token, refresh token, ich ważność i wynik testu danego środowiska. Nie jest potrzebna kolumna provenance ani migracja. Certyfikat Authentication z `KsefCredential` oraz certyfikat Offline z `KsefOfflineCertificate` pozostają niezależnymi domenami: nie są kopiowane ani automatycznie konwertowane, nie muszą mieć tego samego numeru i certyfikat Offline nie służy do uwierzytelnienia. Certyfikat Offline nadal nie jest wiązany z `context_nip`; autorytatywna kontrola właściciela należy do dokładnego query MF. Etap nie dodaje enrollmentu, CSR, revoke, katalogu zdalnego ani zmian trybu offline. Ponowny live test DEMO należy do osobnego `LIVE-DEMO-002`.

### KSeF.8B.2A-LIVE-DEMO-CERT-VERIFY-002 — PASS

03.09.2026 wykonano jedną kontrolowaną weryfikację istniejącego certyfikatu Offline w środowisku DEMO przez produkcyjny `KsefOfflineCertificateRemoteVerificationService`. Uwierzytelnienie miało provenance Certificate/XAdES i wykorzystało ważny cached access token, więc nie wykonywano nowego challenge, XAdES, pollingu, redeem ani refresh. Exact query i exact retrieve zakończyły się `HTTP 200`, zwracając po jednym certyfikacie typu `Offline`, bez kolejnych stron. Numer seryjny, Base64 DER, X.509, fingerprint SHA-256 oraz zgodność z lokalnym kluczem prywatnym przeszły weryfikację. Zapisano zdalny snapshot ze statusem `Active` i świeżym `remote_verified_at`; roundtrip instantów był zgodny, a readiness wyniosło `YES`. Nie wykonano retry, enrollmentu, CSR, revoke, operacji fakturowych ani requestów TEST/Production.

### KSeF.8C.0 — CLOSED

Każdy request wysłania Faktury VAT albo Korekty w istniejącej sesji Online przekazuje jawne `offlineMode = false`. Bezpośrednio przed transportem `KsefFa3IssueDateReader` odczytuje `P_1` z dokładnego, zamrożonego XML FA(3) submissionu. Parser odrzuca DTD, nie rozwiązuje zasobów zewnętrznych, wymaga właściwego root elementu i namespace FA(3), dokładnie jednego `P_1` oraz poprawnej daty `Y-m-d`.

Transport wymaga, aby `P_1` odpowiadało bieżącemu dniowi kalendarzowemu w `Europe/Warsaw`. Pierwsza kontrola działa przed pobraniem access tokena i przed jakimkolwiek requestem KSeF. Druga używa tego samego odczytanego `P_1` bezpośrednio przed invoice POST; zmiana dnia w trakcie flow powoduje best-effort close otwartej sesji i kontrolowane zakończenie bez wysłania dokumentu. Faktury i Korekty korzystają z tej samej granicy.

Rzeczywisty `invoicingMode` zwrócony przez MF jest zapisywany jako enum `Online` albo `Offline`. Końcowy status 200 bez trybu lub z wartością nieznaną pozostaje `uncertain`; brak trybu przy statusie przetwarzania jest tolerowany, a wartość nieznana nadal prowadzi do `uncertain`. Niespodziewane `Offline` zachowuje prawdę MF: status `accepted`, numer KSeF i rzeczywisty tryb, ale nie emituje zdarzenia `KsefInvoiceAccepted`, nie planuje Online UPO follow-up i blokuje prezentację dokumentu jako Accepted Online PDF. Historyczne zaakceptowane rekordy z `invoicing_mode = null` pozostają czytelne i zachowują dotychczasową prezentację.

Etap wyłącznie utwardza granicę istniejącego workflow Online. Nie dodaje automatycznego przejścia do Offline24, `KsefOfflineIssuance`, KODU II Faktury, Offline PDF, Latarni, deadline engine, automatycznej wysyłki Offline, Korekt Offline, korekty technicznej, obsługi total failure ani rollout'u Production.

### KSeF.8C.1 — CLOSED

Historyczny zakres środowisk 8C.1 obejmował TEST/DEMO. Blokadę Production usunięto w 8D.2; zakres rzeczywistej walidacji PROD opisuje 8D, bez deklaracji LIVE Offline PROD.

`KsefOfflineIssuance` jest odrębnym, niezmiennym agregatem faktu prawnego wystawienia Faktury VAT w trybie Offline24; nie jest próbą transmisji i nie używa `KsefInvoiceSubmission`. Dla pary Faktura-środowisko istnieje najwyżej jeden rekord. Relacja z Fakturą ma `RESTRICT ON DELETE`, a nullable relacja z certyfikatem Offline używa `SET NULL`, ponieważ źródłem historii są zamrożone w issuance dane certyfikatu, a nie bieżący rekord konfiguracyjny.

`KsefOfflineIssuanceService` przechwytuje jeden instant `issuedAt`, wymaga wystawionej i sfinalizowanej Faktury VAT, aktywnej integracji, serii włączonej do KSeF oraz dozwolonego środowiska TEST albo DEMO. Kontekst v1 musi być identyfikatorem `Nip` równym NIP-owi sprzedawcy; jest to ograniczenie NEX, nie ogólna reguła MF. Dla dokładnego środowiska wymagany jest jawnie wybrany certyfikat Offline spełniający istniejący kontrakt `KsefOfflineCertificateReadinessService::isReady()`. Wystawienie nie korzysta z deployment gate transmisji i wykonuje zero HTTP.

Autorytatywny generator FA(3) tworzy dokładny XML, z którego `KsefFa3IssueDateReader` odczytuje `P_1`. W wersji v1 `P_1` musi być tym samym dniem co `issuedAt` w `Europe/Warsaw`. Ten sam zamrożony XML jest zaszyfrowany at rest i stanowi źródło SHA-256 Base64, rozmiaru, KODU I oraz podpisanego KODU II. Zapis obejmuje environment, sprzedawcę i kontekst, schema id, dane certyfikatu wymagane do historycznej oceny gotowości oraz wyłącznie finalne URL-e KODU I i KODU II; certyfikat PEM i klucz prywatny nie są duplikowane.

Kosztowne generowanie, hashowanie i podpis odbywa się poza transakcją. Krótka transakcja ponownie blokuje i porównuje Fakturę z pozycjami, konfigurację, serię, wybór certyfikatu, jego tożsamość, materiał klucza i stan gotowości oraz wykluczające historie. Ograniczenie unikalne jest końcową ochroną duplikatu. Historia dowolnego submissionu Online lub `OutsideKsef` w tym samym środowisku blokuje Offline24; po Offline24 zablokowane są zwykłe prepare/manual/automatic/monthly Online, oznaczenie `OutsideKsef` oraz usunięcie Faktury.

Panel sfinalizowanej Faktury pokazuje akcję POST `WYSTAW OFFLINE24` wyłącznie przy pełnej gotowości oraz jawne ostrzeżenie o trwałym zamrożeniu dokumentu. Po sukcesie pokazuje lokalny status, `P_1`, czas wystawienia, numer seryjny i status certyfikatu oraz informację, że numer KSeF nie został jeszcze nadany; nie ujawnia XML, hasha, fingerprintu, klucza ani pełnego KODU II. Etap nie dodaje PDF Offline, obrazów QR, potwierdzenia transakcji, polityki doręczenia nabywcy, transmisji `offlineMode=true`, powiązania z submissionem, deadline engine, Latarni, automatycznej wysyłki, Korekt Offline, korekty technicznej, trybów awaryjnych ani rollout'u Production.

### KSeF.8C.2 — CLOSED

Wyłączenie Production poniżej opisuje zakres wdrożenia 8C.2, nie stan po 8D.2.

Prezentacja i doręczenie dokumentu Offline24 są operacjami wyłącznie lokalnymi, bez requestów KSeF i bez modyfikowania `KsefOfflineIssuance`. Jedynym źródłem danych jest dokładny, zamrożony XML FA(3) zapisany przy wystawieniu. Centralny ekstraktor przed renderowaniem ponownie sprawdza rozmiar i hash payloadu, namespace i schemat, `P_1`, NIP sprzedawcy oraz zapisane URL-e KODU I i KODU II. PDF-y są generowane na żądanie przez istniejący TCPDF, bez osobnego trwałego cache; usunięcie bieżącej konfiguracji albo rekordu certyfikatu nie zmienia historycznej prezentacji.

Centralna polityka v1 klasyfikuje nabywcę konserwatywnie na podstawie zamrożonego XML: polski NIP dopuszcza wyłącznie potwierdzenie transakcji, a brak polskiego NIP dopuszcza wyłącznie Fakturę Offline. Jest to operacyjna polityka NEX-OMS, a nie pełna kwalifikacja prawna wszystkich przypadków. Niejednoznaczna lub niespójna tożsamość nabywcy kończy się fail-closed. Serwer egzekwuje tę samą decyzję niezależnie od widoczności przycisku w UI.

Faktura Offline zawiera dokładnie dwa lokalnie wygenerowane kody QR ze snapshotowych URL-i: KOD I z etykietą `OFFLINE` i KOD II z etykietą `CERTYFIKAT`. Potwierdzenie transakcji nie jest fakturą; zawiera ograniczone dane stron, `P_2` i `P_15` z walutą oraz dokładnie dwa kody QR z nagłówkami `sprawdź fakturę w KSeF` i `zweryfikuj wystawcę faktury`, bez etykiet `OFFLINE` i `CERTYFIKAT` pod kodami. Dokumenty TEST i DEMO są jawnie oznaczone swoim środowiskiem.

Zwykły, mutowalny PDF Faktury, jego istniejący cache oraz PDF zbiorczy są blokowane dla Faktury posiadającej Offline24, także przy bezpośrednim użyciu renderera. Etap nie dodaje transmisji `offlineMode=true`, harmonogramu terminów, integracji z Latarnią, Korekt Offline ani rollout'u Production.

### KSeF.8C.2.1 — CLOSED

Prezentacja podatkowa Offline24 zachowuje pełną semantykę zamrożonego FA(3), w tym historyczne stawki `22` i `7`. Etykiety bucketów są ustalane wyłącznie z wartości `FaWiersz/P_12`: `23`/`22`, `8`/`7`, `5`, `0 KR`, `0 WDT` i `0 EX`. Gdy wspólny bucket zawiera obie historyczne stawki, prezentacja używa jednoznacznego opisu `23% / 22%` albo `8% / 7%`; nie dzieli kwot bez źródła w XML. Wartości pieniężne podsumowania pozostają dokładnymi wartościami `P_13_*` i `P_14_*`. Brak zgodności pomiędzy stawkami linii a obecnością właściwego bucketu kończy prezentację fail-closed.

Historyczne URL-e KODU I i KODU II są używane bez przebudowywania i bez ponownego podpisywania. Ich walidacja nie zależy od bieżącego `config('ksef.qr_base_urls.*')`, lecz od jawnej polityki canonical hostów przypisanych do środowisk TEST, DEMO i PRODUCTION oraz od dokładnej struktury ścieżek, zamrożonego NIP-u sprzedawcy, `P_1`, hasha, kontekstu i numeru seryjnego certyfikatu. KOD II dodatkowo wymaga niepustego canonical Base64URL podpisu. Host złośliwy albo host innego środowiska jest odrzucany. Jeżeli MF w przyszłości zastąpi host, dotychczasowe oficjalne hosty muszą pozostać na allowliście dla historycznych zapisów.

Potwierdzenie transakcji zachowuje tytuł `POTWIERDZENIE TRANSAKCJI`, podstawowe dane sprzedawcy i nabywcy, `P_2`, `P_15` z walutą oraz dwa kody QR z nagłówkami `sprawdź fakturę w KSeF` i `zweryfikuj wystawcę faktury`. Nie zawiera etykiet `OFFLINE` ani `CERTYFIKAT`, pozycji, podsumowania VAT, danych płatności, numeru zamówienia ani dodatkowego zdania „Ten dokument nie jest fakturą.”. Oznaczenie TEST/DEMO pozostaje technicznym zabezpieczeniem rollout'u. Polityka doręczenia 8C.2 pozostaje bez zmian, generowanie wykonuje zero HTTP, etap nie dodaje migracji ani transmisji Offline24.

### KSeF.8C.3 — CLOSED

Ograniczenie transmisji do TEST/DEMO było granicą etapu 8C.3. Od 8D.2 polityka obejmuje również Production; historyczny wynik tego etapu nie jest dowodem LIVE Offline PROD.

`KsefInvoiceSubmission` może wskazywać `KsefOfflineIssuance` przez nullable `offline_issuance_id` z `restrictOnDelete`; relacja nie jest unikalna, ponieważ jedno niezmienne wystawienie może mieć kolejną jawną próbę dopiero po jednoznacznym `TechnicalFailed`. Próba Offline24 kopiuje do zaszyfrowanego payloadu submissionu dokładnie zamrożony XML, hash, rozmiar, schemat, środowisko, kontekst, sprzedawcę i historyczny czas wystawienia. Generator FA(3) ani bieżące dane Faktury nie są używane. Integralność issuance i jego powiązania z submissionem jest sprawdzana przed siecią oraz ponownie bezpośrednio przed POST-em Faktury.

Transport korzysta z istniejącego stosu publicznego klucza szyfrowania, świeżego AES/IV, sesji online i lifecycle submissionu, ale wysyła jawne `offlineMode=true` bez `hashOfCorrectedInvoice`. Dla zwykłego Online fabryka nadal wysyła `offlineMode=false`. Offline24 nie stosuje ograniczenia `P_1 == dzisiaj`; późniejsze wysłanie przekazuje dokładny historyczny XML. Środowisko i kontekst pochodzą z issuance. Bieżące środowisko może być inne, lecz bieżący NIP kontekstu musi odpowiadać zamrożonemu kontekstowi przed jakimkolwiek HTTP. Wyłączona integracja blokuje transport, natomiast późniejsze wyłączenie serii lub usunięcie historycznego certyfikatu KODU II nie blokuje obowiązku przekazania. TEST i DEMO są dozwolone, Production pozostaje zablokowane.

Pierwsza próba i każdy jawny retry są tworzone w krótkiej transakcji, a HTTP działa po commit. Jedna próba wykonuje najwyżej jeden invoice POST. `TechnicalFailed` pozwala na nową ręczną próbę z tym samym issuance i identycznym payloadem; `Rejected` nie pozwala na ślepy resend, a `Uncertain` wymaga reconciliation po dokładnym hash/reference. Nie ma automatycznego retry ani automatycznego wysyłania Offline24.

Oczekiwany `invoicingMode` wynika z obecności powiązania: zwykła próba oczekuje `Online`, a Offline24 oczekuje `Offline`. Zgodny `Accepted Offline` zachowuje numer KSeF, planuje UPO i emituje istniejące zdarzenie `KsefInvoiceAccepted`. Rozbieżność trybu zachowuje prawdę transportową `Accepted`, ale fail-closed blokuje zdarzenie, UPO i dokument dla nabywcy. UPO jest walidowane względem oczekiwanego trybu i pobierane z zamrożonego środowiska przy zachowaniu bieżącej bramki kontekstu.

Po prawidłowym przyjęciu dedykowany PDF Accepted Offline nadal powstaje wyłącznie z zamrożonego XML i zawiera dokładnie jeden KOD I z zapisanym URL-em oraz numerem KSeF jako etykietą; KOD II i etykiety `OFFLINE`/`CERTYFIKAT` nie występują. Wcześniejsze dokumenty 2QR są wtedy blokowane. Interfejs pokazuje każde historyczne issuance z jego własnym środowiskiem oraz akcje zależne od stanu: wyślij, odśwież, reconcile, retry po `TechnicalFailed`, albo końcowy PDF i UPO. Etap nie dodaje deadline engine, Latarni, automatycznej wysyłki, Korekt Offline, korekty technicznej, trybów awaryjnych ani rollout'u Production.

Granica side effectu POST Faktury jest jawna: błędy przed nią oraz jednoznaczne 4xx prowadzą do `technical_failed`, natomiast timeout, błąd połączenia, 5xx i niekompletna odpowiedź 2xx prowadzą do blokującego `uncertain`. Nie ma automatycznego retry ani persistence materiału potrzebnego do powtórzenia tej samej sesji. Token dostępu jest pobierany wyłącznie po zgodności aktualnej konfiguracji z zamrożonym kontekstem, również dla cached tokena, refresh i pełnego reauth. Otrzymanie referencji Faktury ustawia tylko `submitted`; błąd close jest przechowywany osobno. Status odpowiedzi jest mapowany dopiero po dokładnej korelacji `referenceNumber` i `invoiceHash`, a `accepted` wymaga dodatkowo kodu 200, poprawnego CRC numeru KSeF oraz zgodności jego prefiksu NIP z zamrożonym sprzedawcą. Brak lub mismatch identity prowadzi do `uncertain`; nieznany status jest traktowany konserwatywnie.

### KSeF.8C.4A — CLOSED

Integracja Latarni KSeF opiera się na oficjalnym repozytorium `CIRFMF/ksef-latarnia` w rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a` i OpenAPI 1.0.0. Odrębny klient read-only wywołuje wyłącznie publiczne, nieuwierzytelnione `GET /status` i `GET /messages`. Hosty są zamknięte w polityce protokołu: TEST używa `api-latarnia-test.ksef.mf.gov.pl`, PRODUCTION używa `api-latarnia.ksef.mf.gov.pl`, a KSeF DEMO nie ma mapowania i kończy się kontrolowanym błędem bez HTTP. Klient nie odczytuje ani nie wysyła ustawień kontekstu, tokenów, certyfikatów lub innych credentials KSeF, nie przyjmuje hosta od użytkownika i nie wykonuje retry.

Parser fail-closed dopuszcza wyłącznie statusy `AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`, oficjalne kategorie oraz zgodne typy `MAINTENANCE_ANNOUNCEMENT`, `FAILURE_START` i `FAILURE_END`. Status musi być uzasadniony co najmniej jednym komunikatem własnej kategorii; wyższy priorytet może współistnieć z komunikatami niższego priorytetu, a `AVAILABLE` wymaga braku komunikatów. Wszystkie daty wymagają jawnego offsetu albo `Z` i są normalizowane do UTC. W OpenAPI pole `end` figuruje w `required`, ale opis, przykłady i scenariusze MF pomijają je dla trwającego `FAILURE_START`; dlatego parser akceptuje dla START brak/null, wymaga `end >= start`, gdy wartość istnieje, wymaga `end >= start` dla END i ściśle `end > start` dla maintenance. Nieznane neutralne pola są zachowywane w źródłowym payloadzie, mimo aktualnego `additionalProperties=false`, lecz nie uczestniczą w logice.

`ksef_latarnia_messages` przechowuje niezmienną lokalną historię według `(source_environment, external_message_id, version)`, w tym dokładne `eventId`, znormalizowane chwile, kanoniczny JSON i Base64 SHA-256. Kanonizacja rekurencyjnie sortuje klucze obiektów, zachowuje kolejność tablic i typy JSON. Ponowne otrzymanie tej samej wersji i hasha aktualizuje wyłącznie `last_seen_at`; nowa wersja tworzy nowy rekord, zniknięcie z feedu niczego nie usuwa, a zmiana payloadu bez zwiększenia wersji jest blokowana jako `ksef_latarnia_message_version_conflict`. Pola biznesowe i usuwanie historii są chronione również na poziomie modelu.

`ksef_latarnia_sync_states` przechowuje jeden ostatnio potwierdzony status na środowisko, jego kanoniczny payload/hash oraz niezależne chwile prób, sukcesów i bezpieczne błędy dla `/status` i `/messages`. Każda odpowiedź jest walidowana w całości przed atomowym zapisem. Awaria nie oznacza `AVAILABLE` i nie usuwa poprzedniego poprawnego statusu; powodzenie czyści bieżący błąd tylko własnego endpointu. Synchronizacja obu endpointów pozwala na częściowy sukces, więc poprawne komunikaty pozostają zapisane przy awarii statusu, a poprawny status z osadzonymi komunikatami pozostaje zapisany przy awarii feedu.

Etap nie dodaje harmonogramu, kolejki, UI, deadline engine, kalendarza dni roboczych, powiązania z Fakturą, modyfikacji `KsefOfflineIssuance`, wpływu na transport ani nowej procedury Offline. `TOTAL_FAILURE` jest w 8C.4A wyłącznie informacją; nie zmienia obowiązków, terminów, trybu wystawienia ani możliwości POST-u Faktury. Te decyzje należą do późniejszych etapów, w szczególności KSeF.8C.4B.

### KSeF.8C.4A.1 — CLOSED

Kontrolowany odczyt Latarni TEST ujawnił błąd round-trip czasu: parser poprawnie zwracał UTC i surowy SQLite przechowywał prawidłowy UTC wall-clock, lecz standardowy cast `immutable_datetime` interpretował pozbawioną offsetu wartość w `APP_TIMEZONE=Europe/Warsaw`. Pola czasu komunikatów i stanu synchronizacji używają teraz dedykowanego `KsefUtcInstantCast`: zapis przelicza `DateTimeInterface` do UTC, a odczyt interpretuje surową wartość jawnie jako UTC i odrzuca uszkodzony format. Kontrakt ma postać remote ISO instant → parser UTC → DB UTC wall-clock → model UTC instant.

Istniejące rekordy live nie zostały przepisane, ponieważ ich surowe wartości były już poprawne. Testy obejmują zimę, lato, oba brzegi wiosennej zmiany czasu oraz dwa różne instanty powtarzającej się godziny jesiennej; oba jesienne instanty pozostają rozróżnialne po zapisie i odczycie. Aktualny schemat i format dat Eloquent zachowują precyzję do jednej sekundy; źródłowy kanoniczny payload nadal zachowuje ewentualne części ułamkowe. Nie dodano migracji tylko w celu rozszerzenia precyzji. `KsefInstantStorageNormalizer` i starsze kontrakty czasu tokenów/certyfikatów pozostają bez zmian.

Etap nie zmienia parserów, klienta HTTP, historii wersji, transportu KSeF ani wystawień Offline. Nie dodaje deadline engine, kalendarza dni roboczych, schedulera, kolejki, UI, powiązań z Fakturą, procedur awaryjnych ani skutków biznesowych `TOTAL_FAILURE`.

### KSeF.8C.4B — CLOSED

Deterministyczna projekcja obowiązku przesłania Offline24 składa się z `PolishBusinessDayCalendar`, `KsefLatarniaFailureEventProjector` i `KsefOfflineSubmissionObligationEngine`. Silnik przyjmuje immutable `KsefOfflineIssuance`, lokalne komunikaty Latarni, historię submissionów, jawny `CarbonImmutable $asOf` oraz jawny poziom coverage; nie odczytuje zegara globalnego, bazy ani sieci i niczego nie zapisuje. Wynik `KsefOfflineSubmissionObligation` zawiera status, bazową i opcjonalną efektywną datę terminu, powód, coverage, użyte event/message IDs, ostatni status submissionu, czas oceny oraz dane awarii uzasadniające projekcję. Terminy są datami lokalnymi `Europe/Warsaw` z semantyką „do końca dnia”.

Decision record `BUSINESS DAY DEFINITION`, stan prawny zweryfikowany 03.09.2026: źródłami są [ustawa o VAT, tekst jednolity Dz.U. 2025 poz. 775](https://eli.gov.pl/api/acts/DU/2025/775/text/U/D20250775Lj.pdf) (art. 106nda, 106ne, 106nf, 106nha i 106nh), [Ordynacja podatkowa, tekst jednolity Dz.U. 2026 poz. 622](https://eli.gov.pl/api/acts/DU/2026/622/text/U/D20260622Lj.pdf) (art. 2, 3 i 12), [ustawa o dniach wolnych od pracy, Dz.U. 2025 poz. 296](https://eli.gov.pl/api/acts/DU/2025/296/text/T/D20250296L.pdf), oficjalne materiały MF [Offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/), [tryb awaryjny](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-awaryjny/), [Podręcznik KSeF 2.0 część III z 30.04.2026](https://ksef.podatki.gov.pl/media/claghxzf/podrecznik-ksef-20-cz-iii-dodatkowe-funkcjonalnosci-ksef-30042026.pdf) oraz [oficjalne objaśnienie MF identycznego pojęcia „dni roboczych” w tej samej ustawie o VAT](https://podatki.gov.pl/pozostale/procedura-szczegolna-sme/pytania-i-odpowiedzi). Sobota, niedziela i święta ustawowe są niebiznesowe; 24 grudnia jest uwzględniany od 2025 r. Dzień zdarzenia jest dniem 0, pierwszy późniejszy dzień roboczy dniem 1, a siódmy dzień pozostaje dostępny do końca lokalnego dnia. Wielkanoc, Poniedziałek Wielkanocny, Zielone Świątki i Boże Ciało są liczone lokalnym algorytmem gregoriańskim, bez zewnętrznego kalendarza.

Bazowy termin jest zawsze następnym dniem roboczym po zamrożonym `KsefOfflineIssuance.issue_date` (`P_1`); `issued_at` służy wyłącznie jako instant graniczny „po wystawieniu”. Regułę zwykłej awarii uruchamia `FAILURE_START.published_at > issued_at`, także gdy publikacja nastąpiła po bazowym terminie, ale dokument nadal nie ma rozstrzygniętej transmisji. Trwająca awaria daje `WAITING_FOR_FAILURE_END`; po jej końcu `end_at` jest przeliczane na polską datę i termin wynosi siedem kolejnych dni roboczych. Następna awaria resetuje termin, gdy jej publikacja mieści się do końca bieżącego dnia terminu; algorytm obsługuje dowolny liniowy łańcuch. `TOTAL_FAILURE` opublikowane po wystawieniu ma pierwszeństwo i daje wyłącznie read-only projekcję `NOT_REQUIRED_TOTAL_FAILURE`; prawny komunikat awarii całkowitej pochodzi ze środków społecznego przekazu, a Latarnia jest tu strukturalnym evidence bez nieodwracalnego skutku biznesowego. `MAINTENANCE` nie zmienia terminu Offline24.

Projector, zgodny z kontraktem [CIRFMF/ksef-latarnia](https://github.com/CIRFMF/ksef-latarnia) w rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a`, wybiera najwyższą wersję każdego `(source_environment, external_message_id)`, grupuje komunikaty według `event_id` i kategorii oraz składa START/END, w tym zgodny `end_at` zapisany w START. Sprzeczne kategorie, duplikaty START, różne końce, zła chronologia i nakładające się niejednoznaczne awarie kończą się `AMBIGUOUS_EVENT_HISTORY`. TEST używa tylko Latarni TEST, PRODUCTION tylko produkcyjnej, a DEMO nigdy nie korzysta z fallbacku i ma coverage `unsupported_environment`. Brak komunikatów jest wystarczający tylko przy jawnym `complete`; `insufficient` daje `EVIDENCE_UNAVAILABLE` i nieautorytatywny `effectiveDeadline=null`.

Historia submissionu jest ograniczona do dokładnego `offline_issuance_id`, zgodnego Invoice i environment, a najnowszą próbę wyznacza wyłącznie `attempt_number`. `Accepted Offline` daje `FULFILLED` bez wymagania coverage; `Submitted`/`Processing` z referencją daje `SUBMITTED_PENDING_RESULT`; `Uncertain` daje `TRANSMISSION_UNCERTAIN`; `Rejected` wymaga remediation; `Preparing`, `SessionOpened` i `TechnicalFailed` bez referencji pozostawiają aktywny termin. Accepted w trybie Online daje `TRANSPORT_MODE_MISMATCH`, a niespójne dane submissionu osobny fail-closed integrity status. `created_at`, `updated_at`, `generated_at` i `session_closed_at` nigdy nie są interpretowane jako czas POST-u. Etap nie dodaje migracji, tabeli/cache terminu, HTTP, synchronizacji, schedulera, kolejki, UI, automatycznej wysyłki, guardu transportu, mutacji Faktury/issuance ani nowych procedur Offline.

### KSeF.8C.4B.1-R2 — CLOSED

Dwie wcześniejsze próby zostały zablokowane, ponieważ zapis exact instant jako pozbawionego offsetu wall-clock w `APP_TIMEZONE` traci rozróżnienie pomiędzy dwoma wystąpieniami jesiennej godziny. Przyjęto jeden kontrakt dla `KsefOfflineIssuance.issued_at` i `KsefInvoiceSubmission.generated_at`: domenowy exact instant jest zapisywany jako surowy UTC wall-clock i odczytywany jako ten sam instant UTC przez istniejący `KsefUtcInstantCast`.

Migracja `083000` interpretuje wyłącznie historyczne wartości tych dwóch pól jako lokalny wall-clock `Europe/Warsaw`, wymaga dokładnie jednego odwzorowania na UTC i dopiero po walidacji wszystkich rekordów atomowo zapisuje UTC. Powtarzająca się godzina jesienna oraz nieistniejąca godzina wiosenna kończą migrację fail-closed; rollback jest jawnie nieodwracalny. Historyczne ścieżki zapisu `generated_at` zostały potwierdzone jako jednolite, bez mieszanego kontraktu.

Writer Online przekazuje teraz `generated_at` jako exact UTC instant, a ścieżka Offline kopiuje dokładnie `issued_at` do `generated_at`. Porównanie timestampów w integralności Offline pozostaje bez tolerancji, a payload, hash, rozmiar, schema, `offlineMode`, lifecycle sesji i semantyka POST-u nie uległy zmianie. Migracja `083000` została następnie kontrolowanie wykonana na bazie operatora. Pozostałe timestampy submissionu nie były częścią tej zmiany.

### KSeF.8C.4B.2 — CLOSED

Pozostałe exact-instant fields `KsefInvoiceSubmission`: `session_valid_until`, `session_closed_at`, `acquisition_date`, `invoicing_date`, `permanent_storage_date`, `last_checked_at`, `next_follow_up_at` i `last_follow_up_at` używają teraz wspólnego `KsefUtcInstantCast`. Każde pole zapisuje exact instant jako surowy UTC `Y-m-d H:i:s` i odczytuje ten sam instant w UTC, niezależnie od `APP_TIMEZONE`. Dotyczy to ważności i zamknięcia sesji, dat zwracanych przez KSeF, chwili sprawdzenia statusu oraz planowania i wykonania follow-upów. Porównanie due/future i zapytanie dispatchera pracują na instantach UTC; opóźnienia, `Retry-After`, backoff, limiter i liczniki prób zachowują dotychczasową semantykę.

Migracja `084000` obejmuje wyłącznie tych osiem kolumn i interpretuje historyczne wartości jako naive wall-clock `Europe/Warsaw`. Najpierw waliduje wszystkie rekordy przy użyciu jawnych kandydatów offsetów z `DateTimeZone::getTransitions()`, a dopiero potem aktualizuje je w jednej transakcji z optimistic raw guardem. Jesienna godzina niejednoznaczna, wiosenna godzina nieistniejąca, zły format lub zmiana współbieżna kończą migrację fail-closed bez częściowego zapisu; `down()` jest jawnie nieodwracalne. Na bazie operatora `084000` nie została uruchomiona i pozostaje `Pending`.

Kontrakty UTC `KsefOfflineIssuance.issued_at` oraz `KsefInvoiceSubmission.generated_at`, migracja `083000`, integralność Offline, payload XML, hash, rozmiar, schema, szyfrowanie, `offlineMode`, requesty sesji, invoice POST, status mapping i UPO pozostają bez zmian. Etap nie dodaje schedulera, kolejki, UI, automatycznej wysyłki, HTTP, zmian deadline engine ani nowych procedur Offline.

### KSeF.8C.4C — Operational Latarnia integration

Kontrakt oficjalnej Latarni zweryfikowano dla rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a` i OpenAPI `1.0.0`: publiczne `GET /status` i `GET /messages` pozostają zgodne z fundamentem 8C.4A, TEST używa `api-latarnia-test.ksef.mf.gov.pl`, a Production `api-latarnia.ksef.mf.gov.pl`. Latarnia nie udostępnia środowiska DEMO, dlatego DEMO jest jawnie niewspierane i nigdy nie fallbackuje do TEST ani Production.

Opcjonalny scheduler `ksef-latarnia-sync`, domyślnie wyłączony przez `KSEF_LATARNIA_SYNC_ENABLED=false`, działa co pięć minut z `withoutOverlapping()`. W jednym przebiegu deduplikuje maksymalnie dwa istotne środowiska: środowisko aktywnej integracji oraz środowiska utrwalonych wystawień Offline24, ograniczone do TEST i Production. Każde środowisko wykonuje najwyżej jeden status GET i jeden messages GET, bez retry, kolejki, zależności od automatycznej wysyłki lub zmiany reguł transportu.

Udany odczyt `/messages` utrwala razem z komunikatami konserwatywne okno evidence `[T-30 dni, T]` w nowych polach `messages_coverage_from_at` i `messages_coverage_through_at`. Okna nakładające się lub stykające są łączone, a luka większa niż retencja resetuje początek do nowego `T-30 dni`; błąd, konflikt wersji i odczyt samego `/status` nie przesuwają coverage. Migracja `085000` dodająca nullable exact instants została wykonana przez operatora.

`KsefLatarniaEvidenceService` uznaje evidence za kompletne tylko wtedy, gdy wystawienie mieści się w ciągłym oknie, `coverageThrough <= asOf`, a jego wiek nie przekracza 15 minut. Pełna ocena silnika używa dokładnie `evidenceAsOf = coverageThrough`; komunikaty opublikowane lub po raz pierwszy pobrane później nie są widoczne, więc projekcja nie korzysta z przyszłej wiedzy. Przy niepełnym evidence silnik ocenia na bieżący `asOf` i zwraca fail-closed `EVIDENCE_UNAVAILABLE`.

Zakładka „Latarnia” konfiguracji i lista Faktur czytają wyłącznie lokalny stan. Zakładka pokazuje status, świeżość, coverage, bezpieczne błędy i maksymalnie 20 ostatnich komunikatów oraz umożliwia jawne ręczne odświeżenie wybranego TEST lub Production niezależnie od gate; DEMO kończy się bez HTTP. Lista Faktur wylicza read-only badge obowiązku Offline24 stałą liczbą zapytań, bez N+1, HTTP i zapisów. Pilne stany dają jedno zbiorcze ostrzeżenie, natomiast `TOTAL_FAILURE` pozostaje odwracalną projekcją informacyjną i nie modyfikuje dokumentu ani transportu.

Etap nie dodaje auto-send, transport guardów, nowej procedury Offline ani skutków prawnych zapisywanych w domenie. Kontrolowana walidacja live synchronizacji została odłożona; implementacja i testy używają wyłącznie fake HTTP.

### KSeF.8C.4C.1 — CLOSED

Pełne evidence Latarni wymaga dokładnego niezmiennika `coverageFrom <= issuance.issued_at <= coverageThrough <= requestedAsOf` oraz dotychczasowej świeżości granicy końcowej. Faktura Offline24 wystawiona po ostatnim kompletnym odczycie `/messages` pozostaje fail-closed jako `EVIDENCE_UNAVAILABLE` do kolejnej kompletnej synchronizacji obejmującej moment wystawienia; ocena Complete nigdy nie jest wcześniejsza od `issued_at`.

Hardening nie zmienia engine, projektora, synchronizacji coverage, schedulera ani ręcznego odświeżania. Migracja `085000` została wcześniej wykonana przez operatora; etap nie dodaje migracji ani backfillu, nie wykonuje live HTTP i nie powoduje mutacji danych biznesowych.

### KSeF.8C.5 — Planned unavailability + ordinary failure

Historyczny zakres 8C.5 blokował operacje Production. 8D.2 usunęło tę blokadę kodową, nie wymagania świeżego evidence Latarni; gotowość operacyjna tych procedur PROD nie została potwierdzona przez Online LIVE 8D.5.

Stan prawny i techniczny zweryfikowano 04.09.2026 na podstawie ustawy o VAT (art. 106nda, 106nf, 106ng, 106nh, 106nha i 106gb), oficjalnych stron MF o trybach szczególnych i kodach QR oraz dokumentacji CIRFMF `ksef-api` i `ksef-latarnia`. Model wystawienia Offline rozróżnia trzy niezmienne procedury prawne: `offline24`, `planned_unavailability` i `failure`. Wszystkie korzystają z tego samego technicznego transportu `offlineMode=true`, tego samego zamrożonego XML FA(3), `P_1` wynikającego z lokalnej daty `issued_at` w `Europe/Warsaw`, hasha, rozmiaru, schematu, certyfikatu Offline typu 2 oraz kryptografii KODU I i KODU II. XML użyty do kodów jest dokładnie XML-em późniejszej transmisji; nie jest regenerowany.

Wystawienie podczas planowanej niedostępności albo zwykłej awarii jest jawnie wybierane przez operatora i korzysta wyłącznie ze świeżego lokalnego stanu Latarni. Nie wykonuje automatycznego `GET /status`, `GET /messages` ani innego HTTP. Domyślne okno świeżości wynosi 15 minut. `planned_unavailability` wymaga statusu `MAINTENANCE` oraz dokładnie jednego aktywnego `MAINTENANCE_ANNOUNCEMENT`; `failure` wymaga statusu `FAILURE` oraz dokładnie jednego aktywnego zwykłego zdarzenia `FAILURE`. Brak, nieaktualność, niejednoznaczność, późne pierwsze pobranie, niespójność statusu lub `TOTAL_FAILURE` kończą kwalifikację fail-closed. DEMO nie ma Latarni i nie fallbackuje do TEST lub Production. Domena potrafi odczytać Production, ale istniejąca polityka operacyjna nadal blokuje wystawienie i transport Production.

Migracja `086000` dodaje do `ksef_offline_issuances` nullable provenance środowiska, eventu, wiadomości z wersją i kategorią, jej okna czasowego i publikacji oraz exact-instant evidence `as_of/from/through`. Wszystkie nowe instanty używają `KsefUtcInstantCast`. Historyczne `Offline24` zachowują `NULL` bez backfillu. Dla nowych procedur dokładny trigger jest zamrażany razem z issuance, a ponowna kontrola w transakcji chroni przed zmianą lokalnego evidence pomiędzy kwalifikacją i zapisem. Procedura, XML i provenance pozostają niezmienne. Migracja `086000`: `Ran` na bazie operatora; read-only weryfikacja zakończona PASS. KSeF.8C.5 DB migration: CLOSED.

Read-only engine obowiązku zachowuje pierwszeństwo stanów transmisji. Dla `Offline24` bazą jest następny polski dzień roboczy po `P_1`. Dla planowanej niedostępności termin jest nieznany podczas trwania przerwy, a po jej końcu przypada na następny polski dzień roboczy. Dla zwykłej awarii termin jest nieznany do końca awarii, a następnie wynosi siedem polskich dni roboczych; kolejna zwykła awaria w bieżącym okresie resetuje termin od własnego końca. Zwykła awaria rozpoczęta podczas planowanej niedostępności zastępuje jej krótszy termin. Późniejsza awaria całkowita może nadal dać wyłącznie odwracalną projekcję `NOT_REQUIRED_TOTAL_FAILURE`; system nie implementuje wystawienia w trybie awarii całkowitej i nie zapisuje wyniku projekcji.

Przed akceptacją polityka doręczenia `Offline24` i planowanej niedostępności pozostaje dotychczasowa: krajowy nabywca z polskim NIP-em otrzymuje potwierdzenie transakcji, a nabywca bez polskiego NIP-u Fakturę Offline. W trybie zwykłej awarii Faktura Offline z dwoma kodami QR jest dokumentem podstawowym także dla krajowego nabywcy z polskim NIP-em. Submission, status, zaakceptowany PDF i UPO korzystają ze wspólnej ścieżki i wymagają `TrybWysylki=Offline`.

Etap nie dodaje wystawienia w trybie awarii całkowitej, Korekt Offline, korekty technicznej, automatycznego wyboru procedury, auto-send, kolejki ani zmiany schedulera Latarni. Nie odblokowuje Production i nie wykonuje live HTTP. Walidacja live nowych procedur jest odłożona do osobnego kontrolowanego etapu.

### KSeF.8C.5.1 — CLOSED

Zwykła awaria opublikowana podczas nadal trwającej planowanej niedostępności natychmiast przejmuje regułę terminu. Trwająca awaria daje `WaitingForFailureEnd`, a po jej zakończeniu termin wynosi siedem polskich dni roboczych od daty zakończenia awarii; engine nie czeka na koniec pierwotnej przerwy maintenance. Operational eligibility dodatkowo fail-closed odrzuca odwrócone okno Latarni `coverageFrom > coverageThrough`, zachowując poprawność okna równego i prawidłowo uporządkowanego.

Mini-etap nie zmienia migracji `086000`, issuance core, ochrony TOCTOU, transportu, delivery policy, QR, schedulera, projekcji Total Failure ani zakresu Korekt. Nie wykonuje live HTTP ani migracji na bazie operatora.

### KSeF.8C.6 — Ordinary Offline Corrections

Ograniczenia Production w 8C.6 i jego końcowym wpisie dotyczą stanu tego etapu, przed 8D.2. Sukces LIVE DEMO pozostaje wyłącznie dowodem DEMO.

Jawne `issueCorrectionOffline24`, `issueCorrectionPlannedUnavailability` i `issueCorrectionFailure` używają wspólnego `KsefOfflineIssuanceService`. Niezfinalizowana, wydana Korekta zostaje zamknięta w nadrzędnej transakcji; każda odmowa wystawienia cofa także finalizację. `KsefFa3CorrectionDocumentGenerator` zachowuje autorytatywny validator, source resolver, mapper, builder i oficjalny XSD. Nie powstaje nowy model ani schemat bazy. Migracje `079000–086000` są Ran na bazie operatora; etap nie wykonuje migracji.

Źródło Online lub Offline wymaga jednoznacznego Accepted i poprawnego numeru KSeF w dokładnie tym samym środowisku. Primary Offline bez numeru jest blokowany. Legalne, jawne OutsideKsef daje `NrKSeFN=1`, bez `NrKSeF` i `NrKSeFFaKorygowanej`; nie może przykrywać istniejącego issuance Offline. Poprzednie Korekty nadal wymagają Accepted zgodnie ze wspólnym resolverem. Source-reference fingerprint obejmuje root, Accepted submission identity/number, provenance, sprzedawcę i poprzednie Korekty. Odczyt pod lockiem oraz recheck przed CREATE blokuje TOCTOU przez `ksef_offline_correction_source_changed`.

Zamrożony `KOR` ma własne P_1 równe polskiemu dniowi uchwyconego `issued_at` UTC, własne P_2 i referencję root. Hash, size, schema, zaszyfrowany payload, KOD I i podpis KODU II odnoszą się do tych samych bajtów. Wspólny transport kopiuje je bez generatora; `generated_at=issued_at`, `offlineMode=true`, `hashOfCorrectedInvoice` jest nieobecny. To korekta biznesowa, nie remediation techniczna odrzuconego XML. Rejected zachowuje blokadę blind resend oraz `RejectedRemediationRequired`; 8C.7 pozostaje osobnym etapem.

Integralność ma jawną gałąź Correction: kontroluje typ, relację, P_2, hash/size/schema/P_1, sprzedawcę, pary StanPrzed/Po, sumy różnic oraz referencję source. Jawna sprzeczność bieżącej historii z zamrożoną referencją blokuje wysyłkę, nie przepisuje XML. Parser i PDF korzystają wyłącznie z frozen FA(3), pokazują Korektę, przyczynę, źródło, zmianę nabywcy i pary pozycji z różnicami; standardowy mutable PDF nie omija polityki Offline.

Delivery jest wspólne: Offline24/planned dla krajowego nabywcy z NIP daje potwierdzenie transakcji, dla pozostałych wspieranych nabywców PDF Korekty z dwoma QR; Failure daje ten PDF obu grupom. Potwierdzenie identyfikuje Korektę i zachowuje dwa techniczne QR. Po Accepted działa własny frozen PDF Korekty z jednym KODEM I i jej numerem KSeF; pre-acceptance routes są zamknięte. UPO używa istniejącej walidacji `Offline`.

`KsefDocumentViewData` współdzieli dotychczasową projekcję edycji. Korekta ma jawne akcje i bezpieczne przyczyny blokad, preflight bez XML/finalizacji/HTTP oraz potwierdzenie zamknięcia. Lista wykorzystuje istniejący bulk query/presenter obowiązku, bez N+1 dla mieszanych Invoice/Correction. Deadline engine pozostaje wspólny: następny dzień roboczy po P_1, reguły maintenance, siedem dni roboczych po awarii, pierwszeństwo awarii w trakcie maintenance i read-only TotalFailure projection.

Brak TotalFailure issuance, auto-send, odświeżania Latarni przy GET/issuance, zmian schedulera i odblokowania Production. DEMO dopuszcza Offline24, ale nie planned/failure bez źródła Latarni. Implementacja kodowa nie wykonywała live HTTP; późniejsza kontrolowana walidacja operatorowa została zakończona w `KSeF.8C.6-LIVE-TEST-001-R3` i jest opisana w końcowym wpisie tej sekcji.

Audyt oficjalnego kontraktu (05.09.2026): [ustawa o VAT, tekst ujednolicony z 20.07.2026](https://eli.gov.pl/api/acts/DU/2025/775/text/U/D20250775Lj.pdf), art. 106j, 106nda, 106nf, 106nh; [Offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/), [niedostępność](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline-niedostepnosc-ksef/), [tryb awaryjny](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-awaryjny/) MF (strony z aktualizacją 06.02.2026); [broszura FA(3), 04.03.2026, s. 69–70](https://ksef.podatki.gov.pl/media/jknpcymf/broszura-informacyjna-dotyczaca-struktury-logicznej-fa-3-04032026.pdf); [potwierdzenie transakcji](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/potwierdzenie-transakcji/) i [kody QR](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/kody-weryfikujace-qr/); CIRFMF [tryby Offline, wersja dokumentu 10.07.2025](https://github.com/CIRFMF/ksef-api/blob/main/tryby-offline.md) i [korekta techniczna, wersja dokumentu 20.08.2025](https://github.com/CIRFMF/ksef-api/blob/main/offline/korekta-techniczna.md). Wskazane daty są wersjami treści dokumentów, nie pinem builda API. Nie stwierdzono materialnej zmiany kontraktu wymagającej zatrzymania 8C.6.

### KSeF.8C.6.2 — Frozen Correction Financial Evidence

Bloker 8C.6.1 wynikał z braku niezależnej kwoty VAT pozycji w `FaWiersz` KOR. Identyczne `P_11` i `P_12` mogą odpowiadać różnym kwotom VAT snapshotu przy zaokrąglaniu od brutto; nie wolno rekonstruować integrity przez netto razy stawka. Samo występowanie stawki w parach przed/po nie wymaga niezerowego podsumowania: wzajemnie znoszące się różnice prawidłowo pomijają cały koszyk w XML.

`KsefFa3CorrectionDocumentGenerator` wywołuje document mapper dokładnie raz. Ten sam `KsefFa3CorrectionDocumentData` zasila niezmieniony XML builder i `KsefFa3CorrectionFinancialEvidenceBuilder`; `KsefFa3GeneratedDocument::integrityEvidence` przenosi wynik do issuance bez ponownego odczytu modeli lub NBP. Zwykły generator Faktury zachowuje domyślne `null`. Online Correction nie wymaga zapisu evidence ani nowej kolumny w submission.

Evidence ma `version=1`, `profile=correction_financial`, walutę, uporządkowane pozycje z exact netto/VAT/brutto i stawką FA(3) przed/po, dokładną kopię mapperowych `tax_buckets` po canonical cleanup oraz sumy różnic netto/VAT/brutto. Zachowuje istniejący kształt koszyków, również `vat=0.00` dla stawki zero, i opcjonalne `pln_vat` z gotowego przeliczenia. Nie oblicza ponownie VAT pozycji ani kursu. Wspólny `KsefFa3CorrectionTaxBuckets` zapewnia to samo przypisanie stawek w mapperze i walidatorze. Kwoty są kanonicznymi stringami dziesiętnymi, bez float; ujemne różnice są dozwolone.

`KsefFa3CorrectionFinancialEvidenceValidator` sprawdza dokładne klucze, typy, walutę, pozycje bez duplikatów, netto + VAT = brutto każdej strony i sumy różnic. Odejmując exact kwoty przed i dodając po, kontroluje frozen koszyki netto/VAT i globalne totals. Extractor wymaga evidence dla KOR, dopasowuje każdą parę `NrWierszaFa`, `P_11` i `P_12`, a następnie porównuje XML `P_13_*`, `P_14_*`, `P_14_*W` oraz `P_15` ze zweryfikowanym zapisem. `pln_vat` jest porównywany dokładnie, bez przeliczenia waluty. Koszyk `null` wymaga braku jego pól XML; niepusty koszyk wymaga dokładnych pól i wartości. Nieznane, dodatkowe, częściowe lub niespójne podsumowanie powoduje fail-closed. Prezentacja nadal odczytuje treść z XML, nie naprawia jej za pomocą evidence; submission integrity reużywa tego samego extractora.

Migracja `2026_08_13_087000_add_ksef_offline_correction_financial_evidence.php` dodaje wyłącznie nullable `longText` `ksef_offline_issuances.correction_financial_evidence`, odwzorowane w operatorowym SQLite jako nullable `TEXT`, bez indeksu, UPDATE, backfillu lub przepisywania XML; `down()` usuwa kolumnę. Model ma pole w fillable i hidden oraz cast `encrypted:array`; istniejące blokady update/delete obejmują evidence. Każda nowa Korekta Offline24/planned/failure wymaga poprawnego evidence przed CREATE. Zwykła Faktura Offline zachowuje `NULL`. Historyczna Korekta z brakującym lub uszkodzonym evidence jest blokowana, bez rekonstrukcji z XML lub bieżących snapshotów.

Read-only preflight operatora z 05.09.2026 potwierdził jeden OfflineIssuance zwykłej Faktury, zero historycznych Korekt Offline i zero osieroconych issuance. Migracje `079000–087000` są `Ran`; `087000` wykonano w batchu `53`, z backfillem równym `0`. Istniejący ordinary Offline Invoice zachował `correction_financial_evidence = NULL`. Evidence pozostaje prywatnym artefaktem OMS: bez serializacji modelu, UI, logów, XML/API payloadu, QR, UPO i treści PDF. Nie zmieniono source/TOCTOU, delivery, terminów, dokładnych bajtów transportu, `offlineMode=true` ani braku `hashOfCorrectedInvoice`.

Status po pełnej regresji kodu: `KSeF.8C.6.1: PASS / CLOSED via 8C.6.2`, `KSeF.8C.6.2: PASS / CLOSED`, `KSeF.8C.6 CODE: PASS / CLOSED`. Unit KSeF: 422 testy / 1412 asercji; Feature KSeF: 927 / 6777; full suite: 2240 / 13800, wszystko PASS. Regresje obejmują zero-delta same bucket, przypadki groszowe `0.02 / 0.03`, cross-bucket `23% -> 8%`, `0 KR`, `0 WDT`, `0 EX`, walutę obcą/P14W, buyer-only, korektę ujemną oraz tamper fail-closed. Bloker 8C.6.1 został rozwiązany przez frozen financial evidence, ponieważ `FaWiersz` KOR nie przechowuje niezależnej kwoty VAT pozycji i exact VAT po zaokrągleniach nie może być bezpiecznie odtworzony z `P_11` i `P_12`.

### KSeF.8C.6 — FINAL CLOSED

Poniższe statusy są historycznym zamknięciem 8C.6; późniejsze udostępnienie Production w kodzie nastąpiło w 8D.2.

Migracja `087000` ma na operatorowej bazie status `Ran` w batchu `53`; kolumna `ksef_offline_issuances.correction_financial_evidence` ma typ SQLite `TEXT`, `nullable=YES`, a backfill wyniósł `0`. `KSeF.8C.6-DB-VERIFY-001` zakończył się `FAIL / superseded` wyłącznie dlatego, że podczas okna read-only inne procesy PHP zmieniały plik SQLite i jego pełny hash; nie wykryto błędu danych KSeF. Zastępujący go `KSeF.8C.6-DB-VERIFY-002` zakończył się `PASS / CLOSED` przy zatrzymanych procesach `php/php-cgi/httpd`, braku WAL/SHM/journal, otwarciu SQLite `READONLY`, `PRAGMA query_only=1`, `quick_check=ok` przed i po oraz stabilnym `T0=T1=T2=FINAL`. Zweryfikowany plik miał `2502656` bajtów i SHA-256 `87789B48D5D0FAFD3AF7676343FCD2054348A250FBB42EB25FD76460549C026E`.

Historyczny OfflineIssuance `id=1` dla Faktury `invoice_id=123`, `document_type=invoice`, pozostał w środowisku DEMO, procedurze `offline24` i schemacie `FA (3) 1-0E`; payload miał `1642` bajty. `correction_financial_evidence` pozostało `NULL`, a decrypt/hash/size, presentation oraz integralność powiązanego submissionu przeszły weryfikację. Kontrakt końcowy wymaga evidence dla każdej nowej Korekty Offline, szyfruje je jako `encrypted:array`, ukrywa w serializacji i chroni razem z niezmiennym issuance. XML i evidence powstają z jednego przebiegu mappera; exact VAT pozycji pochodzi ze snapshotu, nigdy z mnożenia netto przez stawkę. Evidence nie trafia do FA(3), API KSeF, QR, UPO, treści PDF, UI ani logów. Brak evidence dla KOR działa fail-closed; historycznych danych nie uzupełnia się backfillem.

`KSeF.8C.6-LIVE-AUTH-PREFLIGHT-001/002` są `BLOCKED / superseded`: puste `personal/grants` nie rozstrzygało samo w sobie efektywnego `InvoiceWrite`. `KSeF.8C.6-LIVE-AUTH-PREFLIGHT-003` zakończył się `PASS / CLOSED` na podstawie oficjalnych baseline'ów CIRFMF/ksef-api `93b843d5def041f69fe2a26d0d90a53e9fa9987a` oraz CIRFMF/ksef-client-csharp `04f01c1c7834336a3aef1804149cd5bcbd883a3e`. DEMO ContextToken miał `typ=ContextToken`, `cit=Nip`, zgodne `civ=6282192260`, `per=Owner` oraz puste `rol` i `pep`. Owner w `per`, przy poprawnym ContextToken i zgodnym NIP kontekstu, potwierdza efektywne prawa fakturowe Ownera, w tym `InvoiceWrite`; puste `personal/grants` jest w tym przypadku legalne. Nie zapisano JWT ani sekretów.

`KSeF.8C.6-LIVE-TEST-001-R2` jest `BLOCKED / superseded` wyłącznie przez `WARSAW_MIDNIGHT_SAFETY_WINDOW`; zatrzymano go przed zapisem biznesowym, live HTTP, utworzeniem Korekty, issuance i submissionu. Końcowy `KSeF.8C.6-LIVE-TEST-001-R3` z 06.09.2026 zakończył się `PASS / CLOSED` w środowisku DEMO i procedurze Offline24. Root `BLF 80/2026` (`Invoice id=123`, accepted submission `24`, tryb `Offline`, PLN) zachował numer KSeF `6282192260-20260903-7B7860400000-FF` i pozostał niezmieniony.

Test utworzył i atomowo zamknął Korektę `BLKF 3/2026` (`id=124`, root `123`) z powodem „podwyższenie ceny po wystawieniu faktury”. Cena jednostkowa brutto zmieniła się z `123.00` na `124.00 PLN`, a różnica wyniosła netto `0.81`, VAT `0.19`, brutto `1.00 PLN`. Offline issuance `id=2` ma `environment=DEMO`, `procedure=offline24`, `P_1=2026-09-06`, schemat `FA (3) 1-0E` i payload `1994` bajty. Frozen XML, financial evidence, KOD I, KOD II oraz dokładna referencja do numeru KSeF root przeszły weryfikację.

Transport utworzył submission `id=25`, attempt `1` i wykonał dokładnie jeden invoice POST z exact frozen XML, `offlineMode=true` oraz nieobecnym `hashOfCorrectedInvoice`; status GET wykonano raz, bez retry i resend. Korekta otrzymała status `accepted`, tryb `Offline` i własny numer KSeF `6282192260-20260906-0277D5800000-93`. Finalny PDF przeszedł weryfikację, zawiera jeden KOD I i nie zawiera KODU II; pre-acceptance delivery zostało zamknięte. UPO `id=24` pobrano przy pierwszej próbie, a `TrybWysylki=Offline`, numer KSeF, hash i referencję zweryfikowano.

Sieć R3 była ograniczona do oczekiwanego ruchu DEMO auth/session/invoice/status/UPO: invoice POST `1`, status GET `1`, UPO GET `1`; TEST, PRODUCTION, Latarnia, NBP, QR HTTP i permission endpoints: `0`. Nowy baseline operatora: invoices `48`, orders `106`, offline issuances `2`, KSeF submissions `25`, UPO `24`, Latarnia messages `3`, Latarnia states `1`, automation runs `67`, order events `1007`. Przyrosty oznaczają wyłącznie Korektę, jej OfflineIssuance, pojedynczą transmisję, UPO oraz legalne zdarzenie lifecycle, a nie skutki migracji. Audytowy backup `storage/app/private/backups/database_before_ksef_8c6_live_001_r3_20260906_000233.sqlite` zachowano z SHA-256 `92D3E73821514EB9E080047631199FA2C2BA07C51C173C287877151A1F1DD089`.

Status końcowy: `KSeF.8C.6.1: PASS / CLOSED`, `KSeF.8C.6.2: PASS / CLOSED`, `KSeF.8C.6 migration 087000: PASS / CLOSED`, `KSeF.8C.6-DB-VERIFY-002: PASS / CLOSED`, `KSeF.8C.6-LIVE-AUTH-PREFLIGHT-003: PASS / CLOSED`, `KSeF.8C.6-LIVE-TEST-001: PASS / CLOSED` oraz `KSeF.8C.6: CODE + MIGRATION + DB VERIFY + LIVE DEMO E2E PASS / CLOSED`. Weryfikacja dotyczy DEMO; Production pozostaje zablokowane przez istniejącą operational environment policy. Zwykła Korekta biznesowa 8C.6 nadal używa `offlineMode=true` bez `hashOfCorrectedInvoice`.

### KSeF.8C.7-R1 — Narrow Technical Correction after rejected Offline Invoice

R1 i jego wpisy hardeningowe/wdrożeniowe poniżej opisują historyczny zakres VAT. Odroczenie KOR dotyczyło R1 (parity dodano w R2), a blokadę Production usunięto dopiero w 8D.2. Nie rozszerza to dawnych dowodów LIVE TEST na PROD.

Remediation jest dostępne wyłącznie dla zwykłej Faktury VAT wystawionej Offline, której dokładna próba została odrzucona bez numeru KSeF. Deterministyczna klasyfikacja używa wyłącznie oficjalnego kodu statusu: `440` i `450` są `Eligible`, `410` jest `Ineligible`, a każdy inny lub brak kodu daje `Unknown` i blokadę fail-closed. Tekst odpowiedzi MF nie wpływa na decyzję. Korekty `KOR`, Pro formy, Accepted, Processing, Uncertain i TechnicalFailed są poza tym workflow; parity dla KOR pozostaje odroczone.

Źródłowy OfflineIssuance i odrzucony submission pozostają niezmienne. Integralność wymaga dokładnych relacji, środowiska, kontekstu, sprzedawcy, statusu oraz zgodności odszyfrowanych bajtów źródłowych z zapisanym hash i rozmiarem. Historyczny odrzucony XML nie musi przechodzić bieżącego XSD. Osobny, zaszyfrowany i niezmienny `KsefOfflineTechnicalCorrection` przechowuje nowy autorytatywnie wygenerowany FA(3), jego hash, rozmiar, schema/version, P_1/generation instant oraz `hashOfCorrectedInvoice` równy hashowi starego odrzuconego dokumentu. Nowy dokument powstaje z zamkniętego snapshotu Faktury, zachowuje jej P_1 i P_2, musi przejść aktualny XSD oraz mieć hash różny od źródła.

Przygotowanie artefaktu jest lokalną, jawną operacją bez HTTP. Osobna ręczna wysyłka ponownie sprawdza konfigurację i integralność pod blokadą, otwiera sesję interaktywną i wykonuje dokładnie jeden invoice POST z nowym XML, `offlineMode=true` oraz starym hashem w `hashOfCorrectedInvoice`. Zwykły resend odrzuconego Offline nadal jest blokowany. Kolejna techniczna próba lub drugi POST dla tego samego odrzucenia są blokowane; `21166` i `21167` kończą próbę jako `TechnicalFailed`, a niejednoznaczny błąd sieci pozostawia `Uncertain` do reconciliation, bez retry i blind resend.

Po Accepted prezentacja i UPO odnoszą się do submissionu korekty technicznej i nowego hasha. PDF jest generowany z jej zamrożonego FA(3), zawiera jeden KOD I oraz nie zawiera KODU II; źródłowy PDF, XML, hash i adresy QR pozostają niezmienione. Accepted próba techniczna spełnia ten sam obowiązek Offline i jest widoczna dla resolverów po starszym Rejected. Widok udostępnia dwa osobne POST-y: przygotowanie i transmisję; odczyt nie generuje artefaktu i nie wysyła dokumentu.

Migracja `2026_08_13_088000_create_ksef_offline_technical_corrections.php` tworzy prywatny magazyn artefaktów oraz nullable, unikalne powiązanie submissionu. W chwili implementacji R1 pozostawała `Pending` na bazie operatora, a sam etap implementacyjny nie uruchamiał migracji, live HTTP ani transmisji. Późniejsze wykonanie operatorowe opisuje sekcja zamknięcia wdrożeniowego poniżej. Production pozostaje zablokowane, brak auto-send, kolejek, listenerów i zmian harmonogramu. Audyt oparto na oficjalnych baseline'ach CIRFMF/ksef-api `93b843d5def041f69fe2a26d0d90a53e9fa9987a` oraz CIRFMF/ksef-client-csharp `04f01c1c7834336a3aef1804149cd5bcbd883a3e`, w szczególności dokumentach `offline/korekta-techniczna.md`, `tryby-offline.md` i aktualnym changelogu API.

### KSeF.8C.7-R1.1 — Technical Correction integrity hardening

Widok i backend rozróżniają teraz zwykłą transmisję Offline (`offline_issuance_id != null`, `offline_technical_correction_id = null`) od transmisji korekty technicznej (oba identyfikatory niepuste). Od chwili utworzenia artefaktu albo pojawienia się technicznej historii zwykły resend jest trwale blokowany kodem `ksef_offline_submission_technical_remediation_exists`. Dotyczy to również `Uncertain`, `TechnicalFailed`, `Rejected` i `Accepted`; dwa terminalne niepowodzenia wymagają ręcznej analizy zamiast proponowania zwykłego resend lub technicznej próby v3. Projekcja widoku filtruje już załadowaną kolekcję submissionów, bez dodatkowego zapytania na wiersz.

Kwalifikacja jest zamrażana przy PREPARE jako `source_status_code` i `eligibility_policy_version=1`. Nieusuwalna polityka V1 oznacza `440/450 = Eligible`, `410 = Ineligible`, pozostałe i `null = Unknown`. Historyczne `assertArtifact()` wybiera regułę według zapisanej wersji, sprawdza zgodność kodu ze źródłowym submissionem i nie zależy od przyszłej bieżącej allowlisty. Nieznana wersja lub zmiana kodu źródłowego powoduje fail-closed.

`KsefOfflineTechnicalCorrectionBusinessFingerprintService` utrzymuje dwie niezależne projekcje V1: z niezmiennego snapshotu `Invoice` przez zamrożoną projekcję V1 oraz z zamrożonego technicznego XML przez parser DOM z `LIBXML_NONET`, wyłączonymi encjami zewnętrznymi i blokadą `DOCTYPE`. Stały JSON UTF-8 o ustalonej kolejności kluczy i list, kanonicznych stringach dziesiętnych oraz jawnych bool/enums jest hashowany SHA-256 i kodowany Base64 do 44 znaków. PREPARE wymaga równości fingerprintów przed CREATE; historyczny odczyt porównuje payload, zapisany fingerprint i bieżący niezmienny snapshot Faktury, bez ponownego uruchamiania generatora XML.

V1 obejmuje wszystkie pola biznesowe emitowane przez zwykły mapper/builder FA(3): identyfikację formularza i rodzaj `VAT`; P_1, P_1M, P_2, P_6 i walutę; dane i adres sprzedawcy, PrefiksPodatnika, REGON/BDO; tożsamość, nazwę, adres, kontakt, JST/GV nabywcy; odbiorcę i rolę; wszystkie emitowane pola linii (`NrWierszaFa`, P_7, P_8A, P_8B, P_9A, P_11, P_12, GTU); koszyki netto/VAT i VAT w PLN; P_15; adnotacje; dodatkowe opisy; płatność, termin, metodę i rachunek; datę oraz numer zamówienia. Kwoty VAT i brutto pozycji, których zwykły `FaWiersz` osobno nie serializuje, są chronione przez emitowane P_11/P_12, koszyki P_13/P_14 i P_15 oraz istniejące walidacje finansowe mappera. Jedynym wyłączeniem jest `Naglowek/DataWytworzeniaFa`, ponieważ techniczna regeneracja legalnie nadaje nowy czas utworzenia; P_1 i P_2 nie są wyłączone.

Na etapie R1.1 wówczas niewykonana migracja `088000` została rozszerzona o niepuste `source_status_code`, `eligibility_policy_version`, `business_fingerprint` i `business_fingerprint_version`; nie powstała migracja `089000` ani backfill. Pola są częścią istniejącego niezmiennego artefaktu. KOR parity, live test, auto-send, retry, nowe kolejki i Production pozostają poza zakresem.

### KSeF.8C.7-R1.2 — Frozen Business Projection V1

`KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1` jawnie odtwarza canonical business array z rekordu Faktury, jej pozycji oraz niezmiennych `seller_snapshot`, `buyer_snapshot`, `recipient_snapshot`, `issuer_snapshot`, `payment_snapshot`, `order_snapshot` i `tax_metadata_snapshot`. Nie korzysta z bieżącego `KsefFa3InvoiceMapper`, `KsefFa3OptionalBlocksResolver`, `KsefFa3DocumentGenerator`, Order, Customer, Address, Product, ustawień KSeF, ustawień serii ani NBP. Obsługuje zamrożone opcje dokumentu w wersjach 1 i 2 oraz udowodnioną semantykę legacy bez `ksef_document`; płatność zachowuje mapowanie `ksef_payment` v1 i historyczny fallback.

Projection V1 ma własne reguły dla kwot (2 miejsca), ilości (do 4 miejsc bez nieistotnych zer), stawek `0 KR`/`0 WDT`/`0 EX`, koszyków VAT, P_14_*W, adnotacji, danych stron, płatności, opisów, zamówienia, rachunku i GTU. Obie strony fingerprintu mają identyczną strukturę i null semantics. Jedynym wyłączonym polem FA(3) jest `Naglowek/DataWytworzeniaFa`; P_1 i P_2 pozostają chronione. Historyczne `assertArtifact()` nie uruchamia bieżącego mappera, resolvera opcji ani generatora, natomiast PREPARE nadal używa aktualnego autorytatywnego generatora do utworzenia nowego XML i wymaga parytetu obu projekcji. Każda przyszła zmiana semantyki wymaga osobnej Business Projection V2 i `business_fingerprint_version=2`; znaczenie V1 jest niezmienne.

### KSeF.8C.7 — Technical Correction R1 deployment closure

Statusy `KOR DEFERRED` i `Production: BLOCKED` poniżej należą do chwili zamknięcia R1; późniejsze zmiany opisują R2 i 8D.2.

Zakres R1 dla zwykłej Faktury VAT jest zamknięty kodowo i bazodanowo. R1 wprowadził osobny immutable artefakt korekty technicznej po odrzuconym dokumencie Offline; R1.1 zamroził kwalifikację `440/450 = Eligible`, `410 = Ineligible`, inne wartości lub `null = Unknown` z blokadą fail-closed; R1.2 uniezależnił historyczny fingerprint od bieżącego mappera przez frozen Invoice Business Projection V1. Fingerprint ma kontrakt `Base64(SHA-256(canonical JSON))`, a każda przyszła zmiana semantyki wymaga Projection V2. Źródłowy OfflineIssuance, odrzucony submission, XML, hash, KOD I i KOD II pozostają niezmienne; techniczny XML ma osobny hash, a `hashOfCorrectedInvoice` wskazuje hash pierwotnego odrzuconego dokumentu. Jedynym technicznym wyłączeniem projekcji jest `Naglowek/DataWytworzeniaFa`; P_1 i P_2 są chronione.

Pierwsza kontrola `KSeF.8C.7-MIGRATION-088-VERIFY-001` zatrzymała się na `MIGRATION_088_UNEXPECTED_DML`, ponieważ wynik SQLite/Laravel `--pretend` pokazał rekonstrukcję tabeli tymczasowej w sposób wyglądający na niepełny. Był to wynik `SUPERSEDED / INCONCLUSIVE`, a nie potwierdzony błąd migracji. Następnie `KSeF.8C.7-MIGRATION-088-DRY-RUN-COPY-001` wykonał rzeczywistą migrację na dokładnej jednorazowej kopii operatorowej SQLite i zakończył się `PASS / CLOSED`: submissiony zachowały 26 rekordów, schemat zmienił się z 39 do 40 kolumn wyłącznie przez nullable `offline_technical_correction_id`, wszystkie historyczne wartości nowej kolumny pozostały `NULL`, stare FK, indeksy i triggery zachowały kontrakt, nie pozostały obiekty `__temp__`, tabela techniczna miała 0 rekordów, `quick_check=ok`, a `foreign_key_check=0`. W ramach tego samego przebiegu PRE i POST były równe dla digestów starych 39 kolumn submissionów, Faktur, OfflineIssuances i UPO.

`KSeF.8C.7-MIGRATION-088-OPERATOR-001` wykonał 7 września 2026 o `2026-09-07T07:43:01Z` wyłącznie migrację `2026_08_13_088000_create_ksef_offline_technical_corrections` na operatorowej bazie SQLite. Wynik: `PASS`, exit code `0`, batch `54`, liczba migracji `80 -> 81`, czas wrappera około `0,255 s`, wynik Laravela `DONE`. Operatorowy DB verify zakończył się `quick_check=ok` i `foreign_key_check=0`. `ksef_invoice_submissions` ma teraz 40 kolumn; jedyną nową jest `offline_technical_correction_id INTEGER NULL` z FK `RESTRICT` do `ksef_offline_technical_corrections.id` i unikalnym indeksem `ksef_submission_offline_technical_unique`. Istniejące FK i indeksy submissionów zostały zachowane.

Tabela `ksef_offline_technical_corrections` została utworzona z 19 kolumnami: `id`, `invoice_id`, `offline_issuance_id`, `rejected_submission_id`, `environment`, `context_nip`, `seller_nip`, `schema_id`, `generated_at`, `payload_xml`, `invoice_hash`, `invoice_size`, `hash_of_corrected_invoice`, `source_status_code`, `eligibility_policy_version`, `business_fingerprint`, `business_fingerprint_version` oraz timestampami. Nie wykonano backfillu; początkowa liczba artefaktów technicznych wynosiła `0`.

Wartości weryfikacyjne z chwili migracji operatorowej pozostały bez zmian: Invoices `49 -> 49`, Orders `106 -> 106`, OfflineIssuances `2 -> 2`, submissions `26 -> 26`, UPO `25 -> 25`, Accepted `25 -> 25`, Accepted z numerem KSeF `25 -> 25`, Latarnia messages/states `3/1`, Automation runs/steps/rules/actions `67/74/3/4` i order events `1008`. Są to wyłącznie wartości migration-time, nie stałe oczekiwane liczniki projektu. Operator-run digests miały identyczne wartości PRE i POST: submissions na starych 39 kolumnach `d91870965bb65e9375b3ee7329a65e65b164f62212301a15f64531dc69d7d46e`, Invoices `1ac43edc669e6917ba4df7ee28c5b4ad26f23865168978a69d7d8e278cae6a69`, OfflineIssuances `8d164fa749e90ecf420785a78282febbd1621e85ef268b0bddb5f33d350c3a04`, UPO `d25d114d681d31765213b272e951ac9b5d78820f179c887c8d08b8d47837768f` i Accepted mapping `dd8e0e2cffb834e19eb7d47f85e8ef5a9f54f4ffa3be1155fac9be1f11f8a4f7`. Nie wynika z tego równoważność digestów między różnymi przebiegami lub algorytmami serializacji.

Fizyczny fingerprint operatorowej bazy zmienił się zgodnie z oczekiwaniem wskutek zmiany schematu i historii migracji: PRE SHA-256 `3FA32DF724B5E99911035B9C3650CD8A8348E9BDFCC5ABEC999A138A26906E09`, rozmiar `2502656`; POST SHA-256 `0713373CDFC8B05E480FCA221B32C492BAB3DC2DE284AAC60A806A224AD0F374`, rozmiar `2646016`. Semantyczne dane historyczne pozostały niezmienione. Zachowano dokładną kopię PRE `storage/app/private/backups/database_before_ksef_8c7_088_operator_001_20260907_094207.sqlite`, SHA-256 `3FA32DF724B5E99911035B9C3650CD8A8348E9BDFCC5ABEC999A138A26906E09`, rozmiar `2502656`, `quick_check=ok`, `foreign_key_check=0`; kopia pozostaje prywatna i nie jest częścią repozytorium.

Podczas migracji operatorowej wykonano 0 zapisów aplikacyjnych lub biznesowych. Dopuszczone zmiany obejmowały wyłącznie rekonstrukcję schematu SQLite, nową tabelę techniczną, nullable techniczny FK, definicje FK/indeksów oraz jeden wpis historii migracji. Nie utworzono Faktury, OfflineIssuance, artefaktu technicznego, submissionu ani UPO; nie wykonano zapisu Latarni ani Automation. Ruch KSeF TEST/DEMO/PROD, Latarnia, NBP i QR HTTP wynosił `0`.

Kontrakt transportu pozostaje bez zmian: sesja interaktywna, `offlineMode=true`, hash nowego artefaktu jako `invoiceHash`, hash pierwotnego odrzucenia jako `hashOfCorrectedInvoice`, najwyżej jeden artefakt i jeden techniczny invoice POST w R1. `21166` i `21167` prowadzą do `TechnicalFailed`; stan niepewny dopuszcza wyłącznie reconciliation, bez blind retry, drugiego artefaktu lub zwykłego resend po rozpoczęciu remediation. Accepted otrzymuje nowy numer KSeF, PDF z zamrożonego technicznego payloadu i jednym KODEM I bez KODU II, a UPO odnosi się do nowego submissionu i hasha.

Status końcowy, bez zamykania całego KSeF.8C.7:

```text
KSeF.8C.7-R1: PASS / HARDENED / CLOSED
KSeF.8C.7-R1.1: PASS / CLOSED
KSeF.8C.7-R1.2: PASS / CLOSED
KSeF.8C.7 CODE: PASS / CLOSED FOR R1 SCOPE
KSeF.8C.7-MIGRATION-088-DRY-RUN-COPY-001: PASS / CLOSED
KSeF.8C.7-MIGRATION-088-OPERATOR-001: PASS / CLOSED
KSeF.8C.7 migration 088000: PASS / CLOSED
KSeF.8C.7-DB-VERIFY-001: PASS / CLOSED
KSeF.8C.7 DB: PASS / CLOSED FOR R1 SCOPE
KSeF.8C.7-TEST-OFFLINE-CERT-READINESS-001: PASS / CLOSED
KSeF.8C.7-LIVE-TECHNICAL-CORRECTION-TEST-001-R2: PASS / CLOSED
REAL SOURCE REJECTION 450: PASS
TECHNICAL ACCEPTED 200: PASS
SOURCE invoice POST: 1
TECHNICAL invoice POST: 1
UPO: PASS
Accepted PDF: PASS
Offline obligation: FULFILLED
Correction source resolver: PASS
Accepted XML independent refetch: NOT VERIFIED / HTTP 406 / NON-BLOCKING
KSeF.8C.7 LIVE: PASS / CLOSED FOR R1 SCOPE
KSeF.8C.7 R1 SCOPE: PASS / CLOSED
KOR technical parity: DEFERRED
Production: BLOCKED
NEXT: no further KSeF.8C.7 R1 implementation or LIVE test required
```

### KSeF.8C.7 — Technical Correction R1 LIVE closure

To historyczny LIVE TEST R1. Jego odroczenia KOR/Production nie opisują bieżącego kodu po R2/8D.2.

Kontrolowany test `KSeF.8C.7-LIVE-TECHNICAL-CORRECTION-TEST-001-R2` zakończył się `PASS / CLOSED` 7 września 2026 w środowisku TEST. Wcześniejsza próba LIVE została poprawnie zatrzymana przed pierwszym invoice POST, ponieważ certyfikat Offline TEST nie był gotowy. Po osobnym `KSeF.8C.7-TEST-OFFLINE-CERT-READINESS-001: PASS` potwierdzono certyfikat Offline `id=2`, numer seryjny `01F94B5FC7287D92`, klucz EC P-256, przeznaczenie Non-Repudiation / Content Commitment oraz zdalny status `Active / READY`. Certyfikat Authentication/XAdES nadal należy do odrębnej domeny Digital Signature; nie użyto materiału certyfikatu Offline do uwierzytelnienia.

Test użył wyłącznie syntetycznego audytowego dokumentu TEST: Order `114`, Faktura `126` o numerze `K7T 1/2026`, seria `8` i OfflineIssuance `3`. Poprawny wyjściowy FA(3) miał `P_1=2026-09-07`, `P_2=K7T 1/2026` i hash `qaYU6gfzMSI6tXzc1uZnkFCgf10LoWaWI+i0aA8hJKE=`. Jednorazowy tymczasowy harness LIVE emulował historyczny błąd generatora, zmieniając wyłącznie `P_1` na `2026-09-08`; wynik pozostał zgodny z XSD, miał `1686` bajtów i hash `rmC9f8SSScMQuvFPtxlwhlgx01nSI1HHKi9dtUK2IUc=`. Bieżący NEX-OMS nie pozwala zwykłym kodem aplikacji wystawić dokumentu Offline z przyszłym `P_1`; harness nie jest funkcją produkcyjną.

Dokładna macierz prób:

```text
Attempt 1: ordinary Offline, submission 27, Rejected, 450,
           KSeF number NULL, source hash rmC9f8SSScMQuvFPtxlwhlgx01nSI1HHKi9dtUK2IUc=
Attempt 2: technical correction, submission 28, Accepted, 200,
           KSeF number 6282192260-20260907-5A9BF1000000-98,
           new technical hash qaYU6gfzMSI6tXzc1uZnkFCgf10LoWaWI+i0aA8hJKE=,
           hashOfCorrectedInvoice = Attempt 1 hash
```

Źródłowa sesja interaktywna `20260907-SO-255F762000-DCB6F77F0E-18` wysłała dokładnie jeden invoice POST z `offlineMode=true`, bez `hashOfCorrectedInvoice`; referencja dokumentu to `20260907-EE-255F94B000-606BCDEAE1-D4`. Rzeczywisty wynik MF `450` został zapisany jako `Rejected`, bez numeru KSeF i bez resend. Polityka kwalifikacji V1 sklasyfikowała ten oficjalny wynik jako `Eligible`; test nie wykonywał wariantu `440`, a `410` i stany inne lub `null` nadal zachowują odpowiednio `Ineligible` i `Unknown / fail-closed`.

Niezmienny artefakt techniczny `id=1` wskazuje OfflineIssuance `3` i odrzucony submission `27`, zachowuje `source_status_code=450`, `eligibility_policy_version=1` oraz `business_fingerprint_version=1`. Jego `hashOfCorrectedInvoice` jest dokładnie równy hashowi odrzuconego źródła, a własny hash techniczny jest od niego różny. Techniczny XML przeszedł bieżący XSD, przywrócił `P_1=2026-09-07`, zachował `P_2=K7T 1/2026` i przeszedł frozen Business Projection V1 parity.

Techniczna sesja `20260907-SO-25600FA000-0A5257132C-80` utworzyła submission `28`, attempt `2`, i wykonała dokładnie jeden invoice POST z zamrożonym payloadem artefaktu, `offlineMode=true`, jego nowym `invoiceHash` oraz hashem odrzuconego źródła jako `hashOfCorrectedInvoice`. Referencja dokumentu to `20260907-EE-256031C000-DCBD534EC6-88`. Nie wykonano retry ani trzeciej próby. MF zwróciło rzeczywisty status `200`, a NEX-OMS zapisał `Accepted` i numer KSeF `6282192260-20260907-5A9BF1000000-98`. Jest to syntetyczny dokument środowiska TEST, a nie dowód akceptacji w Production.

UPO `id=26` należy wyłącznie do zaakceptowanego submissionu `28`; odrzucony submission `27` nie ma UPO. Accepted PDF przeszedł weryfikację, miał `110318` bajtów i został utworzony z zamrożonego payloadu artefaktu technicznego, a nie z odrzuconego XML ani ze świeżej regeneracji przy pobraniu. PDF zawiera dokładnie jeden KOD I i nie zawiera KODU II. Źródłowe KODY I/II nadal odnoszą się do niezmiennego odrzuconego hasha i nie zostały przepisane po akceptacji technicznej; publiczny remote check starego KODU I nie był wykonywany. Finalna projekcja obowiązku Offline zwróciła `FULFILLED`, a `KsefFa3CorrectionSourceReferenceResolver` poprawnie wskazał submission `28` jako autorytatywne źródło KSeF dla przyszłej zwykłej korekty biznesowej. W teście nie utworzono Korekty `KOR`.

Opcjonalny GET zaakceptowanego XML wykonano dokładnie raz i otrzymano `HTTP 406`, bez retry. Endpoint wymaga `InvoiceRead`; dostępna autoryzacja TEST wystarczała do transmisji z `InvoiceWrite`, lecz nie dostarczyła dodatkowego dowodu pobrania XML. Ograniczenie jest `NON-BLOCKING FOR KSeF.8C.7 R1 LIVE CLOSURE`, ponieważ stanowiło uzupełniającą kontrolę poza rdzeniem dowodu `450 -> technical correction -> 200`. Nie uzyskano i nie deklaruje się niezależnej weryfikacji hasha zdalnie pobranego accepted XML. Potwierdzone pozostają: dokładny frozen payload i hash faktycznego technicznego POST-u, akceptacja submissionu `28`, korelacja numeru KSeF i UPO oraz użycie tego samego artefaktu przez lokalny Accepted PDF.

Pełne rozliczenie ruchu kontrolowanego przebiegu wyniosło `21` requestów: auth `8`, personal grants `1`, public keys `2`, session open `2`, session close `2`, source invoice POST `1`, technical invoice POST `1`, status GET `2`, UPO `1` oraz accepted XML GET `1`. QR, NBP, Latarnia, DEMO i PROD: `0`. Łącznie wykonano dokładnie `2` invoice POST-y: jeden źródłowy i jeden techniczny, bez resend, blind retry ani attempt `3`. Gałęzie `21166/21167 -> TechnicalFailed` oraz reconciliation dla `Uncertain` pozostają chronionym kontraktem, lecz nie były wykonywane w tym happy-path LIVE teście.

Na czas testu konfigurację kontrolowanie przełączono z DEMO na TEST, pozostawiając NIP kontekstu bez zmiany i ustawiając `automatic_submission=false`. Po teście przywrócono `environment=DEMO` oraz `automatic_submission=true`; materiały Authentication nie zostały zmienione, preferowany certyfikat Offline TEST `id=2` i DEMO `id=1` pozostały wybrane. Ustawienie KSeF dla syntetycznej serii `id=8` zostało wyłączone dla dalszych transmisji.

Celowe przyrosty audytowe to: Order `+1`, OrderItem `+1`, Invoice `+1`, InvoiceItem `+1`, seria Faktur `+1`, OfflineIssuance `+1`, submissions `+2`, TechnicalCorrection `+1`, UPO `+1`, order event `+1` i Automation `0`. Rekordy `114`, `126`, `3`, `27`, `1`, `28` i `26` zachowano, ponieważ istnieje odpowiadający im zdalny stan KSeF TEST i lokalna korelacja audytowa; nie są to produkcyjne rekordy biznesowe. Kopia PRE `storage/app/private/backups/database_before_ksef_8c7_live_technical_001_r2_retry_20260907_125209.sqlite` miała rozmiar `2646016` B i SHA-256 `87FEC82C583B1A9BA98B09ECCEC792D1AFD481F9A22D311E0B09094A057FB38F`. Po przebiegu operatorowa baza miała SHA-256 `BF06FA27512A9F1FD933D826E7F3BA2040E6C4A20095F6D69FABF20097DCC5E0`, `quick_check=ok` i `foreign_key_check=0`; zmiana fingerprintu wynika z celowych rekordów audytowych i metadanych lifecycle konfiguracji.

Wynik zamyka wyłącznie `KSeF.8C.7 R1 scope for ordinary Invoice VAT: PASS / CLOSED`. Parity korekty technicznej dla `KOR` pozostaje `DEFERRED`, a Production pozostaje `BLOCKED`.

### KSeF.8C.7-R2 — Technical Correction parity for Offline KOR

Wyniki i blokada Production w poniższym closure opisują stan R2 przed 8D.2. LIVE KOR pozostał dowodem TEST; nie wykonano tu technicznej Korekty PROD.

Historyczne ograniczenie R1 do zwykłej Faktury VAT zostało domknięte przez R2 dla Korekty `KOR`. Chronologia obejmuje: `KSeF.8C.7-R2A` Contract Audit (`PASS`), `R2B` Core Infrastructure (`PASS / PUBLISHED`), `R2C` Frozen KOR Business Projection V2 (`PASS / PUBLISHED`), `R2D` hardening z fake transportem, downstream i UI (`PASS / PUBLISHED`), następnie `KSeF.8C.7-R2-LIVE-PREFLIGHT-001`, kontrolowany `KSeF.8C.7-R2-LIVE-TEST-001` oraz niezależny read-only `KSeF.8C.7-R2-LIVE-CLOSURE-AUDIT-001`. Po zamknięciu dokumentacyjnym formalny stan R2A-R2D jest `PASS / CLOSED`. R2A nie znalazł w oficjalnym kontrakcie MF ograniczenia wyłączającego `RodzajFaktury=KOR` z ogólnego mechanizmu korekty technicznej dokumentu wystawionego Offline. MF nie udostępniał dedykowanego referencyjnego E2E dla technicznego KOR, dlatego podstawą wdrożenia było `SUPPORTED_BY_GENERIC_OFFICIAL_CONTRACT`, z obowiązkową kontrolowaną walidacją LIVE na KSeF TEST.

`KsefOfflineTechnicalCorrectionDocumentGenerator` jest wspólnym dispatcherem: zwykłą Fakturę VAT przekazuje do `KsefFa3DocumentGenerator`, a Korektę `KOR` do `KsefFa3CorrectionDocumentGenerator`. Pro forma pozostaje nieobsługiwana. Nie powstał drugi mapper KOR ani osobny mechanizm auth, sesji, szyfrowania, request factory, statusu lub transportu. R2 korzysta z tego samego generic technical transportu i wysyła `offlineMode=true`, hash zamrożonego artefaktu technicznego jako `invoiceHash` oraz dokładny hash odrzuconego źródłowego payloadu KOR jako `hashOfCorrectedInvoice`.

Wersja fingerprintu jest częścią niezmiennego kontraktu artefaktu: zwykła Faktura VAT ma `business_fingerprint_version=1`, a `KOR` ma `business_fingerprint_version=2`. Projection V1 pozostaje niezmienna i nigdy nie jest reinterpretowana przez V2. Nieznana wersja, niezgodność typu dokumentu z wersją albo rozbieżność projekcji kończy się fail-closed. `KsefOfflineTechnicalCorrectionCorrectionBusinessProjectionV2` tworzy niezależne projekcje z zamrożonego stanu domenowego KOR i z technicznego FA(3). Obie są kanonizowane bez `float`, serializowane jako JSON UTF-8, hashowane SHA-256 i kodowane Base64. V2 chroni tożsamość dokumentu, P_1 i P_2, walutę, powód korekty, biznesową referencję do korygowanej/root Faktury, sprzedawcę, nabywcę przed i po tam, gdzie ma to zastosowanie, linie przed i po, koszyki VAT, P_15, zapisane wartości VAT w PLN i istotne adnotacje KOR. Wyłącznie techniczne `Naglowek/DataWytworzeniaFa` jest pomijane, ponieważ może legalnie zmienić się przy regeneracji; P_1 nie jest wyłączone.

Źródłowy `OfflineIssuance` KOR jest właścicielem zamrożonego `correction_financial_evidence`; artefakt techniczny nie duplikuje tego dowodu. PREPARE wymaga poprawnego source evidence, zgodności evidence nowo wygenerowanego KOR ze źródłowym frozen evidence oraz zgodności technicznego XML z tym samym dowodem. `KsefFa3CorrectionFinancialEvidencePayloadValidator` jest współdzielony przez prezentację Offline i kontrolę integralności remediation, dzięki czemu ścieżka źródłowa, prezentacyjna i techniczna nie mogą stosować różnych interpretacji kwot. Dla Korekty walutowej używane są zamrożone wartości finansowe i przeliczeniowe; technical remediation nie pobiera nowego kursu NBP i wykonuje `NBP HTTP: 0`.

Należy rozróżniać dwie niezależne relacje. Biznesowe `DaneFaKorygowanej` wskazuje korygowaną/root Fakturę i jej pochodzenie KSeF. Techniczne `hashOfCorrectedInvoice` wskazuje dokładne bajty odrzuconego źródłowego KOR. Relacji tych nie wolno utożsamiać. Każda próba techniczna może wykonać najwyżej jeden invoice POST, bez blind retry. Błąd niejednoznaczny daje `Uncertain` i dopuszcza wyłącznie reconciliation; nie tworzy automatycznie attemptu 3. Deterministyczne `21166` i `21167` prowadzą do `TechnicalFailed`. Te dwa kody oraz ścieżka `Uncertain` zostały pokryte regresją/fake transportem, ale nie wystąpiły w rzeczywistym teście R2.

R2D zachowuje dwuetapowy workflow operatora dla kwalifikującej się odrzuconej zwykłej Faktury VAT lub Korekty KOR: najpierw `PREPARE` niezmiennego artefaktu, następnie osobne `SEND`. Obie akcje wymagają zgodności aktywnego środowiska KSeF ze środowiskiem źródłowego OfflineIssuance, co blokuje na przykład operację na dokumencie TEST przy konfiguracji ustawionej na DEMO. Po utworzeniu technicznego submissionu nie jest oferowane ponowne PREPARE/SEND ani zwykły resend; dotyczy to stanów `Accepted`, `Rejected`, `TechnicalFailed` i `Uncertain`. Terminalne techniczne `Rejected` nie tworzy automatycznie artefaktu numer 2.

Downstream pozostaje wspólny dla Faktury VAT i KOR: status lifecycle, UPO, Accepted Offline PDF, KOD I, obowiązek Offline oraz UI/historia korzystają z istniejących komponentów. Accepted techniczny KOR jest prezentowany z dokładnego zamrożonego payloadu artefaktu, a nie z ponownej generacji na podstawie zmiennych danych. Jego PDF zawiera dokładnie jeden KOD I i nie zawiera KODU II. Odrzucone źródłowe OfflineIssuance zachowuje własne KODY I i II oraz source hash; akceptacja techniczna ich nie nadpisuje. Accepted submission techniczny powiązany z tym samym OfflineIssuance spełnia obowiązek transmisji Offline.

Kontrolowany `KSeF.8C.7-R2-LIVE-TEST-001` wykonał 8 września 2026 rzeczywisty przebieg w środowisku TEST. Root `Invoice id=126`, `K7T 1/2026`, PLN, był wcześniej zaakceptowany przez techniczną korektę R1; resolver poprawnie wykorzystał Accepted submission `28` i numer KSeF root `6282192260-20260907-5A9BF1000000-98` jako pochodzenie nowej biznesowej Korekty. Utworzony i zamknięty KOR `Invoice id=127`, `K7R2 1/2026`, korygował root `126` z powodu „podwyższenie ceny po wystawieniu faktury” i miał deltę `net +0.81 PLN`, `VAT +0.19 PLN`, `gross +1.00 PLN`.

Źródłowy `OfflineIssuance id=4` miał środowisko TEST i procedurę Offline24. Model KOR zachował `issue_date=2026-09-08`; jednorazowy kontrolowany harness zmienił wyłącznie historycznie serializowane źródłowe P_1 na `2026-09-09`, emulując dawny błąd generatora bez udostępniania takiej funkcji aplikacji. Payload pozostał zgodny z FA(3) XSD, miał `1961` bajtów oraz hash `k2my5FRiXJ8qg3J2IK8JjFvZK5zgpjhDK4+698C1iYY=`. Submission `29`, attempt `1`, został rzeczywiście odrzucony przez KSeF TEST kodem `450`, bez numeru KSeF.

Niezmienny artefakt techniczny `id=2` ma `business_fingerprint_version=2`, `source_status_code=450`, `P_1=2026-09-08`, `P_2=K7R2 1/2026`, `RodzajFaktury=KOR`, rozmiar `1961` bajtów i hash `akU2wVABbC+CsK7+ilbrcWlNCNrKBNbgH35qPdPf7bc=`. Jego `hashOfCorrectedInvoice` jest dokładnie równy hashowi źródła, a hash techniczny jest od niego różny. XSD, frozen financial evidence oraz trójstronny fingerprint `DOMAIN = STORED ARTIFACT = TECHNICAL XML` przeszły weryfikację.

Submission techniczny `30`, attempt `2`, został rzeczywiście zaakceptowany kodem `200` w trybie `Offline` i otrzymał numer KSeF `6282192260-20260908-8A2CF1000000-50`. Attempt `3` nie istnieje; liczba artefaktów technicznych wynosi `1`. Źródło wykonało jeden invoice POST, techniczny KOR jeden invoice POST, łącznie `2`; retry źródła i technicznego POST-u wynosiło `0`. DEMO i Production invoice POST, Accepted XML GET, NBP, Latarnia oraz QR HTTP wynosiły `0`. UPO istnieje wyłącznie dla submissionu technicznego (`id=27`, lokalna walidacja `PASS`); źródłowy submission nie ma UPO. Accepted PDF z frozen artefaktu przeszedł weryfikację i zawiera KOD I `1`, KOD II `0`. Metadane TCPDF `CreationDate/ModDate` są dynamiczne, dlatego osobne generacje kontenera PDF nie muszą być byte-for-byte identyczne; integralność opiera się na zamrożonej treści, prezentacji i wiązaniu QR. Obowiązek Offline zakończył się `FULFILLED` dzięki Accepted submissionowi `30`.

Po LIVE przywrócono `environment=DEMO`, aktywną integrację i `automatic_submission=true`; testowa seria Korekt `id=9` zakończyła jako nieaktywna z transmisją KSeF wyłączoną. Późniejszy read-only `KSeF.8C.7-R2-LIVE-CLOSURE-AUDIT-001` niezależnie uzgodnił zachowany raport LIVE, bazę operatora, source i technical payload/hash, V2 fingerprint, financial evidence, submissiony, UPO, Accepted presentation/PDF, obowiązek Offline oraz odtworzenie konfiguracji. Audyt zakończył się `PASS`, bez zapisów i bez runtime HTTP. Baza miała przed i po kontroli `2646016` bajtów, SHA-256 `366E6309C0D95EF0A15E96DD6DFA31BD273F3C61BA84A55F804B2D73BDB1D12C`, `quick_check=ok` i `foreign_key_check=0`. Lokalnymi, prywatnymi dowodami są `storage/app/private/backups/ksef_8c7_r2_live_001_report_20260908_193853.json` oraz `storage/app/private/backups/database_before_ksef_8c7_r2_live_001_20260908_191027.sqlite`; nie są zależnościami aplikacji i nie zawierają publikowanych w dokumentacji sekretów.

Późniejsza prośba o powtórzenie tego samego testu root `126` została prawidłowo zablokowana jako `ROOT_126_CORRECTION_CHAIN_CHANGED`, ponieważ KOR `127` już istniał. Jest to oczekiwana ochrona przed duplikacją, nie niepowodzenie pierwotnego LIVE. Ostateczne ograniczenia pozostają jawne: R2 zweryfikowano tylko na KSeF TEST i dla rzeczywistego kodu źródłowego `450`; `440` jest wspierany przez politykę i testy, lecz nie był sprawdzony LIVE. `21166`, `21167` i `Uncertain/reconciliation` były testowane lokalnie/fake-only. Accepted XML nie został niezależnie pobrany, Pro forma nie jest obsługiwana, a Production pozostaje `BLOCKED`. Wynik nie oznacza certyfikacji ani zatwierdzenia NEX-OMS przez MF.

Status końcowy:

```text
KSeF.8C.7-R2A: PASS / CLOSED
KSeF.8C.7-R2B: PASS / CLOSED
KSeF.8C.7-R2C: PASS / CLOSED
KSeF.8C.7-R2D: PASS / CLOSED
KSeF.8C.7-R2-LIVE-PREFLIGHT-001: PASS
KSeF.8C.7-R2-LIVE-TEST-001: PASS / CLOSED
REAL KOR SOURCE REJECTION 450: PASS / VERIFIED
KOR TECHNICAL ACCEPTED 200: PASS / VERIFIED
KSeF.8C.7-R2-LIVE-CLOSURE-AUDIT-001: PASS
KSeF.8C.7-R2: PASS / CLOSED / DOCUMENTED
KSeF.8C.7 ordinary Invoice VAT technical correction: PASS / CLOSED
KSeF.8C.7 KOR technical correction: PASS / CLOSED
KSeF.8C.7 KOR TECHNICAL PARITY: PASS / CLOSED / DOCUMENTED
KSeF.8C.7: PASS / CLOSED FOR VAT + KOR TECHNICAL CORRECTION SCOPE
Pro forma: UNSUPPORTED
Production: BLOCKED
```

Historycznie w KSeF.4A.1 transport miał deploymentowy gate `KSEF_INVOICE_SUBMISSION_ENABLED` domyślnie `false`, był ograniczony do TEST i nie miał trasy ani UI. Etap nie dodawał automatycznej akcji, listenera, observera, kolejki, crona, pollingu, batch, offline, QR ani UPO; `automatic_submission` nie miało wtedy wykonawcy. Późniejsze etapy dodały workflow ręczny i automatyczny, a 8D.2 obsługę PROD we wspólnym kodzie. Gate nadal jest niezależnym warunkiem transmisji, a włączenie wymaga kontroli ustawień i triggerów. Aktualny kontrakt opisują 6G.1 i 8D.

### Walidacja end-to-end KSeF.4A

Status etapu: `KSeF.4A CLOSED`. Transport został jednorazowo zweryfikowany kontrolowanym happy path na KSeF TEST API 2.7.0, niezależnie od automatycznych testów fake-only. W pełni syntetyczna, sfinalizowana Faktura VAT przeszła autorytatywne generowanie FA(3), walidację lokalnym oficjalnym XSD, zamrożenie i zaszyfrowanie payloadu at rest, wyliczenie SHA-256 i rozmiaru, szyfrowanie AES-256-CBC oraz RSA-OAEP SHA-256/MGF1-SHA256. Certificate/XAdES auth i `InvoiceWrite` zostały potwierdzone przed otwarciem sesji.

Lifecycle obejmował otwarcie online session, dokładnie jeden invoice POST, zamknięcie sesji oraz status GET. Nie wykonano resend, nowego attempt ani blind retry. Odpowiedź statusowa 200 przeszła korelację `referenceNumber` i `invoiceHash`, walidację formatu i CRC numeru KSeF oraz zgodność sprzedawcy; lokalny agregat zakończył się stanem `accepted`. Zamrożone środowisko, `context_nip`, `seller_nip`, payload, hash i rozmiar pozostały niezmienne.

Po live flow wykonano osobną read-only weryfikację z dokładnie jednym status GET. Ponownie otrzymano 200, ten sam numer KSeF i zgodne identity, bez nowego submission lub attempt. Następnie przywrócono poprzedni credential i kontekst TEST, unieważniono syntetyczny runtime auth i wykonano świeże uwierzytelnienie oraz diagnostykę `InvoiceWrite=YES`; artefakt rollback usunięto dopiero po pełnej weryfikacji restore. Dedykowana seria syntetyczna została wyłączona dla KSeF, a zaakceptowany Invoice/Order/Series/Submission zachowano jako audit trail. Istniejący submission nadal blokuje usunięcie Faktury zgodnie z `restrictOnDelete` i policy.

Walidacja miała `DEMO LIVE REQUESTS: 0` i `PRODUCTION LIVE REQUESTS: 0`; nie stanowi produkcyjnego certyfikatu gotowości. Nie udostępnia użytkownikowi akcji wysyłki, UPO, QR, offline, batch ani obsługi Korekt FA(3); Pro forma pozostaje wyłączona z KSeF. Scenariusze `uncertain`, timeout, connection error, 5xx i malformed 2xx są nadal chronione semantyką bez automatycznego resend i testami fake-only, ale nie były wszystkie wykonywane live.

### Ręczny workflow aplikacyjny KSeF.4B.1

Poniższe ograniczenia TEST-only i brak triggera `automatic_submission` są historycznym zakresem 4B.1. Dalsze etapy wdrożyły automatykę (6G), a 8D.2 Production; nie ograniczają one bieżącego workflow do TEST.

`KsefManualInvoiceSubmissionService` jest cienkim orkiestratorem policy nad niezmienionym transportem 4A. W zewnętrznej transakcji stosuje `lockForUpdate()` dla Faktury i singletonu `KsefSetting`, sprawdza brak jakiegokolwiek `KsefInvoiceSubmission` dla bieżącego środowiska i wywołuje istniejące `prepare()` dokładnie raz. Na bazach obsługujących `SELECT ... FOR UPDATE` blokada wiersza pomaga serializować podwójne żądania. Na lokalnym SQLite `lockForUpdate()` nie jest prawdziwą blokadą wiersza; bezpieczeństwo pierwszej próby opiera się na transakcyjnym guardzie, semantyce blokady zapisu SQLite, ponowieniu transakcji, atomowym wyznaczeniu numeru próby i ograniczeniu `UNIQUE(invoice_id, environment, attempt_number)`. Kontrolowany dwuprocesowy test na disposable SQLite potwierdził brak zduplikowanego submission i POST Faktury w badanym manualnym workflow, bez rozszerzania tego wyniku na wszystkie bazy i scenariusze. Guard obejmuje również `rejected` oraz `technical_failed`, ponieważ retry nie należy do 4B.1. Historia z innego środowiska nie blokuje first attempt. Po commit orkiestrator wywołuje `submit()` dokładnie raz, bez `refreshStatus()` i bez obejmowania HTTP transakcją bazy.

`KsefInvoiceSubmissionController` udostępnia wyłącznie dwie mutujące trasy POST z CSRF: pierwszą wysyłkę oraz ręczny refresh konkretnej próby. Refresh sprawdza ownership submission-Faktura przed delegacją i jest dopuszczany przez transport tylko dla `submitted` oraz `processing`; jedno żądanie wykonuje jeden status GET. Kontrolowane wyjątki pokazują wyłącznie bezpieczny komunikat, a nieoczekiwane błędy są redukowane do komunikatu ogólnego bez logowania sekretów lub payloadu.

Status i historia są renderowane na read-only ekranie Faktury VAT. `Invoice::latestKsefSubmission()` korzysta z `HasOne::ofMany()` i jest eager-loadowane na liście Faktur, dzięki czemu kompaktowy badge nie powoduje N+1. Panel szczegółowy korzysta z posortowanej historii `ksef_invoice_submissions`, pokazuje pełny numer KSeF dla `accepted`, bezpieczne błędy i wyraźne ostrzeżenie dla `uncertain`; nie ujawnia XML, hashy, NIP-ów, identyfikatorów sesji ani surowych odpowiedzi. Nie powstała kolumna statusu na `invoices` ani równoległy system eventów.

Workflow pozostaje TEST-only i zależy od deployment gate domyślnie `false`, aktywnej integracji oraz włączonej serii. Wyłączony gate ukrywa akcje, ale nie historię. `automatic_submission` nie ma żadnego triggera i pozostaje konfiguracją nieaktywną funkcjonalnie. Etap nie dodaje retry, reconciliation, kolejki, schedulera, automatycznego pollingu, UPO, QR, offline, batch, DEMO, PRODUCTION, Pro form ani Korekt KSeF.

24 sierpnia 2026 r. etap `KSeF.4B.1-LIVE-UI-TEST-001` przeszedł kontrolowany happy path przez normalny ręczny workflow aplikacji na środowisku KSeF TEST. Syntetyczna Faktura VAT została wysłana dokładnie jednym invoice POST-em, bez automatycznego odświeżenia i bez drugiego attemptu; po jednym ręcznym status GET otrzymała stan `accepted` oraz prawidłowy numer KSeF. Historia i bieżący status były poprawnie widoczne w UI bez ujawnienia XML ani sekretów.

Przed właściwym happy pathem odrębny syntetyczny dokument zakończył się kontrolowanym `network_error` przed pobraniem klucza sesji, otwarciem sesji i invoice POST-em; dokumentu nie wysłano ponownie. Po teście wyłączono ponownie dedykowaną serię `KSEF UI LIVE TEST`, a deployment gate wrócił do domyślnego stanu `false`; kontekst i credential TEST nie były czasowo zmieniane. `DEMO LIVE REQUESTS: 0`; `PRODUCTION LIVE REQUESTS: 0`.

### KSeF.5A — lifecycle, reconciliation i policy kolejnych prób

TEST-only poniżej określa historyczny zakres 5A. Aktualne środowiska i granice LIVE PROD opisuje 8D.

`KsefInvoiceSubmissionStatus` jest centralnym źródłem reguł lifecycle. `preparing` i `session_opened` są stanami aktywnymi bez status lookup; po udanym wysłaniu warstwa manualnego workflow wykonuje jeden natychmiastowy status GET bez pollingu, retry ani ponownego POST Faktury. `submitted` i `processing` pozwalają nadal na pojedynczy ręczny refresh; `accepted` jest terminalnym sukcesem; `rejected` i `technical_failed` są terminalnymi wynikami, po których policy może dopuścić nowy attempt; `uncertain` nie jest wynikiem terminalnym i wymaga reconciliation. Każdy status blokuje zmianę i usunięcie Faktury powiązanej z audit trail KSeF. Dozwolone przejścia są deklarowane przez enum i egzekwowane pod `lockForUpdate()` przy zapisie statusu. Wspólny `KsefInvoiceStatusFollowUpService` po sprawdzeniu lub uzgodnieniu statusu planuje osobny background UPO, jeżeli wynik zmienił się na `accepted`; idempotentny `KsefInvoiceUpoService` jest wywoływany dopiero przez późniejszy follow-up albo jawną ręczną akcję i nigdy nie ponawia invoice POST.

`KsefInvoiceSubmissionLifecyclePolicy` ocenia całą historię pary Faktura-środowisko. Pierwsza próba jest dozwolona przy pustej historii, a kolejna tylko wtedy, gdy wszystkie wcześniejsze próby mają jednoznaczny stan `rejected` albo retry-safe `technical_failed`. W obecnym transporcie `technical_failed` powstaje wyłącznie przed invoice POST-em albo po jednoznacznej odpowiedzi błędnej, natomiast timeout, connection error, 5xx oraz niekompletna lub malformed odpowiedź po możliwym side effect zawsze prowadzą do `uncertain`. Obecność wcześniejszego `accepted`, dowolnego attemptu aktywnego albo `uncertain` blokuje nową próbę. Nowy attempt otrzymuje kolejny `attempt_number` i własny zaszyfrowany payload; poprzedni rekord i dokładny XML pozostają niezmiennym audit trail.

Ręczne reconciliation działa wyłącznie dla `uncertain` i nigdy nie wykonuje invoice POST. Gdy submission posiada `invoice_reference_number`, używany jest istniejący `GET /sessions/{sessionReference}/invoices/{invoiceReference}`. Gdy niejednoznaczny POST nie zwrócił referencji Faktury, system pobiera pierwszą stronę `GET /sessions/{sessionReference}/invoices` dla dedykowanej sesji jednej Faktury i akceptuje wyłącznie dokładnie jedno dopasowanie zapisanego `invoiceHash`; dodatkowe strony, brak dopasowania albo wiele dopasowań pozostawiają stan `uncertain`. Odzyskana referencja jest zapisywana pod blokadą, po czym wspólny mapper statusu nadal wymaga zgodnych `referenceNumber` i `invoiceHash`, prawidłowego numeru KSeF z CRC oraz zgodności NIP-u sprzedawcy. Wynikiem może być `processing`, `accepted`, `rejected` albo nadal `uncertain`.

Ręczny orkiestrator nadal blokuje Fakturę i konfigurację przed przygotowaniem próby, nie obejmuje HTTP transakcją i wykonuje najwyżej jeden invoice POST na attempt. Równoległe żądanie retry po utworzeniu nowego rekordu widzi `preparing` i jest blokowane; ograniczenie `UNIQUE(invoice_id, environment, attempt_number)` pozostaje zabezpieczeniem bazowym. SQLite nie zapewnia pełnej semantyki row lock dla `lockForUpdate()`, dlatego nie jest traktowany jako dowód zachowania każdej docelowej bazy, ale transakcja, blokada zapisu SQLite i indeks unikalny zapobiegają utworzeniu dwóch legalnych prób o tym samym numerze. Refresh i reconciliation nigdy nie tworzą submissionu.

Panel Faktury pokazuje retry jako „Utwórz nową próbę KSeF TEST” wyłącznie po wyniku dopuszczonym przez policy. Dla `uncertain` pokazuje ostrzeżenie zakazujące ponownego wysłania oraz akcję „Sprawdź wynik transmisji”, jeśli istnieje referencja sesji. `accepted` nie ma akcji wysyłki. Historia nie ujawnia XML, hashy, NIP-ów, referencji ani sekretów. KSeF.5A pozostaje ręczne i TEST-only: nie dodaje UPO, kolejki, Automation, schedulera, automatycznej wysyłki ani pollingu, DEMO, PRODUCTION, Korekt FA(3), QR, offline lub batch. Implementacja i testy nie wykonują live requestów.

### KSeF.5B — UPO Faktury

Ograniczenie środowiska w opisie 5B jest historyczne. W 8D.5 pobrano rzeczywiste UPO PROD, lecz nadal bez osobnej kryptograficznej weryfikacji podpisu; nie należy utożsamiać obecności podpisu z jego weryfikacją.

Indywidualne UPO można pobrać ręcznie wyłącznie dla zaakceptowanej Faktury VAT ze środowiska TEST. `KsefInvoiceUpoService` korzysta z zamrożonych danych próby oraz endpointu `GET /sessions/{referenceNumber}/invoices/ksef/{ksefNumber}/upo`; nie wykonuje invoice POST, nie tworzy nowej próby i nie zmienia statusu `accepted`. Deployment gate oraz aktywna integracja są wymagane tylko dla pobrania z MF. Po poprawnym zapisie lokalny download działa bez gate, access tokenu i kolejnego requestu do KSeF.

`KsefHttpClient::getRaw()` zachowuje dokładne bajty XML i udostępnia wyłącznie wybrany nagłówek `x-ms-meta-hash` oraz zsanityzowany `X-System-Warning`. Przed parsowaniem obliczany jest SHA-256 dokładnego body w Base64 i porównywany z nagłówkiem MF. Następnie XML przechodzi lokalną walidację niezmodyfikowanym oficjalnym XSD UPO v4-3 z `LIBXML_NONET` oraz kontrolę jednej pozycji dokumentowej, referencji sesji, NIP-u kontekstu i sprzedawcy, numeru KSeF, numeru Faktury, hasha FA(3), struktury `FA (3)` i trybu `Online`.

Oficjalny XSD został przypięty z repozytorium CIRFMF, commit `1c34fe2799387d517b83a2fb21e31e83d5f66247`; SHA-256 pliku `upo-v4-3.xsd` to `1e5ff386a29324021a9e0126319680aec0b1e0d4f4a18add30b2f5d12ce6fa86`. Oficjalny przykład TEST zawiera rozszerzoną nazwę podmiotu przyjmującego, podczas gdy przypięty XSD ma dla tego elementu stałą wartość `Ministerstwo Finansów`. Implementacja nie modyfikuje oficjalnego schematu i failuje bezpiecznie; rzeczywiste zachowanie TEST w tym punkcie wymaga osobnego kontrolowanego `KSeF.5B-LIVE-UPO-TEST-001`.

`ksef_invoice_upos` przechowuje najwyżej jeden immutable artefakt na submission dzięki unikalnemu kluczowi obcemu z `restrictOnDelete`. Dokładny XML ma encrypted cast; jawnie przechowywane są tylko identyfikator schematu, SHA-256 Base64, rozmiar i czas pobrania. Zapis następuje po walidacji, w krótkiej transakcji z ponownym `lockForUpdate()` i kontrolą niezmienności submissionu. Błąd transportu, hasha, XSD lub identity nie zapisuje UPO i nie zmienia lifecycle transmisji.

Cryptographically verified: **NO**. KSeF.5B zachowuje dokładny XML zwrócony przez HTTPS, weryfikuje nagłówek hasha MF, oficjalny XSD i identity dokumentu, ale nie deklaruje lokalnej walidacji podpisu XAdES ani łańcucha zaufania MF. Oficjalny XSD i przykłady v4-3 nie udostępniają w tym miejscu kompletnego kontraktu truststore potrzebnego do małego, niezależnego weryfikatora. Taki hardening wymaga osobnego etapu przed PRD. KSeF.5B nie obejmuje sesyjnego lub batch UPO, automatyzacji, retry, DEMO, PRODUCTION, Korekt, offline ani QR; testy są wyłącznie fake-only i nie wykonują live requestów.

#### KSeF.5B-LIVE-UPO-TEST-001 — BLOCKED

24 sierpnia 2026 r. pojedynczy kontrolowany request do KSeF TEST dla istniejącego zaakceptowanego submissionu zwrócił `200 application/xml`, dokładnie jeden dokument UPO w namespace v4-3 i prawidłowy `x-ms-meta-hash`; SHA-256 Base64 dokładnych bajtów odpowiedzi był zgodny. Wszystkie kontrole identity NEX przeszły, a odpowiedź zawierała element podpisu XMLDSig. Nie wykonano invoice POST, otwarcia sesji, status GET, retry ani requestów DEMO/PRODUCTION.

Oficjalny, niezmodyfikowany XSD v4-3 odrzucił realny dokument TEST dwoma konfliktami kontraktu. `NazwaPodmiotuPrzyjmujacego` ma w XSD stałą wartość `Ministerstwo Finansów`, podczas gdy TEST zwrócił `Ministerstwo Finansów - środowisko testowe (TE)`. Ponadto live XML zawiera `Signature`, którego przypięty XSD nie deklaruje i nie dopuszcza przez wildcard. XSD pozostawiono bez zmian, walidacji nie osłabiono, UPO nie zapisano jako zweryfikowanego, nie wykonano drugiego GET, a submission pozostał `accepted` z niezmienioną tożsamością i payloadem. Wynik etapu: `BLOCKED_BY_MF_TEST_XSD_INCONSISTENCY`; wymaga osobnej decyzji architektonicznej o oficjalnym schemacie i walidacji podpisanego UPO.

#### KSeF.5B.1 — signed UPO compatibility validation

NEX rozdziela oryginalny artefakt UPO od tymczasowej projekcji używanej wyłącznie do walidacji oficjalnym XSD. Hash z nagłówka MF jest weryfikowany na niezmienionych bajtach odpowiedzi. Oryginalny XML musi zawierać dokładną nazwę odbiorcy właściwą dla środowiska (`Ministerstwo Finansów - środowisko testowe (TE)` dla TEST, `Ministerstwo Finansów - środowisko przedprodukcyjne (TR)` dla DEMO i `Ministerstwo Finansów` dla PRODUCTION), dokładnie jeden element `ds:Signature` w namespace XMLDSig oraz zgodną tożsamość sesji, podmiotów i Faktury. Wszystkie te kontrole są wykonywane przed utworzeniem projekcji i na oryginalnym XML.

Niezależna, nieutrwalana projekcja DOM usuwa wyłącznie wcześniej zweryfikowany `ds:Signature` i zmienia wcześniej zweryfikowaną nazwę odbiorcy na stałą `Ministerstwo Finansów`, wymaganą przez niezmieniony oficjalny XSD UPO v4-3. Każdy inny błąd XSD nadal odrzuca dokument. Do bazy oraz lokalnego pobrania trafia wyłącznie oryginalny, podpisany XML bez jakiejkolwiek normalizacji.

`Original UPO XSD-valid: NO`. `Compatibility projection XSD-valid: YES`. `Original artifact preserved byte-for-byte: YES`. `Cryptographically verified: NO`. `Signed artifact presence verified: YES`. Etap potwierdza obecność podpisu XMLDSig, ale nie deklaruje poprawności kryptograficznej XAdES, zaufania certyfikatu ani poprawności łańcucha zaufania.

#### KSeF.5B-LIVE-UPO-TEST-002 — PASS

24 sierpnia 2026 r. istniejący zaakceptowany submission TEST dla Faktury VAT `KSEF-UI 2/2026` przeszedł kontrolowany workflow pobrania UPO przez produkcyjną trasę aplikacji. Wykonano dokładnie jeden UPO GET oraz jeden wymagany refresh tokena dostępu; nie wykonano invoice POST, otwarcia lub zamknięcia sesji ani status GET. Odpowiedź `200 application/xml` miała poprawny `x-ms-meta-hash`, a SHA-256 Base64 dokładnych 5460 bajtów odpowiedzi był zgodny.

Oryginalny XML zawierał dokładną nazwę odbiorcy TEST, dokładnie jeden rootowy `ds:Signature`, jeden dokument oraz zgodne identity sesji, kontekstu, sprzedawcy, numeru KSeF, numeru Faktury, hasha FA(3), formularza, struktury logicznej i trybu `Online`. `Original UPO XSD-valid: NO` zgodnie ze znanymi konfliktami receiver/Signature. Oddzielna projekcja usunęła podpis i znormalizowała nazwę wyłącznie w pamięci; `Compatibility projection XSD-valid: YES` względem niezmienionego oficjalnego XSD.

Do `ksef_invoice_upos` zapisano dokładnie jeden oryginalny artefakt byte-for-byte, zaszyfrowany at rest, z zachowanym podpisem i nazwą TEST. Lokalny download zwrócił identyczne bajty bez requestu do MF, a ponowne application-level pobranie użyło istniejącego UPO i również nie wykonało requestu. Submission pozostał `accepted`, deployment gate przywrócono do `false`, `DEMO LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0`. `Original artifact preserved byte-for-byte: YES`. `Cryptographically verified: NO`. `Signed artifact presence verified: YES`.

### KSeF.6A — DEMO enablement i UI zależne od środowiska

Opis TEST/DEMO, blokady UI Production oraz braku triggera automatycznego dotyczy historycznego 6A. Trigger dodano w 6G, a obsługę Production we wspólnej polityce i UI w 8D.2.

`KsefOperationalEnvironmentPolicy` jest jednym źródłem prawdy dla ręcznych operacji Faktury: TEST i DEMO dopuszczają przygotowanie oraz wysyłkę, refresh statusu, reconciliation i zdalne pobranie UPO, natomiast PRODUCTION pozostaje zablokowane przed HTTP. Niezależny deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED` nadal domyślnie ma wartość `false`; operacja wymaga jednocześnie włączonego gate i zgody policy. Test połączenia i diagnostyka credentiali nie są operacyjnym transportem Faktur i nie zostały objęte tą blokadą.

Credentiale, runtime tokeny, historia prób, lifecycle i numer próby pozostają rozdzielone per environment, bez fallbacku między TEST, DEMO i PRODUCTION. Panel Faktury wyznacza bieżącą próbę względem aktywnego `ksef_settings.environment`, pokazuje pełną historię z oznaczeniem środowiska i generuje dynamiczne etykiety TEST albo DEMO. DEMO ma jawne ostrzeżenie o danych testowych/fikcyjnych oraz osobne potwierdzenie przed wysyłką; dla PRODUCTION panel pokazuje blokadę i nie udostępnia operacji zdalnych. Pobranie lokalnie zapisanego historycznego UPO pozostaje dostępne niezależnie od bieżącego środowiska i deployment gate.

KSeF.6A pozostaje workflow ręcznym i został zweryfikowany wyłącznie przez fake HTTP, bez requestów live do TEST, DEMO lub PRODUCTION. `automatic_submission` nadal nie ma triggera. Zakładka „Eksportuj dokumenty” pozostaje poza zakresem do KSeF.6B, a kontrolowany DEMO E2E pozostaje osobnym etapem KSeF.6C. Status: `KSeF.6A CLOSED`.

### KSeF.6B — miesięczny eksport niewysłanych Faktur

Wyłączenie Production poniżej było ograniczeniem 6B, usuniętym w 8D.2. Eksport miesięczny pozostaje operacją ręczną, odrębną od późniejszego automatic first-send.

Zakładka „Eksportuj dokumenty” udostępnia prosty ręczny formularz wzorowany na modelu Base: wybór bieżącego albo jednego z 12 poprzednich miesięcy oraz polecenie eksportu. Formularz przekazuje wyłącznie miesiąc `YYYY-MM`; nie zawiera wyboru środowiska. Środowisko jest snapshotowane z `KsefSetting.environment` na początku operacji, a przed każdą pierwszą próbą istniejący workflow pod blokadą potwierdza, że konfiguracja nadal odpowiada snapshotowi. Zmiana środowiska zatrzymuje pozostałą część eksportu bez przełączenia kolejnych dokumentów na nowy host.

Kwalifikują się wyłącznie sfinalizowane Faktury VAT z `issue_date` należącą do wybranego miesiąca, których seria jest aktualnie włączona w `KsefSeriesSetting` i które nie mają żadnego `KsefInvoiceSubmission` w aktywnym środowisku. Dowolna istniejąca próba w tym środowisku, w tym `rejected` albo `technical_failed`, wyklucza dokument z eksportu; ponowienie pozostaje świadomą operacją na pojedynczej Fakturze. Historia innego środowiska nie wyklucza pierwszej próby. Dokumenty są przetwarzane deterministycznie według `issue_date`, a następnie `id`.

Każda Faktura korzysta osobno z istniejącego `KsefManualInvoiceSubmissionService`, własnego submissionu i własnej sesji. Nie ma transakcji obejmującej cały miesiąc i HTTP, batch persistence, automatycznego retry, status pollingu, reconciliation ani pobierania UPO. Błąd konkretnego dokumentu nie blokuje następnych, natomiast `429`, błąd sieci, globalny błąd autoryzacji/credentiala albo awaria API zatrzymują pozostałe dokumenty bez sleep/retry. TEST i DEMO są dopuszczone przez `KsefOperationalEnvironmentPolicy`; PRODUCTION pozostaje zablokowane przed HTTP, a niezależny deployment gate oraz `KsefSetting.is_active` są nadal wymagane.

KSeF.6B pozostaje synchroniczną operacją ręczną, bez queue, schedulera, Automation i triggera `automatic_submission`. Etap został zweryfikowany fake-only, bez live requestów. KSeF.6C pozostaje osobnym kontrolowanym DEMO E2E. Status: `KSeF.6B CLOSED`.

### KSeF.6B.1 — first send from Invoice list

To opis historycznego 6B.1: finalize-on-send dodano w 6E, a blokadę Production usunięto w 8D.2.

Lista Faktur VAT pokazuje kompaktową akcję pierwszej wysyłki bez konieczności otwierania szczegółów dokumentu. Bieżące środowisko pochodzi wyłącznie z `KsefSetting.environment`; formularz listy nie przekazuje ani nie wybiera środowiska. Status w kolumnie KSeF jest najnowszym submissionem aktywnego środowiska, pobranym zbiorczo dla całej strony. Historia TEST nie udaje statusu DEMO i odwrotnie, a liczba zapytań KSeF nie rośnie wraz z liczbą wierszy.

Dedykowana trasa POST korzysta z `KsefManualInvoiceSubmissionService::submitFirstAttempt()` oraz istniejących blokad Faktury, konfiguracji i historii prób. Akcja jest dostępna wyłącznie dla sfinalizowanej Faktury VAT z aktualnie włączoną serią, aktywną integracją, deployment gate i środowiskiem dopuszczonym przez `KsefOperationalEnvironmentPolicy`. Dowolny submission w bieżącym środowisku wyłącza first send z listy, także `rejected` i `technical_failed`; świadomy retry, status refresh, reconciliation i UPO pozostają na ekranie pojedynczej Faktury. TEST i DEMO są obsługiwane, a PRODUCTION blokowane przed HTTP.

KSeF.6B.1 nie dodaje zbiorczej akcji checkboxów, kolejki, Automation, schedulera, status pollingu ani nowego mechanizmu transportowego. Miesięczny eksport KSeF.6B pozostaje bez zmian. Wszystkie regresje są fake-only, bez live requestów; KSeF.6C pozostaje osobnym etapem. Status: `KSeF.6B.1 CLOSED`.

### KSeF.6C — kontrolowana weryfikacja DEMO E2E

24 sierpnia 2026 r. diagnostyka `KSeF.6C-NETWORK-DIAG-001` przeszła w tym samym procesie PHP co aplikacja webowa, z bezpośrednim połączeniem wyłącznie do oficjalnego hosta DEMO. Refresh tokena dostępu, pobranie klucza publicznego, otwarcie pustej sesji i jej zamknięcie zakończyły się poprawnie, bez invoice POST. Wcześniejszy `network_error` wystąpił na tymczasowej lokalnej ścieżce proxy/forwardera przed dotarciem pierwszego requestu `/auth/token/refresh` do obserwatora lub MF; bezpośredni transport produkcyjnego kodu nie wykazał błędu. Status: `KSeF.6C-NETWORK-DIAG-001 — PASS`.

Po potwierdzeniu operatora wykonano `KSeF.6C-LIVE-DEMO-E2E-004` na istniejącej Fakturze testowej. Z ekranu pojedynczej Faktury utworzono dokładnie próbę nr 2, zachowując próbę nr 1 jako `technical_failed/network_error`; nie powstała próba nr 3. Próba nr 2 wykonała dokładnie jeden invoice POST, bez automatycznego retry i bez udziału eksportu miesięcznego. Status został pobrany jeden raz i przeszedł bezpośrednio do `accepted`; numer KSeF, tożsamość dokumentu oraz zamrożone metadane FA(3) przeszły walidację.

UPO pobrano z MF dokładnie raz. Oryginalny podpisany XML został zweryfikowany semantycznie, zachowany byte-for-byte i zaszyfrowany at rest; lokalne pobranie zwróciło identyczne bajty bez kolejnego requestu do MF, a ponowne application-level pobranie było idempotentne. Oryginalny artefakt zawiera jeden rootowy podpis XMLDSig; obecność podpisu została potwierdzona, ale nie wykonano kryptograficznej weryfikacji XAdES. `Original UPO XSD-valid: NO`. `Compatibility projection XSD-valid: YES`.

Cały przebieg wykonał `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0` oraz `MONTHLY EXPORT LIVE POST: 0`. Konfigurację środowiska, aktywność serii i mapowanie serii przywrócono do wartości sprzed próby, serwer diagnostyczny zatrzymano, a deployment gate ponownie ma domyślną wartość `false`. Historia obu prób i UPO pozostała trwałym audytem. Statusy: `KSeF.6C-LIVE-DEMO-E2E-004 — PASS`, `KSeF.6C CLOSED`, `KSeF.6 CLOSED`.

### KSeF.6D — lokalne włączenie operacyjne

Stan konfiguracji i blokada Production poniżej to obserwacja z 24.08.2026, sprzed 8D.2, nie ponowna kontrola dzisiejszego runtime.

24 sierpnia 2026 r. lokalny deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED` został świadomie pozostawiony aktywny do bieżących testów TEST i DEMO. Po zwykłym restarcie aplikacji kwalifikująca się Faktura w aktywnej serii KSeF pokazuje na liście akcję `WYŚLIJ`. Niezależna `KsefOperationalEnvironmentPolicy` nadal blokuje PRODUCTION przed utworzeniem submissionu i przed HTTP. Domyślna wartość w konfiguracji oraz `.env.example` pozostają `false`.

Kontrolowany smoke test nowej Faktury z wyłącznie fikcyjnymi danymi wykonał dokładnie jedną próbę i jeden invoice POST do DEMO, bez automatycznego retry i bez eksportu miesięcznego. Dokument przeszedł do `accepted`, otrzymał numer KSeF i jedno UPO. Oryginalne UPO przeszło walidację hash, tożsamości, odbiorcy DEMO i obecności pojedynczego podpisu, zostało zapisane szyfrowane oraz pobrane lokalnie byte-for-byte bez kolejnego requestu do MF; zgodność projekcji z UPO v4-3 przeszła. `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0`, `MONTHLY EXPORT LIVE POST: 0`. Lokalny runtime pozostał z gate `true`, a dedykowana seria DEMO pozostała aktywna i włączona do KSeF. Status: `KSeF.6D CLOSED`.

### KSeF.6E — finalize-on-send

Lista Faktur pokazuje akcję `WYŚLIJ` dla spójnej, wystawionej Faktury VAT kwalifikującej się do KSeF także wtedy, gdy dokument nie został jeszcze zamknięty. Potwierdzenie informuje, że wysłanie zamknie Fakturę i uniemożliwi jej dalszą edycję; dla DEMO zachowuje również ostrzeżenie o środowisku testowym. Szkice, dokumenty niespójne oraz Faktury posiadające historię w bieżącym środowisku nie otrzymują tej akcji. Zapytania KSeF listy pozostają stałe względem liczby wierszy, bez N+1.

Pierwsza próba ręczna wykonuje w jednej lokalnej transakcji `finalize -> authoritative FA(3) -> frozen submission prepare`. Używa istniejącego `InvoiceFinalizationService`, zachowuje kolejność blokad `Order -> Invoice` i rozpoczyna transport HTTP dopiero po zatwierdzeniu lokalnej transakcji. Błąd lokalnego preflightu albo przygotowania FA(3) wycofuje finalizację oraz submission. Po lokalnym commit finalizacja nie jest cofana: błąd przed wysłaniem zapisuje `technical_failed`, a niejednoznaczny wynik invoice POST zapisuje `uncertain` i wymaga reconciliation, bez ślepego retry. Dotychczasowy przepływ dokumentów już zamkniętych pozostaje bez zmian, a eksport miesięczny nadal wybiera wyłącznie Faktury z `finalized_at`.

Kontrolowany przebieg DEMO z fikcyjną, początkowo niezamkniętą Fakturą potwierdził akcję listy, jedną próbę i jeden invoice POST. Dokument został lokalnie zamknięty, następnie przyjęty, otrzymał numer KSeF oraz jedno podpisane UPO zapisane szyfrowane. UPO pobrano lokalnie byte-for-byte, a drugi odczyt aplikacyjny nie wykonał kolejnego requestu do MF. Edycja i usunięcie po finalizacji zostały zablokowane przez istniejące polityki. Wykonano jeden status GET i jeden zdalny UPO GET; `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0`, `MONTHLY EXPORT LIVE POST: 0`. Lokalny gate pozostaje `true`, natomiast `.env.example` i domyślna konfiguracja pozostają `false`. Faktura `BLF 1/2026` (id `94`) była w chwili końcowej kontroli już zamknięta i posiadała przyjętą próbę DEMO, dlatego poprawnie pokazywała status KSeF zamiast `WYŚLIJ`; nie wykonano na niej żadnej operacji live. Statusy: `KSeF.6E CLOSED`, `KSeF.6 CLOSED`.

### KSeF.6F — background lifecycle follow-up

Historyczny follow-up 6F obejmował TEST/DEMO. Obecny dispatcher używa centralnej polityki obejmującej od 8D.2 także Production; nadal odczytuje środowisko zapisanej próby, bez fallbacku.

`ksef_invoice_submissions` pozostaje jedynym trwałym źródłem prawdy lifecycle; nie istnieje pomocnicza tabela work items. Po zachowanym natychmiastowym status GET rekord otrzymuje `next_follow_up_at`. Scheduler co minutę wybiera ograniczoną partię zaległych rekordów i dispatchuje na bazodanową kolejkę `ksef` jeden `ShouldBeUnique` job per submission. Job ma frameworkowe `tries = 1`; ponowienia wynikają wyłącznie z metadanych submissionu, dlatego restart schedulera lub workera nie gubi pracy.

Automat wykonuje wyłącznie bezpieczne odczyty: `submitted` i `processing` uruchamiają status GET, `uncertain` reconciliation bez invoice POST, a `accepted` bez lokalnego UPO uruchamia UPO GET. `accepted` z UPO, `rejected` i `technical_failed` kończą obsługę; `preparing` i `session_opened` nie są automatycznie wysyłane. Zwykły online invoice POST, attempt 2 i resend nie występują w jobie. HTTP pozostaje poza transakcją bazy, a krótki claim, unikalność joba i blokada per submission zabezpieczają wyścig z działaniami ręcznymi.

Backoff kolejnych terminów wynosi 1, 5, 15 i 60 minut, a później pozostaje godzinny. `Retry-After` może termin wyłącznie opóźnić. Lokalny limiter jest rozdzielony przez operację, środowisko i zamrożony NIP kontekstu. Dla statusu stosuje konserwatywne limity 20/s, 90/min i 900/h wobec limitów MF 30/s, 120/min i 1200/h; dla reconciliation 5/s, 15/min i 90/h, a dla UPO 5/s, 20/min i 90/h. Limiter i rozmiar partii są konfigurowalne w `config/ksef.php`.

Sieć, 429, 5xx, nierozstrzygnięte reconciliation oraz chwilowo niedostępne UPO zachowują prawdziwy status KSeF i planują dalszy odczyt. Błędy konfiguracji, własności i integralności UPO zatrzymują automatykę z bezpieczną diagnostyką do ręcznej obsługi. Przyjęcie nadal unieważnia cache PDF, ale worker nie generuje PDF z wyprzedzeniem. Gate lub nieaktywna integracja pozostawiają zaległy termin bez HTTP, follow-up zawsze używa `submission.environment`, a PRODUCTION pozostaje zablokowane. Architektura może później otrzymać osobną decyzję dla `OfflinePending`, lecz KSeF.6F nie implementuje trybu offline ani certyfikatu Offline. Na serwerze wymagane są stale działający worker kolejki `ksef` oraz minutowe wywołanie Laravel scheduler.

### KSeF.6F.1 — queue hardening

Job follow-up zachowuje frameworkową unikalność per submission, a konfigurowalny `uniqueFor` wynosi sześć godzin. Laravel zwalnia blokadę po prawidłowym wykonaniu joba, natomiast po utracie samego rekordu kolejki blokada wygasa w ograniczonym czasie i scheduler może odtworzyć pracę z autorytatywnego `ksef_invoice_submissions`. Dzięki temu wielokrotne uruchomienie minutowego schedulera podczas postoju workera nie odkłada liniowo duplikatów i nie tworzy trwałego zombie locka.

Nullable `follow_up_action` jest wyłącznie techniczną metadaną o wartościach `status`, `reconcile` albo `upo`, a nie statusem biznesowym. `follow_up_attempts` pozostaje progresywny dla tej samej akcji, lecz zmiana `status -> upo`, `reconcile -> status` albo `reconcile -> upo` zeruje licznik i rozpoczyna sekwencję `1m -> 5m -> 15m -> 60m`; `Retry-After` nadal może wyłącznie odsunąć termin. Scheduler nie wykonuje HTTP, a dispatcher, job i processor nie wykonują online invoice POST ani automatycznej drugiej próby wysłania.

Końcowy kontrolowany test LIVE w środowisku DEMO na dedykowanej fikcyjnej Fakturze `KSEF-DEMO-E2E-003 5/2026` (id `124`) wykonał dokładnie jeden invoice POST. MF od razu zwróciło status `Accepted`, numer KSeF oraz dostępne UPO, dlatego natychmiastowa ścieżka wykonała jeden status GET i jeden UPO GET, zapisała jedno UPO i zakończyła rekord bez `next_follow_up_at`; worker nie musiał obsługiwać tej próby. Nie wykonano attempt 2, reconciliation, eksportu miesięcznego ani requestów LIVE do TEST lub PRODUCTION. Kolejka `ksef` oraz zbiór zaplanowanych follow-upów pozostały puste, a kolejny PDF zawierał numer KSeF, datę przetworzenia, status `Zaakceptowana` i kod QR.

### KSeF.6F.2 — token validity timezone normalization

`accessToken.validUntil` i `refreshToken.validUntil` są zdalnymi timestampami opisującymi konkretny instant. `APP_TIMEZONE` pozostaje `Europe/Warsaw`; wspólny `KsefTokenValidityNormalizer` zachowuje instant i przed zapisem przelicza go na strefę aplikacji, ponieważ istniejące kolumny bez offsetu oraz cast `immutable_datetime` przechowują i odtwarzają lokalny wall clock. Pełne uwierzytelnianie Tokenem i Certyfikatem korzysta z tego kontraktu przez `KsefTokenPair`, a refresh używa tego samego normalizera. Testy roundtrip DB potwierdzają ponowne użycie świeżego access tokenu po reload modelu oraz poprawne przesunięcia `Europe/Warsaw` latem, zimą i przy zmianie DST.

### KSeF.6G — automatic submission of newly issued invoices

`InvoiceIssuingService` pozostaje centralnym wejściem dla ręcznego wystawiania i istniejącej akcji Automation. Po utworzeniu Faktury dispatcher ocenia deployment gate, `is_active`, `automatic_submission`, bieżące środowisko oraz dokładną serię dokumentu. Dla kwalifikującej się Faktury dispatchuje dopiero po commit trwały `ShouldBeUnique` job. Zmiana ustawienia nie obejmuje historycznych Faktur.

Job ponownie ładuje Fakturę i sprawdza niezmienność środowiska, kontekstu NIP, konfiguracji, serii oraz brak istniejącego submissionu przed jakimkolwiek HTTP. Następnie używa `KsefManualInvoiceSubmissionService::submitFirstAttempt()`, zachowując atomowe `finalize -> authoritative prepare`, jeden invoice POST i brak automatycznego attempt 2 lub resend. Restart workera nie gubi oczekującej pierwszej wysyłki, ponieważ job pozostaje w bazie Laravel.

Automatyczna pierwsza wysyłka dotyczy wyłącznie nowych Faktur VAT. W 6G obsługiwała TEST/DEMO; od 8D.2 centralna polityka dopuszcza także Production. Pro formy i Korekty pozostają odrzucane przed HTTP. Minutowy scheduler nadal dispatchuje wyłącznie odczytowe follow-upy statusu, reconciliation i UPO. Implementacja PROD nie oznacza aktywacji automatic first-send ani zweryfikowanej pracy bez nadzoru w instalacji operatora (stan raportów 8D.4/8D.5).

### KSeF.6G.1 — automatic submission hardening and asynchronous UPO

Automatyczny first-send używa dedykowanego połączenia bazodanowego `ksef_submit` i kolejki `ksef-submit`. Job ma `timeout = 120 s`, `tries = 1`, a połączenie `retry_after = 240 s`. Wartości te określają planowany margines między limitem wykonania joba a ponowną dostępnością zadania po wygaśnięciu rezerwacji; same nie potwierdzają rzeczywistego egzekwowania timeoutu. Przed uruchomieniem pracy bez nadzoru należy na docelowym serwerze potwierdzić, że używany PHP CLI oraz konfiguracja nadzoru procesów zapewniają zakończenie zadania przed upływem rezerwacji. Osobny worker uruchamia się przez `php artisan queue:work ksef_submit --queue=ksef-submit --sleep=1 --tries=1 --timeout=120`. Odczytowe follow-upy pozostają na `php artisan queue:work database --queue=ksef --sleep=3 --tries=1 --timeout=60`.

Payload joba zawiera wyłącznie ID Faktury, środowisko i `context_nip`. Deployment gate, `is_active`, `automatic_submission`, seria, środowisko i kontekst są ponownie sprawdzane przed first-attempt. Każda zmiana przed uruchomieniem workera anuluje konkretny job z zerem submissionów i zerem HTTP; ponowne włączenie konfiguracji nie wykonuje recovery ani backfillu. Faktura pozostaje do tego momentu edytowalna, a autorytatywny FA(3) powstaje z jej aktualnej wersji dopiero podczas finalizacji w workerze. Nie jest snapshotowany `lock_version` i nie powstał outbox.

Docelowa instalacja NEX jest planowana na serwerze, najprawdopodobniej VPS. Windows/XAMPP pozostaje środowiskiem lokalnego rozwoju i kontrolowanych testów. Dotychczasowe założenie wolumenu wynosi około 30 Faktur na godzinę. First-send nie posiada lokalnego limitera ani rezerwacji opóźnionych slotów: kwalifikujący się job jest gotowy natychmiast po commit, a jeden zalecany worker `ksef-submit` wykonuje joby sekwencyjnie. Oficjalne limity PRD dla sesji interaktywnej wynoszą 10/s, 30/min i 120/h dla otwarcia oraz zamknięcia sesji, a dla invoice POST 10/s, 30/min i 180/h; DEMO stosuje limity PRD. Odpowiedź `429` jest obsługiwana defensywnie z zachowaniem `Retry-After`, lecz nigdy nie uruchamia automatycznego attempt 2 ani ponownego invoice POST.

Pierwsze wysłanie, ręczne lub automatyczne, wykonuje najwyżej jeden natychmiastowy status GET. Przejście do `Accepted` zapisuje numer KSeF, natychmiast usuwa cache PDF i ustawia `follow_up_action = upo`, `follow_up_attempts = 0`, `next_follow_up_at = now + 1 min`. UPO nie jest pobierane w tym samym request/jobie; delayed `KsefSubmissionFollowUpJob` trafia na kolejkę `ksef`, a trwały termin pozostaje źródłem recovery dla schedulera. Pierwsza nieudana próba UPO przechodzi na 5 min, potem 15 min i 60 min, z pierwszeństwem `Retry-After`. Jawne „Pobierz UPO teraz” nadal działa synchronicznie i po sukcesie wygasza późniejszy job bez dodatkowego HTTP.

### PDF autorytatywnej Faktury KSeF z karty zamówienia

Dla zaakceptowanego submissionu karta zamówienia pokazuje klikalne `KSeF: <numer OMS>`. Kontrolowana trasa pobiera przez `GET /invoices/ksef/{ksefNumber}` dokładny XML z zamrożonego środowiska submissionu, używa istniejącego access-token managera i wymaga aktywnej integracji, deployment gate oraz środowiska dopuszczonego przez `KsefOperationalEnvironmentPolicy`. SHA-256 Base64 odpowiedzi musi zgadzać się jednocześnie z nagłówkiem `x-ms-meta-hash`, treścią odpowiedzi i hashem zamrożonego payloadu. Operacja nie zapisuje XML ani PDF w bazie.

API KSeF udostępnia autorytatywną Fakturę jako XML, nie jako gotowy PDF. Po pomyślnej kontroli integralności przeglądarka generuje plik PDF z XML przez przypięty oficjalny `@akmf/ksef-fe-invoice-converter` 1.1.31 z repozytorium CIRFMF; generator jest ładowany dopiero po kliknięciu. Do PDF przekazywany jest numer KSeF, data przetworzenia oraz oficjalny link weryfikacyjny KOD I. Statusy inne niż `accepted` pozostają nieklikalne i nie wykonują requestu.

### KSeF.8D — Production: wspólny kod i ręczny Online LIVE

`KSeF.8D-DOC-CLOSURE-001: PASS`. Dokumentacyjny baseline: `main`, commit `96f033963e80514c0c4f5747c455844c632c910f` (`Display KSeF processing timestamps in Warsaw time`). Zamknięcie obejmuje opis 8D.1–8D.5.1, nie całą gotowość operacyjną 8D. `PRODUCTION CODE: ENABLED / SHARED IMPLEMENTATION`; `PROD MANUAL ONLINE VAT: LIVE VERIFIED / DOCUMENTED`; `AUTOMATIC / UNATTENDED ROLLOUT: OPEN`.

#### Źródła i chronologia

Siła dowodu jest jawna: `CODE VERIFIED` oznacza odczyt lokalnego kodu i historii Git; `LOCAL TEST REPORT` wcześniejsze wyniki lokalnych testów Codexa, nie GitHub CI; `PRESERVED LIVE REPORT` odczyt niesekretnych pól zachowanego raportu; `OPERATOR CONFIRMED` oświadczenie operatora, nie niezależny pomiar; `NOT VERIFIED / NOT RUN` brak danej kontroli. Closure nie wykonuje nowego audytu MF. Oficjalne rewizje wymienione we wcześniejszych etapach są ich historycznym baseline'em, nie deklaracją aktualności na dzień closure.

| Etap | Stan i zakres dowodu |
| --- | --- |
| 8D.1 | `AUDIT COMPLETED / FINDINGS RESOLVED BY 8D.2`. Historyczny wynik `BLOCKED / CURRENT_ARCHITECTURE_ENVIRONMENT_LEAK` pozostaje bez zmiany. Audyt wykazał rozproszone ograniczenia TEST/DEMO i mieszanie `APP_ENV` z wyborem środowiska PDF; wspólny transport, generator i szyfrowanie wystarczały dla PROD. Wynik audytu przytoczony w zadaniu; rozwiązanie findings: `CODE VERIFIED`. |
| 8D.2 | `PASS / SHARED PRODUCTION CODE / PUBLISHED`, commit `b6ea62547263b4924f05e3ccd7937cce24687492`; `CODE VERIFIED`. `LOCAL TEST REPORT`: 2459 testów / 16311 asercji. |
| 8D.3, 10.09.2026, sufiks 155304 | Token: terminalny AUTH `450`, brak access tokena, `InvoiceWrite UNKNOWN`; nieudany przebieg. `PRESERVED LIVE REPORT A`. |
| 8D.3, 10.09.2026, sufiks 184005 | `CERTIFICATE AUTH + INVOICEWRITE + OFFLINE READINESS VERIFIED`: Certificate/XAdES PROD PASS, właściwy kontekst, InvoiceWrite YES, Offline READY. `PRESERVED LIVE REPORT B`. |
| 8D.4 | `MANUAL PATH VERIFIED` przez kontrolę kodu i preflight, nie wysyłkę w tym etapie. Oryginalny `BLOCKED / BACKUP_RECOVERY_PLAN_INCOMPLETE`: `PRESERVED LIVE REPORT C`. Później `RECOVERY BACKUP CONFIRMED BY OPERATOR`; `RESTORE TEST NOT PERFORMED`. Nie było rerunu audytu z PASS. |
| 8D.5, 11.09.2026 | `PASS / ONE REAL VAT INVOICE ONLINE ACCEPTED IN PROD`; `PRESERVED LIVE REPORT D`. Rzeczywista sprzedaż i brak zewnętrznego duplikatu: `OPERATOR CONFIRMED`. |
| 8D.5.1 | `PASS / WARSAW TIME PRESENTATION / CACHE V46 / PUBLISHED`, commit `96f033963e80514c0c4f5747c455844c632c910f`; `CODE VERIFIED`. `LOCAL TEST REPORT`: 2471 testów / 16557 asercji. |

Oba lokalne raporty testowe (8D.2 i 8D.5.1) zgłaszały zero failures/errors/skips oraz Pint, lint i diff-check PASS. Testów PHP nie uruchamiano w closure dokumentacyjnym.

Wszystkie cztery raporty były lokalnie dostępne podczas closure. Poniższe pliki pozostają prywatnie w `storage/app/private/backups/`, bez zmiany nazw i bez dodawania do Git. Odczytano tylko potrzebne bezpieczne pola i obliczono SHA-256; B/C/D są zgodne z sumami wskazanymi w zadaniu. Dla A suma jest lokalnie obliczoną identyfikacją, bez przekazanego niezależnego wzorca.

| Raport | Plik | SHA-256 |
| --- | --- | --- |
| A | `ksef_8d3_prod_readiness_001_report_20260910_155304.json` | `af5bba481a2e4722c5760d00805af0bc105ad0e05c3fb89ce582d4b89df45b90` |
| B | `ksef_8d3_prod_readiness_001_report_20260910_184005.json` | `e82d64f6e7b30d8cf15922c8852ab7f79cd22f0c69e1641babf7e0e6d94c0ad5` |
| C | `ksef_8d4_prod_operational_preflight_001_report_20260910_204502.json` | `90fa66860e159f5c36be37d0b95ae6cc04c1708bfaa0147d7ee9f0b6d4eb02ac` |
| D | `ksef_8d5_prod_k7t_2_2026_001_report_20260911_072726.json` | `676b3863299399cc38b2fcd3d177a5ec531d9a38c367c97559b8f44cd31259d1` |

#### Granice środowisk po 8D.2

`KsefOperationalEnvironmentPolicy::allowedEnvironments()` obejmuje TEST, DEMO i Production, a dispatcher follow-up korzysta z tej listy. `KsefOfflineCertificateRemoteOperationPolicy` dopuszcza istniejącą ścieżkę weryfikacji certyfikatu również dla PROD; dostępność akcji certyfikatów w UI wynika z backendowej polityki. `KsefOfflinePresentationDataExtractor` i `KsefOfflineSubmissionIntegrityService` rozpoznają poprawne historyczne środowisko niezależnie od chwilowej zgody na transport. Presenter PDF nie wymusza już środowiska KSeF przez profil Laravela. Nie utworzono osobnego transportu/generatora/szyfrowania PROD ani migracji.

Pozostaje jeden deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED`; nie ma `KSEF_PRODUCTION_SUBMISSION_ENABLED` ani drugiego odpowiednika. `APP_ENV` oznacza profil Laravela, `KsefSetting.environment` wybiera środowisko nowych operacji i filtr ogólnego presentera, natomiast `submission/issuance/artifact.environment` utrwala środowisko historycznej operacji. Credentiale, runtime access/refresh i certyfikaty są środowiskowe; materiału DEMO nie kopiuje się do PROD. First-send zachowuje oczekiwane środowisko i kontekst, a zmiana konfiguracji anuluje job zamiast przepiąć go na inne środowisko. Follow-up, reconciliation i UPO korzystają ze środowiska zapisanej próby. Obowiązuje exact environment, bez cross-environment fallback.

#### 8D.3: Token failure a Certificate success

Oba raporty 8D.3 mają identyfikator `001`; odróżnia je czas i metoda. Tokenowy AUTH `450` nie jest kodem odrzucenia Faktury. Nie ustalono szczegółowej przyczyny tego auth failure, nie uzyskano access tokena i nie potwierdzono InvoiceWrite. Późniejszy sukces Certificate nie dowodzi naprawienia starego Tokena ani przetestowania nowego.

Udany przebieg użył credentiala PROD `id=2`, Certificate/XAdES EC P-256. Lokalnie odczytany ContextToken miał `typ=ContextToken`, `cit=Nip`, `civ` zgodne z `6282192260`, rolę Owner (`per`, znormalizowane `owner`) i `pec=[]`, bez wyłączenia InvoiceWrite. Puste `rol/pep` nie oznaczały odmowy; dodatkowe query uprawnień było zbędne. Nie jest to reguła „dowolny tekst Owner wystarcza”: znaczenie ma właściwy typ i kontekst oraz brak mającego zastosowanie wyłączenia. Osobnej kryptograficznej weryfikacji podpisu JWT nie wykonano; provenance wynikało ze świeżego PROD challenge/XAdES/poll/redeem z weryfikowanym TLS, nie z samego lokalnego dekodowania.

Osobny preferowany certyfikat Offline PROD `id=3` miał poprawny lokalny materiał i parę kluczy, przeznaczenie Non-Repudiation, zdalny status Active oraz identity match, co dało READY w chwili weryfikacji. Authentication i Offline pozostały odrębne. Raport B obejmuje sześć requestów PROD, po jednym: challenge, XAdES init, auth status, redeem, certificate query i certificate retrieve. Invoice session open/close i invoice POST: `0`. To dowód punktowy, nie bezterminowa gwarancja ważności credentiali ani dowód wystawienia Offline PROD.

#### 8D.4: preflight i recovery

Historyczny blocker `BACKUP_RECOVERY_PLAN_INCOMPLETE` dotyczył braku potwierdzenia zabezpieczonej kopii klucza potrzebnego do odszyfrowania backupu. Później operator potwierdził posiadanie kopii: `RECOVERY_KEY_BACKUP: OPERATOR_CONFIRMED`. `RESTORE TEST: NOT PERFORMED`; nie zweryfikowano automatycznie lokalizacji ani zawartości kopii i nie uruchomiono ponownie 8D.4 z PASS. Ręczne first-send, status, reconciliation, UPO i PDF były dostępne bez workera.

Obserwacja raportów z 10–11.09.2026: aktywne PROD, `automatic_submission=false`, workerów w kontrolowanym przebiegu nie uruchamiano, wcześniejszych failed jobs TEST/DEMO nie ponawiano. Nie jest to ponowny pomiar procesów ani bazy w closure. Flaga automatycznej transmisji nie wyłącza wszystkich automatyzacji: preflight wskazał aktywną regułę `id=9` po zmianie statusu na „Wysłane”, mogącą wywołać URL i wystawić Fakturę, także po błędzie kroku URL. Nie jest to dowód wykonania tej reguły podczas LIVE.

#### 8D.5: jedna rzeczywista Faktura Online PROD

`KSeF.8D.5-PROD-K7T-2-2026-001`, 11.09.2026: Invoice `128`, `K7T 2/2026`, Order `115`, seria `8` (`KSEF-8C7-TECH-LIVE-20260907105305`), data wystawienia `11.09.2026`, netto `0,81 PLN`, VAT `0,19 PLN`, brutto `1,00 PLN`. W chwili LIVE seria była aktywna i włączona do KSeF; raport potwierdza stan zastany i brak jej zmiany w przebiegu. Wcześniejsze wyłączenie serii w 8D.4 nie opisuje tego późniejszego stanu. To nie syntetyczny fixture: rzeczywistą sprzedaż i brak duplikatu poza OMS potwierdził operator, bez niezależnej kontroli zewnętrznych systemów.

Submission `31`, attempt `1`, environment `production`, status `Accepted / 200`, invoicing mode `Online`, numer KSeF `6282192260-20260911-428158C00000-96`. Dokładny payload miał `1625 bytes`, hash SHA-256 Base64 `pr4XUqIVNH78G8AJxnHkAPv1hZcNzyuAAihLXDJLiF8=`. Według raportu FA(3), lokalne XSD i integralność przeszły PASS. Request zawierał `offlineMode=false`, bez `hashOfCorrectedInvoice`; wykonano jeden invoice POST, bez drugiej próby, emisji Offline i artefaktu korekty technicznej. Nie wywoływano celowo błędu 450 ani nowej fikcyjnej transakcji. Treść, daty, numer i kwoty dokumentu pozostały bez zmian; finalizacja zmieniła jego `finalized_at` oraz `updated_at`.

Ruch **wyłącznie przebiegu 8D.5**, według raportu D: siedem requestów PROD, każdy po jednym:

| Metoda | Endpoint |
| --- | --- |
| POST | `/auth/token/refresh` |
| GET | `/security/public-key-certificates` |
| POST | `/sessions/online` |
| POST | `/sessions/online/{session}/invoices` |
| POST | `/sessions/online/{session}/close` |
| GET | `/sessions/{session}/invoices/{reference}` |
| GET | `/sessions/{session}/invoices/ksef/{number}/upo` |

Pełne auth, retry invoice POST, reconciliation, TEST/DEMO, NBP, Latarnia, QR HTTP i Accepted XML GET: `0`. Refresh nie był pełnym XAdES auth; sześciu requestów raportu B nie sumuje się z tym przebiegiem.

UPO `28` należy do submissionu `31`: hash, XSD, tożsamość i obecność podpisu PASS. Kryptograficznej weryfikacji podpisu UPO nie wykonano. Historyczny PDF `storage/app/private/invoices/128/invoice-v45-ksef-production.pdf` zawierał właściwy numer i kwoty, numer KSeF oraz jeden KOD I z hostem `qr.ksef.mf.gov.pl`, bez KODU II i oznaczeń TEST/DEMO. Kontrola wizualna PASS; skan telefonem i niezależne pobranie Accepted XML: `NOT VERIFIED / NOT RUN`. Nie potwierdzono hasha względem niezależnie pobranego zdalnego XML.

Raport wskazuje prywatny backup `storage/app/private/backups/database_before_ksef_8d5_k7t_2_2026_001_20260911_072726.sqlite`, zgłoszony SHA-256 `c32186276ad2a397e12132fa6f8a0a485d50016ad47db6b4bbcf40e46cc81844`. W closure nie otwierano backupu ani nie weryfikowano ponownie jego hasha. Po możliwym invoice POST nie odtwarza się automatycznie starej bazy i nie wykonuje blind resend; najpierw rozstrzyga się istniejącą próbę. Potwierdzenie posiadania kopii klucza nie jest testem odtworzenia.

#### 8D.5.1: prezentacja czasu bez zmiany instantu

PDF z LIVE pokazywał `11.09.2026 07:27:30` UTC bez oznaczenia strefy. Opublikowana poprawka w `KsefPdfDocumentPresenter` tworzy lokalną immutable reprezentację przez `setTimezone('Europe/Warsaw')`: dla tego instantu `processed_at = 11.09.2026 09:27:30`, `processed_at_timezone = Europe/Warsaw, UTC+02:00`. Wspólny partial pokazuje strefę w drugim wierszu. Offset jest wyliczany dla daty, nie dodawany na stałe jako +2 h.

Bez zmian pozostały UTC storage, `KsefUtcInstantCast`, `acquisition_date`, `P_1`, XML, hashe, QR, numer KSeF, status i UPO. VAT i KOR używają cache `v46` z zachowaniem sufiksu środowiska; Pro forma `v35` bez zmian. Lokalny raport testów obejmował lato, zimę, zmianę dnia, oba przejścia DST, wejściowy offset, mikrosekundy i brak mutacji modelu. Obejrzano PDF-y syntetycznych VAT/KOR, bez odczytu operatorowej DB i bez regeneracji rzeczywistego PDF Invoice `128`. Nowy kod wybierze `v46` przy następnej normalnej generacji; historycznej ścieżki `v45` nie zastępuje się w dowodzie LIVE ścieżką rzekomo istniejącego `v46`.

#### 8D.6.1-8D.6.2A.1: recovery przerwanych prób i granica transakcji

`KSeF.8D.6.2A-DOC-CLOSURE-001: PASS`. Zamknięcie dokumentacyjne obejmuje audyt kolejek i automatyzacji 8D.6.1, recovery core 8D.6.2A, hardening granicy transakcji 8D.6.2A.1 oraz migrację 089 zweryfikowaną kolejno na kopii i w bazie operatora. Nie oznacza uruchomienia recovery, workerów, schedulera ani automatycznej transmisji.

Dowody mają trzy rozłączne poziomy. `CODE VERIFIED` oznacza odczyt kodu i historii Git. `LOCAL TEST REPORT` oznacza zachowany wynik testów lokalnych z etapu implementacyjnego, a nie test uruchomiony w tym closure ani CI. `PRESERVED OPERATIONAL REPORT` oznacza odczyt bezpiecznych pól istniejącego raportu JSON; pliki bazy i kopie nie były w tym closure otwierane ani ponownie hashowane.

| Etap | Wynik i granica dowodu |
| --- | --- |
| 8D.6.1 | `AUDIT COMPLETED WITH FINDINGS`. Punktowy audyt kolejek, schedulera i Automation nie uruchamiał workerów, jobów ani recovery. Wykrył lukę dla przerwanej próby w `Preparing`/`SessionOpened`, gdy brakowało trwałego rozróżnienia, czy invoice POST mógł już nastąpić. |
| 8D.6.2A | `RECOVERY CORE IMPLEMENTED / CODE PUBLISHED`, commit `7a4844596dc0fe366e133e218d4ba632e64e88e8`. Dodano wersjonowany protokół wykonania, lease, jednokierunkowy fence invoice POST, politykę recovery i komendę inspekcji pojedynczego submissionu. |
| 8D.6.2A.1 | `TRANSACTION BOUNDARY ENFORCED / CODE PUBLISHED`, commit `b43eb8ba28722492e1b3ed42c412302152f93f07`. Wspólny transport odrzuca wykonanie wewnątrz aktywnej transakcji bazy. |
| Migration 089 dry-run copy | `PASS`. Migrację wykonano dokładnie raz na odizolowanej kopii; źródło pozostawało read-only. |
| Migration 089 operator | `OPERATOR EXECUTED / RAN / BATCH 55`. Migrację wykonał operator poza closure dokumentacyjnym. |
| Migration 089 post-verify | `PASS / SCHEMA AND DATA PRESERVATION VERIFIED`. Kontrola była read-only i potwierdziła schemat, zachowanie danych oraz brak backfillu historycznych prób. |

Zachowane raporty dowodowe:

| Zakres | Plik w `storage/app/private/backups/` | SHA-256 |
| --- | --- | --- |
| 8D.6.1 queue/automation audit | `ksef_8d6_1_queue_automation_audit_001_report_20260911_102504Z.json` | `5c8991a813f2e489e2b3a22fb10a5eb7e81bb4d934fd5bfe7e3b877041886006` |
| Migration 089 dry-run copy | `ksef_8d6_2a_migration_089_dryrun_copy_001_report_20260914_103534.json` | `fe984f54e340ada89ea417e99b6a55236ad939bee718fa0baca0dd98d146aefe` |
| Migration 089 post-verify | `ksef_8d6_2a_migration_089_post_verify_001_report_20260914_140108.json` | `ff326b6e6b906493660b96ff1bc0ec583864de841a46f0484cdcc807f600766a` |

Raporty zawierają również metadane dowodowe kopii, bez ponownego dostępu do ich zawartości w tym closure: snapshot `database_before_ksef_8d6_2a_089_dryrun_20260914_103534.sqlite` sprzed dry-run miał SHA-256 `3412e03a1fd31ceedf7d2f1f15bdd306dbe08e210c1a75745edbd3f3a26d6926`; bezpośrednia kopia operatora `database_before_089_manual_20260914_123002_981.sqlite` sprzed migracji (`IMMEDIATE_PRE_MIGRATION_BACKUP`) miała SHA-256 `7b6c0a65826b75d3c310cdab17bf6f48829f3e547c4e1c60e5ffecac7c7619b7`; plik operatora `database/database.sqlite` w chwili post-verify miał SHA-256 `7152c558d1c3fb4f76bbf0eff45d5c1dd52848e4c67fb140fcd4332b2ab92c57`.

Migracja `2026_08_13_089000_add_ksef_submission_execution_protocol.php` ma zweryfikowany blob Git `9003cde76e120672084d8aa223991877a63efa46` i dodaje sześć nullable pól bez defaultów i bez backfillu:

| Pole | Kontrakt |
| --- | --- |
| `execution_protocol_version` | Nowe próby protokołu recovery otrzymują jawnie `1`. Historyczne `NULL` oznacza legacy/unknown, nigdy domyślne v1. |
| `execution_owner` | Identyfikator właściciela bieżącego wykonania; jest ukryty w serializacji modelu. |
| `execution_expires_at` | Lease wykonania, domyślnie 300 sekund i odnawiany podczas pracy. Nie jest heartbeat'em procesu ani dowodem, że proces żyje lub umarł. |
| `invoice_post_started_at` | Trwały, jednokierunkowy fence ustawiany przed invoice POST. Oznacza „POST mógł nastąpić”, a nie „MF otrzymało dokument”. |
| `recovery_code` | Bezpieczna lokalna klasyfikacja decyzji recovery. |
| `recovered_at` | Lokalny znacznik czasu zastosowania recovery. |

Nowe timestampy zachowują instants UTC przez istniejący cast. `execution_owner` i zamrożony payload nie są publiczną diagnostyką.

##### Protokół wykonania i CAS

Nowa próba dostaje `execution_protocol_version=1`. Wykonawca atomowo claimuje rekord, otrzymuje właściciela i lease, a kolejne kroki odnawiają lease. Zapisy używają compare-and-swap względem zaobserwowanego surowego stanu rekordu; warunkowy update musi zmienić dokładnie jeden wiersz. Gotowość requestu, ciphertext i wymagane metadane są sprawdzane przed zużyciem prawa do POST.

Bezpośrednio przed HTTP `invoice_post_started_at` jest utrwalany jako fence. Dla tej próby nie powstaje drugie prawo do invoice POST. Nie jest to jednak rozproszona gwarancja exactly-once: brak numeru referencyjnego nie dowodzi, że nic nie wysłano, wygaśnięcie lease nie dowodzi śmierci procesu, a recovery nie odwraca skutków zewnętrznych. Przed fence bezpieczne recovery unieważnia poprzednie wykonanie tak, aby spóźniony worker nie mógł wykonać POST. Po fence próba pozostaje `Uncertain` i podlega istniejącemu reconciliation, bez blind resend i bez czyszczenia fence.

Polityka recovery pojedynczego submissionu:

| Stan zastany | Decyzja |
| --- | --- |
| Lease aktywny / próba niestara | Brak mutacji recovery. |
| Protokół v1, stale, przed `invoice_post_started_at` | Zwolnienie właściciela, `TechnicalFailed` i bezpieczny powód przerwania przed POST. |
| Protokół v1, `invoice_post_started_at` ustawione | `Uncertain`; zachowanie sesji, hasha i fence oraz przekazanie do istniejącego reconciliation. |
| Legacy, unknown albo niespójny stan | `REVIEW_REQUIRED`; bez automatycznego odblokowania lub reinterpretacji. |
| `Submitted` / `Processing` | Istniejący follow-up statusu, nie recovery first-send. |
| `Accepted` | Brak zmiany historii; ewentualny istniejący follow-up UPO. |
| `Rejected` / `TechnicalFailed` | Brak resetu i brak reinterpretacji wyniku. |

Komenda operatora działa wyłącznie dla jednego submissionu:

```text
php artisan ksef:recover-submission --submission=<id>
php artisan ksef:recover-submission --submission=<id> --apply
```

Pierwsza forma tylko ponownie ocenia rekord i niczego nie zapisuje. `--apply` jeszcze raz ocenia stan i stosuje wyłącznie dozwoloną lokalną decyzję; nie tworzy nowej próby, nie wykonuje invoice POST, nie uruchamia HTTP i nie dispatchuje transportu. Dla `Uncertain` zapisuje plan istniejącego reconciliation, który dopiero później może zostać obsłużony przez normalny worker/scheduler. Nie ma `--all`, masowego recovery ani przełącznika omijającego integralność. Sam exit code komendy nie dowodzi, że dokument został naprawiony lub zaakceptowany; operator musi odczytać `decision` i `applied`. Zaakceptowanego submissionu `31` nie należy używać jako przykładu `--apply`.

##### Granica transakcji

Transport faktury musi działać poza aktywną transakcją na połączeniu submissionu. Guard sprawdza zarówno Laravel `transactionLevel()`, jak i `inTransaction()` writer PDO: przed claim/auth na wspólnym wejściu transportu, wewnątrz `consumePost()` przed utrwaleniem fence oraz bezpośrednio przed `sendInvoice()`. Rekord jest przeładowywany przez write PDO na tym samym połączeniu.

Kod `ksef_submission_transaction_active` oznacza wykrytą aktywną transakcję, a `ksef_submission_transaction_state_unavailable` brak bezpiecznej możliwości ustalenia jej stanu. Błąd na wejściu oznacza brak claimu i brak HTTP. Późny błąd gwarantuje brak invoice POST i brak cleanup HTTP, choć wcześniejsze auth, pobranie klucza lub otwarcie sesji mogły już nastąpić. Guard nie czyści fence, nie wykonuje commit/rollback transakcji callera, nie omija stanu drugim PDO i nie ma obejścia testowego. Krótkie transakcje PREPARE/recovery są dozwolone, ale caller musi zatwierdzić PREPARE przed wejściem w transport. Udokumentowany przypadek błędu transakcyjnego był syntetycznym testem, nie zdarzeniem w bazie operatora.

##### Migracja i testy regresyjne

Dry-run wykonał migrację raz z exit code `0` i batch `55`; tabela `ksef_invoice_submissions` wzrosła z 40 do 46 kolumn. Wszystkie 31 historycznych submissionów zachowało dane, a sześć nowych pól pozostało `NULL`. Zachowano 3 foreign keys, 4 indeksy, 28 Accepted i 28 UPO; nie pojawiły się triggery, widoki ani tabele tymczasowe. Pięć wcześniejszych nieudanych inicjalizacji runnera zakończyło się przed uruchomieniem migratora i nie oznacza pięciu wykonań migracji. Dokładne czasy i pełny output tych prób nie zostały utrwalone z powodu problemu cleanup otwartego pliku.

Read-only post-verify potwierdził wzrost tabeli migracji z 81 do 82 rekordów, migrację 089 dokładnie raz w batch `55`, zgodność 43 porównanych tabel oraz brak nieoczekiwanych różnic schematu i danych. SQLite zgłosił integralność OK i 0 naruszeń foreign keys. W chwili audytu nie było submissionów utworzonych po migracji. Historyczne 31 wierszy nadal miało sześć nowych pól `NULL`, zgodnie z kontraktem bez backfillu.

Chroniony łańcuch PROD pozostał niezmieniony: Invoice `128` (`K7T 2/2026`), submission `31`, environment `production`, tryb Online, `Accepted`, numer KSeF i UPO `28` wraz z wartościami, hashem i rozmiarem. Jest to dowód lokalnego zachowania danych, nie nowe zaakceptowanie przez MF ani ponowna walidacja XML, podpisu lub UPO.

`LOCAL TEST REPORT` dla 8D.6.2A: 2531 testów / 17083 asercji. Dla 8D.6.2A.1: 2587 testów / 17766 asercji. Oba raporty wskazują 0 failures/errors/skips oraz Pint, PHP lint i diff-check PASS. Zakres obejmował odrębne procesy na współdzielonym SQLite, hard crash, race i late worker, awarie zapisu, widoczność fence, Laravel/PDO/nested transaction, zachowanie transakcji callera, VAT/KOR/Offline/technical oraz fake TEST/DEMO/PROD. Testy używają `UsesAutocommitDatabase` zamiast bypassu guardu i obejmują `APP_DEBUG=true` oraz `false`. Raportowane środowisko: Laravel 12.63.0, PHP 8.2.12, SQLite 3.39.2. Nie jest to CI, test serwera ani test docelowej bazy innej niż SQLite; w tym closure testów nie uruchamiano.

```text
KSeF.8D.6.1:
AUDIT COMPLETED WITH FINDINGS

KSeF.8D.6.2A:
RECOVERY CORE IMPLEMENTED / CODE PUBLISHED

KSeF.8D.6.2A.1:
TRANSACTION BOUNDARY ENFORCED / CODE PUBLISHED

MIGRATION 089 COPY TEST:
PASS

MIGRATION 089 OPERATOR:
OPERATOR EXECUTED / RAN / BATCH 55

MIGRATION 089 POST-VERIFY:
PASS / SCHEMA AND DATA PRESERVATION VERIFIED

HISTORICAL SUBMISSIONS:
31 ROWS / SIX NEW FIELDS NULL / NO BACKFILL

RECOVERY CORE + OPERATOR SCHEMA:
IMPLEMENTED / VERIFIED / DOCUMENTED

REAL RECOVERY EXECUTION:
NOT RUN IN DOCUMENTED STAGES

UNATTENDED SERVER ROLLOUT:
OPEN
```

#### Zakres nadal otwarty po 8D.6.2A.1

`PRODUCTION AUTOMATIC FIRST-SEND: IMPLEMENTED / NOT ACTIVATED IN REPORTED INSTALLATION`. Wspólna polityka obsługuje PROD, dedykowany first-send działa na `ksef_submit` / `ksef-submit`, odczytowy follow-up na `database` / `ksef`. Pozostają snapshot środowiska/kontekstu, ponowna kwalifikacja, `tries=1`, brak blind retry invoice POST i brak hurtowego backfillu historii. Pro forma i KOR nie są dodane do automatic first-send. `automatic_submission=false` oraz brak workera nie blokują ręcznej ścieżki.

Po 8D.5 raport zachował dwa nieuruchomione joby: `530` na `automation` (`EvaluateAutomationEventJob`) i `531` na `ksef` (`KsefSubmissionFollowUpJob`), oba `attempts=0`, oraz dwa locki. Nie oznacza to kolejnych invoice POST. W closure nie sprawdzano ich ponownie, nie wykonywano ani nie czyszczono; nie deklaruje się też, że każdy z tych jobów na pewno nie ma skutków ubocznych.

Punktowy audyt 8D.6.1 z 11.09.2026 odnotował kolejki ready/delayed/reserved `0`, aktywne/wygasłe locki `0/0`, 27 failed jobs (`integrations=4`, `automation=3`, `ksef-submit=20`), w tym 3 błędy `database is locked`, aktywną regułę Automation `9` i nieaktywną `11`. W chwili pomiaru nie wykryto workera, schedulera ani odpowiadającego zadania Windows. To stan historyczny raportu, nie aktualny monitoring instalacji.

Nadal otwarte są: docelowy deployment serwerowy, wybór systemu operacyjnego, bazy i nadzoru usług, testy na wybranej bazie, uruchomienie workerów i całego schedulera OMS, monitoring backlogu oraz `Uncertain`, decyzja o `automatic_submission`, przegląd Automation i failed jobs oraz gotowość wszystkich ścieżek Offline na serwerze. Nie wybrano ani nie potwierdzono SEOHOST, MariaDB, Linux, supervisor, systemd lub Docker. Brak PCNTL w lokalnym Windows/XAMPP jest ograniczeniem platformy lokalnej, a nie dowodem wadliwości recovery core. Implementacja nie wykonuje automatycznego wykrywania i recovery wszystkich starych prób.

Latarnia pozostaje exact-environment: TEST do TEST, PROD do PROD, DEMO bez fallbacku. W raporcie 8D.4 sync PROD był wyłączony i brakowało lokalnego coverage PROD. Nie blokowało to zwykłego ręcznego Online, ale nie potwierdza gotowości planowanej niedostępności/awarii. Certyfikat Offline READY nie dowodzi wykonania Offline PROD. W zakresie tego LIVE nie sprawdzono rzeczywistej KOR PROD, Offline24 i innych procedur Offline PROD, korekty technicznej PROD, batch, automatyki bez nadzoru ani celowych 440/450/21166/21167 lub Uncertain PROD. Wcześniejsze dowody kodowe/fake i LIVE TEST/DEMO pozostają przypisane do swoich środowisk; nie wymagano celowych błędów PROD dla closure.

`PRODUCTION MANUAL ONLINE: LIVE VERIFIED FOR K7T 2/2026`; `PDF TIMESTAMP: EUROPE/WARSAW / DOCUMENTED`; `RECOVERY CORE + OPERATOR SCHEMA: IMPLEMENTED / VERIFIED / DOCUMENTED`; `UNATTENDED OPERATIONS: OPEN`. Nie jest to certyfikacja MF ani zamknięcie całego 8D. W bieżącym docs-only: `TRACKED CODE CHANGES: 0`, `OPERATOR DB ACCESS / WRITES: 0 / 0`, `RUNTIME HTTP: 0`; bez generowania PDF, nowych transmisji, recovery, uruchamiania workerów i zmian konfiguracji.

### Historyczna granica KSeF.2A

Historyczny zakres KSeF.2A nie tworzył poniższych elementów; ich późniejsze wdrożenia opisano w odpowiednich etapach powyżej, a stan Production w sekcji 8D:

```text
ksef_submissions
pól ksef_* w dokumentach
XML FA(3)
UPO
statusów KSeF
kodów QR KSeF
sesji fakturowania
wysyłki dokumentów
XAdES
trybu offline
```

Architektura ma jednak zapewnić:

- strukturalne dane sprzedawcy,
- strukturalne dane nabywcy,
- strukturalne pozycje,
- netto, VAT i brutto,
- jednostki,
- rabaty,
- walutę,
- daty,
- płatność,
- korekty jako osobne dokumenty,
- niezmienne snapshoty,
- zdarzenia cyklu życia bez kopii poprzednich stanów.

Po historycznym 2A kolejne etapy wdrożyły mapowanie FA(3), sesje, transmisję i obsługę odpowiedzi dokumentowych. Samo uwierzytelnianie 2A nie finalizuje dokumentów i nie zmienia domeny cyklu życia Faktur ani Korekt. Szczegóły protokołu są w oficjalnych źródłach MF: [OpenAPI KSeF](https://github.com/CIRFMF/ksef-api/blob/main/open-api.json), [uwierzytelnianie](https://github.com/CIRFMF/ksef-docs/blob/main/uwierzytelnianie.md) i [historia zmian API](https://github.com/CIRFMF/ksef-api/blob/main/api-changelog.md).

---

# 27. Walidacja i serwisy domenowe

Kontroler nie powinien zawierać całej logiki biznesowej.

Docelowy podział:

## Form Request

Odpowiada za:

- format danych wejściowych,
- wymagane pola,
- podstawową walidację.

## Serwis domenowy

Odpowiada za:

- reguły serii,
- wybór aktywnej serii właściwego typu,
- aktywację,
- generowanie numeru,
- snapshoty,
- obliczenia,
- wystawienie dokumentu,
- korekty,
- zdarzenia cyklu życia bez kopii poprzednich stanów.

## Kontroler

Odpowiada za:

- przyjęcie żądania,
- wywołanie serwisu,
- odpowiedź lub redirect,
- komunikaty UI.

---

# 28. Transakcje

Transakcje są wymagane dla:

- wyboru i blokowania serii podczas wystawiania,
- nadawania numeru,
- wystawiania dokumentu,
- zapisu pozycji,
- zapisu snapshotów,
- zapisu zdarzeń,
- wystawienia korekty,
- anulowania dokumentu.

Nie należy wykonywać częściowego zapisu dokumentu poza transakcją.

---

# 29. Usuwanie i integralność danych

## Serie

- nieużywaną serię można usunąć po walidacji,
- używana seria powinna być dezaktywowana,
- użyta seria i bieżące dokumenty muszą zachować integralność.

## Faktury

- operacja `Usuń fakturę` jest dostępna ręcznie z karty zamówienia oraz ekranu edycji dokumentu,
- wymaga potwierdzenia z dokładnym numerem dokumentu i oczekiwanej wersji blokady,
- obejmuje wyłącznie wystawioną Fakturę VAT bez Korekt i ze spójnym slotem dokumentu,
- transakcyjnie usuwa dokument, pozycje i slot oraz zapisuje audyt w `order_events`,
- usuwa prywatny cache PDF po zatwierdzeniu transakcji,
- po dozwolonym usunięciu zamówienie ponownie może otrzymać Fakturę VAT,
- Pro forma zastąpiona dokładnie przez usuwaną Fakturę zostaje w tej samej transakcji odblokowana i ponownie udostępniona w zamówieniu bez zmiany numeru ani snapshotu.

Dozwolone usunięcie nie tworzy ogólnej puli zwolnionych numerów i nie uzupełnia wewnętrznych luk. Serwis transakcyjnie cofa wyłącznie wolny koniec licznika do większej z wartości: najwyższa pozostała sekwencja albo `protected_floor_sequence_number`, a zmianę zapisuje w `invoice_number_counter_adjustments`. Usunięta Faktura nie pozostaje jako wyszarzony dokument. Przyszłe blokady KSeF zostaną dodane wraz z polami i procesami tej integracji.

## Produkty

- `order_items.product_id` używa `nullOnDelete`,
- `invoice_items.product_id` nie ma klucza obcego,
- snapshot pozycji zamówienia i dokumentu pozostaje niezmienny.

## Domyślna seria korekt

- `nullOnDelete`.

---

# 30. Uprawnienia

Aktualnie aplikacja nie posiada kompletnej warstwy autoryzacji dokumentów.

Przed udostępnieniem wieloużytkownikowym należy dodać:

- uwierzytelnianie,
- polityki,
- kontrolę pobierania PDF,
- kontrolę edycji i usuwania,
- audyt użytkownika wykonującego operację.

W pierwszych etapach lokalnych brak autoryzacji nie może prowadzić do projektowania publicznych URL-i do plików.

---

# 31. Interfejs

UI pozostaje oparte na:

- Blade,
- Bootstrap 5,
- Bootstrap Icons,
- istniejących komponentach.

Lista serii:

```text
Rodzaj
Nazwa
Format
Pokaż/ukryj
Edytuj
Usuń
```

Wymagane elementy:

- panel informacyjny,
- przycisk `Nowa seria numeracji`,
- pusta, nieklikalna gwiazdka serii systemowej,
- ikona aktywności,
- edycja,
- bezpieczne usuwanie,
- paginacja.

Lista jest dostępna pod `/invoices/settings/series` i wyświetla 10 rekordów na stronę. Pusta gwiazdka jest nieklikalnym oznaczeniem `is_system = true`; nie reprezentuje serii domyślnej. Serie systemowe nie udostępniają formularza ukrywania ani usuwania. W Etapie 1B kontrolki tworzenia i edycji były nieaktywne.

W Etapie 1C.1 kontrolki tworzenia i edycji są aktywne. Jeden modal ładuje partial typu przez AJAX, obsługuje stan ładowania i błędy, a zapisuje dane standardowym formularzem Laravel. Błąd walidacji wraca na listę, odtwarza tryb create/edit i ponownie otwiera modal z old input.

Etap 1C.2 rozbudowuje wyłącznie partial typu `invoice`. Strukturalne dane sprzedawcy i konfiguracja faktury są kolumnami `invoice_series`, bez dodatkowej tabeli profili sprzedawcy. Serwis zapisuje jawnie dozwolone pola, zachowując ochronę `is_system`, `system_key`, typu i aktywności serii systemowej. Logo jest prywatnym plikiem dysku `local`, a jego wymiana usuwa poprzedni plik dopiero po udanym zapisie modelu.

Etap 1C.3 rozbudowuje partial typu `correction`. Ustawienia korekty są osobnymi kolumnami `invoice_series`, a źródła daty sprzedaży, wystawiającego i sposobu płatności są zamkniętymi enumami. Serwis używa osobnej jawnej listy dozwolonych pól korekty. Nie pozwala ona zapisywać danych prawnych sprzedawcy, banku, logo ani ustawień dostawy właściwych dla faktury.

Etap 1C.4 rozbudowuje partial typu `proforma`. Faktura i Pro forma współdzielą partiale danych sprzedawcy, banku, wystawienia, VAT, dostawy, płatności, dat, informacji, wydruku i logo. Serwis posiada wspólną jawną listę pól dokumentów handlowych oraz osobne listy pól właściwych tylko Fakturze albo Pro formie. Dla końcowego typu `proforma` zawsze zeruje `default_correction_series_id`. Logo jest obsługiwane dla `invoice` i `proforma`, ale nie dla `correction`.

Pro forma posiada własną serię i numer, nie zużywa numeracji faktury VAT, nie jest fakturą VAT, nie trafia do rejestru VAT ani JPK jako faktura sprzedaży i nie jest wysyłana do KSeF. Dane sprzedawcy należą bezpośrednio do serii, bez `seller_profiles` i `company_settings`. Token `[uwagi_sprzedawcy]` pozostaje nierozwiązany do czasu przyszłego utworzenia snapshotu dokumentu.

Dane prawne korekty pochodzą ze snapshotu dokumentu źródłowego. Korekta pozostaje osobnym dokumentem powiązanym z fakturą źródłową i zapisuje wartości przed zmianą, po zmianie oraz różnicę. Jedna Korekta zamówienia ma jeden bieżący stan; późniejsza edycja nadpisuje go bez tworzenia kolejnego dokumentu. Domyślny powód jest podpowiedzią, a finalna wartość jest snapshotem dokumentu. Pola warunkowe formularza są sterowane w JavaScript, lecz te same zależności egzekwuje Form Request. Zmiana typu serii własnej zachowuje nieaktywne ustawienia poprzedniego typu, a nowy typ otrzymuje bezpieczne wartości domyślne.

Zależności formularza VAT, dostawy i płatności są prezentacyjne po stronie JavaScript, ale wszystkie reguły warunkowe są ponownie egzekwowane przez Form Request. `additional_information_template` przechowuje nierozwiązany token `[uwagi_sprzedawcy]`; renderowanie i snapshot należą do przyszłego serwisu wystawiania dokumentu.

Nie należy dodawać SPA ani frameworka frontendowego tylko dla modułu faktur.

---

# 32. Testowanie architektury

## 32.1. Testy jednostkowe

Dla:

- enumów,
- castów,
- relacji,
- walidacji formatów,
- kalkulatora,
- renderowania zmiennych szablonu,
- generatora numerów.

## 32.2. Testy feature

Dla:

- listy serii,
- tworzenia i edycji,
- aktywowania,
- ustawiania domyślnej,
- bezpiecznego usuwania,
- wystawiania dokumentu,
- korekt,
- pobierania PDF.

## 32.3. Testy integracyjne

Dla:

- migracji,
- kluczy obcych,
- transakcji,
- współbieżnej numeracji,
- kolejki e-mail,
- storage.

Testy muszą działać na SQLite `:memory:`.

Przed wdrożeniem produkcyjnym należy sprawdzić różnice względem docelowej bazy MySQL/MariaDB, szczególnie dla:

- JSON,
- indeksów,
- unikalności,
- blokad,
- kluczy obcych.

---

# 33. Etapy wdrożenia

## Etap 0

Audyt bez zmian.

Status: zakończony.

## Etap 1A

- migracja `invoice_series`,
- model,
- enum typu dokumentu,
- enum resetowania,
- casty,
- self-FK,
- testy.

Bez CRUD i UI.

## Etap 1B

- lista serii,
- paginacja po 10 rekordów,
- aktywność,
- oznaczenie i ochrona serii systemowych,
- bezpieczne usuwanie,
- jawne trasy listy, aktywności i usuwania bez pełnego CRUD,
- nieaktywne kontrolki tworzenia i edycji.

## Etap 1C.1

- jeden modal tworzenia i edycji,
- partiale typu ładowane przez AJAX,
- zapis POST/PATCH,
- nazwa,
- typ,
- format,
- reset,
- rok fiskalny,
- aktywność serii własnych,
- techniczne oznaczenie serii systemowej tylko do odczytu.

Etap 1C.2 rozbudował formularz Faktury, Etap 1C.3 formularz Korekty, a Etap 1C.4 formularz Pro formy.

## Etap 1C.2

- rozbudowane ustawienia serii typu Faktura,
- strukturalne dane sprzedawcy bez `seller_profiles` i `company_settings`,
- ustawienia VAT, dostawy, płatności, dat, pozycji i wydruku,
- wybór serii korekt,
- szablon informacji z nierozwiązanym tokenem `[uwagi_sprzedawcy]`,
- prywatne logo serii.

Ten etap nie tworzy tabel dokumentów, nie wystawia faktur i nie generuje PDF. Korekta została rozbudowana w Etapie 1C.3, a Pro forma w Etapie 1C.4.

## Etap 1C.3

- rozbudowane ustawienia serii typu Korekta,
- zamknięte enumy źródła daty sprzedaży, wystawiającego i sposobu płatności,
- warunkowe pola wystawiającego i stałego sposobu płatności,
- ustawienia pozycji, nagłówka i przyszłego wydruku,
- szablon informacji z nierozwiązanym tokenem `[uwagi_sprzedawcy]`,
- jawna lista dozwolonych pól oddzielona od konfiguracji Faktury,
- dane prawne sprzedawcy dziedziczone w przyszłości ze snapshotu dokumentu źródłowego.

Etap nie tworzy dokumentów, liczników, PDF, JPK ani KSeF. Pro forma została rozbudowana w Etapie 1C.4.

## Etap 1C.4

- rozbudowane ustawienia serii typu Pro forma,
- współdzielone z Fakturą dane sprzedawcy, banku, wystawienia, VAT, dostawy, płatności, dat, informacji i wydruku,
- prywatne logo serii,
- osobne ustawienie identyfikatora płatności,
- jawna lista dozwolonych pól końcowego typu `proforma`,
- bezwarunkowe `default_correction_series_id = null` dla Pro formy,
- bezpieczna zmiana typu dodatkowej serii.

Etap nie tworzy dokumentów, liczników, PDF, JPK ani KSeF. Pro forma posiada własną serię i numer, ale nie jest fakturą VAT i nie trafia do rejestru VAT ani JPK jako faktura sprzedaży. Nie ma paragonów.

## Etap 1D

- VAT,
- ID produktu,
- GTU,
- procedury JPK.

## Etap 1E

- wysyłka,
- waluty,
- płatności,
- daty.

### Etap 1E.1 — lokalny katalog walut

Tabela `currencies` jest niezależnym katalogiem referencyjnym bez klucza liczbowego, timestampów oraz flag `is_active`, `is_system` i `last_seen_at`. Jej kluczem głównym jest trzyliterowy `code`; przechowuje także `name` i nullable `nbp_table`. Istniejące pola walutowe zamówień, pozycji, serii i dokumentów nie mają kluczy obcych do katalogu, dzięki czemu dane historyczne pozostają czytelne również po zmianie listy źródłowej.

`App\Support\CurrencyCatalog` odpowiada za normalizację, walidację, kolejność `PLN` jako pierwszej pozycji i alfabetyczne uporządkowanie pozostałych kodów. `App\Rules\ValidCurrencyCode` udostępnia tę samą regułę warstwie HTTP. Nie tworzy się równoległych list walut w kontrolerach ani Blade.

`NbpCurrencySyncService` pobiera przez klienta HTTP Laravel tabele A i B z adresu HTTPS zdefiniowanego w `config/nbp.php`. Waliduje komplet obu odpowiedzi przed transakcją, wykrywa konflikty kodów, wykonuje upsert bez usuwania rekordów i zawsze zachowuje `PLN`. Komenda `currencies:sync-nbp` jest cienkim ręcznym wejściem; nie istnieje scheduler ani automatyczne odświeżanie. Testy używają `Http::fake()` i nie łączą się z NBP.

`OrderCurrencyService` egzekwuje jedną walutę zamówienia i jego pozycji. Techniczne `PLN` nowego zamówienia nie blokuje przyjęcia waluty pierwszej pozycji. Zmiana następuje pod blokadą zamówienia, w tej samej transakcji co zapis pozycji, przeliczenie sumy i zdarzenie, wyłącznie gdy nie istnieją pozycje, niezerowe wartości pieniężne, Faktury VAT, Pro formy, sloty dokumentów, przesyłki ani próby utworzenia przesyłki. Błąd wycofuje walutę, pozycję, sumę i zdarzenie. Po pierwszej pozycji waluta jest ustalona, a kolejne pozycje muszą być zgodne. Historyczny kod nieobecny w katalogu może zostać zachowany bez zmiany, lecz nie jest dostępny dla nowych wartości. `OrderTotalService` wykrywa mieszane waluty i korzysta z `InvoiceDecimalCalculator` do obliczeń pozycji, kosztu dostawy, sumy oraz pozostałej należności bez `float`. Stan AJAX zawiera `fields.currency`, dzięki czemu istniejąca synchronizacja formularzy aktualizuje bieżącą i domyślną wartość selectów przed ich resetem; nie wymaga to osobnego mechanizmu JavaScript ani połączeń HTTP zewnętrznych.

Sam Etap 1E.1 nie dodawał kursów walut, przewalutowań, automatycznej synchronizacji, kluczy obcych do katalogu ani zmian w snapshotach i PDF wystawionych dokumentów.

### Etap 1E.2 — historyczny kurs średni NBP Faktury VAT

`InvoiceExchangeRateReferenceDateResolver` wyznacza datę odniesienia i wersjonowaną regułę z finalnych `issue_date` oraz `sale_date`; nie korzysta z czasu bieżącego ani fallbacku do daty zamówienia. `NbpExchangeRateClient` jest niezależnym klientem HTTP bez znajomości modelu Faktury. Pobiera XML pojedynczej waluty dla zakresu maksymalnie 93 dni, waliduje kod, tabelę, numer publikacji, daty i dodatni dziesiętny `Mid`, po czym jawnie wybiera najnowszą publikację wcześniejszą od daty odniesienia. Konfiguracja HTTPS, timeoutów i retry znajduje się w `config/nbp.php`. Ponawiane są tylko błędy połączenia i 5xx; 404 oraz błędy treści nie są ponawiane.

`InvoiceCurrencyConversionService` korzysta z `CurrencyCatalog`, `currencies.nbp_table`, resolvera i klienta. Dla waluty obcej przelicza istniejące grupy `tax_summary_snapshot`, używając pełnego tekstu kursu i arytmetyki dziesiętnej bez `float`. Netto i VAT są mnożone osobno oraz zaokrąglane half-up do dwóch miejsc, brutto jest ich sumą, a sumy dokumentu wynikają z gotowych grup. Wynik wraz z metadanymi kursu trafia do `invoices.tax_metadata_snapshot`; nie powstaje tabela ani cache kursów.

`InvoiceIssuingService` działa dwufazowo. Przed transakcją przygotowuje podstawowy dokument, ustala kontekst i pobiera kurs. W transakcji blokuje zamówienie oraz serię, ponownie przygotowuje dokument i porównuje walutę, daty odniesienia oraz tabelę. Przy zmianie wycofuje próbę i maksymalnie raz ponawia cały proces poza transakcją. Dopiero po zgodnym przeliczeniu tworzy slot, szkic i pozycje, a następnie nadaje numer. Oczekiwanie na NBP nie odbywa się pod blokadami bazy.

Przeliczenie jest wywoływane wyłącznie przez ścieżkę wystawiania Faktury VAT. `InvoiceDocumentPreparationService`, `InvoiceSnapshotBuilder`, `ProformaService` i renderer PDF nie wykonują HTTP. Faktura PLN i wszystkie Pro formy zachowują pusty `tax_metadata_snapshot`; kurs nie wpływa na hash Pro formy. PDF nie został zmieniony, a prezentacja kursu należy do Etapu 1E.3.

### Etap 1E.3 — prezentacja snapshotu walutowego w PDF

`InvoicePdfCurrencyConversionPresenter` jest jedyną warstwą interpretującą snapshot przeliczenia na potrzeby PDF. Dla Faktury VAT w walucie obcej waliduje kontrakt wersji 1, źródło NBP, waluty, tabelę, daty, dokładny dodatni tekst kursu, sposób zaokrąglenia, format kwot i niezmienniki sum. Następnie paruje źródłowe i przeliczone grupy po kluczu `code:<kod>` albo `rate:<stawka>`, zachowując kolejność grup źródłowych. Do Blade trafiają gotowe `pln_conversion` i `tax_row_pairs`; widok nie parsuje snapshotu i nie wykonuje arytmetyki.

Generowanie PDF nie wywołuje klienta NBP ani `InvoiceCurrencyConversionService`, nie odczytuje modelu `Currency`, nie mnoży kwot przez kurs i nie zapisuje niczego do bazy. Kwoty PLN pochodzą bezpośrednio z `converted_tax_summary`, a kurs jest prezentowany dokładnie jak zapisano go w `currency_conversion.rate`. Główne sumy, pozycje i kwota słownie pozostają w walucie Faktury.

Pusty `tax_metadata_snapshot` walutowej Faktury jest obsługiwany jako historyczny dokument bez sekcji PLN. Niepusty, częściowy lub niespójny snapshot zgłasza kontrolowany błąd `invoice_pdf_invalid_currency_conversion_snapshot`. Faktury PLN, Pro formy i Korekty nie otrzymują tej sekcji.

`InvoicePdfFilenameGenerator` wersjonuje layout per typ dokumentu. Bieżące nazwy cache nie zawierają technicznej wersji stanu dokumentu. Po rzeczywistej zmianie bieżący plik jest usuwany po commit, a kolejne pobranie generuje go ponownie.

## Etap 1F

- dane sprzedawcy,
- bank,
- miejsce wystawienia,
- wystawiający,
- szablon `additional_information_template`.

## Etap 1G

- wygląd dokumentu,
- języki,
- widoczność VAT,
- elementy wydruku.

## Etap 2A — fundament dokumentów sprzedaży

Zaimplementowane elementy:

- migracje `invoices` i `invoice_items`,
- modele `Modules\Invoices\Models\Invoice` i `InvoiceItem`,
- enumy `InvoiceDocumentStatus` oraz `InvoiceItemType`,
- casty enumów, dat, tablic JSON i wartości dziesiętnych,
- relacje `Order -> invoices`, `OrderItem -> invoiceItems` i `InvoiceSeries -> invoices`,
- relacje dokumentu do serii, zamówienia i pozycji,
- relacje Faktury i Korekty,
- snapshoty sprzedawcy, nabywcy, odbiorcy, wystawiającego, zamówienia, płatności, dostawy, ustawień serii i podatków,
- snapshoty pozycji Korekty przed, po i różnicy,
- pola `source_snapshot_hash` i `last_refreshed_at` Pro formy,
- blokada usunięcia serii posiadającej dokument lub szkic.

Tabela `invoices` obsługuje `invoice`, `proforma` i `correction`. `order_id` jest nullable z `nullOnDelete`, `invoice_series_id` jest wymagane i chronione przed usunięciem serii, a pozycje dokumentu są usuwane kaskadowo wyłącznie wraz z dokumentem. `order_items` używa `nullOnDelete`, więc usunięcie pozycji zamówienia nie niszczy snapshotu dokumentu.

`previous_correction_id` posiada przenośny unikalny indeks i chroni pojedyncze następne ogniwo liniowego łańcucha. Centralny `CorrectionSourceStateService` pobiera Korekty zbiorczo, weryfikuje root, zamówienie, status, typ, połączenia oraz brak rozgałęzień/cykli. Regułę najwyżej jednej bieżącej, niezfinalizowanej Korekty chronią transakcyjnie blokada zamówienia, walidacja łańcucha i `order_document_slots`.

`order_items.product_id` jest nullable i posiada FK do `products` z `nullOnDelete`. `invoice_items.product_id` jest nullable i nie posiada FK, dzięki czemu dokument zachowuje historyczny identyfikator po usunięciu produktu. Modele `OrderItem`, `InvoiceItem` i `Product` udostępniają relacje nawigacyjne, ale dane pozycji pozostają snapshotami i nie są synchronizowane z bieżącym katalogiem.

Nie użyto SoftDeletes i nie dodano pól KSeF. Nie ma tabeli ani ogólnej puli zwolnionych numerów, rewizji Pro formy ani audytu edycji Faktury.

## Etap 2B — liczniki i silnik numeracji

Zaimplementowano:

- `invoice_number_counters` i `invoice_number_counter_adjustments`,
- niezależne liczniki dla `invoice_series_id` i `numbering_period_key`,
- `last_sequence_number` i `protected_floor_sequence_number`,
- centralny resolver okresu i centralny formatter numeru,
- centralny walidator zgodności formatu z okresem resetowania,
- transakcyjne nadawanie numeru istniejącemu szkicowi,
- unikalność sekwencji w serii i okresie,
- serwerowy, niezapisujący podgląd,
- operację „Ustaw następny numer” z obowiązkowym powodem i historią,
- blokadę typu dokumentu po rozpoczęciu numeracji oraz parametrów numeracji dla serii własnych,
- zamrożenie `numbering_period_key` po nadaniu numeru.

Parametry tożsamości numeracji to `document_type`, `number_format`, `reset_period` i `fiscal_year_start_month`. Dla serii własnych po rozpoczęciu numeracji pozostają zablokowane. Seria systemowa zachowuje niezmienny `document_type`, `system_key` i status systemowy, ale może zmienić `number_format`, `reset_period` oraz `fiscal_year_start_month`; nowe ustawienia dotyczą wyłącznie kolejnych dokumentów. Numery, klucze okresów i snapshoty formatów już ponumerowanych dokumentów nie są przeliczane ani aktualizowane. Operacja ręczna działa dla serii systemowych, własnych, aktywnych i ukrytych, oddzielnie dla każdego okresu.

Walidacja konfiguracji jest wykonywana poza warstwą formularza. Reset `monthly` wymaga `%M` i jednego z tokenów roku `%Y`/`%y`. Reset `yearly` od stycznia wymaga tokenu roku, a przy początku roku fiskalnego innym niż styczeń także `%M`. Reset `none` nie wymaga tokenu okresu. Nieprawidłowa seria jest odrzucana kontrolowanym błędem przed podglądem, zmianą licznika lub nadaniem numeru.

Wewnętrzne luki nie są ponownie używane. Serwis usuwania może transakcyjnie cofnąć wolny koniec licznika, ale nie zejdzie poniżej `protected_floor_sequence_number`. Nie ma OSS ani kontroli kompletności zamówienia; brak NIP-u nie blokuje samego nadania numeru. Zmiana `issue_date` nie przenosi ponumerowanego dokumentu do innego okresu. Pierwsza logiczna Pro forma zużywa jeden numer i jej odświeżenie zachowuje numer. Każda odrębna Korekta otrzymuje własny numer, zachowuje go podczas edycji, a finalizacja nie zmienia licznika ani nie zwalnia numeru.

## Etap 2C — centralne operacje Faktury VAT i Pro formy

Warstwa domenowa Etapu 2C składa się z komponentów przygotowania dokumentu (`InvoiceDateResolver`, `InvoiceSnapshotBuilder`, `InvoiceItemBuilder`, `InvoiceTotalsCalculator`, `AdditionalInformationRenderer`) oraz dwóch serwisów orkiestrujących: `InvoiceIssuingService` i `ProformaService`. Komponenty przygotowania nie uruchamiają własnych transakcji. Wszystkie krytyczne zapisy wraz z użyciem `InvoiceNumberingService` odbywają się we wspólnej transakcji operacji dokumentu.

Tabela `order_document_slots` posiada unikalność `(order_id, document_type)` i stanowi ostateczną ochronę jednej Faktury VAT, jednej logicznej Pro formy oraz jednej Korekty zamówienia, także na SQLite. Slot powstaje w tej samej transakcji co dokument. Niespójny slot albo więcej niż jeden rekord Korekty dla zamówienia kończą operację kontrolowanym błędem domenowym; starsza pojedyncza Korekta bez slotu może zostać bezpiecznie podłączona do slotu podczas obsługiwanej operacji domenowej.

`InvoiceIssuingService` wykonuje kontrolę typu i aktywności serii, ochronę duplikatu, tworzy szkic, snapshoty i pozycje, nadaje numer przez istniejący silnik Etapu 2B, wystawia dokument, wiąże slot, oznacza ewentualną Pro formę jako zastąpioną i zapisuje `OrderEvent`. Błąd na dowolnym etapie wycofuje również licznik numeracji.

`ProformaService` tworzy pierwszy dokument albo porównuje kanoniczny SHA-256 z bieżącym stanem. Hash nie obejmuje technicznych ID, timestampów ani kontekstu operacji; sortuje klucze asocjacyjne, ale zachowuje kolejność pozycji. Brak zmiany niczego nie zapisuje. Zmiana zastępuje pozycje i aktualizuje bieżące snapshoty, zachowując tożsamość numeracji i pierwotne daty wystawienia.

Faktura i Pro forma mogą istnieć równolegle. `proforma_superseded_at` oraz `superseded_by_invoice_id` blokują Pro formę na czas istnienia zastępującej ją Faktury. `InvoiceDeletionService` obsługuje transakcyjne usuwanie wystawionej Faktury VAT, aktywnej Pro formy oraz jedynej Korekty. Usuwa dokument i pozycje, czyści prywatny cache PDF, zwalnia wyłącznie wolny koniec właściwego licznika, usuwa slot dokumentu i zapisuje zdarzenie zamówienia. Zastąpionej Pro formy nie można usunąć. Przed usunięciem Faktury serwis blokuje powiązaną Pro formę, weryfikuje jej spójność i atomowo zeruje oba pola. Pro forma zachowuje wtedy numer, snapshot, pozycje i prywatny PDF, a przywrócenie zapisuje zdarzenie `proforma_restored`. Pro forma przechowuje wyłącznie jeden bieżący stan.

Zbiorcze usuwanie wykonuje kompletną prewalidację wszystkich zaznaczonych dokumentów w jednej transakcji. Istnienie serii, zamówień, Korekt blokujących Faktury oraz powiązanych Pro form jest ustalane zapytaniami zbiorczymi, bez relacyjnych zapytań `exists` wykonywanych osobno dla każdego dokumentu; mutacje i kontrola końca numeracji nadal pozostają wykonywane per dokument zgodnie z regułami integralności.

Snapshoty są niezależne od późniejszych zmian `orders`, `order_items` i `invoice_series`. Waluta pochodzi z Order; domyślne `PLN` dotyczy wyłącznie tworzenia nowych, pustych danych, a nie naprawiania historii podczas wystawiania dokumentu. Brak opcjonalnych danych kontrahenta nie blokuje operacji, natomiast brak stawki VAT wymaganej przez konfigurację serii powoduje kontrolowany błąd i pełny rollback. Nie ma OSS ani kontroli kompletności zamówienia.

Etap 2C sam nie dodawał UI, kontrolerów, tras wystawiania ani PDF; ścieżki ręczne Faktury VAT i Pro formy oraz PDF zostały dodane w Etapie 2D. Nadal nie ma e-maila, usuwania dokumentów, ręcznej edycji, wystawiania Korekt, automatyzacji, publicznego API, JPK XML, OSS, KSeF ani Fakturowni.

## Etap 2D — warstwa HTTP, fragment zamówienia i PDF

`OrderInvoiceController` i `OrderProformaController` przyjmują wyłącznie `invoice_series_id`, budują ręczny `InvoiceOperationContext` i delegują odpowiednio do `InvoiceIssuingService` oraz `ProformaService`. Kontrolery nie tworzą dokumentów, pozycji, slotów ani numerów. Kontrolowane błędy domenowe mają stabilny kod i polski komunikat, a nieoczekiwane wyjątki są logowane bez ujawniania SQL użytkownikowi.

Stan akcji dokumentów jest centralnie budowany przez `OrderSalesDocumentActionsView` i jeden partial Blade używany zarówno przy pierwszym renderze, jak i w odpowiedzi AJAX. Event delegation przechwytuje formularz, blokuje akcje bez zmiany tekstu, wysyła `fetch()` i zastępuje wyłącznie stabilny kontener fragmentu. Nie ma przeładowania całej strony, modala, preview ani komunikatu sukcesu. Jedna aktywna seria daje zwykły przycisk, wiele serii daje dropdown. Kliknięcie numeru istniejącej Pro formy najpierw odświeża jej bieżący snapshot przez ten sam centralny endpoint, a po powodzeniu otwiera aktualny prywatny PDF. Po Fakturze Pro forma jest całkowicie ukryta w kafelku, a jej bieżący dokument pozostaje w bazie.

`InvoicePdfViewModelFactory` tworzy warianty Faktury VAT, Pro formy i Korekty wyłącznie z zapisanych danych. Pro forma nie pokazuje technicznego stanu blokady, osoby wystawiającej ani dodatkowych informacji. Korekta wymaga kompletnych snapshotów stanu przed, po i różnicy; rzeczywistą zmianę danych Nabywcy pokazuje na podstawie snapshotów w układzie „Było / Powinno być”. Moduł posiada centralny serwis wystawiania i edycji Korekt, prywatny renderer PDF oraz listę wykorzystującą wspólny mechanizm dokumentów sprzedaży. `InvoiceAmountInWordsFormatter` wykonuje konwersję bez `float`, zachowuje grosze i znak wartości ujemnych, a `InvoiceMoneyFormatter` formatuje kwoty interfejsu przez dokładną arytmetykę dziesiętną i operacje na stringach.

Na PDF `order_snapshot.id`, z fallbackiem do `order_reference_snapshot`, jest jedynym źródłem pola `Numer zamówienia`; `order_snapshot.external_id` pozostaje numerem źródłowym integracji. `InvoicePaymentIdentifierResolver` zapisuje podczas przygotowania dokumentu unikalne identyfikatory zaksięgowanych transakcji PrestaShop, a przy ich braku zaksięgowanych transakcji PayNow. Identyfikator jest zamrażany w `payment_snapshot` i nie jest odczytywany z bieżącego zamówienia podczas renderowania. Jednorazowa migracja naprawia historyczny błąd wyłącznie tam, gdzie stary identyfikator płatności był równy numerowi zamówienia sklepu; uwzględnia tylko płatności istniejące nie później niż w chwili wystawienia dokumentu.

`InvoiceFinancialLimits` odwzorowuje rzeczywiste kontrakty precision/scale pól zamówień, dokumentów i pozycji, a `InvoiceFinancialValueValidator` egzekwuje je przez `BigDecimal` na granicach HTTP i serwisów oraz dla obliczonych sum pozycji, dokumentów i Korekt. Walidacja nie opiera się na `PHP_INT_MAX` ani `float`. Biznesowe wejście procentowego VAT ma leksykalną postać liczby całkowitej `0..100` bez whitelisty, natomiast serwisy akceptują równoważną kanoniczną reprezentację dziesiętną i zapisują ją ze skalą 2. `InvoiceTaxIdentityNormalizer` najpierw rozstrzyga `vat_code`, więc kod podatkowy usuwa nieaktywną stawkę przed jej walidacją.

`InvoiceItemBuilder` zachowuje pozycję dostawy o wartości `0.00`, jeśli metoda dostawy istnieje i seria ją uwzględnia. `InvoiceIssuingService` kopiuje do `order_snapshot.related_documents.proforma` identyfikator, numer i datę istniejącej Pro formy przed jej oznaczeniem jako zastąpionej.

## Etap 2E — edycja Faktur VAT na snapshotach

`InvoiceEditabilityPolicy` centralnie dopuszcza wyłącznie wystawioną Fakturę VAT z numerem, serią i zgodnym `OrderDocumentSlot`, bez Korekt. `InvoiceEditService` orkiestruje niezależne mutacje snapshotów i pozycji w transakcjach, zawsze blokując `Order` przed `Invoice`. Requesty blokują pola techniczne, a ukryte `expected_lock_version` chroni przed zasadą ostatniego zapisu.

Pozycje są budowane przez `InvoiceEditableItemsService` i liczone przez istniejące kalkulatory dziesiętne. Kopiowanie z zamówienia czyta historyczny `series_settings_snapshot`, wymaga zgodnej waluty i zastępuje pozycje atomowo. Zmiana daty wystawienia jest weryfikowana przez `InvoiceNumberFormatter` oraz `InvoiceNumberingPeriodResolver`; nie dotyka licznika i jest odrzucana, gdy zmieniłaby numer lub okres.

Każdy dokument sprzedaży przechowuje jeden bieżący stan. Rzeczywista edycja nadpisuje snapshoty lub pozycje i zwiększa `lock_version` dokładnie o jeden; brak semantycznej zmiany nie zapisuje dokumentu. `lock_version` jest wyłącznie niewidocznym dla użytkownika mechanizmem optimistic locking, nie historią ani wersją biznesową. Zwykła edycja Faktury nie tworzy `OrderEvent`.

`InvoiceEditCurrencyConversionService` zachowuje snapshot NBP dla zmian tekstowych, przelicza zmienione kwoty zapisanym kursem bez HTTP i przygotowuje nowy kurs poza transakcją tylko po zmianie daty odniesienia. W transakcji ponownie sprawdza kontekst. Pusty historyczny snapshot walutowy blokuje tylko operacje pieniężne i daty kursowe, natomiast niepoprawny niepusty snapshot blokuje całą edycję.

Warstwa HTTP zwraca tylko wymagane fragmenty Blade i aktualne `lock_version`, którym JavaScript aktualizuje wszystkie ukryte pola formularzy. Ekran edycji nie zapisuje automatycznie danych skopiowanych do formularza i nie pokazuje komunikatów sukcesu. Faktura, Pro forma i Korekta mają po jednym bieżącym pliku cache PDF zależnym od wersji layoutu. Rzeczywista zmiana usuwa bieżący cache dopiero po commit, a kolejne otwarcie odtwarza go z aktualnych snapshotów.

`InvoiceRevision` nie jest używany. Migracja porządkująca usuwa tabelę `invoice_revisions` i kolumnę `revision_number`; standardowe `updated_at` oznacza jedynie ostatnią aktualizację rekordu. Pro forma zachowuje `source_snapshot_hash` do wykrywania zmiany źródła oraz `last_refreshed_at` dla czasu ostatniego rzeczywistego odświeżenia. System nie przechowuje poprzednich stanów ani poprzednich PDF-ów.

## Etap 2F — centralny proces Korekt

`CorrectionController` jest cienką warstwą HTTP. `CorrectionSeriesResolver` wybiera wyłącznie aktywną serię typu `correction`: najpierw serię wskazaną jawnie przez użytkownika, a bez wyboru serię przypisaną do serii Faktury źródłowej albo aktywną systemową serię Korekt. `CorrectionDraftRequest` normalizuje i waliduje powód, daty, dane Nabywcy oraz proponowany stan pozycji.

`CorrectionSourceStateService` centralnie rozwiązuje liniowy łańcuch Korekt i skuteczny stan źródłowy: pierwotną Fakturę przy pierwszej Korekcie albo ostatnią zamkniętą Korektę przy kolejnej. Pobiera rekordy zbiorczo i w pamięci odrzuca rozgałęzienia, cykle, obcy root lub zamówienie, więcej niż jedną bieżącą Korektę oraz slot niewskazujący bieżącego ogona. `CorrectionService` blokuje zamówienie, root Fakturę, łańcuch, slot i serię, weryfikuje identyfikator oraz `lock_version` skutecznego źródła, buduje snapshoty przed, po i różnicy oraz nadaje każdej nowej Korekcie własny numer. Jeżeli istnieje bieżąca niezfinalizowana Korekta, ponowna ścieżka tworzenia kieruje do jej edycji. Rzeczywista edycja nadpisuje snapshoty i pozycje tego samego bieżącego rekordu, zwiększa `lock_version`, zachowuje tożsamość numeracji i unieważnia prywatny cache PDF po zatwierdzeniu transakcji. Identyczny stan kanoniczny kończy się bez zapisu; comparator uwzględnia pełne snapshoty dokumentu i pozycji, normalizuje reprezentację wartości oraz pozwala jednorazowo ukanonicznić starszy zapis. Root Faktura i zamknięte Korekty pozostają niezmienne.

Pozycje są liczone serwerowo przez istniejące kalkulatory dziesiętne. `InvoiceTaxIdentityNormalizer` tworzy wspólną kanoniczną tożsamość podatku dla Faktur i Korekt: kod po `trim` i `uppercase` ma pierwszeństwo przed stawką, a stawka bez kodu jest normalizowana do skali 2. Kalkulatory grupują podatek po stabilnych kluczach `code:<KOD>` albo `rate:<STAWKA>`, dzięki czemu przejścia stawka-kod i kod-kod pozostają widoczne jako oddzielne grupy różnic. Każda Korekta wskazuje root Fakturę przez `corrected_invoice_id`, a `previous_correction_id` łączy ją z poprzednią zamkniętą Korektą. Brak rzeczywistej zmiany, bieżąca Korekta, niespójny łańcuch/slot, niekompletny stan źródłowy lub niewłaściwa seria kończą się kontrolowanym błędem i pełnym rollbackiem, bez zużycia numeru.

Formularz używa standardowego żądania POST. Przy jednej aktywnej serii link prowadzi bezpośrednio do formularza, a przy wielu seriach wspólny modal Bootstrap służy wyłącznie do wyboru serii. Edycja pozycji, zerowanie zaznaczonych pozycji oraz kopiowanie aktualnych danych zamówienia działają lokalnie w formularzu; zapis następuje dopiero przy wystawieniu Korekty. Istniejący prywatny renderer TCPDF obsługuje gotowy dokument na podstawie snapshotów.

## Kolejne etapy

- Etap 2G — dokumenty zewnętrzne PDF,
- Etap 2H — wysyłka dokumentów e-mailem,
- Etap 3A — rejestr sprzedaży,
- Etap 3B — JPK,
- Etap 3C — audyt KSeF,
- Etap 3D — integracja KSeF.

---

# 34. Kraje adresów i snapshoty dokumentów

`orders.shipping_country_code` i `orders.billing_country_code` są niezależnymi, nullable polami ISO 3166-1 alpha-2. Warstwa zapisu normalizuje kod przez `trim` i `uppercase`, a dla edytowanych sekcji wymaga wartości istniejącej w centralnym `App\Support\CountryCatalog`. Historyczny brak kraju pozostaje brakiem; backend nie stosuje ukrytego fallbacku `PL`.

`CountryCatalog` korzysta z `Symfony\Component\Intl\Countries` i zwraca polskie nazwy. Polska jest pierwszą pozycją interfejsu. Kopiowanie danych między adresem dostawy i danymi faktury przenosi kod kraju, a integracja GUS ustawia dla pobranej polskiej firmy `billing_country_code = PL`.

`InvoiceSnapshotBuilder` zapisuje w `buyer_snapshot` kraj z danych faktury, a w `recipient_snapshot` kraj dostawy jako pary `country_code` i `country_name`. Poprawny kod jest rozwiązywany w chwili tworzenia snapshotu, pusty pozostaje pusty, a nieprawidłowy niepusty kod kończy operację kontrolowanym błędem domenowym. Wystawione dokumenty nie odczytują kraju z aktualnego Order.

`InvoicePdfViewModelFactory` formatuje gotową linię miejscowości Nabywcy, np. `32-545 Psary, Polska`, dla Faktury VAT, Pro formy i Korekty. Starszy snapshot bez `country_name` może rozwiązać prawidłowy `country_code` podczas renderowania bez zapisu do bazy. Kraj Sprzedawcy i Odbiorcy nie został dodany do wydruku. Ta zmiana nie wpływa na VAT, OSS, sposób płatności ani snapshot powiązania Faktury z Pro formą.

## 34.1. Pobieranie danych z GUS BIR

`GusRegonService` komunikuje się z oficjalnym endpointem BIR1.1/BIR1.2 przez SOAP 1.2 transportowany klientem Laravel HTTP. Klucz użytkownika jest pobierany wyłącznie z `services.gus.key`, nie jest przekazywany do przeglądarki ani zapisywany w bazie. Każde wyszukanie wykonuje kontrolowaną sekwencję `Zaloguj`, `DaneSzukajPodmioty` i `Wyloguj`; identyfikator sesji jest przekazywany wymaganym nagłówkiem HTTP `sid`.

Endpoint aplikacji przyjmuje chronione CSRF żądanie `POST`, normalizuje i sprawdza sumę kontrolną polskiego NIP, a następnie zwraca wyłącznie strukturalne dane znalezionych podmiotów. Odpowiedzi błędów GUS, SOAP Fault i puste wyniki są zamieniane na bezpieczne kody i komunikaty aplikacji. Pusty wynik jest rozróżniany metodą diagnostyczną `GetValue`. Dane z rejestru są traktowane jako niezaufany tekst i nie są wstawiane do DOM jako HTML.

GUS uzupełnia formularz danych do faktury w zamówieniu, lecz go nie zapisuje. Wiele wyników wymaga jawnego wyboru użytkownika. Po zapisie istniejący `InvoiceSnapshotBuilder` kopiuje dane do `buyer_snapshot`; integracja GUS nie aktualizuje automatycznie wystawionych ani zamkniętych dokumentów.

---

# 35. Zakazy architektoniczne

Bez wyraźnej decyzji nie należy:

- tworzyć `seller_profiles`,
- tworzyć `company_settings`,
- dodawać `orders.invoice_id`,
- przywracać tabeli numerów seryjnych,
- dodawać `orders.serial_numbers_text`,
- używać `float` w fakturach,
- tworzyć publicznych URL-i do PDF,
- instalować drugi silnik PDF obok TCPDF bez decyzji architektonicznej,
- implementować komunikację z API KSeF poza zatwierdzonym etapem,
- implementować paragony,
- tworzyć osobnych tabel dla pro form wyłącznie z powodu typu,
- wykonywać `max(number) + 1`,
- generować numerów po stronie frontendowej,
- synchronizować automatycznie wystawionej faktury z zamówieniem,
- przechowywać danych sprzedawcy wyłącznie jako jeden dowolny tekst,
- używać ogólnego `document_settings` jako niekontrolowanego worka JSON.

---

# 36. Decyzje wymagające późniejszego zatwierdzenia

Do rozstrzygnięcia w odpowiednich etapach:

- dokładna reguła zaokrągleń,
- strategia decimal/money,
- szczegółowe reguły procesu wystawiania i edycji Faktury,
- walidacja i współbieżność centralnego procesu Korekt,
- zasady anulowania,
- statusy dokumentów,
- obsługa częściowych płatności,
- uprawnienia użytkowników,
- lista wszystkich zmiennych szablonu informacji,
- polityka retencji plików,
- mechanizm ręcznej korekty GTU/JPK,
- docelowa baza produkcyjna,
- sposób testowania współbieżności poza SQLite.

Nie należy rozstrzygać tych kwestii przypadkowo podczas innego etapu.

---

# 37. Integracja PayNow — etap PAYNOW.1

Fundament integracji znajduje się w `Modules/Integrations/PayNow`, natomiast trasy pozostają centralnie w `routes/web.php`, a migracje w `database/migrations`. Warstwa składa się z modeli konta, płatności i zdarzenia, zamkniętych enumów środowiska i statusów, `PayNowAccountService`, `PayNowSignatureService`, `PayNowClient` oraz `PayNowConnectionTestService`. Nie wprowadzono osobnego providera ani zewnętrznej biblioteki Composer.

Podpis v3 jest liczony jako Base64 z binarnego HMAC SHA-256 nad JSON-em zawierającym `Api-Key`, `Idempotency-Key`, parametry jako tablice oraz dokładny tekst body. Implementacja jest objęta oficjalnym wektorem testowym PayNow. Klient używa stałych hostów `https://api.sandbox.paynow.pl` i `https://api.paynow.pl`, krótkich timeoutów i nie wykonuje automatycznych retry. Test połączenia wywołuje `GET /v3/payments/paymentmethods` z `amount=100` i `currency=PLN`; nie tworzy obiektu płatności u dostawcy.

`paynow_accounts` przechowuje zaszyfrowane credentiale i stan ostatniego testu. `paynow_payments` jest przyszłym rejestrem wielu wpłat lub dopłat dla zamówienia i nie używa `float`; kwota ma typ `DECIMAL(18,2)`, a URL płatności jest szyfrowany. `paynow_payment_events` zachowuje zaszyfrowany payload oraz jawny SHA-256 potrzebny do deduplikacji i audytu. Klucze obce do konta, zamówienia i płatności używają `nullOnDelete`, aby usunięcie rekordu źródłowego nie niszczyło historii finansowej.

Sekrety są ukryte przez casty i `$hidden`, a nazwy pól zostały dodane do globalnej listy danych niewracających do sesji po błędzie walidacji. Aktywacja wymaga kompletnej konfiguracji i udanego testu bieżących credentiali. PAYNOW.1 nie zawiera tworzenia linku, webhooka, reconciliation ani księgowania na zamówieniu.

---

# 38. Integracja PayNow — etap PAYNOW.2

`PayNowPaymentLinkService` jest centralnym serwisem tworzenia linku lub ponownego użycia nadal ważnej próby. W transakcji blokuje konto i zamówienie, sprawdza aktywność oraz poprawny test połączenia, walutę PLN, e-mail klienta i pozostałą kwotę. Różnica `total_gross - paid_amount` jest liczona przez `InvoiceDecimalCalculator`; do API trafia jako całkowita liczba groszy. Każda nowa próba otrzymuje unikalne `external_id` i maksymalnie 45-znakowy klucz idempotencji.

Klient wysyła `POST /v3/payments/links` z dokładnie tym samym JSON-em, który został użyty przy obliczaniu podpisu v3. Nie wykonuje automatycznych retry. Gdy komunikacja zostanie przerwana przed zapisaniem odpowiedzi, ponowne wywołanie używa tego samego rekordu i tego samego klucza idempotencji. Odpowiedź jest akceptowana wyłącznie wtedy, gdy zawiera rozpoznany status, identyfikator płatności i adres HTTPS w domenie PayNow.

Po sukcesie URL jest przechowywany przez szyfrowany cast `paynow_payments.payment_url`. Surowy payload odpowiedzi trafia w postaci zaszyfrowanej do append-only `paynow_payment_events`, a historia zamówienia zawiera tylko bezpieczne identyfikatory, kwotę i walutę. Komunikaty błędów są mapowane na treści bezpieczne dla operatora; surowe komunikaty dostawcy nie są zwracane do interfejsu.

Endpoint `POST /orders/{order}/paynow/payment-link` zwraca JSON dla interfejsu AJAX. Warstwa Blade rozróżnia pojedyncze i podwójne kliknięcie, obsługuje schowek z fallbackiem, nie przeładowuje strony i ponownie inicjalizuje tooltip po wymianie fragmentu informacji o zamówieniu. Etap nie aktualizuje kwoty wpłaty ani statusu płatności. Odbiór webhooka, reconciliation i księgowanie należą do PAYNOW.3.

---

# 39. Integracja PayNow — etap PAYNOW.3

`POST /api/paynow/v1/notifications` jest publiczną trasą wyłączoną wyłącznie z ochrony CSRF i ograniczoną osobnym limiterem. `PayNowWebhookService` odczytuje surowe body, wybiera klucz podpisu konta przypisanego do `externalId` i przed wykonaniem operacji biznesowej weryfikuje Base64 HMAC SHA-256 z nagłówka `Signature`. Payload ma limit 64 KiB. Nieznane, ale poprawnie podpisane zdarzenia pozostają w `paynow_payment_events` bez relacji do płatności, co zachowuje ślad bez automatycznego dopasowywania danych.

`PayNowPaymentStatusService` jest wspólną projekcją dla webhooka i odpytywania `GET /v3/payments/{paymentId}/status`. Semantyczny klucz zdarzenia deduplikuje ponowienia niezależnie od formatowania JSON. Projekcja uwzględnia `modifiedAt`, nie cofa `CONFIRMED`, wykrywa sprzeczne identyfikatory oraz obsługuje nowy `paymentId` powstały podczas odzyskiwania płatności z tym samym `externalId`.

`PayNowPaymentAccountingService` blokuje próbę płatności i zamówienie w jednej transakcji. Pole `confirmed_at` oznacza potwierdzenie dostawcy, a `accounted_at` jednokrotne zastosowanie kwoty w OMS. Kwoty są dodawane przez `InvoiceDecimalCalculator`, a wynik przechodzi przez `OrderPaymentStateService`. Nadpłata, usunięte zamówienie i niezgodność waluty ustawiają `needs_review` bez modyfikowania zamówienia. Zapis księgowania obejmuje append-only zdarzenie płatności oraz zdarzenie zamówienia.

`PayNowReconciliationService` pobiera maksymalnie 20 prób `NEW` albo `PENDING`, których nie sprawdzano od dwóch minut, i korzysta z tego samego serwisu projekcji. Harmonogram uruchamia go co minutę bez nakładania wykonań. Nieaktywne konto nadal może zweryfikować wcześniej utworzoną płatność, o ile zachowało credentiale. Warstwa widoku ładuje tylko potwierdzone płatności i aktualizuje fragment `Płatności` co 15 sekund oraz przy zmianie historii zamówienia.

---

# 40. Integracja PayNow — etap PAYNOW.4

Po poprawnym księgowaniu `PayNowPaymentAccountingService` publikuje ogólne zdarzenie domenowe `OrderPaymentRegistered` o nazwie technicznej `payment.registered`. Zdarzenie implementuje `ShouldDispatchAfterCommit`, dlatego silnik automatyzacji otrzymuje je dopiero po zatwierdzeniu zmian płatności, zamówienia oraz historii. Ścieżki `needs_review` kończą się przed publikacją zdarzenia.

Identyfikator zdarzenia jest deterministycznym UUID v5 złożonym ze źródła i wewnętrznego identyfikatora rekordu płatności. Pozwala to wykorzystać istniejącą unikalność `automation_rule_id + event_id`: webhook, reconciliation i ponowiona próba księgowania tej samej wpłaty nie uruchomią reguły ponownie, natomiast dwie odrębne dopłaty pozostają dwoma zdarzeniami.

Payload zawiera wyłącznie bezpieczne dane operacyjne: zamówienie, wewnętrzny rekord płatności, źródło `paynow`, identyfikator dostawcy, kwotę, walutę, saldo przed i po, stan płatności po księgowaniu oraz czas księgowania. Nie zawiera kluczy, podpisów ani URL-a płatności. `AutomationCatalog` udostępnia zdarzenie `Zarejestrowano płatność` i warunek `Źródło płatności`; warunek `Płatność` korzysta dla tego zdarzenia ze stanu wyliczonego dziesiętnie podczas księgowania.

Wykonanie akcji pozostaje asynchroniczne na kolejce `automation`. Błąd akcji zmienia wynik wykonania reguły, lecz nie może wycofać zatwierdzonej transakcji finansowej. PAYNOW.4 nie implementuje zwrotów, chargebacków ani panelu ręcznej obsługi wyjątków.

---

# PayNow - publiczny powrot klienta (PAYNOW.5)

`PayNowReturnUrlService` generuje czasowy podpis Laravel dla konkretnego `paynow_payments.external_id`. Wygasa on dzien po `valid_until` (dla historycznego null: 92 dni od utworzenia). Staly czas na podstawie rekordu, zamiast `now()`, zachowuje podpis przy ponowieniu tego samego zadania z tym samym kluczem idempotencji. Podpis jest osobny dla GET strony, POST sprawdzenia i POST ponowienia. Pomijane przy sprawdzaniu podpisu sa tylko dopisywane przez PayNow `paymentId` i `paymentStatus`; sa traktowane jako niezaufane wskazowki. Nieznany nowy ID odzyskiwanej platnosci czeka na podpisany webhook i nie moze posluzyc do odczytu dowolnej transakcji przez API.

`PayNowCustomerReturnController` nie uzywa layoutu administracyjnego ani nie zwraca adresow, e-maili lub credentiali. GET nie wykonuje HTTP ani ksiegowania. POST status sprawdza wylacznie ID zapisane w autoryzowanym rekordzie i korzysta z `PayNowPaymentStatusService`, bez drugiej sciezki ksiegowania. `PayNowCustomerPaymentService` serializuje ponowienia blokada cache per zamowienie (60 s). `PayNowPaymentLinkService` ponownie sprawdza ostatnia probe, zweryfikowany status i ID, pozostale nierozstrzygniete proby, konto i saldo pod blokadami bazy, przed przygotowaniem linku. Zmiana miedzy odczytem a transakcja blokuje nowe zlecenie. Istniejacy, zgodny aktywny link moze byc zwrocony ponownie; nowa proba wymaga swiezego potwierdzenia stanu REJECTED, ERROR albo EXPIRED. ABANDONED, brak ID, blad odczytu i needs_review nie uprawniaja do utworzenia nowej proby.

`recordSuccess()` zachowuje CONFIRMED i jego provider_payment_id, jesli webhook nadejdzie przed odpowiedzia tworzenia linku. Rozbiezny ID odpowiedzi ustawia needs_review i nie podmienia URL ani potwierdzonego ID. To ochrona kolejnosci zdarzen, bez zmiany kwot lub zasad ksiegowania.

Trzy trasy klienta zachowuja middleware web i CSRF dla POST. Limitery ograniczaja GET do 60/min/IP, status do 30/min/IP i 12/min/ref, ponowienie do 5/min/IP i 3/min/ref. W produkcji wymagany jest wspoldzielony, trwaly cache z atomowymi blokadami (database/Redis albo file na jednym VPS); array jest tylko dla testow. Odpowiedzi strony nie sa cache'owane; maja no-referrer, noindex, DENY i CSP z nonce bez zewnetrznych skryptow, fontow lub analityki. Bez migracji i bez zmiany .env/APP_KEY. Dokumentacja wdrozenia omawia HTTPS i waskie wyjatki Basic Auth.

---

# 41. Integracja PrestaShop — etap PRESTASHOP.1

Fundament integracji znajduje się w `Modules/Integrations/Prestashop`, przy zachowaniu centralnych tras i migracji projektu. `prestashop_accounts` przechowuje jedno logiczne połączenie ze sklepem, zaszyfrowany Klucz API, wynik ostatniego testu oraz wykryte metadane instalacji. `prestashop_order_states` przechowuje lokalny słownik statusów potrzebny w następnym etapie do jawnego mapowania. Zmiana adresu sklepu albo klucza usuwa wykryte dane i wyłącza integrację do czasu ponownego testu.

`PrestashopClient` korzysta wyłącznie z natywnego Webservice API przez Basic Auth, wymusza HTTPS i brak przekierowań. Test wykonuje tylko żądania GET bezpośrednio do zasobów konfiguracji oraz ręcznego importu, dzięki czemu nie zależy od niestabilnego indeksu `/api/`. Dane ogólne pobiera jako JSON, a wielojęzyczne statusy jako XML z wybranym językiem; omija to błąd serializatora JSON występujący w PrestaShop 8.2.7. Odpowiedzi są walidowane i normalizowane przed zapisem; surowe odpowiedzi ani Klucz API nie trafiają do widoku lub komunikatów. Konfiguracja obsługuje dokładnie jeden aktywny sklep i zatrzymuje test po wykryciu multistore.

Etap nie instaluje modułu w PrestaShop, nie wymaga dodatkowej biblioteki Composer i nie uruchamia schedulera. PRESTASHOP.1 nie pobiera zamówień, klientów ani produktów i nie wykonuje żadnych zapisów w sklepie. Odczyt zasobów zamówień dodano w PRESTASHOP.2A, natomiast zapis `order_histories` pozostaje poza zakresem do etapu wysyłania statusów.

---

# 42. Integracja PrestaShop — etap PRESTASHOP.2A

`PrestashopOrderPreviewService` jest granicą normalizacji danych zewnętrznych. Pobiera zamówienie, pozycje, klienta, adresy, przewoźnika, walutę i płatności za pośrednictwem wspólnego `PrestashopClient`. Używa dokładnej arytmetyki dziesiętnej do kwot, wyznacza stawkę VAT z zapisanych wartości brutto i netto oraz odrzuca niekompletne lub niespójne odpowiedzi. Podgląd nie zapisuje danych.

`PrestashopOrderImportService` ponownie pobiera podgląd i zapisuje w jednej transakcji `orders`, `order_items`, `prestashop_order_links`, `prestashop_order_item_links`, `prestashop_order_payments` oraz `order_events`. Zewnętrzne ID produktu i wariantu nie są wpisywane do `order_items.product_id`. Tabele powiązań posiadają unikalności zapobiegające ponownemu importowi tego samego zamówienia, a relacje do historycznych rekordów używają `nullOnDelete` tam, gdzie usunięcie źródła nie powinno niszczyć śladu integracji.

Stan PrestaShop jest zachowany w powiązaniu importu, lecz nowe zamówienie OMS zawsze otrzymuje `new`. Etap nie synchronizuje istniejącego zamówienia przy kolejnym odczycie, poza kontrolowanym uzupełnieniem brakującego telefonu i punktu odbioru oraz wyczyszczeniem błędnego loginu PrestaShop. Nie publikuje zadań cyklicznych i nie wywołuje metod zapisu API sklepu. Moduł PrestaShop nadal korzysta z centralnych tras i migracji projektu, bez własnego providera.

`current_state = 0` jest jedyną dopuszczoną reprezentacją braku statusu po stronie sklepu. `PrestashopOrderPreviewService` normalizuje ją do `null`, dodaje ostrzeżenie i pozwala utworzyć zamówienie `new`; `prestashop_order_links.external_order_state_id` oraz `external_order_state_name` pozostają puste. Brak, tekst, liczba ujemna lub nieznane dodatnie ID nadal kończą import błędem.

---

# 43. Integracja PrestaShop — etap PRESTASHOP.2A.1

`prestashop_accounts.product_url_template` przechowuje wzór adresu HTTPS z wymaganym znacznikiem `[product_id]`. Host wzoru musi być zgodny z hostem `base_url`. Widok pozycji korzysta z `PrestashopOrderItemLink.external_product_id`; nie wpisuje zewnętrznego ID do `order_items.product_id` i nie tworzy relacji z katalogiem OMS.

`prestashop_accounts.order_enrichment_resource` przechowuje opcjonalnie wykryty zasób `bl_order` albo `base_order`. `PrestashopClient` pobiera rozszerzenie tylko przez GET, a brak lub błąd tego zasobu nie blokuje podstawowego importu. Dane `bl_delivery_point_*` są normalizowane przez `PrestashopOrderPreviewService` i zapisywane do istniejących pól `pickup_point_*` zamówienia.

Powtórny import nie nadpisuje niepustego telefonu ani danych punktu zmienionych w OMS. Uzupełnia wyłącznie brakujące wartości, zawsze usuwa błędny login źródła PrestaShop i zapisuje zdarzenie `prestashop_order_metadata_completed`, jeżeli rekord został zmieniony.

---

# 44. Integracja PrestaShop — etap PRESTASHOP.2B.1

`prestashop_inbound_status_mappings` przechowuje relację zewnętrznego ID statusu PrestaShop do aktywnego `order_status_settings`, a `prestashop_outbound_status_mappings` relację w przeciwnym kierunku. Osobne tabele pozwalają na wiele mapowań przychodzących do jednego statusu OMS i dokładnie jeden cel wychodzący dla każdego statusu OMS. Brak wiersza oznacza brak działania.

`PrestashopStatusMappingService` jest jedyną ścieżką pełnego zastępowania konfiguracji obu kierunków. Waliduje bieżący słownik statusów sklepu i aktywne ustawienia statusów OMS, blokuje niedostępny lub usunięty cel wychodzący i zapisuje całość transakcyjnie. Endpoint konfiguracji nie korzysta z `PrestashopClient` i nie wykonuje komunikacji sieciowej.

`prestashop_order_states.is_available` odróżnia status aktualnie zwrócony przez sklep od historycznego wpisu słownika. Ponowny test połączenia ustawia zwrócone rekordy jako dostępne, a brakujące jako niedostępne zamiast je usuwać. Dzięki kluczowaniu mapowań przez zewnętrzne ID konfiguracja przetrwa odświeżenie nazw i flag oraz czasową nieobecność statusu. Zmiana bazowego adresu sklepu usuwa mapowania, natomiast zmiana samego Klucza API zachowuje je.

Widok konfiguracji ma zakładki `Połączenie`, `Zamówienia` i `Statusy`. PRESTASHOP.2B.1 nie dodaje listenera `OrderStatusChanged`, klienta zapisu `order_histories`, kolejki, schedulera, synchronizacji płatności ani modyfikowania istniejących zamówień.

# 45. Integracja PrestaShop — etap PRESTASHOP.2B.2

`DispatchPrestashopStatusSync` reaguje na `OrderStatusChanged` dopiero po zatwierdzeniu transakcji. Listener odrzuca źródło `prestashop` oraz zdarzenia bez aktywnego powiązania i mapowania, a następnie przekazuje identyfikator zdarzenia do unikalnego `SyncPrestashopOrderStatusJob` na kolejce `integrations`.

`PrestashopOutboundStatusSyncService` ponownie rozwiązuje mapowanie w chwili wykonania zadania, odrzuca zadania zastąpione nowszym statusem i przed zapisem odczytuje `orders.current_state`. `PrestashopClient` wysyła zmianę jako POST XML do `order_histories`, ponieważ wejście JSON nie jest obsługiwane przez Webservice PrestaShop 8. Opcjonalny parametr `sendemail` pochodzi z `prestashop_accounts.notify_customer_on_status_change`.

Odczyt `current_state = 0` zwraca techniczny brak bieżącego statusu, ale nie blokuje utworzenia historii dla mapowanego statusu OMS. Dzięki temu zmiana lokalnego zamówienia może nadać pierwszy prawidłowy status zamówieniu zaimportowanemu bez statusu.

Sukces aktualizuje cache statusu w `prestashop_order_links` i zapisuje `prestashop_status_exported` w `order_events`. Trwały błąd zapisuje `prestashop_status_export_failed`; błędy połączenia, HTTP 429 i 5xx są ponawiane przez kolejkę. Błąd zewnętrzny nie wycofuje lokalnej zmiany statusu OMS. Kierunek PrestaShop → OMS, płatności i harmonogram pozostają poza etapem.

# 46. Integracja PrestaShop — etap PRESTASHOP.2B.3

`PrestashopStatusSyncDispatcher` jest wywoływany przez Laravel Scheduler co minutę i zleca unikalne `SyncPrestashopInboundStatusesJob` tylko dla aktywnego konta, gdy upłynął skonfigurowany interwał. Ten sam job może zostać zlecony ręcznie jako pełny przebieg. Pierwszy przebieg pobiera statusy wyłącznie znanych ID w partiach po 100, a kolejne używają filtrowania `orders.date_upd`, stabilnego sortowania, paginacji i dwuminutowej zakładki kursora. Kursor jest przesuwany dopiero po pełnym powodzeniu.

`PrestashopInboundStatusSyncService` aktualizuje cache zewnętrznego statusu, rozwiązuje `prestashop_inbound_status_mappings` i przekazuje faktyczną zmianę do centralnego `OrderStatusService`. Źródło `prestashop_status_sync` uruchamia automatyzacje po zatwierdzeniu transakcji, ale jest pomijane przez listener eksportu. Brak mapowania, usunięte zamówienie albo identyczny status kończą się wyłącznie aktualizacją cache integracji.

Normalizacja list statusów zachowuje wiersz z `current_state = 0` jako `current_state = null`. Serwis czyści wtedy zewnętrzny cache statusu danego powiązania, pozostawia lokalny status OMS bez zmian i kontynuuje pozostałe rekordy przebiegu. Późniejszy prawidłowy status jest obsługiwany standardowo.

Kolumny `pending_outbound_*` w `prestashop_order_links` chronią przed cofnięciem świeżej zmiany OMS przez starszy stan odczytany ze sklepu. Listener eksportu zapisuje oczekiwany cel przed zleceniem zadania, a sukces, trwały błąd lub zastąpienie nowszą lokalną zmianą usuwa znacznik. Synchronizacja przychodząca pomija aktywny znacznik i usuwa go po 15 minutach, jeżeli został osierocony.

Konto przechowuje przełącznik, interwał, czas ostatniego powodzenia oraz bezpieczny komunikat ostatniego błędu. Etap korzysta z natywnego Webservice API i istniejących procesów `integrations` oraz Scheduler; nie wprowadza modułu PrestaShop, webhooka, automatycznego importu nowych zamówień ani synchronizacji płatności.

---

# 47. Integracja PrestaShop — etap PRESTASHOP.2C

`PrestashopOrderImportDispatcher` jest wywoływany przez Laravel Scheduler co minutę i zleca unikalny `DiscoverPrestashopOrdersJob` dla aktywnego konta z włączonym importem. `PrestashopOrderDiscoveryService` pobiera lekką listę `id`, `reference` i `date_add`, używa paginacji po 100 rekordów, stabilnego sortowania oraz dwuminutowej zakładki czasowej. Górna granica pozostaje minutę za bieżącym czasem. `order_import_started_at` jest twardą dolną granicą, a `last_order_import_scan_at` przesuwa się dopiero po pełnym powodzeniu zapytania.

`prestashop_order_import_attempts` jest trwałą kolejką biznesową i historią ostatnich prób. Unikalność konta i zewnętrznego ID zabezpiecza wykrywanie przed duplikacją. Każda oczekująca próba jest przekazywana do osobnego, unikalnego `ImportPrestashopOrderJob` na kolejce `integrations`; stan `processing` starszy niż 15 minut może zostać bezpiecznie odzyskany. Relacje do konta oraz zamówienia używają `nullOnDelete`, dzięki czemu diagnostyka nie znika wraz z rekordem źródłowym.

`PrestashopAutomaticOrderImportService` korzysta z istniejącego `PrestashopOrderImportService`, więc automatyczny i ręczny import mają jedną ścieżkę transakcyjną i te same ograniczenia danych. Po zapisie `PrestashopInboundStatusSyncService::applyStoredOrderState()` stosuje aktualne mapowanie ze źródłem `prestashop_order_import`. Listener wychodzący ignoruje każde źródło zaczynające się od `prestashop`, natomiast automatyzacje statusowe działają normalnie.

Próby chwilowych błędów sieci, HTTP 429 i 5xx są ponawiane przez kolejkę. Błąd trwały zapisuje skrócony, bezpieczny komunikat i pozostawia próbę do ręcznego ponowienia. Błąd wykrywania nie przesuwa kursora. Panel nie wykonuje importu synchronicznie w żądaniu HTTP; zarówno harmonogram, jak i ręczne sprawdzenie jedynie zlecają pracę.

Etap nie dodaje webhooka ani modułu PrestaShop, nie odświeża danych już zaimportowanego zamówienia i nie synchronizuje kolejnych płatności. Do działania na VPS wymagane są stale uruchomione Laravel Scheduler oraz worker kolejki `integrations`.

---

# 48. Integracja PrestaShop — etap PRESTASHOP.3A

`PrestashopPaymentSyncDispatcher` jest uruchamiany przez Laravel Scheduler co minutę. Dla aktywnego konta z włączoną synchronizacją zleca unikalny `SyncPrestashopPaymentsJob` na kolejce `integrations`; częstotliwość nie jest konfigurowalna. Pełny przebieg pobiera lekkie podsumowania znanych zamówień w partiach, a następne używają `orders.date_upd`, paginacji oraz dwuminutowej zakładki. Kursor powodzenia jest przesuwany dopiero po ukończeniu całego przebiegu.

`PrestashopPaymentSyncService` pobiera natywne `order_payments` dla zamówień, których `total_paid_real` wymaga kontroli, i sprawdza zgodność sumy płatności z podsumowaniem zamówienia. `prestashop_order_payments.external_payment_id` zapewnia idempotencję odkrywania, natomiast `accounting_status` rozdziela rekordy oczekujące, zaksięgowane, odzwierciedlone z PayNow i wymagające ręcznego sprawdzenia.

`PrestashopPaymentAccountingService` blokuje rekord płatności i zamówienie w jednej transakcji. Poprawna płatność zwiększa saldo przez istniejący `OrderPaymentStateService`, aktualizuje stan płatności, zapisuje historię i publikuje po zatwierdzeniu `OrderPaymentRegistered` ze źródłem `prestashop`. Ta sama zewnętrzna płatność nie może zostać zaksięgowana ani uruchomić automatyzacji ponownie.

Potwierdzona płatność PayNow o zgodnym identyfikatorze transakcji, kwocie i walucie jest traktowana jako ten sam przepływ środków i oznaczana jako odzwierciedlona bez ponownego naliczenia. Niezgodność waluty, nadpłata, zmiana historycznej płatności, brak zamówienia lub możliwy duplikat bez wspólnego identyfikatora tworzą bezpieczne zdarzenie wymagające weryfikacji i nie zmieniają salda.

Warstwa widoku dołącza płatności PrestaShop do istniejącej sekcji płatności zamówienia. Etap nie modyfikuje pozycji, adresów ani kwot zamówienia poza saldem wpłat, nie implementuje zwrotów i nie wymaga modułu po stronie sklepu. Scheduler i worker kolejki `integrations` pozostają wymagane na VPS.

---

## PrestaShop - deklaracja oplacenia i identyfikator transakcji

`PrestashopPaymentStatusService` odczytuje `paid` z konfiguracji rzeczywistego statusu sklepu. Import i podsumowania synchronizacji przekazuja tez `current_state`, `total_paid_tax_incl` i `id_currency`. Deklaracja pelnego oplacenia jest rozdzielona od natywnych `order_payments`; zapisujemy `external_payment_paid`, `status_paid_amount` i `status_paid_at` na istniejacym powiazaniu zamowienia. Brak rekordu wplaty nie tworzy sztucznego `external_payment_id` ani `transaction_id`.

Obserwacja, uznanie salda, zdarzenie zamowienia i after-commit event sa chronione transakcja oraz blokadami powiazania i zamowienia. `OrderPaymentRegistered` zachowuje dotychczasowe UUID dla wszystkich starych wywolan; opcjonalna tozsamosc `prestashop-paid-status:<link-id>` zapobiega kolizji z natywnym rekordem platnosci przy zachowaniu zrodla `prestashop` w automatyzacjach. Pozniejsze natywne rekordy mieszczace sie lacznie w juz uznanej kwocie sa `mirrored` ze zrodlem `prestashop_status`, bez nowego naliczenia lub zdarzenia. Kontrole nadplat, walut, zmian kwot i wycofanych rekordow nadal dzialaja. Dozwolone uzupelnienie pustego ID nie pozwala podmieniac juz znanego ID.

Nowy nullable wynik obserwacji pozwala stopniowo sprawdzic stare powiazania poza kursorem `date_upd`: maksymalnie 10 najnowszych na przebieg, bez zamowien usunietych lub innego zrodla. Nie zerujemy kursora i nie zmieniamy danych biznesowych w migracji. Sprawdzenie dostepnosci kolumn chroni okres przed migracja przed czesciowym wlaczeniem nowej polityki.

Ustawienie `prestashop_accounts.warn_missing_transaction_id` jest bool, domyslnie false. Warunek ikony czyta flage sklepu i natywne ID transakcji z bazy, nie wykonuje HTTP i nie zmienia stanu platnosci. Endpoint stanu porownuje flage ostrzezenia klienta, aby odswiezyc fragment informacji i platnosci takze przy samym uzupelnieniu ID. Istniejacy initializer i cleanup tooltipow obejmuje nowa ikone, lacznie z obsluga focus/click.

Migracja `2026_10_26_010000_add_prestashop_paid_status_tracking.php` jest addytywna; `down()` odmawia usuniecia zapisanej historii deklaracji. Nie zmienia historii faktur, numeracji, KSeF ani ustawien innych integracji.

---

# 49. Analityka — etap ANALYTICS.1

Moduł `Modules/Analytics` zawiera kontrolery, Form Request, resolver okresów, warstwę filtrów oraz serwis budujący raporty. Trasy pozostają centralnie w `routes/web.php`, a widok znajduje się w `resources/views/analytics/basic.blade.php`. Moduł nie posiada własnego providera ani osobnych migracji modułowych.

`AnalyticsFilterService` jest wspólną granicą filtrowania. Stosuje walutę, statusy, źródła i przedział dat do zapytań `Order`, rozdziela konta PrestaShop przez `prestashop_order_links` i pomija rekordy usunięte przez globalny zakres `SoftDeletes`. Data raportowa wykorzystuje `purchased_at`, a przy jej braku `created_at`.

`BasicStatisticsService` strumieniuje zamówienia i pozycje partiami, a następnie buduje wyłącznie agregaty potrzebne aktywnemu raportowi. Kwoty są przeliczane na całkowite grosze przy użyciu `brick/math`; wykresy otrzymują wartości w groszach, a prezentacja przelicza je wyłącznie do formatowania. Eksport CSV używa tych samych agregatów i filtrów co ekran.

Warstwa widoku korzysta z Chart.js oraz `chartjs-chart-geo` ładowanych z przypiętych wersji CDN. Dane wykresów pochodzą wyłącznie z JSON wygenerowanego przez serwer. Mapa krajów pobiera publiczny atlas świata; niedostępność atlasu nie wpływa na pozostałe raporty ani eksport CSV. Indeksy analityczne obejmują daty zakupu i utworzenia w kontekście waluty, status i źródło oraz relację pozycji z produktem.

---

# Integracja Allegro — etap ALLEGRO.2A

`AllegroClient::order()` odczytuje `GET /order/checkout-forms/{id}` z istniejącą obsługą OAuth. Log odpowiedzi obejmuje wyłącznie ID, status, revision i updatedAt, bez danych adresowych i wiadomości kupującego. `AllegroOrderPreviewService` normalizuje dane, uzgadnia kwoty za pomocą istniejących serwisów decimal i wylicza SHA-256 znormalizowanego snapshotu. Walidacja pieniędzy nie przyjmuje `float`. Daty wymagają poprawnej daty kalendarzowej i formatu ISO 8601.

Potwierdzenie podglądu jest przechowywane w sesji przez 15 minut i związane z kontem, seller_user_id, środowiskiem, UUID oraz hashem. Kontroler POST wymaga tego potwierdzenia. `AllegroOrderImportService` najpierw rozpoznaje istniejący import, a dla nowego zamówienia ponownie pobiera dane i porównuje hash. Żądanie API odbywa się przed transakcją bazy. Zapis blokuje konto i ponownie sprawdza jego tożsamość oraz duplikaty.

Migracja `2026_10_19_040000_create_allegro_order_import_tables` tworzy `allegro_order_links` i `allegro_order_payments`. Unikalność importu obejmuje `(environment, seller_user_id, external_order_id)`, niezależnie od lokalnego ID konta. `order_id` ma osobną unikalność. Klucze do konta i zamówienia używają `nullOnDelete`; powiązanie z płatnościami ma `restrictOnDelete`. Dzięki temu usunięcie źródła nie usuwa historii i nie otwiera ponownie możliwości importu tego samego zamówienia.

Zapis w jednej transakcji obejmuje `orders`, `order_items`, powiązanie, wpłaty i jedno zdarzenie historii. Błąd zapisu wycofuje cały import. `order_items.external_id` przechowuje ID pozycji Allegro, `offer_id` numer oferty, a `product_id` pozostaje null. Dane Smart, żądania faktury, wiadomości kupującego, konta i rewizji znajdują się w powiązaniu, bez rozszerzania głównej tabeli zamówień. `orders.notes` nie jest używane dla wiadomości kupującego.

`InvoicePaymentIdentifierResolver` rozpoznaje zaksięgowane wpłaty Allegro. Istniejący mechanizm wystawiania dokumentu zapisuje ich ID w niezależnym snapshotcie; wcześniejsze dokumenty nie są modyfikowane. Widoki zamówień dołączają relacje konta i płatności, prezentują nazwę integracji, Smart, żądanie faktury i wiadomość oraz bezpieczny link do oferty.

Import ręczny nie emituje `OrderStatusChanged` ani `OrderPaymentRegistered`, nie zleca zadań, nie zmienia magazynu i nie wysyła niczego do Allegro. Nie dodano providera, dodatkowych zależności ani osobnego routingu. Trasy pozostają w `routes/web.php`.

---

# Integracja Allegro — etap ALLEGRO.2B

Migracja `2026_10_19_050000_add_allegro_product_links_and_import_settings` dodaje tabelę `allegro_product_links` i trzy ustawienia boolean na `allegro_accounts`: `use_linked_products`, `use_catalog_product_names` i `use_catalog_vat`, domyślnie true. Powiązania są konfiguracją, nie historią dokumentową: klucze do produktu i konta mają `cascadeOnDelete`. Historyczne `order_items.product_id` nadal korzysta z istniejącego `nullOnDelete`, a nazwa, VAT i ceny pozostają w pozycji.

Numery ofert są stringami do 32 cyfr, bez konwersji do JavaScript Number i bez utożsamiania z `product_id` OMS. Indeks unikalny obejmuje `(allegro_account_id, environment, seller_user_id, external_offer_id)`. `AllegroProductLinkService` blokuje konto przed dodawaniem, edycją i usuwaniem, sprawdza tożsamość oraz przynależność powiązania do produktu. Baza zapewnia końcową ochronę unikalności. Zapisy nie wywołują API Allegro; klikalny URL powstaje z kontrolowanego hosta produkcji albo sandbox.

Interfejs produktów rozszerza istniejący modal zamiast tworzyć równoległy ekran. Typ integracji i ID konta są osobnymi częściami kontekstu, dzięki czemu identyczne lokalne ID kont Allegro i PrestaShop nie kolidują. Odpowiedzi AJAX obu integracji renderują wspólny partial z obiema grupami. `AllegroOfferUrl` jest wspólnym generatorem URL dla historycznego powiązania zamówienia i konfiguracji produktu.

`AllegroOrderCatalogService` dopasowuje wyłącznie ręczne powiązania dla aktualnego konta, sprzedawcy i środowiska. Wzbogaca podgląd o docelowe `product_id`, nazwę, VAT, metadane miniatury i skład zestawu, nigdy o cenę katalogową. Używa istniejącej walidacji VAT i odczytu składu z `OrderItemBundleSnapshotService::components()`. Wadliwy zestaw blokuje import przed zapisem.

Hash podglądu obejmuje wynik dopasowania i ustawienia. Import ponownie wykonuje podgląd z API, a następnie w transakcji po blokadzie konta powtarza dopasowanie z blokadami powiązań, produktów i składu. Osobny `catalog_hash` wykrywa zmianę między ponownym podglądem a transakcją. Dopiero po tej kontroli powstają pozycje i snapshoty składu. Ceny i stany katalogu nie uczestniczą w hashu, ponieważ nie są źródłem danych finansowych importu.

Przenoszenie VAT jest ograniczone do jawnego `allegro-pl` i krajów dostawy oraz faktury `PL`; rynek bazowy sprzedawcy nie jest dowodem rynku zamówienia. Dla pozostałych przypadków stawka pozostaje null z ostrzeżeniem. Nie dodano silnika podatkowego, OSS ani nowych uprawnień OAuth.

Wcześniejsze zamówienia i faktury nie są automatycznie aktualizowane. Zdjęcie w podglądzie i karcie zamówienia korzysta z istniejącego kontrolera obrazów produktu; nie tworzono historycznego archiwum zdjęć. Nie dodano nowych zadań, listenerów, providerów, zależności ani zapisów do zewnętrznych API.

---

# Integracja Allegro - obowiązkowy User-Agent

Każde żądanie HTTP do API lub OAuth Allegro musi wysyłać jawny, niepusty nagłówek `User-Agent`. Reguła dotyczy wszystkich obecnych i przyszłych funkcji, produkcji i sandbox, interfejsu, kolejki i harmonogramu oraz każdego ponowienia żądania.

Wartość jest utrzymywana wyłącznie w `config/services.php` pod kluczem `services.allegro.user_agent`:

```text
NEX-OMS_device/v1.0.0 (+https://multi-click.pl/nex-oms/doc.html)
```

`AllegroClient`, `AllegroOAuthService`, `AllegroShippingClient` i `AllegroShippingDeviceAuthService` używają `withUserAgent((string) config('services.allegro.user_agent'))`. Obejmuje to odczyt zamówień i rozliczeń, testy połączenia, OAuth Device Flow i odnowienie tokenów, operacje przesyłkowe oraz pobieranie binarnych etykiet. Nagłówek jest wymagany także przy starszej konfiguracji kurierskiej i ponowieniu po HTTP 401.

Nowe połączenia z Allegro muszą korzystać z tej samej konfiguracji; nie wolno polegać na domyślnym nagłówku Guzzle/cURL ani powielać wartości w klientach produkcyjnych. Zmiana zatwierdzonej wartości wymaga decyzji właściciela. Stała reguła dla agentów znajduje się również w sekcji 45 `AGENTS.md`.

`tests/Feature/AllegroSettingsTest.php` i `tests/Feature/AllegroShippingTest.php` weryfikują dokładną wartość nagłówka na wszystkich żądaniach w objętych testami przepływach, także podczas autoryzacji, pobierania etykiet i odnowienia tokenów z ponowieniem. Nowe ścieżki komunikacji wymagają analogicznej kontroli. Testy używają symulowanego HTTP i nie stanowią potwierdzenia rzeczywistej akceptacji żądania przez Allegro.

Po zmianie konfiguracji należy odświeżyć jej cache, jeżeli jest używany, oraz ponownie uruchomić długo działające workery, aby wczytały nową wartość. Nie wymaga to migracji ani ponownego udzielenia uprawnień kontu Allegro.

---

# Integracja Allegro - ALLEGRO.2C

Migracja 2026_10_20_150000_add_allegro_automatic_order_import dodaje konfiguracje kursora na allegro_accounts, stan kompletowania na allegro_order_links i trwaly dziennik allegro_order_import_events. Historyczne powiazania otrzymuja completed_at z imported_at. Domyslny import_state=complete zachowuje kompatybilnosc starych zapisow. Nowy import jawnie wybiera incomplete, complete albo review. Anulowanie i polaczenie ustawiaja cancelled/superseded. Klucze historyczne sa nullable z nullOnDelete; replaced_by_link_id wskazuje nastepce, nie zmienia tozsamosci starej transakcji.

DiscoverAllegroOrdersJob przetwarza jedna strone do 100 zdarzen na przebieg, aby limit 80 sekund zadania byl nizszy od domyslnego retry_after=90 kolejki database. Nastepna minuta kontynuuje od zapisanego kursora. Strona zapisuje zdarzenia i kursor w jednej transakcji z blokada konta; zlecenie kolejki nastepuje po zapisie. Unikalny indeks zdarzenia obejmuje srodowisko, sprzedawce i ID zdarzenia. Kolejny przebieg odzyskuje niezlecone/nieudane proby, do 4 podejsc z odstepem. Bez wznowienia przez operatora nie przeskakuje starego lub blednego kursora. Komunikacja uzywa AllegroClient i obowiazkowego User-Agent, a log HTTP zawiera liczbe zdarzen bez danych kupujacych.

SyncAllegroOrderJob ma wspolna blokade na tozsamosc sprzedawcy i UUID zamowienia. AllegroOrderSyncService zawsze odczytuje aktualny checkout-form zamiast odtwarzac kolejnosc stanowa z typow zdarzen. AllegroOrderPreviewService::normalize zachowuje walidacje kompletnego zamowienia; provisional jawnie dopuszcza brak danych dostawy i nie ksieguje niepotwierdzonych platnosci. AllegroOrderImportService wspoldzieli zapis z importem recznym. Aktualizacja niepelnego zakupu blokuje konto i zamowienie, zachowuje notes/status/star_color, odtwarza pozycje i sklad zestawow oraz atomowo zapisuje kompletne wplaty. Import reczny nadal wymaga potwierdzenia hasha i nie nadpisuje istniejacego rekordu.

Order::assertPurchaseReady jest sprawdzane w przygotowaniu dokumentow, rozpoczeciu tworzenia przesylki i wykonaniu automatyzacji oraz na sciezkach edycji danych. Relacja do Faktur pozostaje hasMany; dokumenty nie sa synchronizowane. Kompletnosc, status roboczy i platnosc sa niezalezne. Lista uzywa eager loading/withExists dla ikon i uwzglednia zmiany powiazan oraz Faktur w sygnaturze odswiezania. Komentarz Allegro pozostaje w buyer_message; Blade escapuje tresc i zachowuje podzial wierszy. Pokazywany czas jest data zakupu, nie wymyslona data utworzenia komentarza.

Testy korzystaja z SQLite :memory: i symulowanego HTTP. Funkcja automatyczna jest po migracji wylaczona. Nie dodano zaleznosci ani providera modulu; trasy i harmonogram pozostaja centralne.

---

# Integracja Allegro - ALLEGRO.3A

Migracja `2026_10_21_010000_add_allegro_outbound_status_sync` dodaje na koncie domyslnie wylaczony `status_sync_enabled`, nullable `status_sync_identity` i licznik `status_sync_revision`. Konfiguracja `allegro_outbound_status_mappings` ma unikalnosc `(allegro_account_id, environment, seller_user_id, order_status_setting_id)`. Brak wiersza oznacza "Nie przekazuj". Relacje konfiguracji maja cascadeOnDelete; usuniecie mapy lub konta nie usuwa zamowien, dokumentow ani order_events. Historyczne powiazanie Allegro zachowuje istniejace nullOnDelete dla konta.

Lista pochodzi z `OrderStatusSetting::orderedSettings()`, a zapis waliduje aktualne ID nieusunietych statusow. Nie ma blokady new ani zadnego innego statusu importu. `AllegroFulfillmentStatus` opisuje osiem zapisywalnych stanow Allegro; RETURNED jest celowo wykluczony. `AllegroStatusMappingService` blokuje konto i sprawdza tozsamosc oraz optimistic lock konfiguracji. Kazdy zapis zwieksza revision i uniewaznia dawne zadania bez backfillu. Rozlaczenie lub zmiana tozsamosci wylacza eksport i zwieksza revision.

`OrderStatusChanged` jest istniejacym zdarzeniem ShouldDispatchAfterCommit. Listener `DispatchAllegroStatusSync` pomija zrodlo allegro, pozostale zmiany przekazuje do `AllegroOutboundStatusSyncService::enqueue`. Powiazanie zamowienia przechowuje nullable token zdarzenia, status OMS, cel, stan pending/synced/failed/skipped, blad i czas sukcesu. Kazda nowsza zmiana uniewaznia poprzedni token, rowniez gdy jej status jest niemapowany; powtorzenie tego samego zdarzenia nie zleca nowej wysylki. Zadanie jest wysylane po zatwierdzeniu transakcji do istniejacej kolejki integrations, nie przy samym zapisie map.

`SyncAllegroOrderStatusJob` ma unikalnosc tokenu, cztery proby, timeout 80 s oraz odstepy 15/60/180 s. Wspoldzieli WithoutOverlapping z `SyncAllegroOrderJob` po srodowisku, sprzedawcy i UUID zamowienia. Przed HTTP sprawdza aktualny lokalny status, token, konto, operacyjnosc polaczenia, kompletne powiazanie i wersje map. Pobiera GET checkout-form, wymaga zgodnego UUID, READY_FOR_PROCESSING i provider SELLER, po czym ponownie sprawdza kontekst lokalny. Wykryte checkout.status=CANCELLED przekazuje do istniejacego serwisu anulowania, bez PUT i bez usuwania historii lub dokumentow.

Zapis uzywa `PUT /order/checkout-forms/{id}/fulfillment?checkoutForm.revision={revision}` i JSON zawierajacego tylko status. AllegroClient zachowuje centralny OAuth, timeout, media type i jawny User-Agent z services.allegro.user_agent, takze po HTTP 401. HTTP 409, 429, 5xx i bledy polaczenia pozwalaja kolejce ponowic zadanie od nowego GET; pozostale bledy sa zapisywane bez automatycznego powtarzania. Gdy Allegro ma juz docelowy status, zapis jest pomijany. Sukces aktualizuje fulfillment_status i zapisuje order_event, nie zmienia statusu OMS ani snapshotow faktur. Powtorzenie zakonczonego zadania nie tworzy kolejnego zdarzenia. Nie ma rozproszonej transakcji z Allegro; zmiany juz przyjetej przez API nie da sie cofnac samym wylaczeniem konfiguracji.

Ponowienie z karty zamowienia blokuje powiazanie i zamowienie, wymaga stanu failed zgodnego z aktualnym statusem oraz biezacej, operacyjnej konfiguracji. Niedozwolona proba nie czysci poprzedniego bledu. Kontroler sprawdza tozsamosc konta; trasy pozostaja w routes/web.php i maja standardowa ochrone CSRF. Uwierzytelnianie calego OMS pozostaje osobnym etapem, zgodnie z dotychczasowym stanem aplikacji.

Testy ALLEGRO.3A uzywaja SQLite :memory: i symulowanego HTTP; zadnego statusu na rzeczywistym koncie nie zmieniono. Referencje protokolu: https://developer.allegro.pl/tutorials/jak-obslugiwac-zamowienia-GRaj0qyvwtR oraz https://developer.allegro.pl/swagger.yaml (CheckoutFormFulfillment, CheckoutFormFulfillmentStatus).

---

# Integracja Allegro - ALLEGRO.3B

Migracja `2026_10_21_020000_add_allegro_shipment_exports` dodaje `shipment_sync_mode` (disabled/immediate/status, domyslnie disabled), nullable `shipment_sync_status_setting_id` z nullOnDelete, `shipment_sync_identity` i `shipment_sync_revision` do allegro_accounts. `AllegroShipmentSettingsService` zapisuje konfiguracje pod blokada konta, z kontrola tozsamosci i wersji. Zapis uniewaznia waiting/pending bez backfillu. Zmiana tozsamosci lub rozlaczenie resetuja tryb, tozsamosc i zwiekszaja revision; ponowny test tego samego sprzedawcy zachowuje konfiguracje. Docelowy status jest rzeczywistym rekordem OrderStatusSetting, nie sztywna nazwa lub kodem z integracji.

`shipment_parcels.tracking_numbers` przechowuje nullable liste par carrier_id/waybill. Istniejace tracking_number pozostaje kompatybilnym numerem glownym. `AllegroShippingSynchronizer` normalizuje wszystkie transportingInfo, ignoruje puste carrierWaybill i wybiera spojna pare glowna, preferujac ALLEGRO, gdy jest dostepna. Starsze packages.waybill pozostaje fallbackiem. `AllegroShipmentNumbers` wspoldzieli normalizacje i odczyt aktualnych numerow, bez rzutowania ich na liczby.

`AllegroShipmentTrackingObserver` obserwuje Shipment i ShipmentParcel. Reaguje na roznice numerow/aliasow, a nie na zwykle odswiezenie statusu lub wymiarow. Zachowuje kompatybilnosc istniejacego ShipmentCreated i automatyzacji. `AllegroShipmentExportService` zapisuje trwale zgloszenie dla nowej pary numer/przewoznik i lokalnej przesylki, konta/srodowiska/sprzedawcy/checkout-form. Unikalny SHA-256 fingerprint chroni przed wielokrotnym zapisem tego samego zgloszenia z modelu glownego i paczki. Nowsze numery nie nadpisuja historii starszych.

Tabela `allegro_shipment_exports` ma nullable relacje do konta, powiazania Allegro, zamowienia i przesylki, wszystkie z nullOnDelete. Utrwala tozsamosc zrodla, przewoznika, numer, aliasy tej samej paczki, verification_only, wersje konfiguracji, token proby, state waiting/pending/synced/failed/skipped, blad, remote_id i confirmed_at. Nie zmienia statusow transportu ani danych finansowych. Powodzenie jest zapisywane w tej samej transakcji co order_event; payload zawiera ID eksportu oraz potwierdzona pare, zamiast klucza shipment_id usuwanego przez dotychczasowy ShipmentDeletionService.

Dispatch odbywa sie w DB::afterCommit do istniejacej kolejki integrations. Statusowy listener istniejacego OrderStatusChanged zwalnia tylko zapisane zgloszenia waiting, bez skanowania dawnych przesylek. `ExportAllegroShipmentJob` jest unikalny dla tokenu, ma cztery proby, timeout 80 s, backoff 15/60/180 s i wspoldzieli blokade allegro-order:environment:seller:checkout z importem oraz eksportem statusow. Kazda proba sprawdza biezaca konfiguracje, relacje, kompletne powiazanie, aktywny numer i ewentualna bramke statusowa. Stare, anulowane i obce zrodla sa pomijane; opuszczenie bramki przywraca waiting.

Przed zapisem wykonywane sa GET checkout-form oraz GET /order/checkout-forms/{id}/shipments. Wymagane sa zgodny UUID, READY_FOR_PROCESSING, SELLER i brak fulfillment.CANCELLED. Wykryte checkout.CANCELLED korzysta z istniejacego serwisu anulowania. POST /order/checkout-forms/{id}/shipments otrzymuje carrierId, waybill oraz wszystkie aktualne zrodlowe lineItem.id. Lista przewoznikow pochodzi z GET /order/carriers. Przed POST kontekst lokalny jest ponownie sprawdzany. Niepoprawna struktura danych nie jest traktowana jako pusta lista i nie prowadzi do slepego dodawania.

Wysylam z Allegro jest sciezka verification_only; zadanie nigdy nie wywoluje POST. Do potwierdzenia paczki moze posluzyc jeden z jej rownowaznych numerow, ale musi obejmowac wszystkie pozycje zrodlowego zamowienia. Historia zapisuje rzeczywista odnaleziona pare carrier/waybill, zamiast twierdzic, ze osobno dopisano alias. Nie dodano przydzielania produktow do paczek. Potwierdzenie statusu realizacji SENT pozostaje niezaleznym ALLEGRO.3A lub ustawieniem Allegro.

HTTP 401 zachowuje istniejace odnowienie OAuth wraz z metoda POST i JSON. HTTP 409, 429, 5xx oraz blad polaczenia sa ponawiane od nowych GET; bledy stale wymagaja recznego ponowienia. Brak jednoznacznego potwierdzenia POST jest stanem do ponownego odczytu, nie powodem kolejnego slepego POST. Nowe operacje HTTP loguja tylko liczbe obiektow, bez numerow, adresow i body. Kazda sciezka zachowuje User-Agent z services.allegro.user_agent.

Scheduler `allegro-recover-shipment-exports` co minute przeglada do 100 zapisanych zgloszen. Moze zwolnic waiting z otwarta bramka oraz wznowic pending starsze niz 15 minut (po wygasnieciu 10-minutowej blokady unikalnosci). Dotyka updated_at, aby nie ponawiac tego samego rekordu co minute; waiting sa rotowane po czasie aktualizacji. Nie wykonuje HTTP, nie odzyskuje failed i nie wyszukuje historycznych numerow. Wysylka jest co najmniej jednokrotna na poziomie kolejki; odczyt Allegro i wspolna blokada zapobiegaja ponownemu dopisywaniu juz przypisanego numeru.

UI rozszerza istniejaca zakladke konfiguracji i partial shipment-row. OrdersController doladowuje eksporty oraz relacje potrzebne do renderowania. Stan moze wymagac odswiezenia otwartej karty; nie dodano nowego pollingu. Trasy pozostaja centralne, z ochrona CSRF i kontrola tozsamosci przy ponowieniu. Uwierzytelnianie calego OMS nadal nalezy do osobnego etapu przed udostepnieniem publicznym.

Nie istnieje atomowa transakcja z Allegro: wylaczenie konfiguracji nie cofa POST juz przyjetego przez API. Takie rzeczywiste potwierdzenie jest zachowywane, nawet jesli lokalna konfiguracja zmienila sie podczas HTTP. Rzeczywistego POST nie wykonywano podczas wdrozenia; testy korzystaja z SQLite :memory: i symulowanego HTTP.

---

# Integracja Allegro - ALLEGRO.3B

Migracja `2026_10_21_020000_add_allegro_shipment_exports` dodaje `shipment_sync_mode` (disabled/immediate/status, domyslnie disabled), nullable `shipment_sync_status_setting_id` z nullOnDelete, `shipment_sync_identity` i `shipment_sync_revision` do allegro_accounts. `AllegroShipmentSettingsService` zapisuje konfiguracje pod blokada konta, z kontrola tozsamosci i wersji. Zapis uniewaznia waiting/pending bez backfillu. Zmiana tozsamosci lub rozlaczenie resetuja tryb, tozsamosc i zwiekszaja revision; ponowny test tego samego sprzedawcy zachowuje konfiguracje. Docelowy status jest rzeczywistym rekordem OrderStatusSetting, nie sztywna nazwa lub kodem z integracji.

`shipment_parcels.tracking_numbers` przechowuje nullable liste par carrier_id/waybill. Istniejace tracking_number pozostaje kompatybilnym numerem glownym. `AllegroShippingSynchronizer` normalizuje wszystkie transportingInfo, ignoruje puste carrierWaybill i wybiera spojna pare glowna, preferujac ALLEGRO, gdy jest dostepna. Starsze packages.waybill pozostaje fallbackiem. `AllegroShipmentNumbers` wspoldzieli normalizacje i odczyt aktualnych numerow, bez rzutowania ich na liczby.

`AllegroShipmentTrackingObserver` obserwuje Shipment i ShipmentParcel. Reaguje na roznice numerow/aliasow, a nie na zwykle odswiezenie statusu lub wymiarow. Zachowuje kompatybilnosc istniejacego ShipmentCreated i automatyzacji. `AllegroShipmentExportService` zapisuje trwale zgloszenie dla nowej pary numer/przewoznik i lokalnej przesylki, konta/srodowiska/sprzedawcy/checkout-form. Unikalny SHA-256 fingerprint chroni przed wielokrotnym zapisem tego samego zgloszenia z modelu glownego i paczki. Nowsze numery nie nadpisuja historii starszych.

Tabela `allegro_shipment_exports` ma nullable relacje do konta, powiazania Allegro, zamowienia i przesylki, wszystkie z nullOnDelete. Utrwala tozsamosc zrodla, przewoznika, numer, aliasy tej samej paczki, verification_only, wersje konfiguracji, token proby, state waiting/pending/synced/failed/skipped, blad, remote_id i confirmed_at. Nie zmienia statusow transportu ani danych finansowych. Powodzenie jest zapisywane w tej samej transakcji co order_event; payload zawiera ID eksportu oraz potwierdzona pare, zamiast klucza shipment_id usuwanego przez dotychczasowy ShipmentDeletionService.

Dispatch odbywa sie w DB::afterCommit do istniejacej kolejki integrations. Statusowy listener istniejacego OrderStatusChanged zwalnia tylko zapisane zgloszenia waiting, bez skanowania dawnych przesylek. `ExportAllegroShipmentJob` jest unikalny dla tokenu, ma cztery proby, timeout 80 s, backoff 15/60/180 s i wspoldzieli blokade allegro-order:environment:seller:checkout z importem oraz eksportem statusow. Kazda proba sprawdza biezaca konfiguracje, relacje, kompletne powiazanie, aktywny numer i ewentualna bramke statusowa. Stare, anulowane i obce zrodla sa pomijane; opuszczenie bramki przywraca waiting.

Przed zapisem wykonywane sa GET checkout-form oraz GET /order/checkout-forms/{id}/shipments. Wymagane sa zgodny UUID, READY_FOR_PROCESSING, SELLER i brak fulfillment.CANCELLED. Wykryte checkout.CANCELLED korzysta z istniejacego serwisu anulowania. POST /order/checkout-forms/{id}/shipments otrzymuje carrierId, waybill oraz wszystkie aktualne zrodlowe lineItem.id. Lista przewoznikow pochodzi z GET /order/carriers. Przed POST kontekst lokalny jest ponownie sprawdzany. Niepoprawna struktura danych nie jest traktowana jako pusta lista i nie prowadzi do slepego dodawania.

Wysylam z Allegro jest sciezka verification_only; zadanie nigdy nie wywoluje POST. Do potwierdzenia paczki moze posluzyc jeden z jej rownowaznych numerow, ale musi obejmowac wszystkie pozycje zrodlowego zamowienia. Historia zapisuje rzeczywista odnaleziona pare carrier/waybill, zamiast twierdzic, ze osobno dopisano alias. Nie dodano przydzielania produktow do paczek. Potwierdzenie statusu realizacji SENT pozostaje niezaleznym ALLEGRO.3A lub ustawieniem Allegro.

HTTP 401 zachowuje istniejace odnowienie OAuth wraz z metoda POST i JSON. HTTP 409, 429, 5xx oraz blad polaczenia sa ponawiane od nowych GET; bledy stale wymagaja recznego ponowienia. Brak jednoznacznego potwierdzenia POST jest stanem do ponownego odczytu, nie powodem kolejnego slepego POST. Nowe operacje HTTP loguja tylko liczbe obiektow, bez numerow, adresow i body. Kazda sciezka zachowuje User-Agent z services.allegro.user_agent.

Scheduler `allegro-recover-shipment-exports` co minute przeglada do 100 zapisanych zgloszen. Moze zwolnic waiting z otwarta bramka oraz wznowic pending starsze niz 15 minut (po wygasnieciu 10-minutowej blokady unikalnosci). Dotyka updated_at, aby nie ponawiac tego samego rekordu co minute; waiting sa rotowane po czasie aktualizacji. Nie wykonuje HTTP, nie odzyskuje failed i nie wyszukuje historycznych numerow. Wysylka jest co najmniej jednokrotna na poziomie kolejki; odczyt Allegro i wspolna blokada zapobiegaja ponownemu dopisywaniu juz przypisanego numeru.

UI rozszerza istniejaca zakladke konfiguracji i partial shipment-row. OrdersController doladowuje eksporty oraz relacje potrzebne do renderowania. Stan moze wymagac odswiezenia otwartej karty; nie dodano nowego pollingu. Trasy pozostaja centralne, z ochrona CSRF i kontrola tozsamosci przy ponowieniu. Uwierzytelnianie calego OMS nadal nalezy do osobnego etapu przed udostepnieniem publicznym.

Nie istnieje atomowa transakcja z Allegro: wylaczenie konfiguracji nie cofa POST juz przyjetego przez API. Takie rzeczywiste potwierdzenie jest zachowywane, nawet jesli lokalna konfiguracja zmienila sie podczas HTTP. Rzeczywistego POST nie wykonywano podczas wdrozenia; testy korzystaja z SQLite :memory: i symulowanego HTTP.

---

# Integracja Allegro - ALLEGRO.3C

Addytywna migracja 2026_10_21_030000_add_allegro_invoice_exports dodaje na allegro_accounts invoice_sync_mode (disabled/immediate/status), nullable invoice_sync_status_setting_id z nullOnDelete, invoice_sync_only_required (false), invoice_sync_identity i invoice_sync_revision. AllegroInvoiceSettingsService waliduje aktualne statusy oraz optimistic lock konfiguracji i nie zleca pracy historycznej. Rozlaczenie, zmiana sprzedawcy lub danych autoryzacji wylaczaja automat i zwiekszaja revision.

allegro_invoice_exports przechowuje nullable FK do konta, powiazania Allegro, zamowienia i Faktury (nullOnDelete), trwala tozsamosc sprzedawcy/srodowiska/checkout-form, numer dokumentu, unikalny fingerprint, decyzje manual, revision, token i stan. Zachowuje document_hash snapshotow i pozycji, pdf_checksum, prywatny pdf_path, unikatowy file_name, rozmiar, remote_id, zamiar uploadu, daty wysylki/potwierdzenia i licznik oraz termin kontroli. Usuwanie dokumentu nie kasuje tej historii ani kopii pliku.

DispatchAllegroInvoiceExports reaguje na istniejace InvoiceIssued, OrderStatusChanged i KsefInvoiceAccepted. Outbox oraz dispatch po commit korzystaja z istniejacej kolejki integrations. Zmiana statusu kwalifikuje dokument wedlug aktualnych ustawien; moze wznowic pominiete, jeszcze niewyslane zgodne zgloszenie dopiero jako nowe zdarzenie, nie na skutek zapisu ustawien. Akceptacja KSeF wznawia tylko znane zgloszenia oczekujace, bez skanowania wszystkich dawnych Faktur. Reczna decyzja posiada potwierdzenie i kontrole wersji dokumentu; automat pozostaje niezaleznie wylaczony.

AllegroInvoiceDocumentService wykorzystuje InvoicePdfRenderer (TCPDF) lub KsefAcceptedOfflineInvoicePdfService. Nie dodaje drugiego renderera ani nie uzywa cache potencjalnego podgladu sprzed akceptacji KSeF. Weryfikuje stan KSeF i srodowisko, blokuje preview_warning oraz test_mark dla produkcyjnego Allegro. Kopia jest prywatna na dysku local w allegro/invoice-exports, ma limit 3 000 000 bajtow i SHA-256. Zmiana tresci przed uploadem blokuje uzycie przygotowanej kopii; po uploadzie jest widoczna jako rozbieznosc bez podmiany.

AllegroClient zostal rozszerzony o GET /order/checkout-forms/{id}/invoices i multipart POST /order/{orderId}/billing-documents/files. Wspoldzieli OAuth, kontrolowany timeout i User-Agent z services.allegro.user_agent, takze po 401. Nowe operacje loguja wylacznie bezpieczna liczbe dokumentow, bez PDF, nazw plikow i numerow Faktur. Uzywane sa istniejace orders:read/orders:write, bez nowego scope.

AllegroInvoiceExportService przed pierwszym POST sprawdza tozsamosc, revision (takze dla decyzji manual), dokument, kompletne powiazanie i aktualny checkout-form READY_FOR_PROCESSING/SELLER. Sprawdza liste faktur; obcy istniejacy dokument nie jest automatycznie adoptowany. Po przygotowaniu prywatnej kopii zapisuje upload_started_at przed HTTP. Timeout, bledy 5xx, 409 lub brak jednoznacznego ID uruchamiaja tylko reconciliation. Jednoznaczne bledy odrzucenia, w tym 429, moga zwolnic zamiar, aby ponowic identyczny plik.

ExportAllegroInvoiceJob ma 4 proby, timeout 80 s, uniqueFor 600 s oraz backoff 15/60/180 s. Uzywa wspolnego WithoutOverlapping allegro-order:environment:seller:checkout z blokada 180 s. Recovery co minute przeglada do 100 znanych zgloszen, nie wszystkie Faktury. Pending/uploading starsze niz 15 minut mozna wznowic; zachowany upload_started_at wymusza odczyt zamiast kolejnego POST. Stan verifying/uncertain ma ograniczone do 10 automatycznych odczytow i mozliwosc recznego sprawdzenia. Utrata polaczenia/tozsamosci nie pozwala sprawdzac obcego konta.

Reconciliation wymaga zgodnego ID (jesli znane), numeru, unikatowej nazwy i danych uploadu. Po timeoutach nazwa z losowym UUID i fragmentem SHA-256 laczy rekord z plikiem; samo invoiceNumber nie wystarcza. file.securityVerification.status=WAITING pozostaje verifying, ACCEPTED daje synced i confirmed_at, REJECTED daje failed bez nowego uploadu. API nie udostepnia sumy PDF w tej liscie, wiec nie jest to kryptograficzny dowod zgodnosci zdalnych bajtow. Nie ma wspolnej transakcji pliku, SQLite i Allegro; rzeczywiste pozne potwierdzenia sa zachowywane.

AllegroInvoiceExportView rozszerza OrderSalesDocumentActionsView, dzieki czemu stan jest spojny na karcie i po istniejacych odswiezeniach AJAX. Kontrolery sa w module Allegro, trasy pozostaja centralnie w routes/web.php, z CSRF, kontrola relacji, tozsamosci i jawnym potwierdzeniem. Nie zmieniono regul edycji/usuwania Faktur ani ikony ich wystawienia. Uwierzytelnianie calego OMS pozostaje osobnym etapem.

## ALLEGRO.3D - zamkniete Korekty

Istniejacy eksport dokumentow zostal rozszerzony, bez drugiego klienta, joba, harmonogramu ani tabeli outbox. Addytywna migracja dodaje do allegro_accounts correction_sync_mode (disabled), nullable correction_sync_status_setting_id (nullOnDelete), correction_sync_identity i correction_sync_revision. Ustawienia Faktur i Korekt sa zapisywane niezaleznie, z kontrola tozsamosci i oczekiwanej wersji. Zmiana jednego typu uniewaznia tylko jego nierozpoczete automatyczne zgloszenia. Zmiana konta lub rozlaczenie wylacza oba typy.

allegro_invoice_exports otrzymuje document_type z default invoice dla istniejacych rekordow oraz nullable source_invoice_number jako tekst historyczny. invoice_id wskazuje wysylany dokument, a dla Korekty nie Fakture zrodlowa. Unikalny fingerprint nadal obejmuje niepowtarzalne ID dokumentu oraz tozsamosc Allegro; kazda Korekta otrzymuje odrebna kopie PDF i zgloszenie.

InvoiceFinalizationService emituje neutralne CorrectionFinalized (ShouldDispatchAfterCommit) tylko po rzeczywistym zamknieciu Korekty i usunieciu jej slotu. Nie tworzy nowego zdarzenia prawnego ani kopii historii dokumentu. Rollback nie uruchamia eksportu, ponowna finalizacja nie emituje zdarzenia. Istniejacy DispatchAllegroInvoiceExports kwalifikuje Korekty z tego zdarzenia lub przyszlego OrderStatusChanged; KsefInvoiceAccepted budzi tylko znany outbox. Sam zapis ustawien nie skanuje dokumentow.

AllegroInvoiceExportService weryfikuje finalized_at i korzysta z centralnego CorrectionSourceStateService do walidacji Faktury zrodlowej i liniowego lancucha. Dopuszcza complete lub cancelled z zachowanym completed_at, ale odrzuca powiazania zastapione, niepelne, obce i usuniete. Dla Korekt odczyt checkout-form dopuszcza READY_FOR_PROCESSING lub CANCELLED i nadal wymaga SELLER. Istniejace dokumenty nie blokuja nowego POST Korekty; zabezpieczenia przed ponownym POST i reconciliation pozostaja wspolne.

Automatyczna Korekta oczekuje na rekord synced/confirmed_at Faktury zrodlowej o zgodnej pelnej tozsamosci Allegro. Potwierdzenie Faktury budzi znane oczekujace zgloszenia. Reczne potwierdzenie omija ten warunek i bramke statusowa, ale nie finalizacje, spojny lancuch ani KSeF. Nie powstaje automatyczne zgloszenie Faktury zrodlowej. Brak potwierdzenia jest widocznym ostrzezeniem w UI.

AllegroInvoiceDocumentService dopuszcza istniejacy renderer Korekt tylko po zamknieciu i uwzglednia historie/konfiguracje KSeF dokumentu oraz Faktury zrodlowej. Wymagana akceptacja i numer KSeF dotycza samej Korekty. Offline wykorzystuje KsefAcceptedOfflineInvoicePdfService. Stany, weryfikacja PDF, pozne potwierdzenia, limit kontroli, OAuth i User-Agent pozostaja mechanizmami ALLEGRO.3C. OrderSalesDocumentActionsView i wspolny partial pokazuja osobne operacje przy kazdej zamknietej Korekcie, takze po AJAX.

## ALLEGRO.4A - import zwrotow

Migracja 2026_10_22_010000_add_allegro_customer_returns dodaje konfiguracje returns_* do allegro_accounts i tabele order_returns, order_return_items, order_return_parcels, order_return_events. Zwrot ma unikalnosc environment/seller_user_id/external_return_id i nullable relacje do konta oraz zamowienia z nullOnDelete. Pozycje maja nullable relacje do order_items i products z nullOnDelete; owned children zwrotu sa usuwane dopiero z rodzicem. UI nie udostepnia usuwania zwrotu.

AllegroClient wykonuje GET /order/customer-returns i GET /order/customer-returns/{id}, korzystajac z istniejacego orders:read, OAuth i User-Agent z services.allegro.user_agent. Obie operacje wymagaja Accept application/vnd.allegro.beta.v1+json, takze po 401. Logi zawieraja bezpieczny licznik, bez tresci komentarzy, danych bankowych i danych klienta. Kontrakt sprawdzono w publicznym swagger.yaml Allegro 22.10.2026; endpoint jest beta.

AllegroReturnNormalizer waliduje identyfikatory, daty ISO8601, listy, ilosci i kwoty-stringi. BigDecimal liczy kwoty bez float. Wymagana jest jedna waluta zgloszenia; brakujace ceny i niespojne odpowiedzi sa odrzucane. Source hash obejmuje wylacznie znormalizowane pola. Nie zapisujemy calego payloadu, refund.bankAccount, URL oferty ani dowolnych pol zewnetrznych.

AllegroReturnSyncService utrwala zamkniete okno createdAt.gte/lte oraz offset. Pobiera po 100 rekordow; cala strona jest walidowana i zapisywana atomowo wraz z przesunieciem offsetu. Po zakonczeniu cyklu kolejny pelny przeglad wybranego zakresu rozpoczyna sie najwczesniej za 5 minut. Pozwala to odnalezc pozniej udostepnione zgloszenia o starszej dacie, kosztem ponownego odczytu historii. Odczyt HTTP odbywa sie poza transakcja, a aktualna tozsamosc, revision, wlaczenie i cursor sa ponownie weryfikowane pod blokada konta przed zapisem.

Znane zgloszenia maja next_sync_at; harmonogram co minute kolejkuje do 100 naleznych rekordow na konto, niezaleznie od statusu koncowego. DiscoverAllegroReturnsJob i SyncAllegroReturnJob korzystaja z kolejki integrations, unikalnosci zadania oraz wspolnego WithoutOverlapping per konto (expireAfter 120 s). Kolizja nie jest ponownie wykonywana od razu; rekord pozostaje nalezny dla kolejnego przebiegu. Blad odczytu zachowuje stare dane i bezpieczny komunikat; refresh ponawia sie najwczesniej po 5 minutach.

AllegroReturnImportService korzysta tylko z pelnej tozsamosci AllegroOrderLink. Produkt wolno przypisac, gdy offerId wystepuje raz w zgloszeniu i dokladnie jedna pozycja zamowienia ma zgodne offerId, walute, cene i wystarczajaca ilosc. Nazwy nie sluza do dopasowania. Zwrot przechowuje wlasna nazwe/cene/powod/komentarz i pierwszy snapshot adresu zamowienia; karta pokazuje biezacy adres, a po trwalym usunieciu zamowienia jego snapshot. Nie modyfikuje zamowienia ani dokumentow.

OrderReturnController udostepnia tylko GET listy i karty. Trasy sa centralnie w routes/web.php; ustawienia maja osobny PUT z CSRF i optimistic lock. Zwroty nie korzystaja z panelu statusow zamowien. Dane Blade sa escapowane, a wiadomosci pokazywane jako tekst. Karta odczytuje istniejace dokumenty przez chroniona trase PDF i numery paczek przez AllegroShipmentNumbers z fallbackiem historycznego tracking_number. Nie wywoluje API przy otwarciu strony. Uwierzytelnianie calego lokalnego OMS pozostaje odrebnym etapem.

## Zwroty ogolne OMS - rozszerzenie ALLEGRO.4A

Migracja 2026_10_22_020000_generalize_order_returns dodaje return_status_settings i oddzielny local_status_id z nullOnDelete, date lokalnego statusu, creation_method, star_color, notes, lock_version, unikalny nullable request_key oraz creation_fingerprint. Zewnetrzne pola Allegro i offer_id pozycji staja sie nullable, bez tworzenia sztucznych identyfikatorow. Istniejace zgloszenia zachowuja status zewnetrzny i otrzymuja poczatkowy lokalny status Nowe. Migracja nie ma automatycznego down: danych recznych nie mozna bezstratnie przeksztalcic w zgloszenia Allegro.

Migracja 2026_10_22_021000_preserve_legacy_return_import_defaults zachowuje zgodnosc ze starym workerem podczas wdrozenia. Domyslne source=allegro, creation_method=allegro_import i local_status_id=Nowe pozwalaja staremu importerowi zapisac poprawny rekord bez znajomosci nowych kolumn. Serwis reczny zawsze jawnie ustawia te pola. Migracja uzupelnia ewentualne zgloszenia pobrane w oknie wdrozenia, nie kasujac danych. AllegroReturnImportService jawnie oznacza import i inicjalizuje lokalny status tylko przy utworzeniu; aktualizacje nie zmieniaja lokalnego workflow.

StoreOrderReturnRequest waliduje jawne pola formularza. OrderReturnService stanowi wspolna granice tworzenia i zmiany statusow. Tworzenie oraz zmiany statusow blokuja najpierw rekord poczatkowego statusu, co serializuje rezerwacje ilosci; nastepnie serwis sprawdza klucz idempotencji, hash zamiaru, aktualny snapshot zamowienia oraz przynaleznosc i ilosci pozycji. SQLite korzysta dodatkowo z transakcji z ponowieniami. BigDecimal liczy ceny i sumy, a zapis rodzica, pozycji i zdarzenia jest atomowy. Zmiana gwiazdki i statusu wymaga oczekiwanej lock_version; zbiorcza zmiana statusu nie zapisuje czesciowego wyniku.

OrderReturnController rozszerza istniejace trasy o GET formularza, POST tworzenia, PATCH statusu/gwiazdki oraz POST statusu zbiorczego. ReturnStatusSettingsController zarzadza niezaleznymi statusami z CSRF i ochrona uzytych/poczatkowego. Nie dodano modulowego routingu, providera ani zaleznosci. Style listy zamowien przeniesiono bez zmiany do orders/partials/list-styles.blade.php i wspoldzielono z lista zwrotow. Layout wybiera osobny panel i wyszukiwarke zwrotow. Karta zamowienia pobiera returns.localStatus oraz udostepnia akcje tworzenia i linki do historii zwrotow.

Ilosci rezerwuja tylko pozycje z jednoznacznym order_item_id, w statusie innym niz techniczny rejected. Importowanych zgloszen nie odrzucamy z powodu kolizji z recznym zwrotem: sa faktami zewnetrznymi i pozostaja odrebne. Zgloszen bez dopasowania pozycji nie da sie automatycznie uwzglednic w limicie konkretnej pozycji; operator widzi wczesniejsze zwroty. Nie wykonujemy automatycznego scalania, refundacji, korekt, zmian magazynowych ani zapisu Allegro. Uwierzytelnianie pozostaje odrebnym etapem calego OMS. Raport i testy: docs/returns-general-report.md.

## ALLEGRO.4B.1 - odczyt refundacji

Historyczne ograniczenia 4B.1 dotyczace braku payments:write i POST zostaly rozszerzone przez osobny workflow ALLEGRO.4B.2 opisany na koncu dokumentu. Sam import 4B.1 pozostaje niezalezny i tylko odczytuje API.

Addytywna migracja 2026_10_22_030000_add_allegro_payment_refunds dodaje niezalezna konfiguracje refunds_* do allegro_accounts oraz tabele allegro_payment_refunds, allegro_payment_refund_events i order_return_refund_allocations. order_return_events otrzymuje nullable JSON data dla historii przypisan. Domyslne refunds_enabled=false nie zmienia returns_enabled. Migracja nie udostepnia stratnego automatycznego down.

AllegroPaymentRefund ma unikalnosc environment/seller_user_id/external_refund_id, nullable relacje do konta i zamowienia z nullOnDelete, kwoty DECIMAL(18,2), znormalizowane skladniki, source_hash, lock_version i terminy odczytu. requested_at zachowuje mikrosekundy; daty sa konwertowane do strefy aplikacji przed zapisem w bazie bez strefy, a filtry API do UTC. Wydarzenia i przypisania maja restrictOnDelete do refundacji. Usuniecie karty zwrotu zeruje jej FK, ale zachowuje snapshot numeru i rezerwacje kwoty. Nie udostepniono usuwania finansowej historii.

AllegroClient dodaje wylacznie GET /payments/refunds z publicznym Accept. Uzywa istniejacego OAuth, odnawiania po 401 i obowiazkowego services.allegro.user_agent. Konfiguracja OAuth prosi o opcjonalne allegro:api:payments:read; REQUIRED_SCOPES pozostaje bez zmian. Nowy klient loguje tylko liczbe wynikow, bez komentarzy i kwot. Nie dodano payments:write ani POST refundacji.

AllegroRefundNormalizer przyjmuje kwoty jako stringi i oblicza BigDecimal, bez float. Odrzuca obce waluty, ujemne/nadmiernie precyzyjne kwoty, powtorzone ID skladnikow i niespojna strukture. Limit zrodla wynosi 12 cyfr calkowitych i 2 dziesietne, aby nie tracic groszy w SQLite. Ilosc QUANTITY jest tylko metadana; nie mnozy sie jej przez aktualna cene produktu. Dostawa pochodzi tylko z delivery.value. Produkty to reszta totalValue minus jawna dostawa i rozpoznane pozostale skladniki, tylko przy kompletnym, spojnym kontrakcie; AMOUNT musi potwierdzac te sume. Surcharges lub nieznane skladniki pozostawiaja products_value=null. Nie zapisujemy calego payloadu, danych rachunku bankowego ani dowolnych pol zewnetrznych.

AllegroRefundImportService atomowo zapisuje stan i historie tylko po zmianie source_hash. Identyfikatory platnosci, znanego zamowienia, waluta i data utworzenia nie moga zostac podmienione. Brakujace ID zamowienia moze zostac uzupelnione. Relacja Order wykorzystuje pelna tozsamosc AllegroOrderLink, bez dopasowania po nazwie/loginie. Rekordy bez relacji sa zachowywane. Odczyt nie modyfikuje zamowienia ani lokalnego workflow zwrotu.

AllegroRefundSyncService wykonuje HTTP poza transakcja, nastepnie pod blokada konta ponownie sprawdza revision, tozsamosc, wlaczenie i cursor. Discovery zachowuje stale okno occurredAt.gte/lte, pobiera do 100 rekordow i przesuwa offset o rzeczywista liczbe, zgodnie z count/totalCount. Cala strona jest walidowana przed atomowym importem i przesunieciem. Pelny zakres jest przegladany ponownie po co najmniej 5 minutach, aby odnalezc pozniej udostepnione starsze rekordy. Znane refundacje, takze SUCCESS, sa dodatkowo odswiezane po ID; harmonogram co minute kolejkuje do 100 naleznych rekordow na konto.

DiscoverAllegroRefundsJob i SyncAllegroRefundJob wspoldziela kolejke integrations oraz WithoutOverlapping per konto z expireAfter 120 s; maja timeout 60 s, tries=1 i uniqueFor=180 s. Bledy zachowuja poprzednie kwoty, offset i historie, a ponowienie jest planowane nie wczesniej niz po 5 minutach. Stary revision nie nadpisuje nowej konfiguracji. Rozlaczenie lub zmiana tozsamosci konta wylacza refundacje bez usuwania historii. Settings maja osobny PUT, CSRF, optimistic lock i nie wywoluja API.

OrderReturnRefundService jest wspolna granica lokalnego przypisania. Blokuje refundacje, nastepnie karte zwrotu, sprawdza obie oczekiwane wersje, pelna tozsamosc i walute. Tylko SUCCESS ze znanym podzialem i bez bledu odczytu dopuszcza dodatnia kwote. BigDecimal kontroluje osobne pule produktow/dostawy, wszystkie inne przypisania i wartosc produktow karty. Wyzerowanie istnieje takze po utracie potwierdzenia zrodla. Zapis przypisania, wersji i zdarzenia z kwotami przed/po jest atomowy. Reczne zwroty Allegro zachowuja tozsamosc w order_snapshot.allegro_identity, takze po usunieciu zamowienia.

Podsumowanie nie liczy niespojnych przypisan ani PARTIAL jako SUCCESS. Blad transportu nie zeruje poprzedniego potwierdzenia, ale dodaje ostrzezenie. Lista liczy wskazniki partiami, bez API i bez zapytania na kazdy wiersz. Historia na karcie zwrotu i w platnosciach zamowienia ma paginacje po 20 wpisow. Blade escapuje dane zewnetrzne. Nie powstaje drugi ekran rozliczen ani osobny system uprawnien; uwierzytelnianie calego lokalnego OMS pozostaje odrebnym etapem przed publikacja.

Brak ID zamowienia nie jest zgadywany z payment.id, a refundacja z nieustalonym podzialem nie moze byc automatycznie przypisana. Interfejs pokazuje refundacje w kontekscie znanej tozsamosci zamowienia lub zachowanego przypisania; niepowiazane rekordy bez order.id sa zachowane w bazie bez osobnej listy administracyjnej. Raport: docs/allegro-4b1-report.md.

## ALLEGRO.4B.2 - zlecenia zwrotu wplaty

Addytywna migracja 2026_10_22_040000_add_allegro_refund_commands dodaje refund_write_* do konta i allegro_refund_commands. Flagi scope_requested i enabled sa niezalezne od refunds_enabled oraz domyslnie false. Rewizja i pelna tozsamosc konta chronia konfiguracje oraz zadania. Rozlaczenie lub zmiana sprzedawcy/srodowiska wylacza nowe zlecenia, lecz nie usuwa rezerwacji. OAuth usuwa payments:write z bazowej listy scope i dodaje je tylko po jawnej zgodzie. REQUIRED_SCOPES pozostaje niezmienione.

AllegroRefundContextService odczytuje checkout oraz wszystkie strony GET /payments/refunds dla order.id, waliduje count/totalCount, unikalne ID i pelne skladniki. Niespojny/truncated wynik jest bledem, nie zerowa historia. Pozycje wymagaja istniejacego order_item_id i oryginalnego external_id. Przyjete zlecenia oraz potwierdzone refundacje zmniejszaja osobne pule pozycji i dostawy. Historyczne QUANTITY bez pewnego rozbicia kwot, PARTIAL i dodatkowe skladniki blokuja pierwsza wersje write. Kanoniczny hash sortuje klucze obiektow rekurencyjnie, aby normalizacja JSON przez MySQL nie zmieniala tozsamosci payloadu. Hash obejmuje rowniez lokalny rejestr pozostalych zlecen, wersje zwrotu i konfiguracji.

AllegroRefundCommandService przygotowuje draft z UUID, niezmienna trescia, kwotami DECIMAL(18,2), wersjami i 5-minutowym terminem potwierdzenia. Finalny POST z CSRF i zgoda operatora weryfikuje sesje oraz ponownie odczytuje API. Transakcja blokuje konto, AllegroOrderLink, zwrot i zlecenie; przed queued ponownie sprawdza lokalny hash, wersje i brak rezerwacji innych zlecen tego zamowienia. Worker powtarza odczyt i kontrolowane przejscie queued -> sending, zapisujac started_at PRZED finansowym POST. HTTP jest poza transakcja. Stan bazy, a nie sam cache unique job, chroni przed ponownym wyslaniem.

ProcessAllegroRefundCommandJob korzysta z integrations, tries=1, timeout=120, uniqueFor=180. Scheduler co minute odzyskuje queued oraz sprawdza znane ID co okolo 5 minut. Sending starsze niz 5 minut staje sie uncertain, bez ponownego POST. Brak odpowiedzi, 5xx i niepelna odpowiedz po HTTP 200 pozostawiaja rezerwacje. Znany external_refund_id jest odswiezany takze przy wylaczonym write lub imporcie 4B.1; wymagane pozostaja aktywne konto, ta sama tozsamosc i scope read. Uncertain bez ID ma tylko jawne uzgodnienie odczytem z ID wskazanym przez operatora, bez dopasowania automatycznego i bez wysylki pieniedzy. Obecna odpowiedz RefundDetails nie gwarantuje commandId, a GET nie udostepnia filtra po commandId; nie mozna traktowac zgodnej kwoty jako dowodu tozsamosci.

POST wymaga zapisanego zlecenia sending, poprawnego hasha i zgodnych konta, rewizji, commandId i scope. Po 401 OAuth moze ponowic tylko ten sam payload; zmiana tozsamosci lub konfiguracji zatrzymuje retry. Nowy transport nie loguje surowych odpowiedzi, komentarza ani kwot w IntegrationApiLog. Kazdy GET/POST/OAuth nadal uzywa services.allegro.user_agent. Ekrany formularza i potwierdzenia maja private, no-store.

Zgodna odpowiedz trafia do dotychczasowego AllegroRefundImportService. Dopiero SUCCESS tworzy jedno przypisanie kwot do karty, po sprawdzeniu jej tozsamosci, waluty, limitu i wszystkich innych przypisan, takze z osieroconym FK. Pozniejszy rzeczywisty PARTIAL lub zmienione kwoty sa importowane jako fakt i powoduja review; nie usuwamy starej alokacji, ale niewazne przypisanie nie jest liczone w podsumowaniu. OrderReturnRefundService blokuje AllegroOrderLink przed refundacja i zwrotem oraz odrzuca reczna zmiane przypisania nalezacego do command. Usuniecie karty, zamowienia lub konta zachowuje finansowy command i snapshot return_number. FK do refundacji ma restrictOnDelete; do pozostalych encji nullOnDelete. Wszystkie nowe nazwy indeksow/FK sa jawne i krotsze od 64 znakow. Down celowo odmawia utraty historii.

Nie dodano nowego auth providera ani logowania Laravel. Wlasciciel potwierdzil Basic Auth w reverse proxy dla calego panelu. Wdrozenie wymaga HTTPS, ochrony wszystkich nowych tras i braku publicznego obejscia proxy; nie ufamy dowolnym naglowkom z nazwa uzytkownika. Raport, ograniczenia i bezpieczna aktywacja: docs/allegro-4b2-report.md.

## Allegro - wnioski o zwrot prowizji

Migracja 2026_10_22_050000_add_allegro_commission_refund_claims tworzy allegro_commission_refund_claims. Nullable relacje do konta, zamowienia i zwrotu maja nullOnDelete; tozsamosc srodowiska/sprzedawcy/zakupu/pozycji, numer karty i nazwa produktu sa snapshotami. Unikalnosc obejmuje srodowisko, sprzedawce i oryginalne lineItem.id, niezaleznie od liczby kart zwrotu. Osobna unikalnosc chroni zewnetrzne ID wniosku. Wszystkie nazwy FK/indeksow sa jawne i krotsze niz 64 znaki. Model blokuje usuwanie historii, podmiane zewnetrznego ID i zmiane tresci po rozpoczeciu wysylki. Down odmawia automatycznego usuniecia historii. Nie przechowujemy kwot prowizji ani surowych payloadow.

AllegroClient rozszerza istniejacy transport o GET /order/refund-claims, GET /order/refund-claims/{id} i POST /order/refund-claims z publicznym Accept, wspolnym OAuth i obowiazkowym services.allegro.user_agent. POST zawiera wylacznie lineItem.id oraz integer quantity i wymaga trwalego rekordu sending ze started_at i zgodna tozsamoscia konta. Korzysta z obecnych REQUIRED_SCOPES orders:read/orders:write, bez nowego scope lub zaleznosci od payments:write. Po 401 moze odnowic token i ponowic tylko identyczny wniosek przy niezmienionym koncie. Logi nowych operacji nie zawieraja surowej odpowiedzi ani danych klienta.

AllegroCommissionRefundService buduje kontekst na podstawie pelnej tozsamosci AllegroOrderLink, biezacej karty i checkout-form. Wymaga order_item_id oraz oryginalnego external_id, waliduje buyer.id, offer.id i zakupiona ilosc; nie dopasowuje po nazwie. GET listy filtruje buyer.id, pobiera strony po 100 i sprawdza count oraz cala strukture. Krotka strona zamyka odczyt, a 10 pelnych stron bez konca bezpiecznie blokuje operacje. Odpowiedz nie posiada totalCount. Uzgodnienie uzywa dokladnego lineItem.id i kontroluje kupujacego, oferte, ilosc zakupu oraz stabilne createdAt i claim ID. Nieznane statusy sa zachowane i escapowane.

AllegroCommissionRefundController udostepnia centralne GET/POST returns.commission-refund.create/store. GET przygotowuje sesyjny token, hash kontekstu i termin 5 minut; nie tworzy wniosku. POST wymaga CSRF, jawnego potwierdzenia i ponownego odczytu. Transakcja blokuje konto, link, zwrot oraz rekord wniosku, ponownie weryfikuje tozsamosc i lock_version; zadanie jest dispatchowane po commit. Ten sam rekord obejmuje wszystkie karty dla danej pozycji. Istniejacy wniosek z Allegro, takze AUTOMATIC, zatrzymuje nowy POST. Nierozpoczety not_sent moze otrzymac nowa zgode; pozostale rozpoczete zlecenia nie sa ponownie wysylane.

ProcessAllegroCommissionRefundJob korzysta z integrations, tries=1, timeout=120 i uniqueFor=180. Trwale przejscie queued -> sending ze started_at nastepuje przed HTTP. Scheduler co minute kolejkuje nalezne rekordy; stare sending przechodzi po 5 minutach w uncertain. HTTP 201 z prawidlowym ID daje submitted, a wynik jest sprawdzany odczytem co okolo 5 minut. Timeout, 5xx, 429 i niepelna odpowiedz nie powoduja ponownego POST; odrzucenia 4xx rowniez nie sa automatycznie ponawiane. Uzgadnianie po linii zakupu moze odnalezc pozne potwierdzenie. Wczesniejszy poprawny wynik nie jest zerowany po bledzie odczytu. Zapis zdarzenia i obserwacji jest transakcyjny, bez duplikatu historii przy identycznym wyniku.

Przycisk i historia sa rozszerzeniem istniejacej karty zwrotu; historia czyta baze i ma paginacje po 20 rekordow. Formularz ma private, no-store. Dostep nadal opiera sie na potwierdzonym Basic Auth reverse proxy, HTTPS i braku publicznego obejscia. Nie dodano drugiego systemu auth, nowego providera, zaleznosci, automatycznych refundacji, Korekt ani zmian magazynu. Raport wdrozenia i testow: docs/allegro-commission-refunds-report.md.

## ALLEGRO.4C - powiazanie Korekty ze zwrotem

Migracja 2026_10_22_060000_link_return_corrections_to_invoices dodaje nullable invoices.order_return_id, unique invoices_return_unique i FK invoices_return_fk z nullOnDelete. Nie uzupelnia historycznych dokumentow. Invoice belongsTo OrderReturn, a OrderReturn hasOne correction; nie dodano invoice_id do orders, oddzielnej tabeli dokumentow ani drugiego generatora numerow. Down odmawia automatycznej utraty powiazan.

OrderReturnCorrectionService w module Invoices stanowi adapter istniejacego CorrectionService, bez zaleznosci od klienta Allegro. Przy przygotowaniu sprawdza zamowienie, walute, lokalny status zwrotu, istniejacy dokument i dokladne order_item_id. Jedna pozycja zwrotu musi odpowiadac jednej pozycji product w skutecznym stanie Faktury/ostatniej zamknietej Korekty. Brak dopasowania i duplikaty sa bledem, bez heurystyki nazw/SKU. Pierwsza wersja obsluguje od 1 do 100 pozycji oraz calkowite ilosci do 1000000. Kwoty jednostkowe musza miec dokladna reprezentacje do 2 miejsc i potwierdzac zachowane sumy pozycji; niekompletne historyczne dane sa blokowane zamiast zaokraglane po cichu.

Kontekst V1 zawiera numer zwrotu, referencje, order_id, lock_version oraz mapowanie source_item_id/return_item_id/order_item_id i limity. Hash wykorzystuje istniejacy kanoniczny ProformaSourceSnapshotHasher i obejmuje rowniez biezace pozycje zwrotu oraz bazowe pozycje dokumentu. GET nie zapisuje dokumentu ani numeru. Podczas issue, w transakcji istniejacego serwisu i po blokadzie zamowienia, Faktury oraz lancucha, adapter blokuje zwrot i pozycje, ponownie przygotowuje kontekst i porownuje hash. Oczekiwane ID i lock_version skutecznego zrodla nadal sa kontrolowane przez CorrectionService. Unikalny FK oraz serializacja na zamowieniu zabezpieczaja rownolegle przypisanie.

Formularz wspoldzieli ekran Korekty; osobny partial zawiera tylko ilosci zwracane i opcjonalna kwote dostawy. Server buduje pelny AFTER z historycznego BEFORE, nie przyjmuje cen lub VAT z przeslanych items. Dostawa pozostaje niezalezna od refundacji i domyslnie wylaczona. BigDecimal oblicza pozostala kwote; automatyczne przygotowanie dostawy wymaga jednej pozycji shipping z iloscia 1. Pozostale pozycje zachowuja stan zrodlowy. Podstawowy silnik obliczen, VAT, numeracji, walut i KSeF nie jest duplikowany.

order_snapshot.return_correction jest snapshotem dokumentu, nie historia poprzednich wersji. Zawiera zamrozone limity i biezacy wybor ilosci/dostawy. Przy update adapter odtwarza BEFORE z wlasnych pozycji Korekty, a source_invoice_item_id zachowuje stabilne powiazanie mimo wymiany rekordow pozycji podczas edycji. Nie odczytuje aktualnego zwrotu jako nowego zrodla kwot/limitow. Korekty zwykle nadal dzialaja bez tego kontekstu; nie mozna przypiac istniejacego dokumentu lub zmienic karty w update. CorrectionStateComparator uwzglednia order_snapshot, zachowujac zapis bez zmian jako no-op.

Dotychczasowe kontrolery i centralne trasy obsluguja nowe parametry; nie dodano osobnego routingu. InvoiceReturnContext ma bezpieczny cel order_return z whitelistowanym ID i sprawdzeniem przynaleznosci do zamowienia; obcy/usuniety cel wraca do zamowienia, nie dowolnego URL. Istniejaca inna niezfinalizowana Korekta jest otwierana bez nadpisania, z kontrolowanym komunikatem takze bez trwalej sesji flash. Jeden zwrot oznacza jedna istniejaca Korekte, a starsze dokumenty nie sa laczone automatycznie.

Wystawienie, finalizacja i dozwolone usuniecie zapisuja skrocone order_return_events w tej samej transakcji. Nie dodano historii zwyklych edycji dokumentu. Usuniecie karty zeruje FK i zachowuje snapshot. Dopuszczone usuniecie Korekty usuwa jej relacje razem z dokumentem, lecz pozostawia numer w zdarzeniu zwrotu. PdfViewModel odczytuje return_id tylko ze snapshotu i respektuje zamrozona flage show_return_id_in_header; layout Korekty ma wersje v47. Nie zmieniono layoutu Faktury/Pro formy ani plikow dokumentow zewnetrznych.

InvoiceFinalizationService nadal emituje istniejace CorrectionFinalized po commit, wiec ALLEGRO.3D i KSeF zachowuja swoje bramki. Nowy kod nie wywoluje API ani nie zmienia konfiguracji automatycznych wysylek, refundacji lub stanow. Kopia, migracja, testy, ograniczenia i reczna weryfikacja: docs/allegro-4c-report.md.
