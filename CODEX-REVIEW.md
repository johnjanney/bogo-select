# Codex Repository Review

**Review date:** 2026-07-31

**Reviewed state:** commit `b04de94` (`main`, same as `origin/main`; clean
worktree before this report)

**Review scope:** all repository documents and runtime code, with special review
of the changes after commit `49dd5e5`. The new work includes variable rewards,
the 1.3.0 and 2.0.0 releases, and more integration tests for orders, coupons,
classic templates, sale prices, and tax.

## Overall assessment

The plugin has good code quality and a strong security model. It now has useful
integration tests against real WordPress and WooCommerce installations. The
former release, compatibility, order, tax, coupon, sale-price, and classic-mode
review findings are adequately fixed.

No Critical or High severity defect was found. One Medium functional defect was
reproduced in the new variable-reward chooser. Two pinned sibling variations are
both shown as selected. The customer then has no button to change from one to the
other. There is also a Medium performance risk because one render can load each
variation through four code paths. No catalogue benchmark was found.

The plugin meets its main purpose for simple rewards and for a variable parent
with a variation dropdown. It works on current classic cart and checkout pages,
and on Cart and Checkout blocks, in the tested scenarios. The pinned-sibling
defect prevents an unqualified statement that all supported variable-product
configurations work.

## Sources and review method

Verified facts in this report come from:

