# Kontrolowana aktualizacja stagingu VPS

Skrypt: [scripts/nex-oms-deploy.sh](../scripts/nex-oms-deploy.sh).
Nie uruchomiono go na VPS podczas tworzenia. Nie wykonuje instalacji pakietow,
zmian konfiguracji Supervisor/Nginx ani przywracania bazy.

## Profil i ograniczenia

- Ubuntu, uzytkownik `ubuntu`, aplikacja `/var/www/nex-oms`, galaz `main`.
- MySQL **8.0**, baza `nex_oms`, lokalny administracyjny dostep `sudo mysql`.
- `APP_ENV=staging`, KSeF DEMO, automatyczna wysylka KSeF wylaczona.
- Siedem istniejacych workerow Supervisor i jeden cron
  `/etc/cron.d/nex-oms-scheduler` zgodnie z dokumentem 007.
- `retry_after`: `database=300`, `ksef_submit=240`.
- Kontrola HTTPS `https://oms.multi-click.pl/up`; wymagane dane Basic Auth panelu,
  nie haslo SSH. Nie wylacza weryfikacji TLS i nie podaza za przekierowaniem.
- Wymagane sa istniejace: Bash, PHP, Git, Composer, curl, gzip, flock, mysql,
  mysqldump, sha256sum i GNU sync. Nie wymaga Node/npm/Vite.

To nie jest narzedzie do Production ani mechanizm aktualizowania bez wiedzy
operatora. Nie dodawac go do crona. Nowe zadania moga normalnie wykonac skutki
biznesowe po wznowieniu workerow. Sam skrypt nie zleca wysylek, platnosci,
drukowania ani `schedule:run` i nie ponawia failed jobs.

## Instalacja i uruchomienie

1. Przez WinSCP, konto SSH `ubuntu`, przeslij **tylko** plik
   `scripts/nex-oms-deploy.sh` do `/home/ubuntu/nex-oms-deploy.sh`.
   Uzyj trybu binarnego, aby zachowac zakonczenia linii LF. Nie nadpisuj starego
   zalacznika z Pulpitu. Plik ma dzialac poza katalogiem aktualizowanego kodu.
2. W PuTTY jako `ubuntu`:

```bash
chmod 700 /home/ubuntu/nex-oms-deploy.sh
bash -n /home/ubuntu/nex-oms-deploy.sh
bash /home/ubuntu/nex-oms-deploy.sh
```

Menu:

- `1` pobiera `origin/main`, pokazuje konkretne SHA i wymaga jego wpisania;
- `2` przywraca poprzedni kod tylko dla zgodnej, zakonczonej aktualizacji;
- `3` pokazuje kod, workery, cron, historie i stan aplikacji bez wykonywania jobow;
- `0` konczy prace bez aktualizacji.

Sama diagnostyka:

```bash
bash /home/ubuntu/nex-oms-deploy.sh --status
```

Nie uruchamiac calego skryptu przez `sudo`, przez `source`, potok `curl | bash`
ani przez wklejenie zawartosci do terminala. Uruchom jedna kopie pliku w jednej
sesji PuTTY. Nie zamykaj terminala podczas wdrozenia i nie wykonuj wtedy operacji
w OMS, innych polecen Git ani osobnego wdrozenia.

## Przebieg

Skrypt blokuje rownolegla kopie, sprawdza czysty stan plikow sledzonych oraz
kolizje niesledzonych plikow. Kopie `.env.*` i pliki blokad nie sa usuwane.
Zalegle migracje, zmiany konfiguracji, zaleznosci, bootstrapu, chronionych
katalogow, zrodel Vite, dowiazan lub starych migracji wymagaja osobnego wdrozenia.

Przed przerwa sprawdza Basic Auth, PHP-FPM, zapis dla `ubuntu` i `www-data`,
puste kolejki oraz brak running/queued/waiting automatyzacji. Potwierdza przez
`@@server_uuid`, ze aplikacja i administracyjny backup korzystaja z tego samego
serwera MySQL. Backup transakcyjny jest dozwolony tylko dla tabel InnoDB.

Nastepnie zapisuje prywatna historie **przed** wstrzymaniem uslug, przenosi cron,
czeka 65 sekund na jego przeladowanie, sprawdza pozostale procesy harmonogramu,
wlacza maintenance i lagodnie zatrzymuje siedem workerow. Ponownie wymaga pustych
kolejek. Nie kasuje rezerwacji ani automatyzacji w celu przejscia tej kontroli.

Tworzy nowa kopie bazy z `--single-transaction --set-gtid-purged=OFF`, sprawdza
gzip i zapisuje SHA256. Nie przywraca SQL. Przy awarii backupu pozostaje `.part`,
ktory nie jest traktowany jako poprawna kopia. Kopia obejmuje baze, nie prywatne
PDF ani instalatory NEX Printer; ich odrebna polityka backupu nadal obowiazuje.

Kod jest aktualizowany fast-forward. Skrypt przebudowuje tylko autoload
(`--no-scripts --no-plugins`), cache widokow i odtwarza poprzedni sposob obslugi
cache tras. **Nie przebudowuje config cache**, nie czysci wspolnego cache/lockow,
nie zmienia `.env`, APP_KEY ani ustawien KSeF. Globalne `umask=022` dotyczy kodu;
prywatne dane maja osobne katalogi 0700 i pliki 0600. Istniejace wspolne ACL
`storage` i `bootstrap/cache` musza byc skonfigurowane wedlug dokumentu 006.

