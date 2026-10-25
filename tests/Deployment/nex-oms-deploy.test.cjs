const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');

const script = path.resolve(__dirname, '../../scripts/nex-oms-deploy.sh');
const source = fs.readFileSync(script, 'utf8');
const bash = process.env.DEPLOY_TEST_BASH || (process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : '/bin/bash');
const php = process.env.DEPLOY_TEST_PHP || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const slash = (value) => value.replaceAll('\\', '/');
const oldSha = 'a'.repeat(40);
const newSha = 'b'.repeat(40);
const migration = 'database/migrations/2026_10_26_010000_example.php';
const digest = 'c'.repeat(64);

function shell(code, input = '', extraEnv = {}) {
    return spawnSync(bash, ['--noprofile', '--norc', '-c', `source "$DEPLOY_SCRIPT"\nphp() { "$DEPLOY_PHP" "$@"; }\n${code}`], {
        input,
        encoding: 'utf8',
        timeout: 15000,
        env: { ...process.env, DEPLOY_SCRIPT: slash(script), DEPLOY_PHP: php, ...extraEnv },
    });
}

function fixture(callback) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'nex-oms-deploy-test-'));
    try {
        return callback(slash(directory));
    } finally {
        fs.rmSync(directory, { recursive: true, force: true });
    }
}

function policy(ui = 'yes', pairs = '', approval = '') {
    return shell(`php_tool policy ${oldSha} ${newSha} ${ui} ${pairs}`, approval);
}

test('Bash syntax and embedded PHP syntax are valid without running the application', () => {
    const syntax = spawnSync(bash, ['--noprofile', '--norc', '-n', slash(script)], { encoding: 'utf8' });
    assert.equal(syntax.status, 0, syntax.stderr);
    const embedded = source.match(/    php -r '([\s\S]*?)\n    ' "\$APP"/)[1];
    const lint = spawnSync(php, ['-l'], { input: `<?php\n${embedded}`, encoding: 'utf8' });
    assert.equal(lint.status, 0, lint.stderr);
    assert(!source.includes('\r'));
});

test('private umask is scoped and dangerous deployment shortcuts are absent', () => {
    assert.equal(shell('umask').stdout.trim(), '0022');
    for (const forbidden of ['tinker --execute', 'reset --hard', 'migrate:rollback', 'migrate:fresh', 'cache:clear', 'queue:retry', 'schedule:run', 'npm ', 'config:cache']) {
        assert(!source.includes(forbidden), forbidden);
    }
});

test('metadata failures really return nonzero instead of a successful Tinker exit', () => {
    assert.notEqual(shell('php_tool get missing', '{}').status, 0);
    assert.notEqual(shell('php_tool patch status ready', 'not JSON').status, 0);
});

test('metadata handles quoted strings as data rather than shell code', () => {
    const value = '"; $(exit 87)\nstatus=evil';
    const result = shell('php_tool patch reason "$TEST_VALUE"', '{}', { TEST_VALUE: value });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(JSON.parse(result.stdout).reason, value);
});

test('operator status stays compact and excludes internal record fields', () => {
    const result = shell('php_tool summary record', JSON.stringify({ action: 'update', status: 'completed', backup: '/private/backup', fingerprint: 'internal', ledger_before: 'long list' }));
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(result.stdout), { action: 'update', status: 'completed', backup: '/private/backup' });
});

test('application guards reject wrong environments, database, queues and nontransactional tables on stand-ins', () => fixture((directory) => {
    fs.mkdirSync(`${directory}/vendor`);
    fs.mkdirSync(`${directory}/bootstrap`);
    const runtime = slash(path.resolve(__dirname, 'fixtures/runtime.php')).replaceAll("'", "\\'");
    fs.writeFileSync(`${directory}/vendor/autoload.php`, `<?php require '${runtime}';`);
    fs.writeFileSync(`${directory}/bootstrap/app.php`, '<?php return new DeployTestApp;');
    const check = (profile, mode = 'snapshot') => shell('APP="$TEST_DIR"; php_tool "$TEST_MODE"', '', { TEST_DIR: directory, TEST_MODE: mode, DEPLOY_PROFILE: JSON.stringify(profile) });
    assert.equal(check({}).status, 0);
    for (const profile of [
        { environment: 'production' }, { driver: 'sqlite' }, { database: 'other' },
        { ksef_environment: 'production' }, { automatic_submission: true },
        { mysql_version: '10.11-MariaDB' }, { non_innodb_tables: 1 },
        { queue_driver: 'sync' }, { retry_after: 90 },
    ]) assert.notEqual(check(profile).status, 0, JSON.stringify(profile));
    assert.equal(check({}, 'quiet').status, 0);
    assert.notEqual(check({ jobs: true }, 'quiet').status, 0);
    assert.notEqual(check({ active_automations: true }, 'quiet').status, 0);
}));

test('curl configuration escaping preserves special characters without putting passwords in command arguments', () => {
    const value = 'a"b\\c:secret';
    const result = shell('curl_escape "$TEST_VALUE"', '', { TEST_VALUE: value });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, 'a\\"b\\\\c:secret');
    assert.notEqual(shell('curl_escape "$TEST_VALUE"', '', { TEST_VALUE: 'bad\rvalue' }).status, 0);
    assert(source.includes('curl --config "$TEMP_DIR/http.conf"'));
});

