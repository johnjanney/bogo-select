# Release summary — v2.3.0 to v2.3.8

Between v2.3.0 and v2.3.8 there are eight PATCH releases, all dated 2026-08-01
or 2026-08-02. No release changes how an offer behaves for a correct
configuration. The list below uses ASD-STE100 Simplified Technical English.

## 2.3.1 — the settings screen

- The settings screen refuses a schedule that it cannot use. Before this
  release, it showed an error and stored the bad schedule.
- The screen no longer reads `2026-08-01junk` as a date. The full text must be
  a date. A short form such as `2026-8-1` is still correct.
- A bad date keeps the schedule that is in the database. It does not clear the
  limit.
- A Shop Manager can save the page. Before, `options.php` asked for a different
  capability, and it refused the operator.
- The offer summary counts the selected products. A product and one of its own
  variations count as one selection.
- One gift search loads each product one time. Before, it loaded each product
  two times.
- CI stops if the plugin writes a PHP notice.
- The README shows three arguments for `bogo_select_reward_added`. The code has
  sent three since variations came in.
- The settings screen has tests. It had none before.

## 2.3.2 — static analysis

- PHPStan reads the plugin code. There is no list of accepted errors, and the
  team hides no error.
- The first run showed 116 problems. Eighty of these were missing type notes.
- The team corrected the other problems. Seven sent an integer to `esc_attr()`.
  Three mixed a float and a string on a price. Two called a method that does not
  exist.
- The team kept twelve guards for old WooCommerce releases. The tool called them
  unnecessary, but the stubs show only the current WooCommerce.
- A browser test now looks for the correct text in a variation cart line.

## 2.3.3 — analysis levels 6, 7 and 8

- Level 6 added 34 return types and 46 array types. It found no fault.
- Level 7 found seven places where the code did not examine a
  `WC_Product|false` value. Three needed better documentation only.
- Level 8 changed the code. Three functions used both `null` and `false` for
  "no product". They now give one answer.
- Two functions that stop the request are now `never`. This removed thirteen
  findings.

## 2.3.4 — WordPress Coding Standards

- PHPCS runs from `composer sniff` and in CI.
- The first run showed 422 errors in 42 files. The shipped plugin had 42 of
  them. The plugin now passes fully.
- The shop notice had two translator comments. The first one described an old
  string with two placeholders. The string takes three. The old comment is gone.
- The uninstall script no longer leaves two variables in the global scope.

## 2.3.5 — CI, supply chain and the phone layout

- The build no longer puts `node_modules` in the archive. A developer build was
  4.1 MB and 180 files. It is again 136 KB and 23 files.
- `verify-zip.sh` had approved that bad archive. Both scripts now remove that
  directory before they compare.
- All CI actions are pinned to a commit SHA. Dependabot watches the pins each
  week.
- Five updates came in: checkout 5.1.0 to 7.0.1, setup-node 5.0.0 to 7.0.0,
  upload-artifact 4.6.2 to 7.0.1, Playwright 1.56.0 to 1.62.0, and MariaDB 10.11
  to 12.3.
- A new test shows the compact chooser at 390 x 844 pixels. All earlier browser
  tests used 1280 pixels, where the compact rule does not apply.
- The test found a fault. The buttons were 21 pixels tall. The minimum target is
  now 44 pixels.

## 2.3.6 — measurement and one batch fetch

- A new benchmark measures a catalogue of 2000 products. It records time,
  database queries, CPU and memory.
- The benchmark found 612 database queries for a broad gift search.
  `_prime_post_caches()` now gets the data in one batch.
- The counts decreased: a broad search from 612 to 15 queries, a cold curated
  build from 1508 to 11, and a page from 81 to 12.
- A search for one SKU stays at 12 queries. The code skips a batch of one
  product.
- A new test opens the settings screen through `options.php` with a real role. A
  Shop Manager saves a schedule. An Editor cannot open the page.
- The test site uses a clock that is not UTC. This shows that the day limits use
  the store timezone.

## 2.3.7 — an array where an ID must be

- `absint()` gave 1 for an array. An array in a product ID field became a
  reference to product 1.
- All code that makes an ID from sent data now makes sure that the value is a
  scalar.
- The settings row has a declared shape. `get()` gives a type for each key.
- This shape immediately found three places that sent an integer or a float to
  `esc_attr()`.
- The release did not take level 9 and gave two reasons for this.

## 2.3.8 — level 9

- The tool now runs at level 9. There is no list of accepted errors, and the
  team hides no error.
- Both reasons in 2.3.7 were wrong, and this release corrects them.
- The first reason said that a change increased the findings from 41 to 80. The
  `@phpstan-import-type` line had never reached the file. With the line, the
  same change removes 21 findings.
- The second reason said that the cart-item casts needed eighteen guards. Most
  of them needed a declared shape. `state()` and `variation_options()` now
  declare their keys.
- The values that come from a cart line go through the same `to_id()` function
  as other data from a request.
- This is the only change to the code. An array now gets a refusal, where the
  cast gave 1.
