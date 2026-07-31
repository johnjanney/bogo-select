/**
 * Block cart and block checkout, against a real WordPress and WooCommerce.
 *
 * This exists because the unit suite structurally cannot catch what broke here
 * twice. The stubs model this plugin's callbacks, not WooCommerce's competing
 * ones, so a green suite coexisted with a Checkout block that never rendered
 * (CODEX-REVIEW.md H-01) and with a gift label that never appeared. Both were
 * cross-boundary failures, and only a real store shows them.
 *
 * The two assertions that earn this job's runtime:
 *
 *   1. The chooser slot must never carry `data-block-name`. WooCommerce's
 *      BlockTypesController stamps the first tag of the content it filters, so
 *      injecting ahead of the block at the same priority hands the block's
 *      identity to the chooser and the real root goes unbranded.
 *   2. The block root must leave `is-loading`. That is the difference between
 *      a checkout and a column of grey skeletons.
 *
 * Env: BASE, PAID_ID, GIFT_ID, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const PAID_ID = Number(process.env.PAID_ID);
const GIFT_ID = Number(process.env.GIFT_ID);
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

// --- Build a qualifying cart through the Store API, in the browser's own
// session, exactly as the blocks do. -----------------------------------------

await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });

const api = await page.evaluate(async ({ paidId, giftId }) => {
	const nonce = async () => (await fetch('/wp-json/wc/store/v1/cart')).headers.get('Nonce');

	let n = await nonce();
	const added = await (await fetch('/wp-json/wc/store/v1/cart/add-item', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: n },
		body: JSON.stringify({ id: paidId, quantity: 1 }),
	})).json();

	n = await nonce();
	const chosen = await (await fetch('/wp-json/wc/store/v1/cart/extensions', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: n },
		body: JSON.stringify({ namespace: 'bogo-select', data: { action: 'choose', product_id: giftId } }),
	})).json();

	return { added, chosen };
}, { paidId: PAID_ID, giftId: GIFT_ID });

const cartJson = api.chosen;
check('Store API: cart built', Array.isArray(cartJson.items), JSON.stringify(cartJson).slice(0, 200));

const gift = (cartJson.items || []).find((i) => i.id === GIFT_ID);
const state = (cartJson.extensions || {})['bogo-select'];

check('Store API: offer state present on cart response', !!state, JSON.stringify(state));
check('Store API: cart qualifies', !!state && state.qualifies === true);
check('Store API: chosen gift recorded', !!state && state.selected_product_id === GIFT_ID);
check('Store API: gift line present', !!gift);
check('Store API: gift costs nothing', !!gift && String(gift.totals.line_total) === '0', gift && String(gift.totals.line_total));
check('Store API: gift quantity locked', !!gift && gift.quantity_limits.editable === false
	&& gift.quantity_limits.minimum === 1 && gift.quantity_limits.maximum === 1,
	gift && JSON.stringify(gift.quantity_limits));

const meta = (gift && gift.item_data && gift.item_data[0]) || {};
check('Store API: gift label carries all four members',
	meta.key === 'Free gift' && meta.name === 'Free gift'
	&& meta.value === 'BOGO promotion' && meta.display === 'BOGO promotion',
	JSON.stringify(meta));

// --- The rendered blocks ----------------------------------------------------

const surfaces = [
	{ name: 'Cart', path: '/cart/', rootSel: '.wp-block-woocommerce-cart', blockName: 'woocommerce/cart', checkout: false },
	{ name: 'Checkout', path: '/checkout/', rootSel: '.wp-block-woocommerce-checkout', blockName: 'woocommerce/checkout', checkout: true },
];

for (const s of surfaces) {
	await page.goto(BASE + s.path, { waitUntil: 'networkidle', timeout: 90000 });
	await page.waitForTimeout(4000);

	const dom = await page.evaluate((sel) => {
		const root = document.querySelector(sel);
		const slot = document.querySelector('.bogo-select-slot');
		const text = document.body.innerText;
		return {
			rootFound: !!root,
			rootLoading: root ? root.classList.contains('is-loading') : null,
			rootBlockName: root ? root.getAttribute('data-block-name') : null,
			slotFound: !!slot,
			slotBlockName: slot ? slot.getAttribute('data-block-name') : null,
			chooser: !!document.querySelector('#bogo-select'),
			email: !!document.querySelector('#email, input[type=email]'),
			placeOrder: !!document.querySelector('.wc-block-components-checkout-place-order-button, button[type=submit]'),
			addressFields: document.querySelectorAll('input[id*="address"],input[id*="city"],input[id*="first_name"],input[id*="postcode"]').length,
			lineItems: document.querySelectorAll('.wc-block-components-order-summary-item, .wc-block-cart-items__row').length,
			label: /Free gift/i.test(text) && /BOGO promotion/i.test(text),
			heading: /CHOOSER-HEADING-XYZ/.test(text),
		};
	}, s.rootSel);

	check(`${s.name}: block root rendered`, dom.rootFound);
	check(`${s.name}: chooser slot rendered`, dom.slotFound);

	// H-01. The whole reason this job exists.
	check(`${s.name}: chooser slot does NOT carry data-block-name`,
		dom.slotBlockName === null, `slot had data-block-name="${dom.slotBlockName}"`);
	check(`${s.name}: block root keeps its own data-block-name`,
		dom.rootBlockName === s.blockName, `root had data-block-name="${dom.rootBlockName}"`);

	// The block actually mounted rather than sitting in its loading shell.
	check(`${s.name}: block left is-loading (frontend mounted)`, dom.rootLoading === false);
	check(`${s.name}: both cart lines rendered`, dom.lineItems >= 2, `saw ${dom.lineItems}`);
	check(`${s.name}: chooser UI rendered`, dom.chooser && dom.heading);
	check(`${s.name}: "Free gift: BOGO promotion" visible`, dom.label);

	if (s.checkout) {
		check('Checkout: email field rendered', dom.email);
		check('Checkout: address fields rendered', dom.addressFields >= 3, `saw ${dom.addressFields}`);
		check('Checkout: place order button rendered', dom.placeOrder);
	}

	await page.screenshot({ path: `integration-${WC_VERSION}-${s.name.toLowerCase()}.png`, fullPage: true });
}

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — block integration\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
