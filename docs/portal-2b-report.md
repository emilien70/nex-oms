# PORTAL.2B - domena, krotkie linki i ochrona danych

## Zakres

Stan lokalny 2026-10-27, bazowy HEAD:
`b6951894ef429124e28502e06a9125fb95cc273a`.
Zachowano istniejace niezacommitowane zmiany PORTAL.1/2/2A i pozostalych modulow.
Nie wykonano commit, push, deploymentu ani operacji na VPS.

- Konfigurowalna **Domena strony dla klientow**, bez zmiany globalnego APP_URL.
- Format `https://klient.multi-click.pl/<numer OMS>/<10 losowych liter i cyfr>`.
- CSPRNG, rozroznianie wielkosci liter kodu, szyfrowany token i unikalny SHA-256.
- Numer i kod sa sprawdzane razem dla strony, PDF, obrazow, logo i platnosci.
- Stary format portalu nie jest obslugiwany; wymiana aktywnych kodow jest jawna.
- Dane adresowe i nabywcy sa redagowane przed przekazaniem do Blade.
- Odswiezanie strony nie tworzy linkow, nie wywoluje API ani nie ksieguje wplat.
- Weryfikacja jedna pelna wartoscia: e-mail lub imie i nazwisko/firma nabywcy.
- Bez jednorazowych kodow e-mail. Bez zmian zapisanych danych osobowych.
- Porownanie Unicode NFC/case folding ignoruje wielkosc liter, obcina skrajne
  odstepy, a w nazwach porzadkuje wielokrotne odstepy. Zachowuje diakrytyke,
  interpunkcje i kolejnosc slow. Puste pola i fragmenty nie potwierdzaja dostepu.
- Wybieralne metody: dowolna z wartosci, tylko e-mail, tylko imie i nazwisko/firma.
- Potwierdzenie 15 minut od odpowiedzi, dla zamowienia i sesji przegladarki,
  z regeneracja ID sesji i HMAC biezacego klienta/linku/polityki. Brak PII w grancie.
- PDF GET/HEAD podlega tej samej bramce, przed generowaniem bajtow dokumentu.
- Publiczne dostepnosci finansowe/KSeF i admin PDF pozostaja bez zmian.
- Limity blednych odpowiedzi i chybionych kodow nie wprowadzaja trwalej blokady zamowienia.
- Na osobnej domenie klienta panel jest blokowany, a sesja ma odrebny Secure,
  host-only cookie. Domeny panelu nie mozna zapisac jako domeny klienta.
- W ustawieniach PayNow **Adres strony powrotu OMS** pokazuje adres z domeny
  portalu, np. `https://klient.multi-click.pl/paynow/powrot`.

## Powroty PayNow

Nowe domyslne proby otrzymuja podpisany indywidualny URL pod domena klienta.
Nie ustawiamy jednego numeru zamowienia w statycznym polu konfiguracji.
URL bazowy bez podpisu jest neutralny i nie odczytuje dowolnego paymentId.
Podpis nadal obejmuje host; nie wolno podmieniac domeny w starym URL tekstowo.
Generator podpisow jest izolowany i nie zmienia adresow panelu ani webhooka.
Wlasny URL konta zachowuje pierwszenstwo; nie jest nadpisywany.

Przegladarka, ktora rozpoczela platnosc w portalu, wraca do zamowienia tylko
przy zachowaniu waznego kontekstu tej sesji. Podpisany link platnosci sam
nie ujawnia kodu portalu. Bez takiego kontekstu klient nadal widzi bezpieczna
strone statusu platnosci. Stary powrot na domenie OMS nie otrzymuje cookie
nowej domeny klienta; nie rozszerzamy SESSION_DOMAIN, aby to obchodzic.
Formularze status/retry starego poprawnego URL zachowuja jego pierwotny host.
Zarejestrowanych zdalnie platnosci i ich continueUrl nie przepisujemy.
Nie zmieniono webhooka, ksiegowania, statusow finansowych ani zasad retry.
Przy kolejnej zmianie domeny pozostaje waski wyjatek dla historycznych
podpisanych powrotow i aliasow platnosci. Stary host wymaga zachowania DNS/TLS
i scislych tras serwera; nie moze udostepniac panelu ani portalu zamowienia.

Przy przyszlej zmianie domeny trzeba przygotowac nowy DNS/TLS/vhost i
przejrzec opcjonalny wlasny URL PayNow. Wczesniej wyslane e-maile nie zmieniaja
sie automatycznie. Przed zmiana domeny nalezy rowniez wyjasnic niedokonczone
proby tworzenia linku PayNow bez otrzymanego ID/adresu operatora; istniejacy
serwis odtwarza payload takiej proby z biezacej konfiguracji. Ten etap nie
przebudowuje przechowywania payloadow ani zdalnej idempotencji PayNow.

