<?php

declare(strict_types=1);

namespace PaymosOpenCart;

/**
 * The event table could not record a reservation for a reason other than a
 * competing delivery holding the same event id (A46-02). The callback answers
 * 500, not the 409 reserved for a live in-flight reservation.
 */
final class EventStorageException extends \RuntimeException
{
}
