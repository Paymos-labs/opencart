<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class InMemoryEventStore implements CommitAwareEventStoreInterface
{
    /** @var array<string, int> */
    private $events = array();

    /** @var array<string, bool> */
    private $committed = array();

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
                unset($this->events[$stored], $this->committed[$stored]);
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

    public function isCommitted($eventId)
    {
        return isset($this->committed[(string) $eventId]);
    }

    public function commit()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $this->events[$this->pendingEventId] = time() + $this->pendingTtlSeconds;
        $this->committed[$this->pendingEventId] = true;
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
