<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Paymos\Payment;

require_once (defined('DIR_EXTENSION') ? DIR_EXTENSION : dirname(DIR_APPLICATION) . '/extension/') . 'paymos/system/library/paymos/src/Autoloader.php';

\PaymosOpenCart\Autoloader::register();

class Paymos extends \Opencart\System\Engine\Controller
{
    public function index(): string
    {
        $this->load->language('extension/paymos/payment/paymos');

        $data['button_confirm'] = $this->config->get('payment_paymos_button_text') ?: $this->language->get('button_confirm');
        $data['action'] = $this->url->link('extension/paymos/payment/paymos.checkout', '', true);

        return $this->load->view('extension/paymos/payment/paymos', $data);
    }

    public function checkout(): void
    {
        $this->load->language('extension/paymos/payment/paymos');
        $json = [];

        try {
            if (empty($this->session->data['order_id'])) {
                throw new \RuntimeException('OpenCart session does not contain order_id.');
            }

            \PaymosOpenCart\Migrations::ensure($this->db);

            $result = (new \PaymosOpenCart\GatewayCheckout(
                new \PaymosOpenCart\InvoiceStore($this->db),
                new \PaymosOpenCart\OpenCartAdapter($this->registry)
            ))->start((int) $this->session->data['order_id'], $this->settings());

            $json['redirect'] = $result['payment_url'];
        } catch (\Throwable $e) {
            $this->log->write('[Paymos] Checkout failed: ' . $e->getMessage());
            $json['error'] = $this->language->get('error_checkout');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback(): void
    {
        \PaymosOpenCart\Migrations::ensure($this->db);

        $rawBody = file_get_contents('php://input');
        $signature = '';
        if (isset($this->request->server['HTTP_X_WEBHOOK_SIGNATURE'])) {
            $signature = (string) $this->request->server['HTTP_X_WEBHOOK_SIGNATURE'];
        } elseif (isset($this->request->server['HTTP_X_PAYMOS_SIGNATURE'])) {
            $signature = (string) $this->request->server['HTTP_X_PAYMOS_SIGNATURE'];
        }

        $result = (new \PaymosOpenCart\CallbackProcessor(
            new \PaymosOpenCart\OpenCartAdapter($this->registry),
            new \PaymosOpenCart\InvoiceStore($this->db),
            new \PaymosOpenCart\EventStore($this->db)
        ))->handle($rawBody === false ? '' : $rawBody, $signature, $this->settings());

        $this->response->addHeader('Content-Type: text/plain');
        $this->addStatusHeader($result->statusCode());
        $this->response->setOutput($result->body());
    }

    private function addStatusHeader(int $statusCode): void
    {
        $messages = [
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            500 => 'Internal Server Error',
        ];
        $message = $messages[$statusCode] ?? 'OK';
        $protocol = isset($this->request->server['SERVER_PROTOCOL'])
            ? (string) $this->request->server['SERVER_PROTOCOL']
            : 'HTTP/1.1';

        $this->response->addHeader($protocol . ' ' . $statusCode . ' ' . $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $keys = [
            'payment_paymos_status',
            'payment_paymos_mode',
            'payment_paymos_title',
            'payment_paymos_button_text',
            'payment_paymos_invoice_lifetime',
            'payment_paymos_debug_logging',
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

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = $this->config->get($key);
        }

        return $settings;
    }
}
