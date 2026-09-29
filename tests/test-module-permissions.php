<?php
/**
 * Covers the per-object read checks in the block-insights and navigation
 * modules. Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

$contributor = wp_insert_user( [
	'user_login'   => 'casey_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Casey Contributor',
	'role'         => 'contributor',
] );

$private_post = wp_insert_post( [
	'post_type'    => 'post',
	'post_title'   => 'CONFIDENTIAL board memo',
	'post_status'  => 'private',
	'post_author'  => 1,
	'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:acme/secret-block /-->',
] );

$draft_page = wp_insert_post( [
	'post_type'    => 'page',
	'post_title'   => 'UNRELEASED pricing page',
	'post_status'  => 'draft',
	'post_author'  => 1,
	'post_name'    => 'unreleased-pricing',
	'post_content' => '<!-- wp:acme/pricing-table /-->',
] );

$public_page = wp_insert_post( [
	'post_type'    => 'page',
	'post_title'   => 'Public page',
	'post_status'  => 'publish',
	'post_author'  => 1,
	'post_name'    => 'public-page',
	'post_content' => '<!-- wp:acme/pricing-table /-->',
] );

echo "== block-usage-stats: unpublished content is scoped to the caller ==\n";
wp_set_current_user( $contributor );

$stats_private = run( 'hm-abilities/block-usage-stats', [ 'status' => 'private' ] );
$names         = array_column( (array) field( $stats_private, 'blocks' ), 'name' );
check( 'a contributor sees no private-post blocks', ! in_array( 'acme/secret-block', $names, true ), $stats_private );
check( 'and no private posts are counted', field( $stats_private, 'post_count' ) === 0, $stats_private );

$stats_any   = run( 'hm-abilities/block-usage-stats', [ 'status' => 'any' ] );
$names_any   = array_column( (array) field( $stats_any, 'blocks' ), 'name' );
check( 'status=any also excludes the private post', ! in_array( 'acme/secret-block', $names_any, true ), $stats_any );
check( 'status=any still counts published content', (int) field( $stats_any, 'post_count' ) > 0, $stats_any );

echo "\n== get-page-blocks: per-post read check ==\n";

$blocked_private = run( 'hm-abilities/get-page-blocks', [ 'page_id' => $private_post ] );
check( "a contributor cannot read another user's private post", code( $blocked_private ) === 'hm_abilities_forbidden', $blocked_private );

$blocked_draft = run( 'hm-abilities/get-page-blocks', [ 'page_id' => $draft_page ] );
check( "a contributor cannot read another user's draft", code( $blocked_draft ) === 'hm_abilities_forbidden', $blocked_draft );

$allowed_public = run( 'hm-abilities/get-page-blocks', [ 'page_id' => $public_page ] );
check( 'a published page is still readable', field( $allowed_public, 'post' )['id'] === $public_page, $allowed_public );

echo "\n== find-missing-block: an unreadable page reads as absent ==\n";

$hidden = run( 'hm-abilities/find-missing-block', [ 'block_name' => 'acme/pricing-table', 'path' => 'unreleased-pricing' ] );
$ids    = array_column( array_merge( (array) field( $hidden, 'present' ), (array) field( $hidden, 'missing' ) ), 'page_id' );
check( 'the draft page id is not disclosed', ! in_array( $draft_page, $ids, true ), $hidden );
check( 'and it is not reported as present', (int) field( $hidden, 'present_count' ) === 0, $hidden );

$visible = run( 'hm-abilities/find-missing-block', [ 'block_name' => 'acme/pricing-table', 'path' => 'public-page' ] );
check( 'a published page at a path is still found', (int) field( $visible, 'present_count' ) === 1, $visible );

echo "\n== administrator regression ==\n";
wp_set_current_user( 1 );

$admin_stats = run( 'hm-abilities/block-usage-stats', [ 'status' => 'private' ] );
check( 'an admin still sees private-post blocks', in_array( 'acme/secret-block', array_column( (array) field( $admin_stats, 'blocks' ), 'name' ), true ), $admin_stats );

$admin_page = run( 'hm-abilities/get-page-blocks', [ 'page_id' => $private_post ] );
check( 'an admin still reads the private post', field( $admin_page, 'post' )['id'] === $private_post, $admin_page );

$admin_find = run( 'hm-abilities/find-missing-block', [ 'block_name' => 'acme/pricing-table', 'path' => 'unreleased-pricing' ] );
check( 'an admin still resolves the draft page by path', (int) field( $admin_find, 'present_count' ) === 1, $admin_find );

echo "\n== navigation: trashed menus ==\n";

$menu = wp_insert_post( [
	'post_type'    => 'wp_navigation',
	'post_title'   => 'Primary',
	'post_status'  => 'publish',
	'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/","kind":"custom"} /-->',
] );

$live_read = run( 'hm-abilities/get-navigation', [ 'id' => $menu ] );
check( 'a live menu is still readable', field( $live_read, 'id' ) === $menu, $live_read );

wp_trash_post( $menu );

$trashed_read = run( 'hm-abilities/get-navigation', [ 'id' => $menu ] );
check( 'a trashed menu is not readable', is_wp_error( $trashed_read ), $trashed_read );

summary();
