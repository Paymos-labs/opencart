<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\ClientConfig;
use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed> */
    private static $testConfig = array();

    /** @var array<string, mixed> */
    private $settings;

    /** @var array<string, array<string, string>>|null */
    private $encryptedEnvironments;

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
        $encrypted = $this->encryptedEnvironments();
        if (isset($encrypted[$environment]['base_url']) && trim((string) $encrypted[$environment]['base_url']) !== '') {
            return rtrim((string) $encrypted[$environment]['base_url'], '/');
        }
        $test = self::testEnvironment($environment);
        if (isset($test['base_url']) && is_scalar($test['base_url']) && trim((string) $test['base_url']) !== '') {
            return rtrim((string) $test['base_url'], '/');
        }

        return self::DEFAULT_BASE_URL;
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

    public function statusId($action)
    {
        $key = 'payment_paymos_' . $action . '_status_id';
        $value = (int) $this->setting($key);
        return $value > 0 ? $value : 1;
    }

    public static function resetForTests()
    {
        self::$testConfig = array();
    }

    /** @param array<string, mixed> $config */
    public static function useConfigForTests(array $config)
    {
        self::$testConfig = $config;
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
        $encrypted = $this->encryptedEnvironments();
        if (isset($encrypted[$environment][$field]) && trim((string) $encrypted[$environment][$field]) !== '') {
            return trim((string) $encrypted[$environment][$field]);
        }
        $test = self::testEnvironment($environment);
        if (isset($test[$field]) && is_scalar($test[$field]) && trim((string) $test[$field]) !== '') {
            return trim((string) $test[$field]);
        }
        return '';
    }

    /** @return array<string, array<string, string>> */
    private function encryptedEnvironments()
    {
        if ($this->encryptedEnvironments !== null) {
            return $this->encryptedEnvironments;
        }
        $encoded = $this->setting('payment_paymos_credentials');
        $key = $this->setting('payment_paymos_encryption_key');
        if ($encoded === '' || $key === '') {
            $this->encryptedEnvironments = array();
            return $this->encryptedEnvironments;
        }
        $payload = AesGcmEnvelope::open($encoded, $key, 'paymos-opencart-credentials-v1');
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        $this->encryptedEnvironments = CredentialSet::normalize($payload['environments']);
        return $this->encryptedEnvironments;
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

    /** @return array<string, mixed> */
    private static function testEnvironment($environment)
    {
        $environments = isset(self::$testConfig['environments']) && is_array(self::$testConfig['environments'])
            ? self::$testConfig['environments']
            : array();
        return isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();
    }

}
