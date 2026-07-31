# BOGO Select for WooCommerce

Let customers **choose** their free gift. A configurable "Buy X, Get Y free"
promotion for WooCommerce where the free product is added to the cart as a real
line item at **$0.00** — so stock is still reduced.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE)

---

## What it does

1. A customer adds a qualifying quantity of products to the cart.
2. A chooser appears on the cart or checkout page: *"Choose your free gift —
   pick 1 of 3."*
3. They pick one product. It is added to the cart at the configured Get quantity,
   priced at $0.00.
4. On checkout, WooCommerce reduces stock for the free item exactly as it would
   for a paid one.

## Features

- **Any quantities.** Buy 1 Get 1, Buy 2 Get 2, Buy 4 Get 8 — any positive integers.
- **Independent Buy/Get scopes.** Each side is set to *All Products* or *Select
  Products* with its own list. Mix freely: Buy = all products, Get = two specific SKUs.
- **Searchable, paged chooser.** Long gift lists are paged 24 at a time with a
  search over name, description, and SKU — run by WooCommerce's own product
  search — so *All Products* reaches the whole catalogue without loading it all at
  once.
- **Classic and block, cart and checkout.** The chooser renders above the classic
  cart table and checkout form, and above the Cart and Checkout blocks. In a block
  cart it follows the Store API: picking a gift updates the blocks in place, and
  the chooser appears the moment the cart qualifies, without a page reload.
- **Real inventory reduction.** The gift is a normal cart/order line item at $0.00,
  not a coupon discount, so stock, reports, and packing slips all behave.
- **Customer picks, and can change their mind.** Swapping the gift adds the new
  line before dropping the old one, so a refused swap never leaves them empty-handed.
- **Self-healing cart.** If the cart drops below the Buy quantity — or the gift
  sells out while it's sitting there — it's removed automatically with an
  explanatory notice.
- **Tamper-resistant.** Every selection is re-validated server-side, and again
  before checkout — a crafted AJAX request cannot mint a free product.
- **Optional repeat mode.** Award one gift set per multiple of the Buy quantity
  (Buy 2 Get 1 → 6 in cart awards 3 free).
- **No build step.** Plain PHP, CSS, and vanilla JS. Drop it in and activate.
- **HPOS compatible.** Declares support for WooCommerce High-Performance Order Storage.

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| WooCommerce | 7.0 or later |
| PHP | 7.4 or later |
| Tested with | WooCommerce 9.9.5 and 10.9.4 — classic cart and checkout, plus the Cart and Checkout blocks verified in a browser |

## Installation

Download or clone this repository into `wp-content/plugins/bogo-select`, then
activate **BOGO Select for WooCommerce** in *Plugins*.

Full instructions, including configuration walkthroughs and troubleshooting, are in
[INSTRUCTIONS.md](INSTRUCTIONS.md).

## Quick start

1. **WooCommerce → BOGO Select**
2. Tick **Enable offer**
3. Set **Buy quantity** = `2`, **Get quantity** = `2`
4. **Buy products** = *All Products*
5. **Get products** = *Select Products* → add the products you want to give away
6. **Save changes**

Add two of anything to the cart and the chooser appears.

## How the discount works

The free line item is not a coupon. On every `woocommerce_before_calculate_totals`
pass, the reward line's price is set to `0`:

```php
$cart_item['data']->set_price( 0 );
```

This keeps the item a first-class product line — it counts for stock, appears on
the order, and is taxed on $0.00 rather than discounted after tax. See
[`DECISION.md`](DECISION.md) §D-002 for why this was chosen over a dynamic coupon.

## Project layout

