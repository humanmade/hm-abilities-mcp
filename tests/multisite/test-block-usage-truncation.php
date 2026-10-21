<?php
/**
 * Covers block-usage-stats reporting a truncated subsite at network scope.
 *
 * Multisite only. Run with `npm run test:multisite`, which converts the
 * environment first. Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

use const HM\Abilities\Block_Insights\SCAN_LIMIT;

if ( ! is_multisite() ) {
	echo "SKIP  not a multisite install\n";
	return;
}

wp_set_current_user( 1 );
wp_defer_term_counting( true );

$main = get_current_blog_id();
$big  = get_blog_id_from_url( 'localhost', '/scan-cut/' ) ?: wpmu_create_blog( 'localhost', '/scan-cut/', 'Scan cut', 1 );

if ( is_wp_error( $big ) ) {
	echo 'FAIL  could not create the subsite: ' . $big->get_error_message() . "\n";
	exit( 1 );
}

$big = (int) $big;

switch_to_blog( $big );
for ( $i = (int) wp_count_posts( 'post' )->publish; $i <= SCAN_LIMIT; $i++ ) {
	wp_insert_post( [
		'post_type'    => 'post',
		'post_title'   => "Scan fixture $i",
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
	] );
}
check( 'baseline: the subsite holds more than SCAN_LIMIT posts', (int) wp_count_posts( 'post' )->publish > SCAN_LIMIT, wp_count_posts( 'post' )->publish );
restore_current_blog();

echo "\n== network scope ==\n";

$stats = run( 'hm-abilities/block-usage-stats', [ 'scope' => 'network', 'site_ids' => [ $big, $main ], 'post_type' => 'post' ] );
$rows  = array_column( (array) field( $stats, 'sites' ), 'truncated', 'site_id' );

check( 'the large subsite is flagged in its own row', ( $rows[ $big ] ?? null ) === true, $rows );
check( 'the main site is not', ( $rows[ $main ] ?? null ) === false, $rows );
check( 'and the response as a whole says it is truncated', field( $stats, 'truncated' ) === true, $stats );

$main_only = run( 'hm-abilities/block-usage-stats', [ 'scope' => 'network', 'site_ids' => [ $main ], 'post_type' => 'post' ] );
check( 'a page of untruncated sites is not', field( $main_only, 'truncated' ) === false, $main_only );

summary();
