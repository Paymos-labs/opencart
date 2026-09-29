# Changelog

All notable changes to the Paymos for OpenCart 4 extension are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.2.18] - 2026-09-29

- chore: bundle Paymos PHP SDK v1.5.0

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

### Fixed
- The README named PHP 7.4 as the minimum and the store catalog PHP 8.1
  (BUG-181). OpenCart 4.0.2.0, the oldest release the extension installs on,
  stops below PHP 8.0 (`system/startup.php`), so both now say PHP 8.0.
- When the checkout could not replace an order's invoice because the old one
  may still be paid (BUG-166), the buyer read `error_checkout` — "Paymos is
  temporarily unavailable. Please choose another payment method or contact
  support." — an invitation to pay a second time (BUG-180). That case now
  reads the new `error_replacement_blocked`, the SDK's buyer message, in all
  six catalog languages. Every
  other checkout failure keeps `error_checkout`.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order gets a history entry in the confirming status
  saying it needs manual review. A 404 on the read of the live invoice no
  longer cuts a new one either.
- The extension refuses to install on OpenCart before 4.0.2.0 and says why in
  the store's error log. Those versions split routes on `|` and strip `.`
  (`system/engine/action.php`), ask payment extensions for `getMethod()` rather
  than `getMethods()`, and have no `setting/store` `createStoreInstance()`, so
  checkout, the webhook route and the admin reconcile could not work there. The
  install hook now removes the extension row OpenCart has just added instead of
  creating the Paymos tables.
- Invoices were cut in the store's base currency and labelled with the buyer's.
  OpenCart keeps `order.total` in the default currency and the buyer's currency
  beside it (`currency_code`, `currency_value`); the extension sent the raw
  total under the buyer's code, so a USD buyer of a EUR store paid 100.00 USD for
  a EUR 100 order. The invoice amount is now the order total converted at the
  order's own rate by OpenCart's currency library — the figure the storefront
  showed — at that currency's decimal places (`16050` for JPY, `33.100` for
  KWD). The callback's amount guard compares the same converted figure. An
  order in a currency the store no longer has is refused rather than invoiced
  in the base currency.
- The reconciler compared amounts as strings, so a snapshot of `2500.00` and
  the server's `2500` for a JPY order never matched and the fallback for a lost
  webhook skipped that order on every run. Amounts are now compared numerically.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- **Reconcile Paymos orders** moved no orders. It runs in the admin, and it
  loaded the storefront's `checkout/order` model, which the OpenCart 4 admin
  does not have, so every invoice failed with "Could not load model". In the
  admin the order is now read through `sale/order`, and its history goes
  through the storefront's `checkout/order` in a store instance for the
  order's store and language (`setting/store` `createStoreInstance`), the way
  OpenCart's own order editor does it. The webhook path is unchanged.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.2.17] - 2026-09-26

- chore: bundle Paymos PHP SDK v1.4.4

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

### Fixed
- The README named PHP 7.4 as the minimum and the store catalog PHP 8.1
  (BUG-181). OpenCart 4.0.2.0, the oldest release the extension installs on,
  stops below PHP 8.0 (`system/startup.php`), so both now say PHP 8.0.
- When the checkout could not replace an order's invoice because the old one
  may still be paid (BUG-166), the buyer read `error_checkout` — "Paymos is
  temporarily unavailable. Please choose another payment method or contact
  support." — an invitation to pay a second time (BUG-180). That case now
  reads the new `error_replacement_blocked`, the SDK's buyer message, in all
  six catalog languages. Every
  other checkout failure keeps `error_checkout`.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order gets a history entry in the confirming status
  saying it needs manual review. A 404 on the read of the live invoice no
  longer cuts a new one either.
- The extension refuses to install on OpenCart before 4.0.2.0 and says why in
  the store's error log. Those versions split routes on `|` and strip `.`
  (`system/engine/action.php`), ask payment extensions for `getMethod()` rather
  than `getMethods()`, and have no `setting/store` `createStoreInstance()`, so
  checkout, the webhook route and the admin reconcile could not work there. The
  install hook now removes the extension row OpenCart has just added instead of
  creating the Paymos tables.
- Invoices were cut in the store's base currency and labelled with the buyer's.
  OpenCart keeps `order.total` in the default currency and the buyer's currency
  beside it (`currency_code`, `currency_value`); the extension sent the raw
  total under the buyer's code, so a USD buyer of a EUR store paid 100.00 USD for
  a EUR 100 order. The invoice amount is now the order total converted at the
  order's own rate by OpenCart's currency library — the figure the storefront
  showed — at that currency's decimal places (`16050` for JPY, `33.100` for
  KWD). The callback's amount guard compares the same converted figure. An
  order in a currency the store no longer has is refused rather than invoiced
  in the base currency.
