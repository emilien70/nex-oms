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
- historię dokumentu,
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

Liczniki nie należą do Etapu 1A.

Docelowa tabela:

```text
invoice_series_counters
```

Przykładowe pola:

```text
id
invoice_series_id
period_key
last_number
created_at
updated_at
```

`period_key` zależy od `reset_period`:

```text
yearly  → 2026
monthly → 2026-07
none    → global
```

Rekomendowane ograniczenie:

```text
UNIQUE(invoice_series_id, period_key)
```

Nadawanie numeru musi odbywać się:

- w transakcji,
- z blokadą rekordu,
- bez `max(number) + 1`,
- bez generowania numeru w przeglądarce,
- dopiero przy finalnym wystawieniu.

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
- wiele pro form,
- korekty,
- zewnętrzne dokumenty.

Relacja nadal pozostaje `Order hasMany Invoices`, ponieważ obejmuje różne typy dokumentów i historię. Przyszły `InvoiceIssuingService` będzie centralnym wejściem dla ręcznego wystawiania, automatyzacji, API i integracji. Reguła jednej faktury VAT musi być egzekwowana transakcyjnie oraz mechanizmem bazodanowym lub równoważną blokadą. Próba ponownego wystawienia zwróci błąd biznesowy `invoice_already_exists`, bez pobierania kolejnego numeru.

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

Przykładowe jawne pola dokumentu:

```text
number
document_type
invoice_series_id
order_id
issue_date
sale_date
due_date
currency
total_net
total_tax
total_gross
buyer_name
buyer_tax_id
status
additional_information
```

Dokładna struktura zostanie ustalona przed etapem modelu faktury.

---

# 15. Pozycje faktury

Planowana tabela:

```text
invoice_items
```

Planowane pola:

```text
id
invoice_id
product_id nullable
name
description
quantity
unit
unit_price_net
unit_price_gross
vat_rate
vat_code
net_amount
tax_amount
gross_amount
discount_amount
currency
gtu_codes
position_order
created_at
updated_at
```

Ważne zasady:

- `product_id` jest opcjonalne,
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
invoice_events — pełna historia dokumentu
order_events   — skrócone zdarzenie widoczne na zamówieniu
```

Przykład:

```text
invoice_events:
- document_issued
- buyer_updated
- items_updated
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

Aktualnie biblioteka PDF nie jest zainstalowana.

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

Nie tworzymy osobnej tabeli pro form, chyba że przyszłe wymagania wykażą silną potrzebę.

Pro forma:

- ma własną serię,
- ma własny numer,
- ma własny snapshot,
- nie zwiększa rejestru sprzedaży VAT,
- może zostać powiązana z fakturą.

---

# 23. Korekty

Korekta powinna być dokumentem w tej samej tabeli `invoices` z typem:

```text
correction
```

Planowane powiązania:

```text
corrected_invoice_id
correction_parent_id nullable
```

Dokładna struktura wymaga osobnego projektu procesu korekt.

Korekta przechowuje:

- powód,
- dane przed,
- dane po,
- różnicę,
- własne pozycje,
- własny numer,
- własną historię.

Nie należy nadpisywać dokumentu źródłowego.

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

Obecnie nie tworzymy:

```text
ksef_submissions
pól ksef_*
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
- historię.

Po zakończeniu modułu faktur zostanie wykonany audyt gotowości KSeF.

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
- historię.

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
- historia dokumentów nie może zostać usunięta.

## Faktury

- przyszła operacja ma nazywać się `Usuń fakturę`,
- jest dostępna wyłącznie dla faktury nieprzyjętej przez KSeF i niewystawionej offline ani awaryjnie,
- podczas technicznego wysyłania lub oczekiwania na odpowiedź KSeF jest tymczasowo blokowana,
- jest ręczna i wymaga potwierdzenia z numerem dokumentu,
- usuwa rekord z aktywnych list, ale zapisuje osobny ślad audytowy,
- po dozwolonym usunięciu zamówienie ponownie może otrzymać fakturę VAT.

Dozwolone usunięcie zwalnia numer dla tej samej serii i tego samego okresu numeracji. Przyszły generator najpierw wybiera zwolniony numer, a dopiero przy jego braku zwiększa licznik. Wybór oraz oznaczenie numeru jako wykorzystanego muszą odbywać się transakcyjnie z ochroną przed współbieżnością. Usunięta faktura nie pozostaje jako wyszarzony dokument; historia jest zachowywana w audycie.

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

Dane prawne korekty będą pochodziły ze snapshotu dokumentu źródłowego. Korekta pozostaje osobnym dokumentem powiązanym z fakturą źródłową i w przyszłości zapisze wartości przed zmianą, po zmianie oraz różnicę. Korekty łańcuchowe będą bazowały na skutecznym stanie po poprzednich korektach. Domyślny powód jest podpowiedzią, a finalna wartość będzie snapshotem dokumentu. Pola warunkowe formularza są sterowane w JavaScript, lecz te same zależności egzekwuje Form Request. Zmiana typu serii własnej zachowuje nieaktywne ustawienia poprzedniego typu, a nowy typ otrzymuje bezpieczne wartości domyślne.

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

## Etap 2

- liczniki,
- generator numerów,
- testy współbieżności.

## Kolejne etapy

- model faktury,
- pozycje,
- kalkulator,
- wystawianie z zamówienia,
- pro formy,
- edycja,
- korekty,
- PDF,
- pliki zewnętrzne,
- historia,
- e-mail,
- rejestr sprzedaży,
- JPK,
- audyt KSeF,
- KSeF.

---

# 34. Zakazy architektoniczne

Bez wyraźnej decyzji nie należy:

- tworzyć `seller_profiles`,
- tworzyć `company_settings`,
- dodawać `orders.invoice_id`,
- przywracać tabeli numerów seryjnych,
- dodawać `orders.serial_numbers_text`,
- używać `float` w fakturach,
- tworzyć publicznych URL-i do PDF,
- instalować biblioteki PDF,
- implementować KSeF,
- implementować paragony,
- tworzyć osobnych tabel dla pro form wyłącznie z powodu typu,
- wykonywać `max(number) + 1`,
- generować numerów po stronie frontendowej,
- synchronizować automatycznie wystawionej faktury z zamówieniem,
- przechowywać danych sprzedawcy wyłącznie jako jeden dowolny tekst,
- używać ogólnego `document_settings` jako niekontrolowanego worka JSON.

---

# 35. Decyzje wymagające późniejszego zatwierdzenia

Do rozstrzygnięcia w odpowiednich etapach:

- biblioteka PDF,
- dokładna reguła zaokrągleń,
- strategia decimal/money,
- dokładne pola faktury,
- model korekt,
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
