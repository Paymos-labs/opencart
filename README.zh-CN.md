# Paymos OpenCart 支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 OpenCart 4.0.2.0 及以上版本稳定币支付扩展。买家确认订单后跳转至 Paymos 托管收银台，在项目启用的 USDT/USDC 等稳定币和链网络中任选一种完成支付——包括 Tron、Ethereum、BSC、Solana 以及其余支持的网络。随后 OpenCart 订单由经过 HMAC-SHA256 签名验证的回调自动推进，而非依赖浏览器跳回。账单按订单自身币种计价，并在买家付款的瞬间锁定汇率。

最值得提前了解的一点：每个支付状态对应写入哪一个*自定义*订单状态，完全由你决定。扩展自带默认映射，但五个状态映射全部是从你店铺现有状态列表中拉取的下拉项，让 Paymos 顺应你现有的运营流程，而不是强加一套新流程。

[![观看视频：12 款官方 CMS 稳定币支付插件 — Paymos 一键连接](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，内置 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- OpenCart 4.0.2.0 或更高版本；扩展不支持在 4.0.0.0–4.0.1.1 上安装；
- PHP 8.0 及以上版本，需启用 `curl`、`hash`、`json` 和 `openssl` 扩展；
- 公网可访问的 HTTPS 店铺地址（Store URL）；
- 一个 Paymos 账户，且已在控制台打开用于接收该店面账单的项目。

Paymos PHP SDK 已直接打包在扩展内——无需使用 Composer 安装任何依赖。后台与前台文案内置英语、俄语、德语、西班牙语、土耳其语和简体中文。

## 安装与一键连接

1. 从 [GitHub Releases](https://github.com/paymos-labs/opencart/releases/latest) 下载最新安装包。
2. 在 **Extensions → Installer（扩展安装器）** 中上传 `paymos.ocmod.zip`。
3. 打开 **Extensions → Extensions**，选择 **Payments（支付方式）** 并安装 Paymos。
4. 点击编辑进入设置页，同时在 Paymos 控制台打开目标项目；连接时会自动绑定该当前项目。
5. 在扩展设置中点击 **Connect Paymos**。
6. 在 Paymos 页面核对并批准显示的店铺 URL 和当前项目。

**请勿重命名压缩包。** OpenCart 会依据上传文件名推导扩展目录，包内每个控制器的命名空间也都基于该目录名生成。重命名后的 zip 会安装到一个内部代码无法寻址的目录中，导致扩展静默加载失败。

发布包对所有商户完全一致，不含任何凭证：没有 API 密钥或 secret、没有项目 ID、没有 webhook secret、没有 OAuth token，也没有 device code。在第 6 步完成之前，扩展不会启用任何连接。

一次批准会同时打通 Sandbox 和 Live 两套环境。对于每套环境，Paymos 都会复用你唯一的活跃 Payment 密钥（若尚不存在则自动创建），并为该店铺绑定一个 Invoice webhook——仅当现有 webhook 的 URL、类别和项目全部吻合时才会复用，同一地址上的其他 webhook 会原样保留而不会被覆盖。设备授权仅作为一次性投递通道；其短时 token 很快过期并被丢弃，后续 Merchant API 调用均使用 HMAC 签名。

## Secret 存储

凭证与临时设备状态使用由 `config_encryption` 派生的密钥，以 AES-256-GCM 加密封装的形式保存在 OpenCart 配置中。已保存的 secret 不会回显到后台页面，也不存在手动输入框；保存配置表单时会原样保留加密封装不被破坏。密钥或 webhook secret 轮换后，重新连接即可更新。

## 设置项说明

| 设置项 | 作用 |
|---|---|
| Status | 是否在结账页显示该支付方式 |
| Mode | Sandbox 或 Live |
| Credentials | 只读——显示当前店铺是否已连接 |
| Webhook URL | 只读——已自动注册，展示在此方便测试连通性 |
| Title | 买家在结账页看到的支付方式名称 |
| Button text | 打开托管收银台的按钮文字 |
| Pending / Confirming / Paid / Failed / Cancelled status | 每个支付状态映射到的店铺订单状态 |
| Sort order | 在所有支付方式中的排序位置 |

为 OpenCart 注册的 webhook 接收地址如下：

```text
https://your-store.example/index.php?route=extension/paymos/payment/paymos.callback
```

## 订单状态

OpenCart 会照常创建订单，将其置入你指定的 **Pending status** 并写入一条 *Awaiting Paymos payment* 历史记录，然后将买家导向托管收银台。此后一切流转均由回调驱动。

| 支付状态 | 使用的映射状态 | 原生安装默认值 |
|---|---|---|
| 检测到链上转账，正在等待确认 | Confirming status | Processing |
| 仅收到部分金额，或已计入的转账因链上重组失效 | Pending status | Pending |
| 账单已付清或超额付款 | Paid status | Complete |
| 超过截止时间仍未付足 | Failed status | Failed |
| 账单过期或已取消 | Cancelled status | Canceled |

每次状态流转都会写入一条附带 Paymos 账单编号的订单历史记录；当款项在链上结算时，已付款记录还会包含交易哈希及其区块浏览器链接。

有两条规则优先于上表。第一，订单一旦进入你设定的 Paid status，任何迟到或乱序的回调都无法将其回退——过时状态仅记作历史备注并被忽略。第二，如果已付款回调中的金额与订单不再一致，订单会转入 Confirming status 并附带 *needs manual review*（需人工审核）备注，而不会直接完成，因为账单创建后才改动的订单总额不应由软件擅自结案。

## 在 Sandbox 中测试

1. 保持 **Mode** 为 Sandbox，启用该支付方式，并通过前台店面下一笔订单。
2. 在 Paymos 控制台找到该账单，手动触发想要演练的结果——已付、超额支付、少付、取消。Sandbox 不会按定时器自动确认付款。
3. 查看订单的 History（历史）标签页：每个事件都应按顺序记录，并对应你映射的订单状态。
4. 将 **Mode** 切换为 Live。两套环境在同一次一键连接中均已配置完成，无需额外操作。

Sandbox 付款背后没有真实区块链，因此已付款历史记录中不会显示交易哈希或区块浏览器链接，这属于正常现象。

## 回调与对账

每次投递都携带格式为 `t={timestamp},v1={hmac_hex}` 的 `X-Webhook-Signature` 请求头，针对传输的原始字节进行常量时间比对的 HMAC-SHA256 签名验证，并在密钥轮换期间同时接受新旧两个 secret。系统会记录事件 ID，因此重复投递会直接确认应答，而不会重复作用到订单上。

终态回调绝不会仅凭签名就放行：在推进订单之前，扩展会向 Merchant API 回查该账单并与本地快照比对。

这里的账单对账是一个手动按钮，而非 cron 任务。设置页顶部的 **Reconcile Paymos orders** 会拉取过去 24 小时内最多 50 笔未终结账单，逐一从 API 查询最新状态、走完同一套状态映射与保护校验、同步对账处理中的退款，并反馈本次更新的记录数。服务中断恢复后或发现订单状态停滞时可使用该按钮。

## 退款

扩展通过 Paymos Merchant API（`/v1/refunds`）支持两种由服务端计算的原生退款模式：`full`（全额账单退款）和 `overpayment`（退还 `paid_over` 账单的超额部分）。资金作为一笔新的出站链上交易，严格退回 Paymos 在已付账单上记录并验证的 `first_sender` 地址——不支持手动填写收款钱包地址，也不支持任意金额的部分退款。

在 OpenCart 发起或查看退款的步骤如下：

1. 在 OpenCart 后台打开 **Extensions → Extensions → Payments → Paymos**。
2. 在 **Paymos refunds** 面板（`refundPanel`）输入 OpenCart 订单 ID，加载可退金额、经验证的 `first_sender` 地址以及已有退款记录。
3. 选择 `full` 退款操作或 `overpayment` 退款操作（`extension/paymos/payment/paymos.refund`）。

安装或打开该扩展时会自动注册一个每分钟运行的 OpenCart cron 任务（`paymos_refunds` → `extension/paymos/payment/paymos.refundCron`）；同时，经过签名的回调和 **Reconcile Paymos orders** 按钮也会推进处理中的退款操作。已完成的退款在通过 Merchant API 核验后会写入 OpenCart 订单历史，Sandbox 模式支持模拟退款终态（`completed`、`failed`、`cancelled`）。

## 故障排查

**Paymos 无法保持已安装状态。** 店铺运行的 OpenCart 版本低于 4.0.2.0。扩展会自动卸载、不创建任何数据表，并在 OpenCart 错误日志中留下一条 `[Paymos] Installation refused` 记录，指明检测到的版本号。升级 OpenCart 后重新安装 Paymos 即可。

**一键连接提示店铺必须支持 HTTPS 访问。** 全新安装的 OpenCart 可能未配置 store URL，此时扩展会改用 catalog URL；若该地址仍是 http，系统会拒绝连接，而不会停留在半完成状态。报错信息会写出检测到的地址——在 OpenCart 的 `config.php` 中改为 https 形式后重试。

**订单一直停留在 Pending。** 请从公网外部请求上方回调 URL，它必须在无 HTTP 认证且无重定向的情况下响应——系统不会跟随重定向。随后检查 OpenCart 错误日志中带 `[Paymos]` 的记录。

**结账报错 "Paymos is temporarily unavailable"。** 说明账单未能创建。错误日志会列出具体原因，最常见的是当前环境未完成连接，或订单币种不在项目支持的计价币种范围内。

**订单提示需要人工审核。** 订单总额在账单创建后到付款到账前发生了变化。历史备注中保留了前后两个金额——请人工核对处理。

- [文档](https://paymos.io/zh-hans/docs/cms-opencart)
- [源码](https://github.com/paymos-labs/opencart)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
