<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosOpenCart\GatewayCheckout;
use PaymosOpenCart\InMemoryInvoiceStore;

function test_opencart_gateway_checkout_creates_invoice_and_stores_snapshot()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeOpenCartAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });

    $result = (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    assertSameValue('https://checkout.paymos.test/inv_123', $result['payment_url'], 'checkout must return Paymos payment URL.');
    assertSameValue(1, count($transport->requests()), 'new invoice should call Paymos API once.');
    assertSameValue(1, count($adapter->histories), 'checkout must add an awaiting-payment order history row.');

    $row = $store->findByOpenCartOrderId(42);
    assertSameValue('inv_123', $row['paymos_invoice_id'], 'created Paymos invoice id must be stored.');
    assertSameValue('oc_42_0', $row['external_order_id'], 'first external order id must be deterministic.');
    assertSameValue('100.00', $row['amount'], 'order amount snapshot must be stored.');
    assertSameValue('USD', $row['currency'], 'order currency snapshot must be stored.');

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('prj_123', $payload['project_id'], 'Paymos create payload must include project id.');
    assertSameValue('oc_42_0', $payload['external_order_id'], 'Paymos create payload must use Merchant API external_order_id.');
    assertSameValue('77', $payload['client_id'], 'Paymos create payload must use native OpenCart customer_id when available.');
    assertSameValue(false, isset($payload['order']), 'Paymos create payload must not use webhook/read-model order object.');
}

function test_opencart_gateway_checkout_does_not_use_email_as_client_id()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('customer_id' => 0, 'email' => 'buyer@example.com'));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'checkout_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue(false, isset($payload['client_id']), 'Paymos create payload must not use email as client_id.');
}

function test_opencart_gateway_checkout_reuses_existing_invoice_when_snapshot_matches()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_existing',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/existing',
        'status' => 'created',
        'renew_count' => 0,
    ));

    $transport = new MockTransport(array(
        opencart_live_invoice_response('inv_existing', 'awaiting_client', time() + 600),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakeOpenCartAdapter();

    $result = (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], 'matching existing invoice must be reused.');
    assertSameValue(1, count($transport->requests()), 'a reused invoice is checked against the server once.');
    assertSameValue('GET', $transport->requests()[0]['method'], 'the check is a read, never a second create.');
}

function test_opencart_gateway_checkout_renews_invoice_when_amount_changes()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_old',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '50.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/old',
        'status' => 'created',
        'renew_count' => 0,
    ));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_new',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/new',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $adapter = new FakeOpenCartAdapter();

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    $row = $store->findByOpenCartOrderId(42);
    assertSameValue('inv_new', $row['paymos_invoice_id'], 'amount change must create a fresh Paymos invoice.');
    assertSameValue('oc_42_1', $row['external_order_id'], 'renewed invoice must increment external order id.');
    assertSameValue(1, count($transport->requests()), 'renewed invoice must call Paymos API.');
}

function test_opencart_gateway_checkout_invoices_the_amount_the_buyer_saw_in_the_order_currency()
{
    // BUG-086: OpenCart stores order.total in the store's BASE currency (EUR
    // here) and the buyer's currency separately (currency_code + the rate at
    // order time, currency_value). A buyer who switched to USD at 1.08 saw
    // $108.00 — the invoice must be for 108.00 USD, not 100.00 "USD".
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('total' => '100.0000', 'currency_code' => 'USD', 'currency_value' => '1.08000000'));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('108.00', $payload['amount'], 'the invoice must carry the order total converted into the order currency.');
    assertSameValue('USD', $payload['currency'], 'the invoice currency is the order currency.');
    assertSameValue('108.00', $store->findByOpenCartOrderId(42)['amount'], 'the snapshot must hold the converted amount.');
}

function test_opencart_gateway_checkout_uses_the_currency_decimal_places()
{
    // JPY has no minor unit: 100 EUR at 160.5 is 16050 JPY, never "16050.00".
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('total' => '100.0000', 'currency_code' => 'JPY', 'currency_value' => '160.50000000'));
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayCheckout($store, $adapter, static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('16050', $payload['amount'], 'a zero-decimal currency must be sent without a fraction.');
}

