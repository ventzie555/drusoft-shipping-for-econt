=== Drusoft Shipping for Econt ===
Contributors: ventzie
Tags: woocommerce, shipping, econt, bulgaria, delivery
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.2
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, conflict-free Econt integration for WooCommerce stores in Bulgaria.

== Description ==

**Drusoft Shipping for Econt** is a high-performance, conflict-free WooCommerce integration for Econt delivery services in Bulgaria. Real-time shipping rates, two-step waybill generation, courier pickup requests, and full order management — all through the official Econt API.

= Important Compatibility Note =

This plugin is currently **not compatible** with the WooCommerce Block Cart and Block Checkout pages. Please ensure your store uses the classic shortcode-based Cart (`[woocommerce_cart]`) and Checkout (`[woocommerce_checkout]`) pages.

= For Your Customers =

* **Dynamic Checkout Experience** — Real-time city, office, and automat (APS) selection directly on the checkout page.
* **Multiple Delivery Types** — Choose between delivery to Address, Econt Office, or Econt Automat (APS).
* **Smart Street Search** — Built-in autocomplete for Bulgarian street names with intelligent prefix handling (e.g. "ул.", "бул.").
* **Region-Based City Filtering** — Cities are filtered by the Bulgarian province selected at checkout.

= For Merchants =

* **HPOS Compatible** — Fully supports WooCommerce High-Performance Order Storage.
* **Automated Data Sync** — Uses Action Scheduler to keep Bulgarian cities and Econt offices/automats up to date in the background.
* **Waybill Management** — Generate, print (PDF), and cancel waybills from the order edit screen.
* **Courier Pickup Requests** — Request a courier pickup directly from WP admin, with automatic skip of weekends and Bulgarian bank holidays.
* **Dedicated Econt Orders Page** — Filtered admin list of every Econt order with bulk Generate / Print / Cancel actions.
* **Bulgarian (bg_BG) Translation Included.**

= Also Ship via Speedy? =

