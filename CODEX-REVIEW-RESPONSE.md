# Response to the Codex Repository Review

**Responding to:** `CODEX-REVIEW.md` (reviewed 2026-07-30 at `8e1b7fe`)
**Response date:** 2026-07-30
**Released as:** v1.1.0 — a MINOR bump under `BRIEF.md` §8.1 (new
customer-facing functionality alongside bug fixes; no public hook was removed or
renamed, and no minimum version was raised).

---

## Summary

Every finding was checked against the code before anything was changed. **All
eight are confirmed** — none was a false positive, and none was overstated.

Seven are fixed in code (F-01 through F-05, F-07, F-08). F-06 is **partially**
addressed: a unit suite and CI now exist and cover the pure logic, but the
WooCommerce integration layer the review rightly called the material release risk
is still uncovered, and is the one item left open below.

| ID | Verdict | Status | Where |
|---|---|---|---|
| F-01 | Confirmed | Fixed | `class-bogo-cart.php`, `class-bogo-engine.php` |
| F-02 | Confirmed | Fixed | `class-bogo-engine.php`, `class-bogo-frontend.php`, `class-bogo-ajax.php`, `bogo-select.js` |
| F-03 | Confirmed | Fixed | `class-bogo-ajax.php`, `class-bogo-cart.php` |
| F-04 | Confirmed (both parts) | Fixed | `class-bogo-engine.php`, `class-bogo-cart.php`, `class-bogo-ajax.php`, `BRIEF.md` |
| F-05 | Confirmed | Fixed | `bogo-select.php`, `INSTRUCTIONS.md`, `DECISION.md` D-013 |
| F-06 | Confirmed | **Partially fixed — see below** | `tests/`, `.github/workflows/ci.yml` |
| F-07 | Confirmed | Fixed | `class-bogo-cart.php` |
| F-08 | Confirmed (all four bullets) | Fixed | `BRIEF.md`, `DECISION.md`, `INSTRUCTIONS.md`, `class-bogo-engine.php`, `class-bogo-admin.php` |

Test totals after the change: **71 unit tests, 146 assertions, all passing** on
PHP 8.1; CI runs the same suite on 7.4 through 8.3.

---

## F-01 — Existing gifts can remain selected after stock becomes insufficient

**Verdict: confirmed, and the severity is right.**

Verified at `includes/class-bogo-cart.php:116-142` (v1.0.0): the only call to
`unavailable_reason()` sat inside `if ( $earned !== $current )`. Confirmed too
that `is_get_eligible()` checks purchasability and product type but never stock,
so nothing else in the validation path looked at it. A cart whose earned quantity
was stable therefore kept an unbuyable gift until WooCommerce's own
`check_cart_item_stock` blocked checkout — a checkout failure, not the
self-healing cart `BRIEF.md` §4.4 promised.

**Clarification worth adding to the finding.** The window is wider than "stock
drops". The same branch also skipped the *sold-individually* check, so a gift
could keep a quantity that rule forbids as long as the earned quantity did not
move.

### Fix

`validate()` was restructured into `run_validation()`, which now checks
availability on **every** pass, before the quantity comparison:

```php
$other_demand = BOGO_Select_Engine::stock_demand( $cart, $product, $key );
$reason       = BOGO_Select_Engine::unavailable_reason( $product, $earned, $other_demand );

if ( $reason ) {
    $this->drop( $cart, $key, /* … */ );
    return;
}
```

Also done, as the review recommended:

- **Total cart demand is counted.** New `BOGO_Select_Engine::stock_demand()` sums
  every cart line sharing the target's `get_stock_managed_by_id()` — so a
  variation inheriting its parent's stock counts against the same pool — and
  excludes the gift's own line. `unavailable_reason()` takes that as a third
  `$other_demand` argument (optional, so the signature stays compatible) and
  reports a distinct message when other cart lines are what tipped it over.
- **`woocommerce_cart_item_restored` is now hooked**, so an undone removal cannot
  re-enter the cart under stale rules.

