/**
 * Tax on a discounted reward line, in both display modes.
 *
 * The one place a wrong answer costs a store money. A reward discounted to half
 * price must be taxed on what the customer actually pays, not on the price it
 * was discounted from — the plugin sets the line's price before totals run
 * (`DECISION.md` D-002, D-016), so tax should follow, but nothing had checked it
 * (`CODEX-REVIEW.md` M-03). `OPEN-QUESTIONS.md` Q-004 is open partly on this.
 *
 * Run twice, once per mode, because the two differ in a way worth pinning:
 *
 *   prices exclude tax  20.00 shelf -> 10.00 net  + 1.00 tax  = 11.00 paid
 *   prices include tax  20.00 shelf ->  9.09 net  + 0.91 tax  = 10.00 paid
 *
 * Both are right: an exclusive store's shelf price never included the tax, so
 * half of it plus tax is what the customer owes. What matters in both is that
 * the tax is a tenth of the *discounted* line and not of the original.
 *
 * No browser: this is arithmetic reported by the Store API.
 *
 * Env: BASE, MODE (excl|incl), PAID_ID, REWARD_ID, REWARD_PRICE,
 * DISCOUNT_PERCENT, TAX_RATE.
 */

const BASE = process.env.BASE || 'http://127.0.0.1:8910';
const MODE = process.env.MODE === 'incl' ? 'incl' : 'excl';
const PAID_ID = Number(process.env.PAID_ID);
const REWARD_ID = Number(process.env.REWARD_ID);
const REWARD_PRICE = Number(process.env.REWARD_PRICE || 20);
const DISCOUNT = Number(process.env.DISCOUNT_PERCENT || 50);
const RATE = Number(process.env.TAX_RATE || 10);

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

const net = line ? Number(line.totals.line_total) : null;
const tax = line ? Number(line.totals.line_total_tax) : null;

// What the shelf price becomes once the offer is applied, in each mode.
const gross = Math.round(REWARD_PRICE * (1 - DISCOUNT / 100) * unit);
const expectedNet = MODE === 'incl' ? Math.round(gross / (1 + RATE / 100)) : gross;
const expectedTax = MODE === 'incl' ? gross - expectedNet : Math.round(gross * (RATE / 100));

check(`the discounted line is ${(expectedNet / unit).toFixed(2)} net`,
	net === expectedNet, `expected ${expectedNet}, got ${net}`);

check(`tax on it is ${(expectedTax / unit).toFixed(2)}`,
	tax === expectedTax, `expected ${expectedTax}, got ${tax}`);

// The assertion that matters in both modes, and the one a store would feel.
const taxOnUndiscounted = MODE === 'incl'
	? Math.round(REWARD_PRICE * unit) - Math.round(Math.round(REWARD_PRICE * unit) / (1 + RATE / 100))
	: Math.round(REWARD_PRICE * unit * (RATE / 100));

check('tax is charged on the discounted price, not the price it was discounted from',
	tax !== null && tax < taxOnUndiscounted,
	`tax ${tax}, undiscounted would be ${taxOnUndiscounted}`);

check('tax is the configured rate on the discounted line',
	tax !== null && net !== null && Math.abs(tax - net * (RATE / 100)) <= 1,
	`tax ${tax} against ${RATE}% of ${net}`);

if (MODE === 'incl') {
	// A tax-inclusive store quotes gross, so half of a 20.00 shelf price is
	// exactly 10.00 to the customer, tax included.
	check('the customer pays exactly half the shelf price, tax included',
		net + tax === gross, `${net} + ${tax} !== ${gross}`);
}

check('the cart reports tax at all', Number(cart?.totals?.total_tax) > 0,
	`total_tax ${cart?.totals?.total_tax}`);

// --- Report -----------------------------------------------------------------

console.log(`\nTax on a discounted reward — prices ${MODE === 'incl' ? 'include' : 'exclude'} tax\n`);
for (const c of checks) {
	console.log(`  ${c.pass ? 'PASS' : 'FAIL'}  ${c.name}${!c.pass && c.detail ? `\n          ${c.detail}` : ''}`);
}
console.log(`\n${checks.filter((c) => c.pass).length}/${checks.length} checks passed.`);

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:`);
	for (const f of failures) console.error(`  - ${f}`);
	process.exit(1);
}
