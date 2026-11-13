<?php

declare(strict_types=1);

use PaymosOpenCart\InvoiceStore;
use PaymosOpenCart\Migrations;

/**
 * invoice-late-payment, late-44: the paid-late marker lives in the shop's own table, so a redelivery after a
 * crash (note written, dedup not yet committed) still sees it. Runs the real InvoiceStore/Migrations SQL
 * against a recording database.
 */
final class FakeOpenCartStoreDb
{
    /** @var array<int, string> */
    public $queries = array();
    /** @var bool */
    public $hasMarkerColumn = false;
    /** @var array<string, mixed>|null */
    public $existing = null;
    public $connectionId = '41';
    public $lockOwner = null;
    public $beforeAcquire = null;

    public function escape($value)
    {
        return addslashes((string) $value);
    }

    public function query($sql)
    {
        $sql = (string) $sql;
        $this->queries[] = $sql;
        $result = new stdClass();
        $result->num_rows = 0;
        $result->row = array();
        $result->rows = array();

        if (strpos($sql, 'SELECT DATABASE()') === 0) {
            $result->row = array('database_name' => 'shop_test');
        } elseif (strpos($sql, 'SELECT GET_LOCK(') === 0) {
            if ($this->beforeAcquire !== null) { call_user_func($this->beforeAcquire); }
            $acquired = $this->lockOwner === null ? 1 : 0;
            if ($acquired) { $this->lockOwner = $this->connectionId; }
            $result->row = array('acquired' => $acquired, 'owner_id' => $this->connectionId);
        } elseif (strpos($sql, 'SELECT IS_USED_LOCK(') === 0) {
            $result->row = array('owner_id' => $this->lockOwner, 'connection_id' => $this->connectionId);
        } elseif (strpos($sql, 'SELECT IF(CONNECTION_ID()') === 0) {
            if (strpos($sql, "CONNECTION_ID() = '" . $this->connectionId . "'") !== false && $this->lockOwner === $this->connectionId) {
                $this->lockOwner = null;
            }
        } elseif (stripos($sql, 'SHOW COLUMNS') !== false && $this->hasMarkerColumn) {
            $result->num_rows = 1;
            $result->row = array('Field' => 'paid_late_noted');
        } elseif (stripos($sql, 'SELECT * FROM') !== false && $this->existing !== null) {
            $result->num_rows = 1;
            $result->row = $this->existing;
        }
        $result->rows = $result->num_rows > 0 ? array($result->row) : array();

        return $result;
    }

    public function last($prefix)
    {
        foreach (array_reverse($this->queries) as $sql) {
            if (stripos(ltrim($sql), $prefix) === 0) {
                return $sql;
            }
        }

        return '';
    }
}

function paymos_opencart_store_row(array $extra = array())
{
    return $extra + array(
        'opencart_order_id' => 7,
        'paymos_invoice_id' => 'inv_late',
        'external_order_id' => 'oc-7',
        'environment' => 'live',
        'project_id' => 'prj_1',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://pay.paymos.test/inv_late',
        'status' => 'expired',
        'renew_count' => 0,
    );
}

function test_opencart_store_adds_the_paid_late_column_once()
{
    $old = new FakeOpenCartStoreDb();
    Migrations::ensure($old);
    assertContainsValue('ADD COLUMN `paid_late_noted`', $old->last('ALTER TABLE'), 'an old table gains the marker column.');

    $current = new FakeOpenCartStoreDb();
    $current->hasMarkerColumn = true;
    Migrations::ensure($current);
    assertSameValue('', $current->last('ALTER TABLE'), 'a table that has the column is not altered again.');
}

function test_opencart_store_persists_the_paid_late_marker()
{
    $db = new FakeOpenCartStoreDb();
    $db->hasMarkerColumn = true;
    $db->existing = paymos_opencart_store_row();

    (new InvoiceStore($db))->save(paymos_opencart_store_row(array('paid_late_noted' => 'evt_late_1')));

    assertContainsValue("`paid_late_noted` = 'evt_late_1'", $db->last('UPDATE'), 'the marker is written to the table.');
}

function test_opencart_store_save_without_the_marker_leaves_it_alone()
{
    $db = new FakeOpenCartStoreDb();
    $db->hasMarkerColumn = true;
    $db->existing = paymos_opencart_store_row(array('paid_late_noted' => 'evt_late_1'));

    (new InvoiceStore($db))->save(array_diff_key(paymos_opencart_store_row(), array('paid_late_noted' => true)));

    assertSameValue(false, strpos($db->last('UPDATE'), 'paid_late_noted'), 'a save that does not carry the marker does not touch it.');
}

