<?php
/**
 * Covers the per-post and status-transition gates on the write abilities.
 * Helpers come from tests/bootstrap.php.
 *
 * @package HM_Abilities_MCP
 */

wp_set_current_user( 1 );

$normal = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Normal post', 'post_status' => 'draft', 'post_content' => 'original' ] );
$locked = wp_insert_post( [ 'post_type' => 'post', 'post_title' => 'Locked post', 'post_status' => 'draft', 'post_content' => 'original' ] );

echo "== baseline: an administrator can still update ==\n";

$ok = run( 'hm-abilities/update-content', [ 'id' => $normal, 'title' => 'Renamed', 'content' => 'rewritten' ] );
check( 'update succeeds for an admin', ! is_wp_error( $ok ) && field( $ok, 'title' ) === 'Renamed', $ok );
check( 'the write actually landed', get_post( $normal )->post_content === 'rewritten', get_post( $normal )->post_content );

$to_draft = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'pending' ] );
check( 'an admin may set pending', field( $to_draft, 'status' ) === 'pending', $to_draft );

$to_publish = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'publish' ] );
check( 'an admin may publish', field( $to_publish, 'status' ) === 'publish', $to_publish );

echo "\n== per-post edit_post gate ==\n";

$deny_one = static function ( $caps, $cap, $user_id, $args ) use ( $locked ) {
	if ( $cap === 'edit_post' && isset( $args[0] ) && (int) $args[0] === $locked ) {
		return [ 'do_not_allow' ];
	}
	return $caps;
};
add_filter( 'map_meta_cap', $deny_one, 10, 4 );

check( 'baseline: edit_post is denied on the locked post', ! current_user_can( 'edit_post', $locked ), current_user_can( 'edit_post', $locked ) );

$blocked = run( 'hm-abilities/update-content', [ 'id' => $locked, 'title' => 'Hijacked', 'content' => 'overwritten' ] );
check( 'update-content refuses a post the caller may not edit', code( $blocked ) === 'hm_abilities_forbidden', $blocked );
check( 'the refused write did not touch the post', get_post( $locked )->post_content === 'original' && get_post( $locked )->post_title === 'Locked post', [ get_post( $locked )->post_title, get_post( $locked )->post_content ] );

$still_ok = run( 'hm-abilities/update-content', [ 'id' => $normal, 'title' => 'Still editable' ] );
check( 'a different post is unaffected by the denial', field( $still_ok, 'title' ) === 'Still editable', $still_ok );

remove_filter( 'map_meta_cap', $deny_one, 10 );

echo "\n== status transition gate ==\n";

$strip_publish = static function ( $allcaps ) {
	$allcaps['publish_posts'] = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $strip_publish );

check( 'baseline: publish_posts is denied', ! current_user_can( 'publish_posts' ), current_user_can( 'publish_posts' ) );

$no_publish = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'publish' ] );
check( 'setting publish without publish_posts is refused', code( $no_publish ) === 'hm_abilities_cannot_publish', $no_publish );

$no_private = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'private' ] );
check( 'setting private without publish_posts is refused', code( $no_private ) === 'hm_abilities_cannot_publish', $no_private );

$draft_ok = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'draft' ] );
check( 'setting draft is still allowed without publish_posts', field( $draft_ok, 'status' ) === 'draft', $draft_ok );

$pending_ok = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'pending' ] );
check( 'setting pending is still allowed without publish_posts', field( $pending_ok, 'status' ) === 'pending', $pending_ok );

$title_only = run( 'hm-abilities/update-content', [ 'id' => $normal, 'title' => 'Title without status' ] );
check( 'an update that omits status is unaffected by the publish gate', field( $title_only, 'title' ) === 'Title without status', $title_only );

$before_status = get_post( $normal )->post_status;
run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'publish', 'title' => 'Should not apply' ] );
check( 'a refused status change writes nothing at all', get_post( $normal )->post_status === $before_status && get_post( $normal )->post_title === 'Title without status', [ get_post( $normal )->post_status, get_post( $normal )->post_title ] );

remove_filter( 'user_has_cap', $strip_publish );

echo "\n== create-content: create_posts gate ==\n";

$made = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Made by an admin' ] );
check( 'create succeeds for an admin', ! is_wp_error( $made ) && field( $made, 'title' ) === 'Made by an admin', $made );
check( 'the created post defaults to draft', field( $made, 'status' ) === 'draft', $made );

$deny_create = static function ( $allcaps ) {
	$allcaps['publish_posts'] = false;
	$allcaps['edit_posts']    = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $deny_create );

check( 'baseline: create_posts is denied', ! current_user_can( get_post_type_object( 'post' )->cap->create_posts ), get_post_type_object( 'post' )->cap->create_posts );

$before_count = wp_count_posts( 'post' )->draft;
$refused      = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Should never exist' ] );
check( 'create-content refuses without create_posts', code( $refused ) === 'hm_abilities_forbidden', $refused );
check( 'the refused create wrote no post', wp_count_posts( 'post' )->draft === $before_count, [ $before_count, wp_count_posts( 'post' )->draft ] );

remove_filter( 'user_has_cap', $deny_create );

echo "\n== create-content: status transition gate ==\n";

add_filter( 'user_has_cap', $strip_publish );

$no_pub_create = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Straight to publish', 'status' => 'publish' ] );
check( 'creating as publish without publish_posts is refused', code( $no_pub_create ) === 'hm_abilities_cannot_publish', $no_pub_create );

$no_priv_create = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Straight to private', 'status' => 'private' ] );
check( 'creating as private without publish_posts is refused', code( $no_priv_create ) === 'hm_abilities_cannot_publish', $no_priv_create );

$draft_create = run( 'hm-abilities/create-content', [ 'post_type' => 'post', 'title' => 'Draft is fine', 'status' => 'draft' ] );
check( 'creating as draft is still allowed', field( $draft_create, 'status' ) === 'draft', $draft_create );

check( 'the refused creates left nothing behind', get_page_by_path( 'straight-to-publish', OBJECT, 'post' ) === null, get_page_by_path( 'straight-to-publish', OBJECT, 'post' ) );

remove_filter( 'user_has_cap', $strip_publish );

echo "\n== unchanged behaviour ==\n";

$missing = run( 'hm-abilities/update-content', [ 'id' => 999999 ] );
check( 'a missing post is still a 404', code( $missing ) === 'hm_abilities_not_found', $missing );

$bad_status = run( 'hm-abilities/update-content', [ 'id' => $normal, 'status' => 'nonsense' ] );
check( 'an unsupported status is still rejected', is_wp_error( $bad_status ), $bad_status );

$attachment = wp_insert_post( [ 'post_type' => 'attachment', 'post_title' => 'A file', 'post_status' => 'inherit' ] );
$wrong_type = run( 'hm-abilities/update-content', [ 'id' => $attachment ] );
check( 'an unexposed post type is still refused', code( $wrong_type ) === 'hm_abilities_forbidden', $wrong_type );

summary();
