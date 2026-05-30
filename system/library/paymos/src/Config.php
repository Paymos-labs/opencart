<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\ClientConfig;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed>|null */
    private static $generated;

    /** @var array<string, mixed> */
    private $settings;

    /**
     * @param array<string, mixed> $settings
     */
    private function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings)
    {
        $config = new self($settings);
        $environment = $config->environment();

        $config->assertEnvironmentConfigured($environment);
        $secrets = $config->webhookSecrets();
        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('At least one Paymos webhook secret is required.');
        }
        if (!isset($secrets[$environment])) {
            throw new \InvalidArgumentException('Paymos ' . $environment . ' webhook secret is required for the selected mode.');
        }

        return $config;
    }

    public function clientConfig()
    {
        return $this->clientConfigForEnvironment($this->environment());
    }

    public function clientConfigForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $this->assertEnvironmentConfigured($environment);

        return new ClientConfig(
            $this->apiKey($environment),
            $this->apiSecret($environment),
            $this->apiBaseUrlForEnvironment($environment),
            30
        );
    }

    public function apiKey($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'api_key');
    }

    public function apiSecret($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'api_secret');
    }

    public function projectId($environment = null)
    {
        return $this->projectIdForEnvironment($environment === null ? $this->environment() : $environment);
    }

    public function projectIdForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'project_id');
    }

    public function apiBaseUrlForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        if (isset($generated['base_url']) && is_scalar($generated['base_url']) && trim((string) $generated['base_url']) !== '') {
            return rtrim((string) $generated['base_url'], '/');
        }

        $baseUrl = $this->setting('payment_paymos_api_base_url');
        return $baseUrl === '' ? self::DEFAULT_BASE_URL : rtrim($baseUrl, '/');
    }

    public function environment()
    {
        $mode = strtolower($this->setting('payment_paymos_mode'));
        return in_array($mode, array('sandbox', 'live'), true) ? $mode : 'sandbox';
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();

        foreach (array('sandbox', 'live') as $environment) {
            $secret = $this->environmentValue($environment, 'webhook_secret');
            if ($secret !== '') {
                $secrets[$environment] = $secret;
            }
        }

        return $secrets;
    }

    public function invoiceLifetimeSeconds()
    {
        $hours = (int) $this->setting('payment_paymos_invoice_lifetime');
        if ($hours < 1 || $hours > 12) {
            $hours = 12;
        }

        return $hours * 3600;
    }

    public function buttonText()
    {
        $text = $this->setting('payment_paymos_button_text');
        return $text === '' ? 'Pay with Paymos' : $text;
    }

    public function statusId($action)
    {
        $key = 'payment_paymos_' . $action . '_status_id';
        $value = (int) $this->setting($key);
        return $value > 0 ? $value : 1;
    }

    public function debugLogging()
    {
        return in_array(strtolower($this->setting('payment_paymos_debug_logging')), array('1', 'on', 'yes', 'true'), true);
    }

    public static function hasGeneratedConfig()
    {
        $generated = self::generated();
        return isset($generated['environments']) && is_array($generated['environments']);
    }

    public static function resetForTests()
    {
        self::$generated = null;
    }

    private function assertEnvironmentConfigured($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $fields = array(
            'API key' => 'api_key',
            'API secret' => 'api_secret',
            'project id' => 'project_id',
            'webhook secret' => 'webhook_secret',
        );

        foreach ($fields as $label => $field) {
            if ($this->environmentValue($environment, $field) === '') {
                throw new \InvalidArgumentException('Paymos OpenCart config is missing ' . $environment . ' ' . $label . '.');
            }
        }

        $this->assertApiKeyMatchesEnvironment($environment);
        $this->assertApiSecretMatchesEnvironment($environment);
    }

    private function assertApiKeyMatchesEnvironment($environment)
    {
        $apiKey = $this->apiKey($environment);
        if ($environment === 'sandbox' && strpos($apiKey, 'pk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandbox API key must start with pk_test_.');
        }
        if ($environment === 'live' && strpos($apiKey, 'pk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos live API key must start with pk_live_.');
        }
    }

    private function assertApiSecretMatchesEnvironment($environment)
    {
        $apiSecret = $this->apiSecret($environment);
        if ($environment === 'sandbox' && strpos($apiSecret, 'sk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandbox API secret must start with sk_test_.');
        }
        if ($environment === 'live' && strpos($apiSecret, 'sk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos live API secret must start with sk_live_.');
        }
    }

    private function environmentValue($environment, $field)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        if (isset($generated[$field]) && is_scalar($generated[$field]) && trim((string) $generated[$field]) !== '') {
            return trim((string) $generated[$field]);
        }

        return $this->setting('payment_paymos_' . $environment . '_' . $field);
    }

    private function normalizeEnvironment($environment)
    {
        $environment = strtolower(trim((string) $environment));
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        return $environment;
    }

    private function setting($key)
    {
        return isset($this->settings[$key]) && is_scalar($this->settings[$key])
            ? trim((string) $this->settings[$key])
            : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function generatedEnvironment($environment)
    {
        $generated = self::generated();
        if (!isset($generated['environments']) || !is_array($generated['environments'])) {
            return array();
        }

        $environments = $generated['environments'];
        return isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();
    }

    /**
     * @return array<string, mixed>
     */
    private static function generated()
    {
        if (self::$generated !== null) {
            return self::$generated;
        }

        $file = dirname(__DIR__) . '/paymos-config.php';
        if (!is_readable($file)) {
            self::$generated = array();
            return self::$generated;
        }

        $config = require $file;
        self::$generated = is_array($config) ? $config : array();
        return self::$generated;
    }
}
