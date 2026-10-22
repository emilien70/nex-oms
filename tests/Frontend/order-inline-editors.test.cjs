const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');

function block(start, end) {
    const from = source.indexOf(start);
    const to = source.indexOf(end, from + start.length);
    assert.ok(from >= 0 && to > from, `Missing script block: ${start}`);
    return source.slice(from, to);
}

function fixture({ incomplete = false } = {}) {
    const sections = new Map();
    const forms = [];
    let document;

    class ClassList {
        constructor(...names) { this.names = new Set(names); }
        add(name) { this.names.add(name); }
        remove(name) { this.names.delete(name); }
        contains(name) { return this.names.has(name); }
    }

    class Field {
        constructor(form, name, value, options = null) {
            Object.assign(this, { form, name, value, defaultValue: value, dataset: {}, type: 'text' });
            this.tagName = options ? 'SELECT' : 'INPUT';
            const field = this;
            this.options = options?.map(option => ({
                value: option,
                defaultSelected: option === value,
                get selected() { return field.value === option; },
            }));
        }
        closest(selector) { return selector === 'form' ? this.form : null; }
        focus() { document.activeElement = this; }
        select() { this.selectionStart = 0; this.selectionEnd = this.value.length; }
    }

    function form(section, values, paid = false) {
        const result = {
            section, paid, classList: new ClassList(), fields: {},
            closest(selector) {
                return selector === '.inline-section.is-editing' && this.section?.classList.contains('is-editing')
                    ? this.section : null;
            },
            matches(selector) {
                return selector === '[data-paid-edit].is-editing' && this.paid && this.classList.contains('is-editing');
            },
            querySelector() { return Object.values(this.fields)[0] || null; },
            querySelectorAll() { return Object.values(this.fields); },
            reset() {
                Object.values(this.fields).forEach(field => {
                    field.value = field.options
                        ? field.options.find(option => option.defaultSelected)?.value || ''
                        : field.defaultValue;
                });
            },
        };
        for (const [name, value] of Object.entries(values)) result.fields[name] = new Field(result, name, value);
        result.elements = { namedItem: name => result.fields[name] || null };
        forms.push(result);
        return result;
    }

    for (const name of ['shipping', 'billing', 'pickup', 'order-info']) {
        const section = { classList: new ClassList(), querySelector() { return this.form; } };
        const fieldName = name === 'pickup' ? 'pickup_point_name' : name === 'order-info' ? 'customer_login' : `${name}_name`;
        section.form = form(section, { [fieldName]: `${name}-original` });
        sections.set(name, section);
    }
    const billing = sections.get('billing').form;
    sections.get('order-info').form.fields.notes = new Field(sections.get('order-info').form, 'notes', 'Notatka');
    billing.fields.billing_country_code = new Field(billing, 'billing_country_code', 'PL', ['PL', 'DE']);
    const paid = form(null, { paid_amount: '10.00' }, true);
    const paidView = { style: {} };
    const infoTarget = {
        innerHTML: 'original-info',
        querySelector(selector) {
            return selector === '[data-paid-edit].is-editing' && paid.classList.contains('is-editing') ? paid : null;
        },
    };
    document = {
        activeElement: null,
        querySelector(selector) {
            if (selector === '[data-purchase-readiness]') return incomplete ? {} : null;
            const section = selector.match(/^\[data-inline-section="([^"]+)"\]$/);
            if (section) return sections.get(section[1]) || null;
            return {
                '[data-paid-edit]': paid,
                '[data-paid-input]': paid.fields.paid_amount,
                '[data-paid-view]': paidView,
                '[data-order-info-view]': infoTarget,
            }[selector] || null;
        },
        querySelectorAll(selector) {
            const name = selector.match(/\[name="([^"]+)"\]/)?.[1];
            return name ? forms.flatMap(item => item.fields[name] ? [item.fields[name]] : []) : [];
        },
    };
    const context = {
        document,
        window: { requestAnimationFrame: callback => callback() },
        HTMLElement: Field,
        HTMLInputElement: Field,
        HTMLTextAreaElement: class {},
        alert: message => assert.fail(message),
        fragmentTargets: { 'order-info': '[data-order-info-view]' },
        disposePaymentLinkTooltips() {},
        initializePaymentLinkTooltips() {},
    };
    const script = `
        const deferredFormFields = new WeakMap();
        let deferredOrderInfoHtml = null;
        ${block('const closeSection =', 'const openSection =')}
        ${block('const openSection =', "document.addEventListener('click', (event) => {")}
        ${block('const openPaidEdit =', "document.addEventListener('click', (event) => {")}
        ${block('const syncEditableFields =', 'const applyStarState =')}
        ${block('const applyOrderFragments =', 'const applyOrderState =')}
        ({ openSection, closeSection, openPaidEdit, closePaidEdit, syncEditableFields, applyOrderFragments,
            deferredHtml: () => deferredOrderInfoHtml })
    `;
    const api = vm.runInNewContext(script, context);
    return { api, sections, paid, paidView, infoTarget, document };
}

test('multiple sections open together and retain focus selection', () => {
    const { api, sections, document } = fixture();
    for (const name of sections.keys()) api.openSection(name);
    for (const section of sections.values()) assert.ok(section.classList.contains('is-editing'));
    assert.equal(document.activeElement, sections.get('order-info').form.fields.customer_login);
    assert.equal(document.activeElement.selectionEnd, document.activeElement.value.length);
});

