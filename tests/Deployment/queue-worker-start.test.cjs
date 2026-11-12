const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { test } = require('node:test');

const scripts = path.resolve(__dirname, '../../scripts');
const bash = process.env.DEPLOY_TEST_BASH || (process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : '/bin/bash');
const php = process.env.DEPLOY_TEST_PHP || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const probeName = 'check-queue-mysql.php';
const startName = 'start-queue-worker.sh';
const slash = value => value.replaceAll('\\', '/');

function fixture(callback) {
    const temporaryRoot = fs.realpathSync(os.tmpdir());
    const directory = fs.mkdtempSync(path.join(temporaryRoot, 'nex-oms-queue-start-'));
    try {
        for (const name of ['scripts', 'vendor', 'bootstrap']) fs.mkdirSync(path.join(directory, name));
        for (const name of [probeName, startName]) fs.copyFileSync(path.join(scripts, name), path.join(directory, 'scripts', name));
        fs.copyFileSync(path.join(__dirname, 'fixtures/queue-db-probe-app.php'), path.join(directory, 'bootstrap/app.php'));
        fs.writeFileSync(path.join(directory, 'vendor/autoload.php'), '<?php');
        fs.writeFileSync(path.join(directory, 'artisan'), '<?php echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);');
        return callback(slash(directory));
    } finally {
        assert.equal(path.dirname(fs.realpathSync(directory)), temporaryRoot);
        fs.rmSync(directory, { recursive: true, force: true });
    }
}

function runProbe(connection, mysqlError = '', cacheConnection = '') {
    return fixture(root => spawnSync(php, [path.join(root, 'scripts', probeName), connection], {
        encoding: 'utf8',
        timeout: 10000,
        env: { ...process.env, TEST_MYSQL_ERROR: String(mysqlError), TEST_CACHE_DB: cacheConnection },
    }));
}

function runStarter(probeStatuses, connection = 'database', interruptOnWait = false) {
    return fixture(root => {
        const code = [
            'root="$(cd -- "$TEST_ROOT" && pwd -P)"',
            'timeout() {',
            '    [[ "$*" == *"--foreground --kill-after=1s 3s"* && "$*" == *"check-queue-mysql.php $TEST_CONNECTION" ]] || return 79',
            '    printf "%s\\n" probe >> "$TEST_ROOT/probes"',
            '    read -r -a codes <<< "$TEST_PROBE_STATUSES"',
            '    local n; n=$(wc -l < "$TEST_ROOT/probes")',
            '    return "${codes[n-1]:-79}"',
            '}',
            'sleep() {',
            '    printf "%s\\n" "$1" >> "$TEST_ROOT/delays"',
            '    if [[ "$TEST_INTERRUPT" == yes ]]; then kill -TERM "$$"; fi',
            '}',
            'export -f timeout sleep',
            'exec bash "$root/scripts/start-queue-worker.sh" "$TEST_PHP" "$root/artisan" queue:work "$TEST_CONNECTION" --queue=test --timeout=210 --tries=1',
        ].join('\n');
        const result = spawnSync(bash, ['--noprofile', '--norc', '-c', code], {
            encoding: 'utf8',
            timeout: 10000,
            env: {
                ...process.env,
                TEST_ROOT: root,
                TEST_PHP: slash(php),
                TEST_CONNECTION: connection,
                TEST_PROBE_STATUSES: probeStatuses.join(' '),
                TEST_INTERRUPT: interruptOnWait ? 'yes' : 'no',
            },
        });
        const lines = file => fs.existsSync(path.join(root, file)) ? fs.readFileSync(path.join(root, file), 'utf8').trim().split(/\r?\n/) : [];
        return { ...result, probes: lines('probes').length, delays: lines('delays').map(Number) };
    });
}

