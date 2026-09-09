# Changelog

All notable changes to this project will be documented in this file.

The format loosely follows Keep a Changelog recommendations.

## [3.0.0] - 2026-09-09

**Upgrade note:** reconfiguration required. After updating, reconnect your Backoffice Key and Gateway Key under `LatePoint → Settings → Payments` — the previous Gateway Key is not carried over automatically, and no ifthenpay payment method will be offered at checkout until this is done. If no Gateway Key shows up after reconnecting, it's because Gateway Keys are now fetched live and scoped specifically to the LatePoint context; a Gateway Key provisioned for a different platform won't appear here. Contact ifthenpay support/helpdesk to have a Static Gateway Key provisioned for LatePoint on your account.

### Added

- Multibanco as a deferred payment method: the customer gets an Entity/Reference/Amount at checkout to pay at an ATM or via homebanking, and the booking confirms automatically once ifthenpay reports it paid.
- Payshop as a deferred payment method: works the same way as Multibanco, but is paid in person at any Payshop agent or CTT post office.
- Per-method "Pay Later Configuration" settings (Reference Validity in days, minimum lead time before an appointment) for Multibanco and Payshop, each clamped so a reference is never issued longer than the appointment allows.
- An hourly expiry job that cancels bookings whose Multibanco/Payshop reference lapsed unpaid and releases the slot back for booking.
- A daily digest email to each agent listing their own appointments whose deferred reference lapsed unpaid.
- A new Tools page (`Settings → ifthenpay for LatePoint`): re-register the callback URL, recheck or cancel a stuck deferred payment (Outstanding Deferred Payments), and review or resolve realtime payments that settled without a matching booking (Unclaimed Realtime Payments) — all from wp-admin, no server access required. The existing `wp ifthenpay recheck-payment <token>` and `wp ifthenpay callback-register` WP-CLI commands are unchanged and still available.
- A "Payment Received" notification (`transaction_created`) is now seeded automatically under `LatePoint → Automation → Workflows` on activation, alongside LatePoint's own default "New Booking Notification" (`booking_created`). Sends to the customer only — unlike "New Booking Notification," which also emails the agent — so the customer gets a payment confirmation without the merchant building one by hand.

### Changed

- Backoffice Key and Gateway Key configuration reworked — the Backoffice Key is now validated against ifthenpay directly when you save it, and the Gateway Key list and available payment methods are read live instead of cached from a separate "Connect" step.
- The callback URL ifthenpay uses to confirm payments is now registered automatically whenever you save a Gateway Key, with the result shown on the settings page if it fails.
- ifthenpay payment methods are no longer offered at checkout unless the saved Gateway Key is currently valid, even if the processor itself is turned on.

### Fixed

- Payment records now use one shared table instead of a single-purpose one; existing history is kept, not deleted, during the update.

## [2.1.1] - 2026-08-31

### Changed

- Payment charge reference is now the payment `token` instead of the ifthenpay `transaction_id`, so merchants can reconcile payments more easily.

### Fixed

- Increased the payment status verification timeout from 10s to 45s to give slower payment methods enough time to confirm before giving up.
- Applied WPCS (PHPCBF), ESLint, and Prettier formatting baseline across the plugin (tabs, single quotes, WPCS spacing).

## [2.1.0] - 2026-07-07

### Fixed

- Updated public access whitelist in controller to use correct method names (`get_order_ifthenpay_options`, `get_transaction_ifthenpay_options`) instead of deprecated `get_ifthenpay_options`.
- Removed non-existent `get_payment_options` from customer access whitelist.

## [2.0.5] - 2026-05-07

### Updated

- Bumped "Tested up to" to WordPress 6.9 in all relevant files.

### Added

- Filter out of invisible methods.

## [2.0.3] - 2025-08-12

### Fixed

- Generic class naming and inefficiencies.
- Added the prefix 'Ifthenpay' to 'AdminFormRenderer' static class.
- Cleaned up settings clearing logic and improved error status handling in the controller.
- Removed unused ifthenpay_nonce from localized variables and JavaScript AJAX calls.

## [2.0.1] - 2025-07-29

### Fixed

- Versioning fixes and remove unnecessary 'load_plugin_text_domain'.

## [2.0.0] - 2025-07-28

### Added

- Support for invoice payments (Orders & Invoices).

### Changed

- Clarified ifthenpay account requirement and subscription link.

## [1.0.0] - 2025-05-27

- Initial stable release.

<!-- Future versions:
## [Unreleased]
### Added
### Changed
### Fixed
### Security
-->
