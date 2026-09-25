<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
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
     * The catalog language key for what the buyer reads when start() throws.
     * A blocked replacement means the old invoice may still be paid, so the
     * buyer is asked to contact the store; the generic message tells them to
     * pay another way, which could charge them twice (BUG-180).
     *
     * @return string
     */
    public static function buyerErrorKey(\Throwable $e)
    {
        return $e instanceof InvoiceReplacementBlockedException ? 'error_replacement_blocked' : 'error_checkout';
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

        if (is_array($existing)) {
            $this->closeBeforeReplacing($orderId, $existing, $config);
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
     * same. A 404 is not proof the invoice is gone (see closeBeforeReplacing), so
     * it goes on to the replacement, which refuses it.
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

    /**
     * The order's invoice is about to be replaced (the order changed, or the
     * invoice can no longer be paid). Cancel it on the server first, or the
     * buyer could pay both (BUG-166): the SDK cancels it, or confirms from the
     * server that it ended unpaid. Anything else — paid, still payable, 404,
     * no answer — leaves the order on its old invoice, notes it for manual
     * review and stops the checkout.
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing($orderId, array $row, Config $config)
    {
        $environment = (string) $row['environment'];
        $recorded = isset($row['status']) ? (string) $row['status'] : '';
        $result = (new InvoiceReplacement(function () use ($config, $environment) {
            return $this->client($config, $environment);
        }))->close((string) $row['paymos_invoice_id'], $recorded);

        if ($result->isClosed()) {
            // Record the final status before the new row exists: the old
            // invoice's own webhook (invoice.cancelled after our cancel) then
            // arrives for a row that is already final and is ignored as stale,
            // instead of cancelling the order that now has a new invoice.
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->store->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }

            return;
        }

        $this->opencart->addOrderHistory(
            $orderId,
            $config->statusId('confirming'),
            'Paymos payment needs manual review. ' . $result->summary(),
            false
        );

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * @param string|null $environment The environment the invoice lives in; the selected mode by default.
     */
    private function client(Config $config, $environment = null)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config, $environment);
        }

        return new Client($environment === null
            ? $config->clientConfig()
            : $config->clientConfigForEnvironment($environment));
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
