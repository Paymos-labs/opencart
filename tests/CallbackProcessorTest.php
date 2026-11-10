<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosOpenCart\CallbackProcessor;
use PaymosOpenCart\InMemoryEventStore;
use PaymosOpenCart\InMemoryInvoiceStore;

function opencart_paid_client()
{
    return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(opencart_invoice_event('unused', 'invoice.paid', 'paid')['data']), array()),
    )));
}

function test_opencart_unapplied_paid_same_event_recovers_after_total_correction_and_stale_confirming()
{
    $store = opencart_store_with_status('created');
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42]['total'] = '110.00';
    $processor = new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () { return opencart_paid_client(); });
    $paid = json_encode(opencart_invoice_event('evt_recover_same', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $paid, 1709000000);
    assertSameValue(400, $processor->handle($paid, $signature, opencart_settings(), 1709000000)->statusCode(), 'Mismatched native total must not ACK paid.');
    assertSameValue('created', $store->findByExternalOrderId('oc_42_0')['status'], 'Unapplied payment remains reconcilable.');
    $stale = json_encode(opencart_invoice_event('evt_stale_during_review', 'invoice.confirming', 'confirming'));
    assertSameValue(200, $processor->handle($stale, opencart_signed_header('whsec_sandbox', $stale, 1709000000), opencart_settings(), 1709000000)->statusCode(), 'A stale confirming event can never close the paid retry window.');
    assertSameValue(1, count($store->findUnpaidRecent(10, 0)), 'The held payment remains in reconciliation candidates.');
    $adapter->orders[42]['total'] = '100.00';
    assertSameValue(200, $processor->handle($paid, $signature, opencart_settings(), 1709000000)->statusCode(), 'The exact same event must recover after correction.');
    assertSameValue(200, $processor->handle($paid, $signature, opencart_settings(), 1709000000)->statusCode(), 'A committed retry is idempotent.');
    assertSameValue('paid', $store->findByExternalOrderId('oc_42_0')['status'], 'Only successfully applied payment is final.');
    $paidWrites = array_filter($adapter->histories, static function ($row) { return $row['order_status_id'] === 5; });
    assertSameValue(1, count($paidWrites), 'Corrected payment must have exactly one paid native effect.');
}

function test_opencart_order_mutex_serializes_both_webhook_delivery_orders()
{
    foreach (array('confirming', 'paid') as $first) {
        $store = opencart_store_with_status('created');
        $adapter = new FakeOpenCartAdapter();
        $a = new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () { return opencart_paid_client(); });
        $b = new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () { return opencart_paid_client(); });
        $second = $first === 'paid' ? 'confirming' : 'paid';
        $bodyA = json_encode(opencart_invoice_event('evt_first', 'invoice.' . $first, $first));
        $bodyB = json_encode(opencart_invoice_event('evt_second', 'invoice.' . $second, $second));
        $adapter->beforeHistory = static function () use ($adapter, $b, $bodyB) {
            $adapter->beforeHistory = null;
            $blocked = $b->handle($bodyB, opencart_signed_header('whsec_sandbox', $bodyB, 1709000000), opencart_settings(), 1709000000);
            assertSameValue(409, $blocked->statusCode(), 'A second event cannot mutate between native guards and write.');
            assertSameValue(0, count($adapter->histories), 'Blocked delivery has no native effects.');
        };
        assertSameValue(200, $a->handle($bodyA, opencart_signed_header('whsec_sandbox', $bodyA, 1709000000), opencart_settings(), 1709000000)->statusCode(), 'The order owner completes.');
        assertSameValue(200, $b->handle($bodyB, opencart_signed_header('whsec_sandbox', $bodyB, 1709000000), opencart_settings(), 1709000000)->statusCode(), 'Busy delivery releases only its event and can retry.');
        assertSameValue('paid', $store->findByExternalOrderId('oc_42_0')['status'], 'Final snapshot survives either event order.');
        assertSameValue(5, $adapter->orders[42]['order_status_id'], 'Native order stays paid after either interleaving.');
        assertSameValue(1, count(array_filter($adapter->histories, static function ($row) { return $row['order_status_id'] === 5; })), 'One native paid transition.');
    }
}

