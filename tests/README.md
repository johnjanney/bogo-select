# Tests

```bash
composer install
composer test          # or: ./vendor/bin/phpunit
composer analyse       # PHPStan, level 9, no baseline
bash bin/verify-tests.sh   # does the suite object to real defects?
composer sniff         # WordPress Coding Standards
composer lint          # php -l over everything outside vendor/
```

## What is covered

Two suites and an analyser, covering different things.

**The unit suite** tests the parts of the plugin that are pure decisions,
against small stand-ins for the WordPress and WooCommerce functions they call
(`tests/stubs/`). No database, no HTTP, no WordPress install.

**PHPStan** (`phpstan.neon.dist`) reads the runtime code against the WordPress
and WooCommerce stub packages and judges it as PHP 7.4, the compatibility floor.
It analyses `includes/`, `bogo-select.php`, and `uninstall.php` — not `tests/`,
whose own stubs declare the same functions as the stub packages and would be
reported as redeclaring every one of them. The level is raised in steps and
there is no baseline: it sits where the code passes, so the number means
something. Level 9 is the maximum, and it is where the code sits.

**PHPCS with the WordPress standard** (`.phpcs.xml.dist`) reads the same runtime
code plus the tests. The shipped plugin passes the full standard with nothing
excluded for it alone. `tests/` is held to it with four documented exceptions,
each a convention test code follows and shipped code does not: a stub must carry
the WordPress name it stands in for, a test's name is its documentation, the
fake catalogue is one file describing one thing, and the integration fixtures
query a disposable container directly. Every exclusion in that file has its
reason written beside it.

**The integration job** (`.github/workflows/ci.yml` → `integration`) installs the
built zip into a real WordPress with WooCommerce — the compatibility floor and
whatever is current — and drives it over HTTP and in headless Chromium. It needs
Docker, so it runs in CI rather than from `composer test`. Its browser comes
from `npm ci` against the committed `package-lock.json`, so the Playwright it
runs is the one that file names; Dependabot proposes the bump rather than a new
version arriving unannounced mid-run.

### Unit suite

| File | Covers |
|---|---|
| `SettingsTest.php` | Defaults, normalization, corrupt options, `sanitize()`, and the discount keys. |
| `ScheduleTest.php` | The offer window: inclusive bounds, an unbounded side, date normalization, and that the schedule only ever narrows an offer. |
| `AdminSettingsTest.php` | The settings screen's judgement: which schedules it refuses and what it keeps instead (M-01), who may save the option group (M-02), what the summary sentence counts (L-05), and the gift list it strips. |
| `QualificationTest.php` | Buy counting, scopes, variations, repeat mode, filters, reward-key lookup. |
| `AvailabilityTest.php` | Gift eligibility by type and scope, stock/backorder/sold-individually rules, cart-wide stock demand. |
| `ChooserPagingTest.php` | Paging and search across both Get scopes — regression cover for F-02 — and the page-aware `bogo_select_choice_ids` filter (C-04). |
| `ChooserSearchTest.php` | Searching by name and by SKU, through the product data store and through the query fallback — regression cover for C-01. |
| `EligibilityCacheTest.php` | The cached eligibility of a curated gift list, and what clears it (C-03). |
| `GiftSelectionTest.php` | Choosing, swapping, and removing a gift: refused replacements, quantity correction, scope and stock refusals, and that validation is never left suspended. |
| `FrontendTest.php` | The rendered chooser: where it prints, what it prints, and what the script is told. |
| `BlocksTest.php` | Cart/Checkout Blocks: chooser injection, Store API state, update callback, item labelling, quantity limits. |
| `DiscountPricingTest.php` | The reward's discounted price: the factor, per-unit rounding, that repeated pricing passes do not compound it, the wording helpers, and the order-line snapshot. |
| `VariableProductStubTest.php` | That the fake catalogue models variable products the way WooCommerce does — parent/child links, attributes, "any" values, shared stock pools, and a cart line's own product object. |
| `VariableEligibilityTest.php` | Which products may be offered against which may be awarded, the parent-spoofing guard, and why a product cannot be offered. |
| `VariableSelectionTest.php` | Awarding a variation: the reward pair, sibling swaps, the state signature, and validation. |
| `VariableChooserTest.php` | The variable card: its selector, per-option availability, aggregate card state, and which card owns the selection. |
| `VariableRenderCostTest.php` | How many product loads a page of variable cards costs, and that the variation memo is per request. |
| `ChooserSearchCostTest.php` | How many product loads one gift search costs, that the memo does not change the order it returns, and that it does not outlive a cache flush (M-03). |
| `CartValidationTest.php` | Self-healing cart, and a reward of an already-purchased product keeping its own line (D-020): stock revalidation (F-01), duplicate reward lines (F-04), suspension (F-03), quantity lock, $0 pricing, subtotal display (F-07). |

## What the integration job covers

Each scenario reconfigures the same seeded store rather than rebuilding it, and
they run in order.

