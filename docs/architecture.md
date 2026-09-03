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

Docelowo odpowiada za:

- wewnętrzny katalog produktów,
- domyślną nazwę,
- stawkę VAT,
- jednostkę,
- oznaczenia GTU,
- aktywność produktu.

Powiązania z pozycjami dokumentów mają być opcjonalne.

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

Planowana tabela:

```text
products
```

Relacje:

```text
Product hasMany OrderItems
Product hasMany InvoiceItems
```

Usunięcie produktu:

```text
nullOnDelete
```

Dokument historyczny pozostaje poprawny bez relacji.

Nie należy automatycznie wiązać historycznych pozycji tylko na podstawie nazwy.

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

---

# 26. KSeF — przygotowanie architektury

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

`KsefPdfDocumentPresenter` jest wspólną granicą prezentacyjną PDF dla Faktury VAT i Korekty. W `production` wybiera wyłącznie Production, a poza produkcją dokładną wartość `KsefSetting.environment`; zapytania o submission nie stosują fallbacku między środowiskami. Zaakceptowana Korekta korzysta wyłącznie z własnego submissionu, daty wystawienia, sprzedawcy, hasha, numeru i środowiska. Pro forma kończy prezentację przed odczytem KSeF i nie otrzymuje metadanych, ostrzeżenia ani QR.

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

Ręczna operacja „Sprawdź w KSeF” synchronizuje status istniejącego lokalnego certyfikatu Offline. Query i retrieve są logicznie read-only i działają wyłącznie w dokładnym środowisku zapisanym przy certyfikacie: TEST i DEMO są dozwolone, a Production jest blokowane przed pobraniem tokena i przed HTTP przez osobną, wąską politykę. Certyfikat nie jest wiązany z `context_nip`. Po doprecyzowaniu 8B.2A.3 operacja wymaga access tokena pochodzącego z konfiguracji uwierzytelnienia Certificate/XAdES dla tego samego środowiska; Token KSeF jest blokowany lokalnie przed auth i HTTP. GET zakładki nie wykonuje HTTP.

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

`KsefOfflineIssuance` jest odrębnym, niezmiennym agregatem faktu prawnego wystawienia Faktury VAT w trybie Offline24; nie jest próbą transmisji i nie używa `KsefInvoiceSubmission`. Dla pary Faktura-środowisko istnieje najwyżej jeden rekord. Relacja z Fakturą ma `RESTRICT ON DELETE`, a nullable relacja z certyfikatem Offline używa `SET NULL`, ponieważ źródłem historii są zamrożone w issuance dane certyfikatu, a nie bieżący rekord konfiguracyjny.

`KsefOfflineIssuanceService` przechwytuje jeden instant `issuedAt`, wymaga wystawionej i sfinalizowanej Faktury VAT, aktywnej integracji, serii włączonej do KSeF oraz dozwolonego środowiska TEST albo DEMO. Kontekst v1 musi być identyfikatorem `Nip` równym NIP-owi sprzedawcy; jest to ograniczenie NEX, nie ogólna reguła MF. Dla dokładnego środowiska wymagany jest jawnie wybrany certyfikat Offline spełniający istniejący kontrakt `KsefOfflineCertificateReadinessService::isReady()`. Wystawienie nie korzysta z deployment gate transmisji i wykonuje zero HTTP.

Autorytatywny generator FA(3) tworzy dokładny XML, z którego `KsefFa3IssueDateReader` odczytuje `P_1`. W wersji v1 `P_1` musi być tym samym dniem co `issuedAt` w `Europe/Warsaw`. Ten sam zamrożony XML jest zaszyfrowany at rest i stanowi źródło SHA-256 Base64, rozmiaru, KODU I oraz podpisanego KODU II. Zapis obejmuje environment, sprzedawcę i kontekst, schema id, dane certyfikatu wymagane do historycznej oceny gotowości oraz wyłącznie finalne URL-e KODU I i KODU II; certyfikat PEM i klucz prywatny nie są duplikowane.

Kosztowne generowanie, hashowanie i podpis odbywa się poza transakcją. Krótka transakcja ponownie blokuje i porównuje Fakturę z pozycjami, konfigurację, serię, wybór certyfikatu, jego tożsamość, materiał klucza i stan gotowości oraz wykluczające historie. Ograniczenie unikalne jest końcową ochroną duplikatu. Historia dowolnego submissionu Online lub `OutsideKsef` w tym samym środowisku blokuje Offline24; po Offline24 zablokowane są zwykłe prepare/manual/automatic/monthly Online, oznaczenie `OutsideKsef` oraz usunięcie Faktury.

