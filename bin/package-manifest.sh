#!/usr/bin/env bash
#
# What belongs in the plugin archive, in one place.
#
# `bin/build-zip.sh` copies exactly this list, and `bin/verify-zip.sh` checks
# the archive against exactly this list, so the two cannot disagree about what
# a release contains.
#
# They used to keep separate lists, and the lists drifted. The build learned to
# exclude `node_modules/` and the verifier did not, so a 4.1MB archive carrying
# 197 Playwright files passed its own parity check and reported "87 runtime
# files verified" (CHANGELOG 2.3.5). The verifier also only ever compared
# `.php`, `.js`, and `.css`, so the documentation and licence in every archive
# were shipped unchecked (`CODEX-REVIEW.md` L-03).
#
# This file is sourced, not run.

# --- What stays out ---------------------------------------------------------
#
# Everything else ships. The list says what is left out and never what is let
# in, so a new runtime file is packaged and verified without being named here —
# a file that has to be added in two places is a file that gets added in one.
#
# Each pattern is a shell glob matched against one path component at a time, so
# 'node_modules' catches it at any depth and '*~' catches an editor backup
# wherever it was left.

PACKAGE_EXCLUDES=(
	'.git'
	'.github'
	'.gitignore'
	'dist'
	'bin'
	'tests'
	'vendor'
	'node_modules'
	'composer.json'
	'composer.lock'
	'phpunit.xml.dist'
	'phpstan.neon.dist'
	'phpstan.neon'
	'.phpcs.xml.dist'
	'.phpcs.xml'
	'package.json'
	'package-lock.json'
	'.phpunit.result.cache'
	'CODEX-REVIEW*.md'
	'.DS_Store'
	'Thumbs.db'
	'*.swp'
	'*~'
)

# --- Reading the list -------------------------------------------------------

# package_excluded <relative-path>
#
# True when any component of the path matches an exclusion. Used on the archive
# side, where the question is not "which files should ship" but "should this
# entry be here at all" — a `tests/` directory or a `composer.lock` in a built
# archive is a packaging defect however it got in.
package_excluded() {
	local path="${1%/}" component pattern

	while [[ -n "${path}" && "${path}" != '.' ]]; do
		component="${path##*/}"

		for pattern in "${PACKAGE_EXCLUDES[@]}"; do
			# shellcheck disable=SC2053 -- an unquoted glob match is the point.
			if [[ "${component}" == ${pattern} ]]; then
				return 0
			fi
		done

		[[ "${path}" == */* ]] || break
		path="${path%/*}"
	done

	return 1
}

# package_files <root>
#
# Every file that belongs in the archive, as paths relative to <root>, sorted.
# Excluded directories are pruned rather than walked, so this does not descend
# into `vendor/` or `node_modules/`.
package_files() {
	local root="$1" pattern
	local -a prune=()

	for pattern in "${PACKAGE_EXCLUDES[@]}"; do
		prune+=( -name "${pattern}" -o )
	done
	unset 'prune[${#prune[@]}-1]'

	( cd "${root}" && find . \( "${prune[@]}" \) -prune -o -type f -print ) \
		| sed 's|^\./||' \
		| LC_ALL=C sort
}