function test_opencart_checkout_and_reconciler_use_the_callback_order_mutex()
{
    $store = opencart_store_with_status('created');
    $adapter = new FakeOpenCartAdapter();
    $adapter->beforeHistory = static function () use ($store, $adapter) {
        $adapter->beforeHistory = null;
        $noNetwork = static function () { throw new RuntimeException('Busy paths must not reach the API.'); };
        try {
            (new \PaymosOpenCart\GatewayCheckout($store, $adapter, $noNetwork))->start(42, opencart_settings());
            throw new RuntimeException('Checkout must not enter the callback order lock.');
        } catch (\Paymos\Exception\EventInProgressException $expected) {}
        assertSameValue(0, (new \PaymosOpenCart\Reconciler($store, $adapter, $noNetwork))->run(opencart_settings()), 'Reconcile skips a callback-owned order without native or API effects.');
    };
    $body = json_encode(opencart_invoice_event('evt_owner', 'invoice.paid', 'paid'));
    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () { return opencart_paid_client(); }))
        ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);
    assertSameValue(200, $result->statusCode(), 'Callback completes after competitors leave its order alone.');
    assertSameValue(1, count($adapter->histories), 'Only the callback can apply native history.');
}

function test_opencart_callback_marks_order_paid_after_verified_webhook_and_reverse_lookup()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'created',
        'renew_count' => 0,
    ));
    $adapter = new FakeOpenCartAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array(
                'external_id' => 'oc_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(opencart_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, opencart_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'verified webhook must return HTTP 200.');
    assertSameValue('paid', $store->findByExternalOrderId('oc_42_0')['status'], 'stored invoice status must be updated.');
    assertSameValue(1, count($transport->requests()), 'terminal webhook must reverse-verify invoice through API.');
    assertSameValue(5, $adapter->histories[0]['order_status_id'], 'paid event must use configured paid status id.');
}

function test_opencart_callback_does_not_roll_back_paid_order_on_late_cancel()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'paid',
        'renew_count' => 0,
    ));
    $adapter = new FakeOpenCartAdapter();
    // Order is already at the configured paid status id (5).
    $adapter->orders[42] = opencart_order(array('order_status_id' => 5));

    // API still reports cancelled, so reverse-verify passes and the event reaches
    // the mapper — the roll-back guard must keep the paid order paid.
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'cancelled',
            'is_test' => true,
            'order' => array('external_id' => 'oc_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(opencart_invoice_event('evt_late_cancel', 'invoice.cancelled', 'cancelled'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, opencart_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'late cancel webhook must still return 200.');
    assertSameValue(0, count($adapter->histories), 'A final paid snapshot must not write stale native history.');
}

function test_opencart_callback_holds_for_manual_review_on_amount_mismatch()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'created',
        'renew_count' => 0,
    ));
    $adapter = new FakeOpenCartAdapter();
    // Order total changed to 120.00 after the invoice was created for 100.00.
    $adapter->orders[42] = opencart_order(array('total' => '120.00'));

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array('external_id' => 'oc_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(opencart_invoice_event('evt_paid_mismatch', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, $signature, opencart_settings(), 1709000000);

    assertSameValue(400, $result->statusCode(), 'An unapplied payment must stay retryable after local total correction.');
    assertSameValue('created', $store->findByExternalOrderId('oc_42_0')['status'], 'An observed payment is not a successfully applied final snapshot.');
    assertSameValue(2, $adapter->histories[0]['order_status_id'], 'amount mismatch must move the order to the confirming/review status id (2), not paid.');
}

function test_opencart_callback_rejects_environment_mismatch()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'created',
        'renew_count' => 0,
    ));
    $body = json_encode(opencart_invoice_event('evt_live', 'invoice.paid', 'paid', array(
        'data' => array('is_test' => false),
    )));
    $signature = opencart_signed_header('whsec_live', $body, 1709000000);
    $adapter = new FakeOpenCartAdapter();

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () {
        throw new RuntimeException('client must not be called after environment mismatch.');
    }))->handle($body, $signature, opencart_settings(), 1709000000);

    assertSameValue(400, $result->statusCode(), 'environment mismatch must fail processing.');
    assertSameValue(0, count($adapter->histories), 'environment mismatch must not mutate order status.');
}

