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
because "one free t-shirt" does not say which size.

**Working assumption:** gifts are simple products only.

**Needed:** whether any intended gift is a variable product. If so, the chooser
needs a variation selector, which is a moderate addition.

---

### Q-004 — Tax and shipping treatment of the free item

**Raised:** 2026-07-30

The gift is priced at $0.00, so tax on it is $0.00 and it contributes nothing to
order value — but it *does* have weight and dimensions, so it affects
weight-based shipping and free-shipping-threshold calculations only through
weight, not through subtotal.

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