- The reconciler compared amounts as strings, so a snapshot of `2500.00` and
  the server's `2500` for a JPY order never matched and the fallback for a lost
  webhook skipped that order on every run. Amounts are now compared numerically.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- **Reconcile Paymos orders** moved no orders. It runs in the admin, and it
  loaded the storefront's `checkout/order` model, which the OpenCart 4 admin
  does not have, so every invoice failed with "Could not load model". In the
  admin the order is now read through `sale/order`, and its history goes
  through the storefront's `checkout/order` in a store instance for the
  order's store and language (`setting/store` `createStoreInstance`), the way
  OpenCart's own order editor does it. The webhook path is unchanged.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.2.16] - 2026-09-25

- docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
- fix(plugins): BUG-181 WooCommerce и CS-Cart называют настоящую причину и переводят текст покупателю; минимум PHP для OpenCart — 8.0
- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

### Fixed
- The README named PHP 7.4 as the minimum and the store catalog PHP 8.1
  (BUG-181). OpenCart 4.0.2.0, the oldest release the extension installs on,
  stops below PHP 8.0 (`system/startup.php`), so both now say PHP 8.0.
- When the checkout could not replace an order's invoice because the old one
  may still be paid (BUG-166), the buyer read `error_checkout` — "Paymos is
  temporarily unavailable. Please choose another payment method or contact
  support." — an invitation to pay a second time (BUG-180). That case now
  reads the new `error_replacement_blocked`, the SDK's buyer message, in all
  six catalog languages. Every
  other checkout failure keeps `error_checkout`.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order gets a history entry in the confirming status
  saying it needs manual review. A 404 on the read of the live invoice no
  longer cuts a new one either.
- The extension refuses to install on OpenCart before 4.0.2.0 and says why in
  the store's error log. Those versions split routes on `|` and strip `.`
  (`system/engine/action.php`), ask payment extensions for `getMethod()` rather
  than `getMethods()`, and have no `setting/store` `createStoreInstance()`, so
  checkout, the webhook route and the admin reconcile could not work there. The
  install hook now removes the extension row OpenCart has just added instead of
  creating the Paymos tables.
- Invoices were cut in the store's base currency and labelled with the buyer's.
  OpenCart keeps `order.total` in the default currency and the buyer's currency
  beside it (`currency_code`, `currency_value`); the extension sent the raw
  total under the buyer's code, so a USD buyer of a EUR store paid 100.00 USD for
  a EUR 100 order. The invoice amount is now the order total converted at the
  order's own rate by OpenCart's currency library — the figure the storefront
  showed — at that currency's decimal places (`16050` for JPY, `33.100` for
  KWD). The callback's amount guard compares the same converted figure. An
  order in a currency the store no longer has is refused rather than invoiced
  in the base currency.
- The reconciler compared amounts as strings, so a snapshot of `2500.00` and
  the server's `2500` for a JPY order never matched and the fallback for a lost
  webhook skipped that order on every run. Amounts are now compared numerically.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- **Reconcile Paymos orders** moved no orders. It runs in the admin, and it
  loaded the storefront's `checkout/order` model, which the OpenCart 4 admin
  does not have, so every invoice failed with "Could not load model". In the
  admin the order is now read through `sale/order`, and its history goes
  through the storefront's `checkout/order` in a store instance for the
  order's store and language (`setting/store` `createStoreInstance`), the way
  OpenCart's own order editor does it. The webhook path is unchanged.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.2.15] - 2026-09-25

- fix(magento): BUG-180 при заблокированной замене счёта покупателя просят связаться с магазином, а не платить другим способом
- docs(plugins): минимум OpenCart 4.0.2.0 во всех текстах; понятные строки SDK при заблокированной замене счёта
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- fix(opencart): BUG-161 расширение не ставится на OpenCart до 4.0.2.0 и пишет причину в лог
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

### Fixed
- When the checkout could not replace an order's invoice because the old one
  may still be paid (BUG-166), the buyer read `error_checkout` — "Paymos is
  temporarily unavailable. Please choose another payment method or contact
  support." — an invitation to pay a second time (BUG-180). That case now
  reads the new `error_replacement_blocked`, the SDK's buyer message, in all
  six catalog languages (English in the five others until translated). Every
  other checkout failure keeps `error_checkout`.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the order total, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the order gets a history entry in the confirming status
  saying it needs manual review. A 404 on the read of the live invoice no
  longer cuts a new one either.
