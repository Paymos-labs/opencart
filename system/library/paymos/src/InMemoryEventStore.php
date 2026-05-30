<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Webhook\EventStoreInterface;

final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, int> */
    private $events = array();

    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        $now = time();

        foreach ($this->events as $stored => $expiresAt) {
            if ($expiresAt < $now) {
                unset($this->events[$stored]);
            }
        }

        if (isset($this->events[$eventId])) {
            return false;
        }

        $this->events[$eventId] = $now + 300;
        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    public function commit()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->events[$this->pendingEventId] = time() + $this->pendingTtlSeconds;
        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        unset($this->events[$this->pendingEventId]);
        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }
}