### Tests added

`tests/CartValidationTest.php`: stock sufficient → insufficient with the earned
quantity unchanged; outright out-of-stock; backorders on and off; paid and free
copies sharing one stock record. `tests/AvailabilityTest.php` covers the
`$other_demand` arithmetic and `stock_demand()` directly, including the
exclude-key behaviour. Restoration is covered by the same validation path but not
by a hook-level test — see F-06.

---

## F-02 — "All Products" is incomplete and has no catalogue search

**Verdict: confirmed.** `get_choice_ids()` queried exactly 50 simple products
ordered by title, and the cart UI rendered that fixed set. Products 51 onward
were unreachable, contradicting `BRIEF.md:174`, `INSTRUCTIONS.md:104`, and
`INSTRUCTIONS.md:115`.

The review's judgement that simply removing the cap would trade a correctness bug
for a performance one is right, and its recommendation — bounded pages plus
search — is what was implemented. Its observation that *Select Products* scope was
itself unbounded is also correct, and is addressed by the same change.

### Fix

- `BOGO_Select_Engine::get_choice_page( array $args )` returns
  `[ 'ids', 'page', 'pages', 'total' ]` for a given search term and page. **Both**
  scopes are paged: *All Products* through a paginated `wc_get_products()` query,
  *Select Products* by filtering and slicing the configured list, so a long
  curated list is bounded too.
- Search matches product name and SKU. In *All Products* it is pushed into the
  query; in *Select Products* it filters the configured list.
- New public AJAX endpoint `bogo_select_choices` (nonce + the same qualification
  re-checks as `choose`) returns rendered cards for a page. The card markup moved
  into `BOGO_Select_Frontend::render_choices()`, static, so AJAX and the initial
  server render produce identical HTML.
- The cart UI gains a search box and Previous/Next controls, shown only when there
  is more than one page. Paging swaps the grid in place; only cart *mutations*
  reload the page.
- `get_choice_ids()` is kept, now returning the first page.

**Filter compatibility.** `bogo_select_all_products_limit` is retained but now
means *page size* (default 24, was a hard cap of 50). Keeping the name avoids the
MAJOR bump that removing a public hook would require under §8.1; the change of
meaning is documented in `DECISION.md` D-011 and `INSTRUCTIONS.md`.

**Known limitation, deliberately accepted.** Eligibility filtering
(`is_get_eligible`) runs *after* the query pages, so a page containing
non-purchasable products yields fewer than `per_page` cards and `total` counts
pre-filter matches. Filtering before paging would mean loading the whole
catalogue on every cart view — the exact problem being avoided. This is
documented rather than hidden.

### Tests added

`tests/ChooserPagingTest.php` builds a 60-product catalogue and asserts the
first, fiftieth, and last are all reachable; that walking every page yields all
60; that "Gift 55" (past the old cap) is findable by search; SKU search; page
size filtering; clamping past-the-end pages; empty results; ineligible products
dropped from a page; and the same for *Select Products* scope.

---

## F-03 — Gift replacement is not atomic

**Verdict: confirmed.** `choose()` removed the existing gift at lines 63-68 and
added the replacement at 72-81; the failure path at 83-92 reported the error and
returned without restoring anything. Every rejection route the review names is
real: aggregate cart stock, `sold_individually`, and any third-party
`woocommerce_add_to_cart_validation` callback all run inside
`WC_Cart::add_to_cart()`, after the plugin's own preliminary checks have passed.

### Fix

The replacement is now one operation, ordered add-then-remove:

```php
BOGO_Select_Cart::suspend();
$key = $cart->add_to_cart( /* … */ );

if ( ! $key ) {
    BOGO_Select_Cart::resume();
    $this->fail( $message );   // the previous gift is still in the cart
}

if ( $existing && $existing !== $key ) {
    $cart->remove_cart_item( $existing );
}

BOGO_Select_Cart::resume();
```

Two supporting pieces:

