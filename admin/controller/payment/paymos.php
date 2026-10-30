<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Paymos\Payment;

require_once (defined('DIR_EXTENSION') ? DIR_EXTENSION : dirname(DIR_APPLICATION) . '/extension/') . 'paymos/system/library/paymos/src/Autoloader.php';

\PaymosOpenCart\Autoloader::register();

class Paymos extends \Opencart\System\Engine\Controller
{
    public function index(): void
    {
        if ($this->user->hasPermission('modify', 'extension/paymos/payment/paymos')) {
            $this->registerRefundGuards();
            try {
                $this->registerRefundCron();
            } catch (\Throwable $error) {
                $this->log->write('[Paymos] Refund cron registration failed: ' . $error->getMessage());
            }
        }
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
        $data['refund_panel'] = $this->url->link('extension/paymos/payment/paymos.refundPanel', 'user_token=' . $this->session->data['user_token']);
        $data['refund_submit'] = $this->url->link('extension/paymos/payment/paymos.refund', 'user_token=' . $this->session->data['user_token']);
        $data['connect_start'] = $this->url->link('extension/paymos/payment/paymos.connectStart', 'user_token=' . $this->session->data['user_token']);
        $data['connect_poll'] = $this->url->link('extension/paymos/payment/paymos.connectPoll', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
        $data['webhook_url'] = $this->storeUrl() . '/index.php?route=extension/paymos/payment/paymos.callback';
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
                new \PaymosOpenCart\OpenCartAdapter($this->registry),
                null,
                \PaymosOpenCart\RefundAdapter::fromRegistry($this->registry, $this->settings())
            ))->run($this->settings());

            $json['success'] = sprintf($this->language->get('text_reconcile_success'), $count);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        // OpenCart 4.0.2.0 or later only (BUG-161): refuses older stores and
        // removes the extension row core has just added.
        $this->load->model('setting/extension');
        $installed = \PaymosOpenCart\Installer::install(
            $this->db,
            $this->model_setting_extension,
            $this->log,
            defined('VERSION') ? (string) VERSION : ''
        );
        if ($installed) {
            $this->registerRefundGuards();
            try {
                $this->registerRefundCron();
            } catch (\Throwable $error) {
                $this->log->write('[Paymos] Refund cron registration failed: ' . $error->getMessage());
            }
        }
    }

    public function uninstall(): void
    {
        $this->load->model('setting/cron');
        $cron = $this->model_setting_cron->getCronByCode(\PaymosOpenCart\Installer::REFUND_CRON_CODE);
        if ($cron && $cron['action'] === \PaymosOpenCart\Installer::REFUND_CRON_ACTION) {
            $this->model_setting_cron->deleteCronByCode(\PaymosOpenCart\Installer::REFUND_CRON_CODE);
        }
        \PaymosOpenCart\Installer::uninstall($this->db);
        $this->load->model('setting/event');
        \PaymosOpenCart\Installer::unregisterRefundGuards($this->model_setting_event);
    }

    public function refundPanel(): void
    {
        $this->refundResponse(false);
    }

    public function nativeReturnBefore(string &$route, array &$args): void
    {
        (new \PaymosOpenCart\NativeRefundGuard($this->db))->beforeReturn($route, $args);
    }

    public function refund(): void
    {
        $this->refundResponse(true);
    }

    private function refundResponse(bool $write): void
    {
        $json = array();
        try {
            \PaymosOpenCart\RefundAdapter::assertProtectedRequest($this->request->server, $this->request->get,
                $this->session->data, $this->user->hasPermission('modify', 'extension/paymos/payment/paymos'), $write);
            $orderId = isset($this->request->get['order_id']) ? $this->request->get['order_id'] : '';
            if (!is_scalar($orderId) || !preg_match('/^[1-9][0-9]*$/D', (string) $orderId)) {
                throw new \RuntimeException('refund_order_required');
            }
            \PaymosOpenCart\Migrations::ensure($this->db);
            if ($write) {
                $this->registerRefundCron();
            }
            $adapter = \PaymosOpenCart\RefundAdapter::fromRegistry($this->registry, $this->settings());
            $storeId = (int) $this->config->get('config_store_id');
            $json['success'] = $write ? $adapter->publicState($adapter->submit((int) $orderId, $storeId, $this->request->post))
                : $adapter->panel((int) $orderId, $storeId);
        } catch (\Throwable $error) {
            $this->log->write('[Paymos] Refund action failed: ' . $error->getMessage());
            $json = array('error' => \PaymosOpenCart\NativeRefundGuard::ERROR_MESSAGE, 'reason' => 'refund_action_unavailable');
        }
        http_response_code(200);
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function registerRefundCron(): void
    {
        $this->load->model('setting/cron');
        \PaymosOpenCart\Installer::registerRefundCron($this->model_setting_cron, $this->db);
    }

    private function registerRefundGuards(): void
    {
        $this->load->model('setting/event');
        \PaymosOpenCart\Installer::registerRefundGuards($this->model_setting_event);
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

    /**
     * The storefront root, as Paymos must see it.
     *
     * `config_url` is only editable in the ADDITIONAL-stores form; OpenCart never
     * writes it for the default store and offers no field for it there, so on a
     * normal single-store install it is simply absent. Reading only that setting
     * left the source URL empty, which made connecting impossible and rendered the
     * webhook address on this page without a host. `HTTP_CATALOG` is defined by
     * OpenCart's own admin/config.php on every install and is exactly the
     * storefront root, so it is the reliable source; `config_url` still wins when a
     * merchant has deliberately set it.
     */
    private function storeUrl(): string
    {
        $url = trim((string) $this->config->get('config_url'));
        if ($url === '' && defined('HTTP_CATALOG')) {
            $url = (string) HTTP_CATALOG;
        }

        return rtrim($url, '/');
    }

    private function sourceUrl(): string
    {
        $url = $this->storeUrl();
        if (stripos($url, 'https://') !== 0) {
            // Say what to fix. Paymos rejects a non-HTTPS store, and its own error
            // ("CMS and HTTPS store URL are required") does not tell the merchant
            // which of their settings is at fault.
            throw new \RuntimeException(
                'Paymos needs this store to be reachable over HTTPS. Serve the storefront over HTTPS'
                . ' and make sure the store URL in your OpenCart config.php uses https://'
                . ($url === '' ? '' : ' (currently "' . $url . '").')
            );
        }

        return $url;
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
