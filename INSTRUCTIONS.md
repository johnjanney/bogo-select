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
| WooCommerce | 9.9 |
| PHP | 7.4 |

The plugin will not activate without WooCommerce 9.9 or later — activation is
refused with an explanation. If WooCommerce is removed or downgraded afterwards,
the plugin stops running and shows an admin notice, but stays activated and keeps
your settings; restoring WooCommerce brings the offer back exactly as it was. (On
WordPress 6.5 and later, WordPress enforces the dependency itself and may
deactivate the plugin for you.)

**Either cart and checkout style works.** The classic shortcodes
(`[woocommerce_cart]`, `[woocommerce_checkout]`) and the WooCommerce *Cart* and
*Checkout blocks* are both supported, as of v1.2.0. Nothing needs configuring —
the chooser works out which it is looking at.

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
| **Start date** | The first day the offer runs. Leave empty to start immediately. |
| **End date** | The last day the offer runs — it runs all of that day. Leave empty to run until you switch it off. |

Clearing a date removes that bound. A date the screen cannot read is not the same
thing: it keeps the date you had, and tells you it did, so a typo never quietly
turns a bounded campaign into an open-ended one. An end date before the start
date is refused for the same reason — the schedule you were already running
stays in place until you enter one that can actually run.

### Quantities

| Setting | What it does |
|---|---|
| **Buy quantity** | How many qualifying units the customer must have in the cart. Any whole number from 1 up. |
| **Get quantity** | How many units they receive of the reward they pick. Any whole number from 1 up. |
| **Reward price** | **Free** (default): the reward costs nothing. **Percentage off**: it is sold at the discount you set, from 0 to 100. A discount of 100% is the same as free. |
| **Repeat offer** | **Off** (default): the customer earns one reward set no matter how much they buy. **On**: they earn one set per multiple of the Buy quantity — with Buy 2 Get 1, six qualifying items earn 3. |

Quantities are independent, so Buy 4 Get 8 is as valid as Buy 2 Get 2.

### Buy products — what qualifies

| Setting | What it does |
|---|---|
| **All Products** | Any product in the cart counts toward the Buy quantity. |
| **Select Products** | Only what you list counts. Start typing in the search box to find products and variations by name or SKU. |

Under *Select Products* you can list a product or one specific variation. A
product counts every variation of it — list the T-Shirt and any size counts. A
variation counts only itself — list *T-Shirt — Large* and the small one no longer
qualifies. Listing both is the same as listing the product alone.

