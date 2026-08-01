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
 * CHECKOUT_PATH, VARIABLE_ID, SMALL_ID, LARGE_ID, LARGE_PRICE, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REWARD_PRICE = Number(process.env.REWARD_PRICE || 24);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);
const CART_PATH = process.env.CART_PATH || '/classic-cart/';
const CHECKOUT_PATH = process.env.CHECKOUT_PATH || '/classic-checkout/';
const VARIABLE_ID = Number(process.env.VARIABLE_ID);
const SMALL_ID = Number(process.env.SMALL_ID);
const LARGE_ID = Number(process.env.LARGE_ID);
const LARGE_PRICE = Number(process.env.LARGE_PRICE || 50);
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

// --- A variation, chosen over admin-ajax ------------------------------------
//
// The chooser markup is shared with the blocks, but the transport is not. Until
// now nothing had driven a variation through admin-ajax, so the pair the
// selector builds had only ever reached the server over the Store API
// (`CODEX-REVIEW.md` L-02).

const variableCard = `.bogo-select__item[data-bogo-card="${VARIABLE_ID}"]`;

const hasVariableCard = await page.locator(variableCard).count() > 0;
check('Classic cart: the variable reward is offered too', hasVariableCard);

if (hasVariableCard) {
	await page.selectOption(`${variableCard} [data-bogo-variation]`, String(LARGE_ID));

	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 90000 }),
		page.click(`${variableCard} .bogo-select__choose`),
	]);
	await page.waitForTimeout(4000);

	const chosen = await page.evaluate(() => document.body.innerText);
	const half = (LARGE_PRICE * (1 - DISCOUNT / 100)).toFixed(2);

	check('Classic cart: the chosen variation is named on the line',
		/Large/i.test(chosen), 'no "Large" in the cart');
	check(`Classic cart: the line is priced from that variation (${half})`,
		chosen.includes(half), `looking for ${half}`);
	check('Classic cart: the sibling variation was not added',
		!/Classic Variable Thing - Small/i.test(chosen));
}

// --- Switching between two pinned siblings ----------------------------------
//
// The layout M-01 broke. Both variations are listed individually as well as
// through their parent, so two cards carry the same parent ID. Comparing parents
// marked both selected and left neither able to reach the other; the customer
// could see the sibling and had no control to choose it.

// Addressed by the ID the card was built from, because a selected card shows
// "Selected" and "Remove" and carries no choose button to match on.
const pinned = (id) => `.bogo-select__item[data-bogo-card="${id}"]`;

const pinnedCards = await page.locator(`${pinned(SMALL_ID)}, ${pinned(LARGE_ID)}`).count();
check('Classic cart: both pinned siblings are cards of their own', pinnedCards === 2,
	`saw ${pinnedCards}`);

if (pinnedCards === 2) {
	// Choose the Small one, from its own card.
	await Promise.all([
		page.waitForLoadState('networkidle', { timeout: 90000 }),
		page.click(`${pinned(SMALL_ID)} .bogo-select__choose`),
	]);
	await page.waitForTimeout(4000);

	const afterSmall = await page.evaluate(() => document.querySelectorAll('.bogo-select__item.is-selected').length);
	check('Classic cart: choosing one pinned sibling marks exactly one card',
		afterSmall === 1, `${afterSmall} cards marked selected`);

	const canSwitch = await page.locator(`${pinned(LARGE_ID)} .bogo-select__choose`).count();
	check('Classic cart: the other pinned sibling still offers a control',
		canSwitch === 1, `${canSwitch} controls on the sibling card`);

	if (canSwitch === 1) {
		await Promise.all([
			page.waitForLoadState('networkidle', { timeout: 90000 }),
			page.click(`${pinned(LARGE_ID)} .bogo-select__choose`),
		]);
		await page.waitForTimeout(4000);

		const swapped = await page.evaluate((id) => ({
			selected: document.querySelectorAll('.bogo-select__item.is-selected').length,
			largeIsSelected: !!document.querySelector(
				`.bogo-select__item.is-selected button[data-bogo-remove], .bogo-select__item.is-selected`
			) && !!document.querySelector(`.bogo-select__item.is-selected`),
			text: document.body.innerText,
		}), LARGE_ID);

		check('Classic cart: switching to the sibling still marks exactly one card',
			swapped.selected === 1, `${swapped.selected} cards marked selected`);
		check('Classic cart: the cart now holds the sibling',
			/Large/i.test(swapped.text) && !/Classic Variable Thing - Small/i.test(swapped.text));
	}
}

