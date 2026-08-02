# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.3.3] — 2026-08-02

Three more levels of the analyser, and the one thing they found.

Levels 6 and 7 changed documentation only. Level 8 changed runtime code, which
is why this is a release rather than three annotation commits: `null` and
`false` were both being used to mean "no product", and one of them has stopped.
Behaviour is identical — every caller was testing truthiness — so this is a
PATCH.

### Changed

- **Static analysis raised to level 8**, which checks what becomes of a null.
  Eighteen findings, and this level touched runtime code where 6 and 7 had not.

  `wc_get_product()` has two ways of saying "there is no product": `false` and
  `null`. Three functions passed both straight through while declaring only
  `WC_Product|false`, and every one of their callers was doing a truthiness
  test anyway — so the distinction had never meant anything to anyone. They now
  fold it at the boundary and return one falsy answer. Behaviour is unchanged,
  since `null` and `false` are both falsy and no caller ever compared strictly;
  what changes is that a caller now has one absent case to handle instead of
  two.

  The rest were places where the analyser could not see that execution stops.
  `BOGO_Select_Ajax::fail()` and `BOGO_Select_Blocks::error()` end the request —
  one sends a JSON error, the other throws — and both were documented as
  returning `void`, so everything after a call to them looked reachable and the
  guard above it looked pointless. Both are `never` now, which resolved
  thirteen findings between them.

- **Static analysis raised to level 7.** Level 7 checks that a union type is
  narrowed before it is used, and it found seven places where
  `wc_get_product()` returning `WC_Product|false` had gone unnoticed. All seven
  were the same story with two different endings.

  Three are functions that deliberately answer for the `false` — a deleted
  product is "no longer available", and claims no stock — while their signatures
  claimed to require a `WC_Product`. Each one's very first line is the guard
  that handles it. The documentation was understating the code, so the
  documentation changed and nothing else did.

  The other four all trace back to `is_offerable_variation()`, whose `true`
  answer *is* proof there is a product, since it starts with an `instanceof`.
  Nothing said so, and callers went on to use the result without asking again —
  correct, but resting on an invariant no tool could see and nothing recorded.
  A `@phpstan-assert-if-true` states it once, in the one place that establishes
  it. Removing that line brings all four findings straight back, which is how it
  was checked rather than assumed.

- **Static analysis raised to level 6**, which is the step 2.3.2 named as next.
  Every `array` in a docblock now says what it holds and every method declares a
  return type: 34 `void` declarations and 46 array types, still with no baseline
  and nothing suppressed. No defect was found — that was the prediction, and it
  held.

  Three of the array types are shapes rather than `array<string,mixed>`, and
  those are the ones worth more than documentation. `get_choice_page()` and the
  two methods behind it declare
  `array{ids: int[], page: int, pages: int, total: int}`, which the analyser
  checks against what they actually return, so a key added, renamed, or dropped
  is an error rather than something the chooser discovers later. The other 43
  are honestly open-ended: a settings row and a WooCommerce cart item have no
  fixed shape, and inventing one would document a guess.

## [2.3.2] — 2026-08-02

An analyser, and the twelve findings it was wrong about.

What is added is development tooling rather than plugin functionality, so this
is a PATCH: the runtime changes it prompted are casts and annotations, and every
offer behaves exactly as it did in 2.3.1.

### Added

- **Static analysis, at a level the code actually passes** (`CODEX-REVIEW.md`
  L-04, the half deferred from 2.3.1). PHPStan reads the runtime code against
  the WordPress and WooCommerce stub packages and judges it as PHP 7.4, the
  compatibility floor, while running on a current PHP. `composer analyse` runs
  it and so does a CI job of its own. There is no baseline and nothing is
  suppressed: a baseline records what the code got wrong and then stops
  mentioning it, which turns the level into a number about history rather than
  about the code.

  The first run reported 116 problems. Eighty were missing type annotations —
  the level-6 rules, no defect among them, and the named next step. Of the rest:
  seven passed an int to `esc_attr()`, six used plugin constants the analyser
  cannot see because it does not run `define()`, three crossed float and string
  on a price, and two called `get_variation_attributes()` on a `WC_Product`,
  where it does not exist. All are fixed; none changed behaviour.

  Twelve were reported as redundant guards and were kept. The stubs describe
  current WooCommerce optimistically, so a `method_exists()` check against an
  older release reads as always true and is not — deleting those is how a
  compatibility guard becomes a regression. `treatPhpDocTypesAsCertain: false`
  stops docblock types being treated as certainties while leaving native types
  alone, and the twelve findings went away without the code going with them.

### Fixed

- **The pinned-sibling browser assertion matches what WooCommerce renders.**
  Tightening it in 2.3.1 turned it red: it looked for the variation's full post
  title, `Classic Variable Thing - Large`, in the cart line. WooCommerce renders
  a variation line as the parent's name with `Size: Large` beneath it, so that
  string is never there. The 2.3.1 CI failure was this assertion, not the
  plugin — and it also showed the assertion it replaced had been passing on a
  negative that was true whichever sibling was in the cart. It now matches the
  attribute, still scoped to the cart rows, and prints the row text when it
  fails.

