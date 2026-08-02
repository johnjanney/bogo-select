# Response to the Codex Repository Reviews

Newest first.

- **Part 0 — sixth review: the schedule that was described rather than refused** (below)
- **Part 1 — fifth review: the pinned-sibling defect and render cost** (further down)
- **Part 2 — fourth review: discounts, variable rewards, and the 2.0.0 release** (further down)
- **Part 3 — third review: the Checkout block collision and the stale package** (further down, unchanged)
- **Part 4 — M-01 re-review: the Blocks label** (further down, unchanged)
- **Part 5 — follow-up review → v1.2.0** (further down, unchanged)
- **Part 6 — first review → v1.1.0** (further down, unchanged)

Parts are renumbered as rounds are added, so the newest is always Part 0. Where
`CODEX-REVIEW.md` refers to "Claude Code's Part 0 response", it means the label
response now numbered **Part 4**.

---

# Part 0 — sixth review: the schedule that was described rather than refused

**Responding to:** `CODEX-REVIEW.md`, review date 2026-08-01, reviewed state
`b092d3a` (tag `v2.3.0`) — M-01, M-02, M-03, L-01 through L-05.
**Response date:** 2026-08-01
**Status:** **All eight findings verified. Six fixed, one fixed in part, one
deferred with reasons.** Nothing in the review was overstated, and two findings
were worse than described once I looked at why they had survived.

## On the response the review could not find

The review is right that no answer to the round before this one exists. That
round reviewed `3b14480` and its report was never committed — `CODEX-REVIEW.md`
is overwritten in place each time, so when this round's report replaced it, the
previous one stopped existing anywhere. Its findings were not dismissed; they
were lost. They are re-verified in this report and answered here, which is the
only reason the record can be closed at all. The lesson is about the file, not
about the findings: a report that lives in one mutable path has no history, and
the response document is the only durable half of the exchange.

## Verdict per finding

| ID | Severity | Verified? | Outcome |
|---|---|---|---|
| M-01 | Medium | **Confirmed** — reproduced both halves | **Fixed** — invalid and reversed schedules are refused, not described; 11 unit tests |
| M-02 | Medium | **Confirmed** — code path, and the WordPress default it collides with | **Fixed** — one capability now governs viewing and saving |
| M-03 | Medium | **Confirmed** — measured at 120 loads for 60 candidates | **Fixed** — request memo; the measurement is now a test |
| L-01 | Low runtime / Medium docs | **Confirmed** — all four points | **Fixed** — README, BRIEF, tests/README, and the hook signature |
| L-02 | Low | **Confirmed** — including the assertion that computes and discards | **Fixed in part** — WP_DEBUG now fails the build; the sibling assertion is exact; mobile viewport still uncovered |
| L-03 | Low | **Confirmed** | **Fixed and closed** — token permissions versioned; actions pinned to SHAs, browser pinned by lockfile, Dependabot watching all of it (see the addendum) |
| L-04 | Low | **Confirmed** — the annotation is wrong | **Fixed and closed** — annotation corrected; PHPStan at level 8 and WPCS both clean with no baseline (see the addendum) |
| L-05 | Low | **Confirmed** — reproduced | **Fixed** — the summary counts selections, not array entries |

## M-01 — a message is not a refusal

**Both halves reproduced before changing anything.** `2026-08-01junk` came back
as `2026-08-01`, and a window running from the 20th to the 10th came back
unchanged with an error beside it. The review's account is exact.

The junk-suffix half is a small parsing bug with a one-line cause:
`array_map( 'intval', explode( '-', $value ) )` converts `01junk` to `1`, because
`intval` stops at the first character it cannot read. Nothing downstream ever saw
a reason to doubt the result. `to_date()` now requires the whole string to match
`^(\d{4})-(\d{1,2})-(\d{1,2})$` before `checkdate()` sees it. Unpadded parts stay
legal, because `ScheduleTest` already pins `2026-8-1` → `2026-08-01` and a
hand-written date in that shape is not the defect.

The larger half is the one worth writing down. `add_settings_error()` draws a
message; WordPress writes the option regardless. Every validation the settings
screen performed was therefore advisory, and two of those messages were phrased
as though they were not — the changelog and Q-005 both said the screen "refuses"
a backwards window. It never did.

The fix draws the line the review asked for, between input that is blank and
input that is wrong:

- **Blank stays blank.** Clearing a field is how a store asks for no bound, and
  that request is honoured silently.
- **An unreadable date keeps the stored one.** A typo is a store asking for a
  bound and missing. Reading it as "no bound" is the one interpretation that
  widens a campaign the store was trying to narrow, which is the wrong way for
  the failure to fall.
- **A reversed window is not saved at all.** Both bounds go back to what was
  stored, because either one could be the mistyped one, and the message names the
  schedule that survived so the screen is not merely saying no.

Two smaller decisions inside that:

**The reversed-window check no longer hides behind `enabled`.** It used to run
only for an enabled offer, so a window that could never run could be parked on a
disabled offer and switched on later, at which point nothing re-checked it.

**A refused schedule does not take the rest of the form with it.** Refusing the
whole submission would throw away unrelated edits made in the same visit. The
schedule is one setting, and it is the only one put back. There is a test for
that specifically, because it is the kind of behaviour that gets "simplified"
later by someone who reads the refusal and assumes it was meant to be total.

`DECISION.md` D-019 is amended rather than rewritten: rolling a date forward is
still refused, which is what that entry got right; equating a typo with an empty
field is what it got wrong. `OPEN-QUESTIONS.md` Q-005 is amended to say plainly
that "refuses" was false when it was written.

**Evidence.** Eleven tests in the new `tests/AdminSettingsTest.php`, each
asserting the returned settings and not only the message. That distinction is the
whole finding: a test that reads the error would have passed against the broken
code.

## M-02 — a page a Shop Manager could open and not save

Confirmed by reading the two capabilities against each other. The menu
(`manage_woocommerce`) and the renderer (`manage_woocommerce`) admit a Shop
Manager. `options.php` requires `manage_options` unless the option group says
otherwise, and this group never did. WooCommerce's own documentation puts
`manage_woocommerce` on the Shop Manager role and `manage_options` outside it, so
the intended operator could fill the form in and be refused on submit.

Fixed by making the two halves agree in the direction the code already pointed:
`option_page_capability_bogo_select_group` now returns `manage_woocommerce`. The
alternative — raising the menu to `manage_options` — would have been a defensible
policy and the wrong one here, since a store's offer configuration is exactly the
sort of thing WooCommerce already trusts a Shop Manager with.

**What the test proves and what it does not.** The unit test asserts the filter
resolves to `manage_woocommerce`. It cannot exercise `options.php` itself, so the
end-to-end claim — that a Shop Manager can now save — rests on WordPress applying
its own documented filter. That limit is recorded in `tests/README.md` rather
than papered over.

## M-03 — every candidate loaded twice

Confirmed and measured on the repository's own counter: **120 product loads for
60 candidates**, the same 2× ratio the review found at 200. The cause is
structural rather than accidental — `is_choice()` loads a product to judge it,
then `sort_by_name()` loads the survivors again for their names, and neither knew
about the other.

Fixed with a per-request memo, `choice_product()`, behind both call sites. The
same 60-candidate search now costs 60 loads.

**Why a memo is safe here, which is the only interesting part.** Memoising
product objects across a request is exactly how stale-state bugs are made. It is
safe here because of what these two paths read: type, parent, scope,
purchasability, and name — none of which change inside one request. Stock is
deliberately not among them. `unavailable_reason()` loads its own product and is
untouched, so a reward added mid-request is still judged against fresh stock.
The memo is cleared by `flush_choice_cache()`, alongside the variation memo it
sits next to, and a test asserts it does not survive that flush.

**The measurement is now a test.** `ChooserSearchCostTest` fails at 120 and
passes at 60; I verified that by reverting the fix and watching it fail, rather
than trusting a green suite to mean anything. It also asserts the memo does not
change the order results come back in, since a cache that quietly reorders a page
is a worse bug than the cost it saves.

**What is still not measured.** No wall-clock, query-count, or peak-memory figure
against a real catalogue. The review is right to say product loads are not
latency, and I have not converted one into the other anywhere.

## L-01 — four documentation claims, all wrong

Confirmed individually:

| Claim | Where | Reality |
|---|---|---|
| Shipping untested, all fixtures virtual | `README.md` | `shipping.test.mjs` has run in both reward modes since v2.1.0 |
| Integration layer needs a staging pass | `BRIEF.md` §7 risk table | CI has installed the zip into a real store since v1.3.0 |
| Shipping still manual before release | `BRIEF.md` §8.6 | Same as above |
| `bogo_select_reward_added` takes two arguments | `README.md` | It has sent three since variations landed |

All four corrected. The hook one mattered most: a documented `accepted_args = 2`
means a third-party callback silently never receives `$variation_id`, which is
the argument that says *which* thing was given away.

`ScheduleTest.php` was missing from the unit inventory; it and the two new files
are listed now.

## L-02 — claims without evidence

**WP_DEBUG is now enforced.** `BRIEF.md` §6 has asked for a clean debug log since
v1.0.0 and nothing had ever checked. The integration job turns on `WP_DEBUG` and
`WP_DEBUG_LOG` before the plugin is installed, and a final step fails the build
on any logged line naming a file of ours. Logged rather than displayed, because a
notice printed into a page breaks the browser tests in a way that says nothing
about what raised it; and scoped to this plugin's own paths, because WordPress
and WooCommerce raise deprecations no change here can fix and a check that fails
on those is a check someone switches off.

This step has not run yet at the time of writing. It is new CI, and its first run
is its first evidence.

**The sibling assertion is now exact.** The review is right that
`largeIsSelected` was computed and never asserted, and that the surviving text
check searched the whole page for "Large" — which appears in the chooser's own
options whichever variation is in the cart, so it passed whether or not the swap
happened. It now asserts the selected card carries the large variation's own
`data-bogo-card`, and reads cart text from the line rows rather than the page,
since the chooser is printed inside the cart form.

**Both remaining gaps were closed after 2.3.4**, and the phone one paid for
itself immediately — see the addendum at the end of this part. The paragraph
below is left as it was written.

**Still uncovered, and recorded as such:** a phone-viewport run for the v2.2.0
compact layout, and a real `options.php` save under a named role. Both are
honest gaps rather than deferred work I intend to forget; `tests/README.md` lists
them.