Quantities are **summed across the whole cart**. With Buy quantity 2 and *All
Products*, one Widget plus one Gadget qualifies. (If you need "2 of the same
product", say so — see `OPEN-QUESTIONS.md` Q-002.)

Free gift items never count toward the Buy quantity, so a gift can't qualify the
customer for another gift.

### Get products — what they can choose from

| Setting | What it does |
|---|---|
| **All Products** | The customer may choose any purchasable simple product in your catalogue. |
| **Select Products** | The customer chooses from the list you build. This is the usual choice. |

Notes on the Get list:

- **Variable products can be rewards, and the customer picks the option.** Add the
  variable product and its card carries a dropdown of every variation that can be
  given, each with its price and, where it can't, the reason. Add one variation
  instead and the reward is pinned to it, with no choice shown. Grouped and
  external products are still filtered out and removed when you save, as is a
  variation left set to "Any" for one of its attributes — that would still need a
  choice making.
- **Variations that share their parent's stock compete with each other.** If Small
  and Large draw on one stock record, choosing Small can leave Large unavailable.
  The dropdown says so against the option rather than leaving you to work it out.
- **Out-of-stock products are shown but not selectable.** If you award 8 free units
  and only 5 are in stock, that product is marked unavailable — the customer isn't
  given a partial gift. Units of the same product already in the cart count against
  that stock, so 2 paid plus 2 free needs 4 in stock, not 2.
- **Long lists are paged.** The chooser shows 24 gifts at a time with a search box
  and Previous/Next buttons, so *All Products* really does reach the whole
  catalogue however large it is. *Select Products* is still recommended when you
  want to control exactly what is given away.

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

### Buy 2 of one particular product

| Setting | Value |
|---|---|
| Buy quantity | `2` |
| Get quantity | `1` |
| Buy products | Select Products → **just that one product** |
| Get products | Select Products → your reward list |

The Buy quantity counts units across everything on the Buy list, so a list of one
product means "two of *that* product". This is worth knowing because the reverse
also holds: with two products on the Buy list, one of each adds up to two and
qualifies. If you want "two of the same thing", give the offer a Buy list of one.

There is currently no way to say "two of any single product, chosen from several"
— a Buy list of A and B always accepts one of each. See `OPEN-QUESTIONS.md`
Q-002.

---

## 5. What the customer sees

1. **Shop and product pages** — once the cart qualifies, a short notice appears:
   *"You've unlocked a free gift — choose it in your cart."* Turn this off with
   **Show notice on shop pages** if you'd rather keep it to the cart.
2. **Cart and checkout pages** — a panel above the cart table, the checkout form,
   or the Cart/Checkout block, with your offer title and a grid of gift options:
   image, name, price struck through, and a **Select** button. If there is more
   than one page of gifts, a search box and Previous/Next buttons appear above and
   below the grid. On a block cart the panel appears the moment the cart qualifies
   and updates as the cart changes, with no page reload; on a classic cart page,
   choosing a gift reloads the page so the cart table and totals agree with it.
   Choosing a gift at the checkout never reloads — a half-filled form is left
   alone.
3. **After choosing** — the gift appears in the cart at $0.00, marked **Free
   (BOGO)** on a classic cart and **Free gift: BOGO promotion** in a block cart.
   On a percentage offer the same places read **50% off (BOGO)** and
   **Discounted item: 50% off — BOGO promotion**, and the line shows the
   discounted price beside the original struck through.
   Its quantity is fixed and can't be edited either way, but it can be removed.
   The chosen card shows **Selected ✓** with a **Change** link. If a swap is
   refused — say the new gift has just sold out — the original gift stays put and
   the reason is shown.
4. **If the cart stops qualifying** — say they reduce a quantity — the gift is
   removed automatically and they're told: *"Your free gift was removed because
   your cart no longer qualifies."* The same happens if the gift sells out while
   it is sitting in their cart.
5. **Checkout** — a free gift line shows at $0.00 and contributes nothing to the
   total. A discounted line shows what it costs and contributes that.

---

## 6. How orders and inventory behave

The gift is a **real line item priced at what the offer says** — $0.00 by
default, or the discounted figure — and not a coupon discount. That means:

- **Stock is reduced.** An order with 8 free units of Item C reduces Item C's stock
  by 8, exactly as a paid order would.
- **It appears everywhere a product line normally does** — the order screen,
  packing slips, customer emails, and product sales reports.
- **It's labelled.** Cart, checkout, emails, and the admin order detail screen show
  a **Free (BOGO)** marker on the line, or **50% off (BOGO)** on a discounted one.
- **Tax is calculated on the price the line actually carries**, since that price
  is set before totals run — not a discount applied after tax. On a free gift
  that means tax on $0.00.
- **A free gift doesn't count toward free-shipping thresholds**, because it adds
  nothing to the order subtotal. Its weight *is* included in weight-based
  shipping. A discounted reward *does* count, because it has real value.
- **Eligible coupons apply on top of a discounted reward.** A 20% site-wide
  coupon over a 50% reward leaves the customer paying 40% of list, as it would on
  any reduced price. A coupon that excludes the product, its category, or
  sale items still excludes it here.
- **A reward already on sale is discounted from its sale price**, not from its
  regular price.
- **Refunds** behave as for any other line: restocking through the order screen
  returns the units to inventory.

**A note for whoever handles your tax setup.** The plugin taxes the reward on the
amount the customer is charged — nothing for a free gift, the reduced figure for
a discounted one. That is what WooCommerce does for any reduced price, and it is
the conventional treatment. Some jurisdictions instead treat a promotional good
as taxable at its normal value, and a few treat a free gift above a threshold as
a taxable supply in its own right. If your accountant tells you either applies,
that is not something to change here: it needs a tax plugin or a manual
adjustment at filing time. Verified behaviour, in both display modes: a 20.00
reward at half price is taxed on 10.00 whether your prices include tax or exclude
it.

**Free shipping thresholds.** A free reward adds nothing to the order value, so
it cannot carry a customer over a "free shipping over X" threshold — but it is
still in the parcel, so its weight reaches a weight-based shipping method. A
discounted reward *does* add its own reduced price to the order value and can
therefore cross the threshold, because the customer is paying for it. Both are
covered by the automated tests.

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

**The chooser doesn't appear on the cart or checkout page.**
Check, in order: the offer is enabled; the cart holds at least the Buy quantity of
*eligible* products; and the Get list has at least one product that is purchasable
and in stock for the full Get quantity. Both the classic shortcodes and the
Cart/Checkout blocks are supported from v1.2.0 — on v1.1.0 and earlier the blocks
show no chooser at all, so upgrade if that is what you are running.

**On a block cart, the chooser is there but a gift won't select.**
The block cart makes its changes through WooCommerce's Store API. If another
plugin blocks that route, or the browser console shows a failed request to
`/wp-json/wc/store/`, the chooser falls back to reloading the page — which still
works, but is the symptom to report.

**A gift product is greyed out.**
It's out of stock, has less stock than the Get quantity *plus* any units of the
same product already in the cart, isn't purchasable (no price set, or hidden from
the catalogue), or is limited to one per order while the offer awards more than one.