- the repository at commit `b04de94`;
- the 213-test PHPUnit suite;
- a temporary regression test for two pinned sibling variations;
- PHP, JavaScript, and shell syntax checks;
- Composer validation and advisory data;
- the package parity check; and
- the [GitHub Actions run for `b04de94`](https://github.com/johnjanney/bogo-select/actions/runs/30680432207),
  which passed on WooCommerce 9.9.5 and the current WooCommerce release.

Inferences are marked as such. If evidence was not present, this report says
“Not found in documents.”

## Status of the previous review

| Previous finding | Current status | Verification |
|---|---|---|
| M-01: release identity and stale package | **Fixed** | Versions 1.3.0 and 2.0.0 are tagged. The current header and constant both use 2.0.0. `dist/bogo-select-2.0.0.zip` matches all 14 runtime files. See [`bogo-select.php:3-24`](bogo-select.php#L3-L24). |
| M-02: unsupported WooCommerce 7.0 claim | **Fixed by an explicit breaking change** | Version 2.0.0 requires WooCommerce 9.9. CI tests 9.9.5 and current. See [`CHANGELOG.md:52-73`](CHANGELOG.md#L52-L73) and [the two passing integration jobs](https://github.com/johnjanney/bogo-select/actions/runs/30680432207). |
| M-03: incomplete percentage integration tests | **Fixed** | CI now tests discounted Cart and Checkout blocks, classic cart and checkout, coupons, tax, sale prices, order metadata, and stock reduction. See [`.github/workflows/ci.yml:165-331`](.github/workflows/ci.yml#L165-L331). |
| L-01: `percent:100` stored as `free` | **Fixed** | `discount_snapshot()` now uses the configured type. A test covers the distinction. See [`includes/class-bogo-engine.php:308-325`](includes/class-bogo-engine.php#L308-L325). |
| L-02: price-specific API and specification text | **Partly fixed** | The Store API text is price-neutral. The project brief is still not updated for the 1.3.0 features. See L-01 in this report. |

`CODEX-REVIEW-RESPONSE.md` has no response for the prior 2026-07-31 review. Its
newest recorded response is dated 2026-07-30
([`CODEX-REVIEW-RESPONSE.md:1-20`](CODEX-REVIEW-RESPONSE.md#L1-L20)). A written
Claude response to the prior review was **Not found in documents**. The commits
and code changes were reviewed directly instead.

## Current findings

### M-01 — Pinned sibling variations are all shown as selected

**Severity:** Medium

**Area:** Quality and purpose

**Status:** Open; reproduced

#### Verified facts

The admin can put a variable parent in the Get list, or it can put one specific
variation in the list. This is an explicit feature requirement
([`PLAN-VARIABLE.md:26-45`](PLAN-VARIABLE.md#L26-L45)). It is also possible to
put two specific variations from the same parent in the list.

Each pinned-variation card has a reward pair: parent product ID and variation ID.
However, the selected-state comparison uses only the parent product ID:

- `render_choices()` gets only `selected_product_id`
  ([`includes/class-bogo-frontend.php:303-320`](includes/class-bogo-frontend.php#L303-L320));
- `print_choice()` resolves the full card pair but compares only
  `$card_product_id === $selected`
  ([`includes/class-bogo-frontend.php:339-348`](includes/class-bogo-frontend.php#L339-L348)); and
- a selected pinned-variation card is not a variable-parent card. It therefore
  shows “Selected” and “Remove gift,” but it does not show “Change option” or
  “Choose this instead”
  ([`includes/class-bogo-frontend.php:408-428`](includes/class-bogo-frontend.php#L408-L428)).

A temporary regression test configured variation 101 and variation 102 from
parent 100. It selected variation 101 and rendered the chooser. The test expected
one `is-selected` card. It failed because two cards had `is-selected`.

This defect also occurs if the Get list contains a variable parent and a pinned
variation from that same parent. Both cards use the same parent ID for selected
state.

The current unit tests do not cover this card layout. They cover one pinned
variation, one variable-parent dropdown, and server-side sibling swaps
([`tests/VariableChooserTest.php:103-111`](tests/VariableChooserTest.php#L103-L111),
[`tests/VariableChooserTest.php:182-204`](tests/VariableChooserTest.php#L182-L204),
and [`tests/VariableSelectionTest.php:102-112`](tests/VariableSelectionTest.php#L102-L112)).

#### Impact

The chooser gives false status information. More importantly, it removes the
control that the customer needs to select the sibling card. The server-side swap
code works, but the UI cannot send the request.

The same chooser markup is used for classic and block pages. The defect therefore
affects classic cart, classic checkout, Cart block, and Checkout block when this
configuration is used.

#### Recommendation

Use the complete reward pair for selected state.

- For a pinned variation card, compare both the parent product ID and the exact
  variation ID.
- For a simple product card, compare the product ID and require variation ID 0.
- For a variable-parent card, mark the card selected only when the selected
  variation belongs to that parent and is represented by that card.
- Add tests for two pinned sibling variations and for a parent plus a pinned
  child from the same parent.
- Add one browser assertion that changes between pinned siblings. Run it in at
  least one block surface and one classic surface.

### M-02 — Variable-card rendering repeats variation enumeration and product loads

**Severity:** Medium on a catalogue with many large variable products; Low on a
small catalogue

**Area:** Performance

**Status:** Open risk; code path verified, runtime cost not measured

#### Verified facts

The design document correctly calls variation enumeration the main performance
risk and says to test it on a real catalogue
([`PLAN-VARIABLE.md:149-153`](PLAN-VARIABLE.md#L149-L153) and
[`PLAN-VARIABLE.md:293-296`](PLAN-VARIABLE.md#L293-L296)). The default chooser
page can contain 24 cards
([`includes/class-bogo-engine.php:782-794`](includes/class-bogo-engine.php#L782-L794)).

For each variable card on an uncached render, the current path can call
`wc_get_product()` for each child through these separate stages:

1. `is_choice()` calls `offerable_variation_ids()` to decide if the parent can be
   a card ([`includes/class-bogo-engine.php:367-383`](includes/class-bogo-engine.php#L367-L383)).
2. `variation_options()` calls `offerable_variation_ids()` again
   ([`includes/class-bogo-frontend.php:473-477`](includes/class-bogo-frontend.php#L473-L477)).
3. `variation_options()` loads every accepted variation again to calculate stock,
   labels, and availability
   ([`includes/class-bogo-frontend.php:476-497`](includes/class-bogo-frontend.php#L476-L497)).
4. The `<option>` loop loads every variation again to make its price markup
   ([`includes/class-bogo-frontend.php:393-399`](includes/class-bogo-frontend.php#L393-L399)).

WooCommerce object caching can reduce database work after the first load. It does
not remove the repeated function calls, product checks, stock checks, price
formatting, or the size of a selector with many options.

#### Inference

A page of 24 variable products with 100 variations each can cause about 9,600
child-product retrieval calls from the four stages above, before other product
lookups. This number is a code-path estimate. It is not a measured query count or
latency result.

A large-catalogue benchmark result was **Not found in documents**.

#### Recommendation

- Cache `offerable_variation_ids()` for the current request. Key it by parent ID.
- Build each option once. Keep the variation object or its completed price markup
  in the option data so later loops do not reload it.
- Do not call `offerable_variation_ids()` once for eligibility and again for the
  same card render when one result can be passed through.
- Add a benchmark fixture with 24 variable parents and a realistic number of
  variations. Record wall time, peak memory, query count, and product-load count.
- If large selectors remain slow, add lazy option loading or a lower, documented
  limit for variation options.

### L-01 — Source-of-truth documents still describe the older product

**Severity:** Low runtime risk; Medium documentation risk

**Area:** Quality and stated purpose

**Status:** Open

#### Verified facts

`BRIEF.md` says that it is amended only through 1.2.0
([`BRIEF.md:1-4`](BRIEF.md#L1-L4)). Its purpose, requirements, settings table,
customer flow, and acceptance criteria still describe only free rewards. It does
not specify `get_discount_type`, `get_discount_value`, or the new variable-reward
rules ([`BRIEF.md:11-17`](BRIEF.md#L11-L17),
[`BRIEF.md:21-32`](BRIEF.md#L21-L32), and
[`BRIEF.md:85-102`](BRIEF.md#L85-L102)). Section 3.1 stops at the 1.2.0 block
work ([`BRIEF.md:71-81`](BRIEF.md#L71-L81)).

The release-process text is also stale. It says the classic matrix, order
placement, and stock reduction are still manual
([`BRIEF.md:359-378`](BRIEF.md#L359-L378)). CI now automates all three.

`README.md` has the same stale limitation. It says classic cart, classic
checkout, stock reduction, and order placement are manual
([`README.md:202-206`](README.md#L202-L206)). The current CI workflow and test
scripts show that this is false.

`tests/README.md` now explains the integration suite well, but its unit-test table
does not list `DiscountPricingTest.php` or any of the four variable-product test
files ([`tests/README.md:21-35`](tests/README.md#L21-L35)).

#### Impact

The code and the release notes state a wider purpose than the main specification.
This makes efficacy reviews and future changes less reliable. It can also cause
a maintainer to repeat work that CI already performs.

#### Recommendation

- Amend `BRIEF.md` through 2.0.0.
- Add free/percentage settings, variable parent and pinned-variation behavior,
  variable acceptance criteria, and the dynamic-pricing trade-off.
- Rewrite section 8.6 to match the current real-store matrix.
- Update the README limitation and the unit-test inventory.
- Keep historical release notes unchanged. They correctly describe the state at
  the time of each release.

### L-02 — Real-browser classic coverage does not exercise a variable selector

**Severity:** Low

**Area:** Test completeness

**Status:** Open coverage gap

#### Verified facts

The variable integration script chooses a variation through the Store API and
checks its selector on the Cart and Checkout blocks
([`tests/integration/variable.test.mjs:49-138`](tests/integration/variable.test.mjs#L49-L138)).
The classic integration script uses a simple reward. It checks the admin-AJAX
path, classic cart, and classic checkout, but it does not change a variation
selector ([`tests/integration/classic.test.mjs:46-142`](tests/integration/classic.test.mjs#L46-L142)).

The server selection function is shared between the classic and Store API paths
([`includes/class-bogo-ajax.php:58-72`](includes/class-bogo-ajax.php#L58-L72)).
The same chooser JavaScript reads the variation and sends it through the active
transport ([`assets/js/bogo-select.js:510-519`](assets/js/bogo-select.js#L510-L519)).
Unit tests cover the variable-parent selector and the selection pair. This is
good structural evidence.

A real-browser test of a variable reward on classic cart and classic checkout
was **Not found in documents**.

#### Recommendation

Extend `classic.test.mjs` with a variable-parent fixture. Select one option on
the classic cart. Confirm that the exact variation is in the cart, then change it
on classic checkout without reloading or clearing entered checkout data.

## Verification results

| Check | Result |
|---|---|
| Clean worktree before review | **Pass** |
| `composer validate --strict` | **Pass** |
| PHPUnit | **Pass — 213 tests, 471 assertions** |
| Targeted pinned-sibling regression test | **Fail as expected — 2 selected cards, expected 1** |
| PHP syntax outside `vendor` | **Pass** |
| JavaScript syntax for storefront, admin, and integration scripts | **Pass** |
| Shell syntax for the build and package verifier | **Pass** |
| `composer audit --locked` | **Pass — no known advisories** |
| `git diff --check` before this report | **Pass** |
| `bash bin/verify-zip.sh` | **Pass — 2.0.0 ZIP matches 14 runtime files** |
| Current GitHub Actions run | **Pass — all jobs passed** |
| WooCommerce 9.9.5 real-store lane | **Pass** |
| Current WooCommerce real-store lane | **Pass** |

The temporary regression test was removed after it reproduced M-01. Only this
report is changed in the worktree.

## Cart and checkout compatibility

| Reward and surface | Result | Evidence and limit |
|---|---|---|
| Simple/free reward, Cart block | **Works in CI** | Store API, line price, quantity limit, label, chooser, and mounted block are checked. |
| Simple/free reward, Checkout block | **Works in CI** | Chooser, order review, checkout form, label, and mounted block are checked. |
| Discounted reward, Cart block | **Works in CI** | Discounted total, idempotence, label, and chooser are checked. |
| Discounted reward, Checkout block | **Works in CI** | Discount label and chooser are checked on the rendered block. |
| Variable-parent reward, Cart block | **Works in CI** | Exact variation pair, variation price, selector, and change control are checked. |
| Variable-parent reward, Checkout block | **Works in CI** | Selector and selected state are checked. |
| Discounted simple reward, classic cart | **Works in CI** | Real browser click uses admin-AJAX; price, label, row, and locked quantity are checked. |
| Discounted simple reward, classic checkout | **Works in CI** | Checkout form, chooser, mode, and line label are checked. |
| Variable-parent reward, classic cart/checkout | **Likely works, but not fully verified in a real browser** | Shared PHP and JavaScript paths plus unit tests support this inference. A classic browser run was not found. |
| Two pinned sibling variations, any of the four surfaces | **Does not work correctly** | M-01 was reproduced in the shared chooser renderer. |

Direct answer: the plugin works on WooCommerce cart and checkout pages in block
and classic mode for the main tested configurations. A variable parent works in
both block pages. Classic variable selection has strong code and unit-test
evidence but lacks a real-browser test. Two pinned variations of the same parent
do not work correctly on any surface.

## Quality assessment

### Code quality

The code has clear class boundaries for settings, promotion rules, cart changes,
rendering, classic AJAX, Blocks, and admin work. The product-and-variation pair
is carried through cart selection, Store API state, state signatures, validation,
and pricing. Server-side sibling swaps use the full pair correctly. Release
packaging is deterministic and has a parity gate.

The main quality defect is local to chooser selected-state logic. That code
changes a two-part identity back into one product ID. The documentation also has
several old statements that reduce its value as a specification.

**Assessment:** good, with one Medium UI logic defect and documentation drift.

### Performance

The existing catalogue paging and search bounds remain reasonable. The new
variable-card implementation avoids WooCommerce's large
`get_available_variations()` array, which is a good choice. However, it repeats
child enumeration and child-product loads during one render. This is the main
performance risk.

**Assessment:** acceptable for small and normal variable catalogues; not proved
for a page with many high-variation products.

### Security

The variable-product work preserves the existing security model:

- classic public AJAX requests use a nonce and sanitize IDs
  ([`includes/class-bogo-ajax.php:35-47`](includes/class-bogo-ajax.php#L35-L47));
- Store API data is sanitized
  ([`includes/class-bogo-blocks.php:241-266`](includes/class-bogo-blocks.php#L241-L266));
- the server verifies that a submitted variation belongs to the submitted parent
  ([`includes/class-bogo-engine.php:399-419`](includes/class-bogo-engine.php#L399-L419));
- the server independently verifies qualification, scope, product type,
  purchasability, stock, and quantity before it adds the reward
  ([`includes/class-bogo-ajax.php:69-108`](includes/class-bogo-ajax.php#L69-L108));
- the client does not provide the discount or reward price; and
- settings use WordPress admin controls and server-side sanitization.

No authorization bypass, arbitrary-product award, arbitrary-discount input,
injection path, or sensitive-data exposure was found. Composer reported no known
advisories in the locked development dependencies at review time.

**Assessment:** strong for the stated scope.

### Efficacy against stated objectives

The plugin implements one global Buy X/Get Y promotion with independent product
scopes, configurable quantities, repeat mode, free or percentage pricing,
customer choice, stock-bearing reward lines, cart revalidation, classic pages,
and Blocks. Real integration tests now prove taxes, eligible and excluded
coupons, sale prices, order metadata, and stock reduction.

The variable-parent design is effective when the customer uses one dropdown on
one parent card. The pinned-variation feature is effective for one pinned
variation, but not for multiple sibling variations in the same list.

**Assessment:** the main purpose is accomplished. M-01 is a real exception to
the stated variable-product objective.

## Recommendation

Do not describe every supported variable-reward configuration as complete until
M-01 is fixed.

Before the next release:

1. Fix selected-state comparison to use the full reward pair.
2. Add sibling-card regression tests and one block/classic browser case.
3. Remove repeated per-variation product loads or measure and document their
   acceptable cost.
4. Update `BRIEF.md`, `README.md`, and `tests/README.md` to match the current
   product and CI matrix.
5. Re-run the unit suite, the full real-store matrix, and the ZIP parity gate on
   the exact release commit.
