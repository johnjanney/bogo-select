# Project Brief — BOGO Select for WooCommerce

**Status:** v1.0.0 specification, amended through v2.1.0 (see §3.1)
**Last updated:** 2026-07-31

> Release rules that apply to every future update — versioning, zip builds, and
> archive retention — are in [§8 Release process](#8-release-process-standing-requirements).

---

## 1. Purpose

Give WooCommerce store owners a "Buy X, Get Y free" promotion where the customer
**chooses** which free product they receive from a list the admin controls.

The reward is added to the cart as a real line item rather than a coupon
discount, so that WooCommerce decrements stock for it exactly as it would for a
paid item. It is priced at **$0.00** by default; since v1.3.0 it may instead be
sold at a percentage off (§3.1).

---

## 2. Business requirements

These are the requirements as given by the client, restated for implementation.

| # | Requirement |
|---|-------------|
| R1 | When a customer purchases a qualifying product, they may select from a pre-selected list of products to receive one for free. |
| R2 | The selected "Get" product is added to the cart at a 100% discount, so inventory is still reduced by one unit per free item. |
| R3 | The Buy quantity and Get quantity are both admin-configurable to any positive integer (Buy 2 Get 2, Buy 4 Get 8, etc.). |
| R4 | Buy and Get are each limited to **one product item** per offer. A customer who buys a qualifying quantity chooses **one** Get product and receives the full Get quantity of it. |
| R5 | Buy scope and Get scope are set **independently** to either **All Products** or **Select Products** (with an explicit product list). |
| R6 | *(v1.3.0)* The Get product may be given away or sold at an admin-set percentage off. A 100% discount and the free mode are equivalent in price and differ only in what the order records. |
| R7 | *(v1.3.0)* A variable product may be offered as the Get product, with the customer choosing the variation; or a single variation may be listed, pinning the reward to it. |
| R8 | *(v2.1.0)* The offer may be given a start date, an end date, or both. Both bounds are inclusive whole days in the store's timezone, and both are optional. |

### Worked examples

- **Buy 2, Get 2** — Buy scope *All Products*, Get scope *Select Products* = [Item B, Item C].
  Customer adds 2 × Item A → qualifies → chooses Item B → 2 × Item B added at $0.00.
- **Buy 4, Get 8** — Customer adds 4 qualifying units → chooses Item C → 8 × Item C at $0.00.
- **Buy 2, Get 2, Buy scope = [Item A]** — Adding 2 × Item D does *not* qualify.

---

## 3. Scope

### In scope for v1.0.0

- Single global offer (one Buy rule, one Get rule) configured in the WordPress admin.
- Buy/Get scope selectors: All Products or Select Products (product list with AJAX search).
- Configurable Buy Quantity and Get Quantity (any integer ≥ 1).
- Qualification measured across the whole cart: the sum of quantities of all
  Buy-eligible cart items (free reward items excluded from the count).
- Customer-facing chooser on the **cart page**, plus a notice on the **shop/product
  pages** when the cart qualifies.
- Free item added via AJAX, priced at $0.00, quantity locked to the Get Quantity.
- Customer can swap their choice (removes the old free item, adds the new one).
- Continuous re-validation: if the cart stops qualifying, the free item is removed
  automatically and the customer is told why.
- Free line items are flagged in the cart, checkout, order emails, and admin order screen.
- Optional "repeat" mode: award one reward set per multiple of the Buy Quantity.
- Uninstall routine that deletes plugin options.

### Explicitly out of scope for v1.0.0

- Multiple simultaneous offers / offer stacking.
- Category-, tag-, or attribute-based scoping (product IDs only).
- Variable-product variation-level targeting (parent product IDs only; see Open Questions).
- Per-customer / per-role / per-coupon eligibility rules.
- ~~Scheduling (start and end dates for the offer).~~ Added after v2.0.0 — see §3.1.
- Subscriptions, bundles, composite products, and other third-party product types.
- Multi-currency and multi-language (WPML/Polylang) integration testing.

### 3.1 Scope added after v1.0.0

- **v1.2.0 — Cart and Checkout Blocks.** The chooser renders in the WooCommerce
  Cart and Checkout blocks as well as the classic templates, with gift selection
  running through the Store API. Blocks were never listed as out of scope; they
  were simply not implemented, and a block store could not use the promotion at
  all. See `DECISION.md` D-008.
- **v1.2.0 — Chooser on the checkout page.** A customer who reaches the checkout
  without opening the cart previously had nowhere to pick a gift. The chooser now
  renders above the checkout form (classic) and the Checkout block, and changing
  a gift there never reloads the page.
- **v1.3.0 — The reward may be discounted rather than only given away.** Two
  settings carry it, both defaulting to the free behaviour, so an install that
  never configures a discount is unchanged. The reward's price is read from a
  product loaded fresh on every pricing pass rather than from the cart line's own
  object, which is what keeps a percentage from compounding when WooCommerce
  recalculates totals more than once. **The trade-off is deliberate:** a price set
  on the cart item by a third-party dynamic-pricing plugin is overwritten rather
  than discounted. See `DECISION.md` D-016.
- **v1.3.0 — Variable products as rewards.** The Get list may hold a variable
  product, in which case the chooser offers its variations and the customer picks
  one, or a single variation, which pins the reward with no choice shown. This
  supersedes the v1.0.0 exclusion of variation-level targeting above. Grouped and
  external products remain ineligible, as do variations that leave an attribute
  set to "Any" — they would still need a choice. See `DECISION.md` D-017.
- **v2.1.0 — Campaign scheduling.** The offer takes an optional start date
  and end date, answering `OPEN-QUESTIONS.md` Q-005. Whole days in the store's
  timezone, both bounds inclusive. This was listed as out of scope for v1.0.0
  above; the client asked for it. See `DECISION.md` D-019.
- **v2.0.0 — WooCommerce 9.9 required.** The declared minimum rose from 7.0 to
  the oldest version CI actually tests. This is a breaking change and is why the
  major version moved. See `DECISION.md` D-018.

---

## 4. Functional specification

### 4.1 Settings (admin)

| Setting | Key | Type | Default | Notes |
|---|---|---|---|---|
| Enable offer | `enabled` | bool | `no` | Master on/off switch. |
| Offer title | `offer_title` | string | `Choose your free gift` | Heading on the chooser. |
| Buy quantity | `buy_qty` | int ≥ 1 | `1` | Units required to qualify. |
| Get quantity | `get_qty` | int ≥ 1 | `1` | Free units awarded. |
| Buy scope | `buy_scope` | `all` \| `select` | `all` | |
| Buy products | `buy_products` | int[] | `[]` | Required when `buy_scope = select`. May hold products and individual variations; a product counts every variation of it, a variation counts only itself. |
| Get scope | `get_scope` | `all` \| `select` | `select` | |
| Get products | `get_products` | int[] | `[]` | Required when `get_scope = select`. May hold simple products, variable parents, and individual variations. |
| Start date | `start_date` | `Y-m-d` \| `''` | `''` | First day the offer runs. Empty means no start bound. |
| End date | `end_date` | `Y-m-d` \| `''` | `''` | Last day the offer runs, inclusive. Empty means no end bound. |
| Reward price | `get_discount_type` | `free` \| `percent` | `free` | *(v1.3.0)* Whether the reward is given away or sold at a discount. |
| Discount | `get_discount_value` | float 0–100 | `0` | *(v1.3.0)* Percentage off, used only when the type is `percent`. |
| Repeat offer | `repeat` | bool | `no` | Off: max one reward set. On: `floor(buy_count / buy_qty)` sets. |
| Show on product pages | `show_notice` | bool | `yes` | Site-wide "you qualify" notice. |

Stored as a single serialized option: `bogo_select_settings`.

### 4.2 Qualification algorithm

```
buy_count = Σ quantity of every cart item where:
              item is NOT a BOGO reward item
              AND (buy_scope = all
                   OR product_id ∈ buy_products
                   OR variation_id ∈ buy_products)

sets      = repeat ? floor(buy_count / buy_qty) : (buy_count >= buy_qty ? 1 : 0)
reward_qty = sets × get_qty
qualifies = reward_qty > 0
```

A product that is in **both** the Buy and Get lists still counts toward
`buy_count` when the customer paid for it; the free copies never count.

### 4.3 Reward line item

- Added with cart item data `bogo_select_free => true`. The same flag reaches the
  order as line-item meta `_bogo_select_free => 'yes'`, alongside a human-readable
  *Free gift* meta row for the admin screen, emails, and packing slips.
- No provenance token is stored. Every validation pass re-derives the answer from
  the **current** settings and the **current** cart — offer state, gift
  eligibility, earned quantity, and stock — so a stale or forged stamp could not
  buy a line any leniency. A hash of the settings would only tell us that
  something changed, which the field-by-field recheck already establishes.
- Price forced to `0` on `woocommerce_before_calculate_totals` (priority 20).
- Quantity is not editable in the cart; the input is replaced with static text.
- Free items are excluded from `buy_count`, so a reward can never bootstrap itself.
- Only one reward line item may exist at a time (R4: one Get product per cart).
  Validation enumerates **every** flagged line, keeps the first, and removes the
  rest, so a drifted session cannot leave unchecked free lines behind.

### 4.4 Re-validation triggers

Runs on `woocommerce_cart_loaded_from_session`, `woocommerce_check_cart_items`,
`woocommerce_add_to_cart`, `woocommerce_cart_item_removed`,
`woocommerce_cart_item_restored`, and after any cart quantity update:

1. More than one reward line present → keep one, remove the others, notice.
2. Offer disabled, or reward product no longer eligible → remove reward, notice.
3. Cart no longer qualifies → remove reward, notice.
4. Reward product out of stock, or its stock no longer covers the free units
   **plus** any paid units of the same product in the cart → remove reward, notice.
   Checked on every pass, not only when the earned quantity moves.
5. `reward_qty` changed (quantity up/down, repeat mode) → adjust the line quantity.

### 4.5 Customer flow

1. Customer adds qualifying products to the cart.
2. Cart page shows the chooser: offer title, "Choose 1 of N", product cards with
   image, name, stock state, and a **Select** button. When there is more than one
   page of options, a search box and Previous/Next controls appear; both page over
   AJAX without reloading the cart.
3. Clicking Select fires an AJAX request → server re-validates → reward added →
   cart fragments refresh.
4. Chosen card shows as **Selected** with a **Change** action. Changing the gift is
   one operation: the replacement is added before the previous gift is removed, so
   a rejected add leaves the original gift in place.
5. Checkout, emails, and the admin order screen display the line as **Free (BOGO)**
   at $0.00 with normal stock reduction.

---

## 5. Technical approach

- **Plugin type:** standalone plugin, no framework dependencies, no build step.
- **PHP:** 7.4+ (typed properties avoided for 7.4 compatibility; `declare(strict_types)` not used).
- **WordPress:** 6.0+ / **WooCommerce:** 9.9+ (HPOS compatible — declares
  `custom_order_tables` support).
- **Architecture:** small single-responsibility classes autoloaded by an explicit
  `require` manifest in the bootstrap file.

| Class | Responsibility |
|---|---|
| `BOGO_Select` | Bootstrap, dependency wiring, activation checks. |
| `BOGO_Select_Settings` | Read/write/sanitize the option; defaults. |
| `BOGO_Select_Engine` | Pure qualification logic (no output, no side effects). |
| `BOGO_Select_Cart` | Cart hooks: pricing, quantity lock, validation, display. |
| `BOGO_Select_Frontend` | Renders the chooser and notices, enqueues assets. |
| `BOGO_Select_Ajax` | `choose`/`remove`/`choices`/`refresh` endpoints, nonce + server-side re-validation. |
| `BOGO_Select_Blocks` | Cart/Checkout Blocks: chooser injection, Store API state, updates, and quantity limits. |
| `BOGO_Select_Admin` | Settings screen, product search AJAX, settings link. |

The front-end endpoints are deliberately **public** (`wp_ajax_nopriv_*`): guests
must be able to pick a gift. They therefore carry a nonce and repeat every
business rule server-side — qualification, gift eligibility, quantity, and
availability — rather than a capability check. Only the admin product search
requires `manage_woocommerce`.

- **Free pricing method:** direct price override (`$product->set_price( 0 )`), *not*
  a generated coupon. See `DECISION.md` §D-002.
- **Security:** every AJAX endpoint verifies a nonce; the admin product search
  requires `manage_woocommerce`; all settings input is sanitized on save and all
  output escaped.

---

## 6. Acceptance criteria

1. With Buy 2 / Get 2, adding 2 qualifying units surfaces the chooser; selecting a
   Get product adds 2 units at $0.00 and the cart total is unchanged.
2. Completing that order reduces the Get product's stock by 2.
3. With Buy 4 / Get 8, 4 qualifying units award 8 free units.
4. Reducing the cart below the Buy quantity removes the free item automatically.
5. Buy scope = Select Products limits qualification to the listed products only.
6. Get scope = All Products lets the chooser search the whole catalogue: with 60+
   eligible products, the first, fiftieth, and last are all reachable by paging or
   searching, and no eligible product is unreachable. A product whose only match
   is its SKU is found by searching that SKU.
7. The free item's quantity cannot be edited from the cart page, or from the Cart
   block.
8. No PHP notices/warnings with `WP_DEBUG` enabled.
9. A gift whose stock falls below the awarded quantity is removed on the next
   validation pass, even when the earned quantity has not changed.
10. Changing the gift to a product the cart cannot accept leaves the original gift
    in place and reports why.
11. A cart holding more than one flagged free line is normalised to one.
12. The unit-price and subtotal columns of a multi-unit gift both strike through
    the correct amount (unit price, and unit price × quantity respectively).
13. The chooser appears, and a gift can be chosen and swapped, on all four
    combinations of classic/block cart and classic/block checkout.
14. *(v1.3.0)* With the reward set to 50% off, a 20.00 reward line is charged
    10.00, and repeated total recalculation does not reduce it further. The order
    records the offer that produced it.
15. *(v1.3.0)* A variable product in the Get list renders one card carrying a
    selector of its variations; the reward is priced from the chosen variation
    rather than the parent's price range, and the parent alone cannot be awarded.
16. *(v1.3.0)* Two variations of one parent, each listed individually, are
    separate cards; choosing one marks only that card and leaves the other
    selectable.
17. *(v2.1.0)* An offer with an end date is live for the whole of that day
    and stops the day after; one with a start date shows nothing before it. An
    offer with neither behaves exactly as it did before scheduling existed.
18. Choosing a gift on a checkout page does not clear anything already typed into
    the checkout form.

---

## 7. Risks

| Risk | Mitigation |
|---|---|
| Customer manipulates the AJAX call to get a free item without qualifying. | Server re-validates qualification on every add, and again on `woocommerce_check_cart_items` before checkout. |
| Free item priced $0 but taxed as if paid. | Price override happens before totals are calculated, so tax is computed on $0. |
| Third-party plugins also filter cart item prices. | Price override runs at priority 20 on `woocommerce_before_calculate_totals`. Not an absolute guarantee — an extension hooking later still wins. |
| Out-of-stock Get product selected. | Stock checked at selection time, on every validation pass thereafter, and again at checkout by WooCommerce's own validation. |
| Gift and paid copies of one product exhaust its stock between them. | Availability counts total cart demand against the stock-managed product ID, not the free units alone. |
| A gift swap is rejected mid-flight, leaving the customer with nothing. | Replacement adds before it removes; a rejected add leaves the original gift untouched. |
| WooCommerce lifecycle behaviour (sessions, checkout, stock reduction) regresses silently. | Unit suite covers the pure logic; since v1.3.0 the CI integration job drives a real WordPress and WooCommerce, places an order, and asserts its line metadata and stock reduction. What that job still does not reach is listed in §8.6 and `tests/README.md`. |

---

## 8. Release process (standing requirements)

These three rules apply to **every** update of this plugin, not just v1.0.0.

### 8.1 Version numbering

The plugin follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html)
— `MAJOR.MINOR.PATCH`:

