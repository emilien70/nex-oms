# Portal klienta: dokumenty do weryfikacji Paynow

Projekt zmian do zatwierdzenia przez sprzedawcę przed publikacją. Nie jest
nową wersją regulaminu lub polityki prywatności. Nie zmieniono treści
PrestaShop ani nie wysłano zgłoszenia do Paynow.

## Regulamin sklepu

Punktem wyjścia jest [regulamin Multi-Click](https://multi-click.pl/content/regulamin).
W danych sprzedawcy i sekcjach dotyczących zgłoszeń jako adres e-mail podać
`kontakt@multi-click.pl`. Usunąć dodatkowe adresy z nawiasów, jeśli nie są
już adresami obsługi klientów.

**§4 – propozycja opisu metod płatności:**

> W zależności od wybranej dostawy klient może zapłacić przelewem na rachunek
> sprzedawcy, przez Paynow (mElements S.A.), kartą płatniczą przez Autopay S.A.,
> za pobraniem lub gotówką przy odbiorze osobistym. Dostępne warianty i koszty
> są podawane przy zamówieniu. Aktualne zestawienie zawiera strona
> [Dostawa i płatność](https://multi-click.pl/content/dostawa). Jeśli na stronie
> już złożonego zamówienia dostępny jest przycisk płatności, przekierowuje on
> do Paynow. Portal nie służy do składania nowych zamówień.

W §5 i na stronach pomocy korzystać z tego samego zestawienia; nie
przywracać Przelewy24 ze starszej wersji strony. Nie wpisywać na portalu
innych metod niż obsługiwana przez portal płatność Paynow.

**§12 – propozycja uzupełnienia zakresu usług elektronicznych:**

> Sprzedawca może udostępnić klientowi bezpłatną stronę jego zamówienia pod
> indywidualnym adresem. W zakresie dostępnych funkcji klient może sprawdzić
> status zamówienia i przesyłki, pobrać udostępnione dokumenty lub przejść do
> płatności za istniejące zamówienie. Dostęp do pełnych danych lub dokumentu
> może wymagać potwierdzenia danych z zamówienia. Do korzystania z portalu
> potrzebne są dostęp do internetu, aktualna przeglądarka i indywidualny adres
> otrzymany od sprzedawcy; niektóre operacje wymagają technicznego pliku sesji.
> Adres umożliwia wgląd w zamówienie i nie powinien być udostępniany osobom
> nieuprawnionym. Nie wolno dostarczać treści bezprawnych ani próbować uzyskać
> dostępu do cudzego zamówienia. Klient może w dowolnym momencie zakończyć
> korzystanie z portalu przez zamknięcie strony; nie zmienia to zawartej umowy
> sprzedaży. Zgłoszenia dotyczące działania portalu można przesłać na
> kontakt@multi-click.pl.

**§6 – projekt aktualizacji dotyczącej sprzedaży konsumenckiej:**

> W razie niezgodności towaru z umową konsument może złożyć reklamację,
> np. na adres kontakt@multi-click.pl lub pisemnie na adres sprzedawcy.
> Opis problemu, żądanie i dane pozwalające zidentyfikować zakup ułatwiają
> rozpatrzenie reklamacji. Sprzedawca odpowiada na reklamację w ciągu 14 dni
> od jej otrzymania. Konsument może żądać naprawy lub wymiany towaru,
> a w przypadkach przewidzianych ustawą o prawach konsumenta także obniżenia
> ceny lub odstąpienia od umowy. Koszty reklamacji związane z doprowadzeniem
> towaru do zgodności z umową ponosi sprzedawca zgodnie z ustawą.

Usunąć odesłanie do zlikwidowanej platformy ODR także z osobnej strony
reklamacji, jeśli tam występuje. Przed publikacją sprawdzić aktualne
postanowienia dotyczące odstąpienia, wzory, kanały zgłoszeń i zasady dla
przedsiębiorców korzystających z ochrony konsumenckiej. Nie utożsamiać
odstąpienia bez przyczyny z odstąpieniem w ramach reklamacji. Powyższy tekst
nie jest pełną aktualizacją §6–7.

## Polityka prywatności

W [dotychczasowej polityce](https://multi-click.pl/content/polityka-prywatnosci)
ujednolicić kontakt na `kontakt@multi-click.pl` i wskazać, że opis obejmuje
także `klient.multi-click.pl`. Sprawdzić i zatwierdzić podstawy i okresy
przechowywania dla obsługi zamówień, bezpieczeństwa portalu i rozliczeń.
Zachować opis praw użytkowników i sposobu kontaktu w sprawach danych.
Uwzględnić źródło danych zamówień z innych kanałów sprzedaży, gdy ma
zastosowanie, oraz aktualnych odbiorców danych. Informacja operatora
płatności nie zastępuje informacji sprzedawcy.

**Propozycja uzupełnienia opisu portalu:**

> Administratorem danych przetwarzanych przez sprzedawcę na portalu klienta
> jest Multi-Click Eliasz Malczyk; kontakt: kontakt@multi-click.pl. Portal
> wykorzystuje dane przypisane do złożonego zamówienia, aby udostępnić klientowi
> informacje o jego realizacji, przesyłkach, dokumentach i płatnościach.
> Weryfikacja dostępu polega na porównaniu podanej wartości z danymi
> zamówienia. Przy wyborze płatności Paynow obsługuje ją mElements S.A.,
> zgodnie z [informacją operatora](https://static.paynow.pl/docs/polityka_prywatnosci_w_melements.pdf).

**Cookies portalu klienta – propozycja do wykorzystania po wdrożeniu i pomiarze:**

> Portal klienta nie uruchamia narzędzi analitycznych ani reklamowych.
> Techniczny plik `nex_oms_customer_portal_session` utrzymuje sesję portalu,
> zabezpiecza formularze i pozwala zapamiętać weryfikację dostępu w tej sesji.
> Plik jest ograniczony do domeny portalu i przesyłany tylko po HTTPS. Okres
> ważności pliku wynosi do dwóch godzin od jego ostatniego ustawienia;
> uprawnienie do wyświetlenia pełnych danych wygasa niezależnie od sesji,
> najpóźniej po 15 minutach. Po przejściu z głównej domeny przeglądarka może
> też przesłać pliki ustawione przez sklep dla całej domeny; nie służą one
> do obsługi zamówień w portalu. Strona informacyjna `/informacje` nie
> ustawia własnych cookies. Po przekierowaniu na stronę Paynow zastosowanie
> mają informacje operatora.

### Wynik odczytu z 10 listopada 2026 r.

- Na produkcyjnym `klient.multi-click.pl/` i `/paynow/powrot` bez parametrów:
  sesja portalu i `XSRF-TOKEN` były ograniczone do hosta, Secure, SameSite=Lax
  i miały Max-Age 7200 s. Nie zaobserwowano pobierania zewnętrznych skryptów
  ani wpisów w localStorage i sessionStorage w tych dwóch widokach.
- Po odwiedzeniu sklepu czysta przeglądarka przesłała na subdomenę także
  `_ga`, `_ga_*`, a przy kolejnym żądaniu `PrestaShop-*` i `lsc_private`.
  Google Tag Manager/Analytics był widoczny na stronie sklepu przed
  interakcją ze zgodą. Zaobserwowano też skrypty Apaczki i InPost Pay.
  Nie ustalano roli każdego skryptu ani nie testowano pozostałych ekranów
  sklepu. Sklep wymaga oddzielnego przeglądu zgód, ograniczenia zakresu
  domenowego niepotrzebnych cookies i aktualizacji listy narzędzi w polityce.
  Nie publikować stwierdzenia, że na subdomenę nigdy nie trafiają cookies
  analityczne, dopóki nie zmieni się konfiguracja sklepu.
- Po wdrożeniu ponownie zmierzyć czas życia i atrybuty cookies na publicznej
  stronie i testowym zamówieniu. Potwierdzić brak `XSRF-TOKEN` po stronie
  portalu przy zachowanym sprawdzaniu formularzy. Nie używać danych ani
  płatności prawdziwego klienta. Nie dodawać banera zgody dla plików
  niezbędnych do żądanej usługi; dla analityki sklepu sprawdzić faktyczne
  blokowanie przed zgodą, a nie tylko widoczność banera.

## Weryfikacja Paynow

Przed publikacją poprosić Paynow o odpowiedź na pytanie, czy publiczna
strona `https://klient.multi-click.pl/informacje` z wyraźnymi linkami do
dokumentów na `multi-click.pl` jest wystarczająca, czy pełna treść ma
znajdować się na tej samej subdomenie. W drugim przypadku opublikować
na portalu zatwierdzone wersje dokumentów. Nie przesyłać tokenów, danych
zamówień ani aktywnych linków płatniczych do weryfikacji.

**Pytanie do wysłania w istniejącym wątku z Paynow:**

> Dzień dobry,
>
> weryfikowana subdomena klient.multi-click.pl służy do obsługi już złożonych
> zamówień oraz przekierowania do płatności Paynow. Przygotowujemy na niej
> publiczną stronę informacyjną pod adresem klient.multi-click.pl/informacje,
> dostępną bez logowania i bez linku do zamówienia. Czy wystarczy, że będzie
> zawierała dane sprzedawcy i bezpośrednie linki do regulaminu świadczenia usług
> drogą elektroniczną oraz polityki prywatności opublikowanych na multi-click.pl,
> czy pełna treść obu dokumentów musi być dostępna na subdomenie klienta?
>
> Pozdrawiamy
> Multi-Click

Po zatwierdzeniu treści i wdrożeniu przekazać Paynow publiczne adresy:
`/informacje`, [Regulamin](https://multi-click.pl/content/regulamin) oraz
[Polityka prywatności](https://multi-click.pl/content/polityka-prywatnosci).

Źródła: [Paynow](https://faq.paynow.pl/docs/),
[art. 8 UŚUDE](https://eli.gov.pl/api/acts/DU/2024/1513/text.html),
[art. 13 i 14 RODO](https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX%3A32016R0679),
[art. 399 PKE](https://eli.gov.pl/api/acts/DU/2024/1221/text.html#art_399),
[UOKiK: niezgodność towaru z umową](https://prawakonsumenta.uokik.gov.pl/reklamacja/niezgodnosc/),
[likwidacja ODR](https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=OJ%3AL_202403228).