Panel sfinalizowanej Faktury pokazuje akcję POST `WYSTAW OFFLINE24` wyłącznie przy pełnej gotowości oraz jawne ostrzeżenie o trwałym zamrożeniu dokumentu. Po sukcesie pokazuje lokalny status, `P_1`, czas wystawienia, numer seryjny i status certyfikatu oraz informację, że numer KSeF nie został jeszcze nadany; nie ujawnia XML, hasha, fingerprintu, klucza ani pełnego KODU II. Etap nie dodaje PDF Offline, obrazów QR, potwierdzenia transakcji, polityki doręczenia nabywcy, transmisji `offlineMode=true`, powiązania z submissionem, deadline engine, Latarni, automatycznej wysyłki, Korekt Offline, korekty technicznej, trybów awaryjnych ani rollout'u Production.

### KSeF.8C.2 — CLOSED

Prezentacja i doręczenie dokumentu Offline24 są operacjami wyłącznie lokalnymi, bez requestów KSeF i bez modyfikowania `KsefOfflineIssuance`. Jedynym źródłem danych jest dokładny, zamrożony XML FA(3) zapisany przy wystawieniu. Centralny ekstraktor przed renderowaniem ponownie sprawdza rozmiar i hash payloadu, namespace i schemat, `P_1`, NIP sprzedawcy oraz zapisane URL-e KODU I i KODU II. PDF-y są generowane na żądanie przez istniejący TCPDF, bez osobnego trwałego cache; usunięcie bieżącej konfiguracji albo rekordu certyfikatu nie zmienia historycznej prezentacji.

Centralna polityka v1 klasyfikuje nabywcę konserwatywnie na podstawie zamrożonego XML: polski NIP dopuszcza wyłącznie potwierdzenie transakcji, a brak polskiego NIP dopuszcza wyłącznie Fakturę Offline. Jest to operacyjna polityka NEX-OMS, a nie pełna kwalifikacja prawna wszystkich przypadków. Niejednoznaczna lub niespójna tożsamość nabywcy kończy się fail-closed. Serwer egzekwuje tę samą decyzję niezależnie od widoczności przycisku w UI.

Faktura Offline zawiera dokładnie dwa lokalnie wygenerowane kody QR ze snapshotowych URL-i: KOD I z etykietą `OFFLINE` i KOD II z etykietą `CERTYFIKAT`. Potwierdzenie transakcji nie jest fakturą; zawiera ograniczone dane stron, `P_2` i `P_15` z walutą oraz dokładnie dwa kody QR z nagłówkami `sprawdź fakturę w KSeF` i `zweryfikuj wystawcę faktury`, bez etykiet `OFFLINE` i `CERTYFIKAT` pod kodami. Dokumenty TEST i DEMO są jawnie oznaczone swoim środowiskiem.

Zwykły, mutowalny PDF Faktury, jego istniejący cache oraz PDF zbiorczy są blokowane dla Faktury posiadającej Offline24, także przy bezpośrednim użyciu renderera. Etap nie dodaje transmisji `offlineMode=true`, harmonogramu terminów, integracji z Latarnią, Korekt Offline ani rollout'u Production.

Granica side effectu POST Faktury jest jawna: błędy przed nią oraz jednoznaczne 4xx prowadzą do `technical_failed`, natomiast timeout, błąd połączenia, 5xx i niekompletna odpowiedź 2xx prowadzą do blokującego `uncertain`. Nie ma automatycznego retry ani persistence materiału potrzebnego do powtórzenia tej samej sesji. Token dostępu jest pobierany wyłącznie po zgodności aktualnej konfiguracji z zamrożonym kontekstem, również dla cached tokena, refresh i pełnego reauth. Otrzymanie referencji Faktury ustawia tylko `submitted`; błąd close jest przechowywany osobno. Status odpowiedzi jest mapowany dopiero po dokładnej korelacji `referenceNumber` i `invoiceHash`, a `accepted` wymaga dodatkowo kodu 200, poprawnego CRC numeru KSeF oraz zgodności jego prefiksu NIP z zamrożonym sprzedawcą. Brak lub mismatch identity prowadzi do `uncertain`; nieznany status jest traktowany konserwatywnie.

Transport ma deploymentowy gate `KSEF_INVOICE_SUBMISSION_ENABLED` domyślnie `false`, jest serwisowo ograniczony do TEST i nie ma trasy ani UI. KSeF.4A.1 nie dodaje automatycznej akcji, listenera, observera, kolejki, crona, automatycznego pollingu, batch, offline, QR ani UPO. Trwałe `automatic_submission=true` nie omija deployment gate i przy braku workflow nie uruchamia transmisji. Przed przyszłym włączeniem gate trzeba zweryfikować tę wartość oraz wszystkie ścieżki triggerów. Automatyczne testy pozostają fake-only, używają `Http::fake()` i blokują stray HTTP.

### Walidacja end-to-end KSeF.4A

Status etapu: `KSeF.4A CLOSED`. Transport został jednorazowo zweryfikowany kontrolowanym happy path na KSeF TEST API 2.7.0, niezależnie od automatycznych testów fake-only. W pełni syntetyczna, sfinalizowana Faktura VAT przeszła autorytatywne generowanie FA(3), walidację lokalnym oficjalnym XSD, zamrożenie i zaszyfrowanie payloadu at rest, wyliczenie SHA-256 i rozmiaru, szyfrowanie AES-256-CBC oraz RSA-OAEP SHA-256/MGF1-SHA256. Certificate/XAdES auth i `InvoiceWrite` zostały potwierdzone przed otwarciem sesji.

