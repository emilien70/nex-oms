const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/layouts/app.blade.php'), 'utf8');
const source = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1])
    .find(script => script.includes("const dismissedStorageKey = 'nexOmsDismissedAutomationActivities'"));
assert.ok(source);

function element() {
    return {
        children: [], listeners: {}, attributes: {}, hidden: false,
        appendChild(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
    };
}

function activity(overrides = {}) {
    return {
        id: 13, order_id: 1, status: 'failed', tone: 'error',
        title: 'Automation', message: 'Failed', order_label: 'Order 1',
        order_url: '/orders/1', updated_at: '2026-11-04T10:00:00Z',
        is_terminal: true, can_dismiss: true, steps: [],
        ...overrides,
    };
}

function fixture(initial = [], storage = new Map(), storageFails = false) {
    const listeners = {};
    const timers = new Map();
    const requests = [];
    const center = element();
    center.dataset = { endpoint: '/automation/activity' };
    let incoming = initial;
    let now = 0;
    let nextTimer = 0;
    const document = {
        hidden: false,
        querySelector: selector => selector === '[data-automation-activity-center]' ? center : null,
        createElement: element,
        addEventListener(name, callback) { listeners[name] = callback; },
        dispatchEvent() {},
    };
    const window = {
        setTimeout(callback, delay) {
            const id = ++nextTimer;
            timers.set(id, { callback, due: now + delay, delay });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
        addEventListener() {},
    };
    class ClockDate extends Date {
        constructor(...args) { super(...(args.length ? args : [now])); }
        static now() { return now; }
    }
    vm.runInNewContext(source, {
        document, window, Date: ClockDate,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        sessionStorage: {
            getItem(key) { if (storageFails) throw new Error('Storage unavailable'); return storage.get(key) ?? null; },
            setItem(key, value) { if (storageFails) throw new Error('Storage unavailable'); storage.set(key, value); },
        },
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: true, json: async () => ({ activities: incoming }) };
        },
    });
    listeners.DOMContentLoaded();

    const advance = async milliseconds => {
        const end = now + milliseconds;
        while (true) {
            const next = [...timers.entries()]
                .filter(([, timer]) => timer.due <= end)
                .sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
            if (!next) break;
            now = next[1].due;
            timers.delete(next[0]);
            await next[1].callback();
        }
        now = end;
    };
    const findClose = node => node.className === 'automation-activity-close'
        ? node : node.children.map(findClose).find(Boolean);

    return {
        center, timers, requests, storage, advance,
        show: detail => window.nexOmsShowActivity(detail),
        close: (index = 0) => findClose(center.children[index]).listeners.click(),
        async refresh(next = incoming) {
            incoming = next;
            listeners['nexoms:automation-wake']();
            await advance(0);
        },
    };
}

test('error stays visible for 20 seconds despite background refreshes and does not reappear', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(19999);
    assert.equal(f.center.children.length, 1);
    assert.ok(f.requests.length > 2);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
    assert.equal(f.center.hidden, true);
    await f.refresh();
    assert.equal(f.center.children.length, 0);
    assert.ok(f.requests.every(request => request.url === '/automation/activity' && !request.options.method));
});

test('success still closes after seven seconds', async () => {
    const f = fixture([activity({ status: 'completed', tone: 'success' })]);
    await f.refresh();
    await f.advance(6999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
});

for (const status of ['completed_with_errors', 'interrupted']) {
    test(`${status} errors close after 20 seconds`, async () => {
        const f = fixture([activity({ status })]);
        await f.refresh();
        await f.advance(19999);
        assert.equal(f.center.children.length, 1);
        await f.advance(1);
        assert.equal(f.center.children.length, 0);
    });
}

test('queued, running and waiting activities do not auto-close', async () => {
    const f = fixture(['queued', 'running', 'waiting'].map((status, index) => activity({
        id: index + 1, status, tone: status === 'waiting' ? 'waiting' : 'progress',
        is_terminal: false, can_dismiss: status === 'waiting',
    })));
    await f.refresh();
    await f.advance(60000);
    assert.equal(f.center.children.length, 3);
});

test('an unfinished or non-dismissible error does not auto-close', async () => {
    const f = fixture([
        activity({ id: 1, is_terminal: false }),
        activity({ id: 2, can_dismiss: false }),
    ]);
    await f.refresh();
    await f.advance(20000);
    assert.equal(f.center.children.length, 2);
});

test('manual closing cancels its timer and persists dismissal across page reloads', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(1000);
    assert.equal([...f.timers.values()].filter(timer => timer.delay === 20000).length, 1);
    f.close();
    assert.equal(f.center.children.length, 0);
    assert.equal([...f.timers.values()].filter(timer => timer.delay === 20000).length, 0);
    await f.refresh();
    assert.equal(f.center.children.length, 0);
    const reloaded = fixture([activity()], f.storage);
    await reloaded.refresh();
    assert.equal(reloaded.center.children.length, 0);
});

test('terminal errors missing from a later feed still close after 20 seconds', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(1000);
    await f.refresh([]);
    await f.advance(18999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
});

test('a new error version receives its own full display time', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(5000);
    await f.refresh([activity({ updated_at: '2026-11-04T10:00:05Z' })]);
    await f.advance(15000);
    assert.equal(f.center.children.length, 1);
    await f.advance(4999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
});

test('local error notifications also close after 20 seconds without browser storage', async () => {
    const f = fixture([], new Map(), true);
    f.show({ tone: 'error', message: 'Failed' });
    await f.advance(19999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
    await f.refresh();
    assert.equal(f.center.children.length, 0);
});

test('the timer starts when a notification first becomes visible', async () => {
    const f = fixture(Array.from({ length: 7 }, (_, index) => activity({ id: index + 1 })));
    await f.refresh();
    assert.equal(f.center.children.length, 6);
    await f.advance(20000);
    assert.equal(f.center.children.length, 1);
    await f.advance(19999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.children.length, 0);
});
