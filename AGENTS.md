# AGENTS.md

## Cel

Ten plik zawiera stałe zasady pracy dla Codexa i innych agentów kodujących w projekcie **NEX-OMS**.

Obowiązuje dla całego repozytorium:

```text
C:\projekty\nex-oms
```

Jeżeli w podkatalogu pojawi się bardziej szczegółowy `AGENTS.md`, jego zasady mają pierwszeństwo wyłącznie dla tego podkatalogu.

---

# 1. Źródło prawdy

Jedynym źródłem prawdy jest aktualna zawartość lokalnego katalogu projektu.

Przed rozpoczęciem zadania:

1. Przeczytaj ten plik.
2. Przeczytaj `docs/product-spec.md`, jeśli istnieje.
3. Przeczytaj `docs/architecture.md`, jeśli istnieje.
4. Sprawdź rzeczywisty kod, migracje, modele, kontrolery, Form Requesty, widoki, trasy, konfigurację i testy.
5. Nie zakładaj, że GitHub, README lub wcześniejsze raporty są aktualniejsze od lokalnego kodu.
6. Nie twórz duplikatów istniejących klas, tras, migracji, modeli, widoków ani usług.

Jeżeli dokumentacja jest sprzeczna z kodem:

- nie zgaduj,
- wskaż sprzeczność,
- traktuj kod jako stan faktyczny,
- poproś właściciela o decyzję, jeśli zmiana wpływa na zachowanie biznesowe.

---

# 2. Aktualna architektura projektu

Projekt jest modularnym monolitem Laravel.

Aktualne informacje środowiskowe:

```text
PHP: ^8.2
Laravel: 12
Frontend: Blade + Bootstrap 5 + Bootstrap Icons
Lokalna baza: SQLite
Baza testowa: SQLite :memory:
Namespace modułów: Modules\
```

Aktywne moduły:

```text
Modules/Automation
Modules/Integrations
Modules/Invoices
Modules/Shipments
```

Aktualne konwencje:

- trasy znajdują się centralnie w `routes/web.php`,
- migracje znajdują się w `database/migrations`,
- widoki faktur znajdują się w `resources/views/invoices`,
- testy znajdują się w `tests`,
- moduły nie mają obecnie własnych providerów, tras ani migracji,
- namespace `Modules\` jest ładowany przez Composer.

Nie wprowadzaj osobnego providera modułu, własnego systemu routingu ani nowej konwencji katalogów bez wyraźnej decyzji architektonicznej.

---

# 3. Sposób pracy

Pracuj małymi, kontrolowanymi etapami.

Dla każdego zadania:

1. Najpierw przeanalizuj istniejące rozwiązanie.
2. Określ dokładny zakres plików do zmiany.
3. Zmieniaj wyłącznie elementy wymagane w bieżącym etapie.
4. Nie rozszerzaj samodzielnie zakresu.
5. Zachowuj zgodność z aktualnym stylem projektu.
6. Dodaj lub zaktualizuj adekwatne testy.
7. Uruchom bezpieczne testy.
8. Podaj listę zmienionych plików i instrukcję ręcznej weryfikacji.

Nie wykonuj dodatkowych refaktoryzacji „przy okazji”, chyba że są bezpośrednio konieczne do ukończenia zadania.

Nie usuwaj istniejących funkcji bez wyraźnej zgody właściciela.

---

# 4. Git

Właściciel projektu obsługuje Git ręcznie.

Nie wykonuj:

```text
git commit
git push
git pull
git fetch
git merge
git rebase
git reset
git checkout
git restore
git clean
```

Nie cofaj istniejących zmian użytkownika.

Nie zakładaj, że zdalne repozytorium zawiera najnowszą wersję projektu.

Nie wymagaj czystego katalogu Git jako warunku rozpoczęcia pracy.

---

# 5. Bezpieczeństwo zmian

Nigdy nie używaj:

```text
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

Nie usuwaj danych użytkownika.

Nie zmieniaj bez wyraźnego polecenia:

```text
.env
.env.example
composer.lock
package-lock.json
README.md
```

Nie instaluj ani nie aktualizuj zależności bez osobnej zgody.

Nie zmieniaj nazw istniejących tabel, kolumn, tras ani publicznych metod bez analizy kompatybilności.

Migracje muszą być bezpieczne dla istniejącej bazy:

- twórz nowe migracje zamiast edytować wcześniej wykonane,
- nie zakładaj pustej bazy,
- używaj `nullable`, gdy historyczne dane mogą nie posiadać nowej relacji,
- dodawaj indeksy i klucze obce świadomie,
- jawnie określaj zachowanie `onDelete`,
- przy relacjach historycznych preferuj `nullOnDelete` zamiast kaskadowego usuwania danych dokumentowych.

---

# 6. PHP, Laravel i Windows

Projekt działa lokalnie w Windows/XAMPP.

Jeżeli polecenie `php` nie jest dostępne, użyj:

```text
C:\xampp\php\php.exe
```

Przykłady:

```text
C:\xampp\php\php.exe artisan about
C:\xampp\php\php.exe artisan route:list
C:\xampp\php\php.exe artisan test
C:\xampp\php\php.exe artisan migrate
```

Przed użyciem komendy zapisującej upewnij się, że należy ona do zakresu bieżącego etapu.

Stosuj aktualne konwencje projektu:

- modele głównej aplikacji w `app/Models`,
- modele modułu faktur w `Modules/Invoices/Models`,
- kontrolery modułu faktur w `Modules/Invoices/Http/Controllers`,
- Form Requesty modułu faktur w `Modules/Invoices/Http/Requests`,
- serwisy modułu faktur w `Modules/Invoices/Services`,
- enumy modułu faktur w `Modules/Invoices/Enums`,
- widoki faktur w `resources/views/invoices`,
- migracje w `database/migrations`,
- testy w `tests/Unit/Invoices` i `tests/Feature/Invoices`.

Nie przenoś istniejących klas między `app` i `Modules` bez wyraźnej decyzji.

---

# 7. Baza danych i wartości pieniężne

Nie używaj `float` do krytycznych obliczeń finansowych ani trwałego przechowywania kwot.

Dla kwot:

- używaj `DECIMAL` o jawnej precyzji i skali,
- zachowuj wartości jako stringi dziesiętne lub używaj bezpiecznej arytmetyki,
- stosuj jedną, udokumentowaną regułę zaokrągleń,
- licz wartości po stronie serwera,
- testuj netto, VAT i brutto,
- testuj różnice groszowe,
- testuj sumowanie pozycji i wysyłki.

Dokumenty finansowe muszą przechowywać własne dane historyczne. Nie mogą zależeć wyłącznie od aktualnego stanu zamówienia, produktu lub serii numeracji.

Aktualny kod zamówień używa miejscami `float`. Nie kopiuj tego wzorca do faktur.

---

# 8. Testy

Po zmianach uruchom testy adekwatne do zakresu.

