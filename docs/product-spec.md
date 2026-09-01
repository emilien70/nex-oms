# NEX-OMS — specyfikacja produktu

## Status dokumentu

Ten dokument opisuje uzgodnione wymagania biznesowe i funkcjonalne projektu **NEX-OMS**.

Nie jest dokumentacją aktualnego stanu implementacji. Stan faktyczny należy ustalać na podstawie lokalnego kodu projektu:

```text
C:\projekty\nex-oms
```

Dokument odpowiada na pytania:

- co system ma robić,
- jak ma zachowywać się z punktu widzenia użytkownika,
- jakie decyzje biznesowe są już zatwierdzone,
- co pozostaje poza bieżącym zakresem,
- w jakiej kolejności funkcje mają być wdrażane.

Szczegóły techniczne, klasy, namespace, migracje i struktura katalogów powinny być opisane w `docs/architecture.md`.

---

# 1. Cel produktu

NEX-OMS jest lokalnym systemem OMS do obsługi sprzedaży i realizacji zamówień.

Główne cele:

- gromadzenie zamówień z wielu kanałów,
- obsługa danych klientów, płatności i dostawy,
- organizacja statusów zamówień,
- przygotowanie wysyłek,
- przechowywanie uwag sprzedawcy, w których właściciel może umieszczać numery seryjne jako zwykły tekst,
- wystawianie dokumentów sprzedaży,
- generowanie PDF,
- rejestrowanie historii operacji,
- prowadzenie rejestru sprzedaży,
- przygotowanie danych do JPK,
- późniejsza integracja z KSeF.

System jest rozwijany etapami. Każdy etap musi być możliwy do osobnego przetestowania i zatwierdzenia.

---

# 2. Model użytkowania

System jest przeznaczony dla jednego właściciela prowadzącego sprzedaż.

Założenia:

- właściciel sam przygotowuje i pakuje zamówienia,
- rozbudowany magazynowy workflow kompletacji nie jest potrzebny,
- role magazynierów nie są obecnie wymagane,
- aplikacja obsługuje jednego właściciela,
- różne serie dokumentów mogą zawierać różne dane sprzedawcy.

---

# 3. Kanały sprzedaży i integracje

Planowane lub istniejące źródła zamówień:

- Allegro,
- PrestaShop,
- ręczne tworzenie zamówień.

Planowane lub istniejące formy płatności:

- PayNOW,
- InPost Pay,
- płatności obsługiwane przez kanał sprzedaży,
- pobranie,
- płatność ręczna.

Planowane lub istniejące metody wysyłki:

- Wysyłam z Allegro,
- bezpośrednia integracja InPost,
- inne metody dostawy zapisane na zamówieniu.

Poza bieżącym zakresem:

- API Fakturowni,
- fiskalizacja,
- drukarki fiskalne,
- paragony i e-paragony.

---

# 4. Zamówienia

## 4.1. Statusy

Dozwolone statusy:

| Kod | Nazwa |
|---|---|
| `new` | Nowe |
| `pending` | Oczekujące |
| `shipped` | Wysłane |
| `cancelled` | Anulowane |

Nowe oraz importowane zamówienia domyślnie otrzymują:

```text
new
```

Nie należy dodawać kolejnych statusów bez decyzji właściciela.

## 4.2. Zakres danych zamówienia

Zamówienie powinno przechowywać lub udostępniać:

- wewnętrzne ID,
- identyfikator zewnętrzny,
- źródło,
- status,
- datę zakupu,
- dane klienta,
- dane do faktury,
- dane dostawy,
- pozycje,
- kwoty,
- walutę,
- sposób płatności,
- status płatności,
- kwotę opłaconą,
- koszt wysyłki,
- metodę wysyłki,
- pobranie,
- uwagi,
- numery seryjne,
- powiązane dokumenty sprzedaży.

Aktywny model płatności zamówienia używa wyłącznie statusów `unpaid` i `paid`. Dla dodatniej wartości zamówienia `paid` oznacza pełną wpłatę równą `total_gross`, natomiast brak albo częściowa wpłata pozostaje `unpaid`; status `refunded` nie należy do modelu. Zmiana kwoty zamówienia, statusu lub `paid_amount` musi zachować tę relację, a nadpłata jest odrzucana bez częściowego zapisu.

## 4.3. Interfejs zamówienia

Interfejs ma być prosty i zwarty.

Sekcje:

- dane do faktury,
- dane dostawy,
- informacje o zamówieniu,
- płatność,
- wysyłka,
- notatki,
- numery seryjne,
- dokumenty sprzedaży.

Nagłówek zamówienia powinien pozostać minimalistyczny.

Nie należy bez potrzeby dodawać:

- magazynowego workflow,
- wielu badge’ów,
- dużej liczby przycisków w nagłówku,
- funkcji przeznaczonych dla zespołu magazynowego.

---

# 5. Dane klienta i adresy

## 5.1. Dane do faktury

Widoczne i edytowalne:

- imię i nazwisko lub nazwa nabywcy,
- nazwa firmy,
- NIP,
- ulica,
- numer budynku,
- numer lokalu,
- kod pocztowy,
- miasto,
- kraj wybierany z polskiej listy krajów.

## 5.2. Dane dostawy

Widoczne i edytowalne:

- imię i nazwisko,
- nazwa firmy,
- ulica,
- numer budynku,
- numer lokalu,
- kod pocztowy,
- miasto,
- kraj wybierany z polskiej listy krajów.

E-mail i telefon odbiorcy powinny być prezentowane w sekcji informacji o zamówieniu, a nie powielane w głównym bloku adresu.

Kraj dostawy i kraj danych do faktury są niezależnymi wartościami. Są zapisywane jako kody ISO 3166-1 alpha-2, a interfejs rozwiązuje ich polskie nazwy przez centralny `CountryCatalog`. Kopiowanie adresu kopiuje również kod kraju w wybranym kierunku. Pobranie danych polskiej firmy z GUS ustawia jawnie kraj danych do faktury na `PL`; historyczny pusty kraj nie otrzymuje automatycznego fallbacku.

Snapshot dokumentu przechowuje zarówno `country_code`, jak i polską `country_name` nabywcy oraz odbiorcy. PDF Faktury VAT, Pro formy i Korekty drukuje kraj wyłącznie w bloku Nabywcy, np. `32-545 Psary, Polska`, na podstawie snapshotu. Kraj nie wpływa jeszcze na VAT ani OSS. Sposób płatności i powiązanie Faktury VAT z Pro formą pozostają bez zmian.

## 5.3. GUS/REGON

W sekcji danych do faktury zamówienia można pobrać dane polskiej firmy po prawidłowym NIP z oficjalnej usługi GUS BIR1.1/BIR1.2. Operacja uzupełnia formularz nazwą i strukturalnym adresem, ale nie zapisuje go automatycznie. Przy kilku wpisach użytkownik wybiera właściwy podmiot lub rodzaj działalności. Kraj jest ustawiany jawnie na `PL`.

Klucz GUS pozostaje wyłącznie w konfiguracji serwera. Pobranie danych nie zmienia wystawionych dokumentów. Faktura utworzona po zapisaniu formularza przechowuje dane nabywcy we własnym snapshotcie, a późniejsze zmiany zamówienia lub rejestru REGON nie zmieniają dokumentu historycznego.

---

# 6. Pozycje zamówienia

Tabela pozycji powinna być prosta i funkcjonalna.

Widoczne informacje:

- miniatura, jeśli jest dostępna,
- nazwa produktu,
- ilość,
- cena jednostkowa,
- wartość,
- akcje.

Bez wyraźnej decyzji nie pokazujemy:

- SKU,
- EAN,
- lokalizacji magazynowej,
- atrybutów,
- identyfikatorów ofert zewnętrznych.

Wewnętrzne ID produktu jest dozwolone i planowane.

---

# 7. Pole „Informacje”, uwagi sprzedawcy i numery seryjne

W ustawieniach każdej serii numeracji znajduje się pole tekstowe **„Informacje”**. Pole definiuje szablon treści, która ma zostać pokazana na fakturze lub pro formie w sekcji informacji dodatkowych.

Przykład:

```text
Numery seryjne zakupionych przedmiotów:
[uwagi_sprzedawcy]
```

Właściciel wpisuje numery seryjne w polu uwag sprzedawcy na zamówieniu.

Zmienna:

```text
[uwagi_sprzedawcy]
```

oznacza pełną treść tych uwag. Według audytu źródłem technicznym jest obecnie `orders.notes`, o ile lokalny kod nie wskaże bardziej szczegółowego mapowania.

Założenia:

- pole „Informacje” serii jest szablonem, a nie gotowym snapshotem,
- numery seryjne są zwykłym tekstem w uwagach sprzedawcy,
- jedno pole uwag dotyczy całego zamówienia,
- numery nie są przypisane do pojedynczych pozycji,
- nie tworzymy osobnej tabeli numerów seryjnych,
- nie dodajemy `orders.serial_numbers_text`,
- system nie rozpoznaje ani nie waliduje pojedynczych numerów seryjnych,
- numery seryjne pojawiają się na dokumencie tylko wtedy, gdy szablon zawiera `[uwagi_sprzedawcy]`.

Preferowane pole serii:

```text
additional_information_template
```

Podczas wystawiania dokumentu system:

1. pobiera szablon pola „Informacje” z serii,
2. pobiera uwagi sprzedawcy z zamówienia,
3. zastępuje wszystkie wystąpienia `[uwagi_sprzedawcy]` pełną treścią uwag,
4. zapisuje wyrenderowany wynik jako niezmienny snapshot informacji dodatkowych dokumentu.

Późniejsza zmiana uwag zamówienia lub szablonu serii nie zmienia wystawionego dokumentu.

Gdy uwagi są puste, znacznik jest zastępowany pustym tekstem i nie pozostaje widoczny na dokumencie.

---

# 8. Przyszły moduł Produkty

W głównym menu planowany jest moduł:

```text
Produkty
```

Docelowe założenia:

- każdy produkt posiada wewnętrzne ID,
- pozycja zamówienia może opcjonalnie wskazywać produkt,
- pozycja faktury może opcjonalnie wskazywać produkt,
- produkt może mieć domyślną stawkę VAT,
- produkt może mieć domyślne oznaczenia GTU,
- zmiana lub usunięcie produktu nie zmienia dokumentów historycznych.

Planowane relacje:

```text
order_items.product_id nullable
invoice_items.product_id nullable
```

Historyczne pozycje muszą działać również wtedy, gdy:

- nie są przypisane do produktu,
- produkt został usunięty,
- produkt został dezaktywowany,
- nazwa, VAT lub GTU produktu zmieniły się później.

Nazwa, ceny, VAT i GTU na fakturze są snapshotem dokumentu.

Nie należy automatycznie dopasowywać starych pozycji tylko po nazwie, SKU lub EAN.

---

# 9. Moduł faktur — zakres

Docelowy zakres:

- serie numeracji,
- faktury VAT,
- faktury pro forma,
- korekty,
- duplikaty,
- PDF,
- zewnętrzne dokumenty PDF,
- wysyłka e-mail,
- rejestr sprzedaży,
- zdarzenia cyklu życia dokumentów bez kopii poprzednich stanów,
- JPK,
- GTU,
- wewnętrzne ID produktu.

Poza bieżącym zakresem:

- paragony,
- e-paragony,
- drukarki fiskalne,
- API Fakturowni,
- pełna integracja KSeF.

---

# 10. Nawigacja modułu faktur

Główne sekcje:

- Faktury,
- Faktury pro forma,
- Korekty,
- Rejestr sprzedaży,
- Ustawienia.

Nie dodajemy sekcji paragonów.

W ustawieniach znajdują się między innymi serie numeracji.

---

# 11. Istniejący szkielet modułu

W lokalnym projekcie istnieją już:

- `Modules/Invoices/Http/Controllers/InvoiceController.php`,
- trasa `GET /invoices`,
- `resources/views/invoices/index.blade.php`,
- test `InvoicesPageTest`,
- link `Faktury` w sidebarze,
- przyciski `WYSTAW FAKTURĘ` i `PRO FORMA` na karcie zamówienia.

Są to szkielety lub placeholdery.

Nie należy tworzyć ich duplikatów. Kolejne etapy mają rozbudowywać istniejące elementy.

---

# 12. Lista dokumentów

Lista faktur powinna być zwarta i czytelna.

Filtry:

- seria,
- numer,
- miesiąc,
- rok,
- filtry zaawansowane.

Kolumny:

- checkbox,
- numer dokumentu,
- numer zamówienia,
- nabywca,
- NIP,
- brutto,
- data wystawienia,
- korekty,
- akcje.

Planowane akcje:

- dodanie zewnętrznego PDF,
- przekazanie dokumentu do źródła sprzedaży jako późniejszy placeholder,
- pobranie PDF,
- edycja,
- anulowanie lub bezpieczne usunięcie zależne od stanu.

Na obecnym etapie nie dodajemy akcji KSeF.

---

# 13. Serie numeracji

## 13.1. Lista serii

Lista serii ma być funkcjonalnie zbliżona do Base.

Kolumny:

