/**
 * The compact chooser on a phone.
 *
 * v2.2.0 changed each gift card below 600px into a cart-line-shaped row —
 * 64px thumbnail on the left, name and price beside it, button underneath —
 * because a single-column grid of full-width product images pushed the
 * customer's own cart lines several screens down. Nothing checked it. The
 * integration browser runs at 1280px, so every assertion ever made about the
 * chooser was made at a width where the rule does not apply
 * (`CODEX-REVIEW.md` L-02).
 *
 * Geometry rather than screenshots. A screenshot proves a layout changed and
 * says nothing about whether it changed correctly; the boxes say where the
 * thumbnail, the text, and the button actually are. The layout is also driven
 * once, because a card that renders beautifully and cannot be tapped is the
 * failure mode worth catching.
 *
 * Env: BASE, PAID_ID, CART_PATH, BLOCK_CART_PATH, WC_VERSION.
 */

import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const CART_PATH = process.env.CART_PATH || '/classic-cart/';
const BLOCK_CART_PATH = process.env.BLOCK_CART_PATH || '/cart/';
const WC_VERSION = process.env.WC_VERSION || 'unknown';

// A common small phone. Narrow enough to be inside the 600px rule with room to
// spare, so the test is about the layout rather than about the breakpoint edge.
const VIEWPORT = { width: 390, height: 844 };

// The card is a row shaped like a cart line. A full-width product image at this
// width is 390px of image before any text or button, so these bounds tell the
// two layouts apart by a wide margin while leaving room for a theme's own button
// padding. The measured heights are printed either way, so they can be tightened
// from real numbers rather than guessed at again.
//
// A variable card gets more room because it legitimately carries more: a label
// and a <select> for the customer to pick a size. Holding it to the same bound
// as a simple card would be asserting that a control it needs does not exist.
const MAX_CARD_HEIGHT = 170;
const MAX_CARD_HEIGHT_WITH_SELECT = 250;

// WCAG 2.2 Target Size (Minimum) is 24×24 CSS pixels. Held at the standard
// rather than at whatever the theme currently produces.
const MIN_TAP = 24;

const failures = [];
const checks = [];

function check( name, pass, detail = '' ) {
	checks.push({ name, pass, detail });
	if ( ! pass ) failures.push(`${name}${detail ? ' — ' + detail : ''}`);
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: VIEWPORT, isMobile: true, hasTouch: true });
const page = await context.newPage();

const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));

// A qualifying cart, in this browser's own session, built from an empty one.
//
// Emptying first is what makes this test re-runnable. It taps a gift partway
// through, so a second run against the cart the first left behind starts with a
// gift already chosen — and then measures a chooser where the card it expects to
// carry a button is the one showing "Selected" instead. That is how it behaves
// under the mutation harness, which runs it repeatedly, and a test that only
// passes on a store nobody has touched is a test with a hidden precondition.
await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async (paidId) => {
	const nonce = (await fetch('/wp-json/wc/store/v1/cart')).headers.get('Nonce');
	const cart = await (await fetch('/wp-json/wc/store/v1/cart')).json();

	for (const item of cart.items || []) {
		await fetch('/wp-json/wc/store/v1/cart/remove-item', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Nonce: nonce },
			body: JSON.stringify({ key: item.key }),
		});
	}

	await fetch('/wp-json/wc/store/v1/cart/add-item', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Nonce: nonce },
		body: JSON.stringify({ id: paidId, quantity: 1 }),
	});
}, PAID_ID);

/**
 * Measure every gift card on the page.
 *
 * @returns {Promise<object>} Boxes and page-level overflow.
 */
