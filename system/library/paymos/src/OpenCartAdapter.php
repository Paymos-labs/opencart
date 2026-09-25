<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class OpenCartAdapter implements OpenCartAdapterInterface
{
    /** @var object */
    private $registry;

    /** @var array<string, self> storefront adapters built in the admin, by "store|language" */
    private $storefronts = array();

    public function __construct($registry)
    {
        $this->registry = $registry;
    }

    /**
     * Does this OpenCart object answer to that method?
     *
     * `method_exists()` alone is wrong here. OpenCart 4 does not put the model in
     * the registry — it puts an `Engine\Proxy` whose methods are closures in a data
     * map reached through `__call`, so `method_exists()` reports false for every
     * real model method. That made getOrder() return an empty array and the
     * checkout die with "OpenCart order was not found" on every single payment,
     * while the controller's own `$this->model_checkout_order` worked fine.
     *
     * Proxy implements `__isset` against that same map, so the property check is an
     * accurate capability test there; `method_exists()` still covers a plain model.
     *
     * @param object $object
     * @param string $method
     * @return bool
     */
    private function answersTo($object, $method)
    {
        return is_object($object) && (method_exists($object, $method) || isset($object->{$method}));
    }

    public function getOrder($orderId)
    {
        $model = $this->orderReader();
        if (!$this->answersTo($model, 'getOrder')) {
            return array();
        }

        $order = $model->getOrder((int) $orderId);
        return is_array($order) ? $order : array();
    }

    public function addOrderHistory($orderId, $orderStatusId, $comment, $notify = false)
    {
        if ($this->isAdmin()) {
            $this->storefrontFor((int) $orderId)->addOrderHistory($orderId, $orderStatusId, $comment, $notify);
            return;
        }

        $this->registry->get('load')->model('checkout/order');
        $model = $this->registry->get('model_checkout_order');
        if (!is_object($model)) {
            throw new \RuntimeException('OpenCart checkout order model is unavailable.');
        }

        if ($this->answersTo($model, 'addHistory')) {
            $model->addHistory((int) $orderId, (int) $orderStatusId, (string) $comment, (bool) $notify);
            return;
        }

        if ($this->answersTo($model, 'addOrderHistory')) {
            $model->addOrderHistory((int) $orderId, (int) $orderStatusId, (string) $comment, (bool) $notify);
            return;
        }

        throw new \RuntimeException('OpenCart checkout order history method is unavailable.');
    }

    /**
     * The model that reads an order in the application this runs in.
     *
     * The storefront has checkout/order. The OpenCart 4 admin — where the manual
     * reconcile runs — has none: only admin/model/sale/order.php (every tag from
     * 4.0.0.0 to 4.1.0.4), and loading checkout/order there throws "Could not
     * load model". Both getOrder(int): array return the `order` row with the
     * keys this plugin reads (store_id, order_status_id, total, currency_code,
     * currency_value), and an empty array for an unknown order.
     *
     * @return object|null
     */
    private function orderReader()
    {
        $route = $this->isAdmin() ? 'sale/order' : 'checkout/order';
        $this->registry->get('load')->model($route);

        return $this->registry->get('model_' . str_replace('/', '_', $route));
    }

    /**
     * The storefront the admin adds an order's history through.
     *
     * The admin has no model that adds order history. OpenCart's own order
     * editor runs the storefront inside the admin instead: setting/store
     * createStoreInstance($store_id, $language) with the order's store and
     * language (a public model method since 4.0.2.0; its third parameter
     * differs between 4.0.2.x and 4.1 and is left at its default). Built once
     * per store and language — it runs the storefront startup actions.
     *
     * @param int $orderId
     * @return self
     */
    private function storefrontFor($orderId)
    {
        $order = $this->getOrder($orderId);
        if (count($order) === 0) {
            throw new \RuntimeException('OpenCart order ' . (int) $orderId . ' was not found.');
        }

        $storeId = isset($order['store_id']) && is_numeric($order['store_id']) ? (int) $order['store_id'] : 0;
        $language = isset($order['language_code']) && is_scalar($order['language_code']) ? (string) $order['language_code'] : '';
        $key = $storeId . '|' . $language;
        if (isset($this->storefronts[$key])) {
            return $this->storefronts[$key];
        }

        $this->registry->get('load')->model('setting/store');
        $stores = $this->registry->get('model_setting_store');
        if (!$this->answersTo($stores, 'createStoreInstance')) {
            throw new \RuntimeException('OpenCart admin cannot reach the storefront order model: setting/store has no createStoreInstance (OpenCart 4.0.2.0 or later).');
        }

        $storefront = new self($stores->createStoreInstance($storeId, $language));
        if ($storefront->isAdmin()) {
            throw new \RuntimeException('OpenCart createStoreInstance did not return a storefront.');
        }

        return $this->storefronts[$key] = $storefront;
    }

    /**
     * Whether this runs in the OpenCart admin. OpenCart sets `application` from
     * the entry point (APPLICATION: Admin or Catalog); createStoreInstance
     * sets it to Catalog.
     *
     * @return bool
     */
    private function isAdmin()
    {
        $config = $this->registry->get('config');

        return $this->answersTo($config, 'get') && $config->get('application') === 'Admin';
    }

    public function orderAmount(array $order)
    {
        return OrderAmount::inOrderCurrency($order, $this->registry->get('currency'));
    }

    public function log($message, array $context = array())
    {
        $log = $this->registry->get('log');
        if (!$this->answersTo($log, 'write')) {
            return;
        }

        $suffix = count($context) === 0 ? '' : ' ' . json_encode($context);
        $log->write('[Paymos] ' . (string) $message . $suffix);
    }
}
