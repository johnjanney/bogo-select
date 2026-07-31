# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

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
