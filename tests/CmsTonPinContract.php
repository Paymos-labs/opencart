<?php

declare(strict_types=1);

use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\VerifiedRefund;

/** Exercise a CMS's real store using its existing in-memory platform fixture. */
function paymos_cms_ton_pin_contract($store, string $platform, string $installation, string $paymentKey): void
{
    $raw = '0:ca6e321c7cce9ecedf0a8ca2492ec8592494aa5fb5ce0387dff96ef6af982a3e';
    $friendly = '0QDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPleK';
    $intent = new RefundIntent(array(
        'operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'request_token' => 'ton-pin-request-0001', 'installation_id' => $installation,
        'platform' => $platform, 'native_payment_key' => $paymentKey,
        'invoice_id' => 'inv_fixture', 'project_id' => 'prj_fixture',
        'environment' => 'sandbox', 'mode' => 'overpayment', 'full_draft' => null,
    ));
    $snapshot = static function (string $sender) use ($intent): VerifiedRefund {
        $refund = array('withdrawal_id' => 'wdr_ton_fixture', 'invoice_id' => $intent->invoiceId(),
            'project_id' => $intent->projectId(), 'is_test' => true, 'mode' => $intent->mode(),
            'external_order_id' => $intent->externalOrderId(), 'status' => 'created',
            'amount' => '20', 'currency' => 'USDT', 'network' => 'TON',
            'first_sender' => $sender, 'destination_address' => $sender);
        return VerifiedRefund::fromApi($refund,
            array('invoice_id' => $intent->invoiceId(), 'project_id' => $intent->projectId(), 'is_test' => true),
            new DateTimeImmutable('2026-10-29T00:00:00Z'));
    };

    $store->withPaymentLock($paymentKey, static function () use ($store, $intent, $snapshot, $raw, $friendly): void {
        $store->createIntent($intent);
        if ($store->bindWithdrawal($intent->operationId(), 'wdr_ton_fixture') !== 'bound') {
            throw new RuntimeException('TON test could not establish the initial binding.');
        }
        $store->recordObservation($intent->operationId(), $snapshot($raw));
        $store->recordObservation($intent->operationId(), $snapshot($friendly));
        if ($store->getIntent($intent->operationId())->observation()->recipient() !== $friendly) {
            throw new RuntimeException('The equivalent fresh TON observation was not stored.');
        }
        $rejected = false;
        try { $store->recordObservation($intent->operationId(), $snapshot('0:' . str_repeat('0', 64))); }
        catch (RuntimeException $error) { $rejected = true; }
        if (!$rejected || $store->getIntent($intent->operationId())->observation()->recipient() !== $friendly) {
            throw new RuntimeException('A different TON account must not replace the pinned observation.');
        }
    });
}
