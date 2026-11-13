<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class EventStore implements CommitAwareEventStoreInterface
{
    /**
     * Lifetime of the in-flight lock remember() takes. commit() extends the row
     * to the full dedup window, so a row that outlives its reservation was
     * committed (see isCommitted()).
     */
    private const RESERVATION_SECONDS = 300;

    /** @var object */
    private $db;

    /** @var string */
    private $pendingEventId = '';

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function remember($eventId, $ttlSeconds)
    {
        Migrations::ensure($this->db);

        $eventId = (string) $eventId;
        $now = time();
        $this->db->query("DELETE FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `expires_at` < '" . (int) $now . "'");

        $query = $this->db->query("SELECT `event_id` FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `event_id` = '" . $this->db->escape($eventId) . "'
            LIMIT 1");
        if (isset($query->num_rows) && (int) $query->num_rows > 0) {
            return false;
        }

        $ownerToken = bin2hex(random_bytes(16));

        try {
            $this->db->query("INSERT INTO `" . Migrations::table(Migrations::EVENTS_TABLE) . "` SET
                `event_id` = '" . $this->db->escape($eventId) . "',
                `owner_token` = '" . $this->db->escape($ownerToken) . "',
                `expires_at` = '" . (int) ($now + self::RESERVATION_SECONDS) . "',
                `created_at` = '" . (int) $now . "'");
        } catch (\Exception $e) {
            // Only a competing insert of the same event id means busy/duplicate.
            // Any other write failure is a storage fault and must not be reported
            // as "another delivery is processing this event" (A46-02).
            if (self::isDuplicateKey($e)) {
                return false;
            }
            throw new EventStorageException('Paymos event reservation could not be stored.', 0, $e);
        }

        $this->pendingEventId = $eventId;
        $this->pendingOwnerToken = $ownerToken;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * MySQL error 1062 (ER_DUP_ENTRY). OpenCart's mysqli driver rethrows it as a
     * plain \Exception whose message carries "Error No: 1062"; its PDO driver
     * carries the server text "Duplicate entry"; a mysqli_sql_exception
     * kept as the previous exception carries the code 1062.
     */
    private static function isDuplicateKey(\Throwable $e)
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === '1062'
                || preg_match('/Error No:\s*1062\b|Duplicate entry/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * A committed row lives past its reservation; a lock does not.
     */
    public function isCommitted($eventId)
    {
        $query = $this->db->query("SELECT `expires_at`, `created_at` FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `event_id` = '" . $this->db->escape((string) $eventId) . "'
            LIMIT 1");
        if (!isset($query->num_rows) || (int) $query->num_rows === 0 || !isset($query->row) || !is_array($query->row)) {
            return false;
        }

        $expiresAt = isset($query->row['expires_at']) ? (int) $query->row['expires_at'] : 0;
        $createdAt = isset($query->row['created_at']) ? (int) $query->row['created_at'] : 0;

        return $expiresAt > time() && $expiresAt > $createdAt + self::RESERVATION_SECONDS;
    }

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero matched rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     *
     * @return bool True when this owner's row was extended; false when nothing
     *              was pending or the reservation was lost to a successor.
     */
    public function commit()
    {
        if ($this->pendingEventId === '') {
            return false;
        }

        $eventId = $this->pendingEventId;
        $ownerToken = $this->pendingOwnerToken;

        $this->db->query("UPDATE `" . Migrations::table(Migrations::EVENTS_TABLE) . "` SET
            `expires_at` = '" . (int) (time() + $this->pendingTtlSeconds) . "'
            WHERE `event_id` = '" . $this->db->escape($eventId) . "'
                AND `owner_token` = '" . $this->db->escape($ownerToken) . "'");

        // OpenCart's query() does not report affected rows, so ownership is
        // confirmed by reading the row back: it is still ours exactly when the
        // token written by this remember() is the one in the row.
        $query = $this->db->query("SELECT `owner_token` FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `event_id` = '" . $this->db->escape($eventId) . "'
            LIMIT 1");
        $stillOwner = isset($query->row)
            && is_array($query->row)
            && isset($query->row['owner_token'])
            && (string) $query->row['owner_token'] === $ownerToken;

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return $stillOwner;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->query("DELETE FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `event_id` = '" . $this->db->escape($this->pendingEventId) . "'
                AND `owner_token` = '" . $this->db->escape($this->pendingOwnerToken) . "'");

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;
    }
}