- Rodzaj,
- Nazwa,
- Format,
- Pokaż/ukryj,
- Edytuj,
- Usuń.

Elementy:

- panel informacyjny,
- przycisk `Nowa seria numeracji`,
- pusta, nieklikalna gwiazdka oznaczająca serię systemową,
- przełączanie aktywności,
- edycja,
- bezpieczne usuwanie,
- liczba rekordów,
- paginacja.

Ekran listy serii jest dostępny pod adresem `/invoices/settings/series`. Pusta gwiazdka ma wyłącznie znaczenie informacyjne i oznacza serię systemową; nie służy do wyboru serii domyślnej. Serie systemowe są zawsze aktywne i nie można ich ukryć ani usunąć. Serie własne można aktywować, ukrywać oraz usuwać po spełnieniu reguł bezpieczeństwa.

Od Etapu 1C.1 przycisk tworzenia i ikony edycji są aktywne. Jeden wspólny modal obsługuje tworzenie oraz edycję, właściwy partial formularza jest ładowany przez AJAX, a zapis odbywa się standardowym żądaniem POST albo PATCH.

## 13.2. Typy serii

| Kod | Znaczenie |
|---|---|
| `invoice` | Faktura |
| `proforma` | Faktura pro forma |
| `correction` | Korekta |

Każdy typ może mieć wiele serii.

NEX-OMS posiada dokładnie trzy serie systemowe:

| Klucz systemowy | Typ | Nazwa początkowa | Format początkowy |
|---|---|---|---|
| `invoice` | `invoice` | Faktury | `BL %N/%Y` |
| `correction` | `correction` | Korekty | `BLK %N/%Y` |
| `proforma` | `proforma` | Faktury Pro-Forma | `BLPF %N/%Y` |

Serie systemowe mają `is_system = true`, unikalny `system_key` i są zawsze aktywne. Nie można ich usunąć, ukryć, zmienić ich typu, klucza ani przekształcić w serie własne. Ich nazwa, format numeru oraz ustawienia biznesowe pozostają edytowalne.

Serie własne mają `is_system = false` i `system_key = null`. Mogą być aktywne albo ukryte i mogą zostać bezpiecznie usunięte, jeżeli nie narusza to historii dokumentów.

Model nie używa pola `is_default`.

Nazwa serii ma być unikalna w obrębie typu dokumentu.

## 13.3. Aktywność

W bazie używamy pojęcia:

```text
is_active
```

W interfejsie może być prezentowane jako `Pokaż/ukryj`.

Ukrycie lub dezaktywacja:

- nie usuwa serii,
- nie zmienia dokumentów historycznych,
- wyklucza serię z użycia przy nowych dokumentach.

Serii użytej przez dokument nie wolno usuwać w sposób niszczący historię.

## 13.4. Format numeru

Planowane znaczniki:

```text
%N      numer kolejny
%NN...  numer kolejny z zerami wiodącymi
%M      miesiąc
%Y      rok czterocyfrowy
%y      rok dwucyfrowy
```

Tryby resetowania:

```text
monthly
yearly
none
```

Domyślne propozycje:

| Typ | Format | Reset |
|---|---|---|
| Faktura | `BL %N/%Y` | `yearly` |
| Pro forma | `BLPF %N/%Y` | `yearly` |
| Korekta | `BLK %N/%Y` | `yearly` |

Resetowania nie ustalamy wyłącznie na podstawie tokenów formatu.

Numer dokumentu jest przygotowywany przez centralny silnik numeracji. Etap 2B potrafi transakcyjnie nadać numer istniejącemu szkicowi, ale nie zmienia jego statusu na wystawiony i nie zastępuje przyszłego procesu finalnego wystawiania.

Mechanizm numeracji musi być:

- transakcyjny,
- odporny na równoczesne operacje,
- wolny od duplikatów,
- niezależny dla serii i okresu.

## 13.5. Domyślna seria korekt

Seria faktur może opcjonalnie wskazywać:

```text
default_correction_series_id
```

Relacja jest nullable.

Kolejność wyboru serii korekt:

1. seria przypisana do serii dokumentu źródłowego,
2. aktywna seria systemowa z `system_key = correction`,
3. czytelny błąd, jeśli żadna nie istnieje.

## 13.6. Konfiguracja serii

Każda seria może docelowo posiadać własne:

- nazwę,
- typ dokumentu,
- format numeru,
- okres resetowania,
- początek roku fiskalnego,
- aktywność,
- status serii systemowej tylko do odczytu,
- domyślną serię korekt,
- walutę,
- dane sprzedawcy,
- dane rachunku bankowego,
- miejsce wystawienia,
- wystawiającego,
- logo,
- szablon pola „Informacje” z obsługą zmiennych, w tym `[uwagi_sprzedawcy]`,
- ustawienia VAT,
- ustawienia wysyłki,
- obsługę produktów o wartości zero,
- ustawienia walutowe,
- sposób płatności,
- datę sprzedaży,
- termin płatności,
- zachowanie przy brakujących danych,
- identyfikację produktu,
- umieszczanie ID produktu w nazwie,
- domyślne GTU,
- domyślne procedury JPK,
- sposób łączenia oznaczeń serii i produktów,
- frazy usuwane z nazw produktów,
- ustawienia wydruku,
- języki,
- nazwę dokumentu,
- prezentację netto/brutto,
- widoczność VAT,
- numer zamówienia,
- identyfikator płatności,
- podpis odbiorcy,
- numer pro formy,
- oznaczenie oryginał/kopia,
- liczbę kopii.

Kod QR KSeF nie należy do bieżącego etapu.

## 13.7. Podstawowy formularz Etapu 1C.1

Formularz Etapu 1C.1 obejmuje wyłącznie:

- typ dokumentu,
- nazwę serii,
- format numeracji,
- resetowanie numeracji,
- początek roku fiskalnego,
- domyślną walutę,
- aktywność.

Tworzenie i edycja korzystają z jednego modala Bootstrap. Zmiana typu pobiera odpowiedni fragment HTML przez `fetch()`, natomiast zapis pozostaje zwykłym formularzem Laravel. Nowa seria zawsze jest serią własną z `is_system = false` i `system_key = null`.

Seria systemowa może zmieniać podstawowe ustawienia biznesowe, ale zachowuje typ dokumentu, klucz systemowy, status systemowy i aktywność. Nie ma paragonów ani pola `is_default`.

## 13.8. Ustawienia serii Faktura — Etap 1C.2

Formularz serii typu `invoice` przechowuje bezpośrednio w `invoice_series` dane sprzedawcy, rachunek bankowy, miejsce wystawienia, wystawiającego, przypisaną serię korekt oraz ustawienia VAT, dostawy, płatności, dat, pozycji i przyszłego wydruku. Różne serie mogą posiadać różne dane sprzedawcy; projekt nie używa `seller_profiles` ani `company_settings`.

Dane sprzedawcy mogą być zapisane częściowo. Ich kompletność będzie sprawdzana centralnie dopiero przed wystawieniem dokumentu. Opcjonalne logo serii jest przechowywane na prywatnym dysku `local`.

Pole `additional_information_template` przechowuje szablon „Informacje”. Token `[uwagi_sprzedawcy]` pozostaje w szablonie bez renderowania i zostanie rozwiązany dopiero podczas przyszłego wystawiania dokumentu, a wynik będzie snapshotem faktury.

Etap 1C.2 nie wystawia faktur, nie generuje PDF i nie oblicza VAT. Formularz Pro formy został rozbudowany w Etapie 1C.4. Paragony nie należą do modułu.

## 13.9. Ustawienia serii Korekta — Etap 1C.3

Formularz serii typu `correction` przechowuje domyślny powód korekty, źródło daty sprzedaży, wystawiającego i sposobu płatności, ustawienia nagłówka i pozycji oraz podstawowe ustawienia przyszłego wydruku. Wartości źródłowe są wybierane z zamkniętych, walidowanych list.

Dane prawne sprzedawcy, bank i logo nie są konfigurowane w serii korekt. Przyszła korekta odziedziczy je ze snapshotu dokumentu źródłowego. Domyślny powód jest wyłącznie podpowiedzią i finalnie zostanie zapisany jako snapshot dokumentu. Pole `additional_information_template` przechowuje nierozwiązany szablon, a `[uwagi_sprzedawcy]` zostanie zastąpione dopiero przy wystawianiu dokumentu.

Korekta jest osobnym dokumentem powiązanym z fakturą źródłową. Obowiązkowo pokazuje wartości przed zmianą, po zmianie i różnicę. Jedno zamówienie może mieć liniowy łańcuch odrębnych Korekt, ale najwyżej jedną bieżącą, niezfinalizowaną Korektę; jej edycja nadpisuje snapshoty i pozycje tego samego dokumentu, zachowując numer oraz tożsamość. Zamknięte Korekty są niezmienne. Data sprzedaży może pochodzić z faktury źródłowej albo z daty wystawienia korekty; wystawiający może pochodzić z faktury źródłowej albo z serii; sposób płatności może pochodzić z faktury źródłowej, zostać ukryty albo przyjąć stałą wartość serii.

Źródła daty sprzedaży, wystawiającego i sposobu płatności są autorytatywną konfiguracją serii; wartości przesłane przez formularz nie mogą ich nadpisać. Podczas edycji istniejąca Korekta korzysta z trybów zapisanych w `series_settings_snapshot` oraz z własnych rozwiązanych snapshotów, dlatego późniejsza zmiana konfiguracji serii nie zmienia wystawionego dokumentu.

Etap 1C.3 nie tworzy korekt ani innych dokumentów, nie nadaje numerów i nie generuje PDF, JPK ani danych KSeF. Formularz Pro formy został rozbudowany w Etapie 1C.4. Nie ma paragonów.

## 13.10. Ustawienia serii Pro forma — Etap 1C.4

Formularz serii typu `proforma` przechowuje bezpośrednio w `invoice_series` własne dane sprzedawcy, rachunek bankowy, miejsce wystawienia, wystawiającego oraz ustawienia VAT, dostawy, płatności, dat, pozycji, informacji i przyszłego wydruku. Pro forma współdzieli z Fakturą pola o takim samym znaczeniu, ale nie posiada serii korekt. Dla końcowego typu `proforma` wartość `default_correction_series_id` jest zawsze `null`.

Pro forma posiada własną serię i własny numer, nie zużywa numeru faktury VAT, nie jest fakturą VAT, nie trafia do rejestru VAT ani JPK jako faktura sprzedaży i nie jest wysyłana do KSeF. Projekt nie używa `seller_profiles` ani `company_settings`; dane sprzedawcy należą bezpośrednio do serii. Opcjonalne logo jest przechowywane na prywatnym dysku `local`.

Pole `additional_information_template` nadal przechowuje literalny token `[uwagi_sprzedawcy]`. Dane sprzedawcy, nabywcy, pozycji i wyrenderowanych informacji zostaną zapisane jako snapshot dopiero podczas przyszłego wystawiania dokumentu. Etap nie wystawia Pro form, nie nadaje numerów i nie generuje PDF. Nie ma paragonów.

---

# 14. Dane sprzedawcy

NEX-OMS obsługuje jednego właściciela systemu, ale każda seria numeracji ma własne dane sprzedawcy.

Nie tworzymy:

- `seller_profiles`,
- `company_settings`,
- centralnego profilu sprzedawcy.

Każda seria może zawierać te same lub inne dane.

Pola strukturalne:

- nazwa firmy,
- NIP,
- REGON,
- BDO,
- ulica,
- numer budynku,
- numer lokalu,
- kod pocztowy,
- miasto,
- województwo,
- kod kraju,
- e-mail,
- telefon,
- nazwa banku,
- rachunek bankowy,
- SWIFT/BIC,
- miejsce wystawienia,
- wystawiający,
- logo,
- informacje dodatkowe.

Pola mogą być puste podczas tworzenia roboczej serii.

Przed aktywowaniem serii lub wystawieniem dokumentu należy wymagać co najmniej:

- nazwy sprzedawcy,
- NIP,
- ulicy,
- numeru budynku,
- kodu pocztowego,
- miasta,
- kodu kraju.

Po wystawieniu dokumentu dane sprzedawcy są kopiowane do `seller_snapshot`.

Zmiana serii nie może zmienić dokumentów historycznych.

---

# 15. Wystawianie faktury z zamówienia

Na karcie zamówienia znajdują się akcje:

```text
WYSTAW FAKTURĘ
PRO FORMA
```

Przed pierwszym wystawieniem nie planuje się rozbudowanego formularza.

Przy ręcznym wystawianiu użytkownik wybiera jedną z aktywnych serii właściwego typu. Seria systemowa jest zawsze dostępna, ale aktywne serie własne również mogą zostać wybrane.

Akcja automatyczna `Wystaw Fakturę` przechowuje jawny `invoice_series_id` aktywnej serii typu `invoice`. Nie wybiera serii na podstawie nazwy ani niejawnej „domyślności” i deleguje wystawienie do centralnego `InvoiceIssuingService`.

System:

