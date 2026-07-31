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

All of that holds only while the reward is free. Q-008 has since shipped, so a
store can price the reward at a percentage off instead of giving it away, and
such a line carries real tax and a real subtotal. It counts toward free-shipping
thresholds through value as well as weight, which is the opposite of the
behaviour the working assumption below endorses. The question is therefore live
again for any store that configures a discount, and unanswered for that case.

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

---

### Q-008 — Should the Get product support a percentage discount rather than only being free? — **Answered 2026-07-31**

**Answer:** yes, and it has shipped. The offer expresses "Buy X, Get Y at N% off"
as readily as "Get Y free", configured by `get_discount_type` and
`get_discount_value` and controlled from the settings screen. Both default to the
free behaviour, so an option row saved before the feature reads back as a free
gift and no store changes until someone changes it. See `DECISION.md` D-016.

The pricing was the small half of the work and the wording the large one, much as
the investigation predicted. The reward's undiscounted price is read from a
product loaded fresh on every pass rather than from the cart line's own product
object, because WooCommerce recalculates totals more than once in some requests
and a percentage — unlike a zero — compounds when it is applied to its own
output. The trade is that a price set on the cart item by another plugin is
overwritten rather than discounted. Rounding happens once, on the unit price.

Three of the surrounding decisions are worth restating. The discount comes off
the effective selling price, so a reward already on sale is discounted from its
sale price. Coupons stack, leaving a 20% coupon over a 50% reward at 40% of list
— that follows from where the pricing hook sits rather than from a test, since
the unit stubs have no coupon support, and it is expected behaviour rather than
verified behaviour. And `free` stayed a discount type of its own rather than
becoming a percentage of 100, so the interface can say "Free" without
special-casing a magic number.

Fixed-amount discounts were left out on purpose; a percentage needs no clamping
against negative prices and raises no per-unit versus per-line question, and "$5
off" can be added later on the same field.

The one thing this unsettles rather than settles is Q-004: a discounted reward
carries real tax and real subtotal, so it counts toward free-shipping thresholds
by value as well as weight.
