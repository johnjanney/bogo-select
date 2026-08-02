#!/usr/bin/env bash
#
# Verify that CI passed on the exact commit a tag points at, before that tag is
# published as a release.
#
# Two tags went out with red CI before this existed. v2.3.1's integration lanes
# failed on an assertion introduced in the same release; v2.3.8's coding-standard
# job failed on a docblock. Both were found afterwards, by hand, and only because
# someone went looking.
#
# The failure underneath both was the same, and it is what this script is shaped
# around: the run was identified with `gh run list --limit 1` moments after a
# push, which returns whatever run existed at that instant — often the *previous*
# commit's. A green answer for the wrong commit is worse than no answer, because
# it reads as verification. Runs are matched by SHA here and by nothing else.
#
# Exits non-zero when the run failed, when it is still going after the wait, and
# — deliberately — when no run exists at all. A commit CI has never seen is not a
# commit to publish.
#
# Usage: bash bin/verify-ci.sh [tag]          (default: the current HEAD's tag)
#        BOGO_CI_WAIT=600 bash bin/verify-ci.sh v2.3.9
#
set -euo pipefail

WORKFLOW="CI"
WAIT_SECONDS="${BOGO_CI_WAIT:-900}"
POLL_SECONDS=15

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

if ! command -v gh >/dev/null 2>&1; then
	echo "error: the GitHub CLI (gh) is required to read CI results." >&2
	exit 1
fi

# --- Work out which commit is being published -------------------------------

TAG="${1:-}"

if [[ -z "${TAG}" ]]; then
	TAG="$(git describe --tags --exact-match HEAD 2>/dev/null || true)"
fi

if [[ -z "${TAG}" ]]; then
	echo "error: no tag given and HEAD is not tagged." >&2
	echo "       Usage: bash bin/verify-ci.sh v1.2.3" >&2
	exit 1
fi

if ! git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
	echo "error: ${TAG} is not a tag in this repository." >&2
	exit 1
fi

SHA="$(git rev-list -n1 "${TAG}")"

# --- The tag has to be the one the world can see ----------------------------
#
# CI runs on what was pushed. A local tag pointing somewhere else would have this
# script verifying a commit nobody else has.

REMOTE_LINE="$(git ls-remote --tags origin "refs/tags/${TAG}^{}" "refs/tags/${TAG}" 2>/dev/null | tail -1 || true)"
REMOTE_SHA="${REMOTE_LINE%%$'\t'*}"

if [[ -z "${REMOTE_SHA}" ]]; then
	echo "error: ${TAG} has not been pushed to origin." >&2
	echo "       CI has therefore never seen it. Push the tag first." >&2
	exit 1
fi

if [[ "${REMOTE_SHA}" != "${SHA}" ]]; then
	echo "error: ${TAG} points at ${SHA:0:7} locally and ${REMOTE_SHA:0:7} on origin." >&2
	echo "       Tags are never moved (BRIEF.md §8.4); resolve this by hand." >&2
	exit 1
fi

echo "Checking ${WORKFLOW} for ${TAG} (${SHA:0:7})..."

# --- Find the run for that commit, and only that commit ---------------------

run_field() {
	# $1: field name. Empty output when there is no run.
	gh run list --workflow="${WORKFLOW}" --commit "${SHA}" --limit 1 \
		--json "$1" --jq ".[0].$1" 2>/dev/null || true
}

DEADLINE=$(( SECONDS + WAIT_SECONDS ))
STATUS=""

while :; do
	STATUS="$(run_field status)"

	if [[ -z "${STATUS}" || "${STATUS}" == "null" ]]; then
		if (( SECONDS >= DEADLINE )); then
			echo "error: no ${WORKFLOW} run exists for ${SHA:0:7}." >&2
			echo "       A commit CI has never seen is not one to publish." >&2
			exit 1
		fi

		echo "  no run yet; waiting..."
		sleep "${POLL_SECONDS}"
		continue
	fi

	if [[ "${STATUS}" == "completed" ]]; then
		break
	fi

	if (( SECONDS >= DEADLINE )); then
		echo "error: the run for ${SHA:0:7} is still ${STATUS} after ${WAIT_SECONDS}s." >&2
		echo "       Raise BOGO_CI_WAIT, or wait for it and run this again." >&2
		exit 1
	fi

	echo "  run is ${STATUS}; waiting..."
	sleep "${POLL_SECONDS}"
done

CONCLUSION="$(run_field conclusion)"
RUN_ID="$(run_field databaseId)"

if [[ "${CONCLUSION}" != "success" ]]; then
	echo "error: ${WORKFLOW} concluded '${CONCLUSION}' on ${SHA:0:7}." >&2
	echo >&2
	gh run view "${RUN_ID}" --json jobs \
		--jq '.jobs[] | select(.conclusion != "success") | "         failed: \(.name)"' >&2 || true
	echo >&2
	echo "       Do not publish ${TAG}. Fix it on a later commit; the tag stays" >&2
	echo "       where it is (BRIEF.md §8.4)." >&2
	exit 1
fi

echo "${WORKFLOW} passed on ${TAG} (${SHA:0:7}), run ${RUN_ID}."