## [2.3.1] — 2026-08-01

A settings screen that said no and meant it.

Answers the sixth Codex review (`CODEX-REVIEW-RESPONSE.md` Part 0).

### Fixed

- **The settings screen refuses a schedule it cannot honour, instead of
  describing one and saving it anyway** (`CODEX-REVIEW.md` M-01). A window
  running from the 20th to the 10th produced the error "so it will never run"
  and was then stored exactly as typed: `add_settings_error()` draws a message,
  and WordPress writes the option regardless. Every check the screen made was
  advisory, and two of them — this changelog at 1.3.0, and Q-005 — said
  otherwise. A reversed window now leaves the stored schedule in place, and the
  message names the schedule that survived. A date the screen cannot read does
  the same rather than clearing the bound: an empty field means "no bound"
  because a store asked for one, while a typo is a store asking for a bound and
  missing, and reading the second as the first is the one mistake that widens a
  campaign meant to be narrowed. Everything else in the same submission still
  saves. The reversed-window check no longer runs only for enabled offers, so a
  window that can never run cannot be parked on a disabled one and switched on
  later.

- **`2026-08-01junk` is no longer read as the first of August.** Each
  dash-separated part was converted with `intval()`, which stops at the first
  character it cannot read, so a value that looks nothing like a date became a
  real schedule boundary. The whole string must now be a date. Unpadded parts
  such as `2026-8-1` still work.

- **A Shop Manager can save the settings page they can already open**
  (`CODEX-REVIEW.md` M-02). The menu and the page both ask for
  `manage_woocommerce`, which is the capability WooCommerce gives that role, but
  `options.php` asks for `manage_options` unless the option group says
  otherwise — so the intended operator could fill the form in and be turned away
  on submit. One capability now governs both halves.

- **The offer summary counts selections rather than list entries**
  (`CODEX-REVIEW.md` L-05). A Buy list holding a product and one of its own
  variations reported "2 selected products", when the second selects nothing the
  first had not already selected. The stored list is unchanged — it is what the
  store typed — and only the sentence describing it was wrong.

- **Documentation that had drifted from the code** (`CODEX-REVIEW.md` L-01). The
  README described `bogo_select_reward_added` as taking two arguments when it
  has sent three since variations landed, so a callback written from the README
  never received the one that says which variation was given. It also called
  shipping untested, and the brief still asked for a manual staging pass; both
  have been covered by CI since v2.1.0 and v1.3.0 respectively. `ScheduleTest.php`
  was missing from the test inventory.

### Changed

- **One gift search loads each candidate once instead of twice**
  (`CODEX-REVIEW.md` M-03). A search judged every match for eligibility, loading
  each product, then sorted the survivors by name, loading each again — 120
  product loads for 60 candidates, and up to 400 at the 200-match ceiling, to
  render 24 cards. A per-request memo sits behind both passes. It holds only
  facts that cannot change inside one request; stock is deliberately not among
  them, so a reward added mid-request is still judged against a freshly loaded
  product. A test holds the ratio, and another asserts the memo does not outlive
  the cache flush that clears the variation memo beside it.

- **CI fails on PHP notices raised by this plugin** (`CODEX-REVIEW.md` L-02).
  The brief has asked for a clean log under `WP_DEBUG` since 1.0.0 and nothing
  had ever checked. The integration job now turns `WP_DEBUG` on before the
  plugin is installed and fails on any logged line naming a file of ours;
  notices from WordPress and WooCommerce themselves are printed and ignored,
  since no change here can fix them. The workflow also declares
  `permissions: contents: read`, so the token's reach is versioned with the code
  rather than held in a repository setting (`CODEX-REVIEW.md` L-03).

- **The settings screen has tests.** It had none, which is how a screen that
  said it refused a schedule went on saving it. `AdminSettingsTest.php` asserts
  what the sanitizer returns and not merely what it says, because a test that
  read the error message would have passed against the broken code.

- **The pinned-sibling browser assertion checks the card it means**
  (`CODEX-REVIEW.md` L-02). It computed whether the large variation was selected
  and then never asserted it, falling back to searching the whole page for
  "Large" — which appears in the chooser's own options either way, so it passed
  whether or not the swap happened.

## [2.3.0] — 2026-08-01

A Buy list that can name one size.

### Changed

- **The Buy list can name a single variation.** Its search box offered top-level
  products only, so an offer could turn on every size of a T-shirt or on none of
  them — "buy 2 of the Large" could not be expressed from the settings screen at
  all. The Get list has searched variations since 1.3.0; the Buy list now uses
  the same search. Nothing changed behind it: `is_buy_eligible()` has matched a
  cart line by its variation ID or its parent's since 1.0.0, and D-006 said so on
  day one, so the whole limit was the picker in front of it. Listing a product
  still counts every variation of it. Listing a variation counts only that one,
  and listing both is the same as listing the product alone. The behaviour was
  verified by hand when variable rewards landed (`OPEN-QUESTIONS.md` Q-003) but
  had no test, because no supported configuration could produce it; it now has
  two.

