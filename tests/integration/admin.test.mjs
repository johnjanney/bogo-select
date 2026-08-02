/**
 * The settings screen, saved through options.php by a real role.
 *
 * `AdminSettingsTest` calls the same sanitize callback WordPress calls and
 * asserts what it returns, which is where the schedule rules live. What it
 * structurally cannot reach is everything that happens before that callback is
 * invoked: the nonce, the option allowlist, and the capability check
 * `options.php` performs. That check is the whole of `CODEX-REVIEW.md` M-02 —
 * the menu and the renderer ask for `manage_woocommerce`, `options.php` asks
 * for `manage_options` unless a filter says otherwise, and a Shop Manager could
 * fill this form in and be turned away on submit.
 *
 * Every assertion here reads the form back after the redirect. The form is
 * repopulated from the stored option, so what it shows is what was saved —
 * which is the distinction M-01 turned on, where a screen displayed an error
 * about a schedule and stored that schedule anyway.
 *
 * Env: BASE, MANAGER_USER, EDITOR_USER, ADMIN_PASS, TODAY, YESTERDAY,
 * NEXT_WEEK, TIMEZONE, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const MANAGER_USER = process.env.MANAGER_USER || 'bogo_shop_manager';
const EDITOR_USER = process.env.EDITOR_USER || 'bogo_editor';
const ADMIN_PASS = process.env.ADMIN_PASS || 'bogo-integration-pass';
const TODAY = process.env.TODAY || '';
const YESTERDAY = process.env.YESTERDAY || '';
const NEXT_WEEK = process.env.NEXT_WEEK || '';
const TIMEZONE = process.env.TIMEZONE || 'UTC';
const WC_VERSION = process.env.WC_VERSION || 'unknown';

const SETTINGS = '/wp-admin/admin.php?page=bogo-select';

const failures = [];
const checks = [];

function check( name, pass, detail = '' ) {
	checks.push({ name, pass, detail });
	if ( ! pass ) failures.push(`${name}${detail ? ' — ' + detail : ''}`);
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 1600 } });
const page = await context.newPage();

const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));

/**
 * Sign in, discarding whoever was signed in before.
 *
 * @param {string} user Username.
 */
async function login( user ) {
	await context.clearCookies();
	await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.fill('#user_login', user);
	await page.fill('#user_pass', ADMIN_PASS);
	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 60000 }),
		page.click('#wp-submit'),
	]);
}

/**
 * Read the schedule fields and any settings messages on the current page.
 *
 * @returns {Promise<object>} What the screen shows.
 */
function readScreen() {
	return page.evaluate(() => {
		const val = (name) => {
			const el = document.querySelector(`[name="bogo_select_settings[${name}]"]`);
			return el ? el.value : null;
		};

		return {
			start: val('start_date'),
			end: val('end_date'),
			buyQty: val('buy_qty'),
			notices: Array.from(document.querySelectorAll('.notice, .settings-error'))
				.map((n) => n.textContent.trim().replace(/\s+/g, ' ')),
			summary: (document.querySelector('.bogo-select-summary') || {}).textContent || '',
			hasForm: !!document.querySelector('form[action="options.php"]'),
		};
	});
}

/**
 * Submit the settings form with the given fields overridden.
 *
 * @param {object} fields name => value for the settings inputs.
 * @returns {Promise<object>} The screen after the redirect.
 */
async function save( fields ) {
	await page.goto(BASE + SETTINGS, { waitUntil: 'networkidle', timeout: 60000 });

	for ( const [name, value] of Object.entries(fields) ) {
		// The date inputs are type=date; fill() sets the value without needing
		// the browser's own date picker.
		await page.fill(`[name="bogo_select_settings[${name}]"]`, String(value));
	}

	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 60000 }),
		page.click('#submit'),
	]);

	return readScreen();
}

// --- A Shop Manager can open the page, which was never in doubt -------------

await login(MANAGER_USER);
await page.goto(BASE + SETTINGS, { waitUntil: 'networkidle', timeout: 60000 });

const opened = await readScreen();

check('Shop Manager: the settings page renders',
	opened.hasForm, 'no form posting to options.php');

