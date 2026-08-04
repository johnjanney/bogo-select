# Open Questions

Questions raised during design and development. Open questions block nothing —
each has a working assumption noted so implementation could proceed. When a
question is answered, move it to **Resolved** with the answer and the date.

---

## Open

### Q-012 — Should the plugin report on BOGO sales?

**Raised:** 2026-08-03

The plugin records what it did to a cart and never reports on it. A merchant
asking how the offer is performing has to open orders one at a time. Two figures
were asked for: how many orders contain a reward, and what those orders are worth
in total — order volume and order value, rather than what the promotion costs.

**Working assumption:** no reporting, as today. Nothing is blocked. The reward is
already legible on the admin order screen through its visible label, and the
hidden flag is queryable by anything that wants it.

**What the orders already hold.** `add_order_item_meta()` stamps every reward
line at checkout — `class-bogo-cart.php:445-464`, on
`woocommerce_checkout_create_order_line_item` at `:54`:

- `_bogo_select_free` is `yes`, the hidden flag, kept under that name even for a
  discounted line because it is a persisted key that existing queries rely on;
- `_bogo_select_discount` is `free` or `percent:50`, the offer's terms frozen at
  the moment of the order, so a line can explain its own pricing after the
  settings change beneath it;
- a visible label for the order screen, emails, and packing slips.

The consequence worth noting is that a report would be retroactive. Both figures
can be computed for every BOGO order the store has ever taken, not only for
orders placed after the feature ships.

**What they do not hold.** No order-level meta, so orders can only be found by
scanning line items. No flag on the qualifying Buy lines, so there is no
purchase-side attribution. Nothing records that a cart qualified and declined, so
a redemption rate cannot be computed. No funnel — chooser opens and abandoned
selections would need instrumenting at the AJAX endpoints
(`class-bogo-ajax.php:22-29`), which is a separate question carrying its own
consent implications. And the offer has no stable ID (Q-007), so all history is
"the offer" over time.

**What it would take.** For these two figures specifically, less than a general
reporting feature would.

- **An order-level flag.** Stamping one on the order in the same hook collapses
  the query. Under HPOS — declared at `bogo-select.php:44` — `wc_orders` carries
  status, creation date, and total in the same table the meta join lands on, so
  both figures come from one query rather than from a scan of
  `woocommerce_order_itemmeta` followed by a second lookup whose table depends on
  whether HPOS is on.
- **A backfill for that flag.** Past orders have the line meta and not the order
  meta, so a one-time batched pass would find them by the former and stamp the
  latter. This is the first thing that would actually need `bogo_select_version`,
  which is written at activation (`bogo-select.php:145`) and never afterwards
  compared against `BOGO_SELECT_VERSION`. There is no upgrade routine to hang a
  backfill on yet.
- **A decision about which orders count.** Paid statuses only, by
  `wc_get_is_paid_statuses()`, is the defensible default; pending, failed, and
  cancelled orders should not inflate either figure.
- **A decision about what revenue means.** `get_total()` is gross. It includes
  tax and shipping, and it includes every non-reward line in the order. That is
  the right figure for the question asked, but the label has to say so: "revenue
  from orders containing a reward" is true, and "BOGO revenue" will be read as
  attributable when it is not.
- **Cache invalidation the plugin has no hooks for.** Nothing in `includes/`
  listens to an order's status, so a refunded or cancelled order would sit in a
  cached total until the transient expired. `woocommerce_order_status_changed`
  would be a new dependency.
- **A summary panel rather than a dashboard.** Two to four figures and a date
  range fit above the existing settings form (`BOGO_Select_Admin::render`,
  `class-bogo-admin.php:316`) without a chart library or a second page.

Two edge cases belong on the page rather than in the query. Orders created in
wp-admin or through the REST API never pass through
`woocommerce_checkout_create_order_line_item`, so they are never flagged. And
`repeat` scales the reward quantity rather than adding lines
(`class-bogo-engine.php:198`), so counting distinct orders is correct today and
stays correct if Q-009 ever allows a second reward product.

