# Codex Repository Review

**Review date:** 2026-07-30

**Reviewed state:** the uncommitted v1.2.0 worktree on top of commit
`4029f64`

**Overall assessment:** the promotion-label fix is correct in the source
worktree, but the release is not ready. The packaged ZIP does not contain the
fix, and WooCommerce 10.9.4 exposes a separate high-severity regression that
prevents the Checkout block from rendering whenever the promotion is enabled.

## Executive summary

Claude Code correctly diagnosed the original missing-label defect. A Cart or
Checkout block can build its first Store API response during page hydration,
while the outer HTTP request is still `/cart/` or `/checkout/`.
`WC()->is_store_api_request()` therefore was not a sufficient gate for
`woocommerce_get_item_data`.

The revised source fixes that defect:

- the REST and WooCommerce hydration filters bracket Store API response
  generation;
- the gift metadata supplies both `key`/`name` and `value`/`display`;
- the new tests cover the two request scopes and assert the customer-facing
  string rather than merely inspecting one array key.

Live testing confirms that **“Free gift: BOGO promotion”** now appears:

- after selecting a gift in the Cart block;
- after a fresh Cart block page load, which exercises the hydration path;
- in the Checkout block order summary;
- on WooCommerce 9.9.5 and in the Cart block on WooCommerce 10.9.4.

The fix is therefore adequate in `includes/class-bogo-blocks.php`. It is not
adequately released: `dist/bogo-select-1.2.0.zip` still contains the old
URL-gated implementation and empty `display` member.

Live testing also found a separate regression on WooCommerce 10.9.4. The
plugin's `render_block` filter prepends the chooser at priority 10. WooCommerce
also runs its block-attribute filter at priority 10, after this plugin in the
tested load order. WooCommerce consequently adds
`data-block-name="woocommerce/checkout"` to the newly prepended BOGO slot
instead of to the real Checkout block. The Checkout frontend mounts against the
empty slot, and the real checkout remains `is-loading` with no address, order
summary, payment, or place-order UI.

This is not caused by the new label metadata:

- it reproduces with no gift selected;
- it reproduces with an empty chooser slot when the cart does not qualify;
- disabling the promotion, or deactivating the plugin, restores checkout;
- changing only the injection priority from 10 to 20 restores the correct block
  attribute, the checkout UI, and the promotion label. That diagnostic change
  was reverted after verification and is **not** present in the reviewed code.

No new critical/high security defect was found. Classic cart and checkout
continue to work. The current source is good on the declared WooCommerce 9.9
line, but it should not be described as generally compatible with current
WooCommerce Blocks until the checkout-root collision is fixed.

## Scope and evidence

Reviewed:

- `CODEX-REVIEW-RESPONSE.md`, including its new Part 0;
- all PHP and JavaScript implementation files affected by v1.2.0;
- the Blocks tests and supporting WordPress/WooCommerce stubs;
- requirements, instructions, decisions, README, changelog, and compatibility
  metadata;
- CI, dependency locking, the build script, and the v1.2.0 ZIP;
- WooCommerce 9.9.5 and 10.9.4 hydration and block-rendering source.

Automated checks:

| Check | Result |
|---|---|
| PHPUnit | **Pass — 127 tests, 252 assertions** |
| PHP syntax, repository PHP outside `vendor` | **Pass** |
| JavaScript syntax, `assets/js/bogo-select.js` | **Pass** |
| `bin/build-zip.sh` shell syntax | **Pass** |
| `git diff --check` | **Pass** |
| v1.2.0 ZIP compared with worktree | **Fail — stale Blocks class; label fix absent** |

Live environment:

| Component | Versions exercised |
|---|---|
| WordPress | 7.0.2 |
| PHP | 8.2.33 |
| WooCommerce | 9.9.5 and 10.9.4 |
| Database | MariaDB 10.11 |
| Theme | Twenty Twenty-Five |

The live environment was isolated and temporary. Its containers, network, and
volume were removed after testing.

