# BOGO Select for WooCommerce

Let customers **choose** their reward. A configurable "Buy X, Get Y free — or at
a percentage off" promotion for WooCommerce where the reward is added to the cart
as a real line item at its reward price — so stock is still reduced.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-9.9%2B-96588a)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE)

---

## What it does

1. A customer adds a qualifying quantity of products to the cart.
2. A chooser appears on the cart or checkout page: *"Choose your free gift —
   pick 1 of 3."*
3. They pick one product. It is added to the cart at the configured Get quantity,
   priced at $0.00 — or at a percentage off, if the offer is set that way.
4. On checkout, WooCommerce reduces stock for the reward exactly as it would for
   a paid item, whether it was free or discounted.

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
- **Real inventory reduction.** The reward is a normal cart/order line item at
  its reward price, not a coupon discount, so stock, reports, and packing slips
  all behave.
- **Free, or a percentage off.** The reward is given away by default, and can
  instead be sold at any percentage discount — "Buy 2, get 1 at 50% off".
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
| WooCommerce | 9.9 or later |
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
5. **Get products** = *Select Products* → add the products the customer may
   choose between, and set **Reward price** to *Free* or a percentage off
6. **Save changes**

Add two of anything to the cart and the chooser appears.

## How the discount works

The reward line is not a coupon. On every `woocommerce_before_calculate_totals`
pass, its price is set outright — to zero for a free gift, or to the discounted
figure when the offer is a percentage off:

```php
$cart_item['data']->set_price( BOGO_Select_Engine::reward_price( $base ) );
```

This keeps the item a first-class product line — it counts for stock, appears on
the order, and is taxed on what it actually costs rather than discounted after
tax. See [`DECISION.md`](DECISION.md) §D-002 for why this was chosen over a
dynamic coupon, and §D-016 for the discount.

`$base` is read from a product loaded fresh on every pass, never from the cart
line's own product object. WooCommerce recalculates totals more than once in some
requests, and a percentage applied to its own output would compound. The cost is
that a price another plugin set on the cart item is overwritten rather than
discounted, which matters on stores running dynamic pricing.

Three consequences worth knowing before switching an offer to a percentage:

- **The discount comes off the effective selling price.** A reward already on
  sale is discounted from its sale price, so a 50%-off reward on a product
  already at 40% off costs 30% of list.
- **Eligible coupons stack.** A 20% site-wide coupon over a 50% reward leaves
  the customer paying 40% of list, as it would on any reduced price — subject to
  that coupon's own product, category, and sale-exclusion rules, which still
  apply. Both halves are covered by the integration job against a real store:
  that an eligible coupon compounds, and that one excluding the reward leaves it
  alone while still discounting the rest of the cart.
- **A discounted reward has taxable value.** Unlike a free one it contributes to
  the order subtotal, so it counts toward free-shipping thresholds by value as
  well as by weight.

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
- Variable products can be rewards: list the product and the customer picks the
  variation in the chooser, or list one variation to pin the reward to it.
  Grouped and external products cannot, nor can a variation that leaves an
  attribute set to "Any" — it would still need a choice. On the Buy side a
  variation has always counted, matched by its own ID or its parent's.
- Cart and checkout are covered by an automated integration job: CI installs the
  built zip into a real WordPress with WooCommerce (the compatibility floor and
  the current release) and exercises the Cart and Checkout **blocks** and the
  **classic** shortcode pages in a headless browser, along with placing a real
  order and checking its metadata and stock reduction. Untested: third-party
  pricing plugins, shipping (every fixture product is virtual), and currencies
  whose minor unit is not two digits.
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