```
bogo-select.php              Bootstrap: constants, requirement checks, loader
uninstall.php                Removes plugin options on delete
includes/
  class-bogo-select.php      Wiring and singleton
  class-bogo-settings.php    Option read/write, defaults, sanitization
  class-bogo-engine.php      Qualification logic (pure, testable)
  class-bogo-cart.php        Cart hooks: $0 pricing, qty lock, revalidation
  class-bogo-frontend.php    Chooser UI, notices, asset enqueue
  class-bogo-ajax.php        Front-end select/remove/refresh endpoints
  class-bogo-blocks.php      Cart/Checkout Blocks: injection, Store API, limits
  class-bogo-admin.php       Settings screen + product search
assets/
  css/bogo-select.css        Front-end chooser styles
  css/bogo-select-admin.css  Settings screen styles
  js/bogo-select.js          Chooser interactions, paging, search
  js/bogo-select-admin.js    Scope toggles, product pickers
tests/                       PHPUnit unit suite (not shipped in the zip)
bin/build-zip.sh             Versioned release build
```

## Tests

```bash
composer install
composer test
```

Unit tests run against small WordPress/WooCommerce stand-ins — no WordPress
install needed. See [tests/README.md](tests/README.md) for what is and is not
covered.

## Documentation

| Document | Contents |
|---|---|
| [BRIEF.md](BRIEF.md) | Requirements, scope, functional and technical spec, acceptance criteria |
| [INSTRUCTIONS.md](INSTRUCTIONS.md) | Installation, configuration, usage, troubleshooting |
| [CHANGELOG.md](CHANGELOG.md) | Version history |
| [DECISION.md](DECISION.md) | Decisions made during development without stopping to ask |
| [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | Unresolved questions and their resolutions |

## Hooks for developers

```php
// Change the products offered in the chooser (runs per page of results).
add_filter( 'bogo_select_get_products', function ( $product_ids ) { … } );

// The same, but told which page it is looking at: scope, search, page, per_page.
add_filter( 'bogo_select_choice_ids', function ( $product_ids, $context ) { … }, 10, 2 );

// Change the chooser's page size (default 24).
add_filter( 'bogo_select_all_products_limit', function ( $per_page ) { … } );

// Cap how many matches a gift search inspects (default 200).
add_filter( 'bogo_select_search_limit', function ( $limit ) { … } );

// How long the eligibility of a curated gift list is cached (default 600s).
add_filter( 'bogo_select_eligibility_ttl', function ( $seconds ) { … } );

// Override whether the cart qualifies.
add_filter( 'bogo_select_qualifies', function ( $qualifies, $buy_count ) { … }, 10, 2 );

// Change the awarded quantity.
add_filter( 'bogo_select_reward_quantity', function ( $qty, $buy_count ) { … }, 10, 2 );

// React to a gift being chosen.
add_action( 'bogo_select_reward_added', function ( $product_id, $qty ) { … }, 10, 2 );
```

## Limitations

Known constraints — see [BRIEF.md §3](BRIEF.md) for the full list:

- One offer at a time; offers do not stack.
- Product IDs only — no category, tag, or attribute scoping.
- Variable products cannot be gifts (an ambiguous "one free t-shirt" has no size);
  they remain eligible on the Buy side, matched by variation ID or parent ID.
- The Cart and Checkout **blocks** are covered by an automated integration job: CI
  installs the built zip into a real WordPress with WooCommerce (the compatibility
  floor and the current release) and drives both blocks in a headless browser.
  **Classic** cart and checkout, stock reduction, and order placement are still
  covered by manual staging tests rather than automation.
- Not tested against Subscriptions, Bundles, or Composite Products.
- **Browse counts in "All Products" mode are catalogue counts, not gift counts.**
  Browsing the whole catalogue pages the catalogue and filters each page for
  eligibility afterwards, so the reported total counts publishable simple
  products rather than selectable gifts. A page can therefore show fewer than
  the page size — or nothing at all — while eligible products still wait on a
  later page. Searching does not have this problem: it resolves matches first
  and pages the filtered result, so its total is exact. Curate the gift list
  with "Select Products" when the count needs to be exact.
- **Gift search looks at the first 200 matches.** A search reports the eligible
  products among the first 200 catalogue matches for the term, not among every
  match, so a very broad term on a large catalogue can leave later matches out.
  Narrow the term, or raise `bogo_select_search_limit` from measured
  catalogue data.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
