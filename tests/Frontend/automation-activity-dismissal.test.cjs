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
        children: [], listeners: {}, attributes: {}, dataset: {}, hidden: false,
        appendChild(child) { this.insertBefore(child, null); },
        insertBefore(child, before) {
            if (child === before) return;
            child.remove();
            this.children.splice(before ? this.children.indexOf(before) : this.children.length, 0, child);
            child.parent = this;
        },
        remove() {
            if (!this.parent) return;
            this.parent.children.splice(this.parent.children.indexOf(this), 1);
            this.parent = null;
        },
        replaceChildren(...children) {
            [...this.children].forEach(child => child.remove());
            children.forEach(child => this.appendChild(child));
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        getAttribute(name) { return this.attributes[name]; },
        addEventListener(name, callback) { this.listeners[name] = callback; },
        querySelectorAll() { return this.children.flatMap(child => [child, ...child.querySelectorAll()]); },
        querySelector(selector) {
            return this.querySelectorAll().find(child => child.className?.split(' ').includes(selector.slice(1)));
        },
        closest(selector) {
            if (selector === '[data-automation-run]' && this.dataset.automationRun) return this;
            return this.parent?.closest(selector);
        },
        focus() {},
    };
}

function activity(overrides = {}) {
    return {
        id: 13, order_id: 1, status: 'failed', tone: 'error',
        title: 'Automation', notification_title: 'Paczkomat B', status_label: 'Błąd', message: 'Failed', order_label: 'Order 1',
        order_url: '/orders/1', updated_at: '2026-11-04T10:00:00Z',
        is_terminal: true, can_dismiss: true, steps: [],
        ...overrides,
    };
}

function step(position, status, tone) {
    return { position, status, tone, short_label: 'Krok ' + position, status_label: status };
}

