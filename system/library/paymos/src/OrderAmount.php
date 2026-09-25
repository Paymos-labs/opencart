<?php

declare(strict_types=1);

namespace PaymosOpenCart;

/**
 * The amount a buyer owes for an OpenCart order, in the ORDER currency.
 *
 * OpenCart stores `order.total` in the store's default (base) currency and the
 * buyer's currency beside it: `currency_code` plus `currency_value`, the rate at
 * the moment the order was placed. The storefront, the order confirmation and
 * every core payment extension show the buyer
 * `$currency->format($total, $currency_code, $currency_value, false)` — base total
 * times the order rate, rounded to that currency's decimal places. Invoicing the
 * raw `total` under `currency_code` charged a USD buyer of a EUR store 100.00 USD
 * for a EUR 100 (≈ USD 108) order.
 */
final class OrderAmount
{
    /**
     * @param array<string, mixed> $order    Row from model checkout/order getOrder().
     * @param object               $currency OpenCart's cart/currency library (registry "currency").
     * @return string Dot-decimal amount at the currency's own decimal places ("108.00", "16050", "33.100").
     */
    public static function inOrderCurrency(array $order, $currency)
    {
        $code = isset($order['currency_code']) && is_scalar($order['currency_code'])
            ? strtoupper(trim((string) $order['currency_code']))
            : '';
        if ($code === '') {
            throw new \RuntimeException('OpenCart order currency is missing.');
        }
        if (!is_object($currency) || !is_callable(array($currency, 'format'))) {
            throw new \RuntimeException('OpenCart currency library is unavailable.');
        }
        // A currency the store has since disabled or deleted cannot be converted:
        // format() would return '' and the raw base-currency total is exactly the
        // figure that must never be invoiced under another currency code.
        if (is_callable(array($currency, 'has')) && !$currency->has($code)) {
            throw new \RuntimeException('OpenCart no longer knows the order currency ' . $code . '.');
        }

        $total = isset($order['total']) && is_numeric($order['total']) ? (float) $order['total'] : null;
        if ($total === null) {
            throw new \RuntimeException('OpenCart order total is missing.');
        }

        // 0 makes OpenCart fall back to the currency's current rate, the same
        // thing it does itself for an order row that carries no rate.
        $rate = isset($order['currency_value']) && is_numeric($order['currency_value']) ? (float) $order['currency_value'] : 0.0;

        $amount = $currency->format($total, $code, $rate, false);
        if (!is_numeric($amount)) {
            throw new \RuntimeException('OpenCart could not convert the order total into ' . $code . '.');
        }

        $decimals = is_callable(array($currency, 'getDecimalPlace')) ? (int) $currency->getDecimalPlace($code) : 2;

        return number_format((float) $amount, max(0, $decimals), '.', '');
    }
}
