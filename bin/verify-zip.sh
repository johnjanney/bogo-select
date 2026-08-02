#!/usr/bin/env bash
#
# Verify that dist/bogo-select-<version>.zip actually contains the code in this
# worktree.
#
# A passing test suite says nothing about what was packaged. The v1.2.0 archive
# shipped a superseded class for exactly this reason (CODEX-REVIEW.md M-01): the
# fix was in the worktree, the zip was built before it, and nothing compared the
# two. This script is that comparison, and it is a release gate — see BRIEF.md
# §8.5.
#
# Every runtime file (.php, .js, .css) in the worktree must be present in the
# archive with an identical SHA-256, and the archive must not carry runtime files
# the worktree does not have.
#
# Usage: bash bin/verify-zip.sh
#
set -euo pipefail

SLUG="bogo-select"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="${PLUGIN_DIR}/${SLUG}.php"
DIST_DIR="${PLUGIN_DIR}/dist"

# --- Locate the archive for the current version -----------------------------

VERSION="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-+]+' "${MAIN_FILE}" || true)"

if [[ -z "${VERSION}" ]]; then
	echo "error: no 'Version:' header found in ${SLUG}.php" >&2
	exit 1
fi

ARCHIVE="${DIST_DIR}/${SLUG}-${VERSION}.zip"

if [[ ! -e "${ARCHIVE}" ]]; then
	echo "error: ${ARCHIVE##*/} does not exist — run bash bin/build-zip.sh first." >&2
	exit 1
fi

# --- Unpack it somewhere disposable -----------------------------------------

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

unzip -q "${ARCHIVE}" -d "${STAGE}"

PACKED="${STAGE}/${SLUG}"

if [[ ! -d "${PACKED}" ]]; then
	echo "error: ${ARCHIVE##*/} has no top-level ${SLUG}/ directory." >&2
	exit 1
fi

# --- List the runtime files on each side ------------------------------------
#
# The exclusions mirror bin/build-zip.sh: anything the build deliberately leaves
# out must not be reported as missing from the archive.

runtime_files() {
	# $1: root directory to list, relative paths on stdout.
	( cd "$1" && find . \
		\( -path './.git' -o -path './.github' -o -path './dist' -o -path './bin' \
		   -o -path './tests' -o -path './vendor' -o -name 'node_modules' \) -prune -o \
		-type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \) -print ) \
		| sed 's|^\./||' | sort
}

SOURCE_LIST="${STAGE}/source.txt"
PACKED_LIST="${STAGE}/packed.txt"

runtime_files "${PLUGIN_DIR}" > "${SOURCE_LIST}"
runtime_files "${PACKED}" > "${PACKED_LIST}"

# --- Compare ----------------------------------------------------------------

STATUS=0

while IFS= read -r file; do
	if [[ ! -f "${PACKED}/${file}" ]]; then
		echo "MISSING   ${file} — in the worktree, absent from the archive" >&2
		STATUS=1
		continue
	fi

	src="$(sha256sum "${PLUGIN_DIR}/${file}" | cut -d' ' -f1)"
	pkg="$(sha256sum "${PACKED}/${file}" | cut -d' ' -f1)"

	if [[ "${src}" != "${pkg}" ]]; then
		echo "STALE     ${file} — archive content differs from the worktree" >&2
		echo "            worktree ${src:0:16}" >&2
		echo "            archive  ${pkg:0:16}" >&2
		STATUS=1
	fi
done < "${SOURCE_LIST}"

while IFS= read -r file; do
	if [[ ! -f "${PLUGIN_DIR}/${file}" ]]; then
		echo "EXTRA     ${file} — in the archive, absent from the worktree" >&2
		STATUS=1
	fi
done < "${PACKED_LIST}"

# --- Report -----------------------------------------------------------------

COUNT="$(wc -l < "${SOURCE_LIST}" | tr -d ' ')"

if [[ "${STATUS}" -ne 0 ]]; then
	echo >&2
	echo "error: ${ARCHIVE##*/} does not match the worktree." >&2
	echo "       Rebuild it from the reviewed state before publishing (BRIEF.md §8.5)." >&2
	exit 1
fi

echo "${ARCHIVE##*/} matches the worktree (${COUNT} runtime files verified)."