function fixture(initial = [], storage = new Map(), storageFails = false, mobile = false, orderId = 1, motion = null) {
    const listeners = {};
    const pageListeners = {};
    const timers = new Map();
    const requests = [];
    const events = [];
    const animations = [];
    const center = element();
    center.hidden = true;
    if (orderId !== null) {
        center.dataset = { endpoint: '/automation/activity?order_id=' + orderId, automationOrderId: String(orderId) };
    }
    let incoming = initial;
    let fetchGate = null;
    let now = 0;
    let nextTimer = 0;
    const document = {
        hidden: false,
        querySelector: selector => selector === '[data-automation-activity-center]' ? center : null,
        createElement() {
            const node = element();
            if (motion) {
                node.animate = (frames, options) => {
                    const animation = {
                        node, frames, options, cancelled: false,
                        cancel() {
                            this.cancelled = true;
                            window.clearTimeout(timer);
                        },
                    };
                    const timer = window.setTimeout(() => animation.onfinish?.(), options.duration);
                    animations.push(animation);
                    return animation;
                };
            }
            return node;
        },
        addEventListener(name, callback) { listeners[name] = callback; },
        dispatchEvent(event) { events.push(event); },
    };
    const window = {
        innerHeight: motion?.height ?? 900,
        setTimeout(callback, delay) {
            const id = ++nextTimer;
            timers.set(id, { callback, due: now + delay, delay });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
        matchMedia: query => ({
            matches: query.includes('prefers-reduced-motion') ? motion?.reduced === true : mobile,
            addEventListener() {},
        }),
        getComputedStyle: () => ({ opacity: '0.5', transform: 'matrix(1, 0, 0, 1, 0, 3)', height: '15px' }),
        addEventListener(name, callback) { pageListeners[name] = callback; },
    };
    class ClockDate extends Date {
        constructor(...args) { super(...(args.length ? args : [now])); }
        static now() { return now; }
    }
    vm.runInNewContext(source, {
        document, window, Date: ClockDate, AbortController,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        sessionStorage: {
            getItem(key) { if (storageFails) throw new Error('Storage unavailable'); return storage.get(key) ?? null; },
            setItem(key, value) { if (storageFails) throw new Error('Storage unavailable'); storage.set(key, value); },
        },
        fetch: async (url, options) => {
            requests.push({ url, options });
            const activities = incoming;
            const gate = fetchGate;
            fetchGate = null;
            if (gate) await gate;
            return { ok: true, json: async () => ({ activities }) };
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
    const groups = () => center.children.filter(child => child.className === 'automation-activity-group');
    const chips = () => groups().map(group => group.querySelector('.automation-activity-chip'));

    return {
        center, timers, requests, events, storage, advance, chips, animations,
        open: (index = 0) => chips()[index].listeners.click(),
        panel: (index = 0) => groups()[index]?.querySelector('.automation-activity-steps'),
        stepCount: (index = 0) => groups()[index]?.querySelector('.automation-activity-step-count')?.textContent,
        stepSlots: (index = 0) => groups()[index]?.querySelector('.automation-activity-step-list')?.children ?? [],
        steps: (index = 0) => groups()[index]?.querySelector('.automation-activity-step-list')?.children.map(slot => slot.children[0]) ?? [],
        show: detail => window.nexOmsShowActivity(detail),
        close: () => center.querySelector('.automation-activity-close').listeners.click(),
        hidePage: () => pageListeners.pagehide?.(),
        restorePage: () => pageListeners.pageshow?.({ persisted: true }),
        resize(height) {
            window.innerHeight = height;
            pageListeners.resize();
        },
        pauseNextFetch() {
            let release;
            fetchGate = new Promise(resolve => { release = resolve; });
            return release;
        },
        async refresh(next = incoming) {
            incoming = next;
            listeners['nexoms:automation-wake']?.();
            await advance(0);
        },
    };
}

const outcomes = [
    ['completed', 'success'],
    ['completed_with_errors', 'warning'],
    ['failed', 'error'],
    ['interrupted', 'error'],
];

for (const [status, tone] of outcomes) {
    test(status + ' stays visible for eight seconds without restarting on refresh', async () => {
        const f = fixture([activity({ status, tone })]);
        await f.refresh();
        assert.match(f.chips()[0].className, new RegExp('is-' + tone));
        await f.advance(7999);
        assert.equal(f.chips().length, 1);
        assert.ok(f.requests.length > 2);
        await f.advance(1);
        assert.equal(f.center.hidden, true);
        await f.refresh();
        assert.equal(f.chips().length, 0);
        assert.equal(f.events.length, 1);
        assert.equal(f.events[0].type, 'nexoms:automation-finished');
        assert.ok(f.requests.every(request => request.url === '/automation/activity?order_id=1' && !request.options.method));
    });
}

test('queued, running and waiting activities do not auto-close', async () => {
    const f = fixture(['queued', 'running', 'waiting'].map((status, index) => activity({
        id: index + 1, status, tone: status === 'waiting' ? 'waiting' : 'progress',
        is_terminal: false, can_dismiss: status === 'waiting',
    })));
    await f.refresh();
    await f.advance(60000);
    assert.equal(f.chips().length, 3);
});

test('the same running rule changes color and starts its eight seconds only on completion', async () => {
    const f = fixture([activity({
        status: 'running', tone: 'progress', is_terminal: false, can_dismiss: false,
        steps: [step(1, 'running', 'progress'), step(2, 'queued', 'queued')],
    })]);
    await f.refresh();
    await f.advance(15000);
    assert.equal(f.chips()[0].children[0].children[0].className, 'automation-activity-spinner');
    assert.equal(f.steps().length, 1);
    assert.equal(f.steps()[0].children[0].children[0].className, 'automation-activity-spinner');
    await f.refresh([activity({
        status: 'completed', tone: 'success', status_label: 'Gotowe',
        steps: [step(1, 'completed', 'success'), step(2, 'completed', 'success')],
    })]);
    assert.equal(f.chips().length, 1);
    assert.match(f.chips()[0].className, /is-success/);
    assert.equal(f.chips()[0].attributes['aria-expanded'], 'true');
    await f.advance(4999);
    assert.equal(f.steps().length, 2);
    await f.advance(1);
    assert.equal(f.steps().length, 0);
    await f.refresh();
    assert.equal(f.panel(), undefined);
    await f.advance(2999);
    assert.equal(f.chips().length, 1);
    await f.advance(1);
    assert.equal(f.center.hidden, true);
});

test('an expanded rule shows mixed one-line outcomes, with diagnostics kept out of the steps', async () => {
    const f = fixture([activity({
        status: 'completed_with_errors', tone: 'warning', details: 'Long diagnostic text',
        steps: [
            step(1, 'completed', 'success'),
            step(2, 'completed', 'success'),
            { ...step(3, 'failed', 'warning'), short_label: 'Wywołaj URL', label: 'Wywołaj URL: https://example.test/', details: 'Long error' },
        ],
    })]);
    await f.refresh();
    const chip = f.chips()[0];
    assert.equal(chip.children[1].textContent, 'Paczkomat B');
    assert.equal(chip.attributes['aria-expanded'], 'true');
    assert.match(chip.attributes['aria-label'], /bez zatrzymania reguły/);
    assert.deepEqual(f.steps().map(row => row.className.split(' ').at(-1)), ['is-success', 'is-success', 'is-warning']);
    const [, , warning] = f.steps();
    assert.equal(warning.children[1].textContent, 'Wywołaj URL');
    assert.match(warning.title, /nie zatrzymał reguły/);
    assert.doesNotMatch(warning.title, /Long error|example\.test/);
    f.open();
    assert.equal(f.panel(), undefined);
    await f.refresh();
    assert.equal(f.panel(), undefined);
    f.open();
    assert.equal(f.chips()[0].attributes['aria-expanded'], 'true');
    await f.refresh();
    assert.equal(f.chips()[0].attributes['aria-expanded'], 'true');
    f.center.listeners.keydown({ key: 'Escape', target: f.chips()[0], stopPropagation() {} });
    assert.equal(f.panel(), undefined);
});

test('each step expires independently, and manual expansion can still show skipped steps', async () => {
    const f = fixture([activity({
        status: 'running', tone: 'progress', is_terminal: false, can_dismiss: false,
        steps: [step(1, 'completed', 'success'), step(2, 'running', 'progress')],
    })]);
    await f.refresh();
    await f.advance(3000);
    await f.refresh([activity({
        steps: [step(1, 'completed', 'success'), step(2, 'failed', 'error'), step(3, 'skipped', 'skipped')],
    })]);
    const tones = () => f.steps().map(row => row.className.split(' ').at(-1));
    assert.deepEqual(tones(), ['is-success', 'is-error']);
    await f.advance(2000);
    assert.deepEqual(tones(), ['is-error']);
    await f.advance(3000);
    assert.deepEqual(tones(), []);
    f.open();
    assert.deepEqual(tones(), ['is-success', 'is-error', 'is-skipped']);
    await f.advance(3000);
    assert.equal(f.center.hidden, true);
});

test('all visible rules expand independently and each new rule appears to the left', async () => {
    const running = id => activity({
        id, status: 'running', tone: 'progress', is_terminal: false, can_dismiss: false,
        steps: [step(1, 'running', 'progress')],
    });
    const first = running(1);
    const second = running(2);
    const third = running(3);
    const f = fixture([first]);
    await f.refresh();
    await f.refresh([second, first]);
    await f.refresh([third, second, first]);
    const ids = () => f.chips().map(chip => chip.dataset.activityControl);
    assert.deepEqual(ids(), ['chip:3', 'chip:2', 'chip:1']);
    assert.deepEqual(f.chips().map(chip => chip.attributes['aria-controls']), [
        'automationActivitySteps-3', 'automationActivitySteps-2', 'automationActivitySteps-1',
    ]);
    assert.ok([0, 1, 2].every(index => f.steps(index).length === 1));
    f.open(1);
    await f.refresh([activity({ id: 1, steps: [step(1, 'failed', 'error')] }), third, second]);
    assert.deepEqual(ids(), ['chip:3', 'chip:2', 'chip:1']);
    assert.equal(f.panel(1), undefined);
    assert.equal(f.steps(0).length, 1);
    assert.equal(f.steps(2).length, 1);
});

test('manual closing cancels the timer and persists dismissal across page reloads', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(1000);
    assert.equal([...f.timers.values()].filter(timer => timer.delay === 8000).length, 1);
    f.open();
    f.close();
    assert.equal(f.center.hidden, true);
    assert.equal([...f.timers.values()].filter(timer => timer.delay === 8000).length, 0);
    await f.refresh();
    assert.equal(f.chips().length, 0);
    const reloaded = fixture([activity()], f.storage);
    await reloaded.refresh();
    assert.equal(reloaded.chips().length, 0);
});

test('terminal notifications missing from a later feed still close after their full eight seconds', async () => {
    const f = fixture([activity({ status: 'completed', tone: 'success' })]);
    await f.refresh();
    await f.advance(1000);
    await f.refresh([]);
    await f.advance(6999);
    assert.equal(f.chips().length, 1);
    await f.advance(1);
    assert.equal(f.chips().length, 0);
});

test('a new result version receives its own full display time', async () => {
    const f = fixture([activity()]);
    await f.refresh();
    await f.advance(5000);
    await f.refresh([activity({ updated_at: '2026-11-04T10:00:05Z' })]);
    await f.advance(7999);
    assert.equal(f.chips().length, 1);
    await f.advance(1);
    assert.equal(f.chips().length, 0);
});

test('local notices keep their full messages and original timing without browser storage', async () => {
    const f = fixture([], new Map(), true);
    f.show({ tone: 'error', title: 'NEX Printer', message: 'Failed' });
    f.show({ tone: 'success', title: 'PayNow', message: 'Saved' });
    const [success, error] = f.center.children;
    assert.equal(error.children[1].children.at(-1).textContent, 'Failed');
    assert.equal(success.children[1].children.at(-1).textContent, 'Saved');
    await f.advance(7000);
    assert.equal(f.center.children.length, 1);
    await f.advance(12999);
    assert.equal(f.center.children.length, 1);
    await f.advance(1);
    assert.equal(f.center.hidden, true);
});

test('visible limit is three on desktop and two on mobile; errors take priority and overflow waits for its turn', async () => {
    const statuses = [['completed', 'success'], ['completed_with_errors', 'warning'], ['failed', 'error'], ['completed', 'success']];
    const incoming = statuses.map(([status, tone], index) => activity({ id: index + 1, status, tone }));
    const desktop = fixture(incoming);
    const mobile = fixture(incoming, new Map(), false, true);
    await desktop.refresh();
    await mobile.refresh();

    const visibleTones = f => f.chips().map(chip => chip.className.split(' ').at(-1));
    assert.deepEqual(visibleTones(desktop), ['is-error', 'is-warning', 'is-success']);
    assert.deepEqual(visibleTones(mobile), ['is-error', 'is-warning']);
    assert.equal(desktop.center.children[0].textContent, '+1');
    assert.equal(mobile.center.children[0].textContent, '+2');

    await desktop.advance(8000);
    assert.deepEqual(visibleTones(desktop), ['is-success']);
    await desktop.advance(7999);
    assert.equal(desktop.chips().length, 1);
    await desktop.advance(1);
    assert.equal(desktop.center.hidden, true);
});

test('the order list never polls for automation but still shows manual notifications', async () => {
    const list = fixture([activity()], new Map(), false, false, null);
    await list.refresh();
    await list.advance(60000);
    assert.equal(list.requests.length, 0);
    assert.equal(list.events.length, 0);
    assert.equal(list.center.hidden, true);

    list.show({ tone: 'success', title: 'NEX Printer', message: 'Queued' });
    assert.equal(list.center.children[0].children[1].children.at(-1).textContent, 'Queued');
    await list.advance(7000);
    assert.equal(list.center.hidden, true);
    assert.equal(list.requests.length, 0);
});

test('moving between order cards and back never replays a shown result or exposes another order', async () => {
    const storage = new Map();
    const shown = activity({ id: 1, order_id: 548 });
    const unseen = activity({ id: 2, order_id: 548 });
    const other = activity({ id: 3, order_id: 549 });
    const first = fixture([shown, other], storage, false, false, 548);
    await first.refresh();
    assert.deepEqual(first.chips().map(chip => chip.dataset.activityControl), ['chip:1']);
    assert.deepEqual(first.events.map(event => event.detail.orderId), [548]);
    first.hidePage();
    assert.equal(first.center.hidden, true);

    const second = fixture([shown, unseen, other], storage, false, false, 549);
    await second.refresh();
    assert.deepEqual(second.chips().map(chip => chip.dataset.activityControl), ['chip:3']);
    assert.deepEqual(second.events.map(event => event.detail.orderId), [549]);
    assert.equal(second.requests[0].url, '/automation/activity?order_id=549');

    first.restorePage();
    await first.refresh([shown, unseen, other]);
    assert.deepEqual(first.chips().map(chip => chip.dataset.activityControl), ['chip:2']);
    assert.equal(first.requests.at(-1).url, '/automation/activity?order_id=548');

    const reloaded = fixture([shown, unseen], storage, false, false, 548);
    await reloaded.refresh();
    assert.deepEqual(reloaded.chips().map(chip => chip.dataset.activityControl), ['chip:2']);
    first.hidePage();
    const finishedElsewhere = activity({ id: 4, order_id: 548 });
    await reloaded.refresh([finishedElsewhere]);
    reloaded.hidePage();
    first.restorePage();
    await first.refresh([finishedElsewhere]);
    assert.equal(first.center.hidden, true);
});

test('leaving an order aborts the in-flight poll and returning resumes it', async () => {
    const f = fixture([activity()]);
    const release = f.pauseNextFetch();
    const request = f.refresh();
    f.hidePage();
    assert.equal(f.requests[0].options.signal.aborted, true);
    release();
    await request;
    await f.advance(60000);
    assert.equal(f.requests.length, 1);
    assert.equal(f.events.length, 0);
    assert.equal(f.center.hidden, true);
    f.restorePage();
    await f.advance(0);
    assert.equal(f.requests.length, 2);
    assert.equal(f.chips().length, 1);
    assert.equal(f.events.length, 1);
});

test('a notification slides in once and fades out only after the full result time', async () => {
    const running = activity({ status: 'running', is_terminal: false, can_dismiss: false, tone: 'progress' });
    const f = fixture([running], new Map(), false, false, 1, {});
    await f.refresh();
    assert.equal(f.animations.length, 1);
    const [entrance] = f.animations;
    const groupAnimations = () => f.animations.filter(animation => animation.node.className === 'automation-activity-group');
    assert.equal(entrance.options.duration, 250);
    assert.equal(entrance.frames[0].transform, 'translateY(6px)');
    assert.equal(entrance.frames[1].opacity, 1);

    await f.advance(100);
    const completed = activity({ status: 'completed', tone: 'success', steps: [step(1, 'completed', 'success')] });
    await f.refresh([completed]);
    assert.equal(f.center.children[0], entrance.node);
    assert.match(f.chips()[0].className, /is-success/);
    assert.equal(groupAnimations().length, 1);
    assert.equal(entrance.cancelled, false);
    await f.advance(7999);
    assert.equal(groupAnimations().length, 1);
    await f.advance(1);
    assert.equal(groupAnimations().length, 2);
    const exit = groupAnimations()[1];
    assert.equal(exit.options.duration, 250);
    assert.equal(exit.frames[1].opacity, 0);
    assert.equal(exit.node.inert, true);
    await f.refresh([completed]);
    await f.advance(249);
    assert.equal(f.center.hidden, false);
    await f.advance(1);
    assert.equal(f.center.hidden, true);
});

test('manual dismissal fades the notification and persists while the next notification waits for its slot', async () => {
    const first = activity();
    const second = activity({ id: 14, status: 'running', is_terminal: false, can_dismiss: false, tone: 'progress' });
    const f = fixture([first], new Map(), false, false, 1, {});
    await f.refresh();
    f.open();
    f.close();
    assert.equal(f.animations.length, 2);
    assert.equal(f.animations[0].cancelled, true);
    assert.equal(f.animations[1].frames[0].opacity, '0.5');
    assert.equal(f.animations[1].node.inert, true);
    await f.refresh([first, second]);
    assert.equal(f.chips().length, 1);
    await f.advance(250);
    assert.deepEqual(f.chips().map(chip => chip.dataset.activityControl), ['chip:14']);
    assert.equal(f.animations.length, 3);
    await f.refresh([first, second]);
    assert.deepEqual(f.chips().map(chip => chip.dataset.activityControl), ['chip:14']);
});

test('simultaneous exits keep their slots until the next result can enter', async () => {
    const notices = [1, 2, 3, 4].map(id => activity({ id }));
    const f = fixture(notices, new Map(), false, false, 1, {});
    await f.refresh();
    await f.advance(8000);
    assert.equal(f.chips().length, 3);
    const exits = f.animations.filter(animation => animation.frames.at(-1).opacity === 0);
    assert.equal(exits.length, 3);
    assert.ok(exits.every(animation => animation.node.inert));
    await f.advance(250);
    assert.deepEqual(f.chips().map(chip => chip.dataset.activityControl), ['chip:4']);
    assert.equal(f.animations.length, 7);
});

test('finished steps fade and collapse together after five seconds without replaying other steps', async () => {
    const running = activity({
        status: 'running', is_terminal: false, can_dismiss: false, tone: 'progress',
        steps: [step(1, 'completed', 'success'), step(2, 'completed', 'success'), step(3, 'running', 'progress')],
    });
    const f = fixture([running], new Map(), false, false, 1, {});
    await f.refresh();
    const rows = () => f.animations.filter(animation => animation.node.className === 'automation-activity-step-slot');
    const [first, second, remaining] = f.stepSlots();
    assert.equal(rows().length, 3);
    assert.equal(rows()[0].frames[0].height, '0px');
    await f.advance(4999);
    assert.equal(rows().length, 3);
    await f.advance(1);
    assert.equal(rows().length, 5);
    const exits = rows().slice(-2);
    assert.deepEqual(exits.map(exit => exit.node), [first, second]);
    assert.ok(exits.every(exit => exit.options.duration === 250 && exit.frames[1].height === '0px'
        && exit.frames[1].opacity === 0 && exit.node.getAttribute('aria-hidden') === 'true'));
    await f.refresh([running]);
    await f.advance(249);
    assert.equal(f.stepSlots().length, 3);
    await f.advance(1);
    assert.deepEqual(f.stepSlots(), [remaining]);
    assert.equal(rows().length, 5);
    assert.equal(f.chips()[0].getAttribute('aria-expanded'), 'true');
});

test('updating a step keeps its slot and reopening while it fades uses its current animation state', async () => {
    const running = activity({
        status: 'running', is_terminal: false, can_dismiss: false, tone: 'progress',
        steps: [step(1, 'running', 'progress')],
    });
    const f = fixture([running], new Map(), false, false, 1, {});
    await f.refresh();
    const [slot] = f.stepSlots();
    await f.advance(1000);
    await f.refresh([{ ...running, steps: [step(1, 'completed', 'success')] }]);
    assert.equal(f.stepSlots()[0], slot);
    assert.match(f.steps()[0].className, /is-success/);
    assert.equal(f.animations.length, 2);
    f.open();
    const exit = f.animations.at(-1);
    await f.advance(100);
    assert.equal(f.chips()[0].getAttribute('aria-expanded'), 'false');
    f.open();
    assert.equal(exit.cancelled, true);
    assert.equal(f.stepSlots()[0], slot);
    assert.equal(slot.getAttribute('aria-hidden'), undefined);
    assert.equal(f.animations.at(-1).frames[0].height, '15px');
    await f.advance(7000);
    assert.equal(f.steps().length, 1);
});

test('long step lists reserve room for the count and the close button at different heights', async () => {
    const notice = activity({ steps: Array.from({ length: 10 }, (_, i) => step(i + 1, 'completed', 'success')) });
    const f = fixture([notice], new Map(), false, true, 1, { height: 360, reduced: true });
    await f.refresh();
    assert.equal(f.steps().length, 3);
    assert.equal(f.stepCount(), '+7 kroków');
    f.resize(900);
    assert.equal(f.steps().length, 7);
    assert.equal(f.stepCount(), '+3 kroki');
    f.open();
    assert.equal(f.panel(), undefined);
    f.open();
    f.resize(360);
    assert.equal(f.steps().length, 2);
    assert.equal(f.stepCount(), '+8 kroków');
    assert.ok(f.panel().querySelector('.automation-activity-step-close'));
    await f.advance(5000);
    assert.equal(f.stepCount(), '+8 kroków');
});

test('a waiting step does not enlarge the panel while earlier steps are fading', async () => {
    const running = activity({
        status: 'running', is_terminal: false, can_dismiss: false, tone: 'progress',
        steps: [
            step(1, 'completed', 'success'), step(2, 'completed', 'success'), step(3, 'running', 'progress'),
            step(4, 'waiting', 'waiting'), step(5, 'waiting', 'waiting'), step(6, 'waiting', 'waiting'),
        ],
    });
    const f = fixture([running], new Map(), false, true, 1, { height: 360 });
    await f.refresh();
    const lastVisible = f.stepSlots()[2];
    assert.equal(f.stepSlots().length, 3);
    assert.equal(f.stepCount(), '+3 kroki');
    await f.advance(5000);
    assert.equal(f.stepSlots().length, 4);
    assert.equal(f.stepCount(), undefined);
    await f.advance(250);
    assert.equal(f.stepSlots()[0], lastVisible);
    assert.deepEqual(f.steps().map(row => row.children[1].textContent), ['Krok 3', 'Krok 4', 'Krok 5', 'Krok 6']);
});

test('reduced motion and leaving a card do not leave a fading notification behind', async () => {
    const notice = activity({ steps: [step(1, 'completed', 'success')] });
    const reduced = fixture([notice], new Map(), false, false, 1, { reduced: true });
    await reduced.refresh();
    await reduced.advance(5000);
    assert.equal(reduced.steps().length, 0);
    reduced.open();
    reduced.close();
    assert.equal(reduced.animations.length, 0);
    assert.equal(reduced.center.hidden, true);

    const animated = fixture([activity()], new Map(), false, false, 1, {});
    await animated.refresh();
    animated.open();
    animated.close();
    animated.hidePage();
    assert.equal(animated.animations[1].cancelled, true);
    assert.equal(animated.center.hidden, true);
    animated.restorePage();
    await animated.advance(250);
    assert.equal(animated.chips().length, 0);
    animated.show({ tone: 'success', title: 'NEX Printer' });
    assert.equal(animated.animations.length, 2);
});
