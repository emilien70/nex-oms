const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../public/js/order-filter-preferences.js'), 'utf8');
const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/orders/index.blade.php'), 'utf8');
const filterState = fs.readFileSync(path.resolve(__dirname, '../../app/Support/OrderListFilterState.php'), 'utf8');
const keys = [...filterState.match(/const FILTER_KEYS = \[([\s\S]*?)\];/)[1].matchAll(/'([^']+)'/g)].map((match) => match[1]);
const base = 'https://oms.example/orders';
const link = (suffix, scope) => ({ href: base + suffix, dataset: scope ? { orderFilterTarget: scope } : {} });
const params = (url) => Object.fromEntries(new URL(url).searchParams);

function browser({ scope = 'status:1', suffix = '', storage = new Map(), blocked = false } = {}) {
    const links = [
        link('?status=new', 'status:1'), link('?status=shipped', 'status:2'),
        link('', 'all'), link('?trash=1', 'trash'),
        link('?status=new&product=History&page=3&list_return=1'),
        link('?status=new&payment=unpaid&per_page=20'),
    ];
    const listeners = {};
    const replacements = [];
    const unavailable = () => { throw new Error('Browser storage is unavailable'); };
    const window = {
        location: { href: base + suffix, replace: (url) => replacements.push(url) },
        localStorage: {
            getItem: blocked ? unavailable : (key) => storage.get(key),
            setItem: blocked ? unavailable : (key, value) => storage.set(key, value),
            removeItem: blocked ? unavailable : (key) => storage.delete(key),
        },
        addEventListener: (name, handler) => { listeners[name] = handler; },
    };
    const document = {
        currentScript: { dataset: { orderFilterKeys: JSON.stringify(keys) } },
        querySelectorAll: () => links.filter((anchor) => anchor.dataset.orderFilterTarget),
        querySelector: () => scope ? { dataset: { orderFilterScope: scope } } : null,
    };
    vm.runInNewContext(source, { URL, window, document });
    return { filters: window.nexOmsOrderFilters, links, replacements, listeners, storage };
}

test('all supported criteria persist per list, including zero and special characters', () => {
    const h = browser();
    const complete = Object.fromEntries(keys.map((key) => [key, 'value:'+key]));
    Object.assign(complete, { cash_on_delivery: '0', notes: 'tekst + &', total_from: '0' });
    h.filters.remember('status:1', new URLSearchParams({ ...complete, page: 3, sort: 'id', q: 'search', per_page: 50 }));
    h.filters.remember('status:2', new URLSearchParams({ payment: 'paid' }));
    h.filters.remember('all', new URLSearchParams({ product: 'All' }));
    h.filters.remember('trash', new URLSearchParams({ product: 'Trash' }));

    const reopened = browser({ storage: h.storage });
    assert.deepEqual(params(reopened.links[0].href), { status: 'new', ...complete });
    assert.deepEqual(params(reopened.links[1].href), { status: 'shipped', payment: 'paid' });
    assert.deepEqual(params(reopened.links[2].href), { product: 'All' });
    assert.deepEqual(params(reopened.links[3].href), { trash: '1', product: 'Trash' });
});

test('replacing and clearing preferences never affects another scope or explicit links', () => {
    const h = browser();
    const explicitLinks = h.links.slice(4).map((anchor) => anchor.href);
    h.filters.remember('status:1', new URLSearchParams({ source: 'allegro', product: 'First' }));
    h.filters.remember('status:2', new URLSearchParams({ product: 'Other' }));
    h.filters.remember('status:1', new URLSearchParams({ source: '', product: '  New  ' }));
    assert.deepEqual(params(h.links[0].href), { status: 'new', product: 'New' });
    const otherTab = browser({ storage: h.storage });
    h.filters.remember('status:1', new URLSearchParams());
    otherTab.listeners.storage({ key: 'nexOmsOrderFilters:v1:status:1' });
    assert.deepEqual(params(otherTab.links[0].href), { status: 'new' });
    assert.deepEqual(params(h.links[1].href), { status: 'shipped', product: 'Other' });
    assert.deepEqual(h.links.slice(4).map((anchor) => anchor.href), explicitLinks);
});

test('bare status entry restores preferences but search, saved views, and exact returns keep their URL', () => {
    const h = browser({ suffix: '?status=new' });
    h.filters.remember('status:1', new URLSearchParams({ email: 'buyer@example.test', payment: 'unpaid' }));
    assert.equal(h.filters.restoreEntry(), true);
    assert.deepEqual(params(h.replacements[0]), { status: 'new', email: 'buyer@example.test', payment: 'unpaid' });

    for (const suffix of ['?q=buyer', '?status=new&per_page=20', '?status=new&payment=paid', '?status=new&page=2&list_return=1']) {
        const tab = browser({ suffix, storage: h.storage });
        assert.equal(tab.filters.restoreEntry(), false);
        assert.deepEqual(tab.replacements, []);
    }
});

test('blocked or invalid browser storage does not break navigation', () => {
    const blocked = browser({ blocked: true, suffix: '?status=new' });
    blocked.filters.remember('status:1', new URLSearchParams({ city: 'Krakow' }));
    assert.equal(blocked.filters.restoreEntry(), false);
    const invalid = browser({ storage: new Map([['nexOmsOrderFilters:v1:status:1', '{invalid']]) });
    assert.deepEqual(params(invalid.links[0].href), { status: 'new' });
});