Preferowana kolejność:

1. testy jednostkowe zmienianego elementu,
2. testy feature zmienianego modułu,
3. testy powiązanego obszaru,
4. pełny `artisan test`, jeśli jest bezpieczny i uzasadniony.

Aktualne testy korzystają z SQLite `:memory:`. Mimo to przed uruchomieniem sprawdź konfigurację testową.

Nie uruchamiaj testów przeciwko lokalnej roboczej bazie użytkownika.

Jeżeli nie można bezpiecznie uruchomić testów:

- nie zgaduj,
- podaj konkretny powód,
- podaj bezpieczną komendę do ręcznego wykonania.

Każda nowa funkcja biznesowa powinna mieć testy obejmujące:

- poprawny przypadek,
- walidację,
- przypadek brzegowy,
- ochronę danych historycznych,
- relacje i zachowanie przy usuwaniu,
- uprawnienia, jeśli występują.

---

# 9. Raport po zadaniu

Po każdej implementacji podaj:

## Zmienione pliki

Lista:

- utworzonych,
- zmienionych,
- usuniętych.

## Wykonane zmiany

Krótki opis funkcjonalny.

## Migracje

- nazwa migracji,
- czy została uruchomiona,
- bezpieczna komenda do uruchomienia.

## Testy

- wykonane komendy,
- liczba testów,
- wynik,
- ewentualne błędy.

## Weryfikacja ręczna

Dokładne kroki do wykonania w przeglądarce.

## Ograniczenia i ryzyka

Wskaż wszystko, czego nie udało się sprawdzić lub co wymaga decyzji właściciela.

Nie wykonuj commita ani pusha.

---

# 10. Istniejący szkielet modułu faktur

W projekcie istnieją już:

```text
Modules/Invoices/Http/Controllers/InvoiceController.php
routes/web.php — trasa GET /invoices
resources/views/invoices/index.blade.php
tests/Feature/InvoicesPageTest.php
link „Faktury” w sidebarze
przyciski WYSTAW FAKTURĘ i PRO FORMA na karcie zamówienia
```

Te elementy są szkieletem lub placeholderem.

Nie twórz ich duplikatów.

Przed rozbudową:

- otwórz istniejący kontroler,
- sprawdź istniejącą trasę,
- sprawdź istniejący widok,
- sprawdź istniejący test,
- rozbuduj istniejące elementy zamiast tworzyć równoległe.

---

# 11. Zamówienia

Dozwolone statusy zamówień:

```text
new        = Nowe
pending    = Oczekujące
shipped    = Wysłane
cancelled  = Anulowane
```

Nowe i importowane zamówienia domyślnie otrzymują:

```text
new
```

Nie dodawaj magazynowego workflow pakowania bez wyraźnego polecenia. Właściciel sam przygotowuje przesyłki.

Dane klienta i adresy są obecnie przechowywane bezpośrednio na `orders`.

Nie twórz ponownie tabel `customers` ani `addresses` bez osobnej decyzji.

Relacja dokumentów:

```text
Order hasMany Invoices
```

Nie dodawaj pojedynczego `invoice_id` do tabeli `orders`.

Relacja `hasMany` obsługuje różne typy dokumentów. Jedno zamówienie może posiadać najwyżej jedną istniejącą fakturę VAT oraz jedną bieżącą logiczną Pro formę z jednym stanem i niezmiennym numerem. Jedna faktura VAT może posiadać wiele kolejnych Korekt tworzących liniowy łańcuch.

---

# 12. Dane produktów w interfejsie

Nie pokazuj w interfejsie zamówień i faktur bez wyraźnej decyzji:

```text
SKU
EAN
lokalizacji magazynowej
atrybutów
identyfikatorów ofert zewnętrznych
```

Planowany jest przyszły moduł:

```text
Produkty
```

Wewnętrzne `product_id` jest dozwolone i potrzebne.

Planowane relacje:

```text
order_items.product_id nullable
invoice_items.product_id nullable
```

Relacje z katalogiem produktów muszą być opcjonalne.

Historyczne pozycje muszą działać bez istniejącego produktu.

Nie dopasowuj automatycznie starych pozycji wyłącznie po nazwie, SKU lub EAN.

---

# 13. Pole „Informacje” serii i zmienna `[uwagi_sprzedawcy]`

Każda seria numeracji posiada pole tekstowe **„Informacje”**. Nie jest to gotowa, stała treść dokumentu, lecz szablon określający, co ma zostać pokazane w sekcji informacji dodatkowych faktury lub pro formy.

Przykład konfiguracji serii:

```text
Numery seryjne zakupionych przedmiotów:
[uwagi_sprzedawcy]
```

Znacznik:

```text
[uwagi_sprzedawcy]
```

pobiera pełną treść pola uwag sprzedawcy z zamówienia. Właściciel wpisuje w tych uwagach numery seryjne.

Przed implementacją odczytaj rzeczywiste mapowanie pola w lokalnym kodzie; według audytu odpowiada mu obecnie `orders.notes`.

Założenia:

- numery seryjne są zwykłą treścią uwag sprzedawcy,
- jedno pole uwag dotyczy całego zamówienia,
- nie przypisujemy numerów seryjnych do pojedynczych pozycji,
- nie tworzymy osobnej tabeli numerów seryjnych,
- nie dodajemy `orders.serial_numbers_text`,
- nie rozpoznajemy ani nie walidujemy automatycznie pojedynczych numerów seryjnych,
- numery seryjne pojawią się na dokumencie tylko wtedy, gdy szablon pola „Informacje” zawiera `[uwagi_sprzedawcy]`.

W modelu serii przechowuj szablon, a nie wyrenderowany tekst. Preferowana nazwa pola:

```text
additional_information_template
```

Podczas wystawiania dokumentu:

1. pobierz `additional_information_template` z wybranej serii,
2. pobierz aktualne uwagi sprzedawcy z zamówienia,
3. zastąp wszystkie wystąpienia `[uwagi_sprzedawcy]` ich pełną treścią,
4. zapisz wyrenderowany wynik jako snapshot informacji dodatkowych dokumentu.

Na wystawionym dokumencie przechowuj wynik, a nie zależność od szablonu serii lub zamówienia. Późniejsza zmiana uwag albo konfiguracji serii nie może zmienić dokumentu historycznego.

Jeżeli uwagi sprzedawcy są puste, znacznik zastąp pustym tekstem. Nie pozostawiaj literalnego `[uwagi_sprzedawcy]` na PDF ani w danych dokumentu.

---

# 14. Moduł faktur — zakres

Docelowy zakres:

```text
serie numeracji
faktury VAT
faktury pro forma
korekty
duplikaty
PDF
dokumenty zewnętrzne
wysyłka e-mail
rejestr sprzedaży
zdarzenia cyklu życia dokumentów bez kopii poprzednich stanów
JPK
GTU
wewnętrzne ID produktu
```

Obecnie poza zakresem:

```text
paragony
e-paragony
drukarki fiskalne
API Fakturowni
```

---

# 15. Sprzedawca i serie numeracji

NEX-OMS obsługuje jednego właściciela systemu, ale każda seria numeracji może posiadać własne dane sprzedawcy.

Nie twórz:

```text
seller_profiles
company_settings
centralnego wyboru profilu sprzedawcy
```

Dane sprzedawcy należą bezpośrednio do `invoice_series`.

Różne serie mogą mieć:

- te same albo inne dane firmy,
- inny rachunek bankowy,
- inne logo,
- inne miejsce wystawienia,
- innego wystawiającego,
- inne informacje dodatkowe,
- inne ustawienia dokumentu.

Dane sprzedawcy mają być strukturalne.

Planowane osobne pola obejmują między innymi:

- nazwę,
- NIP,
- REGON,
- BDO,
- ulicę,
- numer budynku,
- numer lokalu,
- kod pocztowy,
- miasto,
- województwo,
- kod kraju,
- e-mail,
- telefon,
- nazwę banku,
- rachunek bankowy,
- SWIFT/BIC.

Po wystawieniu dokumentu dane serii muszą zostać zapisane jako snapshot.

Późniejsza zmiana serii nie może zmienić dokumentu historycznego.

---

# 16. Typy dokumentów

Podstawowe typy:

```text
invoice
proforma
correction
```

Każdy typ może mieć wiele serii.

System zawsze posiada dokładnie trzy serie systemowe, identyfikowane technicznie przez stabilne klucze:

```text
invoice
correction
proforma
```

Serie systemowe są zawsze aktywne. Nie wolno ich usuwać, dezaktywować, zmieniać ich typu dokumentu, klucza systemowego ani przekształcać w serie własne. Można zmieniać ich nazwę, format numeru i ustawienia biznesowe.

Serie własne mają `is_system = false` i `system_key = null`. Mogą być ukrywane, ponownie aktywowane i usuwane, o ile nie narusza to integralności dokumentów historycznych.

Nie używaj pola `is_default`. Serię systemową rozpoznawaj wyłącznie przez `is_system` oraz `system_key`, nigdy po nazwie.

Przy ręcznym wystawianiu dokumentu użytkownik wybiera aktywną serię właściwego typu. Automatyczna akcja wystawiania dokumentu musi przechowywać jawny `invoice_series_id`.

Nazwa serii powinna być unikalna w obrębie typu dokumentu.

Seria może być aktywna lub nieaktywna.

W bazie preferowana nazwa pola:

```text
is_active
```

W interfejsie może być prezentowana jako „Pokaż/ukryj”.

Serii użytej przez dokument nie wolno usuwać w sposób niszczący historię.

---

# 17. Numeracja

Format numeracji ma docelowo obsługiwać:

```text
%N
%NN...
%M
%Y
%y
```

Tryby resetowania:

```text
monthly
yearly
none
```

Domyślne propozycje:

```text
Faktura:   BL %N/%Y
Pro forma: BLPF %N/%Y
Korekta:   BLK %N/%Y
Reset:     yearly
```

Resetowania nie ustalaj wyłącznie na podstawie tokenów formatu. Używaj osobnego pola `reset_period`.

Numer dokumentu należy nadawać:

- dopiero przy finalnym wystawieniu,
- transakcyjnie,
- bez ryzyka duplikatu,
- z osobnym licznikiem dla serii i okresu.

Nie implementuj generatora numerów w etapie ograniczonym do modelu serii.

---

# 18. Domyślna seria korekt

Seria faktur może opcjonalnie wskazywać:

```text
default_correction_series_id
```

Relacja jest nullable.

Przy wystawianiu korekty planowana kolejność:

1. seria korekt przypisana do serii dokumentu źródłowego,
2. aktywna seria systemowa z `system_key = correction`,
3. czytelny błąd, jeśli żadna nie istnieje.

Klucz obcy powinien używać `nullOnDelete`.

---

# 19. Tworzenie dokumentu

Faktura i pro forma mają być tworzone z karty zamówienia:

```text
WYSTAW FAKTURĘ
PRO FORMA
```

Przed pierwszym wystawieniem nie planuje się rozbudowanego formularza.

Dane mają zostać skopiowane z zamówienia i serii.

Dokument musi posiadać własne snapshoty:

- sprzedawcy,
- nabywcy,
- opcjonalnego odbiorcy,
- pozycji,
- ilości i jednostek,
- cen netto i brutto,
- stawek i kwot VAT,
- wysyłki,
- płatności,
- waluty,
- numeru zamówienia,
- rozwiązanej wartości `[uwagi_sprzedawcy]` zawierającej numery seryjne,
- GTU,
- procedur JPK,
- informacji dodatkowych.

Zmiana zamówienia nie może automatycznie zmieniać wystawionego dokumentu.

---

# 20. Edycja faktury

Edycja ma dotyczyć snapshotu dokumentu.

Docelowo ekran powinien pokazywać różnice między:

- aktualnym zamówieniem,
- snapshotem faktury.

Planowane akcje:

```text
Kopiuj aktualne pozycje z zamówienia
Kopiuj aktualne dane nabywcy z zamówienia
```

Nie synchronizuj dokumentu automatycznie z zamówieniem.

---

# 21. Pozycje i obliczenia faktury

Każda pozycja faktury powinna docelowo przechowywać:

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
- oznaczenia GTU, jeśli dotyczą.

Aktualne pozycje zamówienia posiadają głównie brutto i opcjonalny VAT. Nie kopiuj ich ograniczeń do modelu faktury.

Obliczenia faktury muszą być wykonywane po stronie serwera bez `float`.

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

Planowane są:

- korekty pozycji,
- korekty ilości,
- korekty cen,
- korekty VAT,
- korekty danych nabywcy,
- zwroty pełne,
- zwroty częściowe.

Kolejna korekta powinna odnosić się do skutecznego stanu po wcześniejszych korektach.

Po wystawieniu korekty nie pozwalaj swobodnie nadpisywać danych finansowych dokumentu źródłowego.

---

# 23. Duplikaty

Duplikat:

- nie otrzymuje nowego numeru faktury,
- używa numeru dokumentu źródłowego,
- zawiera oznaczenie `DUPLIKAT`,
- posiada datę wystawienia duplikatu,
- zapisuje zdarzenie w historii,
- nie zwiększa rejestru sprzedaży.

---

# 24. JPK i GTU

JPK i GTU są częścią planowanego modułu.

Konfiguracja serii może docelowo zawierać:

- domyślne kody GTU,
- domyślne procedury JPK,
- sposób łączenia oznaczeń serii i produktów.

Planowane strategie GTU:

```text
series_only
products_only
merge
```

Rekomendowana wartość domyślna:

```text
merge
```

Końcowe oznaczenia muszą zostać zapisane jako snapshot dokumentu.

Dozwolone kody muszą być walidowane po stronie serwera.