## L-03 — supply chain

**Done:** the workflow declares `permissions: contents: read`. The review notes
the repository default is already read-only; the point of stating it in the file
is that the file is versioned and the setting is not.

**Deferred at the time, and done in 2.3.5** once the owner asked for Dependabot.
The paragraph below is left as it was written; the addendum at the end of this
part records what actually happened.

**Deferred, deliberately:** pinning every action to a full commit SHA, committing
an npm lock file, and pinning container images by digest. Each is a real
improvement and none is a defect. Pinning by SHA without an update mechanism
trades a supply-chain risk for a staleness risk — pinned actions stop receiving
security fixes, which is how a pinned workflow becomes a liability. Doing it well
means Dependabot or the equivalent, which is a change to how this repository is
maintained rather than a change to its code, and it is the owner's call. Recorded
here rather than done quietly.

## L-04 — the annotation was lying

Confirmed: `@var array<int,int[]>` on a property holding `WC_Product[]`. Corrected
to `array<int,WC_Product[]>`. It never changed behaviour, which is precisely why
it survived — a wrong annotation is invisible until a tool reads it.

**The analyzer was deferred here and added immediately afterwards.** See the
addendum at the end of this part; what follows below was written before that and
is left as it stood.

**The analyzer is deferred.** Adding PHPStan or WPCS means WordPress and
WooCommerce stubs, a baseline, and a first run that produces a large volume of
findings against a codebase that has never had one. That is worth doing and it is
its own piece of work; bolting it on at the end of a review round would produce a
baseline nobody reads. Not done, and not claimed.

## L-05 — counting entries instead of selections

Reproduced: a Buy list of parent `10` and its variation `101` reported "2 selected
products", when the second entry selects nothing the first had not already
selected. Only the sentence was wrong; the engine counts cart units correctly,
which is why this is Low.

Fixed by counting selections rather than array entries — a listed variation whose
parent is also listed is not counted again. The stored list is untouched: it is
what the store typed, and quietly editing it would be a worse answer than
describing it accurately. Three tests: parent plus child, two siblings, two
unrelated products.

## Files changed

| File | Why |
|---|---|
| `includes/class-bogo-settings.php` | Strict date grammar (M-01) |
| `includes/class-bogo-admin.php` | Schedule refusal and last-valid fallback (M-01), option-group capability (M-02), summary count (L-05) |
| `includes/class-bogo-engine.php` | Per-request product memo (M-03), annotation (L-04) |
| `tests/AdminSettingsTest.php` | New — the settings screen had no test at all |
| `tests/ChooserSearchCostTest.php` | New — search load ceiling (M-03) |
| `tests/QualificationTest.php` | Unchanged this round; the v2.3.0 variation tests it gained are what the review verified |
| `tests/stubs/wordpress.php` | `add_settings_error()` recorded so a test can assert both message and result |
| `tests/bootstrap.php` | Loads the admin class |
| `tests/integration/classic.test.mjs` | Exact sibling assertion (L-02) |
| `.github/workflows/ci.yml` | WP_DEBUG enforcement (L-02), token permissions (L-03) |
| `README.md`, `BRIEF.md`, `INSTRUCTIONS.md`, `tests/README.md` | Documentation drift (L-01), schedule behaviour (M-01) |
| `DECISION.md`, `OPEN-QUESTIONS.md` | D-019 and Q-005 amended where they were false (M-01) |
| `CHANGELOG.md` | This round, under Unreleased |

## Checks run

| Check | Result |
|---|---|
| PHPUnit | Pass — 257 tests, 579 assertions (was 238/539) |
| `composer lint` | Pass |
| `node --check` on the changed integration test | Pass |
| Workflow YAML parses | Pass |
| M-03 regression test against the unfixed code | Fails at 120 loads, as intended |
| WP_DEBUG enforcement | **Not yet run** — new CI, first evidence is its first run |

## What I did not do

- No version bump and no release. Every change here is a fix, so this is a PATCH
  when it ships, but nothing has shipped and the changelog says so.
- No static analyzer, no SHA pinning, no npm lock file, no phone-viewport test —
  all recorded above with reasons rather than left implied.
- No large-catalogue benchmark. The review asked for one before any latency claim
  is published; none is published.

## Addendum — the analyzer, added after 2.3.1

L-04 above says the analyzer is deferred. It was added straight after 2.3.1
shipped, so that paragraph is now history rather than a plan.

**PHPStan at level 5, clean, with no baseline.** Configuration is
`phpstan.neon.dist`; `composer analyse` runs it and so does a CI job of its own.
`szepeviktor/phpstan-wordpress` supplies the WordPress stubs and
`php-stubs/woocommerce-stubs` the WooCommerce ones. It judges the code as PHP
7.4 while running on 8.3, since the compatibility floor is what matters and the
stub packages want a modern PHP to install.

**The first run found 116 problems. 36 were real enough to act on.**

| Kind | Count | What happened |
|---|---|---|
| Missing type annotations | 80 | Not defects. Level 6 requires them; see below |
| `esc_attr()` given an int | 7 | Cast at the call site |
| Unknown plugin constants | 6 | The analyser cannot run `define()`; a bootstrap file declares them |
| Guards the stubs call redundant | 12 | Kept. `treatPhpDocTypesAsCertain: false` |
| Price types crossing float and string | 3 | Cast at the call site |
| `get_variation_attributes()` on `WC_Product` | 2 | Narrowed with `@var`, not with a new runtime guard |

Three of those are worth more than a table row.

**The redundant-guard finding was the one to get wrong.** Twelve `is_object()`,
`method_exists()`, and `instanceof` checks were reported as always true, and
deleting them is what the report literally asks for. They are not dead. The stubs
describe the WooCommerce of today: `method_exists( $store, 'search_products' )`
guards a WooCommerce older than the stubs, and a docblock promising an array does
not stop a third-party filter returning null. `treatPhpDocTypesAsCertain: false`
is the setting for exactly this — docblock types stop being treated as
certainties, native types still are. Twelve findings went away and no defensive
code went with them.

**`get_variation_attributes()` on a `WC_Product` is real** — the method lives on
`WC_Product_Variation` — but the two call sites only reach it for variations, and
the obvious fix would have broken the suite: the test stubs model a variation as
a `WC_Product` with a type of `variation`, so an `instanceof WC_Product_Variation`
guard would be false in every test and silently make every "Any" variation look
offerable. It is narrowed with `@var` instead, which documents the invariant,
costs nothing at runtime, and leaves the tests measuring what they measured
before.

**Nothing is suppressed.** No baseline file, no `@phpstan-ignore`. The level
sits where the code actually passes, which is the only way the number means
anything.

**Level 6 followed in 2.3.3**, as its own pass. All 80 annotations were written:
34 methods declaring `void` and 46 arrays saying what they hold. Three are array
shapes rather than `array<string,mixed>`, and those are the ones that earn
something beyond documentation — `get_choice_page()` and the two methods behind
it now declare
`array{ids: int[], page: int, pages: int, total: int}`, which the analyser checks
against what they actually return. A key added, renamed, or dropped there is now
an error rather than a surprise at the far end of the chooser. The remaining 43
are honestly `array<string,mixed>`: a settings row and a WooCommerce cart item
are open-ended, and inventing a shape for either would document a guess.

Nothing else changed. No defect was found at level 6, which is what the earlier
paragraph predicted and worth confirming rather than assuming.

**Level 7 followed immediately**, and unlike level 6 it had something to say.
Seven findings, all one root cause: `wc_get_product()` returns
`WC_Product|false`, and nothing in the codebase said what happened to the
`false`.

Three are functions that deliberately answer for it — `unavailable_reason()`
says "This product is no longer available", `stock_demand()` returns zero,
`succeed()` prints an empty name — while their signatures claimed to require a
`WC_Product`. In each one the guard is the first line of the body. The
documentation was understating the code, so the documentation changed and the
code did not.

The other four trace to `is_offerable_variation()`. Its `true` answer *is* proof
that a product is there, because it opens with an `instanceof`; nothing said so,
and its callers went on to use the value without asking again. That was correct
and it was resting on an invariant no tool could see and no comment recorded — a
later edit to that function could have turned four call sites into fatals with
nothing to catch it. A single `@phpstan-assert-if-true` states the guarantee
where it is established. Deleting that one line brings all four findings back,
which is how it was verified rather than assumed.

That is the difference between the two levels worth recording: level 6 asked for
documentation and found no defect, level 7 found an undocumented invariant four
call sites were relying on.

**Level 8 went further still**, and is the first level to change runtime code.
Eighteen findings. Thirteen were two functions that end the request — `fail()`
sends a JSON error, `error()` throws — both documented as returning `void`, so
every guard that called one looked pointless and everything after it looked
reachable. `@return never` on each closed all thirteen.

The other five come from `wc_get_product()` having two ways to say "no
product", `false` and `null`. Three functions passed both through while
declaring only `WC_Product|false`. Every caller was doing a truthiness test, so
the difference had never meant anything to anyone — which is the argument for
folding it at the boundary rather than widening three signatures and asking
every future caller to think about it. Behaviour is unchanged; I checked each
of the seven call sites rather than assuming, and none compares strictly.

Level 9 remains, and is the first one I would not raise on sight. It makes
`mixed` explicit, and against WordPress code `mixed` is frequently the honest
type — a hook argument really can be anything. `phpstan.neon.dist` says to read
what it reports before deciding whether the level is worth having, and not to
buy it with a baseline.

**WordPress Coding Standards followed after level 8**, closing the other half of
L-04. PHPCS with the `WordPress` standard, run by `composer sniff` and by the
same CI job as the analyser.

422 errors on the first run across 42 files. The shipped plugin accounted for 42
of them and now passes the full standard with nothing excluded for it alone;
the remaining ~380 were all in `tests/`.

**One was a real defect**, and it is the sort only this tool finds. The shop
notice carried two translator comments stacked on top of each other, the first
describing a two-placeholder version of a string that has taken three since the
reward gained a configurable name. Nothing executable was wrong, no test could
have failed, and a translator reading the file would have been told the wrong
thing about the string directly beneath it. Alongside it: the uninstall script
was leaving two variables in the global scope it runs in, and two integration
fixtures were shadowing WordPress's own `$order` and `$mode`.

