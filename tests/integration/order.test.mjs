/**
 * Placing a real order with a discounted reward on it.
 *
 * Everything before this stopped at the cart. BRIEF.md §8.6 listed order
 * placement, order metadata, and stock reduction as verified by hand, and
 * `CODEX-REVIEW.md` M-03 called that out: the plugin writes meta on
 * `woocommerce_checkout_create_order_line_item`, a hook nothing in CI had ever
 * fired.
 *
 * No browser. This drives the Store API directly, because none of it is about
 * rendering — the cart is built over HTTP, the order is placed through the
 * checkout route, and what landed is inspected afterwards by `assert-order.php`
 * through WP-CLI. Skipping Chromium also keeps the lane quick.
 *
 * Prints the order ID on the last line for the assertion step.
 *
 * Env: BASE, PAID_ID, REWARD_ID, REWARD_PRICE, GET_QTY, DISCOUNT_PERCENT.
 */

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REWARD_PRICE = Number(process.env.REWARD_PRICE || 20);
const GET_QTY = Number(process.env.GET_QTY || 2);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);

const failures = [];
const checks = [];

function check(name, pass, detail = '') {
	checks.push({ name, pass, detail });
	if (!pass) failures.push(`${name}${detail ? ' — ' + detail : ''}`);
}

// The cart lives in a session cookie, so the whole exchange has to look like one
// visitor. Node's fetch keeps no jar of its own.
const jar = new Map();

function cookieHeader() {
	return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

function remember(res) {
	for (const raw of res.headers.getSetCookie?.() ?? []) {
		const [pair] = raw.split(';');
		const idx = pair.indexOf('=');
		if (idx > 0) jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
	}
}

let nonce = '';

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

	remember(res);

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

// --- Build a qualifying cart and choose the reward --------------------------

await api('/wp-json/wc/store/v1/cart');

await api('/wp-json/wc/store/v1/cart/add-item', {
	method: 'POST',
	body: JSON.stringify({ id: PAID_ID, quantity: 1 }),
});

const chosen = await api('/wp-json/wc/store/v1/cart/extensions', {
	method: 'POST',
	body: JSON.stringify({
		namespace: 'bogo-select',
		data: { action: 'choose', product_id: REWARD_ID },
	}),
});

check('Store API: reward chosen', chosen.status === 200, `status ${chosen.status}`);

const rewardLine = (chosen.body?.items || []).find((i) => i.id === REWARD_ID);
const minor = Number(chosen.body?.totals?.currency_minor_unit ?? 2);
const expectedLine = Math.round(REWARD_PRICE * GET_QTY * (1 - DISCOUNT / 100) * 10 ** minor);

check('Store API: reward line is on the cart at the earned quantity',
	!!rewardLine && rewardLine.quantity === GET_QTY,
	rewardLine && `quantity ${rewardLine.quantity}`);

check('Store API: cart charges the discounted figure',
	!!rewardLine && Number(rewardLine.totals.line_total) === expectedLine,
	rewardLine && `expected ${expectedLine}, got ${rewardLine.totals.line_total}`);

// --- Place the order --------------------------------------------------------

const address = {
	first_name: 'Ada',
	last_name: 'Lovelace',
	address_1: '1 Test Street',
	city: 'Testville',
	state: 'CA',
	postcode: '90210',
	country: 'US',
	email: 'ada@example.com',
	phone: '5550000000',
};

const placed = await api('/wp-json/wc/store/v1/checkout', {
	method: 'POST',
	body: JSON.stringify({
		billing_address: address,
		shipping_address: address,
		payment_method: 'cod',
	}),
});

check('Store API: checkout accepted the order', placed.status === 200,
	`status ${placed.status}: ${JSON.stringify(placed.body).slice(0, 300)}`);

const orderId = placed.body?.order_id;
check('Store API: an order was created', !!orderId, JSON.stringify(placed.body).slice(0, 200));

// --- Report -----------------------------------------------------------------

console.log('\nOrder placement — Store API\n');
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}

// Last line, for the assertion step.
console.log(JSON.stringify({ order_id: Number(orderId) }));
