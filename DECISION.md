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
1 of each still qualifies for a Buy 2 offer.

**Confirmed 2026-07-31 (Q-002).** This stands. A store that wants "2 of the same
product" expresses it with a Buy list of one product, which needs no engine
change; what is not expressible is "2 of any single product drawn from a list of
several", and that is a new setting rather than a correction to this decision.

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
are offered as gifts. Variable, grouped, and external products — and individual
variations — are stripped from the Get list when the settings are saved, with a
warning naming what was removed; they are also rejected at selection time.
Variable products *are* eligible on the Buy side — a variation counts if either
its own ID or its parent ID is listed.

**Amended 2026-07-30.** Variation objects were rejected only implicitly (the
product picker does not offer them), not by the eligibility rule itself. A hand-set
option row could therefore list a variation ID as a gift, which contradicted the
"simple products only" language. Both the runtime check and the settings sanitizer
now reject `variation` explicitly.

**Why.** Awarding "a free variable product" is ambiguous — which size, which
colour? Adding one silently picks a variation on the customer's behalf, which is
likely to generate support tickets and wrong shipments.

**Consequence.** To give away a specific variation, the client should list it as a
distinct simple product, or this can be extended to variation-level selection.
See `OPEN-QUESTIONS.md` Q-003.

**Superseded 2026-07-31 by D-017.** The ambiguity this entry objected to is now
resolved by asking the customer rather than by refusing the product. Its
reasoning survives unchanged for grouped and external products, and for
variations that leave an attribute set to "Any".

---

## D-007 — Gift quantity locked in the cart

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** The quantity input for a gift line item is replaced with static text.
The customer may still remove the line entirely.

**Why.** Letting a customer type "50" into the quantity box for a $0.00 item is an
obvious abuse vector. Removal is left available so the gift is never forced on
anyone; removing it re-shows the chooser.

---

## D-008 — Chooser on the cart and the checkout, classic and block

**Date:** 2026-07-30 · **Status:** Accepted · **Supersedes:** D-008 (1.0.0–1.1.0)

**Decision.** The chooser renders on both the cart and the checkout page, in both
the classic templates and the Cart/Checkout blocks. Shop and product pages still
show only a short notice linking to the cart; cart and checkout pages show no
notice, because they have the chooser itself.

**Why.** Through 1.1.0 the chooser rendered only on `woocommerce_before_cart_table`
and the blocks were declared incompatible. Two customers were left with nothing:
the one whose store uses the Cart block, who was told a gift was waiting and had
nowhere to pick it, and the one who goes straight from a product page to checkout
without opening the cart. Both are ordinary journeys, and the offer simply did not
work for them. Keeping the chooser off the checkout bought caution about theme
conflicts at the price of the promotion not working at all.

**How the three contexts differ.** The chooser markup is identical everywhere;
what changes is what happens after the cart is altered, which the server records
in `data-bogo-mode`:

| Mode | Where | After a gift changes |
|---|---|---|
| `classic` | `woocommerce_before_cart_table` | Reload. The cart table, totals, and theme fragments are PHP-rendered, and a reload is the only way they agree with the cart again. |
| `checkout` | `woocommerce_before_checkout_form` | No reload — it would empty a part-filled form. The chooser re-renders from the response and WooCommerce's `update_checkout` refreshes the order review. |
| `block` | ahead of `woocommerce/cart` and `woocommerce/checkout` | Nothing extra. The change was made through the Store API, so the blocks re-render from their own response. |

**Blocks, specifically.** Classic template hooks do not fire inside a block, so
block support is not a matter of hook placement (see
[hook alternatives](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/)).
Four seams are used instead:

- `render_block` puts the chooser ahead of the Cart and Checkout blocks, which is
  where the classic templates put it.
- `woocommerce_store_api_register_endpoint_data` carries the offer state — whether
  the cart qualifies, for how many units, and what is currently chosen — on the
  cart response the blocks already fetch.
- `woocommerce_store_api_register_update_callback`, reached from the browser
  through `wc.blocksCheckout.extensionCartUpdate()`, performs the change inside
  the Store API's own cart request. Selection runs through the same
  `BOGO_Select_Ajax::select_gift()` as the classic endpoint, so neither mode can
  drift from the other's rules.
