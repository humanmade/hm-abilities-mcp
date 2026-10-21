<?php
/**
 * Covers block-usage-stats reporting a scan cut short at SCAN_LIMIT posts at
 * site scope, where a caller otherwise reads partial counts as site totals.
 * Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

use const HM\Abilities\Block_Insights\SCAN_LIMIT;

wp_set_current_user( 1 );
wp_defer_term_counting( true );

$add_posts = static function ( int $count ): void {
	for ( $i = 0; $i < $count; $i++ ) {
		wp_insert_post( [
			'post_type'    => 'post',
			'post_title'   => "Scan fixture $i",
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
		] );
	}
};

$published = static fn (): int => (int) wp_count_posts( 'post' )->publish;

echo "== at the limit ==\n";

$add_posts( SCAN_LIMIT - $published() );
check( 'baseline: exactly SCAN_LIMIT published posts', $published() === SCAN_LIMIT, $published() );

$full = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'every post is scanned', field( $full, 'post_count' ) === SCAN_LIMIT, $full );
check( 'and the result is not truncated', field( $full, 'truncated' ) === false, $full );

echo "\n== one past the limit ==\n";

$add_posts( 1 );

$cut = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'the scan stops at SCAN_LIMIT', field( $cut, 'post_count' ) === SCAN_LIMIT, $cut );
check( 'and the result says so', field( $cut, 'truncated' ) === true, $cut );

summary();