if ( ! opened.hasForm ) {
	console.error('Cannot continue without the settings form.');
} else {
	// --- ...and can save it, which is the M-02 regression -------------------
	//
	// Before the option-group capability was filtered, options.php answered
	// this submission with "Sorry, you are not allowed to manage these items."
	// and stored nothing.

	const saved = await save({ start_date: TODAY, end_date: NEXT_WEEK, buy_qty: '3' });

	check('Shop Manager: a valid schedule is stored',
		saved.start === TODAY && saved.end === NEXT_WEEK,
		`form shows ${saved.start} → ${saved.end}, expected ${TODAY} → ${NEXT_WEEK}`);

	check('Shop Manager: the rest of the form saved alongside it',
		saved.buyQty === '3', `buy_qty is ${saved.buyQty}`);

	check('Shop Manager: no permission error was raised',
		! saved.notices.some((n) => /not allowed/i.test(n)),
		saved.notices.filter((n) => /not allowed/i.test(n)).join(' | '));

	// --- A date that is not a date keeps the one already stored ------------
	//
	// A `type=date` input will not hold a malformed value, so it goes in the
	// way a crafted request or a damaged option row would: the type is dropped
	// and the string set by hand. The stored start is TODAY from the save
	// above, which is what makes "kept" distinguishable from "cleared" — the
	// two readings M-01 turned on.

	await page.goto(BASE + SETTINGS, { waitUntil: 'networkidle', timeout: 60000 });
	await page.evaluate(() => {
		const el = document.querySelector('[name="bogo_select_settings[start_date]"]');
		el.removeAttribute('type');
		el.value = '2026-08-01junk';
	});
	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 60000 }),
		page.click('#submit'),
	]);

	const afterJunk = await readScreen();

	check('A date with something after it is not read as the date inside it',
		afterJunk.start !== '2026-08-01', `start_date is ${afterJunk.start}`);

	check('The schedule that was already stored survives the typo',
		afterJunk.start === TODAY, `start_date is ${afterJunk.start}, expected ${TODAY}`);

	check('The refusal says so on screen',
		afterJunk.notices.some((n) => /not a date/i.test(n)),
		afterJunk.notices.join(' | '));

	// --- A window that ends before it begins is not stored -----------------

	await save({ start_date: TODAY, end_date: NEXT_WEEK });

	const reversed = await save({ start_date: NEXT_WEEK, end_date: TODAY });

	check('A reversed window is not stored',
		reversed.start === TODAY && reversed.end === NEXT_WEEK,
		`form shows ${reversed.start} → ${reversed.end}, expected the previous ${TODAY} → ${NEXT_WEEK}`);

	check('The reversed window is explained rather than silently dropped',
		reversed.notices.some((n) => /could never run/i.test(n)),
		reversed.notices.join(' | '));

	// --- The schedule is read in the store's timezone, not UTC -------------
	//
	// TODAY and YESTERDAY are computed by the store with current_time(), so in
	// a zone far enough from UTC they differ from the container's own date for
	// part of every day. An offer ending today is still running; one ending
	// yesterday has closed, and the screen says so.

	const endsToday = await save({ start_date: '', end_date: TODAY });

	check(`An offer ending today (${TODAY} in ${TIMEZONE}) is not called expired`,
		! endsToday.notices.some((n) => /ended on/i.test(n)),
		endsToday.notices.join(' | '));

	const endedYesterday = await save({ end_date: YESTERDAY });

	check(`An offer ending yesterday (${YESTERDAY}) is reported as closed`,
		endedYesterday.notices.some((n) => /ended on/i.test(n)),
		endedYesterday.notices.join(' | '));

	check('The stored end date is the site-local one',
		endedYesterday.end === YESTERDAY, `stored ${endedYesterday.end}`);
}

await page.screenshot({ path: `integration-${WC_VERSION}-admin-settings.png`, fullPage: true });

// --- A role without manage_woocommerce is refused ---------------------------

await login(EDITOR_USER);
await page.goto(BASE + SETTINGS, { waitUntil: 'networkidle', timeout: 60000 });

const asEditor = await page.evaluate(() => ({
	text: document.body.innerText,
	hasForm: !!document.querySelector('form[action="options.php"]'),
}));

check('Editor: the settings page is refused',
	! asEditor.hasForm && /not allowed|do not have permission|Sorry/i.test(asEditor.text),
	asEditor.hasForm ? 'the form rendered for a role without manage_woocommerce'
		: asEditor.text.slice(0, 120));

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — the settings screen through options.php (${TIMEZONE})\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
