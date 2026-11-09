const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/orders/index.blade.php'), 'utf8');
const start = page.indexOf('const loadOrdersPage =');
const end = page.indexOf('const submitMutation =', start);
assert.ok(start > 0 && end > start);

function harness({ initial, redirected = false, responseUrl = initial, ok = true }) {
    const events = { requests: [], pushes: [], replacements: [], assignments: [], rendered: 0, errors: [], scrolls: [] };
    const classes = { add: () => {}, remove: () => {} };
    const activePage = { classList: classes, querySelectorAll: () => [], replaceWith: () => events.rendered++ };
    const location = { href: initial, assign: url => events.assignments.push(url) };
    const context = vm.createContext({
        URL, AbortController,
        window: {
            location,
            history: {
                pushState: (_state, _title, url) => { events.pushes.push(url); location.href = url; },
                replaceState: (_state, _title, url) => { events.replacements.push(url); location.href = url; },
            },
        },
        document: { querySelector: () => null, querySelectorAll: () => [], title: 'Orders', body: { classList: classes, style: { removeProperty: () => {} } } },
        DOMParser: class {
            parseFromString() {
                const nextPage = {
                    querySelector: selector => selector === '[data-list-toolbar-slot]'
                        ? { scrollIntoView: options => events.scrolls.push(options.block) } : null,
                };
                return { querySelector: selector => selector === '[data-orders-page]' ? nextPage : null, title: 'Orders' };
            }
        },
        currentOrdersPage: () => activePage,
        replaceOrdersContext: () => {}, initializeOrdersList: () => {},
        showRequestError: error => events.errors.push(error),
        fetch: async url => { events.requests.push(url); return { ok, redirected, url: responseUrl, text: async () => 'HTML' }; },
    });
    vm.runInContext(`let navigationController = null, navigationSequence = 0, listRequestActive = false;
        ${page.slice(start, end)}
        globalThis.load = loadOrdersPage;`, context);
    return { load: context.load, events, location };
}

const original = 'http://localhost/orders?status=new&source=allegro&page=2&per_page=20&list_return=1';
const canonical = original.replace('page=2', 'page=1');

test('AJAX return follows the canonical last-page URL without losing filters', async () => {
    const h = harness({ initial: original, redirected: true, responseUrl: canonical });
    assert.equal(await h.load(original), true);
    assert.deepEqual(h.events.requests, [original]);
    assert.deepEqual(h.events.pushes, [canonical]);
    assert.equal(h.events.rendered, 1);
    assert.deepEqual(h.events.errors, []);
});

test('background refresh replaces stale page URL rather than adding history', async () => {
    const h = harness({ initial: original, redirected: true, responseUrl: canonical });
    assert.equal(await h.load(original, { pushHistory: false }), true);
    assert.deepEqual(h.events.pushes, []);
    assert.deepEqual(h.events.replacements, [canonical]);
    assert.equal(h.location.href, canonical);
});

test('ordinary background refresh keeps current filter URL and history', async () => {
    const h = harness({ initial: original });
    assert.equal(await h.load(original, { pushHistory: false }), true);
    assert.deepEqual(h.events.pushes, []);
    assert.deepEqual(h.events.replacements, []);
    assert.equal(h.location.href, original);
    assert.deepEqual(h.events.scrolls, []);
});

test('pagination from the docked toolbar returns to the top of the new table', async () => {
    const h = harness({ initial: original });
    const next = original.replace('page=2', 'page=3');
    assert.equal(await h.load(next, { scrollToToolbar: true }), true);
    assert.deepEqual(h.events.pushes, [next]);
    assert.deepEqual(h.events.scrolls, ['start']);
});

test('tracking lookup redirect retains the full order navigation context', async () => {
    const orderUrl = 'http://localhost/orders/123?status=all&list%5Bq%5D=TRACK-123';
    const h = harness({ initial: original, redirected: true, responseUrl: orderUrl });
    assert.equal(await h.load(original), true);
    assert.deepEqual(h.events.assignments, [orderUrl]);
    assert.equal(h.events.rendered, 0);
});

test('failed refresh does not overwrite list context', async () => {
    const h = harness({ initial: original, ok: false });
    assert.equal(await h.load(original), false);
    assert.equal(h.location.href, original);
    assert.equal(h.events.errors.length, 1);
    assert.deepEqual(h.events.pushes, []);
});

test('two tabs retain independent URLs during refresh', async () => {
    const first = harness({ initial: original });
    const otherUrl = 'http://localhost/orders?status=pending&source=prestashop';
    const second = harness({ initial: otherUrl });
    await first.load(original, { pushHistory: false });
    await second.load(otherUrl, { pushHistory: false });
    assert.equal(first.location.href, original);
    assert.equal(second.location.href, otherUrl);
});

for (const [height, top, bottom, expected] of [[900, 160, 192, 696], [844, 406, 438, 394], [600, 490, 522, 478]]) {
    test(`sort menu computes available room at ${height}px viewport height`, () => {
        const fitStart = page.indexOf('const fitOrderSortMenu =');
        const fitEnd = page.indexOf("document.addEventListener('show.bs.dropdown'", fitStart);
        assert.ok(fitStart > 0 && fitEnd > fitStart);
        let actual;
        const dropdown = {
            querySelector: () => ({ getBoundingClientRect: () => ({ top, bottom }) }),
            style: { setProperty: (key, value) => { assert.equal(key, '--orders-sort-max-height'); actual = value; } },
        };
        const context = vm.createContext({ window: { innerHeight: height } });
        vm.runInContext(`${page.slice(fitStart, fitEnd)}; globalThis.fit = fitOrderSortMenu;`, context);
        context.fit(dropdown);
        assert.equal(actual, `${expected}px`);
    });
}
