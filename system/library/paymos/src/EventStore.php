<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Webhook\EventStoreInterface;

final class EventStore implements EventStoreInterface
{
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
                `expires_at` = '" . (int) ($now + 300) . "',
                `created_at` = '" . (int) $now . "'");
        } catch (\Exception $e) {
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
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
