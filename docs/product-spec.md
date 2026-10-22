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

# 8. Moduł Produkty

Od etapu PRODUCTS.1A w głównym menu działa moduł:

```text
Produkty
```

Zakres PRODUCTS.1A:

- lokalny katalog produktów z własnym ID NEX,
- nazwa oraz opcjonalny, unikalny EAN,
- cena brutto i waluta,
- stawki VAT 23%, 8%, 5%, 0% oraz dowolna własna stawka od 0% do 100%,
- informacyjny stan magazynowy dopuszczający wartości ujemne,
- opcjonalna waga opakowania oraz komplet wymiarów długość × szerokość × wysokość,
- jedno prywatne zdjęcie z generowaną miniaturą,
- lista z wyszukiwaniem, filtrami aktywności i wyborem 25, 50, 100, 200, 500 lub wszystkich produktów na stronę,
- menu operacji produktu wyświetlane ponad przewijaną tabelą, bez przycinania przy jej krawędzi,
- edycja stanu magazynowego bezpośrednio na liście, zapisywana asynchronicznie bez przeładowania strony,
- osobne formularze tworzenia i edycji,
- ukrywanie i ponowne aktywowanie bez twardego usuwania produktu.

Zakres PRODUCTS.1B:

- przycisk dodawania produktów na karcie zamówienia rozdziela wybór „Dodaj produkty z magazynu...” i istniejące „Dodaj produkt ręcznie...”,
- modal magazynu pokazuje wyłącznie aktywne produkty, wyszukuje po nazwie, EAN lub wewnętrznym ID i stronicuje wyniki,
- operator może wybrać wiele produktów, a przed zapisem niezależnie zmienić ilość oraz jednostkową cenę brutto każdej pozycji,
- wspólna lista walut obok sumy domyślnie wskazuje walutę zamówienia i przypisuje ją wszystkim dodawanym pozycjom; system nie wykonuje automatycznego przewalutowania kwot,
- puste finansowo zamówienie może przyjąć wybraną walutę pierwszych pozycji, natomiast zamówienie z istniejącymi pozycjami lub wartościami finansowymi wymaga zachowania swojej waluty,
- cena wpisana w zamówieniu nie zmienia ceny katalogowej,
- przekroczenie informacyjnego stanu magazynowego pokazuje ostrzeżenie, ale nie blokuje zapisu,
- cały wybór jest zapisywany w jednej transakcji i przeliczany bez użycia `float`,
- pozycja zamówienia zapisuje nullable `product_id` oraz snapshot nazwy, EAN, ceny, VAT, waluty i wagi z chwili dodania,
- ręcznie dodana pozycja nadal ma `product_id = null`,
- wystawiany dokument kopiuje `product_id` i wewnętrzne ID do własnego snapshotu pozycji,
- usunięcie produktu ustawia `order_items.product_id = null`, ale nie zmienia danych historycznej pozycji zamówienia,
- `invoice_items.product_id` pozostaje nullable i bez klucza obcego, aby usunięcie produktu nie zmieniało dokumentu historycznego.

PRODUCTS.1B nie wprowadza ruchów ani rezerwacji magazynowych, automatycznej zmiany stanu, integracji kanałów sprzedaży, zestawów, promocji, importu ani masowych operacji.

Automatyczne pomniejszanie magazynu po PRODUCTS.1B:

- silnik automatyzacji udostępnia zdarzenie „Wystawiono Fakturę VAT” oraz akcję „Zmniejsz stan magazynowy”,
- akcję można zapisać wyłącznie dla tego zdarzenia; samo wdrożenie nie tworzy ani nie aktywuje reguły za użytkownika,
- zdarzenie powstaje po skutecznym zatwierdzeniu transakcji wystawienia Faktury VAT, niezależnie od tego, czy dokument wystawiono ręcznie, czy automatycznie,
- stan jest zmniejszany wyłącznie dla pozycji typu produkt, które posiadają jednocześnie `product_id` oraz `order_item_id`,
- pozycje ręczne, dostawa, Pro formy, Korekty i inne dokumenty nie pomniejszają magazynu,
- ilość magazynowa pozostaje liczbą całkowitą; ułamkowa lub niedodatnia ilość pozycji zatrzymuje akcję bez częściowego zapisu,
- każda pozycja zamówienia może zostać odjęta tylko raz, także przy ponowieniu kolejki, wielu zgodnych regułach albo ponownym wystawieniu Faktury po jej usunięciu,
- ruch zapisuje stan przed zmianą, zmianę, stan po zmianie oraz snapshot produktu i powiązania operacyjne,
- stan nadal może zejść poniżej zera,
- usunięcie Faktury, anulowanie zamówienia ani Korekta nie przywracają stanu automatycznie; wymagają świadomej zmiany ręcznej lub przyszłej, odrębnej akcji magazynowej.

Zestawy katalogowe:

- lista katalogu posiada osobne zakładki „Produkty” i „Zestawy”,
- produkt i zestaw korzystają ze wspólnej puli wewnętrznych ID,
- zestaw ma własną nazwę, cenę, VAT, walutę, opcjonalny EAN i zdjęcie,
- zestaw składa się z co najmniej dwóch różnych, istniejących produktów wraz z ilością każdego składnika,
- zestaw nie może zawierać innego zestawu ani samego siebie,
- zestaw nie posiada własnego edytowalnego stanu; jego dostępność jest najmniejszą całkowitą liczbą zestawów możliwych do złożenia ze stanów wszystkich składników,
- ukryty albo niedostępny składnik powoduje dostępność równą zero,
- produkt i zestaw mogą posiadać wiele powiązań z ofertami w ramach jednego konta PrestaShop,
- jedna oferta PrestaShop może wskazywać tylko jeden produkt albo zestaw OMS,
- lista katalogu grupuje powiązania w jeden kafelek integracji z liczbą przypisanych ofert,
- import lub ręczne dodanie zestawu tworzy jedną pozycję zamówienia z nazwą i ceną zestawu oraz zapisuje historyczny snapshot składu,
- późniejsza zmiana składu zestawu nie zmienia już istniejących pozycji zamówień,
- akcja „Zmniejsz stan magazynowy” odejmuje od każdego składnika iloczyn ilości zestawów i ilości składnika w snapshotcie,
- ruchy magazynowe są zapisywane osobno dla składników i pozostają idempotentne dla pary pozycja zamówienia-składnik.

Docelowe założenia:

- każdy produkt posiada wewnętrzne ID,
- pozycja zamówienia może opcjonalnie wskazywać produkt,
- pozycja faktury może opcjonalnie wskazywać produkt,
- produkt może mieć domyślną stawkę VAT,
- produkt może mieć domyślne oznaczenia GTU,
- zmiana lub usunięcie produktu nie zmienia dokumentów historycznych.

Relacje po PRODUCTS.1B:

```text
order_items.product_id nullable
invoice_items.product_id nullable
product_bundle_components.bundle_id
product_bundle_components.component_product_id
order_item_bundle_components.order_item_id
```

`order_items.product_id` posiada klucz obcy do katalogu z `nullOnDelete`. `invoice_items.product_id` nie posiada klucza obcego i zachowuje identyfikator jako część historycznego snapshotu dokumentu.

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

Pole `Numer zamówienia` na dokumencie oznacza wewnętrzne ID zamówienia OMS zapisane w snapshocie, a nie numer zamówienia w zewnętrznym sklepie. Pole `Numer płatności` zawiera zapisany w chwili wystawienia identyfikator transakcji operatora płatności; numer zamówienia sklepu nie może być używany jako jego zastępstwo. Gdy dokument obejmuje kilka zaksięgowanych transakcji, ich unikalne identyfikatory są zachowywane razem w snapshocie.

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

## RS.1A.2 — dane i podsumowania (backend)

Wdrożono wspólny mechanizm danych rejestru, niezależny od HTTP i formatu wydruku. Formularz i eksport HTML dodano w RS.1B opisanym poniżej. Nie utworzono tabel raportowych ani nie zmieniono dokumentów historycznych.

- Rejestr łączy wskazane serie Faktur i Korekt jednego właściciela, bez wyboru i grupowania sprzedawcy. Serie ukryte są dozwolone.
- Kwalifikują się wyłącznie `invoice` i `correction` ze statusem `issued`. Finalizacja, płatność, `issued_at`, istnienie zamówienia i wysyłka do KSeF nie są warunkami kwalifikacji.
- Wybór okresowy wymaga miesiąca albo kompletnego zakresu dat wystawienia i niepustej listy serii. Ręczny zakres zastępuje miesiąc; granice są włączne. Daty sprzedaży, obecność identyfikatora podatkowego (również zagranicznego), waluta i kraj są dodatkowymi warunkami AND, serie łączone są przez OR.
- Wybór jawnych ID jest niezależny od filtrów i paginacji listy. Pusty lub błędny wybór jest odrzucany; brakujące, robocze i niekwalifikujące się dokumenty powodują kontrolowany błąd z listą ID.
- Jeden dokument daje jeden rekord i jedno Lp. Kolejność: data wystawienia, numer porównany tekstowo, ID. Kilka grup VAT daje jedną wartość, np. `23%, 8%, 0%, ZW`; stawki malejąco, następnie kody alfabetycznie. To reguła NEX-OMS. Wewnętrzne grupy nie mnożą wartości dokumentu.
- Nabywca pochodzi ze snapshotów dokumentu, wspólnych dla filtrów, prezentacji i kraju. Nazwa firmy ma pierwszeństwo przed imieniem/nazwiskiem zgodnie z zapisem dokumentu. Brak klucza nazwy/NIP pozwala użyć osobnego historycznego pola z ostrzeżeniem. Jawnie pusta wartość nie uruchamia fallbacku; konflikt z niepustym polem osobnym daje nieznaną wartość i ostrzeżenie. Nieznany identyfikator nie przechodzi filtra „z” ani „bez”. Brak kraju oznacza „Nieustalony kraj”, nie PL.
- Kwoty dokumentu to zapisane `total_*`, grupy to `tax_summary_snapshot`. Korekta wnosi podpisaną różnicę, nie stan AFTER; sprzeczność z zapisaną różnicą jest raportowana. Brak dodatkowego snapshotu Korekty jest widoczny w ostrzeżeniach, bez odtwarzania danych ze źródła. Dokumenty powiązane są informacyjne i nie zwiększają zakresu ani sum.
- Waluty oryginalne i kombinacje kraj/waluta mają osobne sumy. Nie stosuje się katalogu bieżących walut ani fallbacku PLN. Kwoty to stringi dziesiętne ze skalą 2; brak i uszkodzenie nie są zerem. Niezgodne grupy VAT nie unieważniają samych poprawnych sum dokumentu.
- Kwoty PLN walut obcych pochodzą wyłącznie z poprawnego zapisanego przeliczenia. Zachowana jest pełna precyzja historycznego kursu za jedną jednostkę. Korekta rzeczywiście bez skutków finansowych i bez zmian kwot grup VAT może nie mieć kursu; samo zerowe brutto nie wystarcza.
- „W tym wysyłka” jest częścią sumy. Dla Faktury odczytywane są pozycje `shipping`, dla Korekty różnica ich stanów AFTER minus BEFORE, również przy zmianie typu i grupy VAT. Pomocnicze PLN wysyłki jest jawnie oznaczone jako obliczone z utrwalonych pozycji i własnego kursu dokumentu: per dokument i grupa, netto/VAT oddzielnie `half_up` do 2 miejsc, brutto jako ich suma. Cały dokument nie jest ponownie przeliczany.
- Numer KSeF jest opcjonalnym lokalnym odczytem wyłącznie zaakceptowanego powiązania `production`, niezależnym od konfiguracji integracji. Brak PROD nie wyklucza dokumentu. Powtórzenia tego samego numeru są dozwolone; różne numery lub niespójne powiązania dają brak numeru i ostrzeżenie. Numer Faktury nie jest kopiowany do Korekty.
- Każda część sum ma informację o kompletności, liczbie i ID uwzględnionych/nieuwzględnionych dokumentów. Poprawne części są sumowane, ale częściowa suma nie jest przedstawiana jako kompletna. Puste zestawienie daje zera; niepuste zestawienie bez znanych kwot daje `null`. Ostrzeżenia wskazują dokument, sekcję i stabilny kod.

Mechanizm nie pobiera kursów NBP, nie wykonuje transportu KSeF, nie generuje PDF ani nie ustala okresu rozliczenia VAT. Wybór dotyczy dat dokumentów, nie kwalifikacji podatkowej do JPK.

## RS.1B — formularz i rejestr HTML

Na listach Faktur i Korekt przycisk `REJESTR SPRZEDAŻY` otwiera formularz według okresu i filtrów albo dla zaznaczonych dokumentów. Pro forma nie udostępnia tej funkcji. Zaznaczenie jest przekazywane jako jedna pełna lista JSON przez POST, niezależnie od filtrów i paginacji listy; puste, nieprawidłowe albo niekwalifikujące się ID dają błąd, nigdy cały rejestr.

Formularz domyślnie wybiera bieżący miesiąc i rok oraz wszystkie widoczne serie Faktur/Korekt. Uwzględnia także ukryte serie mające dokumenty historyczne. Obie puste daty wystawienia oznaczają miesiąc; obie wypełnione zastępują go własnym zakresem; jedna jest błędem. Daty sprzedaży ograniczają zakres niezależnie. Filtry NIP, historycznej waluty i kraju nabywcy działają zgodnie z RS.1A.2. Zmiana miesiąca lub roku czyści własny zakres wystawienia. Powrót zachowuje bezpieczny kontekst listy.

`Generuj` dla HTML otwiera w nowej karcie samodzielny raport z jedną pozycją na dokument, historycznym nabywcą, datami, kwotami i informacyjnymi powiązaniami. RS.1B wprowadził HTML; RS.1C-XLSX-001 dodaje opisany poniżej XLSX. Opcje HTML: nagłówek (domyślnie Tak), tabela historycznych kursów (Nie), numer KSeF dokumentu (Tak). Wyłączenie KSeF usuwa całą kolumnę i pomija odczyt tabel KSeF; nie wybiera się środowiska. Tabela kursów nie steruje podsumowaniami PLN.

Raport pokazuje oddzielne waluty, grupy VAT, wysyłkę jako podzbiór, waluty obce w PLN, łączne PLN oraz kraj/walutę. Nie miesza walut, nie pobiera nowych kursów i nie przelicza ponownie dokumentów. Nieznana kwota jest kreską, nie zerem; nieustalony kraj/waluta pozostają jawne. Częściowe sumy pokazują pokrycie, pominięte dokumenty i przyczyny. Ostrzeżenia pozostają widoczne bez nagłówka, po zapisaniu HTML i na wydruku. Raport ma lokalne style, nie wymaga skryptów ani zasobów zewnętrznych, a nagłówki tabeli powtarzają się przy drukowaniu.

RS.1B nie zapisuje raportów ani preferencji w bazie, nie zmienia dokumentów i nie uruchamia integracji. Eksport jest prywatną odpowiedzią bez cache. Dostęp pozostaje taki jak do istniejących list faktur: obecna aplikacja nie ma na tych trasach logowania ani polityk użytkowników; przed udostępnieniem poza zaufanym środowiskiem potrzebna jest osobna warstwa kontroli dostępu. Inne formaty, JPK, OSS i eksport PDF pozostają poza etapem.

## RS.1C-XLSX-001 — natywny rejestr XLSX

Formularz udostępnia HTML (domyślnie) i XLSX. XLSX pobiera plik bez nowej pustej karty, dla tego samego zakresu dat/filtrów albo jawnych ID. Nie ma XLS ani konwersji HTML na arkusz. Opcje nagłówka i tabeli kursów są ukryte dla XLSX, zachowują wartości po przełączeniu na HTML i nie wpływają na arkusz nawet w spreparowanym żądaniu.

Arkusz `Rejestr sprzedaży` zawiera jeden wiersz na dokument, bez tytułu, podsumowań i dodatkowej tabeli kursów. Kolejność 21 kolumn: Lp., Typ, Pełny numer, Nabywca, Adres, Kod pocztowy, Miasto, NIP, Data wystawienia, Data sprzedaży, Netto, VAT, Kwota VAT, Brutto, Sposób płatności, Waluta, Kurs waluty, Kraj, Dokument powiązany, Numer KSeF, Data autoryzacji w KSeF. Wyłączenie KSeF usuwa obie ostatnie kolumny i wszystkie odczyty tabel KSeF. Pusty wynik ma same nagłówki, bez fikcyjnego dokumentu.

Kwoty i pojedynczy numeryczny VAT są liczbami z formatem `0.00`, bez ponownego obliczania dokumentów. Kilka stawek/kodów daje tekst, np. `23,00; 8,00; ZW`. Identyfikatory, daty i kursy pozostają tekstem; zera wiodące i teksty przypominające formuły nie są interpretowane. I/J mają format `YYYY-MM-DD`, data KSeF `DD.MM.YYYY`. Kurs PLN to `1,000000`, a waluty obcej pochodzi wyłącznie z poprawnego własnego snapshotu i zachowuje pełną precyzję. Brak kwoty, daty albo kursu daje pustą komórkę, nie zero ani wartość bieżącą. Kwoty poza bezpieczną precyzją są zachowane dokładnie jako tekst z ostrzeżeniem.

Metoda płatności pochodzi z `payment_snapshot.effective_payment_method`; jawnie pusta metoda lub wybór niepokazywania pozostają puste, bez zastępowania płatnością zamówienia. Data KSeF pochodzi z `acquisition_date` tego samego poprawnego zaakceptowanego powiązania PROD co numer, po przeliczeniu UTC na strefę aplikacji. Korekta używa własnego powiązania. Sprzeczne daty powtórzeń tego samego numeru oznaczają brak daty i ostrzeżenie, bez usuwania prawidłowego numeru.

Tylko raport z ostrzeżeniami otrzymuje dodatkowy arkusz `Uwagi` z dokumentem, częścią raportu i polskim opisem; obejmuje także dokumenty pominięte przez filtr z nieustalonym NIP-em. Brak numeru KSeF dokumentu niewysłanego nie jest błędem finansowym. Arkusz nie zawiera formuł, makr, aktywnych linków ani zewnętrznych odwołań. Przekroczenie limitów formatu daje czytelny błąd przed pobraniem, nie ucięty plik. Eksport nie zapisuje dokumentów, nie uruchamia integracji, NBP ani KSeF.

---

## RS.1D-XML-001 — eksport XML rejestru

Formularz rejestru udostępnia HTML (domyślnie), XLSX i XML dla tych samych filtrów albo zaznaczonych dokumentów. XML jest pobierany bez nowej karty. Nagłówek/tabela kursów dotyczą tylko HTML; opcja KSeF w XML steruje elementem `ksef_id` i odczytem tabel KSeF. Nie eksportuje się daty autoryzacji.

Struktura odpowiada dostarczonemu przykładowi Base: `invoices/invoice`, dane stron, `items`, dla Korekty dodatkowo numer Faktury źródłowej i `original_items`, następnie `order_items`, płatność, waluta/kurs, kraj odbiorcy i końcowe kwoty. Jeden dokument daje jeden invoice, bez zbiorczych sum. Pusty wynik nie tworzy fikcyjnego dokumentu. To nie jest XML KSeF/FA(3), JPK ani format konkretnego programu księgowego; nie deklaruje się nieprzetestowanej zgodności importu.

Korekta eksportuje własny AFTER w `items`, BEFORE w `original_items` i podpisaną różnicę w sumach, także przy kolejnych Korektach. Zachowane są stany zerowe, pozycje o takich samych nazwach oraz faktycznie zapisana dostawa (również bezpłatna). Brak danych nie jest odtwarzany z bieżącego zamówienia ani PDF. Braki wymaganych kwot/dat/numeru, uszkodzone stany i nieobsługiwane warianty podatku blokują cały wybór czytelnym błędem przed pobraniem.

Obsługiwane są dokumenty o jednej jednoznacznej stawce procentowej. Wiele stawek i kody typu ZW wymagają dodatkowego potwierdzonego kontraktu/przykładu; XML nie zgaduje ich mapowania. HTML/XLSX zachowują dotychczasową obsługę tych dokumentów. Kwoty mają skalę 2, ceny jednostkowe 4, ilości zachowują ułamki, nadmiar rzeczywistej precyzji nie jest obcinany. Dla PLN `currency_calc_rate=0.000000` jest wyłącznie konwencją pliku; kurs waluty obcej zachowuje pełną historyczną precyzję, a brak pozostaje pusty.

Jawne mapowania/różnice NEX-OMS: ID zamówienia to historyczne ID OMS; identyfikator sklepu pochodzi wyłącznie ze snapshotu PrestaShop, nie UUID Allegro. Kraj odbiorcy pochodzi z recipient_snapshot, nie z nabywcy. Własny sprzedawca Korekty jest zachowany, choć próbka miała to pole puste. SKU/ID produktu są eksportowane tylko gdy zapisane przy pozycji. NEX nie utrwala pełnej historycznej kolekcji pozycji zamówienia, dlatego `order_items` pozostaje puste; nie podstawia się pozycji Faktury ani aktualnego zamówienia. Brak wagi/dat nie staje się zerem ani datą wystawienia.

Nieblokujące ograniczenia opisują komentarze XML NEX-OMS po dokumentach (również ostrzeżenia wyboru, gdy nie ma rekordów). Importer nie musi ich prezentować. Eksport koduje tekst bez możliwości wstrzyknięcia węzłów/DTD, nie wykonuje odwołań zewnętrznych i nie ujawnia raw snapshotów. Brak nowych zależności, migracji, zapisów dokumentów i wywołań KSeF/NBP. Szczegółowa mapa pól znajduje się w architecture.md.

## RS.1E-JPK-V7M3-001 — sprzedaż JPK_V7M(3) z KSeF

W rejestrze dostępny jest odrębny format `JPK_V7M (3) – KSeF`. Jest to eksport części sprzedażowej do programu księgowego, bez deklaracji VAT, zakupów, podpisu, wysyłki do MF i UPO. Zgodność XML/XSD nie oznacza kompletności rozliczenia, akceptacji urzędu ani potwierdzonej zgodności z konkretnym importerem księgowym. Nie przeprowadzono testu importu do programu księgowego.

Okres JPK pochodzi z głównego pola `Okres — miesiąc` i roku; nie ma drugiego wyboru okresu. Dla JPK dopuszczalne są okresy od lutego 2026 do grudnia 2090. W trybie zaznaczonych dokumentów ten sam wybór ustala nagłówek JPK, ale nie ogranicza wybranych ID. Własne zakresy dat nadal wybierają dokumenty i nie zmieniają miesiąca nagłówka. Dokumenty wcześniejsze są dozwolone. Parametry bieżącego eksportu obejmują typ podatnika, NIP zgodny z wystawcą wszystkich wybranych dokumentów, kod urzędu, e-mail, opcjonalny telefon, cel pliku oraz pełną nazwę osoby niefizycznej albo imię, nazwisko i datę urodzenia osoby fizycznej. Nie ma domyślnego podatnika z pierwszej serii ani sztucznych danych. Eksport nie zapisuje parametrów w bazie/URL; od RS.1E-JPK-UX-001 dane podatnika można zapisać odrębną operacją jako jeden profil.

**Decyzja produktowa właściciela: nie traktować jako TODO ani regresję.** `Generuj` bezpośrednio pobiera JPK po serwerowej walidacji aktualnych danych i oficjalnego XSD; wcześniejsze sprawdzenie, fingerprint, HMAC, token autoryzacyjny ani checkbox potwierdzenia nie są wymagane. Opcjonalne `Sprawdź eksport` jest bezstanową diagnostyką: przedstawia dokładną listę dokumentów, ich liczbę i braki, ale nie autoryzuje późniejszego pobrania, nie utrwala snapshotu i nie gwarantuje identycznych bajtów pliku po zmianie danych. Pobranie zawsze ponownie odczytuje bieżący stan oraz wykonuje pełną walidację requestu, podatnika, klasyfikacji VAT, GTU, procedur, polityki KSeF, kwot i XSD. Nie jest to deklaracja kompletności ewidencji podatnika. Błąd choćby jednego dokumentu blokuje cały plik i pokazuje diagnostykę, bez pomijania problematycznych wierszy.

Obsługiwane mapowania historycznej klasyfikacji: 23/22% do K_19/K_20, 8/7% do K_17/K_18, 5% do K_15/K_16, krajowe 0% do K_13, WDT do K_21, eksport do K_22. Jeden dokument daje jeden wiersz, także przy wielu stawkach. Klasyfikacja musi być zapisana w semantyce pozycji Faktury/Korekty; sam procent, waluta i kraj nie wystarczają. Nie inicjalizuje się brakujących snapshotów podczas eksportu. Kwoty są w PLN: własne zapisane wartości lub własne utrwalone przeliczenia grup. Brak przeliczenia i nierozdzielne kategorie tej samej grupy walutowej blokują plik. Korekty używają różnic z zachowaniem znaku kwoty oraz własnych BEFORE/AFTER przy zmianie kategorii, także przy zerowym brutto. GTU pochodzi z zapisanych pozycji, niezależnie od ustawień wydruku/KSeF.

Numer KSeF nie jest wymagany dla każdej faktury: bez niego eksport nie dodaje `NrKSeF`, lecz XSD wymaga jednego właściwego oznaczenia BFK/OFF/DI. Checkbox innych formatów nie może wyłączyć tego kontraktu. Świadoma polityka produktu ma następujący priorytet: poprawny i jednoznaczny własny `NrKSeF` PROD; następnie wiarygodny dowód PROD wystawienia poza KSeF albo procedury OFF/DI; następnie blokada każdego konfliktu KSeF lub nierozstrzygniętej transmisji; dopiero przy braku numeru, silniejszego dowodu i konfliktu domyślne BFK, gdy globalna opcja jest włączona. Potwierdzone lokalne `outside_ksef` daje BFK, ustawowa awaria daje OFF, a offline24/niedostępność bez numeru daje DI. Dane TEST/DEMO nie stanowią dowodu PROD. BFK nigdy nie maskuje poprawnego `NrKSeF`, OFF/DI, błędnego powiązania ani konfliktu transmisji.

