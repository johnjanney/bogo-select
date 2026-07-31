# Implementation Plan — Variable products as rewards

Lets a variable product be offered as the Get item, with the customer choosing
the variation. Answers `OPEN-QUESTIONS.md` Q-003 and supersedes `DECISION.md`
D-006.

---

## Contents

1. [Scope and decisions taken](#1-scope-and-decisions-taken)
2. [What already works](#2-what-already-works)
3. [The identity change](#3-the-identity-change)
4. [Eligibility](#4-eligibility)
5. [The chooser](#5-the-chooser)
6. [Admin](#6-admin)
7. [Scope, search, and caching](#7-scope-search-and-caching)
8. [Blocks, Store API, and the browser](#8-blocks-store-api-and-the-browser)
9. [Tests](#9-tests)
10. [Documentation](#10-documentation)
11. [Sequencing](#11-sequencing)
12. [What could bite](#12-what-could-bite)

---

## 1. Scope and decisions taken

Three questions were settled before planning:

**The Get list may hold a variable parent or one specific variation.** Listing
the parent offers every usable variation and the customer picks; listing a single
variation pins the reward to that exact thing with no choice shown. Once the
identity plumbing exists the second costs almost nothing, and it is the only way
to express "a free Small tee" without creating a separate product.

**The chooser offers one dropdown of variations per card**, listing each
purchasable variation by name, price, and availability — not one dropdown per
attribute. Attribute dropdowns mirror the single-product page, but they need
WooCommerce's `wc-add-to-cart-variation` matching JS inside a cart-page card and
they have a "no matching combination" dead end. A flat list cannot reach a state
that does not exist, and it gives per-option availability for free.

**Variable products appear in the "All Products" gift scope**, as one card each.
The cardinality objection raised during the investigation only applies if
variations are enumerated as separate cards, which this design never does.

Two further calls, made here rather than asked:

**A variation with an "any" attribute value is not offerable.** Such a variation
matches several combinations, so adding it still requires a choice — the exact
ambiguity D-006 objected to. They are skipped when a parent's variations are
listed, and rejected with a specific message if one is pinned directly.

**Grouped and external products stay ineligible.** Nothing about this work makes
them addable without a further decision, so D-006's rejection of them survives.

---

## 2. What already works

Worth stating, because it narrows the job considerably.

**The Buy side is done.** `is_buy_eligible()` (`includes/class-bogo-engine.php:61`)
matches a cart line on either the variation's own ID or its parent's, and
`count_buy_units()` reads `variation_id` off each line. Verified by running it: 3
units of a variation count under Buy = All Products, under a Buy list naming the
parent, and under a Buy list naming the variation. The product-type filter in
`BOGO_Select_Admin::sanitize()` touches only `get_products`, so variable parents
already survive on the Buy side. No change is needed here.

**Stock accounting is already variation-aware.** `stock_demand()`
(`class-bogo-engine.php:248`) matches on `get_stock_managed_by_id()`, so a
variation that inherits its parent's stock competes against the same pool as the
parent and its siblings.

**The discount work composes.** `BOGO_Select_Cart::line_product()` already
prefers `variation_id` over `product_id`, so `base_price()` and `reward_markup()`
will price and display a variation reward correctly the moment one can exist.
That was written ahead of this work and needs nothing further.

---

## 3. The identity change

This is the substance. A reward is currently one integer, and a variation cannot
be named by one — `$cart_item['product_id']` on a variation line holds the
parent, so two different rewards from the same parent are indistinguishable.

The reward becomes the pair `(product_id, variation_id)`, with `variation_id` of
`0` meaning "not a variation". Attributes are not stored: they are derivable from
the variation at add-to-cart time via `get_variation_attributes()`, and storing a
second copy invites the two to disagree.

Threading that pair through:

- `BOGO_Select_Ajax::select_gift( $cart, $product_id )` gains a `$variation_id`,
  and its `add_to_cart()` call (`class-bogo-ajax.php:124`) passes the parent ID,
  the variation ID, and the derived attributes instead of `0` and `array()`.
- `BOGO_Select_Engine::selected_product_id()` (`class-bogo-engine.php:871`) keeps
  its name and meaning — the parent — and gains a sibling
  `selected_variation_id()`. Keeping the existing accessor intact matters: it is
  what the Store API field of the same name reports.
- `BOGO_Select_Engine::state()` gains `selected_variation_id`, and its signature
  already folds each line's `variation_id` in, so nothing there needs revisiting.
- `BOGO_Select_Cart::run_validation()` (`class-bogo-cart.php:162`) revalidates
  with the pair rather than `is_get_eligible( $cart_item['product_id'] )`.
- The chooser's "is this the selected one" comparison, the card markup, and the
  browser payload all carry the pair — see §5 and §8.

---

## 4. Eligibility

`is_get_eligible( $product_id )` currently answers two different questions at
once, and they come apart as soon as variations exist. Split it:

- **`is_choice( $product_id )`** — may this appear as a card? True for a
  purchasable simple product, for a variable parent with at least one offerable
  variation, and for a pinned variation. This is what the chooser lists.
- **`is_awardable( $product_id, $variation_id )`** — may this exact thing enter
  the cart? A simple product with no variation; or a variation that is
  purchasable, belongs to the parent it claims, carries no "any" attribute, and
  is in scope through either its own ID or its parent's.

The split is what stops a variable parent being added to the cart as itself. It
is a card, and it is never awardable — only its variations are. Keeping
`is_get_eligible()` as a thin wrapper over `is_awardable()` avoids churning the
existing call sites that ask about simple products.

The type rejection at `class-bogo-engine.php:180` narrows to `grouped` and
`external`.

---

## 5. The chooser

A card for a variable parent renders a `<select>` of its offerable variations,
each labelled with its name and price, and each disabled with a reason when it
cannot be awarded at the earned quantity. The Select button reads the chosen
option. Cards for simple products and pinned variations are unchanged apart from
carrying a variation ID of `0` and their own ID respectively.

**Card-level availability becomes an aggregate.** A variable card is unavailable
only when no variation can be awarded — not when the parent object reports itself
out of stock, which for a variable product is a summary and not the whole story.
`unavailable_reason()` already takes any `WC_Product` and needs no change; it is
simply called per variation.

**Enumerate variations cheaply.** `WC_Product_Variable::get_available_variations()`
builds a large array per variation, including image and attribute data, and a
24-card page could call it repeatedly. Prefer `get_children()` and a
`wc_get_product()` per child, reading only name, price, and stock. This is the
main performance risk in the feature and the reason to avoid the obvious API.

---

## 6. Admin

The Get picker (`class-bogo-admin.php:370`) moves from
`woocommerce_json_search_products` to
`woocommerce_json_search_products_and_variations`, so both parents and individual
variations can be chosen.

The sanitizer at `class-bogo-admin.php:86` stops rejecting `variable` and
`variation` and starts rejecting, each with its own message:

- grouped and external products, as now;
- a variable parent with no offerable variation, since it would render a card
  with an empty selector;
- a pinned variation carrying an "any" attribute value, which cannot be added
  without a further choice.

The help text at `class-bogo-admin.php:327` — currently "Simple products only" —
is rewritten, as is `README.md`'s matching claim.

---

## 7. Scope, search, and caching

`page_all_choices()` (`class-bogo-engine.php:474`) and `query_search()` (`:585`)
are pinned to `type => 'simple'` and widen to `array( 'simple', 'variable' )`.
Variations are never enumerated by scope — they reach the list only by being
pinned individually.

`store_search()` (`:566`) passes `include_variations = false`. That stays right
for the "All Products" scope, where only parents should surface, but it is wrong
when the curated list contains pinned variation IDs: a search inside that list
would never match them. The flag needs to follow whether the constraining list
holds any variation.

The eligibility cache (`eligibility_map()`, `:736`) stores a map keyed by
configured ID. Variation IDs are integers too, so the shape survives, but a
variable parent's eligibility now depends on its children's stock — which changes
without the parent being saved. The existing TTL already bounds that staleness
and the selection endpoint still refuses a stale choice, so the failure mode
stays a wasted click rather than a wrong award.

---

## 8. Blocks, Store API, and the browser

- The Store API schema (`class-bogo-blocks.php:193`) gains `selected_variation_id`
  alongside the existing field. Additive, so existing consumers are unaffected.
- The update callback (`:243`) reads a `variation_id` beside `product_id` and
  passes both to `select_gift()`.
- The chooser JS already funnels every mutation through
  `mutate( action, extraData )` (`assets/js/bogo-select.js:289`), and that object
  reaches both the admin-ajax and Store API paths. Adding `variation_id` to the
  payload at `:466` is a small change, reading the card's selector.

---

## 9. Tests

**The stub needs variable products, and does not model them at all today.**
`WC_Product` in `tests/stubs/woocommerce.php` has no type hierarchy beyond an
`is_type()` string compare, no parent link, no children, and no attributes. It
needs `get_parent_id()`, `get_children()`, and `get_variation_attributes()`, and
the test case builder needs a way to declare a parent with variations in one
call. This is the largest test-side piece, comparable to the cart-line clone fix,
and like that one it should land on its own before any feature code depends on
it.

Coverage to add:

- a variable parent is a card, is never awardable as itself, and its variations
  are awardable;
- a pinned variation is a card with no selector and is awardable;
- a variation whose claimed parent is not its real parent is refused — the
  server must not trust the pair it is handed;
- a variation with an "any" attribute is neither listed nor awardable;
- card availability is the aggregate: available while one variation can be
  awarded, unavailable when none can;
- a variable parent with no offerable variation is stripped on save;
- swapping between two variations of the same parent replaces rather than
  duplicates the reward line;
- the discount applies to the chosen variation's own price, not the parent's.

The existing free-path and discount tests must keep passing untouched. If any
needs editing, that is a signal simple-product behaviour moved when it should
not have.

An integration scenario seeding a variable product and choosing a variation
through the Store API is the end-to-end counterpart, following the pattern
`discount.test.mjs` now sets.

---

## 10. Documentation

- `DECISION.md` — a new entry superseding D-006, recording the parent-or-variation
  scope, the flat variation list, and the "any" attribute exclusion. D-006 is
  marked superseded rather than edited.
- `OPEN-QUESTIONS.md` — Q-003 moves to Resolved.
- `README.md:170` and `INSTRUCTIONS.md` §3 and §5, both of which currently state
  that variable products cannot be gifts.
- `CHANGELOG.md` under Unreleased.

---

## 11. Sequencing

Each step leaves the suite green and the plugin shippable.

1. **Stub: variable products.** Parent/child linkage, attributes, and a builder.
   No plugin code.
2. **Eligibility.** The `is_choice` / `is_awardable` split, with the type
   rejection narrowed. Nothing calls the new shape yet.
3. **Identity.** Thread `(product_id, variation_id)` through `select_gift()`,
   cart validation, `state()`, the AJAX endpoint, and the Store API. A pinned
   variation becomes fully workable at this point, without any chooser UI.
4. **The chooser.** The variation selector, per-option availability, and the
   aggregate card state.
5. **Admin.** Picker, sanitizer, warnings, help text.
6. **Scope and search.** Variable products in "All Products", and the
   `include_variations` fix for curated lists holding variations.
7. **Docs**, and the integration scenario.

Steps 1–3 are the substance; step 4 is the only new UI. Step 3 is the natural
place to stop if the work has to be split across releases, because it ships the
pinned-variation half on its own.

---

## 12. What could bite

**Trusting the submitted pair.** The browser sends a parent and a variation. The
server must confirm the variation really belongs to that parent and is in scope
before awarding it, or a crafted request could pick any variation in the
catalogue by naming an in-scope parent. This is the security-shaped risk in the
feature and deserves its own test.

**The cost of enumerating variations.** A page of 24 variable cards each loading
its children can turn one chooser render into hundreds of product loads.
`get_available_variations()` makes it worse. Watch this on a real catalogue
before assuming the cheap path is cheap enough.

**Variations that inherit parent stock.** `stock_demand()` handles the arithmetic,
but the customer-facing story is subtle: choosing Small can make Large
unavailable when they share a stock pool. The per-option reasons should make that
legible rather than looking like a bug.

**The parent's price range.** A variable product's `get_price()` is the range's
low end, so a card showing the parent's price beside a variation's discounted
price can disagree with itself. The card should show the chosen variation's price
once one is chosen, and the range only before that.

**D-006's reasoning still applies to grouped and external.** It would be easy,
while removing a four-type rejection, to remove all four. Only two of them are
being answered here.