// --- The chooser after WooCommerce replaces the cart form -------------------
//
// Classic cart AJAX — Update cart, apply a coupon, remove a line — does not
// re-render the cart in place: WooCommerce's own cart script replaces
// `form.woocommerce-cart-form` with the server's fresh copy. The chooser is
// printed inside that form, so every listener bound to it went with it, leaving
// a chooser that looked perfectly normal and answered nothing at all — no
// paging, no choosing, and no error to say why.
//
// The click is what has to be asserted, not the outcome: the defect was that
// the click never reached the script.

await page.goto(BASE + CART_PATH, { waitUntil: 'networkidle', timeout: 90000 });

const qty = page.locator('.woocommerce-cart-form input.qty').first();
const canUpdate = await qty.count() > 0 && await page.locator('.woocommerce-cart-form [name="update_cart"]').count() > 0;

check('Classic cart: the cart offers a quantity to update', canUpdate);

if (canUpdate) {
	await qty.fill('2');
	await page.click('.woocommerce-cart-form [name="update_cart"]');
	// The AJAX response replaces the form the chooser sits in.
	await page.waitForTimeout(5000);

	const survived = await page.evaluate(() => ({
		chooser: !!document.querySelector('#bogo-select'),
		choose: document.querySelectorAll('.bogo-select__choose').length,
	}));

	check('Classic cart: the chooser is still rendered after Update cart', survived.chooser);

	if (survived.choose > 0) {
		const reached = await Promise.all([
			page.waitForRequest(
				(request) => request.url().includes('admin-ajax.php')
					&& (request.postData() || '').includes('bogo_select_choose'),
				{ timeout: 15000 }
			).catch(() => null),
			page.click('.bogo-select__choose'),
		]).then(([request]) => !!request);

		check('Classic cart: the chooser still answers clicks after the form is replaced',
			reached, 'the click never reached the script');

		await page.waitForTimeout(4000);
	} else {
		check('Classic cart: a reward is still offered after Update cart', false,
			'no choose button to click');
	}
}

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

// Changing the reward at the checkout must never reload: a reload would empty a
// part-filled form. Typing first is what makes that assertable rather than
// assumed.
const TYPED = 'Ada-Do-Not-Lose-This';
const nameField = page.locator('#billing_first_name').first();

if (await nameField.count() > 0 && await page.locator(`${variableCard} [data-bogo-variation]`).count() > 0) {
	await nameField.fill(TYPED);
	await page.selectOption(`${variableCard} [data-bogo-variation]`, String(SMALL_ID));
	await page.click(`${variableCard} .bogo-select__choose`);
	await page.waitForTimeout(6000);

	const after = await page.evaluate((id) => ({
		typed: document.querySelector('#billing_first_name')?.value ?? null,
		text: document.body.innerText,
		stillSelected: document.querySelector(
			`.bogo-select__item:has(button[data-product-id="${id}"]) [data-bogo-variation]`
		)?.value ?? null,
	}), VARIABLE_ID);

	check('Classic checkout: the variation was changed over admin-ajax',
		/Small/i.test(after.text), 'no "Small" in the order review');
	check('Classic checkout: changing it did not reload and lose typed data',
		after.typed === TYPED, `billing_first_name is ${JSON.stringify(after.typed)}`);
} else {
	check('Classic checkout: the variable card and billing field were both present',
		false, 'could not reach the selector or the billing field');
}

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
