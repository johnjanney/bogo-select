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
# Every entry in the archive is checked, whatever its extension: it must be a
# file the worktree has, with an identical SHA-256, that the build was meant to
# ship. And every file the build was meant to ship must be in the archive. The
# list of what ships is `bin/package-manifest.sh`, which the build reads too, so
# the verifier cannot fall behind what the build does.
#
# Usage: bash bin/verify-zip.sh
#
set -euo pipefail

SLUG="bogo-select"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="${PLUGIN_DIR}/${SLUG}.php"
DIST_DIR="${PLUGIN_DIR}/dist"

# shellcheck source=bin/package-manifest.sh
source "${PLUGIN_DIR}/bin/package-manifest.sh"

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

STATUS=0

# --- Every entry in the archive, read from the archive itself ---------------
#
# `unzip -Z1` is used rather than a walk of the unpacked tree because the
# question is what the archive contains. A file installed outside the plugin
# directory would never appear under ${PACKED} and so could not be found by
# walking it.

ENTRIES=0
ARCHIVE_FILES="${STAGE}/archive.txt"
: > "${ARCHIVE_FILES}"

while IFS= read -r entry; do
	ENTRIES=$(( ENTRIES + 1 ))

	if [[ "${entry}" != "${SLUG}/"* ]]; then
		echo "OUTSIDE   ${entry} — outside the ${SLUG}/ directory the archive installs" >&2
		STATUS=1
		continue
	fi

	file="${entry#"${SLUG}/"}"

	# A directory entry carries no content, but it can still name something
	# that has no business in a release.
	if [[ "${entry}" == */ ]]; then
		if package_excluded "${file}"; then
			echo "EXCLUDED  ${file} — the build leaves this out; the archive has it anyway" >&2
			STATUS=1
		fi
		continue
	fi

	printf '%s\n' "${file}" >> "${ARCHIVE_FILES}"

	if package_excluded "${file}"; then
		echo "EXCLUDED  ${file} — the build leaves this out; the archive has it anyway" >&2
		STATUS=1
		continue
	fi

	if [[ ! -f "${PLUGIN_DIR}/${file}" ]]; then
		echo "EXTRA     ${file} — in the archive, absent from the worktree" >&2
		STATUS=1
	fi
done < <(unzip -Z1 "${ARCHIVE}")

if [[ "${ENTRIES}" -eq 0 ]]; then
	echo "error: ${ARCHIVE##*/} is empty." >&2
	exit 1
fi

LC_ALL=C sort -o "${ARCHIVE_FILES}" "${ARCHIVE_FILES}"

# --- Every file the build was meant to ship ---------------------------------

EXPECTED="${STAGE}/expected.txt"
package_files "${PLUGIN_DIR}" > "${EXPECTED}"

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
done < "${EXPECTED}"

# --- Report -----------------------------------------------------------------
#
# The count is of files actually compared, not of files listed on one side. A
# parity check that overstates its own coverage is worse than none: the v2.3.5
# archive reported "87 runtime files verified" while carrying 197 it had never
# looked at.

COUNT="$(LC_ALL=C comm -12 "${EXPECTED}" "${ARCHIVE_FILES}" | wc -l | tr -d ' ')"

if [[ "${STATUS}" -ne 0 ]]; then
	echo >&2
	echo "error: ${ARCHIVE##*/} does not match the worktree." >&2
	echo "       Rebuild it from the reviewed state before publishing (BRIEF.md §8.5)." >&2
	exit 1
fi

echo "${ARCHIVE##*/} matches the worktree (${COUNT} files verified, every archive entry checked)."
