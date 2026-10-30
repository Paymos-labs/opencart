<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;
use PaymosOpenCart\RefundAdapter;
use PaymosOpenCart\RefundIntentStore;

// SQL/service doubles exercise the production ports, not a native OpenCart package.
final class RefundTestDb
{
    public $tables = array();
    public $queries = array();
    public $transaction = false;
    public $owned = false;
    public $busy = false;
    public $engine = 'InnoDB';
    public $failCommit = null;
    public $failProjection = false;
    private $before;
    private $affected = 0;

    public function __construct()
    {
        $this->tables = array('paymos_refund_intent' => array(), 'paymos_refund_installation' => array(),
            'paymos_refund_projection' => array(), 'order_history' => array(), 'order' => array(array_merge(opencart_order(), array(
                'store_id' => '0', 'order_status_id' => '5', 'payment_code' => 'paymos.paymos', 'currency_value' => '1.00000000',
                'refunded_amount' => '0', 'stock' => 17,
            ))));
    }

    public function escape($value) { return addslashes((string) $value); }
    public function countAffected() { return $this->affected; }

    private function result(array $rows)
    {
        return (object) array('rows' => array_values($rows), 'row' => count($rows) ? reset($rows) : array(), 'num_rows' => count($rows));
    }

    private function fields($sql)
    {
        preg_match_all('/`(\w+)` = (NULL|\'(?:\\\\.|[^\'])*\'|-?[0-9]+)/', $sql, $matches, PREG_SET_ORDER);
        $data = array();
        foreach ($matches as $match) {
            $value = $match[2];
            $data[$match[1]] = $value === 'NULL' ? null : ($value[0] === "'" ? stripslashes(substr($value, 1, -1)) : (int) $value);
        }
        return $data;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        $this->affected = 0;
        if (strpos($sql, 'GET_LOCK(') !== false) {
            $this->owned = !$this->busy;
            return $this->result(array(array('acquired' => $this->owned ? '1' : '0')));
        }
        if (strpos($sql, 'IS_USED_LOCK(') !== false) {
            return $this->result(array(array('owned' => $this->owned ? '1' : '0')));
        }
        if (strpos($sql, 'RELEASE_LOCK(') !== false) {
            $this->owned = false;
            return $this->result(array());
        }
        if (strpos($sql, 'information_schema.TABLES') !== false) {
            return $this->result(array(array('ENGINE' => $this->engine)));
        }
        if ($sql === 'START TRANSACTION') {
            assertFalseValue($this->transaction, 'The store must not nest SQL transactions.');
            $this->before = $this->tables;
            $this->transaction = true;
            return true;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->transaction) {
                $this->tables = $this->before;
                $this->transaction = false;
            }
            return true;
        }
        if ($sql === 'COMMIT') {
            $nativeWrite = count($this->tables['paymos_refund_projection']) > count($this->before['paymos_refund_projection']);
            $failure = $nativeWrite ? $this->failCommit : null;
            $this->failCommit = $failure === null ? $this->failCommit : null;
            if ($failure === 'before') {
                throw new RuntimeException('Simulated death before native commit.');
            }
            $this->transaction = false;
            if ($failure === 'after') {
                throw new RuntimeException('Simulated death after native commit.');
            }
            return true;
        }
        if (strpos($sql, 'CREATE TABLE') === 0 || strpos($sql, 'SHOW COLUMNS') === 0 || strpos($sql, 'ALTER TABLE') === 0) {
            return $this->result(array(array('Field' => 'present')));
        }
        if (strpos($sql, 'INSERT IGNORE INTO `paymos_refund_installation`') === 0) {
            if (!count($this->tables['paymos_refund_installation'])) {
                preg_match("/VALUES \(1, '([^']+)'\)/", $sql, $match);
                $this->tables['paymos_refund_installation'][] = array('id' => 1, 'installation_id' => $match[1]);
            }
            return true;
        }
        if (preg_match('/^(INSERT INTO|UPDATE) `(\w+)` SET (.+)$/s', $sql, $match)) {
            $table = $match[2];
            $parts = explode(' WHERE ', $match[3], 2);
            $data = $this->fields($parts[0]);
            if ($match[1] === 'INSERT INTO') {
                if ($table === 'paymos_refund_projection') { $data += array('review_history_id' => null); }
                if ($table === 'paymos_refund_projection' && $this->failProjection) {
                    $this->failProjection = false;
                    throw new RuntimeException('Simulated marker write failure.');
                }
                if ($table === 'paymos_refund_intent') {
                    $data += array('withdrawal_id' => null, 'poll_count' => 0);
                    foreach ($this->tables[$table] as $row) {
                        foreach (array('operation_id', 'external_order_id', 'request_token') as $key) {
                            if ($row[$key] === $data[$key]) {
                                throw new RuntimeException('Duplicate unique ' . $key);
                            }
                        }
                    }
                }
                $this->tables[$table][] = $data;
                $this->affected = 1;
            } else {
                $where = $this->fields($parts[1]);
                foreach ($this->tables[$table] as &$row) {
                    $matches = true;
                    foreach ($where as $key => $value) {
                        if ((string) $row[$key] !== (string) $value) { $matches = false; }
                    }
                    if (strpos($parts[1], '`withdrawal_id` IS NULL') !== false && $row['withdrawal_id'] !== null) { $matches = false; }
                    if ($matches) {
                        if (strpos($parts[0], '`poll_count` = `poll_count` + 1') !== false) { $row['poll_count']++; }
                        $row = array_replace($row, $data);
                        $this->affected++;
                    }
                }
                unset($row);
            }
            return true;
        }
        if (preg_match('/^SELECT .+ FROM `(\w+)`(?: WHERE (.+)| LIMIT [0-9]+)?$/s', $sql, $match)) {
            $table = $match[1];
            $rows = isset($this->tables[$table]) ? $this->tables[$table] : array();
            $where = isset($match[2]) ? preg_split('/ ORDER BY | LIMIT | FOR UPDATE/', $match[2])[0] : '';
            $filters = $this->fields($where);
            if (strpos($where, '`next_due`') !== false) {
                unset($filters['next_due']);
                // Cursor comparisons are evaluated below, not as equality filters.
            }
            $rows = array_filter($rows, static function ($row) use ($filters) {
                foreach ($filters as $key => $value) {
                    if (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) { return false; }
                }
                return true;
            });
            if (preg_match('/LOCATE\(\'(.*?)\', `comment`\)/', $sql, $marker)) {
                $rows = array_filter($rows, static function ($row) use ($marker) { return strpos($row['comment'], stripslashes($marker[1])) !== false; });
            }
            if (preg_match('/`next_due` <= ([0-9]+)/', $where, $due)) {
                $rows = array_filter($rows, static function ($row) use ($due) { return $row['next_due'] <= (int) $due[1]; });
            }
            if (preg_match('/`next_due` > ([0-9]+).*`operation_id` > \'([^\']+)\'/s', $where, $after)) {
                $rows = array_filter($rows, static function ($row) use ($after) {
                    return $row['next_due'] > (int) $after[1] || ($row['next_due'] === (int) $after[1] && strcmp($row['operation_id'], $after[2]) > 0);
                });
            }
            if (strpos($sql, 'ORDER BY `next_due`') !== false) {
                usort($rows, static function ($a, $b) { return array($a['next_due'], $a['operation_id']) <=> array($b['next_due'], $b['operation_id']); });
            }
            if (preg_match('/LIMIT ([0-9]+)/', $sql, $limit)) { $rows = array_slice($rows, 0, (int) $limit[1]); }
            return $this->result($rows);
        }
        throw new RuntimeException('Unexpected fixture SQL: ' . $sql);
    }
}

