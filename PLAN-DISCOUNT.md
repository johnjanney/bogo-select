# Implementation Plan — Percentage discount on the Get product

Extends the offer from "Buy X, Get Y free" to "Buy X, Get Y at N% off".
Answers `OPEN-QUESTIONS.md` Q-008.

---

## Contents

1. [Scope and decisions taken](#1-scope-and-decisions-taken)
2. [Data model](#2-data-model)
3. [The calculation](#3-the-calculation)
4. [Applying the price](#4-applying-the-price)
5. [Display and language](#5-display-and-language)
6. [Admin](#6-admin)
7. [Blocks, Store API, and the order](#7-blocks-store-api-and-the-order)
8. [Tests](#8-tests)
9. [Documentation](#9-documentation)
10. [Sequencing](#10-sequencing)
11. [What could bite](#11-what-could-bite)

---

## 1. Scope and decisions taken

Three questions were settled before planning:

**Discount types: free and percent only.** No fixed-amount-off. A percentage is
linear, so it needs no clamping against negative prices and forces no per-unit
versus per-line decision. Amount-off can be added later on the same field.

**Base price: read fresh each pass.** The pre-discount figure comes from a new
`wc_get_product()` instance every time the price is applied, never from the cart
item's own product object. This makes the operation idempotent by construction.
The cost is that a price set on the cart item by a third-party dynamic-pricing
plugin is discarded rather than discounted — the priority-20 hook ordering exists
precisely to run after such plugins, so this is a real trade and is accepted.

**Coupons stack.** A site-wide coupon applies on top of the reduced price, which
is what WooCommerce does by default and needs no code.

Two further calls, made here rather than asked:

**`free` stays its own type rather than becoming `percent = 100`.** One field
would be a tidier data model, but it would force the UI to special-case a magic
number every time it wants to say "Free". A `percent` of exactly 100 still
behaves identically to `free`, so nothing breaks if someone configures it that
way.

**The discount applies to the effective selling price**, i.e. `get_price()`, not
`get_regular_price()`. A gift that is already on sale is discounted from the sale
price. The struck-through figure shown to the customer uses the same basis, so
the two always agree.

---

## 2. Data model

Two new keys in `includes/class-bogo-settings.php` — `defaults()` at `:32`,
casting in `all()` at `:61`, and `sanitize()` at `:121`:

| Key | Values | Default |
|---|---|---|
| `get_discount_type` | `free` \| `percent` | `free` |
| `get_discount_value` | float, clamped 0–100 | `0` |

Defaulting the type to `free` means `wp_parse_args()` hands every existing
install its current behaviour. There is no migration, and no upgrade routine. A
cart that already holds a gift line when the plugin updates prices that line at
zero on its next request, exactly as before.

Two new protected helpers alongside `to_scope()` and `to_bool_string()`:
`to_discount_type()` and `to_percent()`. The percent helper clamps rather than
rejects, so a hand-edited option row cannot produce a negative price.

---

## 3. The calculation

The arithmetic belongs in `includes/class-bogo-engine.php`, whose docblock
already claims "pure decisions … no output, no cart mutation". Three additions:

```php
public static function discount_factor()   // 1.0 → full price, 0.0 → free
public static function reward_price( $base )
public static function is_free_reward()
```

`discount_factor()` returns `0.0` for type `free`, otherwise
`1 - ( value / 100 )` clamped to `0.0`–`1.0`. `reward_price()` multiplies and
rounds to `wc_get_price_decimals()`. `is_free_reward()` is true for type `free`
and for a percent of 100 or more — it exists so the display layer can say "Free"
without comparing floats itself.

**Rounding basis:** round the *unit* price, then let WooCommerce multiply by
quantity. This is what core does for every other product, so line totals, the
struck-through display, and the order all derive from one rounded unit figure and
cannot disagree by a penny. This is the whole of the rounding decision Q-008
raised.

---

## 4. Applying the price

`set_reward_price()` in `includes/class-bogo-cart.php:79` keeps its hook and
priority. Its body becomes: for each reward line, read the base price fresh, and
`set_price( BOGO_Select_Engine::reward_price( $base ) )`.

A new protected `base_price( $cart_item )` does the fresh read, preferring
`variation_id` over `product_id` when one is set — the current code looks only at
`product_id`, which is harmless today because variations cannot be gifts, and
becomes correct in advance of Q-003.

**Why this is idempotent.** `calculate_totals()` fires more than once in some
requests. Reading the base from `$cart_item['data']` — an object this function
has already mutated — would compound: 50% off, then 75%, then 87.5%. Reading it
from a fresh `wc_get_product()` cannot, because real WooCommerce builds a
distinct product object per cart item and per `wc_get_product()` call. The
comment explaining this needs to survive in the code; it is the single thing most
likely to be "simplified" away by a later reader.

Under the default `free` configuration the factor is `0.0`, so the function
computes zero and behaves exactly as it does today.

---

## 5. Display and language

Roughly twenty customer-facing strings assume the price is zero. The temptation
is twenty inline conditionals; the plan is instead **one place that decides the
wording**, which every call site asks.

Add label helpers next to the calculation — `reward_label()` returning `Free` or
`50% off`, and `reward_badge()` returning `Free (BOGO)` or `50% off (BOGO)` — and
route these sites through them:

- `includes/class-bogo-cart.php:292` — `free_markup()` becomes `reward_markup()`.
  When the reward is free it renders as it does now. When it is discounted it
  renders the original struck through beside the discounted figure. Both come
  from `wc_get_price_to_display()`, which accepts a `price` argument, so
  tax-inclusive and tax-exclusive stores both render correctly without the
  markup layer knowing which it is.
- `includes/class-bogo-cart.php:244` — the name badge.
- `includes/class-bogo-cart.php:158`–`:200` — the five removal notices.
- `includes/class-bogo-frontend.php:233`, `:239` — the chooser subtitles.
- `includes/class-bogo-frontend.php:346` — the price line on every chooser card,
  which also gains the discounted figure rather than the word "Free".
- `includes/class-bogo-ajax.php:319` — the selection confirmation.
- `includes/class-bogo-frontend.php:397` — the shop-page notice.

The internal vocabulary — `is_reward_item()`, `find_reward_keys()`,
`bogo_select_reward_added`, `BOGO_Select_Engine::FLAG` — is already price-neutral
and is not touched. The `FLAG` constant's *value* is the string
`bogo_select_free`; it stays, because it is a persisted cart-item key and
renaming it would orphan every gift line in every live session.

**The saved offer title is user text and cannot be rewritten.** A store that
saved "Choose your free gift" and later switches to percent keeps that title. The
default in `class-bogo-settings.php:35` is only applied on first save, so
changing it fixes nothing retroactively. The admin should say so — see below.

---

## 6. Admin

A discount control in the Quantities section of `includes/class-bogo-admin.php`,
after the Get quantity field at `:237`: a type selector, and a percent input
revealed when `percent` is chosen. The existing `bogo-scope` fieldset pattern at
`:264` already does show/hide against a radio group in
`assets/js/bogo-select-admin.js`, so the same mechanism can be reused rather than
invented.

Two validation warnings, following the `add_settings_error()` pattern already at
`:110`–`:125`:

- Type is `percent` and the value is `0` — the offer awards a full-price item,
  which is almost certainly a mistake. Warn; do not silently disable. `is_active()`
  is left alone, because a 0% offer still functions and quietly switching a store's
  promotion off is worse than letting it run visibly wrong.
- Type is `percent` and the saved offer title still contains the word "free" —
  a nudge to update the wording the customer sees.

The Get quantity description at `:245` says "How many **free** units" and needs
to become discount-aware, as does the offer description at the top of the page.

---

## 7. Blocks, Store API, and the order

- `includes/class-bogo-blocks.php:285` — the item-data row takes its text from
  the same label helper.
- The Store API schema at `:193`–`:215` gains nothing unless the front-end JS
  needs it. The chooser is server-rendered, so it does not. Leaving the schema
  alone keeps the change invisible to any existing consumer; if it turns out to
  be needed, adding members is additive and safe.
- Line prices in the block cart follow automatically — the Store API reports
  prices after `calculate_totals()`, and that is where the discount is applied.
- `includes/class-bogo-cart.php:313` — the order line item. The hidden
  `_bogo_select_free` key stays for back-compat with anything already querying
  it. Add `_bogo_select_discount` recording the type and value **as applied at
  the time of the order**, because settings can change afterwards and an order
  should be able to explain its own pricing. The visible meta value becomes the
  reward label rather than the fixed string "BOGO promotion".

---

## 8. Tests

A new `tests/DiscountPricingTest.php` covering: `free` still prices at zero (the
regression guard); percent 50 halves; percent 100 is free; percent 0 is full
price; and rounding at awkward figures against `wc_get_price_decimals()`.

**The idempotency test is the one that matters** — call `set_reward_price()`
twice and three times in a row and assert the price does not move.

**A stub fix is required before that test means anything.**
`tests/stubs/woocommerce.php:242` sets a cart item's `data` to the object
`wc_get_product()` returns, and the stub's `wc_get_product()` at `:471` returns
the *shared* instance out of `BOGO_Test_Env::$products`. Catalogue and cart line
are therefore the same object in tests, which real WooCommerce never does — it
builds a distinct product object per cart item and per lookup. Left as-is, the
stub would compound where production does not, and the idempotency test would
fail for a reason that does not exist in the field. `add_item()` must clone.

That stub change is worth landing on its own, ahead of the feature: it makes the
harness match WooCommerce, and any existing test it breaks is a test that was
relying on the shared-instance artefact.

Also needed:

- `tests/stubs/woocommerce.php:618` — `wc_get_price_to_display()` ignores
  `$args['price']`. The new markup depends on it.
- `wc_get_price_decimals()` does not exist in the stubs and must be added.
- `tests/SettingsTest.php` — clamping, both new defaults, and specifically that
  an option row saved before this feature reads back as `free`.
- `tests/CartValidationTest.php` — the existing free-path assertions should keep
  passing untouched. If any needs editing, that is a signal the default changed
  when it should not have.
- `tests/integration/setup-store.php:55` seeds the settings option directly and
  omits the new keys, so the existing block integration job keeps asserting a
  zero price and keeps passing. A second seeded scenario at 50% is the cheap way
  to get real end-to-end coverage of the discounted path.

---

## 9. Documentation

- `DECISION.md` — one new entry recording the three decisions in §1: fresh-read
  base price and what it costs, coupons stack, unit-price rounding.
- `OPEN-QUESTIONS.md` — Q-008 moves to **Resolved** with the answer and date.
  Q-004 is revisited rather than closed: a discounted line has taxable value and
  does count toward free-shipping thresholds, so its working assumption needs
  restating for the discounted case rather than deleting.
- `README.md`, `INSTRUCTIONS.md` §3–§5, and `CHANGELOG.md` under Unreleased.
- The admin help strings listed in §6.

---

## 10. Sequencing

Each step leaves the suite green and the plugin shippable.

1. **Stub fidelity.** Clone in `add_item()`, honour `price` in
   `wc_get_price_to_display()`, add `wc_get_price_decimals()`. No plugin code.
2. **Settings and calculation.** New keys, new engine methods, unit tests. No
   behaviour change — nothing calls the new code yet.
3. **Pricing.** Rewrite `set_reward_price()` and add the idempotency tests. The
   feature works end to end at this point, described in free-gift language.
4. **Display and language.** Label helpers and the call-site sweep.
5. **Admin.** The control, the two warnings, the help text.
6. **Blocks and order meta.**
7. **Docs**, and the second integration scenario.

Steps 1–3 are the substance. Step 4 is the largest by line count and the least
risky.

---

## 11. What could bite

**A later reader "simplifying" the fresh read.** Reading the base from
`$cart_item['data']` is the obvious-looking shortcut and silently compounds the
discount. This is why the idempotency test and the comment both exist, and why
the test asserts three passes rather than two.

**Third-party dynamic pricing.** Accepted in §1, but it should be stated in the
docs rather than discovered by a store owner: a plugin that adjusts cart-item
prices will have its adjustment overwritten on the reward line.

**Free-shipping thresholds.** A discounted gift adds real subtotal, so a store
whose free-shipping threshold sits near the cart value will see behaviour change
the day it switches from free to percent. This is Q-004's territory and belongs
in the release notes, not just the decision log.

**Sale-price interaction.** Discounting `get_price()` means a gift already on
sale is discounted twice over in effect. That is the intended reading of "50%
off", but it is worth one line in the docs so nobody is surprised by a 50%-off
reward on a 40%-off product costing 30% of list.

**Sold-individually products.** `unavailable_reason()` at
`class-bogo-engine.php:230` rejects a reward quantity above 1 for
sold-individually products. That logic is price-blind and needs no change, but it
is worth confirming under test that a discounted reward is still subject to it —
the rule is about units, not money, and should not have quietly become about
money.
