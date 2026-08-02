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

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/${SLUG}"

# rsync when available (precise excludes); otherwise fall back to cp + prune.
if command -v rsync >/dev/null 2>&1; then
	rsync -a \
		--exclude '.git/' \
		--exclude '.github/' \
		--exclude '.gitignore' \
		--exclude 'dist/' \
		--exclude 'bin/' \
		--exclude 'tests/' \
		--exclude 'vendor/' \
		--exclude 'node_modules/' \
		--exclude 'composer.json' \
		--exclude 'composer.lock' \
		--exclude 'phpunit.xml.dist' \
		--exclude 'phpstan.neon.dist' \
		--exclude 'phpstan.neon' \
		--exclude '.phpcs.xml.dist' \
		--exclude '.phpcs.xml' \
		--exclude 'package.json' \
		--exclude 'package-lock.json' \
		--exclude '.phpunit.result.cache' \
		--exclude 'CODEX-REVIEW*.md' \
		--exclude '.DS_Store' \
		--exclude 'Thumbs.db' \
		--exclude '*.swp' \
		--exclude '*~' \
		"${PLUGIN_DIR}/" "${STAGE}/${SLUG}/"
else
	cp -R "${PLUGIN_DIR}/." "${STAGE}/${SLUG}/"
	rm -rf "${STAGE}/${SLUG}/.git" \
		"${STAGE}/${SLUG}/.github" \
		"${STAGE}/${SLUG}/.gitignore" \
		"${STAGE}/${SLUG}/dist" \
		"${STAGE}/${SLUG}/bin" \
		"${STAGE}/${SLUG}/tests" \
		"${STAGE}/${SLUG}/vendor" \
		"${STAGE}/${SLUG}/node_modules" \
		"${STAGE}/${SLUG}/composer.json" \
		"${STAGE}/${SLUG}/composer.lock" \
		"${STAGE}/${SLUG}/phpunit.xml.dist" \
		"${STAGE}/${SLUG}/phpstan.neon.dist" \
		"${STAGE}/${SLUG}/phpstan.neon" \
		"${STAGE}/${SLUG}/.phpcs.xml.dist" \
		"${STAGE}/${SLUG}/.phpcs.xml" \
		"${STAGE}/${SLUG}/package.json" \
		"${STAGE}/${SLUG}/package-lock.json" \
		"${STAGE}/${SLUG}/.phpunit.result.cache"
	find "${STAGE}/${SLUG}" \
		\( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.swp' -o -name '*~' \
		   -o -name 'CODEX-REVIEW*.md' \) \
		-delete
fi

# --- Archive ----------------------------------------------------------------

mkdir -p "${DIST_DIR}"
( cd "${STAGE}" && zip -qr "${ARCHIVE}" "${SLUG}" -x '.*' )

echo "Built ${ARCHIVE#"${PLUGIN_DIR}/"}  ($(du -h "${ARCHIVE}" | cut -f1), $(unzip -Z1 "${ARCHIVE}" | grep -vc '/$') files)"
echo
echo "Archive in ${DIST_DIR#"${PLUGIN_DIR}/"}/:"
ls -1 "${DIST_DIR}"