**A gift I expected isn't in the chooser.**
With a long list the chooser shows one page at a time — use the search box or the
Next button. If it isn't there at all, check it's a simple product that is
published, purchasable, and (for *Select Products*) still on the Get list.

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
// Modify the products offered in the chooser. Runs once per page of results,
// before the eligibility gate — so in "Select Products" scope it can remove
// options but not add ones outside the configured list, because the selection
// endpoint enforces that same gate.
add_filter( 'bogo_select_get_products', function ( array $product_ids ) {
    return $product_ids;
} );

// The same list, with the context that produced it. Use this one when a callback
// needs to know which page, scope, or search term it is being handed.
add_filter( 'bogo_select_choice_ids', function ( array $product_ids, array $context ) {
    // $context: scope, search, page, per_page.
    return $product_ids;
}, 10, 2 );

// Change how many gifts one page of the chooser holds (default 24).
add_filter( 'bogo_select_all_products_limit', function ( $per_page ) {
    return 12;
} );

// Cap how many matching products a gift search inspects (default 200).
add_filter( 'bogo_select_search_limit', function ( $limit ) {
    return 500;
} );

// How long the eligibility of a "Select Products" gift list is cached, in
// seconds (default 600). The cache is also cleared whenever the settings or any
// product are saved. Return 0 to switch it off.
add_filter( 'bogo_select_eligibility_ttl', function ( $seconds ) {
    return 0;
} );

// Override qualification entirely.
add_filter( 'bogo_select_qualifies', function ( $qualifies, $buy_count ) {
    return $qualifies;
}, 10, 2 );

// Change how many reward units are awarded.
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
        // reward line
    }
}
```

Both keys keep the word "free" on a discounted line too. They are persisted keys
that existing reports already query, so they mark a line as the offer's rather
than claiming it cost nothing. What the offer actually was is recorded separately
on the order line, frozen at the moment of the order because the settings can
change afterwards:

```php
$item->get_meta( '_bogo_select_discount' ); // 'free', or 'percent:50'
```

### Settings storage

Everything lives in one option, `bogo_select_settings`:

```php
$settings = get_option( 'bogo_select_settings' );
// [ 'enabled' => 'yes', 'buy_qty' => 2, 'get_qty' => 2,
//   'get_discount_type' => 'free', 'get_discount_value' => 0.0,
//   'buy_scope' => 'all', 'buy_products' => [],
//   'get_scope' => 'select', 'get_products' => [ 12, 34 ],
//   'repeat' => 'no', 'show_notice' => 'yes', 'offer_title' => '…' ]
```
