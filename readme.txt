=== ifthenpay | Payments for LatePoint ===
Contributors: ifthenpay
Tags: ifthenpay, latepoint, payments, booking, invoices
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds ifthenpay payment methods to LatePoint: cards, wallets, local bank transfers; supports orders and invoices for appointment bookings.

== Description ==

This plugin integrates the ifthenpay payment gateway with LatePoint to enable seamless payment processing for appointment bookings. It supports multiple payment methods, including local options like Multibanco and MB WAY, as well as international ones like PIX. Payments are handled via secure pay-by-link, ensuring no sensitive card data is stored on your site. When a customer books an appointment, they can select their preferred payment method, and a secure payment page opens for completion.

In plain terms you get:
* One-time payments for bookings
* Support for invoices and orders
* Merchant backoffice (basic sales) on web + mobile
* Secure automatic payment confirmations (no card numbers stored)

All settings are made in LatePoint. The plugin is built so store owners can manage payments without needing deep technical knowledge.

== Key Features ==

1. Easy integration with LatePoint booking flow
2. Invoice payments (Orders & Invoices support)
3. Secure pay-by-link transactions
4. Automatic payment confirmation (fast access)
5. Multiple local payment types (cards, wallets, transfers, vouchers)
6. Merchant backoffice (basic sales & refund reports)
7. Security first (signed callbacks, no card data stored)

== Requirements ==
* An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
* A Static Gateway Key provisioned for the LatePoint context (request this from ifthenpay support/helpdesk).
* The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
* WordPress 6.5+ and PHP 7.4+, and LatePoint installed and activated.
* HTTPS (SSL) enabled on your site.

== Installation ==
1. In your WordPress admin, go to **Plugins → Add New** and search for **ifthenpay | Payments for LatePoint**, then click **Install Now**.  
2. Or download `ifthenpay-payments-for-latepoint.zip` and upload under **Plugins → Add New → Upload Plugin**.  
3. Activate **LatePoint** and **ifthenpay for LatePoint**.  
4. Navigate to **LatePoint → Settings → Payments**, enter your ifthenpay Backoffice Key & Gateway Key, and click **Connect**.

== Frequently Asked Questions ==