## Findings

### H-01 — WooCommerce 10.9.4 Checkout block mounts into the BOGO slot and never renders

**Severity:** High

**Status:** Open

`BOGO_Select_Blocks` registers:

```php
add_filter( 'render_block', array( $this, 'inject_chooser' ), 10, 2 );
```

and returns:

```php
return BOGO_Select_Frontend::slot_html( 'block' ) . $content;
```

WooCommerce 10.9.4's `BlockTypesController::add_data_attributes()` is also a
priority-10 `render_block` filter. It uses `WP_HTML_Tag_Processor::next_tag()`
and assigns the current block's `data-block-name` to the first tag in the
filtered content.

Because this plugin's callback ran first in the tested load order, the final
checkout markup began as:

```html
<div
  data-block-name="woocommerce/checkout"
  class="bogo-select-slot"
  data-bogo-slot="1"
  data-bogo-mode="block"
></div>
<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading">
  ...
</div>
```

The BOGO slot has the Checkout block identity; the real Checkout root does not.
The customer sees the chooser, but no checkout form.

Reproduction controls:

- active plugin, enabled offer, selected gift: fails;
- active plugin, enabled offer, qualifying cart with no selected gift: fails;
- active plugin, enabled offer, non-qualifying cart and empty slot: fails;
- active plugin, disabled offer: checkout renders;
- plugin deactivated: checkout renders.

Changing only the plugin filter priority to 20 produced:

- no `data-block-name` on the BOGO slot;
- `data-block-name="woocommerce/checkout"` on the real Checkout root;
- a rendered contact/address/payment checkout;
- visible `Free gift: BOGO promotion` item metadata.

The priority change was used only to confirm causality and was reverted.

**Recommendation:** inject after WooCommerce has decorated the original block
root, for example with a later `render_block` priority or an equivalent
block-specific hook whose ordering is explicit. Add a real integration
assertion that:

1. the BOGO slot does not receive the WooCommerce block name;
2. the actual `.wp-block-woocommerce-checkout` root does;
3. the checkout leaves `is-loading` and renders its form.

A unit test that calls `inject_chooser()` directly cannot detect this
cross-plugin filter-order failure.

### M-01 — The source label fix is absent from `dist/bogo-select-1.2.0.zip`

**Severity:** Medium; release blocker

**Status:** Open

The worktree class and packaged class have different SHA-256 hashes. The ZIP
still contains the superseded implementation:

```php
if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) || ! self::is_store_api_request() ) {
    return $item_data;
}

$item_data[] = array(
    'key'     => __( 'Free gift', 'bogo-select' ),
    'value'   => __( 'BOGO promotion', 'bogo-select' ),
    'display' => '',
);
```

It contains none of the new hydration/REST scope hooks. Anyone installing the
current v1.2.0 ZIP still receives the missing-label defect.

**Recommendation:** fix H-01, rerun the full suite and live matrix, rebuild the
ZIP from that exact reviewed state, and compare packaged runtime files with the
source before publishing.

### M-02 — Framework-boundary coverage is still too weak

**Severity:** Medium release risk

**Status:** Open

The new label tests are better than the old one, but they manually fire the
expected hooks. They do not run WooCommerce's hydration service, its
`BlockTypesController`, Store API serialization, or browser frontend.

H-01 is the concrete consequence: 127 tests pass while the current Checkout
block is unusable.

**Recommendation:** add a reproducible WordPress/WooCommerce integration job or
release smoke suite. At minimum cover:

- classic cart and checkout;
- Cart and Checkout blocks;
- minimum supported WooCommerce, declared tested version, and a current
  version;
- gift selection, fresh-page hydration, visible metadata, zero totals,
  quantity limits, reactive qualification, and checkout form rendering;
- the installable ZIP rather than only the source directory.

### M-03 — All Products browse totals remain pre-filter totals

**Severity:** Medium

**Status:** Partially addressed

