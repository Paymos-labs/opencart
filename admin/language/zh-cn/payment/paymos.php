<?php

$_['heading_title'] = 'Paymos';
$_['text_extension'] = '扩展';
$_['text_success'] = 'Paymos 设置已保存。';
$_['text_reconcile_success'] = 'Paymos 对账已完成。已处理订单：%s。';
$_['text_edit'] = 'Paymos 设置';
$_['text_sandbox'] = '沙盒';
$_['text_live'] = '正式模式';
$_['text_generated_config'] = '密钥由「连接 Paymos」下发，并在本 OpenCart 安装中加密存储。';
$_['text_manual_config'] = '本商店尚未连接。点击「连接 Paymos」并批准当前项目。';
$_['text_manual_credentials'] = '连接';
$_['text_title_default'] = '用稳定币付款';

$_['entry_status'] = '状态';
$_['entry_mode'] = '模式';
$_['entry_credentials'] = '访问密钥';
$_['entry_webhook_url'] = 'Webhook 地址';
$_['entry_title'] = '标题';
$_['entry_button_text'] = '按钮文字';
$_['entry_pending_status'] = '「等待付款」状态';
$_['entry_confirming_status'] = '「确认中」状态';
$_['entry_paid_status'] = '「已付款」状态';
$_['entry_failed_status'] = '「付款失败」状态';
$_['entry_cancelled_status'] = '「已取消」状态';
$_['entry_sort_order'] = '排序';
$_['entry_api_key'] = 'API 密钥';
$_['entry_api_secret'] = 'API 密钥（secret）';
$_['entry_project_id'] = '项目 ID';
$_['entry_webhook_secret'] = 'Webhook 密钥';

$_['help_mode'] = '用沙盒进行测试付款，准备好后切换到正式模式。';
$_['help_webhook_url'] = '连接商店时，Paymos 会为沙盒和正式模式自动注册此地址。';

$_['button_reconcile'] = '对账 Paymos 订单';
$_['button_confirm'] = '通过 Paymos 付款';

$_['error_permission'] = '警告：你没有修改 Paymos 设置的权限。';

// Connect and refund interface
$_['text_paymos_connect'] = '连接 Paymos';
$_['text_paymos_connect_intro'] = '将此商店网址连接到 Paymos 中当前选定的项目。';
$_['text_paymos_connected'] = '已安全连接';
$_['text_paymos_connect_store'] = '请将此商店连接到 Paymos。';
$_['text_paymos_connect_starting'] = '正在发起安全连接…';
$_['text_paymos_connect_waiting'] = '正在等待授权。验证码：';
$_['text_paymos_connect_open'] = '打开授权页面';
$_['text_paymos_connect_blocked'] = '浏览器拦截了授权标签页。';
$_['text_paymos_connect_code'] = ' 验证码：';
$_['text_paymos_refunds'] = 'Paymos 退款';
$_['text_paymos_refund_order_id'] = '订单编号';
$_['text_paymos_refund_show'] = '显示';
$_['text_paymos_refund_full'] = '全额退款';
$_['text_paymos_refund_overpayment'] = '退还多付金额';
$_['text_paymos_refund_operation'] = '操作编号';
$_['text_paymos_refund_type'] = '类型';
$_['text_paymos_refund_status'] = '状态';
$_['text_paymos_refund_amount'] = '金额';
$_['text_paymos_refund_loaded'] = '退款详情已加载。';
$_['text_paymos_refund_load_failed'] = '无法加载退款详情。请检查订单编号后重试。';
$_['text_paymos_refund_request_failed'] = '退款请求失败。请先查看下方退款记录，再重试。';
$_['text_paymos_refund_not_sent'] = '退款请求未发送。请刷新页面后重试。';
$_['text_paymos_calculated'] = 'Paymos 将在发送退款前计算金额。';
