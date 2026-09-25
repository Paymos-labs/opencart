<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use PaymosOpenCart\InMemoryEventStore;

function test_opencart_event_store_blocks_duplicate_after_commit()
{
    $store = new InMemoryEventStore();
    $body = json_encode(opencart_invoice_event('evt_1', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);
    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_sandbox'), $store);

    $verifier->process($signature, $body, 1709000000);
    $store->commit();

    try {
        $verifier->process($signature, $body, 1709000000);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'duplicate exception expected.');
        return;
    }

    throw new RuntimeException('Committed event id must block duplicate webhook processing.');
}

function test_opencart_event_store_release_allows_retry()
{
    $store = new InMemoryEventStore();

    assertTrueValue($store->remember('evt_retry', 3600), 'first remember must reserve event id.');
    $store->release();
    assertTrueValue($store->remember('evt_retry', 3600), 'released event id must be retriable.');
}

/**
 * The SQL OpenCart's EventStore issues against paymos_event, interpreted over
 * an array — enough to run the real store instead of the in-memory one.
 */
final class FakeOpenCartEventDb
{
    /** @var array<string, array{expires_at: int, created_at: int}> */
    public $rows = array();

    public function escape($value)
    {
        return addslashes((string) $value);
    }

    public function query($sql)
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        $result = new stdClass();
        $result->num_rows = 0;
        $result->row = array();
        $result->rows = array();

        if (preg_match("/^DELETE FROM `[^`]+` WHERE `expires_at` < '(\d+)'/", $sql, $m)) {
            foreach ($this->rows as $id => $row) {
                if ($row['expires_at'] < (int) $m[1]) {
                    unset($this->rows[$id]);
                }
            }
        } elseif (preg_match("/^DELETE FROM `[^`]+` WHERE `event_id` = '([^']*)'/", $sql, $m)) {
            unset($this->rows[$m[1]]);
        } elseif (preg_match("/^SELECT (.+) FROM `[^`]*paymos_event` WHERE `event_id` = '([^']*)'/", $sql, $m)) {
            if (isset($this->rows[$m[2]])) {
                $result->num_rows = 1;
                $result->row = array('event_id' => $m[2]) + $this->rows[$m[2]];
                $result->rows = array($result->row);
            }
        } elseif (preg_match("/^INSERT INTO `[^`]+` SET `event_id` = '([^']*)', `expires_at` = '(\d+)', `created_at` = '(\d+)'/", $sql, $m)) {
            if (isset($this->rows[$m[1]])) {
                throw new Exception('Duplicate entry');
            }
            $this->rows[$m[1]] = array('expires_at' => (int) $m[2], 'created_at' => (int) $m[3]);
        } elseif (preg_match("/^UPDATE `[^`]+` SET `expires_at` = '(\d+)' WHERE `event_id` = '([^']*)'/", $sql, $m)) {
            if (isset($this->rows[$m[2]])) {
                $this->rows[$m[2]]['expires_at'] = (int) $m[1];
            }
        }

        return $result;
    }
}

function test_opencart_db_event_store_tells_a_locked_event_from_a_committed_one()
{
    // BUG-103: remember() says "seen" for both; isCommitted() must not.
    $db = new FakeOpenCartEventDb();
    $first = new PaymosOpenCart\EventStore($db);
    assertTrueValue($first->remember('evt_db', 604800), 'first delivery takes the lock.');

    $retry = new PaymosOpenCart\EventStore($db);
    assertFalseValue($retry->remember('evt_db', 604800), 'a retry while the lock is held is not new.');
    assertFalseValue($retry->isCommitted('evt_db'), 'a locked, uncommitted event is not committed.');

    $first->commit();
    assertTrueValue($retry->isCommitted('evt_db'), 'after commit the event is committed.');
}

function test_opencart_in_memory_event_store_tells_a_locked_event_from_a_committed_one()
{
    $store = new InMemoryEventStore();
    $store->remember('evt_mem', 604800);
    assertFalseValue($store->isCommitted('evt_mem'), 'locked only: not committed.');
    $store->commit();
    assertTrueValue($store->isCommitted('evt_mem'), 'committed.');
}
