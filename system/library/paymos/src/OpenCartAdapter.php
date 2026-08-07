<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class OpenCartAdapter implements OpenCartAdapterInterface
{
    /** @var object */
    private $registry;

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
        $this->registry->get('load')->model('checkout/order');
        $model = $this->registry->get('model_checkout_order');
        if (!$this->answersTo($model, 'getOrder')) {
            return array();
        }

        $order = $model->getOrder((int) $orderId);
        return is_array($order) ? $order : array();
    }

    public function addOrderHistory($orderId, $orderStatusId, $comment, $notify = false)
    {
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
