<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Paymos\Payment;

class Paymos extends \Opencart\System\Engine\Model
{
    public function getMethods(array $address = []): array
    {
        if (!$this->config->get('payment_paymos_status')) {
            return [];
        }

        $this->load->language('extension/paymos/payment/paymos');

        return [
            'code' => 'paymos',
            'name' => $this->config->get('payment_paymos_title') ?: $this->language->get('text_title'),
            'option' => [
                'paymos' => [
                    'code' => 'paymos.paymos',
                    'name' => $this->config->get('payment_paymos_title') ?: $this->language->get('text_title'),
                ],
            ],
            'sort_order' => (int) $this->config->get('payment_paymos_sort_order'),
        ];
    }

    public function getMethod(array $address = []): array
    {
        $methods = $this->getMethods($address);
        if (!$methods) {
            return [];
        }

        return [
            'code' => $methods['code'],
            'title' => $methods['name'],
            'terms' => '',
            'sort_order' => $methods['sort_order'],
        ];
    }
}
