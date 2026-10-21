<?php
/**
 * Covers block-usage-stats' scan at site scope: the result says when a scan
 * was cut short, hm_abilities_block_usage_scan_limit moves the limit, and the
 * scan counts every post across batches without caching them. The filter keeps
 * the fixtures small. Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

use const HM\Abilities\Block_Insights\SCAN_BATCH;
use const HM\Abilities\Block_Insights\SCAN_LIMIT;

wp_set_current_user( 1 );

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

$limit     = 3;
$seen      = [];
$set_limit = static function ( $default_limit, $scope ) use ( &$limit, &$seen ) {
	$seen = [ $default_limit, $scope ];

	return $limit;
};
add_filter( 'hm_abilities_block_usage_scan_limit', $set_limit, 10, 2 );

echo "== at the limit ==\n";

$add_posts( $limit - $published() );
check( 'baseline: exactly the limit in published posts', $published() === $limit, $published() );

$full = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'the filter receives the default limit and the scope', $seen === [ SCAN_LIMIT, 'site' ], $seen );
check( 'every post is scanned', field( $full, 'post_count' ) === $limit, $full );
check( 'and the result is not truncated', field( $full, 'truncated' ) === false, $full );

echo "\n== one past the limit ==\n";

$add_posts( 1 );

$cut = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'the scan stops at the limit', field( $cut, 'post_count' ) === $limit, $cut );
check( 'and the result says so', field( $cut, 'truncated' ) === true, $cut );

echo "\n== a raised limit ==\n";

$limit = 10;

$raised = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'every post is scanned again', field( $raised, 'post_count' ) === $published(), $raised );
check( 'and the result is not truncated', field( $raised, 'truncated' ) === false, $raised );

echo "\n== more posts than one batch ==\n";

$limit = SCAN_BATCH * 3;
$add_posts( SCAN_BATCH + 5 - $published() );

// Every fixture, and the default "Hello world!" post, holds one paragraph.
$batched    = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
$paragraphs = array_column( (array) field( $batched, 'blocks' ), 'count', 'name' )['core/paragraph'] ?? null;
check( 'every post is scanned', field( $batched, 'post_count' ) === $published(), $batched );
check( 'and every post is tallied', $paragraphs === $published(), [ $paragraphs, $published() ] );

echo "\n== the scan leaves nothing cached ==\n";

$sample = (int) get_posts( [ 'post_type' => 'post', 'posts_per_page' => 1, 'fields' => 'ids' ] )[0];
clean_post_cache( $sample );

run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
check( 'no post object is cached', wp_cache_get( $sample, 'posts' ) === false, $sample );
check( 'no post meta is cached', wp_cache_get( $sample, 'post_meta' ) === false, $sample );

echo "\n== no limit ==\n";

// The fixtures number fewer than SCAN_LIMIT, so the ID query's own page size is
// what shows the scan asked for every post rather than some large cap.
$id_page   = null;
$see_query = static function ( WP_Query $query ) use ( &$id_page ): void {
	if ( $query->get( 'fields' ) === 'ids' ) {
		$id_page = $query->get( 'posts_per_page' );
	}
};
add_action( 'pre_get_posts', $see_query );

// -1 is WP_Query's "all". PHP_INT_MAX overflowed the extra row that detects
// truncation, and a float past it wrapped negative when cast.
foreach ( [ -1, PHP_INT_MAX, PHP_INT_MAX + 1, INF ] as $limit ) {
	$id_page = null;
	$all     = run( 'hm-abilities/block-usage-stats', [ 'post_type' => 'post' ] );
	check( "a limit of $limit queries every post", $id_page === -1, $id_page );
	check( 'and scans every post', field( $all, 'post_count' ) === $published(), $all );
	check( 'and the result is not truncated', field( $all, 'truncated' ) === false, $all );
}

remove_action( 'pre_get_posts', $see_query );

remove_filter( 'hm_abilities_block_usage_scan_limit', $set_limit, 10 );

summary();
