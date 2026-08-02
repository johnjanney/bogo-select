# Codex Repository Review

**Review date:** 2026-08-01

**Reviewed state:** commit `b092d3a` (`main`, same as `origin/main`, tag
`v2.3.0`). The worktree contained the prior edit to this report before this
review. No runtime or test file had an uncommitted change.

**Review scope:** all repository documents, PHP and JavaScript runtime code,
tests, CI configuration, build scripts, the v2.3.0 release archive, and the
commits after the prior reviewed state `3b14480`. The review also checked
`CODEX-REVIEW-RESPONSE.md` for a response to the prior findings.

## Overall assessment

Not all prior issues are resolved. The two Medium and four Low findings from the
prior review remain open. The later releases do not change the affected schedule
validation, Settings API capability, CI dependency, or static-analysis paths.
No response to the review of commit `3b14480` was found in
`CODEX-REVIEW-RESPONSE.md`.

The newer work is otherwise useful and mostly correct:

- v2.2.0 adds a compact mobile chooser layout.
- v2.2.1 fixes the chooser after WooCommerce replaces the classic cart form. It
  also enqueues the chooser script when the chooser is printed.
- v2.3.0 lets the Buy list name one variation. The existing qualification
  engine already supports this rule.

No Critical or High severity defect was found. Three Medium findings are open:

1. Invalid or reversed schedule dates can still be saved with unsafe meaning.
2. A Shop Manager can open the settings page but cannot save its settings.
3. One broad All Products gift search can load 200 products twice before it
   returns a 24-card page.

Five Low findings concern documentation drift, missing or weak test evidence,
CI and release-chain hardening, static analysis, and a false count in the new
Buy-list summary. No exploitable privilege bypass, arbitrary reward award,
injection path, or sensitive-data disclosure was found in the runtime code.

## Evidence and method

Verified facts in this report come from:

- the repository at commit `b092d3a` and the diff from `3b14480`;
- all six parts of `CODEX-REVIEW-RESPONSE.md`;
- the local PHPUnit suite on PHP 8.1 and PHP 8.5: **238 tests and 539
  assertions**, all passed;
- PHP, JavaScript, and shell syntax checks;
- `composer validate --strict` and `composer audit --locked`;
- the ZIP parity script and the local and published release digests;
- focused reproductions for date sanitization, Buy-list summary output, and All
  Products search load count;