function measure() {
	return page.evaluate(() => {
		const box = (el) => {
			if ( ! el ) return null;
			const r = el.getBoundingClientRect();
			return { x: r.x, y: r.y, w: r.width, h: r.height, right: r.right, bottom: r.bottom };
		};

		const cards = Array.from(document.querySelectorAll('.bogo-select__item')).map((li) => ({
			id: li.getAttribute('data-bogo-card'),
			card: box(li),
			thumb: box(li.querySelector('.bogo-select__thumb')),
			info: box(li.querySelector('.bogo-select__info')),
			actions: box(li.querySelector('.bogo-select__actions')),
			button: box(li.querySelector('.bogo-select__choose, .bogo-select__actions .button')),
			buttonText: (li.querySelector('.bogo-select__choose, .bogo-select__actions .button') || {}).textContent || '',
			// Styled as text rather than as a button, and tapped just the same.
			remove: box(li.querySelector('.bogo-select__remove')),
			label: (() => {
				const sel = li.querySelector('[data-bogo-variation]');
				if ( ! sel ) return null;
				const lab = li.querySelector(`label[for="${sel.id}"]`);
				return { id: sel.id, labelled: !!lab && !!lab.textContent.trim() };
			})(),
		}));

		return {
			cards,
			panel: box(document.querySelector('.bogo-select')),
			viewport: { w: window.innerWidth, h: window.innerHeight },
			docWidth: document.documentElement.scrollWidth,
			bodyWidth: document.body.scrollWidth,
		};
	});
}

/**
 * Assert the compact layout on one surface.
 *
 * @param {string} label Surface name for the check text.
 * @param {string} path  Path to open.
 */
async function inspect( label, path ) {
	await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 90000 });
	await page.waitForTimeout(4000);

	const m = await measure();

	check(`${label}: the chooser rendered at ${VIEWPORT.width}px`,
		m.cards.length > 0, `${m.cards.length} cards`);

	if ( ! m.cards.length ) return m;

	// Overflow is asserted against the chooser and its cards, not the document.
	// A page-level assertion would fail on the theme's layout or WooCommerce's
	// own blocks, which is not this plugin's to fix and would turn the check
	// into one someone switches off. The document figure is printed so a real
	// page-level overflow is still visible to anyone reading the log.
	const overflowing = m.cards.filter((c) => c.card.right > m.viewport.w + 1);

	check(`${label}: no card runs off the side of the screen`,
		overflowing.length === 0 && (!m.panel || m.panel.right <= m.viewport.w + 1),
		overflowing.map((c) => `card ${c.id} ends at ${Math.round(c.card.right)}px`).join('; ')
			|| (m.panel ? `panel ends at ${Math.round(m.panel.right)}px` : ''));

	if ( m.docWidth > m.viewport.w + 1 ) {
		console.log(`  note  ${label}: the page itself scrolls sideways ` +
			`(document ${m.docWidth}px vs viewport ${m.viewport.w}px) — theme or WooCommerce, not the chooser`);
	}

	const complete = m.cards.filter((c) => c.thumb && c.info && c.actions);

	check(`${label}: every card has a thumbnail, an info column, and actions`,
		complete.length === m.cards.length, `${complete.length}/${m.cards.length}`);

	// "thumb info" / "thumb actions": the thumbnail spans both rows on the left,
	// with the text and the button stacked to its right.
	const besideThumb = complete.filter(
		(c) => c.info.x >= c.thumb.right - 1 && c.actions.x >= c.thumb.right - 1
	);
	check(`${label}: name and actions sit beside the thumbnail, not under it`,
		besideThumb.length === complete.length,
		complete.filter((c) => !(c.info.x >= c.thumb.right - 1 && c.actions.x >= c.thumb.right - 1))
			.map((c) => `card ${c.id}: thumb ends ${Math.round(c.thumb.right)}, info starts ${Math.round(c.info.x)}`)
			.join('; '));

	const actionsBelow = complete.filter((c) => c.actions.y >= c.info.y);
	check(`${label}: the button is under the name rather than beside it`,
		actionsBelow.length === complete.length,
		`${actionsBelow.length}/${complete.length}`);

	const cappedThumb = complete.filter((c) => c.thumb.w <= 72);
	check(`${label}: the thumbnail is capped rather than full width`,
		cappedThumb.length === complete.length,
		complete.map((c) => `${Math.round(c.thumb.w)}px`).join(', '));

	const limitFor = (c) => (c.label ? MAX_CARD_HEIGHT_WITH_SELECT : MAX_CARD_HEIGHT);
	const shortCards = complete.filter((c) => c.card.h <= limitFor(c));

	check(`${label}: each card is a row, not a screenful`,
		shortCards.length === complete.length,
		complete.map((c) => `card ${c.id} ${Math.round(c.card.h)}px` +
			(c.card.h > limitFor(c) ? ` (over ${limitFor(c)})` : '')).join(', '));

	// Reported rather than asserted: it depends on how many gifts the fixture
	// offers, and a number that moves with the fixture is not a regression.
	const tallest = Math.max(...complete.map((c) => c.card.h));
	console.log(`  note  ${label}: tallest card ${Math.round(tallest)}px — about ` +
		`${Math.floor(m.viewport.h / tallest)} per screen at ${VIEWPORT.height}px`);

	const tappable = complete.filter((c) => c.button && c.button.h >= MIN_TAP && c.button.w >= MIN_TAP);
	const withButton = complete.filter((c) => c.button);
	check(`${label}: buttons meet the WCAG 2.2 minimum target size`,
		withButton.length > 0 && tappable.length === withButton.length,
		withButton.map((c) => `${Math.round(c.button.w)}×${Math.round(c.button.h)}`).join(', '));

	const withRemove = complete.filter((c) => c.remove);

	if ( withRemove.length ) {
		const reachable = withRemove.filter((c) => c.remove.h >= MIN_TAP && c.remove.w >= MIN_TAP);
		check(`${label}: "Remove gift" meets the minimum target size too`,
			reachable.length === withRemove.length,
			withRemove.map((c) => `${Math.round(c.remove.w)}×${Math.round(c.remove.h)}`).join(', '));
	}

	const named = withButton.filter((c) => c.buttonText.trim().length > 0);
	check(`${label}: every button has an accessible name`,
		named.length === withButton.length, `${named.length}/${withButton.length}`);

	const selects = complete.map((c) => c.label).filter(Boolean);
	if ( selects.length ) {
		check(`${label}: each variation select keeps its label`,
			selects.every((s) => s.labelled), JSON.stringify(selects));
	}

	return m;
}

