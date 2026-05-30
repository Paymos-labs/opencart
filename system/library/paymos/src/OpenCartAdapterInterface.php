<?php

declare(strict_types=1);

namespace PaymosOpenCart;

interface OpenCartAdapterInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getOrder($orderId);

    public function addOrderHistory($orderId, $orderStatusId, $comment, $notify = false);

    public function log($message, array $context = array());
}
