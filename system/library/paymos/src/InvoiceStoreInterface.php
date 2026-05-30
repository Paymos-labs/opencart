<?php

declare(strict_types=1);

namespace PaymosOpenCart;

interface InvoiceStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByOpenCartOrderId($orderId);

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId($externalOrderId);

    /**
     * @param array<string, mixed> $row
     */
    public function save(array $row);

    public function updateStatus($paymosInvoiceId, $status);

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent($limit, $sinceTimestamp);
}
