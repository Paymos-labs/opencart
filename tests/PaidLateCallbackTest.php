<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosOpenCart\CallbackProcessor;
use PaymosOpenCart\InMemoryInvoiceStore;

// invoice-late-payment: a paid_late event on an invoice whose row holds a FINAL status (expired)
// re-asserts the order's CURRENT status with the late fact as the comment — an OpenCart note, not a
// transition — and a sequential duplicate (distinct event id) stays silent.

function opencart_late_store()
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
        'status' => 'expired',
        'renew_count' => 0,
    ));

    return $store;
}

function opencart_paid_late_body($eventId)
{
    return json_encode(opencart_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
}

function test_opencart_paid_late_notes_with_current_status_and_changes_nothing()
{
    $store = opencart_late_store();
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = array('order_id' => 42, 'order_status_id' => 7, 'total' => '100.00', 'currency' => 'USD');
    $body = opencart_paid_late_body('evt_late_1');
    $signature = opencart_signed_header('whsec_sandbox', $body, 1709000000);

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore()))->handle(
        $body,
        $signature,
        opencart_settings(),
        1709000000
    );

    assertSameValue(1, count($adapter->histories), 'paid_late must add exactly one order-history entry.');
    assertSameValue(7, $adapter->histories[0]['order_status_id'], 'the history entry keeps the CURRENT status — not a transition.');
    assertFalseValue($adapter->histories[0]['notify'], 'the note never notifies the customer.');
    assertTrueValue(
        strpos($adapter->histories[0]['comment'], 'arrived after the invoice deadline') !== false,
        'the comment names the late fact.'
    );
    assertSameValue('evt_late_1', $store->findByExternalOrderId('oc_42_0')['paid_late_noted'], 'marker records the event id.');
    assertSameValue('expired', $store->findByExternalOrderId('oc_42_0')['status'], 'row status stays final.');
    assertTrueValue($result->statusCode() === 200 || $result->statusCode() === 204, 'the note outcome acknowledges.');
}

function test_opencart_paid_late_sequential_duplicate_is_silent()
{
    $store = opencart_late_store();
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = array('order_id' => 42, 'order_status_id' => 7, 'total' => '100.00', 'currency' => 'USD');
    $eventStore = new InMemoryEventStore();
    $processor = new CallbackProcessor($adapter, $store, $eventStore);

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = opencart_paid_late_body($eventId);
        $processor->handle(
            $body,
            opencart_signed_header('whsec_sandbox', $body, 1709000000),
            opencart_settings(),
            1709000000
        );
    }

    assertSameValue(1, count($adapter->histories), 'a sequential duplicate must not note twice.');
}
