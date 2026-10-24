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
    /** @var array<string, array{expires_at: int, created_at: int, owner_token: string}> */
    public $rows = array();

    /** @var bool Whether the events table has the owner_token column yet. */
    public $hasOwnerTokenColumn = false;

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

        if (preg_match("/^SHOW COLUMNS FROM `[^`]+` LIKE 'owner_token'/", $sql)) {
            if ($this->hasOwnerTokenColumn) {
                $result->num_rows = 1;
                $result->row = array('Field' => 'owner_token');
                $result->rows = array($result->row);
            }

            return $result;
        }

        if (preg_match("/^ALTER TABLE `[^`]+` ADD COLUMN `owner_token`/", $sql)) {
            $this->hasOwnerTokenColumn = true;

            return $result;
        }

        if (preg_match("/^DELETE FROM `[^`]+` WHERE `expires_at` < '(\d+)'/", $sql, $m)) {
            foreach ($this->rows as $id => $row) {
                if ($row['expires_at'] < (int) $m[1]) {
                    unset($this->rows[$id]);
                }
            }
        } elseif (preg_match("/^DELETE FROM `[^`]+` WHERE `event_id` = '([^']*)'(?: AND `owner_token` = '([^']*)')?/", $sql, $m)) {
            if (isset($this->rows[$m[1]])
                && (!isset($m[2]) || (string) $this->rows[$m[1]]['owner_token'] === stripslashes($m[2]))) {
                unset($this->rows[$m[1]]);
            }
        } elseif (preg_match("/^SELECT (.+) FROM `[^`]*paymos_event` WHERE `event_id` = '([^']*)'/", $sql, $m)) {
            if (isset($this->rows[$m[2]])) {
                $result->num_rows = 1;
                $result->row = array('event_id' => $m[2]) + $this->rows[$m[2]];
                $result->rows = array($result->row);
            }
        } elseif (preg_match("/^INSERT INTO `[^`]+` SET `event_id` = '([^']*)', `owner_token` = '([^']*)', `expires_at` = '(\d+)', `created_at` = '(\d+)'/", $sql, $m)) {
            if (isset($this->rows[$m[1]])) {
                throw new Exception('Duplicate entry');
            }
            $this->rows[$m[1]] = array(
                'owner_token' => stripslashes($m[2]),
                'expires_at' => (int) $m[3],
                'created_at' => (int) $m[4],
            );
        } elseif (preg_match("/^UPDATE `[^`]+` SET `expires_at` = '(\d+)' WHERE `event_id` = '([^']*)'(?: AND `owner_token` = '([^']*)')?/", $sql, $m)) {
            if (isset($this->rows[$m[2]])
                && (!isset($m[3]) || (string) $this->rows[$m[2]]['owner_token'] === stripslashes($m[3]))) {
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

/**
 * A10-02: a delivery whose reservation expired must not delete the successor's
 * row, and while the successor is still busy a third delivery must be rejected
 * as in-flight (not as a duplicate) until the successor commits.
 */
function test_opencart_stale_owner_release_does_not_touch_the_successor()
{
    $db = new FakeOpenCartEventDb();
    $ownerA = new PaymosOpenCart\EventStore($db);
    assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

    // Delivery A is stalled past its 300s reservation: rewind the row's expiry
    // so the next remember() prunes it and takes the id over.
    $db->rows['evt_stale_release']['expires_at'] = time() - 1;

    $ownerB = new PaymosOpenCart\EventStore($db);
    assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
    $tokenB = (string) $db->rows['evt_stale_release']['owner_token'];
    assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

    // A wakes up and fails: its release() must complete silently and leave B's row.
    $ownerA->release();
    assertTrueValue(isset($db->rows['evt_stale_release']), 'a stale release must not delete the successor reservation.');
    assertSameValue($tokenB, (string) $db->rows['evt_stale_release']['owner_token'], 'the successor row must keep its own token.');

    // While B is busy, a third delivery is rejected as in-flight, not duplicate.
    $ownerC = new PaymosOpenCart\EventStore($db);
    assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
    assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_sandbox'), $ownerC);
    $body = json_encode(opencart_invoice_event('evt_stale_release', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);
    try {
        $verifier->process($signature, $body, 1709000000);
        throw new RuntimeException('an in-flight event must not verify as new.');
    } catch (\Paymos\Exception\EventInProgressException $e) {
        // busy409: the successor's live reservation must not be acknowledged.
    }

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate (committed200).');
}

/**
 * A10-02: a stale delivery's commit() must report lost ownership (false) and
 * must not extend the successor's short reservation into a committed one.
 */
function test_opencart_stale_owner_commit_does_not_confirm_the_successor()
{
    $db = new FakeOpenCartEventDb();
    $ownerA = new PaymosOpenCart\EventStore($db);
    assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

    $db->rows['evt_stale_commit']['expires_at'] = time() - 1;

    $ownerB = new PaymosOpenCart\EventStore($db);
    assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
    $reservedByB = (int) $db->rows['evt_stale_commit']['expires_at'];

    assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
    assertSameValue($reservedByB, (int) $db->rows['evt_stale_commit']['expires_at'], "a stale commit must not extend the successor's reservation.");
    assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

    $ownerC = new PaymosOpenCart\EventStore($db);
    assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: owner tokens come from random_bytes, not the clock — two reservations
 * taken within the same second can never share a token.
 */
function test_opencart_owner_tokens_do_not_collide_within_the_same_second()
{
    $db = new FakeOpenCartEventDb();
    $first = new PaymosOpenCart\EventStore($db);
    $second = new PaymosOpenCart\EventStore($db);

    assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
    assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

    $tokenA = (string) $db->rows['evt_token_a']['owner_token'];
    $tokenB = (string) $db->rows['evt_token_b']['owner_token'];

    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
    assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
    assertSameValue((int) $db->rows['evt_token_a']['created_at'], (int) $db->rows['evt_token_b']['created_at'], 'precondition: both rows were written in the same second.');
}

/**
 * A10-02: commit() with nothing pending (or after the row is gone) is a quiet
 * non-success, never an error and never a recovery failure.
 */
function test_opencart_zero_row_commit_is_reported_as_not_owned()
{
    $db = new FakeOpenCartEventDb();
    $store = new PaymosOpenCart\EventStore($db);

    assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

    assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before the row disappears.');
    unset($db->rows['evt_zero_rows']);
    assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
}