Lifecycle obejmował otwarcie online session, dokładnie jeden invoice POST, zamknięcie sesji oraz status GET. Nie wykonano resend, nowego attempt ani blind retry. Odpowiedź statusowa 200 przeszła korelację `referenceNumber` i `invoiceHash`, walidację formatu i CRC numeru KSeF oraz zgodność sprzedawcy; lokalny agregat zakończył się stanem `accepted`. Zamrożone środowisko, `context_nip`, `seller_nip`, payload, hash i rozmiar pozostały niezmienne.

Po live flow wykonano osobną read-only weryfikację z dokładnie jednym status GET. Ponownie otrzymano 200, ten sam numer KSeF i zgodne identity, bez nowego submission lub attempt. Następnie przywrócono poprzedni credential i kontekst TEST, unieważniono syntetyczny runtime auth i wykonano świeże uwierzytelnienie oraz diagnostykę `InvoiceWrite=YES`; artefakt rollback usunięto dopiero po pełnej weryfikacji restore. Dedykowana seria syntetyczna została wyłączona dla KSeF, a zaakceptowany Invoice/Order/Series/Submission zachowano jako audit trail. Istniejący submission nadal blokuje usunięcie Faktury zgodnie z `restrictOnDelete` i policy.

Walidacja miała `DEMO LIVE REQUESTS: 0` i `PRODUCTION LIVE REQUESTS: 0`; nie stanowi produkcyjnego certyfikatu gotowości. Nie udostępnia użytkownikowi akcji wysyłki, UPO, QR, offline, batch ani obsługi Korekt FA(3); Pro forma pozostaje wyłączona z KSeF. Scenariusze `uncertain`, timeout, connection error, 5xx i malformed 2xx są nadal chronione semantyką bez automatycznego resend i testami fake-only, ale nie były wszystkie wykonywane live.

### Ręczny workflow aplikacyjny KSeF.4B.1

`KsefManualInvoiceSubmissionService` jest cienkim orkiestratorem policy nad niezmienionym transportem 4A. W zewnętrznej transakcji stosuje `lockForUpdate()` dla Faktury i singletonu `KsefSetting`, sprawdza brak jakiegokolwiek `KsefInvoiceSubmission` dla bieżącego środowiska i wywołuje istniejące `prepare()` dokładnie raz. Na bazach obsługujących `SELECT ... FOR UPDATE` blokada wiersza pomaga serializować podwójne żądania. Na lokalnym SQLite `lockForUpdate()` nie jest prawdziwą blokadą wiersza; bezpieczeństwo pierwszej próby opiera się na transakcyjnym guardzie, semantyce blokady zapisu SQLite, ponowieniu transakcji, atomowym wyznaczeniu numeru próby i ograniczeniu `UNIQUE(invoice_id, environment, attempt_number)`. Kontrolowany dwuprocesowy test na disposable SQLite potwierdził brak zduplikowanego submission i POST Faktury w badanym manualnym workflow, bez rozszerzania tego wyniku na wszystkie bazy i scenariusze. Guard obejmuje również `rejected` oraz `technical_failed`, ponieważ retry nie należy do 4B.1. Historia z innego środowiska nie blokuje first attempt. Po commit orkiestrator wywołuje `submit()` dokładnie raz, bez `refreshStatus()` i bez obejmowania HTTP transakcją bazy.

`KsefInvoiceSubmissionController` udostępnia wyłącznie dwie mutujące trasy POST z CSRF: pierwszą wysyłkę oraz ręczny refresh konkretnej próby. Refresh sprawdza ownership submission-Faktura przed delegacją i jest dopuszczany przez transport tylko dla `submitted` oraz `processing`; jedno żądanie wykonuje jeden status GET. Kontrolowane wyjątki pokazują wyłącznie bezpieczny komunikat, a nieoczekiwane błędy są redukowane do komunikatu ogólnego bez logowania sekretów lub payloadu.

Status i historia są renderowane na read-only ekranie Faktury VAT. `Invoice::latestKsefSubmission()` korzysta z `HasOne::ofMany()` i jest eager-loadowane na liście Faktur, dzięki czemu kompaktowy badge nie powoduje N+1. Panel szczegółowy korzysta z posortowanej historii `ksef_invoice_submissions`, pokazuje pełny numer KSeF dla `accepted`, bezpieczne błędy i wyraźne ostrzeżenie dla `uncertain`; nie ujawnia XML, hashy, NIP-ów, identyfikatorów sesji ani surowych odpowiedzi. Nie powstała kolumna statusu na `invoices` ani równoległy system eventów.