Opcja `Oznacz faktury wystawione poza KSeF jako BFK`, pod urzędem skarbowym, jest świadomie domyślnie zaznaczona zarówno w nowym formularzu okresu, jak i formularzu wybranych dokumentów. Dla dokumentu bez `NrKSeF`, silniejszego dowodu PROD i konfliktu pozwala wygenerować BFK bez dodatkowego potwierdzenia dla każdego ID, niezależnie od NIP nabywcy (także z polskim NIP lub VAT UE). Jej odznaczenie jest respektowane również po błędzie formularza i pozostawia ścieżkę jawnego oznaczenia konkretnego dokumentu. Opcja nie zapisuje ustawienia w profilu ani zmian w fakturach. BFK oznacza wystawienie poza KSeF, nie sam brak NIP ani numeru KSeF; kwalifikację potwierdza użytkownik ([MF: oznaczenia DI/BFK](https://ksef.podatki.gov.pl/ksef-news/najczestsze-pytania/)). Ręczny wybór nie może nadpisać numeru ani konfliktu transmisji. Jest to stan lokalny w chwili eksportu, nie rekonstrukcja wcześniejszego złożenia JPK.

Nieobsługiwane szczególne procedury (FP/RO/WEW, OSS, marża, odwrotne obciążenie, ulga za złe długi i nieznane kwalifikacje) są blokowane, nie przedstawiane jako zwykła sprzedaż. Brak NIP-u nie oznacza FP. Obsługiwane identyfikatory to jednoznaczny polski NIP, zapisany VAT UE oraz rzeczywisty brak; błędny/sprzeczny identyfikator nie staje się BRAK. Nie tworzy się nowego modułu księgowania tych procedur.

Każdy eksport jest walidowany lokalnie niezmienionym finalnym XSD `14090`, wariant 3, `1-0E`, przed prywatnym pobraniem. Pusty zakres daje zero wierszy i zerowe kontrole; zerowy ZakupCtrl dotyczy tylko tego pliku. Załącznik referencyjny potwierdził 291 wierszy, 29 NrKSeF, 262 braki oznaczeń, podatek kontrolny 14674.41 i brak deklaracji. Jego walidacja dała 264 błędy: 262 brakujące wybory NrKSeF/OFF/BFK/DI oraz dwa naruszenia ograniczeń pustego Email. Tych braków nie skopiowano. Źródła i SHA-256 schematów są w `Modules/Invoices/Resources/Schemas/JPK_V7M3/1-0E/SOURCES.md`. Dane prywatnego przykładu nie zostały dodane do repozytorium.

### RS.1E-JPK-V7M3-FIX-001

GTU Korekty obejmuje tylko pozycje rzeczywiście korygowane według ich własnych zapisanych BEFORE/AFTER: ilość, ceny jednostkowe, netto/VAT/brutto, tożsamość VAT lub utrwalona kwalifikacja podatkowa. Pełny zwrot, dodana pozycja i zmiany kompensujące się kwotowo nadal kwalifikują GTU. Równoważny zapis liczby, kolejność kluczy i techniczna pozycja nie tworzą zmiany. Kody pochodzą wyłącznie z `gtu_codes` samej pozycji Korekty; niezmienione pozycje nadal podlegają pełnej walidacji i uzgodnieniu kwot. Reguła odpowiada [wyjaśnieniu MF o GTU na fakturach korygujących](https://www.podatki.gov.pl/podatki-firmowe/jednolity-plik-kontrolny/jpk_vat-z-deklaracja/pytania-i-odpowiedzi).

Granica: sam model nie przechowuje historycznych zestawów GTU BEFORE/AFTER. Korekta wyłącznie formalna z GTU (np. nabywca, opis albo samo oznaczenie), jak również formalnie zmieniona pozycja z GTU bez zmiany ilości/kwot/VAT, wymaga odrębnego ustalenia oznaczeń i blokuje ten eksport kontrolowanym błędem. Nie przyjmuje się automatycznie ani wszystkich, ani żadnych GTU. Nie dodano systemu korekt formalnych. Testy eksportów korzystają z prywatnych katalogów scenariusza i procesu, bez zasobów użytkownika.

### RS.1E-JPK-UX-001

`Faktury -> Ustawienia -> Dane podatnika JPK` przechowuje jeden profil obecnej firmy: osoba fizyczna/JDG albo osoba niefizyczna. Zapis jest jawny, atomowy i chroniony wersją przed nadpisaniem zmian z innej karty. Przełączenie typu usuwa nieaktywne dane drugiego typu. Nie zapisuje okresu, celu, filtrów, GTU ani potwierdzeń KSeF. Początkowa podpowiedź JDG nie zmienia istniejącego profilu.

Jeden domyślny zestaw danych podatnika automatycznie wypełnia początkowy formularz rejestru. Formularz nie pokazuje opisu profilu, przycisku jego ponownego wczytania ani linku do edycji danych podatnika. Dane domyślne można zmienić w ustawieniach faktur. Własne wartości eksportu, także jawnie puste po błędzie, mają pierwszeństwo i nie aktualizują profilu ani dokumentów. `Uzupełnij z danych firmy` w ustawieniach korzysta wyłącznie z jawnie wskazanej serii i pozwala sprawdzić dane przed zapisem. Nie zgaduje imienia, nazwiska, daty urodzenia ani urzędu. Bez profilu nadal działa jednorazowy eksport.

Urząd wybiera się po nazwie lub kodzie z lokalnego `KodyUrzedowSkarbowych_v8-0E.xsd`. Wyszukiwanie obsługuje klawiaturę, bez JavaScriptu dostępna jest pełna lista. Kod zachowuje zera wiodące, nieznany kod wymaga ponownego wyboru. Słownik schematu nie potwierdza właściwości urzędu dla podatnika.

Opcjonalny podgląd pokazuje GTU z tego samego wyniku mapowania co XML, status, numer/oznaczenie KSeF i blokady z bezpiecznymi wskazówkami. Brak zapisanych GTU nie oznacza podatkowego potwierdzenia „nie dotyczy”. Nieobsługiwane procedury są oznaczone jako wykryte, ale nieeksportowane; jeden błąd nadal blokuje cały plik. Generowanie zawsze sprawdza aktualne dane i oznaczenia, bez wymogu ponownego ręcznego podglądu.

W edycji wystawionej, nadal modyfikowalnej Faktury VAT można rozwinąć GTU konkretnej pozycji i jawnie zapisać GTU_01–GTU_13. Zapis samych oznaczeń nie zmienia kwot, kursu ani snapshotów finansowych. Finalizacja, KSeF Online/Offline, provenance, Korekty i konflikt wersji zachowują blokady. Korekta korzysta z utrwalonych kodów i dotychczasowej reguły zakresu rzeczywistej zmiany; formalne Korekty z nierozstrzygniętym GTU pozostają zablokowane. Nadawanie GTU przed automatyczną finalizacją/wysyłką wymaga osobnego etapu i nie jest rozwiązane tym edytorem.

Wdrożenie wymaga migracji `2026_09_17_010000_create_jpk_taxpayer_profiles_table.php`; nie uzupełnia ona profilu automatycznie ani nie zmienia dokumentów. Migracja podczas implementacji jest wykonywana wyłącznie w izolowanej bazie testowej.

# 29. Wysyłka e-mail

Docelowo dokument może być wysłany e-mailem.

Funkcja:

- używa wskazanego adresu,
- dołącza właściwy PDF,
- zapisuje zdarzenie,
- raportuje błędy,
- nadaje się do obsługi w kolejce.

Wiadomość i jej prywatne załączniki są zamrażane przed dodaniem joba do kolejki. Klucz idempotencji jest powiązany z fingerprintem intencji, a lista załączników z deterministycznym manifestem; zmiana transportowo istotnej konfiguracji SMTP po przygotowaniu blokuje wysyłkę. Osierocone rekordy `queued` odzyskuje scheduler, natomiast wynik `unknown` nigdy nie jest ponawiany automatycznie.

Karta zamówienia udostępnia ręczną wysyłkę w sekcji „Wymiana wiadomości”. Szablon jest opcjonalny, konto SMTP jest obowiązkowe i domyślnie wybrane, a odbiorca pochodzi z adresu e-mail zamówienia. Formularz działa przez AJAX, zapisuje wiadomość przed wysyłką i pokazuje aktualizowany status oraz zamrożone załączniki w historii zamówienia. Wybór SMS jest widoczny wyłącznie jako nieaktywna rezerwa pod przyszły etap.

Automatyczne akcje udostępniają operację „Wyślij e-mail”. Reguła wymaga wskazania szablonu, a konto SMTP, temat, treść i załączniki pochodzą z tego szablonu. Szablony ukryte na liście ręcznego wyboru mogą być używane w automatyzacjach. W polu „Do” domyślnie wybrany jest klient; można go usunąć albo dodać do dziesięciu innych adresów. Każdy unikalny odbiorca otrzymuje osobną wiadomość i nie widzi adresów pozostałych odbiorców. Jeżeli wybrano klienta, ale zamówienie nie ma poprawnego adresu e-mail, akcja kończy się przed utworzeniem wiadomości. Istniejące reguły bez jawnej konfiguracji odbiorców nadal wysyłają do klienta.

Wiadomości używają tych samych zmiennych zamówienia, zabezpieczeń, prywatnych załączników i historii co wysyłka ręczna. Jedno wykonanie konkretnego kroku może utworzyć najwyżej jedną wiadomość dla każdego unikalnego odbiorcy, a ponowienie tego samego kroku nie tworzy duplikatów.

Zakończenie kroku automatyzacji oznacza bezpieczne przygotowanie wiadomości i dodanie jej do kolejki, nie potwierdzenie dostarczenia. Wynik SMTP jest później widoczny przy wiadomości jako oczekująca, wysłana, błędna albo o niepewnym wyniku. OMS nie odbiera wiadomości ani odpowiedzi przez IMAP. Odpowiedzi klientów pozostają obsługiwane w lokalnym programie pocztowym.

Nie jest to część pierwszego etapu serii numeracji.

---

# 30. KSeF — wybrana strategia

Aktualny stan Production i siłę dowodów opisuje sekcja KSeF.8D poniżej. Wariant początkowy oraz ograniczenia poszczególnych etapów są historią wdrożenia, nie zakazem użycia funkcji dodanych później. Kod obsługuje Production od 8D.2; rzeczywisty dowód PROD dotyczy jednej ręcznej Faktury VAT Online, nie wszystkich trybów i automatyzacji.

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

W samym etapie KSeF.1 konfiguracja nie uruchamiała operacji dokumentowych i nie zmieniała finalizacji, snapshotów, numeracji ani PDF. Poniższe elementy były poza zakresem tego historycznego etapu; późniejsze wdrożenia opisano dalej, a bieżący stan PROD w 8D:

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

Opis ograniczeń TEST-only, braku UI i niewykonywanego `automatic_submission` poniżej dotyczy chwili zamknięcia 4A.1. Dalsze etapy dodały workflow ręczny, UPO/QR, Korekty i automatykę; ograniczenie środowisk usunięto w 8D.2. Nie jest to opis bieżącej dostępności funkcji.

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

Ograniczenia pierwszej wersji 4B.1 są historyczne. Kolejne etapy dodały lifecycle, świadome ponowienia i automatykę, a 8D.2 obsługę Production. Aktualny ręczny Online PROD i jego ograniczony dowód LIVE opisuje sekcja 8D; kontrakt automatic first-send opisuje 30.11.

KSeF.4B.1 udostępnia na read-only ekranie sfinalizowanej Faktury VAT ręczną pierwszą wysyłkę do KSeF TEST oraz ręczne, pojedyncze sprawdzenie statusu. Akcja wysyłki jest żądaniem POST z CSRF i jawnym oznaczeniem TEST. W ramach jednego wywołania istniejący transport przygotowuje najwyżej jedną próbę, otwiera jedną sesję i wykonuje najwyżej jeden POST Faktury. Po udanym zakończeniu transportu NEX wykonuje dokładnie jeden status GET bez pollingu i retry; wynik może od razu ustawić `processing`, `accepted` albo `rejected`. Od KSeF.6G.1 sprawdzenie lub uzgodnienie wyniku `accepted` planuje osobne background UPO zamiast pobierać je w tym samym żądaniu. Niedostępne jeszcze UPO nie cofa przyjętego statusu, a ręczna operacja pobrania pozostaje dostępna. Niepowodzenie dodatkowego odczytu statusu nie cofa udanej wysyłki, nie tworzy nowej próby i pozostawia dokument w stanie pozwalającym na późniejsze ręczne sprawdzenie.

Manualny orkiestrator blokuje Fakturę i bieżącą konfigurację w transakcji oraz atomowo sprawdza historię dla pary Faktura-bieżące środowisko. Każdy wcześniejszy rekord w tym samym środowisku, również `rejected` albo `technical_failed`, blokuje kolejną ręczną próbę. Historia z innego środowiska sama nie blokuje pierwszej próby w bieżącym środowisku. Przygotowanie pozostaje częścią tej transakcji, natomiast cały transport HTTP jest wykonywany dopiero po jej zatwierdzeniu.

Ręczny refresh jest osobnym żądaniem POST, dostępnym wyłącznie dla `submitted` i `processing`, i wykonuje dokładnie jeden status GET. Stany terminalne, `uncertain`, `preparing` i `session_opened` nie mają akcji ponowienia ani odświeżenia w 4B.1. Numer KSeF jest prezentowany w całości dopiero dla `accepted`; historia pokazuje wyłącznie bezpieczne pola i komunikaty, bez XML, hashy, NIP-ów, referencji sesji i surowych odpowiedzi.

Po potwierdzeniu statusu `accepted` PDF Faktury prezentuje pod numerem zamówienia numer KSeF, datę przetworzenia pochodzącą z `acquisition_date` oraz status KSeF. Pod informacjami dodatkowymi pokazuje również jeden kod weryfikacyjny KOD I z podpisem „Sprawdź w KSeF” i numerem KSeF. Link QR powstaje lokalnie z zamrożonego środowiska próby, NIP-u sprzedawcy, daty wystawienia `P_1` i hasha dokładnie wysłanego XML; przyjęcie unieważnia wcześniejszy cache PDF. Pro forma i dokumenty bez zaakceptowanej próby nie pokazują danych ani kodu KSeF.

### KSeF.8A — CLOSED

Faktura VAT i Korekta korzystają z jednego modelu prezentacyjnego KSeF w PDF. Dla zaakceptowanego dokumentu model odczytuje wyłącznie próbę z dokładnie wybranego środowiska, a numer KSeF, data przetworzenia, NIP sprzedawcy, hash XML i link KOD I zawsze pochodzą z własnego submissionu dokumentu. Korekta używa własnej daty wystawienia i nie dziedziczy danych KSeF z Faktury źródłowej. Od 8D.2 wybór w ogólnym presenterze wynika dokładnie z `KsefSetting.environment`, niezależnie od profilu Laravela `APP_ENV`, bez fallbacku między TEST, DEMO i Production. Dawne wymuszenie Production przez `APP_ENV` jest historyczną regułą 8A, usuniętą w 8D.2.

Od 8D.5.1 data przetworzenia jest prezentowana w `Europe/Warsaw` ze strefą i offsetem właściwym dla daty w drugim wierszu. Nie zmienia to źródłowego UTC ani dokumentu. Bieżący cache VAT/KOR ma wersję `v46`; Pro forma nadal `v35`.

Zaakceptowany dokument jest renderowany fail-closed: niepoprawny numer KSeF, brak wymaganych danych albo niemożność utworzenia oficjalnego linku weryfikacyjnego blokują PDF zamiast generować dokument bez poprawnego KOD I. Kod QR powstaje nadal przez istniejący `KsefInvoiceVerificationLinkBuilder`, centralny `KsefNumberValidator` i TCPDF. PDF w TEST albo DEMO zawiera czytelne oznaczenie dokumentu testowego; Production nie otrzymuje takiego oznaczenia. Jeżeli kod przechodzi na osobną stronę, strona wskazuje typ i numer dokumentu oraz cel „Weryfikacja KSeF”.

Dokument bez zaakceptowanego submissionu nie pokazuje numeru KSeF ani QR. Dla serii objętej KSeF lub istniejącej próby pokazuje neutralne ostrzeżenie odpowiednie dla oczekiwania, niepewnego wyniku, odrzucenia albo błędu technicznego; nie używa oznaczenia OFFLINE. Pro forma pozostaje całkowicie poza prezentacją KSeF. Historyczny fakt przyjęcia ma pierwszeństwo przed późniejszym wyłączeniem integracji lub serii, a potwierdzenie przyjęcia unieważnia cache PDF Faktury albo Korekty. Zbiorczy PDF zachowuje osobny model i osobny KOD I każdego dokumentu bez mieszania danych.

Kontrolowany test `KSeF.8A-LIVE-DEMO-QR-TEST-001: PASS` potwierdził fizycznym skanowaniem telefonu rzeczywiste PDF-y Accepted DEMO Faktury `BLF 79/2026` i Korekty `BLKF 2/2026`. Oba KODY I otworzyły oficjalny serwis `qr-demo.ksef.mf.gov.pl` i wskazały właściwe dokumenty. `Invoice QR scan: PASS`; `Correction QR scan: PASS`; `Correction own P_1: PASS`; `Correction own submission/hash/KSeF number: PASS`; `Cross-environment fallback: NO`; `LIVE INVOICE/CORRECTION POST: 0`. Korekta korzystała z własnego `P_1 = 01.09.2026`, a nie z `P_1 = 26.08.2026` Faktury źródłowej.

### KSeF.8B.1 — CLOSED

Konfiguracja KSeF posiada osobną zakładkę certyfikatów Offline. Dla TEST, DEMO i Production można przechowywać wiele certyfikatów, bez przypisywania ich do NIP-u kontekstu, oraz wskazać jeden lokalnie preferowany w każdym środowisku. Preferowanie jest ustawieniem NEX-OMS, a nie statusem MF. Import przyjmuje certyfikat PEM/DER i pasujący klucz prywatny PEM; hasło klucza jest używane tylko podczas importu i nie jest zapisywane. Materiał prywatny jest szyfrowany i niewidoczny w interfejsie, a lista pokazuje jedynie bezpieczne metadane. Lokalne usunięcie nie unieważnia certyfikatu po stronie KSeF.

Akceptowane są ważne certyfikaty Offline z przeznaczeniem `Non-Repudiation`/`Content Commitment`, numerem seryjnym złożonym z dokładnie 16 wielkich znaków szesnastkowych oraz kluczem RSA 2048 albo EC P-256. Certyfikat Authentication z przeznaczeniem `Digital Signature`, niezgodna para kluczy, nieobsługiwany algorytm albo certyfikat poza okresem ważności są odrzucane bez zapisu.

Fundament KODU II obsługuje identyfikatory kontekstu `Nip`, `InternalId`, `NipVatUe` i `PeppolId`, jawne środowisko bez fallbacku oraz oficjalny format linku i podpisu. RSA używa PSS SHA-256/MGF1 SHA-256 z solą długości 32, a EC P-256 podpisu SHA-256 w formacie P1363 `R||S`; hash i podpis używają Base64URL bez paddingu. Etap nie wystawia dokumentów offline, nie umieszcza jeszcze KODU II na PDF, nie wykonuje operacji enrollment/query/revoke w KSeF i nie wysyła żadnych requestów do MF. Lifecycle i enrollment certyfikatów należą do KSeF.8B.2, a właściwy workflow wystawiania offline do KSeF.8C.

### KSeF.8B.1.1 — CLOSED

Daty `validFrom` i `validTo` certyfikatu X.509 są przechowywane jako konkretne instanty. Przed zapisem NEX-OMS przelicza ich wall clock do `APP_TIMEZONE`, ponieważ SQLite i standardowy zapis Eloquent nie zachowują offsetu w wartości datetime. Po ponownym odczycie `valid_from` i `valid_until` nadal wskazują dokładnie ten sam moment UTC. Mechanizm współdzieli kontrakt KSeF.6F.2 używany dla ważności tokenów i jest pokryty testami lata, zimy, przejścia DST, jawnego offsetu oraz realnego importu certyfikatu. Jest to wyłącznie lokalny hardening persistence; kryptografia i domena 8B.1 nie zmieniły się, a remote lifecycle i enrollment pozostają zakresem KSeF.8B.2.

### KSeF.8B.2A — CLOSED

Zakładka certyfikatów Offline udostępnia ręczną akcję „Sprawdź w KSeF”. Logicznie read-only query i retrieve działają dla lokalnego certyfikatu w jego dokładnym środowisku. Pierwotna polityka 8B.2A dopuszczała TEST/DEMO; od 8D.2 dopuszcza także Production. Certyfikat nie jest przypisywany do `context_nip`. NEX-OMS wyszukuje w MF dokładnie jeden certyfikat o tym samym numerze seryjnym i typie `Offline`, pobiera go, a następnie sprawdza Base64 DER, X.509, numer seryjny, fingerprint SHA-256 i zgodność z lokalnym kluczem prywatnym. Po doprecyzowaniu 8B.2A.3 operacja wymaga konfiguracji Certificate/XAdES w dokładnym środowisku certyfikatu Offline; Token KSeF jest blokowany lokalnie przed auth i HTTP. Samo otwarcie zakładki nie łączy się z KSeF.

Interfejs pokazuje zdalny status, czas ostatniej pełnej weryfikacji, zdalną datę ważności oraz gotowość. Rozpoznawane statusy MF to `Active`, `Blocked`, `Revoked` i `Expired`; każda inna wartość jest prezentowana kontrolowanie i blokuje gotowość. Certyfikat jest gotowy tylko przy kompletnej i aktualnie ważnej parze lokalnej, pełnej zgodności z certyfikatem MF, udanej weryfikacji zdalnej, statusie dokładnie `Active` i aktualnej ważności zdalnej. Wskazanie certyfikatu jako preferowanego nie oznacza gotowości.

Po pełnym sukcesie zapisywane są wyłącznie bezpieczne metadane `remote_status`, `remote_certificate_name`, `remote_valid_from`, `remote_valid_until` i `remote_verified_at`. Zdalne daty są osobnym snapshotem, nie nadpisują ważności X.509 i przed persistence przechodzą przez `KsefInstantStorageNormalizer`. `remote_verified_at` oznacza pełną weryfikację tożsamości, bez dodatkowego TTL w 8B.2A. Jednoznaczna niezgodność tożsamości usuwa poprzedni snapshot zaufania. Przejściowy błąd przed poprawnym exact query nie zmienia ostatniego snapshotu; po poprawnym query obowiązuje doprecyzowana semantyka 8B.2A.2: świeże metadane są zapisywane przed retrieve, a poprzedni `remote_verified_at` może pozostać tylko dla zweryfikowanego `Active` przechodzącego do świeżego, obecnie ważnego `Active` przy niezmienionej lokalnej tożsamości. Zmiana lokalnego certyfikatu, klucza lub środowiska podczas requestu jest wykrywana przed zapisem. Etap nie dodaje automatycznej synchronizacji, kolejki, enrollmentu, CSR, zdalnego unieważniania, katalogu certyfikatów MF, wystawiania Offline24 ani KODU II do PDF.

### KSeF.8B.2A.1 — CLOSED

Jeżeli świeże, jednoznaczne wyszukanie certyfikatu w KSeF zwróci status inny niż `Active`, status nieznany albo zdalny okres ważności, który jeszcze się nie rozpoczął lub już minął, certyfikat natychmiast traci gotowość w NEX-OMS. Aktualny status, nazwa i daty z MF są zachowywane, a czas pełnej weryfikacji jest czyszczony jeszcze przed pobraniem certyfikatu. Późniejszy błąd sieci, 429, 5xx lub niekompletna odpowiedź retrieve nie może przywrócić wcześniejszego zaufanego `Active`.

Błąd przed uzyskaniem poprawnego wyniku wyszukiwania nadal zachowuje ostatni zaufany snapshot. Świeży, jednoznaczny wynik query zapisuje własne metadane przed retrieve; przy późniejszej przejściowej awarii pobrania poprzedni `remote_verified_at` może pozostać tylko dla zweryfikowanego `Active`, świeżego i obecnie ważnego `Active` oraz niezmienionej lokalnej tożsamości. Udane retrieve kończy pełną weryfikację tożsamości, a jednoznaczna niezgodność pobranego certyfikatu usuwa wszystkie zdalne dane zaufania. Nie dodano automatycznego sprawdzania, kolejek, retry, TTL ani migracji; etap nie wykonuje live HTTP.

### KSeF.8B.2A.2 — CLOSED

Każde poprawne, jednoznaczne wyszukanie dokładnego certyfikatu Offline zapisuje przed pobraniem jego świeży status, nazwę oraz pełny zakres ważności zwrócony przez MF. NEX-OMS nie skraca, nie wydłuża ani nie łączy tych dat z poprzednim snapshotem. Jeżeli późniejsze pobranie certyfikatu zakończy się przejściową awarią lub niekompletną odpowiedzią, nowe metadane pozostają widoczne i wpływają na bieżącą gotowość.

Czas `remote_verified_at` nadal opisuje ostatnią pełną kontrolę tożsamości certyfikatu, a nie samo wyszukanie metadanych. Dotychczasowy czas może pozostać wyłącznie przy przejściu ze zweryfikowanego `Active` do świeżego, obecnie ważnego `Active` bez zmiany lokalnego certyfikatu lub klucza. Brak wcześniejszej pełnej weryfikacji, wcześniejszy stan inny niż `Active`, nowy stan inny niż `Active`, nieznany status albo okres poza bieżącym instantem czyszczą ten czas; dopiero poprawne pobranie i porównanie DER, numeru seryjnego, typu, fingerprintu oraz klucza ustawia go ponownie.

Błąd albo malformed response samego query nie zmienia wcześniejszego snapshotu. Jednoznaczny błąd tożsamości query lub retrieve usuwa zdalne zaufanie. Wszystkie zapisy nadal sprawdzają race lokalnej konfiguracji, a requesty HTTP pozostają poza transakcją i nie są ponawiane. Etap nie dodaje migracji, zmian UI, zadań w tle, enrollmentu ani live HTTP.

### KSeF.8B.2A.3 — CLOSED

Live DEMO `KSeF.8B.2A-LIVE-DEMO-CERT-VERIFY-001` zakończył exact query kodem `HTTP 403`, gdy access token pochodził z metody Token KSeF. Obserwacja odpowiada zgłoszeniu CIRFMF [#659](https://github.com/CIRFMF/ksef-api/issues/659), którego duplikatem jest [#608](https://github.com/CIRFMF/ksef-api/issues/608). OpenAPI 2.7.1 opisuje Bearer jako mechanizm techniczny endpointów, a rozszerzona dokumentacja [Certyfikaty KSeF](https://github.com/CIRFMF/ksef-api/blob/main/certyfikaty-KSeF.md) wskazuje wymagane uwierzytelnienie XAdES dla danych certyfikacyjnych.

Zdalne sprawdzenie certyfikatu Offline wymaga teraz aktywnej metody Certificate i kompletnego materiału Authentication certificate/private key dla dokładnie tego samego środowiska. Token Auth kończy się kontrolowanym błędem przed użyciem cache, refresh, pełnym uwierzytelnieniem, query i retrieve. Przy metodzie Certificate dozwolony pozostaje ważny access token albo refresh pochodzący z tej konfiguracji; w przeciwnym razie używany jest istniejący flow Certificate/XAdES. Zmiana metody lub materiału Authentication unieważnia runtime tokeny, dlatego nie dodano pola provenance ani migracji.

Authentication certificate i Offline certificate pozostają osobne: materiał Offline nie jest kopiowany do credentiala i nie może służyć do uwierzytelnienia. Certyfikat Offline nadal nie jest wiązany z `context_nip`; dostęp do dokładnego numeru seryjnego rozstrzyga MF dla uwierzytelnionego podmiotu. Etap nie dodaje enrollmentu, CSR, revoke, zdalnego katalogu ani funkcji 8C. Ponowna próba live DEMO jest osobnym etapem `LIVE-DEMO-002`.

### KSeF.8B.2A-LIVE-DEMO-CERT-VERIFY-002 — PASS

03.09.2026 produkcyjny flow NEX-OMS zweryfikował w DEMO istniejący certyfikat Offline, używając ważnego cached access tokena z konfiguracji Certificate/XAdES. Exact query i retrieve zwróciły po jednym zgodnym certyfikacie typu `Offline`; serial, DER/X.509, fingerprint oraz lokalny klucz prywatny przeszły kontrolę. Zdalny status `Active`, pełny snapshot i świeży `remote_verified_at` zostały zapisane z poprawnym roundtripem instantów, a certyfikat osiągnął readiness `YES`. Nie wykonano retry, enrollmentu, CSR, revoke, transmisji dokumentów ani requestów TEST/Production.

### KSeF.8C.0 — CLOSED

Istniejący workflow Online wysyła Faktury VAT i Korekty wyłącznie z jawnym `offlineMode = false`. Przed jakąkolwiek komunikacją z KSeF oraz ponownie bezpośrednio przed invoice POST system bezpiecznie odczytuje `P_1` z dokładnego zamrożonego XML FA(3) i wymaga daty bieżącego dnia w `Europe/Warsaw`. Zmiana dnia podczas transportu zamyka otwartą sesję w trybie best effort i kończy próbę bez wysłania dokumentu.

Submission zapisuje rzeczywisty `invoicingMode` zwrócony przez MF jako `Online` albo `Offline`. Końcowe przyjęcie bez rozpoznanego trybu jest niepewne. Niespodziewane `Offline` zachowuje status przyjęcia i numer KSeF jako fakt historyczny, ale blokuje prezentację Accepted Online PDF, Online UPO follow-up i zdarzenie automatyzacji `KsefInvoiceAccepted`. Historyczne przyjęte rekordy bez zapisanego trybu zachowują zgodność i dotychczasową prezentację.

KSeF.8C.0 nie wdraża automatycznego Offline24, `KsefOfflineIssuance`, KODU II na Fakturze, Offline PDF, Latarni, deadline engine, automatycznej wysyłki Offline, Korekt Offline, korekty technicznej, total failure ani rollout'u Production.

### KSeF.8C.1 — CLOSED

TEST/DEMO i blokada Production poniżej są historycznym zakresem 8C.1, zmienionym w kodzie w 8D.2. Nie oznacza to wykonania LIVE Offline PROD.

Operator może wystawić lokalnie w trybie Offline24 wyłącznie wystawioną i sfinalizowaną Fakturę VAT z serii włączonej do KSeF. Funkcja działa dla TEST i DEMO; Production pozostaje zablokowane. Integracja musi być aktywna, ale lokalny fakt wystawienia nie zależy od deployment gate transmisji. Kontekst v1 musi być poprawnym NIP-em równym NIP-owi sprzedawcy, a dla dokładnego środowiska musi być wybrany gotowy certyfikat Offline. Brak wyboru, nieaktualna zdalna weryfikacja, status inny niż `Active`, nieważność certyfikatu lub niespójny klucz blokują operację bez HTTP.

Wystawienie tworzy jeden niezmienny `KsefOfflineIssuance` dla Faktury i środowiska. Przechowuje dokładny autorytatywny XML FA(3) zaszyfrowany at rest, jego SHA-256 Base64 i rozmiar, `P_1` odczytane z tego XML, jeden czas `issuedAt`, zamrożone dane sprzedawcy i kontekstu, snapshot gotowości certyfikatu oraz URL-e KODU I i KODU II. `P_1` musi odpowiadać dacie tego samego instantu w `Europe/Warsaw`. Ten sam XML i hash są źródłem obu kodów i przyszłej transmisji; XML nie może być później regenerowany z aktualnego stanu Faktury. Klucz prywatny i certyfikat PEM nie są kopiowane do issuance.

Offline24 jest wzajemnie wykluczające z istniejącą historią Online i oznaczeniem `OutsideKsef` w tym samym środowisku. Po wystawieniu Offline24 zwykła ścieżka Online, ręczna i automatyczna wysyłka, eksport miesięczny, oznaczenie `OutsideKsef` oraz usunięcie Faktury są zablokowane. Zmiana Faktury, ustawień, serii albo certyfikatu podczas lokalnego generowania kończy operację bez zapisu; ograniczenie unikalne chroni także przed duplikatem.

Ekran Faktury pokazuje `WYSTAW OFFLINE24` tylko przy pełnej kwalifikacji i wymaga potwierdzenia trwałego zamrożenia dokumentu. Po operacji widoczny jest status „Offline24 — wystawiona lokalnie”, `P_1`, czas, numer seryjny i historyczny status certyfikatu oraz „Numer KSeF: jeszcze nie nadano”. Etap wykonuje zero requestów KSeF i nie tworzy submissionu. Nie obejmuje PDF Offline, obrazów QR, potwierdzenia transakcji, doręczenia nabywcy, późniejszej transmisji `offlineMode=true`, terminów, Latarni, automatycznej wysyłki, Korekt Offline ani Production.

### KSeF.8C.2 — CLOSED

Wyłączenie Production poniżej dotyczy zakresu 8C.2, przed udostępnieniem wspólnej obsługi w 8D.2.

Po wystawieniu Offline24 ekran pokazuje dokładnie jedną akcję doręczenia wynikającą z zamrożonej tożsamości nabywcy. Dla nabywcy z polskim NIP-em dostępne jest `POBIERZ POTWIERDZENIE TRANSAKCJI`; dla nabywcy bez polskiego NIP-u dostępne jest `POBIERZ FAKTURĘ OFFLINE`. Klasyfikacja „bez polskiego NIP-u” jest konserwatywną polityką v1 NEX-OMS, a nie pełną oceną prawną. Dane niejednoznaczne lub niespójne blokują oba dokumenty. Ograniczenie działa po stronie serwera, nie tylko w interfejsie.

Faktura Offline jest renderowana wyłącznie z zamrożonego XML FA(3), zawiera dane dokumentu i dokładnie dwa kody QR: KOD I z podpisem `OFFLINE` oraz KOD II z podpisem `CERTYFIKAT`. Potwierdzenie transakcji nie jest fakturą; zawiera tylko dane identyfikacyjne stron, numer dokumentu `P_2`, kwotę `P_15` z walutą oraz dwa kody QR z nagłówkami `sprawdź fakturę w KSeF` i `zweryfikuj wystawcę faktury`, bez podpisów `OFFLINE` i `CERTYFIKAT`. Dokumenty środowisk TEST i DEMO są wyraźnie oznaczone.

Generowanie jest lokalne, nie wykonuje HTTP, nie modyfikuje issuance i nie tworzy trwałego cache. Zmiany bieżącej Faktury, konfiguracji lub certyfikatu, a także usunięcie rekordu certyfikatu, nie zmieniają historycznej treści. Dla Faktury posiadającej Offline24 zwykły PDF z bieżącego snapshotu, stary cache tego PDF-u i PDF zbiorczy są blokowane, aby operator korzystał z właściwego dokumentu doręczenia.

Etap nie wdraża transmisji Offline24, terminów przekazania, integracji z Latarnią, Korekt Offline ani środowiska Production.

### KSeF.8C.2.1 — CLOSED

Faktura Offline wiernie przedstawia wszystkie stawki obsługiwane przez aktualny profil FA(3): `23%`, `22%`, `8%`, `7%`, `5%`, `0% krajowa`, `0% WDT` i `0% eksport`. Etykieta bucketu pochodzi wyłącznie z zamrożonych wartości `FaWiersz/P_12`, natomiast kwoty netto i VAT pozostają dokładnymi wartościami zapisanymi w `P_13_*` i `P_14_*`, bez ponownego obliczania podatku. Dla wspólnych bucketów historycznych prezentacja pokazuje `23% / 22%` albo `8% / 7%`, gdy obie stawki występują w jednym dokumencie. Niespójność linii i podsumowania blokuje PDF.

Prezentacja używa dokładnie zamrożonych URL-i KODU I i KODU II, bez ich przebudowy lub ponownego podpisania i niezależnie od późniejszej zmiany bieżącej konfiguracji QR. Mała polityka canonical hostów wiąże oficjalne hosty z właściwym środowiskiem; historycznie użyte oficjalne hosty muszą pozostać dozwolone po ewentualnej zmianie endpointów MF. Oba URL-e przechodzą ścisłą walidację HTTPS, hosta i struktury ścieżki względem zamrożonego środowiska, NIP-u, `P_1`, hasha, kontekstu i certyfikatu. Złośliwy host lub host innego środowiska jest odrzucany.

Potwierdzenie transakcji zawiera wyłącznie wymagany zakres biznesowy: dane identyfikacyjne sprzedawcy i nabywcy, `P_2`, `P_15` z walutą oraz KOD I i KOD II z nagłówkami `sprawdź fakturę w KSeF` i `zweryfikuj wystawcę faktury`. Nie zawiera podpisów `OFFLINE`/`CERTYFIKAT` pod kodami ani dodatkowego zdania „Ten dokument nie jest fakturą.”; techniczne oznaczenie TEST/DEMO pozostaje dla bezpieczeństwa. Polityka doręczenia z 8C.2 nie zmienia się. Etap wykonuje zero HTTP, nie modyfikuje issuance i nie dodaje migracji.

### KSeF.8C.3 — CLOSED

Ograniczenie do TEST/DEMO opisuje historyczny 8C.3. Od 8D.2 Production jest obsługiwane przez wspólną politykę, ale ten etap nie dostarczył dowodu LIVE Offline PROD.

Faktura wystawiona jako Offline24 może zostać później ręcznie przekazana do KSeF z poziomu konkretnego historycznego wystawienia. Submission przechowuje relację do `KsefOfflineIssuance` oraz zaszyfrowaną kopię dokładnie tego samego zamrożonego XML FA(3), hasha, rozmiaru, schematu, środowiska, kontekstu i sprzedawcy. Nie następuje ponowne generowanie FA(3), a zmiany bieżącej Faktury, serii lub certyfikatu Offline nie zmieniają treści transmisji.

Wysyłka używa zamrożonego środowiska TEST albo DEMO i jawnego `offlineMode=true`, bez `hashOfCorrectedInvoice`. Bieżące środowisko konfiguracji może być inne. Dla bezpieczeństwa bieżący NIP kontekstu musi jednak odpowiadać zamrożonemu kontekstowi, a integracja musi być aktywna; niespełnienie tych warunków blokuje operację przed HTTP. Późniejsze wyłączenie serii lub usunięcie certyfikatu użytego wcześniej do KODU II nie blokuje wysłania. Nie obowiązuje internetowa reguła wysyłki tylko w dniu `P_1`, ponieważ źródłem jest historyczny dokument Offline24. Production pozostaje niedostępne.

Jedna próba może wykonać najwyżej jeden POST Faktury. Po jednoznacznym `TechnicalFailed` operator może utworzyć kolejną próbę dla tego samego issuance; `Rejected` blokuje ślepy resend, a `Uncertain` udostępnia wyłącznie reconciliation, które odnajduje dokument po dokładnym hash/reference i nie wysyła go ponownie. Stany `Submitted` i `Processing` korzystają z istniejącego odświeżania oraz follow-upu. Etap nie dodaje automatycznej wysyłki Offline24.

Prawidłowo przyjęty dokument musi mieć `invoicingMode=Offline`. Wtedy zachowywany jest numer KSeF, planowane jest UPO i emitowane istniejące zdarzenie akceptacji Faktury. Jeżeli KSeF zwróci dla powiązanego Offline24 tryb `Online`, stan `Accepted` pozostaje faktem transportowym, ale dokument końcowy, UPO, resend i zdarzenie są blokowane. Analogiczna dotychczasowa ochrona zwykłego Online przed nieoczekiwanym `Offline` pozostaje bez zmian.

Po zgodnym `Accepted Offline` dostępny jest finalny PDF utworzony z zamrożonych danych. Zawiera dokładnie jeden zapisany KOD I podpisany pełnym numerem KSeF, bez KODU II oraz bez etykiet `OFFLINE` i `CERTYFIKAT`; wcześniejsza Faktura Offline 2QR i potwierdzenie transakcji są wtedy blokowane. Ekran zachowuje widoczność historycznego środowiska i pokazuje właściwą akcję dla każdego stanu. Etap nie implementuje terminów ustawowych, Latarni, Korekt Offline, korekty technicznej, trybów awaryjnych ani Production.

### KSeF.8C.4A — CLOSED

NEX-OMS posiada niezależny, read-only fundament integracji z publiczną Latarnią KSeF zgodny z oficjalnym repozytorium `CIRFMF/ksef-latarnia` w rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a` i OpenAPI 1.0.0. Pobierane są wyłącznie `GET /status` i `GET /messages`, bez autoryzacji, kontekstu NIP i credentials KSeF. TEST korzysta wyłącznie z oficjalnej Latarni TEST, PRODUCTION wyłącznie z oficjalnej Latarni produkcyjnej, a DEMO nie ma substytutu ani fallbacku do produkcji i kończy się bez HTTP.

Odpowiedzi są walidowane fail-closed względem statusów `AVAILABLE`, `MAINTENANCE`, `FAILURE`, `TOTAL_FAILURE`, kategorii, typów, zgodności statusu z komunikatami oraz ograniczeń pól. Daty muszą zawierać offset albo `Z` i są zapisywane jako chwile UTC. Ze względu na rozbieżność pomiędzy listą `required` OpenAPI a oficjalnym opisem, przykładami i scenariuszami, `end` może być nieobecne/null dla trwającego `FAILURE_START`; dla `FAILURE_END` jest wymagane, a planowana niedostępność wymaga końca późniejszego od początku. Nieznane enumy i niespójne kombinacje są odrzucane. Dodatkowe neutralne dane źródłowe pozostają w kanonicznym payloadzie, ale nigdy nie sterują logiką.

Każdy komunikat jest zachowywany jako niezmienna historia konkretnego środowiska, identyfikatora i wersji wraz z dokładnym `eventId`, kanonicznym JSON-em i hashem Base64 SHA-256. Identyczny payload tej samej wersji nie tworzy duplikatu i aktualizuje tylko `last_seen_at`; nowa wersja tworzy kolejny rekord; zmiana treści tej samej wersji jest blokowana; usunięcie komunikatu z publicznego feedu nie usuwa lokalnej historii. Ostatni potwierdzony status oraz niezależne próby, sukcesy i błędy `/status` i `/messages` są przechowywane osobno. Błąd nie jest interpretowany jako `AVAILABLE`, nie usuwa poprzedniego potwierdzonego stanu, a częściowy sukces jednego endpointu pozostaje trwały mimo awarii drugiego.

KSeF.8C.4A nie uruchamia automatycznej synchronizacji, schedulerów ani kolejek i nie prezentuje Latarni w UI. Nie liczy terminów ani dni roboczych, nie łączy komunikatów z Fakturami lub Offline24, nie modyfikuje wystawień Offline, nie blokuje i nie uruchamia transportu oraz nie dodaje procedur awaryjnych. `TOTAL_FAILURE` jest na tym etapie wyłącznie zapisaną informacją, bez skutku biznesowego. Deadline engine i wynikające z Latarni obowiązki pozostają poza zakresem do KSeF.8C.4B.

### KSeF.8C.4A.1 — CLOSED

Kontrolowany test publicznej Latarni TEST potwierdził poprawność parsera i surowych danych UTC, ale ujawnił przesunięcie chwili przy ponownym odczycie przez standardowy Eloquent `immutable_datetime` w strefie `Europe/Warsaw`. Dedykowany cast UTC dla pól komunikatów i stanu synchronizacji zapisuje UTC wall-clock oraz zawsze odczytuje go jako ten sam instant UTC, niezależnie od `APP_TIMEZONE`, CET/CEST i zmian DST. Istniejące trzy rekordy live zachowały surowe wartości oraz payloady i nie wymagały przeliczenia ani migracji danych.

Regresje potwierdzają round-trip zimą, latem, przy wejściu w DST i dla obu różnych instantów powtarzającej się jesiennej godziny. Nullable `end_at` pozostaje null, uszkodzony surowy czas jest odrzucany, a `first_fetched_at`, `last_seen_at` oraz czasy prób, sukcesów i błędów zachowują chwilę UTC. Baza przechowuje te pola z dokładnością do jednej sekundy; części ułamkowe pozostają w kanonicznym payloadzie źródłowym. `KsefInstantStorageNormalizer` oraz istniejąca semantyka tokenów i certyfikatów nie zostały zmienione.

KSeF.8C.4A.1 nie zmienia klienta i parserów Latarni, historii komunikatów, Faktur, Offline24 ani transportu KSeF. Nie dodaje deadline engine, kalendarza dni roboczych, schedulera, kolejki, UI, powiązań dokumentowych, skutków biznesowych `TOTAL_FAILURE` ani nowych procedur Offline.

### KSeF.8C.4B — CLOSED

NEX-OMS posiada deterministyczny, tylko odczytowy mechanizm określania stanu obowiązku przesłania istniejącej Faktury Offline24. Bazowy termin to następny dzień roboczy po zamrożonym `P_1`; data i bieżący stan Faktury nie zastępują snapshotu wystawienia. Ocena zawsze otrzymuje jawny czas `asOf`, a terminy są polskimi datami kalendarzowymi oznaczającymi możliwość działania do końca wskazanego dnia.

Decision record `BUSINESS DAY DEFINITION`, stan prawny na 03.09.2026: [ustawa o VAT, Dz.U. 2025 poz. 775](https://eli.gov.pl/api/acts/DU/2025/775/text/U/D20250775Lj.pdf), [Ordynacja podatkowa, Dz.U. 2026 poz. 622](https://eli.gov.pl/api/acts/DU/2026/622/text/U/D20260622Lj.pdf), [ustawa o dniach wolnych od pracy, Dz.U. 2025 poz. 296](https://eli.gov.pl/api/acts/DU/2025/296/text/T/D20250296L.pdf), oficjalne strony MF o [Offline24](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-offline24/) i [trybie awaryjnym](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/tryb-awaryjny/), [Podręcznik KSeF 2.0 część III z 30.04.2026](https://ksef.podatki.gov.pl/media/claghxzf/podrecznik-ksef-20-cz-iii-dodatkowe-funkcjonalnosci-ksef-30042026.pdf) oraz [oficjalne objaśnienie MF identycznego pojęcia „dni roboczych” w tej samej ustawie o VAT](https://podatki.gov.pl/pozostale/procedura-szczegolna-sme/pytania-i-odpowiedzi) potwierdzają przyjęty kontrakt. Sobota, niedziela i święta ustawowe nie są dniami roboczymi; 24 grudnia obowiązuje od 2025 r. Dzień zakończenia awarii jest dniem 0, a termin upływa z końcem siódmego późniejszego dnia roboczego. Święta ruchome są obliczane dla dowolnego roku algorytmem gregoriańskim.

Jeżeli po wystawieniu, a przed rozstrzygniętą transmisją zostanie opublikowany komunikat zwykłej awarii, mechanizm używa `published_at`, nie `start_at`. Reguła obejmuje również komunikat opublikowany już po przekroczeniu bazowego terminu. Do czasu jednoznacznego `end_at` wynik to `WAITING_FOR_FAILURE_END`; później termin wynosi siedem dni roboczych od polskiej daty końca. Kolejna awaria opublikowana do końca aktualnego dnia terminu rozpoczyna liczenie od jej zakończenia, także w wieloelementowym łańcuchu. Awaria całkowita opublikowana po wystawieniu daje read-only `NOT_REQUIRED_TOTAL_FAILURE`, a maintenance jest ignorowane dla Offline24. Latarnia stanowi strukturalne evidence projekcji; nie wykonuje nieodwracalnego rozstrzygnięcia prawnego ani nie zmienia możliwości transportu.

Evidence jest jawne: `complete` pozwala obliczyć termin nawet przy pustym feedzie, `insufficient` zwraca `EVIDENCE_UNAVAILABLE`, a DEMO ma `unsupported_environment` bez fallbacku do TEST lub Production. TEST i Production korzystają wyłącznie z własnych komunikatów. Zgodnie z kontraktem [CIRFMF/ksef-latarnia](https://github.com/CIRFMF/ksef-latarnia) w rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a` najnowsze wersje wiadomości są składane według `eventId`; sprzeczne lub nakładające się zdarzenia kończą się bezpiecznym `AMBIGUOUS_EVENT_HISTORY`.

Dokładnie powiązana historia transmisji ma pierwszeństwo przed deadline: `Accepted Offline` oznacza `FULFILLED`, `Submitted`/`Processing` z referencją oczekuje na wynik, `Uncertain` pozostaje niepewne, `Rejected` wymaga remediation, a technicznie nieudana lub nierozpoczęta transmisja zachowuje aktywny obowiązek. Accepted w niezgodnym trybie i niespójne dane są stanami fail-closed. Kolejność wynika z `attempt_number`; system nie zgaduje czasu wysyłki na podstawie `created_at` lub `updated_at`.

KSeF.8C.4B niczego nie zapisuje i nie zmienia wystawienia Offline24, Faktury ani submissionów. Nie dodaje migracji, HTTP, automatycznej synchronizacji Latarni, schedulera, kolejki, UI, automatycznej wysyłki, blokady transportu, obsługi korekty technicznej ani nowych procedur Offline.

### KSeF.8C.4B.1-R2 — CLOSED

Dwie wcześniejsze próby zamknięcia zostały zablokowane przez niejednoznaczność jesiennej zmiany czasu: dwa różne exact instants mogły zostać zapisane jako ten sam pozbawiony offsetu wall-clock `Europe/Warsaw`. `KsefOfflineIssuance.issued_at` i `KsefInvoiceSubmission.generated_at` mają teraz wspólny kontrakt: exact instant domenowy → surowy UTC wall-clock w bazie → ten sam exact instant UTC po odczycie przez istniejący `KsefUtcInstantCast`.

Migracja `083000` przelicza legacy wall-clock `Europe/Warsaw` na UTC wyłącznie dla tych dwóch pól. Najpierw sprawdza wszystkie rekordy, a następnie wykonuje atomową aktualizację; brak odwzorowania podczas wiosennej przerwy lub więcej niż jedno odwzorowanie podczas jesiennego powtórzenia godziny powoduje fail-closed. Migracja jest jawnie nieodwracalna i została następnie kontrolowanie wykonana na bazie operatora.

Zwykłe przygotowanie Online zapisuje `generated_at` bez konwersji do `APP_TIMEZONE`, a przygotowanie Offline kopiuje exact `issued_at` do `generated_at`. Ścisłe porównanie instantów w integralności Offline pozostaje bez zmian. Payload XML, hash, rozmiar, schema, `offlineMode`, szyfrowanie, lifecycle sesji, retry, statusy, reconciliation i liczba POST-ów zachowują dotychczasową semantykę. Pozostałe timestampy submissionu zostały tylko zaudytowane i nie są częścią tej zmiany.

### KSeF.8C.4B.2 — CLOSED

Osiem pozostałych exact-instant fields submissionu: `session_valid_until`, `session_closed_at`, `acquisition_date`, `invoicing_date`, `permanent_storage_date`, `last_checked_at`, `next_follow_up_at` i `last_follow_up_at` ma jednolity kontrakt exact instant → surowy UTC `Y-m-d H:i:s` → ten sam instant UTC po odczycie przez istniejący `KsefUtcInstantCast`. Zdalne daty KSeF zachowują offset źródłowy jako instant, a znaczniki sesji i follow-upów nie zależą już od lokalnego wall-clock `APP_TIMEZONE`. Due/future, `Retry-After`, backoff, limiter i claim zachowują dotychczasowe reguły, ale porównują i zapisują dokładne momenty UTC.

Migracja `084000` konwertuje wyłącznie tych osiem historycznych kolumn z jednolitego naive wall-clock `Europe/Warsaw` do UTC. Waliduje cały plan przed zapisem, działa transakcyjnie i używa optimistic raw guardu; niejednoznaczna godzina jesienna, nieistniejąca godzina wiosenna, zły format lub zmiana współbieżna zatrzymują całość bez częściowych aktualizacji. Rollback jest jawnie nieodwracalny. Migracja nie została uruchomiona na bazie operatora i pozostaje `Pending`.

Etap nie zmienia `generated_at`, `issued_at`, migracji `083000`, transportu, XML, hashy, szyfrowania, status mappingu, UPO, deadline engine ani integralności Offline. Nie dodaje HTTP, schedulera, kolejki, UI, automatycznej wysyłki ani nowej procedury Offline.

### KSeF.8C.4C — Operational Latarnia integration

Zweryfikowany kontrakt oficjalnej Latarni w rewizji `b3d819616eb640270a2e11321d424f206d5e0b1a` i OpenAPI `1.0.0` zachowuje publiczne `GET /status` oraz `GET /messages`, 30-dniową retencję zakończonych komunikatów i osobne hosty TEST/Production. DEMO nie ma Latarni i nie korzysta z fallbacku do innego środowiska.

Synchronizacja operacyjna jest domyślnie wyłączona przez `KSEF_LATARNIA_SYNC_ENABLED=false`. Po włączeniu zadanie `ksef-latarnia-sync` działa co pięć minut bez nakładania przebiegów i wybiera maksymalnie TEST oraz Production na podstawie aktywnej konfiguracji i historycznych wystawień Offline24. Na środowisko przypada najwyżej jeden GET statusu i jeden GET komunikatów, bez retry i bez kolejki. Ręczne „Odśwież Latarnię” działa niezależnie od gate tylko dla jawnie wybranego TEST lub Production; DEMO nie wykonuje requestu.

Udany `/messages` ustanawia konserwatywne coverage `[T-30 dni, T]`. Ciągłe, nakładające się lub stykające okna są scalane, a nieodwracalna luka resetuje coverage do najnowszego okna. Błędy, konflikty wersji i synchronizacja samego statusu nie przesuwają granic. Migracja `085000`, dodająca nullable exact instants coverage, została wykonana przez operatora.

Pełne evidence wymaga, aby wystawienie mieściło się w coverage, granica `coverageThrough` nie leżała w przyszłości i była świeża w oknie 15 minut. Wtedy projekcja używa `evidenceAsOf = coverageThrough` i ignoruje później opublikowane lub później pobrane komunikaty. Brak ciągłości, świeżości albo wsparcia środowiska daje odpowiednio `insufficient` lub `unsupported_environment`, bez domyślania brakującej historii.

Zakładka „Latarnia” pokazuje wyłącznie lokalne: status, świeżość, coverage, bezpieczne błędy i maksymalnie 20 ostatnich komunikatów. Lista Faktur również nie wykonuje HTTP ani zapisów: zbiorczo wylicza read-only polskie badge i ostrzeżenia obowiązku Offline24 bez N+1. Projekcja `TOTAL_FAILURE` ma charakter informacyjny i odwracalny; nie finalizuje prawnego skutku, nie zmienia Faktury i nie blokuje ani nie uruchamia transportu.

KSeF.8C.4C nie wdraża automatycznej wysyłki ani nowego trybu Offline. Live validation synchronizacji Latarni jest odłożona; testy nie wykonują publicznych requestów.

### KSeF.8C.4C.1 — CLOSED

Pełne evidence Latarni spełnia dokładny niezmiennik `coverageFrom <= issuance.issued_at <= coverageThrough <= requestedAsOf` wraz z dotychczasowym warunkiem świeżości. Faktura Offline24 wystawiona po końcu ostatniego kompletnego coverage pozostaje `EVIDENCE_UNAVAILABLE` do kolejnej kompletnej synchronizacji `/messages`; system nie wylicza dla niej pewnego terminu z evidence kończącego się przed wystawieniem.

Etap nie zmienia deadline engine, projektora, synchronizacji coverage ani schedulera. Migracja `085000` została wcześniej wykonana przez operatora; nie dodano migracji ani backfillu, nie wykonano live HTTP i nie zmieniono danych biznesowych.

### KSeF.8C.5 — Planned unavailability + ordinary failure

Blokada Production poniżej należała do zakresu 8C.5. Usunięto ją w kodzie w 8D.2, zachowując wymagania świeżego evidence Latarni. LIVE Online 8D.5 nie potwierdza operacyjnej gotowości tych procedur PROD.

NEX-OMS rozróżnia trzy procedury wystawienia Faktury Offline: `Offline24`, `Offline – niedostępność KSeF` oraz `Tryb awaryjny`. Podstawą są odpowiednio art. 106nda, 106nh i 106nf ustawy o VAT, zweryfikowane 04.09.2026 z oficjalnymi materiałami MF. Operator wybiera procedurę jawnie; system nie przełącza automatycznie pomiędzy nimi. Wszystkie trzy zamrażają ten sam XML FA(3), datę `P_1`, hash, rozmiar, certyfikat Offline oraz KOD I i KOD II, a późniejsza transmisja wysyła dokładnie te bajty z `offlineMode=true`.

Akcje planowanej niedostępności i trybu awaryjnego są dostępne wyłącznie na podstawie świeżych danych zapisanych wcześniej przez Latarnię. Samo otwarcie ekranu i wystawienie nie odświeżają Latarni i nie wykonują HTTP. Planowana niedostępność wymaga bieżącego statusu `MAINTENANCE` i jednego aktywnego komunikatu przerwy; tryb awaryjny wymaga statusu `FAILURE` i jednego aktywnego zwykłego zdarzenia awarii. Dane stare, brakujące, niejednoznaczne, poznane po czasie lub niespójne ze statusem blokują operację. `TOTAL_FAILURE` także blokuje te akcje. DEMO nie ma Latarni i nie korzysta z żadnego fallbacku, a Production pozostaje operacyjnie zablokowane.

Dla obu nowych procedur Faktura zachowuje niezmienny dowód kwalifikacji: środowisko Latarni, identyfikator eventu i wiadomości, wersję, kategorię, początek i koniec, czas publikacji oraz okno evidence. Migracja `086000` dodaje te pola jako nullable exact instants; istniejące `Offline24` zachowują wartości `NULL` bez backfillu. Migracja `086000`: `Ran` na bazie operatora; read-only weryfikacja zakończona PASS. KSeF.8C.5 DB migration: CLOSED.

Termin `Offline24` nadal przypada na następny dzień roboczy po `P_1`. W planowanej niedostępności termin nie jest pokazywany przed końcem przerwy, a potem przypada na następny dzień roboczy po jej zakończeniu. W trybie awaryjnym termin pozostaje nieznany do końca awarii, a następnie wynosi siedem dni roboczych. Kolejna zwykła awaria w tym okresie resetuje termin od własnego zakończenia; awaria rozpoczęta podczas planowanej przerwy również przełącza obowiązek na termin siedmiodniowy. Późniejsza awaria całkowita pozostaje wyłącznie read-only projekcją braku obowiązku transmisji.

Przed akceptacją dokument dla nabywcy w `Offline24` i planowanej niedostępności podlega dotychczasowej polityce zależnej od obecności polskiego NIP-u. W zwykłej awarii pełna Faktura Offline z KODEM I i KODEM II jest dostępna również dla krajowego nabywcy z polskim NIP-em. Po transmisji wszystkie trzy procedury korzystają z tych samych statusów, zaakceptowanego PDF-u i UPO w trybie `Offline`.

Etap nie implementuje wystawienia przy awarii całkowitej, Korekt Offline, korekty technicznej, automatycznej wysyłki, nowej kolejki, zmian schedulera ani Production. Wdrożenie nie wykonywało live HTTP; kontrolowana walidacja live jest osobnym zadaniem.

### KSeF.8C.5.1 — CLOSED

Zwykła awaria opublikowana podczas nadal trwającej planowanej niedostępności natychmiast przejmuje regułę terminu. Trwająca awaria oznacza `WaitingForFailureEnd`, a po jej zakończeniu termin wynosi siedem polskich dni roboczych od daty końca awarii; NEX-OMS nie czeka na zakończenie pierwotnej przerwy maintenance. Operational eligibility działa fail-closed również dla odwróconego okna Latarni `coverageFrom > coverageThrough`; równe granice nie są odrzucane wyłącznie z powodu równości.

Mini-etap nie zmienia migracji `086000`, issuance core, ochrony TOCTOU, transportu, delivery policy, QR, schedulera, projekcji Total Failure ani zakresu Korekt. Nie wykonuje live HTTP ani migracji na bazie operatora.

### KSeF.8C.6 — Ordinary Offline Corrections

Ograniczenia środowisk w tym etapie są historyczne, sprzed 8D.2; sukces LIVE opisany w końcowym wpisie dotyczy DEMO, nie PROD.

Wydaną Korektę biznesową można jawnie wystawić jako Offline24, Offline – niedostępność albo w trybie awaryjnym. Operacja zamyka Korektę atomowo, zamraża jej własny dokument FA(3) `KOR` i dwa QR; nie wysyła jej automatycznie. Błąd nie pozostawia zamkniętej Korekty bez issuance. Data wystawienia Korekty musi być dzisiejszym dniem w Polsce; historyczne P_1 źródła nie jest używane jako data nowej Korekty.

Faktura źródłowa Online/Offline i wymagane poprzednie Korekty muszą mieć jednoznaczny numer KSeF w aktywnym środowisku. Niezaakceptowane źródło Offline blokuje operację i ekran pokazuje lokalną przyczynę. Źródło legalnie oznaczone OutsideKsef jest obsługiwane przez `NrKSeFN`, bez numeru KSeF źródła. Zmiana referencji w czasie wystawiania powoduje odmowę bez częściowego zapisu. Po wystawieniu zamrożone dane nie zmieniają się wraz z danymi zamówienia, serii, source lub Korekty.

Wysyłka jest osobną ręczną akcją i używa dokładnie wystawionego XML, `offlineMode=true`, bez `hashOfCorrectedInvoice`. Po Accepted Korekta ma własny numer KSeF, UPO Offline i PDF z jednym KODEM I. Odrzuconej Korekty nie wysyła się ponownie w ciemno; remediation techniczna jest poza 8C.6.

Przed Accepted: Offline24 i planned dla krajowego nabywcy z NIP udostępniają potwierdzenie transakcji identyfikujące Korektę; dla pozostałych wspieranych nabywców PDF Korekty z KODAMI I/II i oznaczeniami OFFLINE/CERTYFIKAT. Tryb awaryjny dopuszcza ten PDF obu grupom. PDF pokazuje źródło, przyczynę, dane nabywcy przed/po, zmienione pozycje przed/po i różnice kwot na podstawie zamrożonego XML. Accepted zamyka trasy dokumentów przedakceptacyjnych.

Ekran edycji zachowuje istniejący układ i pokazuje jawne akcje Offline, potwierdzenie zamknięcia, procedurę, P_1, moment wystawienia, status i odpowiedni dokument do przekazania. Odczyt niczego nie wystawia i nie kontaktuje się z MF. Lista Korekt korzysta ze wspólnych terminów i oznaczeń obowiązku: Offline24 po P_1, planned po maintenance, Failure siedem dni roboczych po końcu awarii; awaria podczas maintenance ma pierwszeństwo. TotalFailure pozostaje projekcją obowiązku, bez nowego trybu wystawienia.

Implementacja kodowa nie zmieniała schedulera ani konfiguracji. Production pozostaje zablokowane; DEMO wspiera Offline24, nie planned/failure. Brak auto-send. Późniejsza migracja operatorowa, weryfikacja bazy oraz kontrolowany live test DEMO zostały zakończone i są opisane w końcowym wpisie tej sekcji. Kontrakt audytowy i dokładne źródła MF wraz z datami wersji opisuje sekcja KSeF.8C.6 w `docs/architecture.md`.

### KSeF.8C.6.2 — Frozen Correction Financial Evidence

Korekty Offline przechowują prywatny, zaszyfrowany i niezmienny zapis finansowy powstały w tym samym pojedynczym przebiegu mappera co autorytatywny XML. Usuwa to bloker 8C.6.1: XML KOR nie przechowuje osobnej kwoty VAT pozycji, więc nie pozwala jej dokładnie odtworzyć z netto i stawki. NEX zachowuje rzeczywisty VAT snapshotu, bez mnożenia netto przez stawkę i bez pobierania kursów NBP.

Zapis `version=1`, `profile=correction_financial` zawiera walutę, kwoty netto/VAT/brutto przed i po dla każdej zmienionej pozycji, dokładne koszyki podatkowe mappera, ich ewentualny VAT przeliczony na PLN (`P_14_*W`) oraz sumy różnic. Weryfikacja porównuje pary pozycji XML z zapisem, odbudowuje koszyki z exact VAT pozycji i sprawdza je wobec zapisanych koszyków oraz podsumowań XML i `P_15`. Zapis nie zastępuje treści PDF: źródłem prezentacji nadal jest zamrożony XML.

Zerowa łączna różnica w jednej stawce, korekta samej nazwy lub nabywcy oraz ujemne różnice są prawidłowe. Kanoniczny pusty koszyk nie ma odpowiadających mu pól podsumowania XML. Kontrola zachowuje różnice groszowe, zmiany między stawkami i dokładny VAT w PLN, bez ponownego przeliczania. Zmiana XML, brak lub uszkodzenie zapisu finansowego blokują prezentację i przygotowanie wysyłki; system nie zgaduje wartości i nie poprawia dokumentu w tle.

Schema-only migracja `2026_08_13_087000_add_ksef_offline_correction_financial_evidence.php` dodaje nullable `longText` `correction_financial_evidence`, odwzorowane w operatorowym SQLite jako nullable `TEXT`, bez backfillu lub modyfikacji istniejącego XML. Pole ma cast `encrypted:array`, jest ukryte w serializacji i podlega blokadom zmian/usunięcia issuance. Jest wymagane przed utworzeniem każdej nowej Korekty Offline24/planned/failure; zwykłe Faktury Offline zachowują `NULL`. Historyczna Korekta bez evidence jest blokowana, bez rekonstrukcji ze zmiennych danych. Read-only preflight 05.09.2026 potwierdził jeden OfflineIssuance Faktury i zero historycznych Korekt Offline.

Nie rozszerzono XML, QR ani UPO o evidence; nie jest ono wysyłane do KSeF ani pokazywane w treści PDF, UI lub logach. Nie zmieniono źródeł Korekt, ochrony TOCTOU, delivery, terminów, transportu exact frozen XML ani Korekt Online. Migracje operatora `079000–087000` mają status `Ran`; `087000` wykonano w batchu `53`, bez backfillu (`0` rekordów). Istniejący ordinary Offline Invoice zachował `correction_financial_evidence = NULL`.

Status po pełnej regresji kodu: `KSeF.8C.6.1: PASS / CLOSED via 8C.6.2`, `KSeF.8C.6.2: PASS / CLOSED`, `KSeF.8C.6 CODE: PASS / CLOSED`. Wszystkie testy przeszły: Unit KSeF 422 / 1412 asercji, Feature KSeF 927 / 6777, full suite 2240 / 13800. Potwierdzono zero-delta same bucket, przypadki groszowe `0.02 / 0.03`, cross-bucket `23% -> 8%`, `0 KR`, `0 WDT`, `0 EX`, foreign currency/P14W, buyer-only, korektę ujemną, tamper fail-closed oraz brak regresji zwykłych Faktur Offline i Korekt Online. Bloker 8C.6.1 rozwiązano przez frozen financial evidence, ponieważ `FaWiersz` KOR nie przechowuje niezależnej kwoty VAT pozycji i exact VAT nie może być bezpiecznie odtworzony z `P_11` i `P_12`.

### KSeF.8C.6 — FINAL CLOSED

Poniższe statusy i blokada Production opisują zamknięcie 8C.6 przed 8D.2. Nie są ponowną oceną obecnej polityki środowisk.

Migracja `087000` ma na operatorowej bazie status `Ran` w batchu `53`; kolumna `ksef_offline_issuances.correction_financial_evidence` ma typ SQLite `TEXT`, `nullable=YES`, a backfill wyniósł `0`. `KSeF.8C.6-DB-VERIFY-001` jest `FAIL / superseded`: podczas pierwszego read-only verify inne procesy PHP zmieniały plik SQLite, przez co zmieniał się hash całej bazy; nie wykryto błędu danych KSeF. `KSeF.8C.6-DB-VERIFY-002` zakończył się `PASS / CLOSED` po zatrzymaniu procesów `php/php-cgi/httpd`, bez WAL/SHM/journal, przy SQLite `READONLY`, `PRAGMA query_only=1`, `quick_check=ok` przed i po oraz `T0=T1=T2=FINAL`. Stabilny plik miał `2502656` bajtów i SHA-256 `87789B48D5D0FAFD3AF7676343FCD2054348A250FBB42EB25FD76460549C026E`.

Historyczny OfflineIssuance `id=1` dla Faktury `invoice_id=123`, `document_type=invoice`, pozostał w DEMO, procedurze `offline24` i schemacie `FA (3) 1-0E`; payload miał `1642` bajty, a evidence pozostało `NULL`. Decrypt/hash/size, presentation i integralność submissionu przeszły weryfikację. Nowa Korekta Offline wymaga evidence; zapis jest szyfrowany jako `encrypted:array`, ukryty w serializacji i niezmienny razem z issuance. XML i evidence powstają z jednego przebiegu mappera, a exact VAT pochodzi ze snapshotu, nie z mnożenia netto przez stawkę. Evidence nie trafia do FA(3), API KSeF, QR, UPO, treści PDF, UI ani logów. KOR bez evidence jest blokowany fail-closed; brak backfillu danych historycznych.

`KSeF.8C.6-LIVE-AUTH-PREFLIGHT-001/002` są `BLOCKED / superseded`, ponieważ puste `personal/grants` nie rozstrzygało samo w sobie efektywnego `InvoiceWrite`. `KSeF.8C.6-LIVE-AUTH-PREFLIGHT-003` zakończył się `PASS / CLOSED` na podstawie baseline'ów CIRFMF/ksef-api `93b843d5def041f69fe2a26d0d90a53e9fa9987a` i CIRFMF/ksef-client-csharp `04f01c1c7834336a3aef1804149cd5bcbd883a3e`. DEMO ContextToken miał `typ=ContextToken`, `cit=Nip`, zgodne `civ=6282192260`, `per=Owner` oraz puste `rol` i `pep`. Owner w `per` dla poprawnego tokenu kontekstu i zgodnego NIP-u potwierdza efektywne prawa fakturowe, w tym `InvoiceWrite`; puste `personal/grants` jest legalne dla Ownera. Nie zapisano JWT ani sekretów.

`KSeF.8C.6-LIVE-TEST-001-R2` jest `BLOCKED / superseded` wyłącznie przez `WARSAW_MIDNIGHT_SAFETY_WINDOW`; test zatrzymano przed zapisami biznesowymi, live HTTP, Korektą, issuance i submissionem. Końcowy `KSeF.8C.6-LIVE-TEST-001-R3` z 06.09.2026 zakończył się `PASS / CLOSED` dla DEMO Offline24. Root `BLF 80/2026` (`Invoice id=123`, accepted submission `24`, PLN, tryb `Offline`) zachował numer KSeF `6282192260-20260903-7B7860400000-FF` i pozostał niezmieniony.

Utworzono i atomowo zamknięto Korektę `BLKF 3/2026` (`id=124`, root `123`) z powodem „podwyższenie ceny po wystawieniu faktury”. Cena jednostkowa brutto zmieniła się `123.00 -> 124.00 PLN`, a delta wyniosła netto `0.81`, VAT `0.19`, brutto `1.00 PLN`. Offline issuance `id=2` ma środowisko DEMO, procedurę `offline24`, `P_1=2026-09-06`, schemat `FA (3) 1-0E` i payload `1994` bajty. Frozen XML, financial evidence, KOD I, KOD II oraz dokładna referencja do KSeF root przeszły weryfikację.

Submission `id=25`, attempt `1`, wysłał exact frozen XML przez dokładnie jeden invoice POST z `offlineMode=true` i bez `hashOfCorrectedInvoice`. Wykonano jeden status GET, bez retry i resend. Korekta została `accepted` w trybie `Offline` i otrzymała własny numer KSeF `6282192260-20260906-0277D5800000-93`; numer root pozostał niezmieniony. Finalny PDF przeszedł weryfikację, zawiera jeden KOD I i nie zawiera KODU II, a pre-acceptance delivery zostało zamknięte. UPO `id=24` pobrano przy pierwszej próbie; `TrybWysylki=Offline`, numer KSeF, hash i referencja są poprawne.

Ruch sieciowy R3 obejmował wyłącznie oczekiwane DEMO auth/session/invoice/status/UPO: invoice POST `1`, status GET `1`, UPO GET `1`. TEST, PRODUCTION, Latarnia, NBP, QR HTTP oraz permission endpoints: `0`. Nowy baseline operatora: invoices `48`, orders `106`, offline issuances `2`, KSeF submissions `25`, UPO `24`, Latarnia messages `3`, Latarnia states `1`, automation runs `67`, order events `1007`. Przyrosty to jedna Korekta, jeden OfflineIssuance, jeden submission, jedno UPO i jedno legalne zdarzenie lifecycle, nie efekt migracji. Zachowano prywatny audytowy backup `storage/app/private/backups/database_before_ksef_8c6_live_001_r3_20260906_000233.sqlite`, SHA-256 `92D3E73821514EB9E080047631199FA2C2BA07C51C173C287877151A1F1DD089`.

Status końcowy: `KSeF.8C.6.1: PASS / CLOSED`, `KSeF.8C.6.2: PASS / CLOSED`, `KSeF.8C.6 migration 087000: PASS / CLOSED`, `KSeF.8C.6-DB-VERIFY-002: PASS / CLOSED`, `KSeF.8C.6-LIVE-AUTH-PREFLIGHT-003: PASS / CLOSED`, `KSeF.8C.6-LIVE-TEST-001: PASS / CLOSED` oraz `KSeF.8C.6: CODE + MIGRATION + DB VERIFY + LIVE DEMO E2E PASS / CLOSED`. Wynik dotyczy DEMO; Production pozostaje zablokowane przez operational environment policy. Korekta biznesowa 8C.6 zachowuje `offlineMode=true` i brak `hashOfCorrectedInvoice`.

### KSeF.8C.7-R1 — Narrow Technical Correction after rejected Offline Invoice

R1 i jego wpisy hardeningowe opisują historyczny zakres VAT: KOR odroczono do R2, a Production do 8D.2. Zachowane wyniki TEST nie są dowodami PROD.

Operator może ręcznie przygotować korektę techniczną wyłącznie dla zwykłej Faktury VAT wystawionej Offline, której submission zakończył się `Rejected`, nie otrzymał numeru KSeF i ma oficjalny kod kwalifikujący. Kody `440` i `450` są kwalifikujące, `410` jest niekwalifikujący, a pozostałe lub brak kodu dają nieznany wynik i bezpieczną blokadę. Treść komunikatu MF nie jest analizowana. Korekty `KOR` pozostają poza tym wąskim etapem.

Przygotowanie nie wysyła danych. System zachowuje źródłowy OfflineIssuance, odrzucony XML, hash i QR bez zmian, sprawdza ich integralność i tworzy osobny, zaszyfrowany, niezmienny artefakt. Nowy FA(3) jest generowany z zamkniętego snapshotu Faktury przez aktualny autorytatywny generator: ma P_1 i P_2 Faktury, przechodzi aktualny XSD, posiada nowy hash i wskazuje hash odrzuconego dokumentu. Stary XML nie jest wymagany do przejścia nowszego XSD.

Transmisja jest drugim, osobnym poleceniem. Używa sesji interaktywnej, dokładnie zamrożonych bajtów nowego FA(3), `offlineMode=true` i `hashOfCorrectedInvoice` ze starej próby. Dla jednego odrzucenia istnieje najwyżej jeden artefakt i jedna techniczna transmisja; brak automatycznego retry. `21166` i `21167` kończą się jako `TechnicalFailed`, a niejednoznaczny błąd sieci jako `Uncertain` do reconciliation. Dotychczasowa blokada zwykłego resend pozostaje aktywna.

Accepted korekta techniczna otrzymuje własny numer KSeF. Jej PDF powstaje z nowego zamrożonego XML, zawiera jeden KOD I i nie zawiera KODU II; UPO oraz fulfillment obowiązku odnoszą się do technicznego submissionu i nowego hasha. Odczyt ekranu niczego nie generuje ani nie wysyła. Brak auto-send, listenerów, kolejek i zmian schedulera; Production pozostaje zablokowane.

Migracja `2026_08_13_088000_create_ksef_offline_technical_corrections.php` dodaje prywatną tabelę artefaktów i nullable, unikalne powiązanie submissionu. W chwili implementacji R1 pozostawała `Pending` na bazie operatora, a ten etap nie wykonywał migracji, live HTTP ani transmisji. Późniejsze wykonanie operatorowe opisuje sekcja zamknięcia wdrożeniowego poniżej. Oficjalny kontrakt zweryfikowano wobec CIRFMF/ksef-api `93b843d5def041f69fe2a26d0d90a53e9fa9987a` i CIRFMF/ksef-client-csharp `04f01c1c7834336a3aef1804149cd5bcbd883a3e`.

### KSeF.8C.7-R1.1 — Technical Correction hardening

Zwykła transmisja Offline ma `offline_technical_correction_id = null`, a transmisja korekty technicznej ma ten identyfikator ustawiony. Po rozpoczęciu technicznego remediation zwykły resend pozostaje niedostępny niezależnie od dalszego statusu. `TechnicalFailed` i techniczne `Rejected` oznaczają ręczną analizę: interfejs nie oferuje ani ponownej transmisji zwykłego Offline, ani drugiej korekty technicznej. Tę samą regułę egzekwuje backend przed jakimkolwiek HTTP.

Artefakt zamraża kod odrzucenia i wersję polityki kwalifikacji. Polityka V1 ma niezmienne znaczenie: `440` i `450` są kwalifikujące, `410` niekwalifikujący, a pozostałe wartości są nieznane. Późniejsza zmiana bieżącej polityki nie zmienia legalności historycznego artefaktu V1; niespójny kod źródła albo nieznana wersja blokują operację.

Biznesowy fingerprint V1 to `Base64(SHA-256(canonical JSON))`. Niezależna projekcja snapshotu Faktury musi być identyczna z projekcją technicznego FA(3). Obejmuje cały biznesowy zakres zwykłego mappera: rodzaj dokumentu, P_1/P_2, sprzedawcę, nabywcę, odbiorcę, walutę, datę sprzedaży i miejsce wystawienia, linie z ilością/ceną/netto/stawką VAT/GTU, podsumowania netto/VAT/P15 (w tym VAT w PLN), adnotacje, rejestry, informacje dodatkowe, płatność, rachunek oraz referencję zamówienia. Dane liczbowe są kanonicznymi stringami dziesiętnymi, bez `float`. Jedynym wyłączeniem jest techniczne `Naglowek/DataWytworzeniaFa`; P_1 i P_2 pozostają chronione. Parser blokuje `DOCTYPE` i używa `LIBXML_NONET`.

PREPARE wymaga równości fingerprintu Faktury i nowego payloadu, a `assertArtifact()` utrzymuje trójstronny invariant: immutable Invoice snapshot = frozen XML = stored fingerprint. Historyczna kontrola nie regeneruje XML bieżącym generatorem i nie korzysta z Order, Customer, Address ani Product. Na etapie R1.1 wówczas niewykonana migracja `088000` zawierała już `source_status_code`, `eligibility_policy_version`, `business_fingerprint` oraz `business_fingerprint_version`; `089000` nie powstała. KOR parity, live HTTP, auto-send, retry i Production są nadal odroczone.

### KSeF.8C.7-R1.2 — Frozen Business Projection V1

Fingerprint historycznej korekty technicznej nie zależy już od bieżącego mappera FA(3). Jawna `KsefOfflineTechnicalCorrectionInvoiceBusinessProjectionV1` czyta wyłącznie rekord Faktury, jej pozycje oraz zamrożone snapshoty sprzedawcy, nabywcy, odbiorcy, wystawienia, płatności, zamówienia i podatków. Aktualne Order, Customer, Address, Product, seria, konfiguracja KSeF i kurs NBP nie są źródłami. Opcje `ksef_document` v1/v2, legacy bez tego snapshotu, `ksef_payment` v1 i historyczne mapowanie płatności zachowują znaczenie z baseline R1.

V1 obejmuje pełną biznesową zawartość zwykłej Faktury FA(3): strony i adresy, PrefiksPodatnika, P_1/P_1M/P_2/P_6, walutę, pozycje, wszystkie koszyki VAT i P_14_*W, P_15, adnotacje, kontakt, odbiorcę, opisy, płatność, bank, zamówienie, GTU, REGON i BDO. Kwoty są kanonizowane do 2 miejsc, ilości do 4 miejsc bez zbędnych zer, bez `float`; wspierane są `0 KR`, `0 WDT` i `0 EX`. Jedynym wyłączeniem jest generowany technicznie `Naglowek/DataWytworzeniaFa`. PREPARE nadal korzysta z aktualnego generatora i wymaga zgodności z frozen Projection V1, lecz historyczny `assertArtifact()` nie wywołuje mappera, resolvera opcji ani generatora. Przyszła zmiana semantyki wymaga Projection V2; V1 nie może być reinterpretowana.

### KSeF.8C.7 — Technical Correction R1 deployment closure

Statusy odroczeń KOR i blokady Production poniżej należą do chwili zamknięcia R1; późniejsze wdrożenia opisano w R2 i 8D.2.

Implementacja korekty technicznej R1 dla zwykłej Faktury VAT jest kompletna, utwardzona przez R1.1 i oparta na frozen Invoice Business Projection V1 z R1.2. Obsługuje wyłącznie odrzuconą Fakturę Offline bez numeru KSeF: kody `440` i `450` są kwalifikujące, `410` niekwalifikujący, a inne wartości lub brak kodu kończą się bezpieczną blokadą. Źródłowe wystawienie, submission, XML, hash, KOD I i KOD II są niezmienne; osobny artefakt techniczny otrzymuje nowy XML i hash, a `hashOfCorrectedInvoice` wskazuje pierwotny odrzucony hash. Fingerprint `Base64(SHA-256(canonical JSON))` chroni frozen Business Projection V1 niezależnie od bieżącego mappera; tylko `Naglowek/DataWytworzeniaFa` jest wyłączeniem technicznym, natomiast P_1 i P_2 pozostają chronione.

Pierwotna kontrola `KSeF.8C.7-MIGRATION-088-VERIFY-001` została zatrzymana przez niejednoznaczny wynik rekonstrukcji tabeli w SQLite/Laravel `--pretend`; wynik jest `SUPERSEDED / INCONCLUSIVE` i nie oznacza błędu kodu. Rzeczywista migracja na dokładnej jednorazowej kopii operatorowej w `KSeF.8C.7-MIGRATION-088-DRY-RUN-COPY-001` zakończyła się `PASS / CLOSED`, zachowując 26 submissionów, wszystkie dane historyczne, FK, indeksy i integralność bazy.

`KSeF.8C.7-MIGRATION-088-OPERATOR-001` wykonał migrację `088000` na bazie operatora 7 września 2026 o `2026-09-07T07:43:01Z`: wynik `PASS`, batch `54`, liczba migracji `80 -> 81`. Submissiony zmieniły schemat z 39 do 40 kolumn wyłącznie przez nullable `offline_technical_correction_id` z FK `RESTRICT` i unikalnym indeksem; wszystkie 26 historycznych wartości pozostały `NULL`. Utworzona tabela `ksef_offline_technical_corrections` ma 19 oczekiwanych kolumn i początkowo 0 rekordów, bez backfillu. Operatorowy DB verify potwierdził `quick_check=ok`, `foreign_key_check=0` oraz zachowanie wszystkich historycznych danych, liczników i digestów PRE/POST w ramach tego samego przebiegu; nie zakłada się równoważności digestów między różnymi przebiegami lub metodami serializacji. Dokładne dowody i migration-time counts znajdują się w `architecture.md`.

Zachowano prywatną, bitowo zgodną kopię PRE `storage/app/private/backups/database_before_ksef_8c7_088_operator_001_20260907_094207.sqlite`, SHA-256 `3FA32DF724B5E99911035B9C3650CD8A8348E9BDFCC5ABEC999A138A26906E09`, z `quick_check=ok` i `foreign_key_check=0`.

Migracja wykonała 0 zapisów aplikacyjnych lub biznesowych i nie utworzyła Faktury, OfflineIssuance, artefaktu technicznego, submissionu ani UPO. Ruch KSeF TEST/DEMO/PROD, Latarnia, NBP i QR HTTP wynosił `0`. Transport R1 nadal dopuszcza dokładnie jeden artefakt i jeden techniczny invoice POST w sesji interaktywnej z `offlineMode=true`; `21166/21167` dają `TechnicalFailed`, a wynik niepewny wymaga reconciliation bez blind retry.

Status, bez zamykania całego KSeF.8C.7:

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

To historyczny wynik LIVE TEST R1. Ówczesne `KOR DEFERRED` i `Production: BLOCKED` nie opisują obecnego kodu po R2/8D.2.

`KSeF.8C.7-LIVE-TECHNICAL-CORRECTION-TEST-001-R2` zakończył się `PASS / CLOSED` w środowisku TEST. Po wcześniejszej próbie zatrzymanej bez invoice POST z powodu niegotowego certyfikatu Offline, osobny test readiness potwierdził gotowość certyfikatu TEST. Następnie syntetyczna zwykła Faktura VAT Offline przeszła rzeczywisty lifecycle MF: źródłowy submission `27` został odrzucony kodem `450` bez numeru KSeF, a osobny immutable artefakt techniczny `1` został wysłany jako submission `28` i zaakceptowany kodem `200` z numerem KSeF `6282192260-20260907-5A9BF1000000-98`.

Wykonano dokładnie dwa invoice POST-y: jeden dla źródła i jeden dla korekty technicznej, bez resend, blind retry i trzeciej próby. Techniczny hash różnił się od hasha odrzuconego źródła, a `hashOfCorrectedInvoice` był dokładnie równy hashowi tego źródła. UPO `26` należy wyłącznie do Accepted submissionu `28`; źródłowy submission nie ma UPO. Accepted PDF został wygenerowany z zamrożonego technicznego payloadu, zawiera jeden KOD I i zero KODU II. Obowiązek Offline ma stan `FULFILLED`, a resolver źródła przyszłej zwykłej korekty biznesowej wskazuje zaakceptowany submission techniczny. Nie utworzono Korekty `KOR`.

Opcjonalny pojedynczy GET zaakceptowanego XML zakończył się `HTTP 406`, ponieważ dostępna autoryzacja nie zapewniła wymaganego `InvoiceRead`. Nie wykonano retry ani niezależnej weryfikacji hasha zdalnie pobranego XML; jest to jawne, nieblokujące ograniczenie dodatkowej kontroli, poza rdzeniem dowodu `450 -> 200`. Test nie wykonał requestów DEMO ani Production. Po przebiegu przywrócono konfigurację DEMO i `automatic_submission=true`, zachowano wybory certyfikatów Offline TEST/DEMO, a syntetyczną serię wyłączono dla dalszych transmisji KSeF.

Implementacja, migracja `088000`, wdrożenie DB i LIVE TEST są kompletne dla wąskiego R1 obejmującego zwykłą Fakturę VAT. Wynik nie rozszerza zakresu na kod `440`, parity technicznej korekty `KOR` ani Production: `KOR technical parity: DEFERRED`, `Production: BLOCKED`.

### KSeF.8C.7-R2 — Technical Correction parity for Offline KOR

Poniższe wyniki i blokada Production opisują stan R2 sprzed 8D.2. Dowód LIVE KOR pozostaje przypisany wyłącznie do TEST.

Późniejszy R2 zamyka historyczne odroczenie KOR z R1 bez zmiany znaczenia R1 dla zwykłej Faktury VAT. Etapy implementacyjne osiągnęły kolejno: `KSeF.8C.7-R2A` Contract Audit — `PASS`; `R2B` Core Infrastructure — `PASS / PUBLISHED`; `R2C` Frozen KOR Business Projection V2 — `PASS / PUBLISHED`; `R2D` hardening, fake transport, downstream i UI — `PASS / PUBLISHED`. Następnie `KSeF.8C.7-R2-LIVE-PREFLIGHT-001` zakończył się `PASS`, `KSeF.8C.7-R2-LIVE-TEST-001` — `PASS / CLOSED`, a niezależny `KSeF.8C.7-R2-LIVE-CLOSURE-AUDIT-001` — `PASS`; po zamknięciu dokumentacyjnym formalny stan R2A-R2D jest `PASS / CLOSED`. Oficjalny mechanizm MF jest ogólny dla dokumentów wystawionych Offline i nie zawiera wyłączenia `RodzajFaktury=KOR`; nie było jednak dedykowanego referencyjnego KOR E2E od MF, dlatego wdrożenie o statusie `SUPPORTED_BY_GENERIC_OFFICIAL_CONTRACT` wymagało rzeczywistej walidacji KSeF TEST.

Operator może użyć tego samego dwuetapowego workflow dla kwalifikującej się odrzuconej zwykłej Faktury VAT i Korekty KOR. `PREPARE` tworzy lokalny, niezmienny artefakt bez HTTP, a osobne `SEND` przekazuje dokładnie ten artefakt. Pro forma pozostaje nieobsługiwana. Akcje są dostępne tylko przy zgodności aktywnego środowiska i kontekstu KSeF ze źródłowym OfflineIssuance. Po rozpoczęciu remediation zwykły resend jest zablokowany, a po utworzeniu technicznego submissionu UI nie oferuje kolejnego PREPARE/SEND niezależnie od wyniku `Accepted`, `Rejected`, `TechnicalFailed` lub `Uncertain`. Nie ma automatycznego retry ani automatycznego attemptu 3; `Uncertain` dopuszcza wyłącznie reconciliation.

Nie zmieniła się architektura dokumentów. Zwykła Faktura VAT używa niezmiennego `business_fingerprint_version=1`, natomiast KOR używa `business_fingerprint_version=2`; V2 nie reinterpretuje V1. Dla KOR system porównuje niezależną projekcję zamrożonego stanu biznesowego z projekcją technicznego FA(3), zachowując P_1, P_2, strony, powód, pozycje przed/po, koszyki VAT, P_15 i wartości VAT w PLN. Wyłącznie techniczne `Naglowek/DataWytworzeniaFa` może się zmienić. Source OfflineIssuance pozostaje właścicielem zaszyfrowanego `correction_financial_evidence`; artefakt techniczny go nie duplikuje. Ten sam walidator evidence zabezpiecza prezentację źródła i korektę techniczną, a remediation nie pobiera nowego kursu NBP.

Biznesowa referencja `DaneFaKorygowanej` nadal wskazuje root/korygowaną Fakturę i jej numer KSeF. Oddzielna relacja transportowa `hashOfCorrectedInvoice` wskazuje dokładny hash odrzuconego payloadu KOR. Techniczny request używa `offlineMode=true`, własnego `invoiceHash` i źródłowego `hashOfCorrectedInvoice`. Accepted techniczny KOR korzysta ze wspólnego lifecycle statusu, UPO i obowiązku Offline. Jego PDF jest generowany z zamrożonego artefaktu, zawiera jeden KOD I i nie zawiera KODU II. Odrzucone źródło zachowuje własne KODY I/II i hash; akceptacja techniczna ich nie nadpisuje.

Kontrolowany LIVE na KSeF TEST użył root `Invoice 126`, `K7T 1/2026`, PLN, wcześniej zaakceptowanego przez techniczną korektę R1, oraz biznesowego KOR `Invoice 127`, `K7R2 1/2026`, z powodem „podwyższenie ceny po wystawieniu faktury” i deltą `net +0.81 PLN`, `VAT +0.19 PLN`, `gross +1.00 PLN`. Dla testowego OfflineIssuance `4` celowy jednorazowy harness pozostawił modelowe P_1 `2026-09-08`, lecz w źródłowym, nadal XSD-valid payloadzie ustawił P_1 `2026-09-09`, emulując dawny błąd generatora. Nie jest to funkcja dostępna w zwykłym workflow.

Macierz zachowanych prób jest jednoznaczna:

```text
Attempt 1: source KOR, submission 29, Rejected, 450,
           KSeF number NULL,
           source hash k2my5FRiXJ8qg3J2IK8JjFvZK5zgpjhDK4+698C1iYY=
Attempt 2: technical KOR, artifact 2, fingerprint version 2,
           submission 30, Accepted, 200, invoicing mode Offline,
           KSeF number 6282192260-20260908-8A2CF1000000-50,
           technical hash akU2wVABbC+CsK7+ilbrcWlNCNrKBNbgH35qPdPf7bc=,
           hashOfCorrectedInvoice = Attempt 1 hash
Attempt 3: ABSENT
```

Techniczny XML zachował `P_1=2026-09-08`, `P_2=K7R2 1/2026` i `RodzajFaktury=KOR`; XSD, financial evidence oraz trójstronny fingerprint `DOMAIN = STORED ARTIFACT = TECHNICAL XML` zakończyły się `PASS`. Wykonano jeden źródłowy i jeden techniczny invoice POST, łącznie `2`, bez retry. Źródłowy submission nie ma UPO; Accepted submission `30` ma zapisane UPO `id=27`, które przeszło lokalną walidację. Accepted PDF z frozen artefaktu przeszedł weryfikację, ma KOD I `1` i KOD II `0`; dynamiczne metadane TCPDF nie wymagają identyczności całych bajtów przy osobnych generacjach. Obowiązek Offline ma stan `FULFILLED` dzięki submissionowi `30`.

Po teście przywrócono aktywną konfigurację DEMO z `automatic_submission=true`, a serię Korekt `id=9` wyłączono i oznaczono jako nieaktywną. Późniejszy read-only closure audit niezależnie uzgodnił raport LIVE, bazę operatora, oba payloady i hashe, V2 fingerprint, financial evidence, statusy, UPO, Accepted PDF, QR, obowiązek oraz konfigurację i zakończył się `PASS` bez zapisów i bez HTTP. Dowody pozostają prywatnie w `storage/app/private/backups/ksef_8c7_r2_live_001_report_20260908_193853.json` i `storage/app/private/backups/database_before_ksef_8c7_r2_live_001_20260908_191027.sqlite`; nie są zależnością działania OMS. Ponowna próba tego samego scenariusza root `126` została oczekiwanie zablokowana jako `ROOT_126_CORRECTION_CHAIN_CHANGED`, ponieważ KOR `127` już istnieje; nie zmienia to wyniku pierwotnego LIVE.

Zakres dowodu jest ograniczony do KSeF TEST i rzeczywistego kodu odrzucenia `450`. Kod `440` jest wspierany przez politykę i testy, lecz nie był testowany LIVE. `21166`, `21167` oraz `Uncertain/reconciliation` mają pokrycie regresyjne/fake-only i nie wystąpiły w tym LIVE. Accepted XML nie został niezależnie pobrany. Pro forma pozostaje nieobsługiwana, Production jest `BLOCKED`, a wynik nie stanowi certyfikacji ani zatwierdzenia przez MF.

```text
KSeF.8C.7-R2A: PASS / CLOSED
KSeF.8C.7-R2B: PASS / CLOSED
KSeF.8C.7-R2C: PASS / CLOSED
KSeF.8C.7-R2D: PASS / CLOSED
KSeF.8C.7-R2-LIVE-PREFLIGHT-001: PASS
KSeF.8C.7-R2-LIVE-TEST-001: PASS / CLOSED
KSeF.8C.7-R2-LIVE-CLOSURE-AUDIT-001: PASS
KSeF.8C.7-R2: PASS / CLOSED / DOCUMENTED
KSeF.8C.7 ordinary Invoice VAT technical correction: PASS / CLOSED
KSeF.8C.7 KOR technical correction: PASS / CLOSED
KSeF.8C.7 KOR TECHNICAL PARITY: PASS / CLOSED / DOCUMENTED
KSeF.8C.7: PASS / CLOSED FOR VAT + KOR TECHNICAL CORRECTION SCOPE
Pro forma: UNSUPPORTED
Production: BLOCKED
```

Na karcie zamówienia zaakceptowana Faktura jest oznaczona jako `KSeF: <numer OMS>`. Kliknięcie pobiera autorytatywny XML Faktury z jej zamrożonego środowiska KSeF, weryfikuje hash odpowiedzi i uruchamia pobranie PDF wygenerowanego lokalnie przez oficjalny generator MF. XML źródłowy nie jest utrwalany ponownie w bazie.

Źródłem prawdy pozostaje `ksef_invoice_submissions`; Faktura nie otrzymuje osobnej kolumny statusu. Lista Faktur pokazuje kompaktowy status, a historia pozostaje dostępna przy wyłączonym deployment gate. Historyczne 4B.1 było TEST-only i nie miało wykonawcy `automatic_submission`, retry, reconciliation, UPO, QR ani Offline. Nie opisuje to obecnego zakresu: automatykę wdrożono w 6G (sekcja 30.11), a wspólną obsługę Production w 8D.2. Gate `KSEF_INVOICE_SUBMISSION_ENABLED` nadal domyślnie wynosi `false`.

### KSeF.8D — Production: stan wdrożenia i dowód ręcznego Online

`KSeF.8D-DOC-CLOSURE-001: PASS`. Opis jest aktualny dla udokumentowanego zakresu 8D.1–8D.5.1 na `main`, HEAD `96f033963e80514c0c4f5747c455844c632c910f`. Nie zamyka całego 8D ani rollout'u bez nadzoru. Szczegółowe metadane czterech zachowanych raportów i lokalnie sprawdzone SHA-256 znajdują się w sekcji 8D `docs/architecture.md`; raporty pozostały prywatne i nie były zmieniane.

#### Chronologia i siła dowodu

| Etap | Udokumentowany wynik |
| --- | --- |
| 8D.1 | `AUDIT COMPLETED / FINDINGS RESOLVED BY 8D.2`. Historyczny audyt zakończył się `BLOCKED / CURRENT_ARCHITECTURE_ENVIRONMENT_LEAK`: rozproszone ograniczenia TEST/DEMO i zależność PDF od `APP_ENV`. Nie był potrzebny osobny stos PROD. |
| 8D.2 | `PASS / SHARED PRODUCTION CODE / PUBLISHED`, commit `b6ea62547263b4924f05e3ccd7937cce24687492`; rozwiązanie findings: `CODE VERIFIED`. |
| 8D.3 | `CERTIFICATE AUTH + INVOICEWRITE + OFFLINE READINESS VERIFIED`, według udanego przebiegu Certificate z 10.09.2026, raport 184005 (`PRESERVED LIVE REPORT`). Wcześniejszy raport 155304 dotyczy nieudanego Token auth. |
| 8D.4 | `MANUAL PATH VERIFIED`; historyczny `BLOCKED / BACKUP_RECOVERY_PLAN_INCOMPLETE` zachowany. Później `RECOVERY BACKUP CONFIRMED BY OPERATOR`; `RESTORE TEST NOT PERFORMED`. Bez fikcyjnego rerunu z PASS. |
| 8D.5 | `PASS / ONE REAL VAT INVOICE ONLINE ACCEPTED IN PROD`, 11.09.2026; `PRESERVED LIVE REPORT`. |
| 8D.5.1 | `PASS / WARSAW TIME PRESENTATION / CACHE V46 / PUBLISHED`, commit `96f033963e80514c0c4f5747c455844c632c910f`; `CODE VERIFIED`. |

`LOCAL TEST REPORT` (wcześniejsze wyniki Codexa, nie nowe testy closure ani GitHub CI): 8D.2 ma 2459 testów / 16311 asercji, 8D.5.1 ma 2471 / 16557; zgłoszono zero failures/errors/skips i Pint/lint/diff-check PASS. `OPERATOR CONFIRMED` nie oznacza niezależnej kontroli, a `NOT VERIFIED / NOT RUN` jawnie wskazuje niewykonaną czynność. Closure nie prowadzi nowego audytu MF; wcześniejsze rewizje oficjalnych źródeł pozostają baseline'ami swoich etapów.

#### Środowisko i uprawnienia

Production korzysta z tych samych serwisów co TEST/DEMO: centralnej operational policy, dispatchera follow-up, integralności i prezentacji Offline, presentera PDF oraz polityki zdalnej weryfikacji certyfikatów. Dostępność akcji certyfikatów wynika z backendu. Nie dodano osobnego transportu ani migracji. Pozostaje jeden gate `KSEF_INVOICE_SUBMISSION_ENABLED`, bez `KSEF_PRODUCTION_SUBMISSION_ENABLED` lub drugiego odpowiednika.

`APP_ENV` jest profilem Laravela, nie wyborem serwera KSeF. `KsefSetting.environment` określa środowisko nowych operacji i filtr ogólnego presentera PDF; historyczne submission/issuance/artifact zachowują własne środowisko. Credentiale, runtime tokeny i certyfikaty są rozdzielone, bez kopiowania DEMO do PROD. Zmiana ustawień nie przepina oczekującego first-send; follow-up, reconciliation i UPO używają środowiska zapisanej próby. Ogólny presenter nie wybiera dowolnego historycznego Accepted: obowiązuje dokładny filtr, bez cross-environment fallback.

Wcześniejszy Token auth z raportu 155304 zakończył się terminalnym **AUTH 450**, bez access tokena i z `InvoiceWrite UNKNOWN`; to nie odrzucenie Faktury. Późniejszy Certificate PASS nie wyjaśnia szczegółowej przyczyny ani nie dowodzi naprawienia starego lub przetestowania nowego Tokena. Oba raporty zachowują historyczny identyfikator `001`, a przebiegi rozróżnia czas i metoda.

Certificate/XAdES PROD użył credentiala `2`, EC P-256. Odczytany ContextToken miał właściwy typ, kontekst NIP `6282192260`, Owner i brak wyłączenia InvoiceWrite (`pec=[]`); puste `rol/pep` nie były odmową i nie wymagały dodatkowego query. Sam napis Owner nie wystarcza bez właściwego kontekstu i oceny wyłączeń. JWT odczytano lokalnie bez osobnej kryptograficznej weryfikacji podpisu, a provenance wynikało ze świeżego flow PROD z weryfikowanym TLS. Osobny preferowany Offline PROD `3` miał poprawny materiał/parę kluczy Non-Repudiation, remote Active i identity match: READY w chwili kontroli. Sześć requestów PROD tego udanego przebiegu obejmowało auth i query/retrieve certyfikatu, bez sesji fakturowej i invoice POST. To nie bezterminowa gwarancja ani dowód emisji Offline PROD.

#### Jedna rzeczywista Faktura i ręczna obsługa

11.09.2026 `KSeF.8D.5-PROD-K7T-2-2026-001` potwierdził ręczny Online: Invoice `128`, `K7T 2/2026`, Order `115`, seria `8` (`KSEF-8C7-TECH-LIVE-20260907105305`), data wystawienia 11.09.2026, netto `0,81 PLN`, VAT `0,19 PLN`, brutto `1,00 PLN`. Seria była wtedy aktywna i włączona do KSeF; w przebiegu nie zmieniano jej konfiguracji. Rzeczywistą sprzedaż i brak duplikatu poza OMS potwierdził operator, bez niezależnego audytu innych systemów. Nie był to syntetyczny fixture.

Submission `31`, attempt `1`, environment `production`, `Online`, `Accepted / 200`, numer KSeF `6282192260-20260911-428158C00000-96`; UPO `28` należy do tej próby. Payload `1625 bytes` przeszedł FA(3)/XSD i kontrolę integralności; dokładny hash zawiera opis 8D.5 w architekturze. Request użył `offlineMode=false`, bez `hashOfCorrectedInvoice`. Jeden invoice POST, bez retry, drugiej próby, emisji Offline, artefaktu technicznego, celowego 450 i nowej fikcyjnej transakcji. Dane biznesowe nie zmieniły się; finalizacja zmieniła wyłącznie `finalized_at` i `updated_at` dokumentu.

Siedem requestów PROD raportu 8D.5 to po jednym: refresh, publiczny klucz, otwarcie sesji, invoice POST, zamknięcie sesji, status i UPO. Pełne auth, reconciliation, retry invoice POST, TEST/DEMO, NBP, Latarnia, QR HTTP oraz Accepted XML GET: `0`. To osobny przebieg od sześciu requestów udanego 8D.3. Ręczne wysłanie, sprawdzenie/uzgodnienie wyniku, pobranie UPO i PDF są dostępne bez workera; samo reconciliation nie było potrzebne w tym LIVE.

UPO przeszło hash, XSD, tożsamość i kontrolę obecności podpisu, **nie** kryptograficzną weryfikację podpisu. Historyczny PDF `storage/app/private/invoices/128/invoice-v45-ksef-production.pdf` przeszedł kontrolę wizualną: właściwy numer i kwoty, numer KSeF, jeden KOD I na `qr.ksef.mf.gov.pl`, bez KODU II i oznaczeń TEST/DEMO. Skan telefonem, niezależne pobranie Accepted XML i porównanie jego hasha: `NOT VERIFIED / NOT RUN`.

#### Czas PDF i bezpieczeństwo wznowienia

Historyczny LIVE PDF pokazywał `11.09.2026 07:27:30` UTC bez strefy. 8D.5.1 przelicza immutable instant przez `setTimezone('Europe/Warsaw')`: `11.09.2026 09:27:30`, drugi wiersz `Europe/Warsaw, UTC+02:00`. `processed_at` i `processed_at_timezone` zależą od daty, nie od stałego +2 h. Nie zmieniono UTC storage, `KsefUtcInstantCast`, `acquisition_date`, `P_1`, XML, hashów, QR, numeru KSeF, statusu ani UPO.

VAT/KOR mają cache `v46` z sufiksem środowiska, Pro forma nadal `v35`. Lokalna regresja obejmowała lato, zimę, zmianę dnia, oba przejścia DST, wejściowy offset, mikrosekundy i brak mutacji modelu; obejrzano syntetyczne PDF-y VAT/KOR. Nie odczytano operatorowej DB ani nie regenerowano rzeczywistego PDF Invoice `128` w poprawce lub closure. Następna normalna generacja wybierze `v46`; nie deklarujemy istnienia takiego pliku w historycznym LIVE zamiast `v45`.

8D.4 pierwotnie blokował brak potwierdzenia zabezpieczonej kopii klucza do odszyfrowania backupu. Późniejsze `RECOVERY_KEY_BACKUP: OPERATOR_CONFIRMED` nie oznacza sprawdzenia lokalizacji/zawartości ani testu odtworzenia (`RESTORE TEST: NOT PERFORMED`). Metadane prywatnego backupu sprzed 8D.5 pozostają w architekturze; backupu nie otwierano w closure. Po możliwym invoice POST najpierw rozstrzyga się istniejącą próbę, bez automatycznego odtworzenia starej DB i blind resend.

#### 8D.6.1-8D.6.2A.1: bezpieczne recovery przerwanych prób

`KSeF.8D.6.2A-DOC-CLOSURE-001: PASS`. Audyt 8D.6.1 zakończył się `AUDIT COMPLETED WITH FINDINGS`: nie uruchamiał workerów, schedulera ani jobów, lecz wykazał brak jednoznacznego rozróżnienia przerwania przed invoice POST i sytuacji, w której POST mógł już nastąpić. 8D.6.2A dodał recovery core (commit `7a4844596dc0fe366e133e218d4ba632e64e88e8`), a 8D.6.2A.1 wymusił transport poza aktywną transakcją bazy (commit `b43eb8ba28722492e1b3ed42c412302152f93f07`).

Migracja `2026_08_13_089000_add_ksef_submission_execution_protocol.php` została najpierw wykonana dokładnie raz na odizolowanej kopii, następnie ręcznie przez operatora i na końcu sprawdzona read-only. Wszystkie etapy zakończyły się PASS. Historyczne 31 submissionów zachowało dane, a sześć nowych pól pozostało `NULL`, bez backfillu. Chronione Invoice `128` (`K7T 2/2026`), Accepted submission `31`, numer KSeF i UPO `28` pozostały niezmienione. Jest to potwierdzenie zachowania lokalnych danych, nie nowa operacja MF.

Nowy protokół zapisuje wersję wykonania, właściciela i lease oraz jednokierunkowy `invoice_post_started_at` przed invoice POST. Fence oznacza, że POST mógł nastąpić; nie potwierdza odbioru przez MF. Brak reference number nie dowodzi braku wysyłki, a wygaśnięcie lease nie dowodzi, że proces już nie działa. Dlatego recovery przed fence może bezpiecznie zakończyć przerwaną próbę jako `TechnicalFailed`, natomiast po fence musi zachować próbę jako `Uncertain` i skierować ją do istniejącego reconciliation, bez blind resend.

| Stan | Zachowanie operatora/systemu |
| --- | --- |
| Próba niestara / aktywny lease | Bez mutacji recovery. |
| Protokół v1, przerwanie przed POST | Lokalny `TechnicalFailed`, zwolnienie właściciela, bez invoice POST. |
| POST mógł nastąpić | `Uncertain`, zachowanie sesji/hash/fence i reconciliation, bez ponownego wysłania. |
| Legacy, unknown lub stan niespójny | `REVIEW_REQUIRED`, bez automatycznego odblokowania. |
| Submitted/Processing/Accepted/Rejected/TechnicalFailed | Dotychczasowy follow-up albo zachowanie wyniku; bez reinterpretacji historii. |

Operator może najpierw wykonać wyłącznie inspekcję pojedynczego rekordu:

```text
php artisan ksef:recover-submission --submission=<id>
```

Dopiero po sprawdzeniu `decision` może świadomie zastosować lokalną decyzję:

```text
php artisan ksef:recover-submission --submission=<id> --apply
```

`--apply` ponownie ocenia rekord. Nie tworzy próby, nie wykonuje HTTP ani invoice POST i nie wysyła joba transportowego. Nie ma trybu `--all`, masowego automatycznego recovery ani obejścia integralności. Sam poprawny exit code nie oznacza naprawy lub akceptacji dokumentu. Zaakceptowanego submissionu `31` nie wolno używać jako przykładu zastosowania recovery.

Transport sprawdza aktywną transakcję Laravel i writer PDO przed claim/auth, przed utrwaleniem fence oraz bezpośrednio przed invoice POST. Błąd na wejściu nie wykonuje claimu ani HTTP. Późny błąd nie wykonuje invoice POST ani cleanup HTTP, chociaż wcześniejsze auth, klucz lub otwarcie sesji mogły już nastąpić. Guard nie zatwierdza i nie wycofuje transakcji callera. PREPARE musi zostać zatwierdzony przed transportem.

Zachowane raporty dowodowe i ich SHA-256 są wymienione szczegółowo w `docs/architecture.md`. `LOCAL TEST REPORT`: 8D.6.2A - 2531 testów / 17083 asercji; 8D.6.2A.1 - 2587 / 17766, bez failures/errors/skips. Nie są to testy wykonane w tym closure, CI ani testy docelowego serwera lub bazy innej niż SQLite.

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

#### Otwarty rollout po 8D.6.2A.1

Obserwacja raportów 10–11.09.2026: aktywne PROD, `automatic_submission=false`, workerów nie uruchamiano i failed jobs TEST/DEMO nie ponawiano. Nie jest to nowa kontrola runtime. Reguła `id=9` po statusie „Wysłane” mogła wywołać URL i wystawić Fakturę, również po błędzie kroku URL; wyłączenie automatic submission nie wyłącza wszystkich automatyzacji ani nie dowodzi wykonania tej reguły w LIVE. Po 8D.5 pozostały dwa nieuruchomione joby na `automation/ksef` i dwa locki, nie kolejne invoice POST. Nie zostały tutaj wykonane/wyczyszczone ani uznane za bezwarunkowo pozbawione skutków ubocznych.

Latarnia: TEST do TEST, PROD do PROD, DEMO bez fallbacku. Według 8D.4 sync PROD był wyłączony i lokalnego coverage nie było; nie blokowało to zwykłego Online, lecz nie potwierdza gotowości planowanej niedostępności/awarii. W tym LIVE PROD nie sprawdzono KOR, Offline24/innych Offline, korekty technicznej, batch, automatyki bez nadzoru ani celowych 440/450/21166/21167 lub Uncertain. Zachowano wcześniejsze dowody kodowe/fake i TEST/DEMO bez przenoszenia ich na PROD. Nie jest wymagana celowa awaria PROD dla dokumentacyjnego closure.

`PRODUCTION CODE: ENABLED / SHARED IMPLEMENTATION`; `PRODUCTION MANUAL ONLINE: LIVE VERIFIED FOR K7T 2/2026`; `PROD MANUAL ONLINE VAT: LIVE VERIFIED / DOCUMENTED`; `PDF TIMESTAMP: EUROPE/WARSAW / DOCUMENTED`. `PRODUCTION AUTOMATIC FIRST-SEND: IMPLEMENTED / NOT ACTIVATED IN REPORTED INSTALLATION` (kontrakt 30.11). `RECOVERY CORE + OPERATOR SCHEMA: IMPLEMENTED / VERIFIED / DOCUMENTED`; `AUTOMATIC / UNATTENDED ROLLOUT: OPEN`; `UNATTENDED OPERATIONS: OPEN`.

Dalsza część 8D.6 wymaga osobnej decyzji operatora: wyboru docelowego serwera, systemu operacyjnego, bazy i nadzoru usług; testów na wybranej bazie; uruchomienia workerów i całego schedulera OMS; monitoringu backlogu i `Uncertain`; decyzji o `automatic_submission`; przeglądu Automation i failed jobs; oraz gotowości wszystkich ścieżek Offline na serwerze. Nie wybrano jeszcze ani nie potwierdzono konkretnego hostingu, MariaDB, Linux, supervisor, systemd lub Docker. Nie wykonano rzeczywistego recovery. Nie jest to certyfikacja MF ani zamknięcie całego 8D. W closure: `TRACKED CODE CHANGES: 0`, `OPERATOR DB ACCESS / WRITES: 0 / 0`, `RUNTIME HTTP: 0`, bez testów Artisan, generowania PDF, nowych transmisji, recovery i uruchamiania workerów.

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

Kontrolowany happy path transportu KSeF.4A został pozytywnie zweryfikowany na TEST. Wtedy dalszy audyt był warunkiem udostępnienia workflow i rollout'u produkcyjnego. Późniejszą chronologię audytu 8D.1, implementacji i jednego ręcznego LIVE PROD opisuje sekcja 8D; automatyczny rollout nadal wymaga odrębnej decyzji i weryfikacji operacyjnej.

## 30.11. Automatyczne przekazywanie nowych Faktur

Włączenie `automatic_submission` powoduje, że nowa Faktura VAT wystawiona centralnie przez `InvoiceIssuingService`, także przez istniejącą akcję Automation, otrzymuje po zatwierdzeniu transakcji trwały job na dedykowanym połączeniu `ksef_submit` i kolejce `ksef-submit`. Automatyczna wysyłka wymaga jednocześnie deployment gate, aktywnej integracji, środowiska dopuszczonego przez `KsefOperationalEnvironmentPolicy` i dokładnie tej serii numeracji włączonej w konfiguracji KSeF. Pierwotne 6G obejmowało TEST/DEMO; od 8D.2 wspólna polityka dopuszcza także Production. Pro formy i Korekty pozostają wyłączone z automatic first-send.

Obsługa PROD w kodzie nie oznacza jej uruchomienia. W raportach z 10–11.09.2026 aktywne było PROD z `automatic_submission=false`; workerów nie uruchamiano, a ręczna obsługa była dostępna bez nich. Nie oznacza to wyłączenia wszystkich reguł Automation. Po zamknięciu recovery core pełny rollout kolejek, schedulera i automatyzacji bez nadzoru pozostaje otwartą częścią 8D.6. Poniższe komendy opisują istniejący kontrakt workerów, nie dyspozycję uruchomienia ich w closure dokumentacyjnym.

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

`Order` posiada relację `hasMany Invoices`; tabela `orders` nie posiada `invoice_id`. Od PRODUCTS.1B `order_items.product_id` opcjonalnie wskazuje produkt i używa `nullOnDelete`, natomiast `invoice_items.product_id` jest nullable i celowo nie posiada klucza obcego. Builder dokumentu kopiuje identyfikator produktu z pozycji zamówienia do pozycji oraz snapshotu dokumentu. Historyczne pozycje działają bez istniejącego produktu. Dokumenty nie używają SoftDeletes.

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

Zakładka `Korekty` udostępnia listę wystawionych Korekt z filtrowaniem, sortowaniem, paginacją, podglądem PDF, przejściem do edycji oraz usuwaniem pojedynczym i zbiorczym. Zaznaczone Korekty można wydrukować w jednym zbiorczym PDF. Korekta jest dokumentem księgowym i jest ujmowana razem z Fakturami VAT w rejestrze sprzedaży RS.1A.2/RS.1B; przycisk rejestru udostępnia formularz i eksport HTML.

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

---

# PAYNOW.1 — konfiguracja i fundament płatności

NEX-OMS udostępnia kafel `PayNow` na liście integracji oraz osobny ekran konfiguracji jednego logicznego konta. Konfiguracja obejmuje nazwę, środowisko Sandbox albo Produkcja, Klucz API, Klucz podpisu, czas ważności przyszłego linku, szablon opisu, opcjonalny adres powrotu klienta i przełącznik aktywności. Klucze są szyfrowane w bazie, nie wracają do HTML ani danych sesji, a puste pola podczas edycji zachowują zapisane wartości. Zmiana środowiska wymaga podania obu nowych kluczy.

Test połączenia wykonuje podpisane żądanie v3 do listy metod płatności dla kwoty 1,00 PLN. Używa wyłącznie stałych hostów PayNow Sandbox i Production, nie tworzy płatności i nie wykonuje ponowień. Wynik oraz czas testu są zapisywane przy koncie. Integrację można aktywować dopiero po poprawnym teście bieżącego środowiska i kluczy; nieudany test wyłącza aktywne konto. Komunikaty nie zawierają sekretów ani surowej odpowiedzi dostawcy.

Fundament danych tworzą `paynow_accounts`, `paynow_payments` oraz `paynow_payment_events`. Próba płatności przechowuje dziesiętną kwotę i walutę, identyfikatory NEX i PayNow, klucz idempotencji, zaszyfrowany URL, snapshot e-maila kupującego, status dostawcy i bezpieczny stan diagnostyczny. Zdarzenia przechowują append-only dane dostawcy, hash i zaszyfrowany payload. Usunięcie powiązanego zamówienia, konta lub próby nie usuwa historycznych danych płatniczych; relacje są zerowane przez `nullOnDelete`.

PAYNOW.1 nie generuje jeszcze linków, nie obsługuje webhooka, nie odpytuje statusu istniejącej płatności, nie aktualizuje `orders.paid_amount`, nie pokazuje historii płatności na zamówieniu i nie dodaje automatycznych akcji. Te operacje należą do kolejnych etapów.

---

# PAYNOW.2 — generowanie linku płatności

Przy nierozliczonym zamówieniu ikona linku w kaflu informacji o zamówieniu generuje link PayNow bez przeładowania strony. Pojedyncze kliknięcie kopiuje link do schowka, a podwójne kliknięcie otwiera ten sam link w nowej karcie. Podpowiedź przy ikonie opisuje oba sposoby użycia, a wynik operacji jest prezentowany w centrum aktywności NEX-OMS.

Link może zostać utworzony wyłącznie dla aktywnego i poprawnie przetestowanego konta PayNow, zamówienia w PLN oraz prawidłowego adresu e-mail klienta. Kwota linku jest dokładną różnicą `total_gross - paid_amount`, obliczaną bez `float`; musi mieścić się w przedziale od 1,00 PLN do 1 000 000,00 PLN. Żądanie zawiera snapshot adresu e-mail, opis zamówienia, czas ważności oraz opcjonalny adres powrotu.

Ponowne użycie przycisku zwraca istniejący, nadal ważny link o tym samym koncie, zamówieniu, saldzie, walucie, adresie e-mail i opisie. Nie tworzy drugiego żądania ani duplikatu historii. Niedokończona próba komunikacji zachowuje własny klucz idempotencji, dzięki czemu bezpieczne ponowienie nie tworzy kolejnej płatności po stronie PayNow.

Utworzenie linku zapisuje próbę w `paynow_payments`, zaszyfrowaną odpowiedź dostawcy w append-only `paynow_payment_events` oraz bezpieczny wpis historii zamówienia bez URL i sekretów. PAYNOW.2 nie uznaje zamówienia za opłacone, nie zmienia `orders.paid_amount` ani `orders.payment_status` i nie wyświetla jeszcze zarejestrowanej wpłaty. Webhook, potwierdzenie statusu, księgowanie wpłaty i sekcja „Płatności” należą do PAYNOW.3.

---

# PAYNOW.3 — rejestracja i księgowanie płatności

Publiczny endpoint powiadomień PayNow sprawdza nagłówek `Signature` przez HMAC SHA-256 liczony na dokładnym, surowym body żądania. Niepoprawnie podpisane, zbyt duże albo nieprawidłowe powiadomienia nie zmieniają danych. Poprawne zdarzenia są zachowywane tylko raz w szyfrowanej, append-only historii, a endpoint odpowiada pustym statusem HTTP 202. Duplikaty oraz zdarzenia odebrane poza kolejnością nie mogą cofnąć statusu `CONFIRMED` ani ponownie naliczyć wpłaty.

Pierwsze bezpieczne potwierdzenie płatności zwiększa `orders.paid_amount` o dokładną kwotę próby, wykorzystując centralne reguły stanu płatności zamówienia. Pełna wpłata ustawia stan opłacony i datę płatności, a częściowa pozostawia zamówienie nierozliczone. Czas potwierdzenia dostawcy jest oddzielony od `accounted_at`, czyli chwili zaksięgowania w OMS. Operacja tworzy wpis historii `Zarejestrowano płatność PayNow`.

Nadpłata, niezgodność waluty, brak zamówienia albo konflikt identyfikatorów nie są automatycznie korygowane ani ukrywane. Płatność pozostaje potwierdzona, otrzymuje oznaczenie `Wymaga sprawdzenia`, a saldo zamówienia nie jest zmieniane. Automatyczne zwroty, chargebacki i ręczne dopasowywanie osieroconych wpłat pozostają poza etapem.

Sekcja `Płatności` w informacjach dodatkowych zamówienia pokazuje wyłącznie potwierdzone wpłaty, kwotę, walutę, czas, identyfikator PayNow i wynik księgowania. Widok odświeża ten fragment bez przeładowania strony. Gdy webhook nie jest dostępny, harmonogram co minutę sprawdza nierozstrzygnięte płatności przez API PayNow; lokalnie wymaga uruchomionego Laravel Scheduler, a na VPS publicznego adresu HTTPS skonfigurowanego w PayNow.

---

# PAYNOW.4 — automatyczne akcje po płatności

Jednokrotne zaksięgowanie poprawnej wpłaty PayNow publikuje zdarzenie automatyzacji `Zarejestrowano płatność`. Zdarzenie powstaje dopiero po zatwierdzeniu transakcji aktualizującej zamówienie. Płatność wymagająca sprawdzenia, odrzucona z powodu nadpłaty, niezgodnej waluty albo braku zamówienia nie uruchamia automatycznych akcji.

Reguła może rozróżnić źródło płatności `PayNow` oraz stan zamówienia po wpłacie: nieopłacone, częściowo opłacone albo opłacone. Każda odrębna wpłata lub dopłata otrzymuje własne zdarzenie, natomiast powtórzony webhook i kontrolne odpytywanie statusu tej samej płatności nie tworzą drugiego wykonania tej samej reguły.

Zdarzenie może uruchamiać istniejące akcje, między innymi zmianę statusu, wystawienie faktury, wysłanie e-maila i drukowanie. Błąd automatycznej akcji jest rejestrowany przez dotychczasowy mechanizm automatyzacji i nie cofa już zaksięgowanej wpłaty. Zwroty PayNow oraz ręczna obsługa płatności wymagających sprawdzenia pozostają poza PAYNOW.4.

---

# PRESTASHOP.1 — konfiguracja i rozpoznanie sklepu

NEX-OMS udostępnia kafel `PrestaShop` oraz konfigurację jednego sklepu internetowego. Operator zapisuje nazwę, bazowy adres HTTPS i 32-znakowy Klucz API. Klucz jest szyfrowany w bazie, nie wraca do HTML ani sesji po błędzie walidacji, a pozostawienie pola pustego zachowuje bieżącą wartość. Integrację można aktywować dopiero po poprawnym teście aktualnego adresu i klucza. Zmiana danych połączenia automatycznie ją wyłącza i unieważnia poprzedni wynik testu.

Test jest wyłącznie odczytowy. Sprawdza natywne zasoby Webservice API oraz uprawnienia GET do konfiguracji i ręcznego importu zamówień, bez zależności od indeksu `/api/`. Wykrywa pojedynczy aktywny sklep, wersję PrestaShop, aktywne języki i waluty, a następnie zapisuje bieżący słownik statusów zamówień wraz z flagami PrestaShop, między innymi `paid`, `shipped` i `delivery`. Statusy są pobierane jako XML w aktywnym języku, aby zachować zgodność z PrestaShop 8.2.7. Dla sklepu multi-store test kończy się bez aktywacji, ponieważ wybrany wariant obsługuje dokładnie jeden sklep.

PRESTASHOP.1 nie pobiera zamówień ani statusów płatności, nie zmienia statusów po stronie sklepu, nie synchronizuje produktów, cen lub stanów i nie uruchamia pracy cyklicznej. Nie wymaga też instalowania modułu NEX w PrestaShop. Ręczny import i deduplikację dodano w PRESTASHOP.2A, natomiast mapowanie statusów oraz dwukierunkowa wymiana należą do kolejnych etapów.

---

# PRESTASHOP.2A — kontrolowany import pojedynczego zamówienia

Na ekranie konfiguracji operator może podać ID albo numer referencyjny zamówienia i pobrać podgląd bez zapisu do OMS. Podgląd obejmuje aktualny status PrestaShop, klienta, adres dostawy i rozliczenia, dostawę, kwoty, płatności oraz pozycje. Dane są ponownie pobierane przy właściwym imporcie, a zapis wymaga aktywnej integracji.

Import tworzy zamówienie OMS ze statusem `new`, pozycje, bezpieczne powiązania identyfikatorów PrestaShop, szczegóły płatności bez danych kartowych oraz wpis historii. Cała operacja jest transakcyjna. Powtórzenie importu zwraca istniejące zamówienie, a unikalne powiązanie konta i zewnętrznego ID zabezpiecza przed duplikatem. Po pierwszym imporcie nie można podmienić adresu sklepu w tym samym koncie integracji.

Jeżeli PrestaShop zwraca dokładnie `current_state = 0`, zamówienie nie posiada jeszcze przypisanego statusu sklepu, ale może zostać zaimportowane. OMS zapisuje wtedy pusty zewnętrzny status, ustawia lokalny status `new`, pokazuje ostrzeżenie w podglądzie i zapisuje tę informację w historii. Inne nieprawidłowe wartości statusu nadal blokują import.

Wewnętrzne `order_items.product_id` pozostaje `null`. Import nie dopasowuje katalogu po nazwie, SKU ani EAN; zewnętrzne ID produktu i wariantu są przechowywane wyłącznie w tabelach integracji. Adres `address1/address2` jest zachowywany jako pełna ulica bez heurystycznego rozdzielania numeru budynku i lokalu. Kwoty są przetwarzane dziesiętnie, a niezgodność sumy pozycji, dostawy, płatności lub wartości zamówienia blokuje zapis.

PRESTASHOP.2A jest jednokierunkowy i uruchamiany ręcznie. Nie synchronizuje ponownie danych biznesowych zaimportowanego zamówienia, nie mapuje statusu sklepu na status OMS, nie wysyła statusów do PrestaShop, nie synchronizuje stanów magazynowych i nie uruchamia harmonogramu. Powtórny import może jedynie bezpiecznie uzupełnić brakujący telefon lub dane punktu odbioru oraz usunąć błędnie zapisany login PrestaShop. Te funkcje należą do kolejnych etapów.

---

# PRESTASHOP.2A.1 — metadane sklepu i punkt odbioru

Konto PrestaShop przechowuje konfigurowalny wzór publicznego linku produktu ze znacznikiem `[product_id]`. Zewnętrzne ID produktu pozostaje w tabeli powiązań integracji, jest pokazywane obok nazwy pozycji i prowadzi do produktu w domenie skonfigurowanego sklepu. Numer zamówienia w sklepie jest prezentowany wraz z nazwą konta integracji.

PrestaShop nie posiada loginu klienta odpowiadającego polu OMS, dlatego import pozostawia `customer_login` pusty i przechowuje adres wyłącznie jako e-mail. Telefon jest wybierany z pierwszego niepustego pola `phone_mobile` albo `phone`.

Test połączenia opcjonalnie wykrywa rozszerzony, tylko do odczytu zasób `bl_order` lub `base_order`. Jeżeli jest dostępny, importer pobiera z niego nazwę, ID, adres, kod pocztowy i miasto punktu odbioru. Brak rozszerzonego zasobu nie blokuje podstawowego importu zamówienia.

---

# PRESTASHOP.2B.1 — mapowanie statusów

Konfiguracja PrestaShop posiada osobną zakładkę `Statusy` z dwoma niezależnymi mapowaniami. Kierunek przychodzący przypisuje każdy wykryty status PrestaShop do aktywnego statusu OMS albo pozostawia go bez działania. Kierunek wychodzący przypisuje każdy aktywny status OMS do jednego dostępnego statusu PrestaShop albo wyłącza jego przekazywanie. Wiele statusów PrestaShop może wskazywać ten sam status OMS.

Brak mapowania jest wartością domyślną i oznacza brak zmiany. Zapis konfiguracji nie wykonuje żądania do sklepu, nie zmienia zamówień, nie uruchamia automatyzacji ani pracy cyklicznej. Faktyczne pobieranie i przekazywanie statusów należą do kolejnych etapów PRESTASHOP.2B.

Mapowania są związane z kontem integracji, liczbowym ID statusu PrestaShop oraz rekordem aktywnego ustawienia statusu OMS. Ponowny test tego samego sklepu aktualizuje nazwy i flagi statusów bez usuwania mapowań. Status, którego sklep przestał zwracać, pozostaje w słowniku jako niedostępny i nie może być celem mapowania wychodzącego. Zmiana bazowego adresu na inny sklep usuwa mapowania; wymiana Klucza API dla tego samego adresu zachowuje je do ponownego testu połączenia.

# PRESTASHOP.2B.2 — przekazywanie statusów do sklepu

Zmiana statusu powiązanego zamówienia OMS uruchamia po zatwierdzeniu transakcji osobne zadanie kolejki `integrations`. Dotyczy to zmian ręcznych, zbiorczych, edycji zamówienia i automatycznych akcji, ponieważ wszystkie korzystają z `OrderStatusService`. Brak aktywnej integracji, powiązania zamówienia albo mapowania wychodzącego oznacza brak działania.

Zadanie sprawdza, czy status OMS nie został już zastąpiony nowszym, odczytuje bieżący `current_state` zamówienia PrestaShop i nie tworzy powtórnej historii, jeżeli sklep ma już właściwy status. Faktyczna zmiana jest wykonywana natywnym POST XML do `order_histories`. Opcjonalny przełącznik konta pozwala dodać `sendemail=1`; domyślnie pozostaje wyłączony.

Zdalny `current_state = 0` oznacza brak przypisanego statusu i nie blokuje eksportu. Jeżeli lokalny status OMS ma mapowanie wychodzące, utworzenie `order_history` nadaje zamówieniu pierwszy prawidłowy status PrestaShop.

Po powodzeniu lokalne powiązanie przechowuje ostatni znany status sklepu, a zamówienie otrzymuje zdarzenie informacyjne. Błędy chwilowe są ponawiane przez kolejkę, natomiast trwałe błędy API trafiają do historii zamówienia i nie cofają statusu OMS. Zdarzenia o źródle `prestashop` nie są wysyłane z powrotem, co przygotowuje ochronę przed pętlą dla przyszłego kierunku przychodzącego.

PRESTASHOP.2B.2 nie pobiera statusów ze sklepu, nie synchronizuje płatności, nie importuje zamówień cyklicznie i nie wymaga modułu PrestaShop.

# PRESTASHOP.2B.3 — pobieranie statusów ze sklepu

Zakładka `Statusy` pozwala włączyć automatyczne pobieranie zmian co 1, 5 albo 10 minut oraz ręcznie zlecić pełną synchronizację. Pierwszy przebieg sprawdza wszystkie zamówienia posiadające bezpieczne powiązanie z PrestaShop. Kolejne przebiegi pobierają paginowaną listę zamówień zmienionych od ostatniej poprawnej synchronizacji, z dwuminutową zakładką czasową. Nieznane zamówienia sklepu są ignorowane i nie są automatycznie importowane.

Aktualny status sklepu jest zawsze zapisywany w `prestashop_order_links`. Jeżeli istnieje aktywne mapowanie przychodzące, zmiana przechodzi przez `OrderStatusService` ze źródłem `prestashop_status_sync`, tworzy wpis historii i uruchamia zwykłe automatyczne akcje statusowe. Listener kierunku wychodzącego ignoruje źródła PrestaShop, dlatego zmiana nie jest odsyłana do sklepu.

Wartość `current_state = 0` jest obserwowana jako brak zewnętrznego statusu: nie zmienia statusu OMS i nie przerywa synchronizacji pozostałych zamówień. Późniejsze pojawienie się prawidłowego statusu jest przetwarzane zwykłym mapowaniem przychodzącym.

Powiązanie przechowuje krótkotrwały status oczekujący na wysłanie z OMS. Dopóki eksport pozostaje aktywny, pobieranie nie może zastąpić go starszym stanem sklepu. Zakończenie albo trwały błąd eksportu usuwa blokadę, a osierocona blokada wygasa po 15 minutach. Synchronizacja działa w kolejce `integrations`, błędy chwilowe są ponawiane, a ostatni bezpieczny błąd i czas ostatniego powodzenia są prezentowane w konfiguracji.

PRESTASHOP.2B.3 nie pobiera płatności, produktów, adresów ani kwot, nie importuje nowych zamówień i nie wymaga modułu PrestaShop.

---

# PRESTASHOP.2C — automatyczne pobieranie nowych zamówień

Zakładka `Zamówienia` pozwala włączyć automatyczne pobieranie nowych zamówień co minutę. Pierwsze uruchomienie zapamiętuje dokładny moment włączenia i nie importuje wcześniejszych zamówień. Każdy przebieg odczytuje paginowaną listę utworzoną według `orders.date_add`, z dwuminutową zakładką kursora oraz jednominutowym opóźnieniem bezpieczeństwa, aby nie pobrać zamówienia, które PrestaShop jeszcze zapisuje. Kursor przesuwa się dopiero po poprawnym odczycie całego przedziału.

Ręczne zlecenie przebiegu jest dostępne pod przyciskiem `Sprawdź nowe zamówienia`. Potwierdzenie zlecenia jest wyświetlane na stronie tylko raz i znika automatycznie po 5 sekundach.

Wykryte zamówienie otrzymuje trwały rekord próby i osobne zadanie na kolejce `integrations`. Właściwy zapis wykorzystuje ten sam `PrestashopOrderImportService` co import ręczny, dlatego zachowuje pełną walidację kwot, deduplikację, dane adresowe, punkt odbioru, pozycje oraz płatności dostępne w chwili importu. Powtórne wykrycie, nakładanie przedziałów i równoległy import ręczny nie tworzą drugiego zamówienia.

Automatyczny import stosuje również regułę statusu `0`: kompletne zamówienie jest zapisywane jako `new`, a nieudana wcześniej próba może zostać bezpiecznie ponowiona po wdrożeniu tej obsługi.

Po imporcie bieżący status zapisany razem z zamówieniem jest od razu przepuszczany przez mapowanie przychodzące. Zmiana statusu OMS używa źródła `prestashop_order_import`, uruchamia zwykłe automatyczne akcje i nie jest wysyłana z powrotem do sklepu. Brak mapowania pozostawia domyślny status `new`.

Konfiguracja pokazuje czas uruchomienia, zakres sprawdzony do ostatniego powodzenia, bezpieczny komunikat błędu i ostatnie próby importu. Błąd pojedynczego zamówienia nie zatrzymuje wykrywania kolejnych; zadania chwilowo niedostępnego API są ponawiane, a trwały błąd można zlecić ponownie ręcznie. Ręczny przycisk sprawdzenia uruchamia ten sam proces asynchroniczny i nie omija granicy czasu włączenia.

PRESTASHOP.2C nie aktualizuje później danych biznesowych istniejącego zamówienia, nie synchronizuje kolejnych wpłat ani zmian kwot i nie pobiera historii sprzed włączenia. Nadal korzysta z natywnego Webservice API i nie wymaga modułu NEX w PrestaShop. Synchronizacja płatności należy do PRESTASHOP.3, a ewentualny webhook do PRESTASHOP.5.

---

# PRESTASHOP.3A — automatyczna synchronizacja płatności

Zakładka `Płatności` pozwala włączyć pobieranie nowych wpłat i dopłat dla zamówień już powiązanych z PrestaShop. Częstotliwość jest stała i wynosi jedną minutę; operator nie wybiera interwału. Pierwsze uruchomienie i ręczne sprawdzenie wykonują pełną kontrolę znanych zamówień, a kolejne przebiegi korzystają z `orders.date_upd` z dwuminutową zakładką kursora.

Każda zewnętrzna płatność jest identyfikowana przez ID `order_payment` PrestaShop i przechodzi przez trwałe stany: oczekująca, zaksięgowana, odzwierciedlona z innego źródła albo wymagająca sprawdzenia. Poprawna nowa wpłata jednokrotnie zwiększa `orders.paid_amount`, aktualizuje stan płatności i tworzy zdarzenie `Zarejestrowano płatność PrestaShop`. Każda odrębna dopłata może uruchomić istniejącą automatyzację `Zarejestrowano płatność` ze źródłem `PrestaShop`.

Jeżeli identyfikator transakcji odpowiada już zaksięgowanej wpłacie PayNow o tej samej kwocie i walucie, rekord PrestaShop jest oznaczany jako odzwierciedlony i nie zwiększa salda ponownie. Niezgodność waluty, nadpłata, zmiana wcześniej zapisanej płatności albo możliwy duplikat bez wspólnego identyfikatora trafiają do ręcznej weryfikacji i nie modyfikują salda zamówienia.

Etap pokazuje płatności PrestaShop w sekcji `Informacje dodatkowe → Płatności`. Nie obsługuje automatycznych zwrotów, usuwania wcześniej zaksięgowanych wpłat ani ręcznego rozstrzygania pozycji wymagających sprawdzenia. Nadal korzysta z natywnego Webservice API i nie wymaga modułu NEX w PrestaShop.

---

# ANALYTICS.1 — podstawowe statystyki sprzedaży

Pozycja `Analityka` w głównym menu prowadzi bezpośrednio do podstawowych statystyk. Ekran udostępnia raporty: ilość i wartość zamówień, źródła zamówień, rodzaje wysyłki i płatności, klienci oraz produkty. Raport pracowników pozostaje poza zakresem do czasu wprowadzenia użytkowników i wiarygodnego audytu wykonawcy operacji.

Wspólne filtry obejmują zakres dat, źródła, statusy i jedną walutę. Zakres może oznaczać dzień, ostatnie 7, 14, 28 lub 30 dni, bieżący albo poprzedni miesiąc, kwartał lub rok oraz własny przedział. Strzałki przesuwają przedział o jego długość. Raport nie przelicza walut; każda waluta jest analizowana osobno. Datą sprzedaży jest `purchased_at`, a dla danych bez tej wartości używane jest `created_at`.

Źródła PrestaShop są rozdzielane według kont integracji, dzięki czemu `multi-click.pl` jest osobnym wyborem. Zamówienia usunięte do kosza nie uczestniczą w statystykach. Zestaw powiązany z pozycją zamówienia jest liczony jako jedna sprzedana pozycja zestawu i nie jest rozwijany na składniki w statystykach sprzedaży.

Każdy panel wykresu posiada osobny eksport CSV zgodny z aktywnymi filtrami. Raporty używają zapisanych snapshotów zamówień i pozycji. Kwoty są agregowane w groszach bez używania `float`. Podstawowe statystyki nie zmieniają zamówień, produktów, stanów magazynowych ani konfiguracji integracji.

---

# ALLEGRO.2A — ręczny podgląd i import zamówienia

Zakładka `Integracje → Allegro → Zamówienia` pozwala pobrać podgląd po identyfikatorze UUID zamówienia. Podgląd nie tworzy zamówienia OMS. Import wymaga potwierdzenia podglądu ważnego przez 15 minut, przypisanego do połączonego konta i środowiska. Przed zapisem OMS ponownie odczytuje zamówienie. Zmiana danych wymaga ponownego potwierdzenia odświeżonego podglądu.

Import obejmuje kompletne zamówienia `READY_FOR_PROCESSING` realizowane przez sprzedawcę, z płatnością internetową albo pobraniem. Zamówienie otrzymuje własny numer OMS oraz status `new`. Oddzielnie zachowywane są UUID zamówienia Allegro, ID pozycji, numery ofert i identyfikatory płatności. Źródło na liście i karcie pokazuje nazwę konta integracji. Data zakupu pochodzi z pozycji Allegro.

Zachowywane są login, pełny maskowany adres e-mail, telefon, adres dostawy z formularza Allegro, osobne dane fakturowe, punkt odbioru, Smart i żądanie faktury. Przy braku danych fakturowych i braku żądania faktury kopiowany jest adres dostawy. Wiadomość kupującego ma osobne miejsce i nie trafia do uwag sprzedawcy z numerami seryjnymi.

Pozycje zachowują nazwy i ceny po rabatach z Allegro, bez automatycznego dopasowania do katalogu OMS. Usługi dodatkowe są osobnymi pozycjami. VAT pozostaje nieustalony i wymaga sprawdzenia przed wystawieniem faktury. Suma pozycji i dostawy musi odpowiadać kwocie zamówienia. Płatności i dopłaty są zapisywane osobno; pobranie nie oznacza wpłaty. Częściowa wpłata pozostawia zamówienie nieopłacone. Nadpłaty, Allegro Ceny, płatności odroczone, split payment i niezgodne kwoty wymagają odrębnej obsługi i nie są importowane w tym etapie.

Ponowienie importu otwiera istniejące zamówienie bez nadpisania ręcznych zmian i bez ponownego naliczenia wpłat. Kosz, trwałe usunięcie zamówienia albo usunięcie konta integracji nie znoszą ochrony przed duplikatem. Przy późniejszym ręcznym wystawieniu faktury numerem zamówienia jest ID OMS, a numerem płatności są identyfikatory zaksięgowanych wpłat Allegro zapisane w snapshotcie dokumentu.

Etap nie uruchamia automatyzacji, cyklicznego importu, synchronizacji późniejszych płatności i statusów, wystawiania faktur, e-maili, przesyłek ani zmniejszania stanów. Powiązania katalogowe należą do następnego etapu. Import nie wykonuje zapisów do Allegro.

---

# ALLEGRO.2B — powiązania produktów i zestawów

Wspólne okno powiązań na listach Produkty i Zestawy pozwala wybrać połączone konto Allegro obok integracji PrestaShop. Jeden produkt lub zestaw OMS może mieć wiele ofert tego samego konta. Kafelek pokazuje ich liczbę, a kliknięcie otwiera listę z edycją. Przycisk `+` zawsze rozpoczyna dodawanie nowego powiązania. Po zapisie formularz pozostaje otwarty i pokazuje klikalny numer oferty. Wpisywany jest numer oferty Allegro, nie identyfikator produktu w katalogu Allegro ani EAN.

Powiązanie jest przypisane do lokalnej integracji, środowiska i sprzedawcy. Zmiana środowiska lub połączenie innego sprzedawcy nie przenosi poprzednich powiązań. Ten sam numer oferty w tej samej tożsamości integracji może wskazywać tylko jeden produkt lub zestaw OMS. Usunięcie powiązania nie usuwa produktu ani zamówienia. Trwałe usunięcie konta lub produktu usuwa jego konfigurację powiązań, ale nie usuwa historycznych pozycji zamówień.

Zakładka Allegro → Zamówienia ma trzy ustawienia: używanie powiązanych produktów, nazw katalogowych i VAT katalogowego. Domyślnie są włączone. Nowy import przypisuje wewnętrzne ID OMS, udostępnia zdjęcie katalogowe i opcjonalnie kopiuje nazwę oraz VAT. Cena jednostkowa, wartość, ilość, waluta, koszt dostawy i wpłaty pozostają z zamówienia Allegro. Brak powiązania nie blokuje importu i nie tworzy produktu; pozostają oryginalna nazwa i cena. Usługi dodatkowe nie są dopasowywane do oferty głównej.

Automatyczne kopiowanie VAT wymaga jawnego rynku `allegro-pl` oraz obu krajów adresów `PL`. Brak rynku nie jest zastępowany rynkiem bazowym konta na potrzeby VAT. Dla pozostałych przypadków VAT pozostaje pusty z ostrzeżeniem. Stawka katalogowa musi spełniać istniejące reguły walidacji faktur; nie ma domyślnego przypisywania 23% w importerze.

Zestaw pozostaje jedną pozycją z jego ID, nazwą i zdjęciem oraz ceną Allegro. Import utrwala skład zestawu i ilości na jeden zestaw. Nie zmniejsza stanów. Podgląd pokazuje dopasowanie i uwzględnia jego dane w potwierdzeniu; zmiana powiązania, nazwy, VAT, zdjęcia, składu lub ustawień wymaga nowego potwierdzenia. Zmiana ceny lub stanu katalogowego nie zmienia ceny Allegro.

Powiązania dotyczą nowych importów. Ponowienie wcześniej pobranego zamówienia nie uzupełnia ani nie nadpisuje jego pozycji lub dokumentów. Nie dodano podpowiedzi, operacji masowych, synchronizacji stanów i cen ani tworzenia lub modyfikacji ofert Allegro.

---

# ALLEGRO.2C - automatyczne zakupy niepelne i kompletne

Ustawienia Allegro -> Zamowienia pozwalaja wlaczyc odczyt dziennika co minute oraz zlecic sprawdzenie teraz. Pierwsze wlaczenie zapisuje aktualny kursor bez hurtowego importu historii. Ponowne wlaczenie dla tej samej tozsamosci nie resetuje kursora. Opcjonalny import starszych zakupow BOUGHT/FILLED_IN wymaga zakresu do 60 dni, podgladu i potwierdzenia; limit podgladu wynosi 1000 zakupow. Harmonogram i kolejka integrations musza dzialac takze lokalnie.

BOUGHT i FILLED_IN tworza normalne, widoczne zamowienie OMS w statusie new, z niezalezna informacja o niekompletnosci. Profil kupujacego nie jest adresem dostawy. Nieznany koszt dostawy nie jest przedstawiany jako darmowa wysylka. READY_FOR_PROCESSING uzupelnia ten sam numer OMS o kompletne dane i potwierdzone wplaty. Pobranie nie jest wplata. Nietypowe rozliczenia pozostaja widoczne jako wymagajace sprawdzenia zamiast stawac sie gotowymi do realizacji.

Uwagi sprzedawcy i gwiazdka pozostaja edytowalne. Przed potwierdzeniem kompletnego zakupu dane zrodla sa tylko do odczytu; blokady serwerowe obejmuja dokumenty, przesylki, edycje pozycji, platnosci i automatyzacje. Pozniejsze ponowienia nie nadpisuja kompletnego zamowienia ani dokumentow. Niepelne, anulowane i zastapione powiazania Allegro nie uczestnicza w podstawowych statystykach sprzedazy.

Polaczenie zakupow rozpoznawane jest po lineItem.id. Dawne zakupy pozostaja anulowane w historii z odsylaczem do nowej transakcji. Sam HTTP 404 nie jest dowodem anulowania. Anulowanie zakupu juz obslugiwanego wymaga sprawdzenia i nie usuwa Faktur, wplat ani przesylek. OMS nie wykonuje zwrotow pienieznych.

Na liscie stale widoczne sa T, $ oraz samochod: T jest zielone dla kompletnego Allegro i PrestaShop, szare dla niepelnego zakupu. Po anulowaniu w Allegro (import_state=cancelled lub external_status=CANCELLED) T jest zastepowane bialym !! na czerwonym tle #d54839, w tym samym rozmiarze 22 x 22 px. Ostrzezenie nie zalezy od zachowanego statusu OMS; samo lokalne anulowanie nie oznacza anulowania w Allegro. $ jest szare bez wplaty, zielone dla oplaconego zamowienia, pomaranczowe dla pobrania i czerwone dla czesciowej wplaty. Samochod jest niebieski po zapisaniu numeru przesylki. Dodatkowo pokazywane sa ikony wystawionej Faktury VAT, komentarza kupujacego Allegro oraz niepustych orders.notes. Komentarz jest prezentowany na gorze Wymiany wiadomosci i nigdy nie jest kopiowany do uwag sprzedawcy.

Etap nie wysyla statusow, numerow przesylek ani Faktur do Allegro, nie zmniejsza stanow i nie uruchamia akcji automatycznych wskutek samego importu. Przerwa przekraczajaca bezpieczny okres dziennika jest sygnalizowana bez samoczynnego resetu. Wymagany User-Agent pozostaje wspolny dla kazdego zadania i ponowienia.

Historia pobierania pokazuje maksymalnie 10 unikalnych zamowien aktualnego konta, sprzedawcy i srodowiska, posortowanych wedlug ostatniej aktualizacji. Nie ma paginacji ani rozwijanych logow. Jeden wiersz pokazuje ostatnie zdarzenie wedlug czasu jego wystapienia oraz zbiorczy stan pobierania: nierozwiazany blad ma pierwszenstwo przed oczekujacym zdarzeniem i poprawnym przetworzeniem. Przycisk ponowienia dotyczy nieudanego zdarzenia, nawet jezeli ostatnie zdarzenie tej transakcji zostalo przetworzone poprawnie. Grupowanie dotyczy tylko prezentacji; techniczny dziennik, starsze zamowienia i ich zdarzenia pozostaja w bazie.

---

# ALLEGRO.3A - przekazywanie statusow realizacji

Zakladka Integracje -> Allegro -> Statusy zamowien prezentuje aktualne, nieusuniete statusy z konfiguracji OMS, w ich kolejnosci i z ich nazwami. Nie posiada osobnej, sztywnej listy OMS. Wszystkie wiersze sa edytowalne, w tym status poczatkowy importu. Domyslna wartosc kazdego powiazania to "Nie przekazuj"; nowo dodany status OMS rowniez nie ma mapowania. Globalne przekazywanie jest poczatkowo wylaczone.

Zapis lub wlaczenie konfiguracji nie aktualizuje historycznych zamowien. Dopiero przyszla zmiana statusu OMS przez centralny mechanizm (reczna, zbiorcza lub automatyczna akcja) moze zlecic przekazanie do Allegro. Kilka statusow OMS moze wskazywac ten sam status Allegro. Brak mapowania nie blokuje lokalnej zmiany. Zmiana konta, sprzedawcy albo srodowiska nie przenosi ustawien na nowa tozsamosc; rozlaczenie wylacza przekazywanie.

Do wyboru sa: Nowe, W realizacji, Do wyslania, Do odbioru osobistego, Wyslane, Odebrane osobiscie, Anulowane i Wstrzymane. "Zwrocone" nie jest zapisywalnym statusem sprzedawcy w API. Odbior osobisty nie oznacza dostarczenia paczki przez przewoznika. Przekazanie Anulowane dotyczy statusu realizacji sprzedawcy (fulfillment), nie wykonuje zwrotu pieniedzy ani nie jest osobna operacja anulowania zakupu klienta. Ustawienie W realizacji moze wplywac na mozliwosc samodzielnego anulowania zakupu przez kupujacego, dlatego nie jest automatycznie wysylane podczas importu.

Wysylka w tle sprawdza biezacy status i tozsamosc OMS, wersje konfiguracji oraz aktualny stan zamowienia Allegro. Zakupy niepelne, wymagajace sprawdzenia, anulowane i zastapione nie sa eksportowane. Gotowy zakup musi nadal miec READY_FOR_PROCESSING i byc realizowany przez SELLER. Nieaktualne zadania sa pomijane. Przed zapisem uzywana jest aktualna revision Allegro, a konflikt wymaga nowego odczytu przed ponowieniem.

W Szczegolach zamowienia widoczne sa stan przekazywania i ewentualny blad z mozliwoscia ponowienia. Ponowienie dotyczy aktualnego statusu OMS wedlug biezacej konfiguracji, nie odtwarza starej mapy. Sukces i blad trafiaja do istniejacej historii zmian. Nie dodano osobnego ekranu logow, przekazywania numerow przesylek, faktur ani synchronizacji statusow roboczych OMS w druga strone. Wczesniejszy import anulowania zakupu z Allegro pozostaje aktywny i niezalezny.

---

# ALLEGRO.3B - przekazywanie numerow przesylek

Zakladka Integracje -> Allegro -> Przesylki ma trzy tryby: Nie przekazuj (domyslny), Po uzyskaniu numeru nadania oraz Po przejsciu do wybranego statusu OMS. Lista statusow pochodzi z aktualnych, nieusunietych ustawien OMS; mozna wybrac takze Nowe i statusy wlasne. Sam zapis ustawien, wlaczenie lub zmiana trybu nie zleca wysylki historycznych numerow. Zmiana konta, sprzedawcy lub srodowiska oraz rozlaczenie wylaczaja funkcje.

Obserwowane sa nowe numery zwrocone przez istniejace integracje InPost Paczkomaty, InPost Kurier i DPD oraz numery poszczegolnych paczek, takze uzyskane pozniej. Nie powstaje drugi formularz nadania ani workflow pakowania. Tryb statusowy obsluguje obie kolejnosci: numer przed statusem i numer po statusie. Wyjscie z wybranego statusu wstrzymuje nieprzekazane zadanie do ponownego wejscia. Zapis nowej konfiguracji uniewaznia oczekujace zadania; stare numery nie sa samoczynnie przenoszone do nowej konfiguracji.

OMS sprawdza aktualne konto, sprzedawce, srodowisko, zakup, przesylke i numer. Niepelne, wymagajace sprawdzenia, anulowane, zastapione lub usuniete zamowienia oraz anulowane/usuniete przesylki nie sa przekazywane. Pobranie nie blokuje funkcji. Przed zapisem OMS odczytuje aktualny checkout-form i istniejace numery w Allegro. Przekazuje przewoznika oraz numer tylko gdy brakuje takiego przypisania. Nie zgaduje przewoznika po nazwie metody dostawy; uzywa integracji kurierskiej i aktualnej listy przewoznikow Allegro.

Kazdy numer dotyczy calego zrodlowego zamowienia Allegro. Numery sa przypisywane do aktualnych zrodlowych lineItem.id, nie do lokalnego product_id ani zmienionej listy produktow OMS. Rozdzielenie produktow pomiedzy paczki i wysylki czesciowe pozostaja poza etapem. Istniejacego przypisania tylko do czesci pozycji OMS nie rozszerza automatycznie, lecz pokazuje blad wymagajacy sprawdzenia.

Wysylam z Allegro automatycznie przypisuje paczki po swojej stronie. OMS wykonuje w tym przypadku tylko weryfikacje, nigdy POST z ponownym dodaniem numeru. Tryb zaleznosci od statusu nie opoznia automatycznej operacji Allegro; po wlaczeniu funkcji weryfikacja Wysylam z Allegro jest niezalezna od bramki statusowej. Odczyt uwzglednia cala liste packages.transportingInfo i pomija puste numery. Jedna paczka moze miec numer Allegro i numer przewoznika: potwierdzenie przez jeden z jej rownowaznych numerow oznacza potwierdzenie paczki, a historia wskazuje faktycznie odnaleziony numer. Nie oznacza osobnego dopisania kazdego aliasu.

Przy numerze w istniejacej tabeli Przesylki widoczne sa: brak potwierdzenia, oczekiwanie/bramka statusowa, zielone potwierdzenie, blad z opisem i przyciskiem ponowienia albo pominiecie. Potwierdzenie przekazania nie jest statusem transportu. OMS nie ustawia automatycznie Wyslane po samym utworzeniu etykiety. Ustawienia i ewentualne automatyzacje Allegro moga niezaleznie reagowac na otrzymanie numeru.

Ponowienia po timeoutach, limitach i bledach przejsciowych zaczynaja sie od odczytu Allegro. Nie wykonuja slepego ponownego POST. Sukces i blad sa zapisywane w istniejacej historii zamowienia, oddzielnie od bledu nadania u kuriera. Historie eksportu zachowuje sie po usunieciu lokalnej przesylki lub konta. Odzyskiwanie kolejki dotyczy wylacznie zapisanych zgloszen, bez wyszukiwania starych numerow w przesylkach. Kazde zadanie i odnowienie OAuth zachowuje obowiazkowy User-Agent.

Etap nie dodaje faktur, recznego formularza numerow spoza OMS, automatycznego kasowania numerow w Allegro, nowego ekranu logow ani wstecznej synchronizacji numerow nadanych poza OMS. Pierwszy test rzeczywistego zapisu wymaga kontrolowanego zamowienia i jawnego wlaczenia funkcji.

---

# ALLEGRO.3B - przekazywanie numerow przesylek

Zakladka Integracje -> Allegro -> Przesylki ma trzy tryby: Nie przekazuj (domyslny), Po uzyskaniu numeru nadania oraz Po przejsciu do wybranego statusu OMS. Lista statusow pochodzi z aktualnych, nieusunietych ustawien OMS; mozna wybrac takze Nowe i statusy wlasne. Sam zapis ustawien, wlaczenie lub zmiana trybu nie zleca wysylki historycznych numerow. Zmiana konta, sprzedawcy lub srodowiska oraz rozlaczenie wylaczaja funkcje.

Obserwowane sa nowe numery zwrocone przez istniejace integracje InPost Paczkomaty, InPost Kurier i DPD oraz numery poszczegolnych paczek, takze uzyskane pozniej. Nie powstaje drugi formularz nadania ani workflow pakowania. Tryb statusowy obsluguje obie kolejnosci: numer przed statusem i numer po statusie. Wyjscie z wybranego statusu wstrzymuje nieprzekazane zadanie do ponownego wejscia. Zapis nowej konfiguracji uniewaznia oczekujace zadania; stare numery nie sa samoczynnie przenoszone do nowej konfiguracji.

OMS sprawdza aktualne konto, sprzedawce, srodowisko, zakup, przesylke i numer. Niepelne, wymagajace sprawdzenia, anulowane, zastapione lub usuniete zamowienia oraz anulowane/usuniete przesylki nie sa przekazywane. Pobranie nie blokuje funkcji. Przed zapisem OMS odczytuje aktualny checkout-form i istniejace numery w Allegro. Przekazuje przewoznika oraz numer tylko gdy brakuje takiego przypisania. Nie zgaduje przewoznika po nazwie metody dostawy; uzywa integracji kurierskiej i aktualnej listy przewoznikow Allegro.

Kazdy numer dotyczy calego zrodlowego zamowienia Allegro. Numery sa przypisywane do aktualnych zrodlowych lineItem.id, nie do lokalnego product_id ani zmienionej listy produktow OMS. Rozdzielenie produktow pomiedzy paczki i wysylki czesciowe pozostaja poza etapem. Istniejacego przypisania tylko do czesci pozycji OMS nie rozszerza automatycznie, lecz pokazuje blad wymagajacy sprawdzenia.

Wysylam z Allegro automatycznie przypisuje paczki po swojej stronie. OMS wykonuje w tym przypadku tylko weryfikacje, nigdy POST z ponownym dodaniem numeru. Tryb zaleznosci od statusu nie opoznia automatycznej operacji Allegro; po wlaczeniu funkcji weryfikacja Wysylam z Allegro jest niezalezna od bramki statusowej. Odczyt uwzglednia cala liste packages.transportingInfo i pomija puste numery. Jedna paczka moze miec numer Allegro i numer przewoznika: potwierdzenie przez jeden z jej rownowaznych numerow oznacza potwierdzenie paczki, a historia wskazuje faktycznie odnaleziony numer. Nie oznacza osobnego dopisania kazdego aliasu.

Przy numerze w istniejacej tabeli Przesylki widoczne sa: brak potwierdzenia, oczekiwanie/bramka statusowa, zielone potwierdzenie, blad z opisem i przyciskiem ponowienia albo pominiecie. Potwierdzenie przekazania nie jest statusem transportu. OMS nie ustawia automatycznie Wyslane po samym utworzeniu etykiety. Ustawienia i ewentualne automatyzacje Allegro moga niezaleznie reagowac na otrzymanie numeru.

Ponowienia po timeoutach, limitach i bledach przejsciowych zaczynaja sie od odczytu Allegro. Nie wykonuja slepego ponownego POST. Sukces i blad sa zapisywane w istniejacej historii zamowienia, oddzielnie od bledu nadania u kuriera. Historie eksportu zachowuje sie po usunieciu lokalnej przesylki lub konta. Odzyskiwanie kolejki dotyczy wylacznie zapisanych zgloszen, bez wyszukiwania starych numerow w przesylkach. Kazde zadanie i odnowienie OAuth zachowuje obowiazkowy User-Agent.

Etap nie dodaje faktur, recznego formularza numerow spoza OMS, automatycznego kasowania numerow w Allegro, nowego ekranu logow ani wstecznej synchronizacji numerow nadanych poza OMS. Pierwszy test rzeczywistego zapisu wymaga kontrolowanego zamowienia i jawnego wlaczenia funkcji.

---

# ALLEGRO.3C - przekazywanie Faktur VAT

Zakladka Integracje -> Allegro -> Faktury ma domyslnie wylaczony automat: Nie przekazuj, Po wystawieniu faktury albo Po wybranym statusie OMS. Statusy pochodza z aktualnych ustawien OMS, bez blokady statusu poczatkowego. Domyslnie przekazywane sa wszystkie wystawione Faktury VAT; opcjonalny filtr ogranicza automat do zakupow z zadaniem faktury.

Zapis i wlaczenie konfiguracji nie wysylaja historycznych dokumentow. Przyszla zmiana statusu moze zakwalifikowac istniejaca Fakture; obslugiwane sa Faktura przed statusem i Faktura wystawiona juz w danym statusie. Reczne przekazanie wymaga potwierdzenia i aktualnej wersji Faktury. Jest mozliwe przy wylaczonym automacie, omija jego filtr i status, ale nie omija ochrony dokumentow, konta ani KSeF.

OMS przesyla numer i prywatny PDF istniejacej Faktury VAT, bez wystawiania drugiego dokumentu i bez publicznego linku. Przed pierwszym zapisem sprawdza aktualne zamowienie i dokumenty Allegro. Zakupy niepelne, wymagajace sprawdzenia, anulowane, obce lub usuniete nie sa przekazywane. Wczesniej dodany dokument wymaga sprawdzenia zamiast automatycznej podmiany.

Przekazywany PDF jest zamrozona kopia z suma SHA-256 i unikatowa nazwa pliku. Rozpoczeta, niejednoznaczna wysylka jest wylacznie sprawdzana w Allegro, bez slepego ponownego uploadu. Sam numer Faktury nie potwierdza tozsamosci pliku. Po jednoznacznym odrzuceniu mozna ponowic te sama kopie. Kazda sciezka API i OAuth, rowniez multipart po 401, zachowuje wymagany User-Agent.

Przy Fakturze widac brak przekazania, oczekiwanie na status/KSeF/wysylke, przeslanie z trwajaca weryfikacja, pozytywne potwierdzenie bezpieczenstwa albo blad/wynik wymagajacy sprawdzenia. Po maksymalnie 10 automatycznych kontrolach mozna jawnie zlecic kolejne sprawdzenie. Historia zamowienia pokazuje fakty przeslania i weryfikacji; ikona wystawionej Faktury na liscie nadal oznacza wystawienie, a nie eksport.

Faktury KSeF sa wysylane dopiero po akceptacji i przygotowaniu finalnego PDF. Podglady, dokumenty odrzucone i nierozstrzygniete nie sa przekazywane. Dla Offline uzywany jest istniejacy serwis zaakceptowanej wizualizacji, bez omijania ochrony przedwczesnego doreczenia. Dokumenty KSeF TEST/DEMO nie trafiaja do produkcyjnego Allegro.

Pozniejsza dozwolona edycja Faktury nie zmienia kopii Allegro; OMS pokazuje ostrzezenie o rozbieznosci. Usuniecie lokalnej Faktury nie usuwa dokumentu Allegro ani historii eksportu i prywatnej kopii. Korekty, Pro formy, dokumenty zewnetrzne, podmiana/kasowanie plikow Allegro, masowy eksport historii i wczesne doreczenia Offline pozostaja poza tym etapem.

---

# ALLEGRO.3D - przekazywanie zamknietych Korekt

Zakladka Faktury zawiera niezalezna sekcje Korekty: Nie przekazuj (domyslnie), Po zamknieciu korekty albo Po wybranym statusie OMS. Lista statusow jest aktualna, bez blokowania statusu poczatkowego. Filtr zadania Faktury VAT nie dotyczy Korekt. Zapis ustawien nie wysyla dokumentow historycznych; przyszle zamkniecie Korekty albo zmiana na wybrany status moze zakwalifikowac dokument.

Numerowana, lecz nadal edytowalna Korekta nie jest przekazywana. Wymagane sa status issued, numer, finalized_at, prawidlowa Faktura zrodlowa i spojny liniowy lancuch Korekt tego samego zamowienia. Obslugiwane sa obie kolejnosci: zamkniecie przed statusem oraz zamkniecie juz w docelowym statusie. Zamkniecie pozostaje idempotentne; nie zmienia numeracji, kwot ani snapshotow.

Kazda zamknieta Korekta ma w karcie zamowienia wlasny stan eksportu i jawna operacje reczna z potwierdzeniem. Reczne przekazanie jest mozliwe przy wylaczonym automacie. Istniejaca Faktura i poprzednie Korekty w Allegro nie blokuja dodania kolejnej Korekty; nie sa adoptowane, podmieniane ani usuwane. Jesli OMS nie potwierdzil Faktury zrodlowej w tym samym Allegro, automat oczekuje, a operator moze sprawdzic dokument i jawnie przekazac Korekte. Nie powoduje to automatycznej wysylki Faktury zrodlowej.

Anulowanie zamowienia w OMS lub Allegro nie blokuje Korekty. Powiazanie Allegro musi jednak pochodzic z zakonczonego importu, nalezec do aktualnego sprzedawcy i srodowiska i nie moze byc zastapione, niepelne ani usuniete. Odmowa API, np. limit dokumentow, jest widoczna przy Korekcie; nie zmienia statusu zamowienia ani finansow.

Korekty wspoldziela bezpieczna kolejke, prywatne kopie PDF, sumy SHA-256, blokade zamowienia i odczyt wyniku po niejednoznacznej wysylce z ALLEGRO.3C. Kazdy dokument ma odrebny numer, plik i rekord eksportu. Ochrona KSeF uwzglednia Korekte oraz jej Fakture zrodlowa: wymagany jest wlasny zaakceptowany dokument i finalny PDF, a TEST/DEMO nie trafia do produkcyjnego Allegro. Offline korzysta z istniejacej zaakceptowanej, zamrozonej wizualizacji. Wszystkie zadania API/OAuth zachowuja obowiazkowy User-Agent.

Etap nie tworzy Korekt, zwrotow platnosci ani zwrotow prowizji. Nie dodaje wysylki Pro form, zewnetrznych PDF, masowego eksportu historii ani podmiany plikow w Allegro.

---

# ALLEGRO.4A - odczyt zwrotow klienckich

Zamowienia -> Zwroty otwiera Wszystkie zwroty. Lista i karta korzystaja ze stylistyki zamowien OMS. Lista ma domyslnie 20 pozycji na strone, wybor rozmiaru, paginacje oraz filtry statusu, integracji i daty zgloszenia. Wyszukiwanie obejmuje identyfikatory, klienta, nazwe produktu, numery paczek zwrotnych i glowne numery przesylek zamowienia.

Integracje -> Allegro -> Zwroty zawiera domyslnie wylaczone pobieranie i date poczatkowa (domyslnie dzisiaj). Zapis ustawien nie wywoluje API. Po wlaczeniu harmonogram kolejkuje odczyt nowych zgloszen i odswiezanie znanych zwrotow co okolo 5 minut, z uwzglednieniem czasu pracy kolejki. Odswiezane sa rowniez zakonczone zgloszenia, poniewaz dane paczki lub rozliczenia prowizji moga zostac uzupelnione pozniej.

Jedno zgloszenie Allegro jest jednym zwrotem OMS; deduplikacja obejmuje srodowisko, sprzedawce i ID zwrotu. Kilka zgloszen do jednego zakupu pozostaje oddzielnych. Brak zamowienia w OMS nie blokuje importu. Powiazanie jest uzupelniane podczas kolejnego odczytu, wylacznie przez pelna tozsamosc zamowienia Allegro. Nazwa produktu, SKU ani EAN nie sluza do zgadywania relacji.

Karta pokazuje zwracane produkty, ilosci, ceny i wartosc produktow, powody i komentarze, dane klienta, status Allegro, adres pierwotnej dostawy i dokumenty zamowienia. Osobno widoczne sa Przesylki (zamowienie), ze wszystkimi znanymi numerami paczek wyslanych klientowi, oraz Przesylki zwrotne z Allegro. Historia wiadomosci pochodzi z istniejacego zamowienia (ostatnie 20, pozostale w zamowieniu). Historia zwrotu zapisuje tylko zmiany zaobserwowane przez OMS i ma osobna paginacje po 20 wpisow.

Status zwrotu nie zmienia statusu zamowienia. Wartosc produktow nie oznacza kwoty wykonanej refundacji; karta pokazuje Brak danych dla zwroconych srodkow. Ten etap nie pobiera historii refundacji ani danych rachunku bankowego. Nie uruchamia zwrotu pieniedzy, korekty, zwrotu prowizji, zmian magazynowych, akceptacji/odrzucenia zwrotu, nadawania paczki ani wysylania wiadomosci. Nie ma atrap przyciskow takich operacji.

Rozlaczenie lub zmiana tozsamosci konta wylacza odczyt bez kasowania historii. Bledna odpowiedz API nie nadpisuje zapisanych danych. Nieznany przyszly status pozostaje widoczny. Usuniecie powiazanego zamowienia, konta albo produktu nie usuwa zwrotu ani jego historycznej nazwy, ceny i adresu z momentu pierwszego powiazania.

---

# Zwroty OMS - wszystkie zrodla i tworzenie reczne

Lista Wszystkie zwroty wspoldzieli wyglad z aktualna lista zamowien OMS: kolumny, typografie, odstepy, zaznaczanie, gwiazdki, filtry i paginacje. W panelu bocznym znajduje sie Stworz zwrot oraz niezalezne statusy zwrotow z licznikami. Na malym ekranie przycisk tworzenia jest dostepny takze nad tabela. Domyslnie widac 20 pozycji; mozna wybrac 20/30/50/75/100/150/200. Zaznaczanie zbiorcze dotyczy aktualnej strony, a zmiana statusu zaznaczonych pozycji jest atomowa.

Stworz zwrot pozwala wyszukac dowolne istniejace zamowienie lub utworzyc samodzielny zwrot bez zamowienia. Kazda karta zamowienia ma Akcje -> Stworz zwrot, niezaleznie od zrodla: reczne, PrestaShop, Allegro lub inne. Powiazany zwrot pobiera dane klienta, walute, ceny i nazwy produktow z zamowienia po stronie serwera. Operator wybiera ilosci, powod i komentarz. Mozna tworzyc kilka czesciowych zwrotow; formularz pokazuje poprzednie zgloszenia i pozostale ilosci. Nie dopasowujemy samodzielnych pozycji do katalogu po nazwie, SKU ani EAN; ID produktu jest opcjonalne i jawne.

Statusy obslugi zwrotu sa konfigurowane w OMS, nie w integracji. Poczatkowo sa to Nowe, Odebrane, W trakcie, Zakonczone i Odrzucone. Mozna zmieniac ich nazwy, kolory i kolejnosc oraz dodawac wlasne statusy. Nie wolno usuwac statusu poczatkowego ani aktualnie uzywanego. Nazwy zapisane w historii pozostaja historyczne po zmianie nazwy statusu. Status Odrzucone zwalnia zarezerwowane ilosci; ponowne otwarcie recznego zwrotu ponownie sprawdza dostepne ilosci.

Lokalny status i jego data sa oddzielone od statusu Allegro i daty jego odczytu. Import nie nadpisuje decyzji operatora, gwiazdki ani uwag OMS. Zrodlo zamowienia i sposob utworzenia zwrotu sa osobnymi informacjami. Reczny zwrot do zamowienia Allegro nie posiada zewnetrznego ID zwrotu i nie jest automatycznie laczony z pozniej pobranym zgloszeniem Allegro.

Utworzenie jest chronione przed podwojnym wyslaniem formularza, zmiana zamowienia w innym oknie i przekroczeniem znanych pozostalych ilosci. Ceny i sumy sa liczone dokladnie, bez float. Pozycje i adres maja wlasne snapshoty; usuniecie zamowienia lub produktu nie kasuje historii zwrotu. Oryginalne przesylki wyslane klientowi sa nadal pokazywane oddzielnie od przesylek zwrotnych. Ten etap nie wykonuje refundacji, korekt, operacji magazynowych ani zapisu do API Allegro. Szczegoly wdrozenia: docs/returns-general-report.md.

---

# ALLEGRO.4B.1 - odczyt refundacji i lokalne przypisanie

Aktualizacja ALLEGRO.4B.2 dodaje osobny, reczny workflow zlecania refundacji opisany ponizej. Ograniczenia 4B.1 dotyczace braku POST i automatycznego przypisania pozostaja opisem zakresu historycznego 4B.1.

Integracje -> Allegro -> Zwroty zawiera niezalezna sekcje Zwroty wplat. Pobieranie jest domyslnie wylaczone, ma wlasna date poczatkowa i wymaga opcjonalnego uprawnienia allegro:api:payments:read. Brak tego uprawnienia nie blokuje dotychczasowego importu zamowien. Zapis ustawien nie wywoluje API. Po wlaczeniu kolejka pobiera refundacje oraz odswieza znane rekordy, takze zakonczone, co okolo 5 minut. Otwarcie karty czyta lokalne dane, bez przycisku odswiezania i bez wywolania API.

Refundacja jest faktem z Allegro, niezaleznym od zgloszenia zwrotu towaru. Jedno zamowienie moze miec wiele refundacji i wiele kart zwrotu. Deduplikacja obejmuje srodowisko, sprzedawce i ID refundacji. Powiazanie wykorzystuje wylacznie jawne ID zamowienia Allegro i pelna tozsamosc konta; OMS nie zgaduje po kliencie, nazwie produktu ani samym ID platnosci. Rekord bez ID zamowienia jest zachowany, ale nie pojawia sie na przypadkowej karcie. Brak lokalnego zamowienia nie blokuje zapisu; relacje mozna uzupelnic przy kolejnym odczycie.

Historia platnosci zwrotu na karcie zwrotu i historia refundacji w platnosciach zamowienia pokazuja status, kwote zlecenia, produkty, dostawe, pozostale skladniki, date zlecenia i ID refundacji. Data utworzenia zlecenia nie jest przedstawiana jako data wykonania przelewu. Historia zapisuje tylko zaobserwowane zmiany, po 20 pozycji na strone. Nieznane statusy sa zachowane. PARTIAL nie oznacza pelnego potwierdzenia kwoty zlecenia.

Operator moze lokalnie przypisac potwierdzona refundacje SUCCESS do karty zwrotu, osobno za produkty i dostawe. Poczatkowe przypisanie wynosi zero; brak automatycznego przypisania nawet przy jednej karcie zwrotu. Kwota produktow nie moze przekroczyc wartosci produktow zwrotu ani pozostalej puli refundacji, a dostawa ma osobny limit wynikajacy z danych Allegro. Ta sama kwota nie moze zostac przypisana drugi raz. Mozna zmienic lub wyzerowac przypisanie; historia zachowuje poprzednie kwoty. Dotyczy to takze recznych kart powiazanych z zamowieniem Allegro.

Podsumowanie Zwrocono srodki i ikona dolara dotycza wylacznie przypisanych, potwierdzonych kwot. Brak przypisania oznacza Brak danych, a nie dowod braku przelewu. Refundacja zamowienia w toku lub wymagajaca sprawdzenia daje ostrzezenie, bez automatycznego przypisania jej kwoty. Blad odczytu zachowuje ostatnie poprawne dane. Pozniejsza zmiana statusu lub kwot nie usuwa starych przypisan, lecz oznacza je do sprawdzenia i nie zalicza niespojnych kwot do potwierdzonego podsumowania.

OMS nie kopiuje kosztu pierwotnej dostawy jako kwoty refundacji. Brak delivery oznacza zero, a nie pelny koszt wysylki zamowienia. Nieustalony podzial skladnikow, w tym zlecenie obejmujace doplaty surcharges, nie pozwala przypisywac domniemanej kwoty produktow. Odczyt zachowuje znane skladniki i kwote zlecenia bez zgadywania.

Etap nie zleca przelewow, nie zmienia paid_amount ani statusu platnosci zamowienia, nie wystawia Korekt, nie zmienia magazynu i nie wysyla zadania zwrotu prowizji. Faktyczny zwrot pieniedzy z decyzja, czy zwracac koszt dostawy, nalezy do osobnego ALLEGRO.4B.2. Szczegoly wdrozenia i weryfikacji: docs/allegro-4b1-report.md.

# ALLEGRO.4B.2 - reczne zlecanie zwrotu wplaty

Karta zwrotu powiazanego z zamowieniem Allegro otrzymuje przycisk Zwroc wplate. Osobne przelaczniki w Integracje -> Allegro -> Zwroty domyslnie nie prosza o payments:write i nie zezwalaja na zlecanie refundacji. Opcjonalne uprawnienie allegro:api:payments:write nie jest wymagane przez inne funkcje Allegro. Zapis konfiguracji nie wywoluje API. Wlasciciel potwierdzil zabezpieczenie calego panelu Basic Auth w Nginx/Apache; jest to warunek bezpiecznego udostepnienia tej operacji, razem z HTTPS i ochrona wszystkich tras. Nie powstaje drugi system logowania.

Formularz pozwala wybrac produkty, ilosci i czesciowe kwoty. Zaznaczenie Zwroc rowniez koszt dostawy jest domyslnie wylaczone i dotyczy tylko biezacego zlecenia. Dostawa ma oddzielna kwote i limit. Podsumowanie pokazuje kupujacego, konto, srodowisko, kwoty produktow, dostawe, powod i opcjonalny komentarz. Osobny checkbox oraz przycisk Zlec zwrot X PLN zatwierdzaja rzeczywista operacje; samo otwarcie formularza, podsumowanie ani anulowanie jej nie wykonuja. Potwierdzenie jest przypisane do sesji i wazne przez 5 minut.

OMS odczytuje aktualne zamowienie i cala historie refundacji przy przygotowaniu, potwierdzeniu i bezposrednio przed wyslaniem. Kwoty sa stringami dziesietnymi, a obliczenia korzystaja z BigDecimal bez float. Limity obejmuja wczesniejsze oraz oczekujace refundacje i wszystkie karty tego samego zamowienia. Dodatkowy lokalny rejestr blokuje rownolegle zlecenia i zmiany przypisan, takze po utracie odpowiedzi. Pozycje sa powiazane przez order_item_id i oryginalne Allegro lineItem.id, nigdy przez nazwe lub SKU. API otrzymuje jawne kwoty AMOUNT. Obecny zakres wymaga lokalnego zamowienia, pelnej pojedynczej platnosci ONLINE oraz jednoznacznych skladnikow. Doplaty, uslugi dodatkowe, kaucje, nieustalony podzial, niejednoznaczne stare refundacje QUANTITY, pobranie i brak wplaty sa blokowane z komunikatem.

Zapisane commandId i tresc refundacji sa niezmienne. Podwojne potwierdzenie i powtorzone zadanie kolejki nie powoduja kolejnego POST. Jedyny automatyczny ponowny POST nastepuje po HTTP 401 i odnowieniu OAuth, z identycznym commandId oraz trescia. Timeout, HTTP 5xx, niejednoznaczna odpowiedz lub przerwanie procesu nie zwalniaja rezerwacji. Nie generujemy nowego UUID i nie ponawiamy takiego POST w tle. Znany ID refundacji pozwala odczytywac wynik; przy braku ID operator moze jawnie potwierdzic powiazanie z ID znalezionym w Allegro. OMS sprawdza wtedy tozsamosc, skladniki, kwoty, powod, komentarz i czas. Nie dopasowuje automatycznie obcego przelewu po samej kwocie.

NEW/VALIDATED/IN_PROGRESS/WAITING oznaczaja przyjete zlecenie, a nie zwrot srodkow. Wylacznie zgodne SUCCESS automatycznie przypisuje srodki do karty, z ktorej powstalo zlecenie. Takiego przypisania nie zmienia sie recznie przez mechanizm 4B.1. PARTIAL i nieznane wyniki wymagaja sprawdzenia i nie zwiekszaja potwierdzonej kwoty. Kontrola znanych refundacji dziala co okolo 5 minut niezaleznie od importu 4B.1 i od wylaczenia nowych zlecen. Historia jest widoczna na karcie zwrotu oraz w platnosciach zamowienia, po 20 wpisow. Zlecenia nie zmieniaja orders.paid_amount, faktur, statusow, magazynu ani zwrotu prowizji. Raport: docs/allegro-4b2-report.md.

# Allegro - reczny wniosek o zwrot prowizji

Na karcie zwrotu powiazanego z zamowieniem Allegro, po prawej stronie Zwroc wplate, znajduje sie Zwrot Prowizji. Obie operacje sa niezalezne: prowizja nie zwraca pieniedzy kupujacemu, nie wymaga wlaczenia zwrotow wplat ani payments:write. Korzysta z istniejacych uprawnien orders:read i orders:write. Wymaga aktywnego konta, pelnej tozsamosci Allegro, istniejacego zamowienia i jednoznacznego powiazania pozycji. Dziala takze na recznej karcie zwrotu do zamowienia Allegro; nie jest dostepna dla PrestaShop ani samodzielnego zwrotu.

Klikniecie otwiera formularz z produktami i ilosciami. Operator potwierdza zaznaczone pozycje przyciskiem Wyslij wniosek; otwarcie i anulowanie nie wysylaja wniosku. Ilosc nie przekracza liczby sztuk na biezacej karcie zwrotu ani oryginalnego zakupu. Allegro pozwala utworzyc tylko jeden wniosek dla danej oryginalnej pozycji zakupu, dlatego nie nalezy zakladac mozliwosci pozniejszego zlozenia wniosku na pozostale sztuki tej samej pozycji. O kwalifikacji i wyniku decyduje Allegro, nie lokalny status zwrotu ani stan platnosci.

OMS sprawdza aktualny zakup i istniejace wnioski przed potwierdzeniem oraz przed wyslaniem. Uwzglednia wnioski automatyczne Allegro i wnioski z innych kart tego samego zakupu. Podwojne klikniecie i powtorzone zadanie nie tworza kolejnego POST. Niepewny wynik jest uzgadniany tylko odczytem po oryginalnym ID pozycji; samo podobienstwo oferty, nazwy lub kwoty nie wystarcza. Ponowne potwierdzenie jest mozliwe tylko wtedy, gdy lokalny rekord ma not_sent i wysylka nie zostala rozpoczeta.

Historia Zwroty prowizji Allegro pokazuje lokalne zlecenia, ilosci, wynik i bezpieczny komunikat bledu, po 20 wpisow. Zaobserwowane zmiany trafiaja rowniez do historii karty. Wyniki sa sprawdzane w tle co okolo 5 minut; otwarcie samej karty nie wywoluje API. Przyznano zwrot prowizji nie oznacza zwrotu wplaty kupujacemu. Funkcja nie zmienia kwot zaplaty zamowienia, dokumentow, statusow ani magazynu. Nie dodaje anulowania wnioskow lub odwolan. Raport: docs/allegro-commission-refunds-report.md.
