<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;

final class GatewayCheckout
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var OpenCartAdapterInterface */
    private $opencart;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, OpenCartAdapterInterface $opencart, callable $clientFactory = null)
    {
        $this->store = $store;
        $this->opencart = $opencart;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public function start($orderId, array $settings)
    {
        $config = Config::fromSettings($settings);
        $order = $this->opencart->getOrder($orderId);
        if (count($order) === 0) {
            throw new \RuntimeException('OpenCart order was not found.');
        }

        $amount = $this->amount($this->field($order, 'total'));
        $currency = strtoupper($this->field($order, 'currency_code'));
        $existing = $this->store->findByOpenCartOrderId($orderId);

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)) {
            return array(
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'reused' => '1',
            );
        }

        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = 'oc_' . (int) $orderId . '_' . $renewCount;
        $payload = $this->createPayload($order, $config, $amount, $currency, $externalOrderId);
        $response = $this->client($config)->invoices()->create($payload);

        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        if ($paymosInvoiceId === '') {
            $paymosInvoiceId = $this->responseField($response, array('id'));
        }

        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymentUrl === '') {
            $paymentUrl = $this->responseField($response, array('checkout_url'));
        }
        if ($paymentUrl === '') {
            $paymentUrl = $this->responseField($response, array('url'));
        }
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }

        $this->store->save(array(
            'opencart_order_id' => (int) $orderId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $this->responseField($response, array('status')) ?: 'created',
            'renew_count' => $renewCount,
        ));

        $this->opencart->addOrderHistory(
            $orderId,
            $config->statusId('pending'),
            'Awaiting Paymos payment. Paymos invoice: ' . $paymosInvoiceId,
            false
        );

        return array(
            'invoice_id' => $paymosInvoiceId,
            'payment_url' => $paymentUrl,
            'reused' => '0',
        );
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function createPayload(array $order, Config $config, $amount, $currency, $externalOrderId)
    {
        $payload = array(
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
        );

        $clientId = $this->clientId($order);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, $amount, $currency, Config $config)
    {
        return (string) $row['amount'] === (string) $amount
            && strtoupper((string) $row['currency']) === strtoupper((string) $currency)
            && (string) $row['project_id'] === $config->projectId()
            && (string) $row['environment'] === $config->environment()
            && trim((string) $row['payment_url']) !== '';
    }

    private function client(Config $config)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config);
        }

        return new Client($config->clientConfig());
    }

    private function amount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $order
     */
    private function clientId(array $order)
    {
        $customerId = $this->field($order, 'customer_id');
        return $customerId !== '' && $customerId !== '0' ? $customerId : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function field(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path)
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