1. używa serii wybranej ręcznie albo wskazanej przez `invoice_series_id` automatyzacji,
2. pobiera ustawienia serii,
3. kopiuje dane zamówienia,
4. tworzy snapshot sprzedawcy,
5. tworzy snapshot nabywcy,
6. tworzy opcjonalny snapshot odbiorcy,
7. kopiuje pozycje,
8. oblicza netto, VAT i brutto,
9. dodaje wysyłkę według ustawień serii,
10. kopiuje płatność,
11. rozwiązuje `[uwagi_sprzedawcy]` i zapisuje wynikowe informacje dodatkowe,
12. ustala GTU i procedury JPK,
13. nadaje numer transakcyjnie,
14. zapisuje historię.

Relacja:

```text
Order hasMany Invoices
```

Nie dodajemy pojedynczego `invoice_id` do tabeli `orders`.

Jedno zamówienie może mieć wiele dokumentów różnych typów, ale najwyżej jedną istniejącą Fakturę VAT, jedną logiczną Pro formę z jednym bieżącym stanem i liniowy łańcuch odrębnych Korekt. Najwyżej jedna Korekta może być bieżąca i niezfinalizowana. Ponowne wygenerowanie PDF nie tworzy nowego dokumentu.

## 15.1. Jedna faktura VAT na zamówienie

Wszystkie ścieżki wystawiania faktury VAT — ręczne, automatyczne, API i integracje — muszą korzystać z zaimplementowanego centralnego `InvoiceIssuingService`.

Serwis przed pobraniem numeru sprawdza, czy faktura VAT już istnieje, i zabezpiecza tę regułę transakcyjnie również przed równoległymi procesami. W przypadku duplikatu użytkownik otrzymuje komunikat:

```text
Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.
```

Serwis zwraca błąd biznesowy `invoice_already_exists` z komunikatem `Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.` Automatyczna akcja zachowuje kontrolowany błąd wykonania i nie pobiera kolejnego numeru.

Relacja pozostaje `Order hasMany Invoices`; nie dodajemy `invoice_id` do `orders`.

## 15.2. Docelowy widok faktury przy zamówieniu

Przed wystawieniem widok pokazuje rozwijany przycisk `WYSTAW FAKTURĘ` z aktywnymi seriami typu `invoice` oraz osobny przycisk `PRO FORMA`.

Po wystawieniu przycisk faktury znika. Zastępuje go numer faktury otwierający PDF w nowej karcie przez kontrolowaną trasę Laravel (`target="_blank"`, `rel="noopener"`), bez ujawniania prywatnej ścieżki storage. Obok numeru jest dostępny czerwony krzyżyk opisany jako `Usuń fakturę`.

## 15.3. Usuwanie Faktury i Pro formy oraz zwalnianie numeru

Ręczne usunięcie wystawionej Faktury VAT jest dostępne z karty zamówienia oraz ekranu edycji dokumentu. Operacja wymaga potwierdzenia zawierającego dokładny numer dokumentu i jest blokowana, gdy Faktura posiada Korektę albo jej slot, zamówienie lub tożsamość numeracji są niespójne.

Aktywną Pro formę można usunąć z karty zamówienia, z jej listy albo zbiorczo z zaznaczenia. Pro forma zastąpiona przez istniejącą Fakturę VAT pozostaje chroniona i nie może zostać usunięta. Po usunięciu aktywnej Pro formy zamówienie może otrzymać nową Pro formę.

Usunięcie odbywa się transakcyjnie. Dokument, jego pozycje, slot i prywatny plik PDF znikają z bieżącego stanu, natomiast `order_events` zachowuje audyt numeru, serii, okresu, sekwencji i kontekstu operacji. Po usunięciu zamówienie może ponownie otrzymać dokument tego samego typu. Pro forma zastąpiona dokładnie przez usuwaną Fakturę zostaje odblokowana, ponownie pojawia się jako aktywna akcja i zachowuje dotychczasowy numer oraz snapshot. Przywrócenie zapisuje osobne zdarzenie `proforma_restored`.

Dozwolone usunięcie nie tworzy ogólnej puli zwolnionych numerów. Wewnętrzne luki numeracji nie są ponownie używane: usunięcie dokumentu 11 przy istniejących dokumentach 10, 12 i 13 nie zmienia kolejnego numeru 14. Serwis może cofnąć wyłącznie wolny koniec numeracji w tej samej serii i okresie, nie niżej niż `protected_floor_sequence_number`. Zmiana licznika pozostawia rekord w `invoice_number_counter_adjustments`.

Pola i procesy KSeF, w tym blokady dokumentu przyjętego, wysyłanego, wystawionego offline lub awaryjnie, nie istnieją jeszcze w schemacie i pozostają zakresem przyszłej integracji. Automatyzacja usuwania Faktur nie jest dostępna.

---

# 16. Snapshot dokumentu

Dokument po wystawieniu posiada własne, niezmienne dane historyczne.

Snapshot obejmuje:

- sprzedawcę,
- nabywcę,
- odbiorcę,
- pozycje,
- nazwy produktów,
- wewnętrzne ID produktów,
- ilości,
- jednostki,
- ceny jednostkowe,
- rabaty,
- netto,
- VAT,
- brutto,
- wysyłkę,
- sposób płatności,
- termin płatności,
- kwotę zapłaconą,
- walutę,
- kurs waluty,
- daty,
- numer zamówienia,
- identyfikator płatności,
- wynikową treść uwag sprzedawcy zawierającą numery seryjne,
- GTU,
- procedury JPK,
- informacje dodatkowe.

Zmiana zamówienia, produktu, serii, danych firmy, VAT lub rachunku bankowego nie może automatycznie zmienić wystawionego dokumentu.

---

# 17. Edycja dokumentu

Edycja działa na snapshotach faktury.

Ekran powinien umożliwiać porównanie:

- aktualnych danych zamówienia,
- danych zapisanych na dokumencie.

Planowane akcje:

```text
Kopiuj aktualne pozycje z zamówienia
Kopiuj aktualne dane nabywcy z zamówienia
```

Nie synchronizujemy dokumentu automatycznie z zamówieniem.

Po wystawieniu korekty źródłowych danych finansowych nie wolno swobodnie nadpisywać.

---

# 18. Pozycje faktury i obliczenia

Każda pozycja faktury przechowuje docelowo:

- nazwę,
- opcjonalne `product_id`,
- opis,
- ilość,
- jednostkę,
- cenę jednostkową netto,
- cenę jednostkową brutto,
- stawkę VAT,
- kwotę netto,
- kwotę VAT,
- kwotę brutto,
- rabat,
- walutę,
- GTU, jeśli dotyczy.

Obliczenia:

- nie używają `float`,
- są wykonywane po stronie serwera,
- korzystają z jednej reguły zaokrąglania,
- są testowane dla różnic groszowych,
- sumują pozycje i wysyłkę w sposób jednoznaczny.

Aktualny model pozycji zamówienia posiada głównie brutto i opcjonalny VAT. Tych ograniczeń nie należy przenosić do modelu faktury.

---

# 19. Wysyłka na fakturze

Seria określa sposób obsługi kosztu wysyłki.

Możliwe zachowania:

- doliczenie wysyłki jako osobnej pozycji,
- pominięcie wysyłki,
- własna nazwa pozycji wysyłki,
- własna lub dziedziczona stawka VAT wysyłki.

Koszt wysyłki na dokumencie jest snapshotem.

Zmiana kosztu dostawy na zamówieniu nie zmienia wystawionego dokumentu.

---

# 20. Płatności

Dokument powinien przechowywać:

- sposób płatności,
- termin płatności,
- datę płatności,
- kwotę zapłaconą,
- informację o pobraniu,
- identyfikator płatności,
- rachunek bankowy wynikający z serii.

Pierwsza wersja może kopiować dane z zamówienia.

Docelowa obsługa wielu wpłat, zwrotów i alokacji może wymagać oddzielnego rejestru płatności.

---

# 21. Faktury pro forma

Pro forma:

- ma własny typ,
- ma własną serię,
- może być wystawiona z zamówienia,
- posiada własny snapshot,
- może mieć PDF,
- nie jest traktowana jak faktura VAT,
- może być powiązana z późniejszą fakturą.

Pro forma zawsze posiada własny numer wynikający z własnej serii. Jedno zamówienie posiada maksymalnie jedną bieżącą logiczną Pro formę; przyszłe odświeżenia tworzą kolejne wersje tego samego dokumentu bez zmiany numeru. Pro forma nie zużywa numeru faktury VAT, nie jest wysyłana do KSeF i nie trafia do rejestru VAT ani JPK jako faktura sprzedaży.

---

# 22. Korekty

Korekta jest osobnym dokumentem.

Musi posiadać:

- własny numer,
- własną serię,
- powiązanie z dokumentem źródłowym,
- dane przed zmianą,
- dane po zmianie,
- różnicę,
- własny snapshot,
- własny bieżący snapshot.

Planowane przypadki:

- korekta pozycji,
- korekta ilości,
- korekta ceny,
- korekta VAT,
- korekta danych nabywcy,
- pełny zwrot,
- częściowy zwrot.

Jedno zamówienie może mieć wiele odrębnych Korekt tworzących poprawny liniowy łańcuch. Każda Korekta wskazuje pierwotną Fakturę przez `corrected_invoice_id`; pierwsza ma `previous_correction_id = null`, a kolejna wskazuje ostatnią zamkniętą Korektę. Faktura posiadająca jakąkolwiek Korektę nie jest zwyczajnie edytowalna ani usuwalna. Slot typu `correction` wskazuje wyłącznie bieżącą, niezfinalizowaną Korektę i chroni regułę najwyżej jednego takiego dokumentu również przy równoległych operacjach. Dopóki bieżąca Korekta istnieje, ponowne wejście w tworzenie przekierowuje do jej edycji. Edycja nadpisuje jej snapshoty i pozycje, zachowując `id`, numer, serię oraz okres numeracji. Finalizacja usuwa slot i zamyka dokument przed edycją oraz usunięciem; następna Korekta kopiuje swój stan `BEFORE` ze stanu `AFTER` zamkniętego ogona łańcucha.

Nullable `finalized_at` jest neutralną blokadą domenową treści Faktury VAT lub Korekty, a nie statusem przyjęcia dokumentu przez KSeF. Istniejące dokumenty nie są automatycznie finalizowane. Finalizacja nie zmienia numeru, `lock_version`, snapshotów, pozycji, cache PDF ani zdarzeń; Pro forma nie podlega finalizacji. Zamkniętych dokumentów nie można edytować ani usuwać, ale zamknięta Faktura nadal może być źródłem pierwszej Korekty, a zamknięty ogon łańcucha źródłem kolejnej.

Numer korekty jest nadawany przy finalnym wystawieniu.

---

# 23. Duplikaty

Duplikat:

- nie otrzymuje nowego numeru faktury,
- używa numeru dokumentu źródłowego,
- zawiera oznaczenie `DUPLIKAT`,
- zawiera datę wystawienia duplikatu,
- generuje osobny PDF,
- zapisuje zdarzenie w historii,
- nie zwiększa wartości rejestru sprzedaży.

---

# 24. Dokumenty zewnętrzne

System ma umożliwić dołączenie zewnętrznego dokumentu.

Założenia:

- tylko PDF,
- bez OCR,
- prywatne przechowywanie,
- metadane,
- suma kontrolna,
- możliwość oznaczenia pliku podstawowego dla klienta,
- pobieranie przez kontroler lub bezpieczny podpisany URL.

Zewnętrzny PDF nie może samodzielnie nadpisywać danych strukturalnych dokumentu.

---

# 25. PDF

PDF powinien zawierać:

- typ i numer dokumentu,
- daty,
- dane sprzedawcy,
- dane nabywcy,
- opcjonalne dane odbiorcy,
- pozycje,
- ilości i jednostki,
- netto,
- VAT,
- brutto,
- podsumowanie stawek VAT,
- płatność,
- rachunek bankowy,
- wysyłkę,
- numer zamówienia,
- informacje dodatkowe,
- wynikową treść uwag sprzedawcy, w której mogą znajdować się numery seryjne,
- oznaczenie duplikatu, jeśli dotyczy.

Pliki są przechowywane prywatnie. Etap 2D używa biblioteki `tecnickcom/tcpdf`; plik jest generowany na żądanie wyłącznie z zapisanych snapshotów dokumentu i pozycji, atomowo zapisywany na prywatnym dysku `local` i udostępniany inline przez kontrolowaną trasę. Faktura VAT, Pro forma i przygotowany renderer Korekty nie zawierają stopki generatora ani numerów stron.

---

# 26. Zdarzenia dokumentów

Planowany podział zdarzeń cyklu życia:

```text
invoice_events — wybrane zdarzenia cyklu życia dokumentu
order_events   — skrócone zdarzenia związane z fakturą
```

Przykładowe zdarzenia:

- utworzenie,
- wystawienie,
- wygenerowanie duplikatu,
- wystawienie korekty,
- wysyłka e-mail.

Zdarzenia nie przechowują kopii poprzednich stanów. Zwykła edycja Faktury nie tworzy zdarzenia.
- wygenerowanie PDF,
- wysłanie e-maila,
- dodanie zewnętrznego PDF,
- wystawienie korekty,
- wystawienie duplikatu,
- anulowanie,
- bezpieczne usunięcie dokumentu roboczego.