## [2.2.1] — 2026-08-01

A chooser that rendered perfectly and answered nothing.

### Fixed

- **The gift chooser no longer goes dead after a classic cart update.** Its
  buttons were reported as doing nothing at all — the "Next" page button on a
  catalogue of more than 24 gifts, with no new page and no error to say why.
  The chooser is printed inside the cart form (`woocommerce_before_cart_table`),
  and WooCommerce's own cart script replaces that whole form with the server's
  fresh copy after every cart AJAX update: updating a quantity, applying a
  coupon, removing a line. The plugin held the slot inside that form from page
  load and delegated its listeners from it, so after any such update the
  listeners belonged to a detached element. The chooser then looked perfectly
  normal — the right page number, the right buttons enabled — and no click
  reached the script at all: paging, choosing, removing, and searching were all
  silently dead. Listeners now delegate from the document and the slot is looked
  up on every use, which is what WooCommerce's own cart script does for the same
  reason. The page number and search term are re-read whenever the panel in the
  document is no longer the one they described, so the first click after a cart
  update pages from where the customer actually is. A browser test now updates
  the cart and asserts that a chooser click still reaches the server.

- **The chooser is never printed without the script that answers it.** Its CSS
  and JavaScript were enqueued only where `is_cart()` or `is_checkout()` was
  true during `wp_enqueue_scripts`, but the classic chooser renders on
  `woocommerce_before_cart_table`, which fires wherever the cart template is
  rendered — including a cart a theme or a page builder puts on a page
  WooCommerce does not recognise as one. There the chooser rendered with nothing
  behind it: buttons that look right, answer nothing, and say nothing about why.
  Printing the slot now enqueues the assets itself, so the chooser and its
  script always arrive together. The hook still runs first on ordinary cart and
  checkout pages, which is what keeps the stylesheet in the head; the block cart
  already enqueued this way and now shares the one path.

## [2.2.0] — 2026-08-01

A mobile layout for the gift chooser, and Q-006 closed as a decision.

### Changed

- **The gift chooser is compact on phones.** Below 600px the card grid fell to
  a single column, so every option became a full-width product image and a
  handful of gifts pushed the customer's own cart lines several screens down.
  Each card is now a cart-line-shaped row — 64px thumbnail, name and price
  beside it, button underneath. Six gifts now take about one screen instead of
  four. Nothing changes at wider widths, and the change is CSS only, so the
  classic cart, the block cart, and checkout all get it from the same rule.

- **A reward of a product already in the cart keeps its own line**
  (`OPEN-QUESTIONS.md` Q-006, now `DECISION.md` D-020). This is what the plugin
  has always done and it is now a decision rather than an assumption, with tests
  behind it: the two lines carry different prices, so one merged line could not
  show the customer which unit was free or discounted.

## [2.1.0] — 2026-07-31

Campaign scheduling, a chooser defect found by review, and the integration
coverage that closes out `CODEX-REVIEW.md` M-03.

### Fixed

- **Two variations of one product, each listed individually, are no longer both
  shown as chosen** (`CODEX-REVIEW.md` M-01). Such cards share a parent, and the
  selected-state comparison looked only at the parent — so picking one marked
  both, and because a chosen card shows only "Selected" and "Remove", the
  customer was left with no control anywhere on the page for switching to the
  other. Which card owns the selection is now decided once, where the whole list
  is visible, with a variation listed in its own right taking precedence over the
  parent card that could also offer it.

### Changed

- **A page of variable rewards costs far fewer product loads**
  (`CODEX-REVIEW.md` M-02). Rendering asked for the same variation through four
  separate paths: judging the parent a card, enumerating its selector, pricing
  each option, and quoting the card. On a page of 24 variable products with 20
  variations each that was 2,016 product loads against 504 distinct products;
  it is now 552. A test holds the ratio so it cannot drift back.

### Added

- **The offer can be given a start date and an end date** (`OPEN-QUESTIONS.md`
  Q-005, `DECISION.md` D-019). Both are optional and both are inclusive whole
  days in the store's timezone: an offer running "1–7 August" is live on both of
  those days and stops on the 8th. Leaving a field empty leaves that side
  unbounded, and an offer with neither behaves exactly as it did before — every
  existing install is unscheduled, with no migration.

  The settings screen refuses a window that ends before it begins, and says so
  when an enabled offer has already ended or has not started yet, since in both
  cases the storefront looks identical to the offer being switched off.