Unsearched All Products browsing still:

1. queries one catalogue page;
2. publishes WooCommerce's pre-eligibility `total` and `max_num_pages`;
3. filters only the IDs on that page.

The displayed option count can therefore be too high, and a page can be short
or empty even when eligible products exist later. The legacy
`bogo_select_get_products` filter can also append the same product independently
on every page.

**Recommendation:** count/page an eligibility-compatible candidate set, fetch
forward to fill pages, or explicitly document that browse totals are catalogue
counts rather than exact selectable-gift counts.

### L-01 — The validation re-entry guard is not exception-safe

**Severity:** Low

**Status:** Open

`BOGO_Select_Cart::validate()` sets `$this->validating = true`, calls
`run_validation()`, and then clears the flag without `finally`. An exception
from an extension observing a cart removal or quantity change can leave
validation disabled for the rest of that PHP request.

**Recommendation:** clear the flag in `finally`, matching the exception-safe
gift-swap suspend/resume implementation.

### L-02 — Search completeness is deliberately capped

**Severity:** Low

**Status:** Accepted trade-off; clarify

Search examines at most 200 candidates by default. That bounds work but means a
broad term can omit later eligible matches and report only the bounded result
set.

**Recommendation:** document “first 200 matches,” expose truncation in the
response/UI if useful, and raise the limit only from measured catalogue data.

### L-03 — Curated-list caching still has a cold O(N) path

**Severity:** Low

**Status:** Accepted partial fix

The transient and request memo materially reduce repeat work, but the first
request after expiration or invalidation still loads every configured product.
Any product save clears the full map, including saves for products not in the
configured gift list.

**Recommendation:** retain the current design unless profiling shows a problem.
If it does, narrow invalidation to configured products or cache eligibility per
product behind a versioned list index.

## Verification of Claude Code's Part 0 response

| Claim | Assessment | Evidence |
|---|---|---|
| URL sniffing misses block hydration | **Correct** | Confirmed against WooCommerce 9.9.5/10.9.4 source and live fresh-page rendering. |
| REST and hydration hook names/signatures | **Correct** | Both filter pairs exist with the registered argument counts in the tested versions. |
| Request-depth approach fixes hydrated and fetched responses | **Correct on normal paths** | Label appeared after Store API mutation and after a fresh hydrated page load. |
| `key`/`name` plus `value`/`display` fixes presentation | **Correct** | Live DOM contained `Free gift: BOGO promotion`. |
| Four new unit tests prove browser presentation | **Not claimed, and still not proved by them** | The response correctly says a live check remains necessary; this review supplies it. |
| The label issue is fixed | **Yes in source; no in the packaged ZIP** | Worktree passes live checks; ZIP contains the old implementation. |
| Everything needed is fixed | **No** | H-01 blocks checkout on WooCommerce 10.9.4 and M-01 leaves the release artifact stale. |

The depth counter is reasonable for normal web requests. The accompanying
comment that the closing filters “always” run is stronger than the framework
code guarantees if a callback terminates abnormally, but an uncaught fatal or
exception normally ends the PHP request and resets static state. This is not a
release blocker.

## Cart and checkout compatibility

| WooCommerce | Surface | Result |
|---|---|---|
| 9.9.5 | Classic cart | **Works** — previously live-verified; unaffected by the label patch |
| 9.9.5 | Classic checkout | **Works** — previously live-verified; unaffected by the label patch |
| 9.9.5 | Cart block | **Works** — chooser, selection, zero price, quantity lock, and label verified |
| 9.9.5 | Checkout block | **Works** — chooser, checkout form, gift summary, and label verified |
| 10.9.4 | Classic cart | **Works** — chooser, selection, `Free (BOGO)`, zero price, and totals verified |
| 10.9.4 | Classic checkout | **Works** — chooser, checkout form, order review, `Free (BOGO)`, and totals verified |
| 10.9.4 | Cart block | **Works** — selection, fresh hydration, zero price, quantity lock, and label verified |
| 10.9.4 | Checkout block | **Does not work** — chooser renders, but the checkout remains an empty loading shell (H-01) |

