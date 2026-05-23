# Drusoft Shipping for Econt — WooCommerce Plugin

> **🚧 PORT STATUS — v0.1.0 SCAFFOLD**
>
> This plugin was scaffolded from `drusoft-shipping-for-speedy` and is **not yet functional end-to-end**. The feature list further below describes the *target* once the port is finished. Current state:
>
> **Done in the scaffold**
> - All identifiers renamed (`drushfo`→`drushfe`, `DRUSHFO`→`DRUSHFE`, text domain, plugin slug)
> - Shipping method id: `drushfe_econt`; class: `Drushfe_Shipping_Method`
> - Settings form reshaped to Econt's data model: added `Store ID`, kept `Private Key` + `Demo Mode`. Dropped Speedy-only fields (`uslugi`, `opakovka`, `obqvena`, `chuplivost`, `saturdayoption`, `special_requirements`, `fileceni`, `includeshippingprice`, `administrative`, `printer`, `additionalcopy`, `test_before_pay`, `testplatec`, `autoclose`) and the entire Returns & Vouchers section. The 4-option `moneytransfer` field is now a single `enable_cod` checkbox.
> - `includes/class-drushfe-syncer.php` — rewritten to call Econt's Nomenclatures API (`ee.econt.com` / `demo.econt.com/ee/`) for cities & offices
> - `includes/class-drushfe-waybill-generator.php` — rewritten to call Econt's `OrdersService.updateOrder.json` (`delivery.econt.com` / `delivery-demo.econt.com`)
> - `includes/class-drushfe-activator.php` — offices table `id` changed to `varchar(32)` because Econt office codes are alphanumeric
> - Removed dead helpers from the shipping method class: `validate_econt_credentials()`, `get_econt_services()`, `get_econt_special_requirements()`
>
> **Newly ported (this iteration)**
> - **Payment method change re-quotes shipping**. Econt's `getPrice` payload includes a `cod` flag, and Econt charges a COD-handling fee (~0.48 EUR observed). Added a delegated `change` listener on `input[name='payment_method']` in checkout.js that fires `recalculatePrice()` when Econt is active. Verified: COD 4.15 EUR ↔ Pay-with-card 3.67 EUR.
> - **Cart-page city stays select2'd when toggling Address↔Office**. WC was rebuilding the cart fragment a second time after `updated_cart_totals` already fired (without re-firing it), so our restoration ran once, succeeded, and was then overwritten by WC's late rebuild. Added `ensureCitySelect2()` that polls briefly (5 × 100ms) and re-applies select2 each time the field has been reverted to its native `<input>`. Idempotent — bails out as soon as the select2 sticks.
> - **Cart-page pricing works for all delivery modes**. Two bugs were fixed: (1) `handleEcontCart`/`restoreEcontUI` never fired the initial price calc, so a user landing on the cart with Econt + city pre-selected from session saw no shipping cost. Added `recalculatePriceIfReady()` to both lifecycle hooks. (2) Office/automat mode on cart was being marked as "incomplete" because the cart page has no office picker — fixed by adding a server-side `drushfe_get_joker_office_for_city()` helper that picks the first OFFICE (or first APS) for the city and substitutes it into the `getPrice` payload. Prices are uniform per office class within a city, so the preview is accurate.
> - **State-change now correctly refreshes the city list**. Switching from one region to another (e.g. Sofia → Haskovo) previously left the previous region's cities stale because the `change.econt` handler was being unbound on every `update_checkout` cycle but only rebound when WC fully rebuilt the DOM — most cycles it didn't, so subsequent state changes silently did nothing. Switched the handler from a body-level delegated bind to a direct bind on `#billing_state` / `#shipping_state`, which survives the WC churn entirely. The bind is also re-applied on every `updated_checkout` for safety (idempotent — off + on).
> - **Address-mode pricing now works without typing a street**. Econt's `getPrice` rejects a plain free-form `address` string (returns `ExInvalidAddress` because most well-known street names exist in multiple `quarters`). Since Econt's pricing is uniform per city, the server injects a "joker" street + `num=1` taken from the first entry of the city's `getStreets` nomenclature (cached for 24h alongside the autocomplete cache). The user's typed address is still saved to the order and used for the actual waybill — only the price-quote payload is swapped. New helpers: `drushfe_fetch_streets_for_city()`, `drushfe_get_joker_street_for_city()` in the main plugin file.
> - **Automats (APS) now appear in checkout** for the right cities. The initial seed of `wp_drushfe_offices` was stale (only 1 APS for the whole country). After re-running `Drushfe_Syncer::sync()` the table has 38 APS, 22 of which are in Sofia City — and the "To Automat" radio renders for those cities.
> - **Econt Orders admin page** (`includes/admin/class-drushfe-orders-list-table.php` + `class-drushfe-admin-menu.php`): tracking URL fixed (`econt.com/services/track-shipment/<code>`). Added bulk actions: `Generate Waybills`, `Print Waybills`, `Cancel Shipments`. Bulk Generate loops through orders via `Drushfe_Waybill_Generator::generate_waybill()`. Bulk Print collects each waybill's saved `pdfURL` and renders them as a click-list (no server-side zip). Bulk Cancel batches all selected `shipmentNumbers` into a single `LabelService.deleteLabels.json` call. Bulk results surface as a WP admin notice with success count + per-order error list.
> - **Admin order metabox actions** (`includes/admin/class-drushfe-actions.php`): Print Waybill now redirects to the saved `pdfURL` from `_drushfe_waybill_response` (no API call); Cancel Shipment now calls `Shipments/LabelService.deleteLabels.json` with the waybill `shipmentNumber`; Request Courier now calls `Shipments/ShipmentService.requestCourier.json` with sender info from the shipping-method settings + the configured `sender_time` as the pickup window. All three use `Authorization: <private_key>` header (no more Speedy-shaped `userName`/`password` body) and respect the `econt_test_mode` toggle for demo vs production URLs.
> - **v0.1 pricing (JS-driven calculator)**: each cart/checkout selection change fires `drushfe_calculate_price` which calls `OrdersService.getPrice.json` with `Authorization: <private_key>`; the resulting `receiverDueAmount` is stored in the WC session under `drushfe_shipping_cost`; `Drushfe_Shipping_Method::calculate_shipping()` is a thin ~30-line stub that reads that session value and emits one rate. `flow_version` is a `Date.now()` ms timestamp on the JS side, race-guarded server-side so stale responses can't overwrite newer ones across page reloads.
> - Settings form trimmed to calculator-only: dropped `cenadostavka` dropdown + 9 dependent fields (suma_nadbavka, free_shipping*, fixed_shipping*). Pricing section now contains just `enable_cod`.
> - New `drushfe_clear_price` AJAX handler to zero the session cost when selections become incomplete.
> - `Drushfe_Shipping_Method::calculate_shipping()` simplified — went from ~700 Speedy-shaped lines (cenadostavka modes, fileprices CSV, fiscal receipts) to a ~30-line read-from-session stub.
> - `assets/js/checkout.js` and `cart.js` — both wire a shared `recalculatePrice()` helper into every relevant change handler (city, delivery-type, office, street). Pre-existing service-selector and map-iframe blocks removed.
> - `drushfe_search_streets_ajax` — rewritten to call `Nomenclatures/getStreets.json` with `{cityID}`; full list cached per city in a 24h transient; client-side substring filter.
> - `drushfe_get_services_ajax` and `drushfe_select_service_ajax` — removed (Econt has no multi-tier service selector).
> - `drushfe_get_first_credentials()` — now returns the full settings array (incl. `econt_test_mode`, `teglo`), not Speedy-shaped `{username, password}`.
> - Region map in `drushfe_get_region_map()` — values realigned to Econt's `regionName` strings (e.g. `BG-22 => 'София'`, `BG-23 => 'София област'`). `drushfe_get_cities_ajax` now uses exact `=` lookup.
> - `assets/js/speedy-common.js` → renamed to `econt-common.js`.
>
> **Still Speedy-shaped (needs porting)**
> - The `'fileprices'` branch and `get_csv_file_price()` helper in `class-drushfe-shipping-method.php` are unreachable — safe to delete.
> - ~~`get_econt_clients()` still calls a Speedy-shaped `api.econt.bg/v1/client/contract` endpoint.~~ **Removed in 0.1.2** along with `do_econt_calculate_request`, `call_econt_calculate_api`, `econt_curl_post`, the `api.econt.bg/v1/location/site` city search, and the `api.econt.bg/v1/location/office` fallback inside `get_econt_offices`. The `sender_id` field is now a plain number input; city/office search now queries the local sync tables.
> - (admin tasks for this iteration are complete: order metabox + Econt Orders list table + bulk actions all ported)
> - Any downstream queries on `drushfe_offices.id` using `%d` need to change to `%s` (column is now varchar).
> - `build_fiscal_receipt_items()` and code branching on legacy `moneytransfer` values (`'fiscal'`, `'fiscalone'`, `'YES'`) is unreachable.
>
> **Recommended next steps (in order)**
> 1. Decide pricing strategy (server-side `calculate_shipping` rewrite vs. JS-driven `drushfe_calculate_price` + `woocommerce_package_rates` filter).
> 2. Rewire admin metabox / orders list table / bulk actions to Econt's `OrdersService.updateOrder.json` (already used by `class-drushfe-waybill-generator.php`).
> 3. Delete now-unreachable `'fileprices'`, `get_csv_file_price`, `build_fiscal_receipt_items` paths.
>
> **Local dev / verification**
> - Playwright harness in `scripts/e2e/`. Run with `cd scripts/e2e && npm test`. Specs of note:
>   - `tests/checkout-econt.spec.js` — drives state → city → delivery-type → office and street autocomplete end-to-end. Captures screenshots in `scripts/e2e/screenshots/checkout-econt-*.png`.
>   - `tests/econt-settings.spec.js` — captures the trimmed shipping-zone settings modal.
> - To repopulate cities/offices manually: `docker compose exec -T php wp eval 'require_once WP_PLUGIN_DIR . "/drusoft-shipping-for-econt/includes/class-drushfe-syncer.php"; Drushfe_Syncer::sync();' --path=/var/www/html/wordpress`. Expect ~5,500 cities and ~600 offices.