final class RefundTestNative implements PaymosOpenCart\OpenCartAdapterInterface
{
    public $db;
    public $calls = array();
    public $logs = array();
    public $touchDate = false;
    public function __construct(RefundTestDb $db) { $this->db = $db; }
    public function getOrder($orderId) { return $this->db->tables['order'][0]; }
    public function orderAmount(array $order) { return PaymosOpenCart\OrderAmount::inOrderCurrency($order, new FakeOpenCartCurrency()); }
    public function log($message, array $context = array()) { $this->logs[] = array($message, $context); }
    public function addOrderHistory($orderId, $status, $comment, $notify = false)
    {
        assertTrueValue($this->db->transaction, 'History must share the native transaction.');
        assertSameValue((int) $this->db->tables['order'][0]['order_status_id'], $status, 'Business status must remain paid.');
        assertSameValue(false, $notify, 'Native notification must be suppressed.');
        $this->calls[] = array($orderId, $status, $comment, $notify);
        if ($this->touchDate) { $this->db->tables['order'][0]['date_modified'] = '2026-01-01 00:00:00'; }
        $this->db->tables['order_history'][] = array('order_history_id' => count($this->db->tables['order_history']) + 1,
            'order_id' => $orderId, 'order_status_id' => $status, 'comment' => $comment, 'notify' => 0);
    }
}

final class RefundTestServer implements Paymos\Http\TransportInterface
{
    public $db;
    public $requests = array();
    public $refunds = array();
    public $timeout = false;
    public $loseLock = false;
    public $tamper = array();
    public $canFull = true;
    public $canOver = true;
    public function __construct(RefundTestDb $db) { $this->db = $db; }
    public function invoice(): array
    {
        return array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid_over',
            'order' => array('external_id' => 'oc_42_0', 'amount' => '100', 'currency' => 'USD'),
            'refund_summary' => array('can_refund_full' => $this->canFull, 'can_refund_overpayment' => $this->canOver,
                'full_refund_amount' => '120', 'overpayment_refund_amount' => '20'));
    }
    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertFalseValue($this->db->transaction, 'No provider HTTP may run inside a database transaction.');
        assertContainsValue('HMAC-SHA256 pk_test_', $headers['Authorization'], 'Refund reads/writes must use the SDK signed client.');
        $path = parse_url($url, PHP_URL_PATH);
        $this->requests[] = array('method' => $method, 'path' => $path, 'body' => $body);
        if ($method === 'GET' && $path === '/v1/invoices/inv_123') {
            return new HttpResponse(200, json_encode($this->invoice()), array('Cache-Control' => 'private, no-store'));
        }
        if ($method === 'POST' && $path === '/v1/refunds') {
            $input = json_decode($body, true);
            $vectors = json_decode(file_get_contents(getenv('PAYMOS_CONFORMANCE_CONTRACT') ?: dirname(__DIR__, 2) . '/sdk-conformance/contract.json'), true)['vectors']['refunds'];
            assertSameValue(array_keys($vectors['create']['body']), array_keys($input), 'Only the common mode-only wire fields may leave the adapter.');
            $committed = false;
            foreach ($this->db->tables['paymos_refund_intent'] as $row) {
                $data = json_decode($row['payload'], true);
                if ($data['external_order_id'] === $input['external_order_id']) {
                    $committed = $data['cash_state'] === 'unknown';
                }
            }
            assertTrueValue($committed, 'A committed unknown intent must exist before cash HTTP.');
            $id = null;
            $refunded = 0;
            foreach ($this->refunds as $refund) {
                if ($refund['external_order_id'] === $input['external_order_id']) { $id = $refund['withdrawal_id']; }
                if ($refund['status'] === 'completed') { $refunded += (int) $refund['amount']; }
            }
            if ($id === null) {
                $id = 'wd_' . (count($this->refunds) + 1);
                $this->refunds[$id] = array('withdrawal_id' => $id, 'invoice_id' => $input['invoice_id'], 'project_id' => 'prj_123',
                    'external_order_id' => $input['external_order_id'], 'mode' => $input['mode'], 'is_test' => true,
                    'amount' => (string) ($input['mode'] === 'full' ? 120 - $refunded : max(20 - $refunded, 0)),
                    'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'TFirstSender', 'status' => 'created', 'created_at' => 1709000000);
            }
            if ($this->loseLock) { $this->db->owned = false; $this->loseLock = false; }
            if ($this->timeout) { $this->timeout = false; throw new RuntimeException('Timeout after provider acceptance.'); }
            return new HttpResponse(201, json_encode(array('withdrawal_id' => $id)), array());
        }
        if ($method === 'GET' && preg_match('!^/v1/refunds/(wd_[0-9]+)$!', $path, $match)) {
            return new HttpResponse(200, json_encode(array_replace($this->refunds[$match[1]], $this->tamper)), array('Cache-Control' => 'private, no-store'));
        }
        throw new RuntimeException('Unexpected fixture HTTP ' . $method . ' ' . $path);
    }
    public function posts(): array { return array_values(array_filter($this->requests, static function ($row) { return $row['method'] === 'POST'; })); }
}

