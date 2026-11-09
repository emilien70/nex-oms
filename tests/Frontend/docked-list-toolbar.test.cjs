const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.resolve(__dirname, '../../resources/views/orders/partials/docked-list-toolbar-script.blade.php'), 'utf8')
    .replace(/<\/?script>/g, '');

function harness(reducedMotion = false) {
    const events = {};
    const animations = [];
    const variables = {};
    const bounds = { toolbarBottom: 180, tableTop: 196, tableBottom: 1500 };
    const spacer = { style: { removeProperty: key => delete spacer.style[key] } };
    const table = {
        getBoundingClientRect: () => ({ top: bounds.tableTop, bottom: bounds.tableBottom, left: 250, right: 1250 }),
    };
    const dock = {
        hidden: true,
        inert: true,
        offsetHeight: 53,
        style: { setProperty: (key, value) => variables[key] = value },
        querySelectorAll: () => [],
        animate(frames, options) {
            const animation = { frames, options, cancel() { this.cancelled = true; } };
            animations.push(animation);
            return animation;
        },
    };
    const page = {
        querySelector: selector => ({
            '[data-list-toolbar-dock]': dock,
            '[data-list-toolbar-spacer]': spacer,
            '.orders-table-card': table,
        })[selector],
    };
    const slot = {
        closest: () => page,
        getBoundingClientRect: () => ({ bottom: bounds.toolbarBottom }),
    };
    const container = { querySelector: () => slot };
    page.parentElement = container;
    let frame;
    vm.runInNewContext(script, {
        ResizeObserver: class { observe() {} disconnect() {} },
        MutationObserver: class { observe() {} },
        document: {
            documentElement: { clientWidth: 1280, clientHeight: 720 },
            querySelector: selector => selector === '.app-topbar'
                ? { getBoundingClientRect: () => ({ bottom: 56 }) } : slot,
            addEventListener: (name, fn) => events[name] = fn,
        },
        window: {
            addEventListener: (name, fn) => events[name] = fn,
            matchMedia: () => ({ matches: reducedMotion }),
            requestAnimationFrame: fn => { frame = fn; return 1; },
            getComputedStyle: () => ({ opacity: '0.5', transform: 'translateY(50%)' }),
        },
    });
    events.DOMContentLoaded();
    const scroll = bottom => { bounds.toolbarBottom = bottom; events.scroll(); frame(); };
    return { dock, spacer, animations, variables, scroll };
}

test('a separate toolbar appears at table width after the upper toolbar scrolls away', () => {
    const { dock, spacer, animations, variables, scroll } = harness();
    scroll(57);
    assert.equal(dock.hidden, true);
    scroll(56);
    assert.equal(dock.hidden, false);
    assert.equal(dock.inert, false);
    assert.equal(variables['--list-toolbar-left'], '250px');
    assert.equal(variables['--list-toolbar-width'], '1000px');
    assert.equal(spacer.style.height, '53px');
    assert.equal(animations[0].options.duration, 250);
    animations[0].onfinish();

    scroll(180);
    assert.equal(dock.inert, true);
    assert.equal(dock.hidden, false);
    assert.equal(animations[1].options.duration, 250);
    animations[1].onfinish();
    assert.equal(dock.hidden, true);
    assert.equal(spacer.style.height, undefined);
});

test('changing scroll direction mid-animation continues from the current position', () => {
    const { dock, animations, scroll } = harness();
    scroll(0);
    scroll(180);
    assert.equal(animations[0].cancelled, true);
    assert.equal(animations[1].frames[0].transform, 'translateY(50%)');
    animations[0].onfinish();
    assert.equal(dock.hidden, false);
    animations[1].onfinish();
    assert.equal(dock.hidden, true);
});

test('reduced motion shows and hides the lower toolbar without animation', () => {
    const { dock, animations, scroll } = harness(true);
    scroll(0);
    assert.equal(dock.hidden, false);
    scroll(180);
    assert.equal(dock.hidden, true);
    assert.equal(animations.length, 0);
});
