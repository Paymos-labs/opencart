<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosOpenCart\InMemoryInvoiceStore;
use PaymosOpenCart\Reconciler;

function test_opencart_reconciler_applies_paid_invoice_when_webhook_was_missed()
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
        'created_at' => date('Y-m-d H:i:s', 1708990000),
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

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(opencart_settings(), 1709000000);

    assertSameValue(1, $count, 'reconciler must count newly completed orders.');
    assertSameValue('paid', $store->findByOpenCartOrderId(42)['status'], 'reconciler must update stored invoice status.');
    assertSameValue(5, $adapter->histories[0]['order_status_id'], 'reconciler must apply paid status to OpenCart order.');
}

function test_opencart_reconciler_skips_snapshot_mismatch()
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
        'created_at' => date('Y-m-d H:i:s', 1708990000),
    ));
    $adapter = new FakeOpenCartAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'other_project',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'oc_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(opencart_settings(), 1709000000);

    assertSameValue(0, $count, 'snapshot mismatch must not be counted.');
    assertSameValue(0, count($adapter->histories), 'snapshot mismatch must not mutate order status.');
}

function test_opencart_reconciler_compares_amounts_numerically()
{
    // BUG-133: a snapshot written as "2500.00" for a JPY order and the server's
    // "2500" are the same amount. A string compare skipped the order on every run.
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'opencart_order_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'oc_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '2500.00',
        'currency' => 'JPY',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
        'created_at' => date('Y-m-d H:i:s', 1708990000),
    ));
    $adapter = new FakeOpenCartAdapter();
    $adapter->orders[42] = opencart_order(array('total' => '2500.0000', 'currency_code' => 'JPY', 'currency_value' => '1.00000000'));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'oc_42_0', 'amount' => '2500', 'currency' => 'JPY'),
        )), array()),
    )));

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(opencart_settings(), 1709000000);

    assertSameValue(1, $count, '"2500.00" and "2500" must reconcile as the same amount.');
}

/**
 * OpenCart 4's \Opencart\System\Engine\Proxy: a model's methods are closures
 * in a data map reached through __call, so method_exists() reports false.
 */
final class FakeOpenCartProxy
{
    /** @var array<string, callable> */
    private $data = array();

    public function __set($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function __isset($key)
    {
        return isset($this->data[$key]);
    }

    public function __call($method, array $args)
    {
        if (!isset($this->data[$method])) {
            throw new \Exception('Error: Could not call proxy key ' . $method . '!');
        }

        return call_user_func_array($this->data[$method], $args);
    }
}

/**
 * Registry + Loader + Config of one OpenCart application. The Loader throws
 * exactly like OpenCart 4's does for a model the application does not have.
 */
final class FakeOpenCartApplication
{
    /** @var array<string, object> */
    private $items = array();

    /** @var array<string, callable> route => proxy factory */
    public $models = array();

    /** @var array<int, string> */
    public $loaded = array();

    public function __construct($application, array $models)
    {
        $this->models = $models;
        $app = $this;
        $this->items['config'] = new class ($application) {
            private $application;

            public function __construct($application)
            {
                $this->application = $application;
            }

            public function get($key)
            {
                return $key === 'application' ? $this->application : null;
            }
        };
        $this->items['load'] = new class ($app) {
            private $app;

            public function __construct($app)
            {
                $this->app = $app;
            }

            public function model($route)
            {
                $this->app->loaded[] = $route;
                if (!isset($this->app->models[$route])) {
                    throw new \Exception('Error: Could not load model ' . $route . '!');
                }
                $this->app->set('model_' . str_replace('/', '_', $route), call_user_func($this->app->models[$route]));
            }
        };
    }

    public function get($key)
    {
        return isset($this->items[$key]) ? $this->items[$key] : null;
    }

    public function set($key, $value)
    {
        $this->items[$key] = $value;
    }

    public function has($key)
    {
        return isset($this->items[$key]);
    }
}

/**
 * A storefront application whose checkout/order model records addHistory().
 *
 * @param array<int, array<string, mixed>> $histories
 */
function opencart_fake_storefront(array &$histories)
{
    return new FakeOpenCartApplication('Catalog', array(
        'checkout/order' => static function () use (&$histories) {
            $model = new FakeOpenCartProxy();
            $model->getOrder = static function ($orderId) {
                return (int) $orderId === 42 ? opencart_order(array('store_id' => '0', 'order_status_id' => '1', 'currency_value' => '1.00000000')) : array();
            };
            $model->addHistory = static function ($orderId, $orderStatusId, $comment = '', $notify = false, $override = false) use (&$histories) {
                $histories[] = array('order_id' => (int) $orderId, 'order_status_id' => (int) $orderStatusId, 'comment' => (string) $comment);
            };

            return $model;
        },
    ));
}

function opencart_paid_reconcile_fixture()
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
        'status' => 'awaiting_client',
        'renew_count' => 0,
        'created_at' => date('Y-m-d H:i:s', 1708990000),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'oc_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    )));