Po przeladowaniu PHP-FPM sprawdza `/up` jeszcze w maintenance (Laravel w tej
aplikacji wylacza `/up` z blokady konserwacji), zgodnosc konfiguracji, historie
migracji i dostep PHP-FPM do nowych plikow. Punkt powrotu jest utrwalany przed
uruchomieniem workerow. Po wznowieniu ponawia kontrole HTTP, workerow i aplikacji.
To nie jest dowod poprawnosci wszystkich integracji biznesowych.

Haslo Basic Auth jest odczytywane bez echa, umieszczane w tymczasowym pliku curl
0600 wewnatrz katalogu 0700 i usuwane przy normalnym wyjsciu/sygnale. Nie trafia
do historii operacji ani argumentow curl. SIGKILL/zasilanie moga ominac cleanup;
ewentualny pozostaly katalog jest prywatny i wymaga pozniejszego przegladu.

## Cofanie kodu

Baza NIE jest cofana i nie wykonuje sie `migrate:rollback`. Przed cofnieciem
powstaje kolejna kopia bazy. Uzywany jest `git reset --keep`, nie `--hard`;
kolizje plikow sa dodatkowo kontrolowane. Trzeba miec puste kolejki i brak
odroczonych automatyzacji, zgodna konfiguracje i niezmieniona historie migracji.

Domyslnie za odwracalne uznawane sa tylko zmiany szablonow zamowien/glownego
layoutu, dokumentacji i testow, bez zmian backendu, schematu lub zaleznosci.
Zmiany logiki biznesowej nie otrzymuja automatycznej zgody na rollback.
Ich zgodnosc danych oraz starych/nowych payloadow wymaga analizy osobno.

Przycisk cofania obejmuje tylko aktualizacje wykonane tym skryptem. Nie tworzy
wstecz historii wdrozenia `30e7ff9 -> d349a99` wykonanego poprzednim blokiem.
Po cofnieciu nie oferuje drugiego cofniecia na podstawie tego samego rekordu.

## Zatwierdzenie migracji lub zgodnosci rollback

Nie zatwierdzaj migracji samym wpisaniem ogolnego hasla. Po przegladzie kodu,
testach i ocenie kompatybilnosci operator moze zainstalowac jako root plik:

```text
/root/nex-oms-backups/deployments/approvals/PELNE_STARE_SHA-PELNE_NOWE_SHA.json
```

Przykladowa struktura (placeholdery, nie gotowe zatwierdzenie):

```json
{
  "version": 1,
  "from": "40-znakowe-stare-sha",
  "to": "40-znakowe-nowe-sha",
  "rollback_code": false,
  "reason": "Opis osobno sprawdzonej zgodnosci i zatwierdzonych migracji.",
  "migrations": [
    {
      "path": "database/migrations/2026_XX_XX_XXXXXX_przyklad.php",
      "sha256": "64-znakowy-sha256-tresci-migracji-z-docelowego-commita"
    }
  ]
}
```

Wymagana jest dokladna para SHA i pelna lista nowych migracji z sumami tresci.
Dla zatwierdzenia rollback bez migracji uzyj `"migrations": []`.
`rollback_code=true` oznacza potwierdzona kompatybilnosc starego kodu z nowym
schematem, danymi i zadaniami, nie pozwolenie na cofanie danych. Skrypt nie
tworzy takich zatwierdzen sam i nie potwierdza ich poprawnosci biznesowej.

## Przerwane wdrozenie

Metadane i etapy sa zapisywane atomowo (plik tymczasowy, `sync -f`, rename,
`sync -f` katalogu) w
`/root/nex-oms-backups/deployments/`. `active.json` blokuje kolejna mutacje po
awarii. Wybierz `3` / `--status` i zachowaj log. Nie usuwaj tego pliku, nie
przywracaj crona/uslug ani nie ponawiaj migracji w ciemno. Samo wystapienie
`status=completed` przy nadal istniejacym `active.json` wymaga przegladu.

Skrypt celowo nie wznawia uslug ani nie przywraca poprzedniego kodu automatycznie
w trapie: trwajace efekty zewnetrzne lub czesciowa migracja MySQL wymagaja decyzji
operatora. Niepelna kopia, brak uprawnien czy blad sieci przed przerwa zatrzymuja
operacje bez wstrzymywania aplikacji.

## Weryfikacja lokalna

Testy pomocnika nie korzystaja z bazy OMS ani prawdziwych polecen administracyjnych:

```text
bash -n scripts/nex-oms-deploy.sh
node --test tests/Deployment/nex-oms-deploy.test.cjs
```

Testy obejmuja metadane, zatwierdzenia, kontrolowane kody bledow PHP, parsowanie
stanow Supervisor, reguly plikow i kolejnosc zapisu/przywracania. Test na Windows
nie zastepuje pierwszego kontrolowanego przebiegu Ubuntu/MySQL/FPM. Nie uruchamiac
pelnego skryptu lokalnie na katalogu developerskim ani na rzeczywistych zadaniach
w celu przetestowania awarii.
