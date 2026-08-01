/**
 * Coupons applied alongside a discounted reward.
 *
 * The documentation said eligible coupons stack on top of a reward discount, and
 * said so on the strength of where the pricing hook sits rather than any test —
 * the unit stubs have no coupon support at all. `CODEX-REVIEW.md` M-03 asked for
 * that to be settled, and for the wording to admit that a coupon's own rules
 * still apply. This settles both halves against a real store.
 *
 * With a reward of 20.00 × 2 discounted 50% to 20.00:
 *
 *   a 20% coupon that may apply     -> 16.00, the "40% of list" the docs claim
 *   a 20% coupon excluding the reward -> 20.00, untouched, while the paid line
 *                                        is still discounted
 *
 * No browser: this is arithmetic reported by the Store API, not rendering.
 *
 * Env: BASE, PAID_ID, REWARD_ID, REWARD_PRICE, GET_QTY, DISCOUNT_PERCENT,
 * COUPON_PERCENT, STACKING_CODE, EXCLUDING_CODE.
 */

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REWARD_PRICE = Number(process.env.REWARD_PRICE || 20);
const GET_QTY = Number(process.env.GET_QTY || 2);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);
const COUPON = Number(process.env.COUPON_PERCENT || 20);
const STACKING = process.env.STACKING_CODE || 'stack20';
const EXCLUDING = process.env.EXCLUDING_CODE || 'notreward20';

const failures = [];
const checks = [];

function check(name, pass, detail = '') {
	checks.push({ name, pass, detail });
	if (!pass) failures.push(`${name}${detail ? ' — ' + detail : ''}`);
}

const jar = new Map();
let nonce = '';

const cookieHeader = () => [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');

async function api(path, options = {}) {
	const res = await fetch(BASE + path, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			...(nonce ? { Nonce: nonce } : {}),
			...(jar.size ? { Cookie: cookieHeader() } : {}),
			...(options.headers || {}),
		},
	});

	for (const raw of res.headers.getSetCookie?.() ?? []) {
		const [pair] = raw.split(';');
		const idx = pair.indexOf('=');
		if (idx > 0) jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
	}

	const fresh = res.headers.get('Nonce');
	if (fresh) nonce = fresh;

	let body = null;
	try {
		body = await res.json();
	} catch {
		body = null;
	}

	return { status: res.status, body };
}

const rewardTotal = (cart) => {
	const line = (cart?.items || []).find((i) => i.id === REWARD_ID);
	return line ? Number(line.totals.line_total) : null;
};

// --- A qualifying cart with the reward on it --------------------------------

await api('/wp-json/wc/store/v1/cart');
await api('/wp-json/wc/store/v1/cart/add-item', {
	method: 'POST',
	body: JSON.stringify({ id: PAID_ID, quantity: 1 }),
});

const chosen = await api('/wp-json/wc/store/v1/cart/extensions', {
	method: 'POST',
	body: JSON.stringify({ namespace: 'bogo-select', data: { action: 'choose', product_id: REWARD_ID } }),
});

const minor = Number(chosen.body?.totals?.currency_minor_unit ?? 2);
const unit = 10 ** minor;
const discounted = Math.round(REWARD_PRICE * GET_QTY * (1 - DISCOUNT / 100) * unit);

check('the reward starts at the discounted figure',
	rewardTotal(chosen.body) === discounted,
	`expected ${discounted}, got ${rewardTotal(chosen.body)}`);

// --- An eligible coupon stacks on top ---------------------------------------

const stacked = await api('/wp-json/wc/store/v1/cart/apply-coupon', {
	method: 'POST',
	body: JSON.stringify({ code: STACKING }),
});

check('an eligible coupon is accepted', stacked.status === 200,
	`status ${stacked.status}: ${JSON.stringify(stacked.body).slice(0, 200)}`);

const bothApplied = Math.round(discounted * (1 - COUPON / 100));

check('an eligible coupon compounds on the already-reduced price',
	rewardTotal(stacked.body) === bothApplied,
	`expected ${bothApplied}, got ${rewardTotal(stacked.body)}`);

// The claim the documentation makes, stated the way a reader would check it.
const listPrice = Math.round(REWARD_PRICE * GET_QTY * unit);

check(`the customer pays ${Math.round((bothApplied / listPrice) * 100)}% of list, as documented`,
	rewardTotal(stacked.body) === Math.round(listPrice * (1 - DISCOUNT / 100) * (1 - COUPON / 100)),
	`list ${listPrice}, paid ${rewardTotal(stacked.body)}`);

// --- A coupon that excludes the reward leaves it alone ----------------------

await api('/wp-json/wc/store/v1/cart/remove-coupon', {
	method: 'POST',
	body: JSON.stringify({ code: STACKING }),
});

const excluded = await api('/wp-json/wc/store/v1/cart/apply-coupon', {
	method: 'POST',
	body: JSON.stringify({ code: EXCLUDING }),
});

check('a coupon excluding the reward is still accepted for the rest of the cart',
	excluded.status === 200,
	`status ${excluded.status}: ${JSON.stringify(excluded.body).slice(0, 200)}`);

check('a coupon that excludes the reward does not touch it',
	rewardTotal(excluded.body) === discounted,
	`expected ${discounted}, got ${rewardTotal(excluded.body)}`);

check('...but still discounts the rest of the cart',
	Number(excluded.body?.totals?.total_discount) > 0,
	`total_discount ${excluded.body?.totals?.total_discount}`);

// --- Report -----------------------------------------------------------------

console.log('\nCoupons alongside a discounted reward — Store API\n');
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
