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
| `SettingsTest.php` | Defaults, normalization, corrupt options, `sanitize()`. |
| `QualificationTest.php` | Buy counting, scopes, variations, repeat mode, filters, reward-key lookup. |
| `AvailabilityTest.php` | Gift eligibility by type and scope, stock/backorder/sold-individually rules, cart-wide stock demand. |
| `ChooserPagingTest.php` | Paging and search across both Get scopes — regression cover for F-02 — and the page-aware `bogo_select_choice_ids` filter (C-04). |
| `ChooserSearchTest.php` | Searching by name and by SKU, through the product data store and through the query fallback — regression cover for C-01. |
| `EligibilityCacheTest.php` | The cached eligibility of a curated gift list, and what clears it (C-03). |
| `GiftSelectionTest.php` | Choosing, swapping, and removing a gift: refused replacements, quantity correction, scope and stock refusals, and that validation is never left suspended. |
| `FrontendTest.php` | The rendered chooser: where it prints, what it prints, and what the script is told. |
| `BlocksTest.php` | Cart/Checkout Blocks: chooser injection, Store API state, update callback, item labelling, quantity limits. |
| `CartValidationTest.php` | Self-healing cart: stock revalidation (F-01), duplicate reward lines (F-04), suspension (F-03), quantity lock, $0 pricing, subtotal display (F-07). |

## What the integration job covers

Each scenario reconfigures the same seeded store rather than rebuilding it, and
they run in order.

| Script | Covers |
|---|---|
| `blocks.test.mjs` | Store API cart state and gift label, quantity limits, and the Cart and Checkout **blocks** rendering on a real page — that the chooser slot never takes the block root's `data-block-name`, and that each block leaves `is-loading`. |
| `discount.test.mjs` | A percentage reward through the Store API: the discounted figure WooCommerce actually charges, that repeated recalculation does not compound it, and the discounted wording in the Cart block. |
| `variable.test.mjs` | A variable reward: that the parent alone is refused, that the line is priced from the chosen variation rather than the parent's range, and that the cart renders one selector listing every variation. |
| `coupon.test.mjs` | Coupons alongside a discounted reward: that an eligible coupon compounds on the already-reduced price, and that one excluding the reward leaves it alone while still discounting the rest of the cart. |
| `order.test.mjs` + `assert-order.php` | Placing a real order through the Store API checkout, then inspecting it: the reward line and its quantity, the discounted line total, `_bogo_select_free` and `_bogo_select_discount`, the visible label, and stock reduced by the awarded quantity. No browser — none of it is about rendering. |

## What neither suite covers

- Hook timing against third-party plugins (`woocommerce_before_calculate_totals`
  priority 20 versus other pricing plugins).
- The **classic** cart and checkout templates. Every browser assertion so far is
  against the blocks; the shortcode path is still verified by hand.
- The Checkout block for the discounted and variable rewards specifically — the
  free scenario covers checkout, the other two stop at the cart.
- Tax on a reward line, in either tax-display mode.
- Sale-price interaction: a reward already on sale being discounted again.
- `WP_DEBUG` output.

The first four are tracked as `CODEX-REVIEW.md` M-03.
