<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class CallbackProcessor
{
    /** @var OpenCartAdapterInterface */
    private $opencart;

    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        OpenCartAdapterInterface $opencart,
        InvoiceStoreInterface $invoiceStore,
        EventStoreInterface $eventStore,
        callable $clientFactory = null
    ) {
        $this->opencart = $opencart;
        $this->invoiceStore = $invoiceStore;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function handle($rawBody, $signatureHeader, array $settings, $now = null)
    {
        try {
            $config = Config::fromSettings($settings);
            $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();
                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $settings, true);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            $this->opencart->log('Paymos duplicate webhook ignored.', array('duplicate' => true));
            return new CallbackResult(200, 'OK', true);
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->opencart->log('Paymos OpenCart configuration error.', array('error' => $e->getMessage()));
            return new CallbackResult(500, 'Configuration error');
        } catch (\RuntimeException $e) {
            $this->releaseEvent();
            $this->opencart->log('Paymos webhook processing failed.', array('error' => $e->getMessage()));
            return new CallbackResult(400, 'Processing failed');
        }
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    public function applyTrustedInvoice(array $invoice, array $row, array $settings, $now)
    {
        $invoiceId = $this->field($invoice, array('invoice_id'));
        $status = $this->field($invoice, array('status'));
        $event = new WebhookEvent(array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => $this->eventTypeForStatus($status),
            'occurred_at' => (int) $now,
            'data' => $invoice,
        ));

        return $this->applyEventToOpenCart($event, (string) $row['environment'], $row, $settings, false);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function applyVerifiedEvent(WebhookEvent $event, $environment, array $settings, $reverseVerify)
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing external order id.');
        }

        $row = $this->invoiceStore->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos OpenCart invoice snapshot was not found.');
        }

        return $this->applyEventToOpenCart($event, $environment, $row, $settings, $reverseVerify);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    private function applyEventToOpenCart(WebhookEvent $event, $environment, array $row, array $settings, $reverseVerify)
    {
        $config = Config::fromSettings($settings);
        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($reverseVerify && $this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment, $settings)))->verify($event, array(
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ));

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());

        if ($action === StatusMapper::ACTION_IGNORE) {
            return false;
        }

        $order = $this->opencart->getOrder((int) $row['opencart_order_id']);
        if (count($order) === 0) {
            throw new \RuntimeException('OpenCart order for Paymos invoice snapshot was not found.');
        }

        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $currentAmount = $this->formatAmount($this->scalar($order, 'total', $row['amount']));
            $currentCurrency = strtoupper($this->scalar($order, 'currency_code', $row['currency']));
            if (!AmountGuard::isSafeToComplete(
                $row['amount'],
                $row['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            )) {
                throw new \RuntimeException(AmountGuard::mismatchSummary(
                    $row['amount'],
                    $row['currency'],
                    $currentAmount,
                    $currentCurrency,
                    $event->orderAmount(),
                    $event->orderCurrency()
                ));
            }
        }

        $statusId = $this->statusIdForAction($config, $action);
        $this->opencart->addOrderHistory(
            (int) $row['opencart_order_id'],
            $statusId,
            $this->commentForAction($action, $event),
            false
        );

        return $action === StatusMapper::ACTION_PAYMENT_COMPLETE;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, $environment)
    {
        if ((string) $row['environment'] !== (string) $environment) {
            throw new \RuntimeException('Paymos event environment does not match OpenCart invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match OpenCart invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match OpenCart invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match OpenCart invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function client($environment, array $settings)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client(Config::fromSettings($settings)->clientConfigForEnvironment($environment));
    }

    private function statusIdForAction(Config $config, $action)
    {
        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
                return $config->statusId('confirming');
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $config->statusId('paid');
            case StatusMapper::ACTION_FAIL_ORDER:
                return $config->statusId('failed');
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $config->statusId('cancelled');
            case StatusMapper::ACTION_AWAITING_PAYMENT:
            default:
                return $config->statusId('pending');
        }
    }

    private function commentForAction($action, WebhookEvent $event)
    {
        $invoice = $event->invoiceId();
        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'Paymos payment is confirming. Invoice: ' . $invoice;
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return 'Paymos payment completed. Invoice: ' . $invoice;
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'Paymos payment failed or remained underpaid. Invoice: ' . $invoice;
            case StatusMapper::ACTION_CANCEL_ORDER:
                return 'Paymos invoice expired or was cancelled. Invoice: ' . $invoice;
            default:
                return 'Awaiting Paymos payment. Invoice: ' . $invoice;
        }
    }

    private function eventTypeForStatus($status)
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return ((string) $status === 'paid_over') ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return ((string) $status === 'expired') ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, $key, $fallback)
    {
        return isset($source[$key]) && is_scalar($source[$key]) && trim((string) $source[$key]) !== ''
            ? trim((string) $source[$key])
            : (string) $fallback;
    }

    private function formatAmount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function commitEvent()
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent()
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
