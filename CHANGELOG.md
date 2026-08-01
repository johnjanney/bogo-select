# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Two variations of one product, each listed individually, are no longer both
  shown as chosen** (`CODEX-REVIEW.md` M-01). Such cards share a parent, and the
  selected-state comparison looked only at the parent — so picking one marked
  both, and because a chosen card shows only "Selected" and "Remove", the
  customer was left with no control anywhere on the page for switching to the
  other. Which card owns the selection is now decided once, where the whole list
  is visible, with a variation listed in its own right taking precedence over the
  parent card that could also offer it.

### Changed

- **A page of variable rewards costs far fewer product loads**
  (`CODEX-REVIEW.md` M-02). Rendering asked for the same variation through four
  separate paths: judging the parent a card, enumerating its selector, pricing
  each option, and quoting the card. On a page of 24 variable products with 20
  variations each that was 2,016 product loads against 504 distinct products;
  it is now 552. A test holds the ratio so it cannot drift back.

### Added

- **Shipping behaviour is covered, answering `OPEN-QUESTIONS.md` Q-004.** That
  question had stated from the beginning that a free reward adds weight to the
  parcel but nothing to order value, and nothing had ever checked it — every
  other fixture uses virtual products so that shipping stays out of the way.
  Measured now, in both modes: a free reward leaves the order value untouched and
  cannot carry a customer over a free-shipping threshold, a discounted one adds
  its own reduced price and can, and either joins the parcel.

- **A real order is now placed in CI, and its metadata and stock asserted**
  (`CODEX-REVIEW.md` M-03). The plugin writes reward metadata on
  `woocommerce_checkout_create_order_line_item`, a hook nothing in CI had ever
  fired — `BRIEF.md` §8.6 listed order placement, order metadata, and stock
  reduction as verified by hand. A new lane builds a qualifying cart over the
  Store API, checks out through it, and then inspects what landed: the reward
  line and its quantity, the discounted line total, both meta keys, the visible
  label, and stock down by the awarded quantity rather than by one.

- **The classic cart and checkout are covered in a real browser.** Every browser
  assertion until now ran against the Cart and Checkout blocks, because that is
  what WooCommerce provisions on a fresh install — but the shortcode path is
  different code on both sides, reaching the page through template hooks rather
  than the `render_block` filter and choosing over admin-ajax rather than the
  Store API. The reward is chosen by clicking the button, so the JavaScript and
  the reload that classic mode performs are covered too, along with the badge,
  the discounted price, the locked quantity, and the checkout slot being marked
  so it never reloads a part-filled form.

- **Tax on a discounted reward is covered, in both display modes.** The plugin
  sets the line's price before totals run, so tax should follow the discounted
  figure rather than the price it was discounted from — the difference a store
  would feel, and never checked until now. A store whose prices exclude tax
  charges 10.00 plus tax on a 20.00 reward at half price; one whose prices
  include tax charges exactly 10.00, tax included. Both are asserted, along with
  the rate being applied to the discounted line rather than the original.

- **Coupon behaviour alongside a reward is now tested rather than reasoned.**
  The 1.3.0 notes said eligible coupons stack on the strength of where the
  pricing hook sits, since the unit stubs have no coupon support. Both halves are
  now covered against a real store: an eligible 20% coupon over a 50% reward
  leaves the customer paying 40% of list, and a coupon excluding the reward
  leaves it untouched while still discounting the rest of the cart.

- **`tests/README.md` describes what is actually run.** It had gone stale in the
  opposite direction, still saying there was no database, HTTP, WordPress
  install, JavaScript runner, or real block rendering — all of which the
  integration job has done since 1.2.1. It now covers both suites and is
  explicit about what neither reaches.

## [2.0.0] — 2026-07-31

A single change, and a breaking one: the WooCommerce floor is raised to
match what is actually tested. No functional change to the plugin.

### Changed

