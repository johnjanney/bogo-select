#!/usr/bin/env bash
#
# Check that the package parity gate would notice a bad archive.
#
# `bin/verify-zip.sh` passing says the archive agrees with the worktree. It does
# not say the script would object to an archive that did not, and those are not
# the same claim — it compared `.php`, `.js`, and `.css` for eight releases, so
# every changelog, brief, and licence it shipped went out unread while the
# message said "matches the worktree" (CODEX-REVIEW.md L-03). Before that it
# ignored `node_modules/` on both sides at once and passed a 4.1MB archive
# carrying 197 files it had never looked at (CHANGELOG 2.3.5).
#
# Both failures share a shape: a check that is narrower than its own report.
# Nothing inside a check can detect that, because from the inside a file it does
# not read looks exactly like a file that is fine. So each mutation below builds
# a real archive, breaks it in a way that has happened or could happen, and
# requires the gate to fail *and to say why* — a non-zero exit for the wrong
# reason is not a catch.
#
# Everything happens in a temporary sandbox. This script never writes to the
# worktree it is run from, and never touches dist/.
#
# Usage: bash bin/verify-zip-check.sh
#
set -euo pipefail

SLUG="bogo-select"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=bin/package-manifest.sh
source "${PLUGIN_DIR}/bin/package-manifest.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT INT TERM

SANDBOX="${WORK}/worktree"
INJECT="${WORK}/inject"

# --- A worktree to build from -----------------------------------------------
#
# The files that ship, plus the scripts under test. Not vendor/ or tests/: the
# gate reads neither, and copying them would make this slow for no answer.

mkdir -p "${SANDBOX}/bin"

while IFS= read -r file; do
	dir="${file%/*}"
	[[ "${dir}" != "${file}" ]] && mkdir -p "${SANDBOX}/${dir}"
	cp -p "${PLUGIN_DIR}/${file}" "${SANDBOX}/${file}"
done < <(package_files "${PLUGIN_DIR}")

cp -p "${PLUGIN_DIR}"/bin/package-manifest.sh "${SANDBOX}/bin/"
cp -p "${PLUGIN_DIR}"/bin/build-zip.sh "${SANDBOX}/bin/"
cp -p "${PLUGIN_DIR}"/bin/verify-zip.sh "${SANDBOX}/bin/"

VERSION="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-+]+' "${SANDBOX}/${SLUG}.php")"
ARCHIVE="${SANDBOX}/dist/${SLUG}-${VERSION}.zip"

# --- The baseline has to pass first -----------------------------------------
#
# Without this the whole run is worthless in the most flattering possible way: a
# gate broken enough to fail on everything would report every mutation as
# caught.

( cd "${SANDBOX}" && bash bin/build-zip.sh >/dev/null )

if ! ( cd "${SANDBOX}" && bash bin/verify-zip.sh >"${WORK}/baseline.log" 2>&1 ); then
	echo "error: the gate rejects an archive it has just built from the same worktree." >&2
	echo "       No mutation below can mean anything until that passes." >&2
	echo >&2
	sed 's/^/       /' "${WORK}/baseline.log" >&2
	exit 1
fi

PRISTINE="${WORK}/pristine.zip"
cp "${ARCHIVE}" "${PRISTINE}"

# --- The mutations ----------------------------------------------------------
#
# Each is: a name, the word the gate must say, and a function that breaks the
# archive or the worktree it is compared against. The first four passed silently
# before the gate was widened; the last two are the cases it already caught,
# kept so that widening it cannot quietly lose them.

NAMES=()
MARKERS=()
BREAKS=()

mutation() {
	NAMES+=( "$1" ); MARKERS+=( "$2" ); BREAKS+=( "$3" )
}

# A shipped document drifts after the archive is built. This is the v1.2.0
# defect exactly — built before its own change landed — in the half of the
# archive the gate used to skip.
break_changelog() {
	printf '\n<!-- a paragraph the archive does not have -->\n' >> "${SANDBOX}/CHANGELOG.md"
}

