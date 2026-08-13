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

Tabela `ksef_series_settings` przechowuje kwalifikację istniejących serii do przyszłego przekazywania dokumentów. Backend dopuszcza wyłącznie aktywne serie typu Faktura VAT i Korekta oraz odrzuca Pro formę. Globalne `automatic_submission` określa przyszły tryb automatyczny lub ręczny dla włączonych serii. Zapis konfiguracji nie modyfikuje `InvoiceSeries`, dokumentów, `finalized_at`, snapshotów ani PDF.

KSeF.1 nie wykonuje żadnych połączeń HTTP. Obecnie nie tworzymy:

```text
ksef_submissions
pól ksef_* w dokumentach
XML FA(3)
UPO
statusów KSeF
kodów QR KSeF
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

Kolejne etapy wdrożą klienta API, rzeczywiste uwierzytelnianie, mapowanie FA(3), transmisję i obsługę odpowiedzi. Fundament konfiguracji nie finalizuje dokumentów i nie zmienia istniejącej domeny cyklu życia Faktur ani Korekt.

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
