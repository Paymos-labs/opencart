<?php

declare(strict_types=1);

// Controller fixtures assert native HTTP status codes after earlier test results.
// Keep CLI progress output buffered so PHP does not send headers before those assertions.
ob_start();

require __DIR__ . '/bootstrap.php';

// Compile every plugin source file, not just the ones the tests touch: the
// platform loads all of them, so a defect in an untested file must fail HERE.
$_srcRoot = PAYMOS_OPENCART_PLUGIN_DIR;
$_iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $_srcRoot,
    FilesystemIterator::SKIP_DOTS
));
$_files = array();
foreach ($_iter as $_f) {
    if (!$_f->isFile() || $_f->getExtension() !== 'php') {
        continue;
    }
    $_rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($_f->getPathname(), strlen($_srcRoot)));
    if (preg_match('!(^|/)(tests?|vendor|node_modules|\.github)(/|$)!', $_rel)) {
        continue;
    }
    $_files[] = $_f->getPathname();
}
sort($_files);

// Constants the harness itself pre-defined may be re-defined by the plugin's
// entry file (the platform loads that file FIRST in production). Tolerate
// exactly those collisions; anything else — including a real duplicate between
// two plugin files — still fails the run.
$_predefined = get_defined_constants(true);
$_predefined = isset($_predefined['user']) ? $_predefined['user'] : array();
foreach ($_files as $_file) {
    set_error_handler(static function ($severity, $message, $file, $line) use ($_predefined) {
        if (preg_match('/Constant (\\w+) already defined/', $message, $_m)
            && array_key_exists($_m[1], $_predefined)) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require_once $_file;
    } finally {
        restore_error_handler();
    }
}
unset($_srcRoot, $_iter, $_files, $_file, $_rel, $_f, $_predefined);
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/GatewayCheckoutTest.php';
require __DIR__ . '/EventStoreTest.php';
require __DIR__ . '/CallbackProcessorTest.php';
require __DIR__ . '/PaidLateCallbackTest.php';
require __DIR__ . '/InvoiceStorePaidLateNotedTest.php';
require __DIR__ . '/ReconcilerTest.php';
require __DIR__ . '/InstallerTest.php';
require __DIR__ . '/RefundAdapterTest.php';
require __DIR__ . '/NativeRefundGuardTest.php';

$count = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    paymos_opencart_reset_test_state();
    $function();
    paymos_opencart_reset_test_state();
    $count++;
    echo "PASS {$function}\n";
}

paymos_opencart_reset_test_state();

if (!is_file(__DIR__ . '/refund-panel.cjs')) {
    throw new RuntimeException('Missing the refund panel JavaScript check script.');
}

$template = file_get_contents(PAYMOS_OPENCART_PLUGIN_DIR . 'admin/view/template/payment/paymos.twig');
preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $template, $scripts);
$urls = array('refund_panel', 'refund_submit', 'reconcile', 'connect_start', 'connect_poll');
foreach (array('en-gb', 'ru-ru', 'de-de', 'es-es', 'tr-tr', 'zh-cn') as $locale) {
    $_ = array();
    require PAYMOS_OPENCART_PLUGIN_DIR . 'admin/language/' . $locale . '/payment/paymos.php';
    $labels = $_;
    preg_replace_callback('~{{\s*(\w+)(?:\|escape\(\'js\'\))?\s*}}~', static function ($match) use ($labels, $urls) {
        $key = $match[1];
        if (!in_array($key, $urls, true) && (!isset($labels[$key]) || $labels[$key] === '')) {
            throw new RuntimeException('Missing template translation: ' . $key);
        }
        return '';
    }, implode("\n", $scripts[1]));
}

echo "OK {$count} tests\n";

require __DIR__ . '/refund-native.php';

ob_end_flush();
