<?php

declare(strict_types=1);

namespace PaymosOpenCart;

final class Autoloader
{
    public static function register()
    {
        spl_autoload_register(static function ($class) {
            $prefix = 'PaymosOpenCart\\';
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $relative = substr($class, strlen($prefix));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
                return;
            }

            $sdkPrefix = 'Paymos\\';
            if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($sdkPrefix));
            $localVendor = dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($localVendor)) {
                require $localVendor;
            }
        });
    }
}
