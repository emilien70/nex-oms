const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/layouts/app.blade.php'), 'utf8');
const source = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1])
    .find(script => script.includes('const initializePrinterControls ='));
assert.ok(source);

function fixture() {
    const listeners = new Map();
    const tooltips = new Map();
    const wrapper = { dataset: { bsTitle: 'Drukuj' } };
    const button = {
        expanded: false,
        keyboardFocus: false,
        matches(selector) {
            return selector === '[data-nex-printer-toggle]' || (selector === ':focus-visible' && this.keyboardFocus);
        },
        closest: selector => selector === '[data-nex-printer-tooltip]' ? wrapper : null,
        getAttribute: name => name === 'aria-expanded' ? String(button.expanded) : null,
    };
    const menuItem = { matches: () => false };
    const document = {
        querySelector: () => null,
        querySelectorAll: selector => selector === '[data-nex-printer-tooltip]' ? [wrapper] : [],
        addEventListener(name, listener, capture = false) {
            if (!listeners.has(name)) listeners.set(name, []);
            listeners.get(name).push({ listener, capture });
        },
    };
    const bootstrap = {
        Tooltip: {
            getOrCreateInstance(element, options) {
                if (!tooltips.has(element)) {
                    const tooltip = {
                        options,
                        visible: false,
                        show() { this.visible = true; },
                        hide() { this.visible = false; },
                        dispose() { this.hide(); tooltips.delete(element); },
                    };
                    tooltips.set(element, tooltip);
                }
                return tooltips.get(element);
            },
            getInstance: element => tooltips.get(element),
        },
    };
    const window = { bootstrap };
    vm.runInNewContext(source, { document, window, bootstrap });
    const fire = (name, target = button, attributes = {}) => {
        for (const { listener, capture } of listeners.get(name)) {
            if (name === 'pointerenter' || name === 'pointerleave') assert.equal(capture, true);
            listener({ target, pointerType: 'mouse', ...attributes });
        }
    };
    fire('DOMContentLoaded');

    return { fire, button, menuItem, tooltip: () => tooltips.get(wrapper), window, document };
}

test('the printer tooltip follows the button, not the printer menu', () => {
    const { fire, menuItem, tooltip } = fixture();
    assert.equal(tooltip().options.trigger, 'manual');
    fire('pointerenter');
    assert.equal(tooltip().visible, true);
    fire('pointerleave');
    fire('pointerenter', menuItem);
    assert.equal(tooltip().visible, false);
});

test('opening the dropdown hides the tooltip immediately and prevents it reopening over the choices', () => {
    const { fire, button, tooltip } = fixture();
    assert.equal(tooltip().options.animation, false);
    fire('pointerenter');
    fire('show.bs.dropdown');
    assert.equal(tooltip().visible, false);
    button.expanded = true;
    fire('pointerenter');
    assert.equal(tooltip().visible, false);
    button.expanded = false;
    fire('pointerenter');
    assert.equal(tooltip().visible, true);
});

test('keyboard focus still works, touch does not show a tooltip, and refreshed controls work', () => {
    const { fire, button, tooltip, window, document } = fixture();
    fire('pointerenter', button, { pointerType: 'touch' });
    fire('focusin');
    assert.equal(tooltip().visible, false);
    button.keyboardFocus = true;
    fire('focusin');
    assert.equal(tooltip().visible, true);
    fire('focusout');
    assert.equal(tooltip().visible, false);
    window.nexOmsDisposePrinterControls(document);
    assert.equal(tooltip(), undefined);
    window.nexOmsInitializePrinterControls(document);
    fire('focusin');
    assert.equal(tooltip().visible, true);
});