function test_opencart_callback_is_idempotent_for_duplicate_events()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'created',
        'renew_count' => 0,
    ));
    $adapter = new FakeOpenCartAdapter();
    $eventStore = new InMemoryEventStore();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array(
                'external_id' => 'oc_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $processor = new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    });
    $body = json_encode(opencart_invoice_event('evt_dup', 'invoice.paid', 'paid'));
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);

    $first = $processor->handle($body, $signature, opencart_settings(), 1709000000);
    $second = $processor->handle($body, $signature, opencart_settings(), 1709000000);

    assertSameValue(200, $first->statusCode(), 'first webhook must pass.');
    assertSameValue(200, $second->statusCode(), 'duplicate webhook must return HTTP 200.');
    assertTrueValue($second->isDuplicate(), 'duplicate webhook must be flagged.');
    assertSameValue(1, count($adapter->histories), 'duplicate webhook must not add a second history row.');
}

function test_opencart_callback_completes_a_converted_order_currency_payment()
{
    // BUG-086, callback half: the amount guard must compare the snapshot with
    // the order total in the ORDER currency. Comparing 108.00 USD with the
    // base-currency 100.0000 would hold every foreign-currency order.
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '108.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('total' => '100.0000', 'currency_code' => 'USD', 'currency_value' => '1.08000000'));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array('external_id' => 'oc_42_0', 'amount' => '108.00', 'currency' => 'USD'),
        )), array()),
    )));
    $body = json_encode(opencart_invoice_event('evt_paid_usd', 'invoice.paid', 'paid', array(
        'data' => array('order' => array('amount' => '108.00')),
    )));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'the paid webhook must be accepted.');
    assertSameValue(5, $adapter->histories[0]['order_status_id'], 'a matching converted amount must mark the order paid, not hold it.');
}

/**
 * @param string $rowStatus
 */
function opencart_store_with_status($rowStatus)
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => $rowStatus,
        'renew_count' => 0,
    ));

    return $store;
}

function test_opencart_callback_ignores_a_stale_event_after_a_failed_invoice()
{
    // BUG-135: the invoice ended underpaid, the order is Failed (10). A delayed
    // underpaid_waiting must not move it back to Pending.
    $store = opencart_store_with_status('underpaid');
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('order_status_id' => 10));
    $body = json_encode(opencart_invoice_event('evt_stale', 'invoice.underpaid_waiting', 'underpaid_waiting'));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore()))
        ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'a stale event is acknowledged, not retried.');
    assertSameValue(0, count($adapter->histories), 'a stale event after a final status must not change the order.');
    assertSameValue('underpaid', $store->findByExternalOrderId('oc_42_0')['status'], 'the final status must stay recorded.');
}

function test_opencart_callback_ignores_a_stale_confirming_after_the_merchant_moved_a_paid_order_on()
{
    // The invoice is paid and the merchant has moved the order past the paid
    // status (Shipped, 3). The paid-status guard no longer recognises it; the
    // recorded final invoice status must.
    $store = opencart_store_with_status('paid');
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('order_status_id' => 3));
    $body = json_encode(opencart_invoice_event('evt_stale_confirming', 'invoice.confirming', 'confirming'));

    (new CallbackProcessor($adapter, $store, new InMemoryEventStore()))
        ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);

    assertSameValue(0, count($adapter->histories), 'a stale confirming must not pull a shipped order back to Processing.');
    assertSameValue('paid', $store->findByExternalOrderId('oc_42_0')['status'], 'the final status must stay recorded.');
}

function test_opencart_callback_answers_409_while_the_event_is_still_being_processed()
{
    // BUG-103: another delivery of this event holds the lock (a slow reverse
    // verification). A 200 "duplicate" would mark the event delivered — and
    // OpenCart has no reconciler cron to pick it up if that delivery fails.
    $store = opencart_store_with_status('awaiting_client');
    $adapter = new FakeOpenCartAdapter();
    $events = new InMemoryEventStore();
    assertTrueValue($events->remember('evt_inflight', 604800), 'the first delivery holds the lock.');

    $body = json_encode(opencart_invoice_event('evt_inflight', 'invoice.paid', 'paid'));
    $result = (new CallbackProcessor($adapter, $store, $events))
        ->handle($body, opencart_signed_header('whsec_sandbox', $body, 1709000000), opencart_settings(), 1709000000);

    assertSameValue(409, $result->statusCode(), 'an event still in flight must be answered non-2xx so the server retries.');
    assertFalseValue($result->isDuplicate(), 'an event in flight is not a duplicate.');
    assertFalseValue($events->remember('evt_inflight', 604800), 'the retry must not release the lock the first delivery still holds.');
    assertSameValue(0, count($adapter->histories), 'nothing may be applied while the event is in flight.');
}