- **Validation suspension.** For the moment both lines coexist, the F-04
  duplicate-normalising pass would cull one of them. `BOGO_Select_Cart::suspend()`
  / `resume()` is a nesting-counted static guard around the swap. This is the
  snapshot-and-restore alternative the review offered, done without snapshots —
  the cart is never in a state that needs restoring.
- **Same-product re-selection short-circuits.** Choosing the gift already held now
  just corrects its quantity and returns. This matters because the random stamp
  removed under F-04 was what previously kept identical gift lines from merging;
  without the short-circuit, adding the same gift twice would merge into the line
  about to be removed. The `$existing !== $key` guard is a second line of defence.

Stock arithmetic for the swap excludes the outgoing gift's own units, so a
replacement is not made to compete with the gift it replaces.

### Tests added

`tests/CartValidationTest.php::test_validation_can_be_suspended_and_resumed`
covers the guard. The rejected-add path itself depends on
`WC_Cart::add_to_cart()` and is **not** unit-testable against stubs — it is listed
in `tests/README.md` as needing integration coverage (F-06).

---

## F-04 — Multiple free lines are not normalized, and the documented token is unused

**Verdict: confirmed on both counts, and the review's framing is right** — this is
integrity hardening, not a demonstrated exploit. No public request parameter
creates a second flagged line; the asymmetry is nonetheless real and worth
closing.

**Part one — duplicates.** Confirmed: `find_reward_key()` returned on the first
match, and validation, AJAX removal, and replacement all worked from that single
key, while `set_reward_price()` zeroed *every* flagged line. Extra gifts were
therefore free but unchecked, violating `BRIEF.md` §4.3's one-reward-line
invariant.

**Part two — the token.** Confirmed: `BRIEF.md:109-110` specified a
settings-derived `_bogo_select_token`; the code wrote a random
`wp_generate_uuid4()` under the key `bogo_select_stamp` and never read it back.
The advertised provenance mechanism did not exist.

### Fix — duplicates

`BOGO_Select_Engine::find_reward_keys()` returns every flagged key;
`find_reward_key()` is now a thin wrapper over it, so existing callers are
unaffected. `run_validation()` keeps the first key, removes the rest with a
customer notice, and *then* judges the survivor — so a duplicate can never shield
an invalid gift from removal.

### Fix — the token

Taking the second option the review offered: **the token requirement is removed
from the specification, and the unused stamp is removed from the code.**

The reasoning, now recorded in `BRIEF.md` §4.3: a settings hash can only report
that something changed. Validation already re-derives every answer from current
state — offer active, gift still eligible, earned quantity, availability — on
every pass, which is strictly stronger. A stamp would add a second source of
truth capable of disagreeing with the first, and could not be trusted anyway,
since it lives in the same session data it would be vouching for.

Deleting the random stamp has a second benefit noted under F-03: it was making
every gift line unique, so identical gift lines could not merge.

### Tests added

`tests/CartValidationTest.php`: two flagged lines reduced to one with a notice;
duplicates dropped *and* the survivor still judged (an out-of-stock gift with a
duplicate is fully removed). `tests/QualificationTest.php` covers
`find_reward_keys()` ordering and the empty case. Settings-changed-after-selection
is covered by the offer-disabled and left-the-gift-list tests.

---

## F-05 — Dependency lifecycle behavior does not match the documentation

**Verdict: confirmed.** `bogo-select.php:48-54` registered an admin notice and
returned; the activation hook only stored the version. The plugin stayed
activated but inert, and `INSTRUCTIONS.md:30-31` described neither behaviour
accurately. The WooCommerce 7.0 minimum was declared only in the `WC requires at
least` header, never checked at runtime.

### Fix

Both halves of the review's recommendation, split by what each is actually good
for:

- **Activation is blocked, as documented.** A `Requires Plugins: woocommerce`
  header handles WordPress 6.5+; because the plugin supports 6.0, the activation
  hook also checks and, if unsatisfied, calls `deactivate_plugins()` and
  `wp_die()`s with the reason.
