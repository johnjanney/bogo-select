# Codex Repository Review

**Review date:** 2026-07-31

**Reviewed state:** commit `49dd5e5` (`main`, clean worktree before this report)

**Overall assessment:** the percentage-reward implementation is well designed and
works on the current WooCommerce release in both classic and block cart/checkout
flows. No critical or high-severity quality, performance, or security defect was
found. The code is not release-ready under the repository's own rules, however:
the feature still identifies itself as 1.2.1, the existing 1.2.1 archive contains
the already-published pre-feature code, and several documents simultaneously call
the feature "Unreleased" and "shipped." Integration coverage also does not test
the declared WooCommerce 7.0 minimum and covers only part of the new discounted
path.

## Executive summary

Claude Code's earlier fixes remain correctly implemented:

- block chooser injection runs after WooCommerce's block decoration at
  `render_block` priority 20;
- the Store API/hydration scoping and four-member item-data label are intact;
- the cart-validation and gift-swap guards use `finally`;
- ZIP parity has a mechanical verifier and CI job;
- the accepted catalogue browse-count, bounded-search, and cold-cache trade-offs
  are documented.

The new percentage feature is also fundamentally sound:

- old settings rows default to a free reward;
- discount type and percentage are normalized and clamped server-side;
- the reward is repriced from the current catalogue selling price on every totals
  pass, avoiding self-compounding;
- free, 0%, fractional, and 100% cases are handled deliberately;
- sale prices, currency precision, tax-aware display helpers, classic labels,
  Store API metadata, and order-line metadata are routed through shared pricing
  and vocabulary helpers;
- the reward remains a real inventory-bearing cart/order line;
- client requests cannot choose an ineligible reward or supply their own price.

Live testing from a temporary ZIP built from `49dd5e5` confirmed on WordPress
7.0.2, PHP 8.2, MariaDB 10.11, and WooCommerce 10.9.4:

- free and 50%-off rewards in the classic cart;
- free and 50%-off rewards in classic checkout;
- free and 50%-off rewards in the Cart block;
- free and 50%-off rewards in the Checkout block;
- correct $10 -> $0 and $10 -> $5 line prices, $25/$30 totals, fixed reward
  quantity, promotion labels, chooser wording, and a fully mounted checkout;
- the real block root retained `data-block-name="woocommerce/checkout"`, while
  the BOGO slot did not receive it.

WooCommerce 7.0.0 was also exercised. Its fresh installation provisions classic
cart/checkout pages; those classic pages and the free reward worked. The Store
API extension functions existed and block-mode gift selection succeeded after a
Cart block was introduced, but I did not obtain a canonical full WooCommerce 7
Cart/Checkout block layout in that temporary fixture. Full block compatibility
at the declared minimum therefore remains unproved, which is precisely the gap
in M-02.

## Findings

### M-01 — The unreleased feature still has the immutable 1.2.1 identity

**Severity:** Medium; release blocker

**Status:** Open

The percentage setting is a new customer-facing feature and new settings schema.
`BRIEF.md` section 8.1 explicitly requires a MINOR bump for that kind of change,
but the plugin header and `BOGO_SELECT_VERSION` still say 1.2.1
(`bogo-select.php:5-6`, `:23`). The local `dist/bogo-select-1.2.1.zip` is the
published pre-feature build. Running `bash bin/verify-zip.sh` correctly reports
eight stale runtime files, including the engine, cart, settings, admin, frontend,
Blocks, AJAX, and admin JavaScript.

This produces two bad states:

1. In this working directory the append-only build script cannot create the new
   archive without overwriting/removing the genuine 1.2.1 artifact.
2. On a clean CI checkout, `dist/` is absent, so CI builds the new feature under
   the already-released filename and internal version `1.2.1`. Package parity
   passes, but release identity does not.

The documentation reflects the same contradiction. `CHANGELOG.md:8` correctly
places the feature under **Unreleased**, while `OPEN-QUESTIONS.md:155` says it
"has shipped." The changelog's `[Unreleased]` comparison still starts at v1.1.0
and has no v1.2.0/v1.2.1 link definitions (`CHANGELOG.md:339-341`). The main
plugin and Composer descriptions still advertise only a free $0 reward
(`bogo-select.php:5`, `composer.json:3`).

**Recommendation:** before publication, bump both version locations to 1.3.0,
move the changelog entry into a dated 1.3.0 section, repair all comparison links,
change "has shipped" to "implemented/unreleased" until the release exists,
update package descriptions, build `dist/bogo-select-1.3.0.zip`, run the parity
gate, and tag/publish that exact commit. Do not replace the real 1.2.1 archive.

### M-02 — CI's claimed compatibility floor is 2.9 major versions above the declared floor

**Severity:** Medium; compatibility risk

**Status:** Open

