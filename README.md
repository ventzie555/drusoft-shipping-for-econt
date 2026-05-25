# Drusoft Shipping for Econt — WooCommerce Plugin

[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-96588a.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP](https://img.shields.io/badge/PHP-8.0+-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A high-performance, conflict-free **WooCommerce shipping plugin** for [Econt](https://www.econt.com/) courier services in **Bulgaria**. Provides real-time shipping rates, two-step waybill generation, office/automat selection, courier pickup requests, and full order management — all through the official Econt API.

> **⚠️ Compatibility Note:** This plugin requires the classic shortcode-based Cart and Checkout pages (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout is not yet supported.

---

## ✨ Features

### For Customers
- 🏙️ **Dynamic city, office, and automat selection** — Real-time search on checkout
- 📦 **Three delivery types** — Address, Econt Office, or Econt Automat (APS)
- 🔍 **Smart street autocomplete** — Bulgarian street names with prefix handling (`ул.`, `бул.`)
- 🗺️ **Region-based filtering** — Cities filtered by Bulgarian province

### For Merchants
- ⚡ **HPOS compatible** — High-Performance Order Storage support
- 🔄 **Background data sync** — Cities and offices/automats refreshed daily via Action Scheduler
- 🧾 **Waybill management** — Generate, print (PDF), and cancel waybills from the order screen
- 🚚 **Courier pickup requests** — Request a pickup directly from WP admin, with automatic skip of weekends and Bulgarian bank holidays
- 📊 **Dedicated Econt Orders page** — Filtered admin list with bulk Generate / Print / Cancel actions
- 🇧🇬 **Bulgarian translation included**

---

## 🤝 Sibling Plugin — Also Ship via Speedy?

This plugin has a sibling for the **Speedy** courier: **[Drusoft Shipping for Speedy](https://wordpress.org/plugins/drusoft-shipping-for-speedy/)**.

Both plugins share the same checkout UI, settings layout, and admin order-management screens — once you've learned one, the other feels familiar. Install both if your store supports delivery via either courier.

---

## 📦 Installation

1. Download the [latest release](https://github.com/ventzie555/drusoft-shipping-for-econt/releases) or clone this repo
2. Upload the `drusoft-shipping-for-econt` folder to `/wp-content/plugins/`
3. Ensure **WooCommerce** is installed and active
4. Activate the plugin in **Plugins → Installed Plugins**
5. Go to **WooCommerce → Settings → Shipping → Shipping Zones**
6. Add/edit a zone (e.g. "Bulgaria") → **Add shipping method** → select **Drusoft Shipping for Econt**
7. Enter your **Store ID** and **Private Key** (issued by Econt under **My Econt → Integration → API**) and click **Save Changes**
8. Background sync of cities and offices starts automatically

### Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- Econt API credentials (contact [Econt Bulgaria](https://www.econt.com/))

---

## ⚙️ Configuration

### Credentials
| Setting | Description |
|---------|-------------|
| Store ID | Your Econt store identifier (from **My Econt → Integration → API**) |
| Private Key | The API private key issued alongside the Store ID |
| Demo Mode | Routes API calls to Econt's demo environment when enabled |

### Sender Information
- Sender display name, phone, and email used on waybills
- Pickup city, street, and street number (must match a registered "My Addresses" entry in My Econt)
- Latest pickup time for courier requests (default `14:00` — Econt's per-city cut-off is typically `14:00–14:45`)

### Shipment Defaults
- Default weight (kg) for products that have no weight set
- Cash on Delivery toggle

---

## 🛠️ Technical Details

### Database
Creates two custom tables for cached location data:
- `{prefix}drushfe_cities` — ~5,500 Bulgarian cities and villages
- `{prefix}drushfe_offices` — ~600 Econt offices and APS automats

### Background Sync
The `drushfe_sync_locations_event` action runs daily via WooCommerce Action Scheduler. Monitor it at **WooCommerce → Status → Scheduled Actions**.

### API Integration
Two Econt API hosts are used, both authenticated with an `Authorization: <private_key>` header:

| Host | Purpose |
|------|---------|
| `delivery.econt.com` | Orders, payments, grouping (demo: `delivery-demo.econt.com`) |
| `ee.econt.com` | Nomenclatures and Shipments (demo: `demo.econt.com/ee/`) |

Endpoints called:

- `Nomenclatures/getCities` · `getOffices` · `getStreets` — Location data
- `OrdersService.getPrice` — Live cart/checkout pricing
- `OrdersService.updateOrder` + `OrdersService.createAWB` — Waybill draft → committed AWB
- `OrdersService.deleteLabel` — Cancel a single draft
- `Shipments/LabelService.deleteLabels` — Bulk-cancel committed AWBs
- `Shipments/ShipmentService.requestCourier` — Pickup request (with holiday auto-retry)

### Security
- All AJAX handlers are nonce-protected (separate public and admin scopes)
- All database queries use `$wpdb->prepare()` with table prefixes inlined
- Admin redirects use `wp_safe_redirect()` with an `econt.com` host allowlist
- API calls use `wp_remote_post()`

---

## 📄 License

This plugin is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

Developed and maintained by **[DRUSOFT LTD](https://drusoft.dev/)**.

---

## 🤝 Contributing

Contributions are welcome — please open an issue or submit a pull request.

For support or custom feature requests, contact [DRUSOFT LTD](https://drusoft.dev/).

---

# Drusoft Shipping for Econt — WooCommerce плъгин 🇧🇬

Високопроизводителна и безконфликтна интеграция на **Иконт** куриерски услуги за **WooCommerce** магазини в България. Осигурява изчисляване на цени в реално време, генериране на товарителници в две стъпки, избор на офис/автомат, заявка за куриер и пълно управление на поръчки — през официалния Econt API.

> **⚠️ Забележка:** Плъгинът изисква класическите страници за Количка и Плащане (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout все още не се поддържа.

## ✨ Основни функции

### За клиенти
- 🏙️ **Динамичен избор на град, офис и автомат** в реално време при поръчка
- 📦 **Три вида доставка** — до Адрес, Офис на Иконт или Автомат (APS)
- 🔍 **Интелигентно търсене на улици** с автоматично довършване
- 🗺️ **Филтриране по област** — градовете се зареждат по избраната област

### За търговци
- ⚡ **HPOS съвместим** — поддръжка на High-Performance Order Storage
- 🔄 **Ежедневно фоново синхронизиране** на градове и офиси/автомати чрез Action Scheduler
- 🧾 **Управление на товарителници** — генериране, печат (PDF), отмяна
- 🚚 **Заявка за куриер** директно от админ панела, с автоматичен прескок на почивни дни и национални празници
- 📊 **Страница „Поръчки Иконт“** — специализиран изглед с групови действия
- 🇧🇬 **Включен български превод**

## 🤝 Сестрински плъгин — изпращате и със Спиди?

Този плъгин има сестрински плъгин за куриер **Спиди**: **[Drusoft Shipping for Speedy](https://wordpress.org/plugins/drusoft-shipping-for-speedy/)**.

Двата плъгина споделят един и същ потребителски интерфейс при поръчка, разположение на настройките и админ изгледи за управление на поръчки — щом сте свикнали с единия, другият ще ви е познат. Инсталирайте и двата, ако магазинът ви поддържа доставка чрез който и да е от двата куриера.

## 📦 Инсталация

1. Изтеглете [последната версия](https://github.com/ventzie555/drusoft-shipping-for-econt/releases) или клонирайте репото
2. Качете папката `drusoft-shipping-for-econt` в `/wp-content/plugins/`
3. Активирайте плъгина от **Разширения → Инсталирани разширения**
4. Отидете в **WooCommerce → Настройки → Доставка → Зони за доставка**
5. Добавете/редактирайте зона (напр. „България“) → **Добави метод** → изберете **Drusoft Shipping for Econt**
6. Въведете **Store ID** и **Private Key** от **My Econt → Интеграция → API** и натиснете **Запази промените**
7. Синхронизирането на градове и офиси започва автоматично

### Изисквания
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- Иконт API данни (свържете се със [Иконт](https://www.econt.com/))

---

## 📄 Лиценз

Лицензиран под [GNU General Public License v2 или по-късна версия](https://www.gnu.org/licenses/gpl-2.0.html).

Разработен и поддържан от **[ДРУСОФТ ЕООД](https://drusoft.dev/)**.

*За поддръжка или заявки за персонализирани функции, свържете се с [ДРУСОФТ ЕООД](https://drusoft.dev/).*
