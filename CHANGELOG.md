# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] — 2026-07-30

Addresses the follow-up Codex review (`CODEX-REVIEW.md`) and its central
conclusion: the plugin worked for classic carts and did nothing useful on a
block store. Responses are recorded in `CODEX-REVIEW-RESPONSE.md`.

### Added

- **Cart and Checkout Blocks support.** The chooser renders ahead of the
  `woocommerce/cart` and `woocommerce/checkout` blocks, offer state travels on
  the Store API cart response, and choosing or removing a gift goes through a
  registered Store API update callback, so the blocks re-render from the
  response they already trust — no page reload, and a half-filled block
  checkout survives. The gift line is labelled through `woocommerce_get_item_data`
  and its quantity locked through the Store API's own quantity limits, because
  the classic name and quantity filters never run inside a block. The
  `cart_checkout_blocks` compatibility declaration is now `true`.
- **The chooser on the checkout page**, classic and block alike. A customer who
  goes straight to checkout without visiting the cart previously had nowhere to
  pick their gift. On classic checkout the page is never reloaded — that would
  empty a part-filled form — so the chooser re-renders and WooCommerce is asked
  to update the order review instead.
- **A chooser that keeps up with a block cart.** The markup now sits in a slot
  element the script can replace wholesale, and in block mode the script follows
  the `wc/store/cart` data store: crossing the qualifying threshold makes the
  chooser appear, and dropping below it makes the chooser go away, without a
  page load. A new `bogo_select_refresh` AJAX endpoint returns the re-rendered
  chooser.
- **`bogo_select_choice_ids`**, a page-aware filter receiving the scope, search
  term, page, and page size alongside the IDs. `bogo_select_get_products` is
  unchanged and still applied per page. (C-04)
- **`bogo_select_search_limit`** (default 200), capping how many matches a gift
  search inspects, and **`bogo_select_eligibility_ttl`** (default 600 seconds)
  for the new eligibility cache.

### Fixed

- **The block checkout renders again on current WooCommerce** (`CODEX-REVIEW.md`
  H-01). The chooser was injected on `render_block` at priority 10, and
  WooCommerce's own `BlockTypesController::add_data_attributes()` is also a
  priority-10 `render_block` filter that walks to the *first tag* of the content
  it is handed and stamps `data-block-name` on it. Which callback ran first was
  decided by plugin load order; losing that coin toss meant WooCommerce branded
  the BOGO slot `data-block-name="woocommerce/checkout"` and left the real
  checkout root unbranded, so the Checkout frontend mounted against an empty div
  and the customer got a permanent loading shell with no address, order summary,
  or payment. Injection now runs at priority 20, after WooCommerce has decorated
  the original block root, which makes the ordering explicit rather than
  incidental. Observed on WooCommerce 10.9.4; the reproduction did not depend on
  a gift being selected, or on the cart qualifying at all.
- **A failed validation pass no longer disables validation for the rest of the
  request** (`CODEX-REVIEW.md` L-01). `BOGO_Select_Cart::validate()` raised its
  re-entrancy guard, called `run_validation()`, and lowered the guard on the
  line after. Since that pass removes cart items and changes quantities, an
  extension observing either hook could throw straight past the reset and leave
  the guard stuck on, so every later pass in that request returned early and
  unearned gifts stayed in the cart. The guard is now cleared in `finally`,
  matching the exception-safe suspend/resume path.
- **The "Free gift: BOGO promotion" label now actually appears in the block
  cart and block checkout** (`CODEX-REVIEW.md`, first round M-01). The label is supplied
  through `woocommerce_get_item_data`, which only spoke up when WooCommerce
  reported a Store API request. A block cart paints its first frame from a
  cart response WooCommerce builds *inside the page request* and preloads into
  the markup, and during that build `REQUEST_URI` is still `/cart/`, so
  `WC()->is_store_api_request()` answered no and the row came back empty. The
  plugin now brackets the response build itself — through
  `rest_request_before_callbacks`/`rest_request_after_callbacks` for a
  dispatched Store API route and
  `woocommerce_hydration_dispatch_request`/`woocommerce_hydration_request_after_callbacks`
  for the preloaded one — so both the preloaded and the fetched cart carry the
  label. The entry also sets `name` alongside `key` and a non-empty `display`
  alongside `value`, because which member the blocks read has moved between
  WooCommerce versions and the previous empty `display` blanked the row on the
  versions that prefer it.
