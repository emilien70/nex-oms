const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');
const from = source.indexOf('const refreshAllegroInformation = async (panel) => {');
const to = source.indexOf('const catalogProductModal', from);
assert.ok(from >= 0 && to > from);

function fixture(fetch, { loaded = '0', always = false } = {}) {
    const listeners = {};
    const error = { textContent: '', hidden: true };
    const body = {
        innerHTML: 'saved-details', attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    const panel = {
        dataset: { refreshUrl: '/orders/1/allegro-information', informationLoaded: loaded, refreshOnOpen: always ? '1' : '0' },
        addEventListener(name, handler) { listeners[name] = handler; },
        querySelector(selector) {
            return {
                '[data-allegro-information-error]': error,
                '[data-allegro-information-body]': body,
            }[selector];
        },
    };
    const document = {
        querySelectorAll(selector) {
            assert.equal(selector, '[data-allegro-information-panel]');
            return [panel];
        },
    };
    const context = vm.createContext({ panel, document, pageCsrfToken: 'csrf', fetch });
    vm.runInContext(`${source.slice(from, to)}\nglobalThis.refresh = () => refreshAllegroInformation(panel);`, context);
    return { refresh: context.refresh, open: () => listeners['show.bs.collapse']({ target: panel }), listeners, panel, body, error };
}

test('refresh replaces only details after a same-origin request with CSRF protection', async () => {
    const requests = [];
    const f = fixture(async (url, options) => {
        requests.push({ url, options });
        return { ok: true, json: async () => ({ html: 'updated-details' }) };
    });
    await f.refresh();
    assert.equal(requests[0].url, '/orders/1/allegro-information');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(f.body.innerHTML, 'updated-details');
    assert.equal(f.panel.dataset.informationLoaded, '1');
    assert.equal(f.error.hidden, true);
    assert.equal(f.body.attributes['aria-busy'], undefined);
});

test('an API error keeps the previously displayed details and permits retry', async () => {
    const f = fixture(async () => ({ ok: false, json: async () => ({ message: 'Brak dostepu' }) }));
    await f.refresh();
    assert.equal(f.body.innerHTML, 'saved-details');
    assert.equal(f.error.textContent, 'Brak dostepu');
    assert.equal(f.panel.dataset.informationLoaded, '0');
    assert.equal(f.panel.dataset.loading, undefined);
    assert.equal(f.error.hidden, false);
});

test('a malformed response or connection failure never removes stored details', async () => {
    for (const fetch of [
        async () => ({ ok: true, json: async () => ({}) }),
        async () => { throw new Error('Polaczenie przerwane'); },
    ]) {
        const f = fixture(fetch);
        await f.refresh();
        assert.equal(f.body.innerHTML, 'saved-details');
        assert.ok(f.error.textContent);
        assert.equal(f.error.hidden, false);
    }
});

test('repeated openings during a fetch do not issue concurrent requests', async () => {
    let resolve;
    let calls = 0;
    const f = fixture(() => {
        calls += 1;
        return new Promise(done => { resolve = done; });
    });
    const pending = f.open();
    await f.open();
    assert.equal(calls, 1);
    assert.equal(f.body.attributes['aria-busy'], 'true');
    resolve({ ok: true, json: async () => ({ html: 'updated-details' }) });
    await pending;
    assert.equal(f.body.attributes['aria-busy'], undefined);
});

test('fees refresh on every opening even when a saved snapshot exists', async () => {
    let calls = 0;
    const f = fixture(async () => {
        calls += 1;
        return { ok: true, json: async () => ({ html: `fees-${calls}` }) };
    }, { loaded: '1', always: true });
    f.panel.dataset.refreshUrl = '/orders/1/allegro-fees';
    await f.open();
    await f.open();
    assert.equal(calls, 2);
    assert.equal(f.body.innerHTML, 'fees-2');
});

test('stored order details do not fetch on opening or reopening', async () => {
    let calls = 0;
    const f = fixture(async () => { calls += 1; }, { loaded: '1' });
    await f.open();
    await f.open();
    assert.equal(calls, 0);
    assert.equal(f.body.innerHTML, 'saved-details');
});

test('older order details fetch only until their first successful load', async () => {
    let calls = 0;
    const f = fixture(async () => {
        calls += 1;
        return { ok: true, json: async () => ({ html: 'downloaded-details' }) };
    });
    await f.open();
    await f.open();
    assert.equal(calls, 1);
    assert.equal(f.body.innerHTML, 'downloaded-details');
});

test('a failed fees refresh preserves amounts and retries on the next opening', async () => {
    let calls = 0;
    const f = fixture(async () => {
        calls += 1;
        return calls === 1
            ? { ok: false, json: async () => ({ message: 'Allegro niedostepne' }) }
            : { ok: true, json: async () => ({ html: 'new-fees' }) };
    }, { loaded: '1', always: true });
    await f.open();
    assert.equal(f.body.innerHTML, 'saved-details');
    assert.equal(f.error.hidden, false);
    await f.open();
    assert.equal(calls, 2);
    assert.equal(f.body.innerHTML, 'new-fees');
    assert.equal(f.error.hidden, true);
    assert.equal(f.error.textContent, '');
});

test('nested collapse events do not refresh the enclosing information panel', async () => {
    let calls = 0;
    const f = fixture(async () => { calls += 1; }, { always: true });
    await f.listeners['show.bs.collapse']({ target: {} });
    assert.equal(calls, 0);
});