function test_opencart_adapter_converts_through_the_platform_currency_library()
{
    // The production adapter must take the figure from OpenCart's own currency
    // library (registry "currency"), so it is exactly what the storefront showed.
    $registry = new class {
        public function get($key)
        {
            return $key === 'currency' ? new FakeOpenCartCurrency() : null;
        }
    };
    $adapter = new PaymosOpenCart\OpenCartAdapter($registry);

    assertSameValue('85.00', $adapter->orderAmount(opencart_order(array('total' => '100.0000', 'currency_code' => 'GBP', 'currency_value' => '0.85000000'))), 'GBP order must be converted at the order rate.');
    assertSameValue('33.100', $adapter->orderAmount(opencart_order(array('total' => '100.0000', 'currency_code' => 'KWD', 'currency_value' => '0.33100000'))), 'a three-decimal currency keeps three places.');

    $threw = false;
    try {
        $adapter->orderAmount(opencart_order(array('currency_code' => 'XAU')));
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'an order in a currency OpenCart no longer knows must fail closed, not invoice the base-currency figure.');
}

function opencart_live_invoice_response($invoiceId, $status, $expiresAt)
{
    return new HttpResponse(200, json_encode(array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_123',
        'status' => $status,
        'is_final' => in_array($status, array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled'), true),
        'payment_url' => 'https://checkout.paymos.test/' . $invoiceId,
        'expires_at' => $expiresAt,
        'order' => array('external_id' => 'oc_42_0', 'amount' => '100.00', 'currency' => 'USD'),
    )), array());
}

function opencart_store_with_existing_link($status)
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_existing',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/existing',
        'status' => $status,
        'renew_count' => 0,
    ));

    return $store;
}

function test_opencart_gateway_checkout_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090 (OpenCart): the buyer comes back to the same order after the
    // Paymos invoice's 30 minutes ran out. Same amount, same currency — but the
    // old link leads to an expired checkout, so a new invoice is cut.
    $store = opencart_store_with_existing_link('awaiting_client');
    $transport = new MockTransport(array(
        opencart_live_invoice_response('inv_existing', 'awaiting_client', time() - 3600),
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $result = (new GatewayCheckout($store, new FakeOpenCartAdapter(), static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    assertSameValue('https://checkout.paymos.test/fresh', $result['payment_url'], 'an expired invoice must be replaced by a fresh one.');
    assertSameValue('oc_42_1', $store->findByOpenCartOrderId(42)['external_order_id'], 'the fresh invoice needs a new external order id.');
}

function test_opencart_gateway_checkout_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    $store = opencart_store_with_existing_link('cancelled');
    $transport = new MockTransport(array(
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $result = (new GatewayCheckout($store, new FakeOpenCartAdapter(), static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    assertSameValue('https://checkout.paymos.test/fresh', $result['payment_url'], 'a final invoice must be replaced by a fresh one.');
    assertSameValue(1, count($transport->requests()), 'a recorded final status needs no lookup, only the create.');
}

function test_opencart_gateway_checkout_never_renews_a_paid_invoice()
{
    $store = opencart_store_with_existing_link('paid');
    $transport = new MockTransport(array());
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $result = (new GatewayCheckout($store, new FakeOpenCartAdapter(), static function () use ($client) {
        return $client;
    }))->start(42, opencart_settings());

    assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], 'a paid invoice keeps its link.');
    assertSameValue(0, count($transport->requests()), 'a paid invoice needs neither a lookup nor a new invoice.');
}

function test_opencart_gateway_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. Only the server's answer decides; an open invoice is kept.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $store = opencart_store_with_existing_link('awaiting_client');
        $transport = new MockTransport(array(
            opencart_live_invoice_response('inv_existing', $status, time() - 3600),
        ));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

        $result = (new GatewayCheckout($store, new FakeOpenCartAdapter(), static function () use ($client) {
            return $client;
        }))->start(42, opencart_settings());

        assertSameValue('https://checkout.paymos.test/existing', $result['payment_url'], $status . ': the open invoice keeps its link.');
        assertSameValue(1, count($transport->requests()), $status . ': one lookup and no new invoice.');
        assertSameValue('oc_42_0', $store->findByOpenCartOrderId(42)['external_order_id'], $status . ': the external order id is not bumped.');
    }
}
