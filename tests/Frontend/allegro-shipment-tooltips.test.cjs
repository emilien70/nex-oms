const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/orders/show.blade.php'), 'utf8');
const start = page.indexOf('const initializeAllegroShipmentTooltips =');
const end = page.indexOf("document.querySelectorAll('[data-customer-portal-help]')", start);
assert.ok(start > 0 && end > start);

test('shipment tooltips initialize as plain text and dispose before replacement', () => {
    const elements = [{}, {}];
    const calls = [];
    const root = { querySelectorAll: selector => {
        assert.equal(selector, '[data-allegro-shipment-tooltip]');
        return elements;
    } };
    const context = vm.createContext({
        document: root,
        window: { bootstrap: { Tooltip: {
            getOrCreateInstance: (element, options) => calls.push({ element, options }),
            getInstance: element => ({ dispose: () => calls.push({ disposed: element }) }),
        } } },
    });
    vm.runInContext(`${page.slice(start, end)}; globalThis.initialize = initializeAllegroShipmentTooltips; globalThis.dispose = disposeAllegroShipmentTooltips;`, context);
    assert.equal(calls.length, 2);
    for (const call of calls) {
        assert.equal(call.options.html, false);
        assert.equal(call.options.container, 'body');
    }
    context.dispose(root);
    assert.equal(calls.filter(call => call.disposed).length, 2);
    context.initialize(root);
    assert.equal(calls.length, 6);
});

test('shipment tooltips do not break rendering when Bootstrap is unavailable', () => {
    const root = { querySelectorAll: () => [{}] };
    vm.runInNewContext(page.slice(start, end), { document: root, window: {} });
});

test('both row polling and state fragments reinitialize shipment tooltips', () => {
    const row = page.slice(page.indexOf('const replaceShipmentRow ='), page.indexOf('const shipmentErrorMessage ='));
    const fragments = page.slice(page.indexOf('const applyOrderFragments ='), page.indexOf('const applyOrderState ='));
    assert.ok(row.includes('disposeAllegroShipmentTooltips(currentRow)'));
    assert.ok(row.includes('initializeAllegroShipmentTooltips(newRow)'));
    assert.ok(fragments.includes('disposeAllegroShipmentTooltips(target)'));
    assert.ok(fragments.includes('initializeAllegroShipmentTooltips(target)'));
});
