# Project Brief — BOGO Select for WooCommerce

**Status:** v1.0.0 specification
**Last updated:** 2026-07-30

---

## 1. Purpose

Give WooCommerce store owners a "Buy X, Get Y free" promotion where the customer
**chooses** which free product they receive from a list the admin controls.

The free product is added to the cart as a real line item priced at **$0.00**, so
that WooCommerce decrements stock for it exactly as it would for a paid item.

---

## 2. Business requirements

These are the requirements as given by the client, restated for implementation.

| # | Requirement |
|---|-------------|
| R1 | When a customer purchases a qualifying product, they may select from a pre-selected list of products to receive one for free. |
| R2 | The selected "Get" product is added to the cart at a 100% discount, so inventory is still reduced by one unit per free item. |
| R3 | The Buy quantity and Get quantity are both admin-configurable to any positive integer (Buy 2 Get 2, Buy 4 Get 8, etc.). |
| R4 | Buy and Get are each limited to **one product item** per offer. A customer who buys a qualifying quantity chooses **one** Get product and receives the full Get quantity of it. |
| R5 | Buy scope and Get scope are set **independently** to either **All Products** or **Select Products** (with an explicit product list). |

### Worked examples

- **Buy 2, Get 2** — Buy scope *All Products*, Get scope *Select Products* = [Item B, Item C].
  Customer adds 2 × Item A → qualifies → chooses Item B → 2 × Item B added at $0.00.
- **Buy 4, Get 8** — Customer adds 4 qualifying units → chooses Item C → 8 × Item C at $0.00.
- **Buy 2, Get 2, Buy scope = [Item A]** — Adding 2 × Item D does *not* qualify.

---

## 3. Scope

### In scope for v1.0.0

- Single global offer (one Buy rule, one Get rule) configured in the WordPress admin.
- Buy/Get scope selectors: All Products or Select Products (product list with AJAX search).
- Configurable Buy Quantity and Get Quantity (any integer ≥ 1).
- Qualification measured across the whole cart: the sum of quantities of all
  Buy-eligible cart items (free reward items excluded from the count).
- Customer-facing chooser on the **cart page**, plus a notice on the **shop/product
  pages** when the cart qualifies.
- Free item added via AJAX, priced at $0.00, quantity locked to the Get Quantity.
- Customer can swap their choice (removes the old free item, adds the new one).
- Continuous re-validation: if the cart stops qualifying, the free item is removed
  automatically and the customer is told why.
- Free line items are flagged in the cart, checkout, order emails, and admin order screen.
- Optional "repeat" mode: award one reward set per multiple of the Buy Quantity.
- Uninstall routine that deletes plugin options.

### Explicitly out of scope for v1.0.0

- Multiple simultaneous offers / offer stacking.
- Category-, tag-, or attribute-based scoping (product IDs only).
- Variable-product variation-level targeting (parent product IDs only; see Open Questions).
- Per-customer / per-role / per-coupon eligibility rules.
- Scheduling (start and end dates for the offer).
- Subscriptions, bundles, composite products, and other third-party product types.
- Multi-currency and multi-language (WPML/Polylang) integration testing.

---

## 4. Functional specification

### 4.1 Settings (admin)

| Setting | Key | Type | Default | Notes |
|---|---|---|---|---|
| Enable offer | `enabled` | bool | `no` | Master on/off switch. |
| Offer title | `offer_title` | string | `Choose your free gift` | Heading on the chooser. |
| Buy quantity | `buy_qty` | int ≥ 1 | `1` | Units required to qualify. |
| Get quantity | `get_qty` | int ≥ 1 | `1` | Free units awarded. |
| Buy scope | `buy_scope` | `all` \| `select` | `all` | |
| Buy products | `buy_products` | int[] | `[]` | Required when `buy_scope = select`. |
| Get scope | `get_scope` | `all` \| `select` | `select` | |
| Get products | `get_products` | int[] | `[]` | Required when `get_scope = select`. |
| Repeat offer | `repeat` | bool | `no` | Off: max one reward set. On: `floor(buy_count / buy_qty)` sets. |
| Show on product pages | `show_notice` | bool | `yes` | Site-wide "you qualify" notice. |

Stored as a single serialized option: `bogo_select_settings`.

### 4.2 Qualification algorithm