- **Two tests fix how the Buy quantity counts** (`OPEN-QUESTIONS.md` Q-002,
  confirming `DECISION.md` D-003). Quantities are summed across everything on the
  Buy list, so one each of two listed products satisfies a Buy 2 offer. A store
  wanting "two of the same product" gives the offer a Buy list of one product,
  which needs no new setting — a recipe now in `INSTRUCTIONS.md` §4 and held by a
  test, since untested advice rots.

- **Shipping behaviour is covered, answering `OPEN-QUESTIONS.md` Q-004.** That
  question had stated from the beginning that a free reward adds weight to the
  parcel but nothing to order value, and nothing had ever checked it — every
  other fixture uses virtual products so that shipping stays out of the way.
  Measured now, in both modes: a free reward leaves the order value untouched and
  cannot carry a customer over a free-shipping threshold, a discounted one adds
  its own reduced price and can, and either joins the parcel.

- **A real order is now placed in CI, and its metadata and stock asserted**
  (`CODEX-REVIEW.md` M-03). The plugin writes reward metadata on
  `woocommerce_checkout_create_order_line_item`, a hook nothing in CI had ever
  fired — `BRIEF.md` §8.6 listed order placement, order metadata, and stock
  reduction as verified by hand. A new lane builds a qualifying cart over the
  Store API, checks out through it, and then inspects what landed: the reward
  line and its quantity, the discounted line total, both meta keys, the visible
  label, and stock down by the awarded quantity rather than by one.

- **The classic cart and checkout are covered in a real browser.** Every browser
  assertion until now ran against the Cart and Checkout blocks, because that is
  what WooCommerce provisions on a fresh install — but the shortcode path is
  different code on both sides, reaching the page through template hooks rather
  than the `render_block` filter and choosing over admin-ajax rather than the
  Store API. The reward is chosen by clicking the button, so the JavaScript and
  the reload that classic mode performs are covered too, along with the badge,
  the discounted price, the locked quantity, and the checkout slot being marked
  so it never reloads a part-filled form.

- **Tax on a discounted reward is covered, in both display modes.** The plugin
  sets the line's price before totals run, so tax should follow the discounted
  figure rather than the price it was discounted from — the difference a store
  would feel, and never checked until now. A store whose prices exclude tax
  charges 10.00 plus tax on a 20.00 reward at half price; one whose prices
  include tax charges exactly 10.00, tax included. Both are asserted, along with
  the rate being applied to the discounted line rather than the original.

- **Coupon behaviour alongside a reward is now tested rather than reasoned.**
  The 1.3.0 notes said eligible coupons stack on the strength of where the
  pricing hook sits, since the unit stubs have no coupon support. Both halves are
  now covered against a real store: an eligible 20% coupon over a 50% reward
  leaves the customer paying 40% of list, and a coupon excluding the reward
  leaves it untouched while still discounting the rest of the cart.

- **`tests/README.md` describes what is actually run.** It had gone stale in the
  opposite direction, still saying there was no database, HTTP, WordPress
  install, JavaScript runner, or real block rendering — all of which the
  integration job has done since 1.2.1. It now covers both suites and is
  explicit about what neither reaches.

## [2.0.0] — 2026-07-31

A single change, and a breaking one: the WooCommerce floor is raised to
match what is actually tested. No functional change to the plugin.

### Changed

- **BREAKING: WooCommerce 9.9 or later is now required**, up from 7.0
  (`DECISION.md` D-018, answering `CODEX-REVIEW.md` M-02). The old floor was
  never tested — the integration matrix has only ever run 9.9.5 and current, and
  the unit stubs model this plugin's callbacks rather than WooCommerce's, so
  nothing established that 7.0 through 9.8 preserve the Store API, block
  rendering, hydration, and price-display contracts the plugin relies on. A
  declared minimum is a promise about what someone can install on, and that one
  was unbacked.

  Stores on 7.0–9.8 can no longer install this version, and any already running
  it will find it inert after upgrading, with an admin notice explaining why.
  They can remain on 1.3.0, which is unaffected and stays published.

  The header declares `9.9` while CI pins `9.9.5`, so 9.9.0–9.9.4 are claimed but
  not exercised — a far smaller gap than the one this closes.

## [1.3.0] — 2026-07-31

Two features that widen what the reward can be: it no longer has to be
free, and it no longer has to be a simple product.

### Added

- **Variable products can be rewards, with the customer choosing the variation**
  (`DECISION.md` D-017, which supersedes D-006 and answers `OPEN-QUESTIONS.md`
  Q-003). Add a variable product to the Get list and its card carries a dropdown
  of every variation that can be given, each with its own price and, where it
  cannot, the reason. Add a single variation instead and the reward is pinned to
  it with no choice shown. The Buy side already counted variations and is
  unchanged.

  D-006 refused these because "one free t-shirt" does not say which size. The
  answer turned out to be to ask, which is what a chooser exists to do. Its
  reasoning survives for grouped and external products, and for a variation that
  leaves an attribute set to "Any" — that still needs a choice making, so it is
  not offered.

  The card is one card per product, never one per variation, so a catalogue of
  fifty variable products stays fifty cards. It quotes a variation rather than
  the parent, whose price is the low end of a range and need not match any of
  them. Where variations share a parent's stock record they compete with each
  other, and the dropdown says so against the option rather than leaving the
  customer to work it out.

  Internally a reward is no longer one product ID but a product and variation
  pair, because a variation's cart line stores its parent in `product_id` and the
  two are otherwise indistinguishable. The Store API reports both, additively.