Nie opieraj logiki wyłącznie na checkboxach widoku.

Dla list kodów preferuj walidowane tablice JSON, a nie dowolny tekst.

Pełne generowanie pliku JPK jest osobnym, późniejszym etapem po uruchomieniu faktur, korekt i rejestru sprzedaży.

---

# 25. KSeF — wybrany wariant 2

Wybrana strategia:

```text
Architektura gotowa pod KSeF teraz.
Integracja KSeF dopiero po pełnym sprawdzeniu modułu faktur.
```

Obecnie nie implementuj bez osobnego zadania:

```text
API KSeF
XML FA(3)
ksef_submissions
pól ksef_*
numeru KSeF
statusów KSeF
UPO
kodów QR KSeF
wysyłki do KSeF
```

Projektuj dane strukturalnie, aby późniejsze mapowanie nie wymagało przebudowy podstawowych tabel.

W szczególności:

- dane sprzedawcy mają być rozdzielone na pola,
- dane nabywcy i odbiorcy mają być strukturalne,
- pozycje muszą mieć jednostkę, ilość, netto, VAT i brutto,
- dokument ma mieć własne snapshoty,
- korekta ma być osobnym dokumentem,
- numer faktury ma być niezależny od przyszłego numeru KSeF,
- nie używaj `float`.

---

# 26. PDF i pliki

Pliki dokumentów mają być przechowywane prywatnie.

Pobieranie powinno odbywać się przez kontroler z kontrolą dostępu lub bezpieczny podpisany URL.

Zewnętrzny dokument:

- tylko PDF,
- bez OCR,
- z metadanymi,
- z sumą kontrolną,
- z informacją, który plik jest podstawowy dla klienta.

Etap 2D korzysta z `tecnickcom/tcpdf` do generowania prywatnych plików PDF na żądanie. Nie dodawaj drugiego silnika PDF bez osobnej decyzji architektonicznej.

---

# 27. Zdarzenia dokumentów

Docelowy podział zdarzeń cyklu życia:

```text
invoice_events — wybrane zdarzenia cyklu życia dokumentu
order_events   — skrócone zdarzenia fakturowe widoczne przy zamówieniu
```

Zdarzenia nie przechowują kopii poprzednich stanów dokumentu. Zwykła edycja Faktury nie tworzy zdarzenia. Operacje wystawienia i inne przyszłe zdarzenia cyklu życia powinny być zapisywane w tej samej transakcji co dokument.

---

# 28. Granice audytu

Jeżeli zadanie jest oznaczone jako audyt:

- nie modyfikuj plików,
- nie twórz plików,
- nie usuwaj plików,
- nie uruchamiaj migracji,
- nie uruchamiaj seederów,
- nie instaluj pakietów,
- nie aktualizuj dokumentacji,
- zwróć wyłącznie raport.

---

# 29. Granice Etapu 1A

Jeżeli zadanie dotyczy wyłącznie Etapu 1A, zakres może obejmować tylko:

- migrację `invoice_series`,
- model `InvoiceSeries`,
- enum typu dokumentu,
- enum okresu resetowania,
- enum klucza serii systemowej,
- podstawowe casty,
- relację domyślnej serii korekt,
- utworzenie trzech chronionych serii systemowych,
- testy modelu i migracji.

Etap 1A może zawierać jawne pola:

## Podstawowe

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

## Sprzedawca

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

## Bank i wystawienie

```text
seller_bank_name
seller_bank_account
seller_bank_swift
place_of_issue
issuer_name
logo_path
additional_information_template
```

## Techniczne

```text
created_at
updated_at
```

Nazwa serii ma być unikalna w obrębie `document_type`.

`default_correction_series_id` ma być nullable i używać `nullOnDelete`.

Pola sprzedawcy mogą być nullable na etapie tworzenia serii. Kompletność danych będzie walidowana przed aktywowaniem serii lub wystawieniem dokumentu.

Poza zakresem Etapu 1A:

- kontrolery CRUD,
- trasy CRUD,
- widoki listy,
- formularze,
- modale,
- liczniki,
- generator numerów,
- faktury,
- pozycje faktur,
- PDF,
- JPK eksport,
- KSeF,
- `document_settings` jako ogólny worek JSON,
- dodawanie GTU i procedur JPK przed właściwym etapem.

Nie rozszerzaj zakresu bez zgody właściciela.

---

# 30. Granice Etapu 1B

Etap 1B obejmuje:

- listę serii pod `/invoices/settings/series`,
- 10 rekordów na stronę,
- pustą, nieklikalną gwiazdkę oznaczającą wyłącznie serię systemową,
- aktywowanie i ukrywanie serii własnych,
- blokadę ukrywania oraz usuwania serii systemowych,
- bezpieczne usuwanie aktywnych i nieaktywnych, niepowiązanych serii własnych,
- testy Feature listy i reguł zarządzania.

Pole `is_default` nie istnieje. Nie twórz interfejsu wyboru serii domyślnej.

Tworzenie i edycja serii należą do Etapu 1C. W Etapie 1B ich kontrolki mogą być wyłącznie widoczne i nieaktywne; nie dodawaj pustych tras ani niedziałających linków.

Etap 1B nie obejmuje:

- formularzy tworzenia i edycji,
- modali,
- AJAX,
- liczników numeracji,
- dokumentów, pozycji dokumentów i generatora numerów,
- PDF, JPK, GTU, KSeF i Fakturowni,
- filtrów i wyszukiwania,
- paragonów.

---

# 31. Granice Etapu 1C.1

Etap 1C.1 obejmuje:

- jeden modal Bootstrap wspólny dla tworzenia i edycji serii,
- wybór typu `invoice`, `correction` albo `proforma`,
- ładowanie partiala formularza przez `fetch()`,
- standardowy zapis przez POST albo PATCH,
- podstawowe pola serii: typ, nazwa, format, reset, miesiąc roku fiskalnego, waluta i aktywność,
- wymuszanie `is_system = false` oraz `system_key = null` dla nowych serii,
- ochronę typu, klucza, aktywności i statusu systemowego serii systemowych,
- testy endpointów, walidacji, tworzenia i edycji.

Nie ma pola `is_default`, interfejsu wyboru serii domyślnej ani paragonów.

Przyszła centralna reguła wystawiania faktur VAT:

- jedno zamówienie może posiadać najwyżej jedną istniejącą fakturę VAT,
- ręczne wystawianie, automatyzacje, API i integracje muszą korzystać z jednego serwisu domenowego,
- błąd biznesowy automatyzacji ma kod `invoice_already_exists`,
- relacja pozostaje `Order hasMany Invoices`, bez `orders.invoice_id`.

Po wystawieniu faktury widok zamówienia zastępuje przycisk `WYSTAW FAKTURĘ` numerem dokumentu prowadzącym przez kontrolowaną trasę do prywatnego PDF. Obok numeru jest dostępna operacja `Usuń fakturę`. Ta sama operacja jest dostępna na ekranie edycji dokumentu.

