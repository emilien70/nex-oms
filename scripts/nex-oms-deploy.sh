#!/usr/bin/env bash
# NEX-OMS staging only. Run a copy OUTSIDE the application checkout as ubuntu.
set -Eeuo pipefail
umask 022

APP="/var/www/nex-oms"
ROOT="/root/nex-oms-backups"
HISTORY="$ROOT/deployments"
ACTIVE="$HISTORY/active.json"
LAST="$HISTORY/last-update.json"
CRON="/etc/cron.d/nex-oms-scheduler"
HEALTH_URL="https://oms.multi-click.pl/up"
LOCK_DIR="$HOME/.local/state/nex-oms-deploy"
WORKERS=(nex-oms-default nex-oms-integrations nex-oms-shipments-actions nex-oms-shipments-sync nex-oms-automation nex-oms-ksef nex-oms-ksef-submit)
PHASE="preflight"
RECORD=""
TEMP_DIR=""
CHANGED=()
MIGRATIONS=()
MIGRATION_PAIRS=()
ACTION=""
FROM=""
TO=""
BACKUP=""
PAUSED=""

say() { printf '\n=== %s ===\n' "$*"; }
fail() { printf 'STOP: %s\n' "$*" >&2; exit 1; }

# Pure metadata modes do not bootstrap Laravel. Application modes run as ubuntu.
php_tool() {
    php -r '
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Models\KsefSetting;
use Modules\Ksef\Enums\KsefEnvironment;

try {
    $appPath = $argv[1];
    $mode = $argv[2];
    $args = array_slice($argv, 3);
    $json = static fn ($s) => json_decode($s, true, 512, JSON_THROW_ON_ERROR);
    $emit = static function ($data): void {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    };
    if ($mode === "summary") {
        $data = $json(stream_get_contents(STDIN));
        if (($args[0] ?? "") === "snapshot") {
            $emit(["profile" => "STAGING / KSEF DEMO / AUTO OFF", "maintenance" => $data["maintenance"], "migrations_count" => count($data["applied"]), "pending" => $data["pending"], "route_cached" => $data["route_cached"]]);
        } else {
            $emit(array_intersect_key($data, array_flip(["action", "status", "phase", "from", "to", "backup", "record_path", "paused_cron"])));
        }
        exit(0);
    }
    if ($mode === "get") {
        $data = $json(stream_get_contents(STDIN));
        if (!array_key_exists($args[0], $data)) throw new RuntimeException("Brak pola historii: ".$args[0]);
        $value = $data[$args[0]];
        echo is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? "yes" : "no") : $value);
        exit(0);
    }
    if ($mode === "patch") {
        $data = $json(stream_get_contents(STDIN));
        if (!is_array($data) || count($args) % 2 !== 0) throw new RuntimeException("Niepoprawna historia.");
        for ($i = 0; $i < count($args); $i += 2) {
            if (!preg_match("/^[a-z_]+$/D", $args[$i])) throw new RuntimeException("Niepoprawne pole historii.");
            $data[$args[$i]] = $args[$i + 1];
        }
        $emit($data);
        exit(0);
    }
    if ($mode === "policy") {
        [$from, $to, $uiOnly] = array_slice($args, 0, 3);
        $migrations = [];
        for ($i = 3; $i < count($args); $i += 2) {
            if (!isset($args[$i + 1]) || !preg_match("~^database/migrations/[a-zA-Z0-9_]+\.php$~D", $args[$i]) || !preg_match("/^[a-f0-9]{64}$/D", $args[$i + 1])) throw new RuntimeException("Niepoprawny wykaz migracji.");
            $migrations[$args[$i]] = $args[$i + 1];
        }
        $raw = trim(stream_get_contents(STDIN));
        $rollback = $uiOnly === "yes" && $migrations === [];
        $reason = $rollback ? "Tylko szablony zamowien/layout, dokumentacja lub testy." : "Brak zatwierdzonej zgodnosci cofniecia kodu.";
        if ($raw !== "") {
            $approval = $json($raw);
            $keys = array_keys($approval); sort($keys);
            if ($keys !== ["from", "migrations", "reason", "rollback_code", "to", "version"] || $approval["version"] !== 1 || $approval["from"] !== $from || $approval["to"] !== $to || !is_bool($approval["rollback_code"]) || !is_string($approval["reason"]) || trim($approval["reason"]) === "" || !is_array($approval["migrations"])) throw new RuntimeException("Zatwierdzenie nie pasuje do wersji kodu.");
            $approved = [];
            foreach ($approval["migrations"] as $item) {
                if (!is_array($item) || count($item) !== 2 || !isset($item["path"], $item["sha256"]) || isset($approved[$item["path"]])) throw new RuntimeException("Niepoprawne zatwierdzenie migracji.");
                $approved[$item["path"]] = $item["sha256"];
            }
            ksort($approved); ksort($migrations);
            if ($approved !== $migrations) throw new RuntimeException("Zmienila sie lista lub tresc zatwierdzonych migracji.");
            $rollback = $approval["rollback_code"];
            $reason = $approval["reason"];
        } elseif ($migrations !== []) {
            throw new RuntimeException("Nowe migracje wymagaja zatwierdzenia dla tej pary commitow i sum SHA256.");
        }
        $emit(["rollback_allowed" => $rollback, "reason" => $reason, "migrations" => $migrations]);
        exit(0);
    }
    if ($mode === "verify") {
        [$beforePath, $currentPath, $kind, $migrationJson] = $args;
        $before = $json(file_get_contents($beforePath));
        $current = $json(file_get_contents($currentPath));
        if ($before["fingerprint"] !== $current["fingerprint"] || $before["server_uuid"] !== $current["server_uuid"]) throw new RuntimeException("Zmienila sie konfiguracja lub baza aplikacji.");
        $expected = $before["applied"];
        if ($kind === "update") {
            foreach (array_keys($json($migrationJson)) as $path) $expected[] = pathinfo($path, PATHINFO_FILENAME);
        }
        $expected = array_values(array_unique($expected));
        sort($expected);
        if ($current["applied"] !== $expected || $current["pending"] !== []) throw new RuntimeException("Nieoczekiwany stan historii migracji.");
        exit(0);
    }

    require $appPath."/vendor/autoload.php";
    $app = require $appPath."/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection();
    $settings = KsefSetting::query()->where("singleton_key", KsefSetting::SINGLETON_KEY)->firstOrFail();
    if (!app()->environment("staging") || $db->getDriverName() !== "mysql" || $db->getDatabaseName() !== "nex_oms" || $settings->environment !== KsefEnvironment::Demo || $settings->automatic_submission) throw new RuntimeException("Wymagane staging, MySQL nex_oms, KSeF DEMO, autowysylka OFF.");
    $identity = $db->selectOne("SELECT @@server_uuid AS id, VERSION() AS version");
    if (!str_starts_with($identity->version, "8.0.")) throw new RuntimeException("Profil skryptu wymaga MySQL 8.0.");
    if ($mode === "quiet") {
        if ($db->table("automation_runs")->whereIn("status", ["running", "queued", "waiting"])->exists()) throw new RuntimeException("Pozostaly aktywne lub odroczone automatyzacje.");
        foreach (["database", "ksef_submit"] as $name) {
            $q = config("queue.connections.".$name);
            if (DB::connection($q["connection"])->table($q["table"])->exists()) throw new RuntimeException("Kolejka nie jest pusta: ".$name);
        }
        echo "KOLEJKI: PUSTE\n";
        exit(0);
    }
    if ($mode !== "snapshot") throw new RuntimeException("Nieznany tryb kontroli.");
    $engineCount = $db->table("information_schema.tables")->where("TABLE_SCHEMA", "nex_oms")->where("TABLE_TYPE", "BASE TABLE")->where("ENGINE", "<>", "InnoDB")->count();
    if ($engineCount !== 0) throw new RuntimeException("Backup transakcyjny wymaga samych tabel InnoDB.");
    foreach (["database" => 300, "ksef_submit" => 240] as $name => $retry) {
        $q = config("queue.connections.".$name);
        if ($q["driver"] !== "database" || (int) $q["retry_after"] !== $retry || DB::connection($q["connection"])->getDatabaseName() !== "nex_oms") throw new RuntimeException("Niezgodna konfiguracja kolejki: ".$name);
    }
    $applied = $db->table("migrations")->orderBy("migration")->pluck("migration")->all();
    $files = $app->make("migrator")->getMigrationFiles([$app->databasePath("migrations")]);
    $pending = array_values(array_diff(array_keys($files), $applied)); sort($pending);
    $critical = [config("app.key"), config("app.env"), config("app.url"), config("cache"), config("queue"), config("ksef"), $settings->getRawOriginal()];
    $emit([
        "server_uuid" => $identity->id,
        "fingerprint" => hash("sha256", serialize($critical)),
        "applied" => $applied,
        "pending" => $pending,
        "maintenance" => app()->isDownForMaintenance(),
        "route_cached" => app()->routesAreCached(),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "STOP PHP: ".$e->getMessage().PHP_EOL);
    exit(1);
}
    ' "$APP" "$@"
}