- **Searching gifts by SKU now searches SKUs.** *All Products* search passed the
  term to `wc_get_products()` as `s`, which WordPress resolves against the post
  title, excerpt, and content and never the SKU — so a search that matched only
  a SKU found nothing, contrary to what the search box, README, and acceptance
  criteria all promised. Search now goes through WooCommerce's product data
  store (`search_products()`, the call behind the admin product search), which
  covers name, description, and SKU, with a two-query fallback where that store
  is unavailable. (C-01)
- **The unit stub no longer certified the bug.** `wc_get_products()` in the test
  stubs pretended `s` matched SKUs, which is why a broken SKU search passed its
  own test in 1.1.0. The stub now follows core semantics — `s` for keywords,
  `sku` for SKUs — and the data store's search is stubbed separately. (C-01)
- **Validation can no longer be left suspended.** The suspend/resume pair around
  a gift swap is now closed by `try`/`finally`, so an exception thrown by a
  third-party add-to-cart callback cannot leave the next request unguarded.

### Changed

- **A curated gift list is no longer hydrated in full on every request.**
  Searching *Select Products* is done in the database, constrained to the
  configured IDs, and the eligibility of that list is cached until the settings
  or any product are saved. (C-03)
- **A search total no longer counts gifts that cannot be given.** Totals for a
  search are taken after the eligibility gate. Browsing *All Products* without a
  search still reports the catalogue total, which is what bounds the query. (C-04)
- **Unit suite grown from 71 tests / 146 assertions to 131 / 259**, adding cover
  for SKU search, the eligibility cache, the page-aware filter, gift selection
  and replacement, chooser rendering, and the block integration. The last two
  tests are ordering and exception-safety regressions that a direct call to the
  method under test cannot catch: one drives the whole `render_block` chain with
  a stand-in for WooCommerce's `add_data_attributes()` and asserts the chooser
  slot never takes the block root's identity (H-01), the other throws from a
  filter mid-validation and asserts the next pass still runs (L-01).
- **`bin/verify-zip.sh`, and a CI job that runs it** (`CODEX-REVIEW.md` M-01,
  M-02). The v1.2.0 archive was built before its own fix landed and shipped a
  superseded class while every test passed, because nothing compared the package
  with the source. The script now requires every runtime file in the worktree to
  appear in the archive with an identical SHA-256, CI builds and verifies on
  every push, and the step is a documented release gate (BRIEF.md §8.5).
- **Two accepted limitations are now written down** rather than implied
  (`CODEX-REVIEW.md` M-03, L-02). Browse totals in *All Products* mode are
  catalogue counts taken before the eligibility gate, so the count can overstate
  what is selectable and a page can come back short while eligible products wait
  on later pages; and a gift search reports the eligible products among the
  first 200 matches, not among every match. Both are documented in README.md
  under *Limitations* and at the code that implements them. Curating a list with
  *Select Products* gives exact counts.

## [1.1.0] — 2026-07-30

Addresses the findings of the Codex repository review (`CODEX-REVIEW.md`); the
responses are recorded in `CODEX-REVIEW-RESPONSE.md`.

### Added

- **Searchable, paged gift chooser.** Options are fetched a page at a time (24 by
  default) with a name/SKU search box and Previous/Next controls, over a new
  public `bogo_select_choices` AJAX endpoint. Both Get scopes are paged, so a
  *Select Products* list of any length is bounded too. (F-02)
- **`bogo_select_all_products_limit` is now the page size** rather than a hard cap
  on the *All Products* scope. The filter name is unchanged.
- **WooCommerce dependency enforcement**: a `Requires Plugins: woocommerce` header
  and an activation guard that refuses activation without WooCommerce 7.0 or
  later. (F-05)
- **PHPUnit unit suite** (`tests/`) covering settings normalization, qualification,
  eligibility, availability, chooser paging, and cart validation, plus a GitHub
  Actions workflow running it on PHP 7.4–8.3. (F-06)

### Fixed

- **A gift is no longer kept when its stock runs out.** Availability was only
  rechecked when the earned quantity changed, so a cart whose quantity was stable
  kept an unbuyable gift until checkout failed. It is now rechecked on every
  validation pass, and on `woocommerce_cart_item_restored`. (F-01)
