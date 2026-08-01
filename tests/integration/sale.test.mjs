/**
 * A reward that is already on sale, discounted again.
 *
 * `DECISION.md` D-016 chose to discount the effective selling price rather than
 * the regular one, so a 50% reward on a product already at 40% off costs 30% of
 * list. That was a decision taken on paper; `CODEX-REVIEW.md` M-03 asked for it
 * to be checked against a real store, because the two candidate behaviours
 * differ by real money and the wrong one is not obviously wrong in a total.
 *
 * The fixture prices make the two answers unmistakable: a regular 40.00 on sale
 * at 20.00, halved from the sale price, is 10.00. Halved from the regular price
 * it would be 20.00 — which is also the sale price, and so would look entirely
 * reasonable to anyone glancing at the cart.
 *
 * No browser: this is arithmetic reported by the Store API.
 *
 * Env: BASE, PAID_ID, REWARD_ID, REGULAR_PRICE, SALE_PRICE, DISCOUNT_PERCENT.
 */

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REGULAR = Number(process.env.REGULAR_PRICE || 40);
const SALE = Number(process.env.SALE_PRICE || 20);
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

await api('/wp-json/wc/store/v1/cart');
await api('/wp-json/wc/store/v1/cart/add-item', {
	method: 'POST',
	body: JSON.stringify({ id: PAID_ID, quantity: 1 }),
});

const cart = (await api('/wp-json/wc/store/v1/cart/extensions', {
	method: 'POST',
	body: JSON.stringify({ namespace: 'bogo-select', data: { action: 'choose', product_id: REWARD_ID } }),
})).body;

const line = (cart?.items || []).find((i) => i.id === REWARD_ID);
check('the reward line is on the cart', !!line);

const minor = Number(cart?.totals?.currency_minor_unit ?? 2);
const unit = 10 ** minor;

const fromSale = Math.round(SALE * (1 - DISCOUNT / 100) * unit);
const fromRegular = Math.round(REGULAR * (1 - DISCOUNT / 100) * unit);
const total = line ? Number(line.totals.line_total) : null;

check(`the reward is discounted from the sale price (${(fromSale / unit).toFixed(2)})`,
	total === fromSale, `expected ${fromSale}, got ${total}`);

check(`it is not discounted from the regular price (${(fromRegular / unit).toFixed(2)})`,
	total !== fromRegular, `line total ${total} matches the regular-price answer`);

// The consequence the documentation states, in the terms a reader would check.
const share = total !== null ? Math.round((total / (REGULAR * unit)) * 100) : null;
check(`the customer pays ${share}% of the regular price, as documented`,
	share === Math.round((SALE / REGULAR) * (1 - DISCOUNT / 100) * 100),
	`paid ${total} against a regular ${REGULAR * unit}`);

check('the reward still costs something', total !== null && total > 0, String(total));

// --- Report -----------------------------------------------------------------

console.log('\nA reward already on sale, discounted again — Store API\n');
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
