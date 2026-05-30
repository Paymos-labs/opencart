# Paymos for OpenCart 4 — accept USDT and USDC at checkout

OpenCart 4 payment extension for stablecoin payments. Customer pays in USDT or USDC. USDT settles on 11 chains (Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, TON, Avalanche, Solana, NEAR, Plasma). USDC settles on 10 chains (Ethereum, BSC, Polygon, Arbitrum, Optimism, Base, Avalanche, Solana, NEAR, Sui).

**Two-minute setup**: the `paymos.ocmod.zip` you download from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) ships with your API keys pre-injected, your webhook callback URL pre-built from your OpenCart Store URL, and your signing secret pre-registered. No copy-paste, no separate dashboard trip after install.

[![OpenCart 4.0+](https://img.shields.io/badge/OpenCart-4.0%2B-1e88e5)](https://www.opencart.com/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue)](LICENSE)

- Full documentation: [paymos.io/docs/cms-opencart](https://paymos.io/docs/cms-opencart)
- Product page: [paymos.io/product/plugins/opencart](https://paymos.io/product/plugins/opencart)
- Get the plugin ZIP: [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms)

---

## Why this is two minutes, not two hours

Other OpenCart crypto extensions ask you to:
- Create an API key in their dashboard
- Copy the API key + signing secret into OpenCart extension settings
- Calculate and paste the callback URL yourself
- Configure that URL on their side
- Test the handshake manually

The Paymos package generator does all of that **server-side at download time**:

- Sandbox + Live API credentials — baked into `paymos-config.php` inside the ZIP
- Webhook callback URL — pre-built from the OpenCart Store URL you typed in the dashboard
- Signing secret — pre-registered, never shown to you
- Both modes (Sandbox / Live) — pre-wired in one bundle, mode switch lives in OpenCart admin

You upload the `.ocmod.zip`, activate the extension, switch from Sandbox to Live when ready.

---

## Install — full walkthrough

### Step 1: Sign in to Paymos (≈30 sec)

1. Go to [paymos.io/login](https://paymos.io/login).
2. Email magic-link **or** Google — no password, no documents.
3. Onboarding wizard, 3 required steps: business name, country, integration pick.
4. Pick **CMS plugin → OpenCart**.
5. You land on [paymos.io/dashboard/quickstart](https://paymos.io/dashboard/quickstart).

### Step 2: Generate the package (≈20 sec)

1. Open [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms).
2. Select **OpenCart**.
3. Confirm the project that should receive OpenCart orders is selected in your dashboard workspace.
4. Enter your public OpenCart Store URL — for example `https://shop.example.com`.
5. Review the project's success and failure URLs (override if OpenCart uses different return pages).
6. Click **Download OpenCart package**.

The ZIP is built server-side at this moment. Paymos creates or reuses sandbox + live Payment API keys, registers a project-scoped invoice webhook endpoint, and derives your callback URL from the Store URL you typed:

```
https://shop.example.com/index.php?route=extension/paymos/payment/paymos.callback
```

Keep the filename `paymos.ocmod.zip` — OpenCart uses the `.ocmod.zip` suffix to install the extension under `extension/paymos`.

### Step 3: Install in OpenCart (≈40 sec)

1. OpenCart admin → **Extensions → Installer** → upload `paymos.ocmod.zip`.
2. **Extensions → Extensions** → select **Payments** from the dropdown.
3. Click **Install** next to **Paymos**.
4. Click **Edit** on the Paymos row.

The package writes read-only sandbox and live credentials to:

```
system/library/paymos/paymos-config.php
```

OpenCart only stores presentation settings (mode, labels, statuses, sort order). It never asks you for an API key.

### Step 4: Activate and test (≈30 sec)

1. In the Paymos settings, set **Status: Enabled**, **Mode: Sandbox** → **Save**.
2. Visit your storefront → add any product to cart → checkout.
3. Pick **Paymos** at payment selection.
4. On the hosted Paymos page, click **Simulate payment**.
5. Back in OpenCart admin → Orders → status should flip to your mapped paid status within ~5 seconds.

Working? Switch to **Mode: Live**. Done.

---

## Requirements

- OpenCart **4.0+**
- PHP **8.1+** (OpenCart 4.0.2.0+ requires PHP 8.1+)
- A public HTTPS OpenCart Store URL
- An active Paymos account with a project

No Composer install required on your store. The extension ships with the Paymos PHP SDK bundled.

---

## Runtime flow

1. Customer reaches OpenCart checkout, picks Paymos.
2. Extension creates a Paymos invoice via the Merchant API using the order total and currency.
3. Customer is redirected to the hosted Paymos page.
4. Customer pays in USDT or USDC on a supported chain.
5. Paymos confirms the on-chain payment using a tiered policy — small tickets clear in seconds, large tickets wait for more confirmations.
6. Paymos sends a signed callback to your OpenCart store.
7. Extension verifies signature + timestamp + amount, then reverse-verifies the terminal state against the Paymos API.
8. OpenCart moves the order to your mapped paid status.

If the callback is lost in transit, an admin reconciler can re-check recent unpaid Paymos invoices on demand.

Reference: [paymos.io/docs/payment-flow](https://paymos.io/docs/payment-flow).

---

## Configuration

The package pre-fills everything technical. OpenCart admin only exposes presentation choices:

| Setting | What it controls |
|---|---|
| Status | Extension on/off. |
| Mode | `Sandbox` for tests, `Live` for production. Switch without re-uploading. |
| Title | Customer-facing label at checkout. |
| Button Text | Label on the payment button. |
| Pending Status | Order status set when a Paymos invoice is created. |
| Confirming Status | Order status while payment is confirming. |
| Paid Status | Final paid order status. |
| Failed / Cancelled Status | Statuses for failed terminal outcomes. |
| Debug Logging | Sanitized OpenCart logs (off by default). |
| Sort Order | Position among other payment methods at checkout. |

Generated values loaded from `system/library/paymos/paymos-config.php`, not editable in OpenCart:

| Generated value | Description |
|---|---|
| API Key | Sandbox and live Payment keys |
| API Secret | HMAC signing secrets |
| Project ID | Paymos project used for OpenCart orders |
| Webhook Secret | Sandbox and live callback verification secrets |
| Base URL | Defaults to `https://api.paymos.io` |

To rotate any of these — re-download a fresh package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) and reinstall. The previous secret stays valid through a grace window so in-flight callbacks don't fail.

API keys reference: [paymos.io/docs/api-keys](https://paymos.io/docs/api-keys).

---

## Webhooks (callback) — pre-registered, no setup

The dashboard registers your callback URL against your OpenCart Store URL **before the ZIP is generated**. The callback path is fixed:

```
https://shop.example.com/index.php?route=extension/paymos/payment/paymos.callback
```

You will not need to set this up yourself. The signing secret lives in `paymos-config.php`.

Manage and replay events at [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

Extension verifies every incoming callback:

- **Signature** — header `X-Webhook-Signature`, format `t={timestamp},v1={hex}`, algorithm HMAC-SHA256, timing-safe compare, ±5 min timestamp tolerance (parsed from the `t=` component, rejects replays).
- **Event ID deduplication** — same `event_id` cannot mark the same order paid twice.
- **Reverse verification** — pulls the live invoice from the Paymos API and confirms terminal state before moving the OpenCart order to paid status.

Any check fails → HTTP 4xx response → order is **not** updated.

Retry policy on the Paymos side: **11 attempts** with exponential backoff over ~32 hours (1m, 2m, 4m, 8m, 16m, 32m, 1h, 2h, 4h, 8h, 16h). Failed callbacks land in the dashboard for manual replay.

Signature verification deep-dive: [paymos.io/docs/webhooks/verify](https://paymos.io/docs/webhooks/verify).
Retry schedule: [paymos.io/docs/webhooks/retry](https://paymos.io/docs/webhooks/retry).

---

## Reconciliation

If a callback was missed in transit, the extension admin exposes a reconcile action — it re-checks recent unpaid Paymos invoices on demand and updates the matching OpenCart orders.

You can also replay any event from [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

---

## Sandbox testing

Sandbox is fully wired the moment you install the extension. No whitelist, no extra approval.

1. Confirm **Mode: Sandbox** in the extension settings.
2. Place a test OpenCart order.
3. On the hosted Paymos page, hit **Simulate payment**.
4. OpenCart order should flip to your mapped paid status within ~5 seconds.

Same API surface as Live. Same callback schema. Sandbox uses testnet credentials shipped in the same ZIP, Live uses mainnet.

Sandbox guide: [paymos.io/docs/testing](https://paymos.io/docs/testing).

---

## Behavior matrix

| Paymos invoice state | OpenCart result |
|---|---|
| `invoice.paid` | Order moved to Paid status |
| `invoice.paid_over` | Order moved to Paid status (overpayment recorded) |
| `invoice.confirming` | Order moved to Confirming status |
| `invoice.underpaid_waiting` | Logged, order unchanged |
| `invoice.underpaid` | Order moved to Failed status |
| `invoice.expired` | Order moved to Cancelled status |
| `invoice.cancelled` | Order moved to Cancelled status |

Invoice lifecycle reference: [paymos.io/docs/payment-flow](https://paymos.io/docs/payment-flow).

---

## FAQ

**Why does the package have everything pre-configured?**
Because the dashboard generates it that way. At download time, the server reads your merchant record, creates Sandbox and Live credentials if missing, derives the callback URL from the OpenCart Store URL you typed, registers it on the Paymos side, and writes everything into `paymos-config.php` before zipping. You get a turn-key bundle.

**Do I ever need to paste an API key into OpenCart?**
No. OpenCart extension settings never ask for one — they only expose presentation choices (status, mode, title, statuses, sort order).

**What if I change my OpenCart Store URL?**
Re-download the package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms) after updating the URL in the dashboard. The new ZIP carries the updated callback URL and an updated `paymos-config.php`. Re-upload.

**Does this work on OpenCart 3.x?**
No. Minimum is OpenCart 4.0 — the extension relies on the OCMod 4 packaging format and the modern routing engine.

**What happens if a customer pays late?**
Paymos rejects the on-chain transaction at the domain boundary. The order stays unpaid, the customer can retry.

**Are there chargebacks?**
No. Crypto settlement is final on confirmation.

**What if the callback never arrives?**
An admin reconciler re-checks recent unpaid Paymos invoices on demand. You can also replay any event from [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks).

**Which network is cheapest for the customer?**
The customer picks the network on the hosted Paymos page — they see live gas before paying.

---

## Troubleshooting

| Symptom | What to check |
|---|---|
| `Paymos` not appearing under Extensions → Payments | OpenCart version below 4.0, or `paymos.ocmod.zip` renamed before upload. Re-download with the original filename. |
| Extension settings missing credentials | `paymos-config.php` not present or unreadable. Re-download the package from [paymos.io/dashboard/cms](https://paymos.io/dashboard/cms). |
| Order not flipping after sandbox simulate | Signature verification failed. Check OpenCart logs (`Extensions → Logs`) for `paymos` entries. |
| `Signature verification failed` in logs | Package and dashboard configs out of sync. Re-download the package and re-upload. |
| Callback returns 4xx | Webhook secret rotated on dashboard side but old package still installed. Re-download. |
| Live mode shows sandbox banner | Mode switch in OpenCart admin still on Sandbox. |

Error reference: [paymos.io/docs/errors](https://paymos.io/docs/errors).

---

## Support

- Documentation: [paymos.io/docs/cms-opencart](https://paymos.io/docs/cms-opencart)
- Dashboard: [paymos.io/dashboard](https://paymos.io/dashboard)
- Status: [paymos.io/status](https://paymos.io/status)
- Issues: [github.com/paymos-labs/opencart/issues](https://github.com/paymos-labs/opencart/issues)
- Email: [support@paymos.io](mailto:support@paymos.io)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — or browse the public release history at [paymos.io/changelog](https://paymos.io/changelog).

---

## License

MIT — see [LICENSE](LICENSE).
