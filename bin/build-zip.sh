#!/usr/bin/env bash
#
# Build an installable WordPress plugin zip for BOGO Select.
#
# The version is read from the Version: header in bogo-select.php and appended to
# the output filename, so the archive name can never disagree with the code. The
# script refuses to overwrite an existing archive — see BRIEF.md §8.
#
# Usage: bash bin/build-zip.sh
#
set -euo pipefail

SLUG="bogo-select"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="${PLUGIN_DIR}/${SLUG}.php"
DIST_DIR="${PLUGIN_DIR}/dist"

# shellcheck source=bin/package-manifest.sh
source "${PLUGIN_DIR}/bin/package-manifest.sh"

# --- Read and cross-check the version ---------------------------------------

if [[ ! -f "${MAIN_FILE}" ]]; then
	echo "error: cannot find ${MAIN_FILE}" >&2
	exit 1
fi

HEADER_VERSION="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-+]+' "${MAIN_FILE}" || true)"
CONST_VERSION="$(grep -m1 -oP "define\(\s*'BOGO_SELECT_VERSION'\s*,\s*'\K[0-9A-Za-z.\-+]+" "${MAIN_FILE}" || true)"

if [[ -z "${HEADER_VERSION}" ]]; then
	echo "error: no 'Version:' header found in ${SLUG}.php" >&2
	exit 1
fi

if [[ "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
	echo "error: version mismatch — header is '${HEADER_VERSION}', BOGO_SELECT_VERSION is '${CONST_VERSION}'." >&2
	echo "       Both must agree before a build (BRIEF.md §8.1)." >&2
	exit 1
fi

VERSION="${HEADER_VERSION}"
ARCHIVE="${DIST_DIR}/${SLUG}-${VERSION}.zip"

# --- Never clobber a previously built archive -------------------------------

if [[ -e "${ARCHIVE}" ]]; then
	echo "error: ${ARCHIVE##*/} already exists." >&2
	echo "       Previous builds are never deleted (BRIEF.md §8.3)." >&2
	echo "       Bump the version in ${SLUG}.php, or remove the stale file by hand." >&2
	exit 1
fi

# --- Stage a clean copy under a top-level plugin directory ------------------
#
# The staged tree is copied file by file from `package_files`, which is the same
# list `bin/verify-zip.sh` checks the finished archive against. Copying the tree
# and then deleting from it is what produced the node_modules archive: the two
# scripts each carried their own idea of what to remove, and only one of them
# learned about a new directory (CHANGELOG 2.3.5).

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/${SLUG}"

COPIED=0

while IFS= read -r file; do
	dir="${file%/*}"

	if [[ "${dir}" != "${file}" ]]; then
		mkdir -p "${STAGE}/${SLUG}/${dir}"
	fi

	cp -p "${PLUGIN_DIR}/${file}" "${STAGE}/${SLUG}/${file}"
	COPIED=$(( COPIED + 1 ))
done < <(package_files "${PLUGIN_DIR}")

if [[ "${COPIED}" -eq 0 ]]; then
	echo "error: nothing to package — bin/package-manifest.sh excluded every file." >&2
	exit 1
fi

# --- Archive ----------------------------------------------------------------
#
# No -x here. The staged tree is already exactly what should ship, and a second
# filter at zip time could drop a file the manifest included — leaving the build
# and the verifier disagreeing again, which is the whole thing this avoids.

mkdir -p "${DIST_DIR}"
( cd "${STAGE}" && zip -qr "${ARCHIVE}" "${SLUG}" )

echo "Built ${ARCHIVE#"${PLUGIN_DIR}/"}  ($(du -h "${ARCHIVE}" | cut -f1), $(unzip -Z1 "${ARCHIVE}" | grep -vc '/$') files)"
echo
echo "Archive in ${DIST_DIR#"${PLUGIN_DIR}/"}/:"
ls -1 "${DIST_DIR}"
