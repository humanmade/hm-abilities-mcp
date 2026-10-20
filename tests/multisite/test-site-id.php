<?php
/**
 * Covers the site_id paths on the abilities added for issue #9.
 *
 * Multisite only. Run with `npm run test:multisite`, which converts the
 * environment first. Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

if ( ! is_multisite() ) {
	echo "SKIP  not a multisite install\n";
	return;
}

wp_set_current_user( 1 );

$main = get_current_blog_id();
$sub  = wpmu_create_blog( 'localhost', '/second/', 'Second site', 1 );

if ( is_wp_error( $sub ) ) {
	echo 'FAIL  could not create the subsite: ' . $sub->get_error_message() . "\n";
	exit( 1 );
}

$sub = (int) $sub;

// Fixtures on the main site.
$main_author = wp_insert_user( [
	'user_login'   => 'mainauthor_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Main Site Author',
	'role'         => 'administrator',
] );

$main_post = wp_insert_post( [
	'post_type'   => 'post',
	'post_title'  => 'Main site recipe',
	'post_status' => 'publish',
	'post_author' => $main_author,
] );
$main_term = wp_insert_term( 'main-only-ingredient', 'category' );
wp_set_object_terms( $main_post, [ (int) $main_term['term_id'] ], 'category' );

// Fixtures on the subsite.
switch_to_blog( $sub );
add_user_to_blog( $sub, $main_author, 'administrator' );

$sub_post = wp_insert_post( [
	'post_type'   => 'post',
	'post_title'  => 'Subsite recipe',
	'post_status' => 'publish',
	'post_author' => $main_author,
] );
$sub_term = wp_insert_term( 'subsite-only-ingredient', 'category' );
wp_set_object_terms( $sub_post, [ (int) $sub_term['term_id'] ], 'category' );
restore_current_blog();

echo "== list-content across sites ==\n";

$here = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'per_page' => 50 ] );
check( 'without site_id the current site is listed', leaked( $here, 'Main site recipe' ) && ! leaked( $here, 'Subsite recipe' ), titles( $here ) );

$there = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'site_id' => $sub, 'per_page' => 50 ] );
check( 'site_id lists the subsite', leaked( $there, 'Subsite recipe' ) && ! leaked( $there, 'Main site recipe' ), titles( $there ) );
check( 'the subsite total is its own', field( $there, 'total' ) === count( titles( $there ) ), $there );
check( 'blog context is restored afterwards', get_current_blog_id() === $main, get_current_blog_id() );

$unknown = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'site_id' => 999999 ] );
check( 'an unknown site_id reports the specific error', code( $unknown ) === 'hm_abilities_site_not_found', $unknown );

// The permission callback must still refuse a caller who lacks the capability,
// including one who named a site that does not exist.
$reader = wp_insert_user( [
	'user_login' => 'nocaps_' . wp_rand( 1000, 9999 ),
	'user_pass'  => wp_generate_password(),
	'role'       => 'subscriber',
] );
add_user_to_blog( $sub, $reader, 'subscriber' );
wp_set_current_user( $reader );

$denied = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'site_id' => $sub ] );
check( 'a subscriber is still refused on a real site', code( $denied ) === 'ability_invalid_permissions', $denied );

$denied_bad = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'site_id' => 999999 ] );
check( 'a subscriber naming a bad site still gets no data', is_wp_error( $denied_bad ), $denied_bad );

$denied_write = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Should not exist', 'site_id' => 999999 ] );
check( 'a bad site_id on a write creates nothing', is_wp_error( $denied_write ), $denied_write );

wp_set_current_user( 1 );

echo "\n== list-terms across sites ==\n";

$terms_here = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'category', 'per_page' => 100 ] );
$here_slugs = array_column( (array) field( $terms_here, 'items' ), 'slug' );
check( 'terms come from the current site', in_array( 'main-only-ingredient', $here_slugs, true ) && ! in_array( 'subsite-only-ingredient', $here_slugs, true ), $here_slugs );

$terms_there = run( 'hm-abilities/list-terms', [ 'taxonomy' => 'category', 'site_id' => $sub, 'per_page' => 100 ] );
$there_slugs = array_column( (array) field( $terms_there, 'items' ), 'slug' );
check( 'site_id reads terms from the subsite', in_array( 'subsite-only-ingredient', $there_slugs, true ) && ! in_array( 'main-only-ingredient', $there_slugs, true ), $there_slugs );
check( 'blog context is restored after list-terms', get_current_blog_id() === $main, get_current_blog_id() );

echo "\n== list-users across sites ==\n";

$users_there = run( 'hm-abilities/list-users', [ 'site_id' => $sub, 'per_page' => 100 ] );
check( 'list-users returns the subsite members', in_array( 'Main Site Author', array_column( (array) field( $users_there, 'items' ), 'name' ), true ), $users_there );
check( 'blog context is restored after list-users', get_current_blog_id() === $main, get_current_blog_id() );

$authors_there = run( 'hm-abilities/list-users', [ 'site_id' => $sub, 'has_published' => true, 'per_page' => 100 ] );
check( 'has_published counts posts on the targeted site', in_array( 'Main Site Author', array_column( (array) field( $authors_there, 'items' ), 'name' ), true ), $authors_there );

echo "\n== the term and author filters inside a subsite ==\n";

$filtered = run( 'hm-abilities/list-content', [
	'post_type' => 'post',
	'site_id'   => $sub,
	'terms'     => [ 'category' => [ 'subsite-only-ingredient' ] ],
	'per_page'  => 50,
] );
check( 'the term filter resolves against the subsite', field( $filtered, 'total' ) === 1 && leaked( $filtered, 'Subsite recipe' ), $filtered );

$cross = run( 'hm-abilities/list-content', [
	'post_type' => 'post',
	'site_id'   => $sub,
	'terms'     => [ 'category' => [ 'main-only-ingredient' ] ],
	'per_page'  => 50,
] );
check( 'a main-site term matches nothing on the subsite', field( $cross, 'total' ) === 0, $cross );

$by_author = run( 'hm-abilities/list-content', [
	'post_type' => 'post',
	'site_id'   => $sub,
	'author'    => $main_author,
	'per_page'  => 50,
] );
check( 'the author filter works inside a subsite', field( $by_author, 'total' ) === 1 && leaked( $by_author, 'Subsite recipe' ), $by_author );

echo "\n== get-content across sites ==\n";

$sub_single = run( 'hm-abilities/get-content', [ 'id' => $sub_post, 'site_id' => $sub ] );
check( 'get-content reads a subsite post', field( $sub_single, 'title' ) === 'Subsite recipe', $sub_single );
check( 'blog context is restored after get-content', get_current_blog_id() === $main, get_current_blog_id() );

summary();
