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
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
        $data['webhook_url'] = rtrim((string) $this->config->get('config_url'), '/') . '/index.php?route=extension/paymos/payment/paymos.callback';
        $data['generated_config'] = \PaymosOpenCart\Config::hasGeneratedConfig();

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
            'payment_paymos_api_base_url',
            'payment_paymos_sandbox_api_key',
            'payment_paymos_sandbox_api_secret',
            'payment_paymos_sandbox_project_id',
            'payment_paymos_sandbox_webhook_secret',
            'payment_paymos_live_api_key',
            'payment_paymos_live_api_secret',
            'payment_paymos_live_project_id',
            'payment_paymos_live_webhook_secret',
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
        $data['payment_paymos_api_base_url'] = $data['payment_paymos_api_base_url'] ?: \PaymosOpenCart\Config::DEFAULT_BASE_URL;

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
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_paymos', $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
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
            'payment_paymos_api_base_url',
            'payment_paymos_sandbox_api_key',
            'payment_paymos_sandbox_api_secret',
            'payment_paymos_sandbox_project_id',
            'payment_paymos_sandbox_webhook_secret',
            'payment_paymos_live_api_key',
            'payment_paymos_live_api_secret',
            'payment_paymos_live_project_id',
            'payment_paymos_live_webhook_secret',
        ];

        foreach ($keys as $key) {
            $settings[$key] = $this->config->get($key);
        }

        return $settings;
    }
}