The rest of the shipped-code findings were conventions rather than faults, and
each was either fixed or configured with its reason recorded in
`.phpcs.xml.dist`. Two are worth naming because both could have been "fixed"
into something worse. `manage_woocommerce` is WooCommerce's capability rather
than core's, so the sniff cannot know it exists — it is declared, which keeps
the sniff catching a mistyped one, rather than switched off. And the Store API
exception messages are deliberately left unescaped: that response is JSON, and
`esc_html()` would send an apostrophe to the customer as `&#039;`, which is the
sniff's advice making the output worse rather than safer.

`tests/` is held to the same standard with four exceptions, each a convention
test code follows and shipped code does not: a stub must carry the WordPress
name it stands in for or it cannot stand in for it, a test's name is its
documentation and 233 generated docblocks would say nothing, the fake catalogue
is one file describing one thing, and the integration fixtures query a
disposable container that exists for one CI job.

No baseline, in either tool. L-04 is closed.

## Addendum — L-03, after Dependabot

The deferral above turned on one question — whether this repository wanted an
update mechanism — and the answer was yes, so the rest followed.

**Actions are pinned to full commit SHAs**, with the resolved version in a
comment beside each: `actions/checkout@fbc6f39… # v5.1.0` and so on. A tag can
be moved to point at different code; a SHA cannot. Dependabot maintains both
halves, so the comment does not drift away from the pin.

**The integration job's browser is pinned by a committed lockfile.** It was
running `npm init -y` and `npm install --no-save playwright@1.56.0` at the top
of every run, which pins a version but not a tree. `npm ci` against
`package-lock.json` pins the tree by integrity hash. `.gitignore` used to
exclude both manifests on the reasoning that the browser is installed per run;
that reasoning is what this replaces.

**Container images stay pinned by tag, not digest** — the one recommendation
not taken. They are a WordPress, a MariaDB, and a WP-CLI that exist for the
length of one CI job, are never published or deployed, and are rebuilt from
scratch every time. What is worth having there is the signal that a newer
WordPress exists, which is the same signal that keeps the compatibility matrix
honest, and Dependabot gives that from the tag. Digest pinning would trade it
for a guarantee about a container nobody keeps.

**`.github/dependabot.yml` is the point of the exercise**, not a side effect.
Weekly updates cover the actions, the Composer dev tools, Playwright, and the
containers. Its header says plainly that if it is ever switched off, the pinning
it maintains should be reconsidered rather than left to rot — because a pinned
workflow with nothing watching it is worse than an unpinned one, and the next
person should not have to work that out.

L-03 is closed on those terms.

## Addendum — L-02's two remaining gaps

**The phone-viewport test found a defect on its first run**, which is the answer
to whether it was worth writing. Every geometry assertion passed — the card is
the cart-line row it was designed to be — and the buttons measured 21 CSS pixels
tall, under the 24 WCAG 2.2 asks for and far under what a thumb wants. That had
shipped in v2.2.0 and been in every release since. Four levels of static
analysis, a coding standard, 257 unit tests and a browser suite had nothing to
say about it, because none of them had ever rendered the chooser below 600px
where its own rule applies. The controls now get a 44px minimum, "Remove gift"
included.

It is geometry rather than screenshots, because a screenshot proves a layout
changed and says nothing about whether it changed correctly. It also taps a
gift: a card can measure perfectly and be untappable with something invisible
over it, and Playwright's actionability check is exactly that assertion.

Two of its bounds are deliberately loose and say why in the file. Overflow is
asserted against the chooser and its cards rather than the document, because a
page-level check would fail on the theme's layout or WooCommerce's own blocks —
neither this plugin's to fix, and a check that fails for someone else's reasons
is one that gets switched off. And a variable card is allowed more height than a
simple one, because it carries a label and a select; holding it to the same
bound would be asserting that a control the customer needs does not exist.

**The settings screen is now driven through `options.php` by a real role.** This
is the regression test M-02 asked for and could not have been written against the
unit suite: `AdminSettingsTest` calls the sanitize callback, and the capability
check happens before WordPress ever reaches it. A Shop Manager signs in, opens
the page, and saves — which before the fix answered "Sorry, you are not allowed
to manage these items." An Editor is refused the page.

The schedule refusals are re-checked there too, and deliberately by reading the
form back rather than the message. The form is repopulated from the stored
option, so what it shows is what was saved, and a screen that displays an error
and stores the value anyway is precisely what M-01 turned out to be. The site
runs on a non-UTC clock for it, with the dates computed by the store's own
`current_time()`, so D-019's "whole days in the site's timezone" is exercised
rather than asserted against a stub.

## Addendum — M-03's benchmark, and what it measured

Run on a 4-core GitHub runner, WooCommerce 10.9.4 on PHP 8.2, 2,000 published
simple products with 500 of them on the Get list, **no persistent object cache**
— which is the case worth measuring, because it is what a store without Redis or
Memcached has, and every web request there starts cold.

| Path | Cold | Warm |
|---|---|---|
| All Products, search "Gift" (200-candidate ceiling) | 0.229s, **612 queries**, 0.13s CPU | 0.002s, 0 queries |
| All Products, search one SKU | 0.019s, 12 queries | 0.001s, 0 queries |
| All Products, browse page 1 | 0.034s, 81 queries | 0.000s, 0 queries |
| All Products, browse page 20 | 0.034s, 81 queries | 0.000s, 0 queries |
| Select Products, 500 curated, cold eligibility build | 0.484s, **1,508 queries**, 2 MB | 0.000s, 0 queries |
| Select Products, search "Gift" | 0.527s, 1,512 queries | 0.002s, 0 queries |

**The warm column is the memo and the transient working.** Zero queries on the
second call, in every path. That is what 2.3.1's request memo bought, and it
holds.

**The cold column is the finding.** A broad All Products search costs about
three queries per candidate — 200 candidates, 612 queries — because the memo
stops the same product being loaded twice within a request and does nothing
about the request being the first one. On a store with a persistent object cache
these are absorbed; on a store without one, every distinct search term pays them
again. The endpoint is public to any qualifying cart and debounced at 350ms per
changed term.

The 1,508-query curated build is less alarming than it looks, because a
transient carries it: a store pays it once per ten minutes, or once after saving
a product, rather than per request. It is the same three-queries-per-product
shape, over 500 rather than 200.

**What this does not say.** Nothing here is a claim about a real store: one
runner, one PHP version, one catalogue shape, all products simple and
published, and a database on the same host as the web server. The figures are
per-call costs on a cold cache, which is the pessimistic end. They are recorded
so the next decision can start from a number.

**What it changes.** M-03's own recommendation ended "consider a short result
cache keyed by search term, scope, offer settings, and catalog state if
measurement shows that object reuse is not enough." The measurement shows
exactly that — object reuse fixed the duplication and left the first load of
each product untouched. The cheaper answer than a result cache is priming: one
batched fetch for the candidate IDs before the eligibility loop, instead of
letting each `wc_get_product()` find its own way to the database. That is a
change to a hot path and is not made here; the benchmark exists so it can be
made against numbers, and re-measured with the same script.

The benchmark runs from its own workflow on `workflow_dispatch` rather than on
push. Seeding a catalogue takes about a minute and the numbers are for reading,
not for gating — a threshold on a shared runner would mostly measure the runner.

M-03's code fix shipped in 2.3.1 and its regression test holds the product-load
ratio. This closes the measurement it asked for before any latency claim is
published.

---

# Part 1 — fifth review: the pinned-sibling defect and render cost

**Responding to:** `CODEX-REVIEW.md`, review date 2026-07-31, reviewed state
`b04de94` — M-01, M-02, L-01, L-02.
**Response date:** 2026-07-31
**Status:** **All four findings confirmed and fixed.** M-01 was a real functional
defect that I reproduced before changing anything: two cards claimed the same
selection and the customer was left with no control to reach either from the
other. M-02 was a real cost, and measuring it produced a sharper number than the
review's estimate. Both low findings are closed.

The review was accurate on every point I checked. Nothing was overstated. M-01 is
the kind of defect a unit suite of 213 tests can miss because every existing test
put one card of each kind on the page.

## Verdict per finding

| ID | Severity | Verified? | Outcome |
|---|---|---|---|
| M-01 | Medium | **Confirmed** — reproduced before any fix | **Fixed** — the owning card is decided once, where the whole list is visible; 4 unit tests and 4 browser assertions |
| M-02 | Medium risk | **Confirmed** — measured, not estimated | **Fixed** — 2,016 product loads reduced to 552 on the review's own 24×20 page; regression test holds the ratio |
| L-01 | Low runtime / Medium docs | **Confirmed** | **Fixed** — `BRIEF.md` amended through 2.0.0; README limitation and unit-test inventory corrected |
| L-02 | Low | **Confirmed** | **Fixed** — the classic lane now drives a variation over admin-ajax, and switches between two pinned siblings |

## M-01 — two pinned siblings, both marked selected

**Reproduced first.** Before touching the code I configured variations 101 and
102 of parent 100 as two entries in the Get list, selected 101, and rendered the
chooser:

```
selected pair: product=100 variation=101
cards rendered       : 2
cards marked selected: 2      <- both
'Choose this instead': 0      <- no way to reach the sibling
```

The review's description was exact, including the second half that matters more
than the label: a selected pinned card renders "Selected" and "Remove gift" and
nothing else, so marking both cards left **no control anywhere on the page** for
switching between them. The server-side swap works; the UI could not ask for it.

**Cause.** `print_choice()` compared the card's *parent* ID against
`selected_product_id()`. For a variation cart line WooCommerce stores the parent
in `product_id`, so two variations of one parent are indistinguishable by that
comparison. Each card knew its own reward pair but could not know whether another
card on the page had a better claim to the same reward.

**Fix.** A card cannot answer this alone, so it no longer tries. `render_choices()`
decides which card owns the selection once, where the whole list is visible, and
each card is simply told whether it is that one:

- a variation listed as its own card wins over the parent card that could also
  offer it, being the more specific of the two;
- everything else is named by the ID its card was built from.

That rule settles all four layouts without special cases: two pinned siblings, a
parent plus one of its own children, a parent alone, and a simple product.

