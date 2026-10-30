<?php

declare(strict_types=1);

namespace PaymosOpenCart;

/**
 * The admin install hook, behind a minimum OpenCart version.
 *
 * The extension needs OpenCart 4.0.2.0 or later. Before it (checked against
 * the opencart/opencart tags 4.0.0.0, 4.0.1.0 and 4.0.1.1):
 * - upload/system/engine/action.php splits the route on `|` and strips `.`,
 *   so `extension/paymos/payment/paymos.confirm` and the webhook route
 *   `….paymos.callback` resolve to no method at all;
 * - payment extensions answer getMethod(), not getMethods(), so Paymos never
 *   shows at checkout;
 * - admin/model/setting/store has no createStoreInstance(), which the admin
 *   reconcile needs to write order history (OpenCartAdapter).
 *
 * Core has already added the extension row when it calls the install hook
 * (admin/controller/extension/payment.php), so a refusal removes that row
 * again and writes the reason to the store's error log.
 */
final class Installer
{
    public const MINIMUM_OPENCART_VERSION = '4.0.2.0';
    public const REFUND_CRON_CODE = 'paymos_refunds';
    public const REFUND_CRON_ACTION = 'extension/paymos/payment/paymos.refundCron';

    /**
     * @param string $version OpenCart's VERSION constant; empty when unknown.
     */
    public static function supports($version)
    {
        $version = trim((string) $version);

        return $version !== '' && version_compare($version, self::MINIMUM_OPENCART_VERSION, '>=');
    }

    /**
     * @param object $db              OpenCart DB
     * @param object $extensionModel  admin model setting/extension
     * @param object $log             OpenCart Log
     * @param string $version         OpenCart's VERSION constant; empty when unknown.
     * @return bool                   Whether the extension was installed.
     */
    public static function install($db, $extensionModel, $log, $version)
    {
        if (!self::supports($version)) {
            $running = trim((string) $version) !== '' ? trim((string) $version) : 'unknown';
            $log->write('[Paymos] Installation refused: the Paymos extension requires OpenCart '
                . self::MINIMUM_OPENCART_VERSION . ' or later; this store runs OpenCart ' . $running
                . '. Update OpenCart, then install the extension again.');
            $extensionModel->uninstall('payment', 'paymos');

            return false;
        }

        Migrations::install($db);

        return true;
    }

    public static function ensureRefundSchema($db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `" . Migrations::table('paymos_refund_installation') . "` (
            `id` TINYINT UNSIGNED NOT NULL,
            `installation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY (`installation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `" . Migrations::table('paymos_refund_intent') . "` (
            `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `installation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `request_token` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `payment_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `external_order_id` VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `withdrawal_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `payload` LONGTEXT NOT NULL,
            `created_at` BIGINT NOT NULL,
            `next_due` BIGINT NOT NULL,
            `poll_count` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`operation_id`), UNIQUE KEY `request_token` (`installation_id`, `request_token`),
            UNIQUE KEY (`external_order_id`), UNIQUE KEY (`withdrawal_id`),
            KEY (`payment_key`), KEY `due` (`next_due`, `operation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `" . Migrations::table('paymos_refund_projection') . "` (
            `operation_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `order_id` INT UNSIGNED NOT NULL,
            `history_id` INT UNSIGNED NOT NULL,
            `review_history_id` INT UNSIGNED NULL,
            `history_comment` TEXT NOT NULL,
            `result` LONGTEXT NOT NULL,
            PRIMARY KEY (`operation_id`), UNIQUE KEY (`history_id`), KEY (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `" . Migrations::table('paymos_refund_external') . "` (
            `withdrawal_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `payment_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `payload` LONGTEXT NOT NULL, `next_due` BIGINT NOT NULL,
            PRIMARY KEY (`withdrawal_id`), KEY (`payment_key`), KEY (`next_due`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS `" . Migrations::table('paymos_refund_discovery') . "` (
            `cursor_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `cursor_value` TEXT NULL, PRIMARY KEY (`cursor_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function refundGuardEvents(): array
    {
        $action = 'extension/paymos/payment/paymos.';
        $events = array();
        foreach (array('addReturn', 'editReturn', 'addHistory') as $method) {
            $events['paymos_return_' . $method] = array('admin/model/sale/returns/' . $method . '/before', $action . 'nativeReturnBefore');
        }
        $events['paymos_return_catalog'] = array('catalog/model/account/returns/addReturn/before', $action . 'nativeReturnBefore');
        $events['paymos_refund_history'] = array('catalog/model/checkout/order/addHistory/before', $action . 'nativeOrderHistoryBefore');
        return $events;
    }

    public static function registerRefundGuards($events): void
    {
        foreach (self::refundGuardEvents() as $code => $event) {
            $existing = $events->getEventByCode($code);
            if ($existing && $existing['trigger'] === $event[0] && $existing['action'] === $event[1]
                && (int) $existing['status'] === 1 && (int) $existing['sort_order'] === -1000) {
                continue;
            }
            $events->deleteEventByCode($code);
            $events->addEvent(array('code' => $code, 'description' => '', 'trigger' => $event[0],
                'action' => $event[1], 'status' => true, 'sort_order' => -1000));
        }
    }

    public static function unregisterRefundGuards($events): void
    {
        foreach (self::refundGuardEvents() as $code => $event) {
            $events->deleteEventByCode($code);
        }
    }

    public static function registerRefundCron($cron, $db): void
    {
        self::ensureRefundSchema($db);
        (new RefundIntentStore($db))->withPaymentLock('opencart:cron', static function () use ($cron) {
            $existing = $cron->getCronByCode(self::REFUND_CRON_CODE);
            if (!$existing) {
                $cron->addCron(self::REFUND_CRON_CODE, 'Checks the status of Paymos refunds and records completed ones in order history.', 'minute', self::REFUND_CRON_ACTION, true);
            } elseif ($existing['action'] !== self::REFUND_CRON_ACTION || $existing['cycle'] !== 'minute') {
                throw new \RuntimeException('refund_cron_registration_conflict');
            }
        });
    }

    public static function uninstall($db): void
    {
        self::ensureRefundSchema($db);
        $query = $db->query('SELECT `operation_id` FROM `' . Migrations::table('paymos_refund_intent') . '` LIMIT 1');
        if (!isset($query->num_rows) || (int) $query->num_rows > 0) {
            return;
        }
        Migrations::uninstall($db);
    }
}