- **An integration scenario for the variable reward.** The block job seeds a
  variable product priced differently per variation, chooses one through the
  Store API, and asserts the line is charged from the chosen variation rather
  than the parent's range, that the parent alone is refused, and that the cart
  renders one selector listing both options.

- **The reward can be discounted rather than only given away** (`DECISION.md`
  D-016, answering `OPEN-QUESTIONS.md` Q-008). "Buy 2, get 1 at 50% off" is now
  as configurable as "get 1 free", through a *Reward price* control on the
  settings screen and a percentage field beside it. Two new settings keys carry
  it, `get_discount_type` and `get_discount_value`, both defaulting to the free
  behaviour — an option row saved before this release reads back as a free gift,
  so nothing changes for an existing store until someone changes it.

  The reward's price is worked out from a product loaded fresh on every pricing
  pass rather than from the cart line's own product object. WooCommerce
  recalculates totals more than once in some requests, and setting a price to
  zero survives that where taking half off does not: reading back its own output
  would compound the discount to a quarter, then an eighth. The cost of the
  approach is that a price another plugin set on the cart item is overwritten
  rather than discounted, which matters to stores running dynamic pricing.

  The discount comes off the effective selling price, so a reward already on
  sale is discounted from its sale price. Eligible coupons still apply on top,
  as they do to any reduced price — a 20% coupon over a 50% reward leaves the
  customer paying 40% of list, where that coupon's own product, category, and
  sale-exclusion rules allow it at all. That follows from where the pricing hook
  sits rather than from a test; the unit stubs have no coupon support.

  Fixed-amount discounts were deliberately left out. A percentage is linear, so
  it needs no clamping against negative prices and raises no per-unit versus
  per-line question; "$5 off" can be added later on the same field.

- **An integration scenario for the discounted reward.** The block job now
  switches the seeded store to 50% off and runs a second browser pass, asserting
  through the Store API that WooCommerce charges the discounted figure, that it
  was not applied twice, and that the cart says "Discounted item" where it used
  to say "Free gift".

- **An automated WordPress + WooCommerce integration job** (`CODEX-REVIEW.md`
  M-02). CI now installs the built zip into a real WordPress with WooCommerce —
  the compatibility floor (9.9.5) and whatever is `latest` — seeds a store, and
  drives the Cart and Checkout blocks in headless Chromium. It asserts the two
  things the unit suite structurally cannot: that the chooser slot never takes
  the block root's `data-block-name`, and that each block leaves `is-loading`
  and renders — a real checkout form, both cart lines, and the visible gift
  label, plus the Store API's zero price, locked quantity, and label members.

  This is the gap that let H-01 ship: 127 unit tests passed while the Checkout
  block was unusable. The job was rehearsed against a live store before
  landing — 28/28 checks pass, and reverting the injection priority to 10 turns
  10 of them red, checkout included. Because the matrix includes `latest`, a
  future WooCommerce that breaks the blocks now fails CI rather than a customer's
  store.

  Classic cart and checkout, stock reduction, and order placement remain manual
  (BRIEF.md §8.6).

### Changed

- **Customer-facing wording follows the offer instead of assuming it is free.**
  Roughly twenty strings across the cart, chooser, notices, and block metadata
  ask the engine what the reward costs rather than hardcoding "free". Every one
  of them is byte-identical to the previous wording while the reward is free, so
  a store that never configures a discount sees no change at all.

- **Order lines record the offer that produced them.** A hidden
  `_bogo_select_discount` meta stores `free` or `percent:50` as it stood when
  the order was placed, because the settings can move afterwards and an order
  has to be able to explain its own pricing. The existing `_bogo_select_free`
  flag keeps its name and value on discounted lines — it is a persisted key that
  existing reports query, and now marks a line as the offer's rather than
  claiming it was free.

## [1.2.1] — 2026-07-31

Compatibility metadata only. No functional change: 13 of the 14 runtime files
are byte-identical to 1.2.0, and the fourteenth — `bogo-select.php` — differs
only in the version string and the `WC tested up to` header. Upgrading changes
nothing a customer can see.

### Changed

