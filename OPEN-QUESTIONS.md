# Open Questions

Questions raised during design and development. Open questions block nothing —
each has a working assumption noted so implementation could proceed. When a
question is answered, move it to **Resolved** with the answer and the date.

---

## Open

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

---

### Q-004 — Tax and shipping treatment of the reward — **Answered 2026-07-31**

**Answer:** the working assumption is adopted, and it is no longer an assumption.
Both halves of the original question have been measured against a real store, and
the behaviour is what the entry predicted from the beginning.

**A free reward adds weight but not value.** With a free-shipping method set to
unlock above 50.00, a cart holding a 45.00 item and a free reward stays at 45.00
and free shipping is not offered. The reward is still in the parcel: a per-item
flat rate doubles when it is added. So a free reward reaches a weight-based
method and cannot carry a customer over a value threshold, which is exactly the
behaviour this entry endorsed.

**A discounted reward adds both.** The same cart with the reward at 50% off
reaches 55.00, and free shipping is then offered. That is not a defect. A
discounted line is a line the customer is paying for, and excluding it from the
order value it contributes to would misreport the order.

**Tax follows what the customer actually pays**, in both display modes: a 20.00
reward at half price is taxed on 10.00 whether the store's prices include tax or
exclude it, and a free reward is taxed on nothing.

All of this is now covered by the integration job — `shipping.test.mjs` in both
modes, and `tax.test.mjs` in both display modes — so it is regression-guarded
rather than recorded.

**What was not answered here, and why.** The original entry also asked for
confirmation from whoever handles the store's tax setup, "particularly if the
store operates in a jurisdiction that treats promotional goods as taxable at
their normal value." That is a question about tax law rather than about this
plugin, and it is not one this log can settle: the answer varies by jurisdiction
and by how the business is registered. It is recorded as an operator note in
`INSTRUCTIONS.md` §6 instead, where the person configuring the store will meet
it. The plugin's own behaviour — tax on the amount charged — is the conventional
treatment and is what WooCommerce does for any reduced price; a store told
otherwise by its accountant needs a tax plugin or a manual adjustment, not a
change here.

---

### Q-002 — "Buy 2" — two units total, or two of the same product? — **Answered 2026-07-31**

**Answer:** two units total, summed across everything on the Buy list. The
working assumption stands, and `DECISION.md` D-003 is confirmed rather than
revisited.

Two things settle it. The brief's own *All Products* example in R5 only reads
coherently under cart-wide counting — per-line counting would mean a customer
holding one of each of two products fails a "Buy 2" offer, which is not how
anyone reads "buy 2 products". And having Buy = *All Products* count cart-wide
while Buy = *Select Products* counted per line would be an inconsistency the
admin screen gives no way to predict.

**The question's premise was half wrong, which is what makes it closable.** It
assumed that wanting "2 of the same product" would require a per-product counting
mode. It does not: a Buy list of one product already means exactly that, because
nothing else on the cart is eligible to count. Verified —

| Buy list | Cart | Qualifies for Buy 2 |
|---|---|---|
| A and B | 1 × A + 1 × B | yes |
| A and B | 2 × A | yes |
| A only | 1 × A + 1 × B | no |
| A only | 2 × A | yes |

Both rows of the second block are held by tests in `QualificationTest.php`, since
the one-product list is now the documented way to express per-product intent and
advice that is not tested is advice that rots. The recipe is in `INSTRUCTIONS.md`
§4.

**What genuinely is not expressible**, and is recorded here rather than closed
over: "2 of any *single* product, chosen from several" — a Buy list of A and B
that accepts 2 × A or 2 × B but refuses 1 × A + 1 × B. That needs a counting mode
the plugin does not have. It is a new setting rather than a correction, nobody
has asked for it, and it can be added without disturbing D-003 if anyone does.

---

### Q-005 — Should the offer have a schedule? — **Answered 2026-07-31**

**Answer:** yes, and it is built. The settings screen has a **Start date** and an
**End date**, both optional. See `DECISION.md` D-019.

Both bounds are inclusive whole days in the store's own timezone. An offer set to
run 1–7 August is live on both of those days and stops on the 8th — the half that
is easy to get wrong is the end, where expiring at midnight *as* the last day
begins quietly loses a day nobody agreed to lose. Leaving a field empty leaves
that side unbounded: no start date means the offer has always been running, no
end date means it runs until switched off, and neither means it behaves exactly
as it did before scheduling existed. Every install that upgrades is unscheduled,
and there is no migration.

The schedule only ever narrows an offer. It cannot switch on one whose Enable box
is unticked, which is a deliberate ordering: the checkbox stays the master
switch, and a store can stop a campaign early without editing dates.

**Dates rather than date-times**, because nobody asked for times and admitting
them would force a choice of hour for each bound and then be wrong about it twice
a year when the clocks change. Comparing `Y-m-d` strings sorts chronologically
with no date arithmetic at all. A campaign that must start or end partway through
a day still needs the switch. That limit is recorded in D-019 rather than left to
be discovered.

The settings screen refuses a window that ends before it begins, and says so when
an enabled offer has already ended or has not started yet — in both of those
cases the storefront looks identical to the offer simply being off, which is
exactly the confusion worth heading off.

---

### Q-006 — Behaviour when the gift is also in the cart as a paid item — **Answered 2026-07-31**

**Answer:** two separate lines, as assumed. The working assumption is adopted and
recorded as `DECISION.md` D-020.

A customer who buys one Item B and then chooses Item B as the reward sees two
lines: one at the price they paid, one at the reward price. They receive two
units and the store reduces stock by two.

The reason a merged line was never really available is that the two lines carry
different prices. One line would read "2 × Item B" at a single price, and the
customer could not see which unit was free — or, since v1.3.0, which unit was
discounted and by how much. The split is also what WooCommerce does of its own
accord: the reward line carries cart-item data the paid line does not, and that
is what makes it a distinct line rather than a quantity bump.

Two tests in `CartValidationTest.php` hold it now — that the reward does not
merge into the paid line, that each keeps its own quantity, that both draw on the
same stock record, and that the two prices stay apart. It was a documented
assumption for long enough; assumptions that survive this long are worth pinning
before something quietly changes them.