Istotne operacje finansowe zapisują dokument i zdarzenie w jednej transakcji.

---

# 27. JPK i GTU

## 27.1. GTU

System ma obsługiwać oznaczenia GTU zgodne z aktualnymi wymaganiami.

Źródła:

- domyślne oznaczenia serii,
- oznaczenia produktów,
- ewentualna ręczna korekta dokumentu.

Planowane strategie:

```text
series_only
products_only
merge
```

Rekomendowana wartość domyślna:

```text
merge
```

Końcowe oznaczenia są snapshotem dokumentu.

## 27.2. Procedury JPK

Seria może zawierać domyślne procedury JPK.

Dozwolone wartości muszą być walidowane po stronie serwera.

Nie przechowujemy dowolnych kodów wpisanych bez kontroli.

Dla list oznaczeń preferowane są walidowane tablice JSON.

## 27.3. Eksport JPK

Pełny eksport JPK jest osobnym etapem po uruchomieniu:

- faktur,
- korekt,
- rejestru sprzedaży.

Docelowo obejmie:

- wybór okresu,
- mapowanie dokumentów,
- mapowanie korekt,
- GTU,
- procedury,
- generowanie XML,
- walidację XSD,
- raport błędów,
- historię eksportów.

Wysyłka JPK do administracji nie należy do pierwszej wersji.

---

# 28. Rejestr sprzedaży

Rejestr sprzedaży powinien umożliwiać:

- filtrowanie po okresie,
- filtrowanie po serii,
- uwzględnianie faktur i korekt,
- podsumowanie netto,
- podsumowanie VAT,
- podsumowanie brutto,
- późniejszy eksport,
- przygotowanie danych dla JPK.

Pro formy nie zwiększają rejestru sprzedaży.

Duplikaty nie tworzą nowej sprzedaży.

---

# 29. Wysyłka e-mail

Docelowo dokument może być wysłany e-mailem.

Funkcja:

- używa wskazanego adresu,
- dołącza właściwy PDF,
- zapisuje zdarzenie,
- raportuje błędy,
- nadaje się do obsługi w kolejce.

Nie jest to część pierwszego etapu serii numeracji.

---

# 30. KSeF — wybrana strategia

Wybrany wariant:

```text
Wariant 2:
architektura gotowa pod KSeF teraz,
integracja KSeF dopiero po pełnym sprawdzeniu modułu faktur.
```

## 30.1. Co przygotowujemy teraz

Podstawowy model faktur ma zapewniać:

- strukturalne dane sprzedawcy,
- strukturalne dane nabywcy,
- opcjonalne dane odbiorcy,
- pozycje z jednostką, ilością, netto, VAT i brutto,
- poprawne daty,
- płatność,
- walutę,
- rabaty,
- wysyłkę,
- korekty jako osobne dokumenty,
- niezmienne snapshoty,
- GTU i procedury JPK,
- niezależny numer dokumentu.

## 30.2. Fundament konfiguracji KSeF.1

NEX-OMS posiada jedną logiczną konfigurację KSeF dla całego systemu. Użytkownik wybiera aktywne środowisko `test`, `demo` albo `production`, natomiast dane uwierzytelniające są technicznie przechowywane osobno dla każdego środowiska. Nie oznacza to wielu integracji. Token jest szyfrowany, nie wraca do HTML ani danych sesji, a puste pole podczas edycji zachowuje dotychczasową wartość.

Konfiguracja obejmuje jedną globalną politykę przekazywania dokumentów oraz osobne wskazanie kwalifikujących się serii. Do KSeF można przypisać wyłącznie istniejące, aktywne serie Faktur VAT i Korekt. Pro forma jest wykluczona zarówno w interfejsie, jak i w walidacji backendu.

Pole `is_active` jest globalnym przełącznikiem workflow dokumentowego KSeF i domyślnie ma wartość `false`. Wyłączona integracja blokuje ręczne i automatyczne przekazywanie dokumentów, ale nie jest warunkiem testowania credentiali ani połączenia.

Pole `zero_vat_classification` ma wartości `wdt`, `export` albo `domestic` i domyślnie `wdt`. Jest wyłącznie fallbackiem przyszłego buildera FA(3) dla pozycji z numeryczną stawką VAT `0.00`, które nie mają jawnego `InvoiceItem.vat_code`; jawna klasyfikacja pozycji ma pierwszeństwo. Planowane mapowanie wynosi odpowiednio `0 WDT`, `0 EX` i `0 KR`. Wybór `wdt` nie potwierdza spełnienia prawnych warunków WDT.

Pole `include_seller_vat_prefix` domyślnie ma wartość `false`. Wartość `true` zamraża dla nowej Faktury decyzję o dodaniu `PrefiksPodatnika=PL` w sekcji sprzedawcy FA(3); późniejsza zmiana konfiguracji nie zmienia istniejącego dokumentu.

Ustawienie `include_sale_date` zostało usunięte. Data sprzedaży jest cechą dokumentu i w przyszłym FA(3) będzie mapowana z Faktury, a nie sterowana przełącznikiem transportowym.

Konfiguracja KSeF nie wpływa na wystawione dokumenty, ich finalizację, snapshoty, numerację ani PDF. Nadal nie implementujemy:

- XML FA(3),
- wysyłki dokumentów,
- numeru i statusów KSeF,
- UPO,
- pozyskiwania i odnawiania certyfikatów KSeF,
- trybów offline,
- kodów QR KSeF,
- tabeli `ksef_submissions`,
- pól `ksef_*` w dokumentach.

## 30.3. Uwierzytelnianie tokenem i test połączenia KSeF.2A

KSeF.2A dodaje rzeczywistą komunikację diagnostyczną z oficjalnym API `/v2` dla środowisk TEST, DEMO i PRODUCTION. Nie przypina numeru builda API. Pełny test połączenia zawsze korzysta z zapisanej konfiguracji, pobiera ważny przez 10 minut challenge i jego `timestampMs` z MF oraz bieżący certyfikat MF do `KsefTokenEncryption`, szyfruje tekst `TokenKSeF|timestampMs` przez RSA-OAEP z SHA-256 i MGF1 SHA-256, przekazuje `publicKeyId`, oczekuje na wynik uwierzytelnienia oraz jednokrotnie pobiera access i refresh token. Asynchroniczny status `100` oznacza oczekiwanie, `200` pozwala wykonać redeem, a statusy terminalne kończą flow bez redeem.

Token KSeF, access token i refresh token są szyfrowane w bazie i ukryte w serializacji. Runtime tokeny oraz ostatni wynik testu są przechowywane osobno dla każdego środowiska. Zmiana Tokena KSeF unieważnia runtime tylko tego środowiska, a zmiana NIP-u kontekstu unieważnia runtime wszystkich środowisk bez usuwania ich Tokenów KSeF. Przycisk testu działa także przy `is_active = false`, ale nie przyjmuje z przeglądarki tokenu, NIP-u ani środowiska i wymaga wcześniejszego zapisania zmian.

Test połączenia potwierdza wyłącznie poprawność zapisanej konfiguracji i uwierzytelnienia; nie odpytuje `personal/grants`, nie pobiera metadanych przez `GET /tokens` i nie diagnozuje `InvoiceWrite` w żadnym środowisku. Gotowość konkretnej operacji dokumentowej jest weryfikowana przez autorytatywną odpowiedź KSeF podczas tej operacji. Żądania używają `X-Error-Format: problem-details`; ostrzeżenia `X-System-Warning`, limity `Retry-After` i bezpieczne kody Problem Details są prezentowane bez surowych odpowiedzi i sekretów. KSeF.2A nie tworzy XML FA(3), nie otwiera sesji fakturowania, nie wysyła dokumentów, nie pobiera UPO, nie implementuje XAdES, trybu offline ani kodów QR i nie zmienia cyklu życia Faktur ani Korekt.

