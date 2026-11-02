const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../../resources/views/orders/show.blade.php'), 'utf8');
const start = source.indexOf('                let hasSmsMessages =');
const end = source.indexOf('                const newEmailIdempotencyKey =', start);
assert.ok(start !== -1 && end > start, 'Shared message empty-state handler must exist');
const script = source.slice(start, end);

function history(emailCount = 0, smsCount = 0) {
    const listeners = new Map();
    const state = {hidden: false, emailCount};
    vm.runInNewContext(script, {
        emailExchange: {
            querySelector: () => smsCount ? {} : null,
            addEventListener: (name, handler) => listeners.set(name, handler),
        },
        emailMessages: {querySelector: () => state.emailCount ? {} : null},
        emailEmpty: {classList: {toggle: (name, hidden) => { assert.equal(name, 'd-none'); state.hidden = hidden; }}},
    });
    return {state, updateSms: hasMessages => listeners.get('nexoms:sms-history-updated')({detail: {hasMessages}})};
}

test('first SMS hides the empty notice without reloading the page', () => {
    const page = history();
    assert.equal(page.state.hidden, false);
    page.updateSms(true);
    assert.equal(page.state.hidden, true);
});

test('initial SMS-only and email-only histories hide the notice', () => {
    assert.equal(history(0, 1).state.hidden, true);
    assert.equal(history(1, 0).state.hidden, true);
    assert.equal(history(1, 1).state.hidden, true);
});

test('an empty SMS refresh cannot show the notice over existing email history', () => {
    const page = history(1, 1);
    page.updateSms(false);
    assert.equal(page.state.hidden, true);
});

test('notice returns only when both histories are empty', () => {
    const page = history(0, 1);
    page.updateSms(false);
    assert.equal(page.state.hidden, false);
    page.updateSms(true);
    page.state.emailCount = 1;
    page.updateSms(false);
    assert.equal(page.state.hidden, true);
});
