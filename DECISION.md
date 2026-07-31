# Decision Log

Decisions taken during development that would normally have warranted a pause to
ask, recorded here instead so that implementation could continue. Each can be
revisited — flag any you disagree with and it will be changed.

Format: **D-nnn — Title** · *Date* · *Status*

---

## D-001 — Single global offer rather than multiple offers

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** v1.0.0 supports exactly one offer, stored in one option row.

**Why.** Requirement R4 states Buy and Get are each limited to one product item for
now, and the brief describes a single sale. Multiple offers would require an offer
CPT, priority/stacking rules, and conflict resolution — significant scope beyond
what was asked.

**Consequence.** Moving to multiple offers later means migrating the option into a
custom post type. The settings are already namespaced under one array key, so the
migration is mechanical.

---

## D-002 — 100% discount implemented as a price override, not a coupon

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** The free line item's price is forced to `0` via
`$cart_item['data']->set_price( 0 )` on `woocommerce_before_calculate_totals`,
rather than generating a hidden 100%-off coupon.

**Why.** Requirement R2 asks for a 100% discount *that still reduces inventory*.
A price override:

- keeps the gift a normal product line, so WooCommerce's standard stock reduction
  runs untouched;
- guarantees the discount lands on exactly the intended line item, with no risk of
  a percentage coupon leaking onto other products;
- computes tax on $0.00 instead of applying a discount after tax;
- avoids creating throwaway coupon posts in the database, and avoids collisions
  with "one coupon per order" configurations.

**Trade-off.** The order does not show a "discount" figure — the line simply reads
$0.00. If the client wants the saving displayed as a discount amount, the cart
line shows the original price struck through, and that behaviour can be extended.

---

## D-003 — Qualification is measured across the whole cart

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** `buy_count` is the sum of quantities of **all** Buy-eligible cart
items, not the quantity of any single line item.

**Why.** With Buy scope = *All Products* (the example given in requirement R5),
per-line counting would mean 1 × Item A + 1 × Item B fails a "Buy 2" offer, which
contradicts the plain reading of "buy 2 products". Cart-wide counting matches how
customers read the promotion.

**Consequence.** With Buy scope = *Select Products* and two products listed, buying
1 of each still qualifies for a Buy 2 offer. If the intent was "2 of the *same*
product", this needs to change — see `OPEN-QUESTIONS.md` Q-002.

---

## D-004 — Free items never count toward qualification

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** Cart items flagged as BOGO rewards are excluded from `buy_count`.

**Why.** Otherwise a Buy 2 Get 2 offer would be self-sustaining: the 2 free items
would themselves satisfy the next Buy 2, and the cart would qualify forever.

---

## D-005 — "Repeat" mode added as an off-by-default setting

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** A **Repeat offer** toggle was added. Off (default): the cart earns at
most one gift set no matter how many qualifying items it holds. On: it earns
`floor( buy_count / buy_qty )` sets.

**Why.** The brief does not say what happens when a customer buys 4 items under a
Buy 2 Get 2 offer. Both readings are defensible and the difference is commercially
material, so it was made an explicit setting rather than a silent assumption.
Default is off because it is the more conservative (less costly) behaviour.

**Note.** Even in repeat mode the customer still picks **one** Get product and
receives all sets of it, per R4.

---

## D-006 — Variable products excluded from the Get chooser

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** Only products that can be added to the cart without choosing options
are offered as gifts. Variable, grouped, and external products are stripped from
the Get list when the settings are saved, with a warning naming what was removed;
they are also rejected at selection time. Variable products *are* eligible on the
Buy side — a variation counts if either its own ID or its parent ID is listed.

**Why.** Awarding "a free variable product" is ambiguous — which size, which
colour? Adding one silently picks a variation on the customer's behalf, which is
likely to generate support tickets and wrong shipments.

**Consequence.** To give away a specific variation, the client should list it as a
distinct simple product, or this can be extended to variation-level selection.
See `OPEN-QUESTIONS.md` Q-003.

---

## D-007 — Gift quantity locked in the cart

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** The quantity input for a gift line item is replaced with static text.
The customer may still remove the line entirely.

**Why.** Letting a customer type "50" into the quantity box for a $0.00 item is an
obvious abuse vector. Removal is left available so the gift is never forced on
anyone; removing it re-shows the chooser.

---

## D-008 — Chooser rendered on the cart page, notices elsewhere

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** The full product chooser renders on the cart page
(`woocommerce_before_cart_table`). Shop, product, and checkout pages show only a
short notice linking to the cart.

**Why.** The cart is where the customer can see what they qualified for and act on
it. Rendering a full product grid inside the checkout flow risks conflicting with
the many checkout customisations found in the wild — and, on block-based
checkouts, would not render at all.

**Note.** This plugin targets the **shortcode/classic** cart and checkout. Stores
using the WooCommerce Cart and Checkout *blocks* will see the notice but not the
chooser. See `OPEN-QUESTIONS.md` Q-001.

---

## D-009 — Gift capped at available stock

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** If the awarded quantity exceeds the gift product's available stock,
the selection is rejected with a clear message rather than partially fulfilled.

**Why.** Silently awarding 3 of 8 promised free items is worse than telling the
customer the item is unavailable and letting them pick something else. Products
with insufficient stock are marked unavailable in the chooser.

---

## D-010 — Documentation written directly rather than delegated

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** These project documents were authored directly rather than through
the `ask_kimi`/`kimi_write` delegation workflow in the global instructions.

**Why.** That workflow's own "when not to delegate" guidance excludes architectural
decisions and work requiring careful reasoning. `BRIEF.md` and this file *are* the
architecture — they were written before the code existed to be summarised, and
they are the source the implementation follows. Routine documentation updates
after this point (e.g. regenerating `INSTRUCTIONS.md` from a changed feature set)
remain good delegation candidates.
