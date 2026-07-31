# Codex Repository Review

**Reviewed:** 2026-07-30  
**Revision:** `8e1b7fe`  
**Scope:** PHP, JavaScript, CSS, release tooling, the built `1.0.0` archive, and all
project documentation in this repository.

## Executive assessment

The repository has a clear, compact architecture and unusually good product
documentation for its size. The main classic-cart flow is implemented coherently:
settings are sanitized, qualification is recalculated server-side, gift lines are
distinguished from paid lines, their price is forced to zero, their quantity is
locked in the UI and corrected server-side, and order metadata is added so normal
WooCommerce stock reduction can operate.

The implementation is suitable for staging, but it should not be treated as
production-ready for the stated `1.0.0` objectives yet. Two high-severity
correctness gaps remain:

1. Existing gifts are not rechecked for stock unless their earned quantity changes.
2. “All Products” exposes only the first 50 simple products and provides no search,
   despite the acceptance criterion promising the whole catalogue.

There are also material resilience and lifecycle gaps around gift replacement,
duplicate reward lines, dependency handling, and the absence of automated
WordPress/WooCommerce tests.

No obvious SQL injection, stored/reflected XSS, unauthenticated privilege
escalation, or direct “mint a free gift” path was found in this static review.
Public AJAX requests use nonces and repeat the important qualification, product
eligibility, quantity, and stock checks on the server. The security concern below
is primarily defense-in-depth around cart integrity rather than a demonstrated
standalone exploit.

## Prioritized findings

| ID | Severity | Area | Finding |
|---|---|---|---|
| F-01 | High | Correctness / inventory | Stock is not continuously revalidated when the earned quantity is unchanged. |
| F-02 | High | Objectives / performance | “All Products” silently means the first 50 products, not the whole catalogue. |
| F-03 | Medium | Correctness / UX | Changing gifts removes the current gift before the replacement is known to be addable. |
| F-04 | Medium | Security / integrity | Only the first reward line is validated or removed; the documented settings token does not exist. |
| F-05 | Medium | Compatibility / lifecycle | WooCommerce dependency behavior contradicts the installation documentation. |
| F-06 | Medium | Quality / regression risk | There is no automated test suite or WordPress/WooCommerce runtime harness. |
| F-07 | Low | Correctness / presentation | Gift subtotals strike through a unit price rather than the line total. |
| F-08 | Low | Documentation / maintainability | Several implementation details have drifted from the specification. |

### F-01 — Existing gifts can remain selected after stock becomes insufficient

`BOGO_Select_Cart::validate()` calls
`BOGO_Select_Engine::unavailable_reason()` only inside the
`$earned !== $current` branch (`includes/class-bogo-cart.php:116-142`). If stock
drops while the customer still earns the same gift quantity, the validation hooks
run but never inspect stock. `is_get_eligible()` checks purchasability and product
type, not stock (`includes/class-bogo-engine.php:163-180`).

This directly contradicts `BRIEF.md:116-124`, which says a reward that becomes
out of stock or falls below the required quantity is removed with a notice.
WooCommerce may still block checkout through its own stock validation, but that is
a checkout failure rather than the promised self-healing cart.

**Recommendation:** Check `unavailable_reason()` on every validation pass, before
comparing quantities. The check should account for total cart demand against the
stock-managed product ID, including paid and free lines. Also validate on
`woocommerce_cart_item_restored` so an undone removal cannot bypass the current
rule state.

**Tests to add:** Stock changes from sufficient to insufficient while earned
quantity stays constant; backorders on/off; paid and free copies sharing stock;
restoring a removed gift.

### F-02 — “All Products” is incomplete and has no catalogue search

For Get scope `all`, `get_choice_ids()` queries only 50 simple products ordered by
title (`includes/class-bogo-engine.php:219-239`). The cart UI renders that fixed
set; there is no paging or search endpoint. Products after the first 50 are
therefore impossible to select.

This conflicts with:

- `BRIEF.md:174`: “lets the chooser search the whole catalogue”;
- `INSTRUCTIONS.md:104`: “any purchasable product in your catalogue”;
- `INSTRUCTIONS.md:115`: describes a large catalogue as producing a long chooser.

Removing the limit would satisfy the wording but create a substantial cart-page
performance problem. Selected-product scope is already unbounded, and rendering
loads and formats each product individually.

**Recommendation:** Make the chooser paginated or searchable over AJAX, keeping a
bounded page size while allowing every eligible product to be reached. If that is
out of scope, rename/document the setting as a capped catalogue sample and remove
the unmet acceptance criterion.

**Tests to add:** A catalogue of at least 60 eligible products; page/search access
to the first, fiftieth, and last product; unavailable and non-simple products in
the result set.

### F-03 — Gift replacement is not atomic

