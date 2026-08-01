/**
 * How a reward behaves against shipping.
 *
 * `OPEN-QUESTIONS.md` Q-004 stated this from the beginning and nothing ever
 * checked it: a free reward adds weight to the parcel but nothing to order
 * value, so it must not carry a customer over a free-shipping threshold, while a
 * discounted one adds both. Every other fixture in this suite uses virtual
 * products precisely so shipping stays out of the way, which is how the gap
 * survived this long.
 *
 * The figures make the two behaviours give different answers: the paid item
 * alone sits below the threshold, and the reward's undiscounted value alone
 * would carry it over.
 *
 *   free      45.00 paid + 0.00 reward  = 45.00  -> below, no free shipping
 *   50% off   45.00 paid + 10.00 reward = 55.00  -> above, free shipping offered
 *
 * The flat rate is priced per item, so it doubles when the reward joins the
 * parcel. That is the observable half of "it affects weight-based shipping":
 * flat rate cannot express weight, but a reward that is in the package is a
 * reward whose weight reaches a method that can.
 *
 * Env: BASE, MODE (free|percent), PAID_ID, REWARD_ID, PAID_PRICE, REWARD_VALUE,
 * THRESHOLD, DISCOUNT_PERCENT.
 */

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const MODE = process.env.MODE === 'percent' ? 'percent' : 'free';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const PAID_PRICE = Number(process.env.PAID_PRICE || 45);
const REWARD_VALUE = Number(process.env.REWARD_VALUE || 20);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);

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

const rates = (cart) => (cart?.shipping_rates?.[0]?.shipping_rates) || [];
const freeOffered = (cart) => rates(cart).some((r) => /free/i.test(r.method_id || r.name));
const flatRate = (cart) => {
	const rate = rates(cart).find((r) => /flat/i.test(r.method_id || r.name));
	return rate ? Number(rate.price) : null;
};

const address = {
	first_name: 'Ada', last_name: 'Lovelace', address_1: '1 Test Street',
	city: 'Los Angeles', state: 'CA', postcode: '90210', country: 'US',
};

await api('/wp-json/wc/store/v1/cart');
await api('/wp-json/wc/store/v1/cart/update-customer', {
	method: 'POST',
	body: JSON.stringify({
		shipping_address: address,
		billing_address: { ...address, email: 'ada@example.com' },
	}),
});

await api('/wp-json/wc/store/v1/cart/add-item', {
	method: 'POST',
	body: JSON.stringify({ id: PAID_ID, quantity: 1 }),
});

const before = (await api('/wp-json/wc/store/v1/cart')).body;
const minor = Number(before?.totals?.currency_minor_unit ?? 2);
const unit = 10 ** minor;

check('the paid item alone sits below the free-shipping threshold',
	!freeOffered(before), 'free shipping was already offered without the reward');

check('a shipping rate is quoted at all', flatRate(before) !== null,
	JSON.stringify(rates(before)));

const after = (await api('/wp-json/wc/store/v1/cart/extensions', {
	method: 'POST',
	body: JSON.stringify({ namespace: 'bogo-select', data: { action: 'choose', product_id: REWARD_ID } }),
})).body;

const subtotalBefore = Number(before?.totals?.total_items);
const subtotalAfter = Number(after?.totals?.total_items);

// The parcel grows either way: the reward is a real line with real weight.
check('the reward joins the shipping package',
	flatRate(after) > flatRate(before),
	`flat rate ${flatRate(before)} -> ${flatRate(after)}`);

if (MODE === 'free') {
	check('a free reward adds nothing to order value',
		subtotalAfter === subtotalBefore,
		`${subtotalBefore} -> ${subtotalAfter}`);

	check('a free reward does not carry the customer over the threshold',
		!freeOffered(after),
		'free shipping became available once a free reward was added');
} else {
	const expected = Math.round((PAID_PRICE + REWARD_VALUE * (1 - DISCOUNT / 100)) * unit);

	check('a discounted reward adds its own value to the order',
		subtotalAfter === expected,
		`expected ${expected}, got ${subtotalAfter}`);

	check('a discounted reward can carry the customer over the threshold',
		freeOffered(after),
		'free shipping was not offered even though the subtotal cleared it');
}

// --- Report -----------------------------------------------------------------

console.log(`\nShipping and a ${MODE === 'free' ? 'free' : 'discounted'} reward — Store API\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
