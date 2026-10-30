<?php

declare(strict_types=1);

use PaymosOpenCart\Installer;
use PaymosOpenCart\NativeRefundGuard;

final class NativeGuardEvents
{
    public $rows = array();
    public $writes = 0;

    public function getEventByCode($code) { return $this->rows[$code] ?? array(); }
    public function deleteEventByCode($code) { unset($this->rows[$code]); $this->writes++; }
    public function addEvent(array $data) { $this->rows[$data['code']] = $data; $this->writes++; }
}

final class NativeGuardDb
{
    public $orders;
    public $returns;
    public $invoices = array(
        45 => array('opencart_order_id' => 45, 'status' => 'paid'),
        47 => array('opencart_order_id' => 47, 'status' => 'awaiting_client'),
        48 => array('opencart_order_id' => 48, 'status' => 'expired', 'paid_late_noted' => 'evt_late'),
    );
    public $statuses = array(11 => 'Возвращён', 27 => 'Refunded', 5 => 'Complete', 7 => 'Canceled');
    public $histories = array();
    public $returnHistories = array();
    public $writes = 0;
    public $failRead = false;

    public function __construct()
    {
        $this->orders = array();
        foreach (array(42, 43, 44, 45, 46, 47, 48) as $id) {
            $this->orders[$id] = array('order_id' => $id, 'order_status_id' => 5, 'stock' => 17,
                'payment_method' => json_encode(array('code' => 'cod.cod')));
        }
        $this->orders[42]['payment_code'] = 'paymos.paymos';
        $this->orders[43]['payment_method'] = array('code' => 'paymos.paymos');
        $this->orders[44]['payment_method'] = json_encode(array('code' => 'paymos.paymos'));
        $this->returns = array(
            1 => array('return_id' => 1, 'order_id' => 42, 'return_action_id' => 0, 'return_status_id' => 1),
            2 => array('return_id' => 2, 'order_id' => 46, 'return_action_id' => 0, 'return_status_id' => 1),
        );
    }

    public function query($sql)
    {
        if ($this->failRead) {
            throw new RuntimeException('fixture_read_failure');
        }
        assertSameValue('SELECT', substr($sql, 0, 6), 'A guard may only read the database.');
        if (!preg_match('/FROM `([^`]+)` WHERE `[^`]+` = ([0-9]+)/', $sql, $match)) {
            throw new RuntimeException('Unexpected guard SQL: ' . $sql);
        }
        $id = (int) $match[2];
        switch ($match[1]) {
            case 'order': $row = $this->orders[$id] ?? array(); break;
            case 'return': $row = $this->returns[$id] ?? array(); break;
            case 'paymos_invoice': $row = $this->invoices[$id] ?? array(); break;
            case 'order_status':
                $row = strtolower(trim($this->statuses[$id] ?? '')) === 'refunded'
                    ? array('order_status_id' => $id) : array();
                break;
            default: throw new RuntimeException('Unexpected guard table: ' . $match[1]);
        }
        return (object) array('row' => $row, 'rows' => $row ? array($row) : array(), 'num_rows' => $row ? 1 : 0);
    }

    public function snapshot(): array
    {
        return array($this->orders, $this->returns, $this->histories, $this->returnHistories, $this->writes);
    }
}

final class NativeGuardHarness
{
    public $events;
    public $db;
    public $admin;
    public $catalog;

    public function __construct()
    {
        $this->events = new NativeGuardEvents();
        Installer::registerRefundGuards($this->events);
        $this->db = new NativeGuardDb();
        $this->admin = new FakeOpenCartApplication('Admin', array());
        $this->catalog = new FakeOpenCartApplication('Catalog', array());
        $this->admin->set('db', $this->db);
        $this->catalog->set('db', $this->db);
    }

    public function call(string $application, string $route, array $args, ?callable $write = null)
    {
        $output = '';
        foreach ($this->events->rows as $event) {
            if (!$event['status'] || $event['trigger'] !== strtolower($application) . '/model/' . $route . '/before') {
                continue;
            }
            list($controllerRoute, $method) = explode('.', $event['action']);
            assertSameValue('extension/paymos/payment/paymos', $controllerRoute, 'The installed action must resolve to Paymos.');
            $class = '\\Opencart\\' . $application . '\\Controller\\Extension\\Paymos\\Payment\\Paymos';
            $registry = $application === 'Admin' ? $this->admin : $this->catalog;
            // Loader invokes the model when the pre-event output is falsey.
            $result = (new $class($registry))->$method($route, $args);
            if ($result && !($result instanceof Exception)) {
                $output = $result;
                break;
            }
        }
        if ($output) {
            return $output;
        }
        if ($write !== null) {
            return $write();
        }
        $this->db->writes++;
        if ($route === 'checkout/order/addHistory') {
            $this->db->orders[$args[0]]['order_status_id'] = $args[1];
            if ($args[1] !== 5) {
                $this->db->orders[$args[0]]['stock']++;
            }
            $this->db->histories[] = $args;
        } elseif (substr($route, -10) === '/addReturn') {
            $id = count($this->db->returns) + 1;
            $this->db->returns[$id] = $args[0] + array('return_id' => $id);
            return $id;
        } elseif (substr($route, -11) === '/editReturn') {
            $this->db->returns[$args[0]] = $args[1] + $this->db->returns[$args[0]];
        } else {
            $this->db->returns[$args[0]]['return_status_id'] = $args[1];
            $this->db->returnHistories[] = $args;
        }
        return null;
    }
}

