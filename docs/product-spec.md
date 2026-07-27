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
- przechowywanie numerów seryjnych,
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
- miasto.

Kraj może być przechowywany technicznie, ale nie musi być stale eksponowany w prostym formularzu.

## 5.2. Dane dostawy

Widoczne i edytowalne:

- imię i nazwisko,
- nazwa firmy,
- ulica,
- numer budynku,
- numer lokalu,
- kod pocztowy,
- miasto.

E-mail i telefon odbiorcy powinny być prezentowane w sekcji informacji o zamówieniu, a nie powielane w głównym bloku adresu.

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
- historia dokumentów,
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

Numer dokumentu jest nadawany dopiero przy finalnym wystawieniu.

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

Etap 1C.2 nie wystawia faktur, nie generuje PDF i nie oblicza VAT. Formularze Korekty i Pro formy pozostają w zakresie Etapu 1C.1 i zostaną rozbudowane później. Paragony nie należą do modułu.

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

Automatyczna akcja wystawiania dokumentu musi przechowywać jawny `invoice_series_id`. Nie wolno w niej wybierać serii na podstawie nazwy ani niejawnej „domyślności”.

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

Jedno zamówienie może mieć wiele dokumentów różnych typów, ale najwyżej jedną istniejącą fakturę VAT. Limit nie dotyczy pro form, korekt, duplikatów ani ponownego wygenerowania PDF tej samej faktury.

## 15.1. Jedna faktura VAT na zamówienie

W przyszłym mechanizmie wszystkie ścieżki wystawiania faktury VAT — ręczne, automatyczne, API i integracje — muszą korzystać z jednego centralnego serwisu, np. `InvoiceIssuingService`.

Serwis przed pobraniem numeru sprawdza, czy faktura VAT już istnieje, i zabezpiecza tę regułę transakcyjnie również przed równoległymi procesami. W przypadku duplikatu użytkownik otrzymuje komunikat:

```text
Nie można wystawić faktury VAT. Faktura do tego zamówienia została już wystawiona.
```

Automatyzacja zwraca błąd biznesowy `invoice_already_exists` z komunikatem `Faktura VAT do zamówienia została już wystawiona.` Błąd nie jest bez końca ponawiany i pozostaje w historii wykonania.

Relacja pozostaje `Order hasMany Invoices`; nie dodajemy `invoice_id` do `orders`.

## 15.2. Docelowy widok faktury przy zamówieniu

Przed wystawieniem widok pokazuje rozwijany przycisk `WYSTAW FAKTURĘ` z aktywnymi seriami typu `invoice` oraz osobny przycisk `PRO FORMA`.

Po wystawieniu przycisk faktury znika. Zastępuje go numer faktury otwierający PDF w nowej karcie przez kontrolowaną trasę Laravel (`target="_blank"`, `rel="noopener"`), bez ujawniania prywatnej ścieżki storage. Obok numeru będą dostępne operacje dokumentu, w tym czerwony krzyżyk opisany dokładnie jako `Usuń fakturę`.

## 15.3. Przyszłe usuwanie faktury i zwalnianie numeru

Fakturę będzie można usunąć wyłącznie, gdy jednocześnie:

- nie została przyjęta przez KSeF,
- nie została wystawiona w trybie offline ani awaryjnym.

Podczas wysyłania albo oczekiwania na odpowiedź KSeF operacja będzie tymczasowo zablokowana. Automatyzacja nie może usuwać faktur. Po potwierdzonym ręcznym usunięciu dokument znika z listy i zamówienia, ponownie można wystawić fakturę VAT, a techniczny audyt zachowuje numer, serię, zamówienie, datę, użytkownika i powód operacji.

Usunięta faktura nie pozostaje jako wyszarzony rekord. Jej numer trafia do przyszłego mechanizmu numerów zwolnionych i może zostać użyty dla dowolnego zamówienia wyłącznie w tej samej serii oraz tym samym okresie resetowania. Generator najpierw pobiera najniższy dostępny zwolniony numer, a dopiero później zwiększa licznik; operacja musi być transakcyjna i odporna na równoległe procesy.

Te zasady są wymaganiami przyszłych etapów. Etap 1C.1 nie implementuje tabel faktur, usuwania, liczników ani KSeF.

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

Na fakturze może być widoczny numer pro formy, jeśli konfiguracja serii tego wymaga.

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
- własną historię.

Planowane przypadki:

- korekta pozycji,
- korekta ilości,
- korekta ceny,
- korekta VAT,
- korekta danych nabywcy,
- pełny zwrot,
- częściowy zwrot.

Kolejna korekta odnosi się do skutecznego stanu dokumentu po wcześniejszych korektach.

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

Pliki są przechowywane prywatnie.

Biblioteka PDF zostanie wybrana w osobnym etapie.

---

# 26. Historia zdarzeń

Planowany podział:

```text
invoice_events — pełna historia dokumentu
order_events   — skrócone zdarzenia związane z fakturą
```

Przykładowe zdarzenia:

- utworzenie,
- wystawienie,
- edycja,
- zmiana nabywcy,
- zmiana pozycji,
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

Ustawienia serii Faktura wdrożono w Etapie 1C.2. Rozbudowane ustawienia Korekty i Pro formy powstaną w kolejnych etapach.

## Etap 1C.2 — ustawienia serii Faktura

- dane sprzedawcy przechowywane bezpośrednio w serii,
- rachunek bankowy, miejsce wystawienia i wystawiający,
- domyślna seria korekt,
- konfiguracja VAT, dostawy, płatności, dat i pozycji,
- szablon informacji z tokenem `[uwagi_sprzedawcy]`,
- podstawowa konfiguracja przyszłego wydruku,
- prywatne logo serii.

Etap nie tworzy dokumentów ani PDF. Korekta i Pro forma nadal wymagają kolejnych etapów rozbudowy.

## Etap 1D — VAT, produkty, JPK i GTU

- źródło VAT,
- stała stawka VAT,
- pomijanie pozycji zerowych,
- wewnętrzne ID produktu,
- GTU,
- procedury JPK,
- strategie łączenia oznaczeń.

## Etap 1E — wysyłka, waluty, płatności i daty

## Etap 1F — dane sprzedawcy i informacje dodatkowe

## Etap 1G — wygląd dokumentu

## Etap 2 — liczniki i generator numerów

## Dalsze etapy

- model faktury,
- pozycje faktury,
- wystawianie z zamówienia,
- edycja,
- pro formy,
- korekty,
- PDF,
- dokumenty zewnętrzne,
- historia,
- e-mail,
- rejestr sprzedaży,
- JPK,
- audyt KSeF,
- KSeF.

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
- wybór biblioteki PDF,
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
