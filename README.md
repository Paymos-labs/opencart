# Paymos for OpenCart

Official Paymos payment extension for OpenCart 4.0.2.0 and later. The shopper confirms the order,
lands on the hosted Paymos checkout, and pays in whichever token and network your
project enables — Tron, Ethereum, BSC, Solana and the rest of the supported set.
Your OpenCart order then moves on its own, driven by a signed callback rather than
by the browser coming back. The order's own currency is what the invoice is priced
in, and the rate stops moving the moment the shopper pays.

The part worth knowing before anything else: you decide which of *your* order
statuses each payment state writes. The extension ships defaults, but the five
mappings are dropdowns filled from your own status list, so Paymos fits the
workflow the store already runs instead of imposing one.

## Requirements

- OpenCart 4.0.2.0 or later; the extension will not install on 4.0.0.0–4.0.1.1;
- PHP 7.4 or later with the `curl`, `hash`, `json` and `openssl` extensions;
- a Store URL on HTTPS that the public internet can reach;
- a Paymos account, with the project this store should bill into already open in the dashboard.

The Paymos PHP SDK is vendored inside the package — nothing to install with
Composer. Admin and storefront strings ship in English, Russian, German, Spanish,
Turkish and Simplified Chinese.

## Install and connect

1. Download the latest package from [GitHub Releases](https://github.com/paymos-labs/opencart/releases/latest).
2. Upload `paymos.ocmod.zip` in **Extensions → Installer**.
3. Open **Extensions → Extensions**, choose **Payments**, and install Paymos.
4. Edit it, then open the intended project in the Paymos dashboard; that current project is used automatically.
5. Click **Connect Paymos** in the extension settings.
6. Approve the displayed store URL and current project in Paymos.

**Do not rename the archive.** OpenCart derives the extension directory from the
uploaded file name, and every controller namespace in the package is built from
that directory. A renamed zip installs into a folder nothing inside it can address,
and the extension quietly fails to load.

The release is one file for everybody, and it holds no credentials of any kind: no
API key or secret, no project id, no webhook secret, no OAuth token, no device
code. Nothing is armed until step 6 completes.

Approval provisions Sandbox and Live together. For each one Paymos uses your single
active Payment key, or makes one where there is none, and points an Invoice webhook
at this store — an existing webhook is reused only when its URL, category and
project all line up, and a different webhook at the same address is left in place
rather than overwritten. Device authorization is a delivery channel and nothing
more; its token expires quickly, is discarded, and Merchant API calls are
HMAC-signed.

## Secret storage

Credentials and temporary device state are stored as an AES-256-GCM OpenCart
setting keyed from config_encryption. Saved secrets are not rendered back into the
administration page and there is no field that accepts them; saving the form
preserves the sealed envelope untouched. Reconnect after a key or webhook secret
is rotated.

## Settings

| Setting | What it controls |
|---|---|
| Status | Whether the method is offered at checkout |
| Mode | Sandbox or Live |
| Credentials | Read-only — whether this store is connected |
| Webhook URL | Read-only — registered for you, shown so you can test it |
| Title | The method name the shopper reads at checkout |
| Button text | The label on the button that opens the hosted checkout |
| Pending / Confirming / Paid / Failed / Cancelled status | Your order status for each payment state |
| Sort order | Position among the other payment methods |

The webhook OpenCart is registered to receive on:

```text
https://your-store.example/index.php?route=extension/paymos/payment/paymos.callback
```

## Order statuses

The order is created by OpenCart as usual, dropped into your **Pending status**
with an *Awaiting Paymos payment* history line, and the shopper is sent to the
hosted checkout. From there the callback drives it.

| Payment state | Status used | Default on a stock install |
|---|---|---|
| Transfer seen, gathering confirmations | Confirming status | Processing |
| Part of the amount arrived, or a confirmed transfer was reorged away | Pending status | Pending |
| Invoice paid, or paid over | Paid status | Complete |
| Underpaid past the deadline | Failed status | Failed |
| Expired or cancelled | Cancelled status | Canceled |

Each transition writes an order history line naming the Paymos invoice, and the
paid line carries the transaction hash and its explorer link when the payment
settled on chain.

Two behaviours override the table. Once an order sits in your Paid status, a late
or out-of-order callback cannot pull it back — the stale status is recorded as a
history note and ignored. And a paid callback whose amount no longer matches the
order goes to the Confirming status with a *needs manual review* note rather than
completing, because an order total that changed after the invoice was created is
not something software should settle for you.

## Test in Sandbox

1. Leave **Mode** on Sandbox, enable the method, and put an order through your own storefront.
2. Find that invoice in the Paymos dashboard and trigger the outcome you want — paid, overpaid, underpaid, cancelled. Sandbox does not confirm anything on a timer.
3. Check the order's history tab: each event should be there, in order, with the status you mapped.
4. Set **Mode** to Live. Both environments came out of the same connect, so there is nothing further to set up.

Sandbox payments have no chain behind them, so no transaction hash or explorer
link appears on the paid line. That is normal.

## Callbacks and reconciliation

Every delivery carries `X-Webhook-Signature` in the form `t={timestamp},v1={hmac_hex}`,
HMAC-SHA256 over the exact bytes sent, compared in constant time, and accepted from
either secret while one is being rotated. Event ids are stored, so a redelivery is
acknowledged rather than replayed onto the order.

A terminal callback is never trusted on its signature alone: the invoice is fetched
back from the Merchant API and compared with the stored snapshot before the order
moves.

Reconciliation here is a button, not a cron job. **Reconcile Paymos orders** at the
top of the settings page takes up to 50 unresolved invoices from the last 24 hours,
pulls each one from the API, and runs it through the same mapping and the same
guards, then tells you how many orders it moved. Use it after an outage, or whenever
an order looks stuck.

## Troubleshooting

**Paymos does not stay installed.** The store runs an OpenCart release before
4.0.2.0. The extension removes itself, creates none of its tables, and leaves a
`[Paymos] Installation refused` line naming the version it found in the OpenCart
error log. Update OpenCart, then install Paymos again.

**Connect says the store must be reachable over HTTPS.** A freshly installed
OpenCart can have an empty store URL, in which case the catalog URL is used
instead; if that one is still http, connecting is refused rather than
half-completed. The message names the URL it found — put its https form in your
OpenCart `config.php` and try again.

**The order stays in Pending forever.** Request the callback URL above from outside
your network. It must answer without HTTP auth and without a redirect — redirects are
not followed. Then check the OpenCart error log for `[Paymos]` lines.

**Checkout throws "Paymos is temporarily unavailable".** The invoice could not be
created. The log line names the reason; the usual causes are an environment that
never finished connecting, and an order currency the project does not invoice in.

**An order needs manual review.** Its total changed between the invoice being created
and the payment landing. The history note carries both figures — settle it by hand.

**Sending money back.** An on-chain payment that has reached finality cannot be
reversed, so a refund is a new outbound transaction: withdraw from your Paymos
balance to the address you agreed with the customer, then record it in OpenCart.

- [Documentation](https://paymos.io/docs/cms-opencart)
- [Source](https://github.com/paymos-labs/opencart)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