test('presentation-only releases get rollback; business changes do not', () => {
    assert.equal(JSON.parse(policy().stdout).rollback_allowed, true);
    assert.equal(JSON.parse(policy('no').stdout).rollback_allowed, false);
});

test('new migrations cannot run without an explicit approval', () => {
    const result = policy('no', `${migration} ${digest}`);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /zatwierdzenia/);
});

test('approval pins the commit pair, exact migration contents and rollback decision', () => {
    const approval = { version: 1, from: oldSha, to: newSha, rollback_code: false, reason: 'Reviewed separately', migrations: [{ path: migration, sha256: digest }] };
    const result = policy('no', `${migration} ${digest}`, JSON.stringify(approval));
    assert.equal(result.status, 0, result.stderr);
    assert.equal(JSON.parse(result.stdout).rollback_allowed, false);
    for (const altered of [
        { ...approval, to: 'd'.repeat(40) },
        { ...approval, rollback_code: 'true' },
        { ...approval, migrations: [{ path: migration, sha256: 'e'.repeat(64) }] },
        { ...approval, extra: true },
    ]) {
        assert.notEqual(policy('no', `${migration} ${digest}`, JSON.stringify(altered)).status, 0);
    }
});

test('Supervisor STOPPED exit 3 is accepted but wrong names, states and errors are rejected', () => {
    assert.equal(shell('worker_line_ok nex-oms-default STOPPED 3 "nex-oms-default STOPPED Oct 25"').status, 0);
    assert.equal(shell('worker_line_ok nex-oms-default RUNNING 0 "nex-oms-default RUNNING pid 10"').status, 0);
    for (const command of [
        'worker_line_ok nex-oms-default STOPPED 7 "nex-oms-default STOPPED"',
        'worker_line_ok nex-oms-default RUNNING 3 "nex-oms-default RUNNING"',
        'worker_line_ok nex-oms-default RUNNING 0 "nex-oms-other RUNNING"',
        'worker_line_ok nex-oms-default STOPPED 3 "nex-oms-default STOPPED_BOGUS"',
    ]) assert.notEqual(shell(command).status, 0);
});

test('protected paths and presentation classification remain conservative', () => {
    for (const name of ['.env', '.env.backup', 'composer.lock', 'storage/app/private/a.pdf', 'config/queue.php', 'bootstrap/app.php', 'resources/js/app.js']) {
        assert.equal(shell('protected_path "$TEST_PATH"', '', { TEST_PATH: name }).status, 0, name);
    }
    assert.equal(shell('ui_path resources/views/orders/partials/a.blade.php').status, 0);
    assert.notEqual(shell('ui_path Modules/Automation/Services/AutomationRunner.php').status, 0);
});

test('snapshot verification rejects configuration drift, pending or unexpected migrations', () => fixture((directory) => {
    const before = { fingerprint: 'same', server_uuid: 'same', applied: ['old'], pending: [] };
    fs.writeFileSync(`${directory}/before.json`, JSON.stringify(before));
    const verify = (current, kind = 'update', migrations = {}) => {
        fs.writeFileSync(`${directory}/current.json`, JSON.stringify(current));
        return shell('php_tool verify "$TEST_DIR/before.json" "$TEST_DIR/current.json" "$KIND" "$TEST_MIGRATIONS"', '', { TEST_DIR: directory, KIND: kind, TEST_MIGRATIONS: JSON.stringify(migrations) });
    };
    assert.equal(verify(before).status, 0);
    assert.notEqual(verify({ ...before, fingerprint: 'changed' }).status, 0);
    assert.notEqual(verify({ ...before, server_uuid: 'other' }).status, 0);
    assert.notEqual(verify({ ...before, pending: ['missing'] }).status, 0);
    assert.notEqual(verify({ ...before, applied: ['old', 'unexpected'] }).status, 0);
    const name = path.basename(migration, '.php');
    assert.equal(verify({ ...before, applied: [name, 'old'].sort() }, 'update', { [migration]: digest }).status, 0);
    assert.equal(verify(before, 'rollback', { [migration]: digest }).status, 0);
    const retainedSchema = { ...before, applied: [name, 'old'].sort() };
    fs.writeFileSync(`${directory}/before.json`, JSON.stringify(retainedSchema));
    assert.equal(verify(retainedSchema, 'update', { [migration]: digest }).status, 0);
}));

test('operation journals precede service changes and backups precede code changes', () => {
    for (const name of ['update_main', 'rollback_code']) {
        const body = source.match(new RegExp(`${name}\\(\\) \\{([\\s\\S]*?)\\n\\}`))[1];
        assert(body.indexOf('start_record') < body.indexOf('pause_services'));
        assert(body.indexOf('pause_services') < body.indexOf('make_backup'));
        const mutation = name === 'update_main' ? 'merge --ff-only' : 'reset --keep';
        assert(body.indexOf('make_backup') < body.indexOf(mutation));
    }
    const prepare = source.match(/prepare\(\) \{([\s\S]*?)\n\}/)[1];
    assert(prepare.includes('sudo test -e "$ACTIVE"'));
    assert(source.includes('sudo mv -T -- "$temporary" "$target"'));
});

