#!/usr/bin/env bash
#
# Fails when a branch changes something that ships without raising the version.
#
# The release workflow compares the tag against the plugin header, so a branch that bumps
# nothing passes every check until a release is cut, by hand, on an already merged branch.
# See CONTRIBUTING.md: "Versioning".
#
# Usage: check-version-bump.sh [base-ref]        (default: main; CI passes origin/<base>)
# Set ALLOW_UNVERSIONED=1 to pass anyway, for a branch that deliberately ships unversioned.

set -euo pipefail

readonly BASE="${1:-main}"
readonly PLUGIN_FILE='hm-abilities-mcp.php'

fail() {
	printf 'error: %s\n' "$1" >&2
	exit 1
}

read_version() {
	sed -n 's/^ \* Version: *//p' | tr -d '[:space:]'
}

# A path ships when neither it nor any directory above it is export-ignored. The ancestor
# walk is the whole point: git archive prunes a directory before it looks inside, so `/docs`
# never matches docs/HANDOFF.md on its own.
ships() {
	local candidate="$1"

	while [ "${candidate}" != '.' ] && [ "${candidate}" != '/' ]; do
		if [ "$( git check-attr export-ignore -- "${candidate}" | sed 's/.*: //' )" = 'set' ]; then
			return 1
		fi
		candidate="$( dirname "${candidate}" )"
	done

	return 0
}

version_gt() {
	local a1 a2 a3 b1 b2 b3
	IFS=. read -r a1 a2 a3 <<< "$1"
	IFS=. read -r b1 b2 b3 <<< "$2"

	if [ "$(( a1 ))" -ne "$(( b1 ))" ]; then
		[ "$(( a1 ))" -gt "$(( b1 ))" ]
		return
	fi
	if [ "$(( a2 ))" -ne "$(( b2 ))" ]; then
		[ "$(( a2 ))" -gt "$(( b2 ))" ]
		return
	fi
	[ "$(( a3 ))" -gt "$(( b3 ))" ]
}

git rev-parse --verify --quiet "${BASE}" > /dev/null \
	|| fail "base ref '${BASE}' not found. CI needs actions/checkout with fetch-depth: 0"

base_version="$( git show "${BASE}:${PLUGIN_FILE}" | read_version )"
head_version="$( read_version < "${PLUGIN_FILE}" )"

[ -n "${base_version}" ] || fail "no Version header on ${BASE}"
[ -n "${head_version}" ] || fail "no Version header in ${PLUGIN_FILE}"

# All three sources, because a branch mid-review has committed, modified and untracked work
# at once, and `git diff ${BASE}...HEAD` alone reports none of the last two. Reading only
# that is how this check would come to pass a branch it should fail.
changed="$( mktemp )"
shipping="$( mktemp )"
trap 'rm -f "${changed}" "${shipping}"' EXIT

{
	git diff --name-only "${BASE}...HEAD"
	git diff --name-only HEAD
	git ls-files --others --exclude-standard
} | LC_ALL=C sort -u > "${changed}"

while IFS= read -r path; do
	[ -n "${path}" ] || continue
	if ships "${path}"; then
		printf '%s\n' "${path}" >> "${shipping}"
	fi
done < "${changed}"

if [ ! -s "${shipping}" ]; then
	printf 'No change ships: nothing to version (still %s).\n' "${head_version}"
	exit 0
fi

shipping_count="$( wc -l < "${shipping}" | tr -d ' ' )"

if [ "${ALLOW_UNVERSIONED:-0}" = '1' ]; then
	printf 'ALLOW_UNVERSIONED=1: %d shipping change(s) accepted at %s.\n' \
		"${shipping_count}" "${head_version}"
	exit 0
fi

if [ "${head_version}" = "${base_version}" ]; then
	printf 'error: these changes ship, but the version is still %s:\n' "${head_version}" >&2
	sed 's/^/  /' "${shipping}" >&2
	printf 'Raise the Version header in %s. See CONTRIBUTING.md: "Versioning".\n' "${PLUGIN_FILE}" >&2
	exit 1
fi

version_gt "${head_version}" "${base_version}" \
	|| fail "version went backwards: ${BASE} is ${base_version}, this branch is ${head_version}"

printf 'Version raised %s -> %s for %d shipping change(s).\n' \
	"${base_version}" "${head_version}" "${shipping_count}"