field() { php_tool get "$1"; }

private_write() {
    local target="$1" temporary
    [[ "$target" == "$HISTORY/"* && "$target" != *'/../'* ]] || fail "Niepoprawna sciezka historii."
    temporary="$(sudo mktemp "$HISTORY/.write.XXXXXXXX")"
    sudo tee "$temporary" >/dev/null
    sudo chmod 600 "$temporary"
    sudo sync -f "$temporary"
    sudo mv -T -- "$temporary" "$target"
    sudo sync -f "$HISTORY"
}

record_set() {
    local updated
    updated="$(sudo cat "$RECORD" | php_tool patch "$@")"
    printf '%s\n' "$updated" | private_write "$RECORD"
    printf '%s\n' "$updated" | private_write "$ACTIVE"
}

phase() {
    PHASE="$1"
    say "$PHASE"
    if [[ -n "$RECORD" ]]; then record_set phase "$PHASE"; fi
}

finish() {
    local code=$?
    trap - EXIT
    if [[ -n "$TEMP_DIR" && "$TEMP_DIR" == "$LOCK_DIR/"* ]]; then rm -rf -- "$TEMP_DIR"; fi
    if (( code != 0 )); then
        printf '\nSTOP, etap: %s. Nie ponawiaj calej operacji.\n' "$PHASE" >&2
        if [[ -n "$RECORD" ]]; then
            printf 'Historia: %s\nCron: %s\nKopia: %s\n' "$RECORD" "$PAUSED" "$BACKUP" >&2
            printf 'Uslugi moga pozostawac wstrzymane. Wybierz Status i przeslij wynik.\n' >&2
        else
            printf 'Nie rozpoczeto wstrzymywania aplikacji.\n' >&2
        fi
    fi
    exit "$code"
}

