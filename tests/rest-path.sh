#!/usr/bin/env bash
#
# Exercises abilities over a real HTTP request to the REST route.
#
# The PHP suites run under `wp eval-file`, and WP-CLI loads
# wp-admin/includes/template.php. Query Monitor loads it too. Either one makes
# `add_settings_error()` exist, which is what `sanitize_option()` needs to
# report a value it refuses — so in both of those contexts the settings write
# path looks correct whether or not it handles the refusal itself.
#
# A production MCP request has neither. This runs from the host, against the
# site's own PHP process, with Query Monitor deactivated, which is the only
# arrangement here that reproduces it.
#
# Called by tests/run.sh; not meant to be run directly.

set -uo pipefail

cd "$(dirname "$0")/.."

PASSES=0
FAILURES=0

check() {
	local label="$1" ok="$2" context="${3:-}"

	if [ "$ok" = "1" ]; then
		echo "PASS  $label"
		PASSES=$((PASSES + 1))
	else
		echo "FAIL  $label"
		[ -n "$context" ] && echo "      $context"
		FAILURES=$((FAILURES + 1))
	fi
}

wpcli() {
	npx @wordpress/env run cli -- "$@" 2>/dev/null | tr -d '\r' | grep -vE '^(ℹ|✔|$)'
}

echo "Deactivating Query Monitor so the request matches a production stack."
wpcli wp plugin deactivate query-monitor >/dev/null 2>&1

restore_plugins() {
	wpcli wp plugin activate query-monitor >/dev/null 2>&1
}
trap restore_plugins EXIT

APP_PASS=$(wpcli wp user application-password create admin rest-suite --porcelain | tail -1)

if [ -z "$APP_PASS" ]; then
	echo "FAIL  could not create an application password"
	exit 1
fi

AUTH="admin:$APP_PASS"
ROUTE="http://localhost:3031/index.php?rest_route=/wp-abilities/v1/abilities"

call() {
	# $1 ability, $2 method, rest: input[key]=value pairs
	local ability="$1" method="$2"
	shift 2
	local url="$ROUTE/$ability/run"
	for pair in "$@"; do
		url="$url&$pair"
	done
	curl -s -u "$AUTH" -X "$method" "$url"
}

BEFORE_TZ=$(wpcli wp option get timezone_string | tail -1)

echo ""
echo "== a value WordPress refuses is reported, not swallowed =="

OUT=$(call hm-abilities/update-settings DELETE 'input%5Btimezone%5D=Not%2FAZone')
echo "$OUT" | grep -q 'hm_abilities_setting_rejected'
check "an invalid timezone is reported over REST" "$([ $? -eq 0 ] && echo 1 || echo 0)" "$OUT"

AFTER_TZ=$(wpcli wp option get timezone_string | tail -1)
check "and the option was left alone" "$([ "$AFTER_TZ" = "$BEFORE_TZ" ] && echo 1 || echo 0)" "before=$BEFORE_TZ after=$AFTER_TZ"

echo ""
echo "== values core rewrites are not mistaken for refusals =="

OUT=$(call hm-abilities/update-settings DELETE 'input%5Bdate_format%5D=%3Cb%3EY%3C%2Fb%3E-m-d')
echo "$OUT" | grep -q 'hm_abilities_setting_rejected'
check "a rewritten date format is accepted" "$([ $? -ne 0 ] && echo 1 || echo 0)" "$OUT"

STORED=$(wpcli wp option get date_format | tail -1)
check "and the stripped format is stored" "$([ "$STORED" = "Y-m-d" ] && echo 1 || echo 0)" "stored=$STORED"

OUT=$(call hm-abilities/update-settings DELETE 'input%5Btitle%5D=Bits%20%26%20Bobs')
echo "$OUT" | grep -q 'hm_abilities_setting_rejected'
check "a title core re-encodes is accepted" "$([ $? -ne 0 ] && echo 1 || echo 0)" "$OUT"

OUT=$(call hm-abilities/update-settings DELETE 'input%5Btitle%5D=Bits%20%26%20Bobs')
echo "$OUT" | grep -q 'hm_abilities_setting_rejected'
check "writing that same title again is still accepted" "$([ $? -ne 0 ] && echo 1 || echo 0)" "$OUT"

echo ""
echo "== a valid value still applies =="

OUT=$(call hm-abilities/update-settings DELETE 'input%5Btimezone%5D=Europe%2FLisbon')
STORED=$(wpcli wp option get timezone_string | tail -1)
check "a valid timezone applies over REST" "$([ "$STORED" = "Europe/Lisbon" ] && echo 1 || echo 0)" "stored=$STORED"

echo ""
echo "== a read ability answers over REST =="

# The route picks the method from the ability's annotations: read-only wants
# GET, destructive wants DELETE.
OUT=$(call hm-abilities/list-content GET 'input%5Bpost_type%5D=post')
echo "$OUT" | grep -q '"items"'
check "list-content answers over REST" "$([ $? -eq 0 ] && echo 1 || echo 0)" "$(echo "$OUT" | head -c 200)"

TOTAL=$((PASSES + FAILURES))
echo ""
echo "----"

if [ "$FAILURES" -gt 0 ]; then
	echo "$FAILURES of $TOTAL CHECKS FAILED"
	exit 1
fi

echo "ALL $TOTAL CHECKS PASSED"
