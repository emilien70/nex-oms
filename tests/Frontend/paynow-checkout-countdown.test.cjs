const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.resolve(__dirname, '../../resources/views/paynow/return.blade.php'), 'utf8');
const script = [...page.matchAll(/<script nonce="{{ \$nonce }}">([\s\S]*?)<\/script>/g)]
    .map(match => match[1])
    .find(source => source.includes("document.getElementById('pay-continue')"));
assert.ok(script);

function fixture() {
    const timer = { callback: null };
    const handlers = {};
    const redirects = [];
    const countdown = { hidden: true };
    const seconds = { textContent: '5' };
    const link = { href: 'https://client.example.test/zamowienie/479/aB12cD34eF/pay?continue=1' };
    let now = 1000;
    vm.runInNewContext(script, {
        Date: { now: () => now },
        document: {
            getElementById: id => ({ 'pay-continue': link, 'pay-countdown': countdown, 'pay-seconds': seconds })[id],
        },
        window: {
            location: { replace: url => redirects.push(url) },
            setInterval(callback, delay) {
                assert.equal(delay, 250);
                timer.callback = callback;
                return 1;
            },
            clearInterval(id) { assert.equal(id, 1); timer.callback = null; },
            addEventListener: (name, handler) => { handlers[name] = handler; },
        },
    });
    return {
        redirects, countdown, seconds, link,
        tick(elapsed) { now += elapsed; timer.callback?.(); },
        leave: () => handlers.pagehide(),
    };
}

test('the checkout counts down for five full seconds and redirects to its validated same-origin route once', () => {
    const f = fixture();
    assert.equal(f.countdown.hidden, false);
    f.tick(250);
    assert.equal(f.seconds.textContent, '5');
    f.tick(750);
    assert.equal(f.seconds.textContent, '4');
    f.tick(3999);
    assert.equal(f.seconds.textContent, '1');
    assert.equal(f.redirects.length, 0);
    f.tick(1);
    assert.deepEqual(f.redirects, [f.link.href]);
    f.tick(1000);
    assert.equal(f.redirects.length, 1);
});

test('leaving the page stops the countdown and a throttled tab uses elapsed time rather than tick count', () => {
    const leaving = fixture();
    leaving.tick(1000);
    leaving.leave();
    leaving.tick(10000);
    assert.deepEqual(leaving.redirects, []);

    const throttled = fixture();
    throttled.tick(11000);
    assert.deepEqual(throttled.redirects, [throttled.link.href]);
});
