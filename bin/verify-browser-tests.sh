#!/usr/bin/env bash
#
# Check that the browser assertions would notice if the code were wrong.
#
# bin/verify-tests.sh does this for the unit suite. This is the half that
# matters more, because the browser layer is where a vacuous assertion actually
# shipped: v2.3.1's pinned-sibling check searched the whole page for "Large",
# which the chooser prints whichever sibling is in the cart, and it agreed with
# itself for two releases.
#
# Runs against the stack the integration job has already built, because standing
# up WordPress per mutation is not affordable. Each mutation is copied straight
# into the installed plugin, the matching test is run, and the test is required
# to fail.
#
# Two things make the result trustworthy rather than merely red:
#
# - Every target test is run **before** its mutation and required to pass. A
#   test that was already failing, or a stack that is broken, aborts the whole
#   script rather than being counted as a mutation caught.
# - Every mutation must match its file exactly once. A pattern that has drifted
#   is an error, not a hole.
#
# A survivor is a result to look into rather than a verdict. It means either the
# assertion cannot tell the difference — a hole — or the mutation did not change
# anything, which is a fault in the mutation. The first attempt at the row-layout
# one narrowed grid-template-columns and survived, because grid-template-areas
# still declared two columns and the layout stood; dropping the grid entirely is
# the version that asks the question.
#
# Takes the test files to cover, because the integration scenarios share one
# store and reconfigure it as they go: the storefront mutations have to run while
# the classic fixture's offer is in place, and the settings-screen ones after the
# admin fixture has seeded its users. Running everything at one point in the job
# means running some of it against a store its test was not written for.
#
# Usage: bash bin/verify-browser-tests.sh mobile.test.mjs classic.test.mjs
#        Expects the integration stack up, the plugin installed, and the same
#        env the integration tests are given.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

COMPOSE="docker compose -f tests/integration/docker-compose.yml"
INSTALLED="/var/www/html/wp-content/plugins/bogo-select"

BACKUP="$(mktemp -d)"
trap 'restore_all; rm -rf "${BACKUP}"' EXIT INT TERM

TOUCHED=()

restore_all() {
	local f
	for f in "${TOUCHED[@]:-}"; do
		[[ -z "${f}" ]] && continue
		local flat="${f//\//__}"
		[[ -f "${BACKUP}/${flat}" ]] || continue
		cp "${BACKUP}/${flat}" "${f}"
		${COMPOSE} cp "${f}" "wp:${INSTALLED}/${f}" >/dev/null 2>&1 || true
	done
}

push_file() {
	# Copy a worktree file over the installed plugin's copy of it.
	${COMPOSE} cp "$1" "wp:${INSTALLED}/$1" >/dev/null
}

# --- The mutations ----------------------------------------------------------
#
# name | file | from | to | test command

NAMES=(); FILES=(); FROMS=(); TOS=(); CMDS=()

mutation() {
	NAMES+=( "$1" ); FILES+=( "$2" ); FROMS+=( "$3" ); TOS+=( "$4" ); CMDS+=( "$5" )
}

mutation \
	"the phone layout's touch targets shrink back (v2.3.5)" \
	"assets/css/bogo-select.css" \
	'		min-height: 44px;' \
	'		min-height: 0;' \
	"node tests/integration/mobile.test.mjs"

mutation \
	"a gift card stops being a row on a phone (v2.2.0)" \
	"assets/css/bogo-select.css" \
	'		display: grid;' \
	'		display: block;' \
	"node tests/integration/mobile.test.mjs"

mutation \
	"the chooser's listeners leave the document (v2.2.1)" \
	"assets/js/bogo-select.js" \
	"	document.addEventListener( 'click', function ( event ) {" \
	"	( document.querySelector( '.bogo-select' ) || document ).addEventListener( 'click', function ( event ) {" \
	"node tests/integration/classic.test.mjs"

mutation \
	"a Shop Manager cannot save the settings page (M-02)" \
	"includes/class-bogo-admin.php" \
	"		add_filter( 'option_page_capability_' . self::GROUP, array( \$this, 'settings_capability' ) );
" \
	"" \
	"node tests/integration/admin.test.mjs"

mutation \
	"a reversed schedule is saved rather than refused (M-01)" \
	"includes/class-bogo-admin.php" \
	'			$clean['"'"'start_date'"'"'] = $stored['"'"'start_date'"'"'];
			$clean['"'"'end_date'"'"']   = $stored['"'"'end_date'"'"'];' \
	'			$clean['"'"'start_date'"'"'] = $clean['"'"'start_date'"'"'];
			$clean['"'"'end_date'"'"']   = $clean['"'"'end_date'"'"'];' \
	"node tests/integration/admin.test.mjs"