Workflow pozostaje TEST-only i zależy od deployment gate domyślnie `false`, aktywnej integracji oraz włączonej serii. Wyłączony gate ukrywa akcje, ale nie historię. `automatic_submission` nie ma żadnego triggera i pozostaje konfiguracją nieaktywną funkcjonalnie. Etap nie dodaje retry, reconciliation, kolejki, schedulera, automatycznego pollingu, UPO, QR, offline, batch, DEMO, PRODUCTION, Pro form ani Korekt KSeF.

24 sierpnia 2026 r. etap `KSeF.4B.1-LIVE-UI-TEST-001` przeszedł kontrolowany happy path przez normalny ręczny workflow aplikacji na środowisku KSeF TEST. Syntetyczna Faktura VAT została wysłana dokładnie jednym invoice POST-em, bez automatycznego odświeżenia i bez drugiego attemptu; po jednym ręcznym status GET otrzymała stan `accepted` oraz prawidłowy numer KSeF. Historia i bieżący status były poprawnie widoczne w UI bez ujawnienia XML ani sekretów.

Przed właściwym happy pathem odrębny syntetyczny dokument zakończył się kontrolowanym `network_error` przed pobraniem klucza sesji, otwarciem sesji i invoice POST-em; dokumentu nie wysłano ponownie. Po teście wyłączono ponownie dedykowaną serię `KSEF UI LIVE TEST`, a deployment gate wrócił do domyślnego stanu `false`; kontekst i credential TEST nie były czasowo zmieniane. `DEMO LIVE REQUESTS: 0`; `PRODUCTION LIVE REQUESTS: 0`.

### KSeF.5A — lifecycle, reconciliation i policy kolejnych prób

`KsefInvoiceSubmissionStatus` jest centralnym źródłem reguł lifecycle. `preparing` i `session_opened` są stanami aktywnymi bez status lookup; po udanym wysłaniu warstwa manualnego workflow wykonuje jeden natychmiastowy status GET bez pollingu, retry ani ponownego POST Faktury. `submitted` i `processing` pozwalają nadal na pojedynczy ręczny refresh; `accepted` jest terminalnym sukcesem; `rejected` i `technical_failed` są terminalnymi wynikami, po których policy może dopuścić nowy attempt; `uncertain` nie jest wynikiem terminalnym i wymaga reconciliation. Każdy status blokuje zmianę i usunięcie Faktury powiązanej z audit trail KSeF. Dozwolone przejścia są deklarowane przez enum i egzekwowane pod `lockForUpdate()` przy zapisie statusu. Wspólny `KsefInvoiceStatusFollowUpService` po sprawdzeniu lub uzgodnieniu statusu planuje osobny background UPO, jeżeli wynik zmienił się na `accepted`; idempotentny `KsefInvoiceUpoService` jest wywoływany dopiero przez późniejszy follow-up albo jawną ręczną akcję i nigdy nie ponawia invoice POST.

`KsefInvoiceSubmissionLifecyclePolicy` ocenia całą historię pary Faktura-środowisko. Pierwsza próba jest dozwolona przy pustej historii, a kolejna tylko wtedy, gdy wszystkie wcześniejsze próby mają jednoznaczny stan `rejected` albo retry-safe `technical_failed`. W obecnym transporcie `technical_failed` powstaje wyłącznie przed invoice POST-em albo po jednoznacznej odpowiedzi błędnej, natomiast timeout, connection error, 5xx oraz niekompletna lub malformed odpowiedź po możliwym side effect zawsze prowadzą do `uncertain`. Obecność wcześniejszego `accepted`, dowolnego attemptu aktywnego albo `uncertain` blokuje nową próbę. Nowy attempt otrzymuje kolejny `attempt_number` i własny zaszyfrowany payload; poprzedni rekord i dokładny XML pozostają niezmiennym audit trail.

Ręczne reconciliation działa wyłącznie dla `uncertain` i nigdy nie wykonuje invoice POST. Gdy submission posiada `invoice_reference_number`, używany jest istniejący `GET /sessions/{sessionReference}/invoices/{invoiceReference}`. Gdy niejednoznaczny POST nie zwrócił referencji Faktury, system pobiera pierwszą stronę `GET /sessions/{sessionReference}/invoices` dla dedykowanej sesji jednej Faktury i akceptuje wyłącznie dokładnie jedno dopasowanie zapisanego `invoiceHash`; dodatkowe strony, brak dopasowania albo wiele dopasowań pozostawiają stan `uncertain`. Odzyskana referencja jest zapisywana pod blokadą, po czym wspólny mapper statusu nadal wymaga zgodnych `referenceNumber` i `invoiceHash`, prawidłowego numeru KSeF z CRC oraz zgodności NIP-u sprzedawcy. Wynikiem może być `processing`, `accepted`, `rejected` albo nadal `uncertain`.

