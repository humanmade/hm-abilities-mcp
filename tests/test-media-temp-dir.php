<?php
/**
 * Covers the media import's temp file, in a writable then an unwritable temp
 * directory. A file of its own, because WP_TEMP_DIR is a constant and would stay
 * set for every later check.
 * Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

require_once ABSPATH . 'wp-admin/includes/file.php';

echo "== media: writable temp directory ==\n";

check( 'WP_TEMP_DIR is not already set', ! defined( 'WP_TEMP_DIR' ) );

$created = HM\Abilities\Media\create_temp_file( 'https://example.com/x.png' );
check( 'a writable temp directory gives an existing file', is_string( $created ) && file_exists( $created ), $created );

if ( is_string( $created ) ) {
	wp_delete_file( $created );
}

echo "\n== media: unwritable temp directory ==\n";

// get_temp_dir() reads the constant before its static cache, so this applies
// even though WordPress has already resolved a temp directory.
define( 'WP_TEMP_DIR', '/hm-abilities-missing-temp-dir/' );

// Called directly: through the ability, the source screen needs a fetchable public host.
$url = HM\Abilities\Media\download_to_temp( 'https://example.com/x.png' );
check( 'a URL download reports the temp file failure before fetching', code( $url ) === 'hm_abilities_temp_file_failed', $url );

summary();