Answer to the mode question:

- **Classic cart and checkout:** yes.
- **Cart block:** yes on both tested WooCommerce versions.
- **Checkout block:** yes on WooCommerce 9.9.5; no on WooCommerce 10.9.4 in the
  reviewed code.

The declared header remains `WC tested up to: 9.9`. The 9.9.5 result supports
that declaration. The current-version failure means the header should not be
advanced until H-01 and an appropriate release matrix are completed.

## Quality assessment

### Code quality

The plugin remains well separated into settings, qualification, cart mutation,
frontend rendering, AJAX, and Blocks integration. Shared mutation methods,
escaping, naming, and comments are generally strong.

The main weakness is framework integration testing. The label regression and
the new checkout-root collision both passed unit tests because the stubs model
the plugin's callbacks, not WooCommerce's competing filters or browser mount
logic.

**Assessment:** good internal structure, but not release-grade verification at
framework boundaries.

### Performance

Paged catalogue access, bounded search, and cached curated eligibility are
substantial improvements. No new performance blocker was found. The known
limitations are inaccurate unfiltered browse totals, a capped search universe,
and cold O(N) curated-list hydration.

**Assessment:** acceptable for typical stores; profile unusually large or
frequently imported catalogues.

### Security

Positive controls remain in place:

- AJAX nonces;
- input sanitization;
- server-side qualification, product-scope, purchasability, stock, and quantity
  validation;
- server-enforced zero pricing;
- self-healing removal/resizing;
- escaped frontend output;
- Store API errors through WooCommerce's route-exception mechanism;
- no sensitive data in public extension fields.

No critical/high injection, authorization, arbitrary free-product, or data
exposure issue was found. The open exception-safety guard is a low-severity
robustness concern, not an observed privilege or pricing bypass.

**Assessment:** strong for the plugin's scope.

### Stated objectives

The business engine accomplishes the stated BOGO behavior: independently
configurable Buy/Get scopes, repeating and non-repeating quantities, a real
zero-priced inventory-bearing gift line, stock-aware atomic selection,
revalidation, paging, and name/SKU search.

The source label objective is now met. The complete “supports Cart and Checkout
Blocks” objective is met on WooCommerce 9.9.5 but not on WooCommerce 10.9.4.
The installable ZIP also does not contain the claimed label fix.

**Assessment:** core promotion behavior is sound; current-version block checkout
and release packaging prevent a production-ready verdict.

## Release recommendation

Do not publish the current v1.2.0 ZIP as the fixed release.

Required before release:

1. Fix H-01 by ordering chooser injection after WooCommerce decorates the real
   block root, and retain an integration regression test.
2. Rerun the block cart and checkout flows on WooCommerce 9.9.5 and a current
   release.
3. Rebuild `dist/bogo-select-1.2.0.zip` from the exact passing state and compare
   packaged runtime files with the worktree.
4. Add the ZIP-based Store API/browser smoke test to the release process.

Recommended follow-up:

5. Decide whether All Products browse totals are an accepted limitation.
6. Make the validation guard exception-safe.
7. Commit the v1.2.0 work as one reviewable release state; the reviewed tree is
   still a large uncommitted change set with important new files untracked.

## Authoritative references

- [WooCommerce 10.9.4 `BlockTypesController::add_data_attributes()`](https://github.com/woocommerce/woocommerce/blob/10.9.4/plugins/woocommerce/src/Blocks/BlockTypesController.php#L287-L324)
- [WooCommerce 10.9.4 hydration filters](https://github.com/woocommerce/woocommerce/blob/10.9.4/plugins/woocommerce/src/Blocks/Domain/Services/Hydration.php#L156-L188)
- [WooCommerce: updating the cart on demand](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-update-cart/)
- [WooCommerce: exposing data through Store API schemas](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/)
