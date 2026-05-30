<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;
use Paymos\Plugin\StatusMapper;

final class Reconciler
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
     */
    public function run(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $count = 0;

        foreach ($this->store->findUnpaidRecent(50, $now - 86400) as $row) {
            try {
                $invoice = $this->client((string) $row['environment'], $settings)->invoices()->get((string) $row['paymos_invoice_id']);
                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->opencart->log('Paymos reconcile skipped invoice snapshot mismatch.', array(
                        'invoice' => $invoice,
                        'row' => $row,
                    ));
                    continue;
                }

                $applied = (new CallbackProcessor($this->opencart, $this->store, new InMemoryEventStore(), $this->clientFactory))
                    ->applyTrustedInvoice($invoice, $row, $settings, $now);
                if ($applied) {
                    $count++;
                }
            } catch (\Exception $e) {
                $this->opencart->log('Paymos reconcile failed.', array(
                    'error' => $e->getMessage(),
                    'row' => $row,
                ));
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice)
    {
        return $this->matches((string) $row['project_id'], $this->field($invoice, array('project_id')))
            && $this->matches((string) $row['external_order_id'], $this->field($invoice, array('order', 'external_id')))
            && $this->matches((string) $row['amount'], $this->field($invoice, array('order', 'amount')))
            && $this->matches(strtoupper((string) $row['currency']), strtoupper($this->field($invoice, array('order', 'currency'))))
            && StatusMapper::invoiceAction('', $this->field($invoice, array('status'))) !== StatusMapper::ACTION_IGNORE;
    }

    private function matches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || $expected === $actual;
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
}