function opencart_refund_fixture(): array
{
    $db = new RefundTestDb();
    $store = new RefundIntentStore($db);
    $native = new RefundTestNative($db);
    $server = new RefundTestServer($db);
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $server);
    $factory = static function () use ($client) { return $client; };
    $settings = opencart_settings();
    $settings += array('opencart_version' => '4.0.2.0', 'native_store_id' => 0, 'native_paid_statuses' => array(5), 'native_currency_scales' => array('USD' => 2),
        'payment_paymos_encryption_key' => 'fixture-key');
    $settings['payment_paymos_credentials'] = AesGcmEnvelope::seal(array('schema' => 1, 'environments' => array('sandbox' => array(
        'api_key' => 'pk_test_123', 'api_secret' => 'sk_test_123', 'project_id' => 'prj_123', 'webhook_secret' => 'whsec_sandbox',
        'capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => true,
    ))), 'fixture-key', 'paymos-opencart-credentials-v1');
    $invoices = new PaymosOpenCart\InMemoryInvoiceStore();
    $invoices->save(array('opencart_order_id' => 42, 'paymos_invoice_id' => 'inv_123', 'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox', 'project_id' => 'prj_123', 'amount' => '100', 'currency' => 'USD', 'status' => 'paid_over', 'payment_url' => 'https://checkout.paymos.test'));
    $adapter = new RefundAdapter($store, $invoices, $native, $settings, $factory);
    return compact('db', 'store', 'native', 'server', 'client', 'factory', 'settings', 'invoices', 'adapter');
}

function test_opencart_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    $f = opencart_refund_fixture();
    paymos_cms_ton_pin_contract($f['store'], 'opencart', $f['store']->installationId(), '42:0');
}

function opencart_refund_submit(array $f, string $mode = 'full', string $token = 'request-token-0001'): RefundIntent
{
    return $f['adapter']->submit(42, 0, array('mode' => $mode, 'request_token' => $token));
}

function opencart_refund_reconcile(array $f, RefundIntent $intent): string
{
    return $f['adapter']->reconcile($intent->operationId());
}

function opencart_refund_throws(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $error) {
        assertContainsValue($message, $error->getMessage(), 'The action must fail for the expected reason.');
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

function test_opencart_refund_full_waits_for_verified_get_and_keeps_native_status_stock_and_mail()
{
    $f = opencart_refund_fixture();
    $before = $f['db']->tables['order'];
    $intent = opencart_refund_submit($f);
    assertSameValue('created', $intent->cashState(), 'Accepted is not a native refund.');
    assertSameValue(array(), $f['native']->calls, 'No native mutation before completed.');
    assertSameValue('show_pending', opencart_refund_reconcile($f, $intent), 'Pending GET must not complete.');
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
    assertSameValue('apply_completed', opencart_refund_reconcile($f, $intent), 'Fresh completed GET must project.');
    assertSameValue('10000', $f['store']->getIntent($intent->operationId())->toArray()['projection']['nativeAmountMinor'], 'Full records the entire original native paid total.');
    assertSameValue('120', $f['store']->getIntent($intent->operationId())->observation()->principalDecimal(), 'Crypto full is independently server calculated.');
    assertSameValue($before, $f['db']->tables['order'], 'Order status, native refunded amount and stock remain unchanged.');
    assertSameValue(1, count($f['db']->tables['order_history']), 'Exactly one native cash record/history.');
    assertSameValue('resume_recovery', opencart_refund_reconcile($f, $intent), 'Duplicate callback recovers the exact marker.');
    assertSameValue(1, count($f['native']->calls), 'No repeated native effects.');
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f, 'overpayment', 'request-token-0002'); }, 'unresolved_or_refunded');
}