The choose endpoint removes the existing gift at
`includes/class-bogo-ajax.php:63-68`, then attempts to add the new product at
lines 72-81. WooCommerce or another extension can still reject the add after the
plugin’s preliminary checks—for example because of aggregate cart stock, a
sold-individually rule, or an `add_to_cart_validation` filter. The error path at
lines 83-92 does not restore the old gift.

The customer is left with no gift even though the request was presented as a
change of selection.

**Recommendation:** Treat replacement as one operation: retain the existing cart
item until the new item is successfully added, then remove the old item. If hook
ordering makes that unsafe, snapshot enough cart-item data to restore the original
on failure.

**Tests to add:** A replacement rejected by core stock validation and a replacement
rejected by a third-party `woocommerce_add_to_cart_validation` callback.

### F-04 — Multiple free lines are not normalized, and the documented token is unused

`find_reward_key()` returns the first flagged line and stops
(`includes/class-bogo-engine.php:275-288`). Validation, AJAX removal, and gift
replacement all operate on that one key. In contrast, price calculation sets
**every** flagged line to zero (`includes/class-bogo-cart.php:49-55`).

If duplicate flagged lines arise through a malformed/stale session, extension
interaction, import, or race, extra gifts remain free but are not checked or
removed. This violates the “only one reward line” invariant in `BRIEF.md:114`.
There is no obvious public request parameter that directly creates such a line,
so this is an integrity hardening issue, not a confirmed unauthenticated exploit.

The specification also requires a settings-derived `_bogo_select_token`
(`BRIEF.md:109-110`). The code writes a random `bogo_select_stamp`
(`includes/class-bogo-ajax.php:77-80`) and never validates it. Current settings are
rechecked field by field, but the advertised provenance mechanism provides no
protection because it is not implemented.

**Recommendation:** Enumerate all reward keys during validation, retain at most
one valid reward, and remove all others. Either implement and verify a
settings-derived stamp or delete the token requirement and explain why current
state validation is sufficient.

**Tests to add:** Two flagged lines in a restored session; settings changed after
selection; invalid/missing stamp; removal and replacement when duplicates exist.

### F-05 — Dependency lifecycle behavior does not match the documentation

`INSTRUCTIONS.md:30-31` says the plugin will not activate without WooCommerce and
will deactivate itself if WooCommerce is later removed. The bootstrap instead
registers only an admin notice and returns when the `WooCommerce` class is absent
(`bogo-select.php:48-54`). Its activation hook only stores the version
(`bogo-select.php:85-89`). The plugin therefore remains activated but inert.

The stated WooCommerce 7.0 minimum is also not checked in code. The custom
`WC requires at least` header may be displayed by WooCommerce tooling, but the
runtime currently accepts any version that defines the main class.

**Recommendation:** Add an explicit dependency declaration where supported and an
activation/runtime guard for WordPress versions that do not enforce it. Check
`WC_VERSION` against 7.0 before loading plugin classes. Alternatively, change the
documentation to accurately describe an inert-with-notice lifecycle.

### F-06 — Core commerce behavior has no automated verification

No `tests/` directory, PHPUnit configuration, Composer configuration, JavaScript
test configuration, or CI workflow is present. The repository calls the engine
“pure, testable,” but none of its boundary cases are executable here. Static
syntax checks cannot verify hook timing, session restoration, order-line
creation, stock reduction, tax behavior, or compatibility with supported
WordPress/WooCommerce versions.

This is a material release risk because the most important acceptance criteria
depend on WooCommerce lifecycle behavior rather than syntax.

**Recommendation:** Add:

1. Unit tests for settings normalization, eligibility, cart counting, repeat mode,
   and filtered quantities.
2. WooCommerce integration tests for AJAX choose/change/remove, cart-session
   restoration, totals, checkout, order metadata, and stock reduction.
3. A version matrix covering PHP 7.4 and at least the minimum and current supported
   WooCommerce releases.
4. Static checks such as WordPress Coding Standards and PHP compatibility rules.

### F-07 — Multi-unit gift subtotal displays the wrong original amount

The same `label_price()` callback handles both unit price and subtotal filters
(`includes/class-bogo-cart.php:39-42`). It always displays one unit’s normal price
(`includes/class-bogo-cart.php:196-207`). For eight $10 gifts, the subtotal column
strikes through $10 rather than $80.

This does not affect charged totals, but it understates the promotion and is
visibly incorrect for the arbitrary Get quantities that are a core feature.

**Recommendation:** Use separate unit-price and subtotal callbacks, multiplying
the display price by cart quantity for the subtotal.

### F-08 — Documentation and implementation have drifted

In addition to the token and dependency discrepancies above:

- The decision log says Cart/Checkout blocks “will see the notice but not the
  chooser” (`DECISION.md:146-147`), but the notice callback explicitly returns on
  cart and checkout pages (`includes/class-bogo-frontend.php:177`). Block pages
  receive neither component from this plugin.