Ręczny orkiestrator nadal blokuje Fakturę i konfigurację przed przygotowaniem próby, nie obejmuje HTTP transakcją i wykonuje najwyżej jeden invoice POST na attempt. Równoległe żądanie retry po utworzeniu nowego rekordu widzi `preparing` i jest blokowane; ograniczenie `UNIQUE(invoice_id, environment, attempt_number)` pozostaje zabezpieczeniem bazowym. SQLite nie zapewnia pełnej semantyki row lock dla `lockForUpdate()`, dlatego nie jest traktowany jako dowód zachowania każdej docelowej bazy, ale transakcja, blokada zapisu SQLite i indeks unikalny zapobiegają utworzeniu dwóch legalnych prób o tym samym numerze. Refresh i reconciliation nigdy nie tworzą submissionu.

Panel Faktury pokazuje retry jako „Utwórz nową próbę KSeF TEST” wyłącznie po wyniku dopuszczonym przez policy. Dla `uncertain` pokazuje ostrzeżenie zakazujące ponownego wysłania oraz akcję „Sprawdź wynik transmisji”, jeśli istnieje referencja sesji. `accepted` nie ma akcji wysyłki. Historia nie ujawnia XML, hashy, NIP-ów, referencji ani sekretów. KSeF.5A pozostaje ręczne i TEST-only: nie dodaje UPO, kolejki, Automation, schedulera, automatycznej wysyłki ani pollingu, DEMO, PRODUCTION, Korekt FA(3), QR, offline lub batch. Implementacja i testy nie wykonują live requestów.

### KSeF.5B — UPO Faktury

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