| Bump | When |
|---|---|
| **MAJOR** (`2.0.0`) | Breaking change: settings schema change requiring migration, removal or renaming of a public hook/filter, or a raised WordPress/PHP/WooCommerce minimum. |
| **MINOR** (`1.1.0`) | New backwards-compatible functionality: a new setting, a new hook, a new customer-facing feature. |
| **PATCH** (`1.0.1`) | Backwards-compatible bug fixes, security fixes, wording/i18n corrections, and performance work with no behaviour change. |

Rules:

- Documentation-only or tooling-only changes do **not** bump the version. The
  released version number always describes the shipped plugin code.
- The version appears in exactly **two** places and they must always agree:
  1. the `Version:` header in `bogo-select.php`
  2. the `BOGO_SELECT_VERSION` constant in `bogo-select.php`
- Every version bump adds a dated section to `CHANGELOG.md` under a matching
  `## [x.y.z] — YYYY-MM-DD` heading, with the compare/tag link references at the
  bottom of the file updated.
- No pre-release or build-metadata suffixes (`-beta`, `+build.5`) in released
  builds; WordPress plugin update checks compare these poorly.

### 8.2 Build a versioned zip after every update

After each update that changes plugin code, produce an installable zip:

```
bash bin/build-zip.sh
```

- Output path: `dist/bogo-select-<version>.zip`, where `<version>` is read from
  the `Version:` header in `bogo-select.php` — the filename is never typed by
  hand, so it can never disagree with the code.
