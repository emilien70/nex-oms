const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const runner = path.join(root, 'scripts/run-main-queues.ps1');

test('Main Queues has four disjoint workers and no KSeF queues', { skip: process.platform !== 'win32' }, () => {
    const workers = JSON.parse(execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', runner, '-Describe'], { encoding: 'utf8' }));
    assert.equal(workers.length, 4);
    assert.deepEqual(workers.map(worker => worker.Queue), ['integrations,automation,default', 'shipments-actions', 'shipments-sync', 'orders-import']);
    assert.equal(workers[1].Sleep, 1);
    assert.equal(workers[2].Sleep, 1);
    assert.equal(workers[3].Sleep, 1);
    const queues = workers.flatMap(worker => worker.Queue.split(','));
    assert.equal(new Set(queues).size, queues.length);
    assert.ok(queues.every(queue => !queue.includes('ksef')));
});

test('composer dev has a separate order import listener', () => {
    const { scripts } = JSON.parse(fs.readFileSync(path.join(root, 'composer.json'), 'utf8'));
    const queues = [...scripts.dev.join(' ').matchAll(/queue:listen --queue=([\w,-]+)/g)].map(match => match[1]);
    assert.deepEqual(queues, ['shipments-actions,shipments-sync,integrations,automation,default', 'orders-import']);
});

test('both local launchers use the same group without changing KSeF commands', t => {
    if (!['nex-control.ps1', 'start-oms.ps1'].every(launcher => fs.existsSync(path.join(root, launcher)))) {
        return t.skip('Local launchers are intentionally ignored by Git.');
    }
    for (const launcher of ['nex-control.ps1', 'start-oms.ps1']) {
        const source = fs.readFileSync(path.join(root, launcher), 'utf8');
        assert.ok(source.includes('scripts\\run-main-queues.ps1'));
        assert.ok(!source.includes('--queue=integrations,automation,default,shipments-actions,shipments-sync'));
        assert.ok(source.includes('queue:work ksef_submit --queue=ksef-submit --sleep=1 --tries=1 --timeout=120'));
        assert.ok(source.includes('queue:work database --queue=ksef --sleep=3 --tries=1 --timeout=60'));
    }
});

test('PowerShell runner parses and only starts hidden owned children', { skip: process.platform !== 'win32' }, () => {
    const command = `$errors = $null; $tokens = $null; [void][System.Management.Automation.Language.Parser]::ParseFile('${runner.replaceAll("'", "''")}', [ref]$tokens, [ref]$errors); if ($errors.Count) { $errors | Out-String | Write-Error; exit 1 }`;
    execFileSync('powershell.exe', ['-NoProfile', '-Command', command], { encoding: 'utf8' });
    const source = fs.readFileSync(runner, 'utf8');
    assert.ok(source.includes('-WindowStyle Hidden -PassThru'));
    assert.ok(source.includes('Local\\NEX_OMS_MainQueues_Group'));
    assert.ok(source.includes('$child.HasExited'));
    assert.ok(source.includes('/PID $child.Id /T /F'));
    assert.ok(!source.includes('queue:restart'));
});

test('worker launch quotes paths and arguments without starting PHP', { skip: process.platform !== 'win32' }, () => {
    const command = `
$errors = $null; $tokens = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile('${runner.replaceAll("'", "''")}', [ref]$tokens, [ref]$errors)
$ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false) | ForEach-Object { . ([scriptblock]::Create($_.Extent.Text)) }
$project = "C:\\test path\\O'Neil"
$php = "C:\\php path\\php.exe"
$logDir = "C:\\logs path\\O'Neil"
function Start-Process {
    param($FilePath, $ArgumentList, $WindowStyle, [switch]$PassThru)
    if ($FilePath -ne 'powershell.exe' -or $WindowStyle -ne 'Hidden' -or -not $PassThru) { throw 'Unexpected launch' }
    $script:launch = $ArgumentList
    return [Diagnostics.Process]::GetCurrentProcess()
}
$child = Start-Worker ([PSCustomObject]@{ Name = 'shipments-actions'; Queue = 'shipments-actions'; Sleep = 1 }) 6>$null
if ($child.Id -ne $PID) { throw 'Wrong return value' }
$decoded = [Text.Encoding]::Unicode.GetString([Convert]::FromBase64String($script:launch[-1]))
[void][System.Management.Automation.Language.Parser]::ParseInput($decoded, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw 'Invalid child command' }
ConvertTo-Json -Compress -InputObject $decoded
`;
    const decoded = JSON.parse(execFileSync('powershell.exe', ['-NoProfile', '-Command', command], { encoding: 'utf8' }).trim());
    assert.ok(decoded.includes("'C:\\test path\\O''Neil'"));
    assert.ok(decoded.includes("'C:\\php path\\php.exe'"));
    assert.ok(decoded.includes("'--queue=shipments-actions' '--sleep=1' '--timeout=60' '--max-time=3600'"));
    assert.ok(!decoded.includes('ksef'));
});

test('shipment browser polling is local, bounded and no longer blames the worker', () => {
    const source = fs.readFileSync(path.join(root, 'resources/views/orders/show.blade.php'), 'utf8');
    const polling = source.slice(source.indexOf('const pollShipment ='), source.indexOf("courierFormHost?.addEventListener('submit'"));
    assert.ok(polling.includes('window.setTimeout(resolve, 1000)'));
    assert.ok(polling.includes('Date.now() - startedAt >= 240000'));
    assert.ok(polling.includes('fetch(statusUrl,'));
    assert.ok(polling.includes('form, startedAt)'));
    assert.ok(!polling.includes('worker'));
});

function pollingContext(responses) {
    const source = fs.readFileSync(path.join(root, 'resources/views/orders/show.blade.php'), 'utf8');
    const polling = source.slice(source.indexOf('const pollShipment ='), source.indexOf("courierFormHost?.addEventListener('submit'"));
    const state = { time: 0, delays: [], requests: [], notices: [], closed: 0, refreshed: 0 };
    const context = vm.createContext({
        Date: { now: () => state.time },
        window: { setTimeout: (callback, delay) => { state.time += delay; state.delays.push(delay); callback(); } },
        fetch: async url => {
            state.requests.push(url);
            const payload = responses.shift();
            if (!payload) throw new Error('Offline');
            return { ok: true, json: async () => payload };
        },
        showShipmentNotice: (...notice) => state.notices.push(notice),
        shipmentCreationSucceeded: payload => payload.status === 'succeeded' && Boolean(payload.tracking_number),
        shipmentErrorMessage: () => 'Read failed',
        replaceShipmentRow: () => {},
        closeShipmentFormAfterSuccess: () => state.closed++,
        fetchOrderState: async () => state.refreshed++,
    });
    vm.runInContext(polling.replace('const pollShipment =', 'globalThis.pollShipment ='), context);
    return { state, poll: context.pollShipment };
}

test('browser observes the new number after one second and stops on success', async () => {
    const { state, poll } = pollingContext([
        { polling_finished: false },
        { polling_finished: true, status: 'succeeded', tracking_number: 'TEST', label_available: true },
    ]);
    assert.equal(await poll('/local-status'), true);
    assert.deepEqual(state.delays, [1000, 1000]);
    assert.deepEqual(state.requests, ['/local-status', '/local-status']);
    assert.equal(state.closed, 1);
    assert.equal(state.refreshed, 1);
    assert.equal(state.notices.at(-1)[1], 'success');
});

test('browser errors do not reset the four minute polling deadline', async () => {
    const { state, poll } = pollingContext([]);
    assert.equal(await poll('/local-status'), false);
    assert.equal(state.time, 240000);
    assert.equal(state.requests.length, 240);
    assert.equal(state.closed, 0);
    assert.equal(state.notices.at(-1)[1], 'warning');
});
