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

    public function getOrder($orderId)
    {
        $this->registry->get('load')->model('checkout/order');
        $model = $this->registry->get('model_checkout_order');
        if (!is_object($model) || !method_exists($model, 'getOrder')) {
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

        if (method_exists($model, 'addHistory')) {
            $model->addHistory((int) $orderId, (int) $orderStatusId, (string) $comment, (bool) $notify);
            return;
        }

        if (method_exists($model, 'addOrderHistory')) {
            $model->addOrderHistory((int) $orderId, (int) $orderStatusId, (string) $comment, (bool) $notify);
            return;
        }

        throw new \RuntimeException('OpenCart checkout order history method is unavailable.');
    }

    public function log($message, array $context = array())
    {
        $log = $this->registry->get('log');
        if (!is_object($log) || !method_exists($log, 'write')) {
            return;
        }

        $suffix = count($context) === 0 ? '' : ' ' . json_encode($context);
        $log->write('[Paymos] ' . (string) $message . $suffix);
    }
}