await inspect('Classic cart', CART_PATH);
await page.screenshot({ path: `integration-${WC_VERSION}-mobile-classic.png`, fullPage: true });

// --- The layout has to be usable, not only correct --------------------------
//
// A card can measure perfectly and still be untappable, with something
// invisible over it. Playwright's actionability checks are the assertion here:
// the click waits for the button to be visible, stable, and hit-testable at the
// point it would be tapped, so a covered button fails rather than passes.

const before = await page.locator('.bogo-select__item .bogo-select__choose').count();

if ( before > 0 ) {
	try {
		await Promise.all([
			page.waitForLoadState('networkidle', { timeout: 90000 }),
			page.locator('.bogo-select__item .bogo-select__choose').first().click({ timeout: 15000 }),
		]);
		await page.waitForTimeout(4000);

		const selected = await page.evaluate(
			() => document.querySelectorAll('.bogo-select__item.is-selected').length
		);

		check('Classic cart: a gift can actually be chosen at phone width',
			selected === 1, `${selected} cards marked selected`);
	} catch (e) {
		check('Classic cart: a gift can actually be chosen at phone width', false, String(e.message).split('\n')[0]);
	}
} else {
	check('Classic cart: a choose button was present to tap', false, 'no choose button rendered');
}

// Measured again now that a gift is selected: the selected card is the only one
// that carries "Remove gift", so the first pass had nothing to measure.
await inspect('Classic cart, gift chosen', CART_PATH);

// The block cart is the same component and the same stylesheet reached through
// a different transport, so it is measured rather than driven.
await inspect('Block cart', BLOCK_CART_PATH);
await page.screenshot({ path: `integration-${WC_VERSION}-mobile-block.png`, fullPage: true });

check('No uncaught JavaScript errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));

await browser.close();

// --- Report -----------------------------------------------------------------

console.log(`\nWooCommerce ${WC_VERSION} — the chooser at ${VIEWPORT.width}×${VIEWPORT.height}\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed on WooCommerce ${WC_VERSION}:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
