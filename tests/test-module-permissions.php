<?php
/**
 * Covers the per-object and bounding fixes across the media, navigation,
 * blocks and block-insights modules. Helpers come from tests/bootstrap.php.
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

$before  = get_post( $menu )->post_content;
$trashed_write = run( 'hm-abilities/update-navigation', [ 'id' => $menu, 'items' => [ [ 'label' => 'Overwritten', 'url' => '/x', 'kind' => 'custom' ] ] ] );
check( 'a trashed menu is not writable', is_wp_error( $trashed_write ), $trashed_write );
check( 'and its content is untouched', get_post( $menu )->post_content === $before, get_post( $menu )->post_content );

echo "\n== navigation: depth bound ==\n";

wp_untrash_post( $menu );
wp_update_post( [ 'ID' => $menu, 'post_status' => 'publish' ] );

// Six levels of children, one deeper than MAX_NAV_DEPTH allows.
$deep = [ 'label' => 'L0', 'url' => '/', 'kind' => 'custom' ];
for ( $i = 1; $i <= 6; $i++ ) {
	$deep = [ 'label' => "L$i", 'url' => '/', 'kind' => 'custom', 'children' => [ $deep ] ];
}

$deep_write = run( 'hm-abilities/update-navigation', [ 'id' => $menu, 'items' => [ $deep ] ] );
check( 'a too-deep menu is accepted without exhausting the stack', ! is_wp_error( $deep_write ), $deep_write );
check( 'and the deepest level is dropped rather than serialized', ! str_contains( get_post( $menu )->post_content, '"label":"L0"' ), get_post( $menu )->post_content );

echo "\n== blocks: size and depth bounds ==\n";

$too_big = run( 'hm-abilities/validate-blocks', [ 'content' => str_repeat( 'a', 262145 ) ] );
check( 'markup beyond the size cap is rejected', is_wp_error( $too_big ), code( $too_big ) );

$ok_small = run( 'hm-abilities/validate-blocks', [ 'content' => '<!-- wp:acme/nope /-->' ] );
check( 'ordinary markup still validates', in_array( 'acme/nope', (array) field( $ok_small, 'invalid' ) ?: [], true ) || ! is_wp_error( $ok_small ), $ok_small );
check( 'and reports truncated=false', field( $ok_small, 'truncated' ) === false, $ok_small );

$nested = str_repeat( '<!-- wp:group -->', 60 ) . '<!-- wp:acme/deep /-->' . str_repeat( '<!-- /wp:group -->', 60 );
$deep_blocks = run( 'hm-abilities/validate-blocks', [ 'content' => $nested ] );
check( 'deeply nested markup returns instead of exhausting the stack', ! is_wp_error( $deep_blocks ), $deep_blocks );
check( 'and flags the result as truncated', field( $deep_blocks, 'truncated' ) === true, $deep_blocks );

echo "\n== media: source screening ==\n";

$loopback = run( 'hm-abilities/upload-media', [ 'source_url' => 'http://127.0.0.1/x.png' ] );
check( 'a loopback source is refused', is_wp_error( $loopback ), $loopback );

$metadata = run( 'hm-abilities/upload-media', [ 'source_url' => 'http://169.254.169.254/latest/meta-data/' ] );
check( 'the cloud metadata address is refused', is_wp_error( $metadata ), $metadata );

$ipv6_loopback = run( 'hm-abilities/upload-media', [ 'source_url' => 'http://[::1]/x.png' ] );
check( 'an IPv6 loopback source is refused', is_wp_error( $ipv6_loopback ), $ipv6_loopback );

$unresolvable = run( 'hm-abilities/upload-media', [ 'source_url' => 'http://no-such-host.invalid/x.png' ] );
check( 'an unresolvable host is refused rather than allowed', is_wp_error( $unresolvable ), $unresolvable );

$allow_unresolvable = static function () {
	return [ 'no-such-host.invalid' ];
};
add_filter( 'hm_abilities_media_allowed_source_hosts', $allow_unresolvable );

// Called directly: the ability's wp_http_validate_url() refuses an unresolvable
// host first, so only a DNS answer that changes between lookups reaches here.
$allowed_unresolvable = HM\Abilities\Media\resolve_source_target( 'http://no-such-host.invalid/x.png' );
check( 'an allowlisted host that resolves to nothing is refused rather than pinned to no address', code( $allowed_unresolvable ) === 'hm_abilities_source_not_allowed', $allowed_unresolvable );

remove_filter( 'hm_abilities_media_allowed_source_hosts', $allow_unresolvable );

summary();
