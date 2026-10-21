<?php
/**
 * Shared helpers for the ability test scripts.
 *
 * Loaded with WP-CLI's --require so the helpers are real includes. The test
 * files themselves run through `wp eval-file`, which evaluates them inside a
 * function scope: top-level variables there are not globals, so the counters
 * live in $GLOBALS rather than in a `global` declaration that would silently
 * bind to nothing.
 *
 * @package HM_Abilities_MCP
 */

declare( strict_types = 1 );

$GLOBALS['hm_failures'] = 0;
$GLOBALS['hm_checks']   = 0;

/**
 * Record one assertion.
 *
 * @param string $label   What is being asserted.
 * @param bool   $ok      Whether it held.
 * @param mixed  $context Value to print when it did not.
 * @return void
 */
function check( string $label, bool $ok, $context = null ): void {
	++$GLOBALS['hm_checks'];

	if ( $ok ) {
		echo "PASS  $label\n";
		return;
	}

	++$GLOBALS['hm_failures'];
	echo "FAIL  $label\n      " . describe( $context ) . "\n";
}

/**
 * Execute an ability by name.
 *
 * @param string               $ability Ability name.
 * @param array<string, mixed> $input   Ability input.
 * @return mixed Ability result, or a WP_Error.
 */
function run( string $ability, array $input ) {
	$object = wp_get_ability( $ability );

	return $object ? $object->execute( $input ) : new WP_Error( 'hm_test_missing_ability', "No ability registered as $ability" );
}

/**
 * Read a key from an ability result without indexing a WP_Error.
 *
 * @param mixed  $value Ability result.
 * @param string $key   Key to read.
 * @return mixed Null when the result is an error or the key is absent.
 */
function field( $value, string $key ) {
	return is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null;
}

/**
 * Render a value for a failure message.
 *
 * @param mixed $value Value to render.
 * @return string
 */
function describe( $value ): string {
	if ( is_wp_error( $value ) ) {
		return 'WP_Error(' . $value->get_error_code() . '): ' . $value->get_error_message();
	}

	return (string) wp_json_encode( $value );
}

/**
 * The error code of a result, or a marker when it is not an error.
 *
 * @param mixed $value Ability result.
 * @return string
 */
function code( $value ): string {
	return is_wp_error( $value ) ? $value->get_error_code() : 'not-an-error';
}

/**
 * Titles from a list ability result.
 *
 * @param mixed $result Ability result.
 * @return string[]
 */
function titles( $result ): array {
	$items = field( $result, 'items' );

	return is_array( $items ) ? array_column( $items, 'title' ) : [];
}

/**
 * Whether a list result contains an item with exactly this title.
 *
 * @param mixed  $result Ability result.
 * @param string $needle Title to look for.
 * @return bool
 */
function leaked( $result, string $needle ): bool {
	return in_array( $needle, titles( $result ), true );
}

/**
 * Print the run summary and exit non-zero when anything failed.
 *
 * @return void
 */
function summary(): void {
	$failed = $GLOBALS['hm_failures'];
	$ran    = $GLOBALS['hm_checks'];

	echo "\n----\n" . ( $failed === 0 ? "ALL $ran CHECKS PASSED\n" : "$failed of $ran CHECKS FAILED\n" );

	if ( $failed > 0 ) {
		exit( 1 );
	}
}