**Tests.** Four unit tests, three of which failed against the old code and now
pass — only-one-selected, the sibling still offering a control, a pinned child
winning over its parent card, and a parent card still owning a selection its
list does not pin. Plus four browser assertions in the classic lane, described
under L-02.

## M-02 — repeated variation enumeration and product loads

**Measured rather than estimated.** The review inferred roughly four passes and
said so explicitly. Building the review's own worst case — 24 variable parents of
20 variations, one full chooser page — gave:

| | Product loads |
|---|---|
| Distinct products involved | 504 |
| Before | **2,016** — exactly 4.0× |
| After | **552** — 1.10× |

The estimate was right about the shape and, at 4.0×, right about the multiple.

**Fix, in two parts.** Neither changes behaviour:

1. `offerable_variations()` memoises per parent for the request, and holds the
   loaded products rather than their IDs. One render asked for a parent's
   children twice — once to decide it could be a card, again to build its
   selector — and returning IDs meant the objects were then loaded a third time.
2. Each option carries the product it was built from and its finished price
   markup, so the `<option>` loop and the card's own quote reuse them instead of
   reloading every variation twice more.

The remaining 48 loads over the minimum are the 24 parents, each loaded once to
judge it a card and once to render it. Closing that too would need a product-level
cache, which is a different kind of change with staleness consequences, and 552
against a 504 floor did not justify it.

**Test.** `VariableRenderCostTest` renders a page and holds the load count under
twice the distinct products. The bound is a ratio rather than an exact number so
it does not break on an incidental lookup, and twice still catches a return to
four times with room to spare. Two further tests cover the memo answering a
second call for free, and the memo being cleared when the choice cache is.

**What is still not measured.** Wall time and query count on a real store with a
large catalogue. The unit-suite figure is a load *count*, which is the thing the
fix changes; it is not a latency result, and I have not claimed one.

## L-01 — the specification described an older product

Confirmed in every particular. `BRIEF.md` is amended through 2.0.0:

- two requirements, R6 and R7, for the discount and for variable rewards;
- both discount settings in the §4.1 table, and a note that the Get list may now
  hold variable parents and individual variations;
- three §3.1 entries covering v1.3.0's two features and v2.0.0's raised
  minimum, including the dynamic-pricing trade-off stated as the deliberate
  choice it was;
- three acceptance criteria, one of them the pinned-sibling layout M-01 broke;
- §8.6 retitled and rewritten, since it claimed the classic matrix, order
  placement, and stock reduction were manual when CI had automated all three.

`README.md`'s matching limitation is corrected, and `tests/README.md` now lists
the six unit-test files its inventory had missed. Historical release notes are
untouched: they correctly describe the state at the time of each release.

## L-02 — no browser coverage of a variable selector on classic

Confirmed. The classic lane used a simple reward, so the variation selector had
only ever been driven over the Store API. The chooser markup is shared with the
blocks, but the transport is not.

The classic fixture now also builds a variable product and lists both of its
variations individually, and `classic.test.mjs` grew from 16 assertions to 27:

- choosing a variation from the selector on the classic cart, over admin-ajax,
  and confirming the line is priced from *that* variation and its sibling was
  not added;
- switching between the two pinned siblings — the M-01 layout — and confirming
  exactly one card is marked at each step and the other keeps its control;
- changing the variation on the classic checkout and confirming that text
  already typed into the billing field survives, which is the no-reload
  guarantee stated in D-008 and never previously asserted.

The review asked for the sibling assertion on one block surface and one classic
surface. It runs on classic, in a real browser, over the transport that had no
coverage. The block surface renders identical server-built markup — proven by the
unit tests — over a transport the existing variable block scenario already
exercises. I judged that adequate rather than adding a second browser lane, and
record the choice here rather than leaving it implied.

## Files changed

| File | Why |
|---|---|
| `includes/class-bogo-frontend.php` | M-01 owning-card rule; M-02 option reuse; cards carry `data-bogo-card` so a selected card can still be addressed |
| `includes/class-bogo-engine.php` | M-02 memo holding loaded variations; `offerable_variation_ids()` derives from it |
| `tests/VariableChooserTest.php` | Four M-01 tests |
| `tests/VariableRenderCostTest.php` | New — M-02 load ceiling and memo behaviour |
| `tests/stubs/*.php` | A product-load counter, so cost is assertable |
| `tests/integration/setup-classic.php`, `classic.test.mjs` | L-02 |
| `BRIEF.md`, `README.md`, `tests/README.md` | L-01 |
| `.github/workflows/ci.yml` | Classic lane carries the variable fixture |

## Checks run

- 220 unit tests, 490 assertions — green. Three of the four M-01 tests fail
  against the previous code, which is what makes them worth having.
- The M-01 reproduction re-run after the fix: one card selected, one control to
  reach the sibling.
- The M-02 measurement, before and after, on the review's own page size.
- `classic.test.mjs` — 27/27 against a real WordPress with WooCommerce 10.9.4,
  in a browser.
- PHP, JavaScript, and YAML syntax checks.

## On the missing response to the previous review

The review correctly noted that the 2026-07-31 review had no entry in this file.
It did not: the responses were written into commit messages instead, which is a
worse place for them because they cannot be read as a set. Part 2 below records
that round retrospectively, from the commits and the code, and is marked as
written after the fact rather than at the time.

---

# Part 2 — fourth review: discounts, variable rewards, and the 2.0.0 release

**Responding to:** `CODEX-REVIEW.md`, review date 2026-07-31, reviewed state
`49dd5e5` — M-01, M-02, M-03, L-01, L-02.
**Response date:** 2026-07-31, **written retrospectively.** The work was done
when the review landed; this entry was written afterwards, once a later review
pointed out that this file had no record of it. Dates and outcomes come from the
commits, not from memory.

**Status:** all five findings addressed. Two shipped as releases.

| ID | Finding | Outcome |
|---|---|---|
| M-01 | Release identity: a new feature under the already-published 1.2.1 | **Fixed** — released as 1.3.0; changelog section dated, comparison links repaired, package descriptions updated, archive built and parity-checked |
| M-02 | `WC requires at least: 7.0` untested; CI floors at 9.9.5 | **Fixed as a breaking change** — minimum raised to 9.9 and released as 2.0.0, per `BRIEF.md` §8.1, recorded as D-018 |
| M-03 | Percentage integration coverage stopped at the cart | **Fixed** — lanes added for orders and their metadata, stock reduction, coupons, classic templates, sale prices, and tax in both display modes |
| L-01 | `percent:100` recorded as `free` | **Fixed** — the order snapshot reads the configured type, so a 100% campaign records `percent:100` while still reading as "Free" |
| L-02 | Store API text and specification still price-specific | **Partly fixed then** — the schema text was made price-neutral; `BRIEF.md` was not amended until the next round, where it is L-01 |

Two things from that round are worth keeping visible.

**The fixture key.** M-03 noted that `setup-store.php` wrote a `repeating` key
the plugin never reads; the setting is `repeat`. It was inert only because the
default already matched, so the fixture was not configuring what its author
intended. Fixed in the same pass.

**The coupon claim.** The 1.3.0 notes said eligible coupons stack on the strength
of where the pricing hook sits, with no test behind it, because the unit stubs
have no coupon support. That is now covered against a real store in both
directions — an eligible coupon compounding, and one excluding the reward leaving
it alone. The 1.3.0 changelog section still carries the original caveat, because
it was true when 1.3.0 shipped.

---

# Part 3 — third review: the Checkout block collision and the stale package

**Responding to:** `CODEX-REVIEW.md`, review date 2026-07-30 — H-01, M-01,
M-02, M-03, L-01, L-02, L-03.
**Response date:** 2026-07-30
**Status:** **Both release blockers confirmed and fixed.** H-01 was real,
reproducible from the WooCommerce source, and is fixed with a regression test
that fails without the fix. M-01 was real and is fixed, with a new mechanical
gate so it cannot recur silently. The three low-severity findings are resolved
or documented. The live block matrix was subsequently run against a real
WordPress + WooCommerce stack on 9.9.5 and 10.9.4, in a browser, from the
published ZIP: **all four block surfaces pass**, and H-01 was proved causally by
toggling the priority in place. See *The live matrix — run, and it passes*.

The review was accurate on every point I could check. Nothing in it was
overstated, and two findings (H-01, M-01) would have shipped a broken release.

## Verdict per finding

| ID | Severity | Verified? | Outcome |
|---|---|---|---|
| H-01 | High | **Confirmed** — mechanism read out of WooCommerce 10.9.4 source | **Fixed** — injection moved to `render_block` priority 20, regression test added |
| M-01 | Medium, blocker | **Confirmed** — packaged class differed from the worktree | **Fixed** — archive rebuilt; `bin/verify-zip.sh` + CI job added so it cannot recur |
| M-02 | Medium | **Confirmed** — the gap is real | **Partially fixed** — the two specific blind spots now have tests; a live WP/WC job is not something I can add here |
| M-03 | Medium | **Confirmed** — browse totals are pre-eligibility | **Accepted and documented** — the review's second option |
| L-01 | Low | **Confirmed** — no `finally` | **Fixed** — guard cleared in `finally`, regression test added |
| L-02 | Low | **Confirmed** — 200-candidate cap | **Accepted and documented** |
| L-03 | Low | **Confirmed** — cold path is O(N) | **Accepted, no change** — as the review recommends |

---

## H-01 — the Checkout block mounted into the BOGO slot

### Verified, and the mechanism is exactly as described

I did not have to take the live reproduction on trust; the collision is legible
in the WooCommerce source the review cites. `BlockTypesController` registers:

```php
add_filter( 'render_block', array( $this, 'add_data_attributes' ), 10, 2 );
```

and `add_data_attributes()` walks to the **first tag of the content it is
handed** and writes the block's identity there:

```php
$processor = new \WP_HTML_Tag_Processor( $content );

if ( false === $processor->next_tag() || $processor->is_tag_closer() ) {
	return $content;
}

$processor->set_attribute( 'data-block-name', $block['blockName'] );
```

`next_tag()` has no idea which markup is "the block" and which was prepended by
somebody else. It takes the first tag. This plugin also filtered `render_block`
at priority 10 and returned `slot_html() . $content`, so when it ran first the
first tag was the BOGO slot — and WooCommerce branded that:

```html
<div data-block-name="woocommerce/checkout" class="bogo-select-slot" …></div>
<div class="wp-block-woocommerce-checkout … is-loading"> … </div>
```