function test_opencart_refund_over20_then_full100_has_one_cash_note_and_original_full_native_total()
{
    $f = opencart_refund_fixture();
    $before = $f['db']->tables['order'];
    $over = opencart_refund_submit($f, 'overpayment');
    assertSameValue(null, $over->fullDraft(), 'Overpayment must never hold a financial draft.');
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'completed';
    assertSameValue('apply_completed', opencart_refund_reconcile($f, $over), 'Completed overpayment applies only the note.');
    $projection = $f['store']->getIntent($over->operationId())->toArray()['projection'];
    assertSameValue(null, $projection['nativeAmountMinor'], 'No native financial refund for overpayment.');
    assertSameValue(null, $projection['nativeCurrency'], 'No native financial currency for overpayment.');
    assertSameValue('20', $projection['cryptoObservationAmount'], 'The note records crypto overpayment.');
    assertSameValue($before, $f['db']->tables['order'], 'Overpayment leaves paid order, refunded0 and stock untouched.');
    $full = opencart_refund_submit($f, 'full', 'request-token-0002');
    assertSameValue('100', $full->observation()->principalDecimal(), 'After over20 the server supplies full100.');
    $f['server']->refunds[$full->withdrawalId()]['status'] = 'completed';
    assertSameValue('apply_completed', opencart_refund_reconcile($f, $full), 'Full follows the completed overpayment.');
    assertSameValue('10000', $f['store']->getIntent($full->operationId())->toArray()['projection']['nativeAmountMinor'], 'Full consumes the original native paid total.');
    assertSameValue(2, count($f['db']->tables['paymos_refund_projection']), 'One overpayment note and one full record.');
    assertSameValue($before, $f['db']->tables['order'], 'Neither action changes business status or stock.');
}

function test_opencart_refund_rejects_partial_inputs_and_native_ambiguity_before_cash_http()
{
    foreach (array('amount', 'currency', 'network', 'address', 'parts', 'draft', 'operation_id') as $field) {
        $f = opencart_refund_fixture();
        opencart_refund_throws(static function () use ($f, $field) {
            $f['adapter']->submit(42, 0, array('mode' => 'full', 'request_token' => 'request-token-0001', $field => '1'));
        }, 'mode_and_token_only');
        assertSameValue(0, count($f['server']->posts()), 'Caller-controlled financial fields cannot reach POST.');
    }
    foreach (array('store_id' => '7', 'payment_code' => 'cod.cod', 'total' => '99', 'order_status_id' => '7',
        'refunded_amount' => '1', 'currency_value' => '0') as $field => $value) {
        $f = opencart_refund_fixture();
        $f['db']->tables['order'][0][$field] = $value;
        opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f); }, 'native_payment_changed');
        assertSameValue(0, count($f['server']->posts()), 'Native manual/partial/foreign payment must fail before HTTP.');
    }
}

function test_opencart_refund_requires_admin_token_permission_post_and_confirmed_connection_capability()
{
    $good = array('user_token' => 'admin-token');
    foreach (array(array($good, $good, false, 'POST'), array(array('user_token' => 'wrong'), $good, true, 'POST'),
        array(array(), $good, true, 'POST'), array($good, $good, true, 'GET')) as $case) {
        opencart_refund_throws(static function () use ($case) {
            RefundAdapter::assertProtectedRequest(array('REQUEST_METHOD' => $case[3]), $case[0], $case[1], $case[2], true);
        }, $case[3] === 'GET' ? 'post_required' : 'access_denied');
    }
    RefundAdapter::assertProtectedRequest(array('REQUEST_METHOD' => 'POST'), $good, $good, true, true);
    $f = opencart_refund_fixture();
    unset($f['settings']['payment_paymos_credentials']);
    $f['adapter'] = new RefundAdapter($f['store'], $f['invoices'], $f['native'], $f['settings'], $f['factory']);
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f); }, 'reconnect_required');
    assertSameValue(0, count($f['server']->posts()), 'Legacy connection must not send refunds.');
    $f = opencart_refund_fixture(); $f['db']->engine = 'MyISAM';
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f); }, 'requires_innodb');
    assertSameValue(0, count($f['server']->posts()), 'Nontransactional storage is refused before cash.');
}

function test_opencart_refund_timeout_restart_replays_committed_mode_and_same_key_with_disabled_new_gate()
{
    $f = opencart_refund_fixture(); $f['server']->timeout = true;
    $intent = opencart_refund_submit($f, 'overpayment');
    assertSameValue('unknown', $intent->cashState(), 'Accepted timeout stays unknown.');
    assertSameValue(null, $intent->withdrawalId(), 'A callback must not invent the missing binding.');
    $f['store'] = new RefundIntentStore($f['db']);
    unset($f['settings']['payment_paymos_credentials']);
    $f['server']->canFull = false; $f['server']->canOver = false;
    $f['adapter'] = new RefundAdapter($f['store'], $f['invoices'], $f['native'], $f['settings'], $f['factory']);
    $replayed = opencart_refund_submit($f, 'overpayment');
    assertSameValue($intent->operationId(), $replayed->operationId(), 'Restart reuses durable operation.');
    assertSameValue('created', $replayed->cashState(), 'Same-key replay recovers accepted cash.');
    assertSameValue(1, count($f['server']->refunds), 'Exactly one provider refund.');
    assertSameValue($f['server']->posts()[0]['body'], $f['server']->posts()[1]['body'], 'Replay must use identical mode-only payload.');
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f, 'full'); }, 'request_conflict');
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f, 'full', 'request-token-0002'); }, 'unresolved_or_refunded');
}

function test_opencart_refund_store_token_identity_binding_and_observation_are_write_once()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $f['store']->withPaymentLock($intent->paymentKey(), static function () use ($f, $intent) {
        assertSameValue('same', $f['store']->bindWithdrawal($intent->operationId(), $intent->withdrawalId()), 'Same binding is idempotent.');
        assertSameValue('conflict', $f['store']->bindWithdrawal($intent->operationId(), 'wd_other'), 'A second ID must conflict.');
        assertSameValue($intent->withdrawalId(), $f['store']->getIntent($intent->operationId())->withdrawalId(), 'Binding cannot be overwritten.');
        $data = $intent->toArray();
        $data['mode'] = 'overpayment'; $data['full_draft'] = null; unset($data['payload_hash']);
        opencart_refund_throws(static function () use ($f, $data) { $f['store']->createIntent(new RefundIntent($data)); }, 'request_conflict');
        $wire = $f['server']->refunds[$intent->withdrawalId()]; $wire['first_sender'] = 'TDifferent';
        $changed = VerifiedRefund::fromApi($wire, $f['server']->invoice(), new DateTimeImmutable());
        opencart_refund_throws(static function () use ($f, $intent, $changed) { $f['store']->recordObservation($intent->operationId(), $changed); }, 'observation_conflict');
    });
    assertSameValue('native_review', $f['store']->getIntent($intent->operationId())->projectionState(), 'Conflict blocks native effects.');
}

