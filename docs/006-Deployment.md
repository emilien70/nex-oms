# Deployment

## Serwer testowy

Staging dziala na VPS OVHcloud z Ubuntu, bez Dockera, w `/var/www/nex-oms`. Migawka operatora z 2026-10-24: PHP 8.3.6, Laravel 12.63.0, `APP_ENV=staging`, `APP_DEBUG=false`. Szczegolowa konfiguracja i granice jej weryfikacji: [Kolejki, harmonogram i Supervisor na VPS](007-Queues-Scheduler-VPS.md).

## Katalog publiczny

Document root domeny lub subdomeny musi wskazywac na katalog:

```text
/public
```

Nie nalezy wystawiac katalogu glownego projektu jako katalogu publicznego.

## Podstawowe kroki

Ponizszy przyklad dotyczy pierwszej instalacji. Na istniejacej instalacji nie nadpisywac `.env` i nie generowac ponownie APP_KEY. Wdrozenie kodu i migracje wymagaja odrebnej zgody; audyt kolejek ich nie wykonuje.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Uprawnienia

Katalogi `storage/` i `bootstrap/cache/` musza byc zapisywalne przez oba konteksty wykonawcze aplikacji:

- PHP-FPM dziala jako `www-data` i obsluguje reczne zlecenia wydruku oraz pobieranie pliku przez NEX Printer;
- cron i workery Supervisor dzialaja jako `ubuntu`, w tym automatyczne akcje drukowania.

Samo ustawienie wlasciciela na jednego z tych uzytkownikow nie wystarcza. Zalecana konfiguracja uzywa osobnej grupy systemowej `nex-oms`, bitu `setgid` na katalogach oraz domyslnych ACL. Dzieki temu pliki utworzone przez `www-data` sa dostepne dla `ubuntu` i odwrotnie, niezaleznie od `umask` procesu. Kod aplikacji pozostaje wlasnoscia uzytkownika wdrozeniowego; nie nalezy zmieniac wlasciciela calego repozytorium na `www-data`.

### Prywatne pliki NEX Printer

Dysk Laravel `local` ma katalog bazowy `storage/app/private`. Kod NEX Printer zapisuje kopie PDF zlecen do:

```text
storage/app/private/nex-printer/jobs/{uuid}.pdf
```

Przy Fakturze VAT albo Korekcie PDF moze zostac najpierw wygenerowany i zapisany w `storage/app/private/invoices/{invoice_id}/`, a nastepnie skopiowany do zadania NEX Printer. Etykieta kurierska jest pobierana do pamieci z integracji przewoznika i jej prywatna kopia zadania trafia do `nex-printer/jobs/`. Katalog `storage/app/private/nex-printer/releases/` zawiera manifest i instalator klienta; aplikacja je odczytuje, a publikuje je operator lub proces wdrozeniowy.

Pliki te sa prywatne. Nie wolno wystawiac `storage/app/private` przez serwer WWW ani kierowac do niego publicznego dowiazania `storage`. Agent otrzymuje PDF przez kontrolowany endpoint aplikacji.

Blad:

```text
Unable to create a directory at /var/www/nex-oms/storage/app/private/nex-printer/jobs
```

oznacza, ze proces tworzacy zlecenie nie moze utworzyc katalogu albo pliku w prywatnym dysku. Nie jest to powod do stosowania `chmod 777`.

### Konfiguracja na VPS

Ponizszy wariant zaklada katalog aplikacji `/var/www/nex-oms`, uzytkownika wdrozeniowego i workerow `ubuntu` oraz PHP-FPM `www-data`. Polecenia nalezy wykonac kontrolowanie przy pierwszej instalacji albo po odtworzeniu plikow z kopii:

```bash
getent group nex-oms >/dev/null || sudo groupadd --system nex-oms
sudo usermod -aG nex-oms ubuntu
sudo usermod -aG nex-oms www-data

cd /var/www/nex-oms

sudo install -d -o ubuntu -g nex-oms -m 2770 \
    storage \
    storage/app \
    storage/app/private \
    storage/app/private/nex-printer \
    storage/app/private/nex-printer/jobs \
    storage/app/private/nex-printer/releases \
    bootstrap/cache

sudo chown -R ubuntu:nex-oms storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} +
sudo find storage bootstrap/cache -type f -exec chmod 0660 {} +

sudo setfacl -R -m u::rwX,g::rwX,m::rwX,o::--- storage bootstrap/cache
sudo find storage bootstrap/cache -type d \
    -exec setfacl -m d:u::rwx,d:g::rwx,d:m::rwx,d:o::--- {} +
```

`setfacl` pochodzi z pakietu `acl`; jezeli polecenia nie ma na serwerze, nalezy zainstalowac ten pakiet przed konfiguracja ACL. Katalogi maja tryb `2770`: cyfra `2` wlacza `setgid`, dlatego nowe podkatalogi dziedzicza grupe `nex-oms`. Pliki maja tryb `0660`. Uzytkownicy spoza wlasciciela i grupy nie otrzymuja dostepu.

Po pierwszym dodaniu uzytkownikow do grupy trzeba w kontrolowanym oknie przeladowac PHP-FPM i lagodnie wznowic workery, poniewaz juz uruchomione procesy nie pobiora nowej listy grup:

```bash
sudo systemctl restart php8.3-fpm
sudo -u ubuntu /usr/bin/php /var/www/nex-oms/artisan queue:restart
sudo supervisorctl status
```

Przed restartem workerow nalezy uwzglednic aktywne zadania i zasady opisane w sekcji o Supervisorze. Nie stosowac `restart all` ani wymuszonego zabijania procesow. Cron uruchamiany co minute jako nowy proces `ubuntu` pobierze aktualne grupy przy nastepnym wykonaniu.

