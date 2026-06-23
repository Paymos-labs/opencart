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
        // OpenCart 4 dispatches the checkout confirm button to the payment
        // extension's confirm() method (see core cod.php / free_checkout.php).
        $data['action'] = $this->url->link('extension/paymos/payment/paymos.confirm', '', true);

        return $this->load->view('extension/paymos/payment/paymos', $data);
    }

    public function confirm(): void
    {
        $this->load->language('extension/paymos/payment/paymos');
        $json = [];

        // Validate the order the same way the core gateways do: the session must
        // carry an order_id that resolves to a real order via model checkout/order.
        if (isset($this->session->data['order_id'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
            if (!$order_info) {
                $json['error'] = $this->language->get('error_checkout');
            }
        } else {
            $json['error'] = $this->language->get('error_checkout');
        }

        // Guard: only act when Paymos is the chosen payment method. Without this,
        // hitting this route while another gateway was selected would still mint a
        // Paymos invoice for the order (core gateways guard on the method code).
        if (!isset($this->session->data['payment_method'])
            || $this->session->data['payment_method']['code'] != 'paymos.paymos') {
            $json['error'] = $this->language->get('error_checkout');
        }

        if (!$json) {
            try {
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
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback(): void
    {
        \PaymosOpenCart\Migrations::ensure($this->db);

        $rawBody = file_get_contents('php://input');
        $signature = isset($this->request->server['HTTP_X_WEBHOOK_SIGNATURE'])
            ? (string) $this->request->server['HTTP_X_WEBHOOK_SIGNATURE']
            : '';

        $result = (new \PaymosOpenCart\CallbackProcessor(
            new \PaymosOpenCart\OpenCartAdapter($this->registry),
            new \PaymosOpenCart\InvoiceStore($this->db),
            new \PaymosOpenCart\EventStore($this->db)
        ))->handle($rawBody === false ? '' : $rawBody, $signature, $this->settings());

        $this->response->addHeader('Content-Type: text/plain');
        // Set the status via http_response_code(), not a hand-built "HTTP/1.1 …"
        // status line: a raw status line is invalid under HTTP/2 and the front
        // controller / web server may discard it, returning 200 with a junk
        // header — the Paymos webhook worker would then treat a failed callback
        // as delivered and never retry.
        http_response_code($result->statusCode());
        $this->response->setOutput($result->body());
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
