<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosOpenCart\CallbackProcessor;
use PaymosOpenCart\InMemoryEventStore;
use PaymosOpenCart\InMemoryInvoiceStore;

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