- The archive contains a single top-level `bogo-select/` directory, which is what
  WordPress's *Plugins → Add New → Upload Plugin* expects.
- Excluded from the archive: `.git/`, `dist/`, `bin/`, and OS/editor cruft
  (`.DS_Store`, `*.swp`, `Thumbs.db`).

### 8.3 Never delete previous zips

`dist/` is an append-only archive. Prior versions are retained so any release can
be re-installed or diffed without a rebuild.

- The build script **refuses to overwrite** an existing zip for the same version.
  If a zip for the current version already exists, bump the version first (§8.1)
  or delete the stale file deliberately and by hand.
- `dist/` is excluded from git via `.gitignore`; the zips are build artefacts and
  live on disk, not in version history.

### 8.4 Tag and publish the release

Once the zip is built (§8.2), the version is published to GitHub so every release
has an immutable commit and a downloadable installer.

```bash
# 1. Annotated tag on the release commit — never lightweight.
git tag -a v<version> -m "BOGO Select for WooCommerce <version>"
git push origin v<version>

# 2. GitHub release, with the zip attached as an asset.
gh release create v<version> dist/bogo-select-<version>.zip \
    --verify-tag \
    --title "BOGO Select for WooCommerce <version>" \
    --notes-file <notes>
```

Rules:

- Tag name is `v` + the version (`v1.0.0`) — the `v` prefix is in the tag only,
  never in the plugin header or the zip filename.
- Tags are **annotated** (`-a`), not lightweight, so the release carries a message
  and an author date.
- Always pass `--verify-tag` so a typo fails loudly instead of creating a stray
  tag that points at the wrong commit.
- Release notes are the matching `## [x.y.z]` section of `CHANGELOG.md` — that
  section only, so the `[Unreleased]` heading never leaks into published notes —
  prefixed with a one-line install instruction naming the attached zip.
- Tags are never moved or force-pushed once published. A bad release is
  superseded by the next PATCH version, not rewritten.
- Attaching the zip to the release is what makes §8.3 durable off-machine: the
  archive of every past version survives even if local `dist/` is lost.
- Do not tag until §8.5 passes: the tag is immutable, so publishing a zip that
  disagrees with the tagged commit cannot be corrected in place.

### 8.5 Verify the zip before publishing it

A passing test suite says nothing about what was packaged. The v1.2.0 archive was
built before its own fix landed and shipped a superseded class, while every test
passed against a worktree that contained the fix (`CODEX-REVIEW.md` M-01).

Between building (§8.2) and tagging (§8.4), confirm the archive is the code that
was reviewed:

```
bash bin/verify-zip.sh
```