function test_opencart_refund_signed_callback_is_only_a_scoped_signal_and_cannot_adopt_money_or_id()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $events = new PaymosOpenCart\InMemoryEventStore();
    $processor = new PaymosOpenCart\CallbackProcessor($f['native'], $f['invoices'], $events, $f['factory'], $f['adapter']);
    $data = $f['server']->refunds[$intent->withdrawalId()];
    $data['amount'] = '9999'; $data['status'] = 'completed';
    $payload = array('event_id' => 'evt_refund_1', 'event_type' => 'withdrawal.completed', 'version' => 1, 'occurred_at' => time(), 'data' => $data);
    $body = json_encode($payload);
    $response = $processor->handle($body, opencart_signed_header('whsec_sandbox', $body, time()), $f['settings']);
    assertSameValue(200, $response->statusCode(), 'Valid callback is handled through common verification.');
    assertSameValue(array(), $f['native']->calls, 'Spoofed completed callback cannot override fresh created GET.');
    assertSameValue('120', $f['store']->getIntent($intent->operationId())->observation()->principalDecimal(), 'Callback money is not evidence.');
    $payload['event_id'] = 'evt_refund_foreign'; $payload['data']['project_id'] = 'prj_foreign';
    $body = json_encode($payload);
    assertSameValue(400, $processor->handle($body, opencart_signed_header('whsec_sandbox', $body, time()), $f['settings'])->statusCode(), 'Wrong project must fail closed.');
    $payload['event_id'] = 'evt_refund_env'; $payload['data'] = $data; $payload['data']['is_test'] = false;
    $body = json_encode($payload);
    assertSameValue(400, $processor->handle($body, opencart_signed_header('whsec_sandbox', $body, time()), $f['settings'])->statusCode(), 'Wrong environment must fail closed.');
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $payload['event_id'] = 'evt_refund_2'; $payload['data'] = $data; $body = json_encode($payload);
    assertSameValue(200, $processor->handle($body, opencart_signed_header('whsec_sandbox', $body, time()), $f['settings'])->statusCode(), 'Fresh completed GET applies.');
    assertSameValue(1, count($f['native']->calls), 'One native write.');
    assertSameValue(200, $processor->handle($body, opencart_signed_header('whsec_sandbox', $body, time()), $f['settings'])->statusCode(), 'Duplicate event succeeds without effects.');
    assertSameValue(1, count($f['native']->calls), 'Dedup must survive repeated deliveries.');
}

function test_opencart_refund_mismatched_fresh_evidence_never_projects_or_overwrites_binding()
{
    foreach (array('mode' => 'overpayment', 'amount' => '121', 'currency' => 'USDC', 'network' => 'ERC20',
        'first_sender' => 'TDifferent', 'project_id' => 'prj_other', 'invoice_id' => 'inv_other',
        'withdrawal_id' => 'wd_other', 'external_order_id' => 'other-key', 'is_test' => false) as $key => $value) {
        $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
        $f['server']->tamper = array($key => $value, 'status' => 'completed');
        assertSameValue('show_review', opencart_refund_reconcile($f, $intent), 'Fresh identity/pinned money mismatch requires review.');
        assertSameValue(array(), $f['native']->calls, 'Mismatched GET cannot project.');
        assertSameValue($intent->withdrawalId(), $f['store']->getIntent($intent->operationId())->withdrawalId(), 'Pinned withdrawal remains unchanged.');
    }
}

function test_opencart_refund_crashes_rollback_or_recover_the_same_native_marker()
{
    foreach (array('before', 'after', 'marker') as $point) {
        $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
        $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
        if ($point === 'marker') { $f['db']->failProjection = true; } else { $f['db']->failCommit = $point; }
        assertSameValue('show_review', opencart_refund_reconcile($f, $intent), 'Crash remains explicit unknown/review.');
        assertSameValue($point === 'after' ? 1 : 0, count($f['db']->tables['order_history']), 'Native history and marker share commit/rollback.');
        assertSameValue($point === 'after' ? 'resume_recovery' : 'apply_completed', opencart_refund_reconcile($f, $intent), 'Recovery uses exact marker or safe rolled-back write.');
        assertSameValue(1, count($f['db']->tables['order_history']), 'Exactly one persisted history after recovery.');
        assertSameValue(1, count($f['server']->posts()), 'Native crash must not initiate another cash request.');
    }
}

function test_opencart_refund_reorg_old_mode_callbacks_and_recompletion_do_not_repeat_effects()
{
    $f = opencart_refund_fixture(); $over = opencart_refund_submit($f, 'overpayment');
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'completed'; opencart_refund_reconcile($f, $over);
    $full = opencart_refund_submit($f, 'full', 'request-token-0002');
    $f['server']->refunds[$full->withdrawalId()]['status'] = 'completed'; opencart_refund_reconcile($f, $full);
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'signed';
    assertSameValue('show_review', opencart_refund_reconcile($f, $over), 'Cash rewind retains history and requires review.');
    assertSameValue('completed', $f['store']->getIntent($full->operationId())->cashState(), 'Old overpayment cannot downgrade another mode/full.');
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'completed';
    assertSameValue('resume_recovery', opencart_refund_reconcile($f, $over), 'Recompletion reuses existing cash note.');
    $f['server']->refunds[$full->withdrawalId()]['status'] = 'signed'; opencart_refund_reconcile($f, $full);
    $f['server']->refunds[$full->withdrawalId()]['status'] = 'completed';
    assertSameValue('resume_recovery', opencart_refund_reconcile($f, $full), 'Full recompletion reuses full marker.');
    assertSameValue(4, count($f['native']->calls), 'Two original histories and one unique review note per mode.');
    opencart_refund_reconcile($f, $full);
    assertSameValue(4, count($f['native']->calls), 'Recompletion cannot repeat native refund or review notes.');
}

