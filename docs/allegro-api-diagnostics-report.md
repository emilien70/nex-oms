# Allegro - diagnostyka API i OAuth

Data: 2026-11-04. Zakres lokalny. Bez commita, pusha, wdrozenia na VPS
oraz rzeczywistych wywolan API Allegro, platnosci i KSeF.

## Cel i zachowanie

Dotychczas klient REST po HTTP 401 odswiezal token i zapisywal dopiero
koncowy wynik. Pierwszej odpowiedzi nie mozna bylo odnalezc w historii OMS.
Dodano zapis tej proby przed istniejacym odswiezeniem oraz Trace-Id odpowiedzi.

Przyklad wpisow po udanym ponowieniu:

| operation | attempt_number | response_status | request_id |
| --- | --- | --- | --- |
| order_events | 1 | 401 | wspolny UUID OMS |
| refresh_token | 1 | 200 | ten sam UUID |
| order_events | 2 | 200 | ten sam UUID |

Numer proby dotyczy konkretnej operacji: OAuth nie stanowi drugiej proby
odczytu zamowienia. response_trace_id pochodzi z odpowiedzi Allegro;
request_id pozostaje identyfikatorem OMS, nie jest z nim utozsamiany.
Proaktywne odswiezenie rowniez moze korzystac ze wspolnego UUID. Istniejace
wywolania accessToken/refresh z jednym argumentem pozostaja poprawne.

Nie dodano dodatkowego retry, zadania, harmonogramu, opoznienia ani HTTP.
Zachowano prog pieciu minut, maksymalnie jedno ponowienie po 401 i istniejace
sprawdzenie konta/uprawnien przed ponowieniem refundacji oraz prowizji.
Pierwszy 401 nie wywoluje dodatkowego alarmu, jesli operacja sie powiedzie.
500/503 i bledy polaczenia zachowuja dotychczasowa polityke obslugi.

Nie zmieniono metody HTTP, body, PDF, nazw plikow, User-Agent ani ustawien.
duration_ms API nadal mierzy czas od rozpoczecia operacji; drugi wpis obejmuje
rowniez OAuth. OAuth mierzy oddzielnie czas swojego zadania.

## Ochrona logow

Pierwsza odpowiedz 401 ma response_payload=NULL i staly opis techniczny.
Nie zapisuje sie jej tresci, tokenow, hasel, cookies ani pelnych naglowkow.
Dotychczasowe filtrowanie koncowych payloadow i danych OAuth pozostaje aktywne.
Nowy odczyt naglowkow jest ograniczony do Trace-Id. Dopuszcza pojedynczy
identyfikator ASCII do 255 znakow z liter, cyfr, kropki, myslnika,
podkreslenia i dwukropka. Brak, niepoprawny format lub wiele wartosci daje
NULL, bez blokowania prawidlowej odpowiedzi API.

Nie zmieniono retencji logow. Pozostale integracje nie musza wypelniac nowych
pol. Nie odtwarza sie dawnych Trace-Id ani pierwszych odpowiedzi 401.

## Pliki

Zmodyfikowane:

- Modules/Integrations/Allegro/Services/AllegroClient.php
- Modules/Integrations/Allegro/Services/AllegroOAuthService.php
- Modules/Shipments/Models/IntegrationApiLog.php
- docs/architecture.md
- docs/product-spec.md

Utworzone:

- Modules/Integrations/Allegro/Support/AllegroApiDiagnostics.php
- database/migrations/2026_11_04_020000_add_allegro_diagnostics_to_integration_api_logs.php
- tests/Feature/AllegroApiDiagnosticsTest.php
- tests/Unit/AllegroApiDiagnosticsTest.php
- docs/allegro-api-diagnostics-report.md

Nie usunieto plikow. Wczesniejsze niezacommitowane zmiany powiadomien
w resources/views/layouts/app.blade.php i tests/Frontend/automation-activity-dismissal.test.cjs
pozostaly zachowane, nie sa czescia tej poprawki.