- **Paid and free copies of one product no longer over-commit its stock.**
  Availability counts total cart demand against the stock-managed product ID, so 2
  paid plus 2 free of a product with 3 in stock is refused up front. (F-01)
- **Products past the fiftieth are reachable again.** *All Products* previously
  offered only the first 50 simple products by title, with no way to reach the
  rest. (F-02)
- **Changing your gift can no longer leave you with none.** The replacement is
  added before the previous gift is removed; if the add is refused — by core stock
  validation or a third-party `woocommerce_add_to_cart_validation` callback — the
  original gift stays in the cart and the reason is reported. (F-03)
- **Duplicate free lines are normalised.** Validation enumerates every flagged
  line, keeps one, and removes the rest; previously only the first was inspected
  while pricing zeroed them all. (F-04)
- **Multi-unit gift subtotals show the right figure.** The subtotal column struck
  through one unit's price; eight $10 gifts now strike through $80, not $10. (F-07)
- **Product variations are rejected as gifts** by both the eligibility check and
  the settings sanitizer, matching the documented "simple products only" rule. (F-08)

### Changed

- The unused random `bogo_select_stamp` cart item datum was removed. It was never
  validated, and being random it prevented identical gift lines from merging.
  Re-validation derives everything from current settings and current cart state;
  `BRIEF.md` §4.3 now describes that instead of the unimplemented
  `_bogo_select_token`. (F-04)
- Release archives exclude `tests/`, `vendor/`, `composer.*`, `phpunit.xml.dist`,
  and `.github/`.
- Documentation corrected where it had drifted from the code: cart flag key,
  AJAX endpoint security model, notice placement on cart/checkout, and the
  WooCommerce dependency lifecycle. New decisions D-011, D-012, and D-013 record
  the design choices behind the fixes above. (F-08)

## [1.0.0] — 2026-07-30

Initial release.

### Added

- **Admin settings screen** at *WooCommerce → BOGO Select*: enable/disable, offer
  title, Buy quantity, Get quantity, Buy scope, Get scope, repeat mode, and a
  toggle for the site-wide qualification notice.
- **Independent Buy and Get scopes**, each set to *All Products* or *Select
  Products* with its own AJAX-searched product list.
- **Arbitrary Buy/Get quantities** — any positive integers (Buy 2 Get 2,
  Buy 4 Get 8, …).
- **Customer gift chooser** on the cart page: product cards with image, name,
  price, stock state, and Select/Change actions, added via AJAX.
- **$0.00 reward line items** — the chosen gift is added as a real cart line with
  its price overridden to zero, so WooCommerce reduces stock normally.
- **Continuous cart re-validation** — the gift is removed or its quantity adjusted
  automatically when the cart stops qualifying, the offer changes, or stock runs
  short, with an explanatory customer notice.
- **Quantity lock** on gift line items (removal still permitted).
- **Server-side re-validation** of every selection, and again on
  `woocommerce_check_cart_items` before checkout, so a crafted request cannot
  award a free product.
- **Repeat mode** (off by default) awarding one gift set per multiple of the Buy
  quantity.
- **"Free (BOGO)" labelling** on cart lines, checkout, order emails, and the admin
  order screen.
- **Developer hooks**: `bogo_select_get_products`, `bogo_select_qualifies`,
  `bogo_select_reward_quantity`, `bogo_select_reward_added`.
- **HPOS compatibility** declaration for WooCommerce High-Performance Order Storage.
- **Uninstall routine** removing plugin options.
- Project documentation: `README.md`, `BRIEF.md`, `INSTRUCTIONS.md`,
  `DECISION.md`, `OPEN-QUESTIONS.md`, and this changelog.

### Known limitations

- Classic (shortcode) cart and checkout only — Cart/Checkout **blocks** show the
  notice but not the chooser. See `OPEN-QUESTIONS.md` Q-001.
- One offer at a time; offers do not stack.
- Product IDs only — no category, tag, or attribute scoping.
- Variable products cannot be gifts (they remain eligible on the Buy side).
  See `DECISION.md` D-006.
- Untested against Subscriptions, Bundles, and Composite Products.

[Unreleased]: https://github.com/johnjanney/bogo-select/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/johnjanney/bogo-select/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/bogo-select/releases/tag/v1.0.0