The Checkout frontend mounts against the element carrying
`data-block-name="woocommerce/checkout"`. It found an empty div, mounted into
it, and the real checkout root — now unbranded — was never initialised and sat
in `is-loading` forever. No address, no order summary, no payment, no place
order.

Two details in the review deserve emphasis, because both are worse than a
normal bug:

- **It was decided by plugin load order, not by anything in the code.** Two
  priority-10 filters on the same hook run in registration order. Whether a
  store's checkout worked depended on which plugin's callback was added first,
  which is not a property this repository controls or can test for.
- **It did not need the promotion to be doing anything.** The review reproduced
  it with no gift selected and with a non-qualifying cart rendering an empty
  slot. An empty slot is still a first tag. Enabling the offer was sufficient to
  break checkout.

### The fix — `includes/class-bogo-blocks.php`

One character of behaviour, and a comment saying why it must not be changed
back:

```php
// Priority 20 is load-bearing, not a default. WooCommerce's own
// BlockTypesController::add_data_attributes() is a priority-10
// `render_block` filter that walks to the *first tag* of the content it
// is handed and stamps `data-block-name` on it. …
add_filter( 'render_block', array( $this, 'inject_chooser' ), 20, 2 );
```

Running after WooCommerce has decorated the original root makes the ordering a
property of this plugin rather than an accident of load order: priority 20
always follows priority 10, whoever registered first. WooCommerce stamps the
real checkout root while it is still the first tag; the chooser is prepended
afterwards, and is never a candidate.

I kept the review's suggested approach rather than inventing another. A
block-specific hook would not be better: `render_block_{$name}` runs *earlier*
than `render_block`, not later, so it would lose the same race by construction.

### The test — `tests/BlocksTest.php`

The review's closing line on H-01 is the important one:

> A unit test that calls `inject_chooser()` directly cannot detect this
> cross-plugin filter-order failure.

That is right, and it is why the existing rendering tests were all green. The
new test therefore does not call `inject_chooser()` at all. It registers a
stand-in for WooCommerce's callback at priority 10 — reproducing exactly the
mechanism above, first tag and all — then drives the whole `render_block`
filter chain and inspects the resulting markup:

```php
$output = apply_filters(
	'render_block',
	sprintf( '<div class="%s is-loading"></div>', $root_class ),
	array( 'blockName' => $block_name )
);

$slot = $this->tag_containing( $output, 'bogo-select-slot' );
$root = $this->tag_containing( $output, $root_class );

$this->assertStringNotContainsString( 'data-block-name', $slot, … );
$this->assertStringContainsString( sprintf( 'data-block-name="%s"', $block_name ), $root, … );
```

It runs against both `woocommerce/checkout` and `woocommerce/cart` through a
data provider, and a companion test asserts the chooser still precedes the
block — running late must not cost it its position.

**Confirmed to fail without the fix.** With the priority put back to 10:

```
1) …::test_woocommerce_stamps_the_real_block_root_and_not_the_chooser_slot with data set "checkout"
The chooser slot must never carry a WooCommerce block name; the block frontend would mount against it.
Failed asserting that '<div data-block-name="woocommerce/checkout" class="bogo-select-slot" …>' does not contain "data-block-name".
```

That is the review's H-01 markup, reproduced by the suite. The test earns its
place: it fails for the real reason, not a proxy for it.

This satisfies points 1 and 2 of the review's recommendation. Point 3 — that
the checkout leaves `is-loading` and renders its form — is a browser assertion
and is not something the stub suite can make; see *What this still does not
prove*.

---

## M-01 — the fix was not in the package

### Verified

Confirmed independently before changing anything. Comparing every runtime file
in `dist/bogo-select-1.2.0.zip` against the worktree found exactly one
difference, and it was the file the whole release existed to fix:

```
DIFF  includes/class-bogo-blocks.php   work=1ae030208374  zip=882be2a39afc
SAME  includes/class-bogo-engine.php
SAME  includes/class-bogo-frontend.php
SAME  includes/class-bogo-cart.php
SAME  assets/js/bogo-select.js
SAME  bogo-select.php
```

The archive had been built before the label fix landed. Anyone installing it
would have received the defect the release was named for — while the repository,
the changelog, and a green suite all said otherwise.

### The fix

The archive is rebuilt from the current state, which now also contains H-01 and
L-01. Per your decision, it is rebuilt **as 1.2.0** rather than bumped: v1.2.0
was never tagged (only `v1.0.0` and `v1.1.0` exist) and never published, so the
stale zip was an artifact of a release that never happened. `BRIEF.md` §8.3
allows exactly this — removing a stale archive deliberately and by hand — and
the changelog's `[Unreleased]` section has been folded into `[1.2.0]`.

### The part that matters more than the rebuild

Rebuilding fixes this archive. It does nothing about the next one, and the
underlying problem is that **nothing in the process ever compared the package
with the source** — which is why a green suite and a stale zip coexisted
happily. So the check now exists and runs automatically:

- **`bin/verify-zip.sh`** requires every runtime file (`.php`, `.js`, `.css`)
  in the worktree to appear in the archive with an identical SHA-256, and the
  archive to carry no runtime file the worktree lacks. It names each stale,
  missing, or extra file and exits non-zero.
- **A CI `package` job** builds the zip and verifies it on every push, so a
  divergence fails the build instead of reaching a customer.
- **`BRIEF.md` §8.5** makes it a release gate between building (§8.2) and
  tagging (§8.4), with a note that tags are immutable so a bad package cannot be
  corrected in place.

The script was validated against the *stale* archive before the rebuild, and
independently reported the defect the review found:

```
STALE     includes/class-bogo-blocks.php — archive content differs from the worktree
error: bogo-select-1.2.0.zip does not match the worktree.
```

After the rebuild:

```
bogo-select-1.2.0.zip matches the worktree (14 runtime files verified).
```

---

## M-02 — framework-boundary coverage

**Status: partially addressed, and I want to be exact about which part.**

The review's diagnosis is correct and is worth restating plainly: *127 tests
passed while the Checkout block was unusable.* The stubs model this plugin's
callbacks, not WooCommerce's competing ones, so an entire class of defect —
this plugin interacting badly with WooCommerce — was invisible to the suite by
construction.

**What is now covered.** The two specific blind spots that produced real
defects have tests that fail without their fixes:

- **Competing filters on a shared hook.** The H-01 test drives the real
  `render_block` chain with a stand-in for WooCommerce's `add_data_attributes()`
  registered at its real priority. This is the first test in the suite that
  models *another plugin's* behaviour rather than only this one's.
- **Extensions that throw.** The L-01 test throws from a filter mid-validation
  and asserts the next pass still runs.

Suite: **131 tests, 259 assertions**, all passing, plus PHP lint across the
repository, `node --check` on both scripts, and `bash -n` on both shell scripts.

**What is still not covered, and I am not going to claim otherwise.** Everything
the review asks for in its M-02 recommendation remains outstanding: no
WordPress/WooCommerce integration job, no Store API serialization, no
`BlockTypesController`, no browser, no ZIP-installed smoke test, no
minimum/tested/current WooCommerce matrix. Standing up a real WP+WC+MariaDB
harness is a substantial piece of infrastructure and a decision about what this
project's CI should cost — not something to slip into a review response. It is
recorded as the outstanding item below.

What did get built is the narrow, mechanical half of the review's release
recommendation (point 4): the packaging half, which is deterministic and cheap.
The behavioural half still needs a live environment.

---

## M-03 — All Products browse totals

**Verified.** The unsearched browse path in `page_all_choices()` does exactly
what the review describes: it asks `wc_get_products()` for one catalogue page,
publishes WooCommerce's `total` and `max_num_pages` **as returned**, and only
then filters that page's IDs for eligibility.

```php
$ids   = isset( $results->products ) ? (array) $results->products : (array) $results;
$total = isset( $results->total ) ? (int) $results->total : count( $ids );
$pages = isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;

return array(
	'ids'   => self::filter_choice_ids( $ids, $context ),   // filtered
	'total' => $total,                                      // unfiltered
	…
);
```

So the count can overstate what is selectable, and a page can come back short or
empty while eligible products wait on later pages. Searching does not share the
problem — it filters, then pages, so its total is exact.

**Resolution: the review's second option — accepted and documented.** Making
browse counts exact means counting an eligibility-filtered candidate set, which
is the O(catalogue) pass that paging was introduced to avoid; it would trade a
Medium display inaccuracy for the performance defect of the previous round. That
is a poor trade for a store large enough to notice either.

Documented in three places so it is not folklore:

- **`README.md` → Limitations**, in customer terms: browse counts are catalogue
  counts, a page can look short, search totals are exact, and *Select Products*
  gives exact counts.
- **The `page_all_choices()` docblock**, stating which half reports which kind
  of total and why the inexact one is deliberate.
- **The changelog**, as an accepted limitation rather than a fix.

The review's related note — that `bogo_select_get_products` can append the same
product on every page — is inherent to a filter applied per page, and is why
`bogo_select_choice_ids` was added last round. Both are documented at the
filters themselves.

---

## L-01 — the validation guard was not exception-safe

**Verified.** The guard was raised and lowered around a bare call:

```php
$this->validating = true;
$this->run_validation( $cart, $keys );
$this->validating = false;
```

`run_validation()` removes cart items and changes quantities, so it fires
`woocommerce_cart_item_removed` and friends. Any extension observing those that
throws goes straight past `$this->validating = false`, and the flag stays set for
the rest of the PHP request — every later `validate()` returning early at its
first line. The failure mode is the bad one: validation does not error, it
silently stops, and unearned gifts stay in the cart.

**Fixed** in `includes/class-bogo-cart.php`:

```php
$this->validating = true;

try {
	$this->run_validation( $cart, $keys );
} finally {
	$this->validating = false;
}
```

This matches the exception-safe suspend/resume path, as the review suggested.

**Test:** `test_validation_survives_an_extension_that_throws` registers a filter
that throws mid-pass, asserts the exception escapes (it should — this is not
about swallowing third-party failures), then clears the fault and asserts the
next pass still drops the now-unearned gift. Without `finally` it fails on the
last assertion:

```
Validation must keep working after an extension throws.
Failed asserting that true is false.
```