- The brief names the cart flag `_bogo_select_free`
  (`BRIEF.md:109`), while runtime cart data uses `bogo_select_free`; only order
  metadata uses the underscored key.
- The technical class table describes front-end AJAX as having “nonce + capability
  checks” (`BRIEF.md:155`). The endpoints are intentionally public for guest
  shoppers and have nonce plus business-rule checks, not capability checks.
- Runtime eligibility rejects `variable`, `grouped`, and `external` products but
  does not reject a `variation` product object
  (`includes/class-bogo-engine.php:171-177`). The settings sanitizer follows the
  same rule. That is looser than the documentation’s “simple products only”
  language and should be either explicitly supported and tested or rejected.

**Recommendation:** Make the brief authoritative and update code to match it, or
revise the brief/decision log in the same change whenever the design changes.

## Objective coverage

| Objective | Assessment | Notes |
|---|---|---|
| Customer chooses one free product | Mostly implemented | Works through classic cart UI and AJAX; replacement failure and duplicate-line cases remain. |
| Gift is a real $0 order line reducing inventory | Implemented, runtime unverified | Price override and order metadata are present; actual order stock reduction requires WooCommerce integration testing. |
| Arbitrary positive Buy/Get quantities | Implemented | Settings clamp values to at least one; repeat math matches the brief. |
| Independent Buy/Get scopes | Partially implemented | Select scope is implemented; Get “All Products” is capped at 50. |
| Server-side tamper resistance | Good baseline | Nonce, qualification, eligibility, quantity, and availability are rechecked on selection. Duplicate reward normalization is missing. |
| Self-healing cart | Partially implemented | Qualification and quantity changes heal; unchanged-quantity stock changes do not. |
| One reward product per cart | Intended but not enforced globally | Helpers inspect only the first reward line. |
| Classic cart/checkout support | Implemented by hooks, runtime unverified | Blocks are explicitly declared incompatible. |
| Uninstall cleanup | Implemented | Both plugin options are removed, including multisite iteration. |
| Release archive | Valid | `dist/bogo-select-1.0.0.zip` has one top-level plugin directory and passes archive integrity testing. |

## Quality, performance, and security notes

### Strengths

- Responsibilities are separated cleanly across settings, engine, cart, AJAX,
  front-end, and admin classes.
- The settings option is normalized on both read and save.
- Direct request data used by AJAX is unslashed and converted with `absint`.
- Front-end/admin output is consistently escaped or passed through an appropriate
  allow-list helper.
- Admin settings use the Settings API and a `manage_woocommerce`-protected page.
- Public AJAX selection does not trust the submitted product or quantity.
- Reward items are excluded from Buy counting, preventing self-qualification.
- The all-products query is bounded, and cart validation is linear in cart size;
  performance should be acceptable for ordinary small catalogues/carts.
- The build script checks version consistency, stages into the expected top-level
  directory, refuses to overwrite an existing release, and cleans up its temporary
  directory.

### Remaining risks

- Fixing F-02 by simply removing the 50-product cap would turn correctness into a
  performance problem. Search/pagination is the appropriate design.
- Select scope can contain an unbounded product list and renders every choice on
  each qualifying cart view.
- Product objects are retrieved during eligibility filtering and again during
  rendering. WooCommerce caching limits database cost, but large choice lists still
  incur object, image, price, and markup work.
- Pricing at hook priority 20 is not an absolute guarantee; another extension can
  mutate the price later. This limitation is already documented, but an integration
  compatibility test would make failures visible.
- The plugin has no direct database queries, file uploads, dynamic code execution,
  or custom HTML input, which keeps its attack surface comparatively small.

## Verification performed

- `php -l` passed for all nine PHP files.
- `node --check` passed for both JavaScript files.
- `bash -n bin/build-zip.sh` passed.
- `unzip -t dist/bogo-select-1.0.0.zip` passed.
- The archive contains one `bogo-select/` top-level directory and excludes
  `.git/`, `dist/`, and `bin/`.
- No test/configuration files were found.
- `phpcs` and `phpunit` are not installed in the review environment.

This was a static repository review. A running WordPress/WooCommerce test
environment was not present, so acceptance criteria involving rendered templates,
AJAX sessions, checkout, `WP_DEBUG`, taxes, and physical stock reduction were not
executed. Those criteria should remain unverified until F-06 is addressed.

## Recommended release gate

Before the next production release:

1. Fix F-01 and F-02.
2. Make replacement atomic (F-03) and normalize duplicate rewards (F-04).
3. Add integration coverage for qualification, selection, session restoration,
   checkout, and stock reduction (F-06).
4. Resolve the dependency and documentation mismatches (F-05 and F-08).
5. Run WordPress Coding Standards, PHP compatibility checks, and the integration
   suite against the declared minimum versions.