`KsefOperationalEnvironmentPolicy` jest jednym źródłem prawdy dla ręcznych operacji Faktury: TEST i DEMO dopuszczają przygotowanie oraz wysyłkę, refresh statusu, reconciliation i zdalne pobranie UPO, natomiast PRODUCTION pozostaje zablokowane przed HTTP. Niezależny deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED` nadal domyślnie ma wartość `false`; operacja wymaga jednocześnie włączonego gate i zgody policy. Test połączenia i diagnostyka credentiali nie są operacyjnym transportem Faktur i nie zostały objęte tą blokadą.

Credentiale, runtime tokeny, historia prób, lifecycle i numer próby pozostają rozdzielone per environment, bez fallbacku między TEST, DEMO i PRODUCTION. Panel Faktury wyznacza bieżącą próbę względem aktywnego `ksef_settings.environment`, pokazuje pełną historię z oznaczeniem środowiska i generuje dynamiczne etykiety TEST albo DEMO. DEMO ma jawne ostrzeżenie o danych testowych/fikcyjnych oraz osobne potwierdzenie przed wysyłką; dla PRODUCTION panel pokazuje blokadę i nie udostępnia operacji zdalnych. Pobranie lokalnie zapisanego historycznego UPO pozostaje dostępne niezależnie od bieżącego środowiska i deployment gate.

KSeF.6A pozostaje workflow ręcznym i został zweryfikowany wyłącznie przez fake HTTP, bez requestów live do TEST, DEMO lub PRODUCTION. `automatic_submission` nadal nie ma triggera. Zakładka „Eksportuj dokumenty” pozostaje poza zakresem do KSeF.6B, a kontrolowany DEMO E2E pozostaje osobnym etapem KSeF.6C. Status: `KSeF.6A CLOSED`.

### KSeF.6B — miesięczny eksport niewysłanych Faktur

Zakładka „Eksportuj dokumenty” udostępnia prosty ręczny formularz wzorowany na modelu Base: wybór bieżącego albo jednego z 12 poprzednich miesięcy oraz polecenie eksportu. Formularz przekazuje wyłącznie miesiąc `YYYY-MM`; nie zawiera wyboru środowiska. Środowisko jest snapshotowane z `KsefSetting.environment` na początku operacji, a przed każdą pierwszą próbą istniejący workflow pod blokadą potwierdza, że konfiguracja nadal odpowiada snapshotowi. Zmiana środowiska zatrzymuje pozostałą część eksportu bez przełączenia kolejnych dokumentów na nowy host.

Kwalifikują się wyłącznie sfinalizowane Faktury VAT z `issue_date` należącą do wybranego miesiąca, których seria jest aktualnie włączona w `KsefSeriesSetting` i które nie mają żadnego `KsefInvoiceSubmission` w aktywnym środowisku. Dowolna istniejąca próba w tym środowisku, w tym `rejected` albo `technical_failed`, wyklucza dokument z eksportu; ponowienie pozostaje świadomą operacją na pojedynczej Fakturze. Historia innego środowiska nie wyklucza pierwszej próby. Dokumenty są przetwarzane deterministycznie według `issue_date`, a następnie `id`.

Każda Faktura korzysta osobno z istniejącego `KsefManualInvoiceSubmissionService`, własnego submissionu i własnej sesji. Nie ma transakcji obejmującej cały miesiąc i HTTP, batch persistence, automatycznego retry, status pollingu, reconciliation ani pobierania UPO. Błąd konkretnego dokumentu nie blokuje następnych, natomiast `429`, błąd sieci, globalny błąd autoryzacji/credentiala albo awaria API zatrzymują pozostałe dokumenty bez sleep/retry. TEST i DEMO są dopuszczone przez `KsefOperationalEnvironmentPolicy`; PRODUCTION pozostaje zablokowane przed HTTP, a niezależny deployment gate oraz `KsefSetting.is_active` są nadal wymagane.

KSeF.6B pozostaje synchroniczną operacją ręczną, bez queue, schedulera, Automation i triggera `automatic_submission`. Etap został zweryfikowany fake-only, bez live requestów. KSeF.6C pozostaje osobnym kontrolowanym DEMO E2E. Status: `KSeF.6B CLOSED`.

### KSeF.6B.1 — first send from Invoice list

Lista Faktur VAT pokazuje kompaktową akcję pierwszej wysyłki bez konieczności otwierania szczegółów dokumentu. Bieżące środowisko pochodzi wyłącznie z `KsefSetting.environment`; formularz listy nie przekazuje ani nie wybiera środowiska. Status w kolumnie KSeF jest najnowszym submissionem aktywnego środowiska, pobranym zbiorczo dla całej strony. Historia TEST nie udaje statusu DEMO i odwrotnie, a liczba zapytań KSeF nie rośnie wraz z liczbą wierszy.

Dedykowana trasa POST korzysta z `KsefManualInvoiceSubmissionService::submitFirstAttempt()` oraz istniejących blokad Faktury, konfiguracji i historii prób. Akcja jest dostępna wyłącznie dla sfinalizowanej Faktury VAT z aktualnie włączoną serią, aktywną integracją, deployment gate i środowiskiem dopuszczonym przez `KsefOperationalEnvironmentPolicy`. Dowolny submission w bieżącym środowisku wyłącza first send z listy, także `rejected` i `technical_failed`; świadomy retry, status refresh, reconciliation i UPO pozostają na ekranie pojedynczej Faktury. TEST i DEMO są obsługiwane, a PRODUCTION blokowane przed HTTP.

KSeF.6B.1 nie dodaje zbiorczej akcji checkboxów, kolejki, Automation, schedulera, status pollingu ani nowego mechanizmu transportowego. Miesięczny eksport KSeF.6B pozostaje bez zmian. Wszystkie regresje są fake-only, bez live requestów; KSeF.6C pozostaje osobnym etapem. Status: `KSeF.6B.1 CLOSED`.

### KSeF.6C — kontrolowana weryfikacja DEMO E2E

24 sierpnia 2026 r. diagnostyka `KSeF.6C-NETWORK-DIAG-001` przeszła w tym samym procesie PHP co aplikacja webowa, z bezpośrednim połączeniem wyłącznie do oficjalnego hosta DEMO. Refresh tokena dostępu, pobranie klucza publicznego, otwarcie pustej sesji i jej zamknięcie zakończyły się poprawnie, bez invoice POST. Wcześniejszy `network_error` wystąpił na tymczasowej lokalnej ścieżce proxy/forwardera przed dotarciem pierwszego requestu `/auth/token/refresh` do obserwatora lub MF; bezpośredni transport produkcyjnego kodu nie wykazał błędu. Status: `KSeF.6C-NETWORK-DIAG-001 — PASS`.

Po potwierdzeniu operatora wykonano `KSeF.6C-LIVE-DEMO-E2E-004` na istniejącej Fakturze testowej. Z ekranu pojedynczej Faktury utworzono dokładnie próbę nr 2, zachowując próbę nr 1 jako `technical_failed/network_error`; nie powstała próba nr 3. Próba nr 2 wykonała dokładnie jeden invoice POST, bez automatycznego retry i bez udziału eksportu miesięcznego. Status został pobrany jeden raz i przeszedł bezpośrednio do `accepted`; numer KSeF, tożsamość dokumentu oraz zamrożone metadane FA(3) przeszły walidację.

UPO pobrano z MF dokładnie raz. Oryginalny podpisany XML został zweryfikowany semantycznie, zachowany byte-for-byte i zaszyfrowany at rest; lokalne pobranie zwróciło identyczne bajty bez kolejnego requestu do MF, a ponowne application-level pobranie było idempotentne. Oryginalny artefakt zawiera jeden rootowy podpis XMLDSig; obecność podpisu została potwierdzona, ale nie wykonano kryptograficznej weryfikacji XAdES. `Original UPO XSD-valid: NO`. `Compatibility projection XSD-valid: YES`.

Cały przebieg wykonał `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0` oraz `MONTHLY EXPORT LIVE POST: 0`. Konfigurację środowiska, aktywność serii i mapowanie serii przywrócono do wartości sprzed próby, serwer diagnostyczny zatrzymano, a deployment gate ponownie ma domyślną wartość `false`. Historia obu prób i UPO pozostała trwałym audytem. Statusy: `KSeF.6C-LIVE-DEMO-E2E-004 — PASS`, `KSeF.6C CLOSED`, `KSeF.6 CLOSED`.

### KSeF.6D — lokalne włączenie operacyjne

24 sierpnia 2026 r. lokalny deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED` został świadomie pozostawiony aktywny do bieżących testów TEST i DEMO. Po zwykłym restarcie aplikacji kwalifikująca się Faktura w aktywnej serii KSeF pokazuje na liście akcję `WYŚLIJ`. Niezależna `KsefOperationalEnvironmentPolicy` nadal blokuje PRODUCTION przed utworzeniem submissionu i przed HTTP. Domyślna wartość w konfiguracji oraz `.env.example` pozostają `false`.