| Script | Covers |
|---|---|
| `blocks.test.mjs` | Store API cart state and gift label, quantity limits, and the Cart and Checkout **blocks** rendering on a real page — that the chooser slot never takes the block root's `data-block-name`, and that each block leaves `is-loading`. |
| `discount.test.mjs` | A percentage reward through the Store API: the discounted figure WooCommerce actually charges, that repeated recalculation does not compound it, and the discounted wording in the Cart block. |
| `variable.test.mjs` | A variable reward: that the parent alone is refused, that the line is priced from the chosen variation rather than the parent's range, and that the cart renders one selector listing every variation. |
| `mobile.test.mjs` | The compact chooser at 390×844: that each card is a row rather than a full-width image — thumbnail capped and beside the text, button under it — that no card runs off the side, that buttons meet the WCAG 2.2 minimum target size, and that a gift can actually be tapped at that width. Geometry rather than screenshots, on both the classic and block carts. |
| `classic.test.mjs` | The shortcode cart and checkout, including a variable reward chosen over admin-ajax and a switch between two individually listed siblings: that the chooser arrives through the template hooks rather than the `render_block` filter, that choosing over admin-ajax works by clicking the button, that the reloaded cart shows the badge, discounted price, and locked quantity, and that the checkout slot is marked `checkout` rather than `classic` so it never reloads a part-filled form. |
| `sale.test.mjs` | A reward already on sale, discounted again: that the reduction comes off the sale price rather than the regular one, with fixture prices chosen so the wrong answer cannot be mistaken for the right one. |
| `shipping.test.mjs` | How a reward behaves against shipping, run once per mode: that a free reward adds weight but not order value and so cannot cross a free-shipping threshold, that a discounted one adds both, and that either joins the parcel. |
| `tax.test.mjs` | Tax on a discounted reward, run once per display mode: that the line is taxed on what the customer pays rather than on the price it was discounted from, and that a tax-inclusive store still charges exactly half the shelf price. |
| `coupon.test.mjs` | Coupons alongside a discounted reward: that an eligible coupon compounds on the already-reduced price, and that one excluding the reward leaves it alone while still discounting the rest of the cart. |
| `setup-admin.php` + `admin.test.mjs` | The settings screen through `options.php` under a real role: that a Shop Manager can both open and save it (M-02), that a role without `manage_woocommerce` is refused, and that a malformed date and a reversed window are refused rather than stored (M-01) — read back from the repopulated form, which is what the option holds. Runs against a non-UTC site clock, so "whole days in the store's timezone" is exercised rather than assumed. |
| `order.test.mjs` + `assert-order.php` | Placing a real order through the Store API checkout, then inspecting it: the reward line and its quantity, the discounted line total, `_bogo_select_free` and `_bogo_select_discount`, the visible label, and stock reduced by the awarded quantity. No browser — none of it is about rendering. |

## Does the suite object to anything?

`bin/verify-tests.sh` reintroduces eight defects this plugin actually had — a
Buy list that stops matching a variation, a reversed schedule that saves anyway,
a Shop Manager who cannot save, a search that loads every candidate twice — and
requires the unit suite to fail on each. A mutation that survives is a hole:
behaviour the changelog describes and nothing guards.

It exists because a green suite says the tests agree with the code, not that
they would object to different code. Those are separate claims, and v2.3.1
shipped a browser assertion that passed on a negative true either way. On its
first run this found that the v2.3.7 fix — refusing a non-scalar where a product
ID belongs — had no test at all, and it had already shipped in a release.

Not a substitute for a mutation testing tool. It is a fixed, curated set, chosen
so each entry names a defect rather than a line number, and so a survivor tells
you what is unguarded rather than that some percentage of mutants lived.

## The benchmark

`benchmark.php` measures what a page of choices costs on a large catalogue —
wall time, database queries, CPU, and peak memory, each cold and warm. It runs
from `.github/workflows/benchmark.yml` on `workflow_dispatch` rather than on
push, because seeding a catalogue takes about a minute and the numbers are for
reading rather than for gating: a threshold on a shared runner would mostly
measure the runner.

```bash
gh workflow run Benchmark -f catalogue=2000 -f curated=500
```

The figures from the first run are recorded in `CODEX-REVIEW-RESPONSE.md`
(Part 0, the M-03 addendum), along with what they do and do not say.

## What neither suite covers

- Hook timing against third-party plugins (`woocommerce_before_calculate_totals`
  priority 20 versus other pricing plugins). This is the trade `DECISION.md`
  D-016 took deliberately, not an oversight.
- Weight-based shipping rates specifically. `shipping.test.mjs` proves the reward
  joins the parcel, using a per-item flat rate; WooCommerce's own flat rate cannot
  express weight, so a rate that varies by weight would need a third-party method
  to exercise.
- Multi-currency, and currencies whose minor unit is not two digits. The
  assertions read `currency_minor_unit` rather than assuming, but only one
  currency is ever configured.

`WP_DEBUG` is now covered, having been listed here as uncovered since v1.2.0.
The integration job turns it on before installing the plugin and fails the build
on any logged line naming a file of ours. Notices from WordPress and WooCommerce
themselves are printed and ignored, since no change here can fix them.

`CODEX-REVIEW.md` M-03 is otherwise covered.