test('filter form saves only after successful list navigation and excludes global search', async () => {
    const start = page.indexOf('const submitGetForm =');
    const end = page.indexOf('const isOrdersIndexUrl =', start);
    assert.ok(start > 0 && end > start);
    const h = browser();
    let resolveLoad;
    const context = vm.createContext({
        URL,
        window: { location: { href: base }, nexOmsOrderFilters: h.filters },
        FormData: class { constructor(form) { return form.params; } },
        loadOrdersPage: () => new Promise((resolve) => { resolveLoad = resolve; }),
    });
    vm.runInContext(page.slice(start, end) + '\n globalThis.submit = submitGetForm;', context);
    const form = {
        action: base,
        params: new URLSearchParams({ status: 'new', source: 'allegro', payment: 'unpaid' }),
        dataset: { orderFilterScope: 'status:1' },
        removeAttribute() {},
    };

    const pending = context.submit(form);
    assert.equal(h.storage.size, 0);
    resolveLoad(true);
    await pending;
    assert.deepEqual(params(h.links[0].href), { status: 'new', source: 'allegro', payment: 'unpaid' });

    form.params.set('source', 'prestashop');
    const failed = context.submit(form);
    resolveLoad(false);
    await failed;
    const search = context.submit({ ...form, dataset: {}, params: new URLSearchParams({ q: 'Test' }) });
    resolveLoad(true);
    await search;
    assert.deepEqual(params(h.links[0].href), { status: 'new', source: 'allegro', payment: 'unpaid' });
});

test('successful saved-filter and clear clicks update only their scope, ordinary navigation does not', async () => {
    const anchorStart = page.indexOf("const anchor = event.target.closest('a[href]');");
    const start = page.lastIndexOf("document.addEventListener('click'", anchorStart);
    const end = page.indexOf("document.addEventListener('input'", anchorStart);
    assert.ok(start > 0 && anchorStart > start && end > anchorStart);
    const h = browser();
    let click;
    let loaded = false;
    const calls = [];
    const context = vm.createContext({
        URL,
        window: { nexOmsOrderFilters: h.filters, location: { href: base + '?status=new&product=Current&page=2' } },
        document: { addEventListener: (_name, handler) => { click = handler; } },
        currentOrdersPage: () => ({ querySelector: () => ({ dataset: { orderFilterScope: 'status:1' } }) }),
        isOrdersIndexUrl: () => true,
        loadOrdersPage: async (url, options) => { calls.push({ url: url.href, ...options }); return loaded; },
    });
    vm.runInContext(page.slice(start, end), context);
    h.filters.remember('status:1', new URLSearchParams({ product: 'First' }));
    h.filters.remember('status:2', new URLSearchParams({ product: 'Second' }));
    const anchor = { href: base + '?status=new&per_page=20', dataset: {}, closest: (selector) => selector === '[data-orders-page], .orders-context-list' };
    const event = { target: { closest: () => anchor }, button: 0, preventDefault() {} };

    anchor.dataset.orderFilterClear = 'status:1';
    click(event);
    await Promise.resolve();
    assert.equal(params(h.links[0].href).product, 'First');

    loaded = true;
    delete anchor.dataset.orderFilterClear;
    click(event);
    await Promise.resolve();
    assert.equal(params(h.links[0].href).product, 'First');

    anchor.href = base + '?status=new&product=H8.2H&payment=paid&per_page=30';
    anchor.dataset.orderFilterApply = 'status:1';
    loaded = false;
    click(event);
    await Promise.resolve();
    assert.equal(params(h.links[0].href).product, 'First');

    loaded = true;
    click(event);
    await Promise.resolve();
    assert.deepEqual(params(h.links[0].href), { status: 'new', payment: 'paid', product: 'H8.2H' });

    anchor.href = base + '?status=new&payment=paid&per_page=30';
    delete anchor.dataset.orderFilterApply;
    anchor.dataset.orderFilterRemove = 'status:1';
    click(event);
    await Promise.resolve();
    assert.deepEqual(params(h.links[0].href), { status: 'new', payment: 'paid' });

    anchor.href = base + '?status=new&per_page=30';
    delete anchor.dataset.orderFilterRemove;
    anchor.dataset.orderFilterClear = 'status:1';
    click(event);
    await Promise.resolve();
    assert.equal(params(h.links[0].href).product, undefined);
    assert.equal(params(h.links[0].href).payment, undefined);
    assert.equal(params(h.links[1].href).product, 'Second');

    delete anchor.dataset.orderFilterClear;
    anchor.dataset.orderFilterTarget = 'status:1';
    anchor.closest = (selector) => ['[data-orders-page], .orders-context-list', '.orders-context-list'].includes(selector);
    click(event);
    await Promise.resolve();
    assert.equal(calls.at(-1).collapseFilters, true);
    assert.deepEqual(params(calls.at(-1).url), { status: 'new', product: 'Current', page: '2' });

    anchor.dataset.orderFilterTarget = 'status:2';
    anchor.href = h.links[1].href;
    click(event);
    await Promise.resolve();
    assert.equal(calls.at(-1).collapseFilters, true);
    assert.deepEqual(params(calls.at(-1).url), { status: 'shipped', product: 'Second' });
});