function test_opencart_refund_missing_or_partial_native_marker_is_review_and_never_a_second_write()
{
    foreach (array('missing_history', 'missing_module', 'changed_total') as $case) {
        $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
        $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed'; opencart_refund_reconcile($f, $intent);
        if ($case === 'missing_history') { $f['db']->tables['order_history'] = array(); }
        if ($case === 'missing_module') { $f['db']->tables['paymos_refund_projection'] = array(); }
        if ($case === 'changed_total') { $f['db']->tables['order'][0]['total'] = '80'; }
        assertSameValue('show_review', opencart_refund_reconcile($f, $intent), 'Ambiguous native history must fail closed.');
        assertSameValue(1, count($f['native']->calls), 'Ambiguity never invokes a second native write.');
    }
}

function test_opencart_refund_failed_or_cancelled_allows_only_a_new_explicit_action()
{
    foreach (array('failed', 'cancelled') as $state) {
        $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
        $f['server']->refunds[$intent->withdrawalId()]['status'] = $state; opencart_refund_reconcile($f, $intent);
        $same = opencart_refund_submit($f);
        assertSameValue($intent->operationId(), $same->operationId(), 'Old action retains terminal history.');
        assertSameValue(1, count($f['server']->posts()), 'Terminal action must never resubmit cash.');
        $new = opencart_refund_submit($f, 'full', 'request-token-0002');
        assertTrueValue($new->operationId() !== $intent->operationId(), 'Explicit new action receives its own immutable intent.');
        assertSameValue(array(), $f['native']->calls, 'Failure/cancellation never writes native completion.');
    }
}

function test_opencart_refund_lost_payment_lock_after_http_fences_binding_and_native_effects()
{
    $f = opencart_refund_fixture(); $f['server']->loseLock = true;
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f); }, 'lock_lost');
    $intent = new RefundIntent(json_decode($f['db']->tables['paymos_refund_intent'][0]['payload'], true));
    assertSameValue('unknown', $intent->cashState(), 'Lost-lock worker cannot acknowledge cash.');
    assertSameValue(null, $intent->withdrawalId(), 'Lost-lock worker cannot bind after HTTP.');
    assertSameValue(array(), $f['native']->calls, 'Lost-lock worker cannot project.');
    $again = opencart_refund_submit($f);
    assertSameValue($intent->operationId(), $again->operationId(), 'Recovery retains the same committed intent.');
    assertSameValue(1, count($f['server']->refunds), 'No second provider refund.');
}

function test_opencart_refund_effect_claim_is_committed_and_ambiguous_send_never_automatically_repeats()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed'; opencart_refund_reconcile($f, $intent);
    $f['store']->withPaymentLock($intent->paymentKey(), static function () use ($f, $intent) {
        assertSameValue(EffectClaim::CLAIMED, $f['store']->claimEffect($intent->operationId(), 'confirmation', 1)->kind(), 'First send claim wins.');
        assertFalseValue($f['db']->transaction, 'Started is committed before a caller can send.');
        $f['store']->recordEffectOutcome($intent->operationId(), 'confirmation', 1, 'unknown');
        assertSameValue(EffectClaim::ALREADY_STARTED, $f['store']->claimEffect($intent->operationId(), 'confirmation', 1)->kind(), 'Unknown is never automatically resent.');
    });
}

function test_opencart_refund_schedule_backoff_review_and_historical_fair_cursor()
{
    $f = opencart_refund_fixture(); $now = new DateTimeImmutable('now');
    $store = new RefundIntentStore($f['db'], static function () use (&$now) { return $now; });
    $f['store'] = $store; $f['adapter'] = new RefundAdapter($store, $f['invoices'], $f['native'], $f['settings'], $f['factory']);
    $intent = opencart_refund_submit($f);
    $row = $f['db']->tables['paymos_refund_intent'][0];
    assertSameValue(60, $row['next_due'] - $now->getTimestamp(), 'First due delay is one minute.');
    foreach (array(120, 300, 900, 3600, 3600) as $delay) {
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
        assertSameValue($delay, $f['db']->tables['paymos_refund_intent'][0]['next_due'] - $now->getTimestamp(), 'Expected retry backoff.');
    }
    $now = $now->modify('+25 hours');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
    assertSameValue('refund_unresolved_24h', $store->getIntent($intent->operationId())->toArray()['review_reason'], 'Unresolved >24h enters review.');
    assertSameValue(86400, $f['db']->tables['paymos_refund_intent'][0]['next_due'] - $now->getTimestamp(), 'Review history remains on daily sweep.');
    $row = $f['db']->tables['paymos_refund_intent'][0];
    $f['db']->tables['paymos_refund_intent'] = array();
    for ($i = 0; $i < 205; $i++) {
        $copy = $row; $data = json_decode($copy['payload'], true);
        $data['operation_id'] = sprintf('00000000-0000-4000-8000-%012d', $i); unset($data['external_order_id'], $data['payload_hash']);
        $copy['operation_id'] = $data['operation_id']; $copy['payload'] = json_encode((new RefundIntent($data))->toArray());
        $copy['next_due'] = $now->getTimestamp(); $f['db']->tables['paymos_refund_intent'][] = $copy;
    }
    $page1 = $store->listDue($now, 1000, null); $page2 = $store->listDue($now, 100, $page1->nextCursor());
    $page3 = $store->listDue($now, 100, $page2->nextCursor());
    assertSameValue(array(100, 100, 5), array(count($page1->items()), count($page2->items()), count($page3->items())), 'Due sweeps are capped and cursor-fair.');
    assertSameValue(null, $page3->nextCursor(), 'The historical sweep terminates.');
}

