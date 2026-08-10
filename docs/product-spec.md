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

W przyszłości planowane jest pobieranie danych firmy po NIP z oficjalnego źródła GUS/REGON.

Nie jest to część pierwszego etapu modułu faktur.

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

Korekta jest osobnym dokumentem powiązanym z fakturą źródłową. Obowiązkowo pokazuje wartości przed zmianą, po zmianie i różnicę. Jedno zamówienie może mieć najwyżej jedną istniejącą Korektę; jej późniejsza edycja nadpisuje bieżące snapshoty i pozycje tego samego dokumentu, zachowując numer oraz tożsamość Korekty. Data sprzedaży może pochodzić z faktury źródłowej albo z daty wystawienia korekty; wystawiający może pochodzić z faktury źródłowej albo z serii; sposób płatności może pochodzić z faktury źródłowej, zostać ukryty albo przyjąć stałą wartość serii.

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

Jedno zamówienie może mieć wiele dokumentów różnych typów, ale najwyżej jedną istniejącą Fakturę VAT, jedną logiczną Pro formę z jednym bieżącym stanem i jedną Korektę. Ponowne wygenerowanie PDF nie tworzy nowego dokumentu.

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

Jedno zamówienie może mieć najwyżej jedną istniejącą Korektę. Korekta wskazuje pierwotną Fakturę przez `corrected_invoice_id`, a `previous_correction_id` pozostaje `null`. Faktura posiadająca Korektę nie jest zwyczajnie edytowalna. Jeden slot typu `correction` wskazuje tę Korektę i chroni regułę również przy równoległych operacjach. Ponowne wejście w tworzenie przekierowuje do edycji istniejącej Korekty. Edycja nadpisuje jej bieżące snapshoty i pozycje, zachowując `id`, numer, serię oraz okres numeracji. Usunięcie Korekty usuwa również jej slot.

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

## 30.2. Czego nie wdrażamy teraz

Bez osobnego etapu nie implementujemy:

- API KSeF,
- XML FA(3),
- wysyłki dokumentów,
- numeru KSeF,
- statusów KSeF,
- UPO,
- certyfikatów,
- tokenów,
- trybów offline,
- kodów QR KSeF,
- tabeli `ksef_submissions`,
- pól `ksef_*`.

## 30.3. Audyt gotowości KSeF

Po zakończeniu modułu faktur zostanie wykonany osobny audyt obejmujący:

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

Dopiero po pozytywnym audycie rozpocznie się właściwa integracja.

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

Schemat zachowuje pola `corrected_invoice_id` i nullable `previous_correction_id`, lecz aktualna reguła domenowa dopuszcza tylko jedną Korektę na zamówienie. Korekta wskazuje pierwotną Fakturę, a `previous_correction_id` pozostaje `null`. Pozycje Korekty przechowują stan przed, po i różnicę.

Użytej serii, również posiadającej tylko szkic, nie można usunąć. Można ją ukryć i później reaktywować z tym samym `id` i przyszłym licznikiem.

Etap 2A nie implementował jeszcze pełnego wystawiania, tworzenia snapshotów z zamówienia, kalkulatora, odświeżania Pro formy, listy dokumentów, edycji, usuwania dokumentów, PDF, automatyzacji, JPK XML ani KSeF.

## Etap 2B — liczniki i silnik numeracji

Zaimplementowano niezależne, trwałe liczniki `invoice_number_counters` dla każdej pary seria–okres oraz niezmienną historię ręcznych zmian w `invoice_number_counter_adjustments`. Licznik przechowuje `last_sequence_number` i chroniony próg `protected_floor_sequence_number`. Unikalne indeksy zabezpieczają zarówno pojedynczy licznik okresu, jak i techniczną sekwencję dokumentu w serii i okresie.

Centralny resolver ustala klucz okresu: `YYYY-MM` dla resetu miesięcznego, rok rozpoczęcia okresu fiskalnego dla resetu rocznego oraz `none` dla braku resetu. Centralny formatter zachowuje składnię `%N`, `%NN...`, `%M`, `%Y` i `%y`. Podgląd numeru nie zapisuje ani nie rezerwuje danych.

Centralny walidator konfiguracji wymaga, aby format identyfikował okres resetowania: `monthly` musi zawierać `%M` oraz `%Y` lub `%y`, `yearly` od stycznia wymaga `%Y` lub `%y`, a `yearly` rozpoczynany w innym miesiącu wymaga dodatkowo `%M`. Dla `none` nie są wymagane tokeny miesiąca ani roku. Reguła chroni formularze, serwis zarządzania seriami, podgląd, ręczne ustawianie następnego numeru i właściwe nadawanie numeru.

