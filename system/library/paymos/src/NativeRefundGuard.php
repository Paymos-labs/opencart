<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class NativeRefundGuard
{
    public const ERROR_MESSAGE = 'Refund through Paymos is unavailable; the reason is in the OpenCart error log. If it is refund_reconnect_required, click Connect Paymos and approve the same project.';

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function beforeReturn(string $route, array $args): void
    {
        $method = substr($route, strrpos($route, '/') + 1);
        if ($method === 'addReturn') {
            $this->assertOrderAllowed((int) ($args[0]['order_id'] ?? 0));
        } else {
            $query = $this->db->query('SELECT `order_id` FROM `' . Migrations::table('return')
                . '` WHERE `return_id` = ' . (int) ($args[0] ?? 0));
            $this->assertOrderAllowed((int) ($query->row['order_id'] ?? 0));
            if ($method === 'editReturn') {
                // Rebinding a return must not hide its original payment.
                $this->assertOrderAllowed((int) ($args[1]['order_id'] ?? 0));
            }
        }
    }

    public function beforeOrderHistory(array $args): void
    {
        $statusId = (int) ($args[1] ?? 0);
        // The seeded status keeps its ID when its translations change.
        if ($statusId !== 11) {
            $query = $this->db->query('SELECT `order_status_id` FROM `' . Migrations::table('order_status')
                . '` WHERE `order_status_id` = ' . $statusId . " AND LOWER(TRIM(`name`)) = 'refunded' LIMIT 1");
            if (!$query->row) {
                return;
            }
        }
        $this->assertOrderAllowed((int) ($args[0] ?? 0));
    }

    private function assertOrderAllowed(int $orderId): void
    {
        if ($orderId < 1) {
            return;
        }
        $query = $this->db->query('SELECT * FROM `' . Migrations::table('order') . '` WHERE `order_id` = ' . $orderId);
        $order = $query->row;
        $method = $order['payment_method'] ?? array();
        if (is_string($method)) {
            $method = json_decode($method, true);
        }
        if (($order['payment_code'] ?? '') === 'paymos.paymos'
            || (is_array($method) && ($method['code'] ?? '') === 'paymos.paymos')) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }
        // A paid invoice survives edits to the order's payment method.
        $query = $this->db->query('SELECT * FROM `' . Migrations::table(Migrations::INVOICES_TABLE)
            . '` WHERE `opencart_order_id` = ' . $orderId . ' LIMIT 1');
        $invoice = $query->row;
        if (in_array($invoice['status'] ?? '', array('paid', 'paid_over'), true)
            || !empty($invoice['paid_late_noted'])) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }
    }
}