- **BREAKING: WooCommerce 9.9 or later is now required**, up from 7.0
  (`DECISION.md` D-018, answering `CODEX-REVIEW.md` M-02). The old floor was
  never tested — the integration matrix has only ever run 9.9.5 and current, and
  the unit stubs model this plugin's callbacks rather than WooCommerce's, so
  nothing established that 7.0 through 9.8 preserve the Store API, block
  rendering, hydration, and price-display contracts the plugin relies on. A
  declared minimum is a promise about what someone can install on, and that one
  was unbacked.

  Stores on 7.0–9.8 can no longer install this version, and any already running
  it will find it inert after upgrading, with an admin notice explaining why.
  They can remain on 1.3.0, which is unaffected and stays published.

  The header declares `9.9` while CI pins `9.9.5`, so 9.9.0–9.9.4 are claimed but
  not exercised — a far smaller gap than the one this closes.

## [1.3.0] — 2026-07-31

Two features that widen what the reward can be: it no longer has to be
free, and it no longer has to be a simple product.

### Added

- **Variable products can be rewards, with the customer choosing the variation**
  (`DECISION.md` D-017, which supersedes D-006 and answers `OPEN-QUESTIONS.md`
  Q-003). Add a variable product to the Get list and its card carries a dropdown
  of every variation that can be given, each with its own price and, where it
  cannot, the reason. Add a single variation instead and the reward is pinned to
  it with no choice shown. The Buy side already counted variations and is
  unchanged.

  D-006 refused these because "one free t-shirt" does not say which size. The
  answer turned out to be to ask, which is what a chooser exists to do. Its
  reasoning survives for grouped and external products, and for a variation that
  leaves an attribute set to "Any" — that still needs a choice making, so it is
  not offered.

  The card is one card per product, never one per variation, so a catalogue of
  fifty variable products stays fifty cards. It quotes a variation rather than
  the parent, whose price is the low end of a range and need not match any of
  them. Where variations share a parent's stock record they compete with each
  other, and the dropdown says so against the option rather than leaving the
  customer to work it out.

  Internally a reward is no longer one product ID but a product and variation
  pair, because a variation's cart line stores its parent in `product_id` and the
  two are otherwise indistinguishable. The Store API reports both, additively.

- **An integration scenario for the variable reward.** The block job seeds a
  variable product priced differently per variation, chooses one through the
  Store API, and asserts the line is charged from the chosen variation rather
  than the parent's range, that the parent alone is refused, and that the cart
  renders one selector listing both options.

- **The reward can be discounted rather than only given away** (`DECISION.md`
  D-016, answering `OPEN-QUESTIONS.md` Q-008). "Buy 2, get 1 at 50% off" is now
  as configurable as "get 1 free", through a *Reward price* control on the
  settings screen and a percentage field beside it. Two new settings keys carry
  it, `get_discount_type` and `get_discount_value`, both defaulting to the free
  behaviour — an option row saved before this release reads back as a free gift,
  so nothing changes for an existing store until someone changes it.

  The reward's price is worked out from a product loaded fresh on every pricing
  pass rather than from the cart line's own product object. WooCommerce
  recalculates totals more than once in some requests, and setting a price to
  zero survives that where taking half off does not: reading back its own output
  would compound the discount to a quarter, then an eighth. The cost of the
  approach is that a price another plugin set on the cart item is overwritten
  rather than discounted, which matters to stores running dynamic pricing.

  The discount comes off the effective selling price, so a reward already on
  sale is discounted from its sale price. Eligible coupons still apply on top,
  as they do to any reduced price — a 20% coupon over a 50% reward leaves the
  customer paying 40% of list, where that coupon's own product, category, and
  sale-exclusion rules allow it at all. That follows from where the pricing hook
  sits rather than from a test; the unit stubs have no coupon support.

  Fixed-amount discounts were deliberately left out. A percentage is linear, so
  it needs no clamping against negative prices and raises no per-unit versus
  per-line question; "$5 off" can be added later on the same field.

- **An integration scenario for the discounted reward.** The block job now
  switches the seeded store to 50% off and runs a second browser pass, asserting
  through the Store API that WooCommerce charges the discounted figure, that it
  was not applied twice, and that the cart says "Discounted item" where it used
  to say "Free gift".