```
buy_count = Σ quantity of every cart item where:
              item is NOT a BOGO reward item
              AND (buy_scope = all OR product_id ∈ buy_products)

sets      = repeat ? floor(buy_count / buy_qty) : (buy_count >= buy_qty ? 1 : 0)
reward_qty = sets × get_qty
qualifies = reward_qty > 0
```

A product that is in **both** the Buy and Get lists still counts toward
`buy_count` when the customer paid for it; the free copies never count.

### 4.3 Reward line item

- Added with cart item meta `_bogo_select_free => true` and a stamped
  `_bogo_select_token` (hash of the settings that generated it) for revalidation.
- Price forced to `0` on `woocommerce_before_calculate_totals` (priority 20).
- Quantity is not editable in the cart; the input is replaced with static text.
- Free items are excluded from `buy_count`, so a reward can never bootstrap itself.
- Only one reward line item may exist at a time (R4: one Get product per cart).

### 4.4 Re-validation triggers

Runs on `woocommerce_cart_loaded_from_session`, `woocommerce_check_cart_items`,
and after any cart quantity update:

1. Offer disabled, or reward product no longer eligible → remove reward, notice.
2. Cart no longer qualifies → remove reward, notice.
3. `reward_qty` changed (quantity up/down, repeat mode) → adjust the line quantity.
4. Reward product went out of stock / below required quantity → remove reward, notice.

### 4.5 Customer flow

1. Customer adds qualifying products to the cart.
2. Cart page shows the chooser: offer title, "Choose 1 of N", product cards with
   image, name, stock state, and a **Select** button.
3. Clicking Select fires an AJAX request → server re-validates → reward added →
   cart fragments refresh.
4. Chosen card shows as **Selected** with a **Change** action.
5. Checkout, emails, and the admin order screen display the line as **Free (BOGO)**
   at $0.00 with normal stock reduction.

---

## 5. Technical approach

- **Plugin type:** standalone plugin, no framework dependencies, no build step.
- **PHP:** 7.4+ (typed properties avoided for 7.4 compatibility; `declare(strict_types)` not used).
- **WordPress:** 6.0+ / **WooCommerce:** 7.0+ (HPOS compatible — declares
  `custom_order_tables` support).
- **Architecture:** small single-responsibility classes autoloaded by an explicit
  `require` manifest in the bootstrap file.

| Class | Responsibility |
|---|---|
| `BOGO_Select` | Bootstrap, dependency wiring, activation checks. |
| `BOGO_Select_Settings` | Read/write/sanitize the option; defaults. |
| `BOGO_Select_Engine` | Pure qualification logic (no output, no side effects). |
| `BOGO_Select_Cart` | Cart hooks: pricing, quantity lock, validation, display. |
| `BOGO_Select_Frontend` | Renders the chooser and notices, enqueues assets. |
| `BOGO_Select_Ajax` | `select`/`remove` endpoints, nonce + capability checks. |
| `BOGO_Select_Admin` | Settings screen, product search AJAX, settings link. |

- **Free pricing method:** direct price override (`$product->set_price( 0 )`), *not*
  a generated coupon. See `DECISION.md` §D-002.
- **Security:** every AJAX endpoint verifies a nonce; the admin product search
  requires `manage_woocommerce`; all settings input is sanitized on save and all
  output escaped.

---

## 6. Acceptance criteria

1. With Buy 2 / Get 2, adding 2 qualifying units surfaces the chooser; selecting a
   Get product adds 2 units at $0.00 and the cart total is unchanged.
2. Completing that order reduces the Get product's stock by 2.
3. With Buy 4 / Get 8, 4 qualifying units award 8 free units.
4. Reducing the cart below the Buy quantity removes the free item automatically.
5. Buy scope = Select Products limits qualification to the listed products only.
6. Get scope = All Products lets the chooser search the whole catalogue.
7. The free item's quantity cannot be edited from the cart page.
8. No PHP notices/warnings with `WP_DEBUG` enabled.

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| Customer manipulates the AJAX call to get a free item without qualifying. | Server re-validates qualification on every add, and again on `woocommerce_check_cart_items` before checkout. |
| Free item priced $0 but taxed as if paid. | Price override happens before totals are calculated, so tax is computed on $0. |
| Third-party plugins also filter cart item prices. | Price override runs at priority 20 on `woocommerce_before_calculate_totals`. |
| Out-of-stock Get product selected. | Stock checked at selection time and again at checkout by WooCommerce's own validation. |
