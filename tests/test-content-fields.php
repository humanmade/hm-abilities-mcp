<?php
/**
 * Covers the opt-in fields, totals, multi-type listing, and the term and
 * author filters. Helpers come from tests/bootstrap.php; see tests/run.sh.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

// --- Fixtures -------------------------------------------------------------

register_taxonomy( 'ingredient', [ 'post' ], [ 'public' => true, 'label' => 'Ingredients', 'hierarchical' => false ] );
register_taxonomy( 'internal_state', [ 'post' ], [ 'public' => false, 'label' => 'Internal state', 'hierarchical' => false ] );

$author_a = wp_insert_user( [ 'user_login' => 'ada_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'display_name' => 'Ada Lovelace', 'role' => 'author' ] );
$author_b = wp_insert_user( [ 'user_login' => 'grace_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'display_name' => 'Grace Hopper', 'role' => 'author' ] );

$christmas = wp_insert_term( 'Christmas ' . wp_rand( 100, 999 ), 'category' );
$gochujang = wp_insert_term( 'gochujang ' . wp_rand( 100, 999 ), 'ingredient' );
$miso      = wp_insert_term( 'white miso paste ' . wp_rand( 100, 999 ), 'ingredient' );
$secret    = wp_insert_term( 'needs-legal ' . wp_rand( 100, 999 ), 'internal_state' );

$goch_id = (int) $christmas['term_id'];
$cat_slug  = get_term( (int) $christmas['term_id'] )->slug;
$goch_slug = get_term( (int) $gochujang['term_id'] )->slug;
$miso_slug = get_term( (int) $miso['term_id'] )->slug;

$old_post = wp_insert_post( [
	'post_type'    => 'post',
	'post_title'   => 'Kimchi stew',
	'post_status'  => 'publish',
	'post_author'  => $author_a,
	'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ),
	'post_content' => 'old',
] );
wp_set_object_terms( $old_post, [ (int) $christmas['term_id'] ], 'category' );
wp_set_object_terms( $old_post, [ (int) $gochujang['term_id'], (int) $miso['term_id'] ], 'ingredient' );
wp_set_object_terms( $old_post, [ (int) $secret['term_id'] ], 'internal_state' );

$new_post = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Gochujang noodles', 'post_status' => 'publish', 'post_author' => $author_b ] );
wp_set_object_terms( $new_post, [ (int) $christmas['term_id'] ], 'category' );
wp_set_object_terms( $new_post, [ (int) $gochujang['term_id'] ], 'ingredient' );

wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'About the kitchen', 'post_status' => 'publish', 'post_author' => $author_a ] );

for ( $i = 0; $i < 25; $i++ ) {
	wp_insert_post( [ 'post_type' => 'post', 'post_title' => "Filler $i", 'post_status' => 'publish', 'post_author' => $author_b ] );
}

for ( $i = 0; $i < 30; $i++ ) {
	wp_insert_term( "bulk ingredient $i " . wp_rand( 1000, 9999 ), 'ingredient' );
}

echo "\n== format_post: always-on author and dates ==\n";

$one = run( 'hm-abilities/get-content', [ 'id' => $old_post ] );
check( 'get-content returns author id/name/slug', field( $one, 'author' ) !== null && ( field( $one, 'author' )['name'] ?? '' ) === 'Ada Lovelace' && ! empty( field( $one, 'author' )['slug'] ), $one );
check( 'get-content returns an ISO 8601 date', (bool) preg_match( '/^\d{4}-\d{2}-\d{2}T/', (string) field( $one, 'date' ) ), $one );
check( 'get-content returns modified', ! empty( field( $one, 'modified' ) ), $one );
check( 'get-content still returns content', is_array( $one ) && array_key_exists( 'content', $one ), $one );
check( 'terms absent without fields', is_array( $one ) && ! array_key_exists( 'terms', $one ), $one );

$one_terms = run( 'hm-abilities/get-content', [ 'id' => $old_post, 'fields' => [ 'terms' ] ] );
$terms_out = field( $one_terms, 'terms' );
check( 'fields=terms adds terms', is_array( $terms_out ) && count( $terms_out['ingredient'] ?? [] ) === 2, $one_terms );
check( 'category comes through too', isset( $terms_out['category'][0]['slug'] ) && $terms_out['category'][0]['slug'] === $cat_slug, $terms_out );
check( 'non-public taxonomy excluded', is_array( $terms_out ) && ! isset( $terms_out['internal_state'] ), $terms_out );

$opt_in = static function ( $taxonomies ) {
	$taxonomies[] = 'internal_state';
	return $taxonomies;
};
add_filter( 'hm_abilities_taxonomies', $opt_in );
$opted = run( 'hm-abilities/get-content', [ 'id' => $old_post, 'fields' => [ 'terms' ] ] );
check( 'hm_abilities_taxonomies opts a private taxonomy in', isset( field( $opted, 'terms' )['internal_state'] ), field( $opted, 'terms' ) );
remove_filter( 'hm_abilities_taxonomies', $opt_in );

echo "\n== list-content: total and post_type array ==\n";

$page1 = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'per_page' => 5, 'page' => 1 ] );
check( 'count is the page size', field( $page1, 'count' ) === 5, $page1 );
check( 'total exceeds the page size', (int) field( $page1, 'total' ) > 5, $page1 );
check( 'total matches a direct found_posts', field( $page1, 'total' ) === ( new WP_Query( [ 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 5 ] ) )->found_posts, $page1 );
check( 'has_more and next_page are set', field( $page1, 'has_more' ) === true && field( $page1, 'next_page' ) === 2, $page1 );

$both  = run( 'hm-abilities/list-content', [ 'post_type' => [ 'post', 'page' ], 'per_page' => 100 ] );
$types = is_array( field( $both, 'items' ) ) ? array_unique( array_column( $both['items'], 'type' ) ) : [];
sort( $types );
check( 'post_type array returns both types', $types === [ 'page', 'post' ], $types );

$single = run( 'hm-abilities/list-content', [ 'post_type' => 'page' ] );
check( 'post_type string still works', (int) field( $single, 'count' ) > 0, $single );

$bad_type = run( 'hm-abilities/list-content', [ 'post_type' => [ 'post', 'nonsense' ] ] );
check( 'unknown post_type in an array errors', is_wp_error( $bad_type ), $bad_type );

$bad_single = run( 'hm-abilities/list-content', [ 'post_type' => 'nonsense' ] );
check( 'unknown post_type as a string errors', is_wp_error( $bad_single ), $bad_single );

echo "\n== list-content: author filter ==\n";

$by_id = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => $author_a, 'per_page' => 100 ] );
check( 'author filter by ID', field( $by_id, 'total' ) === 1 && ( $by_id['items'][0]['id'] ?? 0 ) === $old_post, $by_id );

$slug    = get_userdata( $author_a )->user_nicename;
$by_slug = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => $slug, 'per_page' => 100 ] );
check( 'author filter by slug', field( $by_slug, 'total' ) === 1, $by_slug );

$by_other = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => $author_b, 'per_page' => 100 ] );
check( 'the other author gets the other posts', field( $by_other, 'total' ) === 26, $by_other );

$bad_author = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => 'nobody-here' ] );
check( 'unknown author errors', code( $bad_author ) === 'hm_abilities_author_not_found', $bad_author );

echo "\n== list-content: term filter ==\n";

$by_id_term = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => [ (int) $gochujang['term_id'] ] ], 'terms_field' => 'id', 'per_page' => 100 ] );
check( 'term filter by integer id matches both posts', field( $by_id_term, 'total' ) === 2, $by_id_term );

$by_slug_term = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => [ $miso_slug ] ], 'per_page' => 100 ] );
check( 'term filter by slug narrows to one', field( $by_slug_term, 'total' ) === 1 && ( $by_slug_term['items'][0]['id'] ?? 0 ) === $old_post, $by_slug_term );

$bare = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => $miso_slug ], 'per_page' => 100 ] );
check( 'a bare term string works as well as a list', field( $bare, 'total' ) === 1, $bare );

$or_terms = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => [ $goch_slug, $miso_slug ] ], 'per_page' => 100 ] );
check( 'terms within one taxonomy OR together', field( $or_terms, 'total' ) === 2, $or_terms );

$and_tax = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'category' => [ $cat_slug ], 'ingredient' => [ $miso_slug ] ], 'per_page' => 100 ] );
check( 'separate taxonomies AND together', field( $and_tax, 'total' ) === 1, $and_tax );

$by_name = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => [ get_term( (int) $miso['term_id'] )->name ] ], 'terms_field' => 'name', 'per_page' => 100 ] );
check( 'terms_field=name works', field( $by_name, 'total' ) === 1, $by_name );

$term_and_author = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'ingredient' => [ $goch_slug ] ], 'author' => $author_b, 'per_page' => 100 ] );
check( 'term and author filters combine', field( $term_and_author, 'total' ) === 1 && ( $term_and_author['items'][0]['id'] ?? 0 ) === $new_post, $term_and_author );

$bad_tax = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'terms' => [ 'internal_state' => [ 'x' ] ] ] );
check( 'unavailable taxonomy errors', code( $bad_tax ) === 'hm_abilities_invalid_taxonomy', $bad_tax );

echo "\n== list-terms ==\n";

$discovery = run( 'hm-abilities/list-terms', [] );
$tax_list  = field( $discovery, 'taxonomies' );
$names     = is_array( $tax_list ) ? array_column( $tax_list, 'name' ) : [];
check( 'discovery lists public taxonomies', in_array( 'ingredient', $names, true ) && in_array( 'category', $names, true ), $names );
check( 'discovery hides non-public taxonomies', ! in_array( 'internal_state', $names, true ), $names );

$ingredient_row = is_array( $tax_list ) ? ( array_values( array_filter( $tax_list, static fn ( $t ) => $t['name'] === 'ingredient' ) )[0] ?? [] ) : [];
check( 'discovery reports total_terms', (int) ( $ingredient_row['total_terms'] ?? 0 ) >= 32, $ingredient_row );
check( 'discovery reports the attached post types', ( $ingredient_row['post_types'] ?? [] ) === [ 'post' ], $ingredient_row );
check( 'discovery returns no items', field( $discovery, 'items' ) === [] && field( $discovery, 'taxonomy' ) === null, $discovery );

$search = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'ingredient', 'search' => 'miso' ] );
check( 'search finds the real term from a loose word', field( $search, 'total' ) === 1 && str_contains( (string) ( $search['items'][0]['name'] ?? '' ), 'white miso paste' ), $search );
check( 'search result carries the slug the filter needs', ( $search['items'][0]['slug'] ?? '' ) === $miso_slug, field( $search, 'items' ) );

$paged = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'ingredient', 'per_page' => 10, 'page' => 1 ] );
check( 'term paging reports the full total', field( $paged, 'count' ) === 10 && (int) field( $paged, 'total' ) >= 32, $paged );
check( 'term paging sets has_more', field( $paged, 'has_more' ) === true && field( $paged, 'next_page' ) === 2, $paged );

$page2 = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'ingredient', 'per_page' => 10, 'page' => 2 ] );
$ids1  = array_column( (array) field( $paged, 'items' ), 'id' );
$ids2  = array_column( (array) field( $page2, 'items' ), 'id' );
check( 'page 2 returns different terms', $ids1 !== [] && $ids2 !== [] && array_intersect( $ids1, $ids2 ) === [], [ $ids1, $ids2 ] );

$by_count = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'ingredient', 'orderby' => 'count', 'per_page' => 3 ] );
$counts   = array_column( (array) field( $by_count, 'items' ), 'count' );
$sorted   = $counts;
rsort( $sorted );
check( 'orderby=count is descending', $counts !== [] && $counts === $sorted, $counts );

$hide_empty = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'ingredient', 'hide_empty' => true, 'per_page' => 100 ] );
check( 'hide_empty drops the unused bulk terms', field( $hide_empty, 'total' ) === 2, $hide_empty );

$bad_tax_terms = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'internal_state' ] );
check( 'list-terms rejects a hidden taxonomy', code( $bad_tax_terms ) === 'hm_abilities_invalid_taxonomy', $bad_tax_terms );

echo "\n== list-recent-articles ==\n";

$recent = run( 'hm-abilities/list-recent-articles', [ 'after' => '-1 day', 'per_page' => 5 ] );
check( 'recent articles now carry dates', ! empty( $recent['items'][0]['date'] ?? '' ), field( $recent, 'items' ) );
check( 'recent articles carry an author', ! empty( $recent['items'][0]['author']['name'] ?? '' ), field( $recent, 'items' ) );
check( 'recent articles report a total', (int) field( $recent, 'total' ) > 5, $recent );
check( 'recent articles exclude the two-year-old post', ! in_array( $old_post, array_column( (array) field( $recent, 'items' ), 'id' ), true ), field( $recent, 'items' ) );

$audit = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'per_page' => 100 ] );
$stale = is_array( field( $audit, 'items' ) ) ? array_filter( $audit['items'], static fn ( $i ) => strtotime( $i['modified'] ) < strtotime( '-1 year' ) ) : [];
check( 'modified date supports a staleness audit', count( $stale ) === 1, count( $stale ) );

echo "\n== query cost ==\n";

wp_cache_flush();
$before = $GLOBALS['wpdb']->num_queries;
$bulk   = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'per_page' => 20, 'fields' => [ 'terms' ] ] );
$cost   = $GLOBALS['wpdb']->num_queries - $before;
check( 'terms on a 20-item page stays under one query per post', $cost < 20, "queries=$cost items=" . describe( field( $bulk, 'count' ) ) );

wp_cache_flush();
$before_plain = $GLOBALS['wpdb']->num_queries;
run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'per_page' => 20 ] );
$plain_cost = $GLOBALS['wpdb']->num_queries - $before_plain;
echo "INFO  20 items without terms: $plain_cost queries; with terms: $cost queries\n";

summary();
