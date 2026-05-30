<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class InMemoryInvoiceStore implements InvoiceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = array();

    public function findByOpenCartOrderId($orderId)
    {
        $found = null;
        foreach ($this->rows as $row) {
            if ((int) $row['opencart_order_id'] === (int) $orderId) {
                $found = $row;
            }
        }

        return $found;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        foreach ($this->rows as $row) {
            if ((string) $row['external_order_id'] === (string) $externalOrderId) {
                return $row;
            }
        }

        return null;
    }

    public function save(array $row)
    {
        $now = date('Y-m-d H:i:s');
        $row['updated_at'] = isset($row['updated_at']) ? $row['updated_at'] : $now;
        $row['created_at'] = isset($row['created_at']) ? $row['created_at'] : $now;

        foreach ($this->rows as $index => $existing) {
            if ((string) $existing['external_order_id'] === (string) $row['external_order_id']) {
                $this->rows[$index] = array_merge($existing, $row);
                return;
            }
        }

        $this->rows[] = $row;
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        foreach ($this->rows as $index => $row) {
            if ((string) $row['paymos_invoice_id'] === (string) $paymosInvoiceId) {
                $this->rows[$index]['status'] = (string) $status;
                $this->rows[$index]['updated_at'] = date('Y-m-d H:i:s');
            }
        }
    }

    public function findUnpaidRecent($limit, $sinceTimestamp)
    {
        $terminal = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');
        $result = array();

        for ($i = count($this->rows) - 1; $i >= 0; $i--) {
            $row = $this->rows[$i];
            if (in_array((string) $row['status'], $terminal, true)) {
                continue;
            }

            $createdAt = isset($row['created_at']) ? strtotime((string) $row['created_at']) : time();
            if ($createdAt !== false && $createdAt < (int) $sinceTimestamp) {
                continue;
            }

            $result[] = $row;
            if (count($result) >= (int) $limit) {
                break;
            }
        }

        return $result;
    }
}
