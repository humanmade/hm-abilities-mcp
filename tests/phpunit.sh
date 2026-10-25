#!/usr/bin/env bash
#
# Runs the PHPUnit suites in the wp-env test environment, which is configured
# by .wp-env.test.json and kept apart from the development site's database.
#
# Usage: npm run env:test:start    (once, to start the test environment)
#        npm run test:wp
#        npm run test:wp -- --filter=Some_Test
#
# PHPUnit 9 exits 0 when the testsuite matches no files, so a suite pointed at
# the wrong directory would pass without running anything. "No tests executed!"
# is treated as a failure.

set -euo pipefail

cd "$(dirname "$0")/.."

OUTPUT=$(mktemp)
trap 'rm -f "$OUTPUT"' EXIT

npx @wordpress/env run cli --config=.wp-env.test.json \
	--env-cwd=wp-content/plugins/hm-abilities-mcp \
	-- vendor/bin/phpunit "$@" | tee "$OUTPUT"

if grep -q 'No tests executed!' "$OUTPUT"; then
	echo "PHPUnit ran no tests. Check the testsuite in phpunit.xml.dist." >&2
	exit 1
fi