- Every runtime file (`.php`, `.js`, `.css`) in the worktree must appear in the
  archive with an identical SHA-256, and the archive must carry no runtime file
  the worktree lacks.
- The script exits non-zero and names each stale, missing, or extra file.
- CI runs the same build-then-verify pair on every push, so a zip that disagrees
  with the source fails the build rather than reaching a customer.
- If it fails: rebuild from the reviewed state (§8.2 — bump the version or remove
  the stale archive by hand, per §8.3), then re-verify.

The check is mechanical and covers packaging only — it says nothing about whether
the packaged plugin works.

### 8.6 The cart, checkout, and order matrix runs in CI

Block cart and block checkout defects twice survived a green unit suite
(`CODEX-REVIEW.md` M-02), so those surfaces are now covered by an automated job:

- **`.github/workflows/ci.yml` → `integration`.** Installs the built zip into a
  real WordPress with WooCommerce — the compatibility floor and whatever is
  current — seeds a store from `tests/integration/setup-store.php`, and drives
  both blocks in headless Chromium via `tests/integration/blocks.test.mjs`.
- It asserts the two things the unit suite cannot: that the chooser slot never
  takes the block root's `data-block-name`, and that each block leaves
  `is-loading` and renders — a real checkout form, both cart lines, and the
  visible gift label.