The plugin declares `WC requires at least: 7.0` (`bogo-select.php:15`), but the
integration matrix starts at 9.9.5 (`.github/workflows/ci.yml:84-88`). Both the CI
comment and `BRIEF.md:364-367` call 9.9.5 "the compatibility floor." It is not.
Unit stubs cannot establish that WooCommerce 7.x through 9.8 preserve the Store
API, hydration, block rendering, quantity-bound, price-display, and checkout
contracts the plugin uses.

The limited WooCommerce 7.0 live pass is encouraging: classic cart/checkout
worked and the Store API accepted a block-mode reward selection. It does not
replace a canonical Cart and Checkout block run, nor cover the intervening major
versions.

**Recommendation:** either add 7.0.x to the real integration matrix with the
cart and checkout pages explicitly seeded as canonical blocks, or raise
`WC requires at least` to the oldest version the project is prepared to test and
support. Keep a current/latest lane, but pin a current version in release
evidence so a result remains reproducible.

### M-03 — The percentage integration scenario stops at the Cart block

**Severity:** Medium; regression risk

**Status:** Open

The free integration scenario exercises Cart and Checkout blocks. After changing
the offer to 50%, however, `tests/integration/discount.test.mjs:94-115` visits
only `/cart/`. It proves the Store API price, idempotency, Cart block label, and
Cart block wording, but not:

- the discounted Checkout block;
- discounted classic cart or checkout;
- order creation and the real `_bogo_select_discount` metadata hook;
- tax-inclusive/tax-exclusive totals;
- coupon eligibility and stacking;
- sale-price behavior in real WooCommerce;
- stock reduction after a discounted order.

The repository itself acknowledges several of these gaps, but `tests/README.md`
is now stale in the opposite direction: it says there is no database, HTTP,
WordPress install, JavaScript runner, Store API transport, or real block
rendering (`tests/README.md:10-12`, `:27-55`), despite the new Docker/Playwright
job. The fixture also saves an unused `repeating` key instead of the real
`repeat` key (`tests/integration/setup-store.php:64`); default `repeat = no`
makes the present test pass, but the fixture does not set what its author
intended.

The claims about coupons should also be qualified. The changelog correctly says
the 40%-of-list result follows from hook ordering rather than a test
(`CHANGELOG.md:28-32`). In practice, only a coupon for which that product and cart
are eligible will stack; product/category exclusions, sale exclusions, and other
coupon rules still apply.

**Recommendation:** extend the discounted browser test through checkout, add a
classic-mode lane, and place at least one real order in an integration fixture to
assert totals, metadata, and stock. Add representative taxable and coupon cases,
fix the fixture key, update `tests/README.md`, and phrase the documentation as
"eligible coupons apply on top."

### L-01 — A 100% percentage offer loses its configured type in order metadata

**Severity:** Low; reporting accuracy

**Status:** Open

`is_free_reward()` deliberately treats `percent:100` as free for display
(`includes/class-bogo-engine.php:197-206`). `discount_snapshot()` then uses that
display-oriented predicate and writes `free` (`:305-319`). This is harmless to
price and customer wording, but the feature documentation says the hidden field
records the type and value as applied. Reports cannot distinguish an explicit
100%-off percentage campaign from the separate Free mode.

**Recommendation:** base the snapshot on `get_discount_type`, storing
`percent:100` for an explicit percentage while continuing to render it as
"Free." Add a regression test for the snapshot, not only for its zero price.

### L-02 — A few public descriptions still assert that every reward is free

**Severity:** Low; API/documentation quality

**Status:** Open

The Store API schema describes `qualifies` as earning a "free gift" and
`reward_quantity` as "free units" (`includes/class-bogo-blocks.php:200-207`).
`BRIEF.md` has not added the new discount setting or feature to post-v1.0 scope;
its current requirements and settings table still specify only a 100% discount
and free units (`BRIEF.md:21-38`, `:71-100`). `INSTRUCTIONS.md`'s manual test
checklist likewise tests only the free case.

These do not change runtime values, but they undermine the documents as the
source of truth used for assessing efficacy.