This plugin has a sibling for the **Speedy** courier: [Drusoft Shipping for Speedy](https://wordpress.org/plugins/drusoft-shipping-for-speedy/). Both plugins share the same checkout UI, settings layout, and admin order-management screens — once you've learned one, the other feels familiar. Install both if your store supports delivery via either courier.

== Installation ==

1. Upload the `drusoft-shipping-for-econt` folder to the `/wp-content/plugins/` directory.
2. Ensure **WooCommerce** is installed and active.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Navigate to **WooCommerce > Settings > Shipping > Shipping Zones**.
5. Add or edit a shipping zone (e.g. "Bulgaria").
6. Click **Add shipping method** and select **Drusoft Shipping for Econt**.
7. Enter your **Econt Store ID** and **Private Key** (issued by Econt under **My Econt > Integration > API**), then click **Save Changes**.
8. Background sync for cities and offices starts automatically via the WooCommerce Action Scheduler.

== Frequently Asked Questions ==

= What Econt API credentials do I need? =

You need a **Store ID** and a **Private Key**, both issued by Econt under **My Econt > Integration > API**. Contact Econt Bulgaria if you do not yet have API access.

= Does this plugin support the WooCommerce Block Checkout? =

Not yet. The plugin currently requires the classic shortcode-based Checkout page (`[woocommerce_checkout]`). Block Checkout support is planned for a future release.

= How are cities and offices kept up to date? =

The plugin uses the WooCommerce Action Scheduler to sync cities and offices from Econt's Nomenclatures API in the background. You can monitor the scheduled action (`drushfe_sync_locations_event`) under **WooCommerce > Status > Scheduled Actions**.

= How is shipping cost calculated? =

Shipping cost is fetched live from Econt's `OrdersService.getPrice` endpoint each time the customer changes the city, delivery type, office, or street on the cart/checkout page. The result is stored in the WooCommerce session and surfaced as the shipping rate.

= Can I automatically generate waybills? =

Yes. Enable the **Generate Waybill Automatically** option in the shipping method settings. A waybill (draft + AWB) will be created automatically when an order transitions to "Processing" or "On Hold".

= What happens if a courier request lands on a Bulgarian bank holiday? =

The plugin automatically retries with the next working day, skipping weekends and any holiday Econt reports. The skipped dates are recorded as an order note for traceability.

== Screenshots ==

1. Checkout page with Econt delivery type selection (Address, Office, Automat).
2. Shipping method settings in the WooCommerce shipping zone modal.
3. Econt Shipment metabox on the order edit screen.
4. Econt Orders admin page with bulk actions.

== External Services ==

This plugin relies on the **Econt API**, a third-party service provided by **Econt AD** (Econt Bulgaria), to deliver its shipping functionality. The plugin cannot operate without a valid Econt API account.

= What the service is =

Econt AD is a courier and logistics company operating in Bulgaria. Their JSON API allows merchants to calculate shipping rates, create shipments (waybills), manage deliveries, request courier pickups, and retrieve location data (cities, offices, automats, streets).

= What data is sent and when =

Authentication: every request is sent over HTTPS with an `Authorization: <private_key>` header. The Store ID is only stored locally to identify the merchant in the WooCommerce settings and is not transmitted on every request.

* **Recipient address data** (city, street, num, office/automat code, postcode, phone, email) — sent when calculating shipping rates on the cart/checkout page and when creating a waybill after order placement.
* **Sender address data** (name, phone, email, city, street, num) — sent when creating a waybill and when requesting a courier pickup.
* **Shipment details** (weight, COD flag, currency, package count, item descriptions) — sent when creating a waybill.
* **Waybill identifiers** (internal id, shipment number) — sent when cancelling a shipment, requesting a courier pickup, or generating an Air Waybill (AWB).
* **Location queries** (city ID, street name) — sent when the customer searches for cities, offices, automats, or streets, and during the daily background sync of the full Bulgarian nomenclature.

Data is transmitted only when the corresponding action is triggered (a customer changes their selection on checkout, a merchant generates a waybill, the background sync runs, etc.).

= Service links =

* Econt API documentation (Shipments / Nomenclatures): [https://ee.econt.com/services/](https://ee.econt.com/services/)
* Econt Terms and Conditions: [https://www.econt.com/en/general-conditions](https://www.econt.com/en/general-conditions)
* Econt Privacy Policy: [https://www.econt.com/en/privacy-policy](https://www.econt.com/en/privacy-policy)

== Econt API Endpoints ==

This plugin communicates with two Econt API hosts:

* **`delivery.econt.com`** — Orders, payments, grouping (demo: `delivery-demo.econt.com`)
* **`ee.econt.com`** — Nomenclatures and Shipments (demo: `demo.econt.com/ee/`)

All requests are authenticated with an `Authorization: <private_key>` header.

= Location Data (Nomenclatures) =

* **`POST /services/Nomenclatures/getCities.json`** — Full list of Bulgarian cities. Used by the background syncer to populate the local `wp_drushfe_cities` table.
* **`POST /services/Nomenclatures/getOffices.json`** — All Econt offices and automats (APS). Used by the background syncer to populate the local `wp_drushfe_offices` table.
* **`POST /services/Nomenclatures/getStreets.json`** — Streets within a specific city. Used by the checkout-page street autocomplete and cached per city in a 24h transient.

= Pricing =

* **`POST /services/OrdersService.getPrice.json`** — Calculates the shipping price for a recipient address, delivery type, and weight. Used live at cart/checkout each time the customer changes their selection.

= Shipment Management =

* **`POST /services/OrdersService.updateOrder.json`** — Creates a waybill draft. Used by the waybill generator on order status change or via the manual "Generate Waybill" action.
* **`POST /services/OrdersService.createAWB.json`** — Promotes a draft to a committed Air Waybill, returning the shipment number and PDF URL.
* **`POST /services/OrdersService.deleteLabel.json`** — Cancels a single waybill draft by internal id. Used by the per-order "Cancel Shipment" action.
* **`POST /services/Shipments/LabelService.deleteLabels.json`** — Bulk-cancels committed AWBs by shipment number. Used by the Econt Orders bulk "Cancel" action.
* **`POST /services/Shipments/ShipmentService.requestCourier.json`** — Requests a courier pickup for one or more shipments. Used from the per-order "Request Courier" action; auto-retries on national holiday rejections.

= Rate Limiting & Caching =

The plugin minimizes API calls through several strategies:

* **Local database tables** — Cities and offices are synced once per day via Action Scheduler and queried locally.
* **Transient caching** — Per-city street lists are cached for 24 hours.
* **Session storage** — Cart selections and the most recent shipping quote are stored in the WooCommerce session, so unchanged selections do not re-quote.

== Changelog ==

= 1.0.2 =
* Improved: the selected Econt office (name and address) is now shown directly on the WooCommerce order screen, in the shipping-address column. Previously only the internal office ID was stored, so merchants had to generate a waybill to see which office the customer chose. Resolved at display time, so it also applies to orders placed before this version.

= 1.0.1 =
* Fixed: when both Drusoft Shipping for Econt and Drusoft Shipping for Speedy are active, switching couriers during checkout could leave the newly selected courier's picker uninitialised. Both plugins tagged their city dropdown with the same generic Select2 class; each now marks its own.
* Fixed: each courier keeps its own delivery selection (province, city, office, delivery type, postcode). A value entered under one courier no longer carries over to the other through the shared address fields.
* Fixed: corrected the Bulgarian translation of the courier name ("Иконт" → "Еконт") throughout the plugin.
* i18n: the "Delivery Method" field label is now translatable.

= 1.0.0 =
* Initial release.
* WooCommerce shipping method for Econt (`drushfe_econt`) with per-zone instance settings.
* Live shipping cost via `OrdersService.getPrice` with `Authorization: <private_key>` header.
* Three delivery modes: to Address, to Econt Office, or to Econt Automat (APS).
* Native checkout UI with country → state → city Select2 → delivery-type radio → office Select2 or street autocomplete.
* Daily background sync of Bulgarian cities and Econt offices/automats via Action Scheduler.
* Two-step waybill flow: `OrdersService.updateOrder` (draft) → `OrdersService.createAWB` (commit), returning shipment number and signed PDF URL.
* Admin order actions: Print Waybill (signed PDF), Cancel Shipment, Request Courier.
* Courier requests auto-retry across weekends and Bulgarian bank holidays — no manual rescheduling needed.
* Dedicated Econt Orders admin page with bulk Generate / Print / Cancel.
* HPOS (High-Performance Order Storage) compatible.
* Bulgarian (bg_BG) translation included.

== Upgrade Notice ==

= 1.0.1 =
Recommended update. Fixes courier-switch issues when used alongside Drusoft Shipping for Speedy, keeps each courier's checkout selection separate, and corrects the Bulgarian courier name.

= 1.0.0 =
Initial release.
