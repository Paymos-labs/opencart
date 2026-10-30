<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class Migrations
{
    public const INVOICES_TABLE = 'paymos_invoice';
    public const EVENTS_TABLE = 'paymos_event';

    public static function ensure($db)
    {
        self::install($db);
        self::addEventOwnerToken($db);
        self::addPaidLateNoted($db);
    }

    /**
     * Tables created before invoice-late-payment lack the paid-late marker column. SHOW COLUMNS works on
     * MySQL and MariaDB alike, so the ALTER runs once per store.
     */
    private static function addPaidLateNoted($db)
    {
        $table = self::table(self::INVOICES_TABLE);
        $column = $db->query("SHOW COLUMNS FROM `" . $table . "` LIKE 'paid_late_noted'");
        if (isset($column->num_rows) && (int) $column->num_rows > 0) {
            return;
        }

        $db->query("ALTER TABLE `" . $table . "` ADD COLUMN `paid_late_noted` VARCHAR(128) NULL");
    }

    /**
     * Tables created before the owner-token reservation fix (A10-02) lack the
     * `owner_token` column. Old rows keep '' and never match a live owner
     * token, so they simply expire via their reservation TTL (<=300s) — no data
     * migration is needed, and the takeover window closes by itself.
     */
    private static function addEventOwnerToken($db)
    {
        $table = self::table(self::EVENTS_TABLE);
        $column = $db->query("SHOW COLUMNS FROM `" . $table . "` LIKE 'owner_token'");
        if (isset($column->num_rows) && (int) $column->num_rows > 0) {
            return;
        }

        try {
            $db->query("ALTER TABLE `" . $table . "` ADD COLUMN `owner_token` CHAR(32) NOT NULL DEFAULT ''");
        } catch (\Exception $e) {
            // An existing installation whose ALTER failed keeps working until
            // the next request retries it; expired rows still age out by TTL.
        }
    }

    public static function install($db)
    {
        $invoiceTable = self::table(self::INVOICES_TABLE);
        $eventTable = self::table(self::EVENTS_TABLE);

        $db->query("CREATE TABLE IF NOT EXISTS `" . $invoiceTable . "` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `opencart_order_id` INT(11) UNSIGNED NOT NULL,
            `paymos_invoice_id` VARCHAR(128) NOT NULL,
            `external_order_id` VARCHAR(191) NOT NULL,
            `environment` VARCHAR(16) NOT NULL,
            `project_id` VARCHAR(128) NOT NULL,
            `amount` VARCHAR(64) NOT NULL,
            `currency` VARCHAR(16) NOT NULL,
            `payment_url` TEXT NOT NULL,
            `status` VARCHAR(64) NOT NULL,
            `renew_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `paid_late_noted` VARCHAR(128) NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `paymos_invoice_id` (`paymos_invoice_id`),
            UNIQUE KEY `external_order_id` (`external_order_id`),
            KEY `opencart_order_id` (`opencart_order_id`),
            KEY `environment` (`environment`),
            KEY `project_id` (`project_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS `" . $eventTable . "` (
            `event_id` VARCHAR(128) NOT NULL,
            `owner_token` CHAR(32) NOT NULL DEFAULT '',
            `expires_at` INT(11) UNSIGNED NOT NULL,
            `created_at` INT(11) UNSIGNED NOT NULL,
            PRIMARY KEY (`event_id`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Installer::ensureRefundSchema($db);
    }

    public static function uninstall($db)
    {
        $db->query("DROP TABLE IF EXISTS `" . self::table(self::EVENTS_TABLE) . "`");
        $db->query("DROP TABLE IF EXISTS `" . self::table(self::INVOICES_TABLE) . "`");
    }

    public static function table($name)
    {
        $prefix = defined('DB_PREFIX') ? constant('DB_PREFIX') : '';
        return $prefix . $name;
    }
}