Usunąć można wyłącznie wystawioną Fakturę VAT bez Korekt, posiadającą spójny slot, zamówienie i tożsamość numeracji. Operacja wymaga potwierdzenia z numerem dokumentu i oczekiwanej wersji blokady, pozostawia ślad w `order_events`, ale usuwa Fakturę, jej pozycje, slot i prywatny cache PDF z bieżącego stanu. Pro forma zastąpiona dokładnie przez usuwaną Fakturę zostaje w tej samej transakcji odblokowana, ponownie pokazana w zamówieniu i zachowuje numer oraz bieżący snapshot. Blokady KSeF zostaną rozszerzone po dodaniu pól i procesów tej integracji.

Dozwolone usunięcie nie tworzy ogólnej puli zwolnionych numerów. Wewnętrzne luki nie są ponownie używane. Serwis transakcyjnie cofa wyłącznie wolny koniec numeracji w tej samej serii i okresie, nie niżej niż `protected_floor_sequence_number`, i zapisuje korektę licznika w historii.

Automatyzacje usuwania oraz integracja KSeF pozostają poza bieżącym zakresem.

---

# 32. Granice Etapu 1C.2

Etap 1C.2 rozbudowuje wyłącznie formularz serii typu `invoice` o:

- dane sprzedawcy zapisane bezpośrednio w `invoice_series`,
- rachunek bankowy, miejsce wystawienia i wystawiającego,
- domyślną serię korekt,
- ustawienia VAT, dostawy, płatności, dat i pozycji,
- szablon `additional_information_template` z nierozwiązanym tokenem `[uwagi_sprzedawcy]`,
- podstawowe ustawienia przyszłego wydruku,
- prywatne logo serii.

Dane sprzedawcy mogą być częściowe; pełna walidacja nastąpi przed przyszłym wystawieniem dokumentu. Logo należy do prywatnego dysku `local`. Formularz Pro formy został rozbudowany w Etapie 1C.4. Etap nie tworzy dokumentów, pozycji dokumentów, liczników, PDF, JPK ani KSeF. Nie ma paragonów.

---

# 33. Granice Etapu 1C.3

Etap 1C.3 rozbudowuje wyłącznie formularz serii typu `correction` o:

- domyślny powód korekty,
- źródło daty sprzedaży,
- źródło wystawiającego,
- źródło sposobu płatności,
- ustawienia kolejności pozycji, identyfikatora zwrotu i identyfikatora płatności,
- szablon `additional_information_template` z nierozwiązanym tokenem `[uwagi_sprzedawcy]`,
- wspólne ustawienia przyszłego wydruku.

Dane prawne sprzedawcy, rachunek bankowy i logo korekty będą pochodziły ze snapshotu dokumentu źródłowego, dlatego nie są edytowane w serii korekt. Powód korekty jest tylko wartością domyślną przyszłego dokumentu i zostanie zapisany jako snapshot po wystawieniu korekty. Korekta pozostaje osobnym dokumentem powiązanym z fakturą źródłową, obowiązkowo pokaże wartości przed zmianą, po zmianie i różnicę. Korekta łańcuchowa będzie odnosiła się do skutecznego stanu po wcześniejszych korektach. Etap nie tworzy korekt, faktur, pozycji dokumentów, liczników, PDF, JPK ani KSeF. Formularz Pro formy został rozbudowany w Etapie 1C.4. Nie ma paragonów.

---

# 34. Granice Etapu 1C.4

Etap 1C.4 rozbudowuje formularz serii typu `proforma` o dane sprzedawcy, rachunek bankowy, dane wystawienia, ustawienia VAT, dostawy, płatności, dat, informacji, wydruku oraz prywatne logo. Faktura i Pro forma współdzielą pola oraz mechanizmy, które mają identyczne znaczenie biznesowe. Serwis nadal używa jawnych list pól dozwolonych dla końcowego typu dokumentu.

Pro forma:

- posiada własną serię i własny numer,
- nie zużywa numeru faktury VAT,
- nie jest fakturą VAT,
- nie trafia do rejestru VAT ani JPK jako faktura sprzedaży,
- nie jest wysyłana do KSeF,
- nie posiada serii korekt; `default_correction_series_id` zawsze pozostaje `null`,
- może posiadać własne dane sprzedawcy i logo zapisane bezpośrednio w serii,
- nie używa `seller_profiles` ani `company_settings`.

`additional_information_template` przechowuje nierozwiązany token `[uwagi_sprzedawcy]`. Renderowanie tokenu oraz snapshot danych sprzedawcy, nabywcy, pozycji i informacji nastąpią dopiero podczas przyszłego wystawiania dokumentu. Etap nie tworzy dokumentów, pozycji dokumentów, liczników, PDF, JPK ani KSeF. Nie ma paragonów.

---

# 35. Granice Etapu 2A

Etap 2A wdraża fundament dokumentów sprzedaży:

```text
invoices
invoice_items
Modules/Invoices/Models/Invoice.php
Modules/Invoices/Models/InvoiceItem.php
InvoiceDocumentStatus
InvoiceItemType
```

Jedna tabela `invoices` przechowuje typy `invoice`, `proforma` i `correction`. Dokumenty oraz ich pozycje przechowują własne snapshoty i nie są automatycznie synchronizowane z aktualnym zamówieniem, pozycją zamówienia ani konfiguracją serii. Nie ma `orders.invoice_id`; relacją pozostaje `Order hasMany Invoices`.

Model danych obsługuje liniowy łańcuch Korekt:

- każda Korekta wskazuje pierwotną Fakturę przez `corrected_invoice_id`,
- druga i kolejne Korekty wskazują bezpośrednio poprzednią Korektę przez `previous_correction_id`,
- pozycje Korekt mogą przechowywać snapshoty stanu przed, po i różnicy,
- jedna Faktura może posiadać wiele kolejnych Korekt,
- skuteczny stan po Korektach nie jest jeszcze obliczany.

Pro forma posiada pola `source_snapshot_hash` i `last_refreshed_at`. Serwis utrzymuje jedną logiczną Pro formę zamówienia z jednym bieżącym stanem i niezmiennym numerem. Etap 2A nie odświeża jeszcze Pro formy.

`invoice_items.product_id` jest nullable, indeksowane i nie posiada klucza obcego. Tabela oraz model `Product` nie istnieją. Historyczne pozycje dokumentów muszą działać bez katalogu produktów.

Dokumenty nie używają `SoftDeletes`. Użyta seria numeracji nie może zostać usunięta; można ją ukryć przez `is_active = false` i później reaktywować z tym samym `id` oraz przyszłą tożsamością licznika.

Etap 2A nie implementuje:

- liczników ani generatora numerów,
- wystawiania dokumentów i tworzenia snapshotów z zamówienia,
- obliczeń netto, VAT i brutto,
- list, formularzy, edycji ani usuwania dokumentów,
- PDF, audytu, automatyzacji, JPK XML ani KSeF.