Kontrolowany smoke test nowej Faktury z wyłącznie fikcyjnymi danymi wykonał dokładnie jedną próbę i jeden invoice POST do DEMO, bez automatycznego retry i bez eksportu miesięcznego. Dokument przeszedł do `accepted`, otrzymał numer KSeF i jedno UPO. Oryginalne UPO przeszło walidację hash, tożsamości, odbiorcy DEMO i obecności pojedynczego podpisu, zostało zapisane szyfrowane oraz pobrane lokalnie byte-for-byte bez kolejnego requestu do MF; zgodność projekcji z UPO v4-3 przeszła. `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0`, `MONTHLY EXPORT LIVE POST: 0`. Lokalny runtime pozostał z gate `true`, a dedykowana seria DEMO pozostała aktywna i włączona do KSeF. Status: `KSeF.6D CLOSED`.

### KSeF.6E — finalize-on-send

Lista Faktur pokazuje akcję `WYŚLIJ` dla spójnej, wystawionej Faktury VAT kwalifikującej się do KSeF także wtedy, gdy dokument nie został jeszcze zamknięty. Potwierdzenie informuje, że wysłanie zamknie Fakturę i uniemożliwi jej dalszą edycję; dla DEMO zachowuje również ostrzeżenie o środowisku testowym. Szkice, dokumenty niespójne oraz Faktury posiadające historię w bieżącym środowisku nie otrzymują tej akcji. Zapytania KSeF listy pozostają stałe względem liczby wierszy, bez N+1.

Pierwsza próba ręczna wykonuje w jednej lokalnej transakcji `finalize -> authoritative FA(3) -> frozen submission prepare`. Używa istniejącego `InvoiceFinalizationService`, zachowuje kolejność blokad `Order -> Invoice` i rozpoczyna transport HTTP dopiero po zatwierdzeniu lokalnej transakcji. Błąd lokalnego preflightu albo przygotowania FA(3) wycofuje finalizację oraz submission. Po lokalnym commit finalizacja nie jest cofana: błąd przed wysłaniem zapisuje `technical_failed`, a niejednoznaczny wynik invoice POST zapisuje `uncertain` i wymaga reconciliation, bez ślepego retry. Dotychczasowy przepływ dokumentów już zamkniętych pozostaje bez zmian, a eksport miesięczny nadal wybiera wyłącznie Faktury z `finalized_at`.

Kontrolowany przebieg DEMO z fikcyjną, początkowo niezamkniętą Fakturą potwierdził akcję listy, jedną próbę i jeden invoice POST. Dokument został lokalnie zamknięty, następnie przyjęty, otrzymał numer KSeF oraz jedno podpisane UPO zapisane szyfrowane. UPO pobrano lokalnie byte-for-byte, a drugi odczyt aplikacyjny nie wykonał kolejnego requestu do MF. Edycja i usunięcie po finalizacji zostały zablokowane przez istniejące polityki. Wykonano jeden status GET i jeden zdalny UPO GET; `TEST LIVE REQUESTS: 0`, `PRODUCTION LIVE REQUESTS: 0`, `MONTHLY EXPORT LIVE POST: 0`. Lokalny gate pozostaje `true`, natomiast `.env.example` i domyślna konfiguracja pozostają `false`. Faktura `BLF 1/2026` (id `94`) była w chwili końcowej kontroli już zamknięta i posiadała przyjętą próbę DEMO, dlatego poprawnie pokazywała status KSeF zamiast `WYŚLIJ`; nie wykonano na niej żadnej operacji live. Statusy: `KSeF.6E CLOSED`, `KSeF.6 CLOSED`.

### KSeF.6F — background lifecycle follow-up

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

Automatyczna pierwsza wysyłka dotyczy wyłącznie nowych Faktur VAT w TEST lub DEMO. Pro formy, Korekty i PRODUCTION są odrzucane przed HTTP. Minutowy scheduler nadal dispatchuje wyłącznie odczytowe follow-upy statusu, reconciliation i UPO.

### KSeF.6G.1 — automatic submission hardening and asynchronous UPO