break_licence() {
	printf '\nAll rights reserved.\n' >> "${SANDBOX}/LICENSE"
}

# A file the build was meant to ship, absent from the archive.
break_missing() {
	zip -q -d "${ARCHIVE}" "${SLUG}/README.md"
}

# Files the build deliberately leaves out, present anyway. These match the
# worktree byte for byte, so content comparison alone cannot see them: this is
# how the node_modules archive passed.
break_excluded() {
	mkdir -p "${INJECT}/${SLUG}/node_modules/playwright"
	printf 'module.exports = {};\n' > "${INJECT}/${SLUG}/node_modules/playwright/index.js"
	printf '{}\n' > "${INJECT}/${SLUG}/composer.lock"
	( cd "${INJECT}" && zip -qr "${ARCHIVE}" "${SLUG}" )
}

# A file the worktree does not have at all.
break_extra() {
	mkdir -p "${INJECT}/${SLUG}"
	printf 'installed by nobody\n' > "${INJECT}/${SLUG}/stray.txt"
	( cd "${INJECT}" && zip -qr "${ARCHIVE}" "${SLUG}" )
}

# An entry that unpacks beside the plugin directory rather than inside it, so
# walking the unpacked plugin directory would never reach it.
break_outside() {
	mkdir -p "${INJECT}"
	printf '<?php // not part of this plugin\n' > "${INJECT}/evil.php"
	( cd "${INJECT}" && zip -qr "${ARCHIVE}" "evil.php" )
}

mutation "a shipped document drifts from the worktree (CHANGELOG.md)" "STALE"    break_changelog
mutation "the licence drifts from the worktree (LICENSE)"             "STALE"    break_licence
mutation "a file the build ships is absent from the archive"          "MISSING"  break_missing
mutation "node_modules/ and composer.lock are packaged"               "EXCLUDED" break_excluded
mutation "the archive carries a file the worktree lacks"              "EXTRA"    break_extra
mutation "an entry unpacks outside the plugin directory"              "OUTSIDE"  break_outside

# --- Run --------------------------------------------------------------------

echo "Checking that the parity gate objects to ${#NAMES[@]} bad archives."
echo

SURVIVORS=()

for i in "${!NAMES[@]}"; do
	name="${NAMES[$i]}"
	marker="${MARKERS[$i]}"

	cp "${PRISTINE}" "${ARCHIVE}"
	rm -rf "${INJECT}"

	"${BREAKS[$i]}"

	log="${WORK}/mutation-${i}.log"

	if ( cd "${SANDBOX}" && bash bin/verify-zip.sh >"${log}" 2>&1 ); then
		echo "  SURVIVED  ${name}"
		echo "            the gate passed the archive."
		SURVIVORS+=( "${name}" )
	elif ! grep -q "^${marker}" "${log}"; then
		# It failed, but not for this. A gate that rejects everything is as
		# useless as one that rejects nothing, and reads the same from here.
		echo "  SURVIVED  ${name}"
		echo "            the gate failed, but never said ${marker}:"
		sed 's/^/              /' "${log}"
		SURVIVORS+=( "${name}" )
	else
		echo "  caught    ${name}  → ${marker}"
	fi

	# Undo a worktree mutation; an archive mutation is undone by the copy at the
	# top of the next pass.
	cp -p "${PLUGIN_DIR}/CHANGELOG.md" "${SANDBOX}/CHANGELOG.md"
	cp -p "${PLUGIN_DIR}/LICENSE" "${SANDBOX}/LICENSE"
done

echo

if (( ${#SURVIVORS[@]} > 0 )); then
	echo "${#SURVIVORS[@]} of ${#NAMES[@]} bad archives would have been published:" >&2
	for s in "${SURVIVORS[@]}"; do
		echo "  - ${s}" >&2
	done
	echo >&2
	echo "The gate is narrower than its own report. Widen it before trusting it" >&2
	echo "again (BRIEF.md §8.5)." >&2
	exit 1
fi

echo "All ${#NAMES[@]} were rejected, each for the right reason."
