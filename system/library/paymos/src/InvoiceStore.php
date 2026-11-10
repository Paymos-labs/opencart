<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class InvoiceStore implements InvoiceStoreInterface
{
    /** @var object */
    private $db;
    private $orderLocks = array();

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function synchronized($orderId, callable $action)
    {
        $key = (string) (int) $orderId;
        if (isset($this->orderLocks[$key])) {
            throw new \Paymos\Exception\EventInProgressException('Paymos order is busy.');
        }
        $database = $this->db->query('SELECT DATABASE() AS database_name');
        $name = hash('sha256', ($database->row['database_name'] ?? '') . ':' . Migrations::table(Migrations::INVOICES_TABLE) . ':' . $key);
        $query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($name) . "', 0) AS acquired, CONNECTION_ID() AS owner_id");
        if ((string) ($query->row['acquired'] ?? '') === '0') {
            throw new \Paymos\Exception\EventInProgressException('Paymos order is busy.');
        }
        if ((string) ($query->row['acquired'] ?? '') !== '1' || empty($query->row['owner_id'])) {
            throw new \RuntimeException('Paymos order lock could not be acquired.');
        }
        $owner = (string) $query->row['owner_id'];
        $this->orderLocks[$key] = array('name' => $name, 'owner' => $owner);
        try {
            $this->assertLocked($key);
            return $action();
        } finally {
            unset($this->orderLocks[$key]);
            $escapedName = $this->db->escape($name);
            $escapedOwner = $this->db->escape($owner);
            $this->db->query("SELECT IF(CONNECTION_ID() = '" . $escapedOwner . "' AND IS_USED_LOCK('" . $escapedName . "') = '" . $escapedOwner . "', RELEASE_LOCK('" . $escapedName . "'), NULL)");
        }
    }

    public function assertLocked($orderId)
    {
        $lock = $this->orderLocks[(string) (int) $orderId] ?? null;
        if ($lock === null) { throw new \RuntimeException('Paymos order lock is not held.'); }
        $query = $this->db->query("SELECT IS_USED_LOCK('" . $this->db->escape($lock['name']) . "') AS owner_id, CONNECTION_ID() AS connection_id");
        if ((string) ($query->row['owner_id'] ?? '') !== $lock['owner']
            || (string) ($query->row['connection_id'] ?? '') !== $lock['owner']) {
            throw new \RuntimeException('Paymos order lock ownership was lost.');
        }
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

        // The paid-late marker is written only by the callback that set it; other saves leave it alone.
        $paidLateNoted = array_key_exists('paid_late_noted', $row)
            ? ",
                `paid_late_noted` = " . $this->nullable($row['paid_late_noted'])
            : '';

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
                `updated_at` = '" . $this->db->escape($data['updated_at']) . "'" . $paidLateNoted . "
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
            `updated_at` = '" . $this->db->escape($now) . "'" . $paidLateNoted);
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

    private function nullable($value)
    {
        return $value === null ? 'NULL' : "'" . $this->db->escape((string) $value) . "'";
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