**Needed:** whether these two figures are the whole ask or the first pair of
many. That answer decides whether this is a panel over order meta or the start of
a reporting surface, and only the second would justify the custom table and the
migration infrastructure that does not exist yet. Also needed: whether the
non-BOGO comparison belongs beside them. The same aggregate over orders without a
reward gives an average order value to read the BOGO one against, which is the
comparison the question is reaching for — but it is correlation and not lift,
since larger baskets are what qualify for the offer to begin with, and the page
would have to say so.

---

### Q-010 — Should Buy and Get support product categories?

**Raised:** 2026-07-31

The plugin scopes both sides of the offer by product ID only. The admin picks
individual products, or variations, through a `wc-product-search` select2 on each
side, governed by `buy_scope` and `get_scope` — each of them today a two-value
choice between *All Products* and *Select Products*. There is no taxonomy lookup
anywhere in the plugin: no `has_term`, no `product_cat`. An admin who wants "any
product in Outerwear" has to list those products individually and re-do it
whenever the category's membership changes.

**Working assumption:** product IDs only, as today. It is listed as out of scope
in `BRIEF.md` §3 and as a known limitation in `README.md`. Nothing is blocked.

**What it would take.** A third scope value, `category`, on each side, plus
`buy_categories` and `get_categories` term-ID lists. Roughly two to three days.
Most of the plumbing is mechanical and three parts are not.

The mechanical part: `to_scope()` in `class-bogo-settings.php` is a two-value
gate that becomes a whitelist, the settings screen gains a third radio and a
`wc-category-search` control on each side — WooCommerce already ships that one,
as the control behind a coupon's Product Categories field — and the empty-list
guards and the settings summary each need a category branch. `SettingsTest.php`
currently asserts that a `buy_scope` of `category` falls back to `all`; that test
is the present contract and would be rewritten rather than fixed. The storefront,
the blocks, and the Store API are very nearly free, because the chooser consumes
IDs out of `get_choice_page()` and does not care how they were chosen, and the
render signature is derived from the cart.

The three parts that are real work:

- **Variations have no categories of their own** — `product_cat` lives on the
  parent post. `is_buy_eligible()` matches a variation on its own ID or its
  parent's, and a category check would have to resolve to the parent first;
  `variation_in_scope()` needs the same. Cost matters here, because
  `count_buy_units()` runs per cart line on every recalculation, so an
  `in_array()` becomes a term lookup. That wants measuring rather than assuming,
  as Q-004 was.
- **A category is a third kind of pager.** `page_selected_choices()` filters an
  in-memory ID list; `page_all_choices()` pages the catalogue in SQL and accepts
  inexact totals as the documented price of paging. A category is neither —
  query-backed like *All Products*, but bounded enough that exact counts become
  affordable, so it could report better totals than *All Products* manages.
  Browsing is easy, since `wc_get_products()` takes a category argument. Search
  is the sharp edge: `store_search()` delegates to
  `WC_Data_Store::search_products()`, whose `$include` parameter constrains by ID
  list and offers no taxonomy constraint at all. Post-filtering is trivial to
  write but makes the 200-result `search_limit()` ceiling materially worse, since
  it would retrieve 200 catalogue-wide matches and then discard most of them.
  Doing it properly means a `tax_query` path in `query_search()`.
- **Cache invalidation is where a bug would hide.** `eligibility_key()` hashes
  the configured ID list and would need the term IDs folded in, but the sharper
  problem is that category membership can change without any of the hooked events
  firing. The cache is flushed on product save, create, delete, and trash, and on
  a settings update; a bulk Quick Edit, a CSV import, or another plugin calling
  `wp_set_object_terms()` fires none of them. Miss this and the chooser offers a
  reward that has left the category.

Tests are the unglamorous bulk of it. Around twenty test files set `buy_scope` or
`get_scope`, and the stub layer has no taxonomy support whatever —
`wc_get_products()` is hand-rolled in `tests/stubs/woocommerce.php` and there is
no `has_term()` at all. Category matching has to be stubbed before the first unit
test can run, and the integration fixtures create products with no categories.

One knock-on worth recording. The settings screen validates the reward list
product by product on save and names anything it removes. A category cannot be
validated that way, because it is a moving set, so the filtering would lean on
the existing render-time `filter_choice_ids()` path instead. Same mechanism, less
warning to the admin.

