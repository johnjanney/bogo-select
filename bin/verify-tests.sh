#!/usr/bin/env bash
#
# Check that the unit suite would notice if the code were wrong.
#
# A green suite says the tests agree with the code. It does not say the tests
# would object to different code, and those are not the same claim — v2.3.1
# shipped a browser assertion that passed on a negative which was true either
# way, and it passed for two releases.
#
# Each mutation below reintroduces a defect this plugin actually had, and the
# suite is required to fail. A mutation that survives is a hole: the behaviour
# is described in the changelog and guarded by nothing.
#
# Not a substitute for a mutation testing tool. It is a fixed, curated set,
# chosen so every entry corresponds to something that once went wrong here, and
# so a failure names the defect rather than a line number.
#
# Usage: bash bin/verify-tests.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

BACKUP="$(mktemp -d)"
trap 'restore_all; rm -rf "${BACKUP}"' EXIT INT TERM

TOUCHED=()

restore_all() {
	local f
	for f in "${TOUCHED[@]:-}"; do
		[[ -n "${f}" && -f "${BACKUP}/$(basename "${f}")" ]] && cp "${BACKUP}/$(basename "${f}")" "${f}"
	done
}

# --- The mutations ----------------------------------------------------------
#
# Each is: a name, the file, the exact text to replace, and what to replace it
# with. The text must match once — a mutation that silently applies nowhere
# would report a hole that is really a typo, which is the one failure mode this
# script must not have.

NAMES=()
FILES=()
FROMS=()
TOS=()

mutation() {
	NAMES+=( "$1" ); FILES+=( "$2" ); FROMS+=( "$3" ); TOS+=( "$4" )
}

mutation \
	"a Buy list stops matching a variation by its own ID (v2.3.0)" \
	"includes/class-bogo-engine.php" \
	'		return in_array( (int) $product_id, $allowed, true )
			|| ( $variation_id && in_array( (int) $variation_id, $allowed, true ) );' \
	'		return in_array( (int) $product_id, $allowed, true );'

mutation \
	"a gift line counts toward qualifying for another gift (D-004)" \
	"includes/class-bogo-engine.php" \
	'			if ( self::is_reward_item( $cart_item ) ) {
				continue;
			}' \
	'			if ( false ) {
				continue;
			}'

mutation \
	"a reversed schedule is described and saved anyway (M-01)" \
	"includes/class-bogo-admin.php" \
	'			$clean['"'"'start_date'"'"'] = $stored['"'"'start_date'"'"'];
			$clean['"'"'end_date'"'"']   = $stored['"'"'end_date'"'"'];' \
	'			$clean['"'"'start_date'"'"'] = $clean['"'"'start_date'"'"'];
			$clean['"'"'end_date'"'"']   = $clean['"'"'end_date'"'"'];'

mutation \
	"a date with trailing junk is read as the date inside it (v2.3.1)" \
	"includes/class-bogo-settings.php" \
	"'/^(\\d{4})-(\\d{1,2})-(\\d{1,2})\$/'" \
	"'/^(\\d{4})-(\\d{1,2})-(\\d{1,2})/'"

mutation \
	"a Shop Manager cannot save the page they can open (M-02)" \
	"includes/class-bogo-admin.php" \
	"		add_filter( 'option_page_capability_' . self::GROUP, array( \$this, 'settings_capability' ) );
" \
	""

mutation \
	"the offer summary counts list entries, not selections (L-05)" \
	"includes/class-bogo-admin.php" \
	'		$buy_count = $this->distinct_buy_selections( $s['"'"'buy_products'"'"'] );' \
	'		$buy_count = count( $s['"'"'buy_products'"'"'] );'

mutation \
	"a gift search loads every candidate twice (M-03)" \
	"includes/class-bogo-engine.php" \
	'			$product      = self::choice_product( $id );' \
	'			$product      = wc_get_product( $id );'

mutation \
	"an array where an ID belongs becomes product 1 (v2.3.7)" \
	"includes/class-bogo-settings.php" \
	'		return is_scalar( $value ) ? absint( $value ) : 0;' \
	'		return absint( $value ); // phpcs:ignore'

# --- Run --------------------------------------------------------------------

echo "Checking that the suite objects to ${#NAMES[@]} defects it should object to."
echo

SURVIVORS=()

for i in "${!NAMES[@]}"; do
	name="${NAMES[$i]}"
	file="${FILES[$i]}"
	from="${FROMS[$i]}"
	to="${TOS[$i]}"

	cp "${file}" "${BACKUP}/$(basename "${file}")"
	TOUCHED+=( "${file}" )

	# python rather than sed: the patterns are multi-line and full of the
	# punctuation sed would need escaping for.
	if ! FROM="${from}" TO="${to}" python3 - "${file}" <<-'PY'
		import os, sys
		path = sys.argv[1]
		src = open(path).read()
		frm, to = os.environ['FROM'], os.environ['TO']
		if src.count(frm) != 1:
		    sys.stderr.write(f"pattern matched {src.count(frm)} times, expected 1\n")
		    sys.exit(1)
		open(path, 'w').write(src.replace(frm, to))
	PY
	then
		echo "  ERROR  ${name}"
		echo "         the mutation did not apply — the code it edits has moved."
		restore_all
		exit 1
	fi

	if vendor/bin/phpunit >/dev/null 2>&1; then
		echo "  SURVIVED  ${name}"
		SURVIVORS+=( "${name}" )
	else
		echo "  caught    ${name}"
	fi

	cp "${BACKUP}/$(basename "${file}")" "${file}"
done

echo

if (( ${#SURVIVORS[@]} > 0 )); then
	echo "${#SURVIVORS[@]} of ${#NAMES[@]} defects went unnoticed by the suite:" >&2
	for s in "${SURVIVORS[@]}"; do
		echo "  - ${s}" >&2
	done
	echo >&2
	echo "Each is behaviour this plugin documents and nothing guards. Add a test." >&2
	exit 1
fi

echo "All ${#NAMES[@]} defects were caught."
