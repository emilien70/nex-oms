const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const repo = path.resolve(__dirname, '../..');
const page = fs.readFileSync(path.join(repo, 'resources/views/orders/show.blade.php'), 'utf8');
const sms = fs.readFileSync(path.join(repo, 'resources/views/components/order-sms-compose.blade.php'), 'utf8');
const token = 'a'.repeat(64);
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
    return { promise, resolve, reject };
};

class Field {
    constructor(value = 'old@example.test') { this.value = value; this.defaultValue = value; this.type = 'text'; this.editing = false; }
    getClientRects() { return [1]; }
    closest() { return this.editing ? {} : null; }
}

function contactHarness(fetcher) {
    const root = {}, field = new Field(), calls = [];
    const context = vm.createContext({
        HTMLInputElement: Field, HTMLTextAreaElement: Field, URL, Promise,
        document: { querySelector: () => root, querySelectorAll: () => [field] },
        window: { location: { origin: 'http://localhost' }, getComputedStyle: () => ({ visibility: 'visible' }) },
        fetch: async (...args) => { calls.push(args); return fetcher ? fetcher(...args) : { ok: true, json: async () => ({ recipient: 'new@example.test', recipient_token: token }) }; },
    });
    const start = page.indexOf('const contactFieldSelector =');
    const end = page.indexOf('let deferredOrderInfoHtml', start);
    assert.ok(start > 0 && end > start);
    const source = page.slice(start, end).replace("@json(route('orders.message-recipient', $order))", "'/orders/1/message-recipient'");
    vm.runInContext(`${source}; globalThis.pending = pendingContactSaves;`, context);
    return { root, field, calls, pending: context.pending };
}

test('opening a form does not fetch a recipient; Send requests fresh private data', async () => {
    const h = contactHarness();
    assert.equal(h.calls.length, 0);
    const recipient = await h.root.resolveMessageRecipient('email');
    assert.equal(recipient.recipient, 'new@example.test');
    assert.equal(h.calls.length, 1);
    assert.match(h.calls[0][0], /channel=email/);
    assert.equal(h.calls[0][1].cache, 'no-store');
});

test('unsaved contact edits block lookup without auto-saving', async () => {
    const h = contactHarness();
    h.field.value = 'draft@example.test';
    await assert.rejects(h.root.resolveMessageRecipient('email'), /Najpierw zapisz/);
    assert.equal(h.calls.length, 0);
    assert.equal(h.field.value, 'draft@example.test');
});

test('Send waits for an in-flight contact save and then reads the current recipient', async () => {
    const h = contactHarness(), save = deferred(), form = {};
    h.pending.set(form, save);
    const result = h.root.resolveMessageRecipient('sms');
    await Promise.resolve();
    assert.equal(h.calls.length, 0);
    h.pending.delete(form);
    save.resolve();
    await result;
    assert.equal(h.calls.length, 1);
    assert.match(h.calls[0][0], /channel=sms/);
});

test('a failed contact save rejects Send without a recipient request', async () => {
    const h = contactHarness(), save = deferred();
    h.pending.set({}, save);
    const result = h.root.resolveMessageRecipient('email');
    save.reject(new Error('save failed'));
    await assert.rejects(result, /save failed/);
    assert.equal(h.calls.length, 0);
});

test('editing contact during recipient lookup blocks Send', async () => {
    const reply = deferred(), h = contactHarness(() => reply.promise);
    const result = h.root.resolveMessageRecipient('email');
    h.field.value = 'draft@example.test';
    reply.resolve({ ok: true, json: async () => ({ recipient: 'new@example.test', recipient_token: token }) });
    await assert.rejects(result, /Najpierw zapisz/);
});

test('lookup and malformed response failures cannot authorize a send', async () => {
    const h = contactHarness(async () => ({ ok: false, json: async () => ({ message: 'lookup failed' }) }));
    await assert.rejects(h.root.resolveMessageRecipient('email'), /lookup failed/);
    const malformed = contactHarness(async () => ({ ok: true, json: async () => ({ recipient: 'new@example.test' }) }));
    await assert.rejects(malformed.root.resolveMessageRecipient('email'), /potwierdzi/);
});

class FakeFormData extends Map {
    constructor(form) { super(Object.entries(form.elements).map(([name, input]) => [name, input.value])); }
}