Automatyczny first-send używa dedykowanego połączenia bazodanowego `ksef_submit` i kolejki `ksef-submit`. Job ma `timeout = 120 s`, `tries = 1`, a połączenie `retry_after = 240 s`, więc rezerwacja kolejki nie może wygasnąć przed limitem czasu joba. Osobny worker uruchamia się przez `php artisan queue:work ksef_submit --queue=ksef-submit --sleep=1 --tries=1 --timeout=120`. Odczytowe follow-upy pozostają na `php artisan queue:work database --queue=ksef --sleep=3 --tries=1 --timeout=60`.

Payload joba zawiera wyłącznie ID Faktury, środowisko i `context_nip`. Deployment gate, `is_active`, `automatic_submission`, seria, środowisko i kontekst są ponownie sprawdzane przed first-attempt. Każda zmiana przed uruchomieniem workera anuluje konkretny job z zerem submissionów i zerem HTTP; ponowne włączenie konfiguracji nie wykonuje recovery ani backfillu. Faktura pozostaje do tego momentu edytowalna, a autorytatywny FA(3) powstaje z jej aktualnej wersji dopiero podczas finalizacji w workerze. Nie jest snapshotowany `lock_version` i nie powstał outbox.

Docelowy lokalny deployment ma niski wolumen, około 30 Faktur na godzinę. First-send nie posiada lokalnego limitera ani rezerwacji opóźnionych slotów: kwalifikujący się job jest gotowy natychmiast po commit, a jeden zalecany worker `ksef-submit` wykonuje joby sekwencyjnie. Oficjalne limity PRD dla sesji interaktywnej wynoszą 10/s, 30/min i 120/h dla otwarcia oraz zamknięcia sesji, a dla invoice POST 10/s, 30/min i 180/h; DEMO stosuje limity PRD. Odpowiedź `429` jest obsługiwana defensywnie z zachowaniem `Retry-After`, lecz nigdy nie uruchamia automatycznego attempt 2 ani ponownego invoice POST.

Pierwsze wysłanie, ręczne lub automatyczne, wykonuje najwyżej jeden natychmiastowy status GET. Przejście do `Accepted` zapisuje numer KSeF, natychmiast usuwa cache PDF i ustawia `follow_up_action = upo`, `follow_up_attempts = 0`, `next_follow_up_at = now + 1 min`. UPO nie jest pobierane w tym samym request/jobie; delayed `KsefSubmissionFollowUpJob` trafia na kolejkę `ksef`, a trwały termin pozostaje źródłem recovery dla schedulera. Pierwsza nieudana próba UPO przechodzi na 5 min, potem 15 min i 60 min, z pierwszeństwem `Retry-After`. Jawne „Pobierz UPO teraz” nadal działa synchronicznie i po sukcesie wygasza późniejszy job bez dodatkowego HTTP.

### PDF autorytatywnej Faktury KSeF z karty zamówienia

Dla zaakceptowanego submissionu karta zamówienia pokazuje klikalne `KSeF: <numer OMS>`. Kontrolowana trasa pobiera przez `GET /invoices/ksef/{ksefNumber}` dokładny XML z zamrożonego środowiska submissionu, używa istniejącego access-token managera i wymaga aktywnej integracji, deployment gate oraz środowiska dopuszczonego przez `KsefOperationalEnvironmentPolicy`. SHA-256 Base64 odpowiedzi musi zgadzać się jednocześnie z nagłówkiem `x-ms-meta-hash`, treścią odpowiedzi i hashem zamrożonego payloadu. Operacja nie zapisuje XML ani PDF w bazie.

API KSeF udostępnia autorytatywną Fakturę jako XML, nie jako gotowy PDF. Po pomyślnej kontroli integralności przeglądarka generuje plik PDF z XML przez przypięty oficjalny `@akmf/ksef-fe-invoice-converter` 1.1.31 z repozytorium CIRFMF; generator jest ładowany dopiero po kliknięciu. Do PDF przekazywany jest numer KSeF, data przetworzenia oraz oficjalny link weryfikacyjny KOD I. Statusy inne niż `accepted` pozostają nieklikalne i nie wykonują requestu.

KSeF.2A nie tworzy:

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

Kolejne etapy wdrożą mapowanie FA(3), sesje, transmisję i obsługę odpowiedzi dokumentowych. KSeF.2A nie finalizuje dokumentów i nie zmienia istniejącej domeny cyklu życia Faktur ani Korekt. Szczegóły protokołu są w oficjalnych źródłach MF: [OpenAPI KSeF](https://github.com/CIRFMF/ksef-api/blob/main/open-api.json), [uwierzytelnianie](https://github.com/CIRFMF/ksef-docs/blob/main/uwierzytelnianie.md) i [historia zmian API](https://github.com/CIRFMF/ksef-api/blob/main/api-changelog.md).

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

- `nullOnDelete`,
- snapshot pozycji pozostaje.

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

`invoice_items.product_id` jest nullable i nie posiada FK, ponieważ tabela `products` nie istnieje. Nie ma relacji `product()` w modelu. Powiązanie historyczne z `OrderItem` jest opcjonalne, a dane pozycji pozostają snapshotem.

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