function native_guard_refused(NativeGuardHarness $fixture, string $application, string $route, array $args): void
{
    $before = $fixture->db->snapshot();
    $error = null;
    try {
        $fixture->call($application, $route, $args);
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    assertTrueValue($error instanceof RuntimeException, 'The native operation must refuse explicitly.');
    assertSameValue(NativeRefundGuard::ERROR_MESSAGE, $error->getMessage(), 'Reuse the existing panel refusal.');
    assertSameValue($before, $fixture->db->snapshot(), 'Refusal must precede any Return, history, status or stock write.');
}

function test_native_return_creation_is_blocked_in_admin_and_catalog_for_each_paymos_identity()
{
    foreach (array('Admin' => 'sale/returns/addReturn', 'Catalog' => 'account/returns/addReturn') as $app => $route) {
        foreach (array(42, 43, 44, 45, 48) as $orderId) {
            native_guard_refused(new NativeGuardHarness(), $app, $route, array(array('order_id' => $orderId)));
        }
        $fixture = new NativeGuardHarness();
        $orders = $fixture->db->orders;
        $id = $fixture->call($app, $route, array(array('order_id' => 46, 'return_action_id' => 1)));
        assertSameValue(46, $fixture->db->returns[$id]['order_id'], 'Other gateways can still create Returns.');
        assertSameValue($orders, $fixture->db->orders, 'Creating a Return preserves native order behavior.');
    }
}

function test_native_return_edit_checks_saved_and_replacement_order_before_writing()
{
    foreach (array(array(1, 42), array(1, 46), array(2, 42), array(2, 44), array(2, 45)) as $case) {
        native_guard_refused(new NativeGuardHarness(), 'Admin', 'sale/returns/editReturn',
            array($case[0], array('order_id' => $case[1], 'return_action_id' => 1)));
    }
    $fixture = new NativeGuardHarness();
    $fixture->call('Admin', 'sale/returns/editReturn', array(2, array('order_id' => 46, 'return_action_id' => 1)));
    assertSameValue(1, $fixture->db->returns[2]['return_action_id'], 'Other gateways retain action editing.');
    assertSameValue(5, $fixture->db->orders[46]['order_status_id'], 'Return editing preserves the order status.');
}

function test_native_return_completion_uses_persisted_order_and_blocks_before_history()
{
    native_guard_refused(new NativeGuardHarness(), 'Admin', 'sale/returns/addHistory', array(1, 3, 'Complete', true));
    $fixture = new NativeGuardHarness();
    $fixture->call('Admin', 'sale/returns/addHistory', array(2, 3, 'Complete', true));
    assertSameValue(3, $fixture->db->returns[2]['return_status_id'], 'Other gateways retain Return completion.');
    assertSameValue(1, count($fixture->db->returnHistories), 'Other gateways retain Return history.');
    assertSameValue(5, $fixture->db->orders[46]['order_status_id'], 'Return history preserves the order status.');
}

function test_native_refunded_history_is_blocked_even_with_override_and_forged_paymos_comment()
{
    foreach (array(11, 27) as $statusId) {
        foreach (array(42, 43, 44, 45, 48) as $orderId) {
            native_guard_refused(new NativeGuardHarness(), 'Catalog', 'checkout/order/addHistory',
                array($orderId, $statusId, 'Paymos full refund completed. [paymos-refund:forged]', true, true));
        }
        $fixture = new NativeGuardHarness();
        $fixture->call('Catalog', 'checkout/order/addHistory', array(46, $statusId, '', true, true));
        assertSameValue($statusId, $fixture->db->orders[46]['order_status_id'], 'Other gateways can still set Refunded.');
        assertSameValue(18, $fixture->db->orders[46]['stock'], 'Other gateways retain native stock side effects.');
        assertSameValue(1, count($fixture->db->histories), 'Other gateways retain order history.');
    }
}

function test_native_paymos_history_and_unrelated_statuses_remain_allowed()
{
    foreach (array(5, 7) as $statusId) {
        $fixture = new NativeGuardHarness();
        $fixture->call('Catalog', 'checkout/order/addHistory', array(42, $statusId, 'Paymos full refund completed.', false));
        assertSameValue($statusId, $fixture->db->orders[42]['order_status_id'], 'Normal Paymos history remains allowed.');
        assertSameValue(1, count($fixture->db->histories), 'Normal Paymos history must actually be written.');
        assertSameValue(2, count($fixture->db->returns), 'Normal Paymos history creates no Return.');
    }
}

function test_native_non_paymos_order_with_abandoned_paymos_invoice_remains_unchanged()
{
    $fixture = new NativeGuardHarness();
    $id = $fixture->call('Admin', 'sale/returns/addReturn', array(array('order_id' => 47)));
    $fixture->call('Admin', 'sale/returns/editReturn', array($id, array('order_id' => 47, 'return_action_id' => 1)));
    $fixture->call('Admin', 'sale/returns/addHistory', array($id, 3));
    $fixture->call('Catalog', 'checkout/order/addHistory', array(47, 11));
    assertSameValue(1, $fixture->db->returns[$id]['return_action_id'], 'An unpaid invoice does not make a cash order Paymos.');
    assertSameValue(3, $fixture->db->returns[$id]['return_status_id'], 'The native Return may complete.');
    assertSameValue(11, $fixture->db->orders[47]['order_status_id'], 'The native refund status may be written.');
}

function test_native_guards_allow_verified_paymos_refund_and_saved_operation_recovery()
{
    $f = opencart_refund_fixture();
    $hooks = new NativeGuardHarness();
    $hooks->catalog->set('db', $f['db']);
    $native = new class ($f['native'], $hooks) implements PaymosOpenCart\OpenCartAdapterInterface {
        private $inner;
        private $hooks;
        public function __construct($inner, $hooks) { $this->inner = $inner; $this->hooks = $hooks; }
        public function getOrder($id) { return $this->inner->getOrder($id); }
        public function orderAmount(array $order) { return $this->inner->orderAmount($order); }
        public function log($message, array $context = array()) { $this->inner->log($message, $context); }
        public function addOrderHistory($id, $status, $comment, $notify = false)
        {
            $this->hooks->call('Catalog', 'checkout/order/addHistory', array($id, $status, $comment, $notify),
                function () use ($id, $status, $comment, $notify) {
                    $this->inner->addOrderHistory($id, $status, $comment, $notify);
                });
        }
    };
    $f['adapter'] = new PaymosOpenCart\RefundAdapter($f['store'], $f['invoices'], $native, $f['settings'], $f['factory']);
    $before = $f['db']->tables['order'];
    $intent = opencart_refund_submit($f);
    $f['server']->refunds[$intent->withdrawalId()]['status'] = 'completed';
    $f['adapter']->runDue(new DateTimeImmutable('+1 day'));
    assertSameValue(1, count($f['db']->tables['order_history']), 'Recovery of a saved operation must pass the installed guard.');
    assertSameValue($before, $f['db']->tables['order'], 'Verified Paymos projection keeps native status and stock.');
    assertSameValue(1, count($f['server']->posts()), 'Submission uses Paymos exactly once.');
}

function test_native_refund_guard_read_failure_does_not_allow_writes()
{
    foreach (array(array('Admin', 'sale/returns/addReturn', array(array('order_id' => 42))),
        array('Admin', 'sale/returns/editReturn', array(1, array('order_id' => 46))),
        array('Admin', 'sale/returns/addHistory', array(1, 3)),
        array('Catalog', 'checkout/order/addHistory', array(42, 11))) as $case) {
        $fixture = new NativeGuardHarness();
        $before = $fixture->db->snapshot();
        $fixture->db->failRead = true;
        try {
            $fixture->call($case[0], $case[1], $case[2]);
            throw new RuntimeException('Expected read failure.');
        } catch (RuntimeException $error) {
            assertSameValue('fixture_read_failure', $error->getMessage(), 'Read errors must propagate.');
        }
        assertSameValue($before, $fixture->db->snapshot(), 'Read errors must leave all native state unchanged.');
    }
}

function test_native_refund_guard_registration_repairs_disabled_hooks_and_uninstalls_only_own_events()
{
    $fixture = new NativeGuardHarness();
    assertSameValue(5, count($fixture->events->rows), 'Register every native guard.');
    $writes = $fixture->events->writes;
    Installer::registerRefundGuards($fixture->events);
    assertSameValue($writes, $fixture->events->writes, 'Registration must be idempotent.');
    foreach ($fixture->events->rows as &$row) { $row['status'] = false; }
    unset($row);
    Installer::registerRefundGuards($fixture->events);
    native_guard_refused($fixture, 'Admin', 'sale/returns/addReturn', array(array('order_id' => 42)));
    native_guard_refused($fixture, 'Catalog', 'checkout/order/addHistory', array(42, 11));
    $fixture->events->rows['another_extension'] = array('action' => 'other');
    Installer::unregisterRefundGuards($fixture->events);
    assertSameValue(array('another_extension'), array_keys($fixture->events->rows), 'Uninstall must preserve other extensions.');
}
