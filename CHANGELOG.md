# Changelog

All notable changes to this project will be documented in this file.

The format loosely follows Keep a Changelog recommendations.

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
