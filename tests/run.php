<?php

declare(strict_types=1);

// Controller fixtures assert native HTTP status codes after earlier test results.
// Keep CLI progress output buffered so PHP does not send headers before those assertions.
ob_start();

require __DIR__ . '/bootstrap.php';

// Compile every plugin source file, not just the ones the tests touch: the
// platform loads all of them, so a defect in an untested file must fail HERE.
$_srcRoot = PAYMOS_OPENCART_PLUGIN_DIR;
$_iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $_srcRoot,
    FilesystemIterator::SKIP_DOTS
));
$_files = array();
foreach ($_iter as $_f) {
    if (!$_f->isFile() || $_f->getExtension() !== 'php') {
        continue;
    }
    $_rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($_f->getPathname(), strlen($_srcRoot)));
    if (preg_match('!(^|/)(tests?|vendor|node_modules|\.github)(/|$)!', $_rel)) {
        continue;
    }
    $_files[] = $_f->getPathname();
}
sort($_files);

// Constants the harness itself pre-defined may be re-defined by the plugin's
// entry file (the platform loads that file FIRST in production). Tolerate
// exactly those collisions; anything else — including a real duplicate between
// two plugin files — still fails the run.
$_predefined = get_defined_constants(true);
$_predefined = isset($_predefined['user']) ? $_predefined['user'] : array();
foreach ($_files as $_file) {
    set_error_handler(static function ($severity, $message, $file, $line) use ($_predefined) {
        if (preg_match('/Constant (\\w+) already defined/', $message, $_m)
            && array_key_exists($_m[1], $_predefined)) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require_once $_file;
    } finally {
        restore_error_handler();
    }
}
unset($_srcRoot, $_iter, $_files, $_file, $_rel, $_f, $_predefined);
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/GatewayCheckoutTest.php';
require __DIR__ . '/EventStoreTest.php';
require __DIR__ . '/CallbackProcessorTest.php';
require __DIR__ . '/PaidLateCallbackTest.php';
require __DIR__ . '/InvoiceStorePaidLateNotedTest.php';
require __DIR__ . '/ReconcilerTest.php';
require __DIR__ . '/InstallerTest.php';
require __DIR__ . '/RefundAdapterTest.php';
require __DIR__ . '/NativeRefundGuardTest.php';

$count = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    paymos_opencart_reset_test_state();
    $function();
    paymos_opencart_reset_test_state();
    $count++;
    echo "PASS {$function}\n";
}

paymos_opencart_reset_test_state();

$panelChecks = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const template = input.template;
const script = input.script;
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
JS;
$template = file_get_contents(PAYMOS_OPENCART_PLUGIN_DIR . 'admin/view/template/payment/paymos.twig');
preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $template, $scripts);
$script = preg_replace('~{{.*?}}~s', 'fixture', implode("\n", $scripts[1]));
$process = proc_open(array('node', '-e', $panelChecks), array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the refund panel JavaScript checks.');
}
fwrite($pipes[0], json_encode(array('template' => $template, 'script' => $script), JSON_THROW_ON_ERROR));
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
$errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
assertSameValue(0, proc_close($process), 'Refund panel JavaScript checks failed: ' . $errors);
echo $output;

echo "OK {$count} tests\n";

require __DIR__ . '/refund-native.php';

ob_end_flush();