    return array($store, $client);
}

function test_opencart_admin_reconcile_uses_the_models_the_admin_has()
{
    // BUG-157: the manual reconcile runs in the OpenCart 4 admin, which has no
    // checkout/order model — only admin/model/sale/order.php (tags 4.0.0.0 to
    // 4.1.0.4). Loading it threw "Could not load model" and every row failed.
    // The order is read through sale/order; the history goes through the
    // storefront's checkout/order in a store instance, as OpenCart's own order
    // editor does it: setting/store createStoreInstance($store_id, $language).
    $histories = array();
    $storeCalls = array();
    $storefront = opencart_fake_storefront($histories);
    $admin = new FakeOpenCartApplication('Admin', array(
        'sale/order' => static function () {
            $model = new FakeOpenCartProxy();
            $model->getOrder = static function ($orderId) {
                return (int) $orderId === 42
                    ? opencart_order(array('store_id' => '3', 'language_code' => 'de-de', 'order_status_id' => '1', 'currency_value' => '1.00000000'))
                    : array();
            };

            return $model;
        },
        'setting/store' => static function () use ($storefront, &$storeCalls) {
            $model = new FakeOpenCartProxy();
            $model->createStoreInstance = static function ($storeId = 0, $language = '', $third = '') use ($storefront, &$storeCalls) {
                $storeCalls[] = array($storeId, $language);

                return $storefront;
            };

            return $model;
        },
    ));
    $admin->set('currency', new FakeOpenCartCurrency());
    list($store, $client) = opencart_paid_reconcile_fixture();

    $count = (new Reconciler($store, new PaymosOpenCart\OpenCartAdapter($admin), static function () use ($client) {
        return $client;
    }))->run(opencart_settings(), 1709000000);

    assertSameValue(1, $count, 'the admin reconcile must move the paid order.');
    assertSameValue(1, count($histories), 'the paid status must reach the storefront order model.');
    assertSameValue(5, $histories[0]['order_status_id'], 'the order must get the paid status.');
    assertSameValue(array(array(3, 'de-de')), $storeCalls, 'the store instance must be the order\'s store and language.');
    assertSameValue(false, in_array('checkout/order', $admin->loaded, true), 'the admin must never load a model it does not have.');
    assertSameValue('paid', $store->findByOpenCartOrderId(42)['status'], 'the snapshot must record the paid status.');
}

function test_opencart_storefront_adapter_keeps_using_checkout_order()
{
    // The webhook path runs in the storefront: checkout/order reads the order
    // and adds the history, and nothing admin-only is loaded.
    $histories = array();
    $storefront = opencart_fake_storefront($histories);
    $adapter = new PaymosOpenCart\OpenCartAdapter($storefront);

    assertSameValue('USD', $adapter->getOrder(42)['currency_code'], 'the storefront reads the order through checkout/order.');
    $adapter->addOrderHistory(42, 5, 'Paid', false);

    assertSameValue(1, count($histories), 'the storefront adds the history through checkout/order.');
    assertSameValue(array('checkout/order', 'checkout/order'), $storefront->loaded, 'only checkout/order is loaded on the storefront.');
}

function test_opencart_admin_without_a_store_instance_fails_the_row_clearly()
{
    // An admin whose setting/store model cannot build a store instance has no
    // way to add history. The row must fail with a readable reason and nothing
    // may be written.
    $admin = new FakeOpenCartApplication('Admin', array(
        'sale/order' => static function () {
            $model = new FakeOpenCartProxy();
            $model->getOrder = static function ($orderId) {
                return opencart_order(array('store_id' => '0', 'order_status_id' => '1'));
            };

            return $model;
        },
        'setting/store' => static function () {
            return new FakeOpenCartProxy();
        },
    ));

    $threw = null;
    try {
        (new PaymosOpenCart\OpenCartAdapter($admin))->addOrderHistory(42, 5, 'Paid', false);
    } catch (RuntimeException $e) {
        $threw = $e->getMessage();
    }

    assertContainsValue('createStoreInstance', (string) $threw, 'the failure must name what the admin is missing.');
}
