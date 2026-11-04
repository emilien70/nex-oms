const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');
const block = (start, end) => {
    const from = source.indexOf(start);
    const to = source.indexOf(end, from);
    assert.ok(from >= 0 && to > from);
    return source.slice(from, to);
};

function fixture(responses) {
    const host = { innerHTML: '', querySelector: () => null };
    const requests = [];
    const context = vm.createContext({
        document: { querySelector: selector => selector === '[data-order-returns]' ? host : null },
        window: { location: { origin: 'http://localhost' } }, URL,
        orderStateUrl: '/orders/1/state', currentServerStatus: null,
        currentLatestEventId: null, currentShipmentsSignature: null, currentBuyerCommentsSignature: null,
        currentReturnsSignature: null, currentVonHalskyStatusSignature: null,
        managementStatusInput: null, managementStatusDot: null, managementStatusLabel: null,
        managementStatusDateValue: null, managementStatusTimeValue: null,
        applyStarState() {},
        syncEditableFields() { throw new Error('A background return refresh must not change edited fields.'); },
        fetch: async (url, options) => {
            requests.push({ url: new URL(url), options });
            const payload = responses.shift();
            return { ok: !payload.failed, json: async () => payload };
        },
    });
    vm.runInContext([
        block('const fragmentTargets =', 'const syncEditableFields ='),
        block('const applyOrderFragments =', 'const refreshOrderState ='),
        'globalThis.fetchState = fetchOrderState;',
    ].join('\n'), context);
    return { host, requests, context, fetchState: (...args) => context.fetchState(...args) };
}

test('return creation, status refresh and removal replace only the persistent card target', async () => {
    const f = fixture([
        { status: 'new', returns_signature: 'created', fragments: { returns: '<section>Return 1</section>' } },
        { status: 'new', returns_signature: 'received', fragments: { returns: '<section>Received</section>' } },
        { status: 'new', returns_signature: 'empty', fragments: { returns: '' } },
    ]);
    await f.fetchState();
    assert.equal(f.host.innerHTML, '<section>Return 1</section>');
    assert.equal(f.requests[0].url.searchParams.has('returns_signature'), false);
    await f.fetchState();
    assert.equal(f.host.innerHTML, '<section>Received</section>');
    assert.equal(f.requests[1].url.searchParams.get('returns_signature'), 'created');
    await f.fetchState();
    assert.equal(f.host.innerHTML, '');
    assert.equal(f.requests[2].url.searchParams.get('returns_signature'), 'received');
    assert.ok(f.requests.every(request => request.options.method === undefined && request.options.cache === 'no-store'));
});

test('an unchanged response leaves the card in place and reuses its signature', async () => {
    const f = fixture([
        { status: 'new', returns_signature: 'same', fragments: { returns: '<section>Saved card</section>' } },
        { status: 'new', returns_signature: 'same', fragments: {} },
    ]);
    await f.fetchState();
    await f.fetchState();
    assert.equal(f.host.innerHTML, '<section>Saved card</section>');
    assert.equal(f.requests[1].url.searchParams.get('returns_signature'), 'same');
});

test('a failed state request keeps the last rendered return and signature', async () => {
    const f = fixture([
        { status: 'new', returns_signature: 'last-good', fragments: { returns: '<section>Saved card</section>' } },
        { failed: true },
    ]);
    await f.fetchState();
    await assert.rejects(() => f.fetchState());
    assert.equal(f.host.innerHTML, '<section>Saved card</section>');
    assert.equal(f.context.currentReturnsSignature, 'last-good');
});

test('an explicit return refresh preserves other requested fragments without adding polling', async () => {
    const f = fixture([{ status: 'new', returns_signature: 'same', fragments: { returns: '<section>Return</section>' } }]);
    await f.fetchState(['returns', 'history', 'returns']);
    assert.equal(f.requests[0].url.searchParams.get('fragments'), 'returns,history');
    assert.equal(f.requests.length, 1);
    const refreshCode = block('const fetchOrderState =', 'const refreshOrderState =');
    assert.equal(refreshCode.includes('setInterval'), false);
    assert.equal(refreshCode.includes('setTimeout'), false);
});