Two notes for whoever reads that test next: it hooks `bogo_select_qualifies`
rather than `bogo_select_reward_quantity`, because the latter is not reached on
a non-qualifying cart; and it throws `DomainException` rather than
`RuntimeException`, because PHPUnit's own failure exception extends
`RuntimeException` and a `catch` would swallow the `fail()` call — which it did
on the first attempt.

---

## L-02 — the search cap

**Verified and accepted, as the review proposes.** `search_limit()` returns 200
by default, so a search reports the eligible products among the first 200
matches rather than among every match — and says nothing about having stopped.

Documented rather than changed: raising the ceiling without measured catalogue
data trades a bounded cost for an unbounded one. `README.md` now states "the
first 200 matches" in customer terms under *Limitations*, the `search_limit()`
docblock records that the cap limits completeness and not merely cost, and
`bogo_select_search_limit` remains the escape hatch.

Surfacing truncation in the response and UI — the review's optional third
suggestion — is not done. It is a UI change with copy implications, and is
listed as outstanding below rather than smuggled into a fix.

---

## L-03 — curated-list cache cold path

**Verified, no change, exactly as the review recommends:** retain the current
design unless profiling shows a problem. The first request after expiry or
invalidation still loads every configured product, and any product save clears
the whole map. Narrowing invalidation to configured products, or versioning a
per-product eligibility cache, is the documented next step if a real store
profiles badly.

---

## On the review's note about the depth counter

The review is right that the comment claiming the closing filters "always" run
was stronger than the framework guarantees, and right that it is not a release
blocker. The comment now says what is actually true: the pairs are balanced on
every normal path including short-circuits and errors; an uncaught fatal between
them would leave the depth raised; that costs nothing because the request is
already over and the counter is per-request static state; and the depth is
floored at zero on the way down so a stray closing filter cannot push it
negative and disable the label.

---

## Files changed

| File | Change |
|---|---|
| `includes/class-bogo-blocks.php` | H-01: `render_block` priority 10 → 20, with the reasoning recorded; depth-counter comment corrected |
| `includes/class-bogo-cart.php` | L-01: validation guard cleared in `finally` |
| `includes/class-bogo-engine.php` | M-03 and L-02: docblocks stating the accepted limitations and why |
| `tests/BlocksTest.php` | H-01 regression: filter-order test through the real `render_block` chain, both blocks |
| `tests/CartValidationTest.php` | L-01 regression: throwing extension mid-validation |
| `bin/verify-zip.sh` | **New.** M-01: package-vs-source SHA-256 parity gate |
| `.github/workflows/ci.yml` | M-01/M-02: `package` job — build the zip, verify it; both shell scripts linted |
| `BRIEF.md` | New §8.5 release gate; §8.4 must not tag before it passes |
| `README.md` | M-03 and L-02 written up under *Limitations* |
| `CHANGELOG.md` | `[Unreleased]` folded into `[1.2.0]`; H-01, L-01, tooling, and limitations recorded |
| `dist/bogo-select-1.2.0.zip` | Rebuilt from this state; verified against the worktree |

## Checks run

| Check | Result |
|---|---|
| PHPUnit | **Pass — 131 tests, 259 assertions** |
| H-01 regression fails without the fix | **Confirmed** (priority reverted to 10 → 2 failures) |
| L-01 regression fails without the fix | **Confirmed** (`finally` removed → 1 failure) |
| PHP lint, repository outside `vendor` | **Pass** |
| `node --check`, both scripts | **Pass** |
| `bash -n`, both shell scripts | **Pass** |
| `bin/verify-zip.sh` against the stale archive | **Failed as intended** — named the stale class |
| `bin/verify-zip.sh` against the rebuilt archive | **Pass — 14 runtime files verified** |

## The live matrix — run, and it passes

