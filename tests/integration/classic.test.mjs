/**
 * The classic shortcode cart and checkout.
 *
 * Every browser assertion before this ran against the Cart and Checkout blocks,
 * because that is what WooCommerce provisions on a fresh install. The classic
 * path is different code on both sides: the chooser arrives through
 * `woocommerce_before_cart_table` and `woocommerce_before_checkout_form` rather
 * than the `render_block` filter, and choosing a reward goes over admin-ajax
 * with a nonce from `wp_localize_script` rather than through the Store API.
 * `CODEX-REVIEW.md` M-03 called that gap out.
 *
 * The reward is chosen by clicking the button rather than by calling an
 * endpoint, so the assertions cover the JavaScript too — including the reload
 * that classic mode performs and that block mode deliberately does not.
 *
 * Env: BASE, PAID_ID, REWARD_ID, REWARD_PRICE, DISCOUNT_PERCENT, CART_PATH,
 * CHECKOUT_PATH, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REWARD_PRICE = Number(process.env.REWARD_PRICE || 24);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);
const CART_PATH = process.env.CART_PATH || '/classic-cart/';
const CHECKOUT_PATH = process.env.CHECKOUT_PATH || '/classic-checkout/';
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

// A qualifying cart, built in the browser's own session so the classic pages see
// it. Adding the item is not what is under test here.
await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async (paidId) => {
	const nonce = (await fetch('/wp-json/wc/store/v1/cart')).headers.get('Nonce');
	await fetch('/wp-json/wc/store/v1/cart/add-item', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: nonce },
		body: JSON.stringify({ id: paidId, quantity: 1 }),
	});
}, PAID_ID);

// --- The classic cart -------------------------------------------------------

await page.goto(BASE + CART_PATH, { waitUntil: 'networkidle', timeout: 90000 });

const before = await page.evaluate(() => ({
	shortcode: !!document.querySelector('.woocommerce-cart-form, .shop_table.cart'),
	block: !!document.querySelector('.wp-block-woocommerce-cart'),
	slot: !!document.querySelector('.bogo-select-slot'),
	mode: document.querySelector('[data-bogo-mode]')?.getAttribute('data-bogo-mode') ?? null,
	chooser: !!document.querySelector('#bogo-select'),
	heading: /CHOOSER-HEADING-XYZ/.test(document.body.innerText),
	choose: document.querySelectorAll('.bogo-select__choose').length,
}));

check('Classic cart: the shortcode cart rendered, not a block', before.shortcode && !before.block,
	`shortcode ${before.shortcode}, block ${before.block}`);
check('Classic cart: the chooser slot is present', before.slot);
check('Classic cart: the slot is in classic mode', before.mode === 'classic', `mode ${before.mode}`);
check('Classic cart: the chooser rendered', before.chooser && before.heading);
check('Classic cart: a reward is offered', before.choose > 0, `${before.choose} buttons`);

// --- Choosing over admin-ajax, by clicking ----------------------------------

if (before.choose > 0) {
	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 90000 }),
		page.click('.bogo-select__choose'),
	]);
	// Classic mode reloads after the cart changes; give the reload time to land.
	await page.waitForTimeout(4000);

	const after = await page.evaluate(() => {
		const text = document.body.innerText;
		return {
			text,
			selected: !!document.querySelector('.bogo-select__item.is-selected'),
			// Case-insensitive because innerText reflects CSS text-transform, and
			// themes routinely uppercase a badge. The plugin emits "50% off
			// (BOGO)"; the storefront theme here renders "50% OFF (BOGO)".
			badge: /50% off \(BOGO\)/i.test(text),
			rows: document.querySelectorAll('.shop_table.cart tr.cart_item, .woocommerce-cart-form__cart-item').length,
			lockedQty: document.querySelectorAll('.bogo-select-locked-qty').length,
		};
	});

	const discounted = (REWARD_PRICE * (1 - DISCOUNT / 100)).toFixed(2);

	check('Classic cart: the reward was added over admin-ajax', after.rows >= 2, `${after.rows} rows`);
	check('Classic cart: the chooser shows it as selected', after.selected);
	check('Classic cart: the line carries the discount badge', after.badge);
	check(`Classic cart: the line shows the discounted price (${discounted})`,
		after.text.includes(discounted), `looking for ${discounted}`);
	check('Classic cart: the reward quantity is locked', after.lockedQty > 0);
}

await page.screenshot({ path: `integration-${WC_VERSION}-classic-cart.png`, fullPage: true });

// --- The classic checkout ---------------------------------------------------

await page.goto(BASE + CHECKOUT_PATH, { waitUntil: 'networkidle', timeout: 90000 });
await page.waitForTimeout(3000);

const checkout = await page.evaluate(() => ({
	shortcode: !!document.querySelector('form.checkout, form.woocommerce-checkout'),
	block: !!document.querySelector('.wp-block-woocommerce-checkout'),
	slot: !!document.querySelector('.bogo-select-slot'),
	mode: document.querySelector('[data-bogo-mode]')?.getAttribute('data-bogo-mode') ?? null,
	chooser: !!document.querySelector('#bogo-select'),
	billing: document.querySelectorAll('#billing_first_name, input[name="billing_first_name"]').length,
	badge: /50% off \(BOGO\)/i.test(document.body.innerText),
}));

check('Classic checkout: the shortcode checkout rendered, not a block',
	checkout.shortcode && !checkout.block,
	`shortcode ${checkout.shortcode}, block ${checkout.block}`);
check('Classic checkout: the form rendered', checkout.billing > 0, `${checkout.billing} billing fields`);
check('Classic checkout: the chooser is present', checkout.slot && checkout.chooser);

// The chooser must never reload the checkout — it would empty a part-filled
// form — so the server marks this slot differently from the cart's.
check('Classic checkout: the slot is in checkout mode, not classic',
	checkout.mode === 'checkout', `mode ${checkout.mode}`);
check('Classic checkout: the order review shows the discount badge', checkout.badge);

await page.screenshot({ path: `integration-${WC_VERSION}-classic-checkout.png`, fullPage: true });

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — classic cart and checkout\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