- `woocommerce_get_item_data` and the `woocommerce_store_api_product_quantity_*`
  filters label the gift line and pin its quantity, replacing the classic
  `woocommerce_cart_item_name` and `woocommerce_cart_item_quantity` filters that
  the blocks never call.

**Consequence.** `cart_checkout_blocks` is declared `true`. The chooser now has a
JavaScript path that must stay in step with the blocks' data store; the fallback
if any of it is missing is the AJAX endpoints and a reload, which is what 1.1.0
did everywhere. `OPEN-QUESTIONS.md` Q-001 is answered: blocks are supported.

**Amended 2026-07-30 (1.1.0).** The original wording said checkout pages show a
notice. They do not — `maybe_render_notice()` returns early on both cart and
checkout. That remains true, and is now also the right behaviour for a second
reason: the checkout has the chooser.

---

## D-009 — Gift capped at available stock

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** If the awarded quantity exceeds the gift product's available stock,
the selection is rejected with a clear message rather than partially fulfilled.

**Why.** Silently awarding 3 of 8 promised free items is worse than telling the
customer the item is unavailable and letting them pick something else. Products
with insufficient stock are marked unavailable in the chooser.

**Amended 2026-07-30.** "Available stock" now means stock less whatever the rest
of the cart already claims from the same stock record, so 2 paid plus 2 free of a
product with 3 in stock is rejected here rather than at checkout. Availability is
also rechecked on every validation pass, not only when the earned quantity
changes.

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

---

## D-011 — Gift chooser paged and searchable rather than uncapped

**Date:** 2026-07-30 · **Status:** Accepted · **Supersedes:** the 50-product cap in v1.0.0

**Decision.** The chooser fetches one page of gift options at a time (24 by
default, filterable through `bogo_select_all_products_limit`) and offers a search
box over name and SKU once there is more than one page. Both Get scopes are paged,
not just *All Products*.

**Why.** v1.0.0 queried the first 50 simple products and rendered them all, which
meant *All Products* silently excluded everything after the fiftieth — the
acceptance criterion promising the whole catalogue was unmet. Simply removing the
cap would have traded a correctness bug for a performance one: every qualifying
cart view would load, price, and render the entire catalogue. Paging keeps the
per-request cost bounded while leaving every eligible product reachable.

**Consequence.** The `bogo_select_all_products_limit` filter is retained but now
means *page size* rather than *hard cap*; a store that had lowered it to trim the
list will now see paging instead of truncation. `bogo_select_get_products` is
applied per page, so a callback that appends IDs appends them to every page — and
in *Select Products* scope such additions are still rejected by the eligibility
gate, because that gate is the same one the selection endpoint enforces.

**Amended 2026-07-30 (1.2.0).** The search box claimed name *and SKU* from the
start, and in *All Products* scope it never searched SKUs at all — see D-014.
`bogo_select_choice_ids` was added alongside `bogo_select_get_products` so a
callback can tell which page, scope, and search term produced the list it is
being handed; the older filter is unchanged.

---

## D-012 — Gift replacement adds before it removes

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** Swapping gifts adds the replacement first and only removes the
previous gift once the add has succeeded. Validation is suspended for the moment
both lines coexist. Re-picking the gift already held short-circuits to a quantity
check instead of a swap.

**Why.** The previous order — remove, then add — left the customer with no gift at
all when the add was rejected after the plugin's own checks passed: aggregate cart
stock, a sold-individually rule, or any third-party
`woocommerce_add_to_cart_validation` callback can still refuse. Presenting that as
"change your selection" and then silently taking the gift away is the worst
outcome available.

**Trade-off.** For the instant between add and remove the cart holds two flagged
lines. Validation would otherwise treat that as duplication and cull one, so it is
suspended across the swap — a re-entrancy guard that must stay paired.

---

