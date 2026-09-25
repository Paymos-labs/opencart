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
}