- The extension refuses to install on OpenCart before 4.0.2.0 and says why in
  the store's error log. Those versions split routes on `|` and strip `.`
  (`system/engine/action.php`), ask payment extensions for `getMethod()` rather
  than `getMethods()`, and have no `setting/store` `createStoreInstance()`, so
  checkout, the webhook route and the admin reconcile could not work there. The
  install hook now removes the extension row OpenCart has just added instead of
  creating the Paymos tables.
- Invoices were cut in the store's base currency and labelled with the buyer's.
  OpenCart keeps `order.total` in the default currency and the buyer's currency
  beside it (`currency_code`, `currency_value`); the extension sent the raw
  total under the buyer's code, so a USD buyer of a EUR store paid 100.00 USD for
  a EUR 100 order. The invoice amount is now the order total converted at the
  order's own rate by OpenCart's currency library — the figure the storefront
  showed — at that currency's decimal places (`16050` for JPY, `33.100` for
  KWD). The callback's amount guard compares the same converted figure. An
  order in a currency the store no longer has is refused rather than invoiced
  in the base currency.
- The reconciler compared amounts as strings, so a snapshot of `2500.00` and
  the server's `2500` for a JPY order never matched and the fallback for a lost
  webhook skipped that order on every run. Amounts are now compared numerically.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- **Reconcile Paymos orders** moved no orders. It runs in the admin, and it
  loaded the storefront's `checkout/order` model, which the OpenCart 4 admin
  does not have, so every invoice failed with "Could not load model". In the
  admin the order is now read through `sale/order`, and its history goes
  through the storefront's `checkout/order` in a store instance for the
  order's store and language (`setting/store` `createStoreInstance`), the way
  OpenCart's own order editor does it. The webhook path is unchanged.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.2.14] - 2026-09-25

- fix(plugins): BUG-163/BUG-164 остальные плагины — замена счёта только по ответу сервера закреплена тестами, комментарии о сроке счёта исправлены
- fix(opencart): BUG-157 ручная сверка в админке OpenCart 4 берёт модели, которые у админки есть
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- fix(plugins): BUG-086 BUG-133 OpenCart выставляет счёт в валюте заказа и сверяет суммы численно
- chore: bundle Paymos PHP SDK v1.4.2

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

### Fixed
- Invoices were cut in the store's base currency and labelled with the buyer's.
  OpenCart keeps `order.total` in the default currency and the buyer's currency
  beside it (`currency_code`, `currency_value`); the extension sent the raw
  total under the buyer's code, so a USD buyer of a EUR store paid 100.00 USD for
  a EUR 100 order. The invoice amount is now the order total converted at the
  order's own rate by OpenCart's currency library — the figure the storefront
  showed — at that currency's decimal places (`16050` for JPY, `33.100` for
  KWD). The callback's amount guard compares the same converted figure. An
  order in a currency the store no longer has is refused rather than invoiced
  in the base currency.
- The reconciler compared amounts as strings, so a snapshot of `2500.00` and
  the server's `2500` for a JPY order never matched and the fallback for a lost
  webhook skipped that order on every run. Amounts are now compared numerically.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- **Reconcile Paymos orders** moved no orders. It runs in the admin, and it
  loaded the storefront's `checkout/order` model, which the OpenCart 4 admin
  does not have, so every invoice failed with "Could not load model". In the
  admin the order is now read through `sale/order`, and its history goes
  through the storefront's `checkout/order` in a store instance for the
  order's store and language (`setting/store` `createStoreInstance`), the way
  OpenCart's own order editor does it. The webhook path is unchanged.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

## [1.2.13] - 2026-09-23

- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.12] - 2026-09-21

- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.11] - 2026-09-15

- chore: bundle Paymos PHP SDK v1.4.1

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.10] - 2026-08-30

- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.9] - 2026-08-30

- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.8] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.7] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

### Changed
- The release asset is `paymos.ocmod.zip`. OpenCart derives the extension
  directory — and with it the PHP namespace of every controller — from the
  uploaded file name, so a versioned name installed into a directory nothing in
  the package could address.

## [1.2.6] - 2026-08-08

- fix(plugins): make the six shipped locales actually reach the merchant

## [1.2.5] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.2.4] - 2026-08-07

- fix(opencart): make the payment path work on OpenCart 4 at all
- chore: rebuild canonical CMS package

## [1.2.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.2.2] - 2026-08-07

- fix(opencart): persist the connect state on a freshly installed extension
- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.2.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.2.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.3.0

## [1.1.3] - 2026-08-03

- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.7] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.6] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.5] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.4] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

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