- **`WC tested up to` advanced from 9.9 to 10.9.** The previous review declined
  to advance it while the Checkout block was broken on current WooCommerce, and
  1.2.0 shipped the fix without the matrix that would justify the claim. That
  matrix has now been run: a disposable WordPress 7.0.2 / PHP 8.2 / MariaDB
  10.11 stack with Twenty Twenty-Five, the plugin installed **from the 1.2.0
  zip** rather than from source, exercised in a real browser on WooCommerce
  9.9.5 and 10.9.4 — 10.9.4 being the current release. All four block surfaces
  pass: the chooser renders, the block root keeps its own `data-block-name`,
  the Cart and Checkout blocks mount and leave `is-loading`, the checkout
  renders contact, address, and Place Order, the gift line reads `$10.00 →
  $0.00` against an unchanged cart total, and `Free gift: BOGO promotion` is
  visible in the order summary. No JavaScript errors.

  H-01 was confirmed causally rather than by coincidence: toggling only the
  injection priority between 10 and 20 on the installed plugin reproduced the
  empty loading shell at 10 and a working checkout at 20, on WooCommerce
  10.9.4, with nothing else changed.

  Two limits are worth recording. WooCommerce did not preload a cart response
  into the page in this configuration, so the blocks fetched it after load and
  the hydration half of the label fix was never observed running — it remains
  covered by source reading and unit tests only. And with no payment gateway
  configured, checkout was verified up to a populated Place Order form rather
  than a completed order.
- **README records the tested versions** alongside the minimum requirements.

## [1.2.0] — 2026-07-30

Addresses the follow-up Codex review (`CODEX-REVIEW.md`) and its central
conclusion: the plugin worked for classic carts and did nothing useful on a
block store. Responses are recorded in `CODEX-REVIEW-RESPONSE.md`.

### Added

- **Cart and Checkout Blocks support.** The chooser renders ahead of the
  `woocommerce/cart` and `woocommerce/checkout` blocks, offer state travels on
  the Store API cart response, and choosing or removing a gift goes through a
  registered Store API update callback, so the blocks re-render from the
  response they already trust — no page reload, and a half-filled block
  checkout survives. The gift line is labelled through `woocommerce_get_item_data`
  and its quantity locked through the Store API's own quantity limits, because
  the classic name and quantity filters never run inside a block. The
  `cart_checkout_blocks` compatibility declaration is now `true`.
- **The chooser on the checkout page**, classic and block alike. A customer who
  goes straight to checkout without visiting the cart previously had nowhere to
  pick their gift. On classic checkout the page is never reloaded — that would
  empty a part-filled form — so the chooser re-renders and WooCommerce is asked
  to update the order review instead.
- **A chooser that keeps up with a block cart.** The markup now sits in a slot
  element the script can replace wholesale, and in block mode the script follows
  the `wc/store/cart` data store: crossing the qualifying threshold makes the
  chooser appear, and dropping below it makes the chooser go away, without a
  page load. A new `bogo_select_refresh` AJAX endpoint returns the re-rendered
  chooser.
- **`bogo_select_choice_ids`**, a page-aware filter receiving the scope, search
  term, page, and page size alongside the IDs. `bogo_select_get_products` is
  unchanged and still applied per page. (C-04)
- **`bogo_select_search_limit`** (default 200), capping how many matches a gift
  search inspects, and **`bogo_select_eligibility_ttl`** (default 600 seconds)
  for the new eligibility cache.

### Fixed

- **The block checkout renders again on current WooCommerce** (`CODEX-REVIEW.md`
  H-01). The chooser was injected on `render_block` at priority 10, and
  WooCommerce's own `BlockTypesController::add_data_attributes()` is also a
  priority-10 `render_block` filter that walks to the *first tag* of the content
  it is handed and stamps `data-block-name` on it. Which callback ran first was
  decided by plugin load order; losing that coin toss meant WooCommerce branded
  the BOGO slot `data-block-name="woocommerce/checkout"` and left the real
  checkout root unbranded, so the Checkout frontend mounted against an empty div
  and the customer got a permanent loading shell with no address, order summary,
  or payment. Injection now runs at priority 20, after WooCommerce has decorated
  the original block root, which makes the ordering explicit rather than
  incidental. Observed on WooCommerce 10.9.4; the reproduction did not depend on
  a gift being selected, or on the cart qualifying at all.
- **A failed validation pass no longer disables validation for the rest of the
  request** (`CODEX-REVIEW.md` L-01). `BOGO_Select_Cart::validate()` raised its
  re-entrancy guard, called `run_validation()`, and lowered the guard on the
  line after. Since that pass removes cart items and changes quantities, an
  extension observing either hook could throw straight past the reset and leave
  the guard stuck on, so every later pass in that request returned early and
  unearned gifts stayed in the cart. The guard is now cleared in `finally`,
  matching the exception-safe suspend/resume path.
