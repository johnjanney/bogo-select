# Instructions — BOGO Select for WooCommerce

How to install, configure, use, and troubleshoot the plugin.

---

## Contents

1. [Requirements](#1-requirements)
2. [Installation](#2-installation)
3. [Configuration](#3-configuration)
4. [Setting up common offers](#4-setting-up-common-offers)
5. [What the customer sees](#5-what-the-customer-sees)
6. [How orders and inventory behave](#6-how-orders-and-inventory-behave)
7. [Testing your offer](#7-testing-your-offer)
8. [Troubleshooting](#8-troubleshooting)
9. [Uninstalling](#9-uninstalling)
10. [For developers](#10-for-developers)

---

## 1. Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 7.4 |

The plugin will not activate without WooCommerce, and deactivates itself with an
admin notice if WooCommerce is later removed.

**Cart and checkout pages must use the classic shortcodes** (`[woocommerce_cart]`
and `[woocommerce_checkout]`). If your cart page uses the WooCommerce *Cart block*,
the gift chooser will not appear — see [Troubleshooting](#8-troubleshooting).

---

## 2. Installation

### Option A — upload a ZIP

1. Download this repository as a ZIP.
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP, click **Install Now**, then **Activate**.

### Option B — copy the folder

1. Copy the plugin folder to `wp-content/plugins/bogo-select`.
2. **Plugins** → find **BOGO Select for WooCommerce** → **Activate**.

### Option C — Git / WP-CLI

```bash
cd wp-content/plugins
git clone https://github.com/johnjanney/bogo-select.git bogo-select
wp plugin activate bogo-select
```

After activating you'll find the settings at **WooCommerce → BOGO Select**, or via
the **Settings** link under the plugin name on the Plugins screen.

---

## 3. Configuration

Go to **WooCommerce → BOGO Select**.

### Offer

| Setting | What it does |
|---|---|
| **Enable offer** | Master switch. Leave unticked while you're still setting things up — nothing shows on the front end until this is on. |
| **Offer title** | Heading shown above the gift chooser, e.g. *"Choose your free gift"*. |

### Quantities

| Setting | What it does |
|---|---|
| **Buy quantity** | How many qualifying units the customer must have in the cart. Any whole number from 1 up. |
| **Get quantity** | How many free units they receive of the gift they pick. Any whole number from 1 up. |
| **Repeat offer** | **Off** (default): the customer earns one gift set no matter how much they buy. **On**: they earn one set per multiple of the Buy quantity — with Buy 2 Get 1, six qualifying items earn 3 free. |

Quantities are independent, so Buy 4 Get 8 is as valid as Buy 2 Get 2.

### Buy products — what qualifies

| Setting | What it does |
|---|---|
| **All Products** | Any product in the cart counts toward the Buy quantity. |
| **Select Products** | Only the products you list count. Start typing in the search box to find products by name or SKU. |

Quantities are **summed across the whole cart**. With Buy quantity 2 and *All
Products*, one Widget plus one Gadget qualifies. (If you need "2 of the same
product", say so — see `OPEN-QUESTIONS.md` Q-002.)

Free gift items never count toward the Buy quantity, so a gift can't qualify the
customer for another gift.

### Get products — what they can choose from

| Setting | What it does |
|---|---|
| **All Products** | The customer may choose any purchasable product in your catalogue. |
| **Select Products** | The customer chooses from the list you build. This is the usual choice. |

Notes on the Get list:

- **Variable products can't be gifts.** "One free t-shirt" doesn't say which size,
  so variable products are filtered out of the chooser. To give away a specific
  variation, list it as its own simple product.
- **Out-of-stock products are shown but not selectable.** If you award 8 free units
  and only 5 are in stock, that product is marked unavailable — the customer isn't
  given a partial gift.
- Setting Get to *All Products* with a large catalogue produces a long chooser;
  *Select Products* is recommended.

Click **Save changes**. Settings take effect immediately, including for carts that
already exist — an existing cart is re-validated on its next page load.

---

## 4. Setting up common offers

### Buy 2, Get 2 — any purchase, gift from a short list

| Setting | Value |
|---|---|
| Enable offer | ✅ |
| Buy quantity | `2` |
| Get quantity | `2` |
| Repeat offer | ☐ |
| Buy products | All Products |
| Get products | Select Products → *Item B*, *Item C* |

Customer buys 2 of anything → picks Item B or Item C → gets 2 of it free.

### Buy 4, Get 8 — from one product line

| Setting | Value |
|---|---|
| Buy quantity | `4` |
| Get quantity | `8` |
| Buy products | Select Products → *Item A* |
| Get products | Select Products → *Item B*, *Item C* |

Only Item A counts toward qualifying. Four of them earn eight free units of the
customer's chosen gift.

### Buy 1, Get 1 — repeating, same catalogue both sides

| Setting | Value |
|---|---|
| Buy quantity | `1` |
| Get quantity | `1` |
| Repeat offer | ✅ |
| Buy products | All Products |
| Get products | All Products |

Every paid item earns another free one, all of the same chosen product.

---

## 5. What the customer sees

1. **Shop and product pages** — once the cart qualifies, a short notice appears:
   *"You've unlocked a free gift — choose it in your cart."* Turn this off with
   **Show notice on shop pages** if you'd rather keep it to the cart.
2. **Cart page** — a panel above the cart table with your offer title and a grid of
   gift options: image, name, price struck through, and a **Select** button.
3. **After choosing** — the gift appears in the cart table marked **Free (BOGO)**
   at $0.00. Its quantity is fixed and can't be edited, but it can be removed.
   The chosen card shows **Selected ✓** with a **Change** link.
4. **If the cart stops qualifying** — say they reduce a quantity — the gift is
   removed automatically and they're told: *"Your free gift was removed because
   your cart no longer qualifies."*
5. **Checkout** — the gift line shows at $0.00 and contributes nothing to the total.

---

## 6. How orders and inventory behave

The gift is a **real line item priced at $0.00**, not a coupon discount. That means:

- **Stock is reduced.** An order with 8 free units of Item C reduces Item C's stock
  by 8, exactly as a paid order would.
- **It appears everywhere a product line normally does** — the order screen,
  packing slips, customer emails, and product sales reports.
- **It's labelled.** Cart, checkout, emails, and the admin order detail screen show
  a **Free (BOGO)** marker on the line.
- **Tax is calculated on $0.00**, since the price is zero before totals run — not a
  discount applied after tax.
- **It doesn't count toward free-shipping thresholds**, because it adds nothing to
  the order subtotal. Its weight *is* included in weight-based shipping.
- **Refunds** behave as for any $0.00 line: restocking through the order screen
  returns the free units to inventory.

---

## 7. Testing your offer

Before going live, run through this on a staging site or with a test product:

1. Set up the offer and tick **Enable offer**.
2. Add **one less** than the Buy quantity to the cart → chooser should **not** appear.
3. Add one more → chooser appears with the expected gift options.
4. Select a gift → the correct quantity is added at $0.00 and the cart total is unchanged.
5. Try to edit the gift's quantity → the field is static text, not an input.
6. Click **Change** and pick a different gift → the first is replaced, not duplicated.
7. Reduce the paid item below the Buy quantity → gift is removed with a notice.
8. Restore the quantity, re-select, and complete a test order.
9. Check **Products** → the gift product's stock has dropped by the Get quantity.
10. Check the order screen → the gift line reads $0.00 and is marked **Free (BOGO)**.

---

## 8. Troubleshooting

**The chooser doesn't appear on the cart page.**
Check, in order: the offer is enabled; the cart holds at least the Buy quantity of
*eligible* products; the Get list has at least one product that is purchasable and
in stock for the full Get quantity; and your cart page uses the `[woocommerce_cart]`
shortcode rather than the Cart block. The Cart block is not supported in v1.0.0.

**A gift product is greyed out.**
It's out of stock, has less stock than the Get quantity, or isn't purchasable
(no price set, or hidden from the catalogue).

**The gift shows a price instead of $0.00.**
Another plugin is filtering cart item prices after this one. Deactivate other
pricing/discount plugins one at a time to identify it.

**The gift keeps getting removed.**
The cart no longer meets the Buy quantity — usually because a Buy-eligible item was
removed, or the Buy scope was narrowed in settings after the cart was built.

**Stock isn't going down for the free item.**
Confirm the product has **Manage stock** enabled on its Inventory tab. WooCommerce
only reduces stock for products it's tracking.

**Customers report they never see the notice on shop pages.**
Check **Show notice on shop pages** is ticked. Some themes suppress WooCommerce
notices outside cart/checkout — in that case the cart-page chooser still works.

**Nothing works after a WooCommerce update.**
Deactivate and reactivate the plugin, then clear any page/object cache. Cart pages
should be excluded from full-page caching on any WooCommerce store.

---

## 9. Uninstalling

**Deactivate** leaves your settings in place — reactivating restores the offer as
it was.

**Delete** runs the uninstall routine, which removes the plugin's options
(`bogo_select_settings` and its version marker). Orders, products, and stock levels
are untouched. Any gift items sitting in live customer carts simply become normal
priced items on the next cart refresh, so it's best to disable the offer and let
carts clear before deleting.

---

## 10. For developers

### Filters and actions

```php
// Modify the products offered in the chooser.
add_filter( 'bogo_select_get_products', function ( array $product_ids ) {
    return $product_ids;
} );

// Override qualification entirely.
add_filter( 'bogo_select_qualifies', function ( $qualifies, $buy_count ) {
    return $qualifies;
}, 10, 2 );

// Change how many free units are awarded.
add_filter( 'bogo_select_reward_quantity', function ( $qty, $buy_count ) {
    return $qty;
}, 10, 2 );

// Fire when a customer picks a gift.
add_action( 'bogo_select_reward_added', function ( $product_id, $qty ) {
    // e.g. analytics
}, 10, 2 );
```

### Identifying a gift line item

In the cart, the item carries `$cart_item['bogo_select_free'] === true`. On the
order, the line item has the meta key `_bogo_select_free` with value `yes`:

```php
foreach ( $order->get_items() as $item ) {
    if ( 'yes' === $item->get_meta( '_bogo_select_free' ) ) {
        // free gift line
    }
}
```

### Settings storage

Everything lives in one option, `bogo_select_settings`:

```php
$settings = get_option( 'bogo_select_settings' );
// [ 'enabled' => 'yes', 'buy_qty' => 2, 'get_qty' => 2,
//   'buy_scope' => 'all', 'buy_products' => [],
//   'get_scope' => 'select', 'get_products' => [ 12, 34 ],
//   'repeat' => 'no', 'show_notice' => 'yes', 'offer_title' => '…' ]
```