`InvoiceNumberingService` transakcyjnie nadaje numer istniejącemu szkicowi, zapisuje jego sekwencję, okres i snapshot formatu oraz nazwy serii, ale nie wystawia dokumentu, nie tworzy pozycji i nie buduje snapshotów zamówienia. Numer nie jest wyznaczany przez niezabezpieczone `MAX + 1`; końcowym zabezpieczeniem pozostają ograniczenia unikalne, także na SQLite.

Operacja „Ustaw następny numer” działa oddzielnie dla każdego okresu, wymaga powodu i zapisuje historię poprzedniego oraz nowego stanu wraz ze snapshotem aktora. Dla okresu bez dokumentów pozwala skorygować licznik także w dół. Dla okresu z dokumentami pozwala wyłącznie bezpiecznie przesunąć numerację do przodu. Ustawiony ręcznie poziom staje się chronionym progiem. Po rozpoczęciu numeracji nie można zmienić typu dokumentu. W seriach własnych zablokowane pozostają również format, sposób resetu i początek roku fiskalnego. W seriach systemowych te trzy ustawienia można zmienić dla kolejnych dokumentów, bez modyfikowania numerów, okresów i snapshotów dokumentów już wystawionych. Nazwa i pozostałe ustawienia biznesowe serii nadal mogą być zmieniane.

Wewnętrzne luki nie są automatycznie uzupełniane. Serwis usuwania może cofnąć wyłącznie ostatnią, wolną część numeracji, nigdy poniżej `protected_floor_sequence_number`. Zmiana `issue_date` ponumerowanego dokumentu nie przenosi go automatycznie do innego okresu. Pierwsze utworzenie logicznej Pro formy zużywa jeden numer, jej odświeżanie zachowuje ten numer, a jedyna Korekta zamówienia zużywa jeden numer własnej serii i zachowuje go podczas edycji.

Etap 2B nie dodaje OSS ani kontroli kompletności zamówienia; brak NIP-u nie blokuje samego nadania numeru. KSeF pozostaje planowany w trybach `send` i `exclude` i nie jest częścią silnika numeracji.

## Etap 2C — centralne wystawianie Faktury VAT i bieżący stan Pro formy

Zaimplementowano centralne przygotowanie danych dokumentu z `Order`: snapshoty sprzedawcy, nabywcy, odbiorcy, zamówienia, płatności, dostawy i efektywnych ustawień serii, pozycje produktów, opcjonalną pozycję dostawy, daty, informacje dodatkowe oraz deterministyczne sumy netto, VAT i brutto. Obliczenia finansowe nie używają `float`. Brak wymaganej stawki VAT nie jest zastępowany domyślnym 23%, lecz powoduje kontrolowany błąd i rollback.

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

Wystawiona Faktura VAT może otrzymać jedną Korektę. Korekta odnosi się do Faktury źródłowej, a dokument źródłowy nie jest nadpisywany. Kolejna próba tworzenia otwiera edycję istniejącej Korekty zamiast tworzyć następny rekord.

Korekta otrzymuje własną aktywną serię, numer i daty oraz jest od razu wystawiana w jednej transakcji. Przy jednej dostępnej serii przycisk `Korekta` prowadzi bezpośrednio do formularza, a przy wielu seriach najpierw pokazuje wybór serii. Formularz obsługuje zamkniętą listę powodów oraz opcjonalną zmianę pozycji i danych Nabywcy. Aktualne pozycje i dane z zamówienia są kopiowane wyłącznie po jawnej decyzji użytkownika.

Formularz pierwszego wystawienia Korekty zapisuje oczekiwaną `lock_version` Faktury źródłowej. Rzeczywista zmiana Faktury po otwarciu formularza powoduje kontrolowany konflikt; użytkownik musi odświeżyć formularz i ponownie sprawdzić aktualne dane źródłowe.

Pozycje Korekty zapisują kompletne snapshoty stanu przed zmianą, po zmianie i różnicy. Obliczenia netto, VAT i brutto korzystają z centralnej arytmetyki dziesiętnej, a brak rzeczywistej zmiany kończy operację kontrolowanym błędem bez zużycia numeru. Każda udana operacja zapisuje zdarzenie `correction_issued` w historii zamówienia.

PDF Korekty jest generowany z zapisanych snapshotów przez istniejący prywatny renderer. Wystawiona Korekta może być edytowana przez nadpisanie jej bieżącego stanu bez zmiany numeru oraz usunięta przy użyciu wspólnego, transakcyjnego mechanizmu usuwania dokumentów. Usunięcie obejmuje pozycje, slot, prywatny cache PDF, zdarzenie zamówienia i ewentualne cofnięcie wolnego końca licznika. Zwykła Korekta walutowa korzysta z historycznego kursu Faktury źródłowej, zapisuje różnice w walucie dokumentu i PLN oraz nie wykonuje nowego żądania do NBP. Automatyzacje, JPK, KSeF i szczególne korekty rabatów zbiorczych pozostają poza zakresem.

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