function mockDiff(directory, status, changed, untracked = '', fileMode = '100644') {
    return shell(`
TEMP_DIR="$TEST_DIR"; FROM=${oldSha}; TO=${newSha}
git() {
    case "$1" in
        diff) printf '%s\\0%s\\0' "$TEST_STATUS" "$TEST_PATH" ;;
        ls-tree) printf '%s blob fake\\t%s\\n' "$TEST_MODE" "$TEST_PATH" ;;
        ls-files) if [[ -n "$TEST_UNTRACKED" ]]; then printf '%s\\0' "$TEST_UNTRACKED"; fi ;;
        show) printf 'migration content' ;;
        *) return 99 ;;
    esac
}
sudo() { return 1; }
check_diff
`, '', { TEST_DIR: directory, TEST_STATUS: status, TEST_PATH: changed, TEST_UNTRACKED: untracked, TEST_MODE: fileMode });
}

test('file planning preserves unrelated untracked files and rejects collisions or symlinks', () => fixture((directory) => {
    assert.equal(mockDiff(directory, 'M', 'resources/views/orders/index.blade.php', '.env.backup').status, 0);
    assert.notEqual(mockDiff(directory, 'A', 'resources/views/orders/new.blade.php', 'resources/views/orders/new.blade.php').status, 0);
    assert.notEqual(mockDiff(directory, 'A', 'resources/views/orders/new.blade.php', '', '120000').status, 0);
    assert.notEqual(mockDiff(directory, 'M', migration).status, 0);
    assert.notEqual(mockDiff(directory, 'M', 'composer.lock').status, 0);
}));

function resumeFixture(directory, failStart) {
    fs.mkdirSync(`${directory}/history`);
    fs.writeFileSync(`${directory}/history/operation.json`, JSON.stringify({ status: 'validated', action: 'update' }));
    fs.writeFileSync(`${directory}/snapshot.json`, JSON.stringify({ fingerprint: 'same', server_uuid: 'same', applied: [], pending: [], route_cached: false, maintenance: false }));
    fs.writeFileSync(`${directory}/policy.json`, JSON.stringify({ migrations: [] }));
    fs.writeFileSync(`${directory}/paused`, 'cron');
    fs.writeFileSync(`${directory}/env`, 'unchanged');
    return shell(`
HISTORY="$TEST_DIR/history"; ACTIVE="$HISTORY/active.json"; LAST="$HISTORY/last-update.json"
RECORD="$HISTORY/operation.json"; TEMP_DIR="$TEST_DIR"; PAUSED="$TEST_DIR/paused"; CRON="$TEST_DIR/cron"
ACTION=update; BACKUP="$TEST_DIR/backup.sql.gz"
cp "$TEST_DIR/snapshot.json" "$TEST_DIR/before.json"
cp "$RECORD" "$ACTIVE"
eval "$(declare -f php_tool | sed '1s/php_tool/original_php_tool/')"
php_tool() { if [[ "$1" == snapshot ]]; then cat "$TEST_DIR/snapshot.json"; else original_php_tool "$@"; fi; }
sudo() {
    if [[ "$1" == sync ]]; then return 0; fi
    if [[ "$1" == supervisorctl ]]; then
        echo start >> "$TEST_DIR/events"
        [[ -f "$LAST" ]] || return 91
        if [[ "$FAIL_START" == yes ]]; then return 92; fi
        return 0
    fi
    command "$@"
}
php() { if [[ "$1" == artisan ]]; then echo up >> "$TEST_DIR/events"; else "$DEPLOY_PHP" "$@"; fi; }
sleep() { :; }
check_workers() { :; }
health() { :; }
verify_files() { :; }
systemctl() { :; }
git() { echo fake-commit; }
resume_services
`, '', { TEST_DIR: directory, FAIL_START: failStart ? 'yes' : 'no' });
}

test('rollback metadata is durable before workers start; a start failure keeps the active journal', () => fixture((directory) => {
    const result = resumeFixture(directory, true);
    assert.notEqual(result.status, 0);
    assert(fs.existsSync(`${directory}/history/last-update.json`));
    assert(fs.existsSync(`${directory}/history/active.json`));
    assert(fs.existsSync(`${directory}/paused`));
    assert(!fs.existsSync(`${directory}/cron`));
    assert.equal(fs.readFileSync(`${directory}/events`, 'utf8').trim(), 'start');
}));

test('successful resume restores cron, commits history and does not recreate active.json', () => fixture((directory) => {
    const result = resumeFixture(directory, false);
    assert.equal(result.status, 0, result.stderr);
    assert(!fs.existsSync(`${directory}/history/active.json`));
    assert(!fs.existsSync(`${directory}/paused`));
    assert(fs.existsSync(`${directory}/cron`));
    assert.equal(JSON.parse(fs.readFileSync(`${directory}/history/last-update.json`, 'utf8')).status, 'completed');
}));
