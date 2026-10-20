const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');
const from = source.indexOf('const automationHistoryPanel =');
const to = source.indexOf('const ksefAutomaticRefreshMaximumMs', from);
assert.ok(from >= 0 && to > from);

function fixture(fetchOrderState, absent = false) {
    const listeners = {};
    const error = { hidden: true, textContent: '' };
    const body = {
        innerHTML: 'saved-history', attributes: {}, controls: [],
        querySelectorAll() { return this.controls; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    const panel = {
        addEventListener(name, callback) { listeners[name] = callback; },
        querySelector(selector) {
            return {
                '[data-order-automation-history-body]': body,
                '[data-automation-history-error]': error,
            }[selector];
        },
    };
    const context = vm.createContext({
        document: { getElementById: () => absent ? null : panel },
        fetchOrderState: async (...args) => {
            const result = await fetchOrderState(...args);
            return result === undefined ? { fragments: { 'automation-history': 'updated-history' } } : result;
        },
    });
    vm.runInContext(`${source.slice(from, to)}\nglobalThis.load = loadAutomationHistory;`, context);
    return { panel, body, error, listeners, load: context.load };
}

test('opening history requests its fragment without synchronizing edited fields', async () => {
    const calls = [];
    const f = fixture(async (...args) => { calls.push(args); });
    await f.listeners['show.bs.collapse']({ target: f.panel });
    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [[['automation-history'], false, null, null]]);
    assert.equal(f.body.attributes['aria-busy'], undefined);
    assert.equal(f.error.hidden, true);
});

test('nested collapse events do not refresh the history', async () => {
    let calls = 0;
    const f = fixture(async () => { calls += 1; });
    await f.listeners['show.bs.collapse']({ target: {} });
    assert.equal(calls, 0);
});

test('pagination requests the selected page and ignores disabled controls', async () => {
    const pages = [];
    const f = fixture(async (...args) => { pages.push(args[3]); });
    const button = { disabled: false, dataset: { automationHistoryPage: '2' } };
    const event = { target: { closest: () => button } };
    await f.listeners.click(event);
    assert.deepEqual(pages, [2]);
    button.disabled = true;
    await f.listeners.click(event);
    await f.listeners.click({ target: { closest: () => null } });
    assert.deepEqual(pages, [2]);
});

test('concurrent opens or pagination do not send duplicate requests', async () => {
    let done;
    let calls = 0;
    const f = fixture(() => {
        calls += 1;
        return new Promise(resolve => { done = resolve; });
    });
    const pending = f.load();
    assert.equal(f.body.attributes['aria-busy'], 'true');
    await f.load(2);
    assert.equal(calls, 1);
    done();
    await pending;
    assert.equal(f.body.attributes['aria-busy'], undefined);
});

test('a failed request preserves history, reveals an error and allows retry', async () => {
    let calls = 0;
    const f = fixture(async () => {
        calls += 1;
        if (calls === 1) throw new Error('offline');
    });
    await f.load();
    assert.equal(f.body.innerHTML, 'saved-history');
    assert.equal(f.error.hidden, false);
    assert.ok(f.error.textContent);
    assert.equal(f.body.attributes['aria-busy'], undefined);
    await f.load();
    assert.equal(calls, 2);
    assert.equal(f.error.hidden, true);
});

test('a missing history panel does not trigger requests', async () => {
    let calls = 0;
    const f = fixture(async () => { calls += 1; }, true);
    await f.load();
    assert.equal(calls, 0);
    assert.deepEqual(f.listeners, {});
});

test('pagination controls are disabled during refresh and restored on failure', async () => {
    let reject;
    const f = fixture(() => new Promise((resolve, fail) => { reject = fail; }));
    const previous = { disabled: true };
    const next = { disabled: false };
    f.body.controls = [previous, next];
    const pending = f.load();
    assert.equal(previous.disabled, true);
    assert.equal(next.disabled, true);
    reject(new Error('offline'));
    await pending;
    assert.equal(previous.disabled, true);
    assert.equal(next.disabled, false);
});

test('a response without a history fragment displays an error', async () => {
    const f = fixture(async () => ({ fragments: {} }));
    await f.load();
    assert.equal(f.body.innerHTML, 'saved-history');
    assert.equal(f.error.hidden, false);
});