**Update, same day.** The caveat that stood here ("none of it was verified in a
browser") no longer holds. A disposable WordPress + WooCommerce + MariaDB stack
was stood up and the block matrix run against it, including a real headless
browser. Environment: WordPress 7.0.2, PHP 8.2, MariaDB 10.11, Twenty
Twenty-Five, Chromium via Playwright — matching the reviewer's setup. The
plugin was installed **from `dist/bogo-select-1.2.0.zip`**, not run from the
worktree, so this exercises the published artifact.

| WooCommerce | Surface | Server markup | Browser | Result |
|---|---|---|---|---|
| 9.9.5 | Cart block | slot clean, root stamped | mounted, 2 line items, label visible | **Pass** |
| 9.9.5 | Checkout block | slot clean, root stamped | form rendered, label visible | **Pass** |
| 10.9.4 | Cart block | slot clean, root stamped | mounted, 2 line items, label visible | **Pass** |
| 10.9.4 | Checkout block | slot clean, root stamped | form rendered, label visible | **Pass** |

10.9.4 was the latest WooCommerce on wordpress.org at the time of the run, so
"current release" and "the version the defect was found on" are the same
version here.

### H-01 is fixed, and the proof is causal rather than circumstantial

The fix was not merely observed to coexist with a working checkout. The
installed plugin was toggled between priority 10 and priority 20 in place, on
WooCommerce 10.9.4, changing nothing else:

**At priority 10 — the old code — the defect reproduces exactly as reported:**

```html
<div data-block-name="woocommerce/checkout" class="bogo-select-slot" data-bogo-slot="1" data-bogo-mode="block">
<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading">
```

In the browser: `is-loading` never cleared, no email field, zero address
fields, zero line items, no gift label. The screenshot is the chooser sitting
above a column of empty grey skeleton boxes — the reviewer's "empty loading
shell", reproduced independently.

**At priority 20 — the shipped code — it is correct:**

```html
<div class="bogo-select-slot" data-bogo-slot="1" data-bogo-mode="block">
<div data-block-name="woocommerce/checkout" class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading">
```

In the browser: `is-loading` cleared, email field present, 5 address fields,
Place Order button, both line items, and **"Free gift: BOGO promotion"** on the
gift row in the order summary with the gift priced `$10.00 → $0.00` and the
cart total unchanged at $25.00. No JavaScript errors on any page.

That is the third point of the review's H-01 recommendation — the one the unit
suite explicitly cannot make — now satisfied against a real browser.

### Store API behaviour, live

On both versions, the cart response carried the extension state
(`active/qualifies/reward_quantity/selected_product_id/signature`), gift
selection through `cart/extensions` returned the gift at `line_total: 0`, the
quantity limits came back `editable: false, minimum: 1, maximum: 1`, and the
item data carried all four members:

```json
{"key": "Free gift", "name": "Free gift", "value": "BOGO promotion", "display": "BOGO promotion"}
```

### What is still not proved

Smaller than before, but not empty:

- **The hydration path was not exercised.** In this configuration WooCommerce
  did not preload a cart response into the page markup — the blocks fetched it
  after load — so `woocommerce_hydration_dispatch_request` never fired. The
  fetched path is confirmed live; the preloaded path remains confirmed only by
  source reading and unit tests. The rendered outcome is correct either way
  here, but the hydration half of the label fix has not been observed running.
- **Classic cart and checkout** were not re-run; this was the block matrix. They
  were unaffected by the change and were verified in earlier rounds.
- **Order placement** was not exercised: the test store had no payment gateway
  configured, so checkout was verified up to a rendered, populated Place Order
  form rather than a completed order.
- **One store, one theme.** No multisite, no caching layer, no competing
  third-party plugins beyond WooCommerce itself.

The environment was disposable and has been removed.

## Outstanding, not fixed here

1. ~~**M-02's integration job**~~ — **done.** The block half of the matrix now
   runs in CI (`integration` job): the built zip is installed into a real
   WordPress with WooCommerce at the compatibility floor and at `latest`, a
   store is seeded, and both blocks are driven in headless Chromium. It was
   rehearsed against a live store before landing — 28/28 checks pass, and
   reverting the injection priority to 10 fails it with 10 checks red,
   including the checkout never leaving `is-loading`. The **classic** surfaces,
   stock reduction, and order placement are still manual.
2. **The hydration path**, observed live rather than reasoned about.
3. **L-02's optional truncation signal** in the search response and UI.
4. **L-03**, if profiling ever justifies it.

The declared `WC tested up to: 9.9` header is **still 9.9** in the published
v1.2.0, because the matrix was run after that release was tagged. The results
above are what the review said should gate advancing it, and they pass on
10.9.4 — so the header can now be advanced honestly, which requires a v1.2.1
since v1.2.0 is tagged and immutable.

---

# Part 4 — M-01 re-review: the Blocks gift label

**Responding to:** `CODEX-REVIEW.md` M-01, re-checked against
`CODEX-REVIEW-RESPONSE.md` and still reproducing.
**Response date:** 2026-07-30
**Status:** **Confirmed and fixed.** Codex was right twice: the label did not
render, and the unit test that said otherwise proved nothing about
presentation.

## The cause

`item_data()` was gated on `is_store_api_request()`, which is
`WC()->is_store_api_request()` — a `REQUEST_URI` test for `/wc/store/`:

```php
public function is_store_api_request() {
	return false !== strpos( $_SERVER['REQUEST_URI'], trailingslashit( rest_get_url_prefix() ) . 'wc/store/' );
}
```

**A block cart never makes that request to paint its first frame.** WooCommerce
builds the cart response *inside the page request* and preloads it into the
markup. `Automattic\WooCommerce\Blocks\Domain\Services\Hydration` matches the
`/wc/store/v1/cart` path to its controller and calls the route handler
directly:

```php
$response = call_user_func_array( $handler['callback'], array( $request ) );
```

No HTTP request is made, so `REQUEST_URI` is still `/cart/`. The gate answered
"not the Store API", `item_data()` returned early, `CartItemSchema::get_item_data()`
collected nothing, and the blocks received `item_data: []`. On the JS side
`ProductDetails` returns `null` for an empty list — which is exactly the empty
`.wc-block-components-product-metadata` Codex observed, and why neither string
appeared anywhere in the DOM.

The unit test passed because it called `item_data()` after setting
`$_SERVER['REQUEST_URI']` by hand. It tested the one code path the block cart
does not take.

## The fix — `includes/class-bogo-blocks.php`

Bracket the *response build* rather than sniffing the URL, because that is the
one signal true for both the preloaded and the fetched cart:

| Filter pair | Covers |
|---|---|
| `rest_request_before_callbacks` / `rest_request_after_callbacks` | A dispatched `/wc/store/` route — a real Store API call, and the `rest_preload_api_request()` path older WooCommerce hydrates through |
| `woocommerce_hydration_dispatch_request` / `woocommerce_hydration_request_after_callbacks` | The preloaded cart on WooCommerce Blocks 8.9+ |

Each pair is balanced — WordPress and WooCommerce always fire the closing
filter for an opening one — and the count is nested, so a page that builds more
than one Store API response cannot leave the scope stuck open. `item_data()`
now asks `is_store_api_context()`; `inject_chooser()` still asks
`is_store_api_request()`, because there the question really is "am I serving an
HTTP Store API request".

The entry itself was also wrong in a second, quieter way:

```php
// Before                          // After
'key'     => 'Free gift',          'key'     => 'Free gift',
                                   'name'    => 'Free gift',
'value'   => 'BOGO promotion',     'value'   => 'BOGO promotion',
'display' => '',                   'display' => 'BOGO promotion',
```

The blocks read the label as `key` **or** `name` and the text as `display` **or**
`value`, and which member wins has moved between WooCommerce versions. On
current releases `detail.display || detail.value` makes the empty `display`
harmless, but it is exactly the member Codex flagged, and on any release that
prefers `display` it blanks the row. Spelling out both halves of each pair makes
the row read identically across the supported range.

## Tests — `tests/BlocksTest.php`

The old assertion (`$item_data[0]['key'] === 'Free gift'`) is replaced by
`as_blocks_would_render()`, which resolves the row the way `ProductDetails`
does — `key || name`, then `display || value` — and asserts the customer-visible
string **"Free gift: BOGO promotion"**. Four tests now cover it:

- the preloaded block cart (the regression: `REQUEST_URI` is `/cart/`);
- a dispatched Store API route;
- a non-Store-API REST route, which must not open the label;
- the scope closing again afterwards, so a classic cart on the same page is not
  labelled twice.

All four fail against the previous code and pass against the fix. Suite: **127
tests, 252 assertions, all passing.**

## What this still does not prove

The same limit as C-02 and M-03. The reasoning above is read off WooCommerce's
own source at the versions Codex tested (9.9.5 and 10.9.4) and at trunk, but no
live store was involved: there is still no assertion against a real
`/wc/store/v1/cart` response and no browser assertion that the row is visible.
M-01 should be re-checked in a live block cart before it is called closed.

---

# Part 5 — Follow-up review → v1.2.0

**Responding to:** `CODEX-REVIEW.md` (follow-up review, 2026-07-30, at
`4029f64` / v1.1.0)
**Response date:** 2026-07-30
**Released as:** v1.2.0 — a MINOR bump under `BRIEF.md` §8.1: substantial new
customer-facing functionality, no public hook removed or renamed, no minimum
version raised. `bogo_select_get_products` behaves exactly as it did in 1.1.0.

---

## Summary

Every finding was checked against the code before anything was changed. **All
five are confirmed.** None was a false positive; C-01's diagnosis was right down
to the reason its own test passed.

The review's headline conclusion — that the plugin "does **not** support the
WooCommerce Cart or Checkout Blocks for the complete customer journey" — was also
correct, and it is the change this release is mostly about. Blocks are now
supported rather than declared incompatible, and the chooser renders on the
checkout page as well as the cart, closing the direct-to-checkout gap the review
noted in passing.

| ID | Verdict | Status | Where |
|---|---|---|---|
| C-01 | Confirmed | Fixed | `class-bogo-engine.php`, `tests/stubs/woocommerce.php`, `tests/ChooserSearchTest.php` |
| C-02 | Confirmed | **Open — cannot be closed here** | `tests/README.md` |
| C-03 | Confirmed | Partially fixed | `class-bogo-engine.php`, `class-bogo-select.php` |
| C-04 | Confirmed | Fixed | `class-bogo-engine.php` |
| C-05 | Confirmed | Fixed | `INSTRUCTIONS.md`, `DECISION.md`, `CHANGELOG.md`, `class-bogo-admin.php` |
| Blocks (unnumbered) | Confirmed | Implemented | `class-bogo-blocks.php`, `class-bogo-frontend.php`, `class-bogo-ajax.php`, `bogo-select.js`, `bogo-select.php` |
| Quality: unpaired suspend/resume | Confirmed | Fixed | `class-bogo-ajax.php` |
| Quality: CI uses an ignored lock file | Confirmed | Fixed | `.gitignore`, `.github/workflows/ci.yml` |

Also accepted without argument: the review's correction that the previous
response **overstated F-02**. SKU search did not work in *All Products* scope,
and saying it did was wrong. That is C-01.

Test totals after the change: **123 unit tests, 246 assertions, all passing** on
PHP 8.1; CI runs the same suite on 7.4 through 8.3.

**What this release does not claim.** Nothing below has been exercised against a
running WordPress and WooCommerce. The block integration is unit-tested at its
seams — injection, Store API state, the update callback, item labelling, quantity
limits — and those seams are the ones WooCommerce documents, but "the unit tests
pass" is not "the block cart works", and this repository still cannot tell the
difference. C-02 stands, and now covers more surface than it did before.

---

## Blocks — the chooser did not exist on a block cart or checkout

**Verdict: confirmed. This was the finding worth acting on.**

The review's evidence was accurate in every particular:
`cart_checkout_blocks` was declared `false`; the chooser was registered only on
`woocommerce_before_cart_table`; the script exited immediately because
`#bogo-select` was never in the DOM; and line presentation used classic cart
filters that a block never calls. A store running the Cart block was told on its
shop pages that a gift was waiting and then had nowhere to pick it.

The same paragraph identified a second gap that applies to classic stores too:
a customer who goes from a product page straight to checkout never passes the
cart, and the checkout deliberately rendered no chooser. For that customer the
promotion did not work in either mode.

### What was built

Four seams, all documented WooCommerce extension points, and no build step —
the earlier assumption that block support needs React and `@wordpress/scripts`
(`OPEN-QUESTIONS.md` Q-001) turned out to be wrong.

**1. The chooser is injected ahead of the blocks.** `render_block` prepends the
chooser to `woocommerce/cart` and `woocommerce/checkout`, which is where the
classic templates put it. A guard keeps it to one per page.

**2. Offer state travels on the Store API cart response.**
`woocommerce_store_api_register_endpoint_data` adds a `bogo-select` extension to
the cart endpoint carrying whether the offer is active, whether the cart
qualifies, for how many units, which gift is chosen, and a signature that changes
whenever the chooser would render differently. The script watches that signature
rather than guessing from the cart contents.

**3. Gift changes go through the Store API.**
`woocommerce_store_api_register_update_callback` registers a `bogo-select`
callback, reached from the browser through
`wc.blocksCheckout.extensionCartUpdate()`. The change therefore happens inside
the Store API's own cart request, and the blocks re-render from the response they
already trust — no reload, no second fetch, and a part-filled block checkout
survives.

The callback does not reimplement anything. It calls
`BOGO_Select_Ajax::select_gift()`, the same method the classic AJAX endpoint
calls, so the two modes cannot drift apart on qualification, eligibility, stock,
sold-individually, replacement ordering, or duplicate culling. Refusals are
raised as `RouteException` so the blocks show the reason.

**4. Block-side presentation.** The classic `woocommerce_cart_item_name` and
`woocommerce_cart_item_quantity` filters never run in a block, so the gift is
labelled through `woocommerce_get_item_data` and its quantity pinned through
`woocommerce_store_api_product_quantity_editable`, `..._minimum`, and
`..._maximum`. Without those the customer could type a new quantity into a $0.00
line in the block cart — the D-007 abuse vector, reopened by the blocks.

### Self-healing on a block cart

Classic pages reload, so validation always ran against a fresh render. A block
cart does not reload, so the chooser had to learn to keep up. The markup now
lives in a slot (`div.bogo-select-slot`, with `data-bogo-mode`) and the script
subscribes to the `wc/store/cart` data store: when the signature changes it
fetches a freshly rendered chooser from a new `bogo_select_refresh` endpoint and
replaces the slot's contents. Crossing the qualifying threshold makes the chooser
appear; dropping below it makes it go away; removing the gift from the block cart
re-offers it. If that refresh reveals that validation changed the cart — a gift
dropped because its stock ran out, say — the script invalidates the blocks' cart
resolution so they fetch it again.

### The checkout page

`woocommerce_before_checkout_form` renders the chooser on a classic checkout, and
the block injection covers a block checkout. Classic checkout is the one place
that must **not** reload: it would empty a half-completed form. There the script
re-renders the chooser from the response and triggers WooCommerce's
`update_checkout` so the order review catches up. The mode is decided
server-side and written into `data-bogo-mode`, rather than sniffed in the
browser, because a classic cart page on a block theme can have the blocks' data
store present without being a block cart — sniffing would have reloaded the wrong
pages and skipped reloading the pages that need it.

### Degradation

If WooCommerce Blocks does not expose `extensionCartUpdate`, or the Store API
route is blocked, the script falls back to the AJAX endpoints and a reload, which
is what 1.1.0 did everywhere. The promotion keeps working; it just gets less
graceful.

### What is still unverified

Everything at runtime: that the blocks render in the order assumed, that the
update callback is reached, that the quantity limits present as expected, and
that a gift selected on a block cart survives a block checkout into the order
with stock reduced. `BlocksTest` calls the filters directly; it does not run
WooCommerce. See C-02.

---

## C-01 — All Products SKU search was a false positive in the stub suite

**Verdict: confirmed, and the diagnosis is exactly right.**

`page_all_choices()` passed the term as `'s' => $search`. WordPress resolves `s`
against post title, excerpt, and content; it has never looked at
`_sku`. WooCommerce matches SKUs through its own `sku` query argument or through
the product data store's search. So SKU search worked in *Select Products* scope,
where `matches_search()` compares name and SKU in PHP, and silently failed in
*All Products* scope — while the UI placeholder, README, changelog, decision
record, and an acceptance criterion all promised it.

The review's sharpest point is the one about the test: the stub's
`wc_get_products()` matched `name . ' ' . sku` for `s`, so
`test_search_matches_name_or_sku()` asserted the behaviour of the stub. A test
that can only pass is worse than no test, because it is read as cover.

### Fix

Search now goes through `WC_Data_Store::load( 'product' )->search_products()` —
the call behind the admin product search — which matches title, excerpt,
description, and SKU in one query, through the data store rather than through an
assumption that products are posts. *Select Products* scope makes the same call
with `include` set to the configured IDs, so the database does the narrowing.

Where that data store cannot answer, a two-query fallback runs `sku` and `s`
queries and merges them; `s` alone is never treated as covering SKUs again.

Because the search is no longer paginated by the catalogue query, it is capped by
a new `bogo_select_search_limit` filter (200 by default) and paged in PHP, which
also means a search's `total` is now counted after the eligibility gate — a
search can no longer promise a gift that cannot be given.

### Fix to the test that hid it

`tests/stubs/woocommerce.php` now follows core semantics: `s` searches name and
description only, `sku` does a partial case-insensitive SKU match, and
`include` constrains the result. The data store's `search_products()` is stubbed
separately, and `BOGO_Test_Env::$data_store` can be switched off to exercise the
fallback. `tests/ChooserSearchTest.php` adds a product whose search term appears
**only** in its SKU; a return to `s`-only search fails it.

The review's request for a real WooCommerce integration test with such a product
is right and is not met — see C-02.

---

## C-02 — Runtime integration and current-version compatibility

**Verdict: confirmed, and it remains open. This release widens it.**

The unit suite has grown (71 → 123 tests, 146 → 246 assertions) and now reaches
gift selection and replacement, the rendered chooser, and the block seams. None
of that changes the review's point: stubs cannot prove that a promotion charges
the right amount and ships the right quantity.

Adding a `wp-env` harness needs a working Docker/WordPress environment, and the
five-part suite the review describes — minimum WooCommerce, current WooCommerce,
classic pages, block pages, and an end-to-end run through order and inventory —
needs a live store to be meaningful. Neither exists in this repository, and
inventing a harness that cannot be run would be theatre. `tests/README.md` lists
what is uncovered, and the list is now longer: it names the AJAX and Store API
transport, the browser half of the block integration, and the fact that
`BlocksTest` calls `render_block` directly rather than rendering a real block.

**`WC tested up to` stays at 9.9.** The review is right that 10.9.4 is current
and that the repository does not establish 10.x compatibility. Raising the header
without testing would be a claim, not a fact. It should be raised as soon as the
suite above runs green on a current release — not before.

**Not added: WordPress Coding Standards and PHPCompatibility in CI.** Both are
worth having, but adding a job that fails on every push until an unrelated
clean-up lands is worse than not having it. The right order is a first pass over
the existing violations, then the gate. Recorded here rather than quietly
dropped.

**Fixed: CI no longer resolves dependencies freely.** `composer.lock` is now
tracked and CI runs `composer install`, so the matrix tests the versions the
suite was written against.

---

## C-03 — Select Products paging was O(N) per request

**Verdict: confirmed. Partially fixed, and the remainder is deliberate.**

`page_selected_choices()` did call `filter_choice_ids()` over the entire
configured list — loading every configured product through `is_get_eligible()` —
before it sliced a page, and a search then loaded matching candidates again
through `matches_search()`. For a curated list of a few dozen that is
unremarkable; for hundreds, on every cart view and every search keystroke, it is
not.

### What changed

**Searching no longer loads the list at all.** It is a single data-store query
constrained to the configured IDs (the C-01 work pays for itself here).

**Eligibility is cached.** Whether a configured gift is published, purchasable,
and simple is product state, not request state, so it is memoised per request and
cached in a transient — 10 minutes by default, filterable through
`bogo_select_eligibility_ttl` — and cleared whenever the settings or any product
are saved, trashed, or deleted.

The public filters are deliberately **not** cached. A callback may vary the list
per customer or per role, and a shared cache would leak one customer's list to
another. They run per request, over the cached eligibility map; an ID a filter
adds is not in that map and is judged on the spot.

### What was not done, and why

The review's first suggestion — paginate IDs before hydration and backfill the
page when candidates prove ineligible — was not taken. Backfilling gives a page
of the right size but no exact total, so the page count and the "1 of N options"
subtitle would become estimates that shift as the customer pages. Caching keeps
both exact and removes the repeated cost, which is the part that actually hurt.
The residual cost is one cold pass over the configured list per TTL or per
product save.

The trade-off is honest and worth naming: a gift that stops being purchasable can
linger in the chooser for up to the TTL if nothing triggers a save. The selection
endpoint still refuses it and says why, so the failure is a wasted click, not a
free product.

No query-budget test was added; the suite has no way to count object loads.

---

## C-04 — Public filter semantics and pre-filter result metadata

**Verdict: confirmed on both halves.**

`bogo_select_get_products` did change meaning in 1.1.0 — from "the whole chooser
list" to "one page of it" — and a callback that appends IDs does now append them
to every page. And `total`/`pages` for *All Products* did come from WooCommerce
before the eligibility gate and before the filter, so a page can hold fewer cards
than the count implies.

### Fix

**A page-aware filter.** `bogo_select_choice_ids( $ids, $context )` is new, where
`$context` carries `scope`, `search`, `page`, and `per_page`. A callback can now
act on the first page only, leave searches alone, or size its additions to the
page it is actually looking at.

`bogo_select_get_products` is left exactly as it was. Changing it again — a
second contract change in two releases — would be worse for anyone who adapted to
1.1.0 than leaving it alone and documenting it. It is not deprecated; it is the
simple filter, and the new one is the informed one. Both are documented in
`README.md`, `INSTRUCTIONS.md` §10, and `DECISION.md` D-011.

**Post-filter totals where they can be exact.** *Select Products* totals already
were. Search totals in *All Products* now are, because the search resolves its
matches before paging.

Browsing *All Products* without a search still reports the catalogue total. That
is the number the query is paged by, and making it exact would mean counting the
whole catalogue through the eligibility gate on every page view — which is the
1.0.0 performance bug wearing a different hat. The behaviour is documented rather
than pretended away.

---

## C-05 — Documentation cleanup

**Verdict: confirmed, all three.**

- `INSTRUCTIONS.md` no longer says the Cart block "is not supported in v1.0.0" —
  the blocks are supported from 1.2.0, and the troubleshooting entry now says
  which versions have it and what a Store API failure looks like.
- The 1.0.0 changelog entry is historical text and stays as written; the current
  documents — README, INSTRUCTIONS, DECISION D-008 — now describe block support
  directly, so there is nothing left for that line to be mistaken for.
- The admin Get-products help text now names variations alongside variable,
  grouped, and external products, matching what the code rejects.

The review's phrasing guidance — say "unsupported in all releases through X"
rather than naming one version — is right in general. It happens not to apply
here, because the answer changed from "unsupported" to "supported".

---

## Quality concerns raised outside the numbered findings

**Suspension was manually paired.** Confirmed and fixed. The `suspend()` /
`resume()` pair around a gift swap is now closed by `try`/`finally`, so an
exception thrown by a third-party `woocommerce_add_to_cart_validation` callback
cannot leave the guard down for the rest of the request.
`GiftSelectionTest::test_validation_is_never_left_suspended()` covers it.

**AJAX and front-end rendering had little coverage.** Addressed as far as unit
tests can: `GiftSelectionTest` covers selection, replacement, refusal, quantity
correction, and clearing; `FrontendTest` covers where the chooser prints, what it
prints for selected and unavailable gifts, and what the script is told. The
transport layer — nonces, `wp_send_json_*`, the Store API routes — is still
untested. So is order integration.

**CI used an ignored lock file.** Fixed, as above.

---

## Files changed

| File | Change |
|---|---|
| `includes/class-bogo-blocks.php` | **New.** Chooser injection, Store API state and updates, item labelling, quantity limits. |
| `includes/class-bogo-engine.php` | Data-store search (C-01), search limit, eligibility cache (C-03), page-aware filter and post-filter search totals (C-04), offer-state signature. |
| `includes/class-bogo-frontend.php` | Slot rendering with a mode, chooser on the checkout, reusable enqueue, static chooser markup. |
| `includes/class-bogo-ajax.php` | `select_gift()`/`clear_gift()` shared with the Store API, `try`/`finally` around suspension, `bogo_select_refresh` endpoint, chooser markup in responses. |
| `includes/class-bogo-select.php` | Blocks wiring, eligibility-cache invalidation hooks. |
| `includes/class-bogo-admin.php` | Help text names variations (C-05). |
| `bogo-select.php` | 1.2.0; `cart_checkout_blocks` declared `true`; blocks class loaded. |
| `assets/js/bogo-select.js` | Rewritten around the slot: three modes, Store API updates, `wc/store/cart` subscription, checkout without reload. |
| `assets/css/bogo-select.css` | Slot styles; no longer assumes a cart table around it. |
| `tests/` | Five new files, stub fidelity fixes, plugin constants; 71 → 123 tests. |
| `.gitignore`, `.github/workflows/ci.yml` | Track `composer.lock`; `composer install` in CI. |
| Documentation | `README.md`, `INSTRUCTIONS.md`, `DECISION.md` (D-008 rewritten, D-011 amended, D-014 and D-015 added), `CHANGELOG.md`, `BRIEF.md` §3.1, `OPEN-QUESTIONS.md` Q-001 resolved, `tests/README.md`. |

---

## Release gate, as it stands

The review listed six things to do before calling the plugin production-ready.
Where each stands:

1. **Fix C-01 and add a real SKU-search regression test** — the fix is in; the
   regression test is a unit test, not the WooCommerce integration test asked
   for.
2. **Run the classic end-to-end scenarios** — not done here; needs a store.
3. **Add the integration harness and test a current WooCommerce** — not done;
   `WC tested up to` deliberately left at 9.9.
4. **Optimise or bound large Select Products lists** — done as far as caching and
   database-side search take it (C-03).
5. **Documentation and public-filter contract** — done.
6. **Decide whether blocks are required** — decided: yes, and implemented.

So the honest position is unchanged in shape from the last review, and moved in
substance. What was "good release-candidate quality for classic mode, but not
fully production-verified" is now the same sentence with the blocks included: the
functionality the review found missing exists and is unit-tested at its seams,
and the runtime verification it asked for is still owed.

---

# Part 6 — First review → v1.1.0

**Responding to:** the original `CODEX-REVIEW.md` (reviewed 2026-07-30 at `8e1b7fe`)
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
