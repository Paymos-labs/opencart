const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const template = fs.readFileSync(path.join(__dirname, '..', 'admin/view/template/payment/paymos.twig'), 'utf8');
const script = [...template.matchAll(/<script\b[^>]*>(.*?)<\/script>/gs)]
  .map(match => match[1]).join('\n').replace(/{{.*?}}/gs, 'fixture');
new vm.Script(script);

function element(id) {
  const tag = template.match(new RegExp('<[^>]+\\bid="' + id + '"[^>]*>'));
  return {
    textContent: '', hidden: !!tag && /\bhidden\b/.test(tag[0]), disabled: false,
    dataset: {}, listeners: {}, children: [], value: '42',
    addEventListener(type, handler) { this.listeners[type] = handler; },
    appendChild(child) { this.children.push(child); }
  };
}
const elements = {};
const get = id => elements[id] || (elements[id] = element(id));
const buttons = ['full', 'overpayment'].map(mode => {
  const button = element(''); button.dataset.refundMode = mode; button.disabled = true; return button;
});
const showButton = element('');
const form = get('form-paymos-refund-order');
form.querySelector = () => showButton;
const error = get('paymos-refund-error');
assert.match(template, /<div[^>]+id="paymos-refund-error"[^>]+class="[^"]*alert-danger[^"]*"[^>]+role="alert"[^>]+hidden/);
assert.equal(error.hidden, true);
const replies = [];
const storage = new Map();
let storageUnavailable = false;
vm.runInNewContext(script, {
  document: {getElementById: get, querySelectorAll: () => buttons, createElement: () => element('')},
  URLSearchParams,
  window: {
    localStorage: {
      getItem(key) { if (storageUnavailable) throw new Error('storage unavailable'); return storage.get(key) || null; },
      setItem(key, value) { storage.set(key, value); }, removeItem(key) { storage.delete(key); }
    },
    crypto: {getRandomValues(bytes) { bytes.fill(1); }}
  },
  fetch(url, options) {
    const reply = replies.shift();
    assert.ok(reply, 'Unexpected request: ' + url);
    assert.equal(options ? options.method : 'GET', reply.method);
    if (reply.reject) return Promise.reject(new Error('network unavailable'));
    return Promise.resolve({status: reply.status || 200, json: () => Promise.resolve(reply.json)});
  }
});
const tick = () => new Promise(setImmediate);
const available = {summary: {}, operations: [], can_full: true, can_overpayment: true, unavailable_reason: null};
const refusal = 'Refund through Paymos is unavailable; the reason is in the OpenCart error log. If it is refund_reconnect_required, click Connect Paymos and approve the same project.';
const queue = (method, json, status) => replies.push({method, json, status});
const click = () => { assert.equal(buttons[0].disabled, false); buttons[0].listeners.click(); };
async function show(data) {
  queue('GET', {success: data}); form.listeners.submit({preventDefault() {}}); await tick();
}
function visibleError(message) {
  assert.equal(error.hidden, false);
  assert.equal(error.textContent, message);
  assert.equal(get('paymos-refund-state').textContent, '');
  assert.equal(get('paymos-refund-order').disabled, false);
  assert.equal(showButton.disabled, false);
}

(async () => {
  queue('GET', {error: refusal});
  form.listeners.submit({preventDefault() {}}); await tick();
  visibleError(refusal);
  assert.ok(buttons.every(button => button.disabled));

  await show(available);
  assert.equal(error.hidden, true);
  assert.equal(error.textContent, '');
  assert.equal(get('paymos-refund-state').textContent, 'Refund details loaded.');
  assert.ok(buttons.every(button => !button.disabled));

  queue('POST', {error: refusal}); queue('GET', {success: available});
  click(); await tick();
  visibleError(refusal);
  assert.ok(buttons.every(button => !button.disabled));

  const escapedRefusal = '<b>Server refusal</b>';
  queue('POST', {error: escapedRefusal}, 403); queue('GET', {error: refusal});
  click(); assert.equal(error.hidden, true); await tick();
  visibleError(escapedRefusal);
  assert.equal(error.children.length, 0);
  assert.ok(buttons.every(button => button.disabled));

  await show(available);
  queue('POST', {error: refusal}); replies.push({method: 'GET', reject: true});
  click(); await tick();
  visibleError(refusal);
  assert.ok(buttons.every(button => button.disabled));

  await show(available);
  replies.push({method: 'POST', reject: true}); queue('GET', {success: available});
  click(); await tick();
  visibleError('The refund request failed. Check the refunds below before trying again.');
  assert.ok(buttons.every(button => !button.disabled));

  queue('POST', {success: {cash_state: 'created'}}); queue('GET', {success: available});
  click(); await tick();
  assert.equal(error.hidden, true);
  assert.equal(get('paymos-refund-state').textContent, 'Refund details loaded.');

  storageUnavailable = true;
  queue('GET', {success: available}); click(); await tick();
  visibleError('The refund request was not sent. Reload the page and try again.');
  assert.ok(buttons.every(button => !button.disabled));
  storageUnavailable = false;

  const pending = {mode: 'full', cash_state: 'unknown', request_token: 'pending-request-token'};
  await show({...available, can_full: false, can_overpayment: false, operations: [pending],
    unavailable_reason: 'refund_reconnect_required'});
  assert.equal(get('paymos-refund-unavailable').hidden, false);
  assert.equal(get('paymos-refund-unavailable').textContent, 'refund_reconnect_required');
  assert.ok(buttons.every(button => button.disabled));
  assert.equal(error.hidden, true);

  const recoverable = {...available, can_full: false, can_overpayment: false, operations: [pending],
    unavailable_reason: 'refund_payment_unresolved_or_refunded'};
  await show(recoverable);
  assert.equal(buttons[0].disabled, false);
  assert.equal(buttons[1].disabled, true);
  queue('POST', {error: refusal}); queue('GET', {success: recoverable});
  click(); await tick();
  visibleError(refusal);
  assert.equal(buttons[0].disabled, false);

  await show(available);
  assert.equal(get('paymos-refund-unavailable').hidden, true);
  assert.equal(get('paymos-refund-unavailable').textContent, '');
  assert.equal(error.hidden, true);
  assert.equal(replies.length, 0);
  process.stdout.write('PASS refund_panel_javascript\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