### Kontrola po konfiguracji

Najpierw sprawdzic rozwiniecie calej sciezki, wlasciciela, grupe, prawa i ACL:

```bash
namei -l /var/www/nex-oms/storage/app/private/nex-printer/jobs
getfacl /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u www-data test -w /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u ubuntu test -w /var/www/nex-oms/storage/app/private/nex-printer/jobs
```

Pelna kontrola zapisu i wzajemnego odczytu, bez uruchamiania wydruku ani integracji:

```bash
cd /var/www/nex-oms/storage/app/private/nex-printer/jobs
sudo -u www-data sh -c 'umask 007; printf test > .permission-test-www-data'
sudo -u ubuntu test -r .permission-test-www-data
sudo -u ubuntu sh -c 'umask 007; printf test > .permission-test-ubuntu'
sudo -u www-data test -r .permission-test-ubuntu
sudo rm -f .permission-test-www-data .permission-test-ubuntu
```

Kazde polecenie `test` powinno zakonczyc sie kodem `0`. Te kontrole nie tworza zlecenia NEX Printer, nie pobieraja etykiety i nie wysylaja niczego do API przewoznika.

### Ponowne wdrozenie i odtworzenie

- Nie uruchamiac `composer`, Artisan ani budowania cache jako `root`, bo utworzy to pliki niedostepne dla procesow aplikacji. Uzywac `ubuntu` albo jawnie naprawic prawa przed przeladowaniem uslug.
- Jezeli wdrozenie podmienia katalog `storage`, `bootstrap/cache` lub ich dowiazania symboliczne, ponownie wykonac konfiguracje wlasciciela, grupy, trybow i ACL na rzeczywistym katalogu docelowym.
- Po odtworzeniu kopii zapasowej ponownie zastosowac `chown`, `chmod` i ACL. Archiwum moze zachowac numery UID/GID z innego serwera albo nie zachowac ACL.
- Kontrole `namei`, `getfacl` i zapisu obu uzytkownikow wykonac przed wznowieniem workerow i przed testowym zleceniem wydruku.
- Katalog `storage/app/private/nex-printer/jobs` nalezy tworzyc jawnie podczas wdrozenia. Laravel potrafi utworzyc brakujacy podkatalog, ale tylko wtedy, gdy proces ma prawo zapisu do wszystkich katalogow nadrzednych.
- Nie stosowac `chmod -R 777`, nie przenosic prywatnych PDF do `public/` i nie rozwiazywac problemu przez uruchamianie workerow jako `root` albo `www-data`.

## Webhook PayNow i HTTP Basic Auth

Staging `https://oms.multi-click.pl` dziala na VPS OVHcloud z Ubuntu 24.04, Nginx i PHP 8.3-FPM. Globalny HTTP Basic Auth chroni panel OMS, ale blokuje rowniez zewnetrzne powiadomienia PayNow. Przy nowej instalacji albo odtworzeniu konfiguracji Nginx trzeba zachowac osobny wyjatek dla webhooka `POST /api/paynow/v1/notifications`.

Ponizszy blok umiescic we **wlasciwym bloku `server` HTTPS dla `oms.multi-click.pl`**, z document root wskazujacym na `/var/www/nex-oms/public`. Nie umieszczac go tylko w bloku HTTP przekierowujacym na HTTPS:

```nginx
# PayNow webhook: bez HTTP Basic Auth.
# Podpis powiadomienia weryfikuje Laravel.
location = /api/paynow/v1/notifications {
    auth_basic off;

    rewrite ^ /index.php break;

    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_hide_header X-Powered-By;
}
```

`location =` dopasowuje wylacznie wskazana sciezke. `rewrite ... break` przekazuje wykonanie do front controllera Laravel w tym samym location, bez ponownego wejscia do ogolnego, chronionego handlera PHP. Basic Auth nadal pozostaje aktywny dla panelu administracyjnego. Nie wylaczac go globalnie i nie dodawac wyjatku dla calego `/api/`. Nie trzeba otwierac dodatkowych portow w UFW; webhook korzysta z istniejacego HTTPS.

Blok musi wspolistniec z dotychczasowym wyjatkiem NEX-Printer w konfiguracji tego samego serwera. Nie zastepowac ani nie usuwac jego regul, nie rozszerzac ich na inne endpointy.

Zabezpieczenie webhooka po stronie Laravel jest niezalezne od Basic Auth: `PayNowWebhookController` przekazuje surowe body i naglowek `Signature` do `PayNowWebhookService`, a `PayNowSignatureService::verifyNotification()` sprawdza Base64 z HMAC SHA-256 przy uzyciu klucza podpisu konta oraz `hash_equals`. Trasa w `routes/web.php` obsluguje tylko POST i posiada middleware `throttle:paynow-webhook`; limit w `AppServiceProvider` wynosi 120 zadan na minute na adres IP. Wyjatek CSRF jest ograniczony do tego webhooka, a nie calego `/api/`.

### Kontrola konfiguracji webhooka

Po zapisaniu konfiguracji operator powinien najpierw sprawdzic jej skladnie. Przeladowanie wykonac tylko po poprawnym wyniku `nginx -t`:

```bash
sudo nginx -t
sudo systemctl reload nginx

curl -sS -o /dev/null -w '%{http_code}\n' \
    https://oms.multi-click.pl/api/paynow/v1/notifications

curl -sS -o /dev/null -w '%{http_code}\n' \
    https://oms.multi-click.pl/
```

Oczekiwane wyniki:

- `nginx -t`: poprawna konfiguracja;
- GET webhooka bez Basic Auth: HTTP `405`, poniewaz Laravel udostepnia ten endpoint tylko przez POST;
- GET panelu OMS bez uwierzytelnienia: HTTP `401`, czyli ochrona panelu nadal dziala;
- rzeczywiste, poprawnie podpisane i poprawne powiadomienie POST PayNow: HTTP `202` po przyjeciu przez aplikacje.

HTTP `405` potwierdza prawidlowe skierowanie zadania GET do aplikacji zamiast zatrzymania przez Basic Auth. **Nie jest pelnym testem integracji platniczej**, podpisu, ksiegowania ani odbioru rzeczywistego webhooka POST. Nie wysylac fikcyjnych powiadomien potwierdzajacych platnosc w celu sprawdzenia konfiguracji; moglyby wywolac skutki biznesowe.

### Stan potwierdzony na VPS

Wedlug wynikow przekazanych przez operatora dla zmiany wykonanej 2026-10-26:

- `nginx -t`: PASS;
- przeladowanie Nginx: PASS;
- PayNow GET: HTTP `405`;
- panel OMS GET bez danych Basic Auth: HTTP `401`.

Kopia konfiguracji sprzed zmiany: `/root/oms-nginx-before-paynow-20261026_100310.conf`.

Nie potwierdzono jeszcze rzeczywistej platnosci testowej ani odbioru poprawnie podpisanego webhooka POST z HTTP `202`. Powyzsze wyniki dokumentuja konfiguracje routingu i Basic Auth, nie pelna weryfikacje platnosci. W ramach aktualizacji tego dokumentu nie laczono sie z VPS, nie zmieniano serwera i nie wykonywano powyzszych polecen.

## Publiczny powrot klienta PayNow

Po wdrozeniu PAYNOW.5 konfiguracja integracji pokazuje adres strony powrotu OMS. Przy pustym polu wlasnego adresu nowe linki otrzymuja indywidualny, podpisany `continueUrl`. Istniejacego wlasnego URL nie nadpisujemy. Wpisanie dokladnego bazowego adresu OMS jest rozpoznawane jako wybor strony OMS, ale reczne otwarcie samego adresu bazowego pokazuje tylko neutralny komunikat, bez danych platnosci. Stare linki PayNow moga nadal kierowac na swoj poprzedni adres.

Klient nie moze byc proszony o haslo Basic Auth panelu OMS. Operator powinien po sprawdzeniu aktualnego vhosta udostepnic bez Basic Auth **wylacznie dokladne sciezki**:

```text
GET/HEAD /paynow/powrot
POST     /paynow/powrot/status
POST     /paynow/powrot/ponow
```