- Because the matrix includes `latest`, a future WooCommerce that breaks the
  blocks turns CI red here rather than in a customer's store.

Since v1.3.0 the same job also covers the classic shortcode cart and checkout,
the discounted and variable rewards on both block surfaces, coupons applied
alongside a reward, tax in both display modes, a reward already on sale, and
placing a real order through the Store API checkout — after which it asserts the
order's line metadata and the stock WooCommerce reduced. Cash on delivery is
enabled in the fixture, which is what made order placement possible.

Shipping joined that job in v2.1.0 and is no longer manual: a shipping fixture
seeds a non-virtual product and a free-shipping threshold, and `shipping.test.mjs`
runs in both reward modes.

**Still manual before a release**, because CI does not cover them: third-party
pricing plugins against the priority-20 hook (a deliberate trade, see D-016),
weight-based shipping rates — WooCommerce's own flat rate cannot express one, so
it would take a third-party method — and currencies whose minor unit is not two
digits. The hydration path is also unexercised: WooCommerce did not preload a
cart response in the tested configuration.

### 8.7 Static analysis runs in CI

Since 2.3.1 the runtime code is analysed by PHPStan (`phpstan.neon.dist`,
`composer analyse`, and the `analyse` job) against the WordPress and WooCommerce
stub packages, judged as PHP 7.4 — the compatibility floor — while running on a
current PHP. `tests/` is not analysed: its own stubs declare the same functions
as the stub packages, and analysing both reports every one of them as a
redeclaration.

- **The level is raised in steps and there is no baseline.** A baseline records
  what the code got wrong and then stops mentioning it, which turns the level
  into a number about history. Where the level sits is where the code passes.
- **`treatPhpDocTypesAsCertain: false`**, because the stubs describe current
  WooCommerce optimistically. A `method_exists()` guard against an older release,
  or a check that a filter really returned an array, is not dead code, and an
  analyser that calls it redundant is inviting a regression.
- **Level 6 since 2.3.3.** Every `array` in a docblock says what it holds and
  every method declares a return type. Three of them are array shapes rather
  than `array<string,mixed>`, and those the analyser verifies against what the
  method actually returns — a page of gift choices is
  `array{ids: int[], page: int, pages: int, total: int}` and cannot drift from
  that silently.
- **Level 7 since 2.3.3 as well**, checking that a union is narrowed before it
  is used. `wc_get_product()` returns `WC_Product|false`, and three functions
  that deliberately answer for the `false` — "no longer available", "claims no
  stock" — had signatures claiming to require a product. Their documentation now
  matches what they do. `is_offerable_variation()` carries a
  `@phpstan-assert-if-true`, so a true answer from it is proof of a product
  everywhere it is used, which is what the four remaining findings were about.
- **Level 8 since 2.3.3 too**, which checks what happens to a null.
  `wc_get_product()` has two ways of saying "no product" — `false` and `null` —
  and three functions passed both on to callers that were each checking for
  truthiness anyway. They now fold the two into one falsy answer at the
  boundary, so there is one absent case to handle rather than two. Two helpers
  that end the request were also marked `never`, which is what stopped the
  analyser believing execution continued past them.
- Level 9 remains and has not been attempted. It makes `mixed` explicit, and
  against WordPress code `mixed` is frequently the honest type; read what it
  reports before deciding whether the level is worth having.
