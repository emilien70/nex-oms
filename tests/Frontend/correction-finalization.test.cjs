const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../../resources/views/invoices/corrections/_finalize-modal.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function fixture(valid = true) {
    const button = { disabled: false };
    let submit;
    const form = {
        dataset: {},
        reportValidity: () => valid,
        querySelectorAll: () => [button],
        addEventListener(name, listener) { assert.equal(name, 'submit'); submit = listener; },
    };
    vm.runInNewContext(script, {
        document: {
            addEventListener(name, listener) { assert.equal(name, 'DOMContentLoaded'); listener(); },
            querySelector(selector) { assert.equal(selector, '[data-correction-finalize-form]'); return form; },
        },
    });
    return {
        form, button,
        submit() {
            let prevented = false;
            submit({ preventDefault() { prevented = true; } });
            return prevented;
        },
    };
}

test('missing explicit OutsideKsef confirmation never submits or disables the button', () => {
    const state = fixture(false);
    assert.equal(state.submit(), true);
    assert.equal(state.button.disabled, false);
    assert.equal(state.form.dataset.submitting, undefined);
});

test('valid confirmation permits one request and blocks subsequent clicks', () => {
    const state = fixture();
    assert.equal(state.submit(), false);
    assert.equal(state.button.disabled, true);
    assert.equal(state.submit(), true);
});

test('confirmation is unchecked, required and posted separately from editing', () => {
    assert.match(source, /<input[^>]+type="checkbox"[^>]+name="confirm_outside_ksef"[^>]+required>/);
    assert.doesNotMatch(source, /<input[^>]+name="confirm_outside_ksef"[^>]+checked/);
    assert.match(source, /method="POST"/);
    assert.match(source, /@csrf/);
    assert.match(source, /name="expected_lock_version"/);
    assert.match(source, /name="expected_ksef_environment"/);
    assert.match(source, /Operacji nie można cofnąć\./);
});

test('absent finalization form has no side effects', () => {
    vm.runInNewContext(script, {
        document: { addEventListener: (_, listener) => listener(), querySelector: () => null },
    });
});