- **`WC_VERSION` is checked against 7.0** before any plugin class loads, via
  `bogo_select_dependency_problem()`, which both the bootstrap and the activation
  guard share.
- **Runtime stays inert-with-notice, and the documentation now says so.**
  Self-deactivating mid-request was deliberately *not* implemented: it would fire
  on any request where WooCommerce is briefly unavailable, and turning a plugin
  off behind the owner's back is worse than a loud notice. Settings survive
  untouched. `DECISION.md` D-013 records this.

The notice now names the actual problem (missing versus too old) rather than
assuming absence.

---

## F-06 — Core commerce behavior has no automated verification

**Verdict: confirmed.** No `tests/`, no PHPUnit or Composer configuration, no CI.
The review's point that this is the material release risk stands.

### What was done

A Composer setup, a PHPUnit suite, and a GitHub Actions workflow now exist:

- `tests/stubs/` — small WordPress and WooCommerce stand-ins (options, a working
  hook registry, products, a cart, notices, a queryable fake catalogue). No
  database, no WordPress install.
- **71 tests, 146 assertions**, covering the review's items 1 and 4 in full:
  settings normalization and sanitization, eligibility, cart counting, repeat
  mode, filtered quantities, availability, chooser paging and search, and cart
  validation.
- CI runs `php -l`, `node --check`, `bash -n`, and the suite on PHP 7.4, 8.0, 8.1,
  8.2, and 8.3.

Two of the new tests fail against v1.0.0 by construction — they are the
regression locks for F-01 and F-02.

### What is still open

**This does not close the finding.** Items 2 and 3 of the review's
recommendation — WooCommerce integration tests and a WooCommerce version matrix —
are not done. Unit stubs cannot exercise:

- hook timing and ordering against real WooCommerce, including whether the
  priority-20 price override survives a third-party pricing plugin;
- cart session serialisation and restoration between requests;
- `WC_Cart::add_to_cart()` validation, which is precisely the path F-03 hardened;
- order line-item creation, order meta, checkout, tax on a $0.00 line, and stock
  reduction on completion;
- rendered templates, AJAX transport, nonce verification, and `WP_DEBUG` output.

Closing it needs a `wp-env`-based harness running against the declared minimum and
current WooCommerce releases. Until then, `INSTRUCTIONS.md` §7 remains the manual
gate, and the exclusions are written down in `tests/README.md` rather than left
implicit.

WordPress Coding Standards (`phpcs`) and PHP compatibility rules, item 4 of the
recommendation, are also not wired in — `php -l` across the matrix is the current
substitute.

---

## F-07 — Multi-unit gift subtotal displays the wrong original amount

**Verdict: confirmed.** One `label_price()` callback was attached to both
`woocommerce_cart_item_price` and `woocommerce_cart_item_subtotal`, and always
formatted a single unit. Eight $10 gifts struck through $10 in the subtotal
column.

### Fix

Split as recommended. `label_price()` renders one unit; new `label_subtotal()`
multiplies by the line quantity. Both delegate to a shared `free_markup()` that
passes `array( 'qty' => $qty )` to `wc_get_price_to_display()`, so tax is applied
to the whole line rather than to a unit price that is then multiplied — which
matters where per-unit rounding differs from line rounding.

### Tests added

`tests/CartValidationTest.php`: eight $10 gifts strike through 80.00 in the
subtotal and 10.00 in the unit column; both callbacks leave paid lines untouched.

---

## F-08 — Documentation and implementation have drifted

**Verdict: all four bullets confirmed.**