## D-013 — Activation blocked without WooCommerce; runtime stays inert

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** The plugin refuses to activate when WooCommerce is missing or older
than `BOGO_SELECT_MIN_WC` — 7.0 when this was written, raised to 9.9 in 2.0.0 by
D-018 — via a `Requires Plugins: woocommerce` header (WordPress 6.5+) and an
activation-hook guard that deactivates and `wp_die()`s on WordPress 6.0–6.4. If
WooCommerce disappears *after* activation, the plugin loads nothing and shows an
admin notice — it does not deactivate itself.

**Why.** `INSTRUCTIONS.md` claimed both behaviours; the code did neither. Blocking
activation is cheap and prevents a plugin that cannot work from looking active.
Self-deactivating at runtime is not: it would fire on any request where
WooCommerce is temporarily unavailable, and silently turning a plugin off behind
the site owner's back is worse than an inert plugin with a loud notice. On
WordPress 6.5+ the dependency header means WordPress handles that case itself.

**Consequence.** Settings survive a WooCommerce outage untouched; reinstating
WooCommerce restores the offer exactly as it was.

---

## D-014 — Gift search goes through WooCommerce's product data store

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** Searching gifts in *All Products* scope calls
`WC_Data_Store::load( 'product' )->search_products()` — the search behind the
admin product picker — rather than passing the term to `wc_get_products()` as
`s`. *Select Products* scope makes the same call, constrained to the configured
IDs. A two-query fallback (`sku` plus `s`) covers a data store that cannot answer.

**Why.** `s` is a WordPress post-search parameter: it matches the title, excerpt,
and content, and knows nothing about SKUs. The chooser's placeholder, the README,
the changelog, and an acceptance criterion all promised SKU search, and in the
*All Products* scope none of it worked; only *Select Products* did, and only
because it compared names and SKUs in PHP afterwards. `search_products()` covers
name, description, and SKU in one query, and it is a data-store call rather than
an assumption that products are posts.

**Consequence.** Search is capped by `bogo_select_search_limit` (200 by default),
because the query is no longer paginated by the catalogue query itself; totals for
a search are therefore counted after the eligibility gate, which also means a
search never promises a gift that cannot be given. The `s`-based path survives
only as the fallback.

**Note.** The unit stub for `wc_get_products()` used to match SKUs through `s`,
which is precisely why the broken implementation passed its own test. The stub now
follows core semantics, so a regression here fails.

---

## D-015 — Eligibility of a curated gift list is cached

**Date:** 2026-07-30 · **Status:** Accepted

**Decision.** In *Select Products* scope the eligibility of every configured gift
is cached in a transient — 10 minutes by default, filterable through
`bogo_select_eligibility_ttl` — and cleared whenever the settings or any product
are saved, trashed, or deleted. The public gift filters are **not** cached: they
run per request, over the cached eligibility map.

**Why.** Eligibility asks whether a product is published, purchasable, and simple.
That is product state, not request state, and answering it meant loading every
configured product on every cart view and every search keystroke. Caching the
filters as well would have been wrong: a callback may legitimately vary the list
per customer, and a shared cache would leak one customer's list to another.

**Consequence.** A gift that stops being purchasable can linger in the chooser for
up to the TTL if nothing triggers a save — the selection endpoint still refuses
it, and the customer is told why, so the failure mode is a wasted click rather
than a free product. IDs that a filter adds are not in the cached map and are
judged on the spot.

---

## D-016 — The reward's base price is read fresh, not taken from the cart line

**Date:** 2026-07-31 · **Status:** Accepted

**Decision.** The reward may be discounted by a percentage rather than only given
away. The undiscounted figure it is calculated from is read from a product loaded
fresh on every pricing pass, never from the cart line's own product object.
Eligible coupons stack on top of the discounted price, and rounding happens once,
on the unit price.

**Why.** `set_reward_price()` runs on `woocommerce_before_calculate_totals`, which
WooCommerce fires more than once in some requests. Setting a price to zero is
idempotent; taking a percentage off is not. Reading the base from the object this
code has already written to would compound the discount — half price, then a
quarter, then an eighth — and the bug would appear only in requests that
recalculate twice, which is the hardest kind to reproduce from a bug report.
Rounding per unit rather than per line matches what WooCommerce does for every
other product, so the line total, the price shown in the chooser, and the order
cannot disagree by a penny.