**Needed:** two answers. Whether a category replaces a product list or adds to
one — a radio makes the choice exclusive, but "buy 2 from Outerwear *or* these
three specific items" is the more useful rule, and a union changes the control
away from radios and roughly doubles the matching logic. And whether this waits
on Q-007, which asks the same data-model question from the other end: category
scoping is the cheapest of the three futures listed there and forecloses none of
them, but if multiple offers are coming then the flat single-option-row settings
shape is rebuilt anyway, and two more keys bolted onto a structure about to be
replaced is work thrown away.

---

### Q-009 — Should the customer be able to pick a different product for each free unit?

**Raised:** 2026-07-31

With Buy 2 / Get 2, the customer picks **one** Get product and receives two of it.
Could they instead pick two different products — one of Item A and one of Item B?

**Working assumption:** one product per cart, as today. `BRIEF.md` R4 states it,
and the code enforces it in three separate places rather than by accident.

**What the plugin does now**, established by running it rather than by reading
the requirement. With Buy 2 / Get 2 and two products on the Get list:

- choosing Item A creates one reward line of quantity 2;
- choosing Item B afterwards *replaces* it — the swap is deliberate, so a refused
  replacement cannot strand the customer with nothing (`DECISION.md` D-012);
- a second reward line forced into the cart by other means is removed on the next
  validation pass, and the customer is told why.

So a second reward product is not merely absent. It is actively prevented.

**What it would take.** The reward is singular throughout, not only in the cart:

- `selected_product_id()` and `selected_variation_id()` name one reward, and the
  Store API publishes both as single values, so the block front end asks "which
  one is chosen?" and would have to start asking "which ones?";
- the chooser marks exactly one card as selected, and its "Choose this instead"
  wording assumes replacement rather than addition;
- `select_gift()` swaps rather than adds, and cart validation culls anything past
  the first reward line;
- the earned quantity is applied to a single line, so it would have to be spread
  across several and rebalanced whenever the cart changes — a customer who earns
  three units and picks two products has to be told what the third one is.

None of that is deep, but it is wide, and it changes the shape of the offer state
that both cart front ends read.

**Needed:** whether this is wanted, and if so, the answer to the question that
decides the interface — must the customer spend *every* earned unit before
checkout, or may they take fewer than they earned? A chooser that tracks "2 of 3
chosen" is a different control from today's pick-one, and the answer also decides
what the cart does when someone removes one of several reward lines.

---

### Q-007 — Multiple offers and offer stacking

**Raised:** 2026-07-30

The brief says Buy and Get are limited to one product item "for now", implying
this may expand.

**Working assumption:** one global offer for v1.0.0 (`DECISION.md` D-001).

**Deferred:** 2026-08-01. The four shapes below were put up for decision and the
answer was to leave the question open and revisit it later. That is a deliberate
deferral rather than an oversight, and it changes nothing: the working assumption
continues to hold, and no part of the plugin waits on it. The shapes were written
for that conversation and are kept here so the next pass starts from them rather
than from the beginning.

**The shapes it could take.** Four, recorded so the question can be picked up
cold. None of them is implied by the code; the answer is a plan rather than a
fact about the plugin.

- **One offer, unchanged.** D-001 becomes the permanent answer rather than a
  provisional one. Costs nothing and forecloses nothing — the settings already
  sit under a single namespaced key, so a later move stays mechanical — and it
  frees Q-010 to build on the flat option row without fear of the work being
  discarded.
- **Multiple concurrent offers.** One record per offer, which means the option
  row becomes a custom post type. The structure is the easy half; the rules are
  the hard one. Which offer wins when two match the same cart, whether a cart may
  earn from more than one at a time, and what the chooser shows when it must
  present several. This is the expensive shape, and it is the one that puts Q-010
  on hold, since keys bolted onto the flat row would be thrown away.
- **Per-category offers.** Folds Q-007 and Q-010 into one piece of work. Still
  needs multiple records, and still owes the taxonomy work Q-010 costs out:
  resolving a variation to its parent's terms, a `tax_query` path through
  `query_search()`, and cache invalidation for membership changes that fire none
  of the hooked events.
