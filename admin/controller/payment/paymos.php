<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Paymos\Payment;

require_once (defined('DIR_EXTENSION') ? DIR_EXTENSION : dirname(DIR_APPLICATION) . '/extension/') . 'paymos/system/library/paymos/src/Autoloader.php';

\PaymosOpenCart\Autoloader::register();

class Paymos extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        $this->load->language('extension/paymos/payment/paymos');
        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token']),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment'),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/paymos/payment/paymos', 'user_token=' . $this->session->data['user_token']),
        ];

        $data['save'] = $this->url->link('extension/paymos/payment/paymos.save', 'user_token=' . $this->session->data['user_token']);
        $data['reconcile'] = $this->url->link('extension/paymos/payment/paymos.reconcile', 'user_token=' . $this->session->data['user_token']);
        $data['connect_start'] = $this->url->link('extension/paymos/payment/paymos.connectStart', 'user_token=' . $this->session->data['user_token']);
        $data['connect_poll'] = $this->url->link('extension/paymos/payment/paymos.connectPoll', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
        $data['webhook_url'] = rtrim((string) $this->config->get('config_url'), '/') . '/index.php?route=extension/paymos/payment/paymos.callback';
        $data['generated_config'] = trim((string) $this->config->get('payment_paymos_credentials')) !== '';

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $fields = [
            'payment_paymos_status',
            'payment_paymos_mode',
            'payment_paymos_title',
            'payment_paymos_button_text',
            'payment_paymos_pending_status_id',
            'payment_paymos_confirming_status_id',
            'payment_paymos_paid_status_id',
            'payment_paymos_failed_status_id',
            'payment_paymos_cancelled_status_id',
            'payment_paymos_sort_order',
        ];

        foreach ($fields as $field) {
            $data[$field] = $this->config->get($field);
        }

        $data['payment_paymos_mode'] = $data['payment_paymos_mode'] ?: 'sandbox';
        $data['payment_paymos_title'] = $data['payment_paymos_title'] ?: $this->language->get('text_title_default');
        $data['payment_paymos_button_text'] = $data['payment_paymos_button_text'] ?: $this->language->get('button_confirm');
        $data['payment_paymos_pending_status_id'] = $data['payment_paymos_pending_status_id'] ?: 1;
        $data['payment_paymos_confirming_status_id'] = $data['payment_paymos_confirming_status_id'] ?: 2;
        $data['payment_paymos_paid_status_id'] = $data['payment_paymos_paid_status_id'] ?: 5;
        $data['payment_paymos_failed_status_id'] = $data['payment_paymos_failed_status_id'] ?: 10;
        $data['payment_paymos_cancelled_status_id'] = $data['payment_paymos_cancelled_status_id'] ?: 7;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/paymos/payment/paymos', $data));
    }

    public function save(): void
    {
        $this->load->language('extension/paymos/payment/paymos');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/paymos/payment/paymos')) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            foreach (array_keys($this->request->post) as $key) {
                if (preg_match('/^payment_paymos_(sandbox|live)_(api_key|api_secret|project_id|webhook_secret)$/', (string) $key)) {
                    unset($this->request->post[$key]);
                }
            }
            $credentials = trim((string) $this->config->get('payment_paymos_credentials'));
            if ($credentials !== '') {
                // editSetting() replaces every value with this prefix. Preserve
                // the opaque envelope without ever sending it through the form.
                $this->request->post['payment_paymos_credentials'] = $credentials;
            }
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_paymos', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function connectStart(): void
    {
        $this->connectResponse(function (): array {
            // The admin page posts its own URL so approval can return the merchant to it.
            // Paymos drops it unless it shares an origin with the store URL.
            $returnUrl = isset($this->request->post['paymos_return_url'])
                ? (string) $this->request->post['paymos_return_url']
                : '';
            $state = $this->connectClient()->start('opencart', $this->sourceUrl(), $returnUrl);
            $this->saveProtected('payment_paymos_connect_state', array(
                'schema' => 1,
                'expires_at' => time() + (int) $state['expires_in'],
                'state' => $state,
            ), 'paymos-opencart-connect-state-v1');
            return array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            );
        });
    }

    public function connectPoll(): void
    {
        $this->connectResponse(function (): array {
            $payload = $this->loadProtected('payment_paymos_connect_state', 'paymos-opencart-connect-state-v1');
            if (!isset($payload['state']['device_code']) || time() >= (int) ($payload['expires_at'] ?? 0)) {
                throw new \RuntimeException('No active Paymos connection request.');
            }
            $result = $this->connectClient()->poll((string) $payload['state']['device_code']);
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'opencart' || rtrim((string) $result['source_url'], '/') !== $this->sourceUrl()) {
                    throw new \RuntimeException('Paymos connection response does not match this store.');
                }
                $this->saveProtected('payment_paymos_credentials', array(
                    'schema' => 1,
                    'environments' => $result['credentials'],
                ), 'paymos-opencart-credentials-v1');
                $this->deleteSettingValue('payment_paymos_connect_state');
                return array('status' => 'connected');
            }
            if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
                return array('status' => $result['status']);
            }
            $this->deleteSettingValue('payment_paymos_connect_state');
            throw new \RuntimeException('Paymos connection was denied or expired.');
        });
    }

    public function reconcile(): void
    {
        $this->load->language('extension/paymos/payment/paymos');
        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/paymos/payment/paymos')) {
            $json['error'] = $this->language->get('error_permission');
        }

        if (!$json) {
            \PaymosOpenCart\Migrations::ensure($this->db);
            $count = (new \PaymosOpenCart\Reconciler(
                new \PaymosOpenCart\InvoiceStore($this->db),
                new \PaymosOpenCart\OpenCartAdapter($this->registry)
            ))->run($this->settings());

            $json['success'] = sprintf($this->language->get('text_reconcile_success'), $count);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        \PaymosOpenCart\Migrations::install($this->db);
    }

    public function uninstall(): void
    {
        \PaymosOpenCart\Migrations::uninstall($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = [];
        foreach ($this->config->get('payment_paymos') ?: [] as $key => $value) {
            $settings[$key] = $value;
        }

        $keys = [
            'payment_paymos_status',
            'payment_paymos_mode',
            'payment_paymos_title',
            'payment_paymos_button_text',
            'payment_paymos_pending_status_id',
            'payment_paymos_confirming_status_id',
            'payment_paymos_paid_status_id',
            'payment_paymos_failed_status_id',
            'payment_paymos_cancelled_status_id',
        ];

        foreach ($keys as $key) {
            $settings[$key] = $this->config->get($key);
        }

        $settings['payment_paymos_credentials'] = $this->config->get('payment_paymos_credentials');
        $settings['payment_paymos_encryption_key'] = $this->encryptionKey();

        return $settings;
    }

    private function connectResponse(callable $callback): void
    {
        $json = array();
        if (!$this->user->hasPermission('modify', 'extension/paymos/payment/paymos')) {
            $json['error'] = 'Access denied.';
        } else {
            try {
                $json['success'] = $callback();
            } catch (\Throwable $exception) {
                $json['error'] = $exception->getMessage();
            }
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function connectClient(): \Paymos\Connect\DeviceConnectClient
    {
        return new \Paymos\Connect\DeviceConnectClient('https://app.paymos.io');
    }

    private function sourceUrl(): string
    {
        return rtrim((string) $this->config->get('config_url'), '/');
    }

    private function encryptionKey(): string
    {
        $key = trim((string) $this->config->get('config_encryption'));
        if ($key === '') {
            throw new \RuntimeException('OpenCart encryption key is not configured.');
        }
        return $key;
    }

    private function saveProtected(string $setting, array $payload, string $aad): void
    {
        $encoded = \Paymos\Plugin\AesGcmEnvelope::seal($payload, $this->encryptionKey(), $aad);
        $this->load->model('setting/setting');

        // editValue() is a bare UPDATE and cannot insert. On a freshly installed
        // extension there are no payment_paymos rows yet, so it silently wrote
        // nothing — the connect state vanished and polling answered "No active
        // Paymos connection request" forever, with no way for the merchant to
        // finish connecting. Re-saving the whole group inserts what is missing.
        $settings = $this->model_setting_setting->getSetting('payment_paymos');
        $settings[$setting] = $encoded;
        $this->model_setting_setting->editSetting('payment_paymos', $settings);
    }

    private function loadProtected(string $setting, string $aad): array
    {
        $encoded = trim((string) $this->config->get($setting));
        return $encoded === '' ? array() : \Paymos\Plugin\AesGcmEnvelope::open($encoded, $this->encryptionKey(), $aad);
    }

    private function deleteSettingValue(string $setting): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->editValue('payment_paymos', $setting, '');
    }
}
