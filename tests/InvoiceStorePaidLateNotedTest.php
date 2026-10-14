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

        if (stripos($sql, 'SHOW COLUMNS') !== false && $this->hasMarkerColumn) {
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
