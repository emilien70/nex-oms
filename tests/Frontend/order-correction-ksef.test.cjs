const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');
const pdfFrom = source.indexOf('const ksefInvoiceDownloadError =');
const pdfTo = source.indexOf('const closeSection =', pdfFrom);
const formSelector = source.indexOf("const form = event.target.closest('[data-order-ksef-send-form]');");
const modalFrom = source.lastIndexOf("document.addEventListener('submit'", formSelector);
const modalTo = source.indexOf("salesDocumentDeleteModalElement?.addEventListener('show.bs.modal'", modalFrom);
assert.ok(pdfFrom >= 0 && pdfTo > pdfFrom && modalFrom >= 0 && modalTo > modalFrom);

function pdfFixture({ correction = true, blocked = false, response, generate } = {}) {
    const listeners = {};
    const events = [];
    const timers = [];
    const alerts = [];
    const revoked = [];
    const attributes = {};
    const preview = {
        opener: {}, closed: false,
        location: { replace(url) { events.push(['preview', url]); } },
        close() { this.closed = true; },
    };
    const trigger = {
        dataset: {
            ksefPdfOpen: correction ? '1' : undefined,
            ksefPdfGeneratorSrc: '/local-generator.js', ksefInvoiceSourceUrl: '/invoices/2/ksef/source',
            ksefNumber: 'FAKE-KSEF-NUMBER', ksefPdfFilename: 'KSeF_NFK_1_2026.pdf',
            ksefAcquisitionDate: '21.08.2026 10:00:00', ksefVerificationUrl: 'https://example.test/verification',
        },
        getAttribute: name => attributes[name],
        setAttribute(name, value) { attributes[name] = value; },
        removeAttribute(name) { delete attributes[name]; },
    };
    const context = vm.createContext({
        document: {
            addEventListener(name, handler) { listeners[name] = handler; },
            createElement() { return { click() { events.push(['download', this.download]); }, remove() {} }; },
            body: { append() {} },
        },
        window: {
            open() { events.push(['open']); return blocked ? null : preview; },
            setTimeout(handler) { timers.push(handler); },
        },
        File: class { constructor(parts, name, options) { this.parts = parts; this.name = name; this.type = options.type; } },
        URL: { createObjectURL: () => 'blob:fake-pdf', revokeObjectURL: url => revoked.push(url) },
        alert: message => alerts.push(message),
        loadKsefPdfGenerator: async () => ({ generateInvoice: async (...args) => {
            events.push(['generate', ...args]);
            return generate ? generate(...args) : { type: 'application/pdf' };
        } }),
        fetch: async (...args) => {
            events.push(['fetch', ...args]);
            return response ? response(...args) : { ok: true, text: async () => '<Faktura>KOR</Faktura>' };
        },
    });
    vm.runInContext(source.slice(pdfFrom, pdfTo), context);
    return {
        click: () => listeners.click({ target: { closest: () => trigger }, preventDefault() {} }),
        events, timers, alerts, preview, attributes, revoked,
    };
}

test('correction PDF opens once in an isolated tab from a read-only XML request', async () => {
    const f = pdfFixture();
    await f.click();
    assert.equal(f.events[0][0], 'open');
    assert.equal(f.preview.opener, null);
    const request = f.events.find(event => event[0] === 'fetch');
    assert.equal(request[1], '/invoices/2/ksef/source');
    assert.equal(request[2].method, undefined);
    const generation = f.events.find(event => event[0] === 'generate');
    assert.equal(generation[1].parts[0], '<Faktura>KOR</Faktura>');
    assert.equal(generation[2].nrKSeF, 'FAKE-KSEF-NUMBER');
    assert.equal(generation[3], 'blob');
    assert.ok(f.events.some(event => event[0] === 'preview' && event[1] === 'blob:fake-pdf'));
    assert.ok(!f.events.some(event => event[0] === 'download'));
    assert.equal(f.attributes['aria-busy'], undefined);
    f.preview.closed = true;
    f.timers[0]();
    assert.deepEqual(f.revoked, ['blob:fake-pdf']);
});

