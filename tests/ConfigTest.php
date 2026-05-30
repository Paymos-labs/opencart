<?php

declare(strict_types=1);

use PaymosOpenCart\Config;

function test_opencart_config_builds_client_config_and_secret_map()
{
    $config = Config::fromSettings(opencart_settings());

    assertSameValue('sandbox', $config->environment(), 'mode setting must select sandbox environment.');
    assertSameValue('prj_123', $config->projectId(), 'sandbox project id must come from active OpenCart settings.');
    assertSameValue('https://api.paymos.test', $config->clientConfig()->baseUrl(), 'base URL must be normalized into SDK config.');
    assertSameValue('pk_test_123', $config->clientConfig()->apiKey(), 'sandbox mode must use sandbox API key.');
    assertSameValue(array('sandbox' => 'whsec_sandbox', 'live' => 'whsec_live'), $config->webhookSecrets(), 'both webhook secrets should be available for callback verification.');
    assertSameValue(43200, $config->invoiceLifetimeSeconds(), '12 hour invoice lifetime must be seconds.');
}

function test_opencart_generated_config_supplies_read_only_credentials()
{
    paymos_opencart_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_zip',
                'api_secret' => 'sk_test_zip',
                'project_id' => 'prj_zip_sandbox',
                'webhook_secret' => 'whsec_zip_sandbox',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_zip',
                'api_secret' => 'sk_live_zip',
                'project_id' => 'prj_zip_live',
                'webhook_secret' => 'whsec_zip_live',
            ),
        ),
    )");

    $config = Config::fromSettings(opencart_settings(array(
        'payment_paymos_mode' => 'live',
        'payment_paymos_live_api_key' => '',
        'payment_paymos_live_api_secret' => '',
        'payment_paymos_live_project_id' => '',
        'payment_paymos_live_webhook_secret' => '',
    )));

    assertSameValue('live', $config->environment(), 'generated config must still honor OpenCart mode switch.');
    assertSameValue('pk_live_zip', $config->clientConfig()->apiKey(), 'live API key must come from generated config.');
    assertSameValue('sk_live_zip', $config->clientConfig()->apiSecret(), 'live API secret must come from generated config.');
    assertSameValue('prj_zip_live', $config->projectId(), 'live project id must come from generated config.');
    assertSameValue(array('sandbox' => 'whsec_zip_sandbox', 'live' => 'whsec_zip_live'), $config->webhookSecrets(), 'generated config must provide both webhook secrets.');
}

function test_opencart_config_rejects_mismatched_api_secret_environment()
{
    $settings = opencart_settings(array(
        'payment_paymos_mode' => 'live',
        'payment_paymos_live_api_secret' => 'sk_test_123',
    ));

    try {
        Config::fromSettings($settings);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('live API secret', $e->getMessage(), 'mismatched API secret error must identify the field.');
        return;
    }

    throw new RuntimeException('Config must reject API key/API secret environment mismatch.');
}

function test_opencart_config_rejects_missing_selected_environment_webhook_secret()
{
    $settings = opencart_settings(array(
        'payment_paymos_live_webhook_secret' => '',
        'payment_paymos_mode' => 'live',
    ));

    try {
        Config::fromSettings($settings);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('live webhook secret', strtolower($e->getMessage()), 'missing selected environment webhook secret must be explicit.');
        return;
    }

    throw new RuntimeException('Config must reject live mode without live webhook secret.');
}
