<?php

declare(strict_types=1);

use PaymosOpenCart\Installer;

/**
 * BUG-161: before OpenCart 4.0.2.0 the route method separator is `|` and `.`
 * is stripped from the route (upload/system/engine/action.php), payment
 * extensions answer getMethod() instead of getMethods(), and the admin model
 * setting/store has no createStoreInstance(). Checkout, the webhook route
 * (`paymos.callback`, registered at Connect) and the admin reconcile all break,
 * so the extension must refuse to install there and say why in the log.
 */
function test_opencart_installer_refuses_opencart_before_4_0_2_0()
{
    foreach (array('4.0.0.0', '4.0.1.0', '4.0.1.1', '') as $version) {
        $db = new FakeOpenCartInstallDb();
        $extensions = new FakeOpenCartExtensionModel();
        $log = new FakeOpenCartLog();

        $installed = Installer::install($db, $extensions, $log, $version);

        assertSameValue(false, $installed, 'OpenCart ' . var_export($version, true) . ' must be refused.');
        assertSameValue(array(array('payment', 'paymos')), $extensions->uninstalled, 'the extension row core just added must be removed on ' . var_export($version, true) . '.');
        assertSameValue(0, count($db->queries), 'no Paymos tables may be created on ' . var_export($version, true) . '.');
        assertSameValue(1, count($log->lines), 'the refusal must be logged once on ' . var_export($version, true) . '.');
        assertContainsValue('4.0.2.0', $log->lines[0], 'the log must name the minimum version.');
        if ($version !== '') {
            assertContainsValue($version, $log->lines[0], 'the log must name the running version.');
        }
    }
}

function test_opencart_installer_installs_on_4_0_2_0_and_later()
{
    foreach (array('4.0.2.0', '4.0.2.3', '4.1.0.0') as $version) {
        $db = new FakeOpenCartInstallDb();
        $extensions = new FakeOpenCartExtensionModel();
        $log = new FakeOpenCartLog();

        $installed = Installer::install($db, $extensions, $log, $version);

        assertSameValue(true, $installed, 'OpenCart ' . $version . ' must be installed.');
        assertSameValue(array(), $extensions->uninstalled, 'a supported store keeps the extension on ' . $version . '.');
        assertSameValue(2, count($db->queries), 'both Paymos tables are created on ' . $version . '.');
        assertSameValue(array(), $log->lines, 'nothing is logged on ' . $version . '.');
    }
}

function test_opencart_admin_install_hook_goes_through_the_version_gate()
{
    // The harness never defines OpenCart's VERSION, so the admin hook must
    // treat the store as unknown and refuse — proving it is wired to the gate
    // rather than straight to the migrations.
    assertSameValue(false, defined('VERSION'), 'the harness must not define VERSION.');

    $db = new FakeOpenCartInstallDb();
    $extensions = new FakeOpenCartExtensionModel();
    $log = new FakeOpenCartLog();
    $app = new FakeOpenCartApplication('Admin', array(
        'setting/extension' => static function () use ($extensions) {
            return $extensions;
        },
    ));
    $app->set('db', $db);
    $app->set('log', $log);

    (new \Opencart\Admin\Controller\Extension\Paymos\Payment\Paymos($app))->install();

    assertSameValue(array(array('payment', 'paymos')), $extensions->uninstalled, 'the admin install hook must undo core\'s install on an unknown version.');
    assertSameValue(0, count($db->queries), 'the admin install hook must not create tables on an unknown version.');
    assertSameValue(1, count($log->lines), 'the admin install hook must log the refusal.');
}

final class FakeOpenCartInstallDb
{
    /** @var array<int, string> */
    public $queries = array();

    public function query($sql)
    {
        $this->queries[] = (string) $sql;
        return true;
    }

    public function escape($value)
    {
        return addslashes((string) $value);
    }
}

final class FakeOpenCartExtensionModel
{
    /** @var array<int, array<int, string>> */
    public $uninstalled = array();

    public function uninstall($type, $code)
    {
        $this->uninstalled[] = array((string) $type, (string) $code);
    }
}

final class FakeOpenCartLog
{
    /** @var array<int, string> */
    public $lines = array();

    public function write($message)
    {
        $this->lines[] = (string) $message;
    }
}