**Recommendation:** use price-neutral API descriptions ("reward" and "reward
units"), add the percentage feature/settings and intentional dynamic-pricing
trade-off to `BRIEF.md`, and add a discounted case to the manual checklist.

## Verification results

| Check | Result |
|---|---|
| Clean worktree before review | **Pass** |
| `composer validate --strict` | **Pass** |
| PHPUnit | **Pass — 161 tests, 326 assertions** |
| PHP syntax outside `vendor` | **Pass** |
| JavaScript syntax: storefront, admin, and integration scripts | **Pass** |
| Shell syntax: build and ZIP verifier | **Pass** |
| `composer audit --locked` | **Pass — no known advisories** |
| `git diff --check` | **Pass** |
| Existing 1.2.1 ZIP versus feature worktree | **Expected fail / release blocker — 8 stale runtime files** |
| Temporary ZIP built from reviewed commit | **Pass — installed successfully** |
| WooCommerce 10.9.4 live browser pass | **Pass — classic/block cart/checkout, free and 50%** |
| WooCommerce 7.0.0 live classic pass | **Pass — cart/checkout and free reward** |
| WooCommerce 7.0.0 full canonical block pass | **Not established** |

The disposable containers, database volumes, browser tab, and temporary build
were removed after testing. Only this report was changed in the repository.

## Cart and checkout compatibility

| WooCommerce | Surface | Result |
|---|---|---|
| 10.9.4 | Classic cart | **Works** — free and 50%-off chooser, labels, fixed quantity, prices, and totals verified |
| 10.9.4 | Classic checkout | **Works** — free and 50%-off chooser, order review, checkout form, prices, and totals verified |
| 10.9.4 | Cart block | **Works** — free and 50%-off selection/display, metadata, price, total, and mounted block verified |
| 10.9.4 | Checkout block | **Works** — free and 50%-off summary, correct root identity, checkout form, price, and total verified |
| 7.0.0 | Classic cart | **Works in the exercised free scenario** |
| 7.0.0 | Classic checkout | **Works in the exercised free scenario** |
| 7.0.0 | Cart block | **Partial evidence only** — Store API selection worked; canonical full block layout not exercised |
| 7.0.0 | Checkout block | **Not established** |

Direct answer: **yes, the plugin works on current WooCommerce cart and checkout
pages in both block and classic mode**, for both free and percentage rewards in
the scenarios tested. The broad declaration down to WooCommerce 7.0 needs the
additional matrix coverage described in M-02.

## Quality assessment

### Code quality

The implementation has strong separation between settings, qualification,
pricing, cart mutation, transport, rendering, and Blocks integration. The shared
reward vocabulary avoids the original promotion-label drift, and the pricing
path is readable and idempotent. Tests cover difficult arithmetic and cart-line
object identity cases. The main weaknesses are release/document drift and the
remaining framework-boundary gaps, not the internal design.

**Assessment:** good implementation quality; release metadata and integration
documentation need correction.

### Performance

The discount adds one fresh `wc_get_product()` lookup per reward line per totals
pass. Because the engine enforces one reward line and WooCommerce caches product
data, this is a reasonable cost for idempotent pricing. It also deliberately
avoids scanning the cart beyond existing validation passes. Catalogue paging,
bounded search, and curated eligibility caching remain acceptable with their
documented large-catalogue limitations.

The intentional interoperability trade-off is more important than raw speed:
the fresh catalogue price overwrites cart-item dynamic pricing rather than
discounting it. That is clearly documented and should remain prominent for
stores using pricing extensions.

**Assessment:** acceptable; no new performance blocker found.

### Security

The new administrator inputs are normalized server-side. WordPress's Settings
API supplies capability and nonce enforcement; output is escaped; classic AJAX
uses nonces; Store API mutation uses WooCommerce's cart session/nonce layer; and
both paths re-check activation, qualification, product eligibility,
purchasability, stock, and earned quantity. The client never supplies the reward
price or discount. Existing self-healing cart validation remains in place.

No injection, authorization bypass, arbitrary-discount, arbitrary-product, or
sensitive-data exposure was found. The locked development dependencies have no
known Composer advisories at review time.

**Assessment:** strong for the plugin's scope.

### Efficacy against stated objectives

The plugin meets the original BOGO objectives and the newer percentage-reward
plan in the tested current environment: independent Buy/Get scopes, configurable
quantities and repeat behavior, customer choice, a real stock-bearing reward
line, removal/revalidation, classic and block presentation, and free or
percentage pricing all operate coherently. The sale-price and coupon behavior is
credible from hook placement but needs the real integration cases in M-03 before
being presented as fully verified.

**Assessment:** functionally effective on current WooCommerce; documentation and
the declared minimum-support claim are broader than the evidence.

## Release recommendation

Do not publish the feature under 1.2.1.

Required before release:

1. Resolve M-01 with a 1.3.0 identity, accurate changelog/open-question/package
   descriptions, and a new immutable ZIP.
2. Decide M-02 explicitly: test WooCommerce 7.0 canonical blocks or raise the
   minimum to a version the project will test.
3. Extend the discounted integration path through checkout and at least one
   classic path; update the stale test documentation and fixture key.
4. Run the package parity gate and the live matrix against the exact 1.3.0 ZIP.

Recommended follow-up:

5. Add tax, eligible/ineligible coupon, order metadata, and stock-reduction cases.
6. Preserve `percent:100` in the order snapshot and make remaining API/spec text
   price-neutral.