test('probe checks the queue write connection and database cache without application providers', () => {
    for (const [connection, target] of [['database', 'queue-db'], ['ksef_submit', 'ksef-db']]) {
        const result = runProbe(connection);
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(result.stdout.trim().split(/\r?\n/), [target, 'cache-db']);
    }
    const shared = runProbe('database', '', 'queue-db');
    assert.equal(shared.status, 0, shared.stderr);
    assert.equal(shared.stdout.trim(), 'queue-db');
});

test('probe classifies transient MySQL errors without logging exception messages', () => {
    for (const code of [1040, 1053, 2002, 2003, 2006, 2013]) {
        const result = runProbe('database', code);
        assert.equal(result.status, 75, String(code));
        assert.equal(result.stderr, '');
    }
});

test('probe rejects wrong credentials, missing database and invalid queue without retrying', () => {
    for (const code of [1045, 1049]) {
        const result = runProbe('database', code);
        assert.equal(result.status, 78, String(code));
        assert.match(result.stderr, new RegExp(String(code)));
        assert.doesNotMatch(result.stderr, /secret/);
    }
    const wrongQueue = runProbe('redis');
    assert.equal(wrongQueue.status, 78);
    assert.equal(wrongQueue.stdout, '');
});

test('probe rejects a non-MySQL Laravel cached configuration without touching a database', () => {
    const directory = fs.mkdtempSync(path.join(__dirname, '.queue-probe-'));
    const configPath = path.join(directory, 'isolated-config.php');
    try {
        fs.writeFileSync(configPath, '<?php echo "Isolated Laravel configuration loaded"; return ' + [
            "['app' => ['env' => 'testing'],",
            "'queue' => ['connections' => ['database' => ['driver' => 'database']]],",
            "'cache' => ['default' => 'array'],",
            "'database' => ['default' => 'isolated', 'connections' => ['isolated' => ['driver' => 'sqlite', 'database' => ':memory:']]]];",
        ].join('\n'));
        const result = spawnSync(php, [path.join(scripts, probeName), 'database'], {
            encoding: 'utf8',
            timeout: 10000,
            env: { ...process.env, APP_CONFIG_CACHE: slash(path.relative(path.dirname(scripts), configPath)) },
        });
        assert.equal(result.status, 78, result.stderr);
        assert.match(result.stderr, /\[queue-start\] Blad konfiguracji/);
        assert.equal(result.stdout, 'Isolated Laravel configuration loaded');
    } finally {
        fs.rmSync(configPath, { force: true });
        fs.rmdirSync(directory);
    }
});

test('healthy database launches the original worker without delay or argument changes', () => {
    const result = runStarter([0], 'ksef_submit');
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.probes, 1);
    assert.deepEqual(result.delays, []);
    const lines = result.stdout.trim().split(/\r?\n/);
    assert.match(lines[0], /MySQL dostepny/);
    assert.deepEqual(JSON.parse(lines[1]), ['queue:work', 'ksef_submit', '--queue=test', '--timeout=210', '--tries=1']);
});

test('temporary failures and probe timeouts wait, slow down and launch once after recovery', () => {
    const result = runStarter([75, 124, 75, 137, 75, 0]);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.probes, 6);
    assert.deepEqual(result.delays.map((delay, index) => index < 4 ? delay >= 5 && delay <= 7 : delay >= 15 && delay <= 18), [true, true, true, true, true]);
    assert.equal((result.stderr.match(/Czekam na MySQL/g) || []).length, 1);
    assert.equal((result.stdout.match(/MySQL dostepny/g) || []).length, 1);
});

test('permanent startup failures neither wait nor run Artisan', () => {
    const result = runStarter([78, 0]);
    assert.equal(result.status, 78);
    assert.equal(result.probes, 1);
    assert.deepEqual(result.delays, []);
    assert.equal(result.stdout, '');
});

test('a stop signal while waiting does not start Artisan', () => {
    const result = runStarter([75, 0], 'database', true);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.probes, 1);
    assert.equal(result.stdout, '');
});