function test_opencart_callback_refreshes_snapshot_after_acquiring_native_order_lock()
{
    $db = new FakeOpenCartStoreDb();
    $db->existing = paymos_opencart_store_row(array('opencart_order_id' => 42, 'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0', 'environment' => 'sandbox', 'project_id' => 'prj_123', 'status' => 'created'));
    $db->beforeAcquire = static function () use ($db) { $db->existing['status'] = 'paid'; };
    $adapter = new FakeOpenCartAdapter();
    $body = json_encode(opencart_invoice_event('evt_fresh_final', 'invoice.confirming', 'confirming'));
    $result = (new \PaymosOpenCart\CallbackProcessor($adapter, new InvoiceStore($db), new \PaymosOpenCart\InMemoryEventStore()))
        ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);
    assertSameValue(200, $result->statusCode(), 'A final status acquired while waiting is acknowledged.');
    assertSameValue(0, count($adapter->histories), 'The initial stale nonfinal snapshot cannot downgrade an order.');
    assertSameValue(null, $db->lockOwner, 'The real native store releases its owned lock.');
}

final class OpenCartLockLossTransport implements \Paymos\Http\TransportInterface
{
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->db->connectionId = '42';
        $this->db->lockOwner = '42';
        $invoice = opencart_invoice_event('unused', 'invoice.paid', 'paid')['data'];
        $invoice['payment_url'] = 'https://checkout.paymos.test/inv_123';
        return new \Paymos\Http\HttpResponse(200, json_encode($invoice), array());
    }
}

function test_opencart_network_connection_loss_fences_checkout_callback_and_reconcile()
{
    foreach (array('checkout', 'callback', 'reconcile') as $path) {
        $db = new FakeOpenCartStoreDb();
        if ($path !== 'checkout') {
            $db->existing = paymos_opencart_store_row(array('opencart_order_id' => 42, 'paymos_invoice_id' => 'inv_123',
                'external_order_id' => 'oc_42_0', 'environment' => 'sandbox', 'project_id' => 'prj_123', 'status' => 'created'));
        }
        $store = new InvoiceStore($db);
        $adapter = new FakeOpenCartAdapter();
        $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new OpenCartLockLossTransport($db));
        $factory = static function () use ($client) { return $client; };
        if ($path === 'checkout') {
            try {
                (new \PaymosOpenCart\GatewayCheckout($store, $adapter, $factory))->start(42, opencart_settings());
                throw new RuntimeException('A disconnected checkout must not save a snapshot.');
            } catch (RuntimeException $error) { assertContainsValue('ownership was lost', $error->getMessage(), 'Connection change must fence checkout.'); }
        } elseif ($path === 'callback') {
            $body = json_encode(opencart_invoice_event('evt_connection_lost', 'invoice.paid', 'paid'));
            $result = (new \PaymosOpenCart\CallbackProcessor($adapter, $store, new \PaymosOpenCart\InMemoryEventStore(), $factory))
                ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);
            assertSameValue(400, $result->statusCode(), 'Lost ownership must leave the signed delivery retryable.');
        } else {
            assertSameValue(0, (new \PaymosOpenCart\Reconciler($store, $adapter, $factory))->run(opencart_settings()), 'A disconnected reconciler cannot apply payment.');
        }
        assertSameValue(0, count($adapter->histories), 'Lost ownership must prevent every native history effect.');
        assertSameValue('', $db->last('UPDATE'), 'No snapshot status update is allowed after connection loss.');
        if ($path === 'checkout') {
            // A46-01: the create intent is written while the lock is still held,
            // before the POST. Nothing carrying the response may follow it.
            assertContainsValue("`status` = 'creating'", $db->last('INSERT'), 'Checkout stores only its create intent before the POST.');
            assertSameValue(false, strpos($db->last('INSERT'), 'inv_123'), 'Checkout cannot bind a response after losing its lock.');
        } else {
            assertSameValue('', $db->last('INSERT'), 'Checkout cannot bind a response after losing its lock.');
        }
        assertSameValue('42', $db->lockOwner, 'Stale finally must not release the successor connection lock.');
    }
}
