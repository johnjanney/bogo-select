# Tests

```bash
composer install
composer test          # or: ./vendor/bin/phpunit
```

## What this suite covers

Unit tests for the parts of the plugin that are pure decisions, run against
small stand-ins for the WordPress and WooCommerce functions they call
(`tests/stubs/`). No database, no HTTP, no WordPress install.

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

## What this suite does **not** cover

The stubs cannot exercise WooCommerce's own runtime, so these remain verified by
hand on a staging site:

- Hook timing and ordering against real WooCommerce (`woocommerce_before_calculate_totals`
  priority 20 versus third-party pricing plugins).
- Cart session serialisation and restoration between requests.
- `WC_Cart::add_to_cart()` validation — including the rejected-replacement path
  that F-03 hardened, which depends on core and third-party
  `woocommerce_add_to_cart_validation` callbacks.
- Order line-item creation, order meta, checkout, tax on a $0.00 line, and stock
  reduction on order completion.
- AJAX and Store API transport: nonce verification, `check_ajax_referer()`,
  `wp_send_json_*`, and the Store API routes themselves. The handlers' logic is
  covered; the request layer under them is not.
- The browser half of block support: `wc.blocksCheckout.extensionCartUpdate()`,
  the `wc/store/cart` subscription, and the block re-render that follows. There
  is no JavaScript test runner in this project.
- Real block rendering. `BlocksTest` calls the `render_block` filter directly
  with a parsed-block array; it does not prove WooCommerce renders the blocks in
  that order or that the markup lands where intended on a real page.
- `WP_DEBUG` output.

Closing that gap needs a WooCommerce integration harness
(`wp-env` or `wp scaffold plugin-tests` plus the WooCommerce test bootstrap)
running against the declared minimum and current WooCommerce releases, plus a
browser-level pass over the four cart/checkout combinations (classic and block).
That is tracked as remaining work in `CODEX-REVIEW-RESPONSE.md` (F-06, C-02).