Kontrakt techniczny opiera się na oficjalnych źródłach MF: [OpenAPI KSeF](https://github.com/CIRFMF/ksef-api/blob/main/open-api.json), [uwierzytelnianie](https://github.com/CIRFMF/ksef-docs/blob/main/uwierzytelnianie.md) i [historia zmian API](https://github.com/CIRFMF/ksef-api/blob/main/api-changelog.md).

## 30.4. Import certyfikatu Authentication KSeF.2B.1

Druga metoda uwierzytelnienia korzysta z certyfikatu Authentication importowanego wraz z odpowiadającym mu kluczem prywatnym osobno dla środowisk TEST, DEMO i PRODUCTION. Certyfikat oraz znormalizowany, niechroniony dodatkowym hasłem PEM klucza są szyfrowane przez Laravel w bazie; hasło pliku jest używane wyłącznie podczas importu i nie jest utrwalane. Zapis Tokena KSeF i materiału certyfikatu jest niezależny, a przełączenie aktywnej metody nie usuwa drugiego credentiala.

Import wykonuje lokalne parsowanie X.509 i klucza, kryptograficzne dopasowanie pary, kontrolę okresu ważności, `Digital Signature` oraz parametrów RSA 2048 lub EC P-256. Certyfikat identyfikuje uwierzytelniającego, ale sam nie przenosi uprawnień ani nie jest lokalnie wiązany z NIP-em kontekstu. UI pokazuje wyłącznie bezpieczne metadane: ważność, typ klucza i fingerprint SHA-256. Zmiana metody albo materiału czyści runtime uwierzytelnienia i wynik testu danego środowiska, natomiast zmiana NIP-u zachowuje credentiale i czyści runtime wszystkich środowisk.

KSeF.2B.2 uruchamia test połączenia zapisanym certyfikatem Authentication. NEX buduje `AuthTokenRequest` 2.1 z `certificateSubject`, podpisuje go jako enveloped XAdES-BES algorytmem ECDSA-SHA256 dla EC P-256 albo RSA-SHA256 dla RSA 2048 i wysyła do `/auth/xades-signature`. Obie metody uwierzytelnienia korzystają ze wspólnego, ograniczonego pollingu, jednokrotnego redeem oraz tych samych szyfrowanych access i refresh tokenów. Materiał certyfikatu jest ponownie walidowany przed każdym świeżym uwierzytelnieniem, a klucz prywatny nigdy nie opuszcza aplikacji.

Test certyfikatem, tak samo jak test Tokenem KSeF, potwierdza konfigurację i uwierzytelnienie bez dodatkowej diagnostyki `InvoiceWrite`. NEX nie odpytuje w tym teście `personal/grants` ani `GET /tokens` i nie zgaduje powiązań PESEL-NIP. KSeF.2B.2 nie generuje CSR, nie pobiera ani nie rejestruje certyfikatu, nie obsługuje certyfikatów Offline, nie tworzy FA(3), nie otwiera sesji i nie wysyła dokumentów.

## 30.5. Semantyczne przygotowanie FA(3) KSeF.3B.1

Nowa Faktura VAT zapisuje wersjonowaną semantykę KSeF bez generowania XML. `tax_metadata_snapshot.ksef_tax` utrwala zwykły profil dokumentu, obowiązkowe adnotacje, deklarację MPP oraz jednoznaczne traktowanie każdej zapisanej pozycji. Obsługiwane są stawki `23`, `22`, `8`, `7`, `5` oraz rozstrzygnięte `0 KR`, `0 WDT` i `0 EX`; pozostałe stawki i kody VAT nie blokują wystawienia Faktury NEX, ale powodują kontrolowaną odmowę eligibility FA(3). `zero_vat_classification` jest używane tylko przy pierwszym rozstrzygnięciu nowej lub podatkowo zmienionej pozycji, a zapisany wynik nie jest reinterpretowany po zmianie konfiguracji.

`buyer_snapshot` utrwala wersjonowaną tożsamość `pl_nip`, jednoznaczne `eu_vat` albo `none` oraz zwykłe flagi podmiotu (`JST=false`, `GV=false`). Nie jest wykonywana walidacja VIES ani odczyt bieżącego zamówienia. Ustawienie `default_split_payment`, domyślnie `false`, jest wyłącznie deklarowanym domyślnym MPP dla nowych Faktur i po zapisaniu pozostaje częścią snapshotu dokumentu.

Eligibility FA(3) jest oddzielone od poprawności Faktury NEX. Tryb preflight sprawdza zapisane snapshoty sprzedawcy, nabywcy i podatków, a tryb autorytatywny dodatkowo wymaga zamkniętego dokumentu. Finalizacja Faktury uruchamia preflight tylko wtedy, gdy integracja KSeF i jej seria są aktywne; nie dotyczy to Pro form ani Korekt. Historyczne dokumenty bez snapshotu nie są automatycznie uzupełniane. KSeF.3B.1 nie tworzy XML FA(3), nie otwiera sesji i nie wysyła dokumentów.

## 30.6. Generator dokumentu FA(3) KSeF.3B.2

NEX-OMS może wygenerować dla wystawionej Faktury VAT deterministyczny XML `FA (3) 1-0E`. Generator przyjmuje jawny czas utworzenia, wykonuje istniejące sprawdzenie eligibility, mapuje wyłącznie zapisane pozycje i snapshoty Faktury, buduje dokument przez DOM oraz waliduje go offline względem lokalnej, niezmienionej kopii oficjalnego XSD MF wraz z zależnościami. Tryb preflight dopuszcza niezfinalizowaną wystawioną Fakturę, natomiast tryb autorytatywny wymaga `finalized_at`; tryb nie zmienia treści XML.

Podsumowanie VAT powstaje z zapisanych rozstrzygnięć `ksef_tax.line_treatments`, w tym osobnych pól dla `0 KR`, `0 WDT` i `0 EX`. Dla waluty obcej kwoty podatku w PLN pochodzą wyłącznie z historycznego `converted_tax_summary`; generator nie pobiera nowego kursu. MPP, tożsamość nabywcy, REGON i BDO również pochodzą ze snapshotów dokumentu, bez fallbacku do bieżącego zamówienia, serii albo ustawień treściowych KSeF.

Sam generator nie zapisuje XML w bazie ani w plikach. KSeF.3B.2 nie otwiera sesji, nie wysyła Faktur, nie pobiera UPO i nie dodaje numeru KSeF, QR ani trybu offline; późniejsza warstwa transportowa może utrwalić dokładny wynik trybu autorytatywnego jako payload konkretnej próby.

## 30.7. Opcjonalne bloki dokumentu FA(3) KSeF.3C

Nowa Faktura VAT utrwala w `tax_metadata_snapshot.ksef_document` wersji 2 siedem decyzji dotyczących zawartości XML: odbiorcę, kontakt nabywcy, informacje dodatkowe, numer zamówienia, rachunek bankowy, GTU i prefiks VAT sprzedawcy. Zmiana bieżącej konfiguracji nie zmienia tych decyzji na istniejącym dokumencie. Snapshoty wersji 1 zachowują wcześniejszy kontrakt sześciu flag i dotychczasową prezentację prefiksu dla WDT, a Faktury bez `ksef_document` zachowują tryb core-only; żaden wariant historyczny nie jest uzupełniany z aktualnych ustawień.

Opcjonalna treść pochodzi wyłącznie z historycznych snapshotów Faktury i jej pozycji. Płatność może zawierać potwierdzoną pełną zapłatę z datą, termin, jednoznaczną albo opisaną inną formę i włączony rachunek sprzedawcy. Brak historii pojedynczych wpłat oznacza, że NEX nie tworzy danych zapłat częściowych. Odbiorca dostawy jest prezentowany jako inna rola `Podmiot3`, bez kopiowania NIP-u nabywcy. GTU jest wysyłane tylko wtedy, gdy pozycja ma najwyżej jeden unikalny poprawny kod; konfiguracja KSeF nie ogranicza ogólnego modelu `InvoiceItem.gtu_codes`.

Zakładka „Typy płatności” mapuje znormalizowane wartości `orders.payment_method` oraz osobny klucz płatności przy odbiorze na semantyczne typy FA(3). Globalny fallback domyślnie zachowuje oryginalny opis, a indywidualne mapowanie może wybrać `Gotówka`, `Karta`, `Bon`, `Czek`, `Kredyt`, `Przelew` albo `Mobilna`. Brak źródłowej formy płatności nie uruchamia fallbacku, a płatność przy odbiorze nie jest automatycznie uznawana za gotówkę.

Nowa Faktura VAT utrwala rozwiązanie w `payment_snapshot.ksef_payment` wersji 1. Typ `original` tworzy `PlatnoscInna` z zamrożonym opisem, a pozostałe typy zapisują odpowiadający kod `FormaPlatnosci` 1–7. Późniejsza zmiana konfiguracji nie reinterpretuje dokumentu; wyłącznie jawna edycja metody płatności niezfinalizowanej Faktury rozwiązuje snapshot ponownie. Historyczne Faktury bez tego klucza nie są uzupełniane z bieżącej tabeli mapowań. Konfiguracja formy płatności pozostaje niezależna od `payment_status`, `Zaplacono`, daty zapłaty i danych zapłat częściowych.

Informacje dodatkowe są normalizowane liniowo i dzielone bez utraty znaków na elementy zgodne z limitem XSD. Numer zamówienia pochodzi wyłącznie z utrwalonego zewnętrznego identyfikatora, nigdy z wewnętrznego ID bazy. Wszystkie bloki przechodzą lokalną walidację oficjalnym FA(3) XSD. Etap nadal nie otwiera sesji, nie wysyła Faktur, nie pobiera UPO i nie obejmuje Korekt ani Pro form.

## 30.8. Fundament transportu sesji online KSeF.4A.1

KSeF.4A.1 obsługuje wyłącznie sfinalizowaną Fakturę VAT zakwalifikowaną przez istniejący tryb autorytatywny FA(3). Każda próba zapisuje osobny rekord `ksef_invoice_submissions` z zamrożonym środowiskiem, NIP-em kontekstu uwierzytelnienia, NIP-em sprzedawcy z `Podmiot1`, numerem próby, czasem generowania, hashami i dokładnym XML. Kontekst i sprzedawca są odrębnymi tożsamościami, ponieważ oficjalny kontrakt KSeF dopuszcza działanie w uprawnionym kontekście innym niż sprzedawca. `payload_xml` jest szyfrowany przez cast aplikacyjny i nie występuje jawnie w bazie. Pro formy i Korekty nie są obsługiwane.

Fundament transportu sesji online został pierwotnie zaimplementowany według kontraktu KSeF API 2.6.1. Po udostępnieniu na środowisku TEST API 2.7.0 wykonano osobny audyt zgodności faktycznie serwowanego OpenAPI; nie wykazał on różnic wymagających zmiany kodu transportu. Implementacja nie przypina numeru wersji API, dlatego przed kolejnym kontrolowanym uruchomieniem na środowisku docelowym należy ponownie zweryfikować aktualny kontrakt OpenAPI.

Transport wybiera aktualny klucz `SymmetricKeyEncryption`, generuje jednorazowy 32-bajtowy klucz AES i 16-bajtowy IV, szyfruje XML przez AES-256-CBC z PKCS#7 oraz klucz sesji przez RSA-OAEP SHA-256/MGF1-SHA256. Hash SHA-256 i rozmiar bajtowy są liczone osobno dla XML oraz ciphertextu; wartości hash są kodowane Base64. Klucz AES, IV i ciphertext nie są utrwalane.

Przygotowanie próby odbywa się w krótkiej transakcji z blokadą Faktury, natomiast żaden lock bazy nie obejmuje HTTP. Transport i odczyt statusu wymagają nadal tego samego zamrożonego kontekstu; zmiana globalnego NIP-u blokuje HTTP zamiast użyć ważnego tokena z innego kontekstu. Aktywna, zaakceptowana albo niejednoznaczna próba blokuje kolejną wysyłkę. Timeout, 5xx lub niekompletna odpowiedź po side-effecting POST Faktury prowadzą do `uncertain` i nigdy do automatycznego ponowienia. Błąd zamknięcia sesji pozostawia stan `submitted` i zapisuje osobną bezpieczną diagnostykę. Endpoint statusu może ustawić `processing`, `accepted` albo `rejected` dopiero po ścisłym potwierdzeniu numeru referencyjnego i hasha Faktury. `accepted` dodatkowo wymaga prawidłowego numeru KSeF z NIP-em odpowiadającym zamrożonemu sprzedawcy.

Etap jest chroniony deploymentowym `KSEF_INVOICE_SUBMISSION_ENABLED=false` i nawet po jego kontrolowanym włączeniu dopuszcza wyłącznie środowisko TEST. Nie ma trasy ani przycisku wysyłki, automatycznej akcji, listenera, observera, kolejki, harmonogramu, automatycznego pollingu, trybu batch/offline, QR ani pobierania UPO. Pro forma nigdy nie podlega KSeF, a Korekty FA(3) pozostają poza zakresem obecnego generatora i transportu.

Wartość `automatic_submission=true` może istnieć w trwałych ustawieniach, ale sama nie uruchamia transportu przy wyłączonym deployment gate i braku aktywnego workflow automatycznego. Przed przyszłym ustawieniem `KSEF_INVOICE_SUBMISSION_ENABLED=true` trzeba świadomie zweryfikować `automatic_submission` oraz wszystkie ścieżki triggerów i workflow. Automatyczne testy transportu nadal korzystają z `Http::fake()` i blokują stray HTTP.

### Walidacja end-to-end KSeF.4A

Status etapu: `KSeF.4A CLOSED`. Warstwa transportowa została technicznie zamknięta kontrolowaną walidacją end-to-end na środowisku KSeF TEST serwującym API 2.7.0. Użyto wyłącznie w pełni syntetycznej Faktury VAT, zamówienia, kontrahentów, danych bankowych i oznaczeń; nie wykorzystano rzeczywistych danych klientów, zamówień, rachunków, numerów seryjnych, BDO ani REGON. Syntetyczny kontekst utworzono oficjalnym mechanizmem testowym KSeF, a uwierzytelnienie wykonano certyfikatem self-signed dopuszczonym w TEST.

Walidacja potwierdziła autorytatywny FA(3), lokalne oficjalne XSD, zamrożenie i szyfrowanie payloadu at rest, SHA-256, rozmiar bajtowy, AES-256-CBC, RSA-OAEP SHA-256/MGF1-SHA256, `SymmetricKeyEncryption`, Certificate/XAdES, `InvoiceWrite`, otwarcie sesji online, POST Faktury, zamknięcie sesji i odczyt statusu. POST Faktury wykonano dokładnie raz; nie było automatycznego resend, nowej próby ani ślepego retry. KSeF zwrócił status 200 i numer KSeF, a korelacja `referenceNumber`, `invoiceHash`, CRC numeru KSeF oraz NIP-u sprzedawcy zakończyła się powodzeniem. Lokalny stan został zapisany jako `accepted`, bez zmiany zamrożonego payloadu.

Osobna weryfikacja po live teście wykonała dokładnie jeden read-only status GET. Ponownie potwierdziła kod 200, zgodne reference/hash, ten sam numer KSeF, lokalny stan `accepted`, jeden rekord submission i numer próby 1. Zaakceptowany syntetyczny dokument wraz z Order, Series i Submission pozostaje w bazie jako audit trail i nadal blokuje usunięcie Faktury.

Po weryfikacji bezpiecznie przywrócono poprzedni credential i kontekst TEST, unieważniono syntetyczny runtime auth oraz wykonano świeży test uwierzytelnienia i połączenia z wynikiem `InvoiceWrite=YES`. Dedykowana seria testowa została zachowana dla audit trail, ale wyłączona dla KSeF. `DEMO LIVE REQUESTS: 0`; `PRODUCTION LIVE REQUESTS: 0`.

Zamknięcie KSeF.4A oznacza zweryfikowany happy path warstwy transportowej na TEST, nie gotowy workflow użytkownika ani produkcyjny rollout. Użytkownik nie ma jeszcze akcji „Wyślij do KSeF”; nie zaimplementowano obsługi UPO, QR, offline, batch ani automatycznych retry. Failure modes pozostają pokryte automatycznymi testami fake-only, lecz nie były wszystkie sprawdzane live. Kolejny etap może udostępnić kontrolowany workflow aplikacyjny nad zweryfikowanym transportem, ale jego zakres nie jest jeszcze zatwierdzony.

## 30.9. Ręczny workflow TEST KSeF.4B.1

KSeF.4B.1 udostępnia na read-only ekranie sfinalizowanej Faktury VAT ręczną pierwszą wysyłkę do KSeF TEST oraz ręczne, pojedyncze sprawdzenie statusu. Akcja wysyłki jest żądaniem POST z CSRF i jawnym oznaczeniem TEST. W ramach jednego wywołania istniejący transport przygotowuje najwyżej jedną próbę, otwiera jedną sesję i wykonuje najwyżej jeden POST Faktury. Po udanym zakończeniu transportu NEX wykonuje dokładnie jeden status GET bez pollingu i retry; wynik może od razu ustawić `processing`, `accepted` albo `rejected`. Od KSeF.6G.1 sprawdzenie lub uzgodnienie wyniku `accepted` planuje osobne background UPO zamiast pobierać je w tym samym żądaniu. Niedostępne jeszcze UPO nie cofa przyjętego statusu, a ręczna operacja pobrania pozostaje dostępna. Niepowodzenie dodatkowego odczytu statusu nie cofa udanej wysyłki, nie tworzy nowej próby i pozostawia dokument w stanie pozwalającym na późniejsze ręczne sprawdzenie.

Manualny orkiestrator blokuje Fakturę i bieżącą konfigurację w transakcji oraz atomowo sprawdza historię dla pary Faktura-bieżące środowisko. Każdy wcześniejszy rekord w tym samym środowisku, również `rejected` albo `technical_failed`, blokuje kolejną ręczną próbę. Historia z innego środowiska sama nie blokuje pierwszej próby w bieżącym środowisku. Przygotowanie pozostaje częścią tej transakcji, natomiast cały transport HTTP jest wykonywany dopiero po jej zatwierdzeniu.

Ręczny refresh jest osobnym żądaniem POST, dostępnym wyłącznie dla `submitted` i `processing`, i wykonuje dokładnie jeden status GET. Stany terminalne, `uncertain`, `preparing` i `session_opened` nie mają akcji ponowienia ani odświeżenia w 4B.1. Numer KSeF jest prezentowany w całości dopiero dla `accepted`; historia pokazuje wyłącznie bezpieczne pola i komunikaty, bez XML, hashy, NIP-ów, referencji sesji i surowych odpowiedzi.

Po potwierdzeniu statusu `accepted` PDF Faktury prezentuje pod numerem zamówienia numer KSeF, datę przetworzenia pochodzącą z `acquisition_date` oraz status KSeF. Pod informacjami dodatkowymi pokazuje również jeden kod weryfikacyjny KOD I z podpisem „Sprawdź w KSeF” i numerem KSeF. Link QR powstaje lokalnie z zamrożonego środowiska próby, NIP-u sprzedawcy, daty wystawienia `P_1` i hasha dokładnie wysłanego XML; przyjęcie unieważnia wcześniejszy cache PDF. Pro forma i dokumenty bez zaakceptowanej próby nie pokazują danych ani kodu KSeF.

### KSeF.8A — CLOSED

Faktura VAT i Korekta korzystają z jednego modelu prezentacyjnego KSeF w PDF. Dla zaakceptowanego dokumentu model odczytuje wyłącznie próbę z dokładnie wybranego środowiska, a numer KSeF, data przetworzenia, NIP sprzedawcy, hash XML i link KOD I zawsze pochodzą z własnego submissionu dokumentu. Korekta używa własnej daty wystawienia i nie dziedziczy danych KSeF z Faktury źródłowej. W `production` dopuszczone jest wyłącznie środowisko Production; lokalnie i w testach używane jest dokładnie środowisko zapisane w konfiguracji, bez fallbacku między TEST, DEMO i Production.

Zaakceptowany dokument jest renderowany fail-closed: niepoprawny numer KSeF, brak wymaganych danych albo niemożność utworzenia oficjalnego linku weryfikacyjnego blokują PDF zamiast generować dokument bez poprawnego KOD I. Kod QR powstaje nadal przez istniejący `KsefInvoiceVerificationLinkBuilder`, centralny `KsefNumberValidator` i TCPDF. PDF w TEST albo DEMO zawiera czytelne oznaczenie dokumentu testowego; Production nie otrzymuje takiego oznaczenia. Jeżeli kod przechodzi na osobną stronę, strona wskazuje typ i numer dokumentu oraz cel „Weryfikacja KSeF”.

Dokument bez zaakceptowanego submissionu nie pokazuje numeru KSeF ani QR. Dla serii objętej KSeF lub istniejącej próby pokazuje neutralne ostrzeżenie odpowiednie dla oczekiwania, niepewnego wyniku, odrzucenia albo błędu technicznego; nie używa oznaczenia OFFLINE. Pro forma pozostaje całkowicie poza prezentacją KSeF. Historyczny fakt przyjęcia ma pierwszeństwo przed późniejszym wyłączeniem integracji lub serii, a potwierdzenie przyjęcia unieważnia cache PDF Faktury albo Korekty. Zbiorczy PDF zachowuje osobny model i osobny KOD I każdego dokumentu bez mieszania danych.

Kontrolowany test `KSeF.8A-LIVE-DEMO-QR-TEST-001: PASS` potwierdził fizycznym skanowaniem telefonu rzeczywiste PDF-y Accepted DEMO Faktury `BLF 79/2026` i Korekty `BLKF 2/2026`. Oba KODY I otworzyły oficjalny serwis `qr-demo.ksef.mf.gov.pl` i wskazały właściwe dokumenty. `Invoice QR scan: PASS`; `Correction QR scan: PASS`; `Correction own P_1: PASS`; `Correction own submission/hash/KSeF number: PASS`; `Cross-environment fallback: NO`; `LIVE INVOICE/CORRECTION POST: 0`. Korekta korzystała z własnego `P_1 = 01.09.2026`, a nie z `P_1 = 26.08.2026` Faktury źródłowej.

Na karcie zamówienia zaakceptowana Faktura jest oznaczona jako `KSeF: <numer OMS>`. Kliknięcie pobiera autorytatywny XML Faktury z jej zamrożonego środowiska KSeF, weryfikuje hash odpowiedzi i uruchamia pobranie PDF wygenerowanego lokalnie przez oficjalny generator MF. XML źródłowy nie jest utrwalany ponownie w bazie.

Źródłem prawdy pozostaje `ksef_invoice_submissions`; Faktura nie otrzymuje osobnej kolumny statusu. Lista Faktur eager-loaduje najnowszą próbę i pokazuje wyłącznie kompaktowy badge. Deployment gate `KSEF_INVOICE_SUBMISSION_ENABLED` nadal domyślnie ma wartość `false`, workflow jest ograniczony do TEST, a historia pozostaje widoczna przy wyłączonym gate. `automatic_submission` pozostaje nieaktywną deklaracją konfiguracji: nie istnieje trigger, listener, observer, kolejka ani harmonogram automatycznej transmisji. KSeF.4B.1 nie implementuje retry, reconciliation, UPO, QR, offline, batch, Korekt, Pro form, DEMO ani PRODUCTION.

## 30.10. Audyt gotowości KSeF

Audyt gotowości KSeF jest prowadzony etapowo i obejmuje:

- kompletność danych,
- poprawność korekt,
- poprawność obliczeń,
- VAT,
- jednostki,
- waluty,
- rabaty,
- płatności,
- wysyłkę,
- niezmienność dokumentów,
- możliwość mapowania do aktualnej struktury KSeF.

Kontrolowany happy path transportu KSeF.4A został pozytywnie zweryfikowany na TEST. Przed udostępnieniem workflow użytkownika lub rolloutem produkcyjnym wymagany jest kolejny audyt aktualnego kontraktu API, danych, triggerów i granic operacyjnych.

## 30.11. Automatyczne przekazywanie nowych Faktur

Włączenie `automatic_submission` powoduje, że nowa Faktura VAT wystawiona centralnie przez `InvoiceIssuingService`, także przez istniejącą akcję Automation, otrzymuje po zatwierdzeniu transakcji trwały job na dedykowanym połączeniu `ksef_submit` i kolejce `ksef-submit`. Automatyczna wysyłka wymaga jednocześnie deployment gate, aktywnej integracji, środowiska TEST albo DEMO i dokładnie tej serii numeracji włączonej w konfiguracji KSeF. Pro formy, Korekty i PRODUCTION pozostają wyłączone.

Job przechowuje snapshot środowiska i NIP-u kontekstu. Worker przed wykonaniem HTTP ponownie sprawdza całą kwalifikację, zgodność obu wartości oraz brak próby w bieżącym środowisku. Wyłączenie gate, integracji, automatycznej wysyłki lub serii, a także zmiana środowiska albo kontekstu, anuluje oczekującą wysyłkę bez recovery i bez backfillu. Faktura pozostaje edytowalna do startu workera; dopiero wtedy aktualna wersja jest finalizowana i staje się autorytatywnym payloadem FA(3).

First-send wykonuje najwyżej jeden invoice POST i jedno natychmiastowe sprawdzenie statusu. `Accepted` od razu unieważnia cache PDF i planuje UPO na osobnym follow-upie około minutę później; ręczny oraz automatyczny first-send nie czekają na UPO. Dalsze odczyty statusu, reconciliation i UPO działają na kolejce `ksef`, z backoffem i `Retry-After`, bez automatycznej drugiej próby wysłania Faktury. Jawna akcja pobrania UPO pozostaje synchroniczna.

Automatyczny first-send ma `tries = 1` i nie posiada lokalnego limitera ani sztucznego opóźnienia. Dla oczekiwanego niskiego wolumenu około 30 Faktur na godzinę job jest gotowy natychmiast po commit, a jeden zalecany worker first-send wykonuje kolejkę sekwencyjnie: `php artisan queue:work ksef_submit --queue=ksef-submit --sleep=1 --tries=1 --timeout=120`; połączenie ma `retry_after = 240 s`. Odpowiedź MF `429` nie uruchamia automatycznego ponowienia invoice POST, a `Retry-After` pozostaje respektowane przez odczytowe follow-upy. Follow-up działa nadal na `php artisan queue:work database --queue=ksef --sleep=3 --tries=1 --timeout=60`. Nie powstał outbox ani automatyczne wyszukiwanie historycznych Faktur.

---

# 31. Etapowanie wdrożenia

## Etap 0 — audyt

- analiza lokalnego kodu,
- brak zmian w plikach,
- brak migracji,
- raport.

## Etap 1A — model serii

Zakres:

- migracja `invoice_series`,
- model `InvoiceSeries`,
- enum typu dokumentu,
- enum okresu resetowania,
- enum klucza serii systemowej,
- casty,
- relacja domyślnej serii korekt,
- trzy chronione serie systemowe,
- testy modelu i migracji.

Pola podstawowe:

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
```

Pola sprzedawcy:

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
```

Bank i wystawienie:

```text
seller_bank_name
seller_bank_account
seller_bank_swift
place_of_issue
issuer_name
logo_path
additional_information_template
```

Poza zakresem Etapu 1A:

- CRUD,
- lista serii,
- formularze,
- liczniki,
- generator numerów,
- faktury,
- PDF,
- JPK eksport,
- KSeF,
- ogólny worek `document_settings` JSON,
- GTU i procedury JPK przed właściwym etapem.

## Etap 1B — lista serii

- tabela,
- oznaczenie serii systemowych pustą, nieklikalną gwiazdką,
- aktywność,
- bezpieczne usuwanie,
- paginacja po 10 rekordów,
- początkowo nieaktywne kontrolki tworzenia i edycji, aktywowane w Etapie 1C.1.

## Etap 1C.1 — podstawowy formularz

- tworzenie i edycja serii,
- jeden wspólny modal Bootstrap,
- ładowanie formularza typu przez AJAX,
- standardowy zapis POST/PATCH,
- nazwa,
- typ,
- format,
- okres resetowania,
- rok fiskalny,
- aktywność serii własnych,
- pola techniczne serii systemowej tylko do odczytu.

Ustawienia serii Faktura wdrożono w Etapie 1C.2, ustawienia serii Korekta w Etapie 1C.3, a ustawienia serii Pro forma w Etapie 1C.4.

## Etap 1C.2 — ustawienia serii Faktura

- dane sprzedawcy przechowywane bezpośrednio w serii,
- rachunek bankowy, miejsce wystawienia i wystawiający,
- domyślna seria korekt,
- konfiguracja VAT, dostawy, płatności, dat i pozycji,
- szablon informacji z tokenem `[uwagi_sprzedawcy]`,
- podstawowa konfiguracja przyszłego wydruku,
- prywatne logo serii.

Etap nie tworzy dokumentów ani PDF. Korekta została rozbudowana w Etapie 1C.3, a Pro forma w Etapie 1C.4.

## Etap 1C.3 — ustawienia serii Korekta

- domyślny powód korekty,
- źródła daty sprzedaży, wystawiającego i sposobu płatności,
- konfiguracja pozycji i nagłówka korekty,
- szablon informacji z tokenem `[uwagi_sprzedawcy]`,
- podstawowa konfiguracja przyszłego wydruku,
- dziedziczenie danych prawnych sprzedawcy ze snapshotu dokumentu źródłowego.

Etap nie tworzy dokumentów, nie nadaje numerów i nie generuje PDF. Pro forma została rozbudowana w Etapie 1C.4.

## Etap 1C.4 — ustawienia serii Pro forma

- dane sprzedawcy przechowywane bezpośrednio w serii,
- rachunek bankowy, miejsce wystawienia i wystawiający,
- konfiguracja VAT, dostawy, płatności, dat i pozycji,
- identyfikator płatności i podstawowa konfiguracja przyszłego wydruku,
- szablon informacji z nierozwiązanym tokenem `[uwagi_sprzedawcy]`,
- prywatne logo serii,
- brak `default_correction_series_id` dla końcowego typu `proforma`.

Etap nie tworzy dokumentów, nie nadaje numerów i nie generuje PDF. Pro forma nie jest fakturą VAT, nie podlega KSeF i nie trafia do rejestru VAT ani JPK jako faktura sprzedaży.

## Etap 1D — VAT, produkty, JPK i GTU

- źródło VAT,
- stała stawka VAT,
- pomijanie pozycji zerowych,
- wewnętrzne ID produktu,
- GTU,
- procedury JPK,
- strategie łączenia oznaczeń.

## Etap 1E — wysyłka, waluty, płatności i daty

### Etap 1E.1 — lokalny katalog walut

System posiada lokalny katalog kodów walut w tabeli `currencies`. Katalog przechowuje wyłącznie kod ISO 4217, nazwę z NBP i opcjonalne oznaczenie tabeli `A` albo `B`. Nie posiada liczbowego `id`, timestampów ani flag `is_active`, `is_system` i `last_seen_at`. `PLN` jest rekordem systemowym dodawanym przez migrację, zawsze znajduje się na początku list wyboru i nie zależy od odpowiedzi sieciowej.

Polecenie `currencies:sync-nbp` ręcznie pobiera tabele A i B przez HTTPS. Obie odpowiedzi są sprawdzane przed rozpoczęciem transakcji. Synchronizacja wykonuje atomowy upsert, nie usuwa istniejących kodów i nie zmienia rekordu `PLN`. Brak automatycznego harmonogramu, kursów walut i przewalutowań.

`CurrencyCatalog` jest centralnym źródłem listy i walidacji. Kod jest normalizowany przez `trim` i `uppercase`, musi zawierać dokładnie trzy litery `A-Z` oraz istnieć lokalnie. Formularze zamówienia, pozycji i serii numeracji korzystają z tego samego katalogu.

Jedno zamówienie i wszystkie jego pozycje mają jedną walutę. Nowe, ekonomicznie puste zamówienie może technicznie rozpoczynać się w `PLN`, lecz waluta pierwszej pozycji może atomowo zastąpić tę wartość. Jest to dozwolone wyłącznie przy braku pozycji, niezerowych wartości pieniężnych, Faktury VAT, Pro formy, slotów dokumentów, przesyłek i prób utworzenia przesyłki. Zerowe kwoty nie są przewalutowywane. Po pierwszej pozycji kolejne pozycje muszą być zgodne z walutą zamówienia, a przeliczenie sumy wykrywa dane mieszane i kończy operację kontrolowanym błędem. Stan AJAX przekazuje bieżącą walutę, dlatego kwota wpłacona, suma, koszt wysyłki i formularz kolejnej pozycji używają nowego kodu bez przeładowania strony. Proces nie wykonuje połączeń HTTP ani automatycznej konwersji danych historycznych. Nieznany historyczny kod może być zachowany bez zmiany, lecz nie może zostać wybrany dla nowego lub zmienianego pola.

Kwoty w zmienionym przepływie zapisu pozycji, kosztu dostawy, sumy zamówienia i pozostałej należności są obliczane przez współdzieloną arytmetykę dziesiętną bez `float`. Sam Etap 1E.1 nie zmienił snapshotów wystawionych dokumentów, ich waluty ani PDF.

### Etap 1E.2 — historyczny kurs średni NBP Faktury VAT

Faktura VAT w walucie obcej zachowuje walutę zamówienia i otrzymuje dodatkowy, niezmienny snapshot przeliczenia grup VAT do `PLN`. Walutą docelową jest zawsze `PLN`; ustawienie `default_currency` serii nie steruje przeliczeniem. Faktura PLN nie kontaktuje się z NBP i zachowuje pusty `tax_metadata_snapshot`.

Tabela `A` lub `B` pochodzi z `currencies.nbp_table`. System pobiera historyczny średni kurs pojedynczej waluty z maksymalnie 93-dniowego zakresu i wybiera najnowszą publikację wcześniejszą od daty odniesienia. Dla Faktury wystawionej w dniu sprzedaży albo później datą odniesienia jest data sprzedaży. Dla Faktury wystawionej przed datą sprzedaży datą odniesienia jest data wystawienia. Reguła zakłada standardową sprzedaż towarów; szczególne przypadki obowiązku podatkowego pozostają poza zakresem.

Pełna tekstowa wartość `Mid` z XML NBP jest używana do obliczeń i zapisywana bez wymuszania sześciu miejsc oraz bez przejścia przez `float`. Netto i VAT każdej grupy są zaokrąglane half-up do dwóch miejsc, brutto jest ich sumą, a sumy dokumentu wynikają z gotowych grup. Nie przelicza się pozycji, wpłaconej kwoty ani pozostałej należności.

Pobranie i walidacja kursu muszą zakończyć się przed utworzeniem trwałych danych Faktury i przed numeracją. Zmiana waluty, dat albo tabeli pomiędzy pobraniem kursu a blokadą transakcyjną wymaga ponownego pobrania dla aktualnego kontekstu. Błąd nie może zużyć numeru ani pozostawić slotu, szkicu, pozycji lub zdarzenia.

Pro forma w każdej walucie pozostaje całkowicie niezależna od NBP: nie pobiera kursu, nie zapisuje wartości PLN i nie zmienia hasha z powodu kursu. Etap nie zmienia PDF. Wyświetlenie kursu i podsumowania PLN na Fakturze będzie zakresem Etapu 1E.3. Etap 1E.2 nie wdrażał lokalnej tabeli kursów, cache kursów ani walutowej Korekty; obsługa Korekty walutowej została dodana później.

### Etap 1E.3 — kurs NBP i podsumowanie PLN na PDF Faktury VAT

PDF Faktury VAT w walucie obcej pokazuje główne wartości, pozycje, kwotę „Razem” oraz kwotę słownie w walucie dokumentu. Obok źródłowych sum i grup VAT prezentuje dodatkowe sumy i odpowiadające im grupy w `PLN`, a po kwocie słownie pokazuje dokładny tekst kursu, datę publikacji i numer tabeli NBP zapisane w niezmiennym snapshocie Etapu 1E.2.

PDF nie kontaktuje się z NBP, nie odczytuje bieżącego rekordu waluty, nie wykonuje ponownego mnożenia ani zaokrąglania i nie modyfikuje dokumentu. Kurs zachowuje dokładną tekstową wartość, w tym końcowe zera, bez wymuszania sześciu miejsc; gotowe kwoty PLN muszą mieć dokładnie dwa miejsca. Pozycje, `paid_amount` i `amount_due` nie są prezentowane w PLN.

Grupy są parowane po znormalizowanym kodzie VAT albo stawce VAT, niezależnie od ich kolejności w snapshocie. Pusty snapshot historycznej Faktury walutowej nadal pozwala wygenerować poprzedni układ bez PLN i kursu. Niepusty, niekompletny albo niespójny snapshot kończy generowanie kontrolowanym błędem. Faktura PLN nie otrzymuje drugiego podsumowania, a Pro forma nie pokazuje kursu ani PLN. Prezentacja walutowa Korekty została dodana później i korzysta z historycznego kursu Faktury źródłowej.

Cache PDF jest wersjonowany per typ layoutu. Etap podnosi wyłącznie wersję Faktury VAT; stare pliki pozostają prywatnie na dysku, a cache Pro formy i Korekty nie jest niepotrzebnie unieważniany.

## Etap 1F — dane sprzedawcy i informacje dodatkowe

## Etap 1G — wygląd dokumentu

## Etap 2A — fundament dokumentów sprzedaży

Zaimplementowano jedną tabelę `invoices` dla Faktur VAT, Pro form i Korekt oraz tabelę `invoice_items` dla wszystkich pozycji dokumentów. Dodano modele, enumy statusu i typu pozycji, relacje z `Order`, `OrderItem` i `InvoiceSeries`, snapshoty dokumentu, pola wykrywania zmiany źródła Pro formy oraz techniczne relacje Korekt.

Dokument zachowuje własne dane sprzedawcy, nabywcy, odbiorcy, wystawiającego, zamówienia, płatności, dostawy, ustawień serii i podatków. Zmiana zamówienia, pozycji zamówienia lub serii nie aktualizuje tych snapshotów. Pole `additional_information_text` przechowuje finalną treść dokumentu, a nie szablon serii.

`Order` posiada relację `hasMany Invoices`; tabela `orders` nie posiada `invoice_id`. `invoice_items.product_id` jest nullable i nie posiada jeszcze klucza obcego, ponieważ moduł Produkty nie istnieje. Historyczne pozycje działają bez katalogu produktów. Dokumenty nie używają SoftDeletes.

Schemat wykorzystuje `corrected_invoice_id` do wskazania pierwotnej Faktury oraz nullable `previous_correction_id` do budowy liniowego łańcucha odrębnych Korekt. Pierwsza Korekta nie ma poprzednika, a każda kolejna wskazuje poprzednią zamkniętą Korektę. Pozycje każdej Korekty przechowują własny stan przed, po i różnicę.

Użytej serii, również posiadającej tylko szkic, nie można usunąć. Można ją ukryć i później reaktywować z tym samym `id` i przyszłym licznikiem.

Etap 2A nie implementował jeszcze pełnego wystawiania, tworzenia snapshotów z zamówienia, kalkulatora, odświeżania Pro formy, listy dokumentów, edycji, usuwania dokumentów, PDF, automatyzacji, JPK XML ani KSeF.

## Etap 2B — liczniki i silnik numeracji

Zaimplementowano niezależne, trwałe liczniki `invoice_number_counters` dla każdej pary seria–okres oraz niezmienną historię ręcznych zmian w `invoice_number_counter_adjustments`. Licznik przechowuje `last_sequence_number` i chroniony próg `protected_floor_sequence_number`. Unikalne indeksy zabezpieczają zarówno pojedynczy licznik okresu, jak i techniczną sekwencję dokumentu w serii i okresie.

Centralny resolver ustala klucz okresu: `YYYY-MM` dla resetu miesięcznego, rok rozpoczęcia okresu fiskalnego dla resetu rocznego oraz `none` dla braku resetu. Centralny formatter zachowuje składnię `%N`, `%NN...`, `%M`, `%Y` i `%y`. Podgląd numeru nie zapisuje ani nie rezerwuje danych.

Centralny walidator konfiguracji wymaga, aby format identyfikował okres resetowania: `monthly` musi zawierać `%M` oraz `%Y` lub `%y`, `yearly` od stycznia wymaga `%Y` lub `%y`, a `yearly` rozpoczynany w innym miesiącu wymaga dodatkowo `%M`. Dla `none` nie są wymagane tokeny miesiąca ani roku. Reguła chroni formularze, serwis zarządzania seriami, podgląd, ręczne ustawianie następnego numeru i właściwe nadawanie numeru.

`InvoiceNumberingService` transakcyjnie nadaje numer istniejącemu szkicowi, zapisuje jego sekwencję, okres i snapshot formatu oraz nazwy serii, ale nie wystawia dokumentu, nie tworzy pozycji i nie buduje snapshotów zamówienia. Numer nie jest wyznaczany przez niezabezpieczone `MAX + 1`; końcowym zabezpieczeniem pozostają ograniczenia unikalne, także na SQLite.

Operacja „Ustaw następny numer” działa oddzielnie dla każdego okresu, wymaga powodu i zapisuje historię poprzedniego oraz nowego stanu wraz ze snapshotem aktora. Dla okresu bez dokumentów pozwala skorygować licznik także w dół. Dla okresu z dokumentami pozwala wyłącznie bezpiecznie przesunąć numerację do przodu. Ustawiony ręcznie poziom staje się chronionym progiem. Po rozpoczęciu numeracji nie można zmienić typu dokumentu. W seriach własnych zablokowane pozostają również format, sposób resetu i początek roku fiskalnego. W seriach systemowych te trzy ustawienia można zmienić dla kolejnych dokumentów, bez modyfikowania numerów, okresów i snapshotów dokumentów już wystawionych. Nazwa i pozostałe ustawienia biznesowe serii nadal mogą być zmieniane.

Wewnętrzne luki nie są automatycznie uzupełniane. Serwis usuwania może cofnąć wyłącznie ostatnią, wolną część numeracji, nigdy poniżej `protected_floor_sequence_number`. Zmiana `issue_date` ponumerowanego dokumentu nie przenosi go automatycznie do innego okresu. Pierwsze utworzenie logicznej Pro formy zużywa jeden numer, a jej odświeżanie zachowuje ten numer. Każda odrębna Korekta zużywa własny numer serii i zachowuje go podczas edycji; finalizacja nie zwalnia numeru.

Etap 2B nie dodaje OSS ani kontroli kompletności zamówienia; brak NIP-u nie blokuje samego nadania numeru. KSeF pozostaje planowany w trybach `send` i `exclude` i nie jest częścią silnika numeracji.

## Etap 2C — centralne wystawianie Faktury VAT i bieżący stan Pro formy

Zaimplementowano centralne przygotowanie danych dokumentu z `Order`: snapshoty sprzedawcy, nabywcy, odbiorcy, zamówienia, płatności, dostawy i efektywnych ustawień serii, pozycje produktów, opcjonalną pozycję dostawy, daty, informacje dodatkowe oraz deterministyczne sumy netto, VAT i brutto. Obliczenia finansowe nie używają `float`. Brak wymaganej stawki VAT nie jest zastępowany domyślnym 23%, lecz powoduje kontrolowany błąd i rollback.

Nowe i edytowane wartości finansowe oraz ich wyniki pochodne są przed zapisem sprawdzane dokładną arytmetyką dziesiętną względem rzeczywistych kontraktów precision/scale kolumn. Procentowa stawka VAT w danych wejściowych jest liczbą całkowitą od `0` do `100`, bez whitelisty konkretnych stawek, dlatego przyszła stawka `24%` nie wymaga zmiany kodu. Wewnętrzna reprezentacja może zachować skalę 2, np. `24.00`; niepusty `vat_code` nadal ma pierwszeństwo i zeruje nieaktywny `vat_rate`.

`InvoiceIssuingService` jest centralnym wejściem dla wszystkich przyszłych ścieżek wystawiania Faktury VAT. Jedno zamówienie może mieć najwyżej jedną istniejącą Fakturę VAT. Regułę chroni zarówno kontrola domenowa, jak i unikalny slot `order_document_slots`. Dokument, pozycje, slot, numeracja i `OrderEvent` są zapisywane w jednej transakcji, więc błąd po nadaniu numeru nie zużywa numeru ani nie pozostawia częściowych danych.

`ProformaService` utrzymuje jedną logiczną Pro formę zamówienia i jeden jej bieżący stan. Pierwsze utworzenie nadaje numer. Kolejne wywołanie bez zmiany kanonicznego hasha zwraca stan bez zmian i niczego nie zapisuje. Zmiana treści nadpisuje bieżące snapshoty i pozycje tego samego dokumentu, zachowując jego numer, serię, okres, `issue_date` i `issued_at`. Sama zmiana `orders.updated_at` nie wpływa na hash.

Faktura VAT i Pro forma mogą istnieć jednocześnie. Wystawienie Faktury nie usuwa Pro formy, lecz oznacza ją jako historycznie zastąpioną i blokuje dalsze odświeżanie. Dozwolone usunięcie Faktury przez serwis domenowy odblokowuje dokładnie powiązaną Pro formę, która zachowuje numer i bieżący stan; ponowne wystawienie Faktury ponownie ją zastępuje. Zdarzenia zamówienia powstają po udanych operacjach: `invoice_issued`, `proforma_issued`, `proforma_refreshed`, `invoice_deleted` albo `proforma_restored`.

Waluta dokumentu pochodzi z zamówienia. Nowe puste zamówienie może otrzymać domyślnie `PLN`, ale brak lub nieznana waluta danych historycznych nie jest po cichu zastępowana podczas wystawiania dokumentu. Brak NIP-u, telefonu, e-maila lub części opcjonalnych danych adresowych nie blokuje utworzenia dokumentu. Etap nie dodaje kontroli kompletności zamówienia ani OSS.

Etap 2C sam nie implementował UI, kontrolerów i tras wystawiania ani PDF; elementy te dla Faktury VAT i Pro formy zostały dodane w Etapie 2D. Nadal nie ma list i szczegółów dokumentów, e-maila, usuwania dokumentów, cofania licznika, ręcznej edycji i zmiany numeru, wystawiania Korekt, automatyzacji, publicznego API, JPK XML, OSS, KSeF ani Fakturowni.

## Etap 2D — AJAX z kafelka „Zarządzanie” i prywatne PDF

Istniejące przyciski `WYSTAW FAKTURĘ` i `PRO FORMA` w kafelku „Zarządzanie” natychmiast wykonują operację przez AJAX. Nie ma modala, podglądu ani dodatkowego formularza. Tekst przycisku nie zmienia się podczas żądania, cała strona zamówienia nie jest przeładowywana i nie pojawia się komunikat sukcesu. Backend zwraca świeżo wyrenderowany fragment Blade, który zastępuje poprzedni fragment akcji; błędy pozostają widoczne wewnątrz kafelka.

Dla jednej aktywnej serii działa zwykły przycisk, a przy wielu seriach pojawia się dropdown z nazwą i formatem. Po utworzeniu Pro formy jej numer otwiera aktualny prywatny PDF w nowej karcie. W UI nie ma technicznego numeru stanu ani ręcznego odświeżania Pro formy. Po wystawieniu Faktury przycisk zostaje zastąpiony jej numerem, a akcja i numer Pro formy są całkowicie ukryte.

PDF jest generowany na żądanie przez TCPDF z `Invoice` i `InvoiceItem`, bez odczytywania aktualnego zamówienia, serii lub użytkownika. Faktura VAT i Pro forma odwzorowują przyjęte wzory A4; renderer dokumentu typu `correction` obsługuje kompletne snapshoty „Było”, „Powinno być” i różnicy. Wystawianie i edycja Korekt zostały wdrożone w Etapie 2F. Pliki są prywatne, zapisywane atomowo i zwracane inline. Każdy dokument posiada jeden bieżący plik cache zależny od wersji layoutu. Żaden wariant nie pokazuje stopki generatora ani numeru strony.

Pozycja przesyłki jest tworzona również dla kosztu `0.00`, jeśli metoda dostawy jest znana i seria uwzględnia wysyłkę. Faktura zapisuje snapshot istniejącej Pro formy w `order_snapshot.related_documents.proforma`.

Poza Etapem 2D pozostają: wystawianie i UI Korekt, edycja i usuwanie Faktury, zewnętrzne PDF, załączniki, e-mail, automatyzacje, listy dokumentów, JPK XML oraz KSeF.

## Etap 2E — edycja Faktur VAT na snapshotach

Wystawiona Faktura VAT posiada osobny ekran edycji i niezależne operacje AJAX dla nabywcy, odbiorcy, pozostałych danych oraz pozycji. Edycja zmienia wyłącznie `Invoice` i jego `InvoiceItem`; aktualne dane `Order` mogą zostać skopiowane do formularza albo zastąpić pozycje dopiero po jawnej decyzji użytkownika. Brak rzeczywistej zmiany niczego nie zapisuje.

Edytowalna jest wyłącznie wystawiona Faktura VAT z numerem, serią i zgodnym slotem dokumentu, która nie posiada Korekty. Numer, seria, waluta, typ, status, sekwencja, okres i pierwotny czas wystawienia są niezmienne. Data wystawienia może zmienić się tylko w granicach zachowujących ten sam numer i okres. Każda mutacja używa ukrytego `expected_lock_version` i odrzuca zapis ze starej karty.

Pozycje są liczone na serwerze przez istniejącą arytmetykę dziesiętną. Faktura musi zachować co najmniej jedną pozycję, a `paid_amount` nie może przekroczyć nowej sumy. Kopiowanie z zamówienia korzysta z `series_settings_snapshot`, nie z bieżącej serii, wymaga zgodnej waluty i wykonuje pełny rollback przy błędzie.

Każdy dokument sprzedaży posiada wyłącznie jeden bieżący stan. Rzeczywista edycja Faktury nadpisuje aktualne snapshoty lub pozycje i zwiększa techniczne `lock_version` dokładnie o jeden. `lock_version` chroni przed równoległym nadpisaniem, nie jest historią dokumentu i nie jest widoczny dla użytkownika. Edycja nie tworzy zdarzenia zamówienia.

Zmiana tekstowa Faktury walutowej zachowuje NBP bez HTTP. Zmiana kwot korzysta z zapisanego kursu i przebudowuje podsumowanie PLN, a zmiana daty odniesienia pobiera nowy kurs przed transakcją. Pusty historyczny snapshot pozwala tylko na zmiany niepieniężne; niepoprawny niepusty snapshot blokuje edycję. Po rzeczywistej zmianie bieżący cache PDF jest usuwany po zatwierdzeniu transakcji i generowany ponownie przy kolejnym otwarciu.

Etap nie obejmuje edycji Pro form i Korekt, usuwania dokumentów, zewnętrznych PDF, e-maila, JPK ani KSeF. System nie przechowuje poprzednich stanów ani poprzednich PDF-ów; `InvoiceRevision`, tabela `invoice_revisions` i kolumna `revision_number` nie są używane. Standardowe `updated_at` oznacza jedynie ostatnią aktualizację rekordu, a Pro forma nadal używa `source_snapshot_hash` do wykrywania zmiany zamówienia.

## Etap 2F — wystawianie Korekt

Wystawiona Faktura VAT może otrzymać wiele odrębnych Korekt w liniowym łańcuchu. Wszystkie odnoszą się przez `corrected_invoice_id` do pierwotnej Faktury, a każda kolejna wskazuje poprzednią zamkniętą Korektę przez `previous_correction_id`. Dokument źródłowy nie jest nadpisywany. Dopóki istnieje bieżąca, niezfinalizowana Korekta, kolejna próba tworzenia otwiera jej edycję; po finalizacji można wystawić następny osobny dokument na podstawie skutecznego stanu `AFTER` poprzednika.

Korekta otrzymuje własną aktywną serię, numer i daty oraz jest od razu wystawiana w jednej transakcji. Przy jednej dostępnej serii przycisk `Korekta` prowadzi bezpośrednio do formularza, a przy wielu seriach najpierw pokazuje wybór serii. Formularz obsługuje zamkniętą listę powodów oraz opcjonalną zmianę pozycji i danych Nabywcy. Aktualne pozycje i dane z zamówienia są kopiowane wyłącznie po jawnej decyzji użytkownika.

Formularz wystawienia Korekty zapisuje identyfikator i oczekiwaną `lock_version` skutecznego dokumentu źródłowego. Dla pierwszej Korekty jest nim pierwotna Faktura, a dla kolejnej ostatnia zamknięta Korekta. Zmiana skutecznego źródła po otwarciu formularza powoduje kontrolowany konflikt; użytkownik musi odświeżyć formularz i ponownie sprawdzić dane.

Pozycje Korekty zapisują kompletne snapshoty stanu przed zmianą, po zmianie i różnicy. Obliczenia netto, VAT i brutto korzystają z centralnej arytmetyki dziesiętnej, a brak rzeczywistej zmiany kończy operację kontrolowanym błędem bez zużycia numeru. Tożsamość podatku jest normalizowana centralnie: niepusty `vat_code` jest zapisywany wielkimi literami i ma pierwszeństwo przed `vat_rate`, natomiast stawka bez kodu jest normalizowana do dwóch miejsc po przecinku. Korekta zachowuje osobne grupy różnic dla przejść stawka-kod oraz kod-kod nawet wtedy, gdy łączne netto, VAT i brutto wynoszą zero. Każda udana operacja zapisuje zdarzenie `correction_issued` w historii zamówienia.

PDF Korekty jest generowany z zapisanych snapshotów przez istniejący prywatny renderer. Rzeczywista zmiana danych Nabywcy jest prezentowana w układzie „Było / Powinno być”, bez odczytu bieżących danych zamówienia. Bieżąca, niezfinalizowana Korekta może być edytowana przez nadpisanie jej bieżącego stanu bez zmiany numeru oraz usunięta przy użyciu wspólnego, transakcyjnego mechanizmu usuwania dokumentów. Zapis identycznego stanu kanonicznego jest operacją no-op: nie zwiększa `lock_version`, nie przebudowuje pozycji i nie unieważnia cache PDF. Starsza Korekta w niekanonicznym formacie może zostać jednokrotnie znormalizowana przy pierwszej poprawnej aktualizacji; następny identyczny zapis jest już no-op. Usunięcie obejmuje pozycje, slot, prywatny cache PDF, zdarzenie zamówienia i ewentualne cofnięcie wolnego końca licznika. Zwykła Korekta walutowa korzysta z historycznego kursu Faktury źródłowej, zapisuje różnice w walucie dokumentu i PLN oraz nie wykonuje nowego żądania do NBP. Kwoty dokumentów prezentowane w interfejsie modułu Invoices są formatowane jako dokładne wartości dziesiętne bez konwersji do `float`. Automatyzacje, JPK, KSeF i szczególne korekty rabatów zbiorczych pozostają poza zakresem.

Zakładka `Korekty` udostępnia listę wystawionych Korekt z filtrowaniem, sortowaniem, paginacją, podglądem PDF, przejściem do edycji oraz usuwaniem pojedynczym i zbiorczym. Zaznaczone Korekty można wydrukować w jednym zbiorczym PDF. Korekta jest dokumentem księgowym i zostanie ujęta razem z Fakturami VAT w rejestrze sprzedaży wdrażanym w Etapie 3A; obecny przycisk rejestru jest wyłącznie nieaktywną zapowiedzią tej funkcji.

## Dalsze etapy

- Etap 2G — dokumenty zewnętrzne PDF,
- Etap 2H — wysyłka dokumentów e-mailem,
- Etap 3A — rejestr sprzedaży,
- Etap 3B — JPK,
- Etap 3C — audyt KSeF,
- Etap 3D — integracja KSeF.

---

# 32. Kryteria akceptacji funkcji

Funkcja jest gotowa dopiero, gdy:

- odpowiada zatwierdzonemu zakresowi,
- nie usuwa istniejącego działania,
- posiada walidację po stronie serwera,
- posiada adekwatne testy,
- działa na istniejącej bazie bez utraty danych,
- została sprawdzona ręcznie,
- nie rozszerza nieuzgodnionego zakresu,
- dokumenty historyczne pozostają niezmienne,
- błędy są czytelnie raportowane.

---

# 33. Otwarte decyzje

Do rozstrzygnięcia w odpowiednich etapach:

- dokładny zestaw pól poza Etapem 1A,
- dokładna reguła zaokrągleń VAT,
- obsługa częściowych płatności,
- szczegółowe zasady anulowania dokumentów,
- zakres ręcznej edycji GTU i procedur,
- wzory wydruków,
- uprawnienia użytkowników,
- polityka retencji plików,
- dokładny moment blokowania edycji dokumentu,
- dokładna walidacja NIP i danych sprzedawcy.

Nie należy rozstrzygać tych kwestii przypadkowo podczas innego etapu.
