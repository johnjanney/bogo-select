/**
 * The discounted reward, against a real WordPress and WooCommerce.
 *
 * The free path is covered by blocks.test.mjs. This runs after set-discount.php
 * has switched the same seeded store to 50% off, and asserts the two things the
 * unit suite cannot reach: that WooCommerce actually charges the discounted
 * figure through the Store API, and that the discounted wording reaches the
 * rendered cart.
 *
 * It matters because BOGO_Select_Cart::set_reward_price() runs on
 * woocommerce_before_calculate_totals, which real WooCommerce may fire more than
 * once per request. The stubs call it as often as a test chooses to; only a real
 * store decides for itself. A compounding discount would show up here as a line
 * total below the expected half.
 *
 * Env: BASE, PAID_ID, GIFT_ID, GIFT_PRICE, DISCOUNT_PERCENT, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const PAID_ID = Number(process.env.PAID_ID);
const GIFT_ID = Number(process.env.GIFT_ID);
const GIFT_PRICE = Number(process.env.GIFT_PRICE || 10);
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

const api = await page.evaluate(async ({ paidId, giftId }) => {
	const nonce = async () => (await fetch('/wp-json/wc/store/v1/cart')).headers.get('Nonce');

	let n = await nonce();
	await fetch('/wp-json/wc/store/v1/cart/add-item', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: n },
		body: JSON.stringify({ id: paidId, quantity: 1 }),
	});

	n = await nonce();
	const chosen = await (await fetch('/wp-json/wc/store/v1/cart/extensions', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: n },
		body: JSON.stringify({ namespace: 'bogo-select', data: { action: 'choose', product_id: giftId } }),
	})).json();

	return chosen;
}, { paidId: PAID_ID, giftId: GIFT_ID });

check('Store API: cart built', Array.isArray(api.items), JSON.stringify(api).slice(0, 200));

const gift = (api.items || []).find((i) => i.id === GIFT_ID);
check('Store API: reward line present', !!gift);

// Store API amounts are minor units as strings, so the expected figure has to be
// built from the currency the store actually reported rather than assumed.
const minor = Number((api.totals && api.totals.currency_minor_unit) ?? 2);
const expected = Math.round(GIFT_PRICE * (1 - DISCOUNT / 100) * 10 ** minor);

check(`Store API: reward line charges ${DISCOUNT}% off`,
	!!gift && Number(gift.totals.line_total) === expected,
	gift && `expected ${expected}, got ${gift.totals.line_total} (minor unit ${minor})`);

// The failure this guards against is specifically a discount applied twice.
check('Store API: the discount was not compounded',
	!!gift && Number(gift.totals.line_total) >= expected,
	gift && `line total ${gift.totals.line_total} is below the expected ${expected}`);

check('Store API: reward is not priced at zero',
	!!gift && Number(gift.totals.line_total) > 0,
	gift && String(gift.totals.line_total));

const meta = (gift && gift.item_data && gift.item_data[0]) || {};
check('Store API: reward label reads as discounted, not free',
	meta.key === 'Discounted item' && meta.name === 'Discounted item'
	&& /50% off/.test(String(meta.value)) && /50% off/.test(String(meta.display)),
	JSON.stringify(meta));

// --- The rendered cart ------------------------------------------------------

await page.goto(BASE + '/cart/', { waitUntil: 'networkidle', timeout: 90000 });
await page.waitForTimeout(4000);

const dom = await page.evaluate(() => {
	const text = document.body.innerText;
	return {
		chooser: !!document.querySelector('#bogo-select'),
		heading: /CHOOSER-HEADING-XYZ/.test(text),
		discountedLabel: /Discounted item/i.test(text) && /50% off/i.test(text),
		saysFreeGift: /Free gift/i.test(text),
	};
});

check('Cart: chooser UI rendered', dom.chooser && dom.heading);
check('Cart: "Discounted item" and "50% off" visible', dom.discountedLabel);
check('Cart: no longer claims a free gift', dom.saysFreeGift === false);

await page.screenshot({ path: `integration-${WC_VERSION}-discount.png`, fullPage: true });

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — discounted reward\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