## Pliki

Utworzone w tym etapie:

- `Modules/CustomerPortal/Services/PortalUrlService.php`
- `Modules/CustomerPortal/Services/PortalVerificationService.php`
- `Modules/CustomerPortal/Http/Middleware/RestrictCustomerPortalHost.php`
- `app/Console/Commands/ReplaceLegacyCustomerPortalLinks.php`
- `database/migrations/2026_10_27_010000_add_customer_domain_and_verification_to_portal_settings.php`
- `tests/Feature/CustomerPortalPrivacyTest.php`
- `tests/Support/BuildsCustomerPortalRoutes.php`
- `docs/portal-2b-report.md`

Zmienione w tym etapie (czesc miala juz lokalne zmiany poprzednich etapow):

- `Modules/CustomerPortal/Models/PortalSetting.php`
- `Modules/CustomerPortal/Services/PortalAccessService.php`
- `Modules/CustomerPortal/Services/PortalPresentationService.php`
- `Modules/CustomerPortal/Services/PortalPaymentService.php`
- `Modules/CustomerPortal/Http/Controllers/PortalController.php`
- `Modules/CustomerPortal/Http/Controllers/PortalSettingsController.php`
- `Modules/CustomerPortal/Http/Controllers/PortalPaymentController.php`
- `Modules/Integrations/PayNow/Services/PayNowReturnUrlService.php`
- `Modules/Integrations/PayNow/Services/PayNowPaymentLinkService.php`
- `Modules/Integrations/PayNow/Http/Controllers/PayNowSettingsController.php`
- `Modules/Integrations/PayNow/Http/Controllers/PayNowCustomerReturnController.php`
- `app/Services/OrderEmailPreparationService.php`
- `app/Services/OrderVariableService.php`
- `bootstrap/app.php`
- `routes/web.php`
- `resources/views/customer-portal/show.blade.php`
- `resources/views/settings/customer-portal/edit.blade.php`
- `tests/Feature/CustomerPortalTest.php`
- `tests/Feature/CustomerPortalAutomaticLinksTest.php`
- `tests/Feature/CustomerPortalPaymentsTest.php`
- `tests/Feature/PayNowCustomerReturnTest.php`
- `docs/006-Deployment.md`
- `docs/product-spec.md`
- `docs/architecture.md`

Nie usunieto plikow w tym etapie. W aktualizowanych dokumentach usunieto
powtorzone sekcje PORTAL.2A, pozostawiajac jeden opis historyczny.
Ignorowane narzedzia QA, kopie i wyniki w storage/app/private nie sa kodem wdrozeniowym.

## Migracja I Dane Lokalne

Migracja dodaje wylacznie cztery pola customer_portal_settings:
customer_domain (nullable), mask_personal_data (true), verify_documents (true),
verification_method (any). Nie zmienia flag widocznosci, platnosci ani globalnego
is_enabled. Nie edytowano migracji wykonanych w poprzednich etapach.
Down odmawia automatycznego usuwania ustawien ochrony.

Po osobnej zgodzie uzytkownika i zakonczonych testach wykonano lokalna kopie:

`storage/app/private/backups/portal-2b-20261027_122514-15308a43.sqlite`

SHA-256: `e5105ce99036f3b319e0c559e20d9c3d9f4e64f6afa5b4b003194a691ac2b7ff`.
Kopia SQLite ma integrity_check=ok. Uruchomiono tylko nowa migracje
(batch 110, Ran), nastepnie wymieniono 258 aktywnych starych kodow.
28 pozostalych linkow pozostalo bez zmian; lacznie bylo 286 linkow.
Odczytowy dry-run wskazal 259 potencjalnych kandydatow; zapis pod blokadami
ponownie sprawdzil wszystkie warunki i nie wymienil jednego z nich.
Nie przedluzono waznosci ani nie przywrocono nieaktywnych dostepow.

Kontrola bezposrednio po operacji: wszystkie poprzednie pola ustawien
pozostaly identyczne, customer_domain=null, maskowanie i bramka PDF=true,
verification_method=any. Skroty tabel orders/order_items/order_events,
dokumentow, pozycji, serii, licznikow, tabel KSeF, PayNow i mapowan statusow
nie zmienily sie. Nie zmieniono ustawien platnosci. Po potwierdzonym przez
operatora restarcie tylko lokalnego Main Queues ponowna wymiana dala 0 zmian.
Pozniejsza normalna praca importow moze oczywiscie zmieniac dane biznesowe;
nie jest czescia tej kontroli ani powodem do automatycznego odtwarzania kopii.