= What do I need to get started? =
* A valid ifthenpay account (register at [ifthenpay.com/aderir](https://ifthenpay.com/aderir/))
* LatePoint plugin active
* WordPress 6.5+ and PHP 7.4+

= How do I get my Backoffice Key? =
Your Backoffice Key is issued by ifthenpay once you sign up and your contract is validated at [ifthenpay.com/aderir](https://ifthenpay.com/aderir/) — it's the same key used by ifthenpay's other website and app integrations. If you've already signed up but don't have it to hand, [ifthenpay support](https://helpdesk.ifthenpay.com) can resend it.

= How do I get a Gateway Key for LatePoint? =
A Gateway Key must be provisioned specifically for the **LatePoint** context before this plugin can use it — a Gateway Key created for a different platform (e.g. a generic website or another CMS) will not appear here, even with a valid Backoffice Key. Ask [ifthenpay support](https://helpdesk.ifthenpay.com) to provision a Gateway Key for LatePoint on your account, then pick which payment methods (Multibanco, MB WAY, Payshop, cards, …) should be enabled on it — they'll guide you through activation for each. Once that's done, the plugin's settings page picks it up automatically; there is nothing to enter by hand beyond the Backoffice Key itself.

= How do I configure it? =
1. Go to **LatePoint → Settings → Payments**.
2. Enter your Backoffice Key and click **Connect** — this checks it against ifthenpay and shows your available Gateway Key(s).
3. Pick a Gateway Key, select the payment methods (including invoices) you want to offer, then save.
4. Save again any time you change the Gateway Key — this also re-registers the payment notification (callback) URL ifthenpay uses to confirm a payment automatically; see below.

= Does ifthenpay need access back into my site? =
Yes — after a Gateway Key is saved, the plugin registers a payment notification URL with ifthenpay (`https://yoursite.com/wp-json/ifthenpay-lp/v1/callback`) so a payment can be confirmed automatically once it's completed. This happens automatically; there's nothing to copy or paste. If your server sits behind a firewall, WAF, or security plugin that blocks unfamiliar inbound requests, allow GET requests to that URL path through — otherwise a completed payment may not be confirmed automatically. If registration itself fails (for example, your site isn't reachable from the internet yet), the settings page shows which Gateway Key failed and why, without needing to re-enter anything.

= How does the payment process work? =  
Payments are processed securely through ifthenpay's pay-by-link system. Customers select a payment method during booking, and a secure payment page opens for completion. Once paid, the status is verified and the booking is confirmed automatically.

= Are payment details stored? =  
No. The plugin does not store card numbers or full bank details. Only small references needed for matching payments are kept.

= Is there a sandbox? =  
ifthenpay may provide test entities; if unavailable, use a low-value live test.

= Which payment methods are supported? =  
Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Pix, Credit Card if provisioned).

= How secure is the integration? =  
Requests are encrypted over HTTPS; data is minimized; no card details are stored. Payments are handled off-site by ifthenpay, ensuring PCI compliance.

= Why are payment links failing or setup timing out? =  
Your server firewall or VPN may be blocking outbound requests. The plugin must connect to ifthenpay APIs to function. Ensure your network administrator allows outbound HTTPS traffic to ifthenpay domains.

== External Services ==

This plugin integrates with the ifthenpay payment platform to process payments for LatePoint bookings. ifthenpay is a third-party service that provides secure payment processing for various methods including cards, wallets, and local bank transfers.

- **LatePoint** (appointment-booking plugin): we extend its framework classes (`OsFormHelper`, `OsSettingsHelper`, etc.).  
- **ifthenpay Backoffice & Integrations**  
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard for managing payment integrations. The plugin uses the ifthenpay API to retrieve account configuration, generate payment links, and process payments.  
  - **What data is sent and when**:  
    - During setup: Backoffice Key and Gateway Key (stored securely in site settings) to authenticate and retrieve available payment methods.  
    - During payment processing: Minimal transaction details including transaction ID, amount, and booking details to generate payment references.  
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)  
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)
- **Network & VPN Requirements**: Outbound HTTPS requests are made to ifthenpay APIs for setup, link generation, and status validation. Servers behind strict firewalls or restrictive outbound VPNs must allowlist the following domains to prevent connection timeouts:  
  - api.ifthenpay.com (https://api.ifthenpay.com)  
  - ifthenpay.com (https://ifthenpay.com)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored in site options and are not publicly exposed. The plugin does not store raw card numbers or full bank account details.

== Screenshots ==
1. (Admin Only) Backoffice Synchronization under LatePoint Payments Settings
2. (Admin Only) Gateway Settings under LatePoint Payments Settings
3. (Customers Experience) Payment Gateway selection while booking.  
4. (Customers Experience) ifthenpay secure payment (for invoices or orders) screen.  
5. (Customers Experience) Booking confirmation with payment status.

== Changelog ==

= 3.0.0 =
* Changed: Backoffice Key and Gateway Key configuration reworked — the Backoffice Key is now validated against ifthenpay directly when you save it, and the Gateway Key list and available payment methods are read live instead of cached from a separate "Connect" step.
* Changed: The callback URL ifthenpay uses to confirm payments is now registered automatically whenever you save a Gateway Key, with the result shown on the settings page if it fails.
* Changed: ifthenpay payment methods are no longer offered at checkout unless the saved Gateway Key is currently valid, even if the processor itself is turned on.
* Fixed: Payment records now use one shared table instead of a single-purpose one; existing history is kept, not deleted, during the update.

= 2.1.1 =
* Changed: Payment charge reference is now the payment token instead of the ifthenpay transaction ID, so merchants can reconcile payments more easily.
* Fixed: Increased the payment status verification timeout from 10s to 45s to give slower payment methods enough time to confirm before giving up.
* Fixed: Applied WPCS (PHPCBF), ESLint, and Prettier formatting baseline across the plugin (tabs, single quotes, WPCS spacing).

= 2.1.0 =
* Fixed: Updated public access whitelist in controller to use correct method names (get_order_ifthenpay_options, get_transaction_ifthenpay_options) instead of deprecated get_ifthenpay_options.  
* Fixed: Removed non-existent get_payment_options from customer access whitelist.

= 2.0.5 =  
* Updated: Bumped "Tested up to" to WordPress 6.9 in all relevant files.  
* Added: Filter out of invisible methods.

= 2.0.3 =  
* Fixed: Generic class naming and inefficiencies.  
* Fixed: Added the prefix 'Ifthenpay' to 'AdminFormRenderer' static class.  
* Fixed: Cleaned up settings clearing logic and improved error status handling in the controller.  
* Fixed: Removed unused ifthenpay_nonce from localized variables and JavaScript AJAX calls.

= 2.0.1 =  
* Fixed: Versioning fixes and remove unnecessary 'load_plugin_text_domain'.

= 2.0.0 =  
* Added: Support for invoice payments (Orders & Invoices).  
* Changed: Clarified ifthenpay account requirement and subscription link.

= 1.0.0 =  
* Initial stable release.

== Upgrade Notice ==

= 3.0.0 =
Reconfiguration required: after updating, open the ifthenpay settings in LatePoint and reconnect your Backoffice Key and Gateway Key. The previous Gateway Key is not carried over automatically, and no ifthenpay payment method will be offered at checkout until this is done.

= 2.1.1 =
Payments now reconcile by token for easier merchant bookkeeping; longer payment confirmation timeout; formatting cleanup.

= 2.1.0 =
Controller access whitelist fixes for proper payment endpoint routing.

= 2.0.5 =  
Bump tested up to 6.9; Filter out of invisible methods added.

= 2.0.3 =  
Generic class naming fixes and other improvements.

= 2.0.1 =  
Versioning fixes.

= 2.0.0 =  
Invoice payments now supported;

== License ==
This plugin is licensed under the GPLv3.

== Support ==

For assistance use the [WordPress.org support forum](https://wordpress.org/support/plugin/ifthenpay-payments-for-latepoint/):

Please include:
* Backoffice account
* Site URL + plugin version
* Exact error message + relevant log excerpts/screenshots

Pre-checks before posting:
* Payment method enabled on Gateway Key AND mapped to Integration
* Running current recommended versions of WordPress, PHP & LatePoint

Commercial helpdesk available (no direct email required): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)
* ifthenpay support: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)  
* LatePoint docs: [LatePoint docs](https://wpdocs.latepoint.com/)  
