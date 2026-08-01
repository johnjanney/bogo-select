# Tests

```bash
composer install
composer test          # or: ./vendor/bin/phpunit
```

## What is covered

Two suites, covering different things.

**The unit suite** tests the parts of the plugin that are pure decisions,
against small stand-ins for the WordPress and WooCommerce functions they call
(`tests/stubs/`). No database, no HTTP, no WordPress install.

**The integration job** (`.github/workflows/ci.yml` → `integration`) installs the
built zip into a real WordPress with WooCommerce — the compatibility floor and
whatever is current — and drives it over HTTP and in headless Chromium. It needs
Docker, so it runs in CI rather than from `composer test`.

### Unit suite

| File | Covers |
|---|---|
| `SettingsTest.php` | Defaults, normalization, corrupt options, `sanitize()`, and the discount keys. |
| `QualificationTest.php` | Buy counting, scopes, variations, repeat mode, filters, reward-key lookup. |
| `AvailabilityTest.php` | Gift eligibility by type and scope, stock/backorder/sold-individually rules, cart-wide stock demand. |
| `ChooserPagingTest.php` | Paging and search across both Get scopes — regression cover for F-02 — and the page-aware `bogo_select_choice_ids` filter (C-04). |
| `ChooserSearchTest.php` | Searching by name and by SKU, through the product data store and through the query fallback — regression cover for C-01. |
| `EligibilityCacheTest.php` | The cached eligibility of a curated gift list, and what clears it (C-03). |
| `GiftSelectionTest.php` | Choosing, swapping, and removing a gift: refused replacements, quantity correction, scope and stock refusals, and that validation is never left suspended. |
| `FrontendTest.php` | The rendered chooser: where it prints, what it prints, and what the script is told. |
| `BlocksTest.php` | Cart/Checkout Blocks: chooser injection, Store API state, update callback, item labelling, quantity limits. |
| `DiscountPricingTest.php` | The reward's discounted price: the factor, per-unit rounding, that repeated pricing passes do not compound it, the wording helpers, and the order-line snapshot. |
| `VariableProductStubTest.php` | That the fake catalogue models variable products the way WooCommerce does — parent/child links, attributes, "any" values, shared stock pools, and a cart line's own product object. |
| `VariableEligibilityTest.php` | Which products may be offered against which may be awarded, the parent-spoofing guard, and why a product cannot be offered. |
| `VariableSelectionTest.php` | Awarding a variation: the reward pair, sibling swaps, the state signature, and validation. |
| `VariableChooserTest.php` | The variable card: its selector, per-option availability, aggregate card state, and which card owns the selection. |
| `VariableRenderCostTest.php` | How many product loads a page of variable cards costs, and that the variation memo is per request. |
| `CartValidationTest.php` | Self-healing cart: stock revalidation (F-01), duplicate reward lines (F-04), suspension (F-03), quantity lock, $0 pricing, subtotal display (F-07). |

## What the integration job covers

Each scenario reconfigures the same seeded store rather than rebuilding it, and
they run in order.

| Script | Covers |
|---|---|
| `blocks.test.mjs` | Store API cart state and gift label, quantity limits, and the Cart and Checkout **blocks** rendering on a real page — that the chooser slot never takes the block root's `data-block-name`, and that each block leaves `is-loading`. |
| `discount.test.mjs` | A percentage reward through the Store API: the discounted figure WooCommerce actually charges, that repeated recalculation does not compound it, and the discounted wording in the Cart block. |
| `variable.test.mjs` | A variable reward: that the parent alone is refused, that the line is priced from the chosen variation rather than the parent's range, and that the cart renders one selector listing every variation. |
| `classic.test.mjs` | The shortcode cart and checkout, including a variable reward chosen over admin-ajax and a switch between two individually listed siblings: that the chooser arrives through the template hooks rather than the `render_block` filter, that choosing over admin-ajax works by clicking the button, that the reloaded cart shows the badge, discounted price, and locked quantity, and that the checkout slot is marked `checkout` rather than `classic` so it never reloads a part-filled form. |
| `sale.test.mjs` | A reward already on sale, discounted again: that the reduction comes off the sale price rather than the regular one, with fixture prices chosen so the wrong answer cannot be mistaken for the right one. |
| `shipping.test.mjs` | How a reward behaves against shipping, run once per mode: that a free reward adds weight but not order value and so cannot cross a free-shipping threshold, that a discounted one adds both, and that either joins the parcel. |
| `tax.test.mjs` | Tax on a discounted reward, run once per display mode: that the line is taxed on what the customer pays rather than on the price it was discounted from, and that a tax-inclusive store still charges exactly half the shelf price. |
| `coupon.test.mjs` | Coupons alongside a discounted reward: that an eligible coupon compounds on the already-reduced price, and that one excluding the reward leaves it alone while still discounting the rest of the cart. |
| `order.test.mjs` + `assert-order.php` | Placing a real order through the Store API checkout, then inspecting it: the reward line and its quantity, the discounted line total, `_bogo_select_free` and `_bogo_select_discount`, the visible label, and stock reduced by the awarded quantity. No browser — none of it is about rendering. |

## What neither suite covers

- Hook timing against third-party plugins (`woocommerce_before_calculate_totals`
  priority 20 versus other pricing plugins). This is the trade `DECISION.md`
  D-016 took deliberately, not an oversight.
- Weight-based shipping rates specifically. `shipping.test.mjs` proves the reward
  joins the parcel, using a per-item flat rate; WooCommerce's own flat rate cannot
  express weight, so a rate that varies by weight would need a third-party method
  to exercise.
- Multi-currency, and currencies whose minor unit is not two digits. The
  assertions read `currency_minor_unit` rather than assuming, but only one
  currency is ever configured.
- `WP_DEBUG` output.

`CODEX-REVIEW.md` M-03 is otherwise covered.
