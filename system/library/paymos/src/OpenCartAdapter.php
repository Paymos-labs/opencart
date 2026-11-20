<?php

declare(strict_types=1);

namespace PaymosOpenCart;

use Paymos\Plugin\InvoicePresentation;

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
        if (!is_array($order) || count($order) === 0) {
            return array();
        }

        if (!$this->isAdmin() && $this->answersTo($model, 'getProducts')) {
            $products = $this->orderProducts((int) $orderId, $model, $order);
            if (count($products) > 0) {
                $order['products'] = $products;
            }
        }

        return $order;
    }

    /**
     * Extract storefront order line items for `products[]` normalization without
     * breaking admin/reconcile stubs that only implement getOrder().
     *
     * @param object               $orderModel
     * @param array<string, mixed> $order
     * @return array<int, mixed>
     */
    private function orderProducts(int $orderId, $orderModel, array $order): array
    {
        $rows = $orderModel->getProducts($orderId);
        if (!is_array($rows) || count($rows) === 0) {
            return array();
        }

        if (count($rows) > InvoicePresentation::MAX_ITEMS) {
            return array_fill(0, count($rows), array(
                'title' => 'Item',
                'quantity' => 1,
                'unit_amount' => '1.00',
            ));
        }

        $currency = $this->registry->get('currency');
        $currencyCode = isset($order['currency_code']) && is_scalar($order['currency_code'])
            ? strtoupper(trim((string) $order['currency_code']))
            : '';
        $currencyRate = isset($order['currency_value']) && is_numeric($order['currency_value'])
            ? (float) $order['currency_value']
            : 0.0;
        $canConvert = $currencyCode !== ''
            && is_object($currency)
            && is_callable(array($currency, 'format'))
            && (!is_callable(array($currency, 'has')) || $currency->has($currencyCode));
        $decimals = $canConvert && is_callable(array($currency, 'getDecimalPlace'))
            ? max(0, (int) $currency->getDecimalPlace($currencyCode))
            : 2;

        $config = $this->registry->get('config');
        $includeTax = $this->answersTo($config, 'get') && (bool) $config->get('config_tax');
        $productModel = $this->loadCatalogProductModel();
        $productCache = array();

        $products = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $products[] = $row;
                continue;
            }

            $productId = isset($row['product_id']) && is_numeric($row['product_id']) ? (int) $row['product_id'] : 0;
            $details = $this->catalogProductDetails($productModel, $productId, $productCache);

            $description = isset($details['description']) && is_string($details['description'])
                ? $details['description']
                : null;
            $products[] = array(
                'title' => $this->orderProductTitle($orderId, $orderModel, $row),
                'description' => $description,
                'quantity' => array_key_exists('quantity', $row) ? $row['quantity'] : null,
                'unit_amount' => $this->orderProductUnitAmount(
                    $row,
                    $canConvert,
                    $currency,
                    $currencyCode,
                    $currencyRate,
                    $decimals,
                    $includeTax
                ),
            );
        }

        return $products;
    }

    /**
     * @param object               $orderModel
     * @param array<string, mixed> $row
     */
    private function orderProductTitle(int $orderId, $orderModel, array $row): ?string
    {
        if (!isset($row['name']) || !is_string($row['name'])) {
            return null;
        }

        $title = $row['name'];
        if (!$this->answersTo($orderModel, 'getOptions')
            || !isset($row['order_product_id'])
            || !is_numeric($row['order_product_id'])) {
            return $title;
        }

        $options = $orderModel->getOptions($orderId, (int) $row['order_product_id']);
        if (!is_array($options) || count($options) === 0) {
            return $title;
        }

        $parts = array();
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $type = isset($option['type']) && is_scalar($option['type']) ? strtolower(trim((string) $option['type'])) : '';
            if ($type === 'file') {
                continue;
            }
            $optionValue = isset($option['value']) && is_scalar($option['value']) ? trim((string) $option['value']) : '';
            if ($optionValue === '') {
                continue;
            }
            $optionName = isset($option['name']) && is_scalar($option['name']) ? trim((string) $option['name']) : '';
            $parts[] = $optionName !== '' ? ($optionName . ': ' . $optionValue) : $optionValue;
        }

        if (count($parts) === 0) {
            return $title;
        }

        $withOptions = $title . ' (' . implode(', ', $parts) . ')';
        $chars = preg_split('//u', $withOptions, 201, PREG_SPLIT_NO_EMPTY);
        if (is_array($chars) && count($chars) <= 200) {
            return $withOptions;
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $row
     * @param object|null          $currency
     */
    private function orderProductUnitAmount(
        array $row,
        bool $canConvert,
        $currency,
        string $currencyCode,
        float $currencyRate,
        int $decimals,
        bool $includeTax
    ): ?string {
        if (!array_key_exists('price', $row)
            || (!is_string($row['price']) && !is_int($row['price']) && !is_float($row['price']))) {
            return null;
        }

        $rawPrice = is_string($row['price']) ? trim($row['price']) : (string) $row['price'];
        if ($rawPrice === '' || !is_numeric($rawPrice)) {
            return null;
        }
        if ((float) $rawPrice < 0) {
            return $rawPrice;
        }
        if (!$canConvert || !is_object($currency)) {
            return null;
        }

        $baseUnit = (float) $rawPrice;
        if ($includeTax && isset($row['tax']) && is_numeric($row['tax'])) {
            $baseUnit += (float) $row['tax'];
        }
        if ($baseUnit < 0) {
            return '-1';
        }

        $converted = $currency->format($baseUnit, $currencyCode, $currencyRate, false);
        if (!is_numeric($converted)) {
            return null;
        }

        return number_format((float) $converted, $decimals, '.', '');
    }

    /**
     * @return object|null
     */
    private function loadCatalogProductModel()
    {
        $loader = $this->registry->get('load');
        if (!is_object($loader) || !method_exists($loader, 'model')) {
            return null;
        }

        try {
            $loader->model('catalog/product');
            $model = $this->registry->get('model_catalog_product');
            return $this->answersTo($model, 'getProduct') ? $model : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param object|null                    $productModel
     * @param array<int, array<string,mixed>> $cache
     * @return array<string, mixed>
     */
    private function catalogProductDetails($productModel, int $productId, array &$cache): array
    {
        if ($productModel === null || $productId <= 0) {
            return array();
        }
        if (array_key_exists($productId, $cache)) {
            return $cache[$productId];
        }

        try {
            $info = $productModel->getProduct($productId);
            $cache[$productId] = is_array($info) ? $info : array();
        } catch (\Throwable $e) {
            $cache[$productId] = array();
        }

        return $cache[$productId];
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

    public function prepareRefundHistory(int $orderId): void
    {
        $target = $this->isAdmin() ? $this->storefrontFor($orderId) : $this;
        $target->registry->set('db', $this->registry->get('db'));
        if ($target->registry->get('db') !== $this->registry->get('db')) {
            throw new \RuntimeException('refund_native_connection_mismatch');
        }
        $config = $target->registry->get('config');
        $order = $target->getOrder($orderId);
        $paid = array_merge((array) $config->get('config_processing_status'), (array) $config->get('config_complete_status'));
        if (!isset($order['order_status_id']) || !in_array((int) $order['order_status_id'], array_map('intval', $paid), true)) {
            throw new \RuntimeException('refund_native_status_boundary_unavailable');
        }
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

        $storefrontRegistry = $stores->createStoreInstance($storeId, $language);
        $storefront = new self($storefrontRegistry);
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
