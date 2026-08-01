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

**What is now known.** The tax half of this has been measured rather than
assumed. A discounted reward is taxed on what the customer actually pays: a
20.00 reward at half price is taxed on 10.00, in a store whose prices exclude tax
and in one whose prices include it. That is covered by the integration job
(`tests/integration/tax.test.mjs`). What remains open is not the arithmetic but
the policy — whether a promotional item *should* be taxed on its reduced value in
the jurisdictions this store sells into, and how it should count toward
free-shipping thresholds.

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

**Answer:** yes. The offer is implemented and on `main`, unreleased at the
time of writing, and expresses "Buy X, Get Y at N% off"
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
sale price. Eligible coupons stack, leaving a 20% coupon over a 50%
reward at 40% of list wherever that coupon's own rules permit it — reasoned from
the hook ordering at the time, and since covered by the integration job in both
directions. And `free` stayed a discount type of its own rather than
becoming a percentage of 100, so the interface can say "Free" without
special-casing a magic number.

Fixed-amount discounts were left out on purpose; a percentage needs no clamping
against negative prices and raises no per-unit versus per-line question, and "$5
off" can be added later on the same field.

The one thing this unsettles rather than settles is Q-004: a discounted reward
carries real tax and real subtotal, so it counts toward free-shipping thresholds
by value as well as weight.

---

### Q-003 — Should variable products be giftable? — **Answered 2026-07-31**

**Answer:** yes, and the answer to "which size?" is to ask. The Get list may hold
a variable product, in which case the chooser offers its variations and the
customer picks one, or a single variation, which pins the reward to that exact
thing. See `DECISION.md` D-017, which supersedes D-006.

The Buy side already handled variable products and needed no change — verified by
running it rather than reading it: three units of a variation count as three
under Buy = All Products, under a Buy list naming the parent, and under one
naming the variation. The product-type filter that produced the original warning
only ever touched the Get list.

The substance of the work was not the type check but the reward's identity. A
variation's cart line stores its parent in `product_id`, so a single integer
cannot tell two variations of one product apart; a reward is now a
`(product_id, variation_id)` pair, and eligibility splits into whether something
may be offered and whether it may be awarded. A variable product is only ever the
first — it names a product, not a thing.

A variable product renders as one card with a flat list of its variations rather
than a dropdown per attribute, so the chooser can never offer a combination that
does not exist, and each option carries its own availability. The card's price
quotes a variation, because a variable product's own price is the low end of a
range and need not match any of them.

Two things a store should know. A variation that leaves an attribute set to "Any"
is not offerable, because it would still need a choice — the ambiguity D-006
objected to, surviving in that one case. And variations that share a parent's
stock pool compete with each other, so choosing one can make another unavailable;
the reason is shown against the option rather than left to be guessed at.