Domena lokalnie pozostaje pusta, aby nie kierowac
lokalnych zamowien na VPS z niewdroznym jeszcze kodem. Dla lokalnego hosta
localhost/127.0.0.1 zachowywany jest rzeczywisty port przegladarki, bez zmiany .env.

Bezpieczne polecenia po kopii i zatwierdzeniu konkretnego srodowiska:

```bash
php artisan migrate --path=database/migrations/2026_10_27_010000_add_customer_domain_and_verification_to_portal_settings.php --force --no-interaction
php artisan customer-portal:replace-legacy-links --dry-run
php artisan customer-portal:replace-legacy-links
```

Nie ma backfillu w migracji. Komenda wymiany ponownie sprawdza ustawienia,
zamowienie i link pod blokadami settings -> order -> link. Nie przedluza
expires_at, nie przywraca odwolan/wygasniecia/niespojnego nabywcy ani kosza.
Kolejne wykonanie nie zmienia juz krotkich kodow. --dry-run jest odczytowe.
Ustawien konfiguracji nie zmienia sie przy kazdym provisioning/otwarciu strony.

## Testy

Siec zewnetrzna jest blokowana. Testy korzystaja z SQLite :memory: i symulowanego
API. Pelna regresja oraz izolowany scenariusz przegladarki maja sztuczne klucze
i konfiguracje. Nie wywolano PayNow, Allegro ani KSeF.

- Testy prywatnosci: 60 testow, 426 asercji, PASS.
- Powiazana regresja: 594 testy, 4695 asercji, PASS.
- PHP lint: 30 plikow PHP, PASS. Pint --test: PASS. git diff --check: PASS.
- Przegladarka Edge/Playwright: szerokosci 1280, 390, 320 px, odczyt zamaskowany,
  bledna odpowiedz, poprawna odpowiedz z wielkimi literami/nadmiarowymi spacjami,
  nowa przegladarka i bramka PDF: PASS. Brak poziomego overflow i bledow JS.
  Osobny syntetyczny SQLite/storage, bez danych uzytkownika. Serwer QA zatrzymano.
- Pelna regresja: 4521 testow, 41577 asercji, 72 niepowodzenia, 0 bledow,
  1401,16 s, kopia p1b-113815/b. Nie jest to calkowicie zielona suita.
- Porownanie z baseline: 0 nowych niepowodzen, 72 wspolne, 0 ustepujacych;
  64 nowe przypadki przechodza, nie usunieto zadnego testu.
- Szesciu surowych podpisow niepowodzen nie porownano bezrefleksyjnie po hashu:
  trzy nadal maja ten sam brak parametru orders.show, trzy te same asercje HTML
  dotyczace identyfikatorow/status polling. W HTML roznia sie tokeny CSRF,
  czas/cache i zatwierdzony format linku. Przyczyny pozostaja te same.
- Wszystkie 11332 zaleznosci maja identyczne sciezki, SHA-256 i rozmiary.
  Kolejnosc kilku wpisow manifestu roznila sie przez sortowanie; porownanie
  map po sciezkach potwierdzilo brak jakiejkolwiek zmiany zawartosci pakietow.
- PHP/Blade w roboczym katalogu odpowiadaja SHA-256 koncowej kopii testowej.
  Po zamrozeniu zmieniono tylko dokumentacje. Przerwane wczesniejsze przebiegi
  nie sa wynikiem koncowym.
- Odczytowy smoke lokalnego serwera: ustawienia portalu HTTP 200, link portalu
  zamowienia 192 ma nowy format/port 8000 i zwraca HTTP 200. W PayNow pole
  Adres strony powrotu OMS pokazuje http://127.0.0.1:8000/paynow/powrot.
  Nie pobierano rzeczywistych PDF ani nie wykonywano akcji platniczych.

Punkt odniesienia: zakonczona regresja PORTAL.2A w p1b-094116/b,
4457 testow, 41050 asercji, 72 niepowodzenia, 0 bledow. Przed edycjami
potwierdzono zgodnosc SHA-256 biezacego PHP/Blade z ta migawka. Nowa kopia
ma te same 11332 pliki zaleznosci, PHP 8.2.12, Laravel 12.63.0 i SQLite 3.39.2.
Nie kopiuje rzeczywistego .env ani bazy. Wczesniejszych 72 niepowodzen
nie naprawiano w tym etapie; brak nowych niepowodzen dotyczy porownania
konkretnych przypadkow i przyczyn, nie samej liczby bledow.