**Consequence.** A price another plugin sets on the cart item is discarded rather
than discounted, even though the priority-20 hook ordering deliberately runs after
such plugins. Stores running dynamic pricing will see the reward line priced from
the catalogue. Because coupons apply afterwards, a site-wide 20% coupon compounds
with a 50% reward discount and the customer pays 40% of list, wherever that
coupon's own product, category, and sale-exclusion rules permit it; this is
WooCommerce's normal treatment of a reduced price, and is left alone.

**Verified 2026-07-31.** This was reasoned from the hook ordering when the
decision was taken, and is now covered by the integration job
(`tests/integration/coupon.test.mjs`) against a real store, in both directions —
an eligible coupon compounds to 40% of list, and one excluding the reward leaves
it untouched while still discounting the rest of the cart.

A discounted reward also carries real tax and real subtotal, unlike a free one, so
it counts toward free-shipping thresholds through value as well as weight. See
`OPEN-QUESTIONS.md` Q-004, which assumed the reward was always free.

---

## D-017 — Variable products are offered, and the customer picks the variation

**Date:** 2026-07-31 · **Status:** Accepted · **Supersedes:** D-006

**Decision.** The Get list may hold a variable product, in which case the chooser
offers its variations and the customer picks one, or a single variation, which
pins the reward to that exact thing with no choice shown. A variable product is
presented as one card carrying a flat list of its variations rather than one
dropdown per attribute. Variations are never enumerated by scope: a variation
reaches the chooser only by being listed individually. Grouped and external
products remain ineligible, as do variations that leave an attribute set to
"Any".

**Why.** Variable is the usual product type for size and colour ranges, so
excluding it excluded much of what a store would want to give away. D-006 refused
these because "one free t-shirt" does not say which size — the answer is to ask,
which is what a chooser already exists to do. A flat list of variations was
preferred over per-attribute dropdowns because the latter needs WooCommerce's
variation-matching JavaScript inside a cart-page card and can reach a
combination that does not exist; a list cannot offer what is not there, and it
gives per-option availability for free.

**Consequence.** A reward is no longer named by one integer. It is a
`(product_id, variation_id)` pair, because a variation's cart line stores its
parent in `product_id` and the two are indistinguishable without it. Eligibility
splits accordingly into whether something may be offered and whether it may be
awarded, and a variable parent is only ever the first.

A card's availability becomes an aggregate — it stands while any one variation
can be given — and its price quotes a variation rather than the parent, whose
price is the low end of a range and need not match any of them. Variations that
share a parent's stock pool compete with each other, so choosing one can make
another unavailable; the per-option reasons are what make that legible rather
than puzzling. The discount in D-016 composes without change, applying to the
chosen variation's own price.

---

## D-018 — The WooCommerce minimum is raised to 9.9

**Date:** 2026-07-31 · **Status:** Accepted

**Decision.** `WC requires at least` and `BOGO_SELECT_MIN_WC` move from 7.0 to
9.9, and the plugin's major version moves with them (`BRIEF.md` §8.1 counts a
raised minimum as a breaking change).

**Why.** The 7.0 claim was never tested. The integration matrix has only ever run
9.9.5 and current, and the unit stubs model this plugin's own callbacks rather
than WooCommerce's, so nothing established that 7.0 through 9.8 preserve the
Store API, block rendering, hydration, quantity-bound, and price-display
contracts the plugin depends on (`CODEX-REVIEW.md` M-02). A declared minimum is a
promise about versions someone might install on, and this one was unbacked. The
choice was to test four years of releases or to narrow the promise to what is
actually exercised; narrowing is the honest and affordable half.

**Consequence.** Stores on WooCommerce 7.0–9.8 can no longer install the plugin,
and any that already run it will find it inert after upgrading — the activation
guard blocks a fresh install and the runtime check stops it loading on an
existing one, with an admin notice either way. That is a real loss of reach,
taken deliberately in exchange for a compatibility claim that means something.
Those stores can stay on 1.3.0, which is unaffected and remains published.

The declaration is `9.9`, while CI's oldest lane pins `9.9.5`. Four patch
releases are therefore claimed but not exercised — a far smaller gap than the one
this closes, and the conventional shape for a plugin header, but not nothing.
