#!/usr/bin/env bash
#
# Runs the ability tests against the wp-env development site.
#
# Each test file is a WP-CLI script executed with `wp eval-file`, with
# tests/bootstrap.php loaded first via --require so the shared helpers are real
# includes rather than eval'd copies.
#
# Usage: npm test                  (single-site suites)
#        npm test -- read          (only paths matching "read")
#        npm run test:multisite    (network suites; see the warning below)
#
# The database is reset before each single-site file. The tests assert on
# totals and term counts, which a previous file's fixtures would otherwise
# inflate into false failures.
#
# --multisite converts the site to a network. `wp core multisite-convert` writes
# constants into wp-config.php that a database reset does not remove, so a later
# single-site run would otherwise find a config and database that disagree and
# fail with "Site 'localhost:3031/' not found". The runner strips those
# constants again on the way out, so the two modes can be run in either order.

set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIR=/var/www/html/wp-content/plugins/hm-abilities-mcp
MULTISITE=0
FILTER=""

for ARG in "$@"; do
	if [ "$ARG" = "--multisite" ]; then
		MULTISITE=1
	else
		FILTER="$ARG"
	fi
done

if ! docker ps --format '{{.Names}}' | grep -q 'wp-env.*wordpress'; then
	echo "The wp-env site is not running. Start it with: npm run env:start" >&2
	exit 1
fi

# wp-env keeps the site's wp-config.php on the host, which is what makes the
# network conversion reversible.
WP_CONFIG=$(ls "$HOME"/.wp-env/*"$(basename "$PWD")"*/WordPress/wp-config.php 2>/dev/null | head -1)

# Drop the constants `wp core multisite-convert` appended, returning the site to
# single-site. A no-op when they are not there.
unconvert_multisite() {
	if [ -n "$WP_CONFIG" ] && grep -q "define( 'MULTISITE', true );" "$WP_CONFIG" 2>/dev/null; then
		sed -i '' \
			-e "/define( 'WP_ALLOW_MULTISITE', true );/d" \
			-e "/define( 'MULTISITE', true );/d" \
			-e "/define( 'SUBDOMAIN_INSTALL', /d" \
			-e "/define( 'DOMAIN_CURRENT_SITE', /d" \
			-e "/define( 'PATH_CURRENT_SITE', /d" \
			-e "/define( 'SITE_ID_CURRENT_SITE', /d" \
			-e "/define( 'BLOG_ID_CURRENT_SITE', /d" \
			"$WP_CONFIG"
	fi
}

if [ "$MULTISITE" = "1" ]; then
	FILES=(tests/multisite/test-*.php)

	if ! npx @wordpress/env run cli -- wp eval 'echo is_multisite() ? 1 : 0;' 2>/dev/null | grep -q '1'; then
		echo "Converting the environment to a network."
		npx @wordpress/env run cli -- wp core multisite-convert >/dev/null
	fi

	# Leave the site single-site again however this run ends, so the next
	# `npm test` is not left staring at a network config with no network tables.
	trap unconvert_multisite EXIT
else
	FILES=(tests/test-*.php)

	# A previous --multisite run that was killed before its trap fired leaves
	# the constants behind; clear them before resetting the database.
	unconvert_multisite
fi

FAILED=()

for FILE in "${FILES[@]}"; do
	NAME=$(basename "$FILE" .php)

	if [ -n "$FILTER" ] && [[ "$FILE" != *"$FILTER"* ]]; then
		continue
	fi

	echo ""
	echo "=============================================================="
	echo " $NAME"
	echo "=============================================================="

	# Resetting a network would drop the tables the constants still expect, so
	# only the single-site suites get a clean database between files.
	if [ "$MULTISITE" = "0" ]; then
		npx @wordpress/env clean all >/dev/null 2>&1
	fi

	if ! npx @wordpress/env run cli -- \
		wp --require="$PLUGIN_DIR/tests/bootstrap.php" eval-file "$PLUGIN_DIR/$FILE"
	then
		FAILED+=("$NAME")
	fi
done

# The REST suite runs from the host rather than through wp eval-file, because
# WP-CLI loads wp-admin includes that a real MCP request does not.
if [ "$MULTISITE" = "0" ] && { [ -z "$FILTER" ] || [[ "rest-path" == *"$FILTER"* ]]; }; then
	echo ""
	echo "=============================================================="
	echo " rest-path"
	echo "=============================================================="

	npx @wordpress/env clean all >/dev/null 2>&1

	if ! bash tests/rest-path.sh; then
		FAILED+=("rest-path")
	fi
fi

echo ""

if [ ${#FAILED[@]} -gt 0 ]; then
	echo "FAILED: ${FAILED[*]}"
	exit 1
fi

echo "All test files passed."