Przyszła numeracja jest niezależna dla serii i okresu. Nie uzupełnia wewnętrznych luk: po numerach 10, 11, 12, 13 i usunięciu 11 następny numer wynosi 14. Dozwolone usunięcie ostatniego numeru może cofnąć licznik wyłącznie do najwyższego pozostałego numeru; po usunięciu 13 następny może ponownie wynosić 13. Nie powstaje ogólna pula zwolnionych numerów.

Przyszła edycja Faktury będzie działać na snapshotach i nie zmieni zamówienia. Nie będzie zależała od NIP-u. Faktura z przyszłym snapshotem trybu KSeF `exclude` będzie mogła być edytowana tylko bez Korekt i innych blokad, natomiast wystawiona Faktura w trybie `send` nie będzie zwyczajnie edytowalna. Pro forma nie podlega KSeF, a Korekta dziedziczy sposób obsługi KSeF z Faktury źródłowej. Pola i procesy KSeF nie należą do Etapu 2A.

---

# 36. Granice Etapu 2B

Etap 2B wdraża:

- `invoice_number_counters`,
- `invoice_number_counter_adjustments`,
- niezależne liczniki serii i okresów,
- `last_sequence_number`,
- `protected_floor_sequence_number`,
- centralny resolver okresu,
- centralny formatter tokenów `%N`, `%NN...`, `%M`, `%Y` i `%y`,
- centralną walidację zgodności `number_format` z `reset_period` i `fiscal_year_start_month`,
- transakcyjne nadanie numeru istniejącemu szkicowi bez zmiany statusu na wystawiony,
- unikalność sekwencji dokumentu w serii i okresie,
- serwerowy podgląd bez rezerwacji numeru,
- operację „Ustaw następny numer” z obowiązkowym powodem i niezmienną historią,
- blokadę `document_type`, `number_format`, `reset_period` i `fiscal_year_start_month` po rozpoczęciu numeracji,
- zamrożenie `numbering_period_key` ponumerowanego dokumentu.

Klucz okresu ma postać `YYYY-MM` dla `monthly`, rok rozpoczęcia okresu fiskalnego dla `yearly` oraz `none` dla braku resetu. Numer nie jest wyznaczany przez niezabezpieczone `MAX + 1`. Unikalne indeksy są ostatecznym zabezpieczeniem także na SQLite.

Format zawsze wymaga tokenu `%N`, `%NN` albo dłuższego wariantu. Dodatkowo `monthly` wymaga `%M` i `%Y` albo `%y`; `yearly` od stycznia wymaga `%Y` albo `%y`; `yearly` od miesiąca innego niż styczeń wymaga `%M` i `%Y` albo `%y`; `none` nie wymaga tokenu miesiąca ani roku. Reguły muszą być egzekwowane centralnie przez serwisy, a nie wyłącznie przez Form Request.

Operacja ręczna działa oddzielnie dla każdego okresu. Dla okresu bez dokumentów może skorygować licznik w dół, a dla okresu z dokumentami może tylko bezpiecznie przesunąć go do przodu. Ustanowiony poziom staje się chronionym progiem. Nazwa i ustawienia biznesowe serii pozostają edytowalne.

Etap 2B nie implementuje:

- pełnego wystawiania dokumentów ani `InvoiceIssuingService`,
- snapshotów z zamówienia, pozycji dokumentu i obliczeń VAT,
- usuwania faktur ani rzeczywistego cofania końca licznika,
- puli zwolnionych numerów i uzupełniania wewnętrznych luk,
- odświeżania Pro formy i wystawiania Korekt,
- PDF, automatyzacji, JPK, OSS ani KSeF,
- kontroli kompletności zamówienia; brak NIP-u nie blokuje samego nadania numeru.

Przyszły serwis usuwania może cofnąć wyłącznie wolny koniec numeracji i nigdy poniżej `protected_floor_sequence_number`. Zmiana `issue_date` nie przenosi automatycznie ponumerowanego dokumentu do innego okresu. Pierwsze utworzenie logicznej Pro formy zużywa jeden numer, odświeżenie zachowa ten numer, a każda Korekta zużywa osobny numer własnej serii. KSeF pozostaje planowany w trybach `send` i `exclude`.

---

# 37. Granice Etapu 2C

Etap 2C wdraża centralne przygotowanie i wystawianie dokumentów z zamówienia:

- `order_document_slots` jako bazodanową ochronę jednej Faktury VAT i jednej logicznej Pro formy na zamówienie,
- pola trwałego zastąpienia Pro formy przez Fakturę VAT,
- snapshoty sprzedawcy, nabywcy, odbiorcy, zamówienia, płatności, dostawy i ustawień serii,
- pozycje produktów oraz opcjonalną pozycję dostawy,
- deterministyczne obliczenia netto, VAT i brutto bez użycia `float`,
- daty dokumentu i wyrenderowane informacje dodatkowe,
- `InvoiceIssuingService` jako jedyne centralne wejście do przyszłych ścieżek wystawiania Faktury VAT,
- `ProformaService` utrzymujący jedną logiczną Pro formę i jej kanoniczny hash,
- zdarzenia `invoice_issued`, `proforma_issued` i `proforma_refreshed`,
- jedną transakcję obejmującą slot, dokument, pozycje, numerację i zdarzenie zamówienia.

Jedno zamówienie może mieć najwyżej jedną istniejącą Fakturę VAT i jedną logiczną Pro formę. Faktura i Pro forma mogą istnieć jednocześnie. Wystawienie Faktury nie usuwa Pro formy, lecz oznacza ją jako zastąpioną i blokuje dalsze odświeżanie. Dozwolone usunięcie Faktury przez serwis domenowy odblokowuje dokładnie powiązaną Pro formę; ponowne wystawienie Faktury ponownie ją zastępuje.

Pierwsze utworzenie Pro formy zużywa numer i zapisuje jej bieżący stan. Kolejne wywołanie bez zmiany kanonicznego hasha niczego nie zapisuje. Zmiana treści nadpisuje bieżące snapshoty i pozycje tego samego dokumentu, zachowując numer, serię, okres, `issue_date` i `issued_at`. Sama zmiana `orders.updated_at` nie wpływa na hash.

Waluta pochodzi z zamówienia. Domyślne `PLN` jest dozwolone wyłącznie przy tworzeniu nowych, pustych danych; nie wolno po cichu zastępować brakującej lub nieznanej waluty historycznej podczas wystawiania dokumentu. Brak NIP-u, telefonu, e-maila lub opcjonalnych danych adresowych nie blokuje dokumentu. Brak wymaganej stawki VAT nie jest zastępowany przypadkową wartością i kończy operację kontrolowanym błędem oraz pełnym rollbackiem. Etap nie dodaje OSS ani kontroli kompletności zamówienia.

Etap 2C nie implementuje:

- UI, kontrolerów ani tras wystawiania,
- listy i widoku szczegółów dokumentu,
- PDF i wysyłki e-mail,
- usuwania dokumentów, cofania licznika ani ręcznej zmiany numeru,
- ręcznej edycji dokumentu,
- wystawiania Korekt,
- automatyzacji ani publicznego API,
- JPK XML, OSS, KSeF ani Fakturowni.

---

# 38. Granice Etapu 2D

Etap 2D udostępnia AJAX-owe wystawianie Faktury VAT i tworzenie Pro formy z istniejących przycisków `WYSTAW FAKTURĘ` oraz `PRO FORMA` w kafelku „Zarządzanie” zamówienia. Nie powstaje drugi panel dokumentów. Operacja nie używa modala ani podglądu, nie przeładowuje całej strony, nie zmienia tekstu przycisku podczas żądania i nie pokazuje komunikatu sukcesu. Backend zwraca ponownie wyrenderowany fragment Blade, a potwierdzeniem powodzenia jest numer dokumentu zastępujący akcję.

Dla jednej aktywnej serii widoczny jest zwykły przycisk, a dla wielu serii dropdown zawierający nazwę i format. Po utworzeniu Pro formy jej numer otwiera prywatny PDF w nowej karcie. Po wystawieniu Faktury akcja i numer Pro formy są całkowicie ukryte w kafelku, chociaż bieżący dokument pozostaje w bazie.

PDF Faktury VAT, Pro formy i gotowy wariant renderera Korekty są generowane przez TCPDF wyłącznie z zapisanych snapshotów `Invoice` i `InvoiceItem`. Pliki trafiają atomowo na prywatny dysk `local`; kontrolowana trasa zwraca je inline z `Cache-Control: private, no-store`. Każdy dokument posiada jeden bieżący plik cache zależny wyłącznie od wersji layoutu. Dokumenty są A4, nie zawierają stopki generatora ani numerów stron. Nagłówki używają Helvetica, a tekst Verdana, jeśli jest zarejestrowana i dostępna, albo DejaVu Sans jako Unicode fallback.

Pozycja dostawy jest zachowywana również dla kosztu `0.00`, jeżeli seria uwzględnia dostawę i zamówienie ma rozpoznaną metodę wysyłki. Faktura wystawiona po Pro formie zapisuje w `order_snapshot.related_documents.proforma` jej `invoice_id`, numer i datę wystawienia z chwili operacji.

Etap 2D nie implementuje wystawiania ani UI Korekt, edycji i usuwania Faktury, zewnętrznych PDF, załączników, wysyłki e-mail, automatyzacji dokumentów, list dokumentów, JPK XML ani KSeF.

---

# 38A. Granice Etapu 2E

Etap 2E udostępnia niezależną, sekcyjną edycję AJAX wyłącznie wystawionej Faktury VAT. Zmiany dotyczą tylko snapshotów `Invoice` i należących do niego `InvoiceItem`; nie aktualizują `Order`, `OrderItem`, `InvoiceSeries` ani liczników numeracji. Aktualne dane nabywcy i odbiorcy można jedynie skopiować do formularza, a aktualne pozycje zamówienia zastępują pozycje Faktury dopiero po jawnej operacji użytkownika.

Edycja wymaga wystawionej Faktury z numerem, serią i zgodnym `OrderDocumentSlot` oraz jest blokowana po utworzeniu Korekty. Numer, seria, typ, status, waluta, sekwencja, okres numeracji i `issued_at` pozostają niezmienne. `issue_date` może zmienić się tylko wtedy, gdy zamrożony format nadal daje ten sam numer i ten sam okres numeracji. Każda mutacja przesyła ukryte `expected_lock_version`; nieaktualna wartość kończy się kontrolowanym konfliktem zamiast nadpisania zmian.

Każdy dokument sprzedaży posiada tylko jeden bieżący stan. Rzeczywista zmiana nadpisuje snapshoty lub pozycje Faktury i zwiększa techniczne `lock_version` dokładnie o jeden. `lock_version` nie jest historią dokumentu ani wartością widoczną dla użytkownika. Brak zmiany niczego nie zapisuje, a zwykła edycja Faktury nie tworzy `OrderEvent`.

Dla Faktury walutowej zmiany tekstowe zachowują snapshot NBP i nie wykonują HTTP. Zmiany pieniężne przeliczają podsumowanie PLN zapisanym kursem, a zmiana daty odniesienia pobiera nowy kurs przed transakcją i ponownie weryfikuje kontekst pod blokadą. Pusty historyczny snapshot pozwala tylko na zmiany niepieniężne, a niepoprawny niepusty snapshot blokuje całą edycję.

Faktura, Pro forma i Korekta używają po jednym bieżącym pliku cache PDF. Po zatwierdzeniu rzeczywistej zmiany bieżący cache jest usuwany, a kolejne otwarcie generuje go ponownie z aktualnych snapshotów. System nie używa `InvoiceRevision`, usuwa tabelę `invoice_revisions` i kolumnę `revision_number`, a standardowe `updated_at` oznacza jedynie ostatnią aktualizację rekordu. Pro forma nadal używa `source_snapshot_hash` do wykrywania zmiany zamówienia. Etap 2E nie obejmuje edycji Pro formy lub Korekty, usuwania dokumentów, zewnętrznych PDF, e-maila, JPK ani KSeF. Kolejne etapy to 2F Korekty, 2G dokumenty zewnętrzne PDF, 2H wysyłka dokumentów e-mailem, 3A rejestr sprzedaży, 3B JPK, 3C audyt gotowości KSeF i 3D integracja KSeF.

---

# 39. Kraje adresów i dokumentów

Adres dostawy i dane do faktury posiadają niezależne pola `shipping_country_code` oraz `billing_country_code`. Są to kody ISO 3166-1 alpha-2. Polskie nazwy krajów pochodzą wyłącznie z centralnego `App\Support\CountryCatalog`, opartego na Symfony Intl; nie twórz równoległych list w Blade, JavaScript ani kontrolerach.

Przy zapisie edytowanej sekcji kraj jest wymagany, normalizowany przez `trim` i `uppercase` oraz walidowany względem katalogu. Nie stosuj fallbacku `PL` dla pustych danych historycznych. Kopiowanie adresów kopiuje także kod kraju, a poprawne pobranie polskiej firmy z GUS ustawia jawnie `billing_country_code = PL`.

Snapshoty dokumentów zapisują `country_code` i `country_name` osobno dla Nabywcy i Odbiorcy. PDF Faktury VAT, Pro formy i Korekty pokazuje kraj tylko w bloku Nabywcy, np. `32-545 Psary, Polska`, i korzysta wyłącznie ze snapshotu. Starszy snapshot może rozwiązać nazwę z prawidłowego kodu bez modyfikacji bazy. Obsługa kraju nie zmienia VAT, OSS, sposobu płatności ani powiązania Faktury VAT z Pro formą.

