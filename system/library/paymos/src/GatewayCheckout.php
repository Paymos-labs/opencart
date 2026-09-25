<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\StatusMapper;

final class GatewayCheckout
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var OpenCartAdapterInterface */
    private $opencart;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, OpenCartAdapterInterface $opencart, ?callable $clientFactory = null)
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

        // In the order currency, not the base-currency `total` (see OrderAmount).
        $amount = $this->opencart->orderAmount($order);
        $currency = strtoupper($this->field($order, 'currency_code'));
        $existing = $this->store->findByOpenCartOrderId($orderId);

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)
            && $this->keepsExistingInvoice($existing, $config)) {
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

    /**
     * Whether the Paymos invoice behind a matching snapshot is still the one to
     * send the buyer to.
     *
     * A matching amount is not enough: the server answers a repeated
     * external_order_id with the same invoice whatever became of it, and a buyer
     * returning after it ended would land on an expired checkout. Its deadline is
     * the server's, not a copy kept here: confirming a network moves expires_at to
     * now + InvoiceOptions.PaymentTtl and sends no webhook. So: a row that already
     * ended unpaid is renewed at once (that final status came from the server and
     * never changes again); a paid one is kept (a second invoice would invite a
     * second payment); anything else is read back from the server (one GET) and
     * renewed only if the server says it ended unpaid or was never started before
     * its deadline (InvoiceRenewal). An invoice the server holds open — network
     * picked, funds confirming, part paid — is kept. When the server cannot be
     * reached the existing link is kept — the checkout it leads to is down just the
     * same.
     *
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row, Config $config)
    {
        if (InvoiceRenewal::isRequired($row)) {
            return false;
        }
        if (StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '')) {
            return true;
        }

        try {
            $invoice = $this->client($config)->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            return true;
        }

        if (!InvoiceRenewal::isRequired($invoice)) {
            return true;
        }

        $status = $this->responseField($invoice, array('status'));
        if ($status !== '') {
            $this->store->updateStatus((string) $row['paymos_invoice_id'], $status);
        }

        return false;
    }

    private function client(Config $config)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config);
        }

        return new Client($config->clientConfig());
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