---

[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-96588a.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A high-performance, conflict-free **WooCommerce shipping plugin** for [Econt](https://www.econt.bg/) courier services in **Bulgaria**. Provides real-time shipping rates, waybill generation, office/automat selection, and full order management — all through the official Econt REST API.

> **⚠️ Compatibility Note:** This plugin requires the classic shortcode-based Cart and Checkout pages (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout is not yet supported.

---

## ✨ Features

### For Customers
- 🏙️ **Dynamic city & office selection** — Real-time search on checkout
- 📦 **Multiple delivery types** — Address, Econt Office, or Econt Automat (APS)
- 🔍 **Smart street autocomplete** — Bulgarian street names with prefix handling (ул., бул.)
- 💰 **Live service & price selection** — Economy, Express, etc. with real-time rates
- 🗺️ **Region-based filtering** — Cities filtered by Bulgarian province

### For Merchants
- ⚡ **HPOS compatible** — High-Performance Order Storage support
- 🔄 **Background data sync** — Cities & offices updated via Action Scheduler
- 🧾 **Waybill management** — Generate, print (PDF), cancel waybills from the order screen
- 🚚 **Courier pickup requests** — Request pickup directly from WP admin
- 📊 **Econt Orders page** — Dedicated admin page for all Econt shipments
- 💳 **Flexible pricing** — Calculator, fixed price, free shipping, CSV custom prices, or calculator + surcharge
- 🎁 **Free shipping thresholds** — Per delivery type (address/office/automat)
- ✅ **Credential validation** — Real-time API key verification on save
- 🇧🇬 **Bulgarian translation included**

---

## 📦 Installation

1. Download the [latest release](https://github.com/ventzie555/drusoft-shipping-for-econt/releases) or clone this repo
2. Upload the `drusoft-shipping-for-econt` folder to `/wp-content/plugins/`
3. Ensure **WooCommerce** is installed and active.
4. Activate the plugin in **Plugins → Installed Plugins**
5. Go to **WooCommerce → Settings → Shipping → Shipping Zones**
6. Add/edit a zone (e.g. "Bulgaria") → **Add shipping method** → select **Drusoft Shipping for Econt**
7. Enter your **Econt API credentials** and click **Save Changes**
8. Background sync of cities and offices starts automatically

### Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Econt API credentials (contact [Econt Bulgaria](https://www.econt.bg/))

---

## ⚙️ Configuration

### API Connection
| Setting | Description |
|---------|-------------|
| Username & Password | Your Econt REST API credentials |
| Module Status | Enable/disable the shipping method |
| Method Title | Display name at checkout |

### Sender Information
- Sender profile (Econt client object)
- Contact details for waybills (name, email, phone)
- Ship-from-office option
- Working day end time for pickup scheduling

### Shipment Settings
- Active services (Economy, Express, etc.)
- Default packaging type and weight
- Declared value, fragile, Saturday delivery options

### Pricing Methods

| Method | Description |
|--------|-------------|
| **Econt Calculator** | Real-time API rates based on weight, destination, service |
| **Fixed Price** | Flat rate per delivery type (address/office/automat) |
| **Free Shipping** | Always free, or above a configurable threshold |
| **Custom CSV** | Upload a CSV for complex weight/total-based rules |
| **Calculator + Surcharge** | API price + fixed fee |

### Payment & Fiscal
- Cash on Delivery (COD) and Postal Money Transfer
- Fiscal receipt handling for Econt deliveries

---

## 🛠️ Technical Details

### Database
Creates two custom tables for cached location data:
- `{prefix}_drushfe_cities` — ~5,300 Bulgarian cities/villages
- `{prefix}_drushfe_offices` — ~1,200 Econt offices and automats

### Background Sync
The `drushfe_sync_locations_event` action runs via WooCommerce Action Scheduler. Monitor it at **WooCommerce → Status → Scheduled Actions**.

### API Integration
All communication with `https://api.econt.bg/v1/` — endpoints used:
- `/location/site` — City search
- `/location/office` — Office lookup
- `/location/street` — Street autocomplete
- `/calculate` — Shipping rate calculation
- `/shipment` — Waybill creation
- `/print` — PDF label generation
- `/pickup` — Courier pickup requests
- `/client/contract` — Credential validation

### Security
- All AJAX handlers are nonce-protected (separate public/admin/actions scopes)
- All database queries use `$wpdb->prepare()`
- File uploads use `wp_handle_upload()`
- API calls use `wp_remote_post()` (no raw cURL)

---

## 📄 License

This plugin is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

Developed and maintained by **[DRUSOFT LTD](https://drusoft.dev/)**.

---

## 🤝 Contributing

Contributions are welcome! Please open an issue or submit a pull request.

For support or custom feature requests, contact [DRUSOFT LTD](https://drusoft.dev/).

---

---

# Drusoft Shipping for Econt — WooCommerce плъгин 🇧🇬

Високопроизводителна и безконфликтна интеграция на **Иконт** куриерски услуги за **WooCommerce** магазини в България. Осигурява изчисляване на цени в реално време, генериране на товарителници, избор на офис/автомат и пълно управление на поръчки — през официалния Econt REST API.

> **⚠️ Забележка:** Плъгинът изисква класическите страници за Количка и Плащане (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout все още не се поддържа.

## ✨ Основни функции

### За клиенти
- 🏙️ **Динамичен избор на град и офис** в реално време при поръчка
- 📦 **Няколко вида доставка** — до Адрес, Офис на Иконт или Автомат (APS)
- 🔍 **Интелигентно търсене на улици** с автоматично довършване
- 💰 **Избор на услуга с актуализация на цената** в реално време
- 🗺️ **Филтриране по област** — градовете се зареждат по избраната област

### За търговци
- ⚡ **HPOS съвместим** — поддръжка на High-Performance Order Storage
- 🔄 **Фоново синхронизиране** на градове и офиси чрез Action Scheduler
- 🧾 **Управление на товарителници** — генериране, печат (PDF), отмяна
- 🚚 **Заявка за куриер** директно от админ панела
- 📊 **Страница Поръчки Иконт** — специализиран изглед за всички пратки
- 💳 **Гъвкаво ценообразуване** — калкулатор, фиксирана цена, безплатна доставка, CSV, калкулатор + надбавка
- 🎁 **Прагове за безплатна доставка** по тип доставка
- ✅ **Валидация на API данни** в реално време
- 🇧🇬 **Включен български превод**

## 📦 Инсталация

1. Изтеглете [последната версия](https://github.com/ventzie555/drusoft-shipping-for-econt/releases) или клонирайте репото
2. Качете папката `drusoft-shipping-for-econt` в `/wp-content/plugins/`
3. Активирайте плъгина от **Разширения → Инсталирани разширения**
4. Отидете в **WooCommerce → Настройки → Доставка → Зони за доставка**
5. Добавете/редактирайте зона (напр. „България") → **Добави метод** → изберете **Drusoft Shipping for Econt**
6. Въведете вашите **Иконт API данни** и натиснете **Запази промените**
7. Синхронизирането на градове и офиси започва автоматично

### Изисквания
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Иконт API данни (свържете се със [Иконт](https://www.econt.bg/))

---

## 📄 Лиценз

Лицензиран под [GNU General Public License v2 или по-късна версия](https://www.gnu.org/licenses/gpl-2.0.html).

Разработен и поддържан от **[ДРУСОФТ ЕООД](https://drusoft.dev/)**.

*За поддръжка или заявки за персонализирани функции, свържете се с [ДРУСОФТ ЕООД](https://drusoft.dev/).*