| Bullet | Verified | Resolution |
|---|---|---|
| `DECISION.md` D-008 says block stores "see the notice but not the chooser", but `maybe_render_notice()` returns on cart and checkout | Correct — and the decision's body text also wrongly claimed checkout shows a notice | D-008 amended. Shop and product pages do show the notice, so block stores get a notice pointing at a cart with no chooser — which is *why* the blocks are declared incompatible. Documented rather than changed: suppressing the checkout notice is intended. |
| `BRIEF.md:109` names the cart flag `_bogo_select_free`; runtime uses `bogo_select_free` | Correct; only the order meta key is underscored, and `INSTRUCTIONS.md:292` already had it right | `BRIEF.md` §4.3 corrected. The code is the authority here — the underscore prefix hides meta on the order screen, which is wanted there and meaningless in cart item data. |
| `BRIEF.md:155` claims front-end AJAX has "nonce + capability checks" | Correct; the endpoints are `wp_ajax_nopriv_*` by design, with nonce plus business-rule checks | `BRIEF.md` §5 corrected, with a paragraph explaining *why* they are public and what replaces a capability check. |
| Runtime eligibility rejects `variable`/`grouped`/`external` but not a `variation` object | Correct, in both the engine and the settings sanitizer | Tightened rather than documented: both now reject `variation`. It was unreachable through the admin picker (`woocommerce_json_search_products` excludes variations), but a hand-edited option row could reach it, and "simple products only" should be enforced, not merely likely. `DECISION.md` D-006 amended; the admin warning text updated. |

Beyond the four bullets, the same pass updated `BRIEF.md` §4.4 (the real
re-validation trigger list), §4.5 and §6 (paging, atomic replacement, and six new
acceptance criteria covering the fixed defects), §7 (risks), `INSTRUCTIONS.md`
(dependency lifecycle, paged chooser, stock wording, troubleshooting, the new
filter), and `README.md`.

New decision records: **D-011** (paged chooser), **D-012** (add-before-remove
replacement), **D-013** (dependency lifecycle).

---

## Notes on the review's other observations

**Objective coverage table.** With this release, "Independent Buy/Get scopes"
becomes fully implemented, "Self-healing cart" becomes fully implemented, and
"One reward product per cart" is now enforced globally rather than only at the
first line. "Gift is a real $0 order line reducing inventory" and "Classic
cart/checkout support" remain *implemented, runtime unverified* — F-06's open
half is exactly that gap.

**Remaining risks.** The review's point that products are loaded once for
eligibility filtering and again for rendering still stands; it is bounded now by
page size rather than by catalogue size, which was the substance of the concern.
The priority-20 pricing caveat is unchanged and now stated in `BRIEF.md` §7 as a
limitation rather than a mitigation.

**Packaging.** `bin/build-zip.sh` now excludes `tests/`, `vendor/`, `composer.*`,
`phpunit.xml.dist`, `.github/`, and the two review documents — development
records, not plugin documentation. Verified: `dist/bogo-select-1.1.0.zip` has one
top-level `bogo-select/` directory, passes `unzip -t`, and
`dist/bogo-select-1.0.0.zip` is retained per §8.3.

---

## Release gate status

Against the review's own gate:

| # | Gate | Status |
|---|---|---|
| 1 | Fix F-01 and F-02 | Done |
| 2 | Atomic replacement (F-03), normalize duplicate rewards (F-04) | Done |
| 3 | Integration coverage for qualification, selection, session restoration, checkout, stock reduction (F-06) | **Not done** — unit coverage only |
| 4 | Resolve dependency and documentation mismatches (F-05, F-08) | Done |
| 5 | WPCS, PHP compatibility checks, integration suite against declared minimums | **Partial** — `php -l` on 7.4–8.3 in CI; no `phpcs`, no integration suite |

**Recommendation:** v1.1.0 is a clear improvement on v1.0.0 and closes both
high-severity findings, but gate items 3 and 5 remain open. The plugin should
still be exercised manually against a real WooCommerce install — `INSTRUCTIONS.md`
§7 plus the swap-rejection and stock-drop cases above — before it is treated as
production-ready.

Per `BRIEF.md` §8.4, tagging `v1.1.0` and publishing the GitHub release with the
zip attached has **not** been done here; that step is left to you.