confirm() {
    local expected="$1" answer
    printf '\nWpisz dokladnie: %s\n> ' "$expected"
    read -r answer
    [[ "$answer" == "$expected" ]] || fail "Nie potwierdzono operacji."
}

worker_line_ok() {
    local name="$1" expected="$2" rc="$3" line="$4"
    [[ ($rc == 0 || ($expected == STOPPED && $rc == 3)) && $line =~ ^"$name"[[:space:]]+"$expected"([[:space:]]|$) ]]
}

check_workers() {
    local expected="$1" name line rc
    for name in "${WORKERS[@]}"; do
        rc=0; line="$(sudo supervisorctl status "$name" 2>&1)" || rc=$?
        printf '%s\n' "$line"
        worker_line_ok "$name" "$expected" "$rc" "$line" || fail "Niepoprawny stan workera: $name"
    done
}

curl_escape() {
    local value="$1"
    [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || fail "Niepoprawne dane logowania HTTP."
    value="${value//\\/\\\\}"
    printf '%s' "${value//\"/\\\"}"
}

prepare_http() {
    local user password
    printf 'Login Basic Auth do panelu (nie login SSH): '
    read -r user
    [[ -n "$user" && "$user" != *:* ]] || fail "Niepoprawny login HTTP."
    printf 'Haslo Basic Auth (niewidoczne): '
    read -r -s password
    printf '\n'
    [[ "$user" != *$'\r'* && "$password" != *$'\r'* ]] || fail "Niepoprawne dane HTTP."
    (umask 077; printf 'user = "%s:%s"\n' "$(curl_escape "$user")" "$(curl_escape "$password")" > "$TEMP_DIR/http.conf")
    unset password
}

health() {
    local code
    code="$(curl --config "$TEMP_DIR/http.conf" --noproxy '*' --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 30 --silent --show-error --fail --output "$TEMP_DIR/health.html" --write-out '%{http_code}' "$HEALTH_URL")"
    [[ "$code" == 200 ]] || fail "HTTP /up nie zwrocil 200."
    grep -q 'Application up' "$TEMP_DIR/health.html" || fail "Nieoczekiwana odpowiedz /up."
}

prepare() {
    [[ "$(id -un)" == ubuntu && "$(uname -s)" == Linux ]] || fail "Uruchom na VPS Ubuntu jako ubuntu, nie root."
    cd "$APP"
    [[ "$(pwd -P)" == "$APP" ]] || fail "Inny katalog aplikacji."
    local command name
    for command in git php composer curl gzip flock mysql mysqldump sha256sum sync; do command -v "$command" >/dev/null || fail "Brak polecenia: $command"; done
    mkdir -p "$LOCK_DIR"
    chmod 700 "$LOCK_DIR"
    exec 9>"$LOCK_DIR/deploy.lock"
    flock -n 9 || fail "Inne wdrozenie nadal trwa."
    TEMP_DIR="$(mktemp -d "$LOCK_DIR/run.XXXXXXXX")"
    sudo -v
    sudo install -d -m 700 "$ROOT" "$HISTORY" "$HISTORY/approvals"
    if sudo test -e "$ACTIVE"; then fail "Istnieje niedokonczona operacja. Uzyj Status, nie uruchamiaj ponownie aktualizacji."; fi
    [[ "$(git branch --show-current)" == main ]] || fail "Repozytorium nie jest na main."
    git diff --quiet || fail "Zmiany w sledzonych plikach."
    git diff --cached --quiet || fail "Istnieja zmiany staged."
    sudo test -f "$CRON"
    sudo test ! -L "$CRON"
    for name in cron supervisor php8.3-fpm; do systemctl is-active --quiet "$name"; done
    check_workers RUNNING
    local d u
    for d in storage storage/framework/views storage/app/private bootstrap/cache; do
        for u in ubuntu www-data; do sudo -u "$u" test -w "$d" || fail "Brak zapisu $u do $d"; done
    done
    php_tool snapshot > "$TEMP_DIR/before.json"
    [[ "$(field maintenance < "$TEMP_DIR/before.json")" == no ]] || fail "Aplikacja juz jest w konserwacji."
    [[ "$(field pending < "$TEMP_DIR/before.json")" == '[]' ]] || fail "Istnieja zalegle migracje. Wymagana osobna analiza."
    [[ "$(sudo mysql -NBe 'SELECT @@server_uuid')" == "$(field server_uuid < "$TEMP_DIR/before.json")" ]] || fail "Backup wskazuje inny serwer MySQL niz aplikacja."
    env_hash="$(sha256sum .env | cut -d ' ' -f 1)"
    config_hash=absent
    if [[ -f bootstrap/cache/config.php ]]; then config_hash="$(sha256sum bootstrap/cache/config.php | cut -d ' ' -f 1)"; fi
    prepare_http
    health
}

ui_path() {
    case "$1" in
        resources/views/orders/*.blade.php|resources/views/layouts/app.blade.php|docs/*|tests/*) return 0 ;;
        *) return 1 ;;
    esac
}

protected_path() {
    case "$1" in
        .env*|.git*|AGENTS.md|storage/*|bootstrap/*|config/*|vendor/*|node_modules/*|composer.json|composer.lock|package.json|package-lock.json|vite.config.*|resources/js/*|resources/css/*) return 0 ;;
        *) return 1 ;;
    esac
}

check_diff() {
    local status path mode digest untracked changed reference ui=yes
    CHANGED=(); MIGRATIONS=(); MIGRATION_PAIRS=()
    git diff --no-ext-diff --name-status --no-renames -z "$FROM" "$TO" -- > "$TEMP_DIR/changes"
    while IFS= read -r -d '' status && IFS= read -r -d '' path; do
        [[ "$path" =~ ^[a-zA-Z0-9_./-]+$ && "$path" != *'/../'* && "$path" != ../* ]] || fail "Nietypowa sciezka wymaga recznej analizy."
        protected_path "$path" && fail "Zmiana konfiguracji, zaleznosci lub plikow chronionych: $path"
        [[ "$status" != T ]] || fail "Zmiana typu pliku: $path"
        for reference in "$FROM" "$TO"; do
            mode="$(git ls-tree "$reference" -- "$path")"
            [[ -z "$mode" || "$mode" == '100644 '* || "$mode" == '100755 '* ]] || fail "Dowiazanie lub nietypowy plik: $path"
        done
        if [[ "$path" == database/migrations/* ]]; then
            [[ "$status" == A && "$path" =~ ^database/migrations/[a-zA-Z0-9_]+\.php$ ]] || fail "Modyfikacja/usuniecie starej migracji: $path"
            digest="$(git show "$TO:$path" | sha256sum | cut -d ' ' -f 1)"
            MIGRATIONS+=("$path"); MIGRATION_PAIRS+=("$path" "$digest")
        fi
        ui_path "$path" || ui=no
        CHANGED+=("$path")
    done < "$TEMP_DIR/changes"
    git ls-files --others --exclude-standard -z > "$TEMP_DIR/untracked"
    while IFS= read -r -d '' untracked; do
        for changed in "${CHANGED[@]}"; do
            [[ "$untracked" != "$changed" && "$untracked" != "$changed/"* && "$changed" != "$untracked/"* ]] || fail "Kolizja z niesledzonym plikiem: $untracked"
        done
    done < "$TEMP_DIR/untracked"
    if [[ "${1:-}" == files_only ]]; then return; fi
    approval="$HISTORY/approvals/$FROM-$TO.json"
    if sudo test -f "$approval"; then
        sudo cat "$approval" | php_tool policy "$FROM" "$TO" "$ui" "${MIGRATION_PAIRS[@]}" > "$TEMP_DIR/policy.json"
    else
        php_tool policy "$FROM" "$TO" "$ui" "${MIGRATION_PAIRS[@]}" < /dev/null > "$TEMP_DIR/policy.json"
    fi
}

start_record() {
    local stamp data
    stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
    BACKUP="$ROOT/nex-oms-$stamp.sql.gz"
    PAUSED="$ROOT/scheduler-paused-$stamp"
    RECORD="$HISTORY/$stamp.json"
    data="$(printf '{}\n' | php_tool patch version 1 record_path "$RECORD" action "$ACTION" from "$FROM" to "$TO" status preparing phase "$PHASE" backup "$BACKUP" paused_cron "$PAUSED" env_hash "$env_hash" config_hash "$config_hash" fingerprint "$(field fingerprint < "$TEMP_DIR/before.json")" ledger_before "$(field applied < "$TEMP_DIR/before.json")" policy "$(cat "$TEMP_DIR/policy.json")")"
    printf '%s\n' "$data" | private_write "$RECORD"
    printf '%s\n' "$data" | private_write "$ACTIVE"
}

pause_services() {
    phase "wstrzymanie harmonogramu"
    sudo test ! -e "$PAUSED"
    sudo mv -T -- "$CRON" "$PAUSED"
    echo 'Oczekiwanie 65 sekund na przeladowanie crona i zakonczenie harmonogramu...'
    sleep 65
    if pgrep -af '[a]rtisan schedule:(run|work)'; then fail "Harmonogram nadal pracuje."; fi
    phase "konserwacja i zatrzymanie workerow"
    php artisan down --retry=60 --no-interaction
    sudo supervisorctl stop "${WORKERS[@]}"
    check_workers STOPPED
    php_tool quiet
}

make_backup() {
    phase "kopia MySQL"
    sudo test ! -e "$BACKUP"
    sudo bash -c '
        set -Eeuo pipefail
        umask 077
        mysqldump --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 nex_oms | gzip -1 > "$1.part"
        gzip -t "$1.part"
        test -s "$1.part"
        mv -T -- "$1.part" "$1"
        sync -f "$1"
    ' _ "$BACKUP"
    sudo gzip -t "$BACKUP"
    record_set backup_sha256 "$(sudo sha256sum "$BACKUP" | cut -d ' ' -f 1)" status backed_up
    printf 'Kopia: %s\n' "$BACKUP"
}

verify_files() {
    local path current_config=absent
    [[ "$(sha256sum .env | cut -d ' ' -f 1)" == "$env_hash" ]] || fail "Zmienil sie plik .env."
    if [[ -f bootstrap/cache/config.php ]]; then current_config="$(sha256sum bootstrap/cache/config.php | cut -d ' ' -f 1)"; fi
    [[ "$current_config" == "$config_hash" ]] || fail "Zmienil sie aktywny cache konfiguracji."
    for path in "${CHANGED[@]}" vendor/autoload.php vendor/composer/autoload_real.php; do
        if [[ -f "$path" ]]; then sudo -u www-data test -r "$path" || fail "PHP-FPM nie odczyta: $path"; fi
    done
}

refresh_application() {
    phase "autoload i widoki"
    composer dump-autoload --no-dev --optimize --no-scripts --no-plugins --no-interaction
    if [[ "$(field route_cached < "$TEMP_DIR/before.json")" == yes ]]; then
        php artisan route:cache --no-interaction
    else
        php artisan route:clear --no-interaction
    fi
    php artisan view:cache --no-interaction
    verify_files
    php_tool snapshot > "$TEMP_DIR/after.json"
    php_tool verify "$TEMP_DIR/before.json" "$TEMP_DIR/after.json" "$ACTION" "$(field migrations < "$TEMP_DIR/policy.json")"
    phase "przeladowanie PHP-FPM i kontrola HTTP"
    sudo systemctl reload php8.3-fpm
    systemctl is-active --quiet php8.3-fpm
    health
    record_set ledger_after "$(field applied < "$TEMP_DIR/after.json")" status validated
}

resume_services() {
    # The rollback record must be durable before any background work is resumed.
    phase "zapis punktu przywrocenia"
    if [[ "$ACTION" == update ]]; then sudo cat "$RECORD" | private_write "$LAST"; fi
    phase "uruchamianie workerow"
    sudo supervisorctl start "${WORKERS[@]}"
    sleep 7
    check_workers RUNNING
    phase "udostepnienie aplikacji"
    php artisan up --no-interaction
    health
    sudo test ! -e "$CRON"
    sudo mv -T -- "$PAUSED" "$CRON"
    systemctl is-active --quiet cron
    check_workers RUNNING
    php_tool snapshot > "$TEMP_DIR/live.json"
    php_tool verify "$TEMP_DIR/before.json" "$TEMP_DIR/live.json" "$ACTION" "$(field migrations < "$TEMP_DIR/policy.json")"
    [[ "$(field maintenance < "$TEMP_DIR/live.json")" == no ]] || fail "Tryb konserwacji nadal aktywny."
    verify_files
    record_set status completed phase completed
    if [[ "$ACTION" == update ]]; then sudo cat "$RECORD" | private_write "$LAST"; fi
    if [[ "$ACTION" == rollback ]]; then
        sudo cat "$LAST" | php_tool patch status rolled_back | private_write "$LAST"
    fi
    sudo rm -- "$ACTIVE"
    PHASE=completed
    say completed
    git log -1 --format='%H %s'
    printf '\nPASS. Kopia bazy: %s\nHistoria: %s\n' "$BACKUP" "$RECORD"
}

update_main() {
    ACTION=update
    prepare
    phase "pobranie main"
    FROM="$(git rev-parse HEAD)"
    git fetch origin main
    TO="$(git rev-parse origin/main)"
    [[ "$FROM" != "$TO" ]] || { say "Kod jest juz aktualny. Brak zmian."; return; }
    git merge-base --is-ancestor "$FROM" "$TO" || fail "Wymagany fast-forward."
    check_diff
    php_tool quiet
    printf '\nZ: %s\nDO: %s\n' "$FROM" "$TO"
    git log --format='%h %s' "$FROM..$TO"
    printf 'Mozliwosc cofniecia kodu: %s\n' "$(field rollback_allowed < "$TEMP_DIR/policy.json")"
    printf '%s\n' "$(field reason < "$TEMP_DIR/policy.json")"
    confirm "AKTUALIZUJ $TO"
    start_record
    pause_services
    make_backup
    phase "aktualizacja kodu"
    [[ "$(git rev-parse HEAD)" == "$FROM" ]] || fail "HEAD zmienil sie w trakcie operacji."
    git -c core.hooksPath=/dev/null merge --ff-only "$TO"
    [[ "$(git rev-parse HEAD)" == "$TO" ]] || fail "Nieprawidlowy HEAD."
    phase "zatwierdzone migracje"
    local migration
    for migration in "${MIGRATIONS[@]}"; do php artisan migrate --path="$migration" --force --no-interaction; done
    refresh_application
    resume_services
}

rollback_code() {
    ACTION=rollback
    prepare
    sudo test -f "$LAST" || fail "Brak historii udanej aktualizacji wykonanej tym skryptem."
    sudo cat "$LAST" > "$TEMP_DIR/last.json"
    [[ "$(field status < "$TEMP_DIR/last.json")" == completed ]] || fail "Ostatnia aktualizacja nie ma statusu completed."
    FROM="$(field to < "$TEMP_DIR/last.json")"
    TO="$(field from < "$TEMP_DIR/last.json")"
    [[ "$FROM" =~ ^[a-f0-9]{40}$ && "$TO" =~ ^[a-f0-9]{40}$ ]] || fail "Niepoprawne wersje w historii."
    [[ "$(git rev-parse HEAD)" == "$FROM" ]] || fail "HEAD nie odpowiada ostatniej aktualizacji."
    git cat-file -e "$TO^{commit}"
    policy="$(field policy < "$TEMP_DIR/last.json")"
    [[ "$(printf '%s' "$policy" | field rollback_allowed)" == yes ]] || fail "Cofniecie tej wersji wymaga osobnej analizy zgodnosci."
    [[ "$(field ledger_after < "$TEMP_DIR/last.json")" == "$(field applied < "$TEMP_DIR/before.json")" ]] || fail "Od wdrozenia zmienily sie migracje bazy."
    [[ "$(field fingerprint < "$TEMP_DIR/last.json")" == "$(field fingerprint < "$TEMP_DIR/before.json")" ]] || fail "Od wdrozenia zmienila sie konfiguracja."
    [[ "$(field env_hash < "$TEMP_DIR/last.json")" == "$env_hash" && "$(field config_hash < "$TEMP_DIR/last.json")" == "$config_hash" ]] || fail "Od wdrozenia zmienil sie .env lub cache konfiguracji."
    # Validate file safety in reverse; an approved rollback never runs migrations.
    local old_from="$FROM" old_to="$TO"
    FROM="$old_to"; TO="$old_from"
    check_diff files_only
    FROM="$old_from"; TO="$old_to"
    printf '%s\n' "$policy" > "$TEMP_DIR/policy.json"
    php_tool quiet
    printf '\nCofniecie kodu: %s -> %s\nBaza NIE zostanie przywrocona ani cofnieta.\n' "$FROM" "$TO"
    confirm "COFNIJ KOD $TO"
    start_record
    pause_services
    make_backup
    phase "przywracanie poprzedniego kodu"
    [[ "$(git rev-parse HEAD)" == "$FROM" ]] || fail "HEAD zmienil sie w trakcie operacji."
    git -c core.hooksPath=/dev/null reset --keep "$TO"
    [[ "$(git rev-parse HEAD)" == "$TO" ]] || fail "Nieprawidlowy HEAD po cofnieciu."
    refresh_application
    resume_services
}

status() {
    [[ "$(id -un)" == ubuntu ]] || fail "Uruchom jako ubuntu."
    cd "$APP"
    sudo -v
    say "KOD"
    git log -1 --format='%H %s'
    git status --short
    say "WORKERY"
    sudo supervisorctl status || true
    say "CRON"
    if sudo test -f "$CRON"; then echo 'Plik crona obecny.'; else echo 'UWAGA: brak pliku crona.'; fi
    say "AKTYWNA / OSTATNIA OPERACJA"
    local file
    for file in "$ACTIVE" "$LAST"; do
        if sudo test -f "$file"; then printf '%s\n' "$file"; sudo cat "$file" | php_tool summary record; fi
    done
    say "STAN APLIKACJI"
    php_tool snapshot | php_tool summary snapshot
    echo 'RUNNING nie potwierdza wyniku operacji biznesowych. Nie uruchomiono schedulera ani jobow.'
}

main() {
    trap finish EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM HUP
    case "${1:-}" in
        --help) echo 'bash nex-oms-deploy.sh [--status | --help]'; return ;;
        --status) status; return ;;
        '') ;;
        *) fail "Nieznany argument." ;;
    esac
    [[ -t 0 ]] || fail "Uruchom plik w interaktywnym terminalu PuTTY."
    local option
    printf '\nNEX-OMS STAGING\n1) Aktualizuj z main\n2) Cofnij ostatnia zgodna wersje KODU\n3) Status / przerwane wdrozenie\n0) Wyjdz\n> '
    read -r option
    case "$option" in
        1) update_main ;;
        2) rollback_code ;;
        3) status ;;
        0) echo 'Bez zmian.' ;;
        *) fail "Nieprawidlowy wybor." ;;
    esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