# --- Keep only the mutations for the tests named on the command line --------

if (( $# == 0 )); then
	echo "error: name at least one test file, e.g. mobile.test.mjs" >&2
	exit 1
fi

KEEP=()

for i in "${!NAMES[@]}"; do
	for want in "$@"; do
		if [[ "${CMDS[$i]}" == *"${want}"* ]]; then
			KEEP+=( "$i" )
			break
		fi
	done
done

if (( ${#KEEP[@]} == 0 )); then
	echo "error: no mutations cover $*" >&2
	exit 1
fi

# --- A store of our own -----------------------------------------------------
#
# The storefront tests are run repeatedly here, and the integration scenarios
# share one store that every scenario before this has been reconfiguring. Rather
# than depend on what they left, this seeds the classic fixture and uses the IDs
# it returns — so the baseline and every mutation see the same store, and this
# step does not silently depend on its position in the job.

needs_storefront=0

for i in "${KEEP[@]}"; do
	[[ "${CMDS[$i]}" == *mobile.test.mjs* || "${CMDS[$i]}" == *classic.test.mjs* ]] && needs_storefront=1
done

if (( needs_storefront )); then
	echo "Seeding a store for this check..."

	SEED="$( ${COMPOSE} exec -T cli wp eval-file /integration/setup-classic.php | tail -1 | tr -d '\r' )"

	if [[ -z "${SEED}" ]]; then
		echo "error: the classic fixture returned nothing." >&2
		exit 1
	fi

	eval "$( SEED="${SEED}" python3 - <<-'PY'
		import json, os, sys
		d = json.loads(os.environ['SEED'])
		pairs = {
		    'PAID_ID': 'paid', 'REWARD_ID': 'reward', 'VARIABLE_ID': 'variable',
		    'SMALL_ID': 'small', 'LARGE_ID': 'large',
		}
		for env, key in pairs.items():
		    if key not in d:
		        sys.stderr.write(f"fixture did not return {key}\n")
		        sys.exit(1)
		    print(f"export {env}={int(d[key])}")
	PY
	)"

	echo "  paid ${PAID_ID}, reward ${REWARD_ID}, variable ${VARIABLE_ID}, small ${SMALL_ID}, large ${LARGE_ID}"
	echo
fi

# --- Baselines --------------------------------------------------------------
#
# Every test used below must pass first. Without this, a broken stack would
# report every mutation as caught, which is the most flattering possible way for
# this script to be useless.

echo "Baselines — every target test must pass before anything is mutated."

declare -A SEEN=()

for i in "${KEEP[@]}"; do
	cmd="${CMDS[$i]}"
	[[ -n "${SEEN[$cmd]:-}" ]] && continue
	SEEN[$cmd]=1

	printf '  %-46s' "${cmd##*/}"

	# Output is captured rather than discarded: a baseline that fails without
	# saying why costs a full CI round-trip to diagnose, and this script exists
	# to run in CI.
	if baseline_out="$( eval "${cmd}" 2>&1 )"; then
		echo "pass"
	else
		echo "FAIL"
		echo >&2
		echo "error: ${cmd} fails before any mutation is applied." >&2
		echo "       Nothing below would mean anything. Fix that first." >&2
		echo >&2
		echo "${baseline_out}" | tail -30 >&2
		exit 1
	fi
done

echo

# --- Run --------------------------------------------------------------------

echo "Checking that the browser assertions object to ${#KEEP[@]} defects."
echo

SURVIVORS=()

for i in "${KEEP[@]}"; do
	name="${NAMES[$i]}"; file="${FILES[$i]}"
	from="${FROMS[$i]}"; to="${TOS[$i]}"; cmd="${CMDS[$i]}"

	flat="${file//\//__}"
	cp "${file}" "${BACKUP}/${flat}"
	TOUCHED+=( "${file}" )

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
		exit 1
	fi

	push_file "${file}"

	if eval "${cmd}" >/dev/null 2>&1; then
		echo "  SURVIVED  ${name}"
		SURVIVORS+=( "${name}" )
	else
		echo "  caught    ${name}"
	fi

	cp "${BACKUP}/${flat}" "${file}"
	push_file "${file}"
done

echo

if (( ${#SURVIVORS[@]} > 0 )); then
	echo "${#SURVIVORS[@]} of ${#KEEP[@]} defects went unnoticed by the browser tests:" >&2
	for s in "${SURVIVORS[@]}"; do
		echo "  - ${s}" >&2
	done
	echo >&2
	echo "An assertion that cannot tell these apart is not testing them." >&2
	exit 1
fi

echo "All ${#KEEP[@]} defects were caught."