Nie otwierac calego `/paynow/`, `/orders/`, `/integrations/`, `/api/` ani `index.php`. Trasy aplikacji nadal sprawdzaja podpisy, POST wymaga CSRF, a ponowienie ma dodatkowe blokady i limity. Wyjatek dla webhooka to [osobna konfiguracja](#webhook-paynow-i-http-basic-auth) i nie nalezy go rozszerzac. Wynik HTTP `405` webhooka nie potwierdza dostepnosci trzech tras strony powrotu.

W Nginx samo `auth_basic off` z `try_files ... /index.php` moze nie wystarczyc, jesli wewnetrzne przekierowanie ponownie trafia do chronionego location PHP. Dla tych trzech dokladnych URI nalezy zachowac poprawne przekazanie do front controllera Laravel i kontekst HTTPS; sposob dopasowac do rzeczywistego vhosta. Nie dodawac ogolnego publicznego handlera PHP. Nie zmieniano zadnej konfiguracji VPS w ramach implementacji.

Przed dopuszczeniem klientow sprawdzic:

- publiczny HTTPS, poprawny certyfikat i prawidlowe adresy generowane dla domeny OMS (w tym istniejacy `APP_URL` i konfiguracje proxy); podpisu nie wolno naprawiac przez zmiane APP_KEY;
- bazowy GET zwraca neutralna strone bez monitu Basic Auth, a panel zamowien nadal wymaga hasla;
- POST bez CSRF jest odrzucany, a podpisana strona z identyfikatorem innej platnosci lub zmienionymi parametrami nie ujawnia danych;
- trwaly cache i atomowe blokady dzialaja wspolnie dla PHP-FPM; nie uzywac array/null na VPS;
- `.env`, klucze i historyczne proby pozostaja zachowane. Nie zmieniac srodowiska/credentiali PayNow podczas obslugi nierozstrzygnietych prob;
- logi HTTP i narzedzia monitoringu nie udostepniaja publicznie query stringow z podpisanymi URL. Strona sama nie laduje zewnetrznych fontow, skryptow ani analityki;
- test platnosci wykonywac tylko swiadomie na koncie Sandbox. Samo wejscie na bazowa strone nie testuje poprawnosci webhooka, API ani rzeczywistego obiegu bankowego.

Nowa strona nie wymaga migracji ani osobnego crona/jobow. Odczyt statusu klienta jest synchroniczny, a dotychczasowy scheduler PayNow i ksiegowanie pozostaja aktywne. Odczyt potwierdzenia moze uruchomic istniejace automatyzacje po prawidlowym zaksiegowaniu; nie jest to diagnostyka bez skutkow biznesowych.

## Krotkie linki platnosci PayNow /pay/

Po wdrozeniu tej funkcji przycisk generowania linku kopiuje adres
`https://oms.multi-click.pl/pay/<bezpieczny-kod>`. Kod to dokladnie 32 znaki
`A-Z`, `a-z`, `0-9`, `_` albo `-`. Sama domena pochodzi z generatora tras
aplikacji: na VPS musi byc prawidlowy HTTPS i host OMS, a lokalnie link
prowadzi do lokalnej instancji. Nie wpisywac domeny VPS na sztywno w kodzie.

Przed wznowieniem aplikacji uruchomic, po kopii bazy i przegladzie wdrozenia,
tylko odpowiednie oczekujace migracje; nowa migracja tej funkcji to:

```bash
php artisan migrate --path=database/migrations/2026_10_26_030000_create_paynow_short_links_table.php --force --no-interaction
```

Migracja dodaje tabele techniczna, bez backfillu i bez zmiany platnosci.
Bez niej tworzenie/kopiowanie linku zwraca 503 przed wywolaniem PayNow.
Nie wykonywac rollbacku niepustej tabeli ani odtwarzania starej bazy w celu
cofniecia samej zmiany interfejsu.

W serwerze HTTPS Nginx dodac **wylacznie scisly wyjatek** ponizej, obok
dotychczasowych wyjatkow webhooka i trzech tras powrotu klienta. Nie otwierac
calego `/pay/`, `/paynow/`, `/orders/`, `/api/` ani ogolnego handlera PHP.
Nie zmieniac wyjatku NEX-Printer.

```nginx
# Publiczny, losowy alias platnosci; panel OMS nadal ma Basic Auth.
# Cudzyslowy sa wymagane ze wzgledu na {32} w wyrazeniu regularnym.
location ~ "^/pay/[A-Za-z0-9_-]{32}$" {
    auth_basic off;
    access_log off;

    rewrite ^ /index.php break;

    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_hide_header X-Powered-By;
}
```

Sprawdzic kolejnosc i brak nadrzednego `location ^~ /pay/`, ktory pomijalby
ten regex. `rewrite ... break` pozostawia wykonanie w tym samym location,
bez ponownego wejscia do handlera PHP chronionego Basic Auth.
GET/HEAD obsluguje Laravel; inne metody nie udostepniaja nowej akcji.
Nie trzeba otwierac nowych portow UFW.

Token jest uprawnieniem do konkretnej platnosci, wiec nie wolno publikowac
pelnych linkow ani wysylac ich do zewnetrznych skracaczy, analityki i
monitoringu URL. `access_log off` ogranicza zapis tokenow w access logu
tego location. Logi bledow i ewentualny reverse proxy/CDN tez musza byc
chronione i nie moga ujawniac pelnych URL. Odpowiedzi aplikacji maja
`no-store`, `no-referrer` i `noindex`; nie konfigurowac cache dla tej trasy.

Po zatwierdzeniu konfiguracji operator wykonuje:

```bash
sudo nginx -t
sudo systemctl reload nginx

# Celowo nieistniejacy kod: sprawdzamy routing bez tworzenia platnosci.
curl -sS -o /dev/null -w '%{http_code}\n' \
  https://oms.multi-click.pl/pay/00000000000000000000000000000000

curl -sS -o /dev/null -w '%{http_code}\n' https://oms.multi-click.pl/
```

Oczekiwane wyniki: bledny kod 404 bez monitu Basic Auth, panel 401.
Istniejacy poprawny kod aktywnej proby zwraca 302 do PayNow; potwierdzona
lub niekwalifikujaca sie proba moze zwrocic 302 do podpisanej strony statusu
OMS. Nieznany kod i wygasly/odwolany alias daja neutralna strone 404.
Nie uzywac `curl -L` w kontroli samego przekierowania ani nie testowac
poprawnosci konfiguracji przez tworzenie rzeczywistej platnosci.

Istniejace wyjatki `/paynow/powrot`, `/paynow/powrot/status` i
`/paynow/powrot/ponow` sa nadal potrzebne do statusu i ponowienia. GET
aliasu nie wywoluje API ani ksiegowania; pozniejsze swiadome sprawdzenie
statusu na stronie powrotu zachowuje dotychczasowe skutki poprawnie
potwierdzonej wplaty. Krótki link nie uniewaznia dlugiego URL juz wydanego
przez PayNow i nie anuluje zdalnej transakcji.

W ramach lokalnej implementacji nie zmieniano Nginx, nie laczono sie z VPS
i nie potwierdzano dzialania tego wyjatku na serwerze.

## Domena klienta i weryfikacja danych - PORTAL.2B

Ta sekcja zastepuje historyczne adresy `/zamowienie/<32 znaki>` z PORTAL.1/2.
Nowy format to `https://klient.multi-click.pl/<numer OMS>/<10 znakow>`.
Stare adresy portalu nie sa obslugiwane. Nie dotyczy to zapisanych linkow
platnosci PayNow, ich `continueUrl`, webhooka ani `/pay/<32 znaki>`.

Po osobno zatwierdzonej kopii bazy uruchomic tylko migracje nowego etapu:

Ponizsza komenda zaklada, ze migracje PORTAL.1 i PORTAL.2 sa juz wykonane.
Przy pierwszym wdrozeniu portalu wykonac najpierw migracje 040000 i 050000
z opisow poprzednich etapow. Nie pomijac przegladu pozostalych zaleglych migracji.

```bash
php artisan migrate --path=database/migrations/2026_10_27_010000_add_customer_domain_and_verification_to_portal_settings.php --force --no-interaction
php artisan customer-portal:replace-legacy-links --dry-run
php artisan customer-portal:replace-legacy-links
```

Wymiana jest idempotentna i obejmuje tylko nadal aktywne stare dostepy.
Nie przedluza terminu, nie przywraca odwolan, nie pomija zmiany nabywcy,
nie wlacza portalu ani platnosci i nie wysyla wiadomosci. `--dry-run`
podaje potencjalnych kandydatow, a zapis ponownie sprawdza tozsamosc
nabywcy pod blokadami. Nie dodawac tej komendy do kazdego importu ani GET.
Po wdrozeniu odswiezyc dotychczasowe workery zatwierdzona procedura.
Jesli przed ich odswiezeniem stary proces utworzyl jeszcze stary kod,
ponowne wykonanie komendy wymiany obejmie tylko ten aktywny stary dostep.
Nie cofaj migracji ochrony danych automatycznie; rollback odmawia operacji.

W ustawieniach **Strony informacyjne** jest **Domena strony dla klientow**.
Wpisac `klient.multi-click.pl`, bez protokolu, portu, sciezki i ukosnika.
Puste pole korzysta z `APP_URL`; lokalnie dla localhost/127.0.0.1 zachowuje
tez rzeczywisty port przegladarki, np. 8000. Nie zmieniac globalnego
`APP_URL` panelu. Osobna domena nie moze byc domena panelu. Jej zmiana
zmienia adresy prezentowane w OMS i `[link_zamowienia]`, nie kody zamowien.
Nie przepisuje tresci juz wyslanych wiadomosci ani historycznych platnosci.
Najpierw przygotowac DNS/HTTPS/vhost, dopiero potem zapisac domene w OMS.
Zapis pola nie sprawdza wlasnosci DNS i nie instaluje certyfikatu.
Przed zapisem sprawdzic, czy APP_URL poprawnie wskazuje domene panelu OMS.
Na stagingu inne hosty niz panel i portal sa odrzucane, poza waskim wyjatkiem
historycznych podpisanych powrotow i aliasow platnosci. Lokalne srodowisko
dopuszcza dodatkowo loopback panelu; nie wlaczac APP_ENV=local na VPS.
Nie zmieniac domeny przy niedokonczonej probie tworzenia linku PayNow bez
otrzymanego ID/adresu operatora: dotychczasowy serwis odtwarza payload tej
proby z biezacej konfiguracji. Najpierw wyjasnic taka probe. Nie zmieniamy
w tym etapie przechowywania payloadow ani zdalnej idempotencji.

Przygotowac osobny vhost HTTPS dla domeny klienta. Panel OMS nadal ma
dotychczasowy Basic Auth. Ponizszy szablon wymaga juz istniejacego,
poprawnego certyfikatu; sciezki dopasowac do rzeczywistego serwera:

```nginx
server {
    listen 443 ssl;
    server_name klient.multi-click.pl;
    root /var/www/nex-oms/public;

    ssl_certificate /etc/letsencrypt/live/klient.multi-click.pl/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/klient.multi-click.pl/privkey.pem;

    # Tylko ten publiczny vhost. Nie wylaczac ochrony panelu OMS.
    auth_basic off;
    access_log off;

    location ~ "^(?:/[1-9][0-9]{0,17}/[A-Za-z0-9]{10}(?:/(?:dane|weryfikacja|logo|dokumenty/[0-9]+|produkty/[0-9]+/zdjecie|platnosc/(?:pay|status|retry)))?|/paynow/powrot(?:/(?:status|ponow))?|/pay/[A-Za-z0-9_-]{32})/?$" {
        fastcgi_cache off;
        rewrite ^ /index.php break;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Bez ogolnego try_files do Laravel ani publicznego handlera *.php.
    location / { return 404; }
}
```

Nie dodawac przekierowania klienta do panelu ani wyjatkow `/orders`,
`/settings`, `/invoices`, calego `/api` lub dowolnego PHP. Osobny blok HTTP
moze obslugiwac ACME i przekierowanie do HTTPS, zgodnie z metoda certyfikacji.
Nie sa potrzebne nowe porty aplikacji. Laravel dodatkowo sprawdza domene
publicznych tras i odmawia tras administracyjnych na domenie klienta.
Sesja klienta ma osobny host-only cookie `nex_oms_customer_portal_session`,
Secure i SameSite=Lax. Nie ustawiac wspolnego `SESSION_DOMAIN=.multi-click.pl`.
Zachowac HTTPS w FastCGI/proxy oraz poprawne zaufane proxy, aby IP i limity
zadan nie bazowaly na dowolnych naglowkach przesylanych przez klienta.

Na domenie OMS zachowac istniejace scisle wyjatki NEX-Printer, webhooka
PayNow, `/pay/<32 znaki>` i trzech tras `/paynow/powrot...`: sa potrzebne
rowniez dla wczesniej wydanych platnosci. Historyczny wyjatek
`/zamowienie/<32 znaki>` nie jest juz potrzebny i nie daje nowego dostepu.
Nie zmieniac podpisu webhooka, jego CSRF ani zasad ksiegowania.

W integracji PayNow **Adres strony powrotu OMS** korzysta z domeny klienta:
`https://klient.multi-click.pl/paynow/powrot`. Nowa domyslna proba dostaje
indywidualny podpisany URL z parametrami, nie ten sam pusty adres bazowy.
Wlasny opcjonalny URL konta nadal ma pierwszenstwo; nie jest nadpisywany.
Przy zmianie domeny sprawdzic ten wlasny URL, zwlaszcza jesli skopiowano
tam kiedys stary adres bazowy. Adresy powrotu zarejestrowanych juz zdalnie
platnosci pozostaja niezmienione. Formularze status/retry starego powrotu pozostaja
na jego pierwotnej domenie. Tylko przegladarka, ktora rozpoczela platnosc
w portalu i zachowala wazna sesje tego hosta, jest kierowana do zamowienia.
Sam podpis platnosci nie ujawnia kodu portalu; bez takiej sesji dziala
osobna strona statusu platnosci. Stary powrot do OMS nie przenosi sesji
z nowej domeny klienta i nie rozszerzamy w tym celu domeny cookie.

Po przyszlej zmianie domeny stary host moze nadal obslugiwac tylko
poprawnie podpisane `/paynow/powrot...` i `/pay/<32 znaki>`. Zachowac dla niego
DNS, HTTPS i te scisle location do wygasniecia wydanych linkow. Podpis jest
sprawdzany z pierwotnym hostem przed dopuszczeniem wyjatku w Laravel;
podmieniony host nie przechodzi kontroli. Stary host nie udostepnia panelu
ani portalu zamowienia. Nie jest automatycznie aliasem nowego portalu.

Maskowanie i weryfikacja PDF sa domyslnie wlaczone. Klient podaje jedna
pelna wartosc nabywcy: e-mail, imie i nazwisko lub firme (wg ustawienia).
Nie ma wysylania kodow ani logowania do skrzynki. Porownanie ignoruje
wielkosc liter; zachowuje diakrytyke i interpunkcje, porzadkuje odstepy
w nazwach. Puste dane, login marketplace i samo nazwisko nie wystarczaja.
Pelne dane nie sa osadzane w HTML/JSON przed weryfikacja; wylaczone sekcje
pozostaja ukryte. PDF sprawdza te sama sesje i dotychczasowe reguly dokumentu,
takze przy bezposrednim GET/HEAD. Pobieranie z panelu OMS jest bez zmian.

Potwierdzenie trwa 15 minut od poprawnej odpowiedzi, nie odnawia sie przy
odswiezaniu, jest ograniczone do zamowienia i przegladarki. Zmiana danych,
polityki, generacji, odwolanego linku lub terminu uniewaznia dostep.
Brak wymaganych danych wymaga kontaktu ze sklepem; nie ma obejscia bramki.
To ochrona oparta na znajomosci danych, nie potwierdzenie tozsamosci.
10 znakow alfanumerycznych daje okolo 59,5 bita losowosci, mniej niz stary
kod. Numer zamowienia jest przewidywalny i nie dodaje tajnosci.
Limity: 180 odczytow/min/IP, chybienia kodu 60/min/IP i 15/min/IP+numer,
bledna weryfikacja 20/min/IP i 5/min/IP+numer (60 sekund), bez trwalej
globalnej blokady zamowienia. Zachowac dotychczasowe limity platnosci.
Nie logowac kodow ani wpisywanych wartosci, rowniez na proxy/CDN/WAF/APM.
Nie cache'owac stron, odpowiedzi bledow, PDF ani formularzy; zachowac
no-store, no-referrer, noindex. Maskowanie nie ukrywa produktow i kwot.

Po recznej konfiguracji serwera, bez wykonywania platnosci:

```bash
sudo nginx -t
sudo systemctl reload nginx
curl -sS -o /dev/null -w '%{http_code}\n' https://klient.multi-click.pl/1/0000000000
curl -sS -o /dev/null -w '%{http_code}\n' https://klient.multi-click.pl/orders
curl -sS -o /dev/null -w '%{http_code}\n' https://klient.multi-click.pl/paynow/powrot
curl -sS -o /dev/null -w '%{http_code}\n' https://oms.multi-click.pl/
```

Oczekiwane: 404 dla nieistniejacego kodu i /orders, neutralne 200 powrotu
bez parametrow, 401 panelu bez uwierzytelnienia. Nie jest to test platnosci.
W prywatnym oknie sprawdzic maskowanie, poprawne/bledne dane, PDF, wygasniecie
15 minut i odwolanie linku. Nie wysylac fikcyjnego potwierdzenia platnosci.
Nie udostepniac dokumentow KSeF DEMO/TEST przez publiczna strone.
Ta dokumentacja nie potwierdza wykonania DNS/TLS/Nginx ani wdrozenia na VPS.

## Automatyczne linki portalu - PORTAL.2A (opis historyczny)

Od tej wersji nowe zamowienie automatycznie otrzymuje link po zatwierdzeniu
zapisu. Pierwszy rekord konfiguracji jest domyslnie wlaczony. Istniejace
is_enabled=false nie jest samoczynnie zmieniane. Wczesniejsze opisy
domyslnie wylaczonego PORTAL.1 ponizej dokumentuja stan poprzedniego etapu.
Nie zmieniac juz wykonanych migracji PORTAL.1/2; ten etap nie dodaje migracji.

Po kopii bazy i przegladzie ustawien/prywatnosci wykonac:

```bash
php artisan customer-portal:prepare-links --dry-run
php artisan customer-portal:prepare-links --enable
```

--enable jest swiadomym jednorazowym wlaczeniem portalu dla operatora.
Aby nie nadpisac pozniejszego recznego wylaczenia, nie dodawac --enable
do rutynowego skryptu kazdej aktualizacji.
Aby zachowac istniejace wylaczenie, pominac --enable. Komenda tworzy tylko
brakujace linki zamowien poza koszem. Nie zmienia istniejacych kodow,
odwolan ani terminow; stare zamowienia moga otrzymac juz wygasly link
zgodnie z pierwotna data zakupu. Nie wysyla wiadomosci, nie wywoluje API
i nie wlacza platnosci. Gotowy link jest poufnym dostepem do zamowienia
i dozwolonych dokumentow, dlatego nie wyswietlac tokenow w logach.

Utrzymac scisle wyjatki Nginx opisane ponizej. Chroniony panel ustawien
pozwala wybrac numer zamowienia i swiadomie odnowic albo wylaczyc jego link.
Publiczny link nie prowadzi do zarzadzania ustawieniami. Nie tworzymy linku
podczas GET. W Zarzadzaniu pokazujemy gotowy aktywny adres albo powod
niedostepnosci, bez przycisku Strona klienta.

Zmiana danych identyfikujacych nabywce nadal uniewaznia stary dostep.
Dotyczy tez uzupelniania nabywcy po utworzeniu pustego zamowienia recznego:
po zakonczonej edycji nalezy odnowic link w ustawieniach, jesli pokazuje
stan wymagajacy odnowienia. Nie odnawiac automatycznie kodu, ktory mogl
zostac juz przekazany poprzedniemu odbiorcy.

Po wdrozeniu standardowo odswiezyc istniejace workery importu/automatyzacji,
aby wczytaly obserwatora. Bez nowego workera ani crona. Rzeczywiste
uruchomienie VPS i aktualizacja Nginx wymagaja osobnego zatwierdzenia.

## Strony informacyjne klienta - PORTAL.1 (opis historyczny)

Portal jest domyslnie wylaczony. Po kopii bazy i przegladzie wdrozenia
nowe tabele techniczne dodaje wylacznie migracja:

```bash
php artisan migrate --path=database/migrations/2026_10_26_040000_create_customer_portal_tables.php --force --no-interaction
```

Nie ma backfillu, automatycznej wysylki linkow ani nowego crona. Ustawienia
sa dostepne w panelu jako **Strony informacyjne**. Podglad administratora
nie tworzy linku klienta i musi pozostac chroniony Basic Auth. Przed
wlaczeniem trzeba ustawic osobne publiczne nazwy i opisy rzeczywistych
statusow OMS. Status nieudostepniony nie ujawnia nazwy wewnetrznej.

W tym samym bloku HTTPS `server` Nginx, obok zachowanych wyjatkow
NEX-Printer, PayNow i `/pay/`, dodac tylko scisle trasy portalu:

```nginx
# Token jest uprawnieniem do konkretnego zamowienia i jego zasobow.
location ~ "^/zamowienie/[A-Za-z0-9_-]{32}(?:/(?:logo|dokumenty/[0-9]+|produkty/[0-9]+/zdjecie|platnosc/(?:pay|status|retry)))?$" {
    auth_basic off;
    access_log off;
    fastcgi_cache off;

    rewrite ^ /index.php break;

    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_hide_header X-Powered-By;
}
```

Regex musi byc przed ogolnym regexem PHP, bez nadrzednego `location ^~`,
ktory go pomija. Nie otwierac calego `/zamowienie/`, `/settings/`,
`/orders/`, `/invoices/`, `/api/` ani ogolnego `index.php`.
Laravel dopuszcza GET/HEAD odczytu oraz POST tylko dla trzech akcji platnosci,
kontroluje waznosc i przynaleznosc tokenu takze
przy kazdym PDF, logo i zdjeciu oraz ogranicza ruch do 180 zadan/minute/IP.
Nie trzeba otwierac nowych portow. Pliki pozostaja na prywatnym dysku;
oba konta `www-data` i `ubuntu` nadal wymagaja uprawnien opisanych wyzej.

Link jest poufny: kazdy jego posiadacz moze odczytac udostepnione dane.
Nie zapisywac pelnych URL w monitoringu, analityce, CDN ani logach proxy;
zabezpieczyc rowniez logi bledow. Nie konfigurowac cache odpowiedzi tych
tras. Aplikacja ustawia no-store, no-referrer i noindex. Token jest
losowy (192 bity), przechowywany w postaci szyfrowanej obok skrotu SHA-256;
nie zmieniac APP_KEY podczas wdrozenia. Token PayNow nie daje dostepu do
portalu. Zmiana tozsamosci kupujacego, wygasniecie, kosz lub usuniecie
zamowienia uniewazniaja dostep. Nowy link swiadomie uniewaznia poprzedni.

Kontrola konfiguracji, bez tworzenia linku ani platnosci:

```bash
sudo nginx -t
sudo systemctl reload nginx
curl -sS -o /dev/null -w '%{http_code}\n' \
  https://oms.multi-click.pl/zamowienie/00000000000000000000000000000000
curl -sS -o /dev/null -w '%{http_code}\n' https://oms.multi-click.pl/
```

Oczekiwane: nieistniejacy kod 404 bez monitu Basic Auth, panel 401.
Nie jest to pelny test dostepu klienta. Po swiadomym wlaczeniu w panelu
sprawdzic link wybranego zamowienia w prywatnym oknie, pobranie wlasnego
PDF i odmowe dostepu po wylaczeniu/zmianie linku. Publicznie dokumenty
KSeF sa dostepne dopiero po akceptacji w Production; DEMO/TEST tylko
w podgladzie administratora. Dokument Offline przed akceptacja nie jest
udostepniany. Nie zmieniac ustawien KSeF w celu wykonania tego testu.

Zmienna `[link_zamowienia]` jest opcjonalna w szablonach e-mail.
Jej podglad nie zapisuje dostepu; przygotowanie wiadomosci tworzy link
transakcyjnie dla adresu kupujacego. Nie dodajemy jej do starych szablonow
ani nie wysylamy zbiorczo. Po wdrozeniu kodu odswiezyc workery zwykla,
zatwierdzona procedura wdrozeniowa. Nie ma nowego workera ani zadania
harmonogramu. Nie cofaj niepustych tabel portalu przez rollback migracji.

W ramach implementacji lokalnej nie zmieniano konfiguracji VPS.
Szczegoly i ograniczenia: [PORTAL.1](portal-1-report.md).

### PORTAL.2 - platnosci PayNow w portalu

Po kopii bazy dodac ustawienia tylko migracja addytywna:

```bash
php artisan migrate --path=database/migrations/2026_10_26_050000_add_paynow_to_customer_portal_settings.php --force --no-interaction
```

Nie ma backfillu ani nowego harmonogramu. `paynow_enabled` i
`paynow_retry_enabled` pozostaja domyslnie false; `paynow_sources = null`
oznacza tylko zamowienia reczne, a pusta lista nie dopuszcza zadnego zrodla.
PrestaShop wymaga jawnego zaznaczenia; Allegro nie jest obslugiwane przez
platnosci portalu w tym etapie. Zwykly podglad zamowienia nadal dziala
takze bez migracji PORTAL.2, ale akcje platnicze sa wtedy niedostepne.

Powyzszy wyjatek Nginx obejmuje tez scisle sciezki
`/zamowienie/<kod>/platnosc/pay`, `/status` i `/retry`. Nie otwierac
szerszego prefiksu. Wszystkie trzy trasy wymagaja POST, sesji i CSRF;
nie dodawac ich do wyjatkow CSRF webhooka. Limit wynosi 20 POST/min/IP
i 6 POST/min/kod, niezaleznie od ogolnego limitu odczytu portalu.
Zachowac istniejace wyjatki `/pay/<kod>`, strony powrotu PayNow,
webhooka i NEX-Printer. Cookies sesji musza dzialac przez HTTPS
(`Secure`, `HttpOnly`, `SameSite=Lax`); nie wylaczac ich na reverse proxy.

Klient nie tworzy pierwszego linku. Operator lub istniejaca automatyzacja
musi najpierw przygotowac probe PayNow w OMS. Portal dopuszcza kontynuacje
zgodnej aktywnej proby, jawne sprawdzenie statusu oraz opcjonalne ponowienie
po swiezym odczycie REJECTED/ERROR/EXPIRED. Sam uplyw lokalnego terminu
nie oznacza potwierdzonego EXPIRED. ABANDONED, nieznany wynik, needs_review,
niezaksiegowane CONFIRMED, inna nierozstrzygnieta proba, zmiana kwoty/waluty/
klienta, anulowanie, kosz i pobranie blokuja nowe zlecenie z portalu.
Zmiana danych po otwarciu strony wymaga jej odswiezenia; portal nie sluzy
do automatycznego zastapienia linku przygotowanego na inna kwote.

GET portalu/podgladu nie laczy sie z API i nie ksieguje wplat.
POST sprawdzenia lub ponowienia moze przez istniejacy serwis PayNow
zaksiegowac potwierdzona wplate i uruchomic dotychczasowe automatyzacje.
Nie jest to test bez skutkow biznesowych. Pierwsze proby wykonywac tylko
na przygotowanych danych testowych i koncie Sandbox, po swiadomym wlaczeniu
ustawien. Nie testowac fikcyjnym webhookiem. Nie zmieniac credentiali ani
srodowiska konta z nierozstrzygnietymi probami.

Strona powrotu pokazuje `Powrot do zamowienia` tylko w tej samej sesji
przegladarki, ktora rozpoczela akcje w portalu, i tylko przy nadal waznym
uprawnieniu. Sam podpisany link PayNow nie ujawnia kodu portalu.
Stare `continueUrl` i wlasny URL powrotu pozostaja niezmienione;
przy wlasnym URL przycisk powrotu OMS nie moze byc pokazany.
Odwolanie portalu nie anuluje juz utworzonej transakcji PayNow.

Po wdrozeniu odswiezyc workery standardowa zatwierdzona procedura.
Nie ma nowego workera. Szczegoly: [PORTAL.2](portal-2-report.md).

## Cron Laravel

Na stagingu jeden plik `/etc/cron.d/nex-oms-scheduler` uruchamia istniejacy harmonogram `routes/console.php`:

```cron
* * * * * ubuntu cd /var/www/nex-oms && /usr/bin/php artisan schedule:run >> /var/www/nex-oms/storage/logs/scheduler.log 2>&1
```

W tym formacie `ubuntu` jest polem uzytkownika `/etc/cron.d`; nie kopiowac go do crontaba uzytkownika. Osobne stare crony importu Allegro i odswiezania InPost zostaly wylaczone wedlug operatora. Nie uruchamiac jednoczesnie dodatkowego crona lub `schedule:work`. `schedule:run` ma skutki biznesowe, nie sluzy jako zwykly test zdrowia.

## Kolejki i Supervisor

Supervisor nadzoruje siedem workerow: `default`, `integrations`, `shipments-actions`, `shipments-sync`, `automation`, `ksef` na polaczeniu `database` oraz `ksef-submit` na polaczeniu `ksef_submit`. Importy Allegro/PrestaShop sa juz zaimplementowane i korzystaja z `integrations`; nie jest to kolejka oczekujaca na przyszly etap.

Aktywne retry_after stagingu wynosi 300 s dla `database` oraz 240 s dla `ksef_submit`. Job automatycznej wysylki KSeF ma wlasny timeout 120 s, mimo workerowego 150 s. Automatyczna wysylka jest wedlug operatora wylaczona w OMS; uruchomiony worker jej nie wlacza.

Pelna tabela parametrow siedmiu workerow, 31 jobow, 19 zadan schedulera lokalnego kodu (18 w pierwotnym audycie VPS), diagnostyka bez sekretow i procedura lagodnego restartu po zatwierdzonym wdrozeniu znajduja sie w [dokumencie operacyjnym](007-Queues-Scheduler-VPS.md). Nowy callback recovery Automation wymaga osobnej [migracji i wdrozenia poprawki](008-Automation-Execution-Recovery.md); na VPS ich nie wykonano. Przed zatrzymaniem procesu trzeba uwzglednic stopwaitsecs, istniejace zlecenia oraz wyniki niepewne; nie ponawiac hurtowo failed jobs.
