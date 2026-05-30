<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class InvoiceStore implements InvoiceStoreInterface
{
    /** @var object */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findByOpenCartOrderId($orderId)
    {
        $query = $this->db->query("SELECT * FROM `" . Migrations::table(Migrations::INVOICES_TABLE) . "`
            WHERE `opencart_order_id` = '" . (int) $orderId . "'
            ORDER BY `id` DESC
            LIMIT 1");

        return $this->row($query);
    }

    public function findByExternalOrderId($externalOrderId)
    {
        $query = $this->db->query("SELECT * FROM `" . Migrations::table(Migrations::INVOICES_TABLE) . "`
            WHERE `external_order_id` = '" . $this->db->escape((string) $externalOrderId) . "'
            LIMIT 1");

        return $this->row($query);
    }

    public function save(array $row)
    {
        Migrations::ensure($this->db);
        $now = date('Y-m-d H:i:s');
        $data = array(
            'opencart_order_id' => (int) $row['opencart_order_id'],
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => strtoupper((string) $row['currency']),
            'payment_url' => (string) $row['payment_url'],
            'status' => (string) $row['status'],
            'renew_count' => isset($row['renew_count']) ? (int) $row['renew_count'] : 0,
            'updated_at' => $now,
        );

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            $this->db->query("UPDATE `" . Migrations::table(Migrations::INVOICES_TABLE) . "` SET
                `opencart_order_id` = '" . (int) $data['opencart_order_id'] . "',
                `paymos_invoice_id` = '" . $this->db->escape($data['paymos_invoice_id']) . "',
                `environment` = '" . $this->db->escape($data['environment']) . "',
                `project_id` = '" . $this->db->escape($data['project_id']) . "',
                `amount` = '" . $this->db->escape($data['amount']) . "',
                `currency` = '" . $this->db->escape($data['currency']) . "',
                `payment_url` = '" . $this->db->escape($data['payment_url']) . "',
                `status` = '" . $this->db->escape($data['status']) . "',
                `renew_count` = '" . (int) $data['renew_count'] . "',
                `updated_at` = '" . $this->db->escape($data['updated_at']) . "'
                WHERE `external_order_id` = '" . $this->db->escape($data['external_order_id']) . "'");
            return;
        }

        $this->db->query("INSERT INTO `" . Migrations::table(Migrations::INVOICES_TABLE) . "` SET
            `opencart_order_id` = '" . (int) $data['opencart_order_id'] . "',
            `paymos_invoice_id` = '" . $this->db->escape($data['paymos_invoice_id']) . "',
            `external_order_id` = '" . $this->db->escape($data['external_order_id']) . "',
            `environment` = '" . $this->db->escape($data['environment']) . "',
            `project_id` = '" . $this->db->escape($data['project_id']) . "',
            `amount` = '" . $this->db->escape($data['amount']) . "',
            `currency` = '" . $this->db->escape($data['currency']) . "',
            `payment_url` = '" . $this->db->escape($data['payment_url']) . "',
            `status` = '" . $this->db->escape($data['status']) . "',
            `renew_count` = '" . (int) $data['renew_count'] . "',
            `created_at` = '" . $this->db->escape($now) . "',
            `updated_at` = '" . $this->db->escape($now) . "'");
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        $this->db->query("UPDATE `" . Migrations::table(Migrations::INVOICES_TABLE) . "` SET
            `status` = '" . $this->db->escape((string) $status) . "',
            `updated_at` = '" . $this->db->escape(date('Y-m-d H:i:s')) . "'
            WHERE `paymos_invoice_id` = '" . $this->db->escape((string) $paymosInvoiceId) . "'");
    }

    public function findUnpaidRecent($limit, $sinceTimestamp)
    {
        $terminal = array("'paid'", "'paid_over'", "'underpaid'", "'expired'", "'cancelled'");
        $query = $this->db->query("SELECT * FROM `" . Migrations::table(Migrations::INVOICES_TABLE) . "`
            WHERE `status` NOT IN (" . implode(',', $terminal) . ")
            AND `created_at` >= '" . $this->db->escape(date('Y-m-d H:i:s', (int) $sinceTimestamp)) . "'
            ORDER BY `id` DESC
            LIMIT " . (int) $limit);

        return isset($query->rows) && is_array($query->rows) ? $query->rows : array();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row($query)
    {
        if (!isset($query->row) || !is_array($query->row) || count($query->row) === 0) {
            return null;
        }

        return $query->row;
    }
}
