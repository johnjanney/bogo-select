# Open Questions

Questions raised during design and development. Open questions block nothing —
each has a working assumption noted so implementation could proceed. When a
question is answered, move it to **Resolved** with the answer and the date.

---

## Open

### Q-002 — "Buy 2" — two units total, or two of the same product?

**Raised:** 2026-07-30

With Buy scope = *Select Products* containing Item A and Item B, does buying
1 × A + 1 × B satisfy a Buy 2 offer?

**Working assumption:** yes — quantities are summed across all eligible items
(see `DECISION.md` D-003). This matches the *All Products* example in the brief.

**Needed:** confirmation. If the intent is "2 of the same product", the engine
needs a per-product counting mode; it is a small change but changes who qualifies.

---

### Q-003 — Should variable products be giftable?

**Raised:** 2026-07-30

Variable products are currently excluded from the Get list (`DECISION.md` D-006)
because "one free t-shirt" does not say which size. Trying to add one to the Get
list produces a warning naming what was stripped.

**Updated 2026-07-31.** Raised again from the storefront — variable is the usual
product type for size and colour ranges, so excluding it excludes a large share
of what a store would want to give away. The code was surveyed to size the work.

The exclusion is enforced in three places: runtime eligibility
(`includes/class-bogo-engine.php:180`), the settings sanitizer
(`includes/class-bogo-admin.php:86`), and the chooser queries, which are pinned
to `type => 'simple'` (`includes/class-bogo-engine.php:474` and `:585`). Lifting
those checks is not the substance of the work. A gift is identified by a single
product ID everywhere it travels — the engine, the AJAX and Store API endpoints,
the cart line, and the rendered cards — and a variation cannot be named that way,
because `$cart_item['product_id']` on a variation line holds the parent. Any
route to variable gifts therefore starts by widening that identity to a
`(product_id, variation_id, attributes)` tuple. Stock accounting is the exception
and already handles variations correctly (`stock_demand()` matches on
`get_stock_managed_by_id()`).

Two shapes are available. **A:** the admin lists specific variations, so "a free
small red t-shirt" is unambiguous and no new storefront UI is needed. **B:** the
admin lists the variable parent and the customer picks the variation in the
chooser, which is what a size-and-colour gift really wants; it needs A's identity
work first, plus a per-card variation selector and per-variation availability.

**Working assumption:** gifts are simple products only, unchanged. A is a
prerequisite for B, so the tuple refactor is the first move under either.

**Needed:** whether the store wants to name the exact variation it is giving
away (A) or let the customer choose (B). Related decisions if this proceeds:
whether variations may appear in the unfiltered "All Products" gift scope — they
should not, or a store with fifty variable products renders a thousand cards —
and how to treat variations with an "any" attribute value, which are ambiguous
in the same way D-006 objects to. D-006 would be superseded rather than amended.

---

### Q-004 — Tax and shipping treatment of the free item

**Raised:** 2026-07-30

The gift is priced at $0.00, so tax on it is $0.00 and it contributes nothing to
order value — but it *does* have weight and dimensions, so it affects
weight-based shipping and free-shipping-threshold calculations only through
weight, not through subtotal.

All of that holds only while the reward is free. Should Q-008 proceed and the Get
product be discounted rather than given away, the line carries real tax and a real
subtotal, counts toward free-shipping thresholds through value as well as weight,
and this question has to be answered again on different terms.

**Working assumption:** correct as-is — free items should not raise a customer
toward a free-shipping threshold.

**Needed:** confirmation from whoever handles the store's tax/shipping setup,
particularly if the store operates in a jurisdiction that treats promotional
goods as taxable at their normal value.

---

### Q-005 — Should the offer have a schedule?

**Raised:** 2026-07-30

There is no start/end date; the offer runs until manually disabled.

**Working assumption:** manual on/off is sufficient for v1.0.0.

**Needed:** whether campaign scheduling is wanted. It is a contained addition —
two date fields and a check in the engine.

---

### Q-006 — Behaviour when the gift is also in the cart as a paid item

**Raised:** 2026-07-30

If a customer already has 1 × Item B in the cart as a paid purchase and then
selects Item B as their gift, the cart shows two Item B lines: one paid, one free.

**Working assumption:** two separate lines is correct and clearest — the customer
can see what they paid for and what was free, and stock is reduced by the total.

**Needed:** confirmation that a split display is acceptable rather than a single
merged line.

---

### Q-007 — Multiple offers and offer stacking

**Raised:** 2026-07-30

The brief says Buy and Get are limited to one product item "for now", implying
this may expand.

**Working assumption:** one global offer for v1.0.0 (`DECISION.md` D-001).

**Needed:** the likely shape of the next iteration — multiple concurrent offers,
per-category offers, or tiered thresholds — so the data model can be planned
rather than retrofitted.

---

### Q-008 — Should the Get product support a percentage discount rather than only being free?

**Raised:** 2026-07-31

The offer is "Buy X, Get Y free" and nothing else. Whether it should also express
"Buy X, Get Y at 50% off" — or any percentage — was raised while looking at the
Get list. The code was surveyed to size the work.

The pricing itself is one function. `set_reward_price()`
(`includes/class-bogo-cart.php:79`) is the sole choke point: it runs on
`woocommerce_before_calculate_totals` at priority 20 and sets the line price to
zero. A percentage is the same hook with different arithmetic. The trap is that
`set_price( 0 )` is idempotent and a percentage is not — `calculate_totals()`
fires more than once in some requests, so multiplying the current price would
compound the discount pass over pass. A stable base price is needed each time,
read either from a fresh `wc_get_product()` instance, which discards any
adjustment a third-party pricing plugin made to the cart item, or from a figure
stashed on the cart item at selection time, which preserves those adjustments but
goes stale if the product's price moves while the cart sits in a session. Two
settings keys would carry the configuration — a discount type and a value — with
the type defaulting to free, so existing installs need no migration.

The bulk of the work is language, not arithmetic. Roughly twenty customer-facing
strings assume the price is zero, from `free_markup()`
(`includes/class-bogo-cart.php:296`) and the "Free (BOGO)" badge (`:244`) through
the chooser subtitles, the order-item meta, the block cart's item-data row, and
the default offer title. The internal vocabulary — `is_reward_item()`,
`find_reward_keys()`, `bogo_select_reward_added` — is already price-neutral and
needs nothing. Stock and availability are price-blind and need nothing either.

A discounted line also breaks assumptions made when the gift was always free. It
carries real tax and a real subtotal, so it counts toward free-shipping
thresholds — which unsettles Q-004's premise. Site-wide coupons would compound on
top of the reduced price, since the discount lands before coupon calculation.
And discounting a multi-unit display price can land a penny away from discounting
the unit price and multiplying.

**Working assumption:** the Get product is free, unchanged.

**Needed:** whether percentage discounts are wanted at all, and if so, three
calls that are business decisions rather than engineering ones — whether coupons
may stack on a discounted gift, which rounding basis governs, and how the answer
to Q-004 changes once the line has taxable value.

---

## Resolved

### Q-001 — Cart/Checkout blocks or classic shortcodes? — **Answered 2026-07-30**

**Answer:** both, as of v1.2.0. Rather than wait to learn which front end the
store runs, the plugin now supports each of them.

The working assumption — that block support needs a React build step — turned out
to be wrong. The chooser is server-rendered ahead of the Cart and Checkout blocks
through the `render_block` filter, and the browser side talks to the blocks
through the Store API and the `wc/store/cart` data store, both of which are
reachable from plain JavaScript. No React, no build step, no bundled block. See
`DECISION.md` D-008.
