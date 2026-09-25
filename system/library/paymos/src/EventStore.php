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

        try {
            $this->db->query("INSERT INTO `" . Migrations::table(Migrations::EVENTS_TABLE) . "` SET
                `event_id` = '" . $this->db->escape($eventId) . "',
                `expires_at` = '" . (int) ($now + self::RESERVATION_SECONDS) . "',
                `created_at` = '" . (int) $now . "'");
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
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

    public function commit()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->query("UPDATE `" . Migrations::table(Migrations::EVENTS_TABLE) . "` SET
            `expires_at` = '" . (int) (time() + $this->pendingTtlSeconds) . "'
            WHERE `event_id` = '" . $this->db->escape($this->pendingEventId) . "'");

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->db->query("DELETE FROM `" . Migrations::table(Migrations::EVENTS_TABLE) . "`
            WHERE `event_id` = '" . $this->db->escape($this->pendingEventId) . "'");

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }
}
