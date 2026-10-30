<?php

declare(strict_types=1);

// Run only on an approved stand with real OpenCart packages and native DB/mail services.
// The local runner has SQL/service doubles; it cannot satisfy this contract.
$refundNativeContract = array(
    'versions' => array('4.0.2.0', '4.1.0.4'),
    'evidence' => array('exact_package_version_and_sha256', 'php_version', 'mysql_or_mariadb_version',
        'same_registry_connection_id', 'transactional_table_engines', 'native_order_and_history_rows', 'mail_capture'),
    'checks' => array(
        'protected_routes' => 'Native admin dispatch, token/CSRF, modify permission, wrong store and payment method.',
        'mode_only_preflight' => 'Both quick actions; partial/manual native history and amount override fail before POST.',
        'over20_full100' => 'Paid100/gross120: over20 cash note/refunded0, then full100 original native paid total.',
        'stable_unknown' => 'Timeout and process restart reuse the committed token, mode, key and withdrawal.',
        'scoped_callback' => 'Signed foreign project/env/ordinary payout cannot mutate an order; GET remains authoritative.',
        'native_atomicity' => 'Kill before/after history+marker commit, including admin storefront connection.',
        'reorg_recomplete' => 'Retain full record/over note on rewind; no second history, stock or notification.',
        'notifier_boundary' => 'notify=false, unchanged paid business status and stock on each supported native package.',
        'upgrade_reinstall' => 'Legacy install upgrade, retained unresolved history, disabled new gate and historical sweep.',
        'native_cron' => 'Native minute cron registration/dispatch, restart and due backoff on each pinned package.',
    ),
);

foreach ($refundNativeContract['checks'] as $case => $requirement) {
    echo 'SKIP native_opencart_refund_' . $case . ': no approved OpenCart package/DB/mail stand is attached; required versions '
        . implode(', ', $refundNativeContract['versions']) . ".\n";
}
echo 'NATIVE passed=0 failed=0 skipped=' . count($refundNativeContract['checks']) . "\n";

return $refundNativeContract;