- **The "Free gift: BOGO promotion" label now actually appears in the block
  cart and block checkout** (`CODEX-REVIEW.md`, first round M-01). The label is supplied
  through `woocommerce_get_item_data`, which only spoke up when WooCommerce
  reported a Store API request. A block cart paints its first frame from a
  cart response WooCommerce builds *inside the page request* and preloads into
  the markup, and during that build `REQUEST_URI` is still `/cart/`, so
  `WC()->is_store_api_request()` answered no and the row came back empty. The
  plugin now brackets the response build itself — through
  `rest_request_before_callbacks`/`rest_request_after_callbacks` for a
  dispatched Store API route and
  `woocommerce_hydration_dispatch_request`/`woocommerce_hydration_request_after_callbacks`
  for the preloaded one — so both the preloaded and the fetched cart carry the
  label. The entry also sets `name` alongside `key` and a non-empty `display`
  alongside `value`, because which member the blocks read has moved between
  WooCommerce versions and the previous empty `display` blanked the row on the
  versions that prefer it.
- **Searching gifts by SKU now searches SKUs.** *All Products* search passed the
  term to `wc_get_products()` as `s`, which WordPress resolves against the post
  title, excerpt, and content and never the SKU — so a search that matched only
  a SKU found nothing, contrary to what the search box, README, and acceptance
  criteria all promised. Search now goes through WooCommerce's product data
  store (`search_products()`, the call behind the admin product search), which
  covers name, description, and SKU, with a two-query fallback where that store
  is unavailable. (C-01)
- **The unit stub no longer certified the bug.** `wc_get_products()` in the test
  stubs pretended `s` matched SKUs, which is why a broken SKU search passed its
  own test in 1.1.0. The stub now follows core semantics — `s` for keywords,
  `sku` for SKUs — and the data store's search is stubbed separately. (C-01)
- **Validation can no longer be left suspended.** The suspend/resume pair around
  a gift swap is now closed by `try`/`finally`, so an exception thrown by a
  third-party add-to-cart callback cannot leave the next request unguarded.

### Changed

- **A curated gift list is no longer hydrated in full on every request.**
  Searching *Select Products* is done in the database, constrained to the
  configured IDs, and the eligibility of that list is cached until the settings
  or any product are saved. (C-03)
- **A search total no longer counts gifts that cannot be given.** Totals for a
  search are taken after the eligibility gate. Browsing *All Products* without a
  search still reports the catalogue total, which is what bounds the query. (C-04)
- **Unit suite grown from 71 tests / 146 assertions to 131 / 259**, adding cover
  for SKU search, the eligibility cache, the page-aware filter, gift selection
  and replacement, chooser rendering, and the block integration. The last two
  tests are ordering and exception-safety regressions that a direct call to the
  method under test cannot catch: one drives the whole `render_block` chain with
  a stand-in for WooCommerce's `add_data_attributes()` and asserts the chooser
  slot never takes the block root's identity (H-01), the other throws from a
  filter mid-validation and asserts the next pass still runs (L-01).
- **`bin/verify-zip.sh`, and a CI job that runs it** (`CODEX-REVIEW.md` M-01,
  M-02). The v1.2.0 archive was built before its own fix landed and shipped a
  superseded class while every test passed, because nothing compared the package
  with the source. The script now requires every runtime file in the worktree to
  appear in the archive with an identical SHA-256, CI builds and verifies on
  every push, and the step is a documented release gate (BRIEF.md §8.5).
- **Two accepted limitations are now written down** rather than implied
  (`CODEX-REVIEW.md` M-03, L-02). Browse totals in *All Products* mode are
  catalogue counts taken before the eligibility gate, so the count can overstate
  what is selectable and a page can come back short while eligible products wait
  on later pages; and a gift search reports the eligible products among the
  first 200 matches, not among every match. Both are documented in README.md
  under *Limitations* and at the code that implements them. Curating a list with
  *Select Products* gives exact counts.

## [1.1.0] — 2026-07-30

Addresses the findings of the Codex repository review (`CODEX-REVIEW.md`); the
responses are recorded in `CODEX-REVIEW-RESPONSE.md`.

### Added

- **Searchable, paged gift chooser.** Options are fetched a page at a time (24 by
  default) with a name/SKU search box and Previous/Next controls, over a new
  public `bogo_select_choices` AJAX endpoint. Both Get scopes are paged, so a
  *Select Products* list of any length is bounded too. (F-02)
- **`bogo_select_all_products_limit` is now the page size** rather than a hard cap
  on the *All Products* scope. The filter name is unchanged.
- **WooCommerce dependency enforcement**: a `Requires Plugins: woocommerce` header
  and an activation guard that refuses activation without WooCommerce 7.0 or
  later. (F-05)
- **PHPUnit unit suite** (`tests/`) covering settings normalization, qualification,
  eligibility, availability, chooser paging, and cart validation, plus a GitHub
  Actions workflow running it on PHP 7.4–8.3. (F-06)

### Fixed

- **A gift is no longer kept when its stock runs out.** Availability was only
  rechecked when the earned quantity changed, so a cart whose quantity was stable
  kept an unbuyable gift until checkout failed. It is now rechecked on every
  validation pass, and on `woocommerce_cart_item_restored`. (F-01)
- **Paid and free copies of one product no longer over-commit its stock.**
  Availability counts total cart demand against the stock-managed product ID, so 2
  paid plus 2 free of a product with 3 in stock is refused up front. (F-01)