test('blocked popup prevents the XML request and shows a recoverable error', async () => {
    const f = pdfFixture({ blocked: true });
    await f.click();
    assert.equal(f.events.filter(event => event[0] === 'fetch').length, 0);
    assert.equal(f.alerts.length, 1);
    assert.equal(f.attributes['aria-busy'], undefined);
});

test('XML or PDF generation failure closes the empty preview and releases the trigger', async () => {
    for (const options of [
        { response: async () => ({ ok: false, json: async () => ({ message: 'Controlled failure' }) }) },
        { generate: async () => { throw new Error('Conversion failure'); } },
    ]) {
        const f = pdfFixture(options);
        await f.click();
        assert.equal(f.preview.closed, true);
        assert.equal(f.alerts.length, 1);
        assert.equal(f.attributes['aria-busy'], undefined);
    }
});

test('repeated PDF clicks do not open extra tabs or duplicate XML requests', async () => {
    let done;
    const f = pdfFixture({ response: () => new Promise(resolve => { done = resolve; }) });
    const pending = f.click();
    await f.click();
    assert.equal(f.events.filter(event => event[0] === 'open').length, 1);
    assert.equal(f.events.filter(event => event[0] === 'fetch').length, 1);
    done({ ok: true, text: async () => '<Faktura>KOR</Faktura>' });
    await pending;
});

test('ordinary invoice PDF keeps its existing download behavior', async () => {
    const f = pdfFixture({ correction: false });
    await f.click();
    assert.ok(!f.events.some(event => event[0] === 'open'));
    assert.ok(f.events.some(event => event[0] === 'download'));
    assert.equal(f.alerts.length, 0);
});

function modalFixture() {
    const listeners = {};
    const modalListeners = {};
    const confirmListeners = {};
    const question = { textContent: '' };
    const confirm = { disabled: false, addEventListener(name, handler) { confirmListeners[name] = handler; } };
    const modal = { addEventListener(name, handler) { modalListeners[name] = handler; } };
    const submitted = [];
    const context = vm.createContext({
        document: { addEventListener(name, handler) { listeners[name] = handler; } },
        ksefSendModalElement: modal, ksefSendConfirmButton: confirm,
        ksefSendQuestion: question, ksefDefaultSendQuestion: 'Invoice confirmation',
        flushDeferredSalesDocumentRefresh() {},
        bootstrap: { Modal: { getOrCreateInstance: () => ({ show: () => modalListeners['show.bs.modal']() }) } },
        HTMLFormElement: { prototype: { submit() { submitted.push(this); } } },
    });
    vm.runInContext(`let pendingKsefSendForm = null; let ksefSendInteractionActive = false;\n${source.slice(modalFrom, modalTo)}`, context);
    return {
        submit(form) { let prevented = false; listeners.submit({ target: { closest: () => form }, preventDefault() { prevented = true; } }); assert.ok(prevented); },
        accept: () => confirmListeners.click(), cancel: () => modalListeners['hidden.bs.modal'](),
        question, confirm, submitted,
    };
}

function sendForm(question) {
    const button = { disabled: false };
    return { dataset: { ksefConfirmQuestion: question }, querySelectorAll: () => [button], button };
}

test('cancelling correction confirmation never submits the form', () => {
    const f = modalFixture();
    const form = sendForm('Correction NFK 1/2026 DEMO confirmation');
    f.submit(form);
    assert.equal(f.question.textContent, form.dataset.ksefConfirmQuestion);
    assert.equal(f.submitted.length, 0);
    f.cancel();
    f.accept();
    assert.equal(f.submitted.length, 0);
    assert.equal(form.button.disabled, false);
});

test('confirmation submits exactly once and blocks both controls', () => {
    const f = modalFixture();
    const form = sendForm('Correction confirmation');
    f.submit(form);
    f.accept();
    f.accept();
    f.submit(form);
    assert.equal(f.submitted.length, 1);
    assert.equal(form.button.disabled, true);
    assert.equal(f.confirm.disabled, true);
});

test('invoice confirmation restores its own question after a correction was cancelled', () => {
    const f = modalFixture();
    f.submit(sendForm('Correction confirmation'));
    f.cancel();
    f.submit(sendForm());
    assert.equal(f.question.textContent, 'Invoice confirmation');
});