---

# 40. Granice Etapu 1E.1

Etap 1E.1 wdraża lokalny katalog walut:

- tabelę `currencies` z kluczem `code`, nazwą i opcjonalnym oznaczeniem tabeli NBP `A` albo `B`,
- systemowy rekord `PLN` tworzony bez połączenia sieciowego,
- model `Currency`, centralny `CurrencyCatalog` i regułę `ValidCurrencyCode`,
- ręczną komendę `currencies:sync-nbp`, która atomowo synchronizuje tabele A i B przez HTTPS,
- jedną walutę dla zamówienia i wszystkich jego pozycji,
- selecty lokalnego katalogu w zamówieniach, pozycjach i seriach numeracji,
- zachowanie nieznanych historycznych kodów bez umożliwiania ich ponownego wyboru,
- wykrywanie mieszanych walut przed przeliczeniem sumy,
- dziesiętne obliczenia pozycji, dostawy, sumy i pozostałej należności bez `float`.

Tabela nie posiada liczbowego `id`, timestampów ani pól `is_active`, `is_system` i `last_seen_at`. Synchronizacja nie usuwa walut i nie nadpisuje `PLN`. Obie tabele NBP muszą zostać poprawnie zwalidowane przed zapisem. Nie dodawaj kluczy obcych z istniejących pól walutowych do `currencies`.

Sam Etap 1E.1 nie obejmował kursów walut, przewalutowań, harmonogramu, automatycznej synchronizacji, przycisku odświeżania, historii kursów ani zmian PDF i snapshotów wystawionych dokumentów.

---

# 41. Granice Etapu 1E.2

Etap 1E.2 dodaje historyczny średni kurs NBP wyłącznie do procesu wystawiania Faktury VAT w walucie obcej. Faktura zachowuje walutę zamówienia, a podsumowanie grup VAT jest dodatkowo przeliczane zawsze do `PLN`. `invoice_series.default_currency` nie jest walutą docelową tego przeliczenia.

Tabela `A` albo `B` wynika wyłącznie z `currencies.nbp_table`. Klient pobiera przez HTTPS zakres maksymalnie 93 dni, wybiera najnowszą publikację wcześniejszą od daty odniesienia i zachowuje dokładny tekst pola XML `Mid`, łącznie z końcowymi zerami. Data odniesienia wynika z finalnych `issue_date` i `sale_date`: standardowo jest to data sprzedaży, a gdy Fakturę wystawiono przed datą sprzedaży — data wystawienia. Szczególne przypadki obowiązku podatkowego pozostają poza zakresem.

Kurs nie przechodzi przez `float`. Każda grupa VAT jest przeliczana osobno metodą half-up do dwóch miejsc, przy czym brutto PLN jest sumą zaokrąglonego netto i VAT. Kurs, reguła daty, tabela, data publikacji oraz podsumowanie PLN są niezmiennym `tax_metadata_snapshot` Faktury. Nie istnieje lokalna tabela ani cache kursów.

Pobranie HTTP odbywa się przed transakcją i numeracją. W transakcji kontekst waluty, dat i tabeli jest ponownie sprawdzany; zmiana powoduje pełny rollback i najwyżej jedną pełną ponowną próbę z nowym kursem. Błąd NBP nie tworzy slotu, szkicu, pozycji, numeru ani zdarzenia.

Pro forma nie pobiera kursu, nie zapisuje przeliczenia PLN i nie zmienia hasha z powodu kursu. Faktura PLN oraz każda Pro forma zachowują pusty `tax_metadata_snapshot`. Etap 1E.2 nie zmienia PDF; prezentacja kursu i podsumowania PLN należy do Etapu 1E.3. Walutowe Korekty nie są jeszcze wystawiane.

---

# 42. Granice Etapu 1E.3

Etap 1E.3 prezentuje na PDF Faktury VAT w walucie obcej wyłącznie dane zapisane wcześniej w `tax_metadata_snapshot`: dokładny tekst kursu NBP, datę publikacji, numer tabeli oraz dodatkowe podsumowanie grup VAT w `PLN`. Główne wartości, pozycje, blok „Razem” i kwota słownie pozostają w walucie Faktury. Nie przelicza się pozycji, wpłat ani pozostałej należności.

`InvoicePdfCurrencyConversionPresenter` centralnie waliduje kontrakt snapshotu wersji 1, niezmienniki sum oraz paruje źródłowe i przeliczone grupy po znormalizowanym `vat_code` albo `vat_rate`. Renderer, view model i Blade nie kontaktują się z NBP, nie czytają bieżącego katalogu `currencies`, nie wykonują ponownych przeliczeń i nie modyfikują dokumentu. Kurs zachowuje dokładną tekstową precyzję NBP, a zapisane kwoty PLN muszą mieć dwa miejsca dziesiętne.

Pusty snapshot Faktury walutowej oznacza historyczny dokument sprzed Etapu 1E.2 i pozwala wygenerować dotychczasowy PDF bez kursu i PLN. Niepusty, częściowy albo niespójny snapshot blokuje generowanie kontrolowanym błędem `invoice_pdf_invalid_currency_conversion_snapshot`.

Faktura PLN, każda Pro forma i renderer Korekty pozostają bez dodatkowego kursu oraz podsumowania PLN. Cache PDF jest wersjonowany osobno: zmiana Etapu 1E.3 podnosi wyłącznie wersję layoutu Faktury, bez usuwania poprzednich plików i bez unieważniania cache Pro formy lub Korekty. Etap nie dodaje migracji, zależności, walutowej Korekty ani połączeń HTTP podczas generowania PDF.

---

# 43. Waluta pierwszej pozycji pustego zamówienia

Nowe puste zamówienie może technicznie rozpoczynać się w `PLN`. Waluta pierwszej pozycji może atomowo ustawić inną walutę całego zamówienia wyłącznie wtedy, gdy zamówienie nie ma pozycji, niezerowej sumy, wpłaty ani kosztu wysyłki oraz nie ma Faktury VAT, Pro formy, slotu dokumentu, przesyłki ani próby utworzenia przesyłki.

Ustalenie waluty odbywa się w `OrderCurrencyService`, pod blokadą zamówienia i w tej samej transakcji co zapis pozycji, przeliczenie sumy oraz zdarzenie. Nie jest to przewalutowanie: zerowe wartości pozostają zerowe, a istniejące kwoty nie są reinterpretowane. Po pierwszej pozycji wszystkie kolejne pozycje muszą używać waluty zamówienia.

Stan AJAX zamówienia przekazuje `fields.currency`. Istniejący mechanizm synchronizacji ustawia tę wartość w selectach i jako ich wartość domyślną przed resetem formularza, dzięki czemu kafel informacji oraz kolejna pozycja używają aktualnej waluty bez przeładowania strony. Mechanizm nie wykonuje zewnętrznych połączeń HTTP i nie zmienia wystawionych dokumentów, snapshotów ani PDF.