- **Products past the fiftieth are reachable again.** *All Products* previously
  offered only the first 50 simple products by title, with no way to reach the
  rest. (F-02)
- **Changing your gift can no longer leave you with none.** The replacement is
  added before the previous gift is removed; if the add is refused — by core stock
  validation or a third-party `woocommerce_add_to_cart_validation` callback — the
  original gift stays in the cart and the reason is reported. (F-03)
- **Duplicate free lines are normalised.** Validation enumerates every flagged
  line, keeps one, and removes the rest; previously only the first was inspected
  while pricing zeroed them all. (F-04)
- **Multi-unit gift subtotals show the right figure.** The subtotal column struck
  through one unit's price; eight $10 gifts now strike through $80, not $10. (F-07)
- **Product variations are rejected as gifts** by both the eligibility check and
  the settings sanitizer, matching the documented "simple products only" rule. (F-08)

### Changed

- The unused random `bogo_select_stamp` cart item datum was removed. It was never
  validated, and being random it prevented identical gift lines from merging.
  Re-validation derives everything from current settings and current cart state;
  `BRIEF.md` §4.3 now describes that instead of the unimplemented
  `_bogo_select_token`. (F-04)
- Release archives exclude `tests/`, `vendor/`, `composer.*`, `phpunit.xml.dist`,
  and `.github/`.
- Documentation corrected where it had drifted from the code: cart flag key,
  AJAX endpoint security model, notice placement on cart/checkout, and the
  WooCommerce dependency lifecycle. New decisions D-011, D-012, and D-013 record
  the design choices behind the fixes above. (F-08)

## [1.0.0] — 2026-07-30

Initial release.

### Added

- **Admin settings screen** at *WooCommerce → BOGO Select*: enable/disable, offer
  title, Buy quantity, Get quantity, Buy scope, Get scope, repeat mode, and a
  toggle for the site-wide qualification notice.
- **Independent Buy and Get scopes**, each set to *All Products* or *Select
  Products* with its own AJAX-searched product list.
- **Arbitrary Buy/Get quantities** — any positive integers (Buy 2 Get 2,
  Buy 4 Get 8, …).
- **Customer gift chooser** on the cart page: product cards with image, name,
  price, stock state, and Select/Change actions, added via AJAX.
- **$0.00 reward line items** — the chosen gift is added as a real cart line with
  its price overridden to zero, so WooCommerce reduces stock normally.
- **Continuous cart re-validation** — the gift is removed or its quantity adjusted
  automatically when the cart stops qualifying, the offer changes, or stock runs
  short, with an explanatory customer notice.
- **Quantity lock** on gift line items (removal still permitted).
- **Server-side re-validation** of every selection, and again on
  `woocommerce_check_cart_items` before checkout, so a crafted request cannot
  award a free product.
- **Repeat mode** (off by default) awarding one gift set per multiple of the Buy
  quantity.
- **"Free (BOGO)" labelling** on cart lines, checkout, order emails, and the admin
  order screen.
- **Developer hooks**: `bogo_select_get_products`, `bogo_select_qualifies`,
  `bogo_select_reward_quantity`, `bogo_select_reward_added`.
- **HPOS compatibility** declaration for WooCommerce High-Performance Order Storage.
- **Uninstall routine** removing plugin options.
- Project documentation: `README.md`, `BRIEF.md`, `INSTRUCTIONS.md`,
  `DECISION.md`, `OPEN-QUESTIONS.md`, and this changelog.

### Known limitations

- Classic (shortcode) cart and checkout only — Cart/Checkout **blocks** show the
  notice but not the chooser. See `OPEN-QUESTIONS.md` Q-001.
- One offer at a time; offers do not stack.
- Product IDs only — no category, tag, or attribute scoping.
- Variable products cannot be gifts (they remain eligible on the Buy side).
  See `DECISION.md` D-006.
- Untested against Subscriptions, Bundles, and Composite Products.

[Unreleased]: https://github.com/johnjanney/bogo-select/compare/v2.3.3...HEAD
[2.3.3]: https://github.com/johnjanney/bogo-select/compare/v2.3.2...v2.3.3
[2.3.2]: https://github.com/johnjanney/bogo-select/compare/v2.3.1...v2.3.2
[2.3.1]: https://github.com/johnjanney/bogo-select/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/johnjanney/bogo-select/compare/v2.2.1...v2.3.0
[2.2.1]: https://github.com/johnjanney/bogo-select/compare/v2.2.0...v2.2.1
[2.2.0]: https://github.com/johnjanney/bogo-select/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/johnjanney/bogo-select/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/johnjanney/bogo-select/compare/v1.3.0...v2.0.0
[1.3.0]: https://github.com/johnjanney/bogo-select/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/johnjanney/bogo-select/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/johnjanney/bogo-select/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/johnjanney/bogo-select/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/bogo-select/releases/tag/v1.0.0
