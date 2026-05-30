# Changelog

All notable changes to the Paymos for OpenCart 4 extension are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [1.0.0] - 2026-05-30

### Added
- Initial release.
- USDT on 11 networks, USDC on 10 networks (native stablecoin settlement).
- OpenCart 4 OCMod packaging (`paymos.ocmod.zip`).
- HMAC-SHA256 webhook signature verification with secret-rotation grace period.
- Reverse verification on every callback before transitioning the order state.
- 10-minute background reconciler for unresolved invoices.
- Sandbox / Live mode switch in the OpenCart admin.
- API credentials and signing secret pre-injected by the dashboard ZIP generator.