- **An automated WordPress + WooCommerce integration job** (`CODEX-REVIEW.md`
  M-02). CI now installs the built zip into a real WordPress with WooCommerce —
  the compatibility floor (9.9.5) and whatever is `latest` — seeds a store, and
  drives the Cart and Checkout blocks in headless Chromium. It asserts the two
  things the unit suite structurally cannot: that the chooser slot never takes
  the block root's `data-block-name`, and that each block leaves `is-loading`
  and renders — a real checkout form, both cart lines, and the visible gift
  label, plus the Store API's zero price, locked quantity, and label members.

  This is the gap that let H-01 ship: 127 unit tests passed while the Checkout
  block was unusable. The job was rehearsed against a live store before
  landing — 28/28 checks pass, and reverting the injection priority to 10 turns
  10 of them red, checkout included. Because the matrix includes `latest`, a
  future WooCommerce that breaks the blocks now fails CI rather than a customer's
  store.

  Classic cart and checkout, stock reduction, and order placement remain manual
  (BRIEF.md §8.6).

### Changed

- **Customer-facing wording follows the offer instead of assuming it is free.**
  Roughly twenty strings across the cart, chooser, notices, and block metadata
  ask the engine what the reward costs rather than hardcoding "free". Every one
  of them is byte-identical to the previous wording while the reward is free, so
  a store that never configures a discount sees no change at all.

- **Order lines record the offer that produced them.** A hidden
  `_bogo_select_discount` meta stores `free` or `percent:50` as it stood when
  the order was placed, because the settings can move afterwards and an order
  has to be able to explain its own pricing. The existing `_bogo_select_free`
  flag keeps its name and value on discounted lines — it is a persisted key that
  existing reports query, and now marks a line as the offer's rather than
  claiming it was free.

## [1.2.1] — 2026-07-31

Compatibility metadata only. No functional change: 13 of the 14 runtime files
are byte-identical to 1.2.0, and the fourteenth — `bogo-select.php` — differs
only in the version string and the `WC tested up to` header. Upgrading changes
nothing a customer can see.

### Changed

- **`WC tested up to` advanced from 9.9 to 10.9.** The previous review declined
  to advance it while the Checkout block was broken on current WooCommerce, and
  1.2.0 shipped the fix without the matrix that would justify the claim. That
  matrix has now been run: a disposable WordPress 7.0.2 / PHP 8.2 / MariaDB
  10.11 stack with Twenty Twenty-Five, the plugin installed **from the 1.2.0
  zip** rather than from source, exercised in a real browser on WooCommerce
  9.9.5 and 10.9.4 — 10.9.4 being the current release. All four block surfaces
  pass: the chooser renders, the block root keeps its own `data-block-name`,
  the Cart and Checkout blocks mount and leave `is-loading`, the checkout
  renders contact, address, and Place Order, the gift line reads `$10.00 →
  $0.00` against an unchanged cart total, and `Free gift: BOGO promotion` is
  visible in the order summary. No JavaScript errors.

  H-01 was confirmed causally rather than by coincidence: toggling only the
  injection priority between 10 and 20 on the installed plugin reproduced the
  empty loading shell at 10 and a working checkout at 20, on WooCommerce
  10.9.4, with nothing else changed.

  Two limits are worth recording. WooCommerce did not preload a cart response
  into the page in this configuration, so the blocks fetched it after load and
  the hydration half of the label fix was never observed running — it remains
  covered by source reading and unit tests only. And with no payment gateway
  configured, checkout was verified up to a populated Place Order form rather
  than a completed order.
- **README records the tested versions** alongside the minimum requirements.

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

[Unreleased]: https://github.com/johnjanney/bogo-select/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/johnjanney/bogo-select/compare/v1.3.0...v2.0.0
[1.3.0]: https://github.com/johnjanney/bogo-select/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/johnjanney/bogo-select/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/johnjanney/bogo-select/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/johnjanney/bogo-select/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/bogo-select/releases/tag/v1.0.0
