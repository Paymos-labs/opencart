<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/GatewayCheckoutTest.php';
require __DIR__ . '/EventStoreTest.php';
require __DIR__ . '/CallbackProcessorTest.php';
require __DIR__ . '/ReconcilerTest.php';

$count = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    paymos_opencart_reset_test_state();
    $function();
    paymos_opencart_reset_test_state();
    $count++;
    echo "PASS {$function}\n";
}

paymos_opencart_reset_test_state();

echo "OK {$count} tests\n";
