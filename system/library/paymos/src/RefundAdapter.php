<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $invoices;
    private $opencart;
    private $settings;
    private $clientFactory;

    public function __construct(RefundIntentStore $store, InvoiceStoreInterface $invoices,
        OpenCartAdapterInterface $opencart, array $settings, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->invoices = $invoices;
        $this->opencart = $opencart;
        $this->settings = $settings;
        $this->clientFactory = $clientFactory;
    }

    public static function fromRegistry($registry, array $settings): self
    {
        $config = $registry->get('config');
        $settings['opencart_version'] = defined('VERSION') ? (string) VERSION : '';
        $settings['native_store_id'] = (int) $config->get('config_store_id');
        $settings['native_paid_statuses'] = array_merge((array) $config->get('config_processing_status'), (array) $config->get('config_complete_status'));
        $query = $registry->get('db')->query('SELECT `code`, `decimal_place` FROM `' . Migrations::table('currency') . '`');
        $settings['native_currency_scales'] = array();
        foreach ($query->rows as $currency) {
            $settings['native_currency_scales'][$currency['code']] = (int) $currency['decimal_place'];
        }
        return new self(new RefundIntentStore($registry->get('db')), new InvoiceStore($registry->get('db')),
            new OpenCartAdapter($registry), $settings);
    }

    public static function assertProtectedRequest(array $server, array $get, array $session, bool $permission, bool $write): void
    {
        if (!$permission || !isset($get['user_token'], $session['user_token']) || !is_string($get['user_token'])
            || !is_string($session['user_token']) || $session['user_token'] === ''
            || !hash_equals($session['user_token'], $get['user_token'])) {
            throw new \RuntimeException('refund_access_denied');
        }
        if ($write && (!isset($server['REQUEST_METHOD']) || $server['REQUEST_METHOD'] !== 'POST')) {
            throw new \RuntimeException('refund_post_required');
        }
    }

    public function submit(int $orderId, int $storeId, array $input): RefundIntent
    {
        if (array_diff(array_keys($input), array('mode', 'request_token')) || !isset($input['mode'], $input['request_token'])
            || !is_string($input['mode']) || !in_array($input['mode'], array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)
            || !is_string($input['request_token']) || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $input['request_token'])) {
            throw new \RuntimeException('refund_mode_and_token_only');
        }
        return $this->store->withPaymentLock('opencart:' . $orderId, function () use ($orderId, $storeId, $input) {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $input['request_token']);
            if ($existing !== null) {
                if ($existing->paymentKey() !== 'opencart:' . $orderId || $existing->mode() !== $input['mode']) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->assertMapping($existing, $storeId);
                $this->coordinator($existing)->submit($existing->operationId());
                $this->store->schedule($existing->operationId());
                return $this->store->getIntent($existing->operationId());
            }
            $this->store->assertNewActionAllowed('opencart:' . $orderId);
            $snapshot = $this->nativeSnapshot($orderId, $storeId);
            $intent = new RefundIntent(array(
                'operation_id' => RefundIntentStore::uuid(), 'request_token' => $input['request_token'],
                'installation_id' => $installation, 'platform' => 'opencart', 'native_payment_key' => 'opencart:' . $orderId,
                'invoice_id' => $snapshot['invoice']['paymos_invoice_id'], 'project_id' => $snapshot['invoice']['project_id'],
                'environment' => $snapshot['invoice']['environment'], 'mode' => $input['mode'],
                'full_draft' => $input['mode'] === RefundStates::FULL ? $snapshot['draft'] : null,
            ));
            $reason = $this->preflight($intent);
            if ($reason !== null) {
                throw new \RuntimeException($reason);
            }
            $intent = $this->store->createIntent($intent);
            $this->coordinator($intent)->submit($intent->operationId());
            $this->store->schedule($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    public function panel(int $orderId, int $storeId): array
    {
        return $this->store->withPaymentLock('opencart:' . $orderId, function () use ($orderId, $storeId) {
            $order = $this->store->nativeOrder($orderId);
            $row = $this->invoices->findByOpenCartOrderId($orderId);
            if ($order === null || $row === null || !isset($order['store_id']) || (int) $order['store_id'] !== $storeId
                || Config::fromSettings($this->settings)->projectIdForEnvironment($row['environment']) !== $row['project_id']) {
                throw new \RuntimeException('refund_mapping_conflict');
            }
            $invoice = $this->client($row['environment'])->invoices()->get($row['paymos_invoice_id']);
            $this->assertInvoice($row, $invoice);
            $summary = isset($invoice['refund_summary']) && is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : array();
            $allowed = true;
            $unavailableReason = null;
            try {
                $this->assertCapability($row['environment']);
                $this->store->assertNewActionAllowed('opencart:' . $orderId);
                $this->nativeSnapshot($orderId, $storeId);
            } catch (\RuntimeException $error) {
                $allowed = false;
                $unavailableReason = $error->getMessage();
            }
            $operations = array();
            foreach ($this->store->forPayment('opencart:' . $orderId) as $intent) {
                $operations[] = $this->publicState($intent);
            }
            return array('order_id' => $orderId, 'summary' => $summary,
                'unavailable_reason' => $unavailableReason,
                'can_full' => $allowed && isset($summary['can_refund_full']) && $summary['can_refund_full'] === true,
                'can_overpayment' => $allowed && isset($summary['can_refund_overpayment']) && $summary['can_refund_overpayment'] === true,
                'operations' => $operations);
        });
    }

    public function publicState(RefundIntent $intent): array
    {
        $data = $intent->toArray();
        return array('operation_id' => $intent->operationId(), 'request_token' => $data['request_token'],
            'mode' => $intent->mode(), 'withdrawal_id' => $intent->withdrawalId(), 'cash_state' => $intent->cashState(),
            'projection_state' => $intent->projectionState(), 'review_reason' => $data['review_reason'],
            'observation' => $intent->observation() === null ? null : $intent->observation()->toArray());
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $this->assertCapability($intent->environment());
            $this->assertMapping($intent, $this->storeId());
            $snapshot = $this->nativeSnapshot($this->orderId($intent), $this->storeId());
            if ($intent->mode() === RefundStates::FULL && $intent->fullDraft() !== $snapshot['draft']) {
                return 'refund_native_draft_changed';
            }
            $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            $this->assertInvoice($snapshot['invoice'], $invoice);
            $summary = isset($invoice['refund_summary']) && is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : array();
            $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            if (!isset($summary[$field]) || $summary[$field] !== true) {
                return 'refund_server_action_unavailable';
            }
            return null;
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }
    }

    private function assertCapability(string $environment): void
    {
        if (!Config::fromSettings($this->settings)->hasRefundCapability($environment)) {
            throw new \RuntimeException('refund_reconnect_required');
        }
    }

    private function assertMapping(RefundIntent $intent, int $storeId): void
    {
        $order = $this->store->nativeOrder($this->orderId($intent));
        $row = $this->invoices->findByOpenCartOrderId($this->orderId($intent));
        if ($order === null || !isset($order['store_id'], $order['payment_code']) || (int) $order['store_id'] !== $storeId
            || $order['payment_code'] !== 'paymos.paymos' || $row === null
            || $row['paymos_invoice_id'] !== $intent->invoiceId() || $row['project_id'] !== $intent->projectId()
            || $row['environment'] !== $intent->environment()
            || Config::fromSettings($this->settings)->projectIdForEnvironment($intent->environment()) !== $intent->projectId()) {
            throw new \RuntimeException('refund_mapping_conflict');
        }
    }

    private function nativeSnapshot(int $orderId, int $storeId): array
    {
        if (!Installer::supports(isset($this->settings['opencart_version']) ? $this->settings['opencart_version'] : '')) {
            throw new \RuntimeException('refund_unsupported_platform');
        }
        $this->store->assertNativeConnection();
        $order = $this->store->nativeOrder($orderId);
        $row = $this->invoices->findByOpenCartOrderId($orderId);
        $statuses = isset($this->settings['native_paid_statuses']) ? $this->settings['native_paid_statuses'] : array();
        if ($order === null || $row === null || !isset($order['store_id'], $order['payment_code'], $order['order_status_id'])
            || (int) $order['store_id'] !== $storeId || $order['payment_code'] !== 'paymos.paymos'
            || !in_array((int) $order['order_status_id'], array_map('intval', $statuses), true)
            || !in_array($row['status'], array('paid', 'paid_over'), true)
            || !isset($order['currency_value']) || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', (string) $order['currency_value'])
            || AmountGuard::normalizeDecimal((string) $order['currency_value']) === '0'
            || (isset($order['refunded_amount']) && AmountGuard::normalizeDecimal((string) $order['refunded_amount']) !== '0')
            || !AmountGuard::amountsEqual($this->opencart->orderAmount($order), $row['amount'])
            || strtoupper($order['currency_code']) !== strtoupper($row['currency'])
            || Config::fromSettings($this->settings)->projectIdForEnvironment($row['environment']) !== $row['project_id']) {
            throw new \RuntimeException('refund_native_payment_changed');
        }
        if (method_exists($this->opencart, 'prepareRefundHistory')) {
            $this->opencart->prepareRefundHistory($orderId);
        }
        $decimal = AmountGuard::normalizeDecimal($this->opencart->orderAmount($order));
        if ($decimal === null || $decimal === '0') {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $scale = isset($this->settings['native_currency_scales'][$row['currency']]) ? (int) $this->settings['native_currency_scales'][$row['currency']] : null;
        if ($scale === null || $scale < 0 || $scale > 12) {
            throw new \RuntimeException('refund_native_currency_unavailable');
        }
        $parts = explode('.', $decimal, 2);
        $fraction = isset($parts[1]) ? $parts[1] : '';
        if (strlen($fraction) > $scale) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $minor = ltrim($parts[0] . str_pad($fraction, $scale, '0'), '0');
        return array('invoice' => $row, 'order' => $order, 'draft' => array(
            'amount_minor' => $minor, 'amount_decimal' => $decimal, 'currency' => strtoupper($row['currency']), 'order_id' => (string) $orderId,
            'store_id' => (string) $storeId, 'total' => (string) $order['total'], 'currency_value' => (string) $order['currency_value'],
            'order_status_id' => (string) $order['order_status_id'], 'notify' => false, 'restock' => false,
        ));
    }

    private function assertInvoice(array $row, array $invoice): void
    {
        if (!isset($invoice['invoice_id'], $invoice['project_id'], $invoice['is_test'], $invoice['order']['external_id'], $invoice['order']['amount'], $invoice['order']['currency'])
            || $invoice['invoice_id'] !== $row['paymos_invoice_id'] || $invoice['project_id'] !== $row['project_id']
            || $invoice['is_test'] !== ($row['environment'] === 'sandbox') || $invoice['order']['external_id'] !== $row['external_order_id']
            || !AmountGuard::amountsEqual($invoice['order']['amount'], $row['amount']) || $invoice['order']['currency'] !== $row['currency']) {
            throw new \RuntimeException('refund_invoice_snapshot_conflict');
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        try {
            $this->assertMapping($intent, $this->storeId());
            $snapshot = $this->nativeSnapshot($this->orderId($intent), $this->storeId());
            if ($intent->mode() === RefundStates::FULL && $intent->fullDraft() !== $snapshot['draft']) {
                return $this->ambiguous($intent, 'refund_native_draft_changed');
            }
            $row = $this->store->projectionRow($operationId);
            $histories = $this->store->markerHistories($this->orderId($intent), $this->marker($intent));
            if ($row === null) {
                return count($histories) ? $this->ambiguous($intent, 'refund_partial_native_write')
                    : new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
            }
            if (count($histories) !== 1 || (int) $row['order_id'] !== $this->orderId($intent)
                || (int) $histories[0]['order_history_id'] !== (int) $row['history_id']
                || $histories[0]['comment'] !== $row['history_comment'] || (int) $histories[0]['notify'] !== 0) {
                return $this->ambiguous($intent, 'refund_native_marker_conflict');
            }
            $result = NativeProjectionResult::fromStorage(json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR));
            $expected = $this->result($intent, $intent->observation(), (string) $row['history_id']);
            return $result->toArray() === $expected->toArray() ? $result : $this->ambiguous($intent, 'refund_native_marker_conflict');
        } catch (\RuntimeException $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        return $this->store->transaction(function () use ($intent, $refund) {
            if ($refund->status() !== 'completed' || $intent->observation() === null
                || $intent->observation()->toArray() !== $refund->toArray()) {
                return $this->ambiguous($intent, 'refund_completion_unverified');
            }
            $found = $this->inspect($intent->operationId());
            if ($found->kind() !== NativeProjectionResult::ABSENT) {
                return $found;
            }
            $order = $this->store->nativeOrder($this->orderId($intent));
            $draft = $intent->fullDraft();
            $comment = ($intent->mode() === RefundStates::FULL
                    ? 'Paymos full refund completed. Order status and stock are unchanged. '
                    : 'Paymos overpayment refund completed. The order stays paid and stock is unchanged. ')
                . $this->marker($intent) . ' ' . $refund->withdrawalId()
                . ' ' . $refund->principalDecimal() . ' ' . $refund->currency() . '/' . $refund->network();
            if ($draft !== null) {
                $comment .= ' ' . $draft['amount_decimal'] . ' ' . $draft['currency'];
            }
            $this->opencart->addOrderHistory($this->orderId($intent), (int) $order['order_status_id'], $comment, false);
            $histories = $this->store->markerHistories($this->orderId($intent), $this->marker($intent));
            $after = $this->store->nativeOrder($this->orderId($intent));
            $changed = $after === null;
            foreach (array('order_id', 'store_id', 'order_status_id', 'payment_code', 'total', 'currency_code', 'currency_value', 'refunded_amount') as $field) {
                if (($after[$field] ?? null) !== ($order[$field] ?? null)) {
                    $changed = true;
                }
            }
            if (count($histories) !== 1 || $histories[0]['comment'] !== $comment || (int) $histories[0]['notify'] !== 0
                || (int) $histories[0]['order_status_id'] !== (int) $order['order_status_id'] || $changed) {
                throw new \RuntimeException('refund_native_history_unknown');
            }
            $result = $this->result($intent, $refund, (string) $histories[0]['order_history_id']);
            $this->store->saveNativeProjection($intent, $this->orderId($intent), $histories[0], $result);
            return $result;
        });
    }

    private function result(RefundIntent $intent, ?VerifiedRefund $refund, string $nativeId): NativeProjectionResult
    {
        if ($refund === null) {
            return $this->ambiguous($intent, 'refund_evidence_missing');
        }
        $draft = $intent->fullDraft();
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), $nativeId,
            $draft === null ? null : $draft['amount_minor'], $draft === null ? null : $draft['currency'],
            $draft === null ? $refund->principalDecimal() : null, $draft === null ? $refund->currency() . '/' . $refund->network() : null);
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason);
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $intent = $this->store->getIntent($operationId);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $intent = $this->store->getIntent($operationId);
            if ($intent->toArray()['review_reason'] === 'cash_rewound' && $intent->toArray()['projection'] !== null) {
                $this->cashReviewNote($intent);
            }
            return $action;
        });
    }

    private function cashReviewNote(RefundIntent $intent): void
    {
        $this->store->transaction(function () use ($intent) {
            $this->assertMapping($intent, $this->storeId());
            $snapshot = $this->nativeSnapshot($this->orderId($intent), $this->storeId());
            $row = $this->store->projectionRow($intent->operationId());
            if ($row === null) {
                throw new \RuntimeException('refund_native_marker_missing');
            }
            $marker = '[paymos-refund-review:' . $intent->operationId() . ']';
            $histories = $this->store->markerHistories($this->orderId($intent), $marker);
            if (isset($row['review_history_id'])) {
                if (count($histories) !== 1 || (int) $histories[0]['order_history_id'] !== (int) $row['review_history_id']) {
                    throw new \RuntimeException('refund_review_marker_conflict');
                }
                return;
            }
            if (count($histories)) {
                throw new \RuntimeException('refund_review_marker_conflict');
            }
            $this->opencart->addOrderHistory($this->orderId($intent), (int) $snapshot['order']['order_status_id'],
                'Paymos refund is no longer completed and needs review. ' . $marker . ' ' . $intent->withdrawalId() . ' ' . $intent->cashState(), false);
            $histories = $this->store->markerHistories($this->orderId($intent), $marker);
            if (count($histories) !== 1 || (int) $histories[0]['notify'] !== 0) {
                throw new \RuntimeException('refund_review_history_unknown');
            }
            $this->store->saveReviewHistory($intent, (int) $histories[0]['order_history_id']);
        });
    }

    public function callback(WebhookEvent $event, string $environment): void
    {
        $payload = $event->toArray();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        if (strpos($event->type(), 'withdrawal.') !== 0 || !isset($data['invoice_id'])) {
            return;
        }
        $intent = isset($data['withdrawal_id']) && is_string($data['withdrawal_id']) ? $this->store->findByWithdrawalId($data['withdrawal_id']) : null;
        if ($intent === null && isset($data['external_order_id']) && is_string($data['external_order_id'])) {
            $intent = $this->store->findByExternalId($data['external_order_id']);
        }
        if ($intent === null) {
            throw new \RuntimeException('refund_unbound_requires_review');
        }
        if ($environment !== $intent->environment() || !isset($data['project_id'], $data['is_test'])
            || $data['invoice_id'] !== $intent->invoiceId() || $data['project_id'] !== $intent->projectId()
            || $data['is_test'] !== ($environment === 'sandbox')
            || ($intent->withdrawalId() !== null && isset($data['withdrawal_id']) && $data['withdrawal_id'] !== $intent->withdrawalId())) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $this->reconcile($intent->operationId());
        $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) { $this->store->schedule($intent->operationId()); });
    }

    public function runDue(\DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->store->listDue($now, 100, null)->items() as $intent) {
            try {
                $this->reconcile($intent->operationId());
                $count++;
            } catch (\Throwable $error) {
                $this->opencart->log('Refund reconciliation failed.', array('operation_id' => $intent->operationId(), 'error' => $error->getMessage()));
            } finally {
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) { $this->store->schedule($intent->operationId()); });
                } catch (\Throwable $error) {
                    $this->opencart->log('Refund scheduling failed.', array('operation_id' => $intent->operationId(), 'error' => $error->getMessage()));
                }
            }
        }
        return $count;
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->client($intent->environment()), $this->store, $this);
    }

    private function client(string $environment): Client
    {
        return $this->clientFactory !== null ? call_user_func($this->clientFactory, $environment)
            : new Client(Config::fromSettings($this->settings)->clientConfigForEnvironment($environment));
    }

    private function orderId(RefundIntent $intent): int
    {
        if (!preg_match('/^opencart:([1-9][0-9]*)$/D', $intent->paymentKey(), $match)) {
            throw new \RuntimeException('refund_mapping_conflict');
        }
        return (int) $match[1];
    }
    private function marker(RefundIntent $intent): string { return '[paymos-refund:' . $intent->operationId() . ':' . $intent->mode() . ']'; }
    private function storeId(): int { return isset($this->settings['native_store_id']) ? (int) $this->settings['native_store_id'] : 0; }
}
