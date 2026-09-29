<?php
/**
 * Covers who may read unpublished content through the content abilities.
 * Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

$admin = 1;

$contributor = wp_insert_user( [
	'user_login'   => 'contrib_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Casey Contributor',
	'role'         => 'contributor',
] );

$editor = wp_insert_user( [
	'user_login'   => 'jsmith_' . wp_rand( 1000, 9999 ),
	'user_pass'    => wp_generate_password(),
	'display_name' => 'Jane Smith',
	'user_email'   => 'jane_' . wp_rand( 1000, 9999 ) . '@example.com',
	'role'         => 'editor',
] );

$secret = wp_insert_post( [
	'post_type'    => 'post',
	'post_title'   => 'Board pay review SECRET',
	'post_status'  => 'private',
	'post_author'  => $admin,
	'post_content' => 'confidential',
] );

$own_private = wp_insert_post( [
	'post_type'    => 'post',
	'post_title'   => 'Casey own private note',
	'post_status'  => 'private',
	'post_author'  => $contributor,
	'post_content' => 'mine',
] );

$admin_draft = wp_insert_post( [
	'post_type'    => 'post',
	'post_title'   => 'Unannounced layoffs DRAFT',
	'post_status'  => 'draft',
	'post_author'  => $admin,
	'post_content' => 'not public yet',
] );

$admin_pending = wp_insert_post( [
	'post_type'   => 'post',
	'post_title'  => 'Awaiting review PENDING',
	'post_status' => 'pending',
	'post_author' => $admin,
] );

$jane_post = wp_insert_post( [
	'post_type'   => 'post',
	'post_title'  => 'Jane on sourdough',
	'post_status' => 'publish',
	'post_author' => $editor,
] );

echo "== as a Contributor (edit_posts, no read_private_posts) ==\n";
wp_set_current_user( $contributor );

check( 'baseline: cannot read_post the admin private post', ! current_user_can( 'read_post', $secret ), current_user_can( 'read_post', $secret ) );

$private = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'private', 'per_page' => 50 ] );
check( "status=private no longer leaks another user's private post", ! leaked( $private, 'Board pay review SECRET' ), $private );
check( 'status=private still returns the contributor\'s own private post', leaked( $private, 'Casey own private note' ), titles( $private ) );

$any = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'any', 'per_page' => 100 ] );
check( 'status=any no longer leaks the private post', ! leaked( $any, 'Board pay review SECRET' ), titles( $any ) );
check( 'status=any still returns published posts', leaked( $any, 'Jane on sourdough' ), titles( $any ) );

echo '  (diagnostic) can read_post admin draft: ' . var_export( current_user_can( 'read_post', $admin_draft ), true ) . "\n";
$drafts = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'draft', 'per_page' => 50 ] );
check( "status=draft does not leak another user's draft", ! leaked( $drafts, 'Unannounced layoffs DRAFT' ), titles( $drafts ) );
$pending = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'pending', 'per_page' => 50 ] );
check( "status=pending does not leak another user's pending post", ! leaked( $pending, 'Awaiting review PENDING' ), titles( $pending ) );
check( 'status=any does not leak another user\'s draft', ! leaked( $any, 'Unannounced layoffs DRAFT' ), titles( $any ) );
$draft_body = run( 'hm-abilities/get-content', [ 'id' => $admin_draft ] );
check( "get-content does not return another user's draft", is_wp_error( $draft_body ), $draft_body );

$single = run( 'hm-abilities/get-content', [ 'id' => $secret ] );
check( 'get-content on another user\'s private post is now forbidden', is_wp_error( $single ) && $single->get_error_code() === 'hm_abilities_forbidden', $single );

$own = run( 'hm-abilities/get-content', [ 'id' => $own_private ] );
check( 'get-content on the caller\'s own private post still works', field( $own, 'content' ) === 'mine', $own );

$published = run( 'hm-abilities/get-content', [ 'id' => $jane_post ] );
check( 'get-content on a published post still works', ! is_wp_error( $published ), $published );

echo "\n== as an Administrator (regression) ==\n";
wp_set_current_user( $admin );

$admin_private = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'private', 'per_page' => 50 ] );
check( 'an admin still sees every private post', leaked( $admin_private, 'Board pay review SECRET' ) && leaked( $admin_private, 'Casey own private note' ), titles( $admin_private ) );

$admin_single = run( 'hm-abilities/get-content', [ 'id' => $secret ] );
check( 'an admin still reads the private post body', field( $admin_single, 'content' ) === 'confidential', $admin_single );

$admin_any = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'any', 'per_page' => 100 ] );
check( 'an admin status=any still includes private posts', leaked( $admin_any, 'Board pay review SECRET' ), titles( $admin_any ) );

echo "\n== as an Editor (edit_others_posts) — must NOT be over-restricted ==\n";
wp_set_current_user( $editor );

$ed_drafts = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'draft', 'per_page' => 50 ] );
check( "an editor still sees another user's draft", leaked( $ed_drafts, 'Unannounced layoffs DRAFT' ), titles( $ed_drafts ) );

$ed_private = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'private', 'per_page' => 50 ] );
check( 'an editor still sees private posts', leaked( $ed_private, 'Board pay review SECRET' ), titles( $ed_private ) );

$ed_any = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'any', 'per_page' => 100 ] );
check( 'an editor status=any still includes drafts', leaked( $ed_any, 'Unannounced layoffs DRAFT' ), titles( $ed_any ) );
check( 'an editor status=any still includes private posts', leaked( $ed_any, 'Board pay review SECRET' ), titles( $ed_any ) );

$ed_body = run( 'hm-abilities/get-content', [ 'id' => $admin_draft ] );
check( "an editor still reads another user's draft body", field( $ed_body, 'content' ) === 'not public yet', $ed_body );

echo "\n== a Contributor reaching their own unpublished work ==\n";
wp_set_current_user( $contributor );

$own_draft = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Casey own draft', 'post_status' => 'draft', 'post_author' => $contributor ] );
$c_drafts  = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'status' => 'draft', 'per_page' => 50 ] );
check( 'a contributor still sees their own draft', leaked( $c_drafts, 'Casey own draft' ), titles( $c_drafts ) );
check( "a contributor still does not see the admin's draft", ! leaked( $c_drafts, 'Unannounced layoffs DRAFT' ), titles( $c_drafts ) );

wp_set_current_user( $admin );

summary();