function test_opencart_refund_reconciler_recovers_missed_callback_with_disabled_new_submissions()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $f['db']->tables['paymos_refund_intent'][0]['next_due'] = 0;
    unset($f['settings']['payment_paymos_credentials']);
    $f['adapter'] = new RefundAdapter($f['store'], $f['invoices'], $f['native'], $f['settings'], $f['factory']);
    $runner = new PaymosOpenCart\Reconciler($f['invoices'], $f['native'], $f['factory'], $f['adapter']);
    assertSameValue(1, $runner->run($f['settings']), 'Existing reconciler includes due refunds.');
    assertSameValue(1, count($f['native']->calls), 'Read/recovery is independent of new capability gate.');
}

function test_opencart_refund_schema_upgrade_and_uninstall_preserve_operation_identity_and_history()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $installation = $f['store']->installationId();
    PaymosOpenCart\Installer::ensureRefundSchema($f['db']); PaymosOpenCart\Installer::ensureRefundSchema($f['db']);
    PaymosOpenCart\Installer::uninstall($f['db']);
    assertSameValue($installation, (new RefundIntentStore($f['db']))->installationId(), 'Upgrade/reinstall retains the installation identity.');
    assertSameValue($intent->externalOrderId(), (new RefundIntentStore($f['db']))->getIntent($intent->operationId())->externalOrderId(), 'Unresolved history survives uninstall.');
    foreach ($f['db']->queries as $sql) { assertFalseValue(strpos($sql, 'DROP TABLE') === 0, 'Uninstall must keep mappings while refund history exists.'); }
}

function test_opencart_refund_native_history_may_update_timestamp_without_changing_payment()
{
    $f = opencart_refund_fixture(); $intent = opencart_refund_submit($f);
    $f['native']->touchDate = true;
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
    assertSameValue('apply_completed', opencart_refund_reconcile($f, $intent), 'Native history may update date_modified.');
    assertSameValue('resume_recovery', opencart_refund_reconcile($f, $intent), 'Timestamp changes do not invalidate the original native draft.');
    assertSameValue(1, count($f['native']->calls), 'One native cash history.');
}

function test_opencart_refund_reorg_of_overpayment_while_full_is_pending_preserves_both_operations()
{
    $f = opencart_refund_fixture(); $over = opencart_refund_submit($f, 'overpayment');
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'completed'; opencart_refund_reconcile($f, $over);
    $full = opencart_refund_submit($f, 'full', 'request-token-0002');
    $f['server']->refunds[$over->withdrawalId()]['status'] = 'signed';
    opencart_refund_reconcile($f, $over); opencart_refund_reconcile($f, $over);
    assertSameValue('created', $f['store']->getIntent($full->operationId())->cashState(), 'The accepted full remains independent.');
    assertSameValue('100', $f['store']->getIntent($full->operationId())->observation()->principalDecimal(), 'Rewind cannot reprice accepted full100.');
    assertSameValue(2, count($f['native']->calls), 'One overpayment note and one unique rewind note.');
    opencart_refund_throws(static function () use ($f) { opencart_refund_submit($f, 'overpayment', 'request-token-0003'); }, 'unresolved_or_refunded');
}

function test_opencart_refund_callback_unknown_id_does_not_become_a_binding()
{
    $f = opencart_refund_fixture(); $f['server']->timeout = true;
    $intent = opencart_refund_submit($f, 'overpayment');
    $data = $f['server']->refunds['wd_1']; $data['withdrawal_id'] = 'wd_999';
    $event = new WebhookEvent(array('event_type' => 'withdrawal.completed', 'data' => $data));
    $f['adapter']->callback($event, 'sandbox');
    assertSameValue('wd_1', $f['store']->getIntent($intent->operationId())->withdrawalId(), 'A fresh same-key replay, not callback ID, supplies binding.');
    assertSameValue(1, count($f['server']->refunds), 'Unknown callback cannot create new cash.');
    $data['external_order_id'] = 'foreign-key'; $data['withdrawal_id'] = 'wd_unbound';
    opencart_refund_throws(static function () use ($f, $data) {
        $f['adapter']->callback(new WebhookEvent(array('event_type' => 'withdrawal.completed', 'data' => $data)), 'sandbox');
    }, 'unbound_requires_review');
}

