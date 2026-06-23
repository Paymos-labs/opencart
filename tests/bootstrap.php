<?php

declare(strict_types=1);

define('PAYMOS_OPENCART_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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
    return array_merge(array(
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
    $config = PAYMOS_OPENCART_LIBRARY_DIR . 'paymos-config.php';
    if (is_file($config)) {
        unlink($config);
    }

    if (class_exists('PaymosOpenCart\\Config') && method_exists('PaymosOpenCart\\Config', 'resetForTests')) {
        PaymosOpenCart\Config::resetForTests();
    }
}

function paymos_opencart_write_generated_config($php)
{
    file_put_contents(PAYMOS_OPENCART_LIBRARY_DIR . 'paymos-config.php', "<?php\n\nreturn " . $php . ";\n");

    if (class_exists('PaymosOpenCart\\Config') && method_exists('PaymosOpenCart\\Config', 'resetForTests')) {
        PaymosOpenCart\Config::resetForTests();
    }
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
}