function smsHarness(options = {}) {
    const posts = [], confirmations = [], results = [];
    const button = { disabled: false };
    const form = { action: '/sms', elements: { body: { value: 'Draft SMS' }, sms_template_id: { value: '2' }, idempotency_key: { value: 'original-key' } },
        querySelector: () => button, addEventListener: (_, handler) => { form.submit = handler; } };
    const root = { resolveMessageRecipient: options.resolve || (async () => ({ recipient: '+48500000001', recipient_token: token })),
        assertMessageContactSaved: options.assertSaved || (() => {}) };
    const context = vm.createContext({
        form, root, crypto: webcrypto, FormData: FakeFormData, clearTimeout: () => {},
        confirm: value => { confirmations.push(value); return options.confirm !== false; },
        request: async (url, data) => { posts.push(data); return {}; },
        setResult: value => results.push(value), announceHistory: () => {}, resetPreview: () => {}, refresh: async () => {},
    });
    const start = sms.indexOf("form.addEventListener('submit'");
    const end = sms.indexOf("history.addEventListener('click'", start);
    vm.runInContext(`let sending=false, previewTimer, revision=0, polls=0; ${sms.slice(start, end)}`, context);
    return { form, button, posts, confirmations, results, send: () => form.submit({ preventDefault() {} }) };
}

test('SMS confirms the fresh number and posts its confirmation token', async () => {
    const h = smsHarness();
    await h.send();
    assert.match(h.confirmations[0], /\+48500000001/);
    assert.equal(h.posts.length, 1);
    assert.equal(h.posts[0].body.get('recipient'), '+48500000001');
    assert.equal(h.posts[0].body.get('recipient_token'), token);
});

test('double-click during SMS lookup queues only once', async () => {
    const lookup = deferred(), h = smsHarness({ resolve: () => lookup.promise });
    const first = h.send();
    await h.send();
    assert.equal(h.posts.length, 0);
    lookup.resolve({ recipient: '+48500000001', recipient_token: token });
    await first;
    assert.equal(h.posts.length, 1);
    assert.equal(h.confirmations.length, 1);
});

test('SMS lookup failure or cancellation retains draft and idempotency key', async () => {
    for (const options of [{ resolve: async () => { throw new Error('offline'); } }, { confirm: false }]) {
        const h = smsHarness(options);
        await h.send();
        assert.equal(h.posts.length, 0);
        assert.equal(h.form.elements.body.value, 'Draft SMS');
        assert.equal(h.form.elements.idempotency_key.value, 'original-key');
        assert.equal(h.button.disabled, false);
    }
});

test('contact edits after SMS confirmation prevent POST', async () => {
    let checks = 0;
    const h = smsHarness({ assertSaved: () => { if (++checks === 2) throw new Error('unsaved'); } });
    await h.send();
    assert.equal(h.posts.length, 0);
    assert.equal(h.form.elements.body.value, 'Draft SMS');
});

test('email handler blocks double-click and snapshots confirmed token after lookup', async () => {
    const lookup = deferred(), posts = [], button = { disabled: false, textContent: 'Send' };
    const form = { action: '/email', elements: { body: { value: 'Draft' } }, classList: { add() {} },
        addEventListener: (_, handler) => { form.submit = handler; } };
    const exchange = { resolveMessageRecipient: () => lookup.promise, assertMessageContactSaved() {}, querySelector: () => null };
    const context = vm.createContext({ emailForm: form, emailSubmit: button, emailExchange: exchange, FormData: FakeFormData,
        emailBodyInput: {}, currentEmailBody: () => 'Draft', setEmailError() {}, ensureEmailIdempotencyKey() {},
        fetch: async (_, data) => { posts.push(data); return { ok: true, json: async () => ({ polling_finished: true }) }; },
        replaceEmailMessage() {}, resetEmailForm() {}, emailToggleLabel: {}, pollEmailMessage() {},
    });
    const start = page.indexOf("emailForm?.addEventListener('submit'");
    const end = page.indexOf('emailMessages?.querySelectorAll', start);
    vm.runInContext(`let emailSending=false; ${page.slice(start, end)}`, context);
    const first = form.submit({ preventDefault() {} });
    await form.submit({ preventDefault() {} });
    assert.equal(posts.length, 0);
    lookup.resolve({ recipient: 'new@example.test', recipient_token: token });
    await first;
    assert.equal(posts.length, 1);
    assert.equal(posts[0].body.get('recipient_token'), token);
    assert.equal(button.disabled, false);
});