function test_opencart_refund_controller_refuses_unprotected_routes_before_storage_or_http()
{
    foreach (array('refund', 'refundPanel') as $route) {
        foreach (array('token', 'permission', 'method', 'order', 'storage') as $case) {
            if ($route === 'refundPanel' && $case === 'method') { continue; }
            $app = new FakeOpenCartApplication('Admin', array());
            $app->set('request', (object) array('server' => array('REQUEST_METHOD' => $case === 'method' ? 'GET' : 'POST'),
                'get' => array('user_token' => $case === 'token' ? 'wrong' : 'secret', 'order_id' => $case === 'order' ? 'invalid' : '42'), 'post' => array()));
            $app->set('session', (object) array('data' => array('user_token' => 'secret')));
            $app->set('user', new class ($case !== 'permission') {
                private $allowed;
                public function __construct($allowed) { $this->allowed = $allowed; }
                public function hasPermission($action, $route) { return $this->allowed; }
            });
            $db = new class {
                public $calls = 0;
                public function query($sql) { $this->calls++; throw new RuntimeException('fixture_storage_unavailable'); }
            };
            $app->set('db', $db);
            $response = new class {
                public $output;
                public $headers = array();
                public function addHeader($header) { $this->headers[] = $header; }
                public function setOutput($output) { $this->output = $output; }
            };
            $app->set('response', $response); $app->set('log', new FakeOpenCartLog());
            http_response_code(503);
            (new \Opencart\Admin\Controller\Extension\Paymos\Payment\Paymos($app))->{$route}();
            assertSameValue(200, http_response_code(), 'Refund refusals and summary failures must return HTTP 200.');
            assertContainsValue('Content-Type: application/json', implode('\n', $response->headers), 'Every refusal must have a JSON response.');
            assertSameValue('Refund through Paymos is unavailable; the reason is in the OpenCart error log. If it is refund_reconnect_required, click Connect Paymos and approve the same project.', json_decode($response->output, true)['error'], 'Native controller must enforce token before DB/HTTP.');
            assertSameValue('refund_action_unavailable', json_decode($response->output, true)['reason'], 'The existing refusal code must be retained.');
            assertSameValue($case === 'storage' ? 1 : 0, $db->calls, 'Invalid requests must fail before storage.');
        }
    }
}

function test_opencart_refund_confirmed_capability_requires_mode_contract_version_and_boolean_gate()
{
    $f = opencart_refund_fixture();
    $panel = $f['adapter']->panel(42, 0);
    assertSameValue(null, $panel['unavailable_reason'], 'An available refund needs no refusal reason.');
    assertTrueValue($panel['can_full'], 'A confirmed connection permits full refunds.');
    assertTrueValue($panel['can_overpayment'], 'A confirmed connection permits overpayment refunds.');
    $payload = AesGcmEnvelope::open($f['settings']['payment_paymos_credentials'], 'fixture-key', 'paymos-opencart-credentials-v1');
    foreach (array(array('refund_contract_version' => 2), array('refunds_enabled' => 'yes'), array('capabilities' => array())) as $changes) {
        $bad = $payload;
        $bad['environments']['sandbox'] = array_replace($bad['environments']['sandbox'], $changes);
        $settings = $f['settings']; $settings['payment_paymos_credentials'] = AesGcmEnvelope::seal($bad, 'fixture-key', 'paymos-opencart-credentials-v1');
        assertFalseValue(PaymosOpenCart\Config::fromSettings($settings)->hasRefundCapability('sandbox'), 'Unknown/legacy handshake cannot authorize mode-only refunds.');
        $adapter = new RefundAdapter($f['store'], $f['invoices'], $f['native'], $settings, $f['factory']);
        $panel = $adapter->panel(42, 0);
        assertSameValue('refund_reconnect_required', $panel['unavailable_reason'], 'The panel must explain an unavailable connection with the existing reason code.');
        assertFalseValue($panel['can_full'], 'Unavailable full refunds must stay disabled.');
        assertFalseValue($panel['can_overpayment'], 'Unavailable overpayment refunds must stay disabled.');
        assertSameValue(0, count($f['server']->posts()), 'Inspecting an unavailable panel cannot send cash.');
    }
    $f['server']->timeout = true;
    opencart_refund_submit($f);
    $panel = $f['adapter']->panel(42, 0);
    assertSameValue('refund_payment_unresolved_or_refunded', $panel['unavailable_reason'], 'Existing guards retain their reason.');
    assertSameValue('unknown', $panel['operations'][0]['cash_state'], 'Pending recovery remains visible.');
    $adapter = new RefundAdapter($f['store'], $f['invoices'], $f['native'], $settings, $f['factory']);
    assertSameValue('refund_reconnect_required', $adapter->panel(42, 0)['unavailable_reason'], 'A missing capability must remain visible even with pending operations.');
}

function test_opencart_refund_native_cron_registration_is_idempotent_and_preserves_admin_disable()
{
    $db = new RefundTestDb();
    $cron = new class {
        public $entries = array();
        public function getCronByCode($code) { return isset($this->entries[$code]) ? $this->entries[$code] : array(); }
        public function addCron($code, $description, $cycle, $action, $status) {
            assertSameValue('minute', $cycle, 'Native cron ticks once per minute; the store controls per-intent backoff.');
            $this->entries[$code] = compact('description', 'cycle', 'action', 'status');
        }
    };
    PaymosOpenCart\Installer::registerRefundCron($cron, $db);
    PaymosOpenCart\Installer::registerRefundCron($cron, $db);
    assertSameValue(1, count($cron->entries), 'Upgrade/reconnect must not create duplicate native cron entries.');
    $cron->entries['paymos_refunds']['status'] = false;
    PaymosOpenCart\Installer::registerRefundCron($cron, $db);
    assertSameValue(false, $cron->entries['paymos_refunds']['status'], 'Explicit native admin disable is preserved.');
    $cron->entries['paymos_refunds']['action'] = 'custom/action';
    opencart_refund_throws(static function () use ($cron, $db) { PaymosOpenCart\Installer::registerRefundCron($cron, $db); }, 'cron_registration_conflict');
    assertSameValue('custom/action', $cron->entries['paymos_refunds']['action'], 'Ambiguous native cron must not be overwritten.');
}

function test_opencart_refund_native_cron_rejects_invalid_dispatch_context_before_storage()
{
    $app = new FakeOpenCartApplication('Catalog', array());
    opencart_refund_throws(static function () use ($app) {
        (new \Opencart\Catalog\Controller\Extension\Paymos\Payment\Paymos($app))->refundCron(0, 'foreign', 'minute', '', '');
    }, 'cron_context_invalid');
}
