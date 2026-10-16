<?php
/**
 * Covers a base64 upload when the temp directory cannot be written to. A file
 * of its own, because WP_TEMP_DIR is a constant and would stay set for every
 * later check. Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

echo "== media: base64 upload with an unwritable temp directory ==\n";

check( 'WP_TEMP_DIR is not already set', ! defined( 'WP_TEMP_DIR' ) );

// get_temp_dir() reads the constant before its static cache, so this applies
// even though WordPress has already resolved a temp directory.
define( 'WP_TEMP_DIR', '/hm-abilities-missing-temp-dir/' );

$data = run( 'hm-abilities/upload-media', [ 'data' => base64_encode( 'hello' ), 'filename' => 'x.txt' ] );
check( 'a base64 upload reports the temp file failure', code( $data ) === 'hm_abilities_temp_file_failed', $data );

summary();
