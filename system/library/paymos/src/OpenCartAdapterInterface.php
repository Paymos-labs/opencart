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

    /**
     * What the buyer owes for the order, in the order currency (see OrderAmount).
     *
     * @param array<string, mixed> $order
     * @return string
     */
    public function orderAmount(array $order);
}