test('incomplete Allegro purchase allows only notes and blocks address and payment editors', () => {
    const { api, sections, paid, document } = fixture({ incomplete: true });
    api.openSection('shipping');
    api.openSection('billing');
    api.openSection('pickup');
    for (const name of ['shipping', 'billing', 'pickup']) assert.equal(sections.get(name).classList.contains('is-editing'), false);
    api.openSection('order-info', 'customer_login');
    assert.equal(sections.get('order-info').classList.contains('is-editing'), false);
    api.openSection('order-info', 'notes');
    assert.equal(sections.get('order-info').classList.contains('is-editing'), true);
    assert.equal(sections.get('order-info').form.fields.customer_login.disabled, true);
    assert.equal(sections.get('order-info').form.fields.notes.disabled, false);
    assert.equal(document.activeElement, sections.get('order-info').form.fields.notes);
    api.openPaidEdit();
    assert.equal(paid.classList.contains('is-editing'), false);
});

test('cancelling one section does not close or reset another draft', () => {
    const { api, sections } = fixture();
    api.openSection('shipping');
    api.openSection('billing');
    sections.get('shipping').form.fields.shipping_name.value = 'shipping-draft';
    sections.get('billing').form.fields.billing_name.value = 'billing-draft';
    api.closeSection(sections.get('billing'));
    assert.ok(sections.get('shipping').classList.contains('is-editing'));
    assert.equal(sections.get('shipping').form.fields.shipping_name.value, 'shipping-draft');
    assert.equal(sections.get('billing').form.fields.billing_name.value, 'billing-original');
    assert.equal(sections.get('billing').classList.contains('is-editing'), false);
});

test('save updates only its own editor and cancel uses the latest server values', () => {
    const { api, sections } = fixture();
    api.openSection('shipping');
    api.openSection('billing');
    const shipping = sections.get('shipping');
    const billing = sections.get('billing');
    billing.form.fields.billing_name.value = 'unsaved-billing';
    api.syncEditableFields({ shipping_name: 'saved-shipping', billing_name: 'server-billing' }, shipping.form);
    api.closeSection(shipping);
    assert.equal(shipping.form.fields.shipping_name.value, 'saved-shipping');
    assert.equal(billing.form.fields.billing_name.value, 'unsaved-billing');
    assert.ok(billing.classList.contains('is-editing'));
    api.closeSection(billing);
    assert.equal(billing.form.fields.billing_name.value, 'server-billing');
});

test('saving a previously deferred form discards the older queued server values', () => {
    const { api, sections } = fixture();
    const billing = sections.get('billing');
    api.openSection('billing');
    billing.form.fields.billing_name.value = 'billing-draft';
    api.syncEditableFields({ billing_name: 'old-server-value' });
    api.syncEditableFields({ billing_name: 'newly-saved-value' }, billing.form);
    api.closeSection(billing);
    assert.equal(billing.form.fields.billing_name.value, 'newly-saved-value');
});

test('refresh preserves a selected country until that editor is cancelled', () => {
    const { api, sections } = fixture();
    const billing = sections.get('billing');
    api.openSection('billing');
    api.syncEditableFields({ billing_country_code: 'DE' });
    assert.equal(billing.form.fields.billing_country_code.value, 'PL');
    api.closeSection(billing);
    assert.equal(billing.form.fields.billing_country_code.value, 'DE');
    assert.ok(billing.form.fields.billing_country_code.options.find(option => option.value === 'DE').defaultSelected);
});

test('latest refresh wins for deferred values without touching the draft', () => {
    const { api, sections } = fixture();
    const pickup = sections.get('pickup');
    api.openSection('pickup');
    pickup.form.fields.pickup_point_name.value = 'pickup-draft';
    api.syncEditableFields({ pickup_point_name: 'server-first' });
    api.syncEditableFields({ pickup_point_name: 'server-latest' });
    assert.equal(pickup.form.fields.pickup_point_name.value, 'pickup-draft');
    api.closeSection(pickup);
    assert.equal(pickup.form.fields.pickup_point_name.value, 'server-latest');
});

test('payment editing coexists with address editing and defers payment HTML refresh', () => {
    const { api, sections, paid, infoTarget } = fixture();
    api.openSection('shipping');
    api.openPaidEdit();
    paid.fields.paid_amount.value = '42.00';
    api.applyOrderFragments({ fragments: { 'order-info': 'latest-info' } });
    api.syncEditableFields({ paid_amount: '15.00' });
    assert.equal(paid.fields.paid_amount.value, '42.00');
    assert.equal(infoTarget.innerHTML, 'original-info');
    assert.equal(api.deferredHtml(), 'latest-info');
    api.closePaidEdit();
    assert.equal(infoTarget.innerHTML, 'latest-info');
    assert.equal(paid.fields.paid_amount.value, '15.00');
    assert.ok(sections.get('shipping').classList.contains('is-editing'));
    assert.equal(api.deferredHtml(), null);
});

test('the saved payment form permits its own refreshed HTML', () => {
    const { api, paid, infoTarget } = fixture();
    api.openPaidEdit();
    api.applyOrderFragments({ fragments: { 'order-info': 'saved-info' } }, paid);
    assert.equal(infoTarget.innerHTML, 'saved-info');
    assert.equal(api.deferredHtml(), null);
});

test('inactive fields refresh normally and null values become empty', () => {
    const { api, sections } = fixture();
    api.syncEditableFields({ shipping_name: null, billing_name: 'server-billing' });
    assert.equal(sections.get('shipping').form.fields.shipping_name.value, '');
    assert.equal(sections.get('shipping').form.fields.shipping_name.defaultValue, '');
    assert.equal(sections.get('billing').form.fields.billing_name.value, 'server-billing');
});
