/**
 * A variable product as the reward, against a real WordPress and WooCommerce.
 *
 * Runs after setup-variable.php has added a variable product and made it the
 * offer. The unit suite models variations with a stub; only a real store proves
 * that WooCommerce accepts the parent-plus-variation pair the plugin builds,
 * prices the line from the chosen variation rather than the parent's range, and
 * renders the cart line as that variation.
 *
 * The two assertions that earn this file's runtime:
 *
 *   1. The line is charged from the chosen variation. The parent reports the
 *      low end of a range — 12.00 here, against the Large variation's 18.00 —
 *      so pricing from the wrong object is visible in the totals rather than
 *      silent.
 *   2. The parent alone is refused. It names a product but not a thing, and
 *      awarding it would put an unconfigured variable product in the cart.
 *
 * Env: BASE, PAID_ID, PARENT_ID, SMALL_ID, LARGE_ID, LARGE_PRICE,
 * DISCOUNT_PERCENT, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const PAID_ID = Number(process.env.PAID_ID);
const PARENT_ID = Number(process.env.PARENT_ID);
const SMALL_ID = Number(process.env.SMALL_ID);
const LARGE_ID = Number(process.env.LARGE_ID);
const LARGE_PRICE = Number(process.env.LARGE_PRICE || 18);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);
const WC_VERSION = process.env.WC_VERSION || 'unknown';

const failures = [];
const checks = [];

function check(name, pass, detail = '') {
	checks.push({ name, pass, detail });
	if (!pass) failures.push(`${name}${detail ? ' — ' + detail : ''}`);
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 1800 } });
const page = await context.newPage();

const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));

await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });

const api = await page.evaluate(async ({ paidId, parentId, largeId }) => {
	const nonce = async () => (await fetch('/wp-json/wc/store/v1/cart')).headers.get('Nonce');

	const choose = async (body) => {
		const n = await nonce();
		const res = await fetch('/wp-json/wc/store/v1/cart/extensions', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Nonce: n },
			body: JSON.stringify({ namespace: 'bogo-select', data: body }),
		});
		return { status: res.status, json: await res.json() };
	};

	let n = await nonce();
	await fetch('/wp-json/wc/store/v1/cart/add-item', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: n },
		body: JSON.stringify({ id: paidId, quantity: 1 }),
	});

	// The parent on its own names a product but not a thing.
	const bare = await choose({ action: 'choose', product_id: parentId });

	// The pair the chooser actually sends.
	const paired = await choose({ action: 'choose', product_id: parentId, variation_id: largeId });

	return { bare, paired };
}, { paidId: PAID_ID, parentId: PARENT_ID, largeId: LARGE_ID });

check('Store API: the parent alone is refused', api.bare.status >= 400,
	`status ${api.bare.status}`);

const cart = api.paired.json;
check('Store API: cart built after choosing a variation', Array.isArray(cart.items),
	JSON.stringify(cart).slice(0, 200));

const reward = (cart.items || []).find((i) => i.id === LARGE_ID || i.variation_id === LARGE_ID);
check('Store API: the reward line is the chosen variation', !!reward,
	`items: ${JSON.stringify((cart.items || []).map((i) => i.id))}`);

const minor = Number((cart.totals && cart.totals.currency_minor_unit) ?? 2);
const expected = Math.round(LARGE_PRICE * (1 - DISCOUNT / 100) * 10 ** minor);

check('Store API: priced from the chosen variation, not the parent range',
	!!reward && Number(reward.totals.line_total) === expected,
	reward && `expected ${expected}, got ${reward.totals.line_total} (minor unit ${minor})`);

check('Store API: the sibling variation was not awarded',
	!(cart.items || []).some((i) => i.id === SMALL_ID || i.variation_id === SMALL_ID));

const state = (cart.extensions || {})['bogo-select'];
check('Store API: offer state reports both halves of the reward',
	!!state && state.selected_product_id === PARENT_ID && state.selected_variation_id === LARGE_ID,
	JSON.stringify(state));

// --- The rendered cart ------------------------------------------------------

await page.goto(BASE + '/cart/', { waitUntil: 'networkidle', timeout: 90000 });
await page.waitForTimeout(4000);

const dom = await page.evaluate(() => {
	const text = document.body.innerText;
	return {
		chooser: !!document.querySelector('#bogo-select'),
		heading: /CHOOSER-HEADING-XYZ/.test(text),
		selector: document.querySelectorAll('[data-bogo-variation]').length,
		options: document.querySelectorAll('[data-bogo-variation] option').length,
		selectedCard: !!document.querySelector('.bogo-select__item.is-selected'),
		changeButton: /Change option/i.test(text),
	};
});

check('Cart: chooser rendered', dom.chooser && dom.heading);
check('Cart: the variable card carries one selector', dom.selector === 1, `saw ${dom.selector}`);
check('Cart: the selector lists both variations', dom.options === 2, `saw ${dom.options}`);
check('Cart: the chosen card is marked selected', dom.selectedCard);
check('Cart: a selected variable card can still be changed', dom.changeButton);

await page.screenshot({ path: `integration-${WC_VERSION}-variable.png`, fullPage: true });

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — variable reward\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
