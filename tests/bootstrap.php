<?php

declare(strict_types=1);
require __DIR__ . '/stubs/engine.php';

// OpenCart defines these before controller files load. Controllers resolve the
// SDK autoloader through DIR_EXTENSION . 'paymos/...', so mirror the platform's
// extension layout with a temp symlink named after the extension directory.
if (!defined('DIR_APPLICATION')) {
    define('DIR_APPLICATION', __DIR__ . '/');
}
if (!defined('DIR_EXTENSION')) {
    $_extRoot = rtrim(sys_get_temp_dir(), '/\\') . '/paymos-opencart-extension-tests/';
    if (!is_dir($_extRoot)) {
        mkdir($_extRoot, 0777, true);
    }
    if (!is_link($_extRoot . 'paymos') && !file_exists($_extRoot . 'paymos')) {
        symlink(dirname(__DIR__), $_extRoot . 'paymos');
    }
    define('DIR_EXTENSION', $_extRoot);
    unset($_extRoot);
}

define('PAYMOS_OPENCART_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// Any deprecation, notice or warning inside plugin code must fail the run:
// platform installers (Magento DI compile above all) escalate PHP 8.4+
// deprecations to fatals, and a silent one here is how rejections slip through.
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('PAYMOS_OPENCART_LIBRARY_DIR', PAYMOS_OPENCART_PLUGIN_DIR . 'system/library/paymos/');

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosOpenCart\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = PAYMOS_OPENCART_LIBRARY_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_OPENCART_LIBRARY_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_OPENCART_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function opencart_settings(array $overrides = array())
{
    $settings = array_merge(array(
        'payment_paymos_mode' => 'sandbox',
        'payment_paymos_status' => '1',
        'payment_paymos_title' => 'Pay with stablecoins',
        'payment_paymos_button_text' => 'Pay with Paymos',
        'payment_paymos_sandbox_api_key' => 'pk_test_123',
        'payment_paymos_sandbox_api_secret' => 'sk_test_123',
        'payment_paymos_sandbox_project_id' => 'prj_123',
        'payment_paymos_sandbox_webhook_secret' => 'whsec_sandbox',
        'payment_paymos_live_api_key' => 'pk_live_123',
        'payment_paymos_live_api_secret' => 'sk_live_123',
        'payment_paymos_live_project_id' => 'prj_live_123',
        'payment_paymos_live_webhook_secret' => 'whsec_live',
        'payment_paymos_api_base_url' => 'https://api.paymos.test',
        'payment_paymos_pending_status_id' => '1',
        'payment_paymos_paid_status_id' => '5',
        'payment_paymos_confirming_status_id' => '2',
        'payment_paymos_failed_status_id' => '10',
        'payment_paymos_cancelled_status_id' => '7',
    ), $overrides);

    PaymosOpenCart\Config::useConfigForTests(array(
        'environments' => array(
            'sandbox' => array(
                'base_url' => (string) $settings['payment_paymos_api_base_url'],
                'api_key' => (string) $settings['payment_paymos_sandbox_api_key'],
                'api_secret' => (string) $settings['payment_paymos_sandbox_api_secret'],
                'project_id' => (string) $settings['payment_paymos_sandbox_project_id'],
                'webhook_secret' => (string) $settings['payment_paymos_sandbox_webhook_secret'],
            ),
            'live' => array(
                'base_url' => (string) $settings['payment_paymos_api_base_url'],
                'api_key' => (string) $settings['payment_paymos_live_api_key'],
                'api_secret' => (string) $settings['payment_paymos_live_api_secret'],
                'project_id' => (string) $settings['payment_paymos_live_project_id'],
                'webhook_secret' => (string) $settings['payment_paymos_live_webhook_secret'],
            ),
        ),
    ));

    return $settings;
}

function opencart_order(array $overrides = array())
{
    return array_merge(array(
        'order_id' => 42,
        'total' => '100.00',
        'currency_code' => 'USD',
        'customer_id' => 77,
        'firstname' => 'Buyer',
        'lastname' => 'Example',
        'email' => 'buyer@example.com',
    ), $overrides);
}

function opencart_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

function opencart_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => 'oc_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

function paymos_opencart_reset_test_state()
{
    if (class_exists('PaymosOpenCart\\Config') && method_exists('PaymosOpenCart\\Config', 'resetForTests')) {
        PaymosOpenCart\Config::resetForTests();
    }
}

function paymos_opencart_write_generated_config($php)
{
    $config = eval('return ' . $php . ';');
    PaymosOpenCart\Config::useConfigForTests(is_array($config) ? $config : array());
}

final class FakeOpenCartAdapter implements PaymosOpenCart\OpenCartAdapterInterface
{
    /** @var array<int, array<string, mixed>> */
    public $orders = array();

    /** @var array<int, array<string, mixed>> */
    public $histories = array();

    /** @var array<int, array<string, mixed>> */
    public $logs = array();

    public function __construct()
    {
        $this->orders[42] = opencart_order();
    }

    public function getOrder($orderId)
    {
        $orderId = (int) $orderId;
        return isset($this->orders[$orderId]) ? $this->orders[$orderId] : array();
    }

    public function addOrderHistory($orderId, $orderStatusId, $comment, $notify = false)
    {
        $this->histories[] = array(
            'order_id' => (int) $orderId,
            'order_status_id' => (int) $orderStatusId,
            'comment' => (string) $comment,
            'notify' => (bool) $notify,
        );
    }

    public function log($message, array $context = array())
    {
        $this->logs[] = array(
            'message' => (string) $message,
            'context' => $context,
        );
    }

    public function orderAmount(array $order)
    {
        return PaymosOpenCart\OrderAmount::inOrderCurrency($order, new FakeOpenCartCurrency());
    }
}

/**
 * The parts of OpenCart 4's \Opencart\System\Library\Cart\Currency the plugin
 * relies on, with the platform's own arithmetic: format($number, $code,
 * $value, false) multiplies the base-currency figure by $value (or by the
 * currency's current rate when $value is 0) and rounds to the currency's
 * decimal places. Base currency here is EUR.
 */
final class FakeOpenCartCurrency
{
    /** @var array<string, array{value: float, decimal_place: int}> */
    public $currencies = array(
        'EUR' => array('value' => 1.0, 'decimal_place' => 2),
        'USD' => array('value' => 1.0, 'decimal_place' => 2),
        'GBP' => array('value' => 0.85, 'decimal_place' => 2),
        'JPY' => array('value' => 160.0, 'decimal_place' => 0),
        'KWD' => array('value' => 0.331, 'decimal_place' => 3),
    );

    public function format(float $number, string $currency, float $value = 0, bool $format = true)
    {
        if (!isset($this->currencies[$currency])) {
            return '';
        }
        if (!$value) {
            $value = $this->currencies[$currency]['value'];
        }
        $amount = round($value ? $number * $value : $number, $this->currencies[$currency]['decimal_place']);

        return $format ? number_format($amount, $this->currencies[$currency]['decimal_place']) . ' ' . $currency : $amount;
    }

    public function getDecimalPlace(string $currency): int
    {
        return isset($this->currencies[$currency]) ? $this->currencies[$currency]['decimal_place'] : 0;
    }

    public function has(string $currency): bool
    {
        return isset($this->currencies[$currency]);
    }
}
