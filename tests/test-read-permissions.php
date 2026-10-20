<?php
/**
 * Covers who may read unpublished content, and the author lookup that turns a
 * name into a filterable identifier. Helpers come from tests/bootstrap.php.
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
check( 'total matches the scoped item count', field( $private, 'total' ) === count( titles( $private ) ), $private );

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
check( 'the contributor draft total matches what they can see', field( $c_drafts, 'total' ) === count( titles( $c_drafts ) ), $c_drafts );

wp_set_current_user( $admin );

echo "\n== author lookup: list-users ==\n";

$by_display = run( 'hm-abilities/list-users', [ 'search' => 'Jane' ] );
$found      = is_array( field( $by_display, 'items' ) ) ? array_column( $by_display['items'], 'name' ) : [];
check( 'partial display-name search finds the user', in_array( 'Jane Smith', $found, true ), $by_display );

$lower = run( 'hm-abilities/list-users', [ 'search' => 'jane smith' ] );
check( 'full display name, lowercased, also matches', in_array( 'Jane Smith', array_column( (array) field( $lower, 'items' ), 'name' ), true ), $lower );

$mid = run( 'hm-abilities/list-users', [ 'search' => 'mith' ] );
check( 'a mid-word fragment matches, so search is wildcarded', in_array( 'Jane Smith', array_column( (array) field( $mid, 'items' ), 'name' ), true ), $mid );

$jane_row = null;
foreach ( (array) field( $by_display, 'items' ) as $row ) {
	if ( $row['name'] === 'Jane Smith' ) {
		$jane_row = $row;
	}
}
check( 'the result carries id, name and slug', isset( $jane_row['id'], $jane_row['name'], $jane_row['slug'] ), $jane_row );
check( 'an admin sees roles', ( $jane_row['roles'] ?? [] ) === [ 'editor' ], $jane_row );

// The round trip the colleague asked for: name -> slug -> posts.
$jane_posts = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => $jane_row['slug'], 'per_page' => 50 ] );
check( 'name -> slug -> author filter returns that author\'s posts', field( $jane_posts, 'total' ) === 1 && leaked( $jane_posts, 'Jane on sourdough' ), $jane_posts );

$by_id_posts = run( 'hm-abilities/list-content', [ 'post_type' => 'post', 'author' => $jane_row['id'], 'per_page' => 50 ] );
check( 'the id from list-users works in the author filter too', field( $by_id_posts, 'total' ) === 1, $by_id_posts );

$authors = run( 'hm-abilities/list-users', [ 'has_published' => true, 'per_page' => 100 ] );
$names   = array_column( (array) field( $authors, 'items' ), 'name' );
check( 'has_published lists authors', in_array( 'Jane Smith', $names, true ), $names );
check( 'has_published excludes the contributor who published nothing', ! in_array( 'Casey Contributor', $names, true ), $names );

$roles = run( 'hm-abilities/list-users', [ 'roles' => [ 'editor' ], 'per_page' => 100 ] );
check( 'an admin may filter by role', ! is_wp_error( $roles ) && in_array( 'Jane Smith', array_column( (array) field( $roles, 'items' ), 'name' ), true ), $roles );

$paging = run( 'hm-abilities/list-users', [ 'per_page' => 1, 'page' => 1 ] );
check( 'list-users reports a total beyond the page', (int) field( $paging, 'total' ) > 1 && field( $paging, 'count' ) === 1, $paging );
check( 'list-users sets has_more', field( $paging, 'has_more' ) === true && field( $paging, 'next_page' ) === 2, $paging );

echo "\n== list-users privilege scoping ==\n";
wp_set_current_user( $contributor );

$contrib_roles = run( 'hm-abilities/list-users', [ 'roles' => [ 'administrator' ] ] );
check( 'a contributor may not filter by role', is_wp_error( $contrib_roles ) && $contrib_roles->get_error_code() === 'hm_abilities_cannot_filter_by_role', $contrib_roles );

$contrib_search = run( 'hm-abilities/list-users', [ 'search' => 'Jane' ] );
$contrib_rows   = (array) field( $contrib_search, 'items' );
check( 'a contributor can still find an author by name', in_array( 'Jane Smith', array_column( $contrib_rows, 'name' ), true ), $contrib_search );
check( 'a contributor does not see roles', ! array_key_exists( 'roles', $contrib_rows[0] ?? [] ), $contrib_rows[0] ?? null );

$jane_email   = get_userdata( $editor )->user_email;
$email_probe  = run( 'hm-abilities/list-users', [ 'search' => $jane_email ] );
check( 'a contributor cannot probe users by email address', field( $email_probe, 'total' ) === 0, $email_probe );

wp_set_current_user( $admin );
$admin_email_probe = run( 'hm-abilities/list-users', [ 'search' => $jane_email ] );
check( 'an admin can still find a user by email', (int) field( $admin_email_probe, 'total' ) === 1, $admin_email_probe );

summary();
