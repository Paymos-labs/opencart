# Changelog

All notable changes to the Paymos for OpenCart 4 extension are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [1.0.1] - 2026-06-22

### Fixed
- Roll-back guard: a stale/out-of-order webhook (a late confirming, or a cancelled/expired after the order was already paid) downgraded a paid order, because reverse-verify only covered terminal events. `wouldRollBackPaidOrder()` now re-asserts paid + adds an audit note and skips the downgrade.
- An amount mismatch is held for manual review (confirming status + note) and acknowledged (200) instead of being thrown into the infinite retry path.
- The on-chain transaction hash from `data.payment.transfers[]` was dropped; the latest confirmed transfer's tx hash + explorer link are now written into the order history.
- The snapshot status is persisted only after a successful order mutation, so snapshot and order can no longer diverge.

### Changed
- Dropped the dead `X-Paymos-Signature` fallback header (the server only sends `X-Webhook-Signature`), stopped emitting the phantom `invoice.updated` event type, and mapped the reorg awaiting_payment status to `invoice.awaiting_payment`.

### Removed
- README/`install.json` no longer claim an automatic "10-minute background reconciler" (reconcile is admin on-demand). Added a version badge to the README.

## [1.0.0] - 2026-05-30

### Added
- Initial release.
- USDT on 11 networks, USDC on 10 networks (native stablecoin settlement).
- OpenCart 4 OCMod packaging (`paymos.ocmod.zip`).
- HMAC-SHA256 webhook signature verification with secret-rotation grace period.
- Reverse verification on every callback before transitioning the order state.
- Roll-back guard so a stale out-of-order webhook never downgrades a paid order.
- On-chain transaction hash and explorer link recorded in the order history.
- Admin on-demand reconciler for unresolved invoices.
- Sandbox / Live mode switch in the OpenCart admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator.