- repository-level GitHub Actions permission checks; and
- the [passing GitHub Actions run for commit `b092d3a`](https://github.com/johnjanney/bogo-select/actions/runs/30723733964).

The current CI run passed all jobs. Its `latest` lane installed WooCommerce
10.9.4. The 9.9.5 compatibility-floor lane also passed. The
[published v2.3.0 release](https://github.com/johnjanney/bogo-select/releases/tag/v2.3.0)
contains `bogo-select-2.3.0.zip` with SHA-256
`3ebf5050487015eac9a7c8215fb3aafa0d47c368faf4fb75f13ef75199ccae56`.
That digest matches the local archive.

Official WordPress, WooCommerce, and GitHub documentation supports the external
platform statements. Inferences are identified as inferences. If evidence is
absent, this report says “Not found in documents.”

## Status of Claude Code's response and the later updates

The newest response section addresses the review of commit `b04de94`, not the
review of commit `3b14480`
([`CODEX-REVIEW-RESPONSE.md:18-40`](CODEX-REVIEW-RESPONSE.md#L18-L40)). A
response to the two Medium and four Low findings in the prior report was **Not
found in documents**.

The later commits were checked directly:

| Update | Status | Verification |
|---|---|---|
| v2.2.0 mobile layout | **Implementation is coherent; direct mobile test not found** | The 600 px rule changes each card to a 64 px thumbnail row and keeps actions in the content column ([`assets/css/bogo-select.css:213-268`](assets/css/bogo-select.css#L213-L268)). CI uses a 1280 px browser viewport. A phone-viewport screenshot, layout assertion, or accessibility check was **Not found in documents**. |
| v2.2.1 dead chooser after classic cart update | **Fixed** | The script now looks up the live slot on each use and delegates events from `document`, which survives a replaced cart form ([`assets/js/bogo-select.js:47-145`](assets/js/bogo-select.js#L47-L145) and [`assets/js/bogo-select.js:563-678`](assets/js/bogo-select.js#L563-L678)). The slot enqueues its own script ([`includes/class-bogo-frontend.php:151-179`](includes/class-bogo-frontend.php#L151-L179)). Unit tests and the classic browser test cover the two fixes ([`tests/FrontendTest.php:135-155`](tests/FrontendTest.php#L135-L155) and [`tests/integration/classic.test.mjs:204-254`](tests/integration/classic.test.mjs#L204-L254)). |
| v2.3.0 Buy variation picker | **Implemented** | The Buy picker now uses `woocommerce_json_search_products_and_variations` ([`includes/class-bogo-admin.php:444-460`](includes/class-bogo-admin.php#L444-L460)). Qualification matches either the parent ID or exact variation ID ([`includes/class-bogo-engine.php:111-129`](includes/class-bogo-engine.php#L111-L129)). Two unit tests cover parent-wide and exact-variation counting ([`tests/QualificationTest.php:88-135`](tests/QualificationTest.php#L88-L135)). A real settings-page selection and save test was **Not found in documents**. |

The v2.2.1 and v2.3.0 changes do not resolve any open finding from the prior
report. The six prior findings are re-verified below.

## Current findings

### M-01 — Schedule validation reports errors but saves invalid values

**Severity:** Medium

**Area:** Quality, security hardening, and purpose

**Status:** Open; reproduced again at `b092d3a`

#### Verified facts

The date normalizer converts malformed non-empty dates to an empty string. An
empty value means that the related schedule side has no limit
([`includes/class-bogo-settings.php:170-195`](includes/class-bogo-settings.php#L170-L195)).
It also converts each dash-separated part with `intval()` before it calls
`checkdate()`. Thus, `2026-08-01junk` becomes the valid date `2026-08-01`.

The admin sanitizer detects an end date that is earlier than the start date and
calls `add_settings_error()`. It then returns both reversed dates unchanged
([`includes/class-bogo-admin.php:146-178`](includes/class-bogo-admin.php#L146-L178)
and [`includes/class-bogo-admin.php:235-237`](includes/class-bogo-admin.php#L235-L237)).

A focused reproduction produced these results:

| Submitted start/end | Returned start/end | Registered error |
|---|---|---|
| `2026-08-20` / `2026-08-10` | unchanged | `bogo_select_backwards_window` |
| `2026-08-01junk` / `not-a-date` | `2026-08-01` / empty | none |

The official [`add_settings_error()` reference](https://developer.wordpress.org/reference/functions/add_settings_error/)
defines a displayed settings message. It does not cancel an option update. The
changelog and open-question record say that the settings screen “refuses” a
reversed window ([`CHANGELOG.md:112-121`](CHANGELOG.md#L112-L121) and
[`OPEN-QUESTIONS.md:359-390`](OPEN-QUESTIONS.md#L359-L390)). That statement is
false for the current code.

`DECISION.md` D-019 separately says that a malformed date becomes an unbounded
side ([`DECISION.md:496-519`](DECISION.md#L496-L519)). This describes the current
behavior, but it does not make the behavior safe. Invalid input and intentionally
blank input have different meanings and must not be merged.

#### Impact and inference

A reversed window is saved and can never run. A malformed date can silently
remove a boundary, so a promotion can start early or run longer than the store
intended. The HTML date field reduces normal typing errors. It does not protect
against a crafted administrator request, an integration that writes the option,
or damaged stored data. This is a business-rule failure. It is not an
unauthenticated privilege escalation.

#### Recommendation

- Require the complete `YYYY-MM-DD` grammar before numeric conversion.
- Keep blank input different from invalid non-empty input.
- Preserve the last valid schedule, or disable the offer, when a submitted date
  is malformed or the end is before the start.
- Keep the visible error, but do not return the invalid values to `options.php`.
- Add a real Settings API test that proves the stored option after malformed and
  reversed submissions.
- Make D-019, the changelog, and the settings behavior state one policy.

### M-02 — Shop Managers can open settings but cannot save them

**Severity:** Medium

**Area:** Quality and purpose

**Status:** Open; code path re-verified

#### Verified facts

The menu and page renderer require `manage_woocommerce`
([`includes/class-bogo-admin.php:38-46`](includes/class-bogo-admin.php#L38-L46)
and [`includes/class-bogo-admin.php:289-291`](includes/class-bogo-admin.php#L289-L291)).
The form posts to `options.php`. The plugin does not change the option-group
capability ([`includes/class-bogo-admin.php:52-61`](includes/class-bogo-admin.php#L52-L61)
and [`includes/class-bogo-admin.php:303-306`](includes/class-bogo-admin.php#L303-L306)).

The official [WordPress Settings API guide](https://developer.wordpress.org/plugins/settings/settings-api/)
says that `options.php` requires `manage_options` by default. WordPress provides
the official [`option_page_capability_{$option_page}` filter](https://developer.wordpress.org/reference/hooks/option_page_capability_option_page/)
to change this requirement. The official [WooCommerce role guide](https://woocommerce.com/document/roles-capabilities/)
says that Shop Managers have `manage_woocommerce` for WooCommerce settings and
configuration. They do not normally have `manage_options`.

#### Inference

The matching `manage_woocommerce` checks for the menu and renderer show that
Shop Manager access is probably intended. With standard roles, that user can
open the page but WordPress rejects the save request. A real role-based test was
**Not found in documents**. This mismatch denies intended access. It does not
grant excess access.

#### Recommendation

Choose one policy and use it for both view and save:

- If Shop Managers must configure offers, filter
  `option_page_capability_bogo_select_group` to `manage_woocommerce`.
- If only administrators must configure offers, require `manage_options` for
  the menu and page renderer.
- Add an integration test for one allowed role and one denied role.

### M-03 — All Products search loads every candidate twice

**Severity:** Medium on a large catalog with broad searches; Low on a small
catalog

**Area:** Performance and availability hardening

**Status:** Open risk; product-load count reproduced, real latency not measured

#### Verified facts

An All Products search can inspect 200 matches by default
([`includes/class-bogo-engine.php:941-965`](includes/class-bogo-engine.php#L941-L965)).
The search path first passes all matches through `filter_choice_ids()` and
`eligible_only()`. `is_choice()` loads each product
([`includes/class-bogo-engine.php:1035-1056`](includes/class-bogo-engine.php#L1035-L1056)
and [`includes/class-bogo-engine.php:1240-1321`](includes/class-bogo-engine.php#L1240-L1321)).
It then calls `sort_by_name()`, which loads every accepted product again
([`includes/class-bogo-engine.php:1196-1219`](includes/class-bogo-engine.php#L1196-L1219)).

A focused reproduction used the repository's own `wc_get_product()` counter,
200 eligible simple products, the All Products scope, the term `Gift`, and a
24-card page. It returned 24 IDs from 200 matches after **400
`wc_get_product()` calls**.

The AJAX endpoint requires a valid public storefront nonce and a qualifying
cart, and the 200-candidate cap bounds one request
([`includes/class-bogo-ajax.php:205-251`](includes/class-bogo-ajax.php#L205-L251)).
The browser sends a search 350 ms after each changed term
([`assets/js/bogo-select.js:639-678`](assets/js/bogo-select.js#L639-L678)).

A real-store database-query count, wall time, CPU profile, or peak-memory result
for this path was **Not found in documents**. The reproduced number measures
product factory calls, not database queries.

#### Impact and inference

On a large catalog, one broad search can create avoidable object creation and
cache or database work before it renders only 24 cards. Because the endpoint is
public for customers, repeated searches also add an avoidable application-level
availability cost. The cap prevents an unbounded request, so this is not rated
as a High severity denial-of-service vulnerability.

#### Recommendation

- Keep a request-local map of product ID to loaded product and reuse it for
  eligibility, sorting, and card rendering.
- Add a product-load ceiling test for All Products search, as already done for
  variable-card rendering.
- Measure wall time, queries, CPU time, and peak memory against a realistic
  catalog with cold and warm object caches.
- Consider a short result cache keyed by search term, scope, offer settings, and
  catalog state if measurement shows that object reuse is not enough.

### L-01 — Documents still disagree with the code and tests

**Severity:** Low runtime risk; Medium maintenance risk

**Area:** Quality and purpose

**Status:** Open

#### Verified facts

- `shipping.test.mjs` runs in both free and discounted modes
  ([`.github/workflows/ci.yml:300-325`](.github/workflows/ci.yml#L300-L325)). The
  README still says that shipping is untested because all fixtures are virtual
  ([`README.md:206-212`](README.md#L206-L212)).
- The brief still says that WooCommerce lifecycle behavior needs a staging pass
  and that shipping remains manual
  ([`BRIEF.md:275-285`](BRIEF.md#L275-L285) and
  [`BRIEF.md:419-430`](BRIEF.md#L419-L430)).
- The unit-test inventory still omits `ScheduleTest.php`
  ([`tests/README.md:21-40`](tests/README.md#L21-L40)).
- The README documents `bogo_select_reward_added` with two accepted arguments,
  but the action now sends `product_id`, `qty`, and `variation_id`
  ([`README.md:184-192`](README.md#L184-L192) and
  [`includes/class-bogo-ajax.php:176-184`](includes/class-bogo-ajax.php#L176-L184)).

#### Recommendation

- Correct the shipping and lifecycle statements.
- Add `ScheduleTest.php` and the v2.2.1 classic-cart regression to the test
  inventory.
- Document the third reward-added hook argument and use `accepted_args = 3` in
  the example.
- Maintain one compatibility and coverage matrix, then link to it from the other
  documents.

### L-02 — Important acceptance and release claims lack direct evidence

**Severity:** Low

**Area:** Quality and verification

**Status:** Open coverage gap

#### Verified facts

- The brief requires no PHP notices or warnings with `WP_DEBUG` enabled
  ([`BRIEF.md:233-268`](BRIEF.md#L233-L268)). The test guide says this is not
  covered ([`tests/README.md:59-71`](tests/README.md#L59-L71)). Passing evidence
  was **Not found in documents**.
- Schedule behavior is tested only with unit stubs. A real WordPress test of a
  non-UTC timezone and a real settings save was **Not found in documents**.
- The v2.2.0 mobile claim has no phone-viewport browser test or visual check.
- The v2.3.0 Buy variation picker has engine tests, but no test opens the admin
  picker, selects a variation, saves it, and proves the stored ID.
- The pinned-sibling browser test calculates `largeIsSelected` but never asserts
  it. Its final check searches all page text for `Large`, which can also occur in
  chooser options ([`tests/integration/classic.test.mjs:182-200`](tests/integration/classic.test.mjs#L182-L200)).

#### Recommendation

- Enable `WP_DEBUG` and `WP_DEBUG_LOG` in integration CI and fail on plugin
  notices, warnings, or deprecations.
- Add one real admin and schedule scenario. Include the selected role, a non-UTC
  timezone, valid boundaries, malformed input, and a reversed window.
- Add a 600 px or narrower browser run for the compact chooser. Check focus,
  labels, overflow, tap targets, and the four supported cart/checkout surfaces.
- Make the sibling assertion select the exact card by `data-bogo-card` and check
  the exact cart-line variation ID or attribute.

### L-03 — CI and release dependencies can be more reproducible

**Severity:** Low

**Area:** Security and supply-chain hardening

**Status:** Open hardening opportunity; no compromise found

#### Verified facts

The workflow uses movable action tags, including `actions/checkout@v5`,
`shivammathur/setup-php@v2`, `actions/setup-node@v5`, and
`actions/upload-artifact@v4`
([`.github/workflows/ci.yml:18-54`](.github/workflows/ci.yml#L18-L54) and
[`.github/workflows/ci.yml:359-370`](.github/workflows/ci.yml#L359-L370)).
The repository allows all actions and does not require SHA pinning.

The workflow has no `permissions` declaration. The repository's current default
workflow token permission is read-only, so the effective token is already
restricted at review time. The workflow still relies on external repository
configuration that can change.

The integration job creates an npm manifest at run time and installs Playwright
without a committed lock file
([`.github/workflows/ci.yml:159-163`](.github/workflows/ci.yml#L159-L163)). The
Docker services use movable image tags
([`tests/integration/docker-compose.yml:12-58`](tests/integration/docker-compose.yml#L12-L58)).

The ZIP verifier compares 14 `.php`, `.js`, and `.css` runtime files, while the
v2.3.0 archive contains 23 files. The build copies every file that is not on an
exclude list ([`bin/build-zip.sh:51-100`](bin/build-zip.sh#L51-L100)), and the
verifier ignores non-runtime extras ([`bin/verify-zip.sh:55-102`](bin/verify-zip.sh#L55-L102)).
No unintended file was found in the current archive.

The official [GitHub secure-use reference](https://docs.github.com/en/actions/reference/security/secure-use)
says that a full commit SHA is the only immutable action reference and recommends
minimum token permissions. GitHub also documents repository enforcement of
[full-length action SHAs](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-github-actions-settings-for-a-repository).

#### Recommendation

- Pin every action to a reviewed full commit SHA and keep its release tag in a
  comment.
- Add `permissions: contents: read` to the workflow so the policy is versioned
  with the code.
- Commit an npm lock file and use `npm ci`.
- Pin integration container images by digest for release checks.
- Build from an allowlist and verify every archive entry, not only three runtime
  extensions.
- Consider signed release tags or GitHub artifact attestations. The current tag
  is annotated but unsigned; the published asset does have a verified matching
  SHA-256 digest.

### L-04 — Static checks do not cover coding standards or type consistency

**Severity:** Low

**Area:** Quality and maintainability

**Status:** Open

#### Verified facts

CI checks syntax and runs PHPUnit. It does not run WordPress Coding Standards,
PHPStan, Psalm, or an equivalent static analyzer
([`.github/workflows/ci.yml:18-54`](.github/workflows/ci.yml#L18-L54)). A static
analysis report was **Not found in documents**.

The variation memo stores `WC_Product[]`, but its property annotation still says
`array<int,int[]>` ([`includes/class-bogo-engine.php:30-35`](includes/class-bogo-engine.php#L30-L35)
and [`includes/class-bogo-engine.php:718-759`](includes/class-bogo-engine.php#L718-L759)).
This does not change runtime behavior. It gives tools and maintainers false type
information.

#### Recommendation

- Change the property annotation to `array<int,WC_Product[]>`.
- Add WordPress Coding Standards and a PHP static analyzer at a level that
  supports PHP 7.4 and the WooCommerce types.
- Add the checks to CI and increase strictness in small steps.

### L-05 — The Buy-list summary can report a false product count

**Severity:** Low

**Area:** Quality and purpose

**Status:** Open; reproduced

#### Verified facts

v2.3.0 defines these rules: a listed parent counts all its variations, a listed
variation counts only itself, and listing both has the same meaning as listing
the parent alone ([`CHANGELOG.md:16-27`](CHANGELOG.md#L16-L27)). The saved list
keeps both IDs. The settings summary reports the raw array count
([`includes/class-bogo-admin.php:569-580`](includes/class-bogo-admin.php#L569-L580)).

A focused reproduction listed parent `10` and its variation `101`. The summary
said: `Buy 1 of 2 selected products`. Under the documented rule, this is one
parent-wide selection plus one redundant entry, not two independent qualifying
products.

#### Impact and recommendation

The engine counts cart units correctly. Only the administrative summary is
misleading. Either report “2 list entries,” or calculate a semantic count that
does not count a child separately when its parent is also present. Add a summary
test for parent only, variation only, and parent plus child.

## Audit results

### Quality assurance audit

The code has clear boundaries for settings, qualification, cart mutation,
classic AJAX, Store API updates, rendering, and admin work. Inputs are normalized
at trust boundaries. Storefront output is escaped. The regression comments make
the important WooCommerce hook and DOM-replacement rules clear.

The v2.2.1 fix is strong. It fixes the lifetime error at the correct boundary:
the document owns delegated handlers, and the current DOM owns the slot. The
test proves that a click reaches the server after WooCommerce updates the cart.
The v2.3.0 rule is also correctly wired from the admin picker to an existing
engine rule.

Quality is reduced by the two open administrative defects, the false Buy-list
summary, document drift, weak release-claim evidence, and the absence of static
analysis. The suite is broad, but the real admin settings path is still the main
blind spot.

### Performance audit

The earlier variable-card fix remains effective. The request memo reuses loaded
variation objects and rendered prices. The regression test holds the product-load
ratio below twice the number of distinct products. The 200-candidate search cap
and 24-card page size prevent unbounded single requests.

M-03 is the main new performance opportunity. The search path loads each
candidate once for eligibility and again for sorting. The curated-list cache
also remains O(N) on a cold build, and one variable-card page still reloads each
parent once during rendering. These remaining costs are bounded or cached, but
they are not measured on a real catalog.

A large-catalog wall-time, database-query, CPU, and peak-memory benchmark was
**Not found in documents**. Do not convert the existing product-load counts into
a latency claim.

### Detailed security audit

No exploitable storefront vulnerability was found in the reviewed code.

- Classic AJAX mutations verify a nonce, normalize IDs, and call shared
  server-side qualification, reward, variation-parent, stock, and quantity
  validation before changing the cart
  ([`includes/class-bogo-ajax.php:32-108`](includes/class-bogo-ajax.php#L32-L108)).
- The Store API callback normalizes its input and uses the same selection
  function as classic AJAX
  ([`includes/class-bogo-blocks.php:228-266`](includes/class-bogo-blocks.php#L228-L266)).
- The server checks that a submitted variation belongs to the submitted parent
  ([`includes/class-bogo-engine.php:458-478`](includes/class-bogo-engine.php#L458-L478)).
- Reward lines are marked on the server, revalidated with the cart, and repriced
  from server-side settings. Browser input does not set the price or earned
  quantity.
- Product IDs are converted to integers. Customer-visible strings and HTML
  attributes are escaped before output.
- The admin settings form uses the WordPress nonce and option allowlist supplied
  by the Settings API. M-01 is a validation-policy defect, not a stored-XSS path.
- `composer audit --locked` found no known advisory in the locked development
  dependencies. The release has no Composer runtime dependency.

M-02 denies access and does not widen access. M-03 is a bounded public-endpoint
availability risk. L-03 contains the main supply-chain hardening work.

### Purpose and compatibility audit

The plugin accomplishes its stated core purpose in the tested scenarios. A
store can configure one Buy-X/Get-Y offer. A customer can select or change one
free or percentage-off reward. WooCommerce keeps the reward as a real cart and
order line, so stock is reduced.

Automated tests cover simple rewards, variable parents, pinned variations,
discounts, sale prices, taxes, coupons, shipping, order metadata, stock
reduction, and the four main cart and checkout surfaces. The current CI evidence
supports these statements:

| Surface | Mode | Result at `b092d3a` |
|---|---|---|
| Cart | WooCommerce Cart block | **Pass** on WooCommerce 9.9.5 and 10.9.4 |
| Checkout | WooCommerce Checkout block | **Pass** on WooCommerce 9.9.5 and 10.9.4 |
| Cart | Classic shortcode | **Pass**, including a post-update chooser click |
| Checkout | Classic shortcode | **Pass**, including preservation of entered form data |

This evidence applies to the repository fixtures and tested versions. It is not
proof for every theme or third-party extension.

The main purpose gaps are administrative. M-01 can give a campaign the wrong
schedule. M-02 can prevent the likely intended operator from saving it. L-05 can
misstate the scope of the new variation-level Buy list. Open decisions for
multiple simultaneous offers, category scoping, and more than one reward product
per qualification remain outside the current product scope. Approval to add
those features was **Not found in documents**.

## Verification results

| Check | Result |
|---|---|
| Worktree runtime/test changes before review | Pass; none |
| `composer validate --strict` | Pass |
| `composer audit --locked` | Pass; no known advisories |
| PHPUnit on PHP 8.1 | Pass; 238 tests, 539 assertions |
| PHPUnit on PHP 8.5 | Pass; 238 tests, 539 assertions; one PHPUnit cache warning came from the Windows-to-WSL UNC stream, not plugin code |
| PHP syntax outside `vendor` | Pass |
| JavaScript syntax for runtime and integration files | Pass |
| Shell syntax for build and verification scripts | Pass |
| `git diff --check` before this report | Pass |
| `bin/verify-zip.sh` | Pass; v2.3.0 ZIP matches 14 runtime files |
| Local v2.3.0 ZIP SHA-256 | `3ebf5050487015eac9a7c8215fb3aafa0d47c368faf4fb75f13ef75199ccae56` |
| Published v2.3.0 asset SHA-256 | Same as local ZIP |
| Git tag | Annotated `v2.3.0` points to `b092d3a`; tag is unsigned |
| Current GitHub Actions run | Pass for all jobs |
| WooCommerce compatibility lanes | Pass on 9.9.5 and 10.9.4 |
| Reversed-date reproduction | Error displayed; reversed dates returned unchanged |
| Malformed-date reproduction | `2026-08-01junk` accepted as `2026-08-01`; invalid end became unbounded |
| All Products search reproduction | 200 matches caused 400 product loads before a 24-ID page returned |
| Buy summary reproduction | Parent plus child displayed as `2 selected products` |

## Recommended action order

1. Fix schedule rejection and add real Settings API regression tests (M-01).
2. Choose and enforce one settings capability; test the role (M-02).
3. Reuse loaded products in All Products search and add a load ceiling (M-03).
4. Enable debug-log verification and add real admin, schedule, and mobile
   scenarios (L-02).
5. Correct the shipping, test inventory, and hook documentation (L-01).
6. Pin CI dependencies, version token permissions, and verify the complete ZIP
   allowlist (L-03).
7. Correct type annotations and add coding-standard and static-analysis checks
   (L-04).
8. Correct the Buy-list summary count (L-05).
9. Run a realistic large-catalog performance benchmark before publishing a
   latency or database-query claim.