Komendy testow (Windows/XAMPP):

```powershell
$env:APP_ENV='testing'
C:\xampp\php\php.exe -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail -d auto_prepend_file=tests/Support/Invoices/block-network.php vendor/phpunit/phpunit/phpunit --no-progress --filter CustomerPortalPrivacyTest
C:\xampp\php\php.exe -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail -d auto_prepend_file=tests/Support/Invoices/block-network.php vendor/phpunit/phpunit/phpunit --no-progress --filter 'CustomerPortal|PayNow|OrderEmail|EmailTemplateSettings|SettingsVariables|InvoicePdf|ProformaService|Ksef.*(Pdf|Presentation)|AllegroInvoiceDocument'
```

Pelna regresja uruchamiana jest prywatnym harness QA na zamrozonej kopii,
z osobnym .env.testing, cache, storage, SQLite :memory: i blokada sieci.
Nie uruchamiac jej na roboczej bazie uzytkownika.

## Weryfikacja Reczna

1. Otworzyc lokalnie /settings/customer-portal. Sprawdzic trzy ustawienia ochrony
   oraz pole domeny; na komputerze pozostawic domene pusta.
2. Wybrac zamowienie z kompletnymi danymi i aktywnym nowym linkiem na dole
   Zarzadzania. Jezeli sekcja adresow jest wylaczona, nie pojawi sie po weryfikacji.
3. W prywatnym oknie otworzyc link: adresy i nabywca zamaskowani; produkty,
   kwoty i etap nadal widoczne. Nie powinno byc przycisku Strona klienta w OMS.
4. Kliknac Wyswietl pelne dane. Nieprawidlowa wartosc nie odblokowuje danych;
   pelne imie i nazwisko wpisane malymi/wielkimi literami powinno dzialac.
5. W nowym prywatnym oknie kliknac Pobierz PDF: formularz, nie PDF. Po podaniu
   poprawnej wartosci dostepny dokument pobiera sie. Sprawdzic wybrany tryb danych.
6. Wygasniecie 15 minut albo odwolanie/odnowienie linku uniewaznia potwierdzenie.
   Zamowienie nie odblokowuje drugiego zamowienia. Dokument obcego zamowienia
   ma 404, nawet przy poprawnej sesji.
7. Pobieranie z karty faktury w panelu OMS nadal nie wymaga dodatkowych danych.
8. W integracji PayNow Adres strony powrotu OMS lokalnie wskazuje publiczna
   strone lokalnego hosta. Po przygotowaniu i ustawieniu domeny na VPS wskaze
   https://klient.multi-click.pl/paynow/powrot. Wlasny URL konta nie jest zmieniany.
9. Po osobno zatwierdzonym wdrozeniu VPS sprawdzic DNS/TLS, brak dostepu do
   panelu na domenie klienta, 401 panelu OMS i zachowane wyjatki webhooka/Printera.
   Nie przeprowadzono tych operacji w tym etapie.

## Ograniczenia

- Znajomosc nazwiska/firmy/e-maila jest dodatkowa bariera prywatnosci, nie MFA.
- Kod 10-znakowy ma okolo 59,5 bita losowosci, mniej niz dlugi kod. Numer
  zamowienia nie jest sekretem. Nadal trzeba chronic pelne linki przed logami,
  monitoringiem i niezamierzonym przekazywaniem; produkty i kwoty nie sa maskowane.
- Nie potwierdzono DNS, TLS, Nginx ani dzialania nowej domeny na VPS.
- Przed ustawieniem domeny na stagingu APP_URL musi poprawnie wskazywac
  domene panelu; hosty inne niz panel i skonfigurowany portal sa odrzucane,
  poza scislym wyjatkiem historycznych podpisanych powrotow/aliasow platnosci.
  Tylko lokalne srodowisko dopuszcza dodatkowo adresy loopback panelu.
- Stare adresy w juz wyrenderowanych e-mailach/kolejce nie sa przepisywane.
  Testowa wymiana kodow uniewaznia je, zgodnie z decyzja o braku kompatybilnosci.
- Nie wykonano rzeczywistych platnosci, odbioru webhooka ani transmisji KSeF.
- Nie sprawdzono na rzeczywistym MySQL 8/PHP 8.3; testy lokalne sa na SQLite/PHP 8.2.
- Szczegolowa konfiguracja vhosta i kolejnosc wdrozenia: docs/006-Deployment.md,
  sekcja PORTAL.2B. Zachowano opisy NEX-Printer, uprawnien, Supervisor i schedulera.