- **Tiered thresholds.** Buy 2 get 1, buy 4 get 3, in one offer holding a list of
  steps. `buy_qty` and `get_qty` become a list of pairs instead of two integers,
  so one record and one settings screen still suffice — the cheapest of the three
  expansions on the data model. It is not free elsewhere: it has to agree with
  repeat mode about what happens above the top tier, and it meets Q-009's
  question about whether every earned unit must be spent.

**Needed:** the likely shape of the next iteration, so the data model can be
planned rather than retrofitted.

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

**Amended 2026-08-01.** "The Buy side already handled variable products and
needed no change" was true of the engine and false of the settings screen. The
Buy picker searched products only, so the variation-level Buy list this entry
verified could be reached by hand-editing the option and by no other route. The
picker now searches variations on both sides. What was verified by running it is
now held by two tests in `QualificationTest`, one for a list naming the parent
and one for a list naming a single variation.

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

**Amended 2026-08-01.** "Refuses" was not true when this was written. The screen
displayed the error and saved the reversed window anyway, because
`add_settings_error()` draws a message and does not stop an option being written
(`CODEX-REVIEW.md` M-01). It refuses now, keeping the schedule the store was
already running, and the same is true of a date it cannot read. A past or future
window is still only described, since both are schedules that say something
true.

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

---

### Q-011 — What happens when a reward has fewer units in stock than the offer awards? — **Answered 2026-08-01**

**Answer:** the current behaviour stands, and it stands deliberately rather than
by default. The question was raised in review, the behaviour was established by
reading the code rather than by assuming it, and the recommendation to leave it
alone was accepted. This entry exists because the question was asked and because
the answer is not visible from the settings screen.

**The behaviour**, with a Buy 2 / Get 2 offer and a reward down to one unit: the
chooser keeps the card, greys it out, prints "Not enough stock for 2 free units"
against it, and replaces the Choose button with a disabled "Unavailable" one. The
card is not hidden, and that is the point — a customer who can see the gift and
the reason it is out of reach is better served than one left wondering where it
went.

**It is all or nothing.** The plugin will not award one of the two earned units. A
reward with one left is simply not offerable against a Get 2 offer.

**Three conditions gate it**, and the first two mean that "one left" does not
always block:

- Stock management has to be on at the product level. `unavailable_reason()`
  checks `managing_stock()` first, so a product carrying only an in-stock or
  out-of-stock status has no quantity to test against and stays selectable —
  WooCommerce does not know it is down to one.
- Backorders have to be off; `backorders_allowed()` short-circuits the check.
- *Sold individually* is a rule of its own, and it refuses a Get 2 reward whatever
  the stock level, with "Limited to one per order".

**The customer's own cart counts against it.** `stock_demand()` sums the other
lines drawing on the same stock record, so one unit left alongside one already in
the cart as a paid item tightens the threshold further, and the wording changes to
name the units already held. Lines are matched on `get_stock_managed_by_id()`, so
a variation inheriting its parent's stock competes against the same pool.

**Variable products are judged per variation.** Individual options are disabled,
and the card only goes fully unavailable once every variation has a reason of its
own.

**It is enforced rather than only displayed**, in three places — the chooser as it
renders, the selection request in `class-bogo-ajax.php`, and cart validation on
every pass in `class-bogo-cart.php`. The third matters most: stock can fall away
underneath a cart that has not changed, and when it does the reward is removed and
the customer is told which product and why.

**Why it is right to leave.** Awarding one unit of two earned would mean deciding
whether a customer may take fewer units than they earned, which is the question
Q-009 turns on and has not yet answered. Partial fulfilment here would settle that
by accident, in one corner of the plugin, without either the chooser or the cart
being able to express the result. Refusing the reward outright is the honest
behaviour until the wider question is decided.

**What a store should know**, recorded because nothing on the settings screen says
it: the requirement scales. In repeat mode a customer buying four needs four units
of the reward in stock, so a low-stock gift drops out of the chooser sooner than a
"Buy 2, Get 2" headline suggests, and it does so while its stock still reads as in
stock.