## Migracja i kopia lokalna

Za zgoda wlasciciela wykonano spojna kopie SQLite przez backup API,
z odczytem zrodla mode=ro; integralnosc kopii: ok.

```text
storage/app/private/backups/nex-oms-before-allegro-api-diagnostics-20261104-141810.sqlite
SHA256 94d54e30b63c3121aaba9c02dfc2ffd0870b4be8530b5ef0dcc8fbcc9ef64e43
```

Nastepnie uruchomiono tylko:

```powershell
C:\xampp\php\php.exe artisan migrate --path=database/migrations/2026_11_04_020000_add_allegro_diagnostics_to_integration_api_logs.php --force --no-interaction
```

Migracja dodaje nullable response_trace_id (varchar 255) i attempt_number
(unsigned smallint), bez nowych tabel, indeksow, FK i backfillu. Uzywa
istniejacego nieunikalnego indeksu request_id. Porownanie tylko do odczytu
z kopia potwierdzilo niezmiennosc wszystkich 40205 historycznych wpisow API,
NULL w nowych polach tych rekordow i pojedynczy wpis migracji.
Nie uruchamiano rollbacku ani innych migracji na bazie wlasciciela.
Down usuwa wylacznie nowe pola; nie jest sposobem odzyskania ich diagnostyki.

## Testy

PHP 8.2.12, APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:,
pusty DB_URL i osobny testowy APP_KEY. Nowe testy blokuja niezasymulowane HTTP.

Nowe testy: 21 testow, 144 asercje, PASS. Obejmuja 401/odswiezenie/200,
drugie 401 bez kolejnego retry, blad OAuth, blad polaczenia przy drugiej
probie, 500/503 bez dodatkowego retry, proaktywne odswiezenie, stare wywolanie
OAuth, zachowanie PUT/PDF/User-Agent, brak sekretow, walidacje Trace-Id oraz
zgodnosc migracji z historycznymi logami innych integracji.

Szersza regresja: wszystkie tests/Unit/Allegro*Test.php oraz
tests/Feature/Allegro*Test.php, a takze IntegrationApiLogPrunerTest,
DpdShipmentTest, InPostShipmentTest i InPostCourierShipmentTest:
681 testow, 4543 asercje, PASS.

Pint --test i php -l: PASS dla siedmiu plikow PHP tej poprawki.
git diff --check: PASS. Git informuje tylko o dotychczasowej normalizacji
CRLF w docs/product-spec.md.

```text
node --test tests/Frontend/automation-activity-dismissal.test.cjs tests/Frontend/order-automation-history.test.cjs
```

19 testow JavaScript: PASS; potwierdzaja zachowanie poprzedniej poprawki UI.
Nie uruchamiano pelnej regresji wszystkich modulow ani zestawu testow KSeF.

## Weryfikacja i ograniczenia

Nie dodano nowego ekranu lub ustawienia. Po restarcie tylko lokalnego Main
Queues zwykla praca OMS zacznie wypelniac nowe pola. Wlasciciel zadeklarowal
samodzielny restart; w tym etapie agent go nie wykonywal. Nie restartowac
KSeF ani uzywac globalnego queue:restart.

Bezpieczny odczyt diagnostyki:

```sql
SELECT operation, request_id, attempt_number, response_status,
       response_trace_id, successful, created_at
FROM integration_api_logs
WHERE integration = 'allegro'
ORDER BY id DESC
LIMIT 20;
```

Przy naturalnym 401 nalezy sprawdzic wspolny request_id i osobne Trace-Id.
Nie prowokowac bledu przez edycje tokenow, uprawnien lub rzeczywiste
operacje finansowe. Testy korzystaly z symulacji; nie oczekuje sie, aby
poprawka usunela historyczne bledy w Developer Apps lub awarie Allegro.
Nie wykonywano testu migracji na MySQL ani zadnych operacji na VPS.
